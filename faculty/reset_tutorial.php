<?php
/**
 * Helper script to reset tutorial status for testing
 * WARNING: This should be removed or secured in production
 */

require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireFaculty();

$database = Database::getInstance();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];

// Check if column exists
$columnExists = false;
try {
    $stmt = $db->query("SHOW COLUMNS FROM faculty_profiles LIKE 'tutorial_completed'");
    $columnExists = $stmt->rowCount() > 0;
} catch (Exception $e) {
    $columnExists = false;
}

$message = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'reset') {
        if ($columnExists) {
            try {
                $stmt = $db->prepare("UPDATE faculty_profiles SET tutorial_completed = 0 WHERE user_id = ?");
                $stmt->execute([$user_id]);
                $message = 'Tutorial status reset to 0 (not completed). The tutorial will show on your next dashboard visit.';
                $success = true;
            } catch (Exception $e) {
                $message = 'Error resetting tutorial: ' . $e->getMessage();
            }
        } else {
            $message = 'Tutorial column does not exist. Please run the migration first.';
        }
    } elseif ($_POST['action'] === 'complete') {
        if ($columnExists) {
            try {
                $stmt = $db->prepare("UPDATE faculty_profiles SET tutorial_completed = 1 WHERE user_id = ?");
                $stmt->execute([$user_id]);
                $message = 'Tutorial status set to 1 (completed). The tutorial will NOT show.';
                $success = true;
            } catch (Exception $e) {
                $message = 'Error setting tutorial: ' . $e->getMessage();
            }
        } else {
            $message = 'Tutorial column does not exist. Please run the migration first.';
        }
    }
}

// Get current status
$currentStatus = null;
if ($columnExists) {
    $stmt = $db->prepare("SELECT tutorial_completed FROM faculty_profiles WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $currentStatus = (int)$result['tutorial_completed'];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tutorial Status - Reset</title>
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css', true); ?>" rel="stylesheet">
</head>
<body class="container mt-5">
    <div class="card">
        <div class="card-header">
            <h4>Tutorial Status Management</h4>
        </div>
        <div class="card-body">
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $success ? 'success' : 'danger'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <div class="mb-3">
                <strong>Column Exists:</strong> <?php echo $columnExists ? 'Yes' : 'No'; ?><br>
                <strong>Current Status:</strong> 
                <?php 
                if ($currentStatus !== null) {
                    echo $currentStatus === 0 ? '<span class="badge bg-warning">0 (Not Completed - Tutorial WILL show)</span>' : '<span class="badge bg-success">1 (Completed - Tutorial will NOT show)</span>';
                } else {
                    echo '<span class="badge bg-secondary">Not Set</span>';
                }
                ?>
            </div>
            
            <?php if ($columnExists): ?>
                <form method="POST" class="mb-3">
                    <input type="hidden" name="action" value="reset">
                    <button type="submit" class="btn btn-warning">Reset Tutorial (Set to 0 - Will Show)</button>
                </form>
                
                <form method="POST">
                    <input type="hidden" name="action" value="complete">
                    <button type="submit" class="btn btn-success">Mark as Completed (Set to 1 - Will NOT Show)</button>
                </form>
            <?php else: ?>
                <div class="alert alert-warning">
                    The tutorial_completed column does not exist. Please run the migration:
                    <code>FP/db/migrations/add_tutorial_completed_to_faculty_profiles.sql</code>
                </div>
            <?php endif; ?>
            
            <hr>
            <a href="dashboard.php" class="btn btn-primary">Go to Dashboard</a>
            <a href="check_tutorial_status.php" class="btn btn-secondary">Check Status (JSON)</a>
        </div>
    </div>
</body>
</html>

