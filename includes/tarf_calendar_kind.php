<?php
/**
 * Calendar `tarf.calendar_kind`: travel vs ntarf (on-site).
 * Travel: auto attendance / block manual timekeeper punches in scanning APIs.
 * NTARF: employees keep normal DTR via timekeeper (time in, lunch out, lunch in, time out).
 */

if (!function_exists('tarf_calendar_kind_normalize')) {
    function tarf_calendar_kind_normalize(string $value): string
    {
        $v = strtolower(trim($value));
        if ($v === 'ntarf') {
            return 'ntarf';
        }

        return 'travel';
    }
}

if (!function_exists('tarf_calendar_kind_ensure_column')) {
    function tarf_calendar_kind_ensure_column(PDO $db): void
    {
        try {
            $db->exec("ALTER TABLE tarf ADD COLUMN calendar_kind VARCHAR(16) NOT NULL DEFAULT 'travel'");
        } catch (Exception $e) {
            /* column exists */
        }
    }
}
