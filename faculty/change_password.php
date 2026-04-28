<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireFaculty();

$database = Database::getInstance();
$db = $database->getConnection();
$userId = $_SESSION['user_id'];

// Use plain PHP variables — no session flash, no redirect.
// This completely avoids session-persistence issues that silently swallow flash messages.
$pwdSuccess = '';
$pwdError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateFormToken($_POST['csrf_token'] ?? '')) {
        $pwdError = 'Security token expired. Please refresh and try again.';
    } else {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword     = $_POST['new_password']     ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $pwdError = 'All password fields are required.';
        } elseif ($newPassword !== $confirmPassword) {
            $pwdError = 'New passwords do not match.';
        } else {
            $passwordValidation = validatePasswordStrength($newPassword);
            if (!$passwordValidation['valid']) {
                $pwdError = implode('<br>', $passwordValidation['errors']);
            } else {
                $stmt = $db->prepare('SELECT password FROM users WHERE id = ?');
                $stmt->execute([$userId]);
                $user = $stmt->fetch();

                if (!$user || !password_verify($currentPassword, $user['password'])) {
                    $pwdError = 'Current password is incorrect.';
                    logSecurityEvent('PASSWORD_CHANGE_FAILED', "Incorrect current password for user ID: $userId");
                } elseif (password_verify($newPassword, $user['password'])) {
                    $pwdError = 'New password must be different from your current password.';
                } else {
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $db->prepare('UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?');
                    if ($stmt->execute([$hashedPassword, $userId])) {
                        $pwdSuccess = 'Password changed successfully!';
                        logAction('PASSWORD_CHANGE', 'Changed password');
                        logSecurityEvent('PASSWORD_CHANGE_SUCCESS', "Password changed for user ID: $userId");
                    } else {
                        $pwdError = 'Failed to update password. Please try again.';
                    }
                }
            }
        }
    }
}
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
    <title>Change Password - WPU Faculty System</title>
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
</head>
<body class="layout-faculty">
    <?php require_once '../includes/navigation.php';
    include_navigation(); ?>
    
    <div class="container-fluid">
        <div class="row">
            
            <main class="main-content">
                <div class="page-header">
                    <div class="page-title">
                        <i class="fas fa-key"></i>
                        <span>Change Password</span>
                    </div>
                    <p class="page-subtitle">Keep your account secure with a strong, unique password.</p>
                </div>

                <?php if ($pwdSuccess !== '') : ?>
                <div class="alert alert-success alert-dismissible show shadow-sm mb-3" role="alert" data-no-toast="true">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-check-circle fa-lg flex-shrink-0"></i>
                        <div class="flex-grow-1"><strong>Success!</strong> <?php echo htmlspecialchars($pwdSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
                        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($pwdError !== '') : ?>
                <div class="alert alert-danger alert-dismissible show shadow-sm mb-3" role="alert" data-no-toast="true">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-exclamation-circle fa-lg flex-shrink-0"></i>
                        <div class="flex-grow-1"><?php echo $pwdError; ?></div>
                        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                </div>
                <?php endif; ?>

                <div class="row justify-content-center">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="fas fa-lock me-2"></i>Change Your Password</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <?php addFormToken(); ?>
                                    <div class="mb-3">
                                        <label for="current_password" class="form-label">Current Password</label>
                                        <input type="password" class="form-control" id="current_password" name="current_password" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="new_password" class="form-label">New Password</label>
                                        <div class="input-group">
                                            <input type="password" class="form-control" id="new_password" name="new_password" required minlength="8">
                                            <button type="button" 
                                                    class="btn btn-outline-secondary" 
                                                    id="toggleNewPassword" 
                                                    title="Show/Hide password">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                        <div class="form-text">Password must be at least 8 characters long and include uppercase, lowercase, number, and special character.</div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                                        <div class="input-group">
                                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="8">
                                            <button type="button" 
                                                    class="btn btn-outline-secondary" 
                                                    id="toggleConfirmPassword" 
                                                    title="Show/Hide password">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <strong>Password Requirements:</strong>
                                        <ul class="mb-0 mt-2">
                                            <li>At least 8 characters long</li>
                                            <li>At least one uppercase letter (A-Z)</li>
                                            <li>At least one lowercase letter (a-z)</li>
                                            <li>At least one number (0-9)</li>
                                            <li>At least one special character (!@#$%^&*...)</li>
                                            <li>Don't use easily guessable information</li>
                                        </ul>
                                    </div>
                                    
                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-save me-1"></i>Change Password
                                        </button>
                                        <a href="dashboard.php" class="btn btn-outline-secondary">
                                            <i class="fas fa-arrow-left me-1"></i>Back to Dashboard
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Load Bootstrap first - CRITICAL for mobile interactions -->
    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', true); ?>"></script>
    <!-- Load main.js for core functionality -->
    <script src="<?php echo asset_url('js/main.js', true); ?>"></script>
    <!-- Load unified mobile interactions - replaces all mobile scripts -->
    <script src="<?php echo asset_url('js/mobile-interactions-unified.js', true); ?>"></script>
    <script>
        // Password confirmation validation
        document.addEventListener('DOMContentLoaded', function() {
            const flash = document.querySelector('.alert-success, .alert-danger');
            if (flash && flash.scrollIntoView) {
                flash.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }

            const confirmPasswordInput = document.getElementById('confirm_password');
            const newPasswordInput = document.getElementById('new_password');
            
            if (confirmPasswordInput && newPasswordInput) {
                confirmPasswordInput.addEventListener('input', function() {
                    const newPassword = newPasswordInput.value;
                    const confirmPassword = this.value;
                    
                    if (newPassword !== confirmPassword) {
                        this.setCustomValidity('Passwords do not match');
                    } else {
                        this.setCustomValidity('');
                    }
                });
                
                newPasswordInput.addEventListener('input', function() {
                    if (confirmPasswordInput.value) {
                        confirmPasswordInput.dispatchEvent(new Event('input'));
                    }
                });
            }

            // Toggle password visibility for new password field
            const toggleNewPassword = document.getElementById('toggleNewPassword');
            if (toggleNewPassword && newPasswordInput) {
                toggleNewPassword.addEventListener('click', function() {
                    const icon = this.querySelector('i');
                    
                    if (newPasswordInput.type === 'password') {
                        newPasswordInput.type = 'text';
                        icon.classList.remove('fa-eye');
                        icon.classList.add('fa-eye-slash');
                        this.setAttribute('title', 'Hide Password');
                    } else {
                        newPasswordInput.type = 'password';
                        icon.classList.remove('fa-eye-slash');
                        icon.classList.add('fa-eye');
                        this.setAttribute('title', 'Show Password');
                    }
                });
            }

            // Toggle password visibility for confirm password field
            const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
            if (toggleConfirmPassword && confirmPasswordInput) {
                toggleConfirmPassword.addEventListener('click', function() {
                    const icon = this.querySelector('i');
                    
                    if (confirmPasswordInput.type === 'password') {
                        confirmPasswordInput.type = 'text';
                        icon.classList.remove('fa-eye');
                        icon.classList.add('fa-eye-slash');
                        this.setAttribute('title', 'Hide Password');
                    } else {
                        confirmPasswordInput.type = 'password';
                        icon.classList.remove('fa-eye-slash');
                        icon.classList.add('fa-eye');
                        this.setAttribute('title', 'Show Password');
                    }
                });
            }
        });
    </script>
</body>
</html>







