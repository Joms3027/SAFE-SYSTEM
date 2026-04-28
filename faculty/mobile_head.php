<!-- Mobile-First Meta Tags and Styles for All Faculty Pages -->
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="theme-color" content="#003366">
<meta name="description" content="WPU Faculty and Staff Management System">
<?php
// Get base path for assets (works from any subdirectory)
// Use getBasePath() function for consistent, portable path detection
if (!function_exists('getBasePath')) {
    require_once __DIR__ . '/../includes/functions.php';
}
$basePath = getBasePath();
// Ensure basePath has leading slash for consistency
if ($basePath && $basePath !== '/' && strpos($basePath, '/') !== 0) {
    $basePath = '/' . $basePath;
}
?>
<link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css', true); ?>" rel="stylesheet">
<link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css', true); ?>" rel="stylesheet">
<link href="<?php echo asset_url('css/style.css', true); ?>" rel="stylesheet">

<style>
/* Universal Mobile-First Application Styles */
* {
    -webkit-tap-highlight-color: rgba(0, 51, 102, 0.1);
}

body {
    overflow-x: hidden;
    -webkit-overflow-scrolling: touch;
    touch-action: pan-y;
}

.main-content {
    padding: 1rem;
    margin-top: 56px;
    min-height: calc(100vh - 56px);
    background: #f8fafc;
    margin-bottom: 0;
}

/* Mobile-Optimized Cards */
.card {
    margin-bottom: 1.5rem;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    overflow: hidden;
}

.card-header {
    padding: 1rem;
    background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
    border-bottom: 1px solid #e2e8f0;
    border-radius: 16px 16px 0 0 !important;
}

.card-header h5, .card-header h4 {
    font-size: 1.1rem;
    margin: 0;
    color: #0f172a;
    font-weight: 600;
}

.card-body {
    padding: 1rem;
}

/* Mobile Stats Cards */
.stats-card {
    margin-bottom: 1rem;
    min-height: 120px;
    border-radius: 16px;
    padding: 1.25rem;
}

.stats-card .stats-number {
    font-size: 2rem;
}

.stats-card .stats-label {
    font-size: 0.9rem;
}

/* Mobile Form Controls */
.form-control, .form-select {
    border-radius: 12px;
    border: 2px solid #e2e8f0;
    padding: 0.875rem 1rem;
    font-size: 1rem;
    min-height: 48px;
}

.form-control:focus, .form-select:focus {
    border-color: #003366;
    box-shadow: 0 0 0 4px rgba(0, 51, 102, 0.1);
}

.form-label {
    font-weight: 600;
    color: #334155;
    font-size: 0.95rem;
    margin-bottom: 0.5rem;
}

/* Mobile Buttons */
.btn {
    border-radius: 12px;
    padding: 0.875rem 1.25rem;
    font-size: 1rem;
    font-weight: 600;
    min-height: 48px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    transition: all 0.3s ease;
}

.btn-sm {
    padding: 0.625rem 1rem;
    font-size: 0.875rem;
    min-height: 40px;
}

.btn-lg {
    padding: 1rem 1.5rem;
    font-size: 1.125rem;
    min-height: 52px;
}

.btn-primary {
    background: linear-gradient(135deg, #003366 0%, #005599 100%);
    border: none;
    box-shadow: 0 4px 12px rgba(0, 51, 102, 0.2);
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0, 51, 102, 0.3);
}

/* Mobile Tables */
.table-responsive {
    border-radius: 12px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}

.table {
    margin-bottom: 0;
}

.table thead th {
    background: #f8fafc;
    border-bottom: 2px solid #e2e8f0;
    color: #0f172a;
    font-weight: 600;
    font-size: 0.9rem;
    padding: 0.875rem;
}

.table tbody td {
    padding: 0.875rem;
    vertical-align: middle;
    font-size: 0.9rem;
}

/* Mobile Badges */
.badge {
    padding: 0.5rem 0.875rem;
    font-size: 0.85rem;
    font-weight: 600;
    border-radius: 8px;
}

/* Mobile Alerts */
.alert {
    padding: 1rem;
    border-radius: 12px;
    border: none;
    font-size: 0.95rem;
}

