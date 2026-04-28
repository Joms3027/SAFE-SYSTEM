<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/database.php';

// Redirect if already logged in as timekeeper
if (isset($_SESSION['timekeeper_id']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'timekeeper') {
    redirect(SITE_URL . '/timekeeper/dashboard.php');
}

$database = Database::getInstance();
$db = $database->getConnection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $employeeId = sanitizeInput($_POST['employee_id'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($employeeId)) {
        $error = 'Safe Employee ID is required.';
    } elseif (empty($password)) {
        $error = 'Password is required.';
    } else {
        // Get timekeeper by employee ID
        $stmt = $db->prepare("
            SELECT tk.*, u.id as user_id, u.first_name, u.last_name, u.email, 
                   fp.employee_id, s.name as station_name, s.id as station_id
            FROM timekeepers tk
            INNER JOIN users u ON tk.user_id = u.id
            LEFT JOIN faculty_profiles fp ON u.id = fp.user_id
            LEFT JOIN stations s ON tk.station_id = s.id
            WHERE fp.employee_id = ? AND tk.is_active = 1 AND u.is_active = 1
        ");
        $stmt->execute([$employeeId]);
        $timekeeper = $stmt->fetch();
        
        if ($timekeeper && password_verify($password, $timekeeper['password'])) {
            // Set timekeeper session
            $_SESSION['timekeeper_id'] = $timekeeper['id'];
            $_SESSION['timekeeper_user_id'] = $timekeeper['user_id'];
            $_SESSION['timekeeper_station_id'] = $timekeeper['station_id'];
            $_SESSION['user_id'] = $timekeeper['user_id'];
            $_SESSION['user_type'] = 'timekeeper';
            $_SESSION['user_name'] = $timekeeper['first_name'] . ' ' . $timekeeper['last_name'];
            $_SESSION['user_email'] = $timekeeper['email'];
            $_SESSION['employee_id'] = $timekeeper['employee_id'];
            $_SESSION['station_id'] = $timekeeper['station_id'];
            $_SESSION['station_name'] = $timekeeper['station_name'];
            $_SESSION['login_time'] = time();
            
            // Get department name for station
            if ($timekeeper['station_id']) {
                $stmtDept = $db->prepare("
                    SELECT d.name as department_name
                    FROM stations s
                    INNER JOIN departments d ON s.department_id = d.id
                    WHERE s.id = ?
                ");
                $stmtDept->execute([$timekeeper['station_id']]);
                $deptData = $stmtDept->fetch();
                if ($deptData) {
                    $_SESSION['timekeeper_department_name'] = $deptData['department_name'];
                }
            }
            
            // Clear password verification flag on new login
            // Password verification only required when coming from QR scanner
            unset($_SESSION['timekeeper_password_verified']);
            
            logAction('TIMEKEEPER_LOGIN', "Timekeeper logged in: {$timekeeper['employee_id']} - {$timekeeper['station_name']}");
            
            session_regenerate_id(true);
            // Redirect directly to dashboard (no password verification needed from login)
            redirect(SITE_URL . '/timekeeper/dashboard.php');
        } else {
            $error = 'Invalid employee ID or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#1e293b">
    <title>Timekeeper Login - WPU Faculty System</title>
    <link rel="icon" type="image/png" href="<?php echo asset_url('logo.png', true); ?>">
    <link rel="apple-touch-icon" href="<?php echo asset_url('logo.png', true); ?>">
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css'); ?>" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
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
        
        .login-logo {
            margin: 0 auto 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .login-logo .logo-img {
            max-width: 120px;
            height: auto;
            display: block;
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
        }
        
        .input-group .form-control {
            border: none;
            border-radius: 0;
            background: transparent;
            flex: 1;
            padding-left: 0.75rem;
        }
        
        .btn-toggle-password {
            background: #f9fafb;
            border: none;
            border-left: 1px solid #d1d5db;
            color: #6b7280;
            padding: 0.625rem 0.875rem;
            min-width: 42px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .btn-toggle-password:hover {
            background: #f3f4f6;
            color: #374151;
        }
        
        .btn-primary {
            background: #1e293b;
            border: 1px solid #1e293b;
            border-radius: 4px;
            padding: 0.75rem 1.5rem;
            font-size: 0.9375rem;
            font-weight: 500;
            min-height: 42px;
            transition: background-color 0.15s ease;
            color: #ffffff;
            margin-top: 1.5rem;
            width: 100%;
        }
        
        .btn-primary:hover {
            background: #0f172a;
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
        
        .back-link {
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.875rem;
        }
        
        .back-link a {
            color: #64748b;
            text-decoration: none;
        }
        
        .back-link a:hover {
            color: #1e293b;
        }
    </style>
</head>
<body class="login-body">
    <div class="login-container">
        <div class="login-card">
            <div class="text-center mb-4">
                <div class="login-logo">
                    <img src="<?php echo asset_url('logo.png'); ?>" alt="WPU Logo" class="logo-img">
                </div>
                <h1 class="login-title">Timekeeper Login</h1>
                <p class="login-subtitle">WPU Faculty System</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <form method="POST" id="timekeeperLoginForm" novalidate>
                <div class="mb-3">
                    <label for="employee_id" class="form-label">Safe Employee ID</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="fas fa-id-card"></i>
                        </span>
                        <input type="text" 
                               class="form-control" 
                               id="employee_id" 
                               name="employee_id" 
                               placeholder="Enter your Safe Employee ID"
                               value="<?php echo isset($_POST['employee_id']) ? htmlspecialchars($_POST['employee_id']) : ''; ?>" 
                               required
                               autocomplete="username"
                               aria-required="true">
                    </div>
                </div>
                
                <div class="mb-4">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="fas fa-lock"></i>
                        </span>
                        <input type="password" 
                               class="form-control" 
                               id="password" 
                               name="password" 
                               placeholder="Enter your password"
                               required
                               autocomplete="current-password"
                               aria-required="true">
                        <button class="btn-toggle-password" 
                                type="button" 
                                id="togglePassword"
                                title="Show/Hide Password"
                                aria-label="Toggle password visibility">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary" id="loginBtn">
                    <i class="fas fa-sign-in-alt me-2"></i>Sign In
                </button>
            </form>
            
            <div class="back-link">
                <a href="login.php"><i class="fas fa-arrow-left me-1"></i>Back to Regular Login</a>
            </div>
        </div>
    </div>

    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js'); ?>"></script>
    <script>
        const togglePasswordBtn = document.getElementById('togglePassword');
        const passwordField = document.getElementById('password');
        
        if (togglePasswordBtn) {
            togglePasswordBtn.addEventListener('click', function(e) {
                e.preventDefault();
                const icon = this.querySelector('i');
                const isPassword = passwordField.type === 'password';
                
                passwordField.type = isPassword ? 'text' : 'password';
                icon.classList.toggle('fa-eye', !isPassword);
                icon.classList.toggle('fa-eye-slash', isPassword);
            });
        }
        
        const loginForm = document.getElementById('timekeeperLoginForm');
        const loginBtn = document.getElementById('loginBtn');
        
        if (loginForm) {
            loginForm.addEventListener('submit', function(e) {
                if (!this.checkValidity()) {
                    e.preventDefault();
                    e.stopPropagation();
                    this.classList.add('was-validated');
                    return false;
                }
                
                if (loginBtn) {
                    loginBtn.disabled = true;
                    loginBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Signing in...';
                }
            });
        }
    </script>
</body>
</html>

