<?php

namespace Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Authorization\Support\Permissions;
use Modules\Notifications\Models\AlertRecipient;
use Modules\Notifications\Models\OutboundMessage;
use OpenApi\Attributes as OA;

/** The signed-in user's alerts (the bell), and the log of SMS / WhatsApp / email sent. */
class NotificationController extends Controller
{
    #[OA\Get(path: '/api/v1/notifications', summary: 'My latest alerts and how many are unread', tags: ['Notifications'], responses: [new OA\Response(response: 200, description: '{unread, items}')])]
    public function index(Request $request): JsonResponse
    {
        $mine = AlertRecipient::query()->where('user_id', $request->user()->id);
        $items = (clone $mine)->with('alert')->orderByDesc('id')->limit(40)->get()->map(fn (AlertRecipient $r) => [
            'id' => $r->id,
            'type' => $r->alert->type,
            'title' => $r->alert->title,
            'body' => $r->alert->body,
            'link' => $r->alert->data['link'] ?? null,
            'createdAt' => $r->alert->created_at->toIso8601String(),
            'readAt' => $r->read_at?->toIso8601String(),
        ]);

        return $this->success('Notifications.', ['unread' => (clone $mine)->whereNull('read_at')->count(), 'items' => $items]);
    }

    #[OA\Post(path: '/api/v1/notifications/{recipient}/read', summary: 'Mark one of my alerts read', tags: ['Notifications'], responses: [new OA\Response(response: 200, description: 'Read')])]
    public function read(Request $request, AlertRecipient $recipient): JsonResponse
    {
        abort_unless($recipient->user_id === $request->user()->id, 404);
        $recipient->forceFill(['read_at' => $recipient->read_at ?? now()])->save();

        return $this->success('Marked read.');
    }

    #[OA\Post(path: '/api/v1/notifications/read-all', summary: 'Mark all my alerts read', tags: ['Notifications'], responses: [new OA\Response(response: 200, description: 'Read')])]
    public function readAll(Request $request): JsonResponse
    {
        AlertRecipient::query()->where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return $this->success('All marked read.');
    }

    #[OA\Get(path: '/api/v1/notifications/messages', summary: 'SMS, WhatsApp and email sent (or waiting) — owners', tags: ['Notifications'], responses: [new OA\Response(response: 200, description: 'Paginated')])]
    public function messages(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::SETTINGS_BUSINESS), 403);
        $page = OutboundMessage::query()->orderByDesc('id')->paginate(50);
        $page->setCollection($page->getCollection()->map(fn (OutboundMessage $m) => [
            'id' => $m->id,
            'channel' => $m->channel,
            'recipient' => $m->recipient,
            'subject' => $m->subject,
            'body' => $m->body,
            'status' => $m->status,
            'driver' => $m->driver,
            'attempts' => $m->attempts,
            'lastError' => $m->last_error,
            'createdAt' => $m->created_at?->toIso8601String(),
            'sentAt' => $m->sent_at?->toIso8601String(),
        ]));

        return $this->success('Messages.', $this->paginated($page));
    }
}
