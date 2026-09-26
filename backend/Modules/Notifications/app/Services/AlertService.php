<?php

namespace Modules\Notifications\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Authorization\Support\Roles;
use Modules\Notifications\Models\Alert;
use Modules\Notifications\Models\AlertRecipient;
use Modules\Notifications\Models\OutboundMessage;
use Modules\Organisation\Services\BranchAccessService;
use Modules\Settings\Services\SettingsService;

/**
 * Raises alerts (Settings → Notifications): in-app for everyone allowed to act on them
 * at that branch; by SMS / WhatsApp / email for owners, per the channels chosen.
 */
class AlertService
{
    /** type => who acts on it, and the screen that does. Owners always get every type they switch on. */
    public const TYPES = [
        Alert::LOW_STOCK => ['label' => 'Low stock', 'permission' => Permissions::INVENTORY_VIEW, 'link' => ['title' => 'Stock on hand', 'path' => '/inventory/stock', 'view' => 'stockOnHand']],
        Alert::LARGE_REFUND => ['label' => 'Large refund', 'permission' => Permissions::SHIFTS_CASHUP_APPROVE, 'link' => ['title' => 'Sales', 'path' => '/sales', 'view' => 'salesList']],
        Alert::CASH_VARIANCE => ['label' => 'Cash variance', 'permission' => Permissions::SHIFTS_CASHUP_APPROVE, 'link' => ['title' => 'Shifts & cash-ups', 'path' => '/sales/shifts', 'view' => 'shiftsList']],
        Alert::ETIMS_FAILURE => ['label' => 'eTIMS failure', 'permission' => Permissions::COMPLIANCE_VIEW, 'link' => ['title' => 'eTIMS monitor', 'path' => '/compliance/etims', 'view' => 'etimsMonitor']],
        Alert::DAILY_SUMMARY => ['label' => 'End-of-day summary', 'permission' => null, 'link' => ['title' => 'Dashboard', 'path' => '/dashboard', 'view' => 'dashboard']],
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly BranchAccessService $branches,
    ) {}

    public function enabled(string $type): bool
    {
        return in_array($type, (array) $this->settings->get('notifications.recipients'), true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  string|null  $dedupeKey  the same key is not alerted again within $dedupeHours
     */
    public function raise(string $type, ?int $branchId, string $title, string $body, array $data = [], ?string $dedupeKey = null, int $dedupeHours = 24): ?Alert
    {
        if (! $this->enabled($type)) {
            return null;
        }
        if ($dedupeKey && Alert::query()->where('dedupe_key', $dedupeKey)->where('created_at', '>', now()->subHours($dedupeHours))->exists()) {
            return null;
        }

        return DB::transaction(function () use ($type, $branchId, $title, $body, $data, $dedupeKey) {
            $alert = Alert::query()->create([
                'type' => $type, 'branch_id' => $branchId, 'title' => $title, 'body' => $body,
                'data' => ['link' => self::TYPES[$type]['link'], ...$data], 'dedupe_key' => $dedupeKey,
            ]);

            $recipients = $this->recipients($type, $branchId);
            foreach ($recipients as $user) {
                AlertRecipient::query()->create(['alert_id' => $alert->id, 'user_id' => $user->id]);
            }
            $this->queueExternal($alert, $recipients->filter(fn (User $u) => $u->hasRole(Roles::OWNER)));

            return $alert;
        });
    }

    /**
     * Active staff who can act on this type at the branch. Tessera support is not business staff.
     *
     * @return Collection<int, User>
     */
    public function recipients(string $type, ?int $branchId)
    {
        $permission = self::TYPES[$type]['permission'];
        $query = $permission ? User::permission($permission) : User::role(Roles::OWNER);

        return $query->where('is_active', true)->with('roles')->get()
            ->reject(fn (User $u) => $u->hasRole(Roles::TESSERA_ADMIN))
            ->filter(fn (User $u) => $branchId === null || $this->branches->canAccess($u, $branchId))
            ->values();
    }

    /** SMS / WhatsApp / email copies for owners, per Settings → Notifications → Channels. */
    private function queueExternal(Alert $alert, $owners): void
    {
        $channels = array_diff((array) $this->settings->get('notifications.channels'), ['in_app']);
        $sender = (string) $this->settings->get('notifications.sms_sender');

        foreach ($owners as $owner) {
            foreach ($channels as $channel) {
                $to = $channel === 'email' ? $owner->email : $owner->phone;
                if (! $to) {
                    continue;
                }
                OutboundMessage::query()->create([
                    'alert_id' => $alert->id,
                    'user_id' => $owner->id,
                    'channel' => $channel,
                    'recipient' => $to,
                    'subject' => $channel === 'email' ? $alert->title : null,
                    // SMS: short, prefixed with the sender name; email and WhatsApp get the full text.
                    'body' => $channel === 'sms' ? mb_substr("{$sender}: {$alert->title}. {$alert->body}", 0, 320) : "{$alert->title}\n\n{$alert->body}",
                    'status' => OutboundMessage::PENDING,
                ]);
            }
        }
    }
}
