<?php

namespace Modules\Settings\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Modules\Inventory\Tests\Feature\InventoryTestCase;
use Modules\Organisation\Models\Branch;
use Modules\Organisation\Models\Till;
use Modules\Settings\Services\SettingsService;

class SettingsTest extends InventoryTestCase
{
    private User $owner;

    private User $manager;

    private User $support;

    private Branch $other;

    private Till $till;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::role(Roles::OWNER)->firstOrFail();
        $this->manager = $this->staff(Roles::BRANCH_MANAGER);
        $this->support = $this->staff(Roles::TESSERA_ADMIN);
        $this->other = Branch::query()->create(['business_id' => $this->main->business_id, 'code' => 'WEST', 'name' => 'Westlands']);
        $this->till = Till::query()->create(['branch_id' => $this->main->id, 'name' => 'Till 9', 'default_float_cents' => 0]);
    }

    private function change(User $user, string $key, mixed $value, string $scope = 'business', int $scopeId = 0)
    {
        return $this->actingAs($user)->putJson("/api/v1/settings/values/{$key}", ['scope' => $scope, 'scopeId' => $scopeId, 'value' => $value]);
    }

    private function settings(): SettingsService
    {
        return app(SettingsService::class);
    }

    public function test_owner_changes_a_setting_it_is_logged_and_can_be_undone(): void
    {
        $this->change($this->owner, 'branding.display_name', 'Kilimani Wines')->assertOk()->assertJsonPath('data.value', 'Kilimani Wines');
        $this->change($this->owner, 'branding.display_name', 'KW Liquor')->assertOk();

        $this->actingAs($this->owner)->getJson('/api/v1/settings/values/branding.display_name/history')
            ->assertJsonPath('data.0.oldValue', 'Kilimani Wines')
            ->assertJsonPath('data.0.newValue', 'KW Liquor');

        $this->actingAs($this->owner)->postJson('/api/v1/settings/values/branding.display_name/undo', ['scope' => 'business'])
            ->assertOk()->assertJsonPath('data.value', 'Kilimani Wines');

        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.changed', 'user_id' => $this->owner->id]);
        $this->expectException(QueryException::class);
        DB::table('setting_changes')->delete();
    }

    public function test_most_specific_scope_wins_and_reset_falls_back(): void
    {
        $this->change($this->owner, 'sales.layout', 'list')->assertOk();
        $this->change($this->owner, 'sales.layout', 'barcode', 'branch', $this->main->id)->assertOk();
        $this->change($this->owner, 'sales.layout', 'tiles', 'till', $this->till->id)->assertOk();

        $this->assertSame('tiles', $this->settings()->get('sales.layout', tillId: $this->till->id));
        $this->assertSame('barcode', $this->settings()->get('sales.layout', $this->main->id));
        $this->assertSame('list', $this->settings()->get('sales.layout', $this->other->id));

        $this->actingAs($this->owner)->deleteJson("/api/v1/settings/values/sales.layout?scope=till&scopeId={$this->till->id}")
            ->assertOk()->assertJsonPath('data.effective', 'barcode')->assertJsonPath('data.source', 'branch');
    }

    public function test_branch_manager_changes_only_their_own_branch_and_branch_level_settings(): void
    {
        $this->change($this->manager, 'receipts.paper_size', '58mm', 'branch', $this->main->id)->assertOk();
        $this->change($this->manager, 'receipts.paper_size', '58mm')->assertForbidden();                                 // business level is the owner's
        $this->change($this->manager, 'receipts.paper_size', '58mm', 'branch', $this->other->id)->assertForbidden();     // not their branch
        $this->change($this->manager, 'stock.low_stock_default', 3, 'branch', $this->main->id)->assertOk();              // "O, B per branch"
        $this->change($this->manager, 'stock.below_zero', 'block')->assertForbidden();                                   // owner-only
        $this->change($this->manager, 'shifts.opening_float', 300000, 'till', $this->till->id)->assertOk();
        $this->assertSame(300000, $this->till->fresh()->default_float_cents);                                          // stored on the till itself
    }

    public function test_tessera_only_settings_and_the_kra_pin(): void
    {
        $this->change($this->owner, 'business.kra_pin', 'P051234567X')->assertForbidden();
        $this->change($this->support, 'business.kra_pin', 'p051234567x')->assertOk()->assertJsonPath('data.value', 'P051234567X');
        $this->assertDatabaseHas('businesses', ['kra_pin' => 'P051234567X']);
        $this->change($this->support, 'business.kra_pin', 'NOTAPIN')->assertUnprocessable();
    }

    public function test_values_are_validated_and_colours_must_stay_readable(): void
    {
        $this->change($this->owner, 'branding.primary_color', '#F4F2EC')->assertUnprocessable()->assertJsonValidationErrors(['value']);
        $this->change($this->owner, 'branding.primary_color', '#123456')->assertOk();
        $this->change($this->owner, 'sales.layout', 'grid')->assertUnprocessable();
        $this->change($this->owner, 'receipts.footer_lines', "One\nTwo\nThree\nFour")->assertUnprocessable();
        $this->change($this->owner, 'staff.max_discount', ['Cashier' => 150])->assertUnprocessable();
        $this->change($this->owner, 'nope.nothing', true)->assertUnprocessable();
    }

    public function test_invoice_prefix_locks_after_the_first_sale(): void
    {
        $this->change($this->owner, 'receipts.invoice_prefix', 'KWS-')->assertOk()->assertJsonPath('data.value', 'KWS-');

        DB::table('sales')->insert([
            'client_id' => fake()->uuid(), 'number' => 'KWS-MAIN-000001', 'branch_id' => $this->main->id, 'till_id' => $this->till->id,
            'shift_id' => DB::table('shifts')->insertGetId(['branch_id' => $this->main->id, 'till_id' => $this->till->id, 'user_id' => $this->owner->id, 'opening_float_cents' => 0, 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]),
            'location_id' => $this->floor->id, 'user_id' => $this->owner->id, 'subtotal_cents' => 0, 'discount_cents' => 0, 'total_cents' => 0,
            'vat_cents' => 0, 'cost_cents' => 0, 'status' => 'completed', 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->change($this->owner, 'receipts.invoice_prefix', 'ABC-')->assertUnprocessable();
    }

    public function test_secrets_are_masked_everywhere(): void
    {
        $this->change($this->owner, 'payments.mpesa_passkey', 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919')->assertOk()
            ->assertJsonPath('data.value', '••••c919');

        $this->actingAs($this->owner)->getJson('/api/v1/settings/app')->assertOk()->assertJsonMissingPath('data.values.payments\.mpesa_passkey');
        $this->assertStringNotContainsString('bfb279', (string) DB::table('audit_logs')->where('action', 'settings.changed')->latest('id')->value('after'));
        $this->assertSame('bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919', $this->settings()->get('payments.mpesa_passkey'));
    }

    public function test_preset_keeps_your_own_changes_unless_you_agree(): void
    {
        $this->change($this->owner, 'sales.layout', 'list')->assertOk();

        $preview = $this->actingAs($this->owner)->getJson('/api/v1/settings/presets/supermarket/preview')->assertOk()->json('data');
        $layout = collect($preview)->firstWhere('key', 'sales.layout');
        $this->assertTrue($layout['yourChange']);

        $this->actingAs($this->owner)->postJson('/api/v1/settings/presets/supermarket/apply')->assertOk();
        $this->assertSame('list', $this->settings()->get('sales.layout'));        // kept
        $this->assertTrue($this->settings()->get('features.weighing_scale'));    // preset value written
        $this->assertSame('supermarket', $this->settings()->get('business.preset'));

        $this->actingAs($this->owner)->postJson('/api/v1/settings/presets/supermarket/apply', ['overwriteYourChanges' => true])->assertOk();
        $this->assertSame('barcode', $this->settings()->get('sales.layout'));
        $this->actingAs($this->manager)->postJson('/api/v1/settings/presets/general_retail/apply')->assertForbidden();
    }

    public function test_branding_is_public_and_images_upload(): void
    {
        Storage::fake('local');
        $path = $this->actingAs($this->owner)->post('/api/v1/settings/uploads', ['file' => UploadedFile::fake()->image('logo.png', 200, 80)])
            ->assertCreated()->json('data.path');
        $this->change($this->owner, 'branding.app_logo', $path)->assertOk();
        $this->change($this->owner, 'branding.display_name', 'Kilimani Wines')->assertOk();

        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $branding = $this->getJson('/api/v1/settings/public')->assertOk()
            ->assertJsonPath('data.displayName', 'Kilimani Wines')
            ->assertJsonPath('data.welcomeText', 'Sign in to Kilimani Wines')
            ->json('data');
        $this->get(parse_url($branding['appLogo'], PHP_URL_PATH))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_schema_lists_sections_with_who_can_edit(): void
    {
        $schema = $this->actingAs($this->manager)->getJson("/api/v1/settings/schema?scope=branch&scopeId={$this->main->id}")->assertOk()->json('data');

        $this->assertSame('B', $schema['level']);
        $fields = collect($schema['sections'])->flatMap(fn ($s) => $s['fields'])->keyBy('key');
        $this->assertTrue($fields['receipts.paper_size']['editable']);
        $this->assertArrayNotHasKey('branding.primary_color', $fields->all()); // business-only settings do not appear at branch level

        $this->actingAs($this->manager)->getJson("/api/v1/settings/schema?scope=branch&scopeId={$this->other->id}")->assertForbidden();
    }
}
