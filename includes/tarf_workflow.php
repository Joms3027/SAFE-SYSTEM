<?php
/**
 * TARF / NTARF routing: supervisor, applicable endorser, and Budget or Accounting (fund)
 * endorse in parallel (status pending_joint). When all required endorsements exist → pending_president → endorsed.
 */
if (!function_exists('tarf_load_endorser_user_map')) {
    function tarf_load_endorser_user_map(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $path = __DIR__ . '/tarf_endorser_user_map.php';
        if (!is_file($path)) {
            $cached = [];
            return $cached;
        }
        $m = include $path;
        $cached = is_array($m) ? $m : [];
        return $cached;
    }
}

if (!function_exists('tarf_resolve_endorser_target_user_id')) {
    function tarf_resolve_endorser_target_user_id(string $endorserLabel, PDO $db): ?int
    {
        $map = tarf_load_endorser_user_map();
        if (isset($map[$endorserLabel])) {
            $uid = $map[$endorserLabel];
            if ($uid !== null && $uid !== '' && (int) $uid > 0) {
                return (int) $uid;
            }
        }
        try {
            $tbl = $db->query("SHOW TABLES LIKE 'tarf_endorser_route'");
            if (!$tbl || $tbl->rowCount() === 0) {
                return null;
            }
            $st = $db->prepare('SELECT user_id FROM tarf_endorser_route WHERE endorser_label = ? LIMIT 1');
            $st->execute([$endorserLabel]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && (int) ($row['user_id'] ?? 0) > 0) {
                return (int) $row['user_id'];
            }
        } catch (Exception $e) {
            return null;
        }
        return null;
    }
}

if (!function_exists('tarf_fund_availability_designation_label')) {
    /** Faculty designation matched for Budget vs Accounting fund endorsement routing. */
    function tarf_fund_availability_designation_label(string $fundKey): ?string
    {
        if ($fundKey === 'budget_101_164') {
            return 'University Budget Office';
        }
        if ($fundKey === 'accounting_184') {
            return 'Officer in Charge University Accountant';
        }
        return null;
    }
}

if (!function_exists('tarf_resolve_fund_availability_endorser_user_id')) {
    /**
     * Portal user holding the designation for the selected fund-availability role (Budget vs Accounting).
     */
    function tarf_resolve_fund_availability_endorser_user_id(string $fundKey, PDO $db): ?int
    {
        require_once __DIR__ . '/tarf_form_options.php';
        $opts = tarf_get_form_options();
        if (!isset($opts['fund_endorser_role'][$fundKey])) {
            return null;
        }
        $desig = tarf_fund_availability_designation_label($fundKey);
        if ($desig === null || $desig === '') {
            return null;
        }
        try {
            $st = $db->prepare(
                'SELECT fp.user_id FROM faculty_profiles fp
                 INNER JOIN users u ON u.id = fp.user_id
                 WHERE LOWER(TRIM(fp.designation)) = LOWER(?) AND u.is_active = 1
                 ORDER BY fp.user_id ASC LIMIT 1'
            );
            $st->execute([$desig]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r && (int) ($r['user_id'] ?? 0) > 0) {
                return (int) $r['user_id'];
            }
        } catch (Exception $e) {
            return null;
        }
        return null;
    }
}

if (!function_exists('tarf_request_requires_fund_availability_endorsement')) {
    /**
     * True when the submitted form requires Budget/Accounting fund endorsement (same rules as submit APIs).
     */
    function tarf_request_requires_fund_availability_endorsement(array $row): bool
    {
        $form = json_decode($row['form_data'] ?? '{}', true);
        if (!is_array($form)) {
            return false;
        }
        $fk = $form['form_kind'] ?? 'tarf';
        if ($fk === 'ntarf') {
            require_once __DIR__ . '/ntarf_form_options.php';
            $uf = strtolower(trim((string) ($form['university_funding_requested'] ?? '')));
            if ($uf === 'yes') {
                return !empty($form['endorser_fund_availability']);
            }
            if ($uf === 'no') {
                return false;
            }
            $amt = trim((string) ($form['total_estimated_amount'] ?? ''));

            return ntarf_total_amount_requires_funding_detail($amt) && !empty($form['endorser_fund_availability']);
        }
        $trt = $form['travel_request_type'] ?? '';
        $uf = $form['university_funding_requested'] ?? '';
        $needsFundingDetail = ($trt === 'official_business' || $uf === 'yes');

        return $needsFundingDetail && !empty($form['endorser_fund_availability']);
    }
}

