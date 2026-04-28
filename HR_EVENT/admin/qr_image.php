<?php
/**
 * Output event scanner QR code as PNG.
 * Scanning this QR on a device opens the event scanner (to scan employee SAFE QRs).
 * GET event_id= required. Admin only.
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/database.php';

requireAdmin();

$eventId = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
if (!$eventId) {
    http_response_code(400);
    exit('Missing event_id');
}

$database = Database::getInstance();
$db = $database->getConnection();
$stmt = $db->prepare("SELECT id, title, qr_token FROM hr_events WHERE id = ? AND is_active = 1");
$stmt->execute([$eventId]);
$event = $stmt->fetch();
if (!$event) {
    http_response_code(404);
    exit('Event not found');
}

$basePath = getBasePath();
if (strpos($_SERVER['SCRIPT_NAME'] ?? '', 'HR_EVENT') !== false && strpos($basePath, '/HR_EVENT') === false) {
    $basePath = rtrim($basePath, '/') . '/HR_EVENT';
}
$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? '';
$scannerUrl = $proto . '://' . $host . rtrim($basePath, '/') . '/scanner.php?e=' . $eventId . '&t=' . urlencode($event['qr_token']);

if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
}
if (!class_exists('Endroid\QrCode\Builder\Builder')) {
    header('Content-Type: text/plain');
    exit('QR library not available.');
}

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;

$builder = new Builder(
    writer: new PngWriter(),
    data: $scannerUrl,
    encoding: new Encoding('UTF-8'),
    errorCorrectionLevel: ErrorCorrectionLevel::High,
    size: 300,
    margin: 10
);
$result = $builder->build();

header('Content-Type: ' . $result->getMimeType());
header('Content-Disposition: inline; filename="event-' . $eventId . '-scanner-qr.png"');
echo $result->getString();
