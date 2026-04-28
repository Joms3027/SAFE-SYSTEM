<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/upload.php';

requireFaculty();

$database = Database::getInstance();
$db = $database->getConnection();
$uploader = new FileUploader();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!validateFormToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = "Invalid form submission. Please try again.";
        header('Location: upload_file.php');
        exit();
    } else {
        $requirementId = (int)$_POST['requirement_id'];
        
        // Get requirement details AND verify it's assigned to this faculty member
        $stmt = $db->prepare("
            SELECT r.* 
            FROM requirements r
            INNER JOIN faculty_requirements fr ON r.id = fr.requirement_id
            WHERE r.id = ? AND r.is_active = 1 AND fr.faculty_id = ?
        ");
        $stmt->execute([$requirementId, $_SESSION['user_id']]);
        $requirement = $stmt->fetch();
        
        if (!$requirement) {
            $error = 'Requirement not found, inactive, or not assigned to you.';
        } elseif ($requirement['deadline'] && strtotime($requirement['deadline']) < time()) {
            $_SESSION['error'] = 'Submission deadline has passed for this requirement.';
            header('Location: upload_file.php');
            exit();
        } else {
            // Handle file upload
            if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                $allowedTypes = array_map('trim', explode(',', $requirement['file_types']));
                $maxSize = isset($requirement['max_file_size']) ? (int)$requirement['max_file_size'] : MAX_FILE_SIZE;
                
                $result = $uploader->uploadFile($_FILES['file'], 'submissions', $allowedTypes, $maxSize);
                
                if ($result['success']) {
                    // Save submission to database
                    $stmt = $db->prepare("INSERT INTO faculty_submissions (faculty_id, requirement_id, file_path, original_filename, file_size) VALUES (?, ?, ?, ?, ?)");
                    
                    if ($stmt->execute([
                        $_SESSION['user_id'],
                        $requirementId,
                        $result['file_path'],
                        $result['original_filename'],
                        $result['file_size']
                    ])) {
                        // PRG: set success and redirect
                        $_SESSION['success'] = 'File submitted successfully!';
                        logAction('FILE_SUBMITTED', "Submitted file for requirement: " . $requirement['title']);
                        header('Location: upload_file.php');
                        exit();
                    } else {
                        $_SESSION['error'] = 'Failed to save submission. Please try again.';
                        header('Location: upload_file.php');
                        exit();
                    }
                } else {
                        $_SESSION['error'] = $result['message'];
                        header('Location: upload_file.php');
                        exit();
                }
            } else {
                    $_SESSION['error'] = 'Please select a file to upload.';
                    header('Location: upload_file.php');
                    exit();
            }
        }
    }
}

