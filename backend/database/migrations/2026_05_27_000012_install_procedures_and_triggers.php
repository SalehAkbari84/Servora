<?php
// Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Installs the stored procedures, triggers, and scheduled events from
 * database/servora_complete.sql.
 *
 * Loaded from disk at run-time rather than copied inline so the SQL file
 * stays the canonical source for procedural code. The earlier
 * 2026_05_27_000001..11 migrations already created all 12 tables, so this
 * migration extracts ONLY the routines block (everything below the last
 * CREATE TABLE) and executes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sqlPath = base_path('../database/servora_complete.sql');
        if (!file_exists($sqlPath)) {
            // Fall back to inside the backend dir if user keeps a copy there
            $sqlPath = base_path('database/servora_complete.sql');
        }
        if (!file_exists($sqlPath)) {
            throw new RuntimeException("servora_complete.sql not found at {$sqlPath}");
        }

        $full = file_get_contents($sqlPath);

        // Strip the table-creation block (already handled by earlier migrations).
        // We keep everything from the first DROP EVENT/DROP PROCEDURE/DROP TRIGGER onward.
        $startMarkers = ['DROP EVENT IF EXISTS', 'DROP PROCEDURE IF EXISTS', 'DROP TRIGGER IF EXISTS', 'DROP FUNCTION IF EXISTS'];
        $startPos = false;
        foreach ($startMarkers as $marker) {
            $pos = strpos($full, $marker);
            if ($pos !== false && ($startPos === false || $pos < $startPos)) {
                $startPos = $pos;
            }
        }
        if ($startPos === false) {
            throw new RuntimeException('Could not find routines block in servora_complete.sql');
        }

        $routines = substr($full, $startPos);

        // MySQL routines use DELIMITER statements which mysqli/PDO don't understand.
        // Split on DELIMITER markers and execute each block separately.
        $blocks = $this->splitOnDelimiter($routines);

        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') continue;
            DB::unprepared($block);
        }
    }

    public function down(): void
    {
        $names = [
            'PROCEDURE' => [
                'CreateAppointment', 'CancelAppointment', 'AddToQueue',
                'VerifyBusiness', 'WriteAuditLog', 'WriteNotificationOutbox',
            ],
            'FUNCTION' => [
                'GetNextQueuePosition', 'IsSlotAvailable', 'CalcBusinessRating',
            ],
            'EVENT' => [
                'evt_expire_stale_queue', 'evt_cleanup_outbox', 'evt_reset_stuck_processing',
            ],
            'TRIGGER' => [
                // populated lazily — list from servora_complete.sql if more added
                'trg_reviews_after_insert', 'trg_reviews_after_update', 'trg_reviews_after_delete',
                'trg_appointments_after_update', 'trg_business_verification_after_update',
            ],
        ];
        foreach ($names as $kind => $items) {
            foreach ($items as $item) {
                DB::unprepared("DROP {$kind} IF EXISTS {$item}");
            }
        }
    }

    /**
     * Split SQL containing DELIMITER directives into executable blocks.
     * Each block ends at its custom delimiter, then the delimiter is stripped.
     */
    private function splitOnDelimiter(string $sql): array
    {
        $blocks   = [];
        $delim    = ';';
        $buffer   = '';
        $lines    = preg_split('/\r\n|\n|\r/', $sql);

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // DELIMITER directive — change current delimiter, flush buffer if any
            if (stripos($trimmed, 'DELIMITER') === 0) {
                if (trim($buffer) !== '') {
                    $blocks[] = $buffer;
                    $buffer = '';
                }
                $parts = preg_split('/\s+/', $trimmed, 2);
                $delim = $parts[1] ?? ';';
                continue;
            }

            // Skip pure comment lines
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                $buffer .= $line . "\n";
                continue;
            }

            $buffer .= $line . "\n";

            // If buffer ends with current delimiter, flush block
            if (str_ends_with(rtrim($buffer), $delim)) {
                $block = rtrim($buffer);
                $block = substr($block, 0, -strlen($delim));
                $blocks[] = $block;
                $buffer = '';
            }
        }

        if (trim($buffer) !== '') {
            $blocks[] = $buffer;
        }

        return $blocks;
    }
};
