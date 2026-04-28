<?php
/**
 * Migration Script: Mark Old Users as Having Completed Tutorial
 * 
 * This script marks all existing users (created before the cutoff date) as having 
 * completed the tutorial, so they won't see it. Only new users will see the tutorial.
 * 
 * Run this script once after deploying the tutorial feature to ensure old users 
 * don't see the tutorial.
 */

require_once '../../includes/config.php';
require_once '../../includes/database.php';

// Define cutoff date: Users created before this date are considered "old users"
// IMPORTANT: Set this to the deployment date of the tutorial feature (YYYY-MM-DD format)
$tutorialCutoffDate = date('Y-m-d'); // Change this to your actual deployment date if different

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    echo "===========================================\n";
    echo "Mark Old Users as Tutorial Completed\n";
    echo "===========================================\n\n";
    
    // Check if tutorial_completed column exists
    $stmt = $db->query("SHOW COLUMNS FROM faculty_profiles LIKE 'tutorial_completed'");
    $columnExists = $stmt->rowCount() > 0;
    
    if (!$columnExists) {
        echo "❌ ERROR: tutorial_completed column does not exist in faculty_profiles table.\n";
        echo "Please run the migration script first: add_tutorial_completed_to_faculty_profiles.sql\n";
        exit(1);
    }
    
    echo "✅ tutorial_completed column exists.\n\n";
    echo "Cutoff date: $tutorialCutoffDate\n";
    echo "(Users created before this date will be marked as having completed the tutorial)\n\n";
    
    // Count old users (created before cutoff date)
    $stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM faculty_profiles fp
        JOIN users u ON fp.user_id = u.id
        WHERE DATE(u.created_at) < ?
    ");
    $stmt->execute([$tutorialCutoffDate]);
    $oldUserCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    echo "Found $oldUserCount old user(s) (created before $tutorialCutoffDate).\n\n";
    
    if ($oldUserCount == 0) {
        echo "✅ No old users found. Nothing to update.\n";
        exit(0);
    }
    
    // Update old users to mark tutorial as completed
    // Only update if tutorial_completed is NULL or 0 (not already set to 1)
    $stmt = $db->prepare("
        UPDATE faculty_profiles fp
        JOIN users u ON fp.user_id = u.id
        SET fp.tutorial_completed = 1
        WHERE DATE(u.created_at) < ?
        AND (fp.tutorial_completed IS NULL OR fp.tutorial_completed = 0)
    ");
    
    $stmt->execute([$tutorialCutoffDate]);
    $updatedCount = $stmt->rowCount();
    
    echo "✅ Successfully marked $updatedCount old user(s) as having completed the tutorial.\n\n";
    
    // Verify the update
    $stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM faculty_profiles fp
        JOIN users u ON fp.user_id = u.id
        WHERE DATE(u.created_at) < ?
        AND fp.tutorial_completed = 1
    ");
    $stmt->execute([$tutorialCutoffDate]);
    $verifiedCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    echo "Verification: $verifiedCount old user(s) now have tutorial_completed = 1\n\n";
    
    // Show summary
    $stmt = $db->query("
        SELECT 
            COUNT(CASE WHEN fp.tutorial_completed = 1 THEN 1 END) as completed,
            COUNT(CASE WHEN fp.tutorial_completed = 0 OR fp.tutorial_completed IS NULL THEN 1 END) as not_completed,
            COUNT(*) as total
        FROM faculty_profiles fp
    ");
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "===========================================\n";
    echo "Summary:\n";
    echo "  Total faculty profiles: {$summary['total']}\n";
    echo "  Tutorial completed: {$summary['completed']}\n";
    echo "  Tutorial not completed: {$summary['not_completed']}\n";
    echo "===========================================\n\n";
    
    echo "✅ Migration completed successfully!\n";
    echo "\nNote: New users (created on or after $tutorialCutoffDate) will see the tutorial once.\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}