/* Mobile Breadcrumbs */
.breadcrumb {
    font-size: 0.9rem;
    padding: 0.5rem 0;
    margin-bottom: 0.75rem;
    background: transparent;
}

/* Desktop Styles */
@media (min-width: 992px) {
    .main-content {
        margin-left: 280px;
        width: calc(100% - 280px);
        padding: 1.5rem;
        margin-top: 56px;
        margin-bottom: 0;
    }
    
    .stats-card {
        min-height: 140px;
    }
    
    .card-header h5, .card-header h4 {
        font-size: 1.25rem;
    }
}

/* Tablet Styles */
@media (min-width: 768px) and (max-width: 991px) {
    .main-content {
        padding: 1.25rem;
    }
    
    .stats-card {
        min-height: 130px;
    }
}

/* Mobile-Specific Styles */
@media (max-width: 767px) {
    .main-content {
        padding: 0.75rem;
        width: 100%;
    }
    
    .card-header h5, .card-header h4 {
        font-size: 1rem;
    }
    
    .stats-card {
        padding: 1rem;
        min-height: 110px;
    }
    
    .stats-card .stats-number {
        font-size: 1.75rem;
    }
    
    .stats-card .stats-label {
        font-size: 0.85rem;
    }
    
    /* Stack Tables on Mobile */
    .table-responsive thead {
        display: none;
    }
    
    .table-responsive tbody tr {
        display: block;
        margin-bottom: 1rem;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 0.75rem;
        background: white;
    }
    
    .table-responsive tbody td {
        display: block;
        text-align: right;
        padding: 0.5rem;
        position: relative;
        padding-left: 50%;
        border: none;
        border-bottom: 1px solid #f1f5f9;
    }
    
    .table-responsive tbody td:last-child {
        border-bottom: none;
    }
    
    .table-responsive tbody td:before {
        content: attr(data-label);
        position: absolute;
        left: 0.5rem;
        width: 45%;
        padding-right: 0.5rem;
        text-align: left;
        font-weight: 600;
        color: #334155;
    }
    
    /* Mobile Button Groups */
    .btn-group {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        width: 100%;
    }
    
    .btn-group .btn {
        width: 100%;
        border-radius: 12px !important;
    }
}

/* Extra Small Devices */
@media (max-width: 576px) {
    .main-content {
        padding: 0.5rem;
    }
    
    h1, .h1 {
        font-size: 1.5rem;
    }
    
    h2, .h2 {
        font-size: 1.35rem;
    }
    
    h3, .h3 {
        font-size: 1.2rem;
    }
    
    .stats-card {
        padding: 0.875rem;
        min-height: 100px;
    }
    
    .stats-card .stats-number {
        font-size: 1.5rem;
    }
    
    .card-header, .card-body {
        padding: 0.875rem;
    }
    
    .btn {
        padding: 0.75rem 1rem;
        font-size: 0.95rem;
    }
    
    /* Ensure notification dropdown fits on small screens */
    .notification-dropdown {
        top: 56px;
        max-height: calc(100vh - 60px);
    }
}

/* iOS Safe Area Support */
@supports (padding: max(0px)) {
    .main-content {
        padding-left: max(1rem, env(safe-area-inset-left));
        padding-right: max(1rem, env(safe-area-inset-right));
        padding-bottom: max(1rem, env(safe-area-inset-bottom));
    }
}

/* Loading States */
.btn.loading {
    position: relative;
    pointer-events: none;
    opacity: 0.7;
}

.btn.loading::after {
    content: "";
    position: absolute;
    width: 16px;
    height: 16px;
    top: 50%;
    left: 50%;
    margin-left: -8px;
    margin-top: -8px;
    border: 2px solid transparent;
    border-top-color: currentColor;
    border-radius: 50%;
    animation: spinner-border 0.75s linear infinite;
}

@keyframes spinner-border {
    to {
        transform: rotate(360deg);
    }
}

/* Touch Device Optimizations */
@media (hover: none) and (pointer: coarse) {
    .btn:hover {
        transform: none;
    }
    
}

/* Print Styles */
@media print {
    .header,
    .sidebar,
    .btn,
    .btn-toolbar,
    .alert {
        display: none !important;
    }
    
    .main-content {
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
    }
}
</style>

