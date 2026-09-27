<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Licence & permit register (Phase 2). A reminder, not enforcement: compliance stays with
     * the business, and licence types and receipt rules REQUIRE VALIDATION with the county.
     * Alerts 60 and 30 days before expiry; the liquor licence number can print on receipts.
     */
    public function up(): void
    {
        Schema::create('licences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('type', 40);        // liquor_licence | business_permit | fire | health | excise | other
            $table->string('name', 120);       // as written on the certificate
            $table->string('number', 60);
            $table->string('issuer', 120)->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on');
            $table->boolean('print_on_receipt')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['branch_id', 'type', 'expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licences');
    }
};
