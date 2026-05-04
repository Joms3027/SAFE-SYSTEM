<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireFaculty();

$database = Database::getInstance();
$db = $database->getConnection();

// Get faculty profile to get employee_id and department
$stmt = $db->prepare("SELECT fp.employee_id, fp.position, fp.department, u.first_name, u.last_name FROM faculty_profiles fp JOIN users u ON fp.user_id = u.id WHERE fp.user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

$employee_id = $profile['employee_id'] ?? '';
$fullName = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));
$facultyDepartment = $profile['department'] ?? '';

// DTR "In Charge": designated pardon opener (by department/designation) or fallback to Dean
$dtrInCharge = '';
if (function_exists('getPardonOpenerDisplayNameForEmployee')) {
    $dtrInCharge = getPardonOpenerDisplayNameForEmployee($employee_id, $db);
}
if ($dtrInCharge === 'HR' && !empty($facultyDepartment)) {
    $stmtDean = $db->prepare("SELECT u.first_name, u.last_name, fp.department
                             FROM faculty_profiles fp
                             JOIN users u ON fp.user_id = u.id
                             WHERE fp.department = ? AND LOWER(TRIM(COALESCE(fp.designation, ''))) = 'dean'
                             LIMIT 1");
    $stmtDean->execute([$facultyDepartment]);
    $dean = $stmtDean->fetch(PDO::FETCH_ASSOC);
    if ($dean) {
        $dtrInCharge = trim(($dean['first_name'] ?? '') . ' ' . ($dean['last_name'] ?? '')) . (trim($dean['department'] ?? '') ? ', ' . trim($dean['department']) : '');
    }
}
if ($dtrInCharge === 'HR') {
    $dtrInCharge = '';
}

if (empty($employee_id)) {
    $_SESSION['error'] = 'Safe Employee ID not found. Please update your profile.';
    redirect('profile.php');
}

require_once '../includes/navigation.php';
include_navigation();
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
    <meta name="description" content="Employee Daily Time Record - WPU Faculty and Staff Management System">
    <title>Employee Daily Time Record - WPU Faculty System</title>
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
        /* Simple, Clean Design */
        .page-header {
            /* margin-bottom: 1.5rem; */
            padding: 1rem 0;
        }
        
        .page-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--primary-blue);
            margin-bottom: 0.25rem;
        }
        
        .page-subtitle {
            color: var(--text-medium);
            font-size: 0.9rem;
        }
        
        /* Summary Cards - Simple Cards */
        .logs-summary-card {
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            background: var(--white);
            box-shadow: var(--shadow-sm);
            transition: box-shadow 0.2s ease;
        }
        
        .logs-summary-card:hover {
            box-shadow: var(--shadow);
        }
        
        .logs-summary-card .card-body {
            padding: 1rem;
            text-align: center;
        }
        
        .logs-summary-card .small {
            font-size: 0.75rem;
            color: var(--text-medium);
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        
        .logs-summary-card .h5 {
            font-size: 1.5rem;
            font-weight: 600;
            margin: 0;
        }
        
        /* Table Container - compact, allow horizontal scroll so all columns visible */
        .logs-table-container {
            max-height: 651px;
            overflow-x: auto;
            overflow-y: auto;
            border-radius: var(--radius-md);
            -webkit-overflow-scrolling: touch;
            width: 100%;
        }
        .logs-table-container .table {
            width: 100%;
        }
        
        .table {
            margin-bottom: 0;
            font-size: 0.8rem;
        }
        
        .table thead {
            background: var(--light-blue);
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .table thead th {
            font-weight: 600;
            font-size: 0.75rem;
            color: var(--primary-blue);
            border-bottom: 2px solid var(--border-color);
            padding: 0.4rem 0.5rem;
        }
        
        .table tbody td {
            padding: 0.4rem 0.5rem;
            font-size: 0.75rem;
            vertical-align: middle;
        }
        
        .table tbody tr {
            border-bottom: 1px solid var(--border-light);
        }
        
        .table tbody tr:hover {
            background: var(--office-gray);
        }
        
        /* Compact badges in logs table */
        .logs-table-container .badge {
            font-size: 0.65rem;
            padding: 0.2rem 0.4rem;
        }
        
        /* Filter Section */
        .card-header {
            background: var(--white);
            border-bottom: 1px solid var(--border-color);
            padding: 1rem;
        }
        
        .card-header h5 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-dark);
            margin: 0;
        }
        
        /* Totals row in logs table */
        .logs-table-container .logs-total-row td {
            border-top: 2px solid var(--border-color);
            padding: 0.5rem 0.75rem;
        }
        
        /* Action Button - compact for table fit */
        .logs-table-container .btn-edit-modern {
            padding: 0.35rem 0.6rem;
            font-size: 0.7rem;
        }
        .btn-edit-modern {
            background: var(--warning-color);
            border: none;
            color: var(--white);
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            font-weight: 500;
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
        
        .btn-edit-modern:hover:not(:disabled) {
            background: #b45309;
            color: var(--white);
        }
        
        .btn-edit-modern:disabled {
            cursor: not-allowed;
            opacity: 0.6;
        }
        
        /* ============================================
           MOBILE RESPONSIVE STYLES - DEDICATED CSS
           ============================================ */
        
        /* Extra Small Devices (phones, 320px and up) */
        @media (max-width: 575.98px) {
            /* Center all main containers */
            .container-fluid {
                padding-left: 0.75rem !important;
                padding-right: 0.75rem !important;
                margin: 0 auto !important;
            }
            
            .main-content {
                margin: 0 auto !important;
                padding: 0 !important;
            }
            
            .page-header {
                padding: 0.5rem 0;
                margin-bottom: 0.75rem;
                text-align: center;
            }
            
            .page-title {
                font-size: 1.1rem;
                line-height: 1.3;
                text-align: center;
                margin: 0 auto;
            }
            
            .page-title i {
                font-size: 0.9rem;
            }
            
            .page-subtitle {
                font-size: 0.8rem;
                text-align: center;
            }
            
            .card {
                border-radius: 0;
                margin: 0 auto;
                width: 100%;
            }
            
            .card-header {
                padding: 0.75rem;
                text-align: center;
            }
            
            .card-header h5 {
                font-size: 0.9rem;
                line-height: 1.4;
                text-align: center;
                margin: 0 auto;
            }
            
            .card-header .badge {
                font-size: 0.7rem;
                padding: 0.25rem 0.5rem;
                display: inline-block;
                margin: 0.25rem auto 0;
            }
            
            .card-body {
                padding: 0.75rem;
                text-align: center;
            }
            
            .logs-table-container {
                max-height: none;
                overflow-x: auto;
                overflow-y: visible;
                margin: 0 auto;
                width: 100%;
                -webkit-overflow-scrolling: touch;
            }
            
            .layout-faculty .logs-table-container.table-responsive {
                overflow-x: auto !important;
            }
            
            .layout-faculty .table-responsive {
                width: 100% !important;
                max-width: 100% !important;
                overflow-x: auto !important;
                margin: 0 auto !important;
                padding: 0 !important;
            }
            
            .table-responsive {
                border: none;
            }
            
            .table-responsive thead {
                display: none;
            }
            
            .table-responsive tbody tr {
                display: block;
                margin: 0 auto 0.75rem;
                border: 1px solid var(--border-color);
                border-radius: 8px;
                padding: 0.75rem;
                background: var(--white);
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                width: 100%;
                max-width: 100%;
            }
            
            .table-responsive tbody td {
                display: block;
                text-align: right;
                padding: 0.5rem 0.5rem 0.5rem 45%;
                position: relative;
                border: none;
                border-bottom: 1px solid var(--border-light);
                font-size: 0.875rem;
            }
            
            .table-responsive tbody td:last-child {
                border-bottom: none;
                padding-bottom: 0;
            }
            
            .table-responsive tbody td:before {
                content: attr(data-label);
                position: absolute;
                left: 0.5rem;
                width: 42%;
                text-align: left;
                font-weight: 600;
                color: var(--text-medium);
                font-size: 0.75rem;
                top: 50%;
                transform: translateY(-50%);
            }
            
            .table-responsive tbody td:first-child {
                font-weight: 600;
                color: var(--primary-blue);
                border-bottom: 2px solid var(--border-color);
                padding-bottom: 0.5rem;
                margin-bottom: 0.5rem;
                text-align: center;
                padding-left: 0.5rem;
                padding-right: 0.5rem;
                font-size: 0.9rem;
            }
            
            .table-responsive tbody td:first-child:before {
                display: none;
            }
            
            .card-header .row {
                flex-direction: column;
                gap: 0.75rem;
                justify-content: center;
                align-items: center;
            }
            
            .card-header .col-md-6 {
                width: 100%;
                margin-bottom: 0;
                text-align: center;
            }
            
            .card-header .col-md-6:last-child .row {
                flex-direction: column;
                gap: 0.5rem;
                justify-content: center;
                align-items: center;
            }
            
            .card-header .col-md-6:last-child .col-md-6,
            .card-header .col-md-6:last-child .col-md-4,
            .card-header .col-md-6:last-child .col-md-2 {
                width: 100%;
                margin: 0 auto 0.5rem;
                text-align: center;
            }
            
            .card-header .form-label {
                font-size: 0.75rem;
                margin-bottom: 0.25rem;
                text-align: center;
            }
            
            .card-header .form-control,
            .card-header .form-select,
            .card-header input,
            .card-header select {
                text-align: center;
            }
            
            .btn-edit-modern {
                padding: 0.5rem !important;
                font-size: 0 !important;
                line-height: 0 !important;
                min-height: 44px !important;
                min-width: 44px !important;
                width: auto !important;
                margin: 0 auto !important;
                display: flex !important;
                justify-content: center !important;
                align-items: center !important;
                border-radius: 8px;
            }
            
            .btn-edit-modern i {
                font-size: 1rem !important;
                display: inline-block !important;
                margin: 0 !important;
            }
            
            .form-control-sm,
            .btn-sm {
                min-height: 44px;
                font-size: 0.875rem;
                padding: 0.5rem 0.75rem;
            }
            
            .btn-sm {
                padding: 0.5rem 1rem;
            }
            
            #paginationContainer {
                padding: 0.75rem;
                flex-direction: column;
                gap: 0.75rem;
            }
            
            #paginationContainer > div {
                width: 100%;
                text-align: center;
            }
            
            .pagination {
                justify-content: center;
                flex-wrap: wrap;
            }
            
            .pagination .page-link {
                padding: 0.5rem 0.75rem;
                font-size: 0.875rem;
                min-height: 44px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .pagination-info {
                font-size: 0.8rem;
            }
        }
        
        /* Small Devices (landscape phones, 576px and up) */
        @media (min-width: 576px) and (max-width: 767.98px) {
            /* Center all main containers */
            .container-fluid {
                padding-left: 0.75rem !important;
                padding-right: 0.75rem !important;
                margin: 0 auto !important;
            }
            
            .main-content {
                margin: 0 auto !important;
            }
            
            .page-header {
                padding: 0.75rem 0;
                margin-bottom: 1rem;
                text-align: center;
            }
            
            .page-title {
                font-size: 1.25rem;
                text-align: center;
            }
            
            .card-header {
                padding: 1rem;
                text-align: center;
            }
            
            .card-header .row {
                flex-direction: column;
                gap: 0.75rem;
                justify-content: center;
                align-items: center;
            }
            
            .card-header .col-md-6:last-child .row {
                flex-direction: row;
                gap: 0.5rem;
            }
            
            .card-header .col-md-6:last-child .col-md-4 {
                flex: 0 0 auto;
                width: calc(50% - 0.25rem);
            }
            
            .card-header .col-md-6:last-child .col-md-2 {
                flex: 0 0 auto;
                width: 100%;
                margin-top: 0.5rem;
            }
            
            .logs-table-container {
                max-height: none;
                overflow-x: auto;
                overflow-y: visible;
                -webkit-overflow-scrolling: touch;
            }
            
            .layout-faculty .table-responsive {
                width: 100% !important;
                max-width: 100% !important;
                overflow-x: auto !important;
                margin: 0 auto !important;
                padding: 0 !important;
                box-sizing: border-box !important;
            }
            
            .table-responsive thead {
                display: none;
            }
            
            .table-responsive tbody tr {
                display: block;
                margin: 0 auto 1rem;
                border: 1px solid var(--border-color);
                border-radius: var(--radius-md);
                padding: 0.75rem;
                background: var(--white);
                box-shadow: var(--shadow-sm);
                width: 100%;
                max-width: 100%;
            }
            
            .table-responsive tbody td {
                display: block;
                text-align: right;
                padding: 0.5rem;
                position: relative;
                padding-left: 45%;
                border: none;
                border-bottom: 1px solid var(--border-light);
            }
            
            .table-responsive tbody td:last-child {
                border-bottom: none;
            }
            
            .table-responsive tbody td:before {
                content: attr(data-label);
                position: absolute;
                left: 0.5rem;
                width: 42%;
                text-align: left;
                font-weight: 600;
                color: var(--text-medium);
                font-size: 0.8rem;
            }
            
            .table-responsive tbody td:first-child {
                font-weight: 600;
                color: var(--primary-blue);
                border-bottom: 2px solid var(--border-color);
                padding-bottom: 0.5rem;
                margin-bottom: 0.5rem;
                text-align: center;
                padding-left: 0.5rem;
                padding-right: 0.5rem;
            }
            
            .table-responsive tbody td:first-child:before {
                display: none;
            }
            
            .btn-edit-modern {
                padding: 0.625rem 1.25rem;
                font-size: 0.9rem;
                min-height: 44px;
                width: 100%;
                justify-content: center;
            }
            
            .form-control-sm,
            .btn-sm {
                min-height: 44px;
            }
        }
        
        /* Medium Devices (tablets, 768px and up) */
        @media (min-width: 768px) and (max-width: 991.98px) {
            .page-header {
                padding: 1rem 0;
            }
            
            .logs-table-container {
                max-height: 500px;
            }
            
            .table thead th {
                font-size: 0.75rem;
                padding: 0.4rem 0.5rem;
            }
            
            .table tbody td {
                font-size: 0.75rem;
                padding: 0.4rem 0.5rem;
            }
            
            .logs-table-container .btn-edit-modern {
                padding: 0.35rem 0.6rem;
                font-size: 0.7rem;
            }
        }
        
        /* Large Devices (desktops, 992px and up) - Default styles apply */
        
        /* Touch Device Optimizations */
        @media (hover: none) and (pointer: coarse) {
            /* Ensure all interactive elements are touch-friendly */
            .btn,
            .btn-sm,
            .btn-edit-modern,
            .page-link,
            .form-control,
            .form-select,
            input[type="text"],
            input[type="date"],
            input[type="time"],
            select,
            textarea {
                min-height: 44px;
                -webkit-tap-highlight-color: rgba(0, 0, 0, 0.1);
            }
            
            /* Improve button spacing on touch devices */
            .btn + .btn {
                margin-left: 0.5rem;
            }
            
            /* Better modal spacing on touch */
            .modal-content {
                margin: 1rem;
            }
            
            .modal-body {
                padding: 1rem;
            }
            
            .modal-footer {
                padding: 0.75rem 1rem;
            }
            
            .modal-footer .btn {
                min-width: 100px;
            }
        }
        
        /* Landscape Orientation Optimizations */
        @media (max-width: 991.98px) and (orientation: landscape) {
            .logs-table-container {
                max-height: 400px;
            }
            
            .page-header {
                padding: 0.5rem 0;
            }
            
            .card-header {
                padding: 0.75rem;
            }
        }
        
        /* High DPI / Retina Display Optimizations */
        @media (-webkit-min-device-pixel-ratio: 2), (min-resolution: 192dpi) {
            .table-responsive tbody td:before {
                font-weight: 700;
            }
            
            .page-title {
                font-weight: 700;
            }
        }
        
        /* Override padding for pardon modal buttons */
        #editLogModal .modal-footer .btn-secondary[data-bs-dismiss="modal"],
        #editLogModal .modal-footer .btn-secondary[data-bs-dismiss="modal"][data-mobile-fixed="true"],
        #editLogModal .modal-footer #submitPardonBtn,
        #editLogModal .modal-footer #submitPardonBtn[data-mobile-fixed="true"] {
            padding: 0.5rem 1rem !important;
        }
        
        .pardon-cal-grid { user-select: none; }
        .pardon-cal-cell { min-height: 2.25rem; }
        .pardon-cal-pad { visibility: hidden; pointer-events: none; }
        .pardon-cal-no-log { opacity: 0.35; cursor: not-allowed !important; }
        .pardon-cal-adhoc { border-style: dashed !important; opacity: 0.92; }
        .pardon-cal-locked { opacity: 0.45; cursor: not-allowed !important; }
        .pardon-cal-anchor { font-weight: 600; }
        .pardon-cal-weekday-h { text-align: center; font-size: 0.7rem; color: var(--text-medium, #6c757d); font-weight: 600; padding: 0.15rem 0; }
        
        /* Pardon modal: compact, readable layout on phones */
        @media (max-width: 575.98px) {
            #editLogModal .modal-dialog {
                margin: 0.35rem auto;
                max-width: calc(100% - 0.7rem);
                max-height: calc(100vh - 0.7rem);
            }
            #editLogModal .modal-content {
                max-height: calc(100vh - 0.7rem);
                display: flex;
                flex-direction: column;
                border-radius: 0.5rem;
                margin: 0;
            }
            #editLogModal.modal .modal-header {
                padding: 0.45rem 0.65rem;
                flex-shrink: 0;
            }
            #editLogModal .modal-header .btn-close {
                padding: 0.35rem;
                margin: -0.2rem -0.35rem -0.2rem auto;
            }
            #editLogModal .modal-title {
                font-size: 0.95rem;
                font-weight: 600;
                line-height: 1.25;
                padding-right: 0.35rem;
            }
            #editLogModal .modal-body {
                padding: 0.55rem 0.65rem 0.65rem;
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
                flex: 1 1 auto;
                min-height: 0;
            }
            #editLogModal .modal-footer {
                padding: 0.45rem 0.65rem;
                flex-direction: column;
                align-items: stretch;
                gap: 0.35rem;
                flex-shrink: 0;
                border-top: 1px solid var(--bs-border-color, #dee2e6);
            }
            #editLogModal .modal-footer .btn {
                width: 100%;
                margin: 0;
                padding: 0.45rem 0.75rem;
                font-size: 0.875rem;
            }
            #editLogModal .form-label {
                font-size: 0.8125rem;
                margin-bottom: 0.2rem;
            }
            #editLogModal .form-control,
            #editLogModal textarea.form-control {
                padding: 0.4rem 0.5rem;
                font-size: 16px;
            }
            #editLogModal textarea.form-control {
                min-height: 4.25rem;
            }
            #editLogModal #pardonTypeButtons {
                gap: 0.3rem !important;
            }
            #editLogModal .pardon-type-btn {
                flex: 1 1 calc(50% - 0.15rem);
                max-width: calc(50% - 0.15rem);
                font-size: 0.68rem;
                padding: 0.32rem 0.35rem;
                line-height: 1.2;
                white-space: normal;
                word-break: break-word;
                min-height: 2.25rem;
            }
            #editLogModal .pardon-type-hint {
                font-size: 0.7rem !important;
                line-height: 1.3;
                margin-top: 0.25rem !important;
            }
            #editLogModal .alert {
                padding: 0.4rem 0.5rem;
                font-size: 0.78rem;
                margin-bottom: 0.55rem;
                line-height: 1.35;
            }
            #editLogModal .alert i.fa-lg,
            #editLogModal .alert .fa-2x {
                font-size: 1em;
            }
            #editLogModal #pardonMultiDaySection .pardon-cal-cell {
                min-height: 1.55rem;
                font-size: 0.68rem;
                padding: 0.05rem 0.1rem;
            }
            #editLogModal #pardonMultiDaySection .pardon-cal-weekday-h {
                font-size: 0.58rem;
                padding: 0.02rem 0;
            }
            #editLogModal #pardonCalGrid {
                gap: 2px !important;
            }
            #editLogModal #pardonMultiDaySection .d-flex.gap-2 {
                gap: 0.35rem !important;
            }
            #editLogModal .text-muted.small,
            #editLogModal small.text-muted {
                font-size: 0.72rem !important;
                line-height: 1.3;
            }
        }
        
        /* Override padding for all buttons with data-mobile-fixed="true" */
        button.btn.btn-secondary[data-bs-dismiss="modal"][data-mobile-fixed="true"],
        button.btn-secondary[data-bs-dismiss="modal"][data-mobile-fixed="true"] {
            padding: 0.5rem 1rem !important;
        }
        
        /* Pagination Styles */
        #paginationContainer {
            padding: 1rem;
            border-top: 1px solid var(--border-color);
            background: var(--white);
        }
        
        .pagination {
            margin: 0;
        }
        
        .pagination .page-link {
            color: var(--primary-blue);
            border-color: var(--border-color);
            padding: 0.375rem 0.75rem;
            font-size: 0.875rem;
        }
        
        .pagination .page-link:hover {
            background-color: var(--light-blue);
            border-color: var(--primary-blue);
        }
        
        .pagination .page-item.active .page-link {
            background-color: var(--primary-blue);
            border-color: var(--primary-blue);
        }
        
        .pagination .page-item.disabled .page-link {
            color: var(--text-medium);
            background-color: var(--white);
            border-color: var(--border-color);
            cursor: not-allowed;
        }
        
        .pagination-info {
            font-size: 0.875rem;
        }
        
        .pagination-size {
            display: flex;
            align-items: center;
        }
        
        @media (max-width: 767px) {
            #paginationContainer {
                flex-direction: column;
                align-items: stretch !important;
            }
            
            #paginationContainer > div {
                width: 100%;
                margin-bottom: 0.5rem;
            }
            
            #paginationContainer .pagination {
                justify-content: center;
            }
            
            .pagination-size {
                justify-content: center;
            }
        }
        
        /* Override padding for btn-close buttons with data-mobile-fixed */
        .btn-close[data-mobile-fixed="true"],
        button.btn-close[data-bs-dismiss="modal"][data-mobile-fixed="true"],
        .btn-close.btn-close-white[data-mobile-fixed="true"] {
            padding: 0.5rem !important;
        }
        
        /* DTR Modal - Civil Service Form No. 48 (exact match to PDF) */
        #dtrModal .modal-dialog { max-width: 720px; }
        #dtrModal .dtr-form-wrap { font-family: "Times New Roman", Times, serif; color: #000; background: #fff; padding: 1.25rem; border: 1px solid #000; }
        #dtrModal .dtr-form-title { font-size: 1rem; font-weight: 700; text-align: center; margin-bottom: 0.15rem; color: #000; }
        #dtrModal .dtr-form-subtitle { font-size: 0.95rem; font-weight: 700; text-align: center; margin-bottom: 0.15rem; color: #000; letter-spacing: 0.02em; }
        #dtrModal .dtr-form-line { text-align: center; font-size: 0.8rem; letter-spacing: 0.2em; margin-bottom: 0.75rem; color: #000; }
        #dtrModal .dtr-field-row { font-size: 0.9rem; margin-bottom: 0.35rem; color: #000; }
        #dtrModal .dtr-field-inline { display: inline-block; border-bottom: 1px solid #000; min-width: 180px; margin-left: 0.35rem; padding: 0 0.25rem 0.1rem; font-size: 0.9rem; }
        #dtrModal .dtr-official-row { font-size: 0.9rem; margin-bottom: 0.5rem; color: #000; }
        #dtrModal .dtr-official-row .dtr-field-inline { min-width: 80px; }
        #dtrModal .dtr-table { font-size: 0.8rem; table-layout: fixed; width: 100%; border-collapse: collapse; color: #000; }
        #dtrModal .dtr-table th, #dtrModal .dtr-table td { padding: 0.2rem 0.25rem; vertical-align: middle; border: 1px solid #000; }
        #dtrModal .dtr-table th { background: #fff; font-weight: 700; text-align: center; }
        #dtrModal .dtr-table .dtr-day { width: 2.25em; text-align: center; }
        #dtrModal .dtr-table .dtr-time { width: 4em; text-align: center; }
        #dtrModal .dtr-table .dtr-undertime { width: 2.75em; text-align: center; }
        #dtrModal .dtr-table tbody tr.dtr-total { font-weight: 700; }
        #dtrModal .dtr-certify { font-size: 0.8rem; margin-top: 0.75rem; margin-bottom: 0.25rem; line-height: 1.35; color: #000; }
        #dtrModal .dtr-verified { font-size: 0.8rem; margin-top: 1rem; margin-bottom: 0; color: #000; }
        #dtrModal .dtr-verified .dtr-incharge { display: block; font-weight: 700; margin-top: 0.25rem; }
        #dtrModal .dtr-verified .dtr-incharge:empty { font-weight: normal; border-bottom: 1px solid #000; min-width: 200px; }
        #dtrModal .dtr-loading-overlay { position: absolute; inset: 0; background: rgba(255,255,255,0.9); display: flex; flex-direction: column; align-items: center; justify-content: center; z-index: 10; border-radius: inherit; }
        #dtrModal .dtr-table-wrap { position: relative; }
        #dtrModal #dtrHolidayWeekNotice { font-size: 0.8rem; border-left: 4px solid #0d6efd; }
        #viewLogsHolidayWeekBanner { font-size: 0.875rem; line-height: 1.35; }
        #viewLogsHolidayWeekBanner .fa-calendar-week { font-size: 1.1rem; }
        .dtr-scroll-hint { font-size: 0.75rem; color: #64748b; margin-top: 0.5rem; }
        @media (max-width: 767px) {
            #dtrModal .modal-dialog { max-width: 100%; margin: 0.5rem; width: calc(100% - 1rem); }
            #dtrModal .dtr-table-wrap { overflow-x: auto; }
            #dtrModal .dtr-table { font-size: 0.7rem; min-width: 420px; }
            .dtr-scroll-hint { display: block; }
        }
        
        /* Holiday rows in attendance log */
        #logsTableBody tr.dtr-row-holiday { background-color: #ffdddd !important; }
        #logsTableBody tr.dtr-row-holiday:hover { background-color: #ffcccc !important; }
        /* Half-day holiday (matches admin Employees DTR) */
        #logsTableBody tr.dtr-row-half-day-holiday { background-color: #fff3e0 !important; }
        #logsTableBody tr.dtr-row-half-day-holiday:hover { background-color: #ffe0b2 !important; }
        /* Red highlight when employee came in on a holiday */
        #logsTableBody tr.dtr-row-holiday-attendance { background-color: #f8d7da !important; }
        #logsTableBody tr.dtr-row-holiday-attendance:hover { background-color: #f5c6cb !important; }
        
        /* Pardon modal: tablet width (desktop uses Bootstrap modal-lg) */
        @media (max-width: 991.98px) {
            #editLogModal .modal-dialog {
                max-width: min(800px, calc(100vw - 1rem));
                margin-left: auto;
                margin-right: auto;
            }
        }
        
        /* View Official Times Modal - mobile-first overrides for user-friendly UX */
        @media (max-width: 767.98px) {
            #viewOfficialTimesModal .modal-dialog {
                max-width: none;
                width: calc(100vw - 1rem);
                margin: 0.5rem auto;
                max-height: calc(100vh - 1rem);
            }
            
            #viewOfficialTimesModal .modal-content {
                max-height: calc(100vh - 1rem);
                border-radius: 12px;
            }
            
            #viewOfficialTimesModal .official-times-modal-header {
                padding: 1rem 1rem 1rem 1.25rem;
                flex-shrink: 0;
            }
            
            #viewOfficialTimesModal .modal-title {
                font-size: 1.2rem;
            }
            
            /* Larger touch target for close (X) */
            #viewOfficialTimesModal .official-times-modal-close {
                padding: 0.75rem;
                margin: -0.5rem -0.5rem -0.5rem auto;
                min-width: 44px;
                min-height: 44px;
            }
            
            #viewOfficialTimesModal .official-times-modal-body {
                padding: 1rem 1.25rem;
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            /* Hide table header on mobile - we use data-label on cells instead */
            #viewOfficialTimesModal .official-times-thead {
                display: none;
            }
            
            #viewOfficialTimesModal .official-times-table-wrap {
                overflow: visible;
            }
            
            #viewOfficialTimesModal .official-times-table {
                font-size: 0.9375rem;
            }
            
            #viewOfficialTimesModal .official-times-table tbody tr {
                display: block;
                margin-bottom: 1rem;
                border: 1px solid var(--border-color, #dee2e6);
                border-radius: 10px;
                overflow: hidden;
                box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            }
            
            #viewOfficialTimesModal .official-times-table tbody tr:last-child {
                margin-bottom: 0;
            }
            
            #viewOfficialTimesModal .official-times-table tbody td {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 0.75rem;
                padding: 0.65rem 1rem;
                border: none;
                border-bottom: 1px solid rgba(0,0,0,0.06);
                font-size: 0.9375rem;
            }
            
            #viewOfficialTimesModal .official-times-table tbody tr td:last-child {
                border-bottom: none;
            }
            
            #viewOfficialTimesModal .official-times-table tbody td::before {
                content: attr(data-label);
                font-weight: 600;
                color: var(--text-medium, #6c757d);
                flex: 0 0 auto;
                min-width: 0;
                max-width: 42%;
                font-size: 0.875rem;
            }
            
            /* Value stays in its own space and wraps - prevents overlap with label */
            #viewOfficialTimesModal .official-times-table tbody td .official-times-value {
                flex: 1 1 auto;
                min-width: 0;
                text-align: right;
                overflow-wrap: break-word;
                word-break: break-word;
            }
            
            #viewOfficialTimesModal .official-times-table tbody td:first-child {
                background: rgba(0, 51, 102, 0.06);
                font-weight: 600;
            }
            
            #viewOfficialTimesModal .official-times-close-btn {
                min-height: 44px;
                padding: 0.5rem 1.25rem;
                font-size: 1rem;
            }
            
            #viewOfficialTimesModal .official-times-modal-footer {
                padding: 1rem 1.25rem;
                flex-shrink: 0;
            }
            
            #viewOfficialTimesModal .official-times-loading p,
            #viewOfficialTimesModal .official-times-empty {
                font-size: 1rem;
            }
            
            #viewOfficialTimesModal .official-times-empty {
                padding: 1.5rem 0.5rem !important;
            }
            
        }
        
        /* Extra small devices - full bleed with safe area */
        @media (max-width: 575.98px) {
            #viewOfficialTimesModal .modal-dialog {
                width: calc(100vw - 0.5rem);
                margin: 0.25rem auto;
                max-height: calc(100vh - 0.5rem);
            }
            
            #viewOfficialTimesModal .modal-content {
                max-height: calc(100vh - 0.5rem);
            }
        }
        
        /* Notification Modal Responsive */
        @media (max-width: 575.98px) {
            #notificationModal .modal-dialog {
                max-width: 90vw;
                width: 90vw;
                margin: 1rem auto;
            }
            
            #notificationModal .modal-header,
            #notificationModal .modal-body,
            #notificationModal .modal-footer {
                padding: 1rem;
            }
            
            #notificationModal .notification-icon {
                font-size: 2.5rem;
            }
            
            #notificationModal .notification-title {
                font-size: 1.1rem;
            }
            
            #notificationModal .notification-message {
                font-size: 0.9rem;
            }
        }
        
        /* Notification Modal Styles */
        #notificationModal .modal-content {
            border-radius: 12px;
            border: none;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }
        
        #notificationModal .modal-header {
            border-bottom: none;
            padding: 1.5rem 1.5rem 0.5rem;
        }
        
        #notificationModal .modal-body {
            padding: 1rem 1.5rem 1.5rem;
            text-align: center;
        }
        
        #notificationModal .modal-footer {
            border-top: none;
            padding: 0.5rem 1.5rem 1.5rem;
            justify-content: center;
        }
        
        #notificationModal .notification-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        
        #notificationModal .notification-icon.success {
            color: #28a745;
        }
        
        #notificationModal .notification-icon.error {
            color: #dc3545;
        }
        
        #notificationModal .notification-icon.warning {
            color: #ffc107;
        }
        
        #notificationModal .notification-icon.info {
            color: #17a2b8;
        }
        
        #notificationModal .notification-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        #notificationModal .notification-message {
            font-size: 1rem;
            color: #6c757d;
            margin: 0;
        }
        
        #notificationModal .btn-close {
            display: none;
        }
        
        /* Override padding for notification modal OK button */
        #notificationModal #notificationOkBtn,
        #notificationModal #notificationOkBtn[data-mobile-fixed="true"] {
            padding: 0.5rem 1rem !important;
        }
        
        /* Container and Layout Improvements for Mobile */
        @media (max-width: 767.98px) {
            .container-fluid {
                padding-left: 0.75rem;
                padding-right: 0.75rem;
            }
            
            .main-content {
                padding: 0;
            }
            
            /* Improve badge display on mobile */
            .badge {
                font-size: 0.7rem;
                padding: 0.25rem 0.5rem;
                white-space: nowrap;
            }
            
            /* Better spacing for filter section */
            .card-header .row.g-2 {
                margin: 0;
            }
            
            .card-header .row.g-2 > * {
                padding: 0;
            }
        }
        
        /* Fix modal backdrop blocking modal dialog on mobile */
        @media (max-width: 991px) {
            /* Ensure modal backdrop stays below modal dialog */
            #viewOfficialTimesModal.modal.show ~ .modal-backdrop,
            body.modal-open .modal-backdrop.show {
                z-index: 1040 !important;
            }
            
            /* Ensure modal dialog and content are above backdrop */
            #viewOfficialTimesModal.modal.show {
                z-index: 1055 !important;
            }
            
            #viewOfficialTimesModal.modal.show .modal-dialog {
                z-index: 1056 !important;
                position: relative;
            }
            
            #viewOfficialTimesModal.modal.show .modal-content {
                z-index: 1057 !important;
                position: relative;
                pointer-events: auto !important;
            }
            
            /* Ensure modal buttons are clickable */
            #viewOfficialTimesModal.modal.show .modal-content .btn,
            #viewOfficialTimesModal.modal.show .modal-content button,
            #viewOfficialTimesModal.modal.show .modal-content .btn-close {
                pointer-events: auto !important;
                position: relative;
                z-index: 1058 !important;
            }
            
            /* Fix all modals on mobile */
            .modal.show {
                z-index: 1055 !important;
            }
            
            .modal.show .modal-dialog {
                z-index: 1056 !important;
                position: relative;
            }
            
            .modal.show .modal-content {
                z-index: 1057 !important;
                position: relative;
                pointer-events: auto !important;
            }
            
            .modal.show .modal-content .btn,
            .modal.show .modal-content button,
            .modal.show .modal-content .btn-close {
                pointer-events: auto !important;
                position: relative;
                z-index: 1058 !important;
            }
        }
        
        /* Prevent horizontal scroll on mobile - but allow table to scroll so all columns visible */
        @media (max-width: 767.98px) {
            body {
                overflow-x: hidden;
            }
            
            .layout-faculty .table-responsive {
                width: 100% !important;
                max-width: 100% !important;
                overflow-x: auto !important;
                margin: 0 !important;
                padding: 0 !important;
                box-sizing: border-box !important;
                -webkit-overflow-scrolling: touch;
            }
            
            .container-fluid,
            .main-content,
            .card,
            .card-body {
                max-width: 100%;
                overflow-x: hidden;
            }
            
            .logs-table-container {
                overflow-x: auto !important;
                max-width: 100%;
            }
        }
        
        /* Improve text readability on small screens */
        @media (max-width: 575.98px) {
            body {
                font-size: 14px;
            }
            
            .small,
            small {
                font-size: 0.75rem;
            }
            
            .text-muted {
                font-size: 0.8rem;
            }
        }
        
        /* Loading state improvements for mobile */
        @media (max-width: 767.98px) {
            .table-responsive tbody tr td[colspan] {
                padding: 2rem 1rem !important;
                font-size: 0.9rem;
            }
        }
        
        /* Additional Mobile Optimizations */
        @media (max-width: 767.98px) {
            /* Improve spacing between elements */
            .card {
                margin-bottom: 1rem;
            }
            
            /* Better button grouping on mobile */
            .btn-group,
            .btn-group-vertical {
                width: 100%;
            }
            
            .btn-group .btn,
            .btn-group-vertical .btn {
                flex: 1;
            }
            
            /* Improve form input spacing */
            .mb-3 {
                margin-bottom: 1rem !important;
            }
            
            /* Better badge wrapping */
            .badge.ms-2 {
                margin-left: 0 !important;
                margin-top: 0.25rem;
                display: inline-block;
            }
            
            /* Improve icon spacing in titles */
            .page-title i.me-2,
            .card-header h5 i.me-2 {
                margin-right: 0.5rem;
            }
            
            /* Ensure proper alignment for action buttons in table */
            .table-responsive tbody td[data-label="Actions"] {
                text-align: center !important;
                padding: 0.5rem !important;
                display: flex !important;
                justify-content: center !important;
                align-items: center !important;
            }
            
            .table-responsive tbody td[data-label="Actions"]:before {
                display: none;
            }
            
            /* Make btn-edit-modern icon-only on mobile */
            .btn-edit-modern {
                width: auto !important;
                min-width: 44px !important;
                padding: 0.5rem !important;
                margin: 0 auto !important;
                display: flex !important;
                justify-content: center !important;
                align-items: center !important;
            }
            
            .btn-edit-modern i {
                margin-right: 0 !important;
            }
            
            /* Hide text in btn-edit-modern on mobile, show only icon */
            .btn-edit-modern {
                font-size: 0 !important;
                line-height: 0 !important;
            }
            
            .btn-edit-modern i {
                font-size: 1rem !important;
                display: inline-block !important;
                margin: 0 !important;
            }
            
            /* Improve status badge display */
            .table-responsive tbody td[data-label="Status"] {
                text-align: center !important;
            }
            
            .table-responsive tbody td[data-label="Status"]:before {
                display: none;
            }
            
            .table-responsive tbody td[data-label="Status"] .badge {
                display: inline-block;
                margin: 0.25rem auto;
            }
        }
        
        /* Very Small Screens (320px and below) */
        @media (max-width: 320px) {
            .page-title {
                font-size: 1rem;
            }
            
            .card-header h5 {
                font-size: 0.85rem;
            }
            
            .table-responsive tbody td {
                padding-left: 50%;
                font-size: 0.8rem;
            }
            
            .table-responsive tbody td:before {
                width: 48%;
                font-size: 0.7rem;
            }
            
            .btn-edit-modern {
                font-size: 0.8rem;
                padding: 0.625rem 0.875rem;
            }
        }
        
        /* Print Styles - Hide on print for mobile */
        @media print {
            .btn,
            .btn-edit-modern,
            .card-header .btn,
            #paginationContainer {
                display: none !important;
            }
        }
    </style>
