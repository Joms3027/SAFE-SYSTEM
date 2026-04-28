<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireFaculty();

$database = Database::getInstance();
$db = $database->getConnection();

// Get current month and year
$currentMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$currentYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Get first and last day of the month
$firstDay = date('Y-m-01', mktime(0, 0, 0, $currentMonth, 1, $currentYear));
$lastDay = date('Y-m-t', mktime(0, 0, 0, $currentMonth, 1, $currentYear));

// Get month name
$monthName = date('F Y', mktime(0, 0, 0, $currentMonth, 1, $currentYear));

// Calculate previous and next month
$prevMonth = $currentMonth - 1;
$prevYear = $currentYear;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
}

$nextMonth = $currentMonth + 1;
$nextYear = $currentYear;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}

// Get base path for JavaScript calendar navigation
$basePath = getBasePath();
$calendarUrl = clean_url($basePath . '/faculty/calendar.php', $basePath);
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
    <title>Calendar - WPU Faculty System</title>
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
        /* Calendar page: spacing and safe areas for mobile */
        .calendar-page .page-header.calendar-page-header {
            margin-bottom: 0.5rem;
        }
        .calendar-page .calendar-page-subtitle {
            margin: 0.25rem 0 0;
            font-size: 0.9375rem;
            color: var(--text-muted, #64748b);
        }
        @media (max-width: 768px) {
            .calendar-page .page-header.calendar-page-header {
                padding-left: 0;
                padding-right: 0;
            }
            .calendar-page .page-title {
                font-size: 1.35rem;
            }
            .calendar-page .calendar-page-subtitle {
                font-size: 0.875rem;
            }
        }
        @media (max-width: 480px) {
            .calendar-page .page-title {
                font-size: 1.2rem;
            }
        }
        @supports (padding: max(0px)) {
            .calendar-page .calendar-container {
                padding-left: max(0.5rem, env(safe-area-inset-left));
                padding-right: max(0.5rem, env(safe-area-inset-right));
                padding-bottom: max(1.5rem, env(safe-area-inset-bottom) + 1rem);
            }
        }
        .calendar-page .sr-only,
        .calendar-page .visually-hidden {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0,0,0,0);
            white-space: nowrap;
            border: 0;
        }
        .calendar-container {
            display: flex;
            gap: 28px;
            margin-top: 24px;
            align-items: flex-start;
        }
        
        .calendar-wrapper {
            flex: 1;
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            overflow: hidden;
            transition: box-shadow 0.3s ease;
            min-width: 0; /* Allow flex shrinking */
        }
        
        
        .calendar-header {
            background: linear-gradient(135deg, #003366 0%, #004080 100%);
            color: white;
            padding: 22px 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        @media (min-width: 1200px) {
            .calendar-header {
                padding: 24px 32px;
            }
        }
        
        .calendar-header h3 {
            margin: 0;
            font-size: 1.35rem;
            font-weight: 700;
            color: white;
            cursor: pointer;
            user-select: none;
            transition: transform 0.2s ease, opacity 0.2s ease;
            letter-spacing: -0.02em;
        }
        
        
        .calendar-header h3:active {
            transform: scale(0.97);
        }
        
        .calendar-nav {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .calendar-nav-btn {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            padding: 12px 16px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            min-width: 48px;
            min-height: 48px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .calendar-nav-btn:hover {
            background: rgba(255,255,255,0.35);
            transform: translateY(-2px) scale(1.05);
            box-shadow: 0 5px 10px rgba(0,0,0,0.2);
        }
        
        .calendar-nav-btn:active {
            transform: translateY(0) scale(0.98);
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }
        
        .calendar-nav-btn:focus {
            outline: 3px solid rgba(255,255,255,0.6);
            outline-offset: 3px;
        }
        
        .calendar-nav-btn:focus-visible {
            outline: 3px solid rgba(255,255,255,0.6);
            outline-offset: 3px;
        }
        
        .calendar-nav-btn .fa-chevron-left,
        .calendar-nav-btn .fa-chevron-right {
            font-size: 0.875rem;
        }
        
        .calendar-month-picker-btn {
            background: rgba(255,255,255,0.18);
            border: 1px solid rgba(255,255,255,0.25);
            color: white;
            padding: 8px 14px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 600;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            gap: 7px;
            margin-left: auto;
            margin-right: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            min-height: 36px;
        }
        
        .calendar-month-picker-btn:hover {
            background: rgba(255,255,255,0.28);
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(0,0,0,0.15);
        }
        
        .calendar-month-picker-btn:active {
            transform: translateY(0) scale(0.98);
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .calendar-month-picker-btn:focus {
            outline: 2px solid rgba(255,255,255,0.5);
            outline-offset: 2px;
        }
        
        .calendar-month-picker-btn i {
            font-size: 0.8rem;
        }
        
        .calendar-jump-today {
            background: rgba(255,255,255,0.95);
            color: #003366;
            border: 1px solid rgba(255,255,255,0.3);
            padding: 10px !important;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 700;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            white-space: nowrap;
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            gap: 6px;
            min-height: 36px;
        }
        
        .calendar-jump-today:hover {
            background: white;
            transform: translateY(-1px) scale(1.03);
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }
        
        .calendar-jump-today:active {
            transform: translateY(0) scale(0.97);
            box-shadow: 0 2px 4px rgba(0,0,0,0.15);
        }
        
        .calendar-jump-today:focus {
            outline: 2px solid rgba(255,255,255,0.6);
            outline-offset: 2px;
        }
        
        .calendar-jump-today i {
            font-size: 0.8rem;
        }
        
        /* Override any inline padding styles */
        .calendar-jump-today[style*="padding"],
        #jumpTodayBtn[style*="padding"] {
            padding: 0 !important;
        }
        
        .calendar-grid {
            padding: 24px 28px;
        }
        
        @media (min-width: 1200px) {
            .calendar-grid {
                padding: 28px 32px;
            }
        }
        
        .calendar-weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 8px;
            margin-bottom: 12px;
        }
        
        .calendar-weekday {
            text-align: center;
            font-weight: 700;
            color: #495057;
            padding: 14px 8px;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .calendar-days {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 8px;
            grid-auto-rows: 130px;
        }
        
        .calendar-day {
            height: 130px;
            border: 2px solid #e9ecef;
            padding: 10px;
            position: relative;
            cursor: pointer;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            background: white;
            display: flex;
            flex-direction: column;
            border-radius: 12px;
            overflow: hidden;
        }
        
        
        .calendar-day:focus-visible {
            outline: 3px solid #003366;
            outline-offset: 2px;
        }
        
        .calendar-day:focus {
            outline: 2px solid #003366;
            outline-offset: 2px;
        }
        
        .calendar-day.other-month {
            background: #f9f9f9;
            color: #adb5bd;
            opacity: 0.6;
        }
        
        .calendar-day.today {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            border: 3px solid #2196f3;
            box-shadow: 0 4px 16px rgba(33, 150, 243, 0.25);
            position: relative;
            font-weight: 600;
        }
        
        .calendar-day.today::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #2196f3, #64b5f6);
        }
        
        
        .calendar-day-number {
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 0.9rem;
            flex-shrink: 0;
            padding: 4px;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            line-height: 1;
            background: transparent;
            color: #333;
        }
        
        .calendar-day.today .calendar-day-number {
            background: linear-gradient(135deg, #2196f3 0%, #1976d2 100%);
            color: white;
            font-weight: 800;
            box-shadow: 0 3px 8px rgba(33, 150, 243, 0.4);
            width: 32px;
            height: 32px;
            font-size: 0.95rem;
        }
        
        
        .calendar-day-events {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 2px;
            min-height: 0;
            max-height: 100%;
            overflow: hidden;
        }
        
        .calendar-event {
            font-size: 0.72rem;
            padding: 0px 8px;
            border-radius: 6px;
            color: white;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            cursor: pointer;
            flex-shrink: 0;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(255,255,255,0.3);
            font-weight: 600;
            box-shadow: 0 2px 4px rgba(0,0,0,0.15);
            height: 22px;
            line-height: 22px;
            max-height: 22px;
            position: relative;
        }
        
        
        .calendar-event:focus-visible {
            outline: 2px solid rgba(255,255,255,0.8);
            outline-offset: 2px;
        }
        
        .calendar-event:active {
            transform: translateX(0) scale(0.96);
        }
        
        .calendar-event:focus {
            outline: 2px solid rgba(255,255,255,0.5);
            outline-offset: 1px;
        }
        
        .calendar-event-more {
            font-size: 0.65rem;
            padding: 2px 5px;
            border-radius: 3px;
            background: linear-gradient(135deg, #666 0%, #555 100%);
            color: white;
            cursor: pointer;
            text-align: center;
            font-weight: 600;
            transition: all 0.2s;
            border: 1px solid rgba(255,255,255,0.1);
            height: 16px;
            line-height: 16px;
            max-height: 16px;
            flex-shrink: 0;
        }
        
        /* Hover effect removed for faculty pages */
        
        .calendar-day-events::-webkit-scrollbar {
            width: 4px;
        }
        
        .calendar-day-events::-webkit-scrollbar-track {
            background: transparent;
        }
        
        .calendar-day-events::-webkit-scrollbar-thumb {
            background: #ccc;
            border-radius: 2px;
        }
        
        
        .event-list-wrapper {
            width: 440px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
            max-height: calc(100vh - 200px);
            transition: box-shadow 0.3s ease;
            flex-shrink: 0;
        }
        
        
        /* Desktop improvements */
        @media (min-width: 1200px) {
            .calendar-container {
                gap: 32px;
            }
            
            .event-list-wrapper {
                width: 480px;
            }
            
            .calendar-days {
                grid-auto-rows: 140px;
            }
            
            .calendar-day {
                height: 140px;
            }
            
            .calendar-grid {
                padding: 28px;
            }
            
            .event-list-header {
                padding: 24px 28px;
            }
            
            .event-list-content {
                padding: 24px;
            }
            
            .event-card {
                padding: 20px;
                margin-bottom: 18px;
            }
        }
        
        /* Medium screens (tablets in landscape) */
        @media (min-width: 769px) and (max-width: 1023px) {
            .calendar-container {
                gap: 24px;
            }
            
            .event-list-wrapper {
                width: 380px;
            }
            
            .calendar-days {
                grid-auto-rows: 115px;
            }
            
            .calendar-day {
                height: 115px;
                min-height: 115px;
            }
        }
        
        .event-list-header {
            padding: 22px 28px;
            border-bottom: 2px solid #e9ecef;
            display: flex;
            align-items: center;
            gap: 12px;
            background: linear-gradient(135deg, rgba(0,51,102,0.02) 0%, rgba(0,51,102,0.05) 100%);
        }
        .event-list-toggle-btn {
            width: 100%;
            border: none;
            background: linear-gradient(135deg, rgba(0,51,102,0.04) 0%, rgba(0,51,102,0.08) 100%);
            cursor: pointer;
            text-align: left;
            padding: 18px 20px;
            border-radius: 0;
            border-bottom: 2px solid #e9ecef;
            color: #003366;
            font: inherit;
            min-height: 56px;
            touch-action: manipulation;
            -webkit-tap-highlight-color: rgba(0,51,102,0.1);
            transition: background 0.2s ease;
        }
        .event-list-toggle-btn:hover,
        .event-list-toggle-btn:focus-visible {
            background: linear-gradient(135deg, rgba(0,51,102,0.08) 0%, rgba(0,51,102,0.12) 100%);
            outline: 2px solid #003366;
            outline-offset: 2px;
        }
        .event-list-toggle-btn .event-list-toggle-icon {
            margin-left: auto;
            transition: transform 0.25s ease;
            color: #64748b;
        }
        .event-list-wrapper.event-list-collapsed .event-list-toggle-icon {
            transform: rotate(-90deg);
        }
        .event-list-wrapper.event-list-collapsed .event-list-content {
            display: none !important;
        }
        @media (max-width: 767.98px) {
            .event-list-wrapper.event-list-collapsed {
                border-radius: 0 0 20px 20px;
            }
            .event-list-wrapper.event-list-collapsed .event-list-toggle-btn {
                border-radius: 0 0 20px 20px;
                border-bottom: none;
            }
        }
        
        @media (min-width: 1200px) {
            .event-list-header {
                padding: 24px 32px;
            }
        }
        
        .event-list-header h4 {
            margin: 0;
            font-size: 1.2rem;
            font-weight: 700;
            color: #003366;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .event-list-header i {
            color: #003366;
            font-size: 1.1rem;
        }
        
        .event-list-content {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            scrollbar-width: thin;
            scrollbar-color: rgba(0,0,0,0.2) transparent;
        }
        
        @media (min-width: 1200px) {
            .event-list-content {
                padding: 24px;
            }
        }
        
        .event-list-content::-webkit-scrollbar {
            width: 6px;
        }
        
        .event-list-content::-webkit-scrollbar-track {
            background: transparent;
        }
        
        .event-list-content::-webkit-scrollbar-thumb {
            background: rgba(0,0,0,0.2);
            border-radius: 3px;
        }
        
        
        .event-card {
            background: white;
            border: 2px solid #e9ecef;
            border-left: 5px solid #2196f3;
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 18px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }
        
        .event-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: linear-gradient(180deg, #2196f3, #64b5f6);
            transition: width 0.3s ease;
        }
        
        
        .event-card:active {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.12);
        }
        
        .event-card:focus {
            outline: 3px solid #2196f3;
            outline-offset: 3px;
        }
        
        .event-card:focus-visible {
            outline: 3px solid #2196f3;
            outline-offset: 3px;
        }
        
        .event-card-title {
            font-weight: 700;
            font-size: 1.05rem;
            margin-bottom: 12px;
            color: #003366;
            line-height: 1.4;
        }
        
        .event-card-tags {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }
        
        .event-tag {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .event-tag.training {
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .event-tag.workshop {
            background: #e8f5e9;
            color: #388e3c;
        }
        
        .event-tag.seminar {
            background: #f3e5f5;
            color: #7b1fa2;
        }
        
        .event-tag.holiday {
            background: #ffebee;
            color: #c62828;
        }
        
        .event-tag.ongoing {
            background: #e8f5e9;
            color: #2e7d32;
        }
        
        .event-tag.upcoming {
            background: #f5f5f5;
            color: #616161;
        }
        
        .event-card-details {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 10px;
        }
        
        .event-detail {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.875rem;
            color: #495057;
            line-height: 1.5;
        }
        
        .event-detail i {
            width: 18px;
            color: #6c757d;
            font-size: 0.9rem;
            text-align: center;
            flex-shrink: 0;
        }
        
        .event-details-modal .modal-body {
            padding: 20px;
        }
        
        .event-details-modal .event-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 10px;
            color: #333;
        }
        
        .event-details-modal .event-date {
            color: #666;
            margin-bottom: 15px;
        }
        
        .event-details-modal .event-description {
            color: #555;
            line-height: 1.6;
        }
        
        .day-events-modal .modal-body {
            padding: 20px;
            max-height: 600px;
            overflow-y: auto;
        }
        
        .day-events-modal .day-events-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .day-events-modal .day-events-date {
            font-size: 1.25rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }
        
        .day-events-modal .day-events-count {
            color: #666;
            font-size: 0.875rem;
        }
        
        .day-events-modal .day-event-item {
            background: #f9f9f9;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: box-shadow 0.2s;
        }
        
        
        .day-events-modal .day-event-title {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 8px;
            color: #333;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .day-events-modal .day-event-color {
            width: 12px;
            height: 12px;
            border-radius: 3px;
            flex-shrink: 0;
        }
        
        .day-events-modal .day-event-details {
            display: flex;
            flex-direction: column;
            gap: 5px;
            font-size: 0.875rem;
            color: #666;
        }
        
        .day-events-modal .day-event-detail {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .day-events-modal .day-event-detail i {
            width: 16px;
            color: #999;
        }
        
        /* ==========================================
           MOBILE RESPONSIVE STYLES
           ========================================== */
        
        /* Tablet and below - improved layout */
        @media (max-width: 1024px) {
            .calendar-container {
                flex-direction: column;
                gap: 20px;
                margin-top: 20px;
            }
            
            .event-list-wrapper {
                width: 100%;
                max-height: 500px;
                order: -1; /* Show event list first on mobile */
                border-radius: 16px;
            }
            
            .calendar-wrapper {
                border-radius: 16px;
            }
            
            .calendar-grid {
                padding: 20px;
            }
            
            .calendar-days {
                grid-auto-rows: 120px;
            }
            
            .calendar-day {
                height: 120px;
                min-height: 120px;
            }
            
            .calendar-header {
                padding: 18px 20px;
            }
            
            .event-list-header {
                padding: 18px 20px;
            }
            
            .event-list-content {
                padding: 18px;
            }
            
            .event-card {
                padding: 16px;
                margin-bottom: 14px;
            }
        }
        
        /* Swipe indicator */
        .swipe-indicator {
            position: absolute;
            bottom: 8px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 4px;
            opacity: 0.6;
            transition: opacity 0.3s ease;
        }
        
        .swipe-indicator-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: rgba(255,255,255,0.6);
            animation: pulse 2s infinite;
        }
        
        .swipe-indicator-dot:nth-child(2) {
            animation-delay: 0.2s;
        }
        
        .swipe-indicator-dot:nth-child(3) {
            animation-delay: 0.4s;
        }
        
        @keyframes pulse {
            0%, 100% {
                opacity: 0.6;
                transform: scale(1);
            }
            50% {
                opacity: 1;
                transform: scale(1.2);
            }
        }
        
        /* Loading skeleton */
        .calendar-skeleton {
            animation: skeleton-loading 1.5s ease-in-out infinite;
        }
        
        @keyframes skeleton-loading {
            0% {
                background-position: -200px 0;
            }
            100% {
                background-position: calc(200px + 100%) 0;
            }
        }
        
        .calendar-skeleton-day {
            background: linear-gradient(90deg, #f8f9fa 25%, #e9ecef 50%, #f8f9fa 75%);
            background-size: 200px 100%;
            border-radius: 12px;
            min-height: 70px;
            border: 2px solid #e9ecef;
        }
        
        @media (max-width: 768px) {
            .calendar-skeleton-day {
                border-radius: 14px;
                min-height: 75px;
            }
        }
        
        /* Month transition animation */
        .calendar-days.fade-out {
            animation: fadeOut 0.2s ease-out;
        }
        
        .calendar-days.fade-in {
            animation: fadeIn 0.3s ease-in;
        }
        
        @keyframes fadeOut {
            from {
                opacity: 1;
                transform: translateX(0);
            }
            to {
                opacity: 0;
                transform: translateX(-20px);
            }
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateX(20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        /* Empty state */
        .calendar-empty-state {
            text-align: center;
            padding: 80px 20px;
            color: #6c757d;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
        }
        
        .calendar-empty-state i {
            font-size: 3.5rem;
            color: #cbd5e1;
            margin-bottom: 8px;
            display: block;
            opacity: 0.6;
        }
        
        .calendar-empty-state h4 {
            font-size: 1.2rem;
            font-weight: 700;
            color: #495057;
            margin-bottom: 4px;
            line-height: 1.4;
        }
        
        .calendar-empty-state p {
            font-size: 0.95rem;
            color: #6c757d;
            margin: 0;
            max-width: 400px;
            line-height: 1.6;
        }
        
        @media (max-width: 768px) {
            .calendar-empty-state {
                padding: 60px 16px;
            }
            
            .calendar-empty-state i {
                font-size: 3rem;
            }
            
            .calendar-empty-state h4 {
                font-size: 1.1rem;
            }
            
            .calendar-empty-state p {
                font-size: 0.9rem;
            }
        }
        
        /* Mobile-specific styles (tablets and phones) - Enhanced UX */
        @media (max-width: 768px) {
            .calendar-container {
                gap: 16px;
                margin-top: 16px;
            }
            
            .calendar-wrapper {
                border-radius: 20px;
                box-shadow: 0 4px 16px rgba(0,0,0,0.1);
                overflow: hidden;
            }
            
            .calendar-header {
                padding: 20px 18px;
                flex-wrap: wrap;
                gap: 16px;
                position: sticky;
                top: 0;
                z-index: 1098;
                pointer-events: auto !important;
                isolation: isolate !important;
                transform: translateZ(0) !important;
                background: linear-gradient(135deg, #003366 0%, #004080 100%);
            }
            
            .calendar-header h3 {
                font-size: 1.3rem;
                flex: 1 1 100%;
                min-width: 100%;
                color: white;
                font-weight: 700;
                line-height: 1.3;
                margin-bottom: 4px;
                text-align: center;
            }
            
            .calendar-nav {
                gap: 10px;
                width: 100%;
                justify-content: center;
                flex-wrap: nowrap;
            }
            
            .calendar-month-picker-btn {
                display: none;
            }
            
            .calendar-jump-today {
                padding: 12px 16px !important;
                font-size: 0.85rem;
                display: flex;
                align-items: center;
                gap: 6px;
                flex: 0 0 auto;
                min-height: 48px;
                font-weight: 700;
            }
            
            .calendar-jump-today i {
                font-size: 0.8rem;
            }
            
            .swipe-indicator {
                position: absolute;
                bottom: 6px;
                left: 50%;
                transform: translateX(-50%);
                z-index: 1;
            }
            
            .calendar-nav-btn {
                padding: 12px 16px;
                min-width: 52px;
                min-height: 52px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 12px;
                font-size: 1.1rem;
                touch-action: manipulation;
                -webkit-tap-highlight-color: rgba(255,255,255,0.3);
                cursor: pointer;
                pointer-events: auto !important;
                position: relative;
                z-index: 1100 !important;
                user-select: none;
                -webkit-user-select: none;
                -webkit-touch-callout: none;
                isolation: isolate !important;
                transform: translateZ(0) !important;
                box-shadow: 0 3px 8px rgba(0,0,0,0.2);
                flex: 0 0 auto;
            }
            
            .calendar-nav-btn:active {
                transform: scale(0.95) translateY(1px);
                background: rgba(255,255,255,0.4);
                box-shadow: 0 2px 4px rgba(0,0,0,0.15);
            }
            
            .calendar-nav-btn:hover {
                background: rgba(255,255,255,0.35);
                box-shadow: 0 4px 10px rgba(0,0,0,0.25);
            }
            
            .calendar-nav-btn:focus {
                outline: 3px solid rgba(255,255,255,0.7);
                outline-offset: 3px;
            }
            
            .calendar-grid {
                padding: 16px 12px;
            }
            
            .calendar-weekdays {
                gap: 6px;
                margin-bottom: 10px;
            }
            
            .calendar-weekday {
                padding: 10px 6px;
                font-size: 0.8rem;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            
            .calendar-days {
                gap: 6px;
                grid-auto-rows: minmax(85px, 1fr);
            }
            
            .calendar-day {
                height: auto;
                min-height: 85px;
                padding: 10px 8px;
                border-radius: 14px;
                border-width: 2px;
                touch-action: manipulation;
                -webkit-tap-highlight-color: rgba(0,51,102,0.1);
                position: relative;
            }
            
            .calendar-day:active {
                transform: scale(0.97);
                background: #f0f4f8 !important;
                box-shadow: inset 0 2px 6px rgba(0,0,0,0.08);
            }
            
            .calendar-day.today {
                border-width: 3px;
                border-color: #2196f3;
                box-shadow: 0 0 0 4px rgba(33, 150, 243, 0.18), 0 3px 10px rgba(33, 150, 243, 0.25);
                background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            }
            
            .calendar-day.today:active {
                box-shadow: 0 0 0 3px rgba(33, 150, 243, 0.25), inset 0 2px 6px rgba(0,0,0,0.08);
                transform: scale(0.96);
            }
            
            .calendar-day-number {
                font-size: 1rem;
                font-weight: 700;
                padding: 6px;
                margin-bottom: 8px;
                width: 32px;
                height: 32px;
                line-height: 20px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .calendar-day.today .calendar-day-number {
                background: linear-gradient(135deg, #2196f3 0%, #1976d2 100%);
                color: white;
                box-shadow: 0 3px 10px rgba(33, 150, 243, 0.5);
                font-weight: 800;
                width: 36px;
                height: 36px;
                font-size: 1.05rem;
            }
            
            .calendar-day:active .calendar-day-number {
                transform: scale(0.92);
            }
            
            .calendar-day-events {
                gap: 4px;
            }
            
            .calendar-event {
                font-size: 0.7rem;
                padding: 0px 8px;
                border-radius: 6px;
                height: 20px;
                line-height: 20px;
                max-height: 20px;
                font-weight: 700;
                box-shadow: 0 2px 4px rgba(0,0,0,0.15);
                touch-action: manipulation;
                -webkit-tap-highlight-color: rgba(0, 51, 102, 0.25);
                transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            }
            
            .calendar-event:active {
                opacity: 0.85;
                transform: scale(0.94);
                box-shadow: 0 1px 2px rgba(0,0,0,0.12);
            }
            
            .calendar-event-more {
                font-size: 0.65rem;
                padding: 4px 7px;
                border-radius: 5px;
                height: 18px;
                line-height: 18px;
                max-height: 18px;
                font-weight: 700;
                touch-action: manipulation;
                -webkit-tap-highlight-color: rgba(0, 51, 102, 0.2);
                transition: opacity 0.1s ease;
            }
            
            .calendar-event-more:active {
                opacity: 0.8;
                transform: scale(0.95);
            }
            
            /* Event List Mobile Styles - Enhanced */
            .event-list-wrapper {
                border-radius: 20px;
                box-shadow: 0 6px 20px rgba(0,0,0,0.1);
                max-height: 500px;
            }
            
            .event-list-header {
                padding: 18px 20px;
                border-bottom: 2px solid #e9ecef;
            }
            
            .event-list-header h4 {
                font-size: 1.15rem;
                font-weight: 700;
            }
            
            .event-list-header i {
                font-size: 1.2rem;
            }
            
            .event-list-content {
                padding: 18px;
            }
            
            .event-card {
                padding: 18px;
                margin-bottom: 16px;
                border-radius: 16px;
                border-left-width: 6px;
                box-shadow: 0 4px 14px rgba(0,0,0,0.12);
                touch-action: manipulation;
                -webkit-tap-highlight-color: rgba(0, 51, 102, 0.2);
                transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
                background: white;
            }
            
            .event-card:active {
                transform: scale(0.98) translateY(2px);
                box-shadow: 0 3px 10px rgba(0,0,0,0.15);
                opacity: 0.95;
            }
            
            
            .event-card::before {
                width: 6px;
            }
            
            .event-card:active::before {
                width: 7px;
            }
            
            .event-card-title {
                font-size: 1rem;
                margin-bottom: 10px;
                line-height: 1.4;
            }
            
            .event-card-tags {
                gap: 8px;
                margin-bottom: 10px;
            }
            
            .event-tag {
                padding: 5px 12px;
                font-size: 0.75rem;
                border-radius: 6px;
                font-weight: 600;
            }
            
            .event-card-details {
                gap: 8px;
                margin-bottom: 0;
            }
            
            .event-detail {
                font-size: 0.85rem;
                gap: 10px;
            }
            
            .event-detail i {
                width: 16px;
                font-size: 0.8rem;
            }
        }
        
        /* Small mobile phones - Optimized for small screens */
        @media (max-width: 480px) {
            .calendar-header {
                padding: 18px 16px;
            }
            
            .calendar-header h3 {
                font-size: 1.2rem;
                width: 100%;
                margin-bottom: 12px;
                color: white;
                line-height: 1.3;
                text-align: center;
            }
            
            .calendar-nav {
                width: 100%;
                justify-content: space-between;
                gap: 10px;
            }
            
            .calendar-nav-btn {
                flex: 1;
                max-width: none;
                min-width: 48px;
                min-height: 48px;
                padding: 12px;
            }
            
            .calendar-jump-today {
                font-size: 0.8rem;
                padding: 12px 14px !important;
                min-height: 48px;
            }
            
            .calendar-grid {
                padding: 14px 10px;
            }
            
            /* Single-letter weekdays on very small screens for better fit */
            .calendar-weekday {
                padding: 8px 2px;
                font-size: 0;
                font-weight: 700;
            }
            .calendar-weekday::before {
                content: attr(data-short);
                font-size: 0.7rem;
            }
            
            .calendar-days {
                gap: 5px;
                grid-auto-rows: minmax(75px, 1fr);
            }
            
            .calendar-day {
                min-height: 75px;
                padding: 10px 6px;
                border-radius: 12px;
            }
            
            .calendar-day-number {
                font-size: 0.95rem;
                padding: 5px;
                width: 30px;
                height: 30px;
                line-height: 20px;
                font-weight: 700;
            }
            
            .calendar-day.today .calendar-day-number {
                width: 34px;
                height: 34px;
                font-size: 1rem;
            }
            
            .calendar-event {
                font-size: 0.68rem;
                padding: 5px 7px;
                height: 18px;
                line-height: 18px;
                max-height: 18px;
                font-weight: 700;
            }
            
            .calendar-event-more {
                font-size: 0.62rem;
                padding: 4px 6px;
                height: 17px;
                line-height: 17px;
                max-height: 17px;
            }
            
            .event-list-wrapper {
                max-height: 450px;
                border-radius: 18px;
            }
            
            .event-list-header {
                padding: 16px 18px;
            }
            
            .event-list-header h4 {
                font-size: 1.1rem;
            }
            
            .event-list-content {
                padding: 14px;
            }
            
            .event-card {
                padding: 14px;
                margin-bottom: 12px;
                border-radius: 14px;
            }
            
            .event-card-title {
                font-size: 0.95rem;
                margin-bottom: 10px;
            }
            
            .event-tag {
                padding: 3px 8px;
                font-size: 0.68rem;
            }
            
            .event-detail {
                font-size: 0.78rem;
                gap: 8px;
            }
        }
        
        /* CRITICAL FIX: Override Bootstrap modal CSS that blocks button clicks */
        .day-events-modal,
        .event-details-modal {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            z-index: 1130  !important;
            display: none !important;
            width: 100% !important;
            height: 100% !important;
            overflow-x: visible !important; /* Changed from hidden to visible */
            overflow-y: visible !important; /* Changed from auto to visible - let modal-dialog handle scrolling */
            outline: 0 !important;
            pointer-events: auto !important; /* Ensure modal allows interactions */
        }
        
        .day-events-modal.show,
        .day-events-modal.showing,
        .event-details-modal.show,
        .event-details-modal.showing {
            display: block !important;
            pointer-events: auto !important;
        }
        
        /* Desktop modal styles */
        .day-events-modal .modal-dialog,
        .event-details-modal .modal-dialog {
            pointer-events: auto !important;
            touch-action: manipulation !important;
            max-width: 600px !important;
            margin: 1.75rem auto !important;
        }
        
        @media (min-width: 992px) {
            .day-events-modal .modal-dialog {
                max-width: 800px !important;
            }
        }
        
        /* Ensure modal content is interactive */
        .day-events-modal .modal-content,
        .event-details-modal .modal-content {
            pointer-events: auto !important;
            touch-action: manipulation !important;
            position: relative !important;
            z-index: 1055 !important;
            display: flex !important;
            flex-direction: column !important;
            max-height: 85vh !important;
            overflow: hidden !important;
        }
        
        /* Desktop modal body */
        .day-events-modal .modal-body,
        .event-details-modal .modal-body {
            overflow-y: auto !important;
            overflow-x: hidden !important;
            flex: 1 1 auto !important;
            min-height: 0 !important;
            max-height: calc(85vh - 150px) !important;
        }
        
        /* Ensure all buttons in modals are clickable */
        .day-events-modal button,
        .day-events-modal .btn,
        .day-events-modal a.btn,
        .day-events-modal .btn-close,
        .event-details-modal button,
        .event-details-modal .btn,
        .event-details-modal a.btn,
        .event-details-modal .btn-close {
            pointer-events: auto !important;
            touch-action: manipulation !important;
            cursor: pointer !important;
            position: relative !important;
            z-index: 1056 !important;
            -webkit-tap-highlight-color: rgba(0, 51, 102, 0.2) !important;
        }
        
        /* Desktop: Block excessive padding on Close buttons in modal footer */
        .day-events-modal .modal-footer .btn-secondary,
        .day-events-modal .modal-footer button[data-bs-dismiss="modal"],
        .event-details-modal .modal-footer .btn-secondary,
        .event-details-modal .modal-footer button[data-bs-dismiss="modal"],
        .modal-footer .btn-secondary[style*="padding"],
        .modal-footer button[data-bs-dismiss="modal"][style*="padding"] {
            padding: 10px 20px !important;
            max-width: none !important;
            width: auto !important;
        }
        
        /* Ensure modal backdrop doesn't block */
        .day-events-modal .modal-backdrop,
        .event-details-modal .modal-backdrop {
            pointer-events: auto !important;
            z-index: 1040 !important;
        }
        
        /* Modal Mobile Styles - Bottom Sheet Style */
        @media (max-width: 768px) {
            .day-events-modal,
            .event-details-modal {
                overflow-x: visible !important;
                overflow-y: visible !important;
                pointer-events: auto !important;
            }
            
            .day-events-modal .modal-dialog,
            .event-details-modal .modal-dialog {
                margin: 0 !important;
                max-width: 100% !important;
                width: 100% !important;
                height: 100vh !important;
                max-height: 100vh !important;
                display: flex !important;
                align-items: flex-end !important;
                padding: 0 !important;
                overflow: visible !important;
                pointer-events: auto !important;
                touch-action: none !important;
            }
            
            .day-events-modal .modal-content,
            .event-details-modal .modal-content {
                border-radius: 24px 24px 0 0 !important;
                border: none;
                box-shadow: 0 -4px 20px rgba(0,0,0,0.15) !important;
                margin: 0 !important;
                width: 100% !important;
                max-height: 90vh !important;
                height: auto !important;
                display: flex !important;
                flex-direction: column !important;
                overflow: hidden !important;
                position: relative !important;
                padding-top: env(safe-area-inset-top);
            }
            
            .day-events-modal.show .modal-content,
            .event-details-modal.show .modal-content {
                animation: slideUp 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            }
            
            @keyframes slideUp {
                from {
                    transform: translateY(100%);
                    opacity: 0;
                }
                to {
                    transform: translateY(0);
                    opacity: 1;
                }
            }
            
            .day-events-modal .modal-header,
            .event-details-modal .modal-header {
                border-radius: 24px 24px 0 0;
                padding: 20px 20px 16px;
                border-bottom: 1px solid rgba(0,0,0,0.1);
                position: relative;
                flex-shrink: 0;
                min-height: auto;
                height: auto;
            }
            
            .day-events-modal .modal-header::before,
            .event-details-modal .modal-header::before {
                content: '';
                position: absolute;
                top: 8px;
                left: 50%;
                transform: translateX(-50%);
                width: 40px;
                height: 4px;
                background: rgba(255,255,255,0.4);
                border-radius: 2px;
                z-index: 1;
            }
            
            .day-events-modal .modal-body,
            .event-details-modal .modal-body {
                padding: 20px;
                overflow-y: auto !important;
                overflow-x: hidden !important;
                -webkit-overflow-scrolling: touch !important;
                overscroll-behavior: contain !important;
                flex: 1 1 auto !important;
                min-height: 0 !important;
                max-height: none !important;
                pointer-events: auto !important;
                touch-action: pan-y !important; /* Allow vertical scrolling but prevent horizontal */
                /* Calculate dynamic height: 90vh minus header and footer */
                max-height: calc(90vh - 120px) !important;
            }
            
            /* CRITICAL: Ensure buttons in modal body are always clickable */
            .day-events-modal .modal-body *,
            .event-details-modal .modal-body * {
                pointer-events: auto !important;
            }
            
            .day-events-modal .modal-body button,
            .day-events-modal .modal-body .btn,
            .day-events-modal .modal-body .day-event-item,
            .event-details-modal .modal-body button,
            .event-details-modal .modal-body .btn {
                pointer-events: auto !important;
                touch-action: manipulation !important;
                z-index: 1057 !important;
                position: relative !important;
            }
            
            .day-events-modal .modal-footer,
            .event-details-modal .modal-footer {
                padding: 16px 20px 20px;
                border-top: 1px solid rgba(0,0,0,0.05);
                background: #f8f9fa;
                border-radius: 0 0 24px 24px;
                flex-shrink: 0;
            }
            
            /* Modal backdrop adjustment */
            .day-events-modal .modal-backdrop,
            .event-details-modal .modal-backdrop {
                background-color: rgba(0, 0, 0, 0.5);
            }
            
            /* Swipe handle indicator */
            .modal-header .swipe-handle {
                position: absolute;
                top: 8px;
                left: 50%;
                transform: translateX(-50%);
                width: 40px;
                height: 4px;
                background: rgba(255,255,255,0.4);
                border-radius: 2px;
                z-index: 1;
            }
            
            .day-events-modal .day-events-header {
                margin-bottom: 16px;
                padding-bottom: 12px;
            }
            
            .day-events-modal .day-events-date {
                font-size: 1.1rem;
            }
            
            .day-events-modal .day-events-count {
                font-size: 0.8rem;
            }
            
            .day-events-modal .day-event-item {
                padding: 16px !important;
                margin-bottom: 12px !important;
                border-radius: 16px !important;
                touch-action: manipulation !important;
                -webkit-tap-highlight-color: rgba(0, 51, 102, 0.2) !important;
                transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
                background: white !important;
                border: 1px solid #e9ecef !important;
                box-shadow: 0 1px 3px rgba(0,0,0,0.08) !important;
                pointer-events: auto !important;
                cursor: pointer !important;
                position: relative !important;
                z-index: 1057 !important;
                user-select: none !important;
                -webkit-user-select: none !important;
            }
            
            .day-events-modal .day-event-item:active {
                transform: scale(0.98) !important;
                opacity: 0.95 !important;
                box-shadow: 0 1px 2px rgba(0,0,0,0.1) !important;
            }
            
            
            /* Ensure all interactive elements inside event items work */
            .day-events-modal .day-event-item * {
                pointer-events: auto !important;
            }
            
            .day-events-modal .day-event-title {
                font-size: 0.95rem;
                margin-bottom: 8px;
            }
            
            .day-events-modal .day-event-details {
                font-size: 0.8rem;
                gap: 6px;
            }
            
            .day-events-modal .day-event-detail {
                font-size: 0.8rem;
            }
            
            .day-events-modal .day-event-detail i {
                width: 14px;
                font-size: 0.75rem;
            }
            
            .event-details-modal .event-title {
                font-size: 1.1rem;
                margin-bottom: 8px;
            }
            
            .event-details-modal .event-date {
                font-size: 0.9rem;
                margin-bottom: 12px;
            }
            
            .event-details-modal .event-description {
                font-size: 0.9rem;
                line-height: 1.6;
            }
            
            .event-details-modal .event-detail {
                font-size: 0.85rem;
                margin-bottom: 8px;
            }
            
            .modal-footer {
                padding: 12px 16px;
                border-top: 1px solid #e0e0e0;
                pointer-events: auto !important;
                touch-action: manipulation !important;
                position: relative !important;
                z-index: 1058 !important;
            }
            
            .modal-footer .btn {
                min-height: 44px !important;
                padding: 10px 20px !important;
                font-size: 0.9rem !important;
                border-radius: 8px !important;
                touch-action: manipulation !important;
                -webkit-tap-highlight-color: rgba(0, 51, 102, 0.2) !important;
                cursor: pointer !important;
                pointer-events: auto !important;
                position: relative !important;
                z-index: 1059 !important;
                display: inline-block !important;
                user-select: none !important;
                -webkit-user-select: none !important;
            }
            
            /* CRITICAL: Block/override excessive padding on Close buttons */
            .modal-footer .btn-secondary,
            .modal-footer button[data-bs-dismiss="modal"],
            .modal-footer .btn[data-bs-dismiss="modal"],
            .day-events-modal .modal-footer .btn,
            .event-details-modal .modal-footer .btn,
            .modal-footer .btn[data-modal-button-fixed="true"],
            .modal-footer button[data-modal-button-fixed="true"] {
                padding: 10px 20px !important;
                max-width: none !important;
                width: auto !important;
            }
            
            /* Block any inline padding styles */
            .modal-footer .btn[style*="padding"],
            .modal-footer button[style*="padding"] {
                padding: 10px 20px !important;
            }
            
            /* Remove excessive padding from Cancel button in modals */
            .btn-outline-secondary.me-2[data-bs-dismiss="modal"][data-mobile-fixed="true"],
            button.btn-outline-secondary.me-2[data-bs-dismiss="modal"][data-mobile-fixed="true"],
            .btn-outline-secondary[data-bs-dismiss="modal"] {
                padding: 0.375rem 0.75rem !important;
            }
            
            .modal .btn-close,
            .day-events-modal .btn-close,
            .event-details-modal .btn-close {
                min-width: 44px !important;
                min-height: 44px !important;
                touch-action: manipulation !important;
                -webkit-tap-highlight-color: rgba(255,255,255,0.3) !important;
                cursor: pointer !important;
                pointer-events: auto !important;
                position: relative !important;
                z-index: 1060 !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                user-select: none !important;
                -webkit-user-select: none !important;
            }
            
            .modal-header {
                pointer-events: auto !important;
                touch-action: manipulation !important;
                position: relative !important;
                z-index: 1057 !important;
            }
            
            .modal-header .btn-close {
                pointer-events: auto !important;
                touch-action: manipulation !important;
                z-index: 1061 !important;
            }
        }
        
        /* Landscape orientation adjustments */
        @media (max-width: 768px) and (orientation: landscape) {
            .calendar-days {
                grid-auto-rows: minmax(50px, 1fr);
            }
            
            .calendar-day {
                min-height: 50px;
            }
            
            .event-list-wrapper {
                max-height: 300px;
            }
            
            /* Modal adjustments for landscape */
            .day-events-modal .modal-content,
            .event-details-modal .modal-content {
                max-height: 85vh !important;
            }
            
            .day-events-modal .modal-body,
            .event-details-modal .modal-body {
                max-height: calc(85vh - 120px) !important;
            }
        }
        
        /* Touch improvements */
        @media (max-width: 768px) {
            .calendar-day,
            .calendar-event,
            .calendar-event-more,
            .event-card,
            .day-events-modal .day-event-item {
                cursor: pointer;
                -webkit-tap-highlight-color: rgba(0, 51, 102, 0.2);
                user-select: none;
                -webkit-user-select: none;
            }
            
            /* Event list on mobile: vertical scroll (user-friendly list) */
            .event-list-content {
                display: block;
                overflow-y: auto;
                overflow-x: hidden;
                -webkit-overflow-scrolling: touch;
                overscroll-behavior: contain;
                padding: 16px;
            }
            
            .event-list-content .event-card {
                margin-bottom: 16px;
            }
            
            /* Improve scrolling on mobile */
            .day-events-modal .modal-body {
                -webkit-overflow-scrolling: touch;
                overscroll-behavior: contain;
            }
        }
        
        /* Loading state for mobile */
        @media (max-width: 768px) {
            #calendarDays:empty::before {
                content: 'Loading calendar...';
                display: block;
                text-align: center;
                padding: 40px 20px;
                color: #999;
                font-size: 0.9rem;
            }
            
            #eventList:empty {
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 200px;
            }
            
            #eventList:empty::before {
                content: 'Loading events...';
                display: block;
                text-align: center;
                padding: 40px 20px;
                color: #999;
                font-size: 0.9rem;
            }
        }
        
        /* ==========================================
           CRITICAL Z-INDEX FIXES - SAME AS requirements.css
           Fix Bootstrap modal z-index blocking calendar buttons
           ========================================== */
        
        /* CRITICAL: Calendar buttons must be ABOVE Bootstrap (1100+) */
        .calendar-nav-btn,
        .calendar-jump-today,
        .calendar-month-picker-btn,
        .calendar-day,
        .calendar-event,
        .calendar-event-more,
        .event-card {
            position: relative !important;
            z-index: 1100 !important; /* Above Bootstrap's 1055 */
            pointer-events: auto !important;
            touch-action: manipulation !important; /* Better for clickable elements */
            isolation: isolate !important; /* Create new stacking context */
            transform: translateZ(0) !important; /* Force hardware acceleration */
        }
        
        /* CRITICAL FIX: Hide Bootstrap modals completely when closed - prevents blocking */
        .modal:not(.show),
        .modal[style*="display: none"],
        .modal.fade:not(.show),
        body:not(.modal-open) .modal,
        body:not(.modal-open) .modal.fade,
        body:not(.modal-open) .modal:not(.show),
        html:not(.modal-open) body:not(.modal-open) .modal {
            display: none !important;
            visibility: hidden !important;
            opacity: 0 !important;
            pointer-events: none !important;
            z-index: -9999 !important;
            position: fixed !important;
            top: -99999px !important;
            left: -99999px !important;
            width: 0 !important;
            height: 0 !important;
            overflow: hidden !important;
            margin: 0 !important;
            padding: 0 !important;
            border: none !important;
            box-shadow: none !important;
        }
        
        /* CRITICAL FIX: Ensure Bootstrap backdrops don't block buttons when modals are closed */
        .modal-backdrop:not(.show),
        .modal-backdrop[style*="display: none"],
        body:not(.modal-open) .modal-backdrop,
        body:not(.modal-open) .modal-backdrop.fade {
            display: none !important;
            pointer-events: none !important;
            z-index: -1 !important;
            opacity: 0 !important;
            visibility: hidden !important;
            position: fixed !important;
        }
        
        /* Ensure modals are only interactive when showing */
        .modal.show,
        .modal[style*="display: block"],
        body.modal-open .modal.show {
            pointer-events: auto !important;
            z-index: 1055 !important; /* Bootstrap's default */
        }
        
        /* Ensure backdrops are only interactive when showing */
        .modal-backdrop.show,
        body.modal-open .modal-backdrop.show {
            pointer-events: auto !important;
            z-index: 1040 !important; /* Bootstrap's default */
        }
        
        /* CRITICAL: Ensure calendar buttons are always clickable ABOVE Bootstrap when modals are closed */
        body:not(.modal-open) .calendar-nav-btn,
        body:not(.modal-open) .calendar-jump-today,
        body:not(.modal-open) .calendar-month-picker-btn,
        body:not(.modal-open) .calendar-day,
        body:not(.modal-open) .calendar-event,
        body:not(.modal-open) .calendar-event-more,
        body:not(.modal-open) button:not(.modal button):not(:disabled),
        body:not(.modal-open) .btn:not(.modal .btn):not(:disabled) {
            z-index: 1100 !important; /* Above Bootstrap's 1055 */
            position: relative !important;
            pointer-events: auto !important;
            touch-action: manipulation !important;
            isolation: isolate !important;
            transform: translateZ(0) !important; /* Force new stacking context */
        }
        
        /* CRITICAL: Ensure main content is not blocked by hidden modals */
        body:not(.modal-open) .main-content,
        body:not(.modal-open) .main-content *:not(.modal):not(.modal *) {
            position: relative !important;
            z-index: auto !important;
            pointer-events: auto !important;
        }
        
        /* Mobile specific z-index fixes - ABOVE Bootstrap */
        @media (max-width: 991px) {
            /* CRITICAL: Ensure scrolling works on mobile - must be first */
            html, body {
                overflow-y: auto !important;
                overflow-x: hidden !important;
                -webkit-overflow-scrolling: touch !important;
                touch-action: pan-y !important;
                height: auto !important;
                min-height: 100% !important;
                position: relative !important;
            }
            
            .container-fluid {
                overflow: visible !important;
                height: auto !important;
            }
            
            .main-content {
                overflow: visible !important;
                height: auto !important;
                min-height: auto !important;
                max-height: none !important;
            }
            /* CRITICAL: Hide Bootstrap modals completely when closed on mobile */
            body:not(.modal-open) .modal,
            body:not(.modal-open) .modal.fade,
            body:not(.modal-open) .modal:not(.show) {
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
                pointer-events: none !important;
                z-index: -9999 !important;
                position: fixed !important;
                top: -99999px !important;
                left: -99999px !important;
                width: 0 !important;
                height: 0 !important;
                overflow: hidden !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            
            /* CRITICAL: Ensure calendar buttons are clickable on mobile ABOVE Bootstrap */
            .calendar-nav-btn,
            .calendar-jump-today,
            .calendar-month-picker-btn,
            .calendar-day,
            .calendar-event,
            .calendar-event-more,
            .event-card,
            .day-events-modal .day-event-item,
            button:not(.modal button):not(:disabled),
            .btn:not(.modal .btn):not(:disabled) {
                z-index: 1100 !important; /* Above Bootstrap's 1055 */
                position: relative !important;
                pointer-events: auto !important;
                touch-action: manipulation !important;
                isolation: isolate !important;
                transform: translateZ(0) !important; /* Force new stacking context */
            }
            
            /* Ensure main content is interactive and not blocked */
            .main-content {
                position: relative;
                z-index: 1;
                pointer-events: auto !important;
            }
            
            .main-content *:not(.modal):not(.modal *) {
                pointer-events: auto !important;
            }
            
            /* Ensure calendar wrapper is clickable */
            .calendar-wrapper,
            .calendar-container {
                pointer-events: auto !important;
                position: relative;
                z-index: 1;
            }
            
            /* Ensure calendar wrapper is clickable and scrollable */
            .calendar-wrapper {
                pointer-events: auto !important;
                /* overflow: hidden already set for rounded corners - don't override */
            }
            
            /* Ensure calendar grid allows touch events */
            .calendar-grid {
                pointer-events: auto !important;
                /* Allow natural scrolling - don't override touch-action */
            }
            
            /* Ensure calendar days container allows touch events */
            .calendar-days {
                pointer-events: auto !important;
                /* Allow natural scrolling - don't override touch-action */
            }
            
            /* Ensure calendar day elements are clickable */
            .calendar-day {
                touch-action: manipulation !important; /* Better for clickable elements */
                pointer-events: auto !important;
            }
            
            /* Ensure calendar header doesn't block scrolling */
            .calendar-header {
                z-index: 1098 !important; /* Below buttons but above Bootstrap */
                pointer-events: auto !important;
                isolation: isolate !important;
                transform: translateZ(0) !important;
            }
            
            /* But buttons inside calendar should be ABOVE Bootstrap */
            .calendar-wrapper .calendar-nav-btn,
            .calendar-wrapper .calendar-jump-today,
            .calendar-wrapper .calendar-day,
            .calendar-wrapper .calendar-event,
            .calendar-wrapper .calendar-event-more {
                z-index: 1100 !important; /* Above Bootstrap's 1055 */
                position: relative !important;
                pointer-events: auto !important;
                isolation: isolate !important;
                transform: translateZ(0) !important;
            }
            
            /* Ensure main content allows scrolling and touch events */
            .main-content {
                pointer-events: auto !important;
                touch-action: pan-y !important; /* Allow vertical scrolling */
                -webkit-overflow-scrolling: touch !important;
                overscroll-behavior: contain !important;
            }
            
            /* Ensure body allows scrolling when modals are closed */
            body:not(.modal-open) {
                overflow-y: auto !important;
                -webkit-overflow-scrolling: touch !important;
                touch-action: pan-y !important;
            }
            
            /* CRITICAL: Ensure html and body allow scrolling on mobile */
            html {
                overflow-y: auto !important;
                -webkit-overflow-scrolling: touch !important;
                height: auto !important;
                min-height: 100% !important;
            }
            
            body {
                overflow-y: auto !important;
                overflow-x: hidden !important;
                -webkit-overflow-scrolling: touch !important;
                touch-action: pan-y !important;
                height: auto !important;
                min-height: 100% !important;
                position: relative !important;
            }
            
            /* Ensure container-fluid allows scrolling */
            .container-fluid {
                overflow: visible !important;
                height: auto !important;
                min-height: auto !important;
            }
            
            /* Ensure main content is scrollable */
            .main-content {
                overflow: visible !important;
                height: auto !important;
                min-height: auto !important;
                max-height: none !important;
            }
            
            /* Ensure calendar container doesn't block scrolling */
            .calendar-container {
                overflow: visible !important;
                height: auto !important;
                min-height: auto !important;
            }
        }
    </style>
</head>
<body class="layout-faculty">
    <?php require_once '../includes/navigation.php';
    include_navigation(); ?>
    
    <div class="container-fluid">
        <div class="row">
            <main class="main-content calendar-page">
                <div class="page-header calendar-page-header">
                    <div>
                        <div class="page-title">
                            <i class="fas fa-calendar-alt" aria-hidden="true"></i>
                            <span>Calendar</span>
                        </div>
                        <p class="page-subtitle calendar-page-subtitle">View events and important dates</p>
                    </div>
                </div>

                <?php displayMessage(); ?>

                <div class="calendar-container">
                    <div class="calendar-wrapper">
                        <div class="calendar-header">
                            <h3 id="calendarMonthTitle" onclick="showMonthPicker()"><?php echo $monthName; ?></h3>
                            <div class="calendar-nav" onclick="event.stopPropagation();">
                                <button type="button" class="calendar-jump-today" onclick="event.stopPropagation(); event.preventDefault(); jumpToToday();" id="jumpTodayBtn" style="display: none;">
                                    <i class="fas fa-calendar-day"></i> Today
                                </button>
                                <button type="button" class="calendar-nav-btn" onclick="event.stopPropagation(); event.preventDefault(); changeMonthPrev();" 
                                        aria-label="Previous Month"
                                        id="prevMonthBtn"
                                        style="touch-action: manipulation; -webkit-tap-highlight-color: rgba(255,255,255,0.3);">
                                    <i class="fas fa-chevron-left"></i>
                                </button>
                                <button type="button" class="calendar-nav-btn" onclick="event.stopPropagation(); event.preventDefault(); changeMonthNext();" 
                                        aria-label="Next Month"
                                        id="nextMonthBtn"
                                        style="touch-action: manipulation; -webkit-tap-highlight-color: rgba(255,255,255,0.3);">
                                    <i class="fas fa-chevron-right"></i>
                                </button>
                            </div>
                            <div class="swipe-indicator" id="swipeIndicator" style="display: none;">
                                <div class="swipe-indicator-dot"></div>
                                <div class="swipe-indicator-dot"></div>
                                <div class="swipe-indicator-dot"></div>
                            </div>
                        </div>
                        <div class="calendar-grid">
                            <div class="calendar-weekdays" role="row">
                                <div class="calendar-weekday" data-short="S" role="columnheader">Sun</div>
                                <div class="calendar-weekday" data-short="M" role="columnheader">Mon</div>
                                <div class="calendar-weekday" data-short="T" role="columnheader">Tue</div>
                                <div class="calendar-weekday" data-short="W" role="columnheader">Wed</div>
                                <div class="calendar-weekday" data-short="T" role="columnheader">Thu</div>
                                <div class="calendar-weekday" data-short="F" role="columnheader">Fri</div>
                                <div class="calendar-weekday" data-short="S" role="columnheader">Sat</div>
                            </div>
                            <div class="calendar-days" id="calendarDays">
                                <!-- Calendar days will be populated by JavaScript -->
                            </div>
                        </div>
                    </div>
                    
                    <div class="event-list-wrapper" id="eventListWrapper">
                        <button type="button" class="event-list-header event-list-toggle-btn d-md-none" id="eventListToggle" aria-expanded="true" aria-controls="eventList" aria-label="Toggle event list">
                            <i class="fas fa-calendar-alt" aria-hidden="true"></i>
                            <h4>Upcoming events</h4>
                            <span class="event-list-toggle-icon" aria-hidden="true"><i class="fas fa-chevron-down"></i></span>
                        </button>
                        <div class="event-list-header d-none d-md-flex" aria-hidden="true">
                            <i class="fas fa-calendar-alt"></i>
                            <h4>Event List</h4>
                        </div>
                        <div class="event-list-content" id="eventList" role="region" aria-labelledby="eventListHeading">
                            <span id="eventListHeading" class="sr-only">Upcoming events list</span>
                            <!-- Event list will be populated by JavaScript -->
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Day Events Modal (View Only) -->
    <div class="modal fade day-events-modal" id="dayEventsModal" tabindex="-1" aria-labelledby="dayEventsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white" style="
    height: 9vh;
">
                    <h5 class="modal-title" id="dayEventsModalLabel" style="
    color: white;
">Events for Day</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="dayEventsModalBody">
                    <!-- Events will be populated by JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Event Details Modal (View Only) -->
    <div class="modal fade event-details-modal" id="eventDetailsModal" tabindex="-1" aria-labelledby="eventDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white"  style="
    height: 9vh;
"> 
                    <h5 class="modal-title" id="eventDetailsModalLabel" style="
    color: white;
">Event Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="event-title" id="eventDetailsTitle"></div>
                    <div class="event-date" id="eventDetailsDate"></div>
                    <div class="event-description" id="eventDetailsDescription"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
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
        // CRITICAL: Ensure scrolling works on mobile
        (function() {
            'use strict';
            
            // Fix scrolling on mobile devices
            function enableMobileScrolling() {
                // Ensure html and body allow scrolling
                document.documentElement.style.overflowY = 'auto';
                document.documentElement.style.webkitOverflowScrolling = 'touch';
                document.documentElement.style.touchAction = 'pan-y';
                document.documentElement.style.height = 'auto';
                document.documentElement.style.minHeight = '100%';
                
                document.body.style.overflowY = 'auto';
                document.body.style.overflowX = 'hidden';
                document.body.style.webkitOverflowScrolling = 'touch';
                document.body.style.touchAction = 'pan-y';
                document.body.style.height = 'auto';
                document.body.style.minHeight = '100%';
                document.body.style.position = 'relative';
                
                // Ensure main content allows scrolling
                const mainContent = document.querySelector('.main-content');
                if (mainContent) {
                    mainContent.style.overflow = 'visible';
                    mainContent.style.height = 'auto';
                    mainContent.style.minHeight = 'auto';
                    mainContent.style.maxHeight = 'none';
                }
                
                // Ensure container-fluid allows scrolling
                const container = document.querySelector('.container-fluid');
                if (container) {
                    container.style.overflow = 'visible';
                    container.style.height = 'auto';
                }
            }
            
            // Run on DOMContentLoaded
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', enableMobileScrolling);
            } else {
                enableMobileScrolling();
            }
            
            // Also run after a short delay to override any other scripts
            setTimeout(enableMobileScrolling, 100);
        })();
        
        // Polyfill for String.prototype.padStart (for older browsers)
        if (!String.prototype.padStart) {
            String.prototype.padStart = function(targetLength, padString) {
                targetLength = targetLength >> 0; //floor if number or convert non-number to 0;
                padString = String(padString || ' ');
                if (this.length > targetLength) {
                    return String(this);
                } else {
                    targetLength = targetLength - this.length;
                    if (targetLength > padString.length) {
                        padString += padString.repeat(targetLength / padString.length); //append to original to ensure we are longer than needed
                    }
                    return padString.slice(0, targetLength) + String(this);
                }
            };
        }
        
        let currentMonth = <?php echo $currentMonth; ?>;
        let currentYear = <?php echo $currentYear; ?>;
        let events = [];
        
        // Calendar URL for navigation (generated from PHP to ensure correct base path)
        const calendarUrl = <?php echo json_encode($calendarUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        // Show loading indicator with skeleton
        function showLoading() {
            const calendarDays = document.getElementById('calendarDays');
            const eventList = document.getElementById('eventList');
            
            if (calendarDays) {
                // Create skeleton loading
                let skeletonHTML = '';
                for (let i = 0; i < 42; i++) {
                    skeletonHTML += '<div class="calendar-skeleton calendar-skeleton-day"></div>';
                }
                calendarDays.innerHTML = skeletonHTML;
                calendarDays.classList.add('fade-in');
            }
            
            if (eventList) {
                eventList.innerHTML = `
                    <div style="text-align: center; padding: 40px 20px; color: #999;">
                        <div style="display: inline-block; width: 40px; height: 40px; border: 4px solid #f3f3f3; border-top: 4px solid #003366; border-radius: 50%; animation: spin 1s linear infinite; margin-bottom: 16px;"></div>
                        <div style="font-size: 0.9rem; font-weight: 500;">Loading events...</div>
                    </div>
                `;
            }
        }
        
        // Add spin animation for loader
        const style = document.createElement('style');
        style.textContent = `
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        `;
        document.head.appendChild(style);

        // Load events for the current month
        function loadEvents() {
            showLoading();
            
            const start = `${currentYear}-${String(currentMonth).padStart(2, '0')}-01`;
            const lastDay = new Date(currentYear, currentMonth, 0).getDate();
            const end = `${currentYear}-${String(currentMonth).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`;
            
            fetch(`../admin/calendar_api.php?action=event_list&start=${start}&end=${end}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        events = data.events;
                        renderCalendar();
                        renderEventList();
                        // Update URL without reload (for bookmarking/refresh)
                        const path = window.location.pathname || '/faculty/calendar.php';
                        const newUrl = `${path}?month=${currentMonth}&year=${currentYear}`;
                        if (window.history && window.history.replaceState) {
                            window.history.replaceState({ month: currentMonth, year: currentYear }, '', newUrl);
                        }
                    } else {
                        // Show error message
                        const calendarDays = document.getElementById('calendarDays');
                        const eventList = document.getElementById('eventList');
                        if (calendarDays) {
                            calendarDays.innerHTML = '<div style="grid-column: 1/-1; text-align: center; padding: 40px 20px; color: #dc2626;"><i class="fas fa-exclamation-circle" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i><div>Error loading calendar</div></div>';
                        }
                        if (eventList) {
                            eventList.innerHTML = '<p style="text-align: center; color: #dc2626; padding: 20px;">Error loading events</p>';
                        }
                    }
                })
                .catch(error => {
                    console.error('Error loading events:', error);
                    // Show error message
                    const calendarDays = document.getElementById('calendarDays');
                    const eventList = document.getElementById('eventList');
                    if (calendarDays) {
                        calendarDays.innerHTML = '<div style="grid-column: 1/-1; text-align: center; padding: 40px 20px; color: #dc2626;"><i class="fas fa-exclamation-circle" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i><div>Error loading calendar</div></div>';
                    }
                    if (eventList) {
                        eventList.innerHTML = '<p style="text-align: center; color: #dc2626; padding: 20px;">Error loading events</p>';
                    }
                });
        }

        // Render calendar
        function renderCalendar() {
            const calendarDays = document.getElementById('calendarDays');
            
            // Add fade-in animation
            calendarDays.classList.remove('fade-out');
            calendarDays.classList.add('fade-in');
            
            calendarDays.innerHTML = '';
            
            // Update month title
            const monthTitle = document.getElementById('calendarMonthTitle');
            if (monthTitle) {
                const date = new Date(currentYear, currentMonth - 1, 1);
                monthTitle.textContent = date.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
            }
            
            // Check if we should show "Jump to Today" button
            checkShowTodayButton();
            
            // Get first day of month and number of days
            const firstDay = new Date(currentYear, currentMonth - 1, 1);
            const lastDay = new Date(currentYear, currentMonth, 0).getDate();
            const startDay = firstDay.getDay(); // 0 = Sunday, 6 = Saturday
            
            // Get previous month's last days
            const prevMonthLastDay = new Date(currentYear, currentMonth - 1, 0).getDate();
            
            // Calculate previous and next month/year
            let prevMonth = currentMonth - 1;
            let prevYear = currentYear;
            if (prevMonth < 1) {
                prevMonth = 12;
                prevYear--;
            }
            
            let nextMonth = currentMonth + 1;
            let nextYear = currentYear;
            if (nextMonth > 12) {
                nextMonth = 1;
                nextYear++;
            }
            
            // Add previous month's days
            for (let i = startDay - 1; i >= 0; i--) {
                const day = prevMonthLastDay - i;
                const dayElement = createDayElement(day, true, prevMonth, prevYear);
                calendarDays.appendChild(dayElement);
            }
            
            // Add current month's days
            for (let day = 1; day <= lastDay; day++) {
                const dayElement = createDayElement(day, false, currentMonth, currentYear);
                calendarDays.appendChild(dayElement);
            }
            
            // Add next month's days to fill the grid
            const totalCells = calendarDays.children.length;
            const remainingCells = 42 - totalCells; // 6 weeks * 7 days
            for (let day = 1; day <= remainingCells; day++) {
                const dayElement = createDayElement(day, true, nextMonth, nextYear);
                calendarDays.appendChild(dayElement);
            }
        }

        // Helper function to handle both click and touch events
        function addTouchHandler(element, handler) {
            let touchStartTime = 0;
            let touchStartX = 0;
            let touchStartY = 0;
            let isHandled = false;
            
            // Touch start
            element.addEventListener('touchstart', function(e) {
                touchStartTime = Date.now();
                touchStartX = e.touches[0].clientX;
                touchStartY = e.touches[0].clientY;
                element.style.opacity = '0.7';
                isHandled = false;
            }, { passive: true });
            
            // Touch end
            element.addEventListener('touchend', function(e) {
                const touchEndTime = Date.now();
                const touchEndX = e.changedTouches[0].clientX;
                const touchEndY = e.changedTouches[0].clientY;
                const deltaX = Math.abs(touchEndX - touchStartX);
                const deltaY = Math.abs(touchEndY - touchStartY);
                const deltaTime = touchEndTime - touchStartTime;
                
                element.style.opacity = '1';
                
                // Only trigger if it's a tap (not a swipe) and within reasonable time/distance
                if (!isHandled && deltaTime < 300 && deltaX < 10 && deltaY < 10) {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    isHandled = true;
                    handler(e);
                }
            }, { passive: false });
            
            // Click handler for desktop
            element.addEventListener('click', function(e) {
                if (!isHandled) {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    isHandled = true;
                    handler(e);
                }
                // Reset flag after a delay
                setTimeout(() => {
                    isHandled = false;
                }, 300);
            });
        }

        // Create day element
        function createDayElement(day, isOtherMonth, month, year) {
            const dayDiv = document.createElement('div');
            dayDiv.className = 'calendar-day';
            
            if (isOtherMonth) {
                dayDiv.classList.add('other-month');
            }
            
            // Check if today
            const today = new Date();
            if (!isOtherMonth && day === today.getDate() && month === today.getMonth() + 1 && year === today.getFullYear()) {
                dayDiv.classList.add('today');
            }
            
            const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            const dayNumber = document.createElement('div');
            dayNumber.className = 'calendar-day-number';
            dayNumber.textContent = day;
            dayDiv.appendChild(dayNumber);
            
            // Create events container
            const eventsContainer = document.createElement('div');
            eventsContainer.className = 'calendar-day-events';
            dayDiv.appendChild(eventsContainer);
            
            // Get events for this day
            const dayEvents = events.filter(e => e.date === dateStr);
            
            if (dayEvents.length > 0) {
                // Show only first 3 events (fixed display regardless of total count)
                const eventsToShow = dayEvents.slice(0, 3);
                eventsToShow.forEach(event => {
                    const eventDiv = document.createElement('div');
                    eventDiv.className = 'calendar-event';
                    eventDiv.style.backgroundColor = event.color;
                    eventDiv.textContent = event.title;
                    eventDiv.title = `${event.title}${event.time ? ' at ' + event.time : ''}${event.location ? ' - ' + event.location : ''}`;
                    
                    // Add touch handler for event
                    addTouchHandler(eventDiv, (e) => {
                        e.stopPropagation();
                        showEventDetails(event);
                    });
                    
                    eventsContainer.appendChild(eventDiv);
                });
                
                // If there are more than 3 events, show "+X more" indicator
                if (dayEvents.length > 3) {
                    const moreEvents = dayEvents.length - 3;
                    const moreDiv = document.createElement('div');
                    moreDiv.className = 'calendar-event-more';
                    moreDiv.textContent = `+${moreEvents} more`;
                    moreDiv.title = `Click to see all ${dayEvents.length} events`;
                    
                    // Add touch handler for "more" indicator
                    addTouchHandler(moreDiv, (e) => {
                        e.stopPropagation();
                        showDayEvents(dateStr, dayEvents, day, month, year);
                    });
                    
                    eventsContainer.appendChild(moreDiv);
                }
            }
            
            // Click/touch handler for the day
            addTouchHandler(dayDiv, (e) => {
                // Don't trigger if clicking on an event or its children
                if (e.target.closest('.calendar-event') || e.target.closest('.calendar-event-more')) {
                    return;
                }
                
                if (!isOtherMonth) {
                    // If there are events, show them all
                    if (dayEvents.length > 0) {
                        showDayEvents(dateStr, dayEvents, day, month, year);
                    }
                }
            });
            
            return dayDiv;
        }
        
        // Show all events for a day
        function showDayEvents(dateStr, dayEvents, day, month, year) {
            const modalElement = document.getElementById('dayEventsModal');
            const modal = new bootstrap.Modal(modalElement);
            const modalBody = document.getElementById('dayEventsModalBody');
            
            // Format date
            const dateObj = new Date(year, month - 1, day);
            const dateFormatted = dateObj.toLocaleDateString('en-US', { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
            
            // Create header
            const header = document.createElement('div');
            header.className = 'day-events-header';
            header.innerHTML = `
                <div class="day-events-date">${dateFormatted}</div>
                <div class="day-events-count">${dayEvents.length} event${dayEvents.length !== 1 ? 's' : ''}</div>
            `;
            modalBody.innerHTML = '';
            modalBody.appendChild(header);
            
            // Sort events by time (if available)
            dayEvents.sort((a, b) => {
                if (a.time && b.time) {
                    return a.time.localeCompare(b.time);
                }
                return a.time ? -1 : 1;
            });
            
            // Create event items
            dayEvents.forEach(event => {
                const eventItem = document.createElement('div');
                eventItem.className = 'day-event-item';
                
                // CRITICAL: Ensure event item is interactive
                eventItem.style.pointerEvents = 'auto';
                eventItem.style.touchAction = 'manipulation';
                eventItem.style.cursor = 'pointer';
                eventItem.style.position = 'relative';
                eventItem.style.zIndex = '1057';
                
                // Store event reference directly in closure (better than JSON serialization)
                // This preserves all event properties including methods and circular references
                
                // Add click handler for event item - properly handle modal transition
                const handleEventClick = function(e) {
                    // Prevent default behavior and stop propagation
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    
                    // Use the event from closure - no serialization needed
                    const eventData = event;
                    
                    // Wait for the day events modal to fully close before showing event details
                    const onModalHidden = function() {
                        // Small delay to ensure modal cleanup is complete and DOM is ready
                        setTimeout(() => {
                            if (eventData) {
                                showEventDetails(eventData);
                            }
                        }, 150);
                    };
                    
                    // Listen for modal to be fully hidden (Bootstrap event)
                    modalElement.addEventListener('hidden.bs.modal', onModalHidden, { once: true });
                    
                    // Hide the current modal - this will trigger hidden.bs.modal when done
                    modal.hide();
                };
                
                // Add touch handler for event item
                addTouchHandler(eventItem, handleEventClick);
                
                const title = document.createElement('div');
                title.className = 'day-event-title';
                title.innerHTML = `
                    <div class="day-event-color" style="background-color: ${event.color};"></div>
                    <span>${event.title}</span>
                `;
                eventItem.appendChild(title);
                
                const details = document.createElement('div');
                details.className = 'day-event-details';
                
                // Category
                if (event.category) {
                    const category = document.createElement('div');
                    category.className = 'day-event-detail';
                    category.innerHTML = `<i class="fas fa-tag"></i><span>${event.category}</span>`;
                    details.appendChild(category);
                }
                
                // Time
                if (event.time) {
                    const time = document.createElement('div');
                    time.className = 'day-event-detail';
                    let timeStr = event.time;
                    if (event.endTime) {
                        timeStr += ' - ' + event.endTime;
                    }
                    time.innerHTML = `<i class="fas fa-clock"></i><span>${timeStr}</span>`;
                    details.appendChild(time);
                }
                
                // Location
                if (event.location) {
                    const location = document.createElement('div');
                    location.className = 'day-event-detail';
                    location.innerHTML = `<i class="fas fa-map-marker-alt"></i><span>${event.location}</span>`;
                    details.appendChild(location);
                }
                
                // Description
                let descriptionText = event.description || '';
                if (event.isTARF && event.tarfDescription) {
                    descriptionText = event.tarfDescription;
                }
                if (descriptionText) {
                    const description = document.createElement('div');
                    description.className = 'day-event-detail';
                    description.innerHTML = `<i class="fas fa-info-circle"></i><span>${descriptionText}</span>`;
                    details.appendChild(description);
                }
                
                // File link for TARF events
                if (event.isTARF && event.filePath) {
                    const fileLink = document.createElement('div');
                    fileLink.className = 'day-event-detail';
                    const fileName = event.filePath.split('/').pop();
                    fileLink.innerHTML = `<i class="fas fa-file-download"></i><a href="../${event.filePath}" target="_blank" class="text-decoration-none"><span>Download TARF File: ${fileName}</span></a>`;
                    details.appendChild(fileLink);
                }
                
                eventItem.appendChild(details);
                modalBody.appendChild(eventItem);
            });
            
            // Show modal
            modal.show();
            
            // CRITICAL: Fix buttons after modal is shown
            modalElement.addEventListener('shown.bs.modal', function() {
                fixModalButtons();
                // Also fix after a short delay to ensure all buttons are fixed
                setTimeout(fixModalButtons, 100);
            }, { once: true });
        }

        // Render event list
        function renderEventList() {
            const eventList = document.getElementById('eventList');
            eventList.innerHTML = '';
            
            // Get current month's events
            const currentMonthEvents = events.filter(e => {
                const eventDate = new Date(e.date);
                return eventDate.getMonth() + 1 === currentMonth && eventDate.getFullYear() === currentYear;
            });
            
            // Sort by date and time
            currentMonthEvents.sort((a, b) => {
                if (a.date !== b.date) {
                    return a.date.localeCompare(b.date);
                }
                // If same date, sort by time
                if (a.time && b.time) {
                    return a.time.localeCompare(b.time);
                }
                return a.time ? -1 : 1;
            });
            
            // Show all events for the current month
            if (currentMonthEvents.length === 0) {
                eventList.innerHTML = `
                    <div class="calendar-empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <h4>No Events</h4>
                        <p>There are no events scheduled for this month.</p>
                    </div>
                `;
                return;
            }
            
            currentMonthEvents.forEach(event => {
                const eventCard = createEventCard(event);
                eventList.appendChild(eventCard);
            });
            
            // Update scroll indicator after rendering
            updateScrollIndicator();
        }
        
        // Update horizontal scroll indicator visibility
        function updateScrollIndicator() {
            const eventList = document.getElementById('eventList');
            if (!eventList || window.innerWidth > 768) {
                if (eventList) {
                    eventList.classList.remove('scrolled-to-end');
                }
                return;
            }
            
            const checkScroll = () => {
                if (!eventList) return;
                const isScrollable = eventList.scrollWidth > eventList.clientWidth;
                const isAtEnd = eventList.scrollLeft + eventList.clientWidth >= eventList.scrollWidth - 10;
                
                if (!isScrollable || isAtEnd) {
                    eventList.classList.add('scrolled-to-end');
                } else {
                    eventList.classList.remove('scrolled-to-end');
                }
            };
            
            // Check initially after DOM is ready
            setTimeout(checkScroll, 100);
            
            // Add event listeners
            eventList.addEventListener('scroll', checkScroll, { passive: true });
            window.addEventListener('resize', checkScroll, { passive: true });
        }

        // Create event card
        function createEventCard(event) {
            const card = document.createElement('div');
            card.className = 'event-card';
            card.style.cursor = 'pointer';
            
            // Add touch handler for event card
            addTouchHandler(card, () => showEventDetails(event));
            
            const title = document.createElement('div');
            title.className = 'event-card-title';
            title.textContent = event.title;
            card.appendChild(title);
            
            // Tags
            const tags = document.createElement('div');
            tags.className = 'event-card-tags';
            
            // Category tag
            if (event.category) {
                const categoryTag = document.createElement('span');
                categoryTag.className = `event-tag ${event.category.toLowerCase().replace(' ', '-')}`;
                categoryTag.textContent = event.category;
                tags.appendChild(categoryTag);
            }
            
            // Status tag
            if (event.statusText) {
                const statusTag = document.createElement('span');
                statusTag.className = `event-tag ${event.status === 'today' ? 'ongoing' : 'upcoming'}`;
                statusTag.textContent = event.statusText;
                tags.appendChild(statusTag);
            }
            
            card.appendChild(tags);
            
            // Details
            const details = document.createElement('div');
            details.className = 'event-card-details';
            
            // Date
            const date = document.createElement('div');
            date.className = 'event-detail';
            const dateObj = new Date(event.date);
            date.innerHTML = `<i class="fas fa-calendar"></i><span>${dateObj.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })}</span>`;
            details.appendChild(date);
            
            // Time
            if (event.time) {
                const time = document.createElement('div');
                time.className = 'event-detail';
                let timeStr = event.time;
                if (event.endTime) {
                    timeStr += ' - ' + event.endTime;
                }
                time.innerHTML = `<i class="fas fa-clock"></i><span>${timeStr}</span>`;
                details.appendChild(time);
            }
            
            // Location
            if (event.location) {
                const location = document.createElement('div');
                location.className = 'event-detail';
                location.innerHTML = `<i class="fas fa-map-marker-alt"></i><span>${event.location}</span>`;
                details.appendChild(location);
            }
            
            card.appendChild(details);
            
            return card;
        }

        // Show event details
        function showEventDetails(event) {
            // Prevent multiple calls
            if (!event || typeof event !== 'object') {
                console.error('Invalid event data:', event);
                return;
            }
            
            const modalElement = document.getElementById('eventDetailsModal');
            if (!modalElement) {
                console.error('Event details modal element not found');
                return;
            }
            
            // Get existing modal instance or create new one
            let modal = bootstrap.Modal.getInstance(modalElement);
            if (!modal) {
                modal = new bootstrap.Modal(modalElement);
            }
            
            const modalBody = document.querySelector('#eventDetailsModal .modal-body');
            
            // Set event details
            const titleElement = document.getElementById('eventDetailsTitle');
            const dateElement = document.getElementById('eventDetailsDate');
            const descriptionElement = document.getElementById('eventDetailsDescription');
            
            if (!titleElement || !dateElement || !descriptionElement) {
                console.error('Event details modal elements not found');
                return;
            }
            
            titleElement.textContent = event.title || 'Event';
            
            // Format date
            const dateObj = new Date(event.date);
            let dateStr = dateObj.toLocaleDateString('en-US', { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
            
            if (event.time) {
                let timeStr = event.time;
                if (event.endTime) {
                    timeStr += ' - ' + event.endTime;
                }
                dateStr += ' at ' + timeStr;
            }
            
            dateElement.textContent = dateStr;
            
            // Remove existing location if any
            const existingLocation = modalBody.querySelector('.event-location');
            if (existingLocation) {
                existingLocation.remove();
            }
            
            // Location
            if (event.location) {
                const locationDiv = document.createElement('div');
                locationDiv.className = 'event-detail event-location';
                locationDiv.innerHTML = `<i class="fas fa-map-marker-alt"></i><span>${event.location}</span>`;
                dateElement.parentNode.insertBefore(locationDiv, descriptionElement);
            }
            
            // Description
            let description = event.description || 
                               (event.isPhilippinesHoliday ? 'Philippines National Holiday' : 'No description available');
            
            // For TARF events, use tarfDescription if available
            if (event.isTARF && event.tarfDescription) {
                description = event.tarfDescription;
            }
            
            descriptionElement.textContent = description;
            
            // Remove existing file link if any
            const existingFileLink = modalBody.querySelector('.event-file-link');
            if (existingFileLink) {
                existingFileLink.remove();
            }
            
            // Add file download link for TARF events
            if (event.isTARF && event.filePath) {
                const fileLinkDiv = document.createElement('div');
                fileLinkDiv.className = 'event-detail event-file-link mt-3';
                const fileName = event.filePath.split('/').pop();
                fileLinkDiv.innerHTML = `
                    <i class="fas fa-file-download"></i>
                    <a href="../${event.filePath}" target="_blank" class="text-decoration-none">
                        <span>Download TARF File: ${fileName}</span>
                    </a>
                `;
                descriptionElement.parentNode.insertBefore(fileLinkDiv, descriptionElement.nextSibling);
            }
            
            // Show modal
            try {
                modal.show();
            } catch (error) {
                console.error('Error showing modal:', error);
                return;
            }
            
            // CRITICAL: Fix buttons after modal is shown
            const fixButtons = function() {
                fixModalButtons();
                // Also fix after a short delay to ensure all buttons are fixed
                setTimeout(fixModalButtons, 100);
            };
            
            modalElement.addEventListener('shown.bs.modal', fixButtons, { once: true });
        }

        // Navigate to previous month (no page reload)
        function changeMonthPrev() {
            let prevMonth = currentMonth - 1;
            let prevYear = currentYear;
            if (prevMonth < 1) {
                prevMonth = 12;
                prevYear--;
            }
            changeMonth(prevMonth, prevYear);
        }
        
        // Navigate to next month (no page reload)
        function changeMonthNext() {
            let nextMonth = currentMonth + 1;
            let nextYear = currentYear;
            if (nextMonth > 12) {
                nextMonth = 1;
                nextYear++;
            }
            changeMonth(nextMonth, nextYear);
        }
        
        // Change month with animation (no page reload - uses AJAX)
        function changeMonth(month, year, direction = 'next') {
            // Prevent multiple rapid clicks
            if (window.isChangingMonth) {
                return false;
            }
            window.isChangingMonth = true;
            
            // Update current month/year
            currentMonth = month;
            currentYear = year;
            
            // Add fade animation
            const calendarDays = document.getElementById('calendarDays');
            if (calendarDays) {
                calendarDays.classList.add('fade-out');
                
                setTimeout(() => {
                    // Load new month via AJAX - no page reload
                    loadEvents();
                    window.isChangingMonth = false;
                }, 150);
            } else {
                loadEvents();
                window.isChangingMonth = false;
            }
            
            return false;
        }
        
        // Jump to today
        function jumpToToday() {
            const today = new Date();
            const month = today.getMonth() + 1;
            const year = today.getFullYear();
            const currentDate = new Date(currentYear, currentMonth - 1, 1);
            const todayDate = new Date(today.getFullYear(), today.getMonth(), 1);
            
            // If already on current month, just scroll to today
            if (currentDate.getTime() === todayDate.getTime()) {
                scrollToToday();
            } else {
                // Navigate to current month - ensure we stay on calendar page
                changeMonth(month, year);
                // Scroll to today after calendar loads
                setTimeout(() => {
                    scrollToToday();
                }, 600);
            }
            
            triggerHapticFeedback();
            return false;
        }
        
        // Show month picker (simple version - could be enhanced with a modal)
        function showMonthPicker() {
            // For now, just scroll to top and show a message
            // In a full implementation, you could show a month/year picker modal
            window.scrollTo({ top: 0, behavior: 'smooth' });
            
            // Show visual feedback
            const title = document.getElementById('calendarMonthTitle');
            if (title) {
                title.style.transform = 'scale(0.95)';
                setTimeout(() => {
                    title.style.transform = 'scale(1)';
                }, 150);
            }
        }
        
        // Check if we should show "Jump to Today" button
        function checkShowTodayButton() {
            const today = new Date();
            const currentDate = new Date(currentYear, currentMonth - 1, 1);
            const todayDate = new Date(today.getFullYear(), today.getMonth(), 1);
            
            const jumpTodayBtn = document.getElementById('jumpTodayBtn');
            if (jumpTodayBtn) {
                // Show button if we're not on current month
                if (currentDate.getTime() !== todayDate.getTime()) {
                    jumpTodayBtn.style.display = 'flex';
                    jumpTodayBtn.style.alignItems = 'center';
                    jumpTodayBtn.style.gap = '6px';
                } else {
                    jumpTodayBtn.style.display = 'none';
                }
            }
        }
        
        // Add smooth scroll to today's date when jumping
        function scrollToToday() {
            const today = new Date();
            const todayElement = document.querySelector('.calendar-day.today');
            if (todayElement) {
                todayElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                // Add highlight animation
                todayElement.style.animation = 'pulse 0.5s ease-in-out';
                setTimeout(() => {
                    todayElement.style.animation = '';
                }, 500);
            }
        }
        
        // Show/hide swipe indicator on mobile
        function toggleSwipeIndicator(show) {
            const indicator = document.getElementById('swipeIndicator');
            if (indicator && window.innerWidth <= 768) {
                if (show) {
                    indicator.style.display = 'flex';
                    // Hide after 3 seconds
                    setTimeout(() => {
                        indicator.style.opacity = '0';
                        setTimeout(() => {
                            indicator.style.display = 'none';
                            indicator.style.opacity = '0.6';
                        }, 300);
                    }, 3000);
                } else {
                    indicator.style.display = 'none';
                }
            }
        }
        
        // CRITICAL FIX: Ensure navigation buttons work on mobile
        function initNavigationButtons() {
            const navButtons = document.querySelectorAll('.calendar-nav-btn');
            navButtons.forEach(button => {
                // Skip if already initialized
                if (button.dataset.navInitialized === 'true') {
                    return;
                }
                button.dataset.navInitialized = 'true';
                
                // Ensure button is interactive
                button.style.pointerEvents = 'auto';
                button.style.touchAction = 'manipulation';
                button.style.cursor = 'pointer';
                button.style.position = 'relative';
                button.style.zIndex = '1000';
                
                // Get onclick attribute value
                const onclickAttr = button.getAttribute('onclick');
                if (onclickAttr && (onclickAttr.includes('changeMonthPrev') || onclickAttr.includes('changeMonthNext'))) {
                    // Remove onclick attribute to avoid conflicts
                    button.removeAttribute('onclick');
                    
                    // Flag to prevent double-firing (using button dataset for persistence)
                    let resetTimer = null;
                    
                    // Visual feedback function
                    const showFeedback = function() {
                        button.style.opacity = '0.7';
                        button.style.transform = 'scale(0.95)';
                        setTimeout(() => {
                            button.style.opacity = '1';
                            button.style.transform = 'scale(1)';
                        }, 150);
                    };
                    
                    // Execute change month function (prev/next - no page reload)
                    const executeChange = function() {
                        if (window.isChangingMonth) return;
                        if (resetTimer) {
                            clearTimeout(resetTimer);
                            resetTimer = null;
                        }
                        button.dataset.touchHandled = 'true';
                        showFeedback();
                        if (onclickAttr.includes('changeMonthPrev')) {
                            changeMonthPrev();
                        } else {
                            changeMonthNext();
                        }
                        // Reset flag after a delay (browser click event fires ~300ms after touch)
                        resetTimer = setTimeout(function() {
                            button.dataset.touchHandled = 'false';
                            resetTimer = null;
                        }, 400);
                    };
                    
                    // Touch handler (works directly on mobile)
                    button.addEventListener('touchstart', function(e) {
                        button.dataset.touchHandled = 'false';
                        button.dataset.touchStartTime = Date.now().toString();
                        showFeedback();
                    }, { passive: true, capture: false });
                    
                    button.addEventListener('touchend', function(e) {
                        const touchEndTime = Date.now();
                        const touchStartTime = parseInt(button.dataset.touchStartTime || '0');
                        const deltaTime = touchStartTime ? touchEndTime - touchStartTime : 0;
                        
                        // Only trigger if it's a quick tap (not a long press)
                        if (deltaTime < 500 && button.dataset.touchHandled !== 'true') {
                            e.preventDefault();
                            e.stopPropagation();
                            executeChange();
                        } else {
                            button.style.opacity = '1';
                            button.style.transform = 'scale(1)';
                        }
                    }, { passive: false, capture: false });
                    
                    // Click handler (works on desktop and as fallback)
                    button.addEventListener('click', function(e) {
                        if (button.dataset.touchHandled === 'true') {
                            e.preventDefault();
                            e.stopPropagation();
                            return;
                        }
                        e.preventDefault();
                        e.stopPropagation();
                        executeChange();
                    }, { passive: false, capture: false });
                    
                    // Also handle mousedown for desktop
                    button.addEventListener('mousedown', function(e) {
                        e.stopPropagation();
                    }, { passive: true, capture: false });
                }
            });
        }

        // Mobile swipe gesture support for month navigation
        function initSwipeGestures() {
            const calendarWrapper = document.querySelector('.calendar-wrapper');
            if (!calendarWrapper) return;
            
            let touchStartX = 0;
            let touchEndX = 0;
            let touchStartY = 0;
            let touchEndY = 0;
            const minSwipeDistance = 50; // Minimum distance for swipe
            let isSwipe = false;
            
            // CRITICAL: Don't interfere with buttons - check if touch started on button
            calendarWrapper.addEventListener('touchstart', function(e) {
                // Don't track swipe if touching a button or interactive element
                const target = e.target;
                const isButton = target.closest('button, .btn, .calendar-nav-btn, .calendar-event, .calendar-day, a');
                if (isButton) {
                    // Don't track this as a swipe gesture
                    touchStartX = 0;
                    touchStartY = 0;
                    isSwipe = false;
                    return;
                }
                
                touchStartX = e.changedTouches[0].screenX;
                touchStartY = e.changedTouches[0].screenY;
                isSwipe = true;
            }, { passive: true });
            
            calendarWrapper.addEventListener('touchmove', function(e) {
                // Don't process swipe if we're on a button
                const target = e.target;
                const isButton = target.closest('button, .btn, .calendar-nav-btn, .calendar-event, .calendar-day, a');
                if (isButton || !isSwipe) {
                    isSwipe = false;
                    return;
                }
            }, { passive: true });
            
            calendarWrapper.addEventListener('touchend', function(e) {
                // Don't process swipe if we're on a button
                const target = e.target;
                const isButton = target.closest('button, .btn, .calendar-nav-btn, .calendar-event, .calendar-day, a');
                if (isButton || !isSwipe || !touchStartX) {
                    isSwipe = false;
                    touchStartX = 0;
                    touchStartY = 0;
                    return;
                }
                
                touchEndX = e.changedTouches[0].screenX;
                touchEndY = e.changedTouches[0].screenY;
                handleSwipe();
                
                // Reset
                isSwipe = false;
                touchStartX = 0;
                touchStartY = 0;
            }, { passive: true });
            
            function handleSwipe() {
                const deltaX = touchEndX - touchStartX;
                const deltaY = touchEndY - touchStartY;
                
                // Check if horizontal swipe is more significant than vertical
                if (Math.abs(deltaX) > Math.abs(deltaY) && Math.abs(deltaX) > minSwipeDistance) {
                    // Calculate previous and next month
                    let prevMonth = currentMonth - 1;
                    let prevYear = currentYear;
                    if (prevMonth < 1) {
                        prevMonth = 12;
                        prevYear--;
                    }
                    
                    let nextMonth = currentMonth + 1;
                    let nextYear = currentYear;
                    if (nextMonth > 12) {
                        nextMonth = 1;
                        nextYear++;
                    }
                    
                    // Swipe left = next month, swipe right = previous month
                    if (deltaX > 0) {
                        // Swipe right - go to previous month
                        changeMonth(prevMonth, prevYear, 'prev');
                    } else {
                        // Swipe left - go to next month
                        changeMonth(nextMonth, nextYear, 'next');
                    }
                }
            }
        }
        
        // Add haptic feedback simulation (visual feedback since haptic API may not be available)
        function triggerHapticFeedback() {
            // Visual feedback for button presses
            if (window.innerWidth <= 768) {
                // Could use Vibration API if available
                if (navigator.vibrate) {
                    navigator.vibrate(10); // 10ms vibration
                }
            }
        }

        // Improve mobile scrolling performance
        function optimizeMobileScrolling() {
            if (window.innerWidth <= 768) {
                // Add smooth scrolling to event list
                const eventListContent = document.getElementById('eventList');
                if (eventListContent) {
                    eventListContent.style.webkitOverflowScrolling = 'touch';
                    eventListContent.style.overscrollBehavior = 'contain';
                }
            }
        }

        // Add visual feedback for touch interactions
        function enhanceTouchFeedback() {
            if (window.innerWidth <= 768) {
                const touchElements = document.querySelectorAll('.calendar-day, .calendar-event, .event-card, .calendar-nav-btn');
                touchElements.forEach(element => {
                    element.addEventListener('touchstart', function() {
                        this.style.transition = 'transform 0.1s ease';
                    }, { passive: true });
                });
            }
        }

        // Prevent accidental zoom on double tap
        // REMOVED: This was blocking button clicks on mobile
        // Modern browsers handle double-tap zoom prevention automatically with touch-action: manipulation
        function preventDoubleTapZoom() {
            // DISABLED - was causing button click issues on mobile
            // touch-action: manipulation in CSS already prevents double-tap zoom
            return;
        }

        // CRITICAL FIX: Ensure all modal buttons work on mobile
        function fixModalButtons() {
            // Fix all buttons in modals
            const modalButtons = document.querySelectorAll('.modal button, .modal .btn, .modal .btn-close, .modal a.btn, .day-events-modal button, .day-events-modal .btn, .day-events-modal .btn-close, .event-details-modal button, .event-details-modal .btn, .event-details-modal .btn-close');
            
            modalButtons.forEach(function(button) {
                // Skip if already fixed
                if (button.dataset.modalButtonFixed === 'true') {
                    return;
                }
                button.dataset.modalButtonFixed = 'true';
                
                // Ensure button is fully interactive
                button.style.pointerEvents = 'auto';
                button.style.touchAction = 'manipulation';
                button.style.cursor = 'pointer';
                button.style.position = 'relative';
                button.style.zIndex = '1060';
                button.style.userSelect = 'none';
                button.style.webkitUserSelect = 'none';
                
                // CRITICAL: Block/remove excessive padding on Close buttons in modal footer
                if (button.closest('.modal-footer') && (button.classList.contains('btn-secondary') || button.getAttribute('data-bs-dismiss') === 'modal')) {
                    button.style.padding = '10px 20px';
                    button.style.maxWidth = 'none';
                    button.style.width = 'auto';
                }
                
                // Remove any blocking styles
                if (button.style.overflow) {
                    button.style.overflow = 'visible';
                }
                
                // Ensure click events work
                button.addEventListener('click', function(e) {
                    // Don't prevent default - let button action work
                    e.stopPropagation(); // But stop propagation to modal
                }, { passive: true, capture: false });
                
                // Ensure touch events work
                button.addEventListener('touchend', function(e) {
                    // Let browser convert touch to click naturally
                    e.stopPropagation();
                }, { passive: true, capture: false });
                
                // Ensure mousedown works
                button.addEventListener('mousedown', function(e) {
                    e.stopPropagation();
                }, { passive: true, capture: false });
            });
            
            // Fix event items in modals
            const eventItems = document.querySelectorAll('.day-events-modal .day-event-item');
            eventItems.forEach(function(item) {
                if (item.dataset.modalItemFixed === 'true') {
                    return;
                }
                item.dataset.modalItemFixed = 'true';
                
                item.style.pointerEvents = 'auto';
                item.style.touchAction = 'manipulation';
                item.style.cursor = 'pointer';
                item.style.position = 'relative';
                item.style.zIndex = '1057';
            });
        }
        
        // Enhance modals for mobile (bottom sheet style)
        function enhanceModalsForMobile() {
            if (window.innerWidth <= 768) {
                const modals = document.querySelectorAll('.day-events-modal, .event-details-modal');
                modals.forEach(modal => {
                    // CRITICAL: Fix buttons when modal is shown
                    modal.addEventListener('shown.bs.modal', function() {
                        fixModalButtons();
                        // Also fix after a short delay to catch dynamically added content
                        setTimeout(fixModalButtons, 100);
                    });
                    
                    // Add swipe-to-close functionality
                    let touchStartY = 0;
                    let touchCurrentY = 0;
                    let isDragging = false;
                    
                    const modalContent = modal.querySelector('.modal-content');
                    if (!modalContent) return;
                    
                    // Only allow swipe from header, not from buttons
                    modalContent.addEventListener('touchstart', function(e) {
                        // Don't allow swipe if touching a button
                        const target = e.target;
                        const isButton = target.closest('button, .btn, .btn-close, .day-event-item');
                        if (isButton) {
                            isDragging = false;
                            return;
                        }
                        
                        // Only allow swipe from header or top of modal
                        if (e.target.closest('.modal-header') || e.touches[0].clientY < 100) {
                            touchStartY = e.touches[0].clientY;
                            isDragging = true;
                        }
                    }, { passive: true });
                    
                    modalContent.addEventListener('touchmove', function(e) {
                        if (!isDragging) return;
                        
                        // Don't allow swipe if touching a button
                        const target = e.target;
                        const isButton = target.closest('button, .btn, .btn-close, .day-event-item');
                        if (isButton) {
                            isDragging = false;
                            modalContent.style.transform = '';
                            modalContent.style.transition = '';
                            return;
                        }
                        
                        touchCurrentY = e.touches[0].clientY;
                        const deltaY = touchCurrentY - touchStartY;
                        
                        // Only allow downward swipe
                        if (deltaY > 0) {
                            modalContent.style.transform = `translateY(${Math.min(deltaY, 100)}px)`;
                            modalContent.style.transition = 'none';
                        }
                    }, { passive: true });
                    
                    modalContent.addEventListener('touchend', function(e) {
                        if (!isDragging) return;
                        isDragging = false;
                        const deltaY = touchCurrentY - touchStartY;
                        modalContent.style.transition = 'transform 0.3s cubic-bezier(0.4, 0, 0.2, 1)';
                        
                        // Close modal if swiped down more than 100px
                        if (deltaY > 100) {
                            const bsModal = bootstrap.Modal.getInstance(modal);
                            if (bsModal) {
                                bsModal.hide();
                            }
                        } else {
                            // Snap back
                            modalContent.style.transform = 'translateY(0)';
                        }
                    }, { passive: true });
                });
            }
        }
        
        // Load events on page load
        document.addEventListener('DOMContentLoaded', function() {
            // CRITICAL FIX: Initialize navigation buttons first
            initNavigationButtons();
            
            // Mobile: collapsible event list
            var eventListToggle = document.getElementById('eventListToggle');
            var eventListWrapper = document.getElementById('eventListWrapper');
            if (eventListToggle && eventListWrapper) {
                eventListToggle.addEventListener('click', function() {
                    eventListWrapper.classList.toggle('event-list-collapsed');
                    eventListToggle.setAttribute('aria-expanded', eventListWrapper.classList.contains('event-list-collapsed') ? 'false' : 'true');
                });
            }
            
            // Check if we should show "Jump to Today" button
            checkShowTodayButton();
            
            // CRITICAL: Fix all modal buttons on page load
            fixModalButtons();
            
            // Also fix modal buttons after a delay to catch any dynamically added content
            setTimeout(fixModalButtons, 500);
            
            loadEvents();
            
            // Initialize mobile enhancements
            if (window.innerWidth <= 768) {
                initSwipeGestures();
                optimizeMobileScrolling();
                enhanceTouchFeedback();
                enhanceModalsForMobile();
                
                // Show swipe indicator on first load
                setTimeout(() => {
                    toggleSwipeIndicator(true);
                }, 1000);
            }
            
            // CRITICAL: Fix modal buttons when modals are shown
            document.addEventListener('shown.bs.modal', function(e) {
                // Fix all buttons in the shown modal
                fixModalButtons();
                
                // Enhance modals for mobile
                enhanceModalsForMobile();
                
                // Also fix after a short delay to catch any dynamically added content
                setTimeout(function() {
                    fixModalButtons();
                }, 200);
            });
            
            // Also fix buttons when modals are about to be shown
            document.addEventListener('show.bs.modal', function(e) {
                // Fix buttons before modal is shown
                setTimeout(fixModalButtons, 50);
            });
            
            // Re-initialize on resize
            let resizeTimer;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    if (window.innerWidth <= 768) {
                        initNavigationButtons(); // Re-initialize buttons
                        initSwipeGestures();
                        optimizeMobileScrolling();
                        enhanceTouchFeedback();
                        enhanceModalsForMobile();
                    }
                }, 250);
            });
            
            // Also re-initialize buttons after calendar is rendered
            setTimeout(function() {
                initNavigationButtons();
                checkShowTodayButton();
            }, 500);
        });
        
        // Add fade-in animation when page loads
        window.addEventListener('load', function() {
            const calendarDays = document.getElementById('calendarDays');
            if (calendarDays) {
                calendarDays.classList.add('fade-in');
            }
        });
    </script>
</body>
</html>

