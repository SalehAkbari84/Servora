<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Best-effort response compression. Negotiates the strongest encoding the
 * client supports (Brotli > Gzip > identity) and skips the work entirely
 * when the body is tiny — compression overhead on a sub-1KB JSON payload
 * is net negative for latency.
 *
 * Why not let nginx/Apache do it? In dev (and many small deployments) we
 * run PHP-FPM behind nothing, so without this middleware payloads ship
 * uncompressed even when the browser is asking for gzip.
 *
 * Production tip: if you have nginx in front with `gzip on`, this becomes
 * a no-op because nginx will set Content-Encoding before this middleware
 * runs against an already-encoded response.
 */
class CompressResponse
{
    private const MIN_BYTES = 1024;        // skip tiny payloads — overhead > win
    private const GZIP_LEVEL = 6;          // 1=fastest, 9=smallest; 6 is the sweet spot

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only compress text-ish responses
        $contentType = (string) $response->headers->get('Content-Type', '');
        if (!preg_match('#^(application/(json|javascript|xml)|text/)#i', $contentType)) {
            return $response;
        }

        // Skip if already encoded upstream (e.g. nginx beat us to it)
        if ($response->headers->has('Content-Encoding')) {
            return $response;
        }

        $body = $response->getContent();
        if (!is_string($body) || strlen($body) < self::MIN_BYTES) {
            return $response;
        }

        $accept = strtolower((string) $request->headers->get('Accept-Encoding', ''));

        // Prefer Brotli when the runtime supports it (PHP brotli extension).
        if (str_contains($accept, 'br') && function_exists('brotli_compress')) {
            $compressed = brotli_compress($body, 5);  // quality 5 = balanced
            if ($compressed !== false) {
                $response->setContent($compressed);
                $response->headers->set('Content-Encoding', 'br');
                $response->headers->set('Content-Length', (string) strlen($compressed));
                $response->headers->set('Vary', $this->mergeVary($response, 'Accept-Encoding'));
                return $response;
            }
        }

        if (str_contains($accept, 'gzip')) {
            $compressed = gzencode($body, self::GZIP_LEVEL);
            if ($compressed !== false) {
                $response->setContent($compressed);
                $response->headers->set('Content-Encoding', 'gzip');
                $response->headers->set('Content-Length', (string) strlen($compressed));
                $response->headers->set('Vary', $this->mergeVary($response, 'Accept-Encoding'));
            }
        }

        return $response;
    }

    /** Merge the existing Vary header with a new field. */
    private function mergeVary(Response $r, string $field): string
    {
        $existing = (string) $r->headers->get('Vary', '');
        if ($existing === '') return $field;
        $parts = array_map('trim', explode(',', $existing));
        if (!in_array($field, $parts, true)) $parts[] = $field;
        return implode(', ', $parts);
    }
}
