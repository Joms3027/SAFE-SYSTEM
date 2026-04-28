<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new Auth();
$error = '';
$token = $_GET['token'] ?? '';

if (empty($token)) {
    $error = "Invalid reset link. Please request a new password reset.";
} else {
    // Verify the token
    $resetToken = $auth->verifyPasswordResetToken($token);
    
    if (!$resetToken) {
        $error = "Invalid or expired reset link. Please request a new password reset.";
    } else {
        // Token is valid and verified - redirect to password reset page
        $_SESSION['reset_token'] = $token;
        $_SESSION['reset_email'] = $resetToken['email'];
        redirect(SITE_URL . '/reset-password.php');
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
    <meta name="description" content="Verify Password Reset - WPU Faculty System">
    <title>Verify Password Reset - WPU Faculty System</title>
    
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
                <h1 class="login-title">Verifying...</h1>
                <p class="login-subtitle">Please wait while we verify your reset link</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger" role="alert">
                    <?php echo htmlspecialchars($error); ?>
                </div>
                
                <div class="back-to-login">
                    <a href="<?php echo SITE_URL; ?>/forgot-password.php">
                        <i class="fas fa-arrow-left"></i> Request New Reset Link
                    </a>
                </div>
            <?php else: ?>
                <div class="text-center">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-3">Redirecting to password reset page...</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js'); ?>"></script>
</body>
</html>