</head>
<body class="layout-faculty">
    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <div class="page-header">
                    <h1 class="page-title">
                        <i class="fas fa-clipboard-list me-2"></i>
                        Employee Daily Time Record
                    </h1>
                </div>

                <?php displayMessage(); ?>

                <div class="card">
                    <div class="card-header">
                        <div class="row align-items-center">
                            <div class="col-md-6 mb-2 mb-md-0">
                                <h5 class="mb-0">
                                    <i class="fas fa-user me-2"></i>
                                    <?php echo htmlspecialchars($fullName); ?>
                                    <span class="badge bg-primary ms-2"><?php echo htmlspecialchars($employee_id); ?></span>
                                </h5>
                            </div>
                            <div class="col-md-6">
                                <div class="row g-2">
                                    <div class="col-md-2 d-flex align-items-end">
                                        <button onclick="viewOfficialTimes()" class="btn btn-info btn-sm" title="View Official Time" aria-label="View Official Time">
                                            <i class="fas fa-clock me-1"></i> Official Time
                                        </button>
                                    </div>
                                    <div class="col-md-2 d-flex align-items-end">
                                        <button onclick="openDTRModal()" class="btn btn-outline-primary btn-sm w-100">
                                            <i class="fas fa-file-alt me-1"></i> DTR
                                        </button>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small mb-1">Search</label>
                                        <input type="text" id="searchLogs" placeholder="Search date/time..." class="form-control form-control-sm" onkeyup="filterLogs()">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small mb-1">Date From</label>
                                        <input type="date" id="filterDateFrom" class="form-control form-control-sm" value="<?php echo date('Y-m-01'); ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small mb-1">Date To</label>
                                        <input type="date" id="filterDateTo" class="form-control form-control-sm" value="<?php echo date('Y-m-t'); ?>">
                                    </div>
                                    <div class="col-md-2 d-flex align-items-end gap-1">
                                        <button onclick="loadLogs()" class="btn btn-primary btn-sm flex-grow-1">Load</button>
                                        <button onclick="resetFilters()" class="btn btn-secondary btn-sm">Reset</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div id="viewLogsHolidayWeekBanner" class="alert alert-primary border-primary border-start border-4 py-3 px-3 mb-3 d-none shadow-sm" role="status">
                            <div class="d-flex align-items-start gap-2 flex-wrap flex-md-nowrap">
                                <span class="text-primary flex-shrink-0 mt-1"><i class="fas fa-calendar-week" aria-hidden="true"></i></span>
                                <div>
                                    <strong>Holiday week:</strong> this calendar week (Sun–Sat) includes a university holiday and has been switched to an <strong>8-hour reference day</strong> (08:00–12:00, 13:00–17:00).
                                    Late, undertime, and related totals on this page use that schedule for affected dates—not only your stored official-times pattern.
                                    <span id="viewLogsHolidayWeekBannerSub" class="d-block small mt-2 mb-0 opacity-90"></span>
                                </div>
                            </div>
                        </div>
                        <div class="table-responsive logs-table-container">
                            <table class="table table-sm table-hover">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th>Log Date</th>
                                        <th>Time In</th>
                                        <th>Lunch Out</th>
                                        <th>Lunch In</th>
                                        <th>Time Out</th>
                                        <th>Hours</th>
                                        <th>Late (Hrs, Days)</th>
                                        <th>Undertime (Hrs, Days)</th>
                                        <th>Absent (Hrs, Days)</th>
                                        <th>OT IN</th>
                                        <th>OT OUT</th>
                                        <th>Status</th>
                                        <th>DTR Submit</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="logsTableBody">
                                    <tr>
                                        <td colspan="14" class="text-center text-muted py-4" style="display: block; padding-left: 0.5rem !important;">Loading...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <!-- Pagination Controls -->
                        <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2" id="paginationContainer" style="display: none !important;">
                            <div class="pagination-info">
                                <small class="text-muted" id="paginationInfo">Showing 0-0 of 0</small>
                            </div>
                            <nav aria-label="Logs pagination">
                                <ul class="pagination pagination-sm mb-0" id="paginationControls">
                                    <!-- Pagination buttons will be generated here -->
                                </ul>
                            </nav>
                            <div class="pagination-size" style="display: none;">
                                <label class="form-label small mb-0 me-2">Rows per page:</label>
                                <select id="rowsPerPage" class="form-select form-select-sm d-inline-block" style="width: auto;">
                                    <option value="10" selected>10</option>
                                </select>
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
        // Suppress 403/401 errors from notifications and chat APIs in console (run early, before other scripts)
        (function() {
            const originalError = console.error;
            const originalWarn = console.warn;
            
            console.error = function(...args) {
                const message = args.join(' ').toLowerCase();
                // Suppress 403/401 errors from API calls
                if (message.includes('403') || message.includes('forbidden') ||
                    message.includes('401') || message.includes('unauthorized') ||
                    (message.includes('fetch failed') && (message.includes('notifications_api') || message.includes('chat_api'))) ||
                    (message.includes('error loading conversations') && (message.includes('403') || message.includes('forbidden'))) ||
                    (message.includes('error checking notifications') && (message.includes('403') || message.includes('forbidden'))) ||
                    (message.includes('network response was not ok') && (message.includes('403') || message.includes('forbidden')))) {
                    return; // Don't log these errors
                }
                originalError.apply(console, args);
            };
            
            // Also suppress warnings for 403 errors
            console.warn = function(...args) {
                const message = args.join(' ').toLowerCase();
                if (message.includes('403') || message.includes('forbidden') ||
                    message.includes('401') || message.includes('unauthorized')) {
                    return; // Don't log these warnings
                }
                originalWarn.apply(console, args);
            };
        })();
        
        const employeeId = '<?php echo htmlspecialchars($employee_id, ENT_QUOTES); ?>';
        const fullName = '<?php echo htmlspecialchars($fullName, ENT_QUOTES); ?>';
        
        // Default official times (fallback)
        const DEFAULT_OFFICIAL_TIMES = {
            time_in: '08:00:00',
            lunch_out: '12:00:00',
            lunch_in: '13:00:00',
            time_out: '17:00:00'
        };
        
        // Cache for employee official times
        const employeeOfficialTimesCache = {};
        
        // Pagination variables
        let allLogs = [];
        let filteredLogs = [];
        let currentPage = 1;
        let dtrSubmittedDates = {};
        let dtrCanSubmitDates = [];
        let rowsPerPage = 10;

        const PARDON_TYPES_CALENDAR = ['tarf_ntarf', 'work_from_home', 'vacation_leave', 'sick_leave', 'special_privilege_leave', 'forced_mandatory_leave', 'special_emergency_leave', 'maternity_leave', 'solo_parent_leave', 'magna_carta_leave', 'rehabilitation_leave', 'wellness_leave'];
        
        // Helper function to get weekday name from date
        function getWeekdayName(dateStr) {
            const date = new Date(dateStr + 'T00:00:00');
            const weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            return weekdays[date.getDay()];
        }
        
        // Convert minutes to hh:mm:ss format
        function minutesToTimeFormat(totalMinutes) {
            if (totalMinutes <= 0) return '00:00:00';
            const hours = Math.floor(totalMinutes / 60);
            const minutes = Math.floor(totalMinutes % 60);
            const seconds = Math.floor((totalMinutes % 1) * 60);
            return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
        }

        function hoursToDayFraction(hours) {
            if (!hours || hours <= 0) return 0;
            return Math.round((hours / 8) * 1000) / 1000;
        }
        
        // Get official times for an employee for a specific date (based on weekday)
        async function getOfficialTimesForDate(logDate) {
            if (!employeeId || !logDate) {
                return { found: false, holidayWeekEightHour: false, times: DEFAULT_OFFICIAL_TIMES };
            }
            
            const weekday = getWeekdayName(logDate);
            const cacheKey = `${employeeId}_${logDate}_${weekday}`;
            
            // Check cache first
            if (employeeOfficialTimesCache[cacheKey]) {
                return employeeOfficialTimesCache[cacheKey];
            }
            
            try {
                // Get official times that apply to this date and weekday
                const response = await fetch(`../admin/manage_official_times_api.php?action=get_by_date&employee_id=${encodeURIComponent(employeeId)}&date=${encodeURIComponent(logDate)}&weekday=${encodeURIComponent(weekday)}`, { credentials: 'same-origin' });
                const data = await response.json();
                
                // Debug logging
                if (!data.success) {
                    console.error('Error fetching official times:', data.message || 'Unknown error', {
                        employeeId,
                        logDate,
                        weekday,
                        response: data
                    });
                }
                
                if (data.success && data.official_time && data.official_time.found) {
                    const hasLunch = !!(data.official_time.lunch_out && data.official_time.lunch_in);
                    const hw = !!(data.official_time && data.official_time.holiday_week_eight_hour);
                    const officialTimes = {
                        found: true,
                        weekday: weekday,
                        hasLunch: hasLunch,
                        holidayWeekEightHour: hw,
                        times: {
                            time_in: data.official_time.time_in + ':00',
                            lunch_out: hasLunch ? (data.official_time.lunch_out + ':00') : null,
                            lunch_in: hasLunch ? (data.official_time.lunch_in + ':00') : null,
                            time_out: data.official_time.time_out + ':00'
                        }
                    };
                    
                    // Cache it
                    employeeOfficialTimesCache[cacheKey] = officialTimes;
                    return officialTimes;
                } else {
                    // No official time found (API may still report holiday_week_eight_hour on overlay path)
                    const hw = !!(data.success && data.official_time && data.official_time.holiday_week_eight_hour);
                    const result = {
                        found: false,
                        weekday: weekday,
                        holidayWeekEightHour: hw,
                        times: DEFAULT_OFFICIAL_TIMES
                    };
                    employeeOfficialTimesCache[cacheKey] = result;
                    return result;
                }
            } catch (error) {
                console.error('Error fetching official times:', error);
                return { found: false, holidayWeekEightHour: false, times: DEFAULT_OFFICIAL_TIMES };
            }
        }
        
        // DTR daily submission - submit each day's DTR the next day or after
        function loadDTRStatus() {
            const el = document.getElementById('dtrSubmissionStatus');
            if (!el) return;
            const monthSelect = document.getElementById('dtrMonth');
            const yearSelect = document.getElementById('dtrYear');
            const year = yearSelect ? parseInt(yearSelect.value, 10) : new Date().getFullYear();
            const month = monthSelect ? parseInt(monthSelect.value, 10) : new Date().getMonth() + 1;
            const dateFrom = year + '-' + String(month).padStart(2, '0') + '-01';
            const lastDay = new Date(year, month, 0).getDate();
            const dateTo = year + '-' + String(month).padStart(2, '0') + '-' + String(lastDay).padStart(2, '0');
            fetch('submit_dtr_api.php?date_from=' + encodeURIComponent(dateFrom) + '&date_to=' + encodeURIComponent(dateTo), { credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        el.innerHTML = '<span class="text-muted small">' + (data.message || 'Unable to load DTR status.') + '</span>';
                        return;
                    }
                    const submitted = data.submitted || {};
                    const canSubmit = data.canSubmit || [];
                    let html = '<span class="text-muted small d-block mb-1">' + (data.policy || 'Submit each day\'s DTR the next day or after.') + '</span>';
                    if (canSubmit.length > 0) {
                        const today = new Date();
                        today.setHours(0, 0, 0, 0);
                        const yesterday = new Date(today);
                        yesterday.setDate(yesterday.getDate() - 1);
                        const yesterdayStr = yesterday.getFullYear() + '-' + String(yesterday.getMonth() + 1).padStart(2, '0') + '-' + String(yesterday.getDate()).padStart(2, '0');
                        html += '<div class="d-flex flex-wrap gap-1">';
                        canSubmit.slice(0, 10).forEach(function(d) {
                            const label = new Date(d + 'T12:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                            const btnText = d === yesterdayStr ? 'Submit (' + label + ')' : 'Submit Late (' + label + ')';
                            html += '<button type="button" class="btn btn-sm btn-primary" onclick="submitDTRDate(\'' + d + '\', this)"><i class="fas fa-paper-plane me-1"></i>' + btnText + '</button>';
                        });
                        if (canSubmit.length > 10) html += '<span class="badge bg-secondary align-self-center">+' + (canSubmit.length - 10) + ' more</span>';
                        html += '</div>';
                    }
                    const submittedCount = Object.keys(submitted).length;
                    if (submittedCount > 0) {
                        html += '<span class="text-muted small d-block mt-1">' + submittedCount + ' day(s) submitted this month.</span>';
                    }
                    if (canSubmit.length === 0 && submittedCount === 0) {
                        html += '<span class="text-muted small">No dates available to submit for this month.</span>';
                    }
                    el.innerHTML = html;
                })
                .catch(function() {
                    el.innerHTML = '<span class="text-muted small">Could not load DTR status.</span>';
                });
        }
        function submitDTRDate(logDate, btnEl) {
            const form = new FormData();
            form.append('log_date', logDate);
            const btn = btnEl || document.querySelector('button[onclick*="submitDTRDate(\'' + logDate + '\'"]');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Submitting...';
            }
            fetch('submit_dtr_api.php', { method: 'POST', body: form, credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        if (typeof showNotification === 'function') {
                            showNotification('success', 'Success', data.message || 'DTR submitted to Dean and Admin.');
                        } else {
                            alert(data.message || 'DTR submitted successfully.');
                        }
                        dtrSubmittedDates[logDate] = data.submittedAt || new Date().toISOString();
                        dtrCanSubmitDates = dtrCanSubmitDates.filter(d => d !== logDate);
                        loadDTRStatus();
                        renderLogsPage();
                    } else {
                        if (typeof showNotification === 'function') {
                            showNotification('error', 'Error', data.message || 'Submission failed.');
                        } else {
                            alert(data.message || 'Submission failed.');
                        }
                        const isYesterday = (() => {
                            const y = new Date(); y.setHours(0, 0, 0, 0);
                            const yd = new Date(y); yd.setDate(yd.getDate() - 1);
                            const d = new Date(logDate + 'T12:00:00'); d.setHours(0, 0, 0, 0);
                            return d.getTime() === yd.getTime();
                        })();
                        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane me-1"></i>' + (isYesterday ? 'Submit' : 'Submit Late'); }
                    }
                })
                .catch(function() {
                    if (typeof showNotification === 'function') {
                        showNotification('error', 'Error', 'Network error. Please try again.');
                    } else {
                        alert('Network error. Please try again.');
                    }
                    const isYesterday = (() => {
                        const y = new Date(); y.setHours(0, 0, 0, 0);
                        const yd = new Date(y); yd.setDate(yd.getDate() - 1);
                        const d = new Date(logDate + 'T12:00:00'); d.setHours(0, 0, 0, 0);
                        return d.getTime() === yd.getTime();
                    })();
                    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane me-1"></i>' + (isYesterday ? 'Submit' : 'Submit Late'); }
                });
        }

        // Load logs on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadLogs();
            
            // Add event listener for rows per page change
            const rowsPerPageSelect = document.getElementById('rowsPerPage');
            if (rowsPerPageSelect) {
                rowsPerPageSelect.addEventListener('change', function() {
                    rowsPerPage = parseInt(this.value);
                    currentPage = 1;
                    renderLogsPage();
                });
            }
        });
        
        function updateMainPageHolidayWeekBanner(data) {
            const banner = document.getElementById('viewLogsHolidayWeekBanner');
            const sub = document.getElementById('viewLogsHolidayWeekBannerSub');
            if (!banner) return;
            if (data && data.success && data.holiday_week_eight_hour_active) {
                banner.classList.remove('d-none');
                if (sub) {
                    var display = (data.holiday_week_eight_hour_week_end_display || '').trim();
                    sub.textContent = display
                        ? ('This week\'s policy window ends Saturday, ' + display + '. After that week, totals use your saved official times again.')
                        : 'This applies for the current calendar week only.';
                }
            } else {
                banner.classList.add('d-none');
                if (sub) sub.textContent = '';
            }
        }

        function loadLogs() {
            const tbody = document.getElementById('logsTableBody');
            if (!tbody) {
                console.error('logsTableBody element not found');
                return;
            }
            tbody.innerHTML = '<tr><td colspan="14" class="text-center text-muted py-4" style="display: block; padding-left: 0.5rem !important;">Loading...</td></tr>';
            updateMainPageHolidayWeekBanner({ success: false });
            
            
            // Fetch logs from API (with date range from filter)
            const dateFrom = document.getElementById('filterDateFrom') ? document.getElementById('filterDateFrom').value : '';
            const dateTo = document.getElementById('filterDateTo') ? document.getElementById('filterDateTo').value : '';
            let url = 'fetch_my_logs_api.php?employee_id=' + encodeURIComponent(employeeId) + '&simple=1';
            if (dateFrom) url += '&date_from=' + encodeURIComponent(dateFrom);
            if (dateTo) url += '&date_to=' + encodeURIComponent(dateTo);
            fetch(url, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(response => {
                    if (!response.ok) {
                        return response.json()
                            .then(data => {
                                const msg = response.status === 403
                                    ? (data.message || 'Access denied or session expired. Please refresh the page or log in again.')
                                    : (data.message || 'Failed to load logs');
                                throw new Error(msg);
                            })
                            .catch(e => { if (e instanceof SyntaxError) throw new Error(response.status === 403 ? 'Access denied or session expired. Please refresh the page or log in again.' : 'Failed to load logs'); throw e; });
                    }
                    return response.json();
                })
                .then(data => {
                    tbody.innerHTML = '';
                    updateMainPageHolidayWeekBanner(data || {});
                    
                    if (data.success && data.logs && data.logs.length > 0) {
                        // Store all logs
                        allLogs = data.logs;
                        filteredLogs = [...allLogs];
                        currentPage = 1;
                        
                        // Fetch DTR submission status for date range of logs
                        const dates = allLogs.map(l => l.log_date).filter(Boolean);
                        if (dates.length > 0) {
                            const dateFrom = dates.reduce((a, b) => a < b ? a : b);
                            const dateTo = dates.reduce((a, b) => a > b ? a : b);
                            fetch('submit_dtr_api.php?date_from=' + encodeURIComponent(dateFrom) + '&date_to=' + encodeURIComponent(dateTo), { credentials: 'same-origin' })
                                .then(r => r.json())
                                .then(dtrData => {
                                    if (dtrData.success) {
                                        dtrSubmittedDates = dtrData.submitted || {};
                                        dtrCanSubmitDates = dtrData.canSubmit || [];
                                    }
                                    renderLogsPage();
                                })
                                .catch(() => renderLogsPage());
                        } else {
                            renderLogsPage();
                        }
                    } else {
                        tbody.innerHTML = '<tr><td colspan="14" class="text-center text-muted py-4" style="display: block; padding-left: 0.5rem !important;">No attendance logs found</td></tr>';
                        document.getElementById('paginationContainer').style.display = 'none';
                    }
                })
                .catch(error => {
                    console.error('Error fetching logs:', error);
                    updateMainPageHolidayWeekBanner({});
                    const msg = error.message || 'Error loading logs. Please try again.';
                    tbody.innerHTML = '<tr><td colspan="14" class="text-center text-danger py-4" style="display: block; padding-left: 0.5rem !important;">' + msg + '</td></tr>';
                    document.getElementById('paginationContainer').style.display = 'none';
                });
        }
        
        async function renderLogsPage() {
            const tbody = document.getElementById('logsTableBody');
            if (!tbody) return;
            
            tbody.innerHTML = '';
            
            if (filteredLogs.length === 0) {
                tbody.innerHTML = '<tr><td colspan="14" class="text-center text-muted py-4" style="display: block; padding-left: 0.5rem !important;">No logs found</td></tr>';
                document.getElementById('paginationContainer').style.display = 'none';
                return;
            }
            
            // Calculate pagination
            const totalPages = Math.ceil(filteredLogs.length / rowsPerPage);
            const startIndex = (currentPage - 1) * rowsPerPage;
            const endIndex = Math.min(startIndex + rowsPerPage, filteredLogs.length);
            const pageLogs = filteredLogs.slice(startIndex, endIndex);
            
            // Update pagination info
            document.getElementById('paginationInfo').textContent = `Showing ${startIndex + 1}-${endIndex} of ${filteredLogs.length}`;
            
            // Show pagination if there are logs (so user can change rows per page)
            if (filteredLogs.length > 0) {
                document.getElementById('paginationContainer').style.display = 'flex';
                renderPaginationControls(totalPages);
            } else {
                document.getElementById('paginationContainer').style.display = 'none';
            }
            
            // Accumulators for totals row
            let totalHours = 0;
            let totalLateMinutes = 0;
            let totalUndertimeMinutes = 0;
            let totalAbsentHours = 0;
            
            // Render logs for current page
            for (const log of pageLogs) {
                try {
                    const remarksHalfDay = ((log.remarks || '').trim().indexOf('Holiday (Half-day') === 0);
                    const isHalfDayHolidayRow = Number(log.holiday_is_half_day) === 1 || remarksHalfDay;
                    const row = document.createElement('tr');
                    if (log.has_holiday_attendance) {
                        row.classList.add('dtr-row-holiday-attendance');
                    } else if (log.is_holiday) {
                        if (isHalfDayHolidayRow) {
                            row.classList.add('dtr-row-half-day-holiday');
                        } else {
                            row.classList.add('dtr-row-holiday');
                        }
                    }
                    // Format date: MONTH/DAY/YEAR/DATE (weekday)
                    let logDate = '-';
                    if (log.log_date) {
                        const d = new Date(log.log_date + 'T00:00:00');
                        const month = d.toLocaleDateString('en-US', { month: 'short' });
                        const day = d.getDate();
                        const year = d.getFullYear();
                        const weekday = d.toLocaleDateString('en-US', { weekday: 'short' });
                        logDate = `${month}/${day}/${year}/${weekday}`;
                    }
                    
                    // Store original date for filtering
                    row.setAttribute('data-log-date', log.log_date || '');
                    
                    // Get official times for this log date
                    let officialTimesData;
                    try {
                        officialTimesData = await getOfficialTimesForDate(log.log_date);
                    } catch (error) {
                        console.error('Error fetching official times for log:', log.id, error);
                        officialTimesData = { found: false, holidayWeekEightHour: false, times: DEFAULT_OFFICIAL_TIMES };
                    }
                    
                    // Calculate hours, late, undertime, overtime, absent (same logic as Employee Management DTR)
                    let hours = 0;
                    let lateMinutes = 0;
                    let undertimeMinutes = 0;
                    let overtimeHours = 0;
                    let absentHours = 0;
                    let absentPeriod = ''; // 'morning', 'afternoon', 'full', or ''
                    let statusBadge = '<span class="badge bg-secondary">Incomplete</span>';
                    let hasOfficialTime = officialTimesData && officialTimesData.found;
                
                    // Parse time string (HH:MM format) to hours and minutes
                    const parseTime = (timeStr) => {
                        if (!timeStr) return null;
                        const parts = timeStr.split(':');
                        if (parts.length >= 2) {
                            const hours = parseInt(parts[0], 10) || 0;
                            const minutes = parseInt(parts[1], 10) || 0;
                            return hours * 60 + minutes; // Return total minutes
                        }
                        return null;
                    };

                    const remarksStr = (log.remarks || '').trim();
                    const isHalfDayHoliday = Number(log.holiday_is_half_day) === 1
                        || remarksStr.indexOf('Holiday (Half-day') === 0;
                    let halfDayPeriod = 'morning';
                    if (Number(log.holiday_is_half_day) === 1) {
                        halfDayPeriod = (log.holiday_half_day_period || 'morning') === 'afternoon' ? 'afternoon' : 'morning';
                    } else if (remarksStr.indexOf('Holiday (Half-day PM):') === 0) {
                        halfDayPeriod = 'afternoon';
                    }
                    
                    // Helper function to check if a time is actually logged (not empty and not 00:00)
                    const isTimeLogged = (time) => {
                        if (!time) return false;
                        const trimmed = String(time).trim();
                        if (trimmed === 'HOLIDAY' || trimmed === 'LEAVE' || trimmed === 'TARF') return false;
                        // Treat 00:00, 00:00:00, or any variation as "not logged"
                        return trimmed !== '00:00' && trimmed !== '00:00:00' && trimmed !== '0:00' && trimmed !== '0:00:00';
                    };
                    
                    const remarksUpperForLeave = (log.remarks && String(log.remarks).trim().toUpperCase()) || '';
                    const isLeaveRow = remarksUpperForLeave === 'LEAVE' || ['time_in', 'lunch_out', 'lunch_in', 'time_out'].some(function (k) {
                        const v = log[k];
                        return v && String(v).trim().toUpperCase() === 'LEAVE';
                    });

                    let halfDayHandled = false;
                    const isHolCell = function (v) { return v === 'HOLIDAY'; };

                    // Half-day holiday: credit only the official segment for the declared half; work half uses actuals or tardiness/undertime
                    if (log.is_holiday && isHalfDayHoliday && officialTimesData && officialTimesData.times) {
                        const official = officialTimesData.times;
                        const officialHasLunch = (officialTimesData.hasLunch === true) || (officialTimesData.found === false);
                        const officialInMin = parseTime(official.time_in);
                        const officialOutMin = parseTime(official.time_out);
                        const officialLunchOutMin = parseTime(official.lunch_out);
                        const officialLunchInMin = parseTime(official.lunch_in);
                        let officialMorningMinutes = 0;
                        let officialAfternoonMinutes = 0;
                        if (officialHasLunch && officialInMin !== null && officialOutMin !== null
                            && officialLunchOutMin !== null && officialLunchInMin !== null) {
                            officialMorningMinutes = Math.max(0, officialLunchOutMin - officialInMin);
                            officialAfternoonMinutes = Math.max(0, officialOutMin - officialLunchInMin);
                        } else if (officialInMin !== null && officialOutMin !== null) {
                            const total = Math.max(0, officialOutMin - officialInMin);
                            officialMorningMinutes = total / 2;
                            officialAfternoonMinutes = total / 2;
                        }
                        const hasTimeIn = isTimeLogged(log.time_in);
                        const hasLunchOut = isTimeLogged(log.lunch_out);
                        const hasLunchIn = isTimeLogged(log.lunch_in);
                        const hasTimeOut = isTimeLogged(log.time_out);
                        if (halfDayPeriod === 'afternoon') {
                            if (hasTimeIn && hasLunchOut && !isHolCell(log.time_in) && !isHolCell(log.lunch_out)) {
                                const aIn = parseTime(log.time_in);
                                const aLo = parseTime(log.lunch_out);
                                if (aIn !== null && aLo !== null) {
                                    hours += (aLo - aIn) / 60;
                                    if (officialInMin !== null && aIn > officialInMin) lateMinutes += (aIn - officialInMin);
                                    if (officialLunchOutMin !== null && aLo < officialLunchOutMin) undertimeMinutes += (officialLunchOutMin - aLo);
                                }
                            } else {
                                lateMinutes += officialMorningMinutes;
                            }
                            if (isHolCell(log.lunch_in) && isHolCell(log.time_out)) {
                                hours += officialAfternoonMinutes / 60;
                            } else if (hasLunchIn && hasTimeOut && !isHolCell(log.lunch_in) && !isHolCell(log.time_out)) {
                                const li = parseTime(log.lunch_in);
                                const to = parseTime(log.time_out);
                                if (li !== null && to !== null) {
                                    hours += (to - li) / 60;
                                    if (officialLunchInMin !== null && li > officialLunchInMin) lateMinutes += (li - officialLunchInMin);
                                    if (officialOutMin !== null && to < officialOutMin) undertimeMinutes += (officialOutMin - to);
                                }
                            } else {
                                hours += officialAfternoonMinutes / 60;
                            }
                        } else {
                            if (isHolCell(log.time_in) && isHolCell(log.lunch_out)) {
                                hours += officialMorningMinutes / 60;
                            } else if (hasTimeIn && hasLunchOut && !isHolCell(log.time_in) && !isHolCell(log.lunch_out)) {
                                const aIn = parseTime(log.time_in);
                                const aLo = parseTime(log.lunch_out);
                                if (aIn !== null && aLo !== null) {
                                    hours += (aLo - aIn) / 60;
                                    if (officialInMin !== null && aIn > officialInMin) lateMinutes += (aIn - officialInMin);
                                    if (officialLunchOutMin !== null && aLo < officialLunchOutMin) undertimeMinutes += (officialLunchOutMin - aLo);
                                }
                            } else {
                                hours += officialMorningMinutes / 60;
                            }
                            if (hasLunchIn && hasTimeOut && !isHolCell(log.lunch_in) && !isHolCell(log.time_out)) {
                                const li = parseTime(log.lunch_in);
                                const to = parseTime(log.time_out);
                                if (li !== null && to !== null) {
                                    hours += (to - li) / 60;
                                    if (officialLunchInMin !== null && li > officialLunchInMin) lateMinutes += (li - officialLunchInMin);
                                    if (officialOutMin !== null && to < officialOutMin) undertimeMinutes += (officialOutMin - to);
                                }
                            } else {
                                undertimeMinutes += officialAfternoonMinutes;
                            }
                        }
                        statusBadge = '<span class="badge bg-warning text-dark">' + (halfDayPeriod === 'afternoon' ? 'Half-day PM' : 'Half-day AM') + '</span>';
                        hasOfficialTime = true;
                        halfDayHandled = true;
                    } else if (log.is_holiday && !log.has_holiday_attendance && !isHalfDayHoliday) {
                        // Full-day holiday, no actual punches: credit full official base only if official times exist
                        if (officialTimesData && officialTimesData.found && officialTimesData.times) {
                            const official = officialTimesData.times;
                            const officialInMin = parseTime(official.time_in);
                            const officialOutMin = parseTime(official.time_out);
                            if (officialInMin !== null && officialOutMin !== null) {
                                const officialHasLunch = !!(official.lunch_out && official.lunch_in);
                                if (officialHasLunch && official.lunch_out && official.lunch_in) {
                                    const officialLunchOutMin = parseTime(official.lunch_out);
                                    const officialLunchInMin = parseTime(official.lunch_in);
                                    if (officialLunchOutMin !== null && officialLunchInMin !== null) {
                                        hours = (officialLunchOutMin - officialInMin) / 60 + (officialOutMin - officialLunchInMin) / 60;
                                    } else {
                                        hours = (officialOutMin - officialInMin) / 60;
                                    }
                                } else {
                                    hours = (officialOutMin - officialInMin) / 60;
                                }
                            }
                        }
                        statusBadge = '<span class="badge bg-secondary">Holiday</span>';
                        hasOfficialTime = false;
                    }
                    
                    // Approved leave (pardon): same rule as assets/js/employee_logs.js — credit official base hours, not absent
                    if (isLeaveRow) {
                        if (officialTimesData && officialTimesData.times) {
                            const official = officialTimesData.times;
                            const officialInMin = parseTime(official.time_in);
                            const officialOutMin = parseTime(official.time_out);
                            if (officialInMin !== null && officialOutMin !== null) {
                                const officialLeaveHasLunch = !!(official.lunch_out && official.lunch_in);
                                if (officialLeaveHasLunch && official.lunch_out && official.lunch_in) {
                                    const officialLunchOutMin = parseTime(official.lunch_out);
                                    const officialLunchInMin = parseTime(official.lunch_in);
                                    if (officialLunchOutMin !== null && officialLunchInMin !== null) {
                                        hours = (officialLunchOutMin - officialInMin) / 60 + (officialOutMin - officialLunchInMin) / 60;
                                    } else {
                                        hours = (officialOutMin - officialInMin) / 60;
                                    }
                                } else {
                                    hours = (officialOutMin - officialInMin) / 60;
                                }
                            }
                        }
                        statusBadge = '<span class="badge bg-danger">LEAVE</span>';
                        lateMinutes = 0;
                        undertimeMinutes = 0;
                        absentHours = 0;
                        absentPeriod = '';
                    }

                    let tarfRowHandled = false;
                    const tarfCreditMatchView = remarksStr.match(/TARF_HOURS_CREDIT:([\d.]+)/);
                    if (tarfCreditMatchView && (Number(log.tarf_id) > 0 || remarksStr.indexOf('TARF:') === 0)) {
                        const creditH = parseFloat(tarfCreditMatchView[1], 10);
                        hours = !isNaN(creditH) && creditH > 0 ? creditH : 8;
                        lateMinutes = 0;
                        undertimeMinutes = 0;
                        absentHours = 0;
                        absentPeriod = '';
                        overtimeHours = 0;
                        statusBadge = '<span class="badge bg-info text-dark">TARF</span>';
                        hasOfficialTime = false;
                        tarfRowHandled = true;
                    }

                    if (!tarfRowHandled) {
                    // Check if log entries are actually logged (not empty and not 00:00)
                    const hasTimeIn = isTimeLogged(log.time_in);
                    const hasLunchOut = isTimeLogged(log.lunch_out);
                    const hasLunchIn = isTimeLogged(log.lunch_in);
                    const hasTimeOut = isTimeLogged(log.time_out);
                    
                    // Official time may have no lunch (half-day workers) - single shift: time_in to time_out
                    const officialHasLunch = (officialTimesData && officialTimesData.hasLunch === true) || (officialTimesData && officialTimesData.found === false);
                    const morningShiftComplete = officialHasLunch ? (hasTimeIn && hasLunchOut) : (hasTimeIn && hasTimeOut);
                    const afternoonShiftComplete = officialHasLunch ? (hasLunchIn && hasTimeOut) : false;
                    const halfDayComplete = !officialHasLunch && hasTimeIn && hasTimeOut;
                    
                    // Absent detection (half-day PM: morning is work; half-day AM: afternoon is work — skip generic holiday skip for those halves)
                    const isHalfDayPmRow = log.is_holiday && isHalfDayHoliday && halfDayPeriod === 'afternoon';
                    const isHalfDayAmRow = log.is_holiday && isHalfDayHoliday && halfDayPeriod === 'morning';
                    const morningShiftAbsent = !halfDayHandled && ((!log.is_holiday && !isLeaveRow && !hasTimeIn) || (isHalfDayPmRow && !hasTimeIn));
                    const afternoonShiftAbsent = !halfDayHandled && ((!log.is_holiday && !isLeaveRow && officialHasLunch && !hasLunchIn) || (isHalfDayAmRow && officialHasLunch && !hasLunchIn));
                    const isAbsent = morningShiftAbsent || afternoonShiftAbsent;
                    if (isAbsent) {
                        if (morningShiftAbsent && afternoonShiftAbsent) absentPeriod = 'full';
                        else if (morningShiftAbsent) absentPeriod = 'morning';
                        else absentPeriod = 'afternoon';
                    }
                    
                    // Calculate absent hours and apply DTR business rule: morning absent → LATE; afternoon absent → UNDERTIME
                    if (isAbsent && hasOfficialTime && officialTimesData && officialTimesData.times) {
                        const official = officialTimesData.times;
                        const officialInMinutes = parseTime(official.time_in);
                        const officialOutMinutes = parseTime(official.time_out);
                        const officialLunchOutMinutes = parseTime(official.lunch_out);
                        const officialLunchInMinutes = parseTime(official.lunch_in);
                        if (officialHasLunch && officialInMinutes !== null && officialOutMinutes !== null &&
                            officialLunchOutMinutes !== null && officialLunchInMinutes !== null) {
                            const lunchBreakMinutes = officialLunchInMinutes - officialLunchOutMinutes;
                            const expectedTotalMinutes = (officialOutMinutes - officialInMinutes) - lunchBreakMinutes;
                            const officialMorningMinutes = officialLunchOutMinutes - officialInMinutes;
                            const officialAfternoonMinutes = officialOutMinutes - officialLunchInMinutes;
                            if (morningShiftAbsent && afternoonShiftAbsent) absentHours = expectedTotalMinutes / 60;
                            else if (morningShiftAbsent) absentHours = officialMorningMinutes / 60;
                            else absentHours = officialAfternoonMinutes / 60;
                        } else if (!officialHasLunch && officialInMinutes !== null && officialOutMinutes !== null) {
                            const expectedMinutes = officialOutMinutes - officialInMinutes;
                            if (morningShiftAbsent && afternoonShiftAbsent) absentHours = expectedMinutes / 60;
                        }
                    }
                    // When no official time: absent hours stay 0 (absent hours must be based on official time)
                    if (absentPeriod === 'morning') { lateMinutes += absentHours * 60; absentHours = 0; }
                    else if (absentPeriod === 'afternoon') { undertimeMinutes += absentHours * 60; absentHours = 0; }
                    
                    // Missing time_in or lunch_out → Tardiness for the whole morning (only when official has lunch)
                    if (!halfDayHandled && officialHasLunch && hasOfficialTime && officialTimesData && officialTimesData.times && hasTimeIn && !hasLunchOut) {
                        const official = officialTimesData.times;
                        const officialInMinutes = parseTime(official.time_in);
                        const officialLunchOutMinutes = parseTime(official.lunch_out);
                        if (officialInMinutes !== null && officialLunchOutMinutes !== null) {
                            lateMinutes += (officialLunchOutMinutes - officialInMinutes);
                        }
                    }
                    
                    // Complete shifts: skip pure holiday credit rows; include real work on a holiday; skip if half-day already computed
                    if (!halfDayHandled && (!log.is_holiday || log.has_holiday_attendance) && (morningShiftComplete || afternoonShiftComplete)) {
                        try {
                            // Use employee's official times if available, otherwise use defaults
                            const official = hasOfficialTime ? officialTimesData.times : DEFAULT_OFFICIAL_TIMES;
                            
                            // Official times in minutes from midnight
                            const officialInMinutes = parseTime(official.time_in);
                            const officialOutMinutes = parseTime(official.time_out);
                            const officialLunchOutMinutes = parseTime(official.lunch_out);
                            const officialLunchInMinutes = parseTime(official.lunch_in);
                            
                            let morningMinutes = 0;
                            let afternoonMinutes = 0;
                            
                            // Morning: full-day = time_in to lunch_out; half-day = time_in to time_out
                            if (morningShiftComplete) {
                                const actualInMinutes = parseTime(log.time_in);
                                if (officialHasLunch) {
                                    const actualLunchOutMinutes = parseTime(log.lunch_out);
                                    if (actualInMinutes !== null && actualLunchOutMinutes !== null) {
                                        morningMinutes = Math.max(0, actualLunchOutMinutes - actualInMinutes);
                                    }
                                } else {
                                    const actualOutMinutes = parseTime(log.time_out);
                                    if (actualInMinutes !== null && actualOutMinutes !== null) {
                                        morningMinutes = Math.max(0, actualOutMinutes - actualInMinutes);
                                    }
                                }
                            }
                            
                            // Afternoon: full-day only (lunch_in AND time_out)
                            if (afternoonShiftComplete) {
                                const actualLunchInMinutes = parseTime(log.lunch_in);
                                const actualOutMinutes = parseTime(log.time_out);
                                if (actualLunchInMinutes !== null && actualOutMinutes !== null) {
                                    afternoonMinutes = Math.max(0, actualOutMinutes - actualLunchInMinutes);
                                }
                            }
                            
                            // Total hours = morning + afternoon (only from complete shifts)
                            hours = (morningMinutes + afternoonMinutes) / 60;
                            
                            // Only calculate late, undertime if official times are set
                            if (hasOfficialTime && officialInMinutes !== null && officialOutMinutes !== null) {
                                if (officialHasLunch && officialLunchOutMinutes !== null && officialLunchInMinutes !== null) {
                                    // Full-day: late/undertime with lunch
                                    if (morningShiftComplete) {
                                        const actualInMinutes = parseTime(log.time_in);
                                        if (actualInMinutes !== null && actualInMinutes > officialInMinutes) {
                                            lateMinutes += (actualInMinutes - officialInMinutes);
                                        }
                                    }
                                    if (afternoonShiftComplete) {
                                        const actualLunchInMinutes = parseTime(log.lunch_in);
                                        if (actualLunchInMinutes !== null && actualLunchInMinutes > officialLunchInMinutes) {
                                            lateMinutes += (actualLunchInMinutes - officialLunchInMinutes);
                                        }
                                    }
                                    if (morningShiftComplete) {
                                        const actualLunchOutMinutes = parseTime(log.lunch_out);
                                        if (actualLunchOutMinutes !== null && actualLunchOutMinutes < officialLunchOutMinutes) {
                                            undertimeMinutes += (officialLunchOutMinutes - actualLunchOutMinutes);
                                        }
                                    }
                                    if (afternoonShiftComplete) {
                                        const actualOutMinutes = parseTime(log.time_out);
                                        if (actualOutMinutes !== null && actualOutMinutes < officialOutMinutes) {
                                            undertimeMinutes += (officialOutMinutes - actualOutMinutes);
                                        }
                                    }
                                    if (morningShiftComplete && hasLunchIn && !hasTimeOut) {
                                        undertimeMinutes += (officialOutMinutes - officialLunchInMinutes);
                                    }
                                } else if (!officialHasLunch && halfDayComplete) {
                                    // Half-day: late from time_in, undertime from time_out
                                    const actualInMinutes = parseTime(log.time_in);
                                    const actualOutMinutes = parseTime(log.time_out);
                                    if (actualInMinutes !== null && actualInMinutes > officialInMinutes) {
                                        lateMinutes += (actualInMinutes - officialInMinutes);
                                    }
                                    if (actualOutMinutes !== null && actualOutMinutes < officialOutMinutes) {
                                        undertimeMinutes += (officialOutMinutes - actualOutMinutes);
                                    }
                                }
                            }
                            
                            // Overtime ONLY from explicit OT in/OT out fields (same as admin/employee_logs)
                            if (log.ot_in && log.ot_out) {
                                const otInMinutes = parseTime(log.ot_in);
                                const otOutMinutes = parseTime(log.ot_out);
                                if (otInMinutes !== null && otOutMinutes !== null && otOutMinutes > otInMinutes) {
                                    overtimeHours = (otOutMinutes - otInMinutes) / 60;
                                }
                            }
                            
                            // When no OT is logged, cap hours at official base time
                            if (hasOfficialTime && overtimeHours === 0 && hours > 0 && officialTimesData && officialTimesData.times) {
                                const official = officialTimesData.times;
                                const officialInMin = parseTime(official.time_in);
                                const officialOutMin = parseTime(official.time_out);
                                if (officialInMin !== null && officialOutMin !== null) {
                                    let officialBaseHours;
                                    if (officialHasLunch && official.lunch_out && official.lunch_in) {
                                        const officialLunchOutMin = parseTime(official.lunch_out);
                                        const officialLunchInMin = parseTime(official.lunch_in);
                                        if (officialLunchOutMin !== null && officialLunchInMin !== null) {
                                            officialBaseHours = (officialLunchOutMin - officialInMin) / 60 + (officialOutMin - officialLunchInMin) / 60;
                                        } else {
                                            officialBaseHours = (officialOutMin - officialInMin) / 60;
                                        }
                                    } else {
                                        officialBaseHours = (officialOutMin - officialInMin) / 60;
                                    }
                                    if (hours > officialBaseHours) {
                                        hours = officialBaseHours;
                                    }
                                }
                            }
                            
                            // Full-day: both shifts complete; Half-day: time_in and time_out (no lunch)
                            const isComplete = officialHasLunch ? (morningShiftComplete && afternoonShiftComplete) : halfDayComplete;
                            const lateHrs = lateMinutes / 60;
                            const undertimeHrs = undertimeMinutes / 60;
                            
                            if (hasOfficialTime) {
                                // Status badge
                                if (!isComplete && undertimeHrs > 0) {
                                    statusBadge = lateHrs > 0 ? '<span class="badge bg-warning">Late & Undertime</span>' : '<span class="badge bg-warning">Undertime</span>';
                                } else if (!isComplete && lateHrs > 0) {
                                    statusBadge = '<span class="badge bg-danger">Late</span>';
                                } else if (!isComplete) {
                                    statusBadge = '<span class="badge bg-secondary">Incomplete</span>';
                                } else if (lateHrs > 0 && undertimeHrs > 0) {
                                    statusBadge = '<span class="badge bg-warning">Late & Undertime</span>';
                                } else if (lateHrs > 0) {
                                    statusBadge = '<span class="badge bg-danger">Late</span>';
                                } else if (undertimeHrs > 0) {
                                    statusBadge = '<span class="badge bg-warning">Undertime</span>';
                                } else if (overtimeHours > 0) {
                                    statusBadge = '<span class="badge bg-success">Overtime</span>';
                                } else {
                                    statusBadge = '<span class="badge bg-success">Complete</span>';
                                }
                            } else if (hours > 0) {
                                statusBadge = '<span class="badge bg-secondary">Incomplete</span>';
                            } else {
                                statusBadge = '<span class="badge bg-secondary">No official time yet</span>';
                            }
                        } catch (e) {
                            console.error('Error calculating hours for log:', log, e);
                        }
                    }
                    
                    // Full-day absent with official time: show Absent status (overrides other badges)
                    if (hasOfficialTime && isAbsent && absentPeriod === 'full' && !isLeaveRow) {
                        statusBadge = '<span class="badge bg-danger">Absent</span>';
                    }
                    }

                    // Accumulate totals
                    totalHours += hours;
                    totalLateMinutes += lateMinutes;
                    totalUndertimeMinutes += undertimeMinutes;
                    if (absentHours > 0 && absentPeriod === 'full') {
                        totalAbsentHours += absentHours;
                    }
                    
                    // Format late, undertime, absent for display
                    const lateTimeFormat = lateMinutes > 0
                        ? minutesToTimeFormat(lateMinutes) + ' <small class="text-muted">(' + hoursToDayFraction(lateMinutes / 60).toFixed(3) + ' d)</small>'
                        : '<span class="text-muted">-</span>';
                    const undertimeTimeFormat = undertimeMinutes > 0
                        ? minutesToTimeFormat(undertimeMinutes) + ' <small class="text-muted">(' + hoursToDayFraction(undertimeMinutes / 60).toFixed(3) + ' d)</small>'
                        : '<span class="text-muted">-</span>';
                    let absentHoursFormat = '<span class="text-muted">-</span>';
                    if (absentHours > 0 && absentPeriod === 'full') {
                        absentHoursFormat = absentHours.toFixed(2) + ' h <small class="text-muted">(' + hoursToDayFraction(absentHours).toFixed(3) + ' d)</small>';
                    }
                    
                    // Determine pardon status badge and button
                    let pardonStatusBadge = '';
                    let pardonButton = '';
                    const pardonStatus = log.pardon_status || null;
                
                    if (pardonStatus === 'pending') {
                        pardonStatusBadge = '<span class="badge bg-warning ms-1">Pardon Pending</span>';
                        pardonButton = `<button onclick="editLog(${log.id}, '${log.log_date}', '${log.time_in || ''}', '${log.lunch_out || ''}', '${log.lunch_in || ''}', '${log.time_out || ''}')" class="btn-edit-modern" title="View Pending Request" disabled>
                            <i class="fas fa-clock"></i> Pending Review
                        </button>`;
                    } else if (pardonStatus === 'approved') {
                        pardonStatusBadge = '<span class="badge bg-success ms-1">Pardon Approved</span>';
                        pardonButton = `<button onclick="editLog(${log.id}, '${log.log_date}', '${log.time_in || ''}', '${log.lunch_out || ''}', '${log.lunch_in || ''}', '${log.time_out || ''}')" class="btn-edit-modern" title="View Approved Request" disabled style="opacity: 0.6;">
                            <i class="fas fa-check-circle"></i> Approved
                        </button>`;
                    } else if (pardonStatus === 'rejected') {
                        pardonStatusBadge = '<span class="badge bg-danger ms-1">Pardon Rejected</span>';
                        pardonButton = `<button onclick="editLog(${log.id}, '${log.log_date}', '${log.time_in || ''}', '${log.lunch_out || ''}', '${log.lunch_in || ''}', '${log.time_out || ''}')" class="btn-edit-modern" title="Resubmit Pardon Request">
                            <i class="fas fa-redo"></i> Resubmit
                        </button>`;
                    } else {
                        // No pardon request yet: only clickable when supervisor (dean) has opened pardon for this date
                        const pardonOpen = log.pardon_open === true;
                        if (pardonOpen) {
                            pardonButton = `<button onclick="editLog(${log.id}, '${log.log_date}', '${log.time_in || ''}', '${log.lunch_out || ''}', '${log.lunch_in || ''}', '${log.time_out || ''}')" class="btn-edit-modern" title="Submit Pardon Request">
                                <i class="fas fa-paper-plane"></i> Submit Pardon
                            </button>`;
                        } else {
                            pardonButton = `<button onclick="editLog(${log.id}, '${log.log_date}', '${log.time_in || ''}', '${log.lunch_out || ''}', '${log.lunch_in || ''}', '${log.time_out || ''}')" class="btn-edit-modern" title="Your supervisor must open pardon for this date before you can submit." disabled>
                                <i class="fas fa-paper-plane"></i> Submit Pardon
                            </button>`;
                        }
                    }
                
                    // DTR Submit: daily submission - Submit next day (on time), Submit Late 2+ days after
                    let dtrSubmitCell = '<span class="text-muted small">-</span>';
                    if (log.log_date) {
                        if (dtrSubmittedDates[log.log_date]) {
                            dtrSubmitCell = '<span class="badge bg-success">Submitted</span>';
                        } else if (dtrCanSubmitDates.indexOf(log.log_date) >= 0) {
                            const logDateObj = new Date(log.log_date + 'T12:00:00');
                            const today = new Date();
                            today.setHours(0, 0, 0, 0);
                            const yesterday = new Date(today);
                            yesterday.setDate(yesterday.getDate() - 1);
                            logDateObj.setHours(0, 0, 0, 0);
                            const isYesterday = logDateObj.getTime() === yesterday.getTime();
                            const btnLabel = isYesterday ? 'Submit' : 'Submit Late';
                            dtrSubmitCell = '<button type="button" class="btn btn-sm btn-primary" onclick="submitDTRDate(\'' + log.log_date + '\', this)" title="Submit this day\'s DTR to Dean and Admin"><i class="fas fa-paper-plane me-1"></i>' + btnLabel + '</button>';
                        } else {
                            const logDateObj = new Date(log.log_date + 'T12:00:00');
                            const today = new Date();
                            today.setHours(0, 0, 0, 0);
                            logDateObj.setHours(0, 0, 0, 0);
                            if (logDateObj.getTime() === today.getTime()) {
                                dtrSubmitCell = '<span class="badge bg-secondary">Submit tomorrow</span>';
                            } else if (logDateObj > today) {
                                dtrSubmitCell = '<span class="text-muted small">-</span>';
                            } else {
                                dtrSubmitCell = '<span class="badge bg-secondary">Submit tomorrow</span>';
                            }
                        }
                    }
                
                    const hoursFormat = hours > 0 ? hours.toFixed(2) : '<span class="text-muted">-</span>';
                    const hw8LogBadge = (officialTimesData && officialTimesData.holidayWeekEightHour)
                        ? ' <span class="badge rounded-pill" style="font-size:0.65rem;background:#cfe2ff;color:#084298;font-weight:600;" title="Holiday week: late and undertime use the standard 8-hour schedule (08:00–12:00, 13:00–17:00).">8h week</span>'
                        : '';
                    const dayCellContent = (log.is_holiday && !log.has_holiday_attendance)
                        ? (isHalfDayHoliday
                            ? `${logDate}${hw8LogBadge} <span class="badge bg-warning text-dark ms-1">${halfDayPeriod === 'afternoon' ? 'Half-day PM' : 'Half-day AM'}</span>${pardonStatusBadge}`
                            : `${logDate}${hw8LogBadge} <span class="badge bg-danger ms-1">Holiday</span>${pardonStatusBadge}`)
                        : `${logDate}${hw8LogBadge}${pardonStatusBadge}`;
                    const showTarfInTimeCells = (remarksStr.indexOf('TARF_HOURS_CREDIT:') !== -1
                            && (log.tarf_id || remarksStr.indexOf('TARF:') === 0))
                        || (Number(log.tarf_id) > 0 && remarksStr.indexOf('TARF:') === 0);
                    const timeCellDisplay = (val) => {
                        if (showTarfInTimeCells || val === 'TARF') {
                            return '<span class="badge bg-info text-dark">TARF</span>';
                        }
                        if (val === 'HOLIDAY') {
                            return isHalfDayHoliday
                                ? '<span class="badge bg-warning text-dark">Holiday</span>'
                                : '<span class="badge bg-danger">Holiday</span>';
                        }
                        if (val === 'LEAVE') return '<span class="badge bg-danger">LEAVE</span>';
                        return val || '<span class="text-muted">-</span>';
                    };
                    if (showTarfInTimeCells) {
                        statusBadge = '<span class="badge bg-info text-dark">TARF</span>';
                    }
                    if (log.is_holiday && !log.has_holiday_attendance && !isHalfDayHoliday && !showTarfInTimeCells) {
                        statusBadge = '<span class="badge bg-secondary">Holiday</span>';
                    }
                    row.innerHTML = `
                        <td data-label="Log Date" class="fw-medium">${dayCellContent}</td>
                        <td data-label="Time In">${timeCellDisplay(log.time_in)}</td>
                        <td data-label="Lunch Out">${timeCellDisplay(log.lunch_out)}</td>
                        <td data-label="Lunch In">${timeCellDisplay(log.lunch_in)}</td>
                        <td data-label="Time Out">${timeCellDisplay(log.time_out)}</td>
                        <td data-label="Hours" class="fw-semibold ${hours > 0 ? 'text-primary' : 'text-muted'}">${hoursFormat}</td>
                        <td data-label="Late" class="fw-semibold ${lateMinutes > 0 ? 'text-danger' : 'text-muted'}">${lateTimeFormat}</td>
                        <td data-label="Undertime" class="fw-semibold ${undertimeMinutes > 0 ? 'text-warning' : 'text-muted'}">${undertimeTimeFormat}</td>
                        <td data-label="Absent" class="fw-semibold ${absentHours > 0 ? 'text-danger' : 'text-muted'}">${absentHoursFormat}</td>
                        <td data-label="OT IN" class="fw-semibold ${log.ot_in ? 'text-success' : 'text-muted'}">
                            ${log.ot_in || '<span class="text-muted">-</span>'}
                        </td>
                        <td data-label="OT OUT" class="fw-semibold ${log.ot_out ? 'text-success' : 'text-muted'}">
                            ${log.ot_out || '<span class="text-muted">-</span>'}
                        </td>
                        <td data-label="Status">${statusBadge}</td>
                        <td data-label="DTR Submit">${dtrSubmitCell}</td>
                        <td data-label="Actions">
                            ${pardonButton}
                        </td>
                    `;
                    row.setAttribute('data-log-id', log.id);
                    tbody.appendChild(row);
                } catch (error) {
                    console.error('Error rendering log row:', error, log);
                    // Still create a basic row even if there's an error
                    const row = document.createElement('tr');
                    let errLogDate = '-';
                    if (log.log_date) {
                        const d = new Date(log.log_date + 'T00:00:00');
                        errLogDate = `${d.toLocaleDateString('en-US', { month: 'short' })}/${d.getDate()}/${d.getFullYear()}/${d.toLocaleDateString('en-US', { weekday: 'short' })}`;
                    }
                    row.innerHTML = `
                        <td data-label="Log Date" class="fw-medium">${errLogDate}</td>
                        <td data-label="Time In">${log.time_in || '<span class="text-muted">-</span>'}</td>
                        <td data-label="Lunch Out">${log.lunch_out || '<span class="text-muted">-</span>'}</td>
                        <td data-label="Lunch In">${log.lunch_in || '<span class="text-muted">-</span>'}</td>
                        <td data-label="Time Out">${log.time_out || '<span class="text-muted">-</span>'}</td>
                        <td data-label="Hours"><span class="text-muted">-</span></td>
                        <td data-label="Late"><span class="text-muted">-</span></td>
                        <td data-label="Undertime"><span class="text-muted">-</span></td>
                        <td data-label="Absent"><span class="text-muted">-</span></td>
                        <td data-label="OT IN">${log.ot_in || '<span class="text-muted">-</span>'}</td>
                        <td data-label="OT OUT">${log.ot_out || '<span class="text-muted">-</span>'}</td>
                        <td data-label="Status"><span class="badge bg-secondary">Error</span></td>
                        <td data-label="DTR Submit">-</td>
                        <td data-label="Actions">-</td>
                    `;
                    row.setAttribute('data-log-id', log.id);
                    row.setAttribute('data-log-date', log.log_date || '');
                    tbody.appendChild(row);
                }
            }
            
            // Add totals row
            if (pageLogs.length > 0) {
                const totalRow = document.createElement('tr');
                totalRow.className = 'table-light fw-bold logs-total-row';
                const totalHoursFormat = totalHours > 0 ? totalHours.toFixed(2) : '-';
                const totalLateWithDays = totalLateMinutes > 0
                    ? minutesToTimeFormat(totalLateMinutes) + ' <small class="text-muted">(' + hoursToDayFraction(totalLateMinutes / 60).toFixed(3) + ' d)</small>'
                    : '-';
                const totalUndertimeWithDays = totalUndertimeMinutes > 0
                    ? minutesToTimeFormat(totalUndertimeMinutes) + ' <small class="text-muted">(' + hoursToDayFraction(totalUndertimeMinutes / 60).toFixed(3) + ' d)</small>'
                    : '-';
                const totalAbsentWithDays = totalAbsentHours > 0
                    ? totalAbsentHours.toFixed(2) + ' h <small class="text-muted">(' + hoursToDayFraction(totalAbsentHours).toFixed(3) + ' d)</small>'
                    : '-';
                totalRow.innerHTML = `
                    <td data-label="Log Date" colspan="5" class="text-end">Total</td>
                    <td data-label="Hours" class="text-primary">${totalHoursFormat}</td>
                    <td data-label="Late" class="${totalLateMinutes > 0 ? 'text-danger' : 'text-muted'}">${totalLateWithDays}</td>
                    <td data-label="Undertime" class="${totalUndertimeMinutes > 0 ? 'text-warning' : 'text-muted'}">${totalUndertimeWithDays}</td>
                    <td data-label="Absent" class="${totalAbsentHours > 0 ? 'text-danger' : 'text-muted'}">${totalAbsentWithDays}</td>
                    <td data-label="OT IN"><span class="text-muted">-</span></td>
                    <td data-label="OT OUT"><span class="text-muted">-</span></td>
                    <td data-label="Status"><span class="text-muted">-</span></td>
                    <td data-label="DTR Submit"><span class="text-muted">-</span></td>
                    <td data-label="Actions"><span class="text-muted">-</span></td>
                `;
                tbody.appendChild(totalRow);
            }
        }
        
        function renderPaginationControls(totalPages) {
            const paginationControls = document.getElementById('paginationControls');
            if (!paginationControls) return;
            
            paginationControls.innerHTML = '';
            
            if (totalPages <= 1) {
                return;
            }
            
            // Previous button
            const prevLi = document.createElement('li');
            prevLi.className = `page-item ${currentPage === 1 ? 'disabled' : ''}`;
            prevLi.innerHTML = `<a class="page-link" href="#" onclick="goToPage(${currentPage - 1}); return false;">Previous</a>`;
            paginationControls.appendChild(prevLi);
            
            // Page numbers
            const maxVisiblePages = 5;
            let startPage = Math.max(1, currentPage - Math.floor(maxVisiblePages / 2));
            let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);
            
            if (endPage - startPage < maxVisiblePages - 1) {
                startPage = Math.max(1, endPage - maxVisiblePages + 1);
            }
            
            if (startPage > 1) {
                const firstLi = document.createElement('li');
                firstLi.className = 'page-item';
                firstLi.innerHTML = `<a class="page-link" href="#" onclick="goToPage(1); return false;">1</a>`;
                paginationControls.appendChild(firstLi);
                
                if (startPage > 2) {
                    const ellipsisLi = document.createElement('li');
                    ellipsisLi.className = 'page-item disabled';
                    ellipsisLi.innerHTML = `<span class="page-link">...</span>`;
                    paginationControls.appendChild(ellipsisLi);
                }
            }
            
            for (let i = startPage; i <= endPage; i++) {
                const li = document.createElement('li');
                li.className = `page-item ${i === currentPage ? 'active' : ''}`;
                li.innerHTML = `<a class="page-link" href="#" onclick="goToPage(${i}); return false;">${i}</a>`;
                paginationControls.appendChild(li);
            }
            
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    const ellipsisLi = document.createElement('li');
                    ellipsisLi.className = 'page-item disabled';
                    ellipsisLi.innerHTML = `<span class="page-link">...</span>`;
                    paginationControls.appendChild(ellipsisLi);
                }
                
                const lastLi = document.createElement('li');
                lastLi.className = 'page-item';
                lastLi.innerHTML = `<a class="page-link" href="#" onclick="goToPage(${totalPages}); return false;">${totalPages}</a>`;
                paginationControls.appendChild(lastLi);
            }
            
            // Next button
            const nextLi = document.createElement('li');
            nextLi.className = `page-item ${currentPage === totalPages ? 'disabled' : ''}`;
            nextLi.innerHTML = `<a class="page-link" href="#" onclick="goToPage(${currentPage + 1}); return false;">Next</a>`;
            paginationControls.appendChild(nextLi);
        }
        
        function goToPage(page) {
            const totalPages = Math.ceil(filteredLogs.length / rowsPerPage);
            if (page < 1 || page > totalPages) return;
            currentPage = page;
            renderLogsPage();
            // Scroll to top of table
            document.querySelector('.logs-table-container').scrollTop = 0;
        }
        
        function filterLogs() {
            const searchTerm = document.getElementById('searchLogs').value.toLowerCase();

            // Filter logs array (search only - date range is applied when loading)
            filteredLogs = allLogs.filter(log => {
                const matchesSearch = !searchTerm || 
                    (log.log_date && log.log_date.toLowerCase().includes(searchTerm)) ||
                    (log.time_in && log.time_in.toLowerCase().includes(searchTerm)) ||
                    (log.time_out && log.time_out.toLowerCase().includes(searchTerm)) ||
                    (log.lunch_out && log.lunch_out.toLowerCase().includes(searchTerm)) ||
                    (log.lunch_in && log.lunch_in.toLowerCase().includes(searchTerm));
                return matchesSearch;
            });
            
            // Reset to first page when filtering
            currentPage = 1;
            
            // Re-render with filtered results
            renderLogsPage();
        }
        
        function resetFilters() {
            document.getElementById('searchLogs').value = '';
            const today = new Date();
            const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
            const lastDay = new Date(today.getFullYear(), today.getMonth() + 1, 0);
            document.getElementById('filterDateFrom').value = firstDay.toISOString().slice(0, 10);
            document.getElementById('filterDateTo').value = lastDay.toISOString().slice(0, 10);
            loadLogs();
            currentPage = 1;
            renderLogsPage();
        }
        
        let dtrAllLogs = [];
        let dtrOfficialRegular = '08:00-12:00, 13:00-17:00';
        let dtrOfficialSaturday = '—';
        let dtrOfficialByDate = {};
        let dtrHolidayWeekEightHourDates = {};
        let dtrInCharge = '<?php echo addslashes(htmlspecialchars(trim($dtrInCharge), ENT_QUOTES)); ?>';

        function showDtrLoading(show) {
            const el = document.getElementById('dtrLoadingOverlay');
            if (el) el.classList.toggle('d-none', !show);
        }

        function openDTRModal() {
            document.getElementById('dtrEmployeeName').textContent = fullName || '—';
            const modal = new bootstrap.Modal(document.getElementById('dtrModal'));
            modal.show();
            showDtrLoading(true);
            fetchDTRData();
        }

        function fetchDTRData() {
            const monthSelect = document.getElementById('dtrMonth');
            const yearSelect = document.getElementById('dtrYear');
            if (!monthSelect || !yearSelect || !employeeId) {
                showDtrLoading(false);
                return;
            }
            const month = monthSelect.value;
            const year = yearSelect.value;
            const dateFrom = year + '-' + month + '-01';
            const dateTo = year + '-' + month + '-' + String(new Date(parseInt(year, 10), parseInt(month, 10), 0).getDate()).padStart(2, '0');
            const url = 'fetch_my_logs_api.php?employee_id=' + encodeURIComponent(employeeId) + '&simple=1&date_from=' + encodeURIComponent(dateFrom) + '&date_to=' + encodeURIComponent(dateTo);
            fetch(url, { credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    dtrAllLogs = (data.success && data.logs) ? data.logs : [];
                    dtrOfficialRegular = data.official_regular || '08:00-12:00, 13:00-17:00';
                    dtrOfficialSaturday = data.official_saturday || '—';
                    dtrOfficialByDate = (data.success && data.official_by_date && typeof data.official_by_date === 'object') ? data.official_by_date : {};
                    dtrHolidayWeekEightHourDates = {};
                    if (Array.isArray(data.holiday_week_eight_hour_dates)) {
                        data.holiday_week_eight_hour_dates.forEach(function (d) {
                            if (d && typeof d === 'string') dtrHolidayWeekEightHourDates[d] = true;
                        });
                    }
                    dtrInCharge = data.in_charge || '';
                    document.getElementById('dtrOfficialRegular').textContent = dtrOfficialRegular;
                    document.getElementById('dtrOfficialSat').textContent = dtrOfficialSaturday;
                    document.getElementById('dtrInCharge').textContent = dtrInCharge || '—';
                    renderDTR();
                    showDtrLoading(false);
                })
                .catch(() => {
                    dtrAllLogs = [];
                    dtrOfficialByDate = {};
                    dtrHolidayWeekEightHourDates = {};
                    renderDTR();
                    showDtrLoading(false);
                });
        }

        function parseTime(timeStr) {
            if (!timeStr) return null;
            const p = String(timeStr).split(':');
            if (p.length >= 2) return (parseInt(p[0], 10) || 0) * 60 + (parseInt(p[1], 10) || 0);
            return null;
        }

        function parseOfficialTimes(str) {
            let lunchOutMin = 12 * 60, lunchInMin = 13 * 60, timeOutMin = 17 * 60;
            if (!str || str === '—' || str === '-') return { lunchOut: lunchOutMin, lunchIn: lunchInMin, timeOut: timeOutMin };
            const parts = String(str).split(',');
            if (parts.length >= 2) {
                const am = parts[0].trim().split('-'), pm = parts[1].trim().split('-');
                if (am.length >= 2) { const lo = parseTime(am[1].trim()); if (lo !== null) lunchOutMin = lo; }
                if (pm.length >= 2) {
                    const li = parseTime(pm[0].trim()); if (li !== null) lunchInMin = li;
                    const to = parseTime(pm[1].trim()); if (to !== null) timeOutMin = to;
                }
            } else if (parts.length === 1) {
                const seg = parts[0].trim().split('-');
                if (seg.length >= 2) { const end = parseTime(seg[1].trim()); if (end !== null) { lunchOutMin = end; lunchInMin = end; timeOutMin = end; } }
            }
            return { lunchOut: lunchOutMin, lunchIn: lunchInMin, timeOut: timeOutMin };
        }

        function renderDTR() {
            const monthSelect = document.getElementById('dtrMonth');
            const yearSelect = document.getElementById('dtrYear');
            if (!monthSelect || !yearSelect) return;
            const month = monthSelect.value;
            const year = yearSelect.value;
            const monthName = monthSelect.options[monthSelect.selectedIndex].text;
            document.getElementById('dtrMonthLabel').textContent = monthName + ' ' + year;
            const tbody = document.getElementById('dtrTableBody');
            if (!tbody) return;
            tbody.innerHTML = '';
            const logByDate = {};
            (dtrAllLogs || []).forEach(log => {
                if (log.log_date && log.log_date.substring(0, 4) === year && log.log_date.substring(5, 7) === month) logByDate[log.log_date] = log;
            });
            const regOfficial = parseOfficialTimes(dtrOfficialRegular || '08:00-12:00, 13:00-17:00');
            const satOfficial = parseOfficialTimes(dtrOfficialSaturday || '—');
            const noticeEl = document.getElementById('dtrHolidayWeekNotice');
            let hwNoticeShown = false;
            for (let dShow = 1; dShow <= 31; dShow++) {
                const chk = new Date(parseInt(year, 10), parseInt(month, 10) - 1, dShow);
                if (chk.getMonth() !== parseInt(month, 10) - 1) continue;
                const dkShow = year + '-' + month + '-' + String(dShow).padStart(2, '0');
                if (dtrHolidayWeekEightHourDates && dtrHolidayWeekEightHourDates[dkShow]) {
                    hwNoticeShown = true;
                    break;
                }
            }
            if (noticeEl) {
                noticeEl.classList.toggle('d-none', !hwNoticeShown);
            }
            for (let day = 1; day <= 31; day++) {
                const dayStr = String(day).padStart(2, '0');
                const dateKey = year + '-' + month + '-' + dayStr;
                const log = logByDate[dateKey];
                const blankIfZero = (t) => { const s = (t || '').toString().trim(); return (s === '00:00' || s === '0:00') ? '' : (t || ''); };
                const timeIn = log ? blankIfZero(log.time_in) : '';
                const lunchOut = log ? blankIfZero(log.lunch_out) : '';
                const lunchIn = log ? blankIfZero(log.lunch_in) : '';
                const timeOut = log ? blankIfZero(log.time_out) : '';
                let utHrs = '—', utMin = '—';
                const isLeave = (timeIn === 'LEAVE' || lunchOut === 'LEAVE' || lunchIn === 'LEAVE' || timeOut === 'LEAVE');
                const hw8Badge = (dtrHolidayWeekEightHourDates && dtrHolidayWeekEightHourDates[dateKey])
                    ? ' <span class="badge rounded-pill align-middle" style="font-size:0.55rem;vertical-align:middle;background:#cfe2ff;color:#084298;font-weight:600;" title="Holiday week policy: undertime uses the standard 8-hour day (08:00–12:00, 13:00–17:00).">8h week</span>'
                    : '';
                if (log && !isLeave) {
                    const isSaturday = new Date(parseInt(year, 10), parseInt(month, 10) - 1, day).getDay() === 6;
                    let official = isSaturday ? satOfficial : regOfficial;
                    if (dtrOfficialByDate && dtrOfficialByDate[dateKey]) {
                        const od = dtrOfficialByDate[dateKey];
                        official = {
                            lunchOut: typeof od.lunch_out === 'number' ? od.lunch_out : 720,
                            lunchIn: typeof od.lunch_in === 'number' ? od.lunch_in : 780,
                            timeOut: typeof od.time_out === 'number' ? od.time_out : 1020
                        };
                    }
                    let undertimeMinutes = 0;
                    const hasLunchOut = lunchOut && String(lunchOut).trim();
                    const hasLunchIn = lunchIn && String(lunchIn).trim();
                    const hasTimeOut = timeOut && String(timeOut).trim();
                    if (hasLunchOut) {
                        const actualLunchOut = parseTime(lunchOut);
                        if (actualLunchOut !== null && actualLunchOut < official.lunchOut) undertimeMinutes += official.lunchOut - actualLunchOut;
                    }
                    if (hasTimeOut) {
                        const actualOut = parseTime(timeOut);
                        if (actualOut !== null && actualOut < official.timeOut) undertimeMinutes += official.timeOut - actualOut;
                    } else if (hasLunchIn) {
                        undertimeMinutes += official.timeOut - official.lunchIn;
                    }
                    if (undertimeMinutes > 0) {
                        utHrs = String(Math.floor(undertimeMinutes / 60));
                        utMin = String(undertimeMinutes % 60);
                    }
                }
                const tr = document.createElement('tr');
                tr.innerHTML = '<td class="dtr-day">' + day + hw8Badge + '</td><td class="dtr-time">' + timeIn + '</td><td class="dtr-time">' + lunchOut + '</td><td class="dtr-time">' + lunchIn + '</td><td class="dtr-time">' + timeOut + '</td><td class="dtr-undertime">' + utHrs + '</td><td class="dtr-undertime">' + utMin + '</td>';
                tbody.appendChild(tr);
            }
            const totalRow = document.createElement('tr');
            totalRow.className = 'dtr-total';
            totalRow.innerHTML = '<td class="dtr-day">Total</td><td></td><td></td><td></td><td></td><td></td><td></td>';
            tbody.appendChild(totalRow);
        }
        
        function viewOfficialTimes() {
            const modal = new bootstrap.Modal(document.getElementById('viewOfficialTimesModal'));
            modal.show();
            
            // Show loading, hide content
            document.getElementById('officialTimesLoading').style.display = 'block';
            document.getElementById('officialTimesContent').style.display = 'none';
            document.getElementById('noOfficialTimes').style.display = 'none';
            
            // Fetch official times
            fetch(`../admin/manage_official_times_api.php?action=get&employee_id=${encodeURIComponent(employeeId)}`, { credentials: 'same-origin' })
                .then(response => response.json())
                .then(data => {
                    document.getElementById('officialTimesLoading').style.display = 'none';
                    
                    if (data.success && data.official_times && data.official_times.length > 0) {
                        const tbody = document.getElementById('officialTimesTableBody');
                        tbody.innerHTML = '';
                        
                        const labels = ['Start Date', 'End Date', 'Weekday', 'Time In', 'Lunch Out', 'Lunch In', 'Time Out'];
                        data.official_times.forEach(ot => {
                            const endDateHtml = ot.end_date || '<span class="text-success">Ongoing</span>';
                            const row = document.createElement('tr');
                            row.innerHTML = `
                                <td data-label="${labels[0]}"><span class="official-times-value">${ot.start_date || '-'}</span></td>
                                <td data-label="${labels[1]}"><span class="official-times-value">${endDateHtml}</span></td>
                                <td data-label="${labels[2]}"><span class="official-times-value">${ot.weekday || '-'}</span></td>
                                <td data-label="${labels[3]}"><span class="official-times-value">${ot.time_in || '-'}</span></td>
                                <td data-label="${labels[4]}"><span class="official-times-value">${ot.lunch_out || '-'}</span></td>
                                <td data-label="${labels[5]}"><span class="official-times-value">${ot.lunch_in || '-'}</span></td>
                                <td data-label="${labels[6]}"><span class="official-times-value">${ot.time_out || '-'}</span></td>
                            `;
                            tbody.appendChild(row);
                        });
                        
                        document.getElementById('officialTimesContent').style.display = 'block';
                    } else {
                        document.getElementById('noOfficialTimes').style.display = 'block';
                    }
                })
                .catch(error => {
                    console.error('Error fetching official times:', error);
                    document.getElementById('officialTimesLoading').style.display = 'none';
                    document.getElementById('noOfficialTimes').style.display = 'block';
                    document.getElementById('noOfficialTimes').innerHTML = `
                        <i class="fas fa-exclamation-triangle fa-2x mb-3 text-danger"></i>
                        <p class="text-danger">Error loading official times. Please try again later.</p>
                    `;
                });
        }
        
        function editLog(logId, logDate, timeIn, lunchOut, lunchIn, timeOut) {
            document.getElementById('editLogId').value = logId;
            document.getElementById('editLogDate').value = logDate;
            document.getElementById('editOriginalTimeIn').value = timeIn || '';
            document.getElementById('editOriginalLunchOut').value = lunchOut || '';
            document.getElementById('editOriginalLunchIn').value = lunchIn || '';
            document.getElementById('editOriginalTimeOut').value = timeOut || '';
            document.getElementById('editReason').value = '';
            const editModal = new bootstrap.Modal(document.getElementById('editLogModal'));
            editModal.show();
        }
        
        // Function to show notification modal
        function showNotification(type, title, message, callback) {
            const modal = document.getElementById('notificationModal');
            const icon = document.getElementById('notificationIcon');
            const titleEl = document.getElementById('notificationTitle');
            const messageEl = document.getElementById('notificationMessage');
            const okBtn = document.getElementById('notificationOkBtn');
            
            // Remove all icon classes
            icon.className = 'notification-icon';
            
            // Set icon and styling based on type
            switch(type) {
                case 'success':
                    icon.className += ' success';
                    icon.innerHTML = '<i class="fas fa-check-circle"></i>';
                    okBtn.className = 'btn btn-success';
                    break;
                case 'error':
                    icon.className += ' error';
                    icon.innerHTML = '<i class="fas fa-times-circle"></i>';
                    okBtn.className = 'btn btn-danger';
                    break;
                case 'warning':
                    icon.className += ' warning';
                    icon.innerHTML = '<i class="fas fa-exclamation-triangle"></i>';
                    okBtn.className = 'btn btn-warning';
                    break;
                case 'info':
                default:
                    icon.className += ' info';
                    icon.innerHTML = '<i class="fas fa-info-circle"></i>';
                    okBtn.className = 'btn btn-primary';
                    break;
            }
            
            titleEl.textContent = title;
            messageEl.textContent = message;
            
            // Show modal
            const bsModal = new bootstrap.Modal(modal);
            bsModal.show();
            
            // Handle OK button click
            okBtn.onclick = function() {
                bsModal.hide();
                if (callback && typeof callback === 'function') {
                    callback();
                }
            };
            
            // Also close on backdrop click or ESC
            modal.addEventListener('hidden.bs.modal', function() {
                if (callback && typeof callback === 'function') {
                    callback();
                }
            }, { once: true });
        }
        
        function submitPardonRequest() {
            const logId = document.getElementById('editLogId').value;
            const logDate = document.getElementById('editLogDate').value;
            const pardonType = document.getElementById('pardonType').value;
            const reason = document.getElementById('editReason').value.trim();
            const supportingDocs = document.getElementById('editSupportingDoc').files;
            
            if (!reason) {
                showNotification('warning', 'Validation Error', 'Please provide a justification for this pardon request.', function() {
                    document.getElementById('editReason').focus();
                });
                return;
            }
            
            if (PARDON_TYPES_NEED_TIME.indexOf(pardonType) >= 0) {
                const timeIn = document.getElementById('editTimeIn').value;
                const timeOut = document.getElementById('editTimeOut').value;
                if (!timeIn || !timeOut) {
                    showNotification('warning', 'Validation Error', 'Please provide Time In and Time Out for this pardon type.');
                    return;
                }
            }
            
            if (!supportingDocs.length) {
                showNotification('warning', 'Validation Error', 'At least one supporting document is required.', function() {
                    document.getElementById('editSupportingDoc').focus();
                });
                return;
            }
            
            // Check file sizes (20MB max per file)
            if (supportingDocs.length > 0) {
                for (let i = 0; i < supportingDocs.length; i++) {
                    if (supportingDocs[i].size > 20 * 1024 * 1024) {
                        showNotification('error', 'File Size Error', `File "${supportingDocs[i].name}" exceeds 20MB limit.`);
                        return;
                    }
                }
            }
            
            const submitBtn = document.getElementById('submitPardonBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
            
            const formData = new FormData();
            formData.append('log_id', logId);
            formData.append('log_date', logDate);
            formData.append('pardon_type', pardonType);
            formData.append('reason', reason);
            
            if (PARDON_TYPES_NEED_TIME.indexOf(pardonType) >= 0) {
                formData.append('time_in', document.getElementById('editTimeIn').value);
                formData.append('lunch_out', document.getElementById('editLunchOut').value || '');
                formData.append('lunch_in', document.getElementById('editLunchIn').value || '');
                formData.append('time_out', document.getElementById('editTimeOut').value);
            }

            if (PARDON_TYPES_CALENDAR.indexOf(pardonType) >= 0) {
                const anchor = document.getElementById('editLogDate').value;
                const dates = Array.from(pardonSelectedDates);
                if (anchor && dates.indexOf(anchor) < 0) dates.push(anchor);
                dates.sort();
                formData.append('pardon_covered_dates', JSON.stringify(dates));
            }
            
            // Append all files
            for (let i = 0; i < supportingDocs.length; i++) {
                formData.append('supporting_documents[]', supportingDocs[i]);
            }
            
            fetch('submit_pardon_request.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('success', 'Success', 'Pardon request submitted successfully! It will be reviewed by admin.', function() {
                        bootstrap.Modal.getInstance(document.getElementById('editLogModal')).hide();
                        loadLogs();
                    });
                } else {
                    showNotification('error', 'Error', data.message || 'Failed to submit pardon request');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-check"></i> Submit Request';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('error', 'Error', 'Error submitting pardon request. Please try again.');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-check"></i> Submit Request';
            });
        }
    </script>
    
    <!-- Edit Log Modal -->
    <div class="modal fade" id="editLogModal" tabindex="-1" aria-labelledby="pardonModalTitle">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header py-2 py-md-3">
                    <h5 class="modal-title" id="pardonModalTitle">Submit Pardon Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="editLogId">
                    <input type="hidden" id="editLogDate">
                    <input type="hidden" id="editOriginalTimeIn">
                    <input type="hidden" id="editOriginalLunchOut">
                    <input type="hidden" id="editOriginalLunchIn">
                    <input type="hidden" id="editOriginalTimeOut">
                    
                    <div class="mb-2 mb-md-3">
                        <label class="form-label">Log Date</label>
                        <input type="date" id="editLogDateDisplay" class="form-control" readonly>
                    </div>
                    
                    <div class="mb-2 mb-md-3">
                        <label class="form-label">Type of Pardon <span class="text-danger">*</span></label>
                        <select id="pardonTypeSelect" class="form-select d-none d-md-block w-100 mb-0 mb-md-2" aria-label="Type of pardon">
                            <option value="ordinary_pardon">Ordinary Pardon</option>
                            <option value="tarf_ntarf">TARF / NTARF</option>
                            <option value="work_from_home">Work from Home</option>
                            <option value="vacation_leave">Vacation Leave</option>
                            <option value="sick_leave">Sick Leave</option>
                            <option value="special_privilege_leave">Special Privilege Leave</option>
                            <option value="forced_mandatory_leave">Forced / Mandatory Leave</option>
                            <option value="special_emergency_leave">Special Emergency Leave</option>
                            <option value="maternity_leave">Maternity Leave</option>
                            <option value="solo_parent_leave">Solo Parent Leave</option>
                            <option value="magna_carta_leave">Magna Carta Leave</option>
                            <option value="rehabilitation_leave">Rehabilitation Leave</option>
                            <option value="wellness_leave">Wellness Leave</option>
                        </select>
                        <div id="pardonTypeButtons" class="d-flex d-md-none flex-wrap gap-2">
                            <button type="button" class="btn btn-outline-primary btn-sm pardon-type-btn active" data-type="ordinary_pardon" title="Edit time entries">Ordinary Pardon</button>
                            <button type="button" class="btn btn-outline-primary btn-sm pardon-type-btn" data-type="tarf_ntarf" title="Edit time entries">TARF/NTARF</button>
                            <button type="button" class="btn btn-outline-primary btn-sm pardon-type-btn" data-type="work_from_home" title="Edit time entries">Work from Home</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm pardon-type-btn" data-type="vacation_leave">Vacation Leave</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm pardon-type-btn" data-type="sick_leave">Sick Leave</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm pardon-type-btn" data-type="special_privilege_leave">Special Privilege Leave</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm pardon-type-btn" data-type="forced_mandatory_leave">Forced/Mandatory Leave</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm pardon-type-btn" data-type="special_emergency_leave">Special Emergency Leave</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm pardon-type-btn" data-type="maternity_leave">Maternity Leave</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm pardon-type-btn" data-type="solo_parent_leave">Solo Parent Leave</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm pardon-type-btn" data-type="magna_carta_leave">Magna Carta Leave</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm pardon-type-btn" data-type="rehabilitation_leave">Rehabilitation Leave</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm pardon-type-btn" data-type="wellness_leave">Wellness Leave</button>
                        </div>
                        <input type="hidden" id="pardonType" value="ordinary_pardon">
                        <small class="text-muted d-block mt-1 pardon-type-hint d-none d-md-block">Ordinary Pardon, TARF/NTARF, and Work from Home require time entry editing. Leave types show LEAVE in DTR; rendered hours follow your official time.</small>
                        <small class="text-muted d-block mt-1 pardon-type-hint d-md-none">Ordinary/TARF/WFH: edit times. Leave: LEAVE on DTR, hours from official time.</small>
                    </div>
                    
                    <div id="pardonTimeSection" class="mb-2 mb-md-3">
                        <div class="row g-2">
                            <div class="col-6 col-md-3">
                                <label class="form-label">Time In <span class="text-danger">*</span></label>
                                <input type="time" id="editTimeIn" class="form-control">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label">Lunch Out</label>
                                <input type="time" id="editLunchOut" class="form-control">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label">Lunch In</label>
                                <input type="time" id="editLunchIn" class="form-control">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label">Time Out <span class="text-danger">*</span></label>
                                <input type="time" id="editTimeOut" class="form-control">
                            </div>
                        </div>
                        <small class="text-muted d-none d-md-inline">Edit the times that will be applied when your pardon is approved. For TARF/NTARF and Work from Home with multiple days selected, the same times apply to each included date.</small>
                        <small class="text-muted d-md-none d-block mt-1">Times apply when approved; TARF/WFH applies same times to all selected days.</small>
                    </div>
                    
                    <div id="pardonLeaveNote" class="alert alert-info mb-2 mb-md-3 py-2" style="display: none;">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Leave:</strong> When approved, your DTR will show <strong>LEAVE</strong> for each date you include below; hours rendered use your official time. No time entries needed.
                    </div>
                    
                    <div id="pardonMultiDaySection" class="mb-2 mb-md-3" style="display: none;">
                        <label class="form-label">Days included</label>
                        <p class="small text-muted mb-2 mb-md-3 d-none d-md-block">For <strong>TARF/NTARF</strong>, <strong>Work from Home</strong>, and <strong>leave</strong> types, choose every calendar day this request should cover. The row you opened from is highlighted in blue and is always included. Your supervisor only needs to open pardon for <strong>that</strong> day. You can add any other dates; days without a DTR row yet are dashed—those rows are created when HR approves. Dates that already have another pending or approved pardon cannot be added.</p>
                        <p class="small text-muted mb-2 d-md-none">Tap any day to include it. <strong>Blue</strong> = this log (always included). Dashed days are not on your table yet; a row is added when HR approves if needed.</p>
                        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="pardonCalPrev" title="Previous month"><i class="fas fa-chevron-left"></i></button>
                            <span id="pardonCalMonthLabel" class="fw-medium text-center flex-grow-1"></span>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="pardonCalNext" title="Next month"><i class="fas fa-chevron-right"></i></button>
                        </div>
                        <div class="d-grid mb-1" style="grid-template-columns: repeat(7, 1fr); gap: 2px;" id="pardonCalWeekdays"></div>
                        <div id="pardonCalGrid" class="pardon-cal-grid d-grid" style="grid-template-columns: repeat(7, 1fr); gap: 4px;"></div>
                        <small class="text-muted d-block mt-2"><span id="pardonCalCount">1</span> day(s) selected</small>
                    </div>
                    
                    <div class="mb-2 mb-md-3">
                        <label class="form-label">Justification <span class="text-danger">*</span></label>
                        <textarea id="editReason" class="form-control" rows="3" placeholder="Why you need this pardon..." required></textarea>
                        <small class="text-muted d-none d-md-inline">This field is required. Please explain why you need this pardon.</small>
                        <small class="text-muted d-md-none">Required.</small>
                    </div>
                    
                    <div class="mb-2 mb-md-3">
                        <label class="form-label">Supporting files <span class="text-danger">*</span></label>
                        <input type="file" id="editSupportingDoc" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" multiple required>
                        <small class="text-muted d-none d-md-block">At least one file (PDF, DOC, DOCX, JPG, PNG). Max 20MB each. Multiple OK.</small>
                        <small class="text-muted d-md-none">PDF/DOC/DOCX/JPG/PNG, max 20MB each.</small>
                        <div id="filePreview" class="mt-2" style="display: none;">
                            <div id="fileList"></div>
                        </div>
                    </div>
                    
                    <div id="adminReviewSection" style="display: none;">
                        <div class="mb-2 mb-md-3">
                            <label class="form-label fw-bold">Admin Review</label>
                            <div id="adminReviewContent"></div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info py-2 mb-0" id="infoAlert">
                        <span class="d-none d-md-inline"><i class="fas fa-info-circle"></i> Your request will be reviewed by an administrator. The changes will only be applied if approved.</span>
                        <span class="d-md-none small"><i class="fas fa-info-circle me-1"></i>Reviewed by admin; changes apply if approved.</span>
                    </div>
                </div>
                <div class="modal-footer py-2 py-md-3 flex-column flex-sm-row gap-2 justify-content-sm-between align-items-stretch align-items-sm-center w-100">
                    <button type="button" class="btn btn-secondary order-2 order-sm-1" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" id="submitPardonBtn" class="btn btn-primary order-1 order-sm-2" onclick="submitPardonRequest()">
                        <i class="fas fa-check"></i> Submit Request
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Notification Modal -->
    <div class="modal fade" id="notificationModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="notificationTitle"></h5>
                </div>
                <div class="modal-body">
                    <div class="notification-icon" id="notificationIcon"></div>
                    <p class="notification-message" id="notificationMessage"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal" id="notificationOkBtn">OK</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- View Official Times Modal -->
    <div class="modal fade" id="viewOfficialTimesModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-scrollable modal-lg official-times-modal-dialog">
            <div class="modal-content">
                <div class="modal-header official-times-modal-header">
                    <h5 class="modal-title">My Official Times</h5>
                    <button type="button" class="btn-close official-times-modal-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body official-times-modal-body">
                    <div id="officialTimesLoading" class="text-center py-4 official-times-loading">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2 text-muted">Loading official times...</p>
                    </div>
                    <div id="officialTimesContent" style="display: none;" class="official-times-content">
                        <div class="table-responsive official-times-table-wrap">
                            <table class="table table-sm table-bordered official-times-table">
                                <thead class="table-light official-times-thead">
                                    <tr>
                                        <th>Start Date</th>
                                        <th>End Date</th>
                                        <th>Weekday</th>
                                        <th>Time In</th>
                                        <th>Lunch Out</th>
                                        <th>Lunch In</th>
                                        <th>Time Out</th>
                                    </tr>
                                </thead>
                                <tbody id="officialTimesTableBody">
                                    <!-- Official times will be loaded here -->
                                </tbody>
                            </table>
                        </div>
                        <div id="noOfficialTimes" class="text-center text-muted py-4 official-times-empty" style="display: none;">
                            <i class="fas fa-info-circle fa-2x mb-3"></i>
                            <p>No official times have been set for you yet.</p>
                            <p class="small">Please contact your administrator to set your official working hours.</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer official-times-modal-footer">
                    <button type="button" class="btn btn-secondary official-times-close-btn" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- DTR Modal (Daily Time Record - Civil Service Form No. 48) -->
    <div class="modal fade" id="dtrModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h5 class="modal-title">Employee Daily Time Record</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body position-relative">
                    <div id="dtrLoadingOverlay" class="dtr-loading-overlay d-none">
                        <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>
                        <p class="mt-2 mb-0 small text-muted">Loading time records...</p>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small mb-1" for="dtrMonth">Month</label>
                            <select id="dtrMonth" class="form-select form-select-sm" onchange="showDtrLoading(true); fetchDTRData();">
                                <?php for ($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?php echo str_pad($i, 2, '0', STR_PAD_LEFT); ?>" <?php echo ($i === (int)date('n')) ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $i, 1)); ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small mb-1" for="dtrYear">Year</label>
                            <select id="dtrYear" class="form-select form-select-sm" onchange="showDtrLoading(true); fetchDTRData();">
                                <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                                    <option value="<?php echo $y; ?>" <?php echo ($y === (int)date('Y')) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <div class="dtr-form-wrap">
                        <div class="dtr-form-title">Civil Service Form No. 48</div>
                        <div class="dtr-form-subtitle">DAILY TIME RECORD</div>
                        <div class="dtr-form-line">-----o0o-----</div>
                        <div class="dtr-field-row">(Name) <span id="dtrEmployeeName" class="dtr-field-inline"></span></div>
                        <div class="dtr-field-row">For the month of <span id="dtrMonthLabel" class="dtr-field-inline"></span></div>
                        <div class="dtr-official-row">Official hours for arrival and departure</div>
                        <div class="dtr-official-row">Regular days <span id="dtrOfficialRegular" class="dtr-field-inline">08:00-12:00, 13:00-17:00</span> Saturdays <span id="dtrOfficialSat" class="dtr-field-inline">—</span></div>
                        <div id="dtrHolidayWeekNotice" class="alert alert-primary py-2 px-3 small mb-2 d-none" role="status"><i class="fas fa-calendar-week me-1"></i>Dates labeled <strong>8h week</strong> fall in your <strong>current</strong> calendar week (Sun–Sat) that includes a holiday: late and undertime use the <strong>standard 8-hour day</strong> (08:00–12:00, 13:00–17:00). All other days use your saved official schedule.</div>
                        <div class="dtr-table-wrap">
                        <p class="dtr-scroll-hint"><i class="fas fa-arrows-alt-h me-1"></i>Scroll horizontally to see all columns</p>
                        <table class="dtr-table" role="grid">
                            <thead>
                                <tr>
                                    <th class="dtr-day">Day</th>
                                    <th colspan="2">A.M.</th>
                                    <th colspan="2">P.M.</th>
                                    <th colspan="2">Undertime</th>
                                </tr>
                                <tr>
                                    <th></th>
                                    <th class="dtr-time">Arrival</th>
                                    <th class="dtr-time">Departure</th>
                                    <th class="dtr-time">Arrival</th>
                                    <th class="dtr-time">Departure</th>
                                    <th class="dtr-undertime">Hours</th>
                                    <th class="dtr-undertime">Minutes</th>
                                </tr>
                            </thead>
                            <tbody id="dtrTableBody">
                                <!-- Rows 1-31 + Total filled by JS -->
                            </tbody>
                        </table>
                        </div>
                        <p class="dtr-certify">I certify on my honor that the above is a true and correct report of the hours of work performed, record of which was made daily at the time of arrival and departure from office.</p>
                        <p class="dtr-verified">VERIFIED as to the prescribed office hours:<br>In Charge<br><strong id="dtrInCharge" class="dtr-incharge"><?php echo htmlspecialchars(trim($dtrInCharge)); ?></strong></p>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        let pardonSelectedDates = new Set();
        let pardonCalViewYear = null;
        let pardonCalViewMonth = null;
        let pardonCalendarInteractive = true;

        function getPardonCalendarAnchor() {
            const el = document.getElementById('editLogDate');
            return el ? el.value : '';
        }

        function parseYmdPardon(s) {
            if (!s || !/^\d{4}-\d{2}-\d{2}$/.test(s)) return null;
            const p = s.split('-');
            return { y: parseInt(p[0], 10), m: parseInt(p[1], 10) - 1, d: parseInt(p[2], 10) };
        }

        function pardonDateMeta(dateStr) {
            const log = typeof allLogs !== 'undefined' ? allLogs.find(function(l) { return l.log_date === dateStr; }) : null;
            return {
                hasLoadedLog: !!log,
                pardon_status: log ? (log.pardon_status || null) : null
            };
        }

        function renderPardonCalendar() {
            const sec = document.getElementById('pardonMultiDaySection');
            const grid = document.getElementById('pardonCalGrid');
            const wk = document.getElementById('pardonCalWeekdays');
            if (!sec || !grid || !wk) return;
            if (sec.style.display === 'none') return;
            const anchor = getPardonCalendarAnchor();
            if (!anchor) return;
            const ap = parseYmdPardon(anchor);
            if (!ap) return;
            if (pardonCalViewYear == null || pardonCalViewMonth == null) {
                pardonCalViewYear = ap.y;
                pardonCalViewMonth = ap.m;
            }
            const y = pardonCalViewYear;
            const m = pardonCalViewMonth;
            const labelEl = document.getElementById('pardonCalMonthLabel');
            if (labelEl) {
                labelEl.textContent = new Date(y, m, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
            }
            const wdNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            wk.innerHTML = wdNames.map(function(w) { return '<div class="pardon-cal-weekday-h">' + w + '</div>'; }).join('');
            grid.innerHTML = '';
            const firstDow = new Date(y, m, 1).getDay();
            const lastDate = new Date(y, m + 1, 0).getDate();
            let i = 0;
            for (; i < firstDow; i++) {
                const el = document.createElement('div');
                el.className = 'pardon-cal-cell pardon-cal-pad';
                grid.appendChild(el);
            }
            for (let d = 1; d <= lastDate; d++) {
                const ds = y + '-' + String(m + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
                const cell = document.createElement('button');
                cell.type = 'button';
                cell.className = 'pardon-cal-cell btn btn-sm';
                cell.textContent = String(d);
                const meta = pardonDateMeta(ds);
                const isAnchor = ds === anchor;
                const selected = pardonSelectedDates.has(ds);
                if (isAnchor) {
                    pardonSelectedDates.add(ds);
                }
                if (!isAnchor && (meta.pardon_status === 'pending' || meta.pardon_status === 'approved')) {
                    cell.classList.add('pardon-cal-locked');
                    cell.disabled = true;
                    cell.title = 'This day already has a pardon request';
                } else if (isAnchor) {
                    cell.classList.add('pardon-cal-anchor', 'btn-primary');
                    cell.title = 'This row (always included)';
                    cell.disabled = true;
                } else if (selected) {
                    cell.classList.add('btn-primary');
                    cell.title = 'Click to remove';
                } else {
                    cell.classList.add('btn-outline-secondary');
                    if (!meta.hasLoadedLog) {
                        cell.classList.add('pardon-cal-adhoc');
                        cell.title = 'Include this date (DTR row is created when HR approves, if needed)';
                    } else {
                        cell.title = 'Click to include';
                    }
                }
                if (!cell.disabled && pardonCalendarInteractive && !isAnchor) {
                    cell.addEventListener('click', function() {
                        if (pardonSelectedDates.has(ds)) {
                            pardonSelectedDates.delete(ds);
                        } else {
                            pardonSelectedDates.add(ds);
                        }
                        renderPardonCalendar();
                    });
                }
                grid.appendChild(cell);
            }
            const cntEl = document.getElementById('pardonCalCount');
            if (cntEl) cntEl.textContent = String(pardonSelectedDates.size);
            const prevBtn = document.getElementById('pardonCalPrev');
            const nextBtn = document.getElementById('pardonCalNext');
            if (prevBtn) prevBtn.disabled = !pardonCalendarInteractive;
            if (nextBtn) nextBtn.disabled = !pardonCalendarInteractive;
        }

        // Update display date when modal is shown
        function timeToInputValue(timeStr) {
            if (!timeStr) return '';
            const s = String(timeStr).substring(0, 8);
            return s.substring(0, 5);
        }
        
        function setPardonTimesInputs(timeIn, lunchOut, lunchIn, timeOut) {
            document.getElementById('editTimeIn').value = timeToInputValue(timeIn);
            document.getElementById('editLunchOut').value = timeToInputValue(lunchOut);
            document.getElementById('editLunchIn').value = timeToInputValue(lunchIn);
            document.getElementById('editTimeOut').value = timeToInputValue(timeOut);
        }
        
        const PARDON_TYPES_NEED_TIME = ['ordinary_pardon', 'tarf_ntarf', 'work_from_home'];
        
        function updatePardonTypeUI() {
            const type = document.getElementById('pardonType').value;
            const needTime = PARDON_TYPES_NEED_TIME.indexOf(type) >= 0;
            const useCal = PARDON_TYPES_CALENDAR.indexOf(type) >= 0;
            document.getElementById('pardonTimeSection').style.display = needTime ? 'block' : 'none';
            document.getElementById('pardonLeaveNote').style.display = needTime ? 'none' : 'block';
            const md = document.getElementById('pardonMultiDaySection');
            if (md) {
                md.style.display = useCal ? 'block' : 'none';
                if (useCal) {
                    const anchor = getPardonCalendarAnchor();
                    if (anchor) pardonSelectedDates.add(anchor);
                    renderPardonCalendar();
                }
            }
        }

        function applyPardonTypeValue(type) {
            const hidden = document.getElementById('pardonType');
            const sel = document.getElementById('pardonTypeSelect');
            let resolved = type || 'ordinary_pardon';
            if (sel && sel.options.length) {
                const allowed = new Set(Array.from(sel.options, function(o) { return o.value; }));
                if (!allowed.has(resolved)) {
                    resolved = 'ordinary_pardon';
                }
                sel.value = resolved;
            }
            hidden.value = resolved;
            document.querySelectorAll('.pardon-type-btn').forEach(function(b) {
                const isSelected = b.dataset.type === resolved;
                b.classList.toggle('active', isSelected);
                b.classList.toggle('btn-primary', isSelected);
                b.classList.toggle('btn-outline-primary', !isSelected && (b.dataset.type === 'ordinary_pardon' || b.dataset.type === 'tarf_ntarf' || b.dataset.type === 'work_from_home'));
                b.classList.toggle('btn-outline-secondary', !isSelected);
            });
            updatePardonTypeUI();
        }
        
        document.getElementById('editLogModal').addEventListener('show.bs.modal', function() {
            const logDate = document.getElementById('editLogDate').value;
            const logId = document.getElementById('editLogId').value;
            pardonCalViewYear = null;
            pardonCalViewMonth = null;
            pardonCalendarInteractive = true;
            pardonSelectedDates = new Set(logDate ? [logDate] : []);
            document.getElementById('editLogDateDisplay').value = logDate;
            document.getElementById('editSupportingDoc').value = '';
            document.getElementById('filePreview').style.display = 'none';
            const pts = document.getElementById('pardonTypeSelect');
            if (pts) pts.disabled = false;
            applyPardonTypeValue('ordinary_pardon');
            
            // Hide admin review section initially
            document.getElementById('adminReviewSection').style.display = 'none';
            document.getElementById('adminReviewContent').innerHTML = '';
            document.getElementById('infoAlert').style.display = 'block';
            
            // Fetch pardon request details for this log
            fetch('fetch_my_logs_api.php?employee_id=' + encodeURIComponent(employeeId) + '&log_id=' + logId, { credentials: 'same-origin' })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.pardon) {
                        const pardon = data.pardon;
                        if (pardon.pardon_type) {
                            applyPardonTypeValue(pardon.pardon_type);
                        }
                        if (pardon.requested_time_in || pardon.requested_time_out) {
                            setPardonTimesInputs(pardon.requested_time_in, pardon.requested_lunch_out, pardon.requested_lunch_in, pardon.requested_time_out);
                        } else {
                            const weekday = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'][new Date(logDate + 'T12:00:00').getDay()];
                            fetch(`../admin/manage_official_times_api.php?action=get_by_date&employee_id=${encodeURIComponent(employeeId)}&date=${encodeURIComponent(logDate)}&weekday=${encodeURIComponent(weekday)}`, { credentials: 'same-origin' })
                                .then(r => r.json())
                                .then(ot => {
                                    if (ot.success && ot.official_time && ot.official_time.found) {
                                        const t = ot.official_time;
                                        setPardonTimesInputs(t.time_in ? t.time_in + ':00' : null, t.lunch_out ? t.lunch_out + ':00' : null, t.lunch_in ? t.lunch_in + ':00' : null, t.time_out ? t.time_out + ':00' : null);
                                    }
                                });
                        }
                        
                        // Display admin review notes if available
                        if (pardon.review_notes) {
                            // Escape HTML to prevent XSS
                            const escapeHtml = (text) => {
                                if (!text) return '';
                                const div = document.createElement('div');
                                div.textContent = text;
                                return div.innerHTML;
                            };
                            
                            const reviewedAt = escapeHtml(pardon.reviewed_at);
                            const reviewerName = escapeHtml(pardon.reviewer_name ? pardon.reviewer_name.trim() : '');
                            const reviewNotes = escapeHtml(pardon.review_notes).replace(/\n/g, '<br>');
                            
                            let reviewHtml = '';
                            if (pardon.status === 'approved') {
                                reviewHtml = `<div class="alert alert-success">
                                    <strong><i class="fas fa-check-circle me-2"></i>Approved</strong>
                                    ${reviewedAt ? `<br><small class="text-muted">Reviewed on: ${reviewedAt}</small>` : ''}
                                    ${reviewerName ? `<br><small class="text-muted">Reviewed by: ${reviewerName}</small>` : ''}
                                    <hr class="my-2">
                                    <strong>Admin Notes:</strong>
                                    <p class="mb-0 mt-2">${reviewNotes}</p>
                                </div>`;
                            } else if (pardon.status === 'rejected') {
                                reviewHtml = `<div class="alert alert-danger">
                                    <strong><i class="fas fa-times-circle me-2"></i>Rejected</strong>
                                    ${reviewedAt ? `<br><small class="text-muted">Reviewed on: ${reviewedAt}</small>` : ''}
                                    ${reviewerName ? `<br><small class="text-muted">Reviewed by: ${reviewerName}</small>` : ''}
                                    <hr class="my-2">
                                    <strong>Admin Notes:</strong>
                                    <p class="mb-0 mt-2">${reviewNotes}</p>
                                </div>`;
                            }
                            document.getElementById('adminReviewContent').innerHTML = reviewHtml;
                            document.getElementById('adminReviewSection').style.display = 'block';
                            document.getElementById('infoAlert').style.display = 'none';
                        }
                        
                        if (pardon.status === 'pending') {
                            document.getElementById('pardonModalTitle').textContent = 'Pardon Request Pending';
                            document.getElementById('submitPardonBtn').disabled = true;
                            document.getElementById('submitPardonBtn').innerHTML = '<i class="fas fa-clock"></i> Request Under Review';
                            document.getElementById('editReason').disabled = true;
                            document.getElementById('editSupportingDoc').disabled = true;
                            document.querySelectorAll('.pardon-type-btn').forEach(b => b.disabled = true);
                            if (document.getElementById('pardonTypeSelect')) document.getElementById('pardonTypeSelect').disabled = true;
                            document.getElementById('editTimeIn').disabled = true;
                            document.getElementById('editLunchOut').disabled = true;
                            document.getElementById('editLunchIn').disabled = true;
                            document.getElementById('editTimeOut').disabled = true;
                        } else if (pardon.status === 'approved') {
                            document.getElementById('pardonModalTitle').textContent = 'Pardon Request Approved';
                            document.getElementById('submitPardonBtn').disabled = true;
                            document.getElementById('submitPardonBtn').innerHTML = '<i class="fas fa-check-circle"></i> Already Approved';
                            document.getElementById('editReason').disabled = true;
                            document.getElementById('editSupportingDoc').disabled = true;
                            document.querySelectorAll('.pardon-type-btn').forEach(b => b.disabled = true);
                            if (document.getElementById('pardonTypeSelect')) document.getElementById('pardonTypeSelect').disabled = true;
                            document.getElementById('editTimeIn').disabled = true;
                            document.getElementById('editLunchOut').disabled = true;
                            document.getElementById('editLunchIn').disabled = true;
                            document.getElementById('editTimeOut').disabled = true;
                        } else if (pardon.status === 'rejected') {
                            document.getElementById('pardonModalTitle').textContent = 'Resubmit Pardon Request';
                            document.getElementById('submitPardonBtn').disabled = false;
                            document.getElementById('submitPardonBtn').innerHTML = '<i class="fas fa-redo"></i> Resubmit Request';
                            document.getElementById('editReason').disabled = false;
                            document.getElementById('editSupportingDoc').disabled = false;
                            document.querySelectorAll('.pardon-type-btn').forEach(b => b.disabled = false);
                            if (document.getElementById('pardonTypeSelect')) document.getElementById('pardonTypeSelect').disabled = false;
                            const needTime = PARDON_TYPES_NEED_TIME.indexOf(pardon.pardon_type || 'ordinary_pardon') >= 0;
                            document.getElementById('editTimeIn').disabled = !needTime;
                            document.getElementById('editLunchOut').disabled = !needTime;
                            document.getElementById('editLunchIn').disabled = !needTime;
                            document.getElementById('editTimeOut').disabled = !needTime;
                        }
                        if (pardon.covered_dates && pardon.covered_dates.length) {
                            pardonSelectedDates = new Set(pardon.covered_dates);
                        }
                        pardonSelectedDates.add(logDate);
                        pardonCalendarInteractive = (pardon.status === 'rejected');
                        renderPardonCalendar();
                    } else {
                        // No pardon request - enable everything, fetch official times for display
                        document.getElementById('pardonModalTitle').textContent = 'Submit Pardon Request';
                        document.getElementById('submitPardonBtn').disabled = false;
                        document.getElementById('submitPardonBtn').innerHTML = '<i class="fas fa-check"></i> Submit Request';
                        document.getElementById('editReason').disabled = false;
                        document.getElementById('editSupportingDoc').disabled = false;
                        if (document.getElementById('pardonTypeSelect')) document.getElementById('pardonTypeSelect').disabled = false;
                        const weekday = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'][new Date(logDate + 'T12:00:00').getDay()];
                        pardonSelectedDates = new Set(logDate ? [logDate] : []);
                        pardonCalendarInteractive = true;
                        renderPardonCalendar();
                        fetch(`../admin/manage_official_times_api.php?action=get_by_date&employee_id=${encodeURIComponent(employeeId)}&date=${encodeURIComponent(logDate)}&weekday=${encodeURIComponent(weekday)}`, { credentials: 'same-origin' })
                            .then(r => r.json())
                            .then(ot => {
                                if (ot.success && ot.official_time && ot.official_time.found) {
                                    const t = ot.official_time;
                                    setPardonTimesInputs(t.time_in ? t.time_in + ':00' : null, t.lunch_out ? t.lunch_out + ':00' : null, t.lunch_in ? t.lunch_in + ':00' : null, t.time_out ? t.time_out + ':00' : null);
                                }
                            });
                    }
                })
                .catch(error => {
                    console.error('Error checking pardon status:', error);
                    document.getElementById('pardonModalTitle').textContent = 'Submit Pardon Request';
                    document.getElementById('submitPardonBtn').disabled = false;
                    document.getElementById('submitPardonBtn').innerHTML = '<i class="fas fa-check"></i> Submit Request';
                    pardonSelectedDates = new Set(logDate ? [logDate] : []);
                    pardonCalendarInteractive = true;
                    renderPardonCalendar();
                    if (document.getElementById('pardonTypeSelect')) document.getElementById('pardonTypeSelect').disabled = false;
                });
        });
        
        document.querySelectorAll('.pardon-type-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                applyPardonTypeValue(this.dataset.type);
            });
        });

        (function() {
            const el = document.getElementById('pardonTypeSelect');
            if (el) {
                el.addEventListener('change', function() {
                    applyPardonTypeValue(this.value);
                });
            }
        })();

        document.getElementById('pardonCalPrev').addEventListener('click', function() {
            if (!pardonCalendarInteractive) return;
            const anchor = getPardonCalendarAnchor();
            const a = parseYmdPardon(anchor);
            if (pardonCalViewYear == null && a) {
                pardonCalViewYear = a.y;
                pardonCalViewMonth = a.m;
            }
            if (pardonCalViewYear == null) return;
            pardonCalViewMonth--;
            if (pardonCalViewMonth < 0) {
                pardonCalViewMonth = 11;
                pardonCalViewYear--;
            }
            renderPardonCalendar();
        });
        document.getElementById('pardonCalNext').addEventListener('click', function() {
            if (!pardonCalendarInteractive) return;
            const anchor = getPardonCalendarAnchor();
            const a = parseYmdPardon(anchor);
            if (pardonCalViewYear == null && a) {
                pardonCalViewYear = a.y;
                pardonCalViewMonth = a.m;
            }
            if (pardonCalViewYear == null) return;
            pardonCalViewMonth++;
            if (pardonCalViewMonth > 11) {
                pardonCalViewMonth = 0;
                pardonCalViewYear++;
            }
            renderPardonCalendar();
        });
        
        document.getElementById('editLogModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('editReason').value = '';
            document.getElementById('editSupportingDoc').value = '';
            document.getElementById('filePreview').style.display = 'none';
            document.getElementById('editReason').disabled = false;
            document.getElementById('editSupportingDoc').disabled = false;
            document.querySelectorAll('.pardon-type-btn').forEach(b => b.disabled = false);
            if (document.getElementById('pardonTypeSelect')) document.getElementById('pardonTypeSelect').disabled = false;
            document.getElementById('editTimeIn').disabled = false;
            document.getElementById('editLunchOut').disabled = false;
            document.getElementById('editLunchIn').disabled = false;
            document.getElementById('editTimeOut').disabled = false;
        });
        
        // Show file preview when files are selected
        document.getElementById('editSupportingDoc').addEventListener('change', function(e) {
            const files = e.target.files;
            const fileList = document.getElementById('fileList');
            
            if (files.length > 0) {
                fileList.innerHTML = '';
                for (let i = 0; i < files.length; i++) {
                    const file = files[i];
                    const fileItem = document.createElement('div');
                    fileItem.className = 'mb-1';
                    fileItem.innerHTML = `<small class="text-success"><i class="fas fa-file"></i> ${file.name} (${(file.size / 1024).toFixed(2)} KB)</small>`;
                    fileList.appendChild(fileItem);
                }
                document.getElementById('filePreview').style.display = 'block';
            } else {
                document.getElementById('filePreview').style.display = 'none';
            }
        });
    </script>
</body>
</html>

