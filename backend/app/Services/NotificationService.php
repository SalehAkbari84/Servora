<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Services;

use App\Models\AppNotification;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Writes notifications directly to the user's inbox for **instant** in-app
 * delivery, and fans out a Web Push notification to any registered devices
 * (so the user gets a phone/desktop notification even with the site closed).
 *
 * Note: this service does NOT write to `notification_outbox`. The relevant
 * stored procedures (CreateAppointment, CancelAppointment, VerifyBusiness)
 * and the `trg_appointments_after_update` trigger already enqueue outbox
 * rows for async SMS delivery. The outbox worker's dedup logic then sees
 * the inbox row we wrote here and skips its own inbox copy, leaving only
 * SMS delivery — so each event produces exactly ONE inbox notification
 * and at most ONE SMS attempt.
 */
class NotificationService
{
    /**
     * Push a notification immediately to the user's inbox AND to web push
     * subscriptions.
     *
     * @param array{
     *   user_id: int,
     *   type: string,
     *   title: string,
     *   body: string,
     *   related_entity_type?: string|null,
     *   related_entity_id?: int|null,
     *   url?: string|null,
     * } $data
     */
    public function push(array $data): void
    {
        $phone = User::where('id', $data['user_id'])->value('phone') ?? '';

        // Race-condition guard: skip only if an IDENTICAL notification (same
        // title — same event) was just created within 2 seconds. Different
        // events on the same entity (e.g. booking vs confirming a single
        // appointment) keep distinct titles, so they are NOT deduplicated.
        $exists = AppNotification::where('user_id', $data['user_id'])
            ->where('title', $data['title'])
            ->where('created_at', '>=', now()->subSeconds(2))
            ->exists();
        if ($exists) return;

        AppNotification::create([
            'user_id'             => $data['user_id'],
            'user_phone'          => $phone,
            'type'                => $data['type'],
            'title'               => $data['title'],
            'body'                => $data['body'],
            'url'                 => $data['url'] ?? null,
            'related_entity_type' => $data['related_entity_type'] ?? null,
            'related_entity_id'   => $data['related_entity_id'] ?? null,
            'is_read'             => false,
        ]);

        $this->sendWebPush($data);
    }

    /**
     * Fan out a Web Push to every subscription the user has registered.
     * Failures are logged but never bubble up — push is best-effort and must
     * never break the original action (booking, status change, …).
     *
     * Subscriptions that the push service reports as expired/gone are pruned
     * so we don't keep hammering dead endpoints.
     */
    private function sendWebPush(array $data): void
    {
        $publicKey  = (string) env('VAPID_PUBLIC_KEY');
        $privateKey = (string) env('VAPID_PRIVATE_KEY');
        $subject    = (string) env('VAPID_SUBJECT', 'mailto:admin@servora.ir');

        if ($publicKey === '' || $privateKey === '') return;

        $subs = PushSubscription::where('user_id', $data['user_id'])->get();
        if ($subs->isEmpty()) return;

        try {
            // Bundled CA cert path — PHP on Windows often has no default CA
            // bundle, which makes the FCM HTTPS handshake fail with cURL 60.
            $caBundle = storage_path('certs/cacert.pem');
            $clientOptions = is_file($caBundle) ? ['verify' => $caBundle] : [];

            $webPush = new WebPush(
                [
                    'VAPID' => [
                        'subject'    => $subject,
                        'publicKey'  => $publicKey,
                        'privateKey' => $privateKey,
                    ],
                ],
                [],
                30,
                $clientOptions,
            );

            $payload = json_encode([
                'title' => $data['title'],
                'body'  => $data['body'],
                'url'   => $data['url'] ?? $this->urlFor($data),
                'type'  => $data['type'],
            ], JSON_UNESCAPED_UNICODE);

            foreach ($subs as $sub) {
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint'        => $sub->endpoint,
                        'publicKey'       => $sub->p256dh,
                        'authToken'       => $sub->auth,
                        'contentEncoding' => 'aesgcm',
                    ]),
                    $payload,
                );
            }

            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) continue;

                // 404/410 = subscription gone — clean it up
                if ($report->isSubscriptionExpired()) {
                    PushSubscription::where('endpoint_hash', hash('sha256', $report->getEndpoint()))
                        ->delete();
                    continue;
                }

                Log::warning('webpush.delivery_failed', [
                    'endpoint' => $report->getEndpoint(),
                    'reason'   => $report->getReason(),
                ]);
            }
        } catch (Throwable $e) {
            Log::error('webpush.exception', ['msg' => $e->getMessage()]);
        }
    }

    /**
     * Pick a deep-link inside the app for the notification's click target.
     * Defaults to the user's appointments page; verification/queue events
     * have their own canonical pages.
     */
    private function urlFor(array $data): string
    {
        $entity = $data['related_entity_type'] ?? null;
        return match ($entity) {
            'business_verifications' => '/owner/verification',
            'queue_entries'          => '/profile/queue',
            default                  => '/profile/appointments',
        };
    }
}