// Get active requirements assigned to this faculty member
$stmt = $db->prepare("
    SELECT r.* 
    FROM requirements r
    INNER JOIN faculty_requirements fr ON r.id = fr.requirement_id
    WHERE r.is_active = 1 AND fr.faculty_id = ?
    ORDER BY r.deadline ASC, r.created_at DESC
");
$stmt->execute([$_SESSION['user_id']]);
$requirements = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#003366">
    <title>Simple File Upload - WPU Faculty and Staff System</title>
    <link rel="icon" type="image/png" href="<?php echo asset_url('logo.png', true); ?>">
    <?php
    $basePath = getBasePath();
    ?>
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/faculty-portal.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/mobile.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/mobile-button-fix.css', true); ?>" rel="stylesheet">
    <style>
        .upload-form {
            max-width: 800px;
            margin: 2rem auto;
            padding: 2rem;
            background: #ffffff;
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.05);
        }
        .form-label {
            font-weight: 600;
            color: #2c3e50;
        }
        .form-control:focus, .form-select:focus {
            border-color: #4a90e2;
            box-shadow: 0 0 0 0.2rem rgba(74, 144, 226, 0.25);
        }
        .btn-primary {
            background-color: #4a90e2;
            border-color: #357abd;
            padding: 10px 20px;
        }
        .btn-primary:hover {
            background-color: #357abd;
            border-color: #2d6da3;
        }
        .file-info-card {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
        }
        .alert {
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        .form-text {
            color: #6c757d;
            font-size: 0.875rem;
        }
        .selected-file-info {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 2rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .requirement-info {
            margin-top: 1rem;
            padding: 0.75rem;
            background: #e9ecef;
            border-radius: 6px;
            font-size: 0.9rem;
        }
    </style>
</head>
<body class="bg-light">
    <?php include '../includes/navigation.php'; include_navigation(); ?>

    <div class="container mt-4">
        <div class="upload-form">
            <h2 class="mb-4 text-primary">
                <i class="fas fa-upload me-2"></i>File Upload</h2>
            
            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <?php addFormToken(); ?>
                
                <div class="mb-4">
                    <label for="requirement_id" class="form-label">
                        <i class="fas fa-tasks me-2"></i>Select Requirement *
                    </label>
                    <select class="form-select form-select-lg" id="requirement_id" name="requirement_id" required>
                        <option value="">Choose a requirement...</option>
                        <?php foreach ($requirements as $req): ?>
                            <option value="<?php echo $req['id']; ?>" 
                                    data-deadline="<?php echo $req['deadline'] ? formatDate($req['deadline'], 'M j, Y') : ''; ?>"
                                    data-types="<?php echo htmlspecialchars($req['file_types']); ?>"
                                    data-maxsize="<?php echo isset($req['max_file_size']) ? $req['max_file_size'] : MAX_FILE_SIZE; ?>">
                                <?php echo htmlspecialchars($req['title']); ?>
                                <?php if ($req['deadline']): ?>
                                    (Due: <?php echo formatDate($req['deadline'], 'M j, Y'); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="requirementInfo" class="requirement-info mt-2" style="display: none;"></div>
                </div>

                <div class="mb-4">
                    <label for="file" class="form-label">
                        <i class="fas fa-file-upload me-2"></i>Select File *
                    </label>
                    <input type="file" class="form-control form-control-lg" id="file" name="file" required>
                    <div class="form-text mt-2" id="fileHelp">
                        <i class="fas fa-info-circle me-1"></i>
                        <span id="fileHelpText">
                            Supported file types: PDF, DOC, DOCX, JPG, PNG<br>
                            Maximum file size: 5 MB
                        </span>
                    </div>
                </div>

                <div class="mb-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="confirm" required>
                        <label class="form-check-label" for="confirm">
                            <i class="fas fa-check-circle me-2"></i>
                            I confirm that this file is complete and accurate
                        </label>
                    </div>
                </div>

                <div class="d-grid gap-3">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-upload me-2"></i>Upload File
                    </button>
                    <a href="requirements.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Requirements
                    </a>
                </div>
            </form>
        </div>

        <!-- File Information Display -->
        <div class="selected-file-info mt-4 text-center" style="display: none;">
            <h5 class="mb-3">
                <i class="fas fa-file-alt me-2"></i>Selected File Information
            </h5>
            <div class="file-info-card">
                <p id="fileInfo" class="mb-0"></p>
            </div>
        </div>
    </div>

    <!-- Load Bootstrap first - CRITICAL for mobile interactions -->
    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', true); ?>"></script>
    <!-- Load main.js for core functionality -->
    <script src="<?php echo asset_url('js/main.js', true); ?>"></script>
    <!-- Load unified mobile interactions - replaces all mobile scripts -->
    <script src="<?php echo asset_url('js/mobile-interactions-unified.js', true); ?>"></script>
    <script>
        // Show file information when a file is selected
        document.getElementById('file').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const fileInfo = document.querySelector('.selected-file-info');
            const fileInfoText = document.getElementById('fileInfo');
            
            if (file) {
                const size = (file.size / 1024 / 1024).toFixed(2);
                const type = file.type;
                const icon = getFileIcon(file.name);
                fileInfoText.innerHTML = `
                    <div class="mb-2"><i class="${icon} fa-2x text-primary"></i></div>
                    <strong>File Name:</strong> ${file.name}<br>
                    <strong>Size:</strong> ${size} MB<br>
                    <strong>Type:</strong> ${type}
                `;
                fileInfo.style.display = 'block';
            } else {
                fileInfo.style.display = 'none';
            }
        });

        // Show requirement-specific information when selected
        document.getElementById('requirement_id').addEventListener('change', function(e) {
            const fileHelp = document.getElementById('fileHelp');
            const requirementInfo = document.getElementById('requirementInfo');
            const selectedOption = e.target.options[e.target.selectedIndex];
            
            if (selectedOption.value) {
                const deadline = selectedOption.dataset.deadline;
                const types = selectedOption.dataset.types || 'PDF, DOC, DOCX, JPG, PNG';
                const maxSize = selectedOption.dataset.maxsize ? 
                    (parseInt(selectedOption.dataset.maxsize) / (1024 * 1024)).toFixed(1) : 5;

                fileHelp.innerHTML = `
                    <i class="fas fa-info-circle me-1"></i>
                    <span>
                        Supported file types: ${types}<br>
                        Maximum file size: ${maxSize} MB
                    </span>
                `;

                requirementInfo.innerHTML = `
                    <div class="text-muted">
                        <div><i class="fas fa-file-alt me-2"></i>Selected: ${selectedOption.text}</div>
                        ${deadline ? `<div class="mt-1"><i class="fas fa-calendar me-2"></i>Due: ${deadline}</div>` : ''}
                    </div>
                `;
                requirementInfo.style.display = 'block';
            } else {
                requirementInfo.style.display = 'none';
            }
        });

        // Helper function to get appropriate Font Awesome icon based on file extension
        function getFileIcon(filename) {
            const ext = filename.split('.').pop().toLowerCase();
            const icons = {
                'pdf': 'fas fa-file-pdf',
                'doc': 'fas fa-file-word',
                'docx': 'fas fa-file-word',
                'jpg': 'fas fa-file-image',
                'jpeg': 'fas fa-file-image',
                'png': 'fas fa-file-image',
                'default': 'fas fa-file'
            };
            return icons[ext] || icons.default;
        }

        // Initialize Bootstrap tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl)
        });
    </script>
</body>
</html>
