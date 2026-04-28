<?php
/**
 * Admin: printable DISAPP-style view for a single TARF (read-only).
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/tarf_workflow.php';
require_once __DIR__ . '/../includes/tarf_disapp_view_render.php';
require_once __DIR__ . '/../includes/tarf_disapp_view_styles.php';

requireAdmin();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    $_SESSION['error'] = 'Invalid request.';
    redirect(clean_url(getBasePath() . '/admin/tarf_requests.php', getBasePath()));
}

$database = Database::getInstance();
$db = $database->getConnection();

$stmt = $db->prepare('SELECT * FROM tarf_requests WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    $_SESSION['error'] = 'TARF request not found.';
    redirect(clean_url(getBasePath() . '/admin/tarf_requests.php', getBasePath()));
}

$viewerId = (int) $_SESSION['user_id'];
$statusRow = $row['status'] ?? 'pending_supervisor';
if ($statusRow === 'pending') {
    $statusRow = 'pending_supervisor';
}
$disappCardHtml = tarf_render_disapp_card_html($db, $row, $viewerId);

$basePath = getBasePath();
$adminPath = $basePath ? rtrim($basePath, '/') . '/admin' : '/admin';
$listUrl = clean_url($adminPath . '/tarf_requests.php', $basePath);

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

require_once __DIR__ . '/../includes/admin_layout_helper.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php admin_page_head('TARF #' . (int) $row['id'], 'Travel Activity Request Form'); ?>
    <?php tarf_emit_disapp_view_styles(); ?>
</head>
<body class="layout-admin tarf-request-view">
    <?php
    require_once __DIR__ . '/../includes/navigation.php';
    include_navigation();
    ?>

    <div class="container-fluid">
        <div class="row">
            <main class="main-content py-3">
                <div class="no-print mb-3 d-flex flex-wrap gap-2 align-items-center">
                    <a href="<?php echo h($listUrl); ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Back to list</a>
                    <?php if ($statusRow === 'endorsed'): ?>
                    <a class="btn btn-success btn-sm" href="<?php echo h(clean_url($basePath . '/faculty/tarf_request_pdf.php?id=' . (int) $row['id'], $basePath)); ?>" target="_blank" rel="noopener">
                        <i class="fas fa-file-pdf me-1"></i>Download PDF
                    </a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-primary btn-sm" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
                </div>

                <?php displayMessage(); ?>

                <?php echo $disappCardHtml; ?>
            </main>
        </div>
    </div>

    <?php admin_page_scripts(); ?>
</body>
</html>
