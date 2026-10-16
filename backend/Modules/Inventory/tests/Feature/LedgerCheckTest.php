<?php

namespace Modules\Inventory\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;

/** The nightly check: stock balances must equal the append-only ledger. */
class LedgerCheckTest extends InventoryTestCase
{
    public function test_a_clean_ledger_passes_quietly(): void
    {
        $this->openingStock(12, 300000, $this->floor);

        $this->artisan('inventory:check-ledger')
            ->expectsOutput('All stock balances agree with the stock ledger.')
            ->assertExitCode(0);
        $this->assertDatabaseMissing('alerts', ['type' => 'stock_ledger']);
    }

    public function test_a_balance_changed_outside_the_ledger_fails_and_alerts_the_owners(): void
    {
        $this->openingStock(12, 300000, $this->floor);
        $manager = $this->staff(Roles::BRANCH_MANAGER);
        // Someone edits the projection directly (the ledger itself refuses changes).
        DB::table('stock_balances')->where('location_id', $this->floor->id)->where('variant_id', $this->whisky->id)->update(['quantity' => 15]);

        $this->artisan('inventory:check-ledger')
            ->expectsOutput('1 stock balance(s) disagree with the stock ledger:')
            ->expectsOutputToContain('TW-750')
            ->assertExitCode(1);

        $alert = DB::table('alerts')->where('type', 'stock_ledger')->first();
        $this->assertNotNull($alert, 'raised even though it is not in the recipients setting');
        $this->assertStringContainsString('balance 15, ledger 12', $alert->body);
        $owner = User::role(Roles::OWNER)->firstOrFail();
        $this->assertDatabaseHas('alert_recipients', ['alert_id' => $alert->id, 'user_id' => $owner->id]);
        $this->assertDatabaseMissing('alert_recipients', ['alert_id' => $alert->id, 'user_id' => $manager->id]);

        // Once a day, however often it runs.
        $this->artisan('inventory:check-ledger')->assertExitCode(1);
        $this->assertSame(1, DB::table('alerts')->where('type', 'stock_ledger')->count());
    }

    public function test_movements_without_a_balance_row_are_found_too(): void
    {
        $this->openingStock(5, 300000, $this->store);
        DB::table('stock_balances')->where('location_id', $this->store->id)->delete();

        $this->artisan('inventory:check-ledger')
            ->expectsOutputToContain('TW-750')
            ->assertExitCode(1);
    }

    public function test_it_runs_every_night_at_half_past_eleven(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'inventory:check-ledger'));

        $this->assertNotNull($event);
        $this->assertSame('30 23 * * *', $event->expression);
    }
}
