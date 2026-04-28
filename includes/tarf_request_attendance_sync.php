<?php
/**
 * When a TARF request (tarf_requests) is fully approved (status = endorsed), mirror into
 * calendar `tarf` / `tarf_employees` and `attendance_logs` so DTR and timekeeper behave like admin-calendar TARFs.
 */
if (!function_exists('tarf_request_default_hours_no_official')) {
    /** Standard credited hours when no employee_official_times row applies for that TARF date. */
    function tarf_request_default_hours_no_official(): float
    {
        return 8.0;
    }
}

if (!function_exists('tarf_request_official_times_for_date')) {
    /**
     * When a schedule exists for that calendar day, fill DTR time cells from it; otherwise only hour credit applies (see remarks TARF_HOURS_CREDIT).
     *
     * @return array{found:bool,time_in:?string,lunch_out:?string,lunch_in:?string,time_out:?string}
     */
    function tarf_request_official_times_for_date(string $employeeId, string $dateYmd, PDO $db): array
    {
        $dateObj = new DateTime($dateYmd);
        $weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $weekday = $weekdays[(int) $dateObj->format('w')];

        $stmt = $db->prepare(
            'SELECT * FROM employee_official_times
             WHERE employee_id = ?
             AND weekday = ?
             AND start_date <= ?
             AND (end_date IS NULL OR end_date >= ?)
             ORDER BY start_date DESC
             LIMIT 1'
        );
        $stmt->execute([$employeeId, $weekday, $dateYmd, $dateYmd]);
        $officialTime = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($officialTime) {
            $logDate = new DateTime($dateYmd);
            $logDate->setTime(0, 0, 0);
            $startDate = new DateTime($officialTime['start_date']);
            $startDate->setTime(0, 0, 0);
            $endDate = $officialTime['end_date'] ? new DateTime($officialTime['end_date']) : null;
            if ($endDate) {
                $endDate->setTime(0, 0, 0);
            }
            $isInRange = ($logDate >= $startDate) && ($endDate === null || $logDate <= $endDate);
            if ($isInRange) {
                $hasLunch = ($officialTime['lunch_out'] && $officialTime['lunch_out'] != '00:00:00'
                    && $officialTime['lunch_in'] && $officialTime['lunch_in'] != '00:00:00');

                return [
                    'found' => true,
                    'has_lunch' => $hasLunch,
                    'time_in' => $officialTime['time_in'],
                    'lunch_out' => ($officialTime['lunch_out'] && $officialTime['lunch_out'] != '00:00:00')
                        ? $officialTime['lunch_out'] : '12:00:00',
                    'lunch_in' => ($officialTime['lunch_in'] && $officialTime['lunch_in'] != '00:00:00')
                        ? $officialTime['lunch_in'] : '13:00:00',
                    'time_out' => $officialTime['time_out'],
                ];
            }
        }

        return [
            'found' => false,
            'has_lunch' => false,
            'time_in' => null,
            'lunch_out' => null,
            'lunch_in' => null,
            'time_out' => null,
        ];
    }
}

if (!function_exists('tarf_request_parse_minutes')) {
    function tarf_request_parse_minutes(?string $time): ?int
    {
        if ($time === null || $time === '') {
            return null;
        }
        $parts = explode(':', trim($time));
        if (count($parts) < 2) {
            return null;
        }

        return (int) $parts[0] * 60 + (int) $parts[1];
    }
}

if (!function_exists('tarf_request_credit_hours_from_official_slice')) {
    /**
     * Credited hours for a TARF day (matches DTR official base when schedule exists).
     */
    function tarf_request_credit_hours_from_official_slice(array $ot): float
    {
        if (empty($ot['found'])) {
            return tarf_request_default_hours_no_official();
        }
        $in = tarf_request_parse_minutes($ot['time_in'] ?? null);
        $out = tarf_request_parse_minutes($ot['time_out'] ?? null);
        if ($in === null || $out === null) {
            return tarf_request_default_hours_no_official();
        }
        if (!empty($ot['has_lunch'])) {
            $lo = tarf_request_parse_minutes($ot['lunch_out'] ?? null);
            $li = tarf_request_parse_minutes($ot['lunch_in'] ?? null);
            if ($lo === null || $li === null) {
                return round(max(0, ($out - $in) / 60), 2);
            }

            return round(max(0, ($lo - $in) / 60) + max(0, ($out - $li) / 60), 2);
        }

        return round(max(0, ($out - $in) / 60), 2);
    }
}

