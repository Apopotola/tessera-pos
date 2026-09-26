<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Settings are data, never code: a key, a scope and a value. The most specific scope wins
     * (till → branch → business → the default in SettingsRegistry). Every change is recorded
     * (who, when, old, new) so the owner can undo the last change of any setting.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80);
            $table->string('scope', 10);                          // business | branch | till | user
            $table->unsignedBigInteger('scope_id')->default(0);   // 0 for business
            $table->jsonb('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['key', 'scope', 'scope_id']);
        });

        Schema::create('setting_changes', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80);
            $table->string('scope', 10);
            $table->unsignedBigInteger('scope_id')->default(0);
            $table->jsonb('old_value')->nullable();               // null = was inherited / not set
            $table->jsonb('new_value')->nullable();               // null = override removed
            $table->string('source', 20)->default('edit');        // edit | reset | undo | preset
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['key', 'scope', 'scope_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION setting_changes_guard() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'setting history is append-only';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER setting_changes_guard BEFORE UPDATE OR DELETE ON setting_changes
                FOR EACH ROW EXECUTE FUNCTION setting_changes_guard();
        SQL);

        // Dev/demo data already has sales in the MAIN-S-000001 format: keep it (the prefix locks after the first sale).
        if (DB::table('sales')->exists()) {
            DB::table('settings')->insert(['key' => 'receipts.invoice_prefix', 'scope' => 'business', 'scope_id' => 0, 'value' => json_encode(''), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS setting_changes_guard ON setting_changes');
        DB::unprepared('DROP FUNCTION IF EXISTS setting_changes_guard()');
        Schema::dropIfExists('setting_changes');
        Schema::dropIfExists('settings');
    }
};
