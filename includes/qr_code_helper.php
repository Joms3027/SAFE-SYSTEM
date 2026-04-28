<?php
/**
 * QR Code Helper Functions
 * Generates QR codes for faculty/staff attendance tracking
 */

require_once __DIR__ . '/config.php';

// Try to load QR code library via Composer autoload
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * Generate QR code for a faculty/staff member
 * QR code contains: Employee ID as encoded data (for scanning)
 * QR code displays: Lastname, Firstname in the middle of the QR code image
 * 
 * @param int $userId User ID
 * @param string|null $employeeId Employee ID (if not provided, will be fetched from database)
 * @return string|false Path to saved QR code file, or false on failure
 */
function generateQRCode($userId, $employeeId = null) {
    // Check if QR code library is available
    if (!class_exists('Endroid\QrCode\Builder\Builder')) {
        error_log("QR Code library not found. Please run: composer require endroid/qr-code");
        return false;
    }
    
    // Get database connection
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    // If employee ID is not provided, fetch it from database
    if (empty($employeeId)) {
        $stmt = $db->prepare("SELECT employee_id FROM faculty_profiles WHERE user_id = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        if ($result && !empty($result['employee_id'])) {
            $employeeId = $result['employee_id'];
        } else {
            error_log("Employee ID not found for user ID: $userId. Cannot generate QR code.");
            return false;
        }
    }
    
    // Validate and sanitize employee ID
    $employeeId = trim($employeeId);
    if (empty($employeeId)) {
        error_log("Employee ID is empty after trimming for user ID: $userId. Cannot generate QR code.");
        return false;
    }
    
    // Remove any null bytes or control characters that might interfere with QR code scanning
    $employeeId = str_replace(["\0", "\r", "\n", "\t"], '', $employeeId);
    $employeeId = trim($employeeId);
    
    if (empty($employeeId)) {
        error_log("Employee ID is empty after sanitization for user ID: $userId. Cannot generate QR code.");
        return false;
    }
    
    // IMPORTANT: Fetch firstname and lastname based on employee_id to ensure correct name display
    // This ensures the QR code always shows the name of the person who owns the employee_id
    $firstName = '';
    $lastName = '';
    $actualUserId = $userId; // Track the actual user_id for this employee_id
    
    // Fetch name from users table based on employee_id (not userId) to ensure correctness
    $stmt = $db->prepare("
        SELECT u.id, u.first_name, u.last_name 
        FROM users u 
        INNER JOIN faculty_profiles fp ON u.id = fp.user_id 
        WHERE fp.employee_id = ?
    ");
    $stmt->execute([$employeeId]);
    $userResult = $stmt->fetch();
    
    if ($userResult) {
        $firstName = trim($userResult['first_name'] ?? '');
        $lastName = trim($userResult['last_name'] ?? '');
        $actualUserId = $userResult['id']; // Use the correct user_id for this employee_id
        
        // Verify that the employee_id belongs to the provided userId
        // If not, log a warning and use the actualUserId for filename
        if ($actualUserId != $userId) {
            error_log("Warning: Employee ID $employeeId belongs to user_id $actualUserId, but generateQRCode was called with user_id $userId. Using correct user_id for filename.");
        }
    } else {
        // Fallback: if employee_id lookup fails, use the provided userId
        error_log("Warning: Could not find user for employee_id: $employeeId. Using provided userId: $userId");
        $stmt = $db->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $userResult = $stmt->fetch();
        if ($userResult) {
            $firstName = trim($userResult['first_name'] ?? '');
            $lastName = trim($userResult['last_name'] ?? '');
        }
    }
    
    // Check if GD library is available for PNG images and text overlay
    $gdAvailable = extension_loaded('gd');
    
    try {
        // Create QR code data: Employee ID (encoded in QR code for scanning)
        // The QR code encodes the employee ID as plain text for attendance tracking
        // The visual display will show Lastname, Firstname in the middle of the image
        // Employee ID is already trimmed and sanitized above
        $qrData = $employeeId;
        
        // Final validation: ensure QR data is not empty
        if (empty($qrData)) {
            error_log("QR code data is empty for user ID: $userId, employee ID: " . var_export($employeeId, true));
            return false;
        }
        
        // Use UPLOAD_PATH constant for portability across different devices
        $uploadDir = UPLOAD_PATH . 'qr_codes/';
        
        // Create directory if it doesn't exist
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Choose file format based on available extensions
        // If GD is not available, use SVG (vector format, no image library needed)
        if (!$gdAvailable) {
            // Use actualUserId (the user who owns the employee_id) for filename to ensure correctness
            // This ensures QR code file matches the correct user even if employee_id was reassigned
            $filename = 'qr_' . $actualUserId . '.svg';
            $filePath = $uploadDir . $filename;
            
            // Always regenerate QR code to ensure it has the correct name matching the employee_id
            // Delete existing QR code if it exists to force regeneration with correct data
            if (file_exists($filePath)) {
                @unlink($filePath); // Remove old QR code to regenerate with correct name
            }
            
            // Use SVG writer (doesn't require GD)
            // Increased size to 500 for better readability and scanning
            $builder = new Builder(
                writer: new SvgWriter(),
                data: $qrData,
                encoding: new Encoding('UTF-8'),
                errorCorrectionLevel: ErrorCorrectionLevel::High,
                size: 500,
                margin: 10
            );
            
            $result = $builder->build();
            $result->saveToFile($filePath);
            
            error_log("QR code generated as SVG (GD extension not available). File: $filename");
            return 'qr_codes/' . $filename;
        }
        
        // GD is available - use PNG with text overlay
        // Use actualUserId (the user who owns the employee_id) for filename to ensure correctness
        // This ensures QR code file matches the correct user even if employee_id was reassigned
        $filename = 'qr_' . $actualUserId . '.png';
        $filePath = $uploadDir . $filename;
        
        // Always regenerate QR code to ensure it has the correct name matching the employee_id
        // Delete existing QR code if it exists to force regeneration with correct data
        if (file_exists($filePath)) {
            @unlink($filePath); // Remove old QR code to regenerate with correct name
        }
        
        // Build QR code using Builder with named parameters
        // Generate QR code without label (we'll add text overlay later)
        // Increased size to 500 for better readability and scanning reliability
        $builder = new Builder(
            writer: new PngWriter(),
            data: $qrData,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 500, // Increased size for better text visibility and scanning reliability
            margin: 10
        );
        
        $result = $builder->build();
        
        // GD is available - add text overlay
        // Save QR code image temporarily
        $tempFilePath = $uploadDir . 'temp_' . $filename;
        $result->saveToFile($tempFilePath);
        
        // Load the QR code image using GD
        $qrImage = imagecreatefrompng($tempFilePath);
        if (!$qrImage) {
            error_log("Failed to load QR code image for text overlay. Saving QR code without overlay.");
            // Fallback: save the temporary file as final file
            if (file_exists($tempFilePath)) {
                rename($tempFilePath, $filePath);
            }
            return 'qr_codes/' . $filename;
        }
        
        // Get image dimensions
        $width = imagesx($qrImage);
        $height = imagesy($qrImage);
        
        // Prepare text to overlay: Display Lastname, Firstname in the middle of QR code
        // Format: "Lastname, Firstname" or fallback to employee ID if name not available
        if (!empty($lastName) || !empty($firstName)) {
            $text = trim($lastName . ($lastName && $firstName ? ', ' : '') . $firstName);
        } else {
            // Fallback to employee ID if name is not available
            $text = trim($employeeId);
        }
        
        // Calculate font size based on image size and text length
        // Use a serif font similar to the Wikipedia example
        $baseFontSize = max(12, min(24, (int)($width / max(strlen($text), 10))));
        
        // Try to use a serif font (Times New Roman style)
        // Cross-platform font detection - works on Windows, Linux, and macOS
        $fontPaths = [];
        
        // Detect operating system for font path detection
        $os = strtoupper(substr(PHP_OS, 0, 3));
        
        if ($os === 'WIN') {
            // Windows font paths - detect Windows directory dynamically
            $windowsDir = getenv('WINDIR') ?: getenv('SystemRoot') ?: 'C:/Windows';
            $fontPaths = [
                $windowsDir . '/Fonts/times.ttf',
                $windowsDir . '/Fonts/timesi.ttf',
                $windowsDir . '/Fonts/timesbd.ttf',
                $windowsDir . '/Fonts/timesbi.ttf',
                $windowsDir . '/Fonts/timesnr.ttf',
                $windowsDir . '/Fonts/timesbi.ttf',
            ];
        } elseif ($os === 'DAR') {
            // macOS font paths
            $fontPaths = [
                '/System/Library/Fonts/Supplemental/Times New Roman.ttf',
                '/Library/Fonts/Times New Roman.ttf',
                '/System/Library/Fonts/Times.ttc',
                '/Library/Fonts/Times.ttc',
            ];
        } else {
            // Linux/Unix font paths (most common distributions)
            $fontPaths = [
                '/usr/share/fonts/truetype/liberation/LiberationSerif-Regular.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSerif.ttf',
                '/usr/share/fonts/truetype/liberation/LiberationSerif-Bold.ttf',
                '/usr/share/fonts/TTF/DejaVuSerif.ttf',
                '/usr/share/fonts/truetype/ttf-liberation/LiberationSerif-Regular.ttf',
                '/usr/local/share/fonts/truetype/liberation/LiberationSerif-Regular.ttf',
            ];
        }
        
        // Also check for fonts in project directory (for portability)
        $projectFontPath = dirname(__DIR__) . '/assets/fonts/';
        if (is_dir($projectFontPath)) {
            $projectFonts = glob($projectFontPath . '*.ttf');
            if (!empty($projectFonts)) {
                $fontPaths = array_merge($projectFonts, $fontPaths);
            }
        }
        
        $fontPath = null;
        foreach ($fontPaths as $path) {
            if (file_exists($path)) {
                $fontPath = $path;
                break;
            }
        }
        
        // If no TrueType font found, use built-in font
        $useTTF = $fontPath !== null;
        
        if ($useTTF) {
            // Calculate text dimensions using TrueType font
            $bbox = imagettfbbox($baseFontSize, 0, $fontPath, $text);
            $textWidth = abs($bbox[4] - $bbox[0]);
            $textHeight = abs($bbox[5] - $bbox[1]);
        } else {
            // Use built-in font (smaller, but works everywhere)
            $font = 5; // Built-in font 5 is largest
            $textWidth = imagefontwidth($font) * strlen($text);
            $textHeight = imagefontheight($font);
        }
        
        // Calculate position to center the text in the middle of QR code
        // Avoid the finder patterns (corners) - place text in center area
        $centerX = ($width - $textWidth) / 2;
        
        if ($useTTF) {
            // For TTF fonts, Y position is baseline (bottom of text)
            // Calculate baseline position for vertical centering
            $bbox = imagettfbbox($baseFontSize, 0, $fontPath, $text);
            $textBaseline = abs($bbox[7]); // Distance from baseline to top
            $centerY = ($height / 2) + ($textBaseline / 2);
        } else {
            // For built-in fonts, Y position is top-left corner
            $centerY = ($height - $textHeight) / 2;
        }
        
        // Create a white background rectangle for text readability
        $bgPadding = 8;
        $bgX = max(0, $centerX - $bgPadding);
        if ($useTTF) {
            $bbox = imagettfbbox($baseFontSize, 0, $fontPath, $text);
            $textTop = abs($bbox[7]);
            $textBottom = abs($bbox[1]);
            $actualTextHeight = $textTop + $textBottom;
            $bgY = max(0, ($height / 2) - ($actualTextHeight / 2) - $bgPadding);
            $bgHeight = $actualTextHeight + ($bgPadding * 2);
        } else {
            $bgY = max(0, $centerY - $bgPadding);
            $bgHeight = $textHeight + ($bgPadding * 2);
        }
        $bgWidth = min($width - $bgX, $textWidth + ($bgPadding * 2));
        
        // Draw white background rectangle
        $white = imagecolorallocate($qrImage, 255, 255, 255);
        imagefilledrectangle($qrImage, (int)$bgX, (int)$bgY, (int)($bgX + $bgWidth), (int)($bgY + $bgHeight), $white);
        
        // Draw black text
        $black = imagecolorallocate($qrImage, 0, 0, 0);
        
        if ($useTTF) {
            // Use TrueType font (Y is baseline position)
            imagettftext($qrImage, $baseFontSize, 0, (int)$centerX, (int)$centerY, $black, $fontPath, $text);
        } else {
            // Use built-in font (Y is top position)
            imagestring($qrImage, $font, (int)$centerX, (int)$centerY, $text, $black);
        }
        
        // Save the final image with text overlay
        imagepng($qrImage, $filePath);
        imagedestroy($qrImage);
        
        // Remove temporary file
        if (file_exists($tempFilePath)) {
            unlink($tempFilePath);
        }
        
        // Return relative path for database storage
        return 'qr_codes/' . $filename;
        
    } catch (Exception $e) {
        error_log("Failed to generate QR code: " . $e->getMessage());
        return false;
    }
}

/**
 * Get QR code image path for a user
 * 
 * @param int $userId User ID
 * @return string|false Path to QR code file, or false if not found
 */
function getQRCodePath($userId) {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    $stmt = $db->prepare("SELECT qr_code FROM faculty_profiles WHERE user_id = ?");
    $stmt->execute([$userId]);
    $result = $stmt->fetch();
    
    if ($result && !empty($result['qr_code'])) {
        return $result['qr_code'];
    }
    
    return false;
}


