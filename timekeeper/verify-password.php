<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

// Check if user is logged in as timekeeper (but don't requireTimekeeper() to avoid redirect loop
// Only check basic timekeeper session, not password verification
if (!isset($_SESSION['timekeeper_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'timekeeper') {
    redirect('../station_login.php');
}

$database = Database::getInstance();
$db = $database->getConnection();
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    
    if (empty($password)) {
        $error = 'Password is required.';
    } else {
        // Get timekeeper's password hash from database
        $stmt = $db->prepare("
            SELECT tk.password, tk.user_id, fp.employee_id
            FROM timekeepers tk
            INNER JOIN users u ON tk.user_id = u.id
            LEFT JOIN faculty_profiles fp ON u.id = fp.user_id
            WHERE tk.id = ? AND tk.is_active = 1
        ");
        $stmt->execute([$_SESSION['timekeeper_id']]);
        $timekeeper = $stmt->fetch();
        
        if ($timekeeper && password_verify($password, $timekeeper['password'])) {
            // Password verified - set session flag
            $_SESSION['timekeeper_password_verified'] = true;
            // Clear QR scanner flag since password is verified
            unset($_SESSION['from_qr_scanner']);
            $success = true;
            
            // Redirect to intended destination or dashboard
            $redirectTo = $_SESSION['timekeeper_redirect_after_auth'] ?? 'dashboard.php';
            unset($_SESSION['timekeeper_redirect_after_auth']);
            
            // Small delay to show success message
            header("Refresh: 1; url=" . $redirectTo);
        } else {
            $error = 'Invalid password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Password - Timekeeper Dashboard</title>
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css'); ?>" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        body {
            background: #f5f7fa;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }
        
        .verify-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            padding: 2.5rem;
            max-width: 400px;
            width: 100%;
        }
        
        .verify-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 1.5rem;
            background: #f0f9ff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #0ea5e9;
        }
        
        .verify-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #1e293b;
            text-align: center;
            margin-bottom: 0.5rem;
        }
        
        .verify-subtitle {
            font-size: 0.875rem;
            color: #64748b;
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .form-label {
            font-weight: 500;
            color: #374151;
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
        }
        
        .form-control {
            border: 1px solid #d1d5db;
            border-radius: 4px;
            padding: 0.625rem 0.875rem;
            font-size: 0.9375rem;
        }
        
        .form-control:focus {
            border-color: #0ea5e9;
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
        }
        
        .btn-primary {
            background: #0ea5e9;
            border: 1px solid #0ea5e9;
            border-radius: 4px;
            padding: 0.75rem 1.5rem;
            font-size: 0.9375rem;
            font-weight: 500;
            width: 100%;
            margin-top: 1rem;
        }
        
        .btn-primary:hover {
            background: #0284c7;
            border-color: #0284c7;
        }
        
        .alert {
            border-radius: 4px;
            margin-bottom: 1.5rem;
        }
        
        .success-message {
            text-align: center;
            color: #059669;
            font-weight: 500;
        }
        
        .input-group {
            position: relative;
        }
        
        .btn-toggle-password {
            position: absolute;
            right: 0;
            top: 0;
            bottom: 0;
            background: transparent;
            border: none;
            padding: 0 0.875rem;
            color: #6b7280;
            cursor: pointer;
            z-index: 10;
        }
        
        .btn-toggle-password:hover {
            color: #374151;
        }
    </style>
</head>
<body>
    <div class="verify-card">
        <div class="verify-icon">
            <i class="fas fa-lock fa-2x"></i>
        </div>
        
        <h2 class="verify-title">Password Verification</h2>
        <p class="verify-subtitle">Please enter your password to access the dashboard</p>
        
        <?php if ($success): ?>
            <div class="alert alert-success success-message">
                <i class="fas fa-check-circle me-2"></i>Password verified! Redirecting...
            </div>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <form method="POST" id="verifyPasswordForm">
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <input type="password" 
                               class="form-control" 
                               id="password" 
                               name="password" 
                               placeholder="Enter your password"
                               required
                               autofocus
                               autocomplete="current-password">
                        <button type="button" class="btn-toggle-password" onclick="togglePassword()">
                            <i class="fas fa-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-unlock me-2"></i>Verify Password
                </button>
            </form>
            
            <div class="text-center mt-3">
                <a href="../logout.php" class="text-muted text-decoration-none" style="font-size: 0.875rem;">
                    <i class="fas fa-sign-out-alt me-1"></i>Logout
                </a>
            </div>
        <?php endif; ?>
    </div>
    
    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js'); ?>"></script>
    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }
        
        // Auto-focus password field
        document.getElementById('password')?.focus();
    </script>
</body>
</html>

