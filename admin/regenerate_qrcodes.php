<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/qr_code_helper.php';

requireAdmin();

$database = Database::getInstance();
$db = $database->getConnection();

$action = $_POST['action'] ?? 'view';
$forceRegenerate = isset($_POST['force']) && $_POST['force'] === '1';
$results = [];

if ($action === 'regenerate') {
    // Check if GD is enabled for text overlay
    $gdEnabled = extension_loaded('gd');
    if (!$gdEnabled) {
        $results = [['status' => 'error', 'message' => 'GD extension is not enabled. QR codes will be generated without name overlay. Please enable GD extension in php.ini.']];
    }
    try {
        // Ensure qr_codes directory exists
        $qrDir = UPLOAD_PATH . 'qr_codes/';
        if (!is_dir($qrDir)) {
            mkdir($qrDir, 0755, true);
        }
        
        // Get all faculty with employee IDs
        $stmt = $db->query("
            SELECT 
                fp.user_id,
                u.first_name,
                u.last_name,
                fp.employee_id,
                fp.qr_code
            FROM faculty_profiles fp
            JOIN users u ON fp.user_id = u.id
            WHERE fp.employee_id IS NOT NULL AND fp.employee_id != ''
            ORDER BY u.last_name, u.first_name
        ");
        
        $total = 0;
        $generated = 0;
        $skipped = 0;
        $failed = 0;
        
        while ($row = $stmt->fetch()) {
            $total++;
            $userId = $row['user_id'];
            $employeeId = $row['employee_id'];
            $firstName = $row['first_name'];
            $lastName = $row['last_name'];
            $name = $firstName . ' ' . $lastName;
            $existingPath = $row['qr_code'];
            
            // Check if QR code file exists on disk
            $needsGeneration = false;
            $reason = '';
            
            if ($forceRegenerate) {
                $needsGeneration = true;
                $reason = 'Force regenerate requested';
                
                // Delete existing QR code file if it exists
                if (!empty($existingPath)) {
                    $fullPath = UPLOAD_PATH . $existingPath;
                    if (file_exists($fullPath)) {
                        unlink($fullPath);
                    }
                }
            } elseif (empty($existingPath)) {
                $needsGeneration = true;
                $reason = 'No QR code in database';
            } else {
                $fullPath = UPLOAD_PATH . $existingPath;
                if (!file_exists($fullPath)) {
                    $needsGeneration = true;
                    $reason = 'File missing from disk';
                }
            }
            
            if ($needsGeneration) {
                $qrCodePath = generateQRCode($userId, $employeeId);
                
                if ($qrCodePath) {
                    // Update database with new path
                    $updateStmt = $db->prepare("UPDATE faculty_profiles SET qr_code = ? WHERE user_id = ?");
                    $updateStmt->execute([$qrCodePath, $userId]);
                    
                    // Verify file was created
                    $fullPath = UPLOAD_PATH . $qrCodePath;
                    if (file_exists($fullPath)) {
                        $extension = pathinfo($qrCodePath, PATHINFO_EXTENSION);
                        $nameInfo = $gdEnabled ? " (with name: $lastName, $firstName)" : " (no name overlay)";
                        $results[] = ['status' => 'success', 'name' => $name, 'employee_id' => $employeeId, 'path' => $qrCodePath, 'extension' => $extension, 'info' => $nameInfo];
                        $generated++;
                    } else {
                        $results[] = ['status' => 'error', 'name' => $name, 'employee_id' => $employeeId, 'message' => 'File not found after generation'];
                        $failed++;
                    }
                } else {
                    $results[] = ['status' => 'error', 'name' => $name, 'employee_id' => $employeeId, 'message' => 'Generation failed'];
                    $failed++;
                }
            } else {
                $results[] = ['status' => 'skipped', 'name' => $name, 'employee_id' => $employeeId, 'path' => $existingPath];
                $skipped++;
            }
        }
        
        $summary = [
            'total' => $total,
            'generated' => $generated,
            'skipped' => $skipped,
            'failed' => $failed
        ];
        
    } catch (Exception $e) {
        $results = [['status' => 'error', 'message' => $e->getMessage()]];
        $summary = ['error' => true];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Regenerate QR Codes - Admin</title>
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css'); ?>" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="row">
            <div class="col-md-10 offset-md-1">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0"><i class="fas fa-qrcode me-2"></i>Regenerate QR Codes</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($action === 'view'): ?>
                            <p>This utility will check all faculty members and regenerate missing QR codes.</p>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Note:</strong> Only faculty members with Safe Employee IDs can have QR codes. 
                                QR codes will display "LASTNAME, FIRSTNAME" in the middle of the QR image.
                            </div>
                            
                            <?php if (extension_loaded('gd')): ?>
                                <div class="alert alert-success">
                                    <i class="fas fa-check-circle me-2"></i>
                                    <strong>GD Extension:</strong> Enabled - QR codes will include name overlays
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    <strong>GD Extension:</strong> Not enabled - QR codes will be generated without name overlay. 
                                    To enable, uncomment <code>;extension=gd</code> in php.ini and restart the web server.
                                </div>
                            <?php endif; ?>
                            
                            <form method="POST">
                                <input type="hidden" name="action" value="regenerate">
                                <div class="mb-3">
                                    <button type="submit" name="force" value="0" class="btn btn-primary">
                                        <i class="fas fa-sync-alt me-2"></i>Regenerate Missing QR Codes Only
                                    </button>
                                    <button type="submit" name="force" value="1" class="btn btn-warning">
                                        <i class="fas fa-redo me-2"></i>Force Regenerate ALL QR Codes
                                    </button>
                                </div>
                                <small class="text-muted">
                                    <strong>Missing Only:</strong> Generates QR codes only for faculty without QR codes or with missing files.<br>
                                    <strong>Force All:</strong> Regenerates all QR codes (useful after enabling GD extension or updating names).
                                </small>
                            </form>
                            <div class="mt-3">
                                <a href="faculty.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left me-2"></i>Back to Faculty Management
                                </a>
                            </div>
                        <?php else: ?>
                            <h5>Results</h5>
                            
                            <?php if (isset($summary) && !isset($summary['error'])): ?>
                                <div class="alert alert-success">
                                    <h6>Summary</h6>
                                    <ul class="mb-0">
                                        <li>Total Faculty: <?php echo $summary['total']; ?></li>
                                        <li>Generated: <?php echo $summary['generated']; ?></li>
                                        <li>Skipped (already exists): <?php echo $summary['skipped']; ?></li>
                                        <li>Failed: <?php echo $summary['failed']; ?></li>
                                    </ul>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($results)): ?>
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Status</th>
                                                <th>Name</th>
                                                <th>Safe Employee ID</th>
                                                <th>Details</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($results as $result): ?>
                                                <tr>
                                                    <td>
                                                        <?php if ($result['status'] === 'success'): ?>
                                                            <span class="badge bg-success"><i class="fas fa-check"></i> Generated</span>
                                                        <?php elseif ($result['status'] === 'skipped'): ?>
                                                            <span class="badge bg-secondary"><i class="fas fa-check-circle"></i> Exists</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-danger"><i class="fas fa-times"></i> Failed</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($result['name'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars($result['employee_id'] ?? 'N/A'); ?></td>
                                                    <td>
                                                        <?php 
                                                        if (isset($result['path'])) {
                                                            echo htmlspecialchars($result['path']);
                                                            if (isset($result['extension'])) {
                                                                echo ' <span class="badge bg-info">' . strtoupper($result['extension']) . '</span>';
                                                            }
                                                            if (isset($result['info'])) {
                                                                echo '<br><small class="text-muted">' . htmlspecialchars($result['info']) . '</small>';
                                                            }
                                                        } elseif (isset($result['message'])) {
                                                            echo '<span class="text-danger">' . htmlspecialchars($result['message']) . '</span>';
                                                        }
                                                        ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                            
                            <div class="mt-3">
                                <a href="regenerate_qrcodes.php" class="btn btn-primary">
                                    <i class="fas fa-sync-alt me-2"></i>Run Again
                                </a>
                                <a href="faculty.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left me-2"></i>Back to Faculty Management
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js'); ?>"></script>
</body>
</html>
