<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Sms\SmsIrClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 1-to-1 chat between a customer and a business owner.
 *
 * The authenticated user is either:
 *   - The conversation's `user_id` (= customer side), OR
 *   - The owner of the conversation's business (= owner side).
 *
 * Both can post messages and read; the controller picks the correct
 * sender_type and updates the appropriate unread counter automatically.
 */
class ChatController extends Controller
{
    private const MSG_MAX_LEN = 1000;
    private const MSG_PER_PAGE = 50;

    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * GET /api/businesses/{id}/conversation — customer opens chat with a
     * business. Creates the conversation row on demand. Returns the
     * conversation + the latest page of messages.
     */
    public function openWithBusiness(Request $request, int $businessId): JsonResponse
    {
        $user = $request->user();
        $biz  = Business::where('id', $businessId)->where('is_verified', true)->where('is_active', true)->first();
        if (!$biz) return $this->fail('کسب‌وکار یافت نشد', 404);

        // Self-chat doesn't make sense
        if ($biz->owner_user_id === $user->id) {
            return $this->fail('با کسب‌وکار خودتان نمی‌توانید گفت‌وگو کنید', 422);
        }

        $conv = Conversation::firstOrCreate(
            ['user_id' => $user->id, 'business_id' => $biz->id],
            [
                'business_name' => $biz->name,
                'user_name'     => $user->full_name,
            ],
        );

        // Mark all owner-sent messages as read by user since they're opening it
        $this->markRead($conv, viewer: 'user');

        return $this->ok([
            'conversation' => $this->formatConv($conv),
            'messages'     => $this->messagesPage($conv->id),
        ]);
    }

    /**
     * GET /api/conversations/{id}/messages — paginated message log.
     * Caller must be either the conversation's user or the business owner.
     */
    public function listMessages(Request $request, int $conversationId): JsonResponse
    {
        $conv = Conversation::find($conversationId);
        if (!$conv) return $this->fail('گفت‌وگو یافت نشد', 404);

        $role = $this->roleFor($request->user()->id, $conv);
        if (!$role) return $this->fail('دسترسی غیرمجاز', 403);

        // Opening the message list marks the OTHER side's messages as read.
        $this->markRead($conv, viewer: $role);

        return $this->ok([
            'conversation' => $this->formatConv($conv->fresh(), $role),
            'messages'     => $this->messagesPage($conv->id, $request->query('before_id')),
        ]);
    }

    /**
     * POST /api/conversations/{id}/messages — send a message.
     * Body: { body: string }
     */
    public function sendMessage(Request $request, int $conversationId): JsonResponse
    {
        $conv = Conversation::find($conversationId);
        if (!$conv) return $this->fail('گفت‌وگو یافت نشد', 404);

        $role = $this->roleFor($request->user()->id, $conv);
        if (!$role) return $this->fail('دسترسی غیرمجاز', 403);

        $data = $request->validate([
            'body' => 'required|string|min:1|max:' . self::MSG_MAX_LEN,
        ]);

        $msg = DB::transaction(function () use ($conv, $request, $data, $role) {
            $msg = Message::create([
                'conversation_id' => $conv->id,
                'sender_id'       => $request->user()->id,
                'sender_type'     => $role,
                'body'            => trim($data['body']),
                'is_read'         => false,
            ]);

            // Update conversation header counters atomically
            $counter = $role === 'user' ? 'unread_for_owner' : 'unread_for_user';
            $conv->update([
                'last_message_at'      => $msg->created_at,
                'last_message_preview' => mb_substr($msg->body, 0, 200),
                'last_message_sender'  => $role,
                $counter               => $conv->{$counter} + 1,
            ]);

            return $msg;
        });

        // Ping the other side with an in-app notification
        $this->notifyOther($conv, $role, $msg);

        return $this->ok($this->formatMessage($msg));
    }

    /**
     * PATCH /api/conversations/{id}/read — explicitly mark messages read.
     */
    public function markAllRead(Request $request, int $conversationId): JsonResponse
    {
        $conv = Conversation::find($conversationId);
        if (!$conv) return $this->fail('گفت‌وگو یافت نشد', 404);

        $role = $this->roleFor($request->user()->id, $conv);
        if (!$role) return $this->fail('دسترسی غیرمجاز', 403);

        $this->markRead($conv, viewer: $role);
        return $this->ok(['ok' => true]);
    }

