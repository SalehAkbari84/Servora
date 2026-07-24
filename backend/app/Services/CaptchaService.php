<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Self-hosted CAPTCHA: server renders a distorted SVG with 5 characters,
 * stores a hash of the answer keyed by a random `token`, and verifies it
 * on next submission.
 *
 * Why SVG and not PNG:
 *   - no GD/Imagick dependency (Windows PHP often lacks GD)
 *   - vector = sharp on all DPIs, smaller payload
 *   - easy to add noise (random paths) without image libraries
 *
 * Why custom and not reCAPTCHA/Turnstile:
 *   - those services are often blocked or unstable inside Iran
 *   - this version is fully self-contained and works offline
 */
class CaptchaService
{
    /** Excluded confusing chars: 0/O, 1/I/l, etc. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const LENGTH   = 5;
    private const TTL_MIN  = 5;

    /**
     * Generate a new captcha. Returns the opaque `token` (for resubmission)
     * and an SVG string ready to drop into the page.
     *
     * @return array{token: string, svg: string}
     */
    public function generate(?string $ip = null): array
    {
        $answer = $this->randomString(self::LENGTH);
        $token  = (string) Str::uuid();

        DB::table('captchas')->insert([
            'token'       => $token,
            'answer_hash' => hash('sha256', strtoupper($answer)),
            'expires_at'  => now()->addMinutes(self::TTL_MIN),
            'ip'          => $ip,
            'created_at'  => now(),
        ]);

        // Prune expired rows opportunistically (1-in-25 chance per generate)
        if (random_int(1, 25) === 1) {
            DB::table('captchas')->where('expires_at', '<', now()->subHour())->delete();
        }

        return ['token' => $token, 'svg' => $this->renderSvg($answer)];
    }

    /**
     * Verify a user's answer for a given token. Single-use — successful
     * verification marks the row consumed so a leaked token can't be replayed.
     */
    public function verify(string $token, string $answer): bool
    {
        $row = DB::table('captchas')->where('token', $token)->first();
        if (!$row) return false;

        if ($row->consumed_at !== null) return false;
        if (strtotime($row->expires_at) < time()) return false;

        // Per-row attempt cap to prevent brute-force of the captcha itself.
        if ($row->attempts >= 5) return false;

        if (!hash_equals($row->answer_hash, hash('sha256', strtoupper(trim($answer))))) {
            DB::table('captchas')->where('token', $token)->increment('attempts');
            return false;
        }

        DB::table('captchas')->where('token', $token)->update(['consumed_at' => now()]);
        return true;
    }

    /**
     * Pick `len` chars from the alphabet uniformly.
     */
    private function randomString(int $len): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < $len; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }
        return $out;
    }

    /**
     * Render the captcha as an SVG string.
     *
     * Layout (180×60 px viewBox):
     *   - light grey background
     *   - 4 random sinusoidal lines (noise)
     *   - 60 small dots (more noise)
     *   - each character rotated ±15° and offset ±3px
     *   - characters get one of 4 brand colors so OCR can't easily threshold
     *
     * The output stays under ~1KB so it's cheap to inline in the response.
     */
    private function renderSvg(string $text): string
    {
        $W = 180; $H = 60;
        $colors = ['#4f46e5', '#7c3aed', '#0891b2', '#0d9488', '#dc2626'];

        $bg = '<rect width="100%" height="100%" fill="#f3f4f6"/>';

        // Noise — sinusoidal polylines
        $lines = '';
        for ($i = 0; $i < 4; $i++) {
            $points = [];
            $amp    = random_int(4, 10);
            $period = random_int(20, 40);
            $yMid   = random_int(15, $H - 15);
            for ($x = 0; $x <= $W; $x += 4) {
                $y = $yMid + (int) round($amp * sin($x / $period * M_PI));
                $points[] = "$x,$y";
            }
            $color = $colors[array_rand($colors)];
            $lines .= '<polyline fill="none" stroke="' . $color . '" stroke-opacity="0.35" stroke-width="1.2" points="'
                   . implode(' ', $points) . '"/>';
        }

        // Dot noise
        $dots = '';
        for ($i = 0; $i < 60; $i++) {
            $cx = random_int(0, $W); $cy = random_int(0, $H);
            $r  = random_int(1, 2);
            $color = $colors[array_rand($colors)];
            $dots .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="' . $color . '" fill-opacity="0.4"/>';
        }

        // Characters
        $chars = '';
        $count = strlen($text);
        $slot  = (int) ($W / ($count + 1));
        for ($i = 0; $i < $count; $i++) {
            $ch  = $text[$i];
            $cx  = $slot * ($i + 1) + random_int(-4, 4);
            $cy  = (int) ($H / 2) + random_int(-2, 6);
            $rot = random_int(-18, 18);
            $color = $colors[array_rand($colors)];
            $fs  = random_int(28, 34);
            $chars .= '<text x="' . $cx . '" y="' . $cy . '"'
                   . ' font-family="Vazirmatn, Tahoma, sans-serif"'
                   . ' font-size="' . $fs . '" font-weight="700"'
                   . ' fill="' . $color . '"'
                   . ' text-anchor="middle" dominant-baseline="middle"'
                   . ' transform="rotate(' . $rot . ' ' . $cx . ' ' . $cy . ')">'
                   . htmlspecialchars($ch, ENT_QUOTES) . '</text>';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $W . ' ' . $H . '"'
             . ' width="100%" height="60" preserveAspectRatio="xMidYMid meet">'
             . $bg . $lines . $dots . $chars
             . '</svg>';
    }
}
