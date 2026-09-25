<?php

namespace Modules\AuditTrail\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Roles;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_logger_redacts_secrets_from_snapshots(): void
    {
        $user = User::factory()->create();

        $entry = app(AuditLogger::class)->log(
            'users.updated',
            $user,
            before: ['name' => 'Old', 'password' => 'secret-hash'],
            after: ['name' => 'New', 'password' => 'new-hash'],
            reason: 'Name correction',
            userId: $user->id,
        );

        $this->assertSame(['name' => 'Old'], $entry->before);
        $this->assertSame(['name' => 'New'], $entry->after);
    }

    public function test_database_rejects_updates_to_audit_rows(): void
    {
        $entry = app(AuditLogger::class)->log('test.event');

        $this->expectException(QueryException::class);
        DB::table('audit_logs')->where('id', $entry->id)->update(['action' => 'tampered']);
    }

    public function test_database_rejects_deletes_of_audit_rows(): void
    {
        $entry = app(AuditLogger::class)->log('test.event');

        $this->expectException(QueryException::class);
        DB::table('audit_logs')->where('id', $entry->id)->delete();
    }

    public function test_cashier_cannot_read_the_audit_log(): void
    {
        $cashier = User::factory()->create();
        $cashier->assignRole(Roles::CASHIER);

        $this->actingAs($cashier)->getJson('/api/v1/audit-trail/logs')->assertForbidden();
    }

    public function test_owner_reads_paginated_audit_log_newest_first(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole(Roles::OWNER);
        app(AuditLogger::class)->log('first.event', userId: $owner->id);
        app(AuditLogger::class)->log('second.event', userId: $owner->id);

        $this->actingAs($owner)->getJson('/api/v1/audit-trail/logs?per_page=1')
            ->assertOk()
            ->assertJsonPath('data.items.0.action', 'second.event')
            ->assertJsonPath('data.meta.perPage', 1)
            ->assertJsonPath('data.meta.total', 2);
    }
}
