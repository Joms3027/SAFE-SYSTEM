<?php
/**
 * Download approved TARF / NTARF as PDF (same DISAPP HTML/CSS as on-screen, not Word→PDF conversion).
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/tarf_workflow.php';
require_once __DIR__ . '/../includes/tarf_disapp_view_render.php';
require_once __DIR__ . '/../includes/tarf_disapp_view_styles.php';
require_once __DIR__ . '/../includes/tarf_request_pdf_helpers.php';

requireAuth();
$pdfErrorRedirect = isAdmin()
    ? clean_url(getBasePath() . '/admin/tarf_requests.php', getBasePath())
    : clean_url(getBasePath() . '/faculty/tarf_request.php', getBasePath());
if (!isAdmin() && !isFaculty() && !isStaff()) {
    $_SESSION['error'] = 'Access denied.';
    redirect($pdfErrorRedirect);
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    $_SESSION['error'] = 'Invalid request.';
    redirect($pdfErrorRedirect);
}

$database = Database::getInstance();
$db = $database->getConnection();

$stmt = $db->prepare('SELECT * FROM tarf_requests WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$viewerId = (int) $_SESSION['user_id'];
if (!$row) {
    $_SESSION['error'] = 'TARF request not found.';
    redirect($pdfErrorRedirect);
}
if (!isAdmin() && !tarf_user_can_view_request($row, $viewerId, $db)) {
    $_SESSION['error'] = 'TARF request not found or access denied.';
    redirect($pdfErrorRedirect);
}

$status = $row['status'] ?? '';
if ($status !== 'endorsed') {
    $_SESSION['error'] = 'PDF download is available after the request is fully approved.';
    redirect($pdfErrorRedirect);
}

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    $_SESSION['error'] = 'Server configuration error.';
    redirect($pdfErrorRedirect);
}

ob_start();
tarf_emit_disapp_view_styles();
$styleHtml = (string) ob_get_clean();

$GLOBALS['tarf_disapp_pdf_export'] = true;
$cardHtml = tarf_render_disapp_card_html($db, $row, $viewerId);
unset($GLOBALS['tarf_disapp_pdf_export']);

$formPreview = json_decode($row['form_data'] ?? '{}', true);
$isNtarf = is_array($formPreview) && (($formPreview['form_kind'] ?? '') === 'ntarf');
$suggestedName = ($isNtarf ? 'NTARF' : 'TARF')
    . '-' . (int) $row['id']
    . '-s' . (int) ($row['serial_year'] ?? 0)
    . '.pdf';

$pdfExtraCss = <<<'CSS'
<style>
/* PDF export: match print view; omit portal-only download links */
.disapp-official-form-ref,
.disapp-filled-official-form-ref,
.tarf-portal-meta { display: none !important; }
</style>
CSS;

$html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
    . $styleHtml
    . $pdfExtraCss
    . '</head><body class="layout-faculty tarf-request-view">'
    . $cardHtml
    . '</body></html>';

$html = tarf_disapp_pdf_prepare_document_html($html, $root);
$needsRemote = (bool) preg_match('#<img\b[^>]*\bsrc\s*=\s*(["\'])https?://#i', $html);

$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    error_log('tarf_request_pdf: missing vendor/autoload.php');
    $_SESSION['error'] = 'PDF export is not available.';
    redirect($pdfErrorRedirect);
}

require_once $autoload;

$options = new \Dompdf\Options();
$options->set('isRemoteEnabled', $needsRemote);
$options->set('defaultMediaType', 'print');
$options->set('chroot', $root);

$dompdf = new \Dompdf\Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream($suggestedName, ['Attachment' => true]);
exit;
