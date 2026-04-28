<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

// Allow both admin and faculty access
// Admin can access any employee_id, faculty can only access their own
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$isAdmin = isAdmin();
$isFaculty = isset($_SESSION['user_type']) && ($_SESSION['user_type'] === 'faculty' || $_SESSION['user_type'] === 'staff');

if (!$isAdmin && !$isFaculty) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$database = Database::getInstance();
$db = $database->getConnection();

try {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $employee_id = $_GET['employee_id'] ?? $_POST['employee_id'] ?? '';
    
    // Debug logging
    error_log("manage_official_times_api.php called - Action: $action, Employee ID: $employee_id, User Type: " . ($isAdmin ? 'admin' : 'faculty'));
    
    if (empty($employee_id)) {
        echo json_encode(['success' => false, 'message' => 'Safe Employee ID is required']);
        exit;
    }
    
    // If faculty, verify they can only access their own employee_id OR employees in their pardon opener scope
    // Also restrict SAVE and DELETE actions to admin only
    if ($isFaculty && !$isAdmin) {
        // Faculty can only read (get/get_by_date), not modify
        if ($action === 'save' || $action === 'delete') {
            echo json_encode(['success' => false, 'message' => 'Unauthorized: Only administrators can modify official times']);
            exit;
        }
        
        // Verify faculty can access: own employee_id OR employees in pardon opener scope (my_assigned_employees)
        $stmt = $db->prepare("SELECT fp.employee_id FROM faculty_profiles fp WHERE fp.user_id = ? AND fp.employee_id = ? LIMIT 1");
        $stmt->execute([$_SESSION['user_id'], $employee_id]);
        $ownEmployee = $stmt->fetch(PDO::FETCH_ASSOC);
        $allowed = (bool) $ownEmployee;
        if (!$allowed && function_exists('canUserOpenPardonForEmployee')) {
            $allowed = canUserOpenPardonForEmployee($_SESSION['user_id'], $employee_id, $db);
        }
        
        if (!$allowed) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized: You can only access your own official times or those of employees in your assigned scope']);
            exit;
        }
    }
    
    if ($action === 'get_date_range') {
        // Get the most recent official time's date range for defaulting DTR view
        $stmt = $db->prepare("SELECT start_date, end_date FROM employee_official_times 
            WHERE employee_id = ? 
            ORDER BY start_date DESC 
            LIMIT 1");
        $stmt->execute([$employee_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['start_date']) {
            $endDate = $row['end_date'] ?? null;
            if (empty($endDate) || $endDate === '0000-00-00') {
                $endDate = date('Y-m-d'); // Ongoing - use today
            }
            echo json_encode([
                'success' => true,
                'date_from' => $row['start_date'],
                'date_to' => $endDate
            ]);
        } else {
            echo json_encode(['success' => true, 'date_from' => null, 'date_to' => null]);
        }
        exit;
    }
    
    if ($action === 'get' || $action === 'get_by_date') {
        // Get official times for an employee
        $start_date = $_GET['start_date'] ?? null;
        $date = $_GET['date'] ?? null; // For finding official times by specific date
        
        if ($action === 'get_by_date' && $date) {
            // Get official times that apply to a specific date and weekday
            $weekday = $_GET['weekday'] ?? null;
            
            // Get weekday from date if not provided
            if (!$weekday) {
                $dateObj = new DateTime($date);
                $dayOfWeek = $dateObj->format('w'); // 0 (Sunday) to 6 (Saturday)
                $weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                $weekday = $weekdays[$dayOfWeek];
            }
            
            // Find official time for this date range and weekday.
            // Official time applies only from its start_date onwards (computation starts at Start Date).
            $stmt = $db->prepare("SELECT * FROM employee_official_times 
                                 WHERE employee_id = ? 
                                 AND weekday = ?
                                 AND (end_date IS NULL OR end_date >= ?)
                                 AND start_date <= ?
                                 ORDER BY start_date DESC 
                                 LIMIT 1");
            $stmt->execute([$employee_id, $weekday, $date, $date]);
            $officialTime = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($officialTime) {
                echo json_encode([
                    'success' => true,
                    'official_time' => [
                        'found' => true,
                        'weekday' => $weekday,
                        'start_date' => $officialTime['start_date'],
                        'end_date' => $officialTime['end_date'],
                        'time_in' => substr($officialTime['time_in'], 0, 5),
                        'lunch_out' => ($officialTime['lunch_out'] && $officialTime['lunch_out'] != '00:00:00') ? substr($officialTime['lunch_out'], 0, 5) : '',
                        'lunch_in' => ($officialTime['lunch_in'] && $officialTime['lunch_in'] != '00:00:00') ? substr($officialTime['lunch_in'], 0, 5) : '',
                        'time_out' => substr($officialTime['time_out'], 0, 5)
                    ]
                ]);
            } else {
                // No official time found for this weekday and date range
                echo json_encode([
                    'success' => true,
                    'official_time' => [
                        'found' => false,
                        'weekday' => $weekday,
                        'reason' => 'No official time set'
                    ]
                ]);
            }
            exit;
        }
        
        if ($start_date) {
            // Get specific official time by start_date and optionally weekday
            $weekday = $_GET['weekday'] ?? null;
            
            if ($weekday) {
                // Get specific weekday's official time
                $stmt = $db->prepare("SELECT * FROM employee_official_times 
                                     WHERE employee_id = ? AND start_date = ? AND weekday = ? 
                                     LIMIT 1");
                $stmt->execute([$employee_id, $start_date, $weekday]);
            } else {
                // Get any official time for this start_date (for backward compatibility)
                $stmt = $db->prepare("SELECT * FROM employee_official_times 
                                     WHERE employee_id = ? AND start_date = ? 
                                     LIMIT 1");
                $stmt->execute([$employee_id, $start_date]);
            }
            
            $officialTime = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($officialTime) {
                echo json_encode([
                    'success' => true,
                    'official_time' => [
                        'start_date' => $officialTime['start_date'],
                        'end_date' => $officialTime['end_date'],
                        'weekday' => $officialTime['weekday'] ?? 'Monday',
                        'time_in' => substr($officialTime['time_in'], 0, 5),
                        'lunch_out' => ($officialTime['lunch_out'] && $officialTime['lunch_out'] != '00:00:00') ? substr($officialTime['lunch_out'], 0, 5) : '',
                        'lunch_in' => ($officialTime['lunch_in'] && $officialTime['lunch_in'] != '00:00:00') ? substr($officialTime['lunch_in'], 0, 5) : '',
                        'time_out' => substr($officialTime['time_out'], 0, 5)
                    ]
                ]);
            } else {
                // Return default times
                echo json_encode([
                    'success' => true,
                    'official_time' => [
                        'start_date' => $start_date,
                        'end_date' => null,
                        'weekday' => $weekday ?? 'Monday',
                        'time_in' => '08:00',
                        'lunch_out' => '12:00',
                        'lunch_in' => '13:00',
                        'time_out' => '17:00'
                    ]
                ]);
            }
        } else {
            // Get all official times for history
            try {
                // Direct query - weekday column exists based on table structure
                // Note: created_at might not exist, so we'll select what we need
                $stmt = $db->prepare("SELECT id, start_date, end_date, weekday, time_in, lunch_out, lunch_in, time_out 
                                     FROM employee_official_times 
                                     WHERE employee_id = ? 
                                     ORDER BY start_date DESC, weekday ASC
                                     LIMIT 20");
                
                $stmt->execute([$employee_id]);
                $officialTimes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                error_log("Query executed for employee_id: $employee_id");
                error_log("Found " . count($officialTimes) . " official times");
                
                $result = [];
                foreach ($officialTimes as $ot) {
                    $result[] = [
                        'id' => (int)$ot['id'],
                        'start_date' => $ot['start_date'],
                        'end_date' => $ot['end_date'] ? $ot['end_date'] : null,
                        'weekday' => $ot['weekday'] ? $ot['weekday'] : 'Monday',
                        'time_in' => $ot['time_in'] ? substr($ot['time_in'], 0, 5) : '08:00',
                        'lunch_out' => ($ot['lunch_out'] && $ot['lunch_out'] != '00:00:00') ? substr($ot['lunch_out'], 0, 5) : '-',
                        'lunch_in' => ($ot['lunch_in'] && $ot['lunch_in'] != '00:00:00') ? substr($ot['lunch_in'], 0, 5) : '-',
                        'time_out' => $ot['time_out'] ? substr($ot['time_out'], 0, 5) : '17:00'
                    ];
                }
                
                $response = [
                    'success' => true,
                    'official_times' => $result,
                    'count' => count($result)
                ];
                
                // Ensure no output before JSON
                if (ob_get_length()) ob_clean();
                
                header('Content-Type: application/json');
                echo json_encode($response, JSON_PRETTY_PRINT);
                exit;
            } catch (Exception $e) {
                error_log("Error fetching official times history: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
                
                // Ensure no output before JSON
                if (ob_get_length()) ob_clean();
                
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Error loading history: ' . $e->getMessage(),
                    'official_times' => []
                ]);
                exit;
            }
        }
    } else if ($action === 'save') {
        // Save official times
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? null;
        $weekday = $_POST['weekday'] ?? 'Monday'; // Default to Monday if not provided
        $time_in = $_POST['time_in'] ?? '';
        $lunch_out = $_POST['lunch_out'] ?? '';
        $lunch_in = $_POST['lunch_in'] ?? '';
        $time_out = $_POST['time_out'] ?? '';
        $id = $_POST['id'] ?? null; // For updates
        // Convert empty string to null
        if ($id === '' || $id === '0') {
            $id = null;
        } else if ($id !== null) {
            $id = intval($id);
        }
        
        // Debug logging
        error_log("Save official time - ID: " . ($id ?? 'null') . ", Employee: $employee_id, Weekday: $weekday, Start: $start_date, End: " . ($end_date ?? 'null'));
        
        if (empty($start_date) || empty($time_in) || empty($time_out) || empty($weekday)) {
            echo json_encode(['success' => false, 'message' => 'Start date, weekday, time in, and time out are required']);
            exit;
        }
        
        // Validate weekday
        $valid_weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        if (!in_array($weekday, $valid_weekdays)) {
            echo json_encode(['success' => false, 'message' => 'Invalid weekday']);
            exit;
        }
        
        // Validate date range
        if ($end_date && $end_date < $start_date) {
            echo json_encode(['success' => false, 'message' => 'End date must be after start date']);
            exit;
        }
        
        // Check for overlapping date ranges for the same weekday
        // Two date ranges overlap if they share any common dates
        // For ranges [A_start, A_end] and [B_start, B_end]:
        // They overlap if: A_start <= B_end AND B_start <= A_end
        // When end_date is NULL, it means ongoing (infinite end date)
        // IMPORTANT: Exclude the current record being edited (if $id is set)
        
        if ($id !== null && $id > 0) {
            // Editing existing record - exclude this ID from conflict check
            // Use a more explicit exclusion to ensure it works
            $overlapCheck = $db->prepare("SELECT id, start_date, end_date FROM employee_official_times 
                                         WHERE employee_id = ? 
                                         AND weekday = ?
                                         AND id <> ?
                                         AND (
                                             -- Case 1: Both have end dates - standard overlap: new_start <= existing_end AND existing_start <= new_end
                                             (? IS NOT NULL AND end_date IS NOT NULL AND ? <= end_date AND start_date <= ?)
                                             OR
                                             -- Case 2: New has end_date, existing is ongoing (NULL end_date means infinite)
                                             -- Overlap if new_end >= existing_start (new range ends after existing starts)
                                             (? IS NOT NULL AND end_date IS NULL AND ? >= start_date)
                                             OR
                                             -- Case 3: New is ongoing (NULL end_date), existing has end_date
                                             -- Overlap if new_start <= existing_end (new starts before existing ends)
                                             (? IS NULL AND end_date IS NOT NULL AND ? <= end_date)
                                             OR
                                             -- Case 4: Both are ongoing (both NULL end_date)
                                             -- Overlap if both exist (any start date means they overlap since both are infinite)
                                             (? IS NULL AND end_date IS NULL)
                                         )");
            
            $params = [
                $employee_id, 
                $weekday, 
                $id,  // Exclude this specific ID
                $end_date, $start_date, $end_date,  // Case 1: both have end dates
                $end_date, $end_date,                // Case 2: new has end, existing ongoing
                $end_date, $start_date,              // Case 3: new ongoing, existing has end
                $end_date                            // Case 4: both ongoing
            ];
            
            error_log("Conflict check (EDIT mode) - Excluding ID: $id, Params: " . json_encode($params));
            $overlapCheck->execute($params);
        } else {
            // New record - check against all existing records
            $overlapCheck = $db->prepare("SELECT id, start_date, end_date FROM employee_official_times 
                                         WHERE employee_id = ? 
                                         AND weekday = ?
                                         AND (
                                             -- Case 1: Both have end dates - standard overlap: new_start <= existing_end AND existing_start <= new_end
                                             (? IS NOT NULL AND end_date IS NOT NULL AND ? <= end_date AND start_date <= ?)
                                             OR
                                             -- Case 2: New has end_date, existing is ongoing (NULL end_date means infinite)
                                             -- Overlap if new_end >= existing_start (new range ends after existing starts)
                                             (? IS NOT NULL AND end_date IS NULL AND ? >= start_date)
                                             OR
                                             -- Case 3: New is ongoing (NULL end_date), existing has end_date
                                             -- Overlap if new_start <= existing_end (new starts before existing ends)
                                             (? IS NULL AND end_date IS NOT NULL AND ? <= end_date)
                                             OR
                                             -- Case 4: Both are ongoing (both NULL end_date)
                                             -- Overlap if both exist (any start date means they overlap since both are infinite)
                                             (? IS NULL AND end_date IS NULL)
                                         )");
            
            $params = [
                $employee_id, 
                $weekday, 
                $end_date, $start_date, $end_date,  // Case 1: both have end dates
                $end_date, $end_date,                // Case 2: new has end, existing ongoing
                $end_date, $start_date,              // Case 3: new ongoing, existing has end
                $end_date                            // Case 4: both ongoing
            ];
            
            error_log("Conflict check (NEW mode) - Params: " . json_encode($params));
            $overlapCheck->execute($params);
        }
        
        $overlap = $overlapCheck->fetch(PDO::FETCH_ASSOC);
        if ($overlap) {
            $existingStart = $overlap['start_date'];
            $existingEnd = $overlap['end_date'] ? $overlap['end_date'] : 'ongoing';
            $newEnd = $end_date ? $end_date : 'ongoing';
            $conflictId = $overlap['id'];
            
            error_log("Conflict found! Existing ID: $conflictId, Editing ID: " . ($id ?? 'null'));
            
            $message = "Conflict detected for {$weekday}: ";
            $message .= "The date range ({$start_date} to {$newEnd}) overlaps with an existing entry ";
            $message .= "({$existingStart} to {$existingEnd}). ";
            if ($id) {
                $message .= "Note: You are editing entry ID {$id}, but this conflict is with a different entry (ID {$conflictId}). ";
            }
            $message .= "Please adjust the date range or set an end date for the existing entry.";
            
            echo json_encode([
                'success' => false, 
                'message' => $message,
                'conflict_details' => [
                    'existing_id' => $conflictId,
                    'editing_id' => $id,
                    'existing_start' => $existingStart,
                    'existing_end' => $overlap['end_date'],
                    'new_start' => $start_date,
                    'new_end' => $end_date,
                    'weekday' => $weekday
                ]
            ]);
            exit;
        }
        
        error_log("No conflict found. Proceeding with save/update.");
        
        // Format times
        $time_in_formatted = $time_in . ':00';
        $lunch_out_formatted = $lunch_out ? $lunch_out . ':00' : null;
        $lunch_in_formatted = $lunch_in ? $lunch_in . ':00' : null;
        $time_out_formatted = $time_out . ':00';
        
        // Convert empty string to null for end_date
        $end_date = ($end_date === '') ? null : $end_date;
        
        if ($id) {
            // Update existing
            $stmt = $db->prepare("UPDATE employee_official_times 
                                SET start_date = ?, end_date = ?, weekday = ?, time_in = ?, lunch_out = ?, lunch_in = ?, time_out = ?
                                WHERE id = ? AND employee_id = ?");
            $stmt->execute([
                $start_date,
                $end_date,
                $weekday,
                $time_in_formatted,
                $lunch_out_formatted,
                $lunch_in_formatted,
                $time_out_formatted,
                $id,
                $employee_id
            ]);
        } else {
            // Check if exists by start_date and weekday
            $stmt = $db->prepare("SELECT id FROM employee_official_times 
                                 WHERE employee_id = ? AND start_date = ? AND weekday = ?");
            $stmt->execute([$employee_id, $start_date, $weekday]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // Update
                $stmt = $db->prepare("UPDATE employee_official_times 
                                    SET end_date = ?, time_in = ?, lunch_out = ?, lunch_in = ?, time_out = ?
                                    WHERE employee_id = ? AND start_date = ? AND weekday = ?");
                $stmt->execute([
                    $end_date,
                    $time_in_formatted,
                    $lunch_out_formatted,
                    $lunch_in_formatted,
                    $time_out_formatted,
                    $employee_id,
                    $start_date,
                    $weekday
                ]);
            } else {
                // Insert
                $stmt = $db->prepare("INSERT INTO employee_official_times 
                                    (employee_id, start_date, end_date, weekday, time_in, lunch_out, lunch_in, time_out)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $employee_id,
                    $start_date,
                    $end_date,
                    $weekday,
                    $time_in_formatted,
                    $lunch_out_formatted,
                    $lunch_in_formatted,
                    $time_out_formatted
                ]);
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Official times saved successfully'
        ]);
    } else if ($action === 'delete') {
        $id = $_POST['id'] ?? $_GET['id'] ?? '';
        
        if (empty($id)) {
            echo json_encode(['success' => false, 'message' => 'ID is required']);
            exit;
        }
        
        $stmt = $db->prepare("DELETE FROM employee_official_times 
                             WHERE id = ? AND employee_id = ?");
        $stmt->execute([$id, $employee_id]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode([
                'success' => true,
                'message' => 'Official times deleted successfully'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Official times not found or unauthorized'
            ]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log("Error managing official times: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>

