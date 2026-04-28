<?php
require_once 'config.php';

class FileUploader {
    private $uploadPath;
    private $maxFileSize;
    private $allowedTypes;
    private $lastError = null;
    
    public function __construct() {
        $this->uploadPath = UPLOAD_PATH;
        $this->maxFileSize = MAX_FILE_SIZE;
        $this->allowedTypes = ALLOWED_FILE_TYPES;
        
        // Create upload directories if they don't exist
        $this->createDirectories();
    }
    
    private function createDirectories() {
        $directories = [
            $this->uploadPath,
            $this->uploadPath . 'submissions/',
            $this->uploadPath . 'profiles/',
            $this->uploadPath . 'pds/',
            $this->uploadPath . 'requirements/',
            $this->uploadPath . 'qr_codes/',
            $this->uploadPath . 'pardon_letters/',
            $this->uploadPath . 'tarf_requests/'
        ];
        
        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }
    
    public function uploadFile($file, $subfolder = '', $allowedTypes = null, $maxSize = null) {
        try {
            // Validate file
            if (!$this->validateFile($file, $allowedTypes, $maxSize)) {
                $message = $this->lastError ?: 'Invalid file.';
                return ['success' => false, 'message' => $message];
            }
            
            // Validate and sanitize subfolder to prevent path traversal attacks
            if ($subfolder) {
                // Remove any path traversal attempts (.., /, \)
                $subfolder = str_replace(['..', '/', '\\'], '', $subfolder);
                // Only allow alphanumeric, underscore, and hyphen
                $subfolder = preg_replace('/[^a-zA-Z0-9_-]/', '', $subfolder);
            }
            
            // Generate unique filename
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            // Sanitize extension to prevent path traversal in filename
            $extension = preg_replace('/[^a-zA-Z0-9]/', '', $extension);
            $filename = uniqid() . '_' . time() . '.' . $extension;
            
            // Set upload path
            $uploadDir = $this->uploadPath . ($subfolder ? $subfolder . '/' : '');
            // Ensure upload directory exists and is within allowed path
            $realUploadPath = realpath($this->uploadPath);
            $realUploadDir = realpath($uploadDir);
            if ($realUploadDir === false || strpos($realUploadDir, $realUploadPath) !== 0) {
                return ['success' => false, 'message' => 'Invalid upload path.'];
            }
            $filePath = $uploadDir . $filename;
            
            // Move uploaded file
            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                // Compress profile images for faster page loads
                if ($subfolder === 'profiles' && in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])) {
                    $this->compressProfileImage($filePath);
                }
                return [
                    'success' => true,
                    'file_path' => $subfolder . '/' . $filename,
                    'original_filename' => $file['name'],
                    'file_size' => filesize($filePath),
                    'file_type' => $file['type']
                ];
            } else {
                return ['success' => false, 'message' => 'Failed to upload file.'];
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Upload error: ' . $e->getMessage()];
        }
    }

    /**
     * Compress and resize profile images for faster page loads.
     * Resizes to max 512x512 (profile pics display at 96x96) and compresses JPEG/PNG.
     * GIF is resized only (keeps format; animated GIFs lose animation).
     *
     * @param string $fullPath Full filesystem path to the image
     * @return bool True if compression succeeded or was skipped, false on error
     */
    private function compressProfileImage($fullPath) {
        if (!function_exists('imagecreatefromjpeg') || !function_exists('imagejpeg')) {
            return true; // GD not available, skip compression
        }
        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $image = null;
        switch ($ext) {
            case 'jpg':
            case 'jpeg':
                $image = @imagecreatefromjpeg($fullPath);
                break;
            case 'png':
                $image = @imagecreatefrompng($fullPath);
                break;
            case 'gif':
                $image = @imagecreatefromgif($fullPath);
                break;
            default:
                return true;
        }
        if (!$image) {
            return true; // Could not load, keep original
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $maxDim = 512;
        $ratio = min($maxDim / $width, $maxDim / $height, 1);
        $newW = (int)round($width * $ratio);
        $newH = (int)round($height * $ratio);
        if ($newW >= $width && $newH >= $height && $ext !== 'png' && $ext !== 'gif') {
            // No resize needed; still recompress JPEG for smaller file size
            $saved = imagejpeg($image, $fullPath, 85);
            imagedestroy($image);
            return $saved;
        }
        if ($newW >= $width && $newH >= $height && ($ext === 'png' || $ext === 'gif')) {
            imagedestroy($image);
            return true; // PNG/GIF: no resize, skip (recompressing PNG/GIF can increase size)
        }
        $resized = imagecreatetruecolor($newW, $newH);
        if (!$resized) {
            imagedestroy($image);
            return true;
        }
        if ($ext === 'png') {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $transparent = imagecolorallocatealpha($resized, 255, 255, 255, 127);
            imagefilledrectangle($resized, 0, 0, $newW, $newH, $transparent);
        }
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newW, $newH, $width, $height);
        imagedestroy($image);
        $saved = false;
        if ($ext === 'gif') {
            $saved = imagegif($resized, $fullPath);
        } elseif ($ext === 'png') {
            $saved = imagepng($resized, $fullPath, 8);
        } else {
            $saved = imagejpeg($resized, $fullPath, 85);
        }
        imagedestroy($resized);
        return $saved;
    }
    
    private function validateFile($file, $allowedTypes = null, $maxSize = null) {
        // Use enhanced security validation if available
        if (function_exists('validateFileUpload')) {
            $allowedTypes = $allowedTypes ?: $this->allowedTypes;
            $maxSize = $maxSize ?: $this->maxFileSize;
            
            $validation = validateFileUpload($file, $allowedTypes, $maxSize);
            if (!$validation['valid']) {
                $this->lastError = $validation['error'];
                return false;
            }
            return true;
        }
        
        // Fallback to basic validation
        // Check if file was uploaded
        if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
            $this->lastError = 'No file uploaded or upload error.';
            return false;
        }
        
        // Check file size
        $maxSize = $maxSize ?: $this->maxFileSize;
        if ($file['size'] > $maxSize) {
            $this->lastError = 'File size exceeds the maximum allowed size.';
            return false;
        }
        
        // Check file type
        $allowedTypes = $allowedTypes ?: $this->allowedTypes;
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($extension, $allowedTypes)) {
            $this->lastError = 'File type not allowed.';
            return false;
        }
        
        // Additional security checks
        if (!function_exists('finfo_open')) {
            $this->lastError = 'File validation service unavailable. Please contact administrator.';
            return false;
        }
        
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            $this->lastError = 'Failed to initialize file validation service.';
            return false;
        }
        
        $mimeType = @finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if ($mimeType === false) {
            $this->lastError = 'Unable to determine file type.';
            return false;
        }
        
        // Validate MIME type (only if we successfully got MIME type)
        if ($mimeType) {
            $allowedMimeTypes = [
                'pdf' => ['application/pdf'],
                'doc' => ['application/msword'],
                'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
                'jpg' => ['image/jpeg', 'image/jpg'],
                'jpeg' => ['image/jpeg', 'image/jpg'],
                'png' => ['image/png'],
                'gif' => ['image/gif']
            ];
            
            if (isset($allowedMimeTypes[$extension])) {
                if (!in_array($mimeType, $allowedMimeTypes[$extension])) {
                    $this->lastError = 'File MIME type does not match file extension.';
                    return false;
                }
            }
        }
        
        // Check for dangerous content in file
        $fileContent = file_get_contents($file['tmp_name'], false, null, 0, 1024);
        if (preg_match('/<\?php|<\?=|<script/i', $fileContent)) {
            $this->lastError = 'File contains potentially dangerous content.';
            return false;
        }
        
        return true;
    }

    /**
     * Return last validation error message (if any)
     */
    public function getLastError() {
        return $this->lastError;
    }
    
    public function deleteFile($filePath) {
        $fullPath = $this->uploadPath . $filePath;
        $realFullPath = realpath($fullPath);
        $realUploadPath = realpath($this->uploadPath);
        
        if ($realFullPath === false || $realUploadPath === false || strpos($realFullPath, $realUploadPath) !== 0) {
            return false;
        }
        
        if (file_exists($realFullPath)) {
            return unlink($realFullPath);
        }
        
        return false;
    }
    
    public function getFileInfo($filePath) {
        $fullPath = $this->uploadPath . $filePath;
        $realFullPath = realpath($fullPath);
        $realUploadPath = realpath($this->uploadPath);
        
        if ($realFullPath === false || $realUploadPath === false || strpos($realFullPath, $realUploadPath) !== 0) {
            return false;
        }
        
        if (file_exists($realFullPath)) {
            return [
                'size' => filesize($realFullPath),
                'modified' => filemtime($realFullPath),
                'type' => mime_content_type($realFullPath)
            ];
        }
        
        return false;
    }
    
    public function downloadFile($filePath, $originalName = null) {
        $fullPath = $this->uploadPath . $filePath;
        $realFullPath = realpath($fullPath);
        $realUploadPath = realpath($this->uploadPath);
        
        if ($realFullPath === false || $realUploadPath === false || strpos($realFullPath, $realUploadPath) !== 0) {
            return false;
        }
        
        if (!file_exists($realFullPath)) {
            return false;
        }
        
        $originalName = $originalName ?: basename($filePath);
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
        
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Content-Length: ' . filesize($realFullPath));
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        
        readfile($realFullPath);
        exit();
    }
    
    public function getFileUrl($filePath) {
        // Dynamically get the current protocol, host, and port from the request
        // This ensures port forwarding works correctly on all devices
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        // Force HTTP for localhost to avoid HTTPS certificate issues and extension blocking
        $isLocalhost = in_array($host, ['localhost', '127.0.0.1', '::1']) || 
                       strpos($host, 'localhost') !== false ||
                       strpos($host, '127.0.0.1') !== false ||
                       strpos($host, '.local') !== false ||
                       strpos($host, '.test') !== false ||
                       strpos($host, 'xampp') !== false;
        
        // For localhost, use relative paths to avoid ad blocker blocking
        // Ad blockers often block absolute URLs for images
        if ($isLocalhost) {
            // Build relative path from current script location
            $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
            $currentDir = dirname($scriptName);
            
            // Get base path
            $basePath = '';
            if (defined('SITE_URL') && SITE_URL) {
                if (preg_match('/https?:\/\/[^\/]+(.+)$/', SITE_URL, $matches)) {
                    $basePath = $matches[1];
                } elseif (strpos(SITE_URL, '/') === 0) {
                    $basePath = SITE_URL;
                }
            }
            
            // Remove basePath from currentDir if present
            $relativeDir = $currentDir;
            if ($basePath && $basePath !== '/' && strpos($currentDir, $basePath) === 0) {
                $relativeDir = substr($currentDir, strlen($basePath));
            }
            
            // Count directory depth (e.g., /faculty = 1 level, /admin = 1 level)
            $relativeDir = trim($relativeDir, '/');
            $depth = $relativeDir ? substr_count($relativeDir, '/') + 1 : 0;
            
            // Build relative path: go up N levels, then to uploads
            $relativePrefix = $depth > 0 ? str_repeat('../', $depth) : '';
            $url = $relativePrefix . 'uploads/' . $filePath;
            
            // Remove any double slashes
            $url = preg_replace('#([^:])//+#', '$1/', $url);
            
            return $url;
        }
        
        // For non-localhost (production), use absolute URLs
        // Detect protocol normally
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        
        // Extract relative path from SITE_URL if it exists (e.g., '/FP' from 'http://localhost:8080/FP')
        $relativePath = '';
        if (defined('SITE_URL') && SITE_URL) {
            // Extract path from full URL if it's absolute
            if (preg_match('/https?:\/\/[^\/]+(.+)$/', SITE_URL, $matches)) {
                $relativePath = $matches[1];
            }
            // If SITE_URL is just a path, use it directly
            elseif (strpos(SITE_URL, '/') === 0) {
                $relativePath = SITE_URL;
            }
        }
        
        // Build URL with current host and port (supports port forwarding)
        // HTTP_HOST already includes the port if it's non-standard
        $baseUrl = $protocol . '://' . $host . $relativePath;
        return rtrim($baseUrl, '/') . '/uploads/' . $filePath;
    }
    
    public function formatFileSize($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
?>