if (!function_exists('tarf_status_is_awaiting_joint_endorsements')) {
    function tarf_status_is_awaiting_joint_endorsements(?string $status): bool
    {
        return $status === 'pending_joint'
            || $status === 'pending_supervisor'
            || $status === 'pending_endorser';
    }
}

if (!function_exists('tarf_joint_endorsements_satisfied')) {
    /**
     * President (final) only after:
     * - Supervisor (pardon opener) and applicable endorser have endorsed; and
     * - When the form requires fund certification: Budget OR Accounting (fund_availability_target_user_id) has endorsed.
     */
    function tarf_joint_endorsements_satisfied(array $row, PDO $db): bool
    {
        if (empty($row['supervisor_endorsed_at']) || empty($row['endorser_endorsed_at'])) {
            return false;
        }
        if (!tarf_request_requires_fund_availability_endorsement($row)) {
            return true;
        }
        $tid = (int) ($row['fund_availability_target_user_id'] ?? 0);
        if ($tid <= 0 || empty($row['fund_availability_endorsed_at'])) {
            return false;
        }
        $by = (int) ($row['fund_availability_endorsed_by'] ?? 0);

        return $by === $tid;
    }
}

if (!function_exists('tarf_try_advance_joint_request_to_president')) {
    /**
     * If supervisor + applicable endorser + (Budget or Accounting when required) are done, move to pending_president.
     */
    function tarf_try_advance_joint_request_to_president(PDO $db, int $tarfId): bool
    {
        $st = $db->prepare('SELECT * FROM tarf_requests WHERE id = ? LIMIT 1');
        $st->execute([$tarfId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || !tarf_status_is_awaiting_joint_endorsements($row['status'] ?? '')) {
            return false;
        }
        if (!tarf_joint_endorsements_satisfied($row, $db)) {
            return false;
        }
        $up = $db->prepare(
            "UPDATE tarf_requests SET status = 'pending_president', updated_at = NOW() WHERE id = ? AND "
            . 'status IN (\'pending_joint\',\'pending_supervisor\',\'pending_endorser\')'
        );
        $up->execute([$tarfId]);

        return $up->rowCount() > 0;
    }
}

if (!function_exists('tarf_user_holds_fund_availability_designation')) {
    function tarf_user_holds_fund_availability_designation(int $userId, PDO $db): bool
    {
        if ($userId <= 0) {
            return false;
        }
        try {
            $st = $db->prepare(
                'SELECT 1 FROM faculty_profiles fp
                 INNER JOIN users u ON u.id = fp.user_id
                 WHERE fp.user_id = ? AND u.is_active = 1 AND (
                    LOWER(TRIM(fp.designation)) = LOWER(?)
                    OR LOWER(TRIM(fp.designation)) = LOWER(?)
                 ) LIMIT 1'
            );
            $st->execute([$userId, 'University Budget Office', 'Officer in Charge University Accountant']);

            return (bool) $st->fetchColumn();
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('tarf_is_endorser_target_user')) {
    function tarf_is_endorser_target_user(int $userId, PDO $db): bool
    {
        if ($userId <= 0) {
            return false;
        }
        foreach (tarf_load_endorser_user_map() as $uid) {
            if ($uid !== null && (int) $uid === $userId) {
                return true;
            }
        }
        try {
            $tbl = $db->query("SHOW TABLES LIKE 'tarf_endorser_route'");
            if (!$tbl || $tbl->rowCount() === 0) {
                return false;
            }
            $st = $db->prepare('SELECT 1 FROM tarf_endorser_route WHERE user_id = ? LIMIT 1');
            $st->execute([$userId]);
            return (bool) $st->fetch();
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('tarf_user_can_view_request')) {
    function tarf_user_can_view_request(array $row, int $userId, PDO $db): bool
    {
        if ($userId <= 0) {
            return false;
        }
        if ((int) ($row['user_id'] ?? 0) === $userId) {
            return true;
        }
        $empId = trim($row['employee_id'] ?? '');
        if ($empId !== '' && function_exists('canUserOpenPardonForEmployee') && canUserOpenPardonForEmployee($userId, $empId, $db)) {
            return true;
        }
        $target = isset($row['endorser_target_user_id']) ? (int) $row['endorser_target_user_id'] : 0;
        if ($target > 0 && $target === $userId) {
            return true;
        }
        $fundT = isset($row['fund_availability_target_user_id']) ? (int) $row['fund_availability_target_user_id'] : 0;
        if ($fundT > 0 && $fundT === $userId) {
            return true;
        }
        if (function_exists('tarf_president_viewer_can_see_request') && tarf_president_viewer_can_see_request($row, $userId, $db)) {
            return true;
        }
        return false;
    }
}

if (!function_exists('tarf_load_president_viewer_config')) {
    function tarf_load_president_viewer_config(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $path = __DIR__ . '/tarf_president_viewer_config.php';
        if (!is_file($path)) {
            $cached = ['viewer_user_id' => 0, 'employee_key_official_labels' => []];
            return $cached;
        }
        $c = include $path;
        $cached = is_array($c) ? $c : ['viewer_user_id' => 0, 'employee_key_official_labels' => []];
        if (!isset($cached['employee_key_official_labels']) || !is_array($cached['employee_key_official_labels'])) {
            $cached['employee_key_official_labels'] = ['PRESIDENT'];
        }
        return $cached;
    }
}

if (!function_exists('tarf_normalize_key_official_label')) {
    function tarf_normalize_key_official_label(string $s): string
    {
        return strtolower(trim($s));
    }
}

if (!function_exists('tarf_requester_has_president_key_official')) {
    function tarf_requester_has_president_key_official(int $requesterUserId, PDO $db): bool
    {
        if ($requesterUserId <= 0) {
            return false;
        }
        $cfg = tarf_load_president_viewer_config();
        $labels = $cfg['employee_key_official_labels'] ?? [];
        if (empty($labels)) {
            return false;
        }
        $norm = [];
        foreach ($labels as $l) {
            $norm[tarf_normalize_key_official_label((string) $l)] = true;
        }
        try {
            $st = $db->prepare('SELECT key_official FROM faculty_profiles WHERE user_id = ? LIMIT 1');
            $st->execute([$requesterUserId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            $ko = isset($row['key_official']) ? tarf_normalize_key_official_label((string) $row['key_official']) : '';
            return $ko !== '' && isset($norm[$ko]);
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('tarf_is_president_key_official_viewer')) {
    function tarf_is_president_key_official_viewer(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $cfg = tarf_load_president_viewer_config();
        $vid = (int) ($cfg['viewer_user_id'] ?? 0);
        return $vid > 0 && $vid === $userId;
    }
}

if (!function_exists('tarf_president_viewer_can_see_request')) {
    function tarf_president_viewer_can_see_request(array $row, int $viewerUserId, PDO $db): bool
    {
        if (!tarf_is_president_key_official_viewer($viewerUserId)) {
            return false;
        }
        $reqUid = (int) ($row['user_id'] ?? 0);
        if (($row['status'] ?? '') === 'pending_president') {
            return true;
        }
        if ((int) ($row['president_endorsed_by'] ?? 0) === $viewerUserId) {
            return true;
        }
        return tarf_requester_has_president_key_official($reqUid, $db);
    }
}

if (!function_exists('tarf_user_can_supervisor_endorse')) {
    function tarf_user_can_supervisor_endorse(array $row, int $userId, PDO $db): bool
    {
        if (!tarf_status_is_awaiting_joint_endorsements($row['status'] ?? '')) {
            return false;
        }
        if (!empty($row['supervisor_endorsed_at'])) {
            return false;
        }
        $empId = trim($row['employee_id'] ?? '');
        if ($empId === '' || !function_exists('canUserOpenPardonForEmployee')) {
            return false;
        }
        return canUserOpenPardonForEmployee($userId, $empId, $db);
    }
}

if (!function_exists('tarf_user_can_endorser_endorse')) {
    function tarf_user_can_endorser_endorse(array $row, int $userId): bool
    {
        if (!tarf_status_is_awaiting_joint_endorsements($row['status'] ?? '')) {
            return false;
        }
        if (!empty($row['endorser_endorsed_at'])) {
            return false;
        }
        $target = isset($row['endorser_target_user_id']) ? (int) $row['endorser_target_user_id'] : 0;
        return $target > 0 && $target === $userId;
    }
}

if (!function_exists('tarf_user_can_fund_availability_endorse')) {
    function tarf_user_can_fund_availability_endorse(array $row, int $userId, PDO $db): bool
    {
        if (!tarf_status_is_awaiting_joint_endorsements($row['status'] ?? '')) {
            return false;
        }
        if (!tarf_request_requires_fund_availability_endorsement($row)) {
            return false;
        }
        if (!empty($row['fund_availability_endorsed_at'])) {
            return false;
        }
        $tid = isset($row['fund_availability_target_user_id']) ? (int) $row['fund_availability_target_user_id'] : 0;
        return $tid > 0 && $tid === $userId;
    }
}

if (!function_exists('tarf_user_can_president_act')) {
    function tarf_user_can_president_act(array $row, int $userId, PDO $db): bool
    {
        if (($row['status'] ?? '') !== 'pending_president') {
            return false;
        }
        if (!tarf_is_president_key_official_viewer($userId)) {
            return false;
        }
        return true;
    }
}

if (!function_exists('tarf_fund_availability_certifier_display_name')) {
    /**
     * Display name for Budget/Accounting fund certification — the designated portal user, not the role label
     * (e.g. not "Budget (Fund 101, Fund 164)"). Prefer the actual endorser when present; else routing target; else lookup by fund key.
     */
    function tarf_fund_availability_certifier_display_name(PDO $db, array $row, array $form): string
    {
        $key = trim((string) ($form['endorser_fund_availability'] ?? ''));
        if ($key === '') {
            return '';
        }
        $byEndorse = (int) ($row['fund_availability_endorsed_by'] ?? 0);
        if ($byEndorse > 0) {
            $n = tarf_display_name_for_user($byEndorse, $db);
            if ($n !== '') {
                return $n;
            }
        }
        $tid = (int) ($row['fund_availability_target_user_id'] ?? 0);
        if ($tid > 0) {
            $n = tarf_display_name_for_user($tid, $db);
            if ($n !== '') {
                return $n;
            }
        }
        $resolved = tarf_resolve_fund_availability_endorser_user_id($key, $db);
        if ($resolved !== null && $resolved > 0) {
            return tarf_display_name_for_user($resolved, $db);
        }

        return '';
    }
}

if (!function_exists('tarf_display_name_for_user')) {
    function tarf_display_name_for_user(?int $userId, PDO $db): string
    {
        if (!$userId) {
            return '';
        }
        try {
            $st = $db->prepare('SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1');
            $st->execute([$userId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) {
                return '';
            }
            return trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        } catch (Exception $e) {
            return '';
        }
    }
}
