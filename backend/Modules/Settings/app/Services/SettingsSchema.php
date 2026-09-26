<?php

namespace Modules\Settings\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Catalogue\Models\ProductVariant;
use Modules\Organisation\Models\Business;
use Modules\Organisation\Models\Till;
use Modules\Organisation\Services\BranchAccessService;
use Modules\Settings\Support\SettingsRegistry;

/** Shapes settings for the Settings screens, the apps and the login page. */
class SettingsSchema
{
    public const POWERED_BY = ['text' => 'Powered by Tessera', 'support' => 'support@tessera.co.ke'];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly BranchAccessService $branches,
    ) {}

    /** @return array<string, mixed> */
    public function build(User $user, string $scope, int $scopeId): array
    {
        $this->assertCanView($user, $scope, $scopeId);

        $changed = DB::table('setting_changes')->where(['scope' => $scope, 'scope_id' => $scopeId])->distinct()->pluck('key')->flip();
        $sections = [];
        foreach (SettingsRegistry::SECTIONS as $sectionKey => $section) {
            $fields = [];
            foreach (SettingsRegistry::all() as $key => $def) {
                if ($def['section'] === $sectionKey && in_array($scope, $def['scopes'], true)) {
                    $fields[] = $this->fieldArray($user, $key, $def, $scope, $scopeId, isset($changed[$key]));
                }
            }
            $sections[] = ['key' => $sectionKey, ...$section, 'fields' => $fields];
        }

        $branchIds = $this->branches->branchesFor($user)->modelKeys();
        // Names for item ids held in "items" settings (favourite products).
        $itemIds = collect($sections)->flatMap(fn ($section) => $section['fields'])->where('type', 'items')
            ->flatMap(fn ($f) => array_merge((array) $f['value'], (array) $f['effective']))->unique()->values()->all();
        $itemNames = ProductVariant::query()->with('product')->findMany($itemIds)
            ->map(fn (ProductVariant $v) => ['id' => $v->id, 'label' => $v->display_name])->values()->all();

        return [
            'level' => $this->settings->levelOf($user),
            'scope' => $scope,
            'scopeId' => $scopeId,
            'canEditBusiness' => $this->settings->levelOf($user) !== 'B',
            'branches' => DB::table('branches')->whereIn('id', $branchIds)->orderBy('name')->get(['id', 'code', 'name'])->map(fn ($b) => (array) $b)->all(),
            'tills' => DB::table('tills')->whereIn('branch_id', $branchIds)->where('is_active', true)->orderBy('name')->get(['id', 'branch_id as branchId', 'name'])->map(fn ($t) => (array) $t)->all(),
            'sections' => $sections,
            'presets' => array_map(fn ($key, $p) => ['key' => $key, 'title' => $p['title'], 'description' => $p['description']], array_keys(SettingsRegistry::presets()), SettingsRegistry::presets()),
            'currentPreset' => $this->settings->get('business.preset'),
            'locked' => SettingsRegistry::locked(),
            'salesStarted' => DB::table('sales')->exists(),
            'references' => [
                'categories' => DB::table('categories')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'parent_id as parentId'])->map(fn ($c) => (array) $c)->all(),
                'roles' => SettingsRegistry::ROLES_FOR_LIMITS,
                'dashboardTiles' => SettingsRegistry::DASHBOARD_TILES,
                'items' => $itemNames,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function field(User $user, string $key, string $scope, int $scopeId): array
    {
        $def = $this->settings->definition($key);
        $hasHistory = DB::table('setting_changes')->where(['key' => $key, 'scope' => $scope, 'scope_id' => $scopeId])->exists();

        return $this->fieldArray($user, $key, $def, $scope, $scopeId, $hasHistory);
    }

    /** What the back office or a till needs: effective values plus this user's role limits. */
    public function forUser(User $user, ?Till $till): array
    {
        $values = $this->settings->effective($till?->branch_id, $till?->id);
        $role = $user->roles->first()?->name;

        return [
            'values' => $values,
            'businessName' => $values['branding.display_name'] ?: Business::query()->orderBy('id')->value('name'),
            'myDiscountLimit' => (int) ($values['staff.max_discount'][$role] ?? 0),
            'dashboardTiles' => $values['dashboard.tiles_by_role'][$role] ?? null,
            'poweredBy' => self::POWERED_BY,
        ];
    }

    /** Login page branding (public). */
    public function publicBranding(): array
    {
        $get = fn (string $key) => $this->settings->present($this->settings->definition($key), $this->settings->get($key));
        $name = $get('branding.display_name') ?: Business::query()->orderBy('id')->value('name') ?? 'Tessera';

        return [
            'displayName' => $name,
            'appLogo' => $get('branding.app_logo'),
            'favicon' => $get('branding.favicon'),
            'primaryColor' => $get('branding.primary_color'),
            'accentColor' => $get('branding.accent_color'),
            'loginStyle' => $get('branding.login_style'),
            'loginBackground' => $get('branding.login_background'),
            'loginBackgroundImage' => $get('branding.login_background_image'),
            'welcomeText' => $get('branding.welcome_text') ?: "Sign in to {$name}",
            'poweredBy' => self::POWERED_BY,
        ];
    }

    /** @return array<string, mixed> */
    private function fieldArray(User $user, string $key, array $def, string $scope, int $scopeId, bool $hasHistory): array
    {
        $state = $this->settings->describe($key, $scope, $scopeId);
        $lockedPrefix = $key === 'receipts.invoice_prefix' && DB::table('sales')->exists();

        return [
            'key' => $key,
            'label' => $def['label'],
            'type' => $def['type'],
            'group' => $def['group'],
            'help' => $def['help'],
            'options' => array_map(fn ($value, $label) => ['value' => (string) $value, 'label' => $label], array_keys($def['options']), $def['options']),
            'default' => $this->settings->present($def, $def['default']),
            'level' => $def['level'],
            'scopes' => $def['scopes'],
            'available' => $def['available'],
            'note' => $def['note'],
            'min' => $def['min'],
            'max' => $def['max'],
            'value' => $this->settings->present($def, $state['stored']),
            // Image fields also return the stored path so the screen can keep it.
            'storedPath' => $def['type'] === 'image' ? $state['stored'] : null,
            'isSet' => $state['isSet'],
            'effective' => $this->settings->present($def, $state['effective']),
            'source' => $state['source'],
            'editable' => ! $lockedPrefix && $this->settings->canEdit($user, $def, $scope, $scopeId),
            'lockedReason' => $lockedPrefix ? 'Locked after the first sale so invoice numbers stay in sequence.' : null,
            'hasHistory' => $hasHistory,
            'modelBacked' => $def['model'] !== null,
        ];
    }

    private function assertCanView(User $user, string $scope, int $scopeId): void
    {
        if ($scope === 'business') {
            return;
        }
        $branchId = $scope === 'till' ? Till::query()->whereKey($scopeId)->value('branch_id') : $scopeId;
        if (! $branchId || ! $this->branches->canAccess($user, (int) $branchId)) {
            throw new AuthorizationException('You cannot see settings for this '.$scope.'.');
        }
    }
}
