<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\NotificationOutbox;
use App\Models\Setting;
use App\Services\Sms\SmsIrClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Outbox worker — drains `notification_outbox`:
 *   - delivers each row via sms.ir
 *   - copies to `notifications` inbox (dedup'd by idempotency_key + title)
 *   - marks delivered / failed accordingly
 *
 * Resilience model:
 *   - DB stored procedures (CreateAppointment, AddToQueue, VerifyBusiness)
 *     and triggers enqueue rows here with status='pending'. Booking flows
 *     return immediately and don't wait on SMS.
 *   - This worker is scheduled every minute (routes/console.php). It picks
 *     up pending rows whose `next_retry_at` is null or in the past.
 *   - When sms.ir is down it returns `transient=true`. The worker treats
 *     that as a re-try (exponential back-off: 1, 2, 4, 8, 16 minutes —
 *     max 5 attempts ≈ 31 minutes total). The user_phone column was set
 *     at enqueue time so it stays correct even if the user's profile phone
 *     changes later.
 *   - Permanent failures (template missing, bad credentials, …) burn the
 *     retries quickly and the row is marked 'failed'. The inbox copy is
 *     still written so the user sees the notification in the bell.
 *
 * Dedup safeguards:
 *   - `notification_outbox.idempotency_key` has a UNIQUE index so SP/trigger
 *     callers can't enqueue the same event twice.
 *   - We also dedup the inbox copy by (user_id, type, title, entity) within
 *     a 6-hour window — covers the case where someone re-runs a migration
 *     that triggers existing rows.
 *   - The worker locks rows with `lockForUpdate()` inside a transaction so
 *     two concurrent worker invocations can't double-process the same row.
 */
class ProcessOutboxCommand extends Command
{
    protected $signature   = 'outbox:process {--limit=50 : Max rows per run}';
    protected $description = 'Deliver pending notification_outbox entries (SMS + inbox copy)';

    private const MAX_ATTEMPTS = 5;

    public function handle(): int
    {
        $limit  = (int) $this->option('limit');
        $client = SmsIrClient::fromSettings();

        // Pull pending rows. Order by created_at so a slow gateway doesn't
        // starve older messages while newer ones keep getting enqueued.
        $rows = NotificationOutbox::where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('next_retry_at')
                  ->orWhere('next_retry_at', '<=', now());
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->pluck('id');

        if ($rows->isEmpty()) {
            $this->info('No pending outbox rows.');
            return self::SUCCESS;
        }

        $delivered = 0;
        $retrying  = 0;
        $failed    = 0;

        foreach ($rows as $rowId) {
            // Lock + claim the row atomically. If another worker has already
            // started on it, our SELECT will return null (status != pending)
            // and we'll skip — no double-send.
            $result = DB::transaction(function () use ($rowId) {
                $row = NotificationOutbox::lockForUpdate()->find($rowId);
                if (!$row || $row->status !== 'pending') return null;
                $row->update(['status' => 'processing']);
                return $row;
            });
            if (!$result) continue;

            $outcome = $this->deliverOne($result, $client);
            match ($outcome) {
                'delivered' => $delivered++,
                'retrying'  => $retrying++,
                'failed'    => $failed++,
            };
        }

        $this->info("outbox: delivered={$delivered}  retrying={$retrying}  failed={$failed}");
        return self::SUCCESS;
    }

    /**
     * Deliver a single row. Returns 'delivered' | 'retrying' | 'failed'.
     */
    private function deliverOne(NotificationOutbox $row, SmsIrClient $client): string
    {
        // Always copy to inbox first — even when SMS is unavailable, the
        // user should see the event in the bell.
        $this->copyToInbox($row);

        // If SMS provider isn't configured, mark delivered (inbox-only mode).
        // The user notification is still landed via the inbox copy above.
        $templateId = (int) Setting::get('sms_ir_notification_template_id', '0');
        if ($templateId <= 0) {
            $row->update(['status' => 'delivered', 'processed_at' => now()]);
            return 'delivered';
        }

        // Skip rows with a junk phone. Better to mark failed than waste a
        // sms.ir credit on a guaranteed-failure send.
        if (!$this->validPhone($row->user_phone)) {
            $row->update(['status' => 'failed', 'processed_at' => now()]);
            Log::warning('outbox.bad_phone', ['outbox_id' => $row->id, 'phone' => $row->user_phone]);
            return 'failed';
        }

        // Send. The body that lands in SMS is title (first 80 chars).
        $text   = $row->title ?: $row->body;
        $result = $client->sendNotification($row->user_phone, $text);

        if ($result['success']) {
            $row->update(['status' => 'delivered', 'processed_at' => now()]);
            return 'delivered';
        }

        // Failed. If the failure looks transient (network / 5xx) AND we have
        // attempts left, re-arm for a later retry.
        $attempts = $row->attempt_count + 1;
        $maxedOut = $attempts >= self::MAX_ATTEMPTS;
        $transient = !empty($result['transient']);

        if ($transient && !$maxedOut) {
            // Exponential back-off: 1, 2, 4, 8, 16 minutes
            $minutes = 2 ** ($attempts - 1);
            $row->update([
                'status'        => 'pending',
                'attempt_count' => $attempts,
                'next_retry_at' => now()->addMinutes($minutes),
            ]);
            return 'retrying';
        }

        // Permanent error OR retries exhausted — give up. Inbox copy was
        // already written so the user still sees the event.
        $row->update([
            'status'        => 'failed',
            'attempt_count' => $attempts,
            'processed_at'  => now(),
        ]);
        Log::warning('outbox.permanent_failure', [
            'outbox_id' => $row->id,
            'reason'    => $result['message'] ?? 'unknown',
            'attempts'  => $attempts,
        ]);
        return 'failed';
    }

    /**
     * Idempotent inbox write. Skip if the SAME user already has a row with
     * the same title + entity within the last 6 hours (covers re-runs after
     * a migration / restart). The 6-hour window is conservative — the
     * legitimate dedup is by idempotency_key in the outbox row above.
     */
    private function copyToInbox(NotificationOutbox $row): void
    {
        $exists = AppNotification::where('user_id', $row->user_id)
            ->where('type', $row->type)
            ->where('title', $row->title)
            ->where('created_at', '>=', now()->subHours(6))
            ->when($row->related_entity_id, fn ($q) => $q
                ->where('related_entity_type', $row->related_entity_type)
                ->where('related_entity_id',   $row->related_entity_id))
            ->exists();
        if ($exists) return;

        AppNotification::create([
            'user_id'             => $row->user_id,
            'user_phone'          => $row->user_phone,
            'type'                => $row->type,
            'title'               => $row->title,
            'body'                => $row->body,
            'related_entity_type' => $row->related_entity_type,
            'related_entity_id'   => $row->related_entity_id,
            'is_read'             => false,
        ]);
    }

    /** Strict Iranian mobile check — same shape we accept at registration. */
    private function validPhone(?string $phone): bool
    {
        if (!$phone) return false;
        return (bool) preg_match('/^09\d{9}$/', $phone);
    }
}