    // ────────────────────────────────────────────────────────────
    // Owner endpoints
    // ────────────────────────────────────────────────────────────

    /**
     * GET /api/owner/conversations — list of conversations for the
     * authenticated owner's business, ordered by most-recent activity.
     */
    public function listOwnerConversations(Request $request): JsonResponse
    {
        $biz = Business::where('owner_user_id', $request->user()->id)->first();
        if (!$biz) return $this->fail('کسب‌وکاری یافت نشد', 404);

        $convs = Conversation::where('business_id', $biz->id)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(50);

        return $this->ok($convs);
    }

    /**
     * GET /api/owner/conversations/unread — single integer: total unread
     * messages across all the owner's conversations. Used by the nav badge
     * and polled every few seconds for near-real-time feel.
     */
    public function ownerUnreadCount(Request $request): JsonResponse
    {
        $biz = Business::where('owner_user_id', $request->user()->id)->first();
        if (!$biz) return $this->ok(['unread' => 0]);

        $unread = (int) Conversation::where('business_id', $biz->id)
            ->sum('unread_for_owner');

        return $this->ok(['unread' => $unread]);
    }

    /** GET /api/conversations/unread — total unread for the user side. */
    public function userUnreadCount(Request $request): JsonResponse
    {
        $unread = (int) Conversation::where('user_id', $request->user()->id)
            ->sum('unread_for_user');
        return $this->ok(['unread' => $unread]);
    }

    // ────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────

    /**
     * Return 'user' if the caller IS the conversation's user_id,
     * 'owner' if the caller owns the business, null otherwise.
     */
    private function roleFor(int $callerId, Conversation $conv): ?string
    {
        if ($conv->user_id === $callerId) return 'user';
        $biz = Business::find($conv->business_id);
        if ($biz && $biz->owner_user_id === $callerId) return 'owner';
        return null;
    }

    /** Mark all unread messages from the OTHER side as read + zero counter. */
    private function markRead(Conversation $conv, string $viewer): void
    {
        $otherSide = $viewer === 'user' ? 'owner' : 'user';
        DB::transaction(function () use ($conv, $viewer, $otherSide) {
            Message::where('conversation_id', $conv->id)
                ->where('sender_type', $otherSide)
                ->where('is_read', false)
                ->update(['is_read' => true]);

            $counter = $viewer === 'user' ? 'unread_for_user' : 'unread_for_owner';
            $conv->update([$counter => 0]);
        });
    }

