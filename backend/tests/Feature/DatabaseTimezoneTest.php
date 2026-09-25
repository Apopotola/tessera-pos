<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Timestamps written by Laravel (no offset) and by Postgres (now()) must be the same instant. */
class DatabaseTimezoneTest extends TestCase
{
    public function test_app_written_timestamps_keep_their_instant(): void
    {
        $this->assertSame(config('app.timezone'), DB::selectOne('SHOW timezone')->TimeZone);

        $written = Carbon::now()->startOfSecond();
        $read = DB::selectOne('SELECT extract(epoch FROM ?::timestamptz)::bigint AS epoch, extract(epoch FROM now())::bigint AS db_now', [$written->format('Y-m-d H:i:s')]);

        $this->assertSame($written->getTimestamp(), (int) $read->epoch);
        $this->assertEqualsWithDelta($written->getTimestamp(), (int) $read->db_now, 5);
    }
}
