<?php
/**
 * Backfill TARF/NTARF pardons that were already approved before the
 * "indicate as TARF" rendering changes shipped.
 *
 * For every pardon_requests row with status='approved' AND pardon_type='tarf_ntarf':
 *   - For each covered date (anchor log_date + pardon_covered_dates):
 *       * Ensure a calendar `tarf` row exists (description = "pardon_request_id:N").
 *       * Ensure a `tarf_employees` link exists.
 *       * Update the matching attendance_logs row so DTR / view_logs /
 *         Employee Management / printed DTRs render TARF in the time cells:
 *           time_in/lunch_out/lunch_in/time_out -> NULL
 *           remarks                            -> "TARF: <reason> | TARF_HOURS_CREDIT:<hours>"
 *           tarf_id                            -> calendar tarf id
 *
 * Idempotent: rows already marked (remarks starting with "TARF:" and a
 * tarf_id present) are skipped.
 *
 * Usage:
 *   php db/migrations/run_backfill_tarf_ntarf_pardon_attendance.php
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/tarf_request_attendance_sync.php';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();

    echo "Backfilling TARF/NTARF approved pardons -> attendance_logs (TARF marker)...\n";

    tarf_request_ensure_tarf_tables($db);
    tarf_calendar_kind_ensure_column($db);

    $check = $db->query("SHOW TABLES LIKE 'pardon_requests'");
    if (!$check || $check->rowCount() === 0) {
        echo "Table pardon_requests does not exist. Nothing to do.\n";
        exit(0);
    }

    $hasCovered = false;
    try {
        $colChk = $db->query("SHOW COLUMNS FROM pardon_requests LIKE 'pardon_covered_dates'");
        $hasCovered = $colChk && $colChk->rowCount() > 0;
    } catch (Exception $e) { /* ignore */ }

    $coveredSelect = $hasCovered ? ', pardon_covered_dates' : '';
    $stmt = $db->query("SELECT id, employee_id, log_date, reason{$coveredSelect}
                        FROM pardon_requests
                        WHERE status = 'approved' AND pardon_type = 'tarf_ntarf'
                        ORDER BY id ASC");
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $totalPardons = count($rows);
    $updatedLogs = 0;
    $createdTarf = 0;
    $skippedAlreadyMarked = 0;
    $skippedNoLog = 0;

    foreach ($rows as $req) {
        $reqId = (int) $req['id'];
        $empId = (string) $req['employee_id'];
        $reason = trim((string) ($req['reason'] ?? ''));

        $anchor = $req['log_date'] ? date('Y-m-d', strtotime($req['log_date'])) : null;
        $datesToApply = [];
        if ($anchor) {
            $datesToApply[] = $anchor;
        }
        if ($hasCovered && !empty($req['pardon_covered_dates'])) {
            $decoded = json_decode($req['pardon_covered_dates'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $d) {
                    $ds = date('Y-m-d', strtotime(trim((string) $d)));
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ds)) {
                        $datesToApply[] = $ds;
                    }
                }
            }
        }
        $datesToApply = array_values(array_unique($datesToApply));
        sort($datesToApply);

        if ($datesToApply === []) {
            continue;
        }

        $title = ($reason !== '') ? $reason : ('TARF/NTARF Pardon #' . $reqId);
        $titleShort = function_exists('mb_substr')
            ? mb_substr($title, 0, 220)
            : substr($title, 0, 220);

        foreach ($datesToApply as $dStr) {
            $logStmt = $db->prepare("SELECT id, remarks, tarf_id FROM attendance_logs WHERE employee_id = ? AND DATE(log_date) = ? LIMIT 1");
            $logStmt->execute([$empId, $dStr]);
            $logRow = $logStmt->fetch(PDO::FETCH_ASSOC);
            if (!$logRow) {
                $skippedNoLog++;
                continue;
            }

            $rem = (string) ($logRow['remarks'] ?? '');
            if (strpos($rem, 'TARF:') === 0 && !empty($logRow['tarf_id'])) {
                $skippedAlreadyMarked++;
                continue;
            }

            $calendarTarfId = 0;
            try {
                $findTarf = $db->prepare('SELECT id FROM tarf WHERE description = ? AND date = ? LIMIT 1');
                $findTarf->execute(['pardon_request_id:' . $reqId, $dStr]);
                $existing = $findTarf->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    $calendarTarfId = (int) $existing['id'];
                }
            } catch (Exception $e) { /* ignore */ }

            if ($calendarTarfId === 0) {
                try {
                    $insTarf = $db->prepare('INSERT INTO tarf (title, description, date, calendar_kind, created_at) VALUES (?, ?, ?, ?, NOW())');
                    $insTarf->execute([$titleShort, 'pardon_request_id:' . $reqId, $dStr, 'travel']);
                    $calendarTarfId = (int) $db->lastInsertId();
                    $createdTarf++;
                } catch (Exception $e) {
                    try {
                        $insSimple = $db->prepare('INSERT INTO tarf (title, description, date, created_at) VALUES (?, ?, ?, NOW())');
                        $insSimple->execute([$titleShort, 'pardon_request_id:' . $reqId, $dStr]);
                        $calendarTarfId = (int) $db->lastInsertId();
                        try {
                            $db->prepare('UPDATE tarf SET calendar_kind = ? WHERE id = ?')->execute(['travel', $calendarTarfId]);
                        } catch (Exception $e2) { /* ignore */ }
                        $createdTarf++;
                    } catch (Exception $e2) {
                        error_log('backfill TARF/NTARF tarf insert (req ' . $reqId . ', ' . $dStr . '): ' . $e2->getMessage());
                        $calendarTarfId = 0;
                    }
                }
            }

            if ($calendarTarfId > 0) {
                try {
                    $hasLink = $db->prepare('SELECT 1 FROM tarf_employees WHERE tarf_id = ? AND employee_id = ? LIMIT 1');
                    $hasLink->execute([$calendarTarfId, $empId]);
                    if (!$hasLink->fetch()) {
                        $insTe = $db->prepare('INSERT INTO tarf_employees (tarf_id, employee_id) VALUES (?, ?)');
                        $insTe->execute([$calendarTarfId, $empId]);
                    }
                } catch (Exception $eTe) {
                    error_log('backfill TARF/NTARF tarf_employees insert: ' . $eTe->getMessage());
                }
            }

            $ot = tarf_request_official_times_for_date($empId, $dStr, $db);
            $creditH = tarf_request_credit_hours_from_official_slice($ot);
            $remarksUse = 'TARF: ' . $titleShort . ' | TARF_HOURS_CREDIT:' . $creditH;
            if (strlen($remarksUse) > 500) {
                $remarksUse = substr($remarksUse, 0, 497) . '...';
            }

            $upd = $db->prepare('UPDATE attendance_logs SET time_in = NULL, lunch_out = NULL, lunch_in = NULL, time_out = NULL, remarks = ?, tarf_id = ? WHERE id = ?');
            $upd->execute([$remarksUse, ($calendarTarfId > 0 ? $calendarTarfId : null), (int) $logRow['id']]);
            $updatedLogs++;
        }
    }

    echo "Done.\n";
    echo "  Approved TARF/NTARF pardons examined : {$totalPardons}\n";
    echo "  attendance_logs rows updated         : {$updatedLogs}\n";
    echo "  Calendar tarf entries created        : {$createdTarf}\n";
    echo "  Skipped (already marked TARF)        : {$skippedAlreadyMarked}\n";
    echo "  Skipped (no attendance_logs row)     : {$skippedNoLog}\n";
} catch (PDOException $e) {
    fwrite(STDERR, 'Backfill failed: ' . $e->getMessage() . "\n");
    exit(1);
} catch (Exception $e) {
    fwrite(STDERR, 'Backfill failed: ' . $e->getMessage() . "\n");
    exit(1);
}
