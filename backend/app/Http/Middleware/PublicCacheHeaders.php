<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Setting;

/**
 * Attach Cache-Control headers + a strong ETag to public, anonymous,
 * read-only endpoints (homepage stats, category list, business list, etc.).
 *
 * The TTL is read from the admin-controllable `cache_public_seconds` setting
 * and defaults to 60 seconds. Setting it to 0 turns caching off for
 * incidents / debugging without touching the code.
 *
 * Pipeline:
 *   1. Run the downstream handler so we have the body.
 *   2. Compute md5(body) — gives clients a strong validator for 304s.
 *   3. If the request's If-None-Match matches, return 304 with no body.
 *   4. Otherwise set Cache-Control: public, max-age=<ttl>, stale-while-revalidate.
 *
 * NEVER attached to authenticated routes — different users see different
 * payloads, so caching them publicly would leak data.
 */
class PublicCacheHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        // Bail on non-GET or non-success — those are not safe to cache.
        if ($request->getMethod() !== 'GET' || $response->getStatusCode() !== 200) {
            return $response;
        }

        $ttl = (int) Setting::get('cache_public_seconds', '60');
        if ($ttl <= 0) {
            // Caching disabled — make it explicit so reverse proxies don't keep stale data.
            $response->headers->set('Cache-Control', 'no-store');
            return $response;
        }

        $body = $response->getContent() ?: '';
        $etag = '"' . md5($body) . '"';
        $response->headers->set('ETag', $etag);
        // SWR window of twice the TTL — clients can use the stale copy while revalidating.
        $response->headers->set(
            'Cache-Control',
            "public, max-age={$ttl}, stale-while-revalidate=" . ($ttl * 2),
        );

        $ifNone = $request->headers->get('If-None-Match');
        if ($ifNone && trim($ifNone) === $etag) {
            // 304 — bandwidth-saving short-circuit. Body must be empty.
            return response('', 304, $response->headers->all());
        }

        return $response;
    }
}