    /**
     * Fan out a "new message" notification to the OTHER side of the chat.
     *
     * Three parallel delivery channels:
     *   1. In-app inbox entry (bell badge) — always.
     *   2. Web Push (browser / mobile notification) — always, fire-and-forget
     *      via NotificationService (best-effort, never throws).
     *   3. SMS — ONLY when the recipient is a business owner AND has been
     *      offline (no API activity in the last 2 minutes). Customers never
     *      get chat SMS by design — only owners (paid service tier).
     *
     * Click-target URLs:
     *   - Owner side  → /owner/messages?conv={id}     (Telegram-style page)
     *   - Customer    → /businesses/{business_id}     (re-opens chat widget)
     */
    private function notifyOther(Conversation $conv, string $senderRole, Message $msg): void
    {
        $recipientRole = $senderRole === 'user' ? 'owner' : 'user';

        // Resolve the recipient user_id + phone + name
        if ($recipientRole === 'owner') {
            $biz = Business::find($conv->business_id);
            if (!$biz) return;
            $recipientId    = $biz->owner_user_id;
            $recipientPhone = $biz->owner_phone ?? '';
            $senderName     = $conv->user_name ?: 'کاربر';
            $url            = "/owner/messages?conv={$conv->id}";
        } else {
            $recipientId    = $conv->user_id;
            $recipientPhone = ''; // populated by NotificationService from users table
            $senderName     = $conv->business_name ?: 'کسب‌وکار';
            $url            = "/businesses/{$conv->business_id}";
        }

        if (!$recipientId) return;

        // Truncate body preview so we don't ship a 1000-char SMS.
        $preview = mb_substr($msg->body, 0, 80);

        // 1) + 2) Inbox + Web Push via the central NotificationService
        $this->notifications->push([
            'user_id'             => $recipientId,
            'type'                => 'پیام_جدید',
            'title'               => "پیام جدید از {$senderName}",
            'body'                => $preview,
            'url'                 => $url,
            'related_entity_type' => 'conversations',
            'related_entity_id'   => $conv->id,
        ]);

        // 3) SMS fallback — owner side only, AND only when they look offline
        if ($recipientRole !== 'owner' || !$recipientPhone) return;

        $owner = User::find($recipientId);
        if (!$owner) return;

        // "Online" = a heartbeat from TouchLastSeen middleware in the last 2 min.
        // If they're sitting in /owner/messages right now they don't need an SMS.
        $isOnline = $owner->last_seen_at && $owner->last_seen_at->gt(now()->subMinutes(2));
        if ($isOnline) return;

        // Rate-limit per conversation: at most one chat SMS per recipient per
        // 10 minutes. Owner gets one ping, can come back online to read the
        // full thread — repeated bursts of SMS for every message would be spam.
        $recentSms = \App\Models\NotificationOutbox::where('user_id', $recipientId)
            ->where('type', 'پیام_جدید')
            ->where('created_at', '>=', now()->subMinutes(10))
            ->exists();
        if ($recentSms) return;

        try {
            // Enqueue — the outbox worker handles delivery + retries when
            // sms.ir is reachable. If the gateway is down right now, the
            // row stays as `pending` and gets retried automatically with
            // exponential back-off; the chat send returns instantly either
            // way. Idempotency_key is unique so a duplicate enqueue from
            // a retry can't double-send.
            \App\Models\NotificationOutbox::create([
                'idempotency_key'     => 'chat:' . $conv->id . ':' . $msg->id,
                'user_id'             => $recipientId,
                'user_phone'          => $recipientPhone,
                'type'                => 'پیام_جدید',
                'title'               => "پیام جدید از {$senderName}",
                'body'                => $preview,
                'related_entity_type' => 'conversations',
                'related_entity_id'   => $conv->id,
                'status'              => 'pending',
                'attempt_count'       => 0,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Most likely the unique idempotency_key clashed — that's fine,
            // we already enqueued this exact message somewhere upstream.
            if ((int) $e->getCode() !== 23000) {
                \Illuminate\Support\Facades\Log::warning('chat.sms.enqueue_failed', ['msg' => $e->getMessage()]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('chat.sms.exception', ['msg' => $e->getMessage()]);
        }
    }

    /**
     * One page of messages, oldest→newest. Pass `before_id` to load older
     * messages above the current view (infinite-scroll-up).
     */
    private function messagesPage(int $conversationId, ?int $beforeId = null): array
    {
        $q = Message::where('conversation_id', $conversationId);
        if ($beforeId) $q->where('id', '<', $beforeId);

        $rows = $q->orderByDesc('id')->limit(self::MSG_PER_PAGE)->get()->reverse()->values();
        return $rows->map(fn ($m) => $this->formatMessage($m))->all();
    }

    private function formatMessage(Message $m): array
    {
        return [
            'id'          => $m->id,
            'sender_type' => $m->sender_type,
            'body'        => $m->body,
            'is_read'     => $m->is_read,
            'created_at'  => $m->created_at->toISOString(),
        ];
    }

    private function formatConv(Conversation $conv, ?string $viewerRole = null): array
    {
        return [
            'id'                   => $conv->id,
            'business_id'          => $conv->business_id,
            'business_name'        => $conv->business_name,
            'user_id'              => $conv->user_id,
            'user_name'            => $conv->user_name,
            'last_message_at'      => $conv->last_message_at?->toISOString(),
            'last_message_preview' => $conv->last_message_preview,
            'unread'               => $viewerRole === 'owner'
                ? $conv->unread_for_owner
                : $conv->unread_for_user,
        ];
    }

    private function ok($data, string $message = '', int $code = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data, 'message' => $message, 'code' => $code], $code);
    }

    private function fail(string $message, int $code = 422): JsonResponse
    {
        return response()->json(['success' => false, 'data' => null, 'message' => $message, 'code' => $code], $code);
    }
}
