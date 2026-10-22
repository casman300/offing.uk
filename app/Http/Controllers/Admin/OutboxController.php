<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OutboxMessage;
use App\Support\Push\PushSender;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /admin/outbox (#11 slice): what the reminder pipeline has queued, sent,
 * skipped or failed, and why. Shows birthday dates but never people's names
 * or notes — those are data about third parties.
 */
class OutboxController extends Controller
{
    public const STATUSES = [OutboxMessage::PENDING, OutboxMessage::SENT, OutboxMessage::SKIPPED, OutboxMessage::FAILED];

    public function index(Request $request, PushSender $push): Response
    {
        $pushStatus = $push->status();

        $status = in_array($request->query('status'), self::STATUSES, true) ? $request->query('status') : null;

        return Inertia::render('admin/outbox', [
            'status' => $status,
            // Shown as a banner when push is meant to work here but can't.
            'pushProblem' => $pushStatus->configured && ! $pushStatus->ready ? $pushStatus->reason : null,
            'counts' => OutboxMessage::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'messages' => OutboxMessage::query()
                ->with('user:id,email')
                ->when($status, fn ($q) => $q->where('status', $status))
                ->latest('send_at')
                ->limit(100)
                ->get()
                ->map(fn (OutboxMessage $m) => [
                    'id' => $m->id,
                    'user' => $m->user?->email,
                    'channel' => $m->channel,
                    'slot' => $m->payload['slot'] ?? null,
                    'birthday' => $m->payload['date'] ?? null,
                    'send_at' => $m->send_at->toIso8601String(),
                    'status' => $m->status,
                    'attempts' => $m->attempts,
                    'next_attempt_at' => $m->next_attempt_at?->toIso8601String(),
                    'sent_at' => $m->sent_at?->toIso8601String(),
                    'delivered_via' => $m->delivered_via,
                    'last_error' => $m->last_error,
                ]),
        ]);
    }
}
