<?php
/**
 * Optional policy: If today falls in a calendar week (Sunday–Saturday, matching admin/calendar)
 * that includes a holiday, use a standard 8-hour workday (08:00–12:00, 13:00–17:00) for late/undertime
 * and matching helpers that week only. Weeks that are entirely in the past or future use employee official schedules.
 */

if (!function_exists('calendar_holiday_week_schedule_ensure_table')) {
    function calendar_holiday_week_schedule_ensure_table(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS calendar_settings (
            setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
            setting_value VARCHAR(255) NOT NULL DEFAULT '0',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('calendar_holiday_week_eight_hour_enabled')) {
    function calendar_holiday_week_eight_hour_enabled(PDO $db): bool
    {
        calendar_holiday_week_schedule_ensure_table($db);
        try {
            $stmt = $db->prepare("SELECT setting_value FROM calendar_settings WHERE setting_key = 'holiday_week_eight_hour' LIMIT 1");
            $stmt->execute();
            $v = $stmt->fetchColumn();
            return $v !== false && trim((string) $v) === '1';
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('calendar_holiday_week_eight_hour_set')) {
    function calendar_holiday_week_eight_hour_set(PDO $db, bool $enabled): void
    {
        calendar_holiday_week_schedule_ensure_table($db);
        $stmt = $db->prepare("INSERT INTO calendar_settings (setting_key, setting_value) VALUES ('holiday_week_eight_hour', ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([$enabled ? '1' : '0']);
    }
}

if (!function_exists('calendar_week_bounds_sunday_saturday')) {
    /** @return array{sunday:string,saturday:string} */
    function calendar_week_bounds_sunday_saturday(string $dateYmd): array
    {
        $d = new DateTimeImmutable($dateYmd);
        $dow = (int) $d->format('w');
        $sunday = $d->modify("-{$dow} days");
        $saturday = $sunday->modify('+6 days');
        return [
            'sunday' => $sunday->format('Y-m-d'),
            'saturday' => $saturday->format('Y-m-d'),
        ];
    }
}

if (!function_exists('calendar_week_contains_holiday')) {
    function calendar_week_contains_holiday(PDO $db, string $weekSunday, string $weekSaturday): bool
    {
        try {
            $stmt = $db->prepare('SELECT 1 FROM holidays WHERE date >= ? AND date <= ? LIMIT 1');
            $stmt->execute([$weekSunday, $weekSaturday]);
            if ($stmt->fetchColumn()) {
                return true;
            }
        } catch (Throwable $e) {
            // ignore
        }
        try {
            $tc = $db->query("SHOW TABLES LIKE 'calendar_events'");
            if (!$tc || $tc->rowCount() === 0) {
                return false;
            }
            $ceArc = $db->query("SHOW COLUMNS FROM calendar_events LIKE 'is_archived'");
            $archFilter = ($ceArc && $ceArc->rowCount() > 0) ? 'AND COALESCE(is_archived, 0) = 0' : '';
            $stmt = $db->prepare("SELECT 1 FROM calendar_events WHERE event_type = 'holiday' $archFilter AND event_date >= ? AND event_date <= ? LIMIT 1");
            $stmt->execute([$weekSunday, $weekSaturday]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('calendar_should_apply_holiday_week_eight_hours')) {
    /**
     * True when to use standard 08:00–17:00 (with lunch) for that date's Sun–Sat week.
     * Only applies while today is inside that same calendar week; past/future weeks use official times.
     */
    function calendar_should_apply_holiday_week_eight_hours(PDO $db, string $dateYmd, ?DateTimeImmutable $today = null): bool
    {
        if (!calendar_holiday_week_eight_hour_enabled($db)) {
            return false;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateYmd)) {
            return false;
        }
        $today = $today ?? new DateTimeImmutable('today');
        $b = calendar_week_bounds_sunday_saturday($dateYmd);
        $weekSun = DateTimeImmutable::createFromFormat('Y-m-d', $b['sunday']);
        $weekSat = DateTimeImmutable::createFromFormat('Y-m-d', $b['saturday']);
        if (!$weekSun || !$weekSat) {
            return false;
        }
        if ($today < $weekSun || $today > $weekSat) {
            return false;
        }
        return calendar_week_contains_holiday($db, $b['sunday'], $b['saturday']);
    }
}

if (!function_exists('calendar_holiday_week_standard_ot_row')) {
    /** Same shape as calendar_api official time resolution */
    function calendar_holiday_week_standard_ot_row(): array
    {
        return [
            'found' => true,
            'time_in' => '08:00:00',
            'lunch_out' => '12:00:00',
            'lunch_in' => '13:00:00',
            'time_out' => '17:00:00',
        ];
    }
}

if (!function_exists('calendar_holiday_week_standard_official_by_date_minutes')) {
    /** @return array{lunch_out:int,lunch_in:int,time_out:int} */
    function calendar_holiday_week_standard_official_by_date_minutes(): array
    {
        return [
            'lunch_out' => 12 * 60,
            'lunch_in' => 13 * 60,
            'time_out' => 17 * 60,
        ];
    }
}

if (!function_exists('calendar_holiday_week_apply_print_official_bundle')) {
    /**
     * @param array{official:array, from_db:bool, has_lunch:bool} $res
     * @return array{official:array, from_db:bool, has_lunch:bool}
     */
    function calendar_holiday_week_apply_print_official_bundle(array $res, PDO $db, string $logDateRaw): array
    {
        $d = $logDateRaw;
        if (strpos($logDateRaw, ' ') !== false) {
            $d = substr($logDateRaw, 0, 10);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            return $res;
        }
        if (!calendar_should_apply_holiday_week_eight_hours($db, $d)) {
            return $res;
        }
        return [
            'official' => [
                'time_in' => '08:00:00',
                'lunch_out' => '12:00:00',
                'lunch_in' => '13:00:00',
                'time_out' => '17:00:00',
            ],
            'from_db' => true,
            'has_lunch' => true,
        ];
    }
}
