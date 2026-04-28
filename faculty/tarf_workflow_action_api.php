<?php
/**
 * President only after supervisor + applicable endorser (+ Budget or Accounting when required).
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/tarf_workflow.php';
require_once __DIR__ . '/../includes/tarf_request_attendance_sync.php';
require_once __DIR__ . '/../includes/tarf_official_order_docx.php';

requireFaculty();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method.']);
    exit;
}

if (!validateFormToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid or expired security token.']);
    exit;
}

$tarfId = (int) ($_POST['tarf_id'] ?? 0);
$role = trim($_POST['role'] ?? '');
$action = trim($_POST['action'] ?? '');
$comment = trim($_POST['comment'] ?? '');
$rejectReason = trim($_POST['rejection_reason'] ?? '');

if ($tarfId <= 0 || !in_array($role, ['supervisor', 'endorser', 'fund_availability', 'president'], true) || !in_array($action, ['endorse', 'reject'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$database = Database::getInstance();
$db = $database->getConnection();
$uid = (int) $_SESSION['user_id'];

$stmt = $db->prepare('SELECT * FROM tarf_requests WHERE id = ? LIMIT 1');
$stmt->execute([$tarfId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Request not found.']);
    exit;
}

if ($action === 'reject' && $rejectReason === '') {
    echo json_encode(['success' => false, 'message' => 'A rejection reason is required.']);
    exit;
}

$jointPendingSql = " status IN ('pending_joint','pending_supervisor','pending_endorser') ";

$up = null;
$becameEndorsed = false;
$newStatus = null;

try {
    if ($role === 'supervisor') {
        if (!tarf_user_can_supervisor_endorse($row, $uid, $db)) {
            echo json_encode(['success' => false, 'message' => 'You cannot act on this request as supervisor.']);
            exit;
        }
        if ($action === 'endorse') {
            $up = $db->prepare(
                "UPDATE tarf_requests SET supervisor_endorsed_at = NOW(), supervisor_endorsed_by = ?, supervisor_comment = ?, updated_at = NOW()
                 WHERE id = ? AND $jointPendingSql AND supervisor_endorsed_at IS NULL"
            );
            $up->execute([$uid, $comment !== '' ? $comment : null, $tarfId]);
        } else {
            $newStatus = 'rejected';
            $up = $db->prepare(
                "UPDATE tarf_requests SET status = ?, rejected_at = NOW(), rejected_by = ?, rejection_reason = ?, rejection_stage = ?, updated_at = NOW()
                 WHERE id = ? AND $jointPendingSql AND supervisor_endorsed_at IS NULL"
            );
            $up->execute([$newStatus, $uid, $rejectReason, 'supervisor', $tarfId]);
        }
    } elseif ($role === 'endorser') {
        if (!tarf_user_can_endorser_endorse($row, $uid)) {
            echo json_encode(['success' => false, 'message' => 'You cannot act on this request as the applicable endorser.']);
            exit;
        }
        if ($action === 'endorse') {
            $up = $db->prepare(
                "UPDATE tarf_requests SET endorser_endorsed_at = NOW(), endorser_endorsed_by = ?, endorser_comment = ?, updated_at = NOW()
                 WHERE id = ? AND $jointPendingSql AND endorser_endorsed_at IS NULL AND endorser_target_user_id = ?"
            );
            $up->execute([$uid, $comment !== '' ? $comment : null, $tarfId, $uid]);
        } else {
            $newStatus = 'rejected';
            $up = $db->prepare(
                "UPDATE tarf_requests SET status = ?, rejected_at = NOW(), rejected_by = ?, rejection_reason = ?, rejection_stage = ?, updated_at = NOW()
                 WHERE id = ? AND $jointPendingSql AND endorser_endorsed_at IS NULL AND endorser_target_user_id = ?"
            );
            $up->execute([$newStatus, $uid, $rejectReason, 'endorser', $tarfId, $uid]);
        }
    } elseif ($role === 'fund_availability') {
        if (!tarf_user_can_fund_availability_endorse($row, $uid, $db)) {
            echo json_encode(['success' => false, 'message' => 'You cannot act on this request as Budget/Accounting fund endorser.']);
            exit;
        }
        if ($action === 'endorse') {
            $up = $db->prepare(
                "UPDATE tarf_requests SET fund_availability_endorsed_at = NOW(), fund_availability_endorsed_by = ?, fund_availability_comment = ?, updated_at = NOW()
                 WHERE id = ? AND $jointPendingSql AND fund_availability_endorsed_at IS NULL AND fund_availability_target_user_id = ?"
            );
            $up->execute([$uid, $comment !== '' ? $comment : null, $tarfId, $uid]);
        } else {
            $newStatus = 'rejected';
            $up = $db->prepare(
                "UPDATE tarf_requests SET status = ?, rejected_at = NOW(), rejected_by = ?, rejection_reason = ?, rejection_stage = ?, updated_at = NOW()
                 WHERE id = ? AND $jointPendingSql AND fund_availability_endorsed_at IS NULL AND fund_availability_target_user_id = ?"
            );
            $up->execute([$newStatus, $uid, $rejectReason, 'fund_availability', $tarfId, $uid]);
        }
    } elseif ($role === 'president') {
        if (!tarf_user_can_president_act($row, $uid, $db)) {
            echo json_encode(['success' => false, 'message' => 'You cannot act on this request as President (key official).']);
            exit;
        }
        if ($action === 'endorse') {
            $becameEndorsed = true;
            $newStatus = 'endorsed';
            $up = $db->prepare(
                'UPDATE tarf_requests SET status = ?, president_endorsed_at = NOW(), president_endorsed_by = ?, president_comment = ?, updated_at = NOW()
                 WHERE id = ? AND status = ?'
            );
            $up->execute([$newStatus, $uid, $comment !== '' ? $comment : null, $tarfId, 'pending_president']);
        } else {
            $newStatus = 'rejected';
            $up = $db->prepare(
                'UPDATE tarf_requests SET status = ?, rejected_at = NOW(), rejected_by = ?, rejection_reason = ?, rejection_stage = ?, updated_at = NOW()
                 WHERE id = ? AND status = ?'
            );
            $up->execute([$newStatus, $uid, $rejectReason, 'president', $tarfId, 'pending_president']);
        }
    }

    if (!$up || $up->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => 'This request was already updated. Refresh the page.']);
        exit;
    }

    if ($role !== 'president' && $action === 'endorse') {
        tarf_try_advance_joint_request_to_president($db, $tarfId);
        $st = $db->prepare('SELECT status FROM tarf_requests WHERE id = ? LIMIT 1');
        $st->execute([$tarfId]);
        $newStatus = $st->fetchColumn();
    }

    if ($becameEndorsed) {
        try {
            tarf_request_sync_endorsed_to_attendance($db, $tarfId);
        } catch (Exception $syncEx) {
            error_log('tarf_request_sync_endorsed_to_attendance: ' . $syncEx->getMessage());
        }
        try {
            $stmtFresh = $db->prepare('SELECT * FROM tarf_requests WHERE id = ? LIMIT 1');
            $stmtFresh->execute([$tarfId]);
            $rowFresh = $stmtFresh->fetch(PDO::FETCH_ASSOC);
            if ($rowFresh) {
                tarf_generate_filled_official_order_docx($db, $rowFresh);
            }
        } catch (Exception $docEx) {
            error_log('tarf_generate_filled_official_order_docx: ' . $docEx->getMessage());
        }
    }

    if ($newStatus === 'endorsed' || $newStatus === 'rejected') {
        try {
            require_once __DIR__ . '/../includes/mailer.php';
            $uStmt = $db->prepare('SELECT email, first_name, last_name FROM users WHERE id = ? LIMIT 1');
            $uStmt->execute([(int) ($row['user_id'] ?? 0)]);
            $reqUser = $uStmt->fetch(PDO::FETCH_ASSOC);
            if ($reqUser && !empty($reqUser['email'])) {
                $reqName = trim(($reqUser['first_name'] ?? '') . ' ' . ($reqUser['last_name'] ?? ''));
                $fd = json_decode($row['form_data'] ?? '{}', true);
                $purpose = '';
                if (is_array($fd)) {
                    $purpose = trim((string) ($fd['event_purpose'] ?? ''));
                    if ($purpose === '') {
                        $purpose = trim((string) ($fd['activity_requested'] ?? ''));
                    }
                }
                $serialYear = $row['serial_year'] ?? null;
                $mailer = new Mailer();
                if ($newStatus === 'endorsed') {
                    $mailer->sendTarfRequestApprovedEmail(
                        $reqUser['email'],
                        $reqName,
                        $tarfId,
                        $serialYear,
                        $purpose,
                        $row['created_at'] ?? null
                    );
                } else {
                    $mailer->sendTarfRequestRejectedEmail(
                        $reqUser['email'],
                        $reqName,
                        $tarfId,
                        $serialYear,
                        $purpose,
                        $row['created_at'] ?? null,
                        $rejectReason,
                        $role
                    );
                }
            }
        } catch (Exception $mailEx) {
            error_log('tarf_workflow_action_api email: ' . $mailEx->getMessage());
        }
    }

    $out = [
        'success' => true,
        'message' => $action === 'endorse' ? 'Recorded successfully.' : 'Rejection recorded.',
        'next_status' => $newStatus,
    ];
    if ($newStatus === 'pending_president') {
        $out['routed_to_president'] = true;
    }
    echo json_encode($out);
} catch (Exception $e) {
    error_log('tarf_workflow_action_api: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
