<?php
/**
 * Regenerate Missing QR Codes
 * 
 * This script regenerates QR codes for all users whose QR code files are missing
 * from the uploads directory. Useful when moving the system to a new computer.
 */

require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/qr_code_helper.php';

requireAdmin();

$database = Database::getInstance();
$db = $database->getConnection();

$regenerated = 0;
$errors = [];
$skipped = 0;

// Get all users with QR codes in database
$stmt = $db->prepare("
    SELECT fp.user_id, fp.qr_code, fp.employee_id, u.first_name, u.last_name 
    FROM faculty_profiles fp 
    INNER JOIN users u ON fp.user_id = u.id 
    WHERE fp.qr_code IS NOT NULL AND fp.qr_code != ''
    ORDER BY u.last_name, u.first_name
");
$stmt->execute();
$users = $stmt->fetchAll();

foreach ($users as $user) {
    $qrCodePath = $user['qr_code'];
    $fullPath = UPLOAD_PATH . $qrCodePath;
    
    // Check if file exists
    if (!file_exists($fullPath)) {
        // File is missing, regenerate QR code
        $employeeId = $user['employee_id'] ?? null;
        if ($employeeId) {
            $regeneratedPath = generateQRCode($user['user_id'], $employeeId);
        } else {
            $regeneratedPath = false;
        }
        
        if ($regeneratedPath) {
            // Update database with new path
            $updateStmt = $db->prepare("UPDATE faculty_profiles SET qr_code = ? WHERE user_id = ?");
            if ($updateStmt->execute([$regeneratedPath, $user['user_id']])) {
                $regenerated++;
                logAction('QR_CODE_REGENERATED', "Regenerated missing QR code for user ID: {$user['user_id']}");
            } else {
                $errors[] = "Failed to update QR code for {$user['first_name']} {$user['last_name']} (ID: {$user['user_id']})";
            }
        } else {
            $errors[] = "Failed to generate QR code for {$user['first_name']} {$user['last_name']} (ID: {$user['user_id']})";
        }
    } else {
        $skipped++;
    }
}

// Also check for users without QR codes in database
$stmt = $db->prepare("
    SELECT u.id, u.first_name, u.last_name, fp.employee_id 
    FROM users u 
    LEFT JOIN faculty_profiles fp ON u.id = fp.user_id 
    WHERE (fp.qr_code IS NULL OR fp.qr_code = '') 
    AND u.user_type IN ('faculty', 'staff')
    ORDER BY u.last_name, u.first_name
");
$stmt->execute();
$usersWithoutQR = $stmt->fetchAll();

foreach ($usersWithoutQR as $user) {
    $employeeId = $user['employee_id'] ?? null;
    if ($employeeId) {
        $qrCodePath = generateQRCode($user['id'], $employeeId);
    } else {
        $qrCodePath = false;
    }
    
    if ($qrCodePath) {
        // Check if faculty profile exists
        $checkStmt = $db->prepare("SELECT id FROM faculty_profiles WHERE user_id = ?");
        $checkStmt->execute([$user['id']]);
        $profileExists = $checkStmt->fetch();
        
        if ($profileExists) {
            // Update existing profile
            $updateStmt = $db->prepare("UPDATE faculty_profiles SET qr_code = ? WHERE user_id = ?");
            $updateStmt->execute([$qrCodePath, $user['id']]);
        } else {
            // Create new profile with QR code
            $insertStmt = $db->prepare("INSERT INTO faculty_profiles (user_id, qr_code) VALUES (?, ?)");
            $insertStmt->execute([$user['id'], $qrCodePath]);
        }
        
        $regenerated++;
        logAction('QR_CODE_CREATED', "Created QR code for user ID: {$user['id']}");
    } else {
        $errors[] = "Failed to generate QR code for {$user['first_name']} {$user['last_name']} (ID: {$user['id']})";
    }
}

$message = '';
$messageType = 'success';

if ($regenerated > 0) {
    $message = "Successfully regenerated {$regenerated} QR code(s).";
    if ($skipped > 0) {
        $message .= " {$skipped} QR code(s) already existed and were skipped.";
    }
} else {
    if ($skipped > 0) {
        $message = "All QR codes already exist. No regeneration needed.";
    } else {
        $message = "No QR codes found to regenerate.";
    }
}

if (!empty($errors)) {
    $message .= " " . count($errors) . " error(s) occurred.";
    $messageType = 'warning';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php
    require_once '../includes/admin_layout_helper.php';
    admin_page_head('Regenerate Missing QR Codes', 'Regenerate QR codes for users with missing files');
    ?>
</head>
<body class="layout-admin">
    <?php require_once '../includes/navigation.php';
    include_navigation(); ?>
    
    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <?php
                admin_page_header(
                    'Regenerate Missing QR Codes',
                    'Regenerate QR codes for users whose QR code files are missing from the uploads directory.',
                    'fas fa-qrcode',
                    [
                        ['label' => 'Dashboard', 'url' => 'dashboard.php', 'icon' => 'fas fa-home'],
                        ['label' => 'QR Codes', 'url' => '', 'icon' => '']
                    ],
                    '<a href="faculty.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Back to Faculty</a>'
                );
                ?>

                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="card mb-4">
                        <div class="card-header bg-danger text-white">
                            <h5 class="mb-0"><i class="fas fa-exclamation-circle me-2"></i>Errors</h5>
                        </div>
                        <div class="card-body">
                            <ul class="mb-0">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>About This Tool</h5>
                    </div>
                    <div class="card-body">
                        <p>This tool scans all faculty and staff accounts and regenerates QR codes for:</p>
                        <ul>
                            <li>Users whose QR code files are missing from the <code>uploads/qr_codes/</code> directory</li>
                            <li>Users who don't have QR codes in the database</li>
                        </ul>
                        <p class="mb-0"><strong>Note:</strong> This is useful when moving the system to a new computer where the upload files may not have been copied.</p>
                    </div>
                </div>

                <div class="mt-3">
                    <a href="faculty.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Faculty List
                    </a>
                    <a href="regenerate_missing_qr_codes.php" class="btn btn-primary">
                        <i class="fas fa-sync me-2"></i>Run Again
                    </a>
                </div>
            </main>
        </div>
    </div>

    <?php admin_page_scripts(); ?>
</body>
</html>

