<?php
/**
 * Travel Activity Request Form (TARF) — employee submission (Google Form 3.2 fields; DISAPP view).
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/tarf_form_options.php';
require_once __DIR__ . '/../includes/ntarf_form_options.php';
require_once __DIR__ . '/../includes/tarf_workflow.php';
require_once __DIR__ . '/../includes/tarf_disapp_view_styles.php';

requireFaculty();

$database = Database::getInstance();
$db = $database->getConnection();

$stmt = $db->prepare(
    "SELECT fp.employee_id, fp.department, u.first_name, u.last_name, u.email AS user_email
     FROM faculty_profiles fp
     INNER JOIN users u ON fp.user_id = u.id
     WHERE fp.user_id = ? LIMIT 1"
);
$stmt->execute([$_SESSION['user_id']]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$profile) {
    $_SESSION['error'] = 'Profile not found.';
    redirect(clean_url(getBasePath() . '/faculty/dashboard.php', getBasePath()));
}

$defaultName = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));
$defaultEmail = trim($profile['user_email'] ?? '');

$tableExists = false;
$myRequests = [];
try {
    $tbl = $db->query("SHOW TABLES LIKE 'tarf_requests'");
    $tableExists = $tbl && $tbl->rowCount() > 0;
    if ($tableExists) {
        $q = $db->prepare(
            "SELECT id, serial_year, form_data, created_at, status FROM tarf_requests
             WHERE user_id = ? ORDER BY created_at DESC LIMIT 15"
        );
        $q->execute([$_SESSION['user_id']]);
        $myRequests = $q->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $tableExists = false;
}

$myRequestsPending = [];
$myRequestsRejected = [];
foreach ($myRequests as $r) {
    $st = $r['status'] ?? 'pending_supervisor';
    if ($st === 'pending') {
        $st = 'pending_supervisor';
    }
    if ($st === 'rejected') {
        $myRequestsRejected[] = $r;
    }
    if ($st === 'pending_supervisor' || $st === 'pending_endorser' || $st === 'pending_joint' || $st === 'pending_president') {
        $myRequestsPending[] = $r;
    }
}

if (!function_exists('tarf_render_my_tarf_list_rows')) {
    /**
     * @param array<int, array<string, mixed>> $rows
     * @param bool $useViewModal If true, "View" opens the DISAPP preview modal (same layout as tarf_request_view.php).
     */
    function tarf_render_my_tarf_list_rows(array $rows, string $basePath, bool $useViewModal = false): void
    {
        $stLabels = [
            'pending_joint' => ['Awaiting endorsements', 'warning'],
            'pending_supervisor' => ['Awaiting endorsements', 'warning'],
            'pending_endorser' => ['Awaiting endorsements', 'warning'],
            'pending_president' => ['With President (final)', 'primary'],
            'endorsed' => ['Approved', 'success'],
            'rejected' => ['Rejected', 'danger'],
        ];
        if (empty($rows)) {
            ?>
            <div class="tarf-mr-empty text-center py-5 px-4">
                <div class="tarf-mr-empty-icon mb-3" aria-hidden="true"><i class="fas fa-folder-open"></i></div>
                <p class="text-muted small mb-1 fw-medium">Nothing here yet</p>
                <p class="text-muted small mb-0 opacity-75">Submissions that match this tab will appear in this list.</p>
            </div>
            <?php
            return;
        }
        echo '<ul class="list-group list-group-flush tarf-mr-list">';
        foreach ($rows as $r) {
            $fd = json_decode($r['form_data'], true);
            $title = '';
            if (is_array($fd)) {
                $title = (($fd['form_kind'] ?? '') === 'ntarf')
                    ? ($fd['activity_requested'] ?? '')
                    : ($fd['event_purpose'] ?? '');
            }
            if ($title !== '') {
                $title = function_exists('mb_strimwidth')
                    ? mb_strimwidth($title, 0, 80, '…', 'UTF-8')
                    : (strlen($title) > 80 ? substr($title, 0, 77) . '...' : $title);
            } else {
                $title = 'Request #' . (int) $r['id'];
            }
            $st = $r['status'] ?? 'pending_supervisor';
            if ($st === 'pending') {
                $st = 'pending_supervisor';
            }
            $stInfo = $stLabels[$st] ?? ['Submitted', 'secondary'];
            $createdRaw = $r['created_at'] ?? '';
            $createdTs = $createdRaw !== '' ? strtotime($createdRaw) : false;
            $dateIso = $createdTs ? date('Y-m-d', $createdTs) : '';
            $dateDisp = $createdTs ? date('M j, Y', $createdTs) : '—';
            ?>
                                <li class="list-group-item tarf-mr-item border-0 border-bottom" data-tarf-status="<?php echo htmlspecialchars($st, ENT_QUOTES, 'UTF-8'); ?>">
                                    <div class="d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center justify-content-between gap-3 py-1">
                                        <div class="tarf-mr-item-main flex-grow-1 min-w-0">
                                            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                                <span class="tarf-mr-id fw-semibold text-body"><?php echo (is_array($fd) && (($fd['form_kind'] ?? '') === 'ntarf')) ? 'NTARF' : 'TARF'; ?> #<?php echo (int) $r['id']; ?></span>
                                                <span class="badge rounded-pill bg-<?php echo htmlspecialchars($stInfo[1], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($stInfo[0], ENT_QUOTES, 'UTF-8'); ?></span>
                                            </div>
                                            <div class="d-flex align-items-center gap-2 small text-muted mb-1">
                                                <i class="fas fa-calendar-alt fa-fw tarf-mr-meta-icon" aria-hidden="true"></i>
                                                <?php if ($dateIso !== ''): ?>
                                                <time datetime="<?php echo htmlspecialchars($dateIso, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($dateDisp, ENT_QUOTES, 'UTF-8'); ?></time>
                                                <?php else: ?>
                                                <span><?php echo htmlspecialchars($dateDisp, ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <p class="tarf-mr-purpose small text-secondary mb-0 text-break" title="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                        <?php if ($useViewModal): ?>
                                        <button type="button" class="btn btn-sm btn-primary tarf-mr-cta align-self-stretch align-self-sm-center flex-shrink-0 btn-tarf-open-view" data-tarf-id="<?php echo (int) $r['id']; ?>">
                                            <i class="fas fa-file-alt me-1" aria-hidden="true"></i>View
                                        </button>
                                        <?php else:
                                            $view = clean_url($basePath . '/faculty/tarf_request_view.php?id=' . (int) $r['id'], $basePath);
                                            ?>
                                        <a class="btn btn-sm btn-primary tarf-mr-cta align-self-stretch align-self-sm-center flex-shrink-0" href="<?php echo htmlspecialchars($view, ENT_QUOTES, 'UTF-8'); ?>">
                                            <i class="fas fa-file-alt me-1" aria-hidden="true"></i>View / print
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </li>
            <?php
        }
        echo '</ul>';
    }
}

$opts = tarf_get_form_options();
$ntarfOpts = ntarf_get_form_options();
$basePath = getBasePath();

