<?php

namespace Modules\Settings\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Organisation\Models\Business;
use Modules\Organisation\Models\Till;
use Modules\Organisation\Services\BranchAccessService;
use Modules\Settings\Models\Setting;
use Modules\Settings\Models\SettingChange;
use Modules\Settings\Support\Contrast;
use Modules\Settings\Support\SettingsRegistry;

/**
 * Reads and writes settings. The most specific scope wins: till → branch → business → the
 * registry default. Values are cached per business and the cache is cleared on every change.
 * Every change is recorded (who, when, old, new) and can be undone.
 */
class SettingsService
{
    public const SCOPES = ['business', 'branch', 'till'];

    private const CACHE_KEY = 'settings.rows.v1';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BranchAccessService $branches,
    ) {}

    // ------------------------------------------------------------------ reading

    /** Effective value for a branch / till (both optional). */
    public function get(string $key, ?int $branchId = null, ?int $tillId = null): mixed
    {
        $def = $this->definition($key);
        if ($tillId !== null && $branchId === null) {
            $branchId = $this->tillBranch($tillId);
        }

        if ($def['model'] !== null) {
            return $this->modelValue($def, $tillId);
        }

        foreach ([['till', $tillId], ['branch', $branchId], ['business', 0]] as [$scope, $id]) {
            if ($id === null || ! in_array($scope, $def['scopes'], true) && $scope !== 'business') {
                continue;
            }
            $stored = $this->rows()->get($this->rowKey($key, $scope, (int) $id));
            if ($stored !== null) {
                return $this->decode($def, $stored);
            }
        }

        return $def['default'];
    }

    /**
     * Value stored exactly at a scope (null = not set there) and what applies there.
     *
     * @return array{stored: mixed, isSet: bool, effective: mixed, source: string}
     */
    public function describe(string $key, string $scope, int $scopeId): array
    {
        $def = $this->definition($key);
        if ($def['model'] !== null) {
            $value = $this->modelValue($def, $scopeId ?: null);

            return ['stored' => $value, 'isSet' => true, 'effective' => $value, 'source' => $scope];
        }

        $raw = $this->rows()->get($this->rowKey($key, $scope, $scopeId));
        $stored = $raw === null ? null : $this->decode($def, $raw);

        $chain = match ($scope) {
            'till' => [['till', $scopeId], ['branch', $this->tillBranch($scopeId)], ['business', 0]],
            'branch' => [['branch', $scopeId], ['business', 0]],
            default => [['business', 0]],
        };
        foreach ($chain as [$s, $id]) {
            $row = $id === null ? null : $this->rows()->get($this->rowKey($key, $s, (int) $id));
            if ($row !== null) {
                return ['stored' => $stored, 'isSet' => $raw !== null, 'effective' => $this->decode($def, $row), 'source' => $s];
            }
        }

        return ['stored' => $stored, 'isSet' => $raw !== null, 'effective' => $def['default'], 'source' => 'default'];
    }

    /** Every setting the apps need, resolved for a branch / till. Secrets are never included. */
    public function effective(?int $branchId = null, ?int $tillId = null): array
    {
        $values = [];
        foreach (SettingsRegistry::all() as $key => $def) {
            if ($def['type'] !== 'secret') {
                $values[$key] = $this->present($def, $this->get($key, $branchId, $tillId));
            }
        }

        return $values;
    }

    // ------------------------------------------------------------------ writing

    public function set(User $user, string $key, string $scope, int $scopeId, mixed $value, string $source = 'edit'): void
    {
        $def = $this->definition($key);
        $this->assertScope($def, $scope, $scopeId);
        if (! $this->canEdit($user, $def, $scope, $scopeId)) {
            throw new AuthorizationException('You cannot change this setting'.($scope === 'business' ? '' : ' here').'.');
        }

        $value = $this->normalize($key, $def, $value);
        $this->guardLocked($key, $value);

        DB::transaction(function () use ($user, $key, $def, $scope, $scopeId, $value, $source) {
            $old = $def['model'] !== null ? $this->modelValue($def, $scopeId ?: null) : $this->storedRaw($key, $scope, $scopeId);

            if ($def['model'] !== null) {
                $this->writeModel($def, $scopeId ?: null, $value);
            } else {
                Setting::query()->updateOrCreate(
                    ['key' => $key, 'scope' => $scope, 'scope_id' => $scopeId],
                    ['value' => $this->encode($def, $value), 'updated_by' => $user->id, 'updated_at' => now()],
                );
            }

            $this->record($user, $key, $def, $scope, $scopeId, $old, $def['model'] !== null ? $value : $this->encode($def, $value), $source);
        });
    }

    /** Remove the override at this scope so the parent value applies again. */
    public function reset(User $user, string $key, string $scope, int $scopeId): void
    {
        $def = $this->definition($key);
        $this->assertScope($def, $scope, $scopeId);
        if ($def['model'] !== null || ! $this->canEdit($user, $def, $scope, $scopeId)) {
            throw new AuthorizationException('This setting cannot be reset here.');
        }
        $this->guardLocked($key, null);

        DB::transaction(function () use ($user, $key, $def, $scope, $scopeId) {
            $old = $this->storedRaw($key, $scope, $scopeId);
            if ($old === null) {
                return;
            }
            Setting::query()->where(['key' => $key, 'scope' => $scope, 'scope_id' => $scopeId])->delete();
            $this->record($user, $key, $def, $scope, $scopeId, $old, null, 'reset');
        });
    }

    /** Put back the value from before the last change at this scope. */
    public function undo(User $user, string $key, string $scope, int $scopeId): void
    {
        $def = $this->definition($key);
        if (! $this->canEdit($user, $def, $scope, $scopeId)) {
            throw new AuthorizationException;
        }
        $last = SettingChange::query()->where(['key' => $key, 'scope' => $scope, 'scope_id' => $scopeId])->latest('id')->first()
            ?? throw ValidationException::withMessages(['key' => 'Nothing to undo for this setting.']);
        $this->guardLocked($key, $last->old_value);

        DB::transaction(function () use ($user, $key, $def, $scope, $scopeId, $last) {
            $current = $def['model'] !== null ? $this->modelValue($def, $scopeId ?: null) : $this->storedRaw($key, $scope, $scopeId);
            if ($def['model'] !== null) {
                $this->writeModel($def, $scopeId ?: null, $last->old_value);
            } elseif ($last->old_value === null) {
                Setting::query()->where(['key' => $key, 'scope' => $scope, 'scope_id' => $scopeId])->delete();
            } else {
                Setting::query()->updateOrCreate(
                    ['key' => $key, 'scope' => $scope, 'scope_id' => $scopeId],
                    ['value' => $last->old_value, 'updated_by' => $user->id, 'updated_at' => now()],
                );
            }
            $this->record($user, $key, $def, $scope, $scopeId, $current, $last->old_value, 'undo');
        });
    }

    /** @return Collection<int, array<string, mixed>> */
    public function history(string $key, string $scope, int $scopeId): Collection
    {
        $def = $this->definition($key);

        return SettingChange::query()->with('user')->where(['key' => $key, 'scope' => $scope, 'scope_id' => $scopeId])
            ->latest('id')->limit(20)->get()
            ->map(fn (SettingChange $c) => [
                'id' => $c->id,
                'oldValue' => $this->present($def, $c->old_value === null ? null : $this->decode($def, $c->old_value)),
                'newValue' => $this->present($def, $c->new_value === null ? null : $this->decode($def, $c->new_value)),
                'source' => $c->source,
                'user' => $c->user?->name,
                'at' => $c->created_at->toIso8601String(),
            ]);
    }

    // ------------------------------------------------------------------ presets

    /** What applying a preset would change; `yourChange` = the owner changed it (kept unless they agree). */
    public function presetPreview(string $preset): array
    {
        $bundle = SettingsRegistry::presets()[$preset] ?? throw ValidationException::withMessages(['preset' => 'Unknown preset.']);
        $changes = [];
        foreach ($bundle['values'] as $key => $value) {
            $def = $this->definition($key);
            $current = $this->get($key);
            if ($current == $value) {
                continue;
            }
            $lastSource = SettingChange::query()->where(['key' => $key, 'scope' => 'business', 'scope_id' => 0])->latest('id')->value('source');
            $changes[] = [
                'key' => $key,
                'label' => $def['label'],
                'from' => $this->present($def, $current),
                'to' => $this->present($def, $value),
                'yourChange' => $lastSource !== null && $lastSource !== 'preset',
            ];
        }

        return $changes;
    }

    /** @return int number of settings written */
    public function applyPreset(User $user, string $preset, bool $overwriteYourChanges): int
    {
        if (! $user->can(Permissions::SETTINGS_BUSINESS)) {
            throw new AuthorizationException;
        }
        $bundle = SettingsRegistry::presets()[$preset] ?? throw ValidationException::withMessages(['preset' => 'Unknown preset.']);
        $written = 0;

        DB::transaction(function () use ($user, $preset, $bundle, $overwriteYourChanges, &$written) {
            foreach ($this->presetPreview($preset) as $change) {
                if ($change['yourChange'] && ! $overwriteYourChanges) {
                    continue;
                }
                $this->set($user, $change['key'], 'business', 0, $bundle['values'][$change['key']], 'preset');
                $written++;
            }
            $this->set($user, 'business.preset', 'business', 0, $preset, 'preset');
        });

        return $written;
    }

    // ------------------------------------------------------------------ who may change what

    public function canEdit(User $user, array $def, string $scope, int $scopeId): bool
    {
        if ($user->can(Permissions::SETTINGS_PLATFORM)) {
            return true;
        }
        if ($def['level'] === 'T') {
            return false;
        }
        if ($user->can(Permissions::SETTINGS_BUSINESS)) {
            return true;
        }

        // Branch managers: their own branch (and its tills), for branch-level settings.
        $branchScoped = $def['level'] === 'B' ? in_array($scope, ['branch', 'till'], true) : in_array($scope, $def['branchLevelScopes'], true);
        if (! $branchScoped || ! $user->can(Permissions::SETTINGS_BRANCH)) {
            return false;
        }
        $branchId = $scope === 'till' ? $this->tillBranch($scopeId) : $scopeId;

        return $branchId !== null && $this->branches->canAccess($user, $branchId);
    }

    public function levelOf(User $user): string
    {
        return match (true) {
            $user->can(Permissions::SETTINGS_PLATFORM) => 'T',
            $user->can(Permissions::SETTINGS_BUSINESS) => 'O',
            default => 'B',
        };
    }

    // ------------------------------------------------------------------ internals

    /** @return array<string, mixed> */
    public function definition(string $key): array
    {
        return SettingsRegistry::get($key) ?? throw ValidationException::withMessages(['key' => "Unknown setting {$key}."]);
    }

    /** API shape of a value: images as URLs, secrets masked. */
    public function present(array $def, mixed $value): mixed
    {
        return match ($def['type']) {
            'image' => $value ? route('api.v1.settings.files', ['path' => $value]) : null,
            'secret' => $value ? '••••'.mb_substr((string) $value, -4) : null,
            default => $value,
        };
    }

    /** @return Collection<string, mixed> "key|scope|id" => raw stored value */
    private function rows(): Collection
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => Setting::query()->get(['key', 'scope', 'scope_id', 'value'])
            ->mapWithKeys(fn (Setting $s) => [$this->rowKey($s->key, $s->scope, $s->scope_id) => $s->value]));
    }

    private function rowKey(string $key, string $scope, int $id): string
    {
        return "{$key}|{$scope}|{$id}";
    }

    private function storedRaw(string $key, string $scope, int $scopeId): mixed
    {
        // Through the model so the json cast applies (value() would return the raw JSON text).
        return Setting::query()->where(['key' => $key, 'scope' => $scope, 'scope_id' => $scopeId])->first()?->value;
    }

    private function tillBranch(int $tillId): ?int
    {
        return Cache::remember("settings.till-branch.{$tillId}", 3600, fn () => Till::query()->whereKey($tillId)->value('branch_id'));
    }

    private function assertScope(array $def, string $scope, int $scopeId): void
    {
        if (! in_array($scope, $def['scopes'], true)) {
            throw ValidationException::withMessages(['scope' => 'This setting cannot be set at '.$scope.' level.']);
        }
        if ($scope !== 'business' && $scopeId <= 0) {
            throw ValidationException::withMessages(['scopeId' => 'Choose the '.$scope.'.']);
        }
    }

    private function modelValue(array $def, ?int $tillId): mixed
    {
        [$model, $column] = $def['model'];

        return match ($model) {
            'business' => Business::query()->orderBy('id')->value($column),
            'till' => $tillId ? Till::query()->whereKey($tillId)->value($column) : $def['default'],
        };
    }

    private function writeModel(array $def, ?int $tillId, mixed $value): void
    {
        [$model, $column] = $def['model'];
        match ($model) {
            'business' => Business::query()->orderBy('id')->firstOrFail()->forceFill([$column => $value])->save(),
            'till' => Till::query()->findOrFail($tillId)->forceFill([$column => $value])->save(),
        };
        Cache::forget(self::CACHE_KEY);
    }

    private function encode(array $def, mixed $value): mixed
    {
        return $def['type'] === 'secret' && $value !== null ? Crypt::encryptString((string) $value) : $value;
    }

    private function decode(array $def, mixed $stored): mixed
    {
        if ($def['type'] === 'secret' && $stored !== null) {
            try {
                return Crypt::decryptString((string) $stored);
            } catch (\Throwable) {
                return null;
            }
        }

        return $stored;
    }

    private function record(User $user, string $key, array $def, string $scope, int $scopeId, mixed $old, mixed $new, string $source): void
    {
        SettingChange::query()->create([
            'key' => $key, 'scope' => $scope, 'scope_id' => $scopeId,
            // The json cast on SettingChange encodes these.
            'old_value' => $old, 'new_value' => $new,
            'source' => $source, 'user_id' => $user->id,
        ]);
        // Secrets never reach the audit log.
        $mask = fn ($v) => $def['type'] === 'secret' ? ($v === null ? null : '(changed)') : $v;
        $this->audit->log('settings.changed', null, before: ['value' => $mask($old)], after: ['key' => $key, 'scope' => $scope, 'scope_id' => $scopeId, 'value' => $mask($new), 'source' => $source], userId: $user->id);
        Cache::forget(self::CACHE_KEY);
    }

    /** Settings that must not change once trading has started. */
    private function guardLocked(string $key, mixed $value): void
    {
        if ($key === 'receipts.invoice_prefix' && DB::table('sales')->exists()) {
            throw ValidationException::withMessages(['value' => 'The invoice prefix is locked after the first sale so invoice numbers stay in sequence.']);
        }
    }

    /** Validates and cleans a value for its type. */
    private function normalize(string $key, array $def, mixed $value): mixed
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['value' => $message]);
        $options = array_map('strval', array_keys($def['options']));

        switch ($def['type']) {
            case 'text':
            case 'secret':
                if ($value === null || (is_string($value) && trim($value) === '')) {
                    return $key === 'receipts.invoice_prefix' ? '' : null;
                }
                if (! is_string($value) && ! is_numeric($value)) {
                    $fail('Enter text.');
                }
                $value = trim((string) $value);
                if ($def['pattern'] !== null && str_contains($def['pattern'], 'A-Z')) {
                    $value = mb_strtoupper($value);
                }
                if ($def['max'] !== null && mb_strlen($value) > $def['max']) {
                    $fail("At most {$def['max']} characters.");
                }
                if ($def['pattern'] !== null && ! preg_match($def['pattern'], $value)) {
                    $fail('That does not look right for '.mb_strtolower($def['label']).'.');
                }

                return $value;
            case 'lines':
                if ($value === null || trim((string) $value) === '') {
                    return null;
                }
                $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $value) ?: []), fn ($l) => $l !== ''));
                if (count($lines) > ($def['max'] ?? 3)) {
                    $fail('At most '.($def['max'] ?? 3).' lines.');
                }
                foreach ($lines as $line) {
                    if (mb_strlen($line) > 48) {
                        $fail('Each line fits 48 characters on a receipt.');
                    }
                }

                return implode("\n", $lines);
            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $fail('Choose on or off.');
            case 'select':
                if (! in_array((string) $value, $options, true)) {
                    $fail('Choose one of the options.');
                }

                return is_int(array_key_first($def['options'])) ? (int) $value : (string) $value;
            case 'multiselect':
            case 'order':
                if (! is_array($value) || array_diff(array_map('strval', $value), $options) !== []) {
                    $fail('Choose from the options.');
                }

                return array_values(array_unique(array_map('strval', $value)));
            case 'number':
            case 'money':
                if (! is_numeric($value) || (int) $value != $value) {
                    $fail('Enter a whole number.');
                }
                $value = (int) $value;
                if (($def['min'] !== null && $value < $def['min']) || ($def['max'] !== null && $value > $def['max'])) {
                    $fail('Out of range.');
                }

                return $value;
            case 'color':
                $value = mb_strtoupper(trim((string) $value));
                if (! preg_match('/^#[0-9A-F]{6}$/', $value)) {
                    $fail('Enter a colour like #5B3FA3.');
                }
                if (! isset($def['options'][$value]) && Contrast::ratio('#FFFFFF', $value) < 4.5) {
                    $fail('White text on this colour would be hard to read. Pick a darker shade.');
                }

                return $value;
            case 'image':
                if ($value === null || $value === '') {
                    return null;
                }
                if (! is_string($value) || ! str_starts_with($value, 'branding/') || ! Storage::disk('local')->exists($value)) {
                    $fail('Upload the image first.');
                }

                return $value;
            case 'time':
                if ($value === null || $value === '') {
                    return null;
                }
                if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $value)) {
                    $fail('Enter a time like 21:00.');
                }

                return (string) $value;
            case 'categories':
            case 'items':
                if (! is_array($value)) {
                    $fail('Choose from the list.');
                }
                $ids = array_values(array_unique(array_map('intval', $value)));
                if ($def['max'] !== null && count($ids) > $def['max']) {
                    $fail("At most {$def['max']}.");
                }
                $table = $def['type'] === 'categories' ? 'categories' : 'product_variants';
                if (count($ids) !== DB::table($table)->whereIn('id', $ids)->count()) {
                    $fail('Some of these no longer exist.');
                }

                return $ids;
            case 'role_percent':
                if (! is_array($value)) {
                    $fail('Enter a percentage per role.');
                }
                $clean = [];
                foreach ($value as $role => $percent) {
                    if (! in_array($role, SettingsRegistry::ROLES_FOR_LIMITS, true) || ! is_numeric($percent) || $percent < 0 || $percent > 100) {
                        $fail('Each role needs a limit from 0 to 100%.');
                    }
                    $clean[$role] = (int) $percent;
                }

                return $clean;
            case 'role_tiles':
                if (! is_array($value)) {
                    $fail('Choose tiles per role.');
                }
                $clean = [];
                foreach ($value as $role => $tiles) {
                    if (! in_array($role, SettingsRegistry::ROLES_FOR_LIMITS, true) || ! is_array($tiles) || array_diff($tiles, array_keys(SettingsRegistry::DASHBOARD_TILES)) !== []) {
                        $fail('Choose tiles from the library.');
                    }
                    $clean[$role] = array_values(array_unique($tiles));
                }

                return $clean;
        }

        return $value;
    }
}
