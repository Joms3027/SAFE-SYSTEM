<?php
/**
 * DISAPP-style printable view for a submitted TARF (2.2D layout).
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/tarf_workflow.php';
require_once __DIR__ . '/../includes/tarf_disapp_view_render.php';
require_once __DIR__ . '/../includes/tarf_disapp_view_styles.php';

requireFaculty();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    $_SESSION['error'] = 'Invalid request.';
    redirect(clean_url(getBasePath() . '/faculty/tarf_request.php', getBasePath()));
}

$database = Database::getInstance();
$db = $database->getConnection();

$stmt = $db->prepare('SELECT * FROM tarf_requests WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$viewerId = (int) $_SESSION['user_id'];
if (!$row || !tarf_user_can_view_request($row, $viewerId, $db)) {
    $_SESSION['error'] = 'TARF request not found or access denied.';
    redirect(clean_url(getBasePath() . '/faculty/tarf_request.php', getBasePath()));
}

$basePath = getBasePath();
$isOwner = (int) ($row['user_id'] ?? 0) === $viewerId;
$disappCardHtml = tarf_render_disapp_card_html($db, $row, $viewerId);

$formPreview = json_decode($row['form_data'] ?? '{}', true);
$isNtarf = is_array($formPreview) && (($formPreview['form_kind'] ?? '') === 'ntarf');
$viewDocTitle = $isNtarf
    ? ('NTARF #' . (int) $row['id'] . ' — [NON-TRAVEL] Activity Request Form')
    : ('TARF #' . (int) $row['id'] . ' — [TRAVEL] Activity Request Form');

require_once __DIR__ . '/../includes/navigation.php';
include_navigation();

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

$status = $row['status'] ?? 'pending_supervisor';
if ($status === 'pending') {
    $status = 'pending_supervisor';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($viewDocTitle); ?></title>
    <link rel="icon" type="image/png" href="<?php echo asset_url('logo.png', true); ?>">
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/faculty-portal.css', true); ?>" rel="stylesheet">
    <?php tarf_emit_disapp_view_styles(); ?>
</head>
<body class="layout-faculty tarf-request-view">
    <div class="container-fluid">
        <div class="row">
            <main class="main-content py-3">
        <div class="no-print mb-3 d-flex flex-wrap gap-2 align-items-center">
            <?php if ($isOwner): ?>
            <a href="<?php echo h(clean_url($basePath . '/faculty/tarf_request.php', $basePath)); ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Back to form</a>
            <?php endif; ?>
            <?php if (function_exists('hasPardonOpenerAssignments') && hasPardonOpenerAssignments($viewerId, $db)): ?>
            <a href="<?php echo h(clean_url($basePath . '/faculty/tarf_supervisor_queue.php', $basePath)); ?>" class="btn btn-outline-secondary btn-sm">Supervisor queue</a>
            <?php endif; ?>
            <?php if (tarf_is_endorser_target_user($viewerId, $db)): ?>
            <a href="<?php echo h(clean_url($basePath . '/faculty/tarf_endorser_queue.php', $basePath)); ?>" class="btn btn-outline-secondary btn-sm">Applicable endorser queue</a>
            <?php endif; ?>
            <?php if (function_exists('tarf_user_holds_fund_availability_designation') && tarf_user_holds_fund_availability_designation($viewerId, $db)): ?>
            <a href="<?php echo h(clean_url($basePath . '/faculty/tarf_fund_availability_queue.php', $basePath)); ?>" class="btn btn-outline-secondary btn-sm">Budget / Accounting queue</a>
            <?php endif; ?>
            <?php if (function_exists('tarf_is_president_key_official_viewer') && tarf_is_president_key_official_viewer($viewerId)): ?>
            <a href="<?php echo h(clean_url($basePath . '/faculty/tarf_president_queue.php', $basePath)); ?>" class="btn btn-outline-secondary btn-sm">President (final)</a>
            <?php endif; ?>
            <?php if ($status === 'endorsed'): ?>
            <a class="btn btn-success btn-sm" href="<?php echo h(clean_url($basePath . '/faculty/tarf_request_pdf.php?id=' . (int) $row['id'], $basePath)); ?>" target="_blank" rel="noopener">
                <i class="fas fa-file-pdf me-1"></i>Download PDF
            </a>
            <?php endif; ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
        </div>
        <?php if ($isOwner && in_array($status, ['pending_joint', 'pending_supervisor', 'pending_endorser'], true)): ?>
            <div class="no-print alert alert-info py-2 mb-3">Before the President gives final approval: your <strong>supervisor</strong>, <strong>applicable endorser</strong>, and—when your form requires fund certification—<strong>Budget or Accounting</strong> must each endorse (any order). Requests without fund certification require supervisor and applicable endorser only.</div>
        <?php elseif ($isOwner && $status === 'pending_president'): ?>
            <div class="no-print alert alert-info py-2 mb-3"><?php
                $need3 = function_exists('tarf_request_requires_fund_availability_endorsement')
                    && tarf_request_requires_fund_availability_endorsement($row);
                echo $need3
                    ? 'Supervisor, applicable endorser, and Budget <strong>or</strong> Accounting have endorsed. It is now with the <strong>President</strong> for final approval.'
                    : 'Supervisor and applicable endorser have endorsed. It is now with the <strong>President</strong> for final approval.';
            ?></div>
        <?php endif; ?>

        <?php echo $disappCardHtml; ?>
            </main>
        </div>
    </div>
    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', true); ?>"></script>
</body>
</html>
