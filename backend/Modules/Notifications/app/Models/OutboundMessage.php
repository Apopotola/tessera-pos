<?php

namespace Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;

/** An SMS, WhatsApp message or email waiting to go out, or already sent. */
class OutboundMessage extends Model
{
    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const MAX_ATTEMPTS = 5;

    protected $fillable = ['alert_id', 'user_id', 'channel', 'recipient', 'subject', 'body', 'status', 'attempts', 'driver', 'provider_ref', 'last_error', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime'];
    }
}
