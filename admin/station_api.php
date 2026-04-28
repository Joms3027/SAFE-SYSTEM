<?php
// Start output buffering to prevent any accidental output
ob_start();

require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAdmin();

// Clear any output that may have been generated
ob_clean();

header('Content-Type: application/json');

$database = Database::getInstance();
$db = $database->getConnection();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'create':
            $stationName = trim($_POST['station_name']);
            $stationPin = $_POST['station_pin'] ?? '';
            
            // Validate inputs
            if (empty($stationName)) {
                throw new Exception('Station name is required.');
            }
            
            if (empty($stationPin)) {
                throw new Exception('Station PIN is required.');
            }
            
            // Validate PIN format (numeric, 4-8 digits)
            if (!preg_match('/^\d{4,8}$/', $stationPin)) {
                throw new Exception('Station PIN must be 4-8 digits.');
            }
            
            // Check if station name already exists
            $stmt = $db->prepare("SELECT id FROM stations WHERE name = ?");
            $stmt->execute([$stationName]);
            if ($stmt->fetch()) {
                throw new Exception('Station name already exists.');
            }
            
            // Hash the PIN
            $hashedPin = password_hash($stationPin, PASSWORD_DEFAULT);
            
            // Insert new station (open station - no department)
            $stmt = $db->prepare("
                INSERT INTO stations (name, department_id, pin) 
                VALUES (?, NULL, ?)
            ");
            
            if ($stmt->execute([$stationName, $hashedPin])) {
                logAction('STATION_CREATE', "Created station: $stationName (device will be locked on first login)");
                echo json_encode([
                    'success' => true,
                    'message' => 'Station added successfully! Device will be automatically locked on first login.'
                ]);
            } else {
                throw new Exception('Failed to add station.');
            }
            break;
            
        case 'update':
            $stationId = (int)$_POST['station_id'];
            $stationName = trim($_POST['station_name']);
            $stationPin = $_POST['station_pin'] ?? '';
            
            // Validate inputs
            if (empty($stationName)) {
                throw new Exception('Station name is required.');
            }
            
            // Check if station exists
            $stmt = $db->prepare("SELECT id FROM stations WHERE id = ?");
            $stmt->execute([$stationId]);
            if (!$stmt->fetch()) {
                throw new Exception('Station not found.');
            }
            
            // Check if station name already exists (excluding current station)
            $stmt = $db->prepare("SELECT id FROM stations WHERE name = ? AND id != ?");
            $stmt->execute([$stationName, $stationId]);
            if ($stmt->fetch()) {
                throw new Exception('Station name already exists.');
            }
            
            // Update station - PIN is optional on update
            if (!empty($stationPin)) {
                // Validate PIN format
                if (!preg_match('/^\d{4,8}$/', $stationPin)) {
                    throw new Exception('Station PIN must be 4-8 digits.');
                }
                
                // Hash the new PIN
                $hashedPin = password_hash($stationPin, PASSWORD_DEFAULT);
                
                $stmt = $db->prepare("
                    UPDATE stations 
                    SET name = ?, pin = ? 
                    WHERE id = ?
                ");
                
                if ($stmt->execute([$stationName, $hashedPin, $stationId])) {
                    logAction('STATION_UPDATE', "Updated station ID: $stationId (with new PIN)");
                    echo json_encode([
                        'success' => true,
                        'message' => 'Station updated successfully!'
                    ]);
                } else {
                    throw new Exception('Failed to update station.');
                }
            } else {
                // Update without changing PIN
                $stmt = $db->prepare("
                    UPDATE stations 
                    SET name = ? 
                    WHERE id = ?
                ");
                
                if ($stmt->execute([$stationName, $stationId])) {
                    logAction('STATION_UPDATE', "Updated station ID: $stationId");
                    echo json_encode([
                        'success' => true,
                        'message' => 'Station updated successfully!'
                    ]);
                } else {
                    throw new Exception('Failed to update station.');
                }
            }
            break;
            
        case 'delete':
            $stationId = (int)$_POST['station_id'];
            
            // Check if station exists
            $stmt = $db->prepare("SELECT name FROM stations WHERE id = ?");
            $stmt->execute([$stationId]);
            $station = $stmt->fetch();
            
            if (!$station) {
                throw new Exception('Station not found.');
            }
            
            // Delete station
            $stmt = $db->prepare("DELETE FROM stations WHERE id = ?");
            
            if ($stmt->execute([$stationId])) {
                logAction('STATION_DELETE', "Deleted station: {$station['name']}");
                echo json_encode([
                    'success' => true,
                    'message' => 'Station deleted successfully!'
                ]);
            } else {
                throw new Exception('Failed to delete station.');
            }
            break;
            
        case 'reset_device':
            $stationId = (int)$_POST['station_id'];
            
            // Check if station exists
            $stmt = $db->prepare("SELECT name FROM stations WHERE id = ?");
            $stmt->execute([$stationId]);
            $station = $stmt->fetch();
            
            if (!$station) {
                throw new Exception('Station not found.');
            }
            
            // Reset device binding
            $stmt = $db->prepare("
                UPDATE stations 
                SET device_token = NULL, 
                    device_fingerprint = NULL, 
                    last_device_ip = NULL, 
                    device_registered_at = NULL 
                WHERE id = ?
            ");
            
            if ($stmt->execute([$stationId])) {
                logAction('STATION_DEVICE_RESET', "Reset device binding for station: {$station['name']}");
                echo json_encode([
                    'success' => true,
                    'message' => 'Device binding reset successfully!'
                ]);
            } else {
                throw new Exception('Failed to reset device binding.');
            }
            break;
            
        case 'export':
            // Export stations as CSV
            ob_clean(); // Clear any output before CSV
            $stmt = $db->query("
                SELECT s.id, s.name, s.created_at 
                FROM stations s 
                ORDER BY s.name ASC
            ");
            $stations = $stmt->fetchAll();
            
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="stations_' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            
            // Add CSV headers
            fputcsv($output, ['ID', 'Station Name', 'Created At']);
            
            // Add data rows
            foreach ($stations as $station) {
                fputcsv($output, [
                    $station['id'],
                    $station['name'],
                    $station['created_at']
                ]);
            }
            
            fclose($output);
            logAction('STATION_EXPORT', "Exported stations to CSV");
            exit;
            
        default:
            throw new Exception('Invalid action.');
    }
} catch (Exception $e) {
    // Ensure no output before JSON
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} catch (Throwable $e) {
    // Catch any other errors (PHP 7+)
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage()
    ]);
}