if (!function_exists('tarf_request_collect_travel_employee_ids')) {
    /**
     * Employee IDs for people on the TARF (directory selections + requester if they have an ID).
     *
     * @return list<string>
     */
    function tarf_request_collect_travel_employee_ids(array $tarfRequestRow, array $form, PDO $db): array
    {
        $out = [];
        $uids = $form['persons_to_travel_user_ids'] ?? [];
        if (is_array($uids)) {
            $uids = array_values(array_unique(array_map('intval', $uids)));
            $uids = array_filter($uids, function ($x) {
                return $x > 0;
            });
            if ($uids !== []) {
                $ph = implode(',', array_fill(0, count($uids), '?'));
                $st = $db->prepare("SELECT employee_id FROM faculty_profiles WHERE user_id IN ($ph) AND TRIM(COALESCE(employee_id,'')) <> ''");
                $st->execute($uids);
                while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                    $eid = trim((string) ($r['employee_id'] ?? ''));
                    if ($eid !== '') {
                        $out[$eid] = true;
                    }
                }
            }
        }
        $reqEmp = trim((string) ($tarfRequestRow['employee_id'] ?? ''));
        if ($reqEmp !== '') {
            $out[$reqEmp] = true;
        }

        return array_keys($out);
    }
}

if (!function_exists('tarf_request_ensure_tarf_tables')) {
    function tarf_request_ensure_tarf_tables(PDO $db): void
    {
        try {
            $tbl = $db->query("SHOW TABLES LIKE 'tarf'");
            if (!$tbl || $tbl->rowCount() === 0) {
                $db->exec("CREATE TABLE IF NOT EXISTS tarf (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    title VARCHAR(255) NOT NULL,
                    description TEXT,
                    file_path VARCHAR(500),
                    date DATE NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_date (date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            } else {
                try {
                    $db->exec('ALTER TABLE tarf ADD COLUMN description TEXT');
                } catch (Exception $e) {
                    /* exists */
                }
            }
        } catch (Exception $e) {
            error_log('tarf_request_ensure_tarf_tables tarf: ' . $e->getMessage());
        }
        try {
            $tbl = $db->query("SHOW TABLES LIKE 'tarf_employees'");
            if (!$tbl || $tbl->rowCount() === 0) {
                $db->exec("CREATE TABLE IF NOT EXISTS tarf_employees (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    tarf_id INT NOT NULL,
                    employee_id VARCHAR(50) NOT NULL,
                    INDEX idx_tarf_id (tarf_id),
                    INDEX idx_employee_id (employee_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }
        } catch (Exception $e) {
            error_log('tarf_request_ensure_tarf_tables tarf_employees: ' . $e->getMessage());
        }
        foreach (['remarks' => 'VARCHAR(500) DEFAULT NULL', 'tarf_id' => 'INT DEFAULT NULL'] as $col => $def) {
            try {
                $db->exec("ALTER TABLE attendance_logs ADD COLUMN $col $def");
            } catch (Exception $e) {
                /* exists */
            }
        }
        try {
            $db->exec('ALTER TABLE attendance_logs ADD INDEX idx_tarf_id (tarf_id)');
        } catch (Exception $e) {
            /* exists */
        }
    }
}

if (!function_exists('tarf_request_sync_endorsed_to_attendance')) {
    /**
     * Call after tarf_requests row is updated to status endorsed. Idempotent enough for a single endorse action.
     */
    function tarf_request_sync_endorsed_to_attendance(PDO $db, int $tarfRequestId): void
    {
        if ($tarfRequestId <= 0) {
            return;
        }
        $st = $db->prepare('SELECT * FROM tarf_requests WHERE id = ? LIMIT 1');
        $st->execute([$tarfRequestId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || ($row['status'] ?? '') !== 'endorsed') {
            return;
        }

        $form = json_decode($row['form_data'] ?? '', true);
        if (!is_array($form)) {
            $form = [];
        }

        $dep = trim((string) ($form['date_departure'] ?? ''));
        $ret = trim((string) ($form['date_return'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dep) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ret) || $dep > $ret) {
            return;
        }

        $employeeIds = tarf_request_collect_travel_employee_ids($row, $form, $db);
        if ($employeeIds === []) {
            return;
        }

        tarf_request_ensure_tarf_tables($db);

        $eventTitle = trim((string) ($form['event_purpose'] ?? ''));
        if ($eventTitle === '') {
            $eventTitle = 'TARF #' . $tarfRequestId;
        }
        if (function_exists('mb_substr')) {
            $titleShort = mb_substr($eventTitle, 0, 220);
        } else {
            $titleShort = substr($eventTitle, 0, 220);
        }
        $remarksBasePlain = 'TARF: ' . $titleShort;
        if (strlen($remarksBasePlain) > 500) {
            $remarksBasePlain = substr($remarksBasePlain, 0, 497) . '...';
        }

        $period = new DatePeriod(
            new DateTime($dep),
            new DateInterval('P1D'),
            (new DateTime($ret))->modify('+1 day')
        );

        foreach ($period as $dateObj) {
            /** @var DateTime $dateObj */
            $dateStr = $dateObj->format('Y-m-d');

            $insTarf = $db->prepare(
                'INSERT INTO tarf (title, description, date, created_at) VALUES (?, ?, ?, NOW())'
            );
            try {
                $insTarf->execute([
                    $titleShort,
                    'tarf_request_id:' . $tarfRequestId,
                    $dateStr,
                ]);
            } catch (Exception $e) {
                try {
                    $insSimple = $db->prepare('INSERT INTO tarf (title, date, created_at) VALUES (?, ?, NOW())');
                    $insSimple->execute([$titleShort, $dateStr]);
                } catch (Exception $e2) {
                    error_log('tarf_request_sync insert tarf: ' . $e2->getMessage());
                    continue;
                }
            }
            $calendarTarfId = (int) $db->lastInsertId();
            if ($calendarTarfId <= 0) {
                continue;
            }

            $insTe = $db->prepare('INSERT INTO tarf_employees (tarf_id, employee_id) VALUES (?, ?)');
            foreach ($employeeIds as $eid) {
                if ($eid === '') {
                    continue;
                }
                try {
                    $insTe->execute([$calendarTarfId, $eid]);
                } catch (Exception $e) {
                    error_log('tarf_request_sync tarf_employees: ' . $e->getMessage());
                }

                $ot = tarf_request_official_times_for_date($eid, $dateStr, $db);
                $creditH = tarf_request_credit_hours_from_official_slice($ot);
                $remarksUse = $remarksBasePlain . ' | TARF_HOURS_CREDIT:' . $creditH;
                if (strlen($remarksUse) > 500) {
                    $remarksUse = substr($remarksUse, 0, 497) . '...';
                }

                $stmtEx = $db->prepare('SELECT id FROM attendance_logs WHERE employee_id = ? AND log_date = ?');
                $stmtEx->execute([$eid, $dateStr]);
                $existing = $stmtEx->fetch(PDO::FETCH_ASSOC);

                if ($existing) {
                    $upd = $db->prepare(
                        'UPDATE attendance_logs SET
                            time_in = ?,
                            lunch_out = ?,
                            lunch_in = ?,
                            time_out = ?,
                            remarks = ?,
                            tarf_id = ?
                         WHERE id = ?'
                    );
                    $upd->execute([
                        null,
                        null,
                        null,
                        null,
                        $remarksUse,
                        $calendarTarfId,
                        $existing['id'],
                    ]);
                } else {
                    $ins = $db->prepare(
                        'INSERT INTO attendance_logs (employee_id, log_date, time_in, lunch_out, lunch_in, time_out, remarks, tarf_id, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
                    );
                    try {
                        $ins->execute([
                            $eid,
                            $dateStr,
                            null,
                            null,
                            null,
                            null,
                            $remarksUse,
                            $calendarTarfId,
                        ]);
                    } catch (Exception $e) {
                        error_log('tarf_request_sync attendance_logs: ' . $e->getMessage());
                    }
                }
            }
        }
    }
}
