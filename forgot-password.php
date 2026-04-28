<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';
require_once 'includes/mailer.php';

$auth = new Auth();
$mailer = new Mailer();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $email = sanitizeInput($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = "Please enter your email address.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        $result = $auth->requestPasswordReset($email);
        
        if (is_array($result) && isset($result['success']) && $result['success']) {
            // Generate reset URL
            $resetUrl = SITE_URL . '/verify-reset-token.php?token=' . urlencode($result['token']);
            
            // Send email
            $userName = $result['user']['first_name'] . ' ' . $result['user']['last_name'];
            $emailSent = $mailer->sendPasswordResetEmail($email, $userName, $result['token'], $resetUrl);
            
            if ($emailSent) {
                $success = "Password reset instructions have been sent to your email address. Please check your inbox and click the verification link to proceed.";
            } else {
                $error = "Failed to send email. Please try again later or contact administrator.";
            }
        } else {
            // For security, show success message even if user doesn't exist
            $success = "If an account with that email exists, password reset instructions have been sent.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="dns-prefetch" href="//fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    
    <?php include_once 'includes/pwa-meta.php'; ?>
    <meta name="description" content="Reset Password - WPU Faculty System">
    <title>Forgot Password - WPU Faculty System</title>
    
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css'); ?>" rel="stylesheet">
    <style>
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }
        
        .login-body {
            background: #f5f7fa;
            min-height: 100vh;
            min-height: -webkit-fill-available;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            width: 100%;
            box-sizing: border-box;
        }
        
        .login-container {
            width: 100%;
            max-width: 400px;
            margin: 0 auto;
            box-sizing: border-box;
        }
        
        .login-card {
            background: #ffffff;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            padding: 3rem 2.5rem;
            border: 1px solid #e5e7eb;
        }
        
        .login-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #1e293b;
            text-align: center;
            margin-bottom: 0.5rem;
            letter-spacing: -0.01em;
        }
        
        .login-subtitle {
            font-size: 0.875rem;
            color: #64748b;
            text-align: center;
            margin-bottom: 2rem;
            font-weight: 400;
        }
        
        .form-label {
            font-weight: 500;
            color: #374151;
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
            display: block;
        }
        
        .form-control {
            border: 1px solid #d1d5db;
            border-radius: 4px;
            padding: 0.625rem 0.875rem;
            font-size: 0.9375rem;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
            min-height: 42px;
            background: #ffffff;
            width: 100%;
            outline: none;
            color: #1e293b;
        }
        
        .form-control:focus {
            border-color: #1e293b;
            outline: none;
            box-shadow: 0 0 0 3px rgba(30, 41, 59, 0.1);
        }
        
        .input-group {
            border-radius: 4px;
            overflow: hidden;
            transition: border-color 0.15s ease;
            display: flex;
            align-items: stretch;
            width: 100%;
            background: #ffffff;
            border: 1px solid #d1d5db;
        }
        
        .input-group:focus-within {
            border-color: #1e293b;
            box-shadow: 0 0 0 3px rgba(30, 41, 59, 0.1);
        }
        
        .input-group-text {
            background: #f9fafb;
            border: none;
            border-right: 1px solid #d1d5db;
            color: #6b7280;
            padding: 0.625rem 0.875rem;
            min-width: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .input-group .form-control {
            border: none;
            border-radius: 0;
            background: transparent;
            flex: 1;
            padding-left: 0.75rem;
        }
        
        .btn-primary {
            background: #1e293b;
            border: 1px solid #1e293b;
            border-radius: 4px;
            padding: 0.75rem 1.5rem;
            font-size: 0.9375rem;
            font-weight: 500;
            min-height: 42px;
            transition: background-color 0.15s ease, border-color 0.15s ease;
            color: #ffffff;
            margin-top: 1.5rem;
            display: block;
            margin-left: auto;
            margin-right: auto;
            width: 100%;
        }
        
        .btn-primary:hover {
            background: #0f172a;
            border-color: #0f172a;
        }
        
        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .alert {
            border-radius: 4px;
            border: 1px solid;
            padding: 0.875rem 1rem;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
        }
        
        .alert-danger {
            background: #fef2f2;
            color: #991b1b;
            border-color: #fecaca;
        }
        
        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border-color: #bbf7d0;
        }
        
        .back-to-login {
            text-align: center;
            margin-top: 1.5rem;
        }
        
        .back-to-login a {
            color: #64748b;
            text-decoration: none;
            font-size: 0.875rem;
        }
        
        .back-to-login a:hover {
            color: #1e293b;
        }
        
        @media (max-width: 576px) {
            .login-card {
                padding: 2rem 1.5rem;
            }
        }
    </style>
</head>
<body class="login-body">
    <div class="login-container">
        <div class="login-card">
            <div class="text-center mb-4">
                <h1 class="login-title">Forgot Password</h1>
                <p class="login-subtitle">Enter your email to reset your password</p>
                <p class="text-muted small mt-2" style="font-size: 0.75rem; color: #9ca3af;">Available for faculty and staff only</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($success); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <?php if (!$success): ?>
            <form method="POST" id="forgotPasswordForm" novalidate>
                <div class="mb-3">
                    <label for="email" class="form-label">Email Address</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="fas fa-envelope"></i>
                        </span>
                        <input type="email" 
                               class="form-control" 
                               id="email" 
                               name="email" 
                               placeholder="your.email@wpu.edu.ph"
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" 
                               required
                               autocomplete="email"
                               aria-required="true">
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary" id="submitBtn">
                    Send Reset Link
                </button>
            </form>
            <?php endif; ?>
            
            <div class="back-to-login">
                <a href="<?php echo SITE_URL; ?>/login.php">
                    <i class="fas fa-arrow-left"></i> Back to Login
                </a>
            </div>
        </div>
    </div>

    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js'); ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('forgotPasswordForm');
            const submitBtn = document.getElementById('submitBtn');
            
            if (form && submitBtn) {
                form.addEventListener('submit', function(e) {
                    if (!this.checkValidity()) {
                        e.preventDefault();
                        e.stopPropagation();
                        this.classList.add('was-validated');
                        return false;
                    }
                    
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Sending...';
                });
            }
        });
    </script>
</body>
</html>
