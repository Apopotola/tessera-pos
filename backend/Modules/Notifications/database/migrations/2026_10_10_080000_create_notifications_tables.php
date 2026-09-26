<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Alerts (Phase 2 notifications): something happened that someone should act on.
     * Each alert lands in the in-app inbox of everyone allowed to act on it; owners also
     * get it by SMS / WhatsApp / email through the outbox, per Settings → Notifications.
     */
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);               // low_stock | large_refund | cash_variance | etims_failure | daily_summary
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 160);
            $table->text('body');
            $table->jsonb('data')->nullable();         // link to the screen that acts on it, figures
            $table->string('dedupe_key', 160)->nullable(); // the same thing is not alerted twice
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['type', 'created_at']);
            $table->index(['dedupe_key', 'created_at']);
        });

        Schema::create('alert_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('read_at')->nullable();

            $table->unique(['alert_id', 'user_id']);
            $table->index(['user_id', 'read_at']);
        });

        // Messages to send outside the app. Sent by `notifications:send` (every minute).
        Schema::create('outbound_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 20);             // sms | whatsapp | email
            $table->string('recipient', 150);          // +2547… or an email address
            $table->string('subject', 160)->nullable();
            $table->text('body');
            $table->string('status', 20)->default('pending'); // pending | sent | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('driver', 30)->nullable();  // which gateway sent it (log = demo, nothing left the server)
            $table->string('provider_ref', 100)->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_messages');
        Schema::dropIfExists('alert_recipients');
        Schema::dropIfExists('alerts');
    }
};