$travelPersonRows = [];
try {
    $tpStmt = $db->query(
        "SELECT fp.user_id, fp.employee_id, fp.designation, fp.position, fp.department,
                u.first_name, u.last_name, u.user_type
         FROM faculty_profiles fp
         INNER JOIN users u ON u.id = fp.user_id
         WHERE u.user_type IN ('faculty', 'staff') AND u.is_active = 1
         ORDER BY u.last_name ASC, u.first_name ASC"
    );
    if ($tpStmt) {
        $travelPersonRows = $tpStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $travelPersonRows = [];
}

require_once __DIR__ . '/../includes/navigation.php';
include_navigation();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity requests — TARF &amp; NTARF</title>
    <link rel="icon" type="image/png" href="<?php echo asset_url('logo.png', true); ?>">
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/faculty-portal.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/mobile.css', true); ?>" rel="stylesheet">
    <?php tarf_emit_disapp_view_styles(); ?>
    <style>
        .tarf-page .page-title { font-size: 1.5rem; font-weight: 600; color: var(--primary-blue, #003366); }
        .tarf-section { margin-bottom: 1.5rem; }
        .tarf-section h2 { font-size: 1.05rem; font-weight: 600; color: #00264d; border-bottom: 2px solid rgba(0,51,102,0.15); padding-bottom: 0.35rem; margin-bottom: 1rem; }
        .tarf-toast { position: fixed; bottom: 1.5rem; left: 50%; transform: translateX(-50%) translateY(120px); z-index: 9999; min-width: 280px; max-width: 92vw; padding: 1rem 1.25rem; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.15); opacity: 0; transition: all 0.35s ease; }
        .tarf-toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
        .tarf-toast.success { background: #059669; color: #fff; }
        .tarf-toast.error { background: #dc2626; color: #fff; }
        .tarf-travel-pick-wrap { border: 1px solid rgba(0,0,0,0.12); border-radius: 0.375rem; background: #fff; }
        .tarf-travel-pick-search { border-bottom: 1px solid rgba(0,0,0,0.08); }
        .tarf-travel-pick { max-height: 280px; overflow-y: auto; padding: 0.5rem 0.75rem; }
        .tarf-travel-pick .form-check { padding-top: 0.2rem; padding-bottom: 0.2rem; border-bottom: 1px solid rgba(0,0,0,0.04); }
        .tarf-travel-pick .form-check:last-child { border-bottom: 0; }
        .tarf-travel-pick .form-check-label { cursor: pointer; font-size: 0.925rem; }
        .tarf-my-requests-modal .modal-content {
            border: none;
            border-radius: 0.75rem;
            box-shadow: 0 1rem 2.5rem rgba(0, 38, 77, 0.12);
            overflow: hidden;
        }
        .tarf-my-requests-modal .modal-header {
            background: linear-gradient(180deg, rgba(0, 51, 102, 0.04) 0%, #fff 100%);
            border-bottom: 1px solid rgba(0, 51, 102, 0.1);
            padding: 1rem 1.25rem;
        }
        .tarf-my-requests-modal .modal-title {
            font-weight: 600;
            color: var(--primary-blue, #003366);
        }
        .tarf-my-requests-modal .tarf-my-requests-modal-body {
            max-height: min(85vh, 36rem);
            display: flex;
            flex-direction: column;
            background: #f8fafc;
        }
        .tarf-my-requests-modal .tarf-my-requests-tabs-wrap {
            flex-shrink: 0;
            background: #fff;
            border-bottom: 1px solid rgba(0, 51, 102, 0.1);
        }
        .tarf-my-requests-modal .tarf-my-requests-tabs-scroll {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
        }
        .tarf-my-requests-modal .tarf-my-requests-tabs-scroll .nav-tabs {
            flex-wrap: nowrap;
            border-bottom: none;
            min-height: 2.75rem;
        }
        .tarf-my-requests-modal .tarf-my-requests-tabs-scroll .nav-link {
            border: none;
            border-bottom: 3px solid transparent;
            border-radius: 0;
            color: #64748b;
            font-weight: 500;
            padding: 0.65rem 1rem;
            white-space: nowrap;
            transition: color 0.15s ease, border-color 0.15s ease, background 0.15s ease;
        }
        .tarf-my-requests-modal .tarf-my-requests-tabs-scroll .nav-link:hover {
            color: var(--primary-blue, #003366);
            background: rgba(0, 51, 102, 0.04);
        }
        .tarf-my-requests-modal .tarf-my-requests-tabs-scroll .nav-link.active {
            color: var(--primary-blue, #003366);
            background: transparent;
            border-bottom-color: var(--primary-blue, #003366);
        }
        .tarf-my-requests-modal .tarf-my-requests-tabs-scroll .nav-link:focus-visible {
            outline: 2px solid var(--primary-blue, #003366);
            outline-offset: -2px;
        }
        .tarf-my-requests-modal .tarf-tab-badge {
            font-size: 0.7rem;
            font-weight: 600;
            vertical-align: middle;
        }
        .tarf-my-requests-modal .tarf-my-requests-modal-body .tab-content {
            overflow-y: auto;
            flex: 1 1 auto;
            min-height: 0;
            background: #fff;
        }
        .tarf-my-requests-modal .tarf-mr-list .tarf-mr-item {
            padding-left: 1rem;
            padding-right: 1rem;
            border-left: 3px solid rgba(100, 116, 139, 0.4);
            transition: background 0.15s ease;
        }
        .tarf-my-requests-modal .tarf-mr-list .tarf-mr-item:last-child {
            border-bottom: none !important;
        }
        .tarf-my-requests-modal .tarf-mr-list .tarf-mr-item:hover {
            background: rgba(0, 51, 102, 0.03);
        }
        .tarf-my-requests-modal .tarf-mr-item[data-tarf-status="endorsed"] { border-left-color: var(--bs-success); }
        .tarf-my-requests-modal .tarf-mr-item[data-tarf-status="rejected"] { border-left-color: var(--bs-danger); }
        .tarf-my-requests-modal .tarf-mr-item[data-tarf-status="pending_joint"] { border-left-color: var(--bs-warning); }
        .tarf-my-requests-modal .tarf-mr-item[data-tarf-status="pending_supervisor"] { border-left-color: var(--bs-warning); }
        .tarf-my-requests-modal .tarf-mr-item[data-tarf-status="pending_endorser"] { border-left-color: var(--bs-warning); }
        .tarf-my-requests-modal .tarf-mr-item[data-tarf-status="pending_president"] { border-left-color: var(--bs-primary); }
        .tarf-my-requests-modal .tarf-mr-meta-icon { opacity: 0.65; font-size: 0.85em; }
        .tarf-my-requests-modal .tarf-mr-purpose {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .tarf-my-requests-modal .tarf-mr-empty {
            background: #fff;
        }
        .tarf-my-requests-modal .tarf-mr-empty-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 3.5rem;
            height: 3.5rem;
            margin-inline: auto;
            border-radius: 50%;
            background: rgba(0, 51, 102, 0.06);
            color: var(--primary-blue, #003366);
            font-size: 1.35rem;
            opacity: 0.85;
        }
        .tarf-request-preview-modal .modal-dialog {
            max-width: min(1140px, 98vw);
        }
        .tarf-request-preview-modal .modal-content {
            overflow: visible;
        }
        .tarf-request-preview-modal .modal-body {
            max-height: min(85vh, 920px);
            overflow-x: hidden;
            overflow-y: auto;
            background: #fff;
        }
        .tarf-main-tabs .nav-link {
            font-weight: 500;
            color: #64748b;
            border-bottom-width: 3px;
        }
        .tarf-main-tabs .nav-link.active {
            color: var(--primary-blue, #003366);
            border-bottom-color: var(--primary-blue, #003366);
        }
    </style>
</head>
<body class="layout-faculty tarf-page">
    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <div class="page-header py-3">
                    <h1 class="page-title"><i class="fas fa-clipboard-list me-2"></i>Activity requests (TARF &amp; NTARF)</h1>
                    <p class="text-muted mb-0">Use the tabs for <strong>TARF</strong> (travel) or <strong>NTARF</strong> (non-travel). The President receives your request only after parallel endorsement by your <strong>supervisor</strong>, <strong>applicable endorser</strong>, and—when fund certification applies—a third endorsement from <strong>Budget or Accounting</strong> (per your form). Requests without fund certification require endorsements from supervisor and applicable endorser only. <strong>NTARF</strong> is on-site: you and everyone involved must still record <strong>time in, lunch out, lunch in, and time out</strong> at the timekeeper on each activity day. Only <strong>travel TARF</strong> uses system auto-attendance from official time.</p>
                </div>

                <ul class="nav nav-tabs mb-3 flex-nowrap tarf-main-tabs" id="activityRequestMainTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="tab-tarf-main" data-bs-toggle="tab" data-bs-target="#pane-tarf-main" type="button" role="tab" aria-controls="pane-tarf-main" aria-selected="true">
                            <i class="fas fa-plane-departure me-1"></i>TARF <span class="d-none d-sm-inline">(Travel)</span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-ntarf-main" data-bs-toggle="tab" data-bs-target="#pane-ntarf-main" type="button" role="tab" aria-controls="pane-ntarf-main" aria-selected="false">
                            <i class="fas fa-building me-1"></i>NTARF <span class="d-none d-sm-inline">(Non-travel)</span>
                        </button>
                    </li>
                </ul>

                <?php displayMessage(); ?>

                <?php if (!$tableExists): ?>
                    <div class="alert alert-warning">
                        <strong>Not available.</strong> The administrator must run <code>db/migrations/run_tarf_requests_migration.php</code> once.
                    </div>
                <?php else: ?>
                <?php
                    $csrfPageToken = generateFormToken();
                    $nRecent = count($myRequests);
                    $nPending = count($myRequestsPending);
                    $nRejected = count($myRequestsRejected);
                ?>
                <div class="mb-4 d-flex flex-wrap align-items-center gap-2">
                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#tarfMyRequestsModal" aria-expanded="false" aria-controls="tarfMyRequestsModal" aria-haspopup="dialog">
                        <i class="fas fa-list me-1"></i>My requests
                        <span class="badge bg-primary ms-1"><?php echo (int) $nRecent; ?></span>
                    </button>
                    <?php if ($nPending > 0): ?>
                    <span class="text-muted small"><i class="fas fa-hourglass-half me-1"></i><?php echo (int) $nPending; ?> pending</span>
                    <?php endif; ?>
                    <?php if ($nRejected > 0): ?>
                    <span class="text-muted small"><i class="fas fa-times-circle me-1"></i><?php echo (int) $nRejected; ?> rejected</span>
                    <?php endif; ?>
                </div>

                <div class="modal fade tarf-my-requests-modal" id="tarfMyRequestsModal" tabindex="-1" aria-labelledby="tarfMyRequestsModalLabel" aria-describedby="tarfMyRequestsModalDesc" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered modal-lg">
                        <div class="modal-content">
                            <div class="modal-header align-items-start">
                                <div class="pe-3">
                                    <h5 class="modal-title" id="tarfMyRequestsModalLabel"><i class="fas fa-clipboard-list me-2 text-primary" aria-hidden="true"></i>My activity requests</h5>
                                    <p class="small text-muted mb-0 mt-1" id="tarfMyRequestsModalDesc">Up to your 15 most recent submissions. Open a request for the printable layout.</p>
                                </div>
                                <button type="button" class="btn-close mt-1" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body p-0 tarf-my-requests-modal-body">
                                <div class="tarf-my-requests-tabs-wrap">
                                    <div class="tarf-my-requests-tabs-scroll px-1 px-sm-2">
                                        <ul class="nav nav-tabs mb-0" id="tarfMyRequestsTabs" role="tablist">
                                            <li class="nav-item" role="presentation">
                                                <button class="nav-link active" id="tarf-tab-recent" data-bs-toggle="tab" data-bs-target="#tarf-pane-recent" type="button" role="tab" aria-controls="tarf-pane-recent" aria-selected="true">
                                                    Recent <span class="badge tarf-tab-badge rounded-pill bg-secondary ms-1"><?php echo (int) $nRecent; ?></span>
                                                </button>
                                            </li>
                                            <li class="nav-item" role="presentation">
                                                <button class="nav-link" id="tarf-tab-pending" data-bs-toggle="tab" data-bs-target="#tarf-pane-pending" type="button" role="tab" aria-controls="tarf-pane-pending" aria-selected="false">
                                                    Pending <span class="badge tarf-tab-badge rounded-pill bg-warning text-dark ms-1"><?php echo (int) $nPending; ?></span>
                                                </button>
                                            </li>
                                            <li class="nav-item" role="presentation">
                                                <button class="nav-link" id="tarf-tab-rejected" data-bs-toggle="tab" data-bs-target="#tarf-pane-rejected" type="button" role="tab" aria-controls="tarf-pane-rejected" aria-selected="false">
                                                    Rejected <span class="badge tarf-tab-badge rounded-pill bg-danger ms-1"><?php echo (int) $nRejected; ?></span>
                                                </button>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                                <div class="tab-content border-0" id="tarfMyRequestsTabContent">
                                    <div class="tab-pane fade show active" id="tarf-pane-recent" role="tabpanel" aria-labelledby="tarf-tab-recent" tabindex="0">
                                        <?php tarf_render_my_tarf_list_rows($myRequests, $basePath, true); ?>
                                    </div>
                                    <div class="tab-pane fade" id="tarf-pane-pending" role="tabpanel" aria-labelledby="tarf-tab-pending" tabindex="0">
                                        <?php tarf_render_my_tarf_list_rows($myRequestsPending, $basePath, true); ?>
                                    </div>
                                    <div class="tab-pane fade" id="tarf-pane-rejected" role="tabpanel" aria-labelledby="tarf-tab-rejected" tabindex="0">
                                        <?php tarf_render_my_tarf_list_rows($myRequestsRejected, $basePath, true); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal fade tarf-request-preview-modal" id="tarfRequestViewModal" tabindex="-1" aria-labelledby="tarfRequestViewModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header align-items-center py-2 py-sm-3">
                                <h5 class="modal-title mb-0" id="tarfRequestViewModalLabel"><i class="fas fa-file-contract me-2 text-primary" aria-hidden="true"></i>Activity request</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body p-2 p-sm-3" id="tarfRequestViewModalBody">
                                <div class="text-center py-5 text-muted small">Select a request from the list.</div>
                            </div>
                            <div class="modal-footer flex-wrap gap-2 py-2">
                                <a class="btn btn-success btn-sm d-none" id="tarfRequestViewModalPdf" href="#" target="_blank" rel="noopener">
                                    <i class="fas fa-file-pdf me-1" aria-hidden="true"></i>Download PDF
                                </a>
                                <button type="button" class="btn btn-primary btn-sm" id="tarfRequestViewModalPrint">
                                    <i class="fas fa-print me-1" aria-hidden="true"></i>Print
                                </button>
                                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-content border-0" id="activityRequestTabsContent">
                <div class="tab-pane fade show active" id="pane-tarf-main" role="tabpanel" aria-labelledby="tab-tarf-main" tabindex="0">
                <div class="card shadow-sm mb-5">
                    <div class="card-body">
                        <form id="tarfForm" method="post" action="<?php echo htmlspecialchars(clean_url($basePath . '/faculty/tarf_request_submit_api.php', $basePath), ENT_QUOTES, 'UTF-8'); ?>" enctype="multipart/form-data" class="tarf-form">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfPageToken, ENT_QUOTES, 'UTF-8'); ?>">

                            <div class="tarf-section">
                                <h2>Requester</h2>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Name of requester <span class="text-danger">*</span></label>
                                        <input type="text" name="requester_name" class="form-control" required value="<?php echo htmlspecialchars($defaultName, ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Email <span class="text-danger">*</span></label>
                                        <input type="email" name="requester_email" class="form-control" required value="<?php echo htmlspecialchars($defaultEmail, ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">College / Office / Project <span class="text-danger">*</span></label>
                                        <select name="college_office" id="college_office" class="form-select" required>
                                            <option value="">— Select —</option>
                                            <?php foreach ($opts['colleges'] as $c): ?>
                                                <option value="<?php echo htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?></option>
                                            <?php endforeach; ?>
                                            <option value="__other__">Other (specify below)</option>
                                        </select>
                                        <input type="text" name="college_office_other" id="college_office_other" class="form-control mt-2 d-none" placeholder="Specify college, office, or project">
                                    </div>
                                </div>
                            </div>

                            <div class="tarf-section">
                                <h2>Details of travel</h2>
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label">Type of event / purpose category <span class="text-danger">*</span></label>
                                        <select name="travel_purpose_type" class="form-select" required>
                                            <option value="">— Select —</option>
                                            <?php foreach ($opts['travel_purpose_types'] as $t): ?>
                                                <option value="<?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Event to attend / purpose of travel <span class="text-danger">*</span></label>
                                        <textarea name="event_purpose" class="form-control" rows="3" required placeholder="Title of program, activity, training, conference, etc."></textarea>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Person/s to travel (position) <span class="text-danger">*</span></label>
                                        <p class="small text-muted mb-2">Select employees from the directory. Use “Additional travelers” for names not listed (e.g. guests, students).</p>
                                        <?php if (empty($travelPersonRows)): ?>
                                            <div class="alert alert-warning py-2 mb-2">No directory entries loaded. Enter travelers under “Additional travelers” only, or contact the administrator.</div>
                                        <?php else: ?>
                                        <div class="tarf-travel-pick-wrap mb-2">
                                            <div class="tarf-travel-pick-search p-2">
                                                <input type="search" class="form-control form-control-sm" id="tarfTravelPickSearch" placeholder="Search by name, position, department, or employee ID…" autocomplete="off">
                                            </div>
                                            <div class="tarf-travel-pick" id="tarfTravelPickList">
                                                <?php foreach ($travelPersonRows as $tpr):
                                                    $uid = (int) ($tpr['user_id'] ?? 0);
                                                    if ($uid <= 0) {
                                                        continue;
                                                    }
                                                    $line = tarf_travel_person_display_line($tpr);
                                                    $emp = trim((string) ($tpr['employee_id'] ?? ''));
                                                    $dept = trim((string) ($tpr['department'] ?? ''));
                                                    $role = tarf_travel_person_role_label($tpr);
                                                    $fn = trim((string) ($tpr['first_name'] ?? ''));
                                                    $ln = trim((string) ($tpr['last_name'] ?? ''));
                                                    $searchBits = strtolower($fn . ' ' . $ln . ' ' . $role . ' ' . $dept . ' ' . $emp);
                                                    ?>
                                                <div class="form-check tarf-travel-pick-row" data-search="<?php echo htmlspecialchars($searchBits, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input class="form-check-input" type="checkbox" name="persons_to_travel_user_ids[]" value="<?php echo $uid; ?>" id="ptt_<?php echo $uid; ?>">
                                                    <label class="form-check-label" for="ptt_<?php echo $uid; ?>"><?php echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8'); ?></label>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        <label class="form-label small text-muted">Additional travelers <span class="text-muted fw-normal">(optional)</span></label>
                                        <textarea name="persons_to_travel_other" class="form-control" rows="2" placeholder="One per line: e.g. Guest Name (Role), student representatives, etc."></textarea>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Destination/s <span class="text-danger">*</span></label>
                                        <textarea name="destination" class="form-control" rows="2" required></textarea>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Justification / explanation <span class="text-danger">*</span></label>
                                        <textarea name="justification" class="form-control" rows="3" required></textarea>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Are any persons to travel COS or JO status? <span class="text-danger">*</span></label>
                                        <?php $cosFirst = true; foreach ($opts['cos_jo_options'] as $val => $lab): ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="cos_jo" value="<?php echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); ?>" id="cos_<?php echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $cosFirst ? ' required' : ''; ?>>
                                                <label class="form-check-label" for="cos_<?php echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($lab, ENT_QUOTES, 'UTF-8'); ?></label>
                                            </div>
                                        <?php $cosFirst = false; endforeach; ?>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Date of departure <span class="text-danger">*</span></label>
                                        <input type="date" name="date_departure" class="form-control" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Date of return <span class="text-danger">*</span></label>
                                        <input type="date" name="date_return" class="form-control" required>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Supporting documents (optional)</label>
                                        <input type="file" name="supporting_documents[]" class="form-control" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                        <div class="form-text">PDF, Word, or images. Up to 10 files.</div>
                                    </div>
                                </div>
                            </div>

                            <div class="tarf-section">
                                <h2>Endorsement &amp; publicity</h2>
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label">Applicable endorser <span class="text-danger">*</span></label>
                                        <select name="applicable_endorser" class="form-select" required>
                                            <option value="">— Select —</option>
                                            <?php foreach ($opts['endorsers'] as $e): ?>
                                                <option value="<?php echo htmlspecialchars($e, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($e, ENT_QUOTES, 'UTF-8'); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Email of immediate supervisor or college/office/campus/project email <span class="text-danger">*</span></label>
                                        <input type="email" name="supervisor_email" class="form-control" required placeholder="e.g. unit@wpu.edu.ph">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Support requested (publicity / coverage) <span class="text-danger">*</span></label>
                                        <?php foreach ($opts['publicity_support'] as $val => $lab): ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="publicity[]" value="<?php echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); ?>" id="pub_<?php echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); ?>">
                                                <label class="form-check-label" for="pub_<?php echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($lab, ENT_QUOTES, 'UTF-8'); ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                        <input type="text" name="publicity_other" class="form-control mt-2" placeholder="Other (optional)">
                                    </div>
                                </div>
                            </div>

                            <div class="tarf-section">
                                <h2>Travel type &amp; support</h2>
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label">Type of travel requested <span class="text-danger">*</span></label>
                                        <?php $trtFirst = true; foreach ($opts['travel_request_type'] as $val => $lab): ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="travel_request_type" value="<?php echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); ?>" id="trt_<?php echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $trtFirst ? ' required' : ''; ?>>
                                                <label class="form-check-label" for="trt_<?php echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($lab, ENT_QUOTES, 'UTF-8'); ?></label>
                                            </div>
                                        <?php $trtFirst = false; endforeach; ?>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Requested support <span class="text-danger">*</span></label>
                                        <?php foreach ($opts['travel_support'] as $val => $lab): ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="support_travel[]" value="<?php echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); ?>" id="st_<?php echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); ?>">
                                                <label class="form-check-label" for="st_<?php echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($lab, ENT_QUOTES, 'UTF-8'); ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                        <textarea name="support_travel_other" class="form-control mt-2" rows="2" placeholder="Other support or budgetary requirements (optional)"></textarea>
                                    </div>
                                </div>
                            </div>

                            <div class="tarf-section border rounded p-3 bg-light d-none" id="fundingBlock" aria-hidden="true">
                                <h2 class="border-0">Funding (required for official business)</h2>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Funding charged to <span class="tarf-fund-req text-danger d-none" aria-hidden="true">*</span></label>
                                        <select name="funding_charged_to" id="funding_charged_to" class="form-select">
                                            <option value="">—</option>
                                            <?php foreach ($opts['funding_charged'] as $f): ?>
                                                <option value="<?php echo htmlspecialchars($f, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($f, ENT_QUOTES, 'UTF-8'); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Funding specifier <span class="tarf-fund-req text-danger d-none" aria-hidden="true">*</span></label>
                                        <select name="funding_specifier" id="funding_specifier" class="form-select">
                                            <option value="">—</option>
                                            <?php foreach ($opts['funding_specifiers'] as $f): ?>
                                                <option value="<?php echo htmlspecialchars($f, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($f, ENT_QUOTES, 'UTF-8'); ?></option>
                                            <?php endforeach; ?>
                                            <option value="__other__">Other (specify)</option>
                                        </select>
                                        <input type="text" name="funding_specifier_other" id="funding_specifier_other" class="form-control mt-2 d-none" placeholder="Project name or funding institution">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Endorser for fund availability <span class="tarf-fund-req text-danger d-none" aria-hidden="true">*</span></label>
                                        <select name="endorser_fund_availability" id="endorser_fund_availability" class="form-select" aria-describedby="endorser_fund_help">
                                            <option value="">— Select Budget or Accounting —</option>
                                            <?php foreach ($opts['fund_endorser_role'] as $val => $lab): ?>
                                                <option value="<?php echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($lab, ENT_QUOTES, 'UTF-8'); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div id="endorser_fund_help" class="form-text">Required when travel type is Official Business (certification by Budget for Fund 101/164, or Accounting for Fund 184).</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Total estimated amount (IOT + LIB) <span class="tarf-fund-req text-danger d-none" aria-hidden="true">*</span></label>
                                        <input type="text" name="total_estimated_amount" id="total_estimated_amount" class="form-control" placeholder="e.g. 15000">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Itinerary of travel (IOT)</label>
                                        <input type="file" name="itinerary_file[]" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Line item budget (LIB), optional</label>
                                        <input type="file" name="lib_file[]" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex flex-wrap gap-2">
                                <button type="submit" class="btn btn-primary" id="tarfSubmitBtn"><i class="fas fa-paper-plane me-1"></i>Submit TARF</button>
                                <button type="reset" class="btn btn-outline-secondary">Reset</button>
                            </div>
                        </form>
                    </div>
                </div>
                </div>

                <div class="tab-pane fade" id="pane-ntarf-main" role="tabpanel" aria-labelledby="tab-ntarf-main" tabindex="0">
                <div class="card shadow-sm mb-5 border-primary border-opacity-25">
                    <div class="card-body">
                        <div class="alert alert-info mb-4" role="status">
                            <p class="mb-2"><strong>One form per activity.</strong> Please file one NTARF per discrete activity, session, venue, or period of time.</p>
                            <p class="mb-0"><strong>Time entry required.</strong> Everyone listed must complete time in, lunch out, lunch in, and time out at the timekeeper on each activity day. This approval does not replace DTR logging.</p>
                        </div>
                        <form id="ntarfForm" method="post" action="<?php echo htmlspecialchars(clean_url($basePath . '/faculty/ntarf_request_submit_api.php', $basePath), ENT_QUOTES, 'UTF-8'); ?>" enctype="multipart/form-data" class="tarf-form">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfPageToken, ENT_QUOTES, 'UTF-8'); ?>">

                            <div class="tarf-section">
                                <h2>Requester</h2>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Email <span class="text-danger">*</span></label>
                                        <input type="email" name="requester_email" class="form-control" required value="<?php echo htmlspecialchars($defaultEmail, ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Name of requester <span class="text-danger">*</span></label>
                                        <input type="text" name="requester_name" class="form-control" required value="<?php echo htmlspecialchars($defaultName, ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">College / Office / Project <span class="text-danger">*</span></label>
                                        <select name="college_office" id="ntarf_college_office" class="form-select" required>
                                            <option value="">— Select —</option>
                                            <?php foreach ($ntarfOpts['colleges'] as $c): ?>
                                                <option value="<?php echo htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($c, ENT_QUOTES, 'UTF-8'); ?></option>
                                            <?php endforeach; ?>
                                            <option value="__other__">Other (specify below)</option>
                                        </select>
                                        <input type="text" name="college_office_other" id="ntarf_college_office_other" class="form-control mt-2 d-none" placeholder="Specify college, office, or project">
                                    </div>
                                </div>
                            </div>

                            <div class="tarf-section">
                                <h2>Details of activity</h2>
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label">Activity requested <span class="text-danger">*</span></label>
                                        <div class="form-text">For multiple applications for the same event, add a number at the beginning (e.g. Session 1). State the title of the program, project, activity, training, or conference.</div>
                                        <textarea name="activity_requested" class="form-control" rows="3" required placeholder="e.g. Session 3 – Skills Training on Banana Chips Processing…"></textarea>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Justification / explanation <span class="text-danger">*</span></label>
                                        <textarea name="justification" class="form-control" rows="3" required placeholder="e.g. College activity for student development; invitation from PCSD; paper accepted for presentation…"></textarea>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Main organizer <span class="text-danger">*</span></label>
                                        <input type="text" name="main_organizer" class="form-control" required placeholder="Person or unit organizing the activity">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Which campus will serve as the venue? <span class="text-danger">*</span></label>
                                        <div class="form-text">If the activity is outside any campus, select OUTSIDE THE CAMPUS and specify the exact location below.</div>
                                        <select name="activity_campus" id="nw_activity_campus" class="form-select" required>
                                            <option value="">— Select —</option>
                                            <?php foreach ($ntarfOpts['activity_campuses'] as $ac): ?>
                                                <option value="<?php echo htmlspecialchars($ac, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($ac, ENT_QUOTES, 'UTF-8'); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="text" name="activity_campus_other" id="nw_activity_campus_other" class="form-control mt-2 d-none" placeholder="Exact off-campus location (required if outside the campus)">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Venue <span class="text-danger">*</span></label>
                                        <div class="form-text">This form is for <strong>one</strong> venue only; file a separate NTARF for each location. For videoconference, select ZOOM, GOOGLE MEET, or ONLINE as appropriate.</div>
                                        <select name="venue_site" id="nw_venue_site" class="form-select" required>
                                            <option value="">— Select —</option>
                                            <?php foreach ($ntarfOpts['venue_sites'] as $vs): ?>
                                                <option value="<?php echo htmlspecialchars($vs, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($vs, ENT_QUOTES, 'UTF-8'); ?></option>
                                            <?php endforeach; ?>
                                            <option value="__other__">Other (specify below)</option>
                                        </select>
                                        <input type="text" name="venue_site_other" id="nw_venue_site_other" class="form-control mt-2 d-none" placeholder="Specify exact venue">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Date of activity (start) <span class="text-danger">*</span></label>
                                        <input type="date" name="date_activity_start" class="form-control" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Date of activity (end) <span class="text-danger">*</span></label>
                                        <input type="date" name="date_activity_end" class="form-control" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Time of activity (start) <span class="text-danger">*</span></label>
                                        <input type="time" name="time_activity_start" class="form-control" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Time of activity (end) <span class="text-danger">*</span></label>
                                        <input type="time" name="time_activity_end" class="form-control" required>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Involved WPU personnel (position) <span class="text-danger">*</span></label>
                                        <p class="small text-muted mb-2">e.g. Dr. Sam Paul Neim (CPAM Dean). Select from the directory and/or add lines below.</p>
                                        <?php if (empty($travelPersonRows)): ?>
                                            <div class="alert alert-warning py-2 mb-2">No directory entries loaded. Enter personnel under Additional involved personnel only.</div>
                                        <?php else: ?>
                                        <div class="tarf-travel-pick-wrap mb-2">
                                            <div class="tarf-travel-pick-search p-2">
                                                <input type="search" class="form-control form-control-sm" id="ntarfTravelPickSearch" placeholder="Search by name, position, department, or employee ID…" autocomplete="off">
                                            </div>
                                            <div class="tarf-travel-pick" id="ntarfTravelPickList">
                                                <?php foreach ($travelPersonRows as $tpr):
                                                    $uid = (int) ($tpr['user_id'] ?? 0);
                                                    if ($uid <= 0) {
                                                        continue;
                                                    }
                                                    $line = tarf_travel_person_display_line($tpr);
                                                    $emp = trim((string) ($tpr['employee_id'] ?? ''));
                                                    $dept = trim((string) ($tpr['department'] ?? ''));
                                                    $role = tarf_travel_person_role_label($tpr);
                                                    $fn = trim((string) ($tpr['first_name'] ?? ''));
                                                    $ln = trim((string) ($tpr['last_name'] ?? ''));
                                                    $searchBits = strtolower($fn . ' ' . $ln . ' ' . $role . ' ' . $dept . ' ' . $emp);
                                                    ?>
                                                <div class="form-check tarf-travel-pick-row" data-search="<?php echo htmlspecialchars($searchBits, ENT_QUOTES, 'UTF-8'); ?>">
                                                    <input class="form-check-input" type="checkbox" name="involved_personnel_user_ids[]" value="<?php echo $uid; ?>" id="inv_<?php echo $uid; ?>">
                                                    <label class="form-check-label" for="inv_<?php echo $uid; ?>"><?php echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8'); ?></label>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        <label class="form-label small text-muted">Additional involved personnel</label>
                                        <textarea name="involved_personnel_other" class="form-control" rows="2" placeholder="One per line if not in directory"></textarea>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Type of involvement <span class="text-danger">*</span></label>
                                        <div class="form-text">Check all that apply.</div>
                                        <?php foreach ($ntarfOpts['involvement_types'] as $ik => $ilab): ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="involvement_types[]" value="<?php echo htmlspecialchars($ik, ENT_QUOTES, 'UTF-8'); ?>" id="ninv_<?php echo htmlspecialchars($ik, ENT_QUOTES, 'UTF-8'); ?>">
                                                <label class="form-check-label" for="ninv_<?php echo htmlspecialchars($ik, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($ilab, ENT_QUOTES, 'UTF-8'); ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                        <input type="text" name="involvement_other" class="form-control mt-2" placeholder="Other type of involvement (optional)">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Supporting documents for approval (optional)</label>
                                        <input type="file" name="supporting_documents[]" class="form-control" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                        <div class="form-text">e.g. invitation, letter of acceptance. PDF, Word, or images. Up to 10 files.</div>
                                    </div>
                                </div>
                            </div>

                            <div class="tarf-section">
                                <h2>Requested support</h2>
                                <p class="small text-muted mb-0">Check all that apply. For renting chairs, tables, etc., choose the fee option and give particulars under Other (e.g. With Fees – 50 chairs, 10 tables).</p>
                                <div class="row g-3 mt-1">
                                    <div class="col-12">
                                        <?php foreach ($ntarfOpts['requested_support'] as $val => $lab): ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="ntarf_support[]" value="<?php echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); ?>" id="ns_<?php echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); ?>">
                                                <label class="form-check-label" for="ns_<?php echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($lab, ENT_QUOTES, 'UTF-8'); ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                        <textarea name="ntarf_support_other" class="form-control mt-2" rows="2" placeholder="Other requested support (required only if no box above applies)"></textarea>
                                    </div>
                                </div>
                            </div>

                            <div class="tarf-section">
                                <h2>Support requested</h2>
                                <p class="small text-muted">Check all that apply.</p>
                                <div class="row g-3">
                                    <div class="col-12">
                                        <?php foreach ($ntarfOpts['publicity_support'] as $val => $lab): ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="publicity[]" value="<?php echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); ?>" id="ntpub_<?php echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); ?>">
                                                <label class="form-check-label" for="ntpub_<?php echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($lab, ENT_QUOTES, 'UTF-8'); ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                        <input type="text" name="publicity_other" class="form-control mt-2" placeholder="Other support requested (optional)">
                                    </div>
                                </div>
                            </div>

                            <div class="tarf-section">
                                <h2>Endorsement</h2>
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label">Applicable endorser <span class="text-danger">*</span></label>
                                        <select name="applicable_endorser" class="form-select" required>
                                            <option value="">— Select —</option>
                                            <?php foreach ($ntarfOpts['endorsers'] as $e): ?>
                                                <option value="<?php echo htmlspecialchars($e, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($e, ENT_QUOTES, 'UTF-8'); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Endorser for venue availability <span class="text-danger">*</span></label>
                                        <select name="endorser_venue_availability" id="nw_endorser_venue" class="form-select" required>
                                            <option value="">— Select —</option>
                                            <?php foreach ($ntarfOpts['endorser_venue_availability'] as $ev): ?>
                                                <option value="<?php echo htmlspecialchars($ev, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($ev, ENT_QUOTES, 'UTF-8'); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Email of immediate supervisor <em>or</em> college / office / campus / project email <span class="text-danger">*</span></label>
                                        <input type="email" name="supervisor_email" class="form-control" required placeholder="e.g. cpam@wpu.edu.ph">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Endorser for electricity and generator use <span class="text-danger">*</span></label>
                                        <select name="endorser_electricity" id="nw_endorser_electricity" class="form-select" required>
                                            <option value="">— Select —</option>
                                            <?php foreach ($ntarfOpts['endorser_electricity'] as $ee): ?>
                                                <option value="<?php echo htmlspecialchars($ee, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($ee, ENT_QUOTES, 'UTF-8'); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="tarf-section border rounded p-3 bg-light" id="ntarfFundingBlock">
                                <h2 class="border-0">Funding</h2>
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label">Is university funding being requested? <span class="text-danger">*</span></label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="university_funding_requested" value="yes" id="ntarf_uf_yes" required>
                                            <label class="form-check-label" for="ntarf_uf_yes">Yes</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="university_funding_requested" value="no" id="ntarf_uf_no">
                                            <label class="form-check-label" for="ntarf_uf_no">No</label>
                                        </div>
                                    </div>
                                    <div class="col-12 d-none" id="ntarfFundDetails">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label">Funding charged to <span class="ntarf-fund-req text-danger">*</span></label>
                                                <select name="funding_charged_to" id="ntarf_funding_charged_to" class="form-select">
                                                    <option value="">—</option>
                                                    <?php foreach ($ntarfOpts['funding_charged'] as $f): ?>
                                                        <option value="<?php echo htmlspecialchars($f, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($f, ENT_QUOTES, 'UTF-8'); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Funding specifier <span class="ntarf-fund-req text-danger">*</span></label>
                                                <select name="funding_specifier" id="ntarf_funding_specifier" class="form-select">
                                                    <option value="">—</option>
                                                    <?php foreach ($ntarfOpts['funding_specifiers'] as $f): ?>
                                                        <option value="<?php echo htmlspecialchars($f, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($f, ENT_QUOTES, 'UTF-8'); ?></option>
                                                    <?php endforeach; ?>
                                                    <option value="__other__">Other (specify)</option>
                                                </select>
                                                <input type="text" name="funding_specifier_other" id="ntarf_funding_specifier_other" class="form-control mt-2 d-none" placeholder="Project name or funding institution">
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label">Endorser for fund availability <span class="ntarf-fund-req text-danger">*</span></label>
                                                <select name="endorser_fund_availability" id="ntarf_endorser_fund_availability" class="form-select">
                                                    <option value="">— Select Budget or Accounting —</option>
                                                    <?php foreach ($ntarfOpts['fund_endorser_role'] as $val => $lab): ?>
                                                        <option value="<?php echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($lab, ENT_QUOTES, 'UTF-8'); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label">Line item budget (LIB) for budgetary requirements (optional)</label>
                                                <input type="file" name="lib_file[]" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Total estimated amount <span class="text-danger">*</span></label>
                                        <input type="text" name="total_estimated_amount" id="ntarf_total_estimated_amount" class="form-control" required placeholder="e.g. 15000 or 0 if none">
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex flex-wrap gap-2">
                                <button type="submit" class="btn btn-primary" id="ntarfSubmitBtn"><i class="fas fa-paper-plane me-1"></i>Submit NTARF</button>
                                <button type="reset" class="btn btn-outline-secondary">Reset</button>
                            </div>
                        </form>
                    </div>
                </div>
                </div>
                </div>

                <div id="tarfToast" class="tarf-toast" role="alert"></div>

                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', true); ?>"></script>
    <script>
    var TARF_VIEW_FRAGMENT = <?php echo json_encode(clean_url($basePath . '/faculty/tarf_request_view_fragment.php', $basePath), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var TARF_REQUEST_PDF = <?php echo json_encode(clean_url($basePath . '/faculty/tarf_request_pdf.php', $basePath), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    (function() {
        var modalPrintBtn = document.getElementById('tarfRequestViewModalPrint');
        if (modalPrintBtn) {
            modalPrintBtn.addEventListener('click', function() {
                window.print();
            });
        }
        function openTarfRequestPreview(id, opts) {
            opts = opts || {};
            var printAfter = !!opts.printAfter;
            var pdfBtn = document.getElementById('tarfRequestViewModalPdf');
            if (pdfBtn) {
                var st = opts.status || '';
                var showPdf = st === 'endorsed' && typeof TARF_REQUEST_PDF !== 'undefined';
                pdfBtn.classList.toggle('d-none', !showPdf);
                if (showPdf) {
                    var sep = TARF_REQUEST_PDF.indexOf('?') >= 0 ? '&' : '?';
                    pdfBtn.href = TARF_REQUEST_PDF + sep + 'id=' + encodeURIComponent(id);
                } else {
                    pdfBtn.setAttribute('href', '#');
                }
            }
            if (!id || typeof TARF_VIEW_FRAGMENT === 'undefined') {
                return;
            }
            var modalEl = document.getElementById('tarfRequestViewModal');
            var bodyEl = document.getElementById('tarfRequestViewModalBody');
            if (!modalEl || !bodyEl || typeof bootstrap === 'undefined') {
                return;
            }
            bodyEl.innerHTML = '<div class="text-center py-5 text-muted"><span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Loading…</div>';
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
            fetch(TARF_VIEW_FRAGMENT + (TARF_VIEW_FRAGMENT.indexOf('?') >= 0 ? '&' : '?') + 'id=' + encodeURIComponent(id), { credentials: 'same-origin' })
                .then(function(r) {
                    if (!r.ok) {
                        throw new Error('load failed');
                    }
                    return r.text();
                })
                .then(function(html) {
                    bodyEl.innerHTML = html;
                    if (printAfter) {
                        requestAnimationFrame(function() {
                            setTimeout(function() { window.print(); }, 150);
                        });
                    }
                })
                .catch(function() {
                    bodyEl.innerHTML = '<div class="alert alert-danger m-0">Could not load this request. Refresh the page and try again.</div>';
                });
        }
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.btn-tarf-open-view');
            if (!btn) {
                return;
            }
            e.preventDefault();
            var row = btn.closest('.tarf-mr-item');
            var st = row ? row.getAttribute('data-tarf-status') : '';
            openTarfRequestPreview(btn.getAttribute('data-tarf-id'), { printAfter: false, status: st || '' });
        });
        var college = document.getElementById('college_office');
        var collegeOther = document.getElementById('college_office_other');
        if (college && collegeOther) {
            function syncCollege() {
                var on = college.value === '__other__';
                collegeOther.classList.toggle('d-none', !on);
                collegeOther.required = on;
            }
            college.addEventListener('change', syncCollege);
            syncCollege();
        }
        function syncFundingRequirements() {
            var f = document.getElementById('tarfForm');
            if (!f) return;
            var trt = f.querySelector('input[name="travel_request_type"]:checked');
            var needs = trt && trt.value === 'official_business';
            document.querySelectorAll('.tarf-fund-req').forEach(function(el) {
                el.classList.toggle('d-none', !needs);
            });
            var fc = document.getElementById('funding_charged_to');
            var fsEl = document.getElementById('funding_specifier');
            var fsoEl = document.getElementById('funding_specifier_other');
            var efa = document.getElementById('endorser_fund_availability');
            var tea = document.getElementById('total_estimated_amount');
            if (fc) fc.required = needs;
            if (fsEl) fsEl.required = needs;
            if (efa) efa.required = needs;
            if (tea) tea.required = needs;
            if (fsoEl) fsoEl.required = needs && fsEl && fsEl.value === '__other__';
            var fundingBlock = document.getElementById('fundingBlock');
            if (fundingBlock) {
                fundingBlock.classList.toggle('d-none', !needs);
                fundingBlock.setAttribute('aria-hidden', needs ? 'false' : 'true');
            }
        }
        var fs = document.getElementById('funding_specifier');
        var fso = document.getElementById('funding_specifier_other');
        if (fs && fso) {
            function syncFs() {
                var on = fs.value === '__other__';
                fso.classList.toggle('d-none', !on);
                syncFundingRequirements();
            }
            fs.addEventListener('change', syncFs);
            syncFs();
        }
        var tarfFormFunding = document.getElementById('tarfForm');
        if (tarfFormFunding) {
            tarfFormFunding.querySelectorAll('input[name="travel_request_type"]').forEach(function(el) {
                el.addEventListener('change', syncFundingRequirements);
            });
        }
        syncFundingRequirements();
        var pickSearch = document.getElementById('tarfTravelPickSearch');
        var pickList = document.getElementById('tarfTravelPickList');
        if (pickSearch && pickList) {
            pickSearch.addEventListener('input', function() {
                var q = pickSearch.value.trim().toLowerCase();
                pickList.querySelectorAll('.tarf-travel-pick-row').forEach(function(row) {
                    var hay = row.getAttribute('data-search') || '';
                    row.style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
                });
            });
        }
        var form = document.getElementById('tarfForm');
        var toast = document.getElementById('tarfToast');
        function showToast(msg, ok) {
            if (!toast) return;
            toast.className = 'tarf-toast show ' + (ok ? 'success' : 'error');
            toast.textContent = msg;
            setTimeout(function() { toast.classList.remove('show'); }, 5000);
        }
        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                var btn = document.getElementById('tarfSubmitBtn');
                if (btn) { btn.disabled = true; }
                var fd = new FormData(form);
                fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success && data.id) {
                            showToast(data.message || 'Submitted.', true);
                            if (btn) { btn.disabled = false; }
                            openTarfRequestPreview(String(data.id), { printAfter: true });
                        } else {
                            showToast(data.message || 'Submission failed.', false);
                            if (btn) { btn.disabled = false; }
                        }
                    })
                    .catch(function() {
                        showToast('Network error. Try again.', false);
                        if (btn) { btn.disabled = false; }
                    });
            });
        }
        var nc = document.getElementById('ntarf_college_office');
        var nco = document.getElementById('ntarf_college_office_other');
        if (nc && nco) {
            function syncNtarfCollege() {
                var on = nc.value === '__other__';
                nco.classList.toggle('d-none', !on);
                nco.required = on;
            }
            nc.addEventListener('change', syncNtarfCollege);
            syncNtarfCollege();
        }
        var nwCamp = document.getElementById('nw_activity_campus');
        var nwCampO = document.getElementById('nw_activity_campus_other');
        if (nwCamp && nwCampO) {
            function syncNwCamp() {
                var out = nwCamp.value === 'OUTSIDE THE CAMPUS';
                nwCampO.classList.toggle('d-none', !out);
                nwCampO.required = out;
            }
            nwCamp.addEventListener('change', syncNwCamp);
            syncNwCamp();
        }
        var nwVs = document.getElementById('nw_venue_site');
        var nwVsO = document.getElementById('nw_venue_site_other');
        if (nwVs && nwVsO) {
            function syncNwVenue() {
                var o = nwVs.value === '__other__';
                nwVsO.classList.toggle('d-none', !o);
                nwVsO.required = o;
            }
            nwVs.addEventListener('change', syncNwVenue);
            syncNwVenue();
        }
        function syncNtarfUniversityFunding() {
            var yes = document.querySelector('#ntarfForm input[name="university_funding_requested"]:checked');
            var isYes = yes && yes.value === 'yes';
            var fd = document.getElementById('ntarfFundDetails');
            if (fd) {
                fd.classList.toggle('d-none', !isYes);
            }
            document.querySelectorAll('.ntarf-fund-req').forEach(function(el) {
                el.classList.toggle('d-none', !isYes);
            });
            var fc = document.getElementById('ntarf_funding_charged_to');
            var fsEl = document.getElementById('ntarf_funding_specifier');
            var fsoEl = document.getElementById('ntarf_funding_specifier_other');
            var efa = document.getElementById('ntarf_endorser_fund_availability');
            if (fc) fc.required = isYes;
            if (fsEl) fsEl.required = isYes;
            if (efa) efa.required = isYes;
            if (fsoEl) fsoEl.required = isYes && fsEl && fsEl.value === '__other__';
        }
        document.querySelectorAll('#ntarfForm input[name="university_funding_requested"]').forEach(function(r) {
            r.addEventListener('change', syncNtarfUniversityFunding);
        });
        var nfs = document.getElementById('ntarf_funding_specifier');
        var nfso = document.getElementById('ntarf_funding_specifier_other');
        if (nfs && nfso) {
            function syncNfs() {
                var on = nfs.value === '__other__';
                nfso.classList.toggle('d-none', !on);
                syncNtarfUniversityFunding();
            }
            nfs.addEventListener('change', syncNfs);
            syncNfs();
        }
        syncNtarfUniversityFunding();
        var npickSearch = document.getElementById('ntarfTravelPickSearch');
        var npickList = document.getElementById('ntarfTravelPickList');
        if (npickSearch && npickList) {
            npickSearch.addEventListener('input', function() {
                var q = npickSearch.value.trim().toLowerCase();
                npickList.querySelectorAll('.tarf-travel-pick-row').forEach(function(row) {
                    var hay = row.getAttribute('data-search') || '';
                    row.style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
                });
            });
        }
        var ntarfForm = document.getElementById('ntarfForm');
        if (ntarfForm) {
            ntarfForm.addEventListener('submit', function(e) {
                e.preventDefault();
                var btn = document.getElementById('ntarfSubmitBtn');
                if (ntarfForm.querySelectorAll('input[name="publicity[]"]:checked').length === 0) {
                    showToast('Select at least one support requested option (or N/A).', false);
                    return;
                }
                if (ntarfForm.querySelectorAll('input[name="ntarf_support[]"]:checked').length === 0) {
                    var so = ntarfForm.querySelector('[name="ntarf_support_other"]');
                    if (!so || !(so.value || '').trim()) {
                        showToast('Select at least one requested support option or describe other support.', false);
                        return;
                    }
                }
                if (ntarfForm.querySelectorAll('input[name="involvement_types[]"]:checked').length === 0) {
                    var io = ntarfForm.querySelector('[name="involvement_other"]');
                    if (!io || !(io.value || '').trim()) {
                        showToast('Select at least one type of involvement or describe other.', false);
                        return;
                    }
                }
                var uf = ntarfForm.querySelector('input[name="university_funding_requested"]:checked');
                if (!uf) {
                    showToast('Indicate whether university funding is being requested.', false);
                    return;
                }
                if (btn) { btn.disabled = true; }
                var fd = new FormData(ntarfForm);
                fetch(ntarfForm.action, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success && data.id) {
                            showToast(data.message || 'Submitted.', true);
                            if (btn) { btn.disabled = false; }
                            openTarfRequestPreview(String(data.id), { printAfter: true });
                        } else {
                            showToast(data.message || 'Submission failed.', false);
                            if (btn) { btn.disabled = false; }
                        }
                    })
                    .catch(function() {
                        showToast('Network error. Try again.', false);
                        if (btn) { btn.disabled = false; }
                    });
            });
        }
    })();
    </script>
</body>
</html>
