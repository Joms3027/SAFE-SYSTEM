<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAdmin();

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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php
    require_once '../includes/admin_layout_helper.php';
    admin_page_head('Calendar', 'Manage university events and view Philippines holidays');
    ?>
    <style>
        .calendar-container {
            display: flex;
            gap: 20px;
            margin-top: 20px;
        }
        
        .calendar-wrapper {
            flex: 1;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .calendar-header {
            background: linear-gradient(135deg, #003366 0%, #005599 100%);
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .calendar-header h3 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 600;
            color: white;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .calendar-header-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .calendar-event-count {
            background: rgba(255,255,255,0.2);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .calendar-nav {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        
        .calendar-nav-btn {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            height: 36px;
        }
        
        .calendar-nav-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-1px);
        }
        
        .calendar-nav-btn:active {
            transform: translateY(0);
        }
        
        .calendar-nav-btn.today-btn {
            background: rgba(255,255,255,0.25);
            font-weight: 600;
            padding: 8px 16px;
            min-width: auto;
        }
        
        .calendar-nav-btn.today-btn:hover {
            background: rgba(255,255,255,0.35);
        }
        
        .month-year-selector {
            display: flex;
            gap: 8px;
            align-items: center;
            background: rgba(255,255,255,0.15);
            padding: 4px 8px;
            border-radius: 6px;
        }
        
        .month-year-selector select {
            background: transparent;
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.875rem;
        }
        
        .month-year-selector select option {
            background: #003366;
            color: white;
        }
        
        .calendar-grid {
            padding: 20px;
        }
        
        .calendar-weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
            margin-bottom: 10px;
        }
        
        .calendar-weekday {
            text-align: center;
            font-weight: 600;
            color: #666;
            padding: 10px;
            font-size: 0.875rem;
        }
        
        .calendar-days {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
            grid-auto-rows: 120px;
        }
        
        .calendar-day {
            height: 120px;
            border: 2px solid #e0e0e0;
            padding: 8px;
            position: relative;
            cursor: pointer;
            transition: all 0.2s ease;
            background: white;
            display: flex;
            flex-direction: column;
            border-radius: 6px;
            overflow: hidden;
        }
        
        .calendar-day.other-month {
            background: #f9f9f9;
            color: #ccc;
            border-color: #f0f0f0;
        }
        
        .calendar-day.today {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            border-color: #2196f3;
            border-width: 2px;
            box-shadow: 0 2px 8px rgba(33,150,243,0.2);
        }
        
        .calendar-day.today .calendar-day-number {
            background: #2196f3;
            color: white;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }
        
        .calendar-day.has-events {
            border-left: 4px solid #2196f3;
        }
        
        .calendar-day-number {
            font-weight: 600;
            margin-bottom: 6px;
            font-size: 0.875rem;
            flex-shrink: 0;
            padding: 2px 6px;
            border-radius: 4px;
            width: fit-content;
            transition: all 0.2s;
            height: 20px;
            line-height: 20px;
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
            font-size: 0.7rem;
            padding: 1px 5px;
            border-radius: 3px;
            color: white;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            cursor: pointer;
            flex-shrink: 0;
            transition: all 0.2s;
            border: 1px solid rgba(255,255,255,0.2);
            font-weight: 500;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            height: 18px;
            line-height: 18px;
            max-height: 18px;
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
        
        .calendar-event-more:hover {
            background: linear-gradient(135deg, #555 0%, #444 100%);
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        
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
            width: 420px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
            max-height: calc(100vh - 200px);
        }
        
        .event-list-header {
            padding: 15px 20px;
            border-bottom: 2px solid #e0e0e0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
        }
        
        .event-list-header-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .event-list-header h4 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
        }
        
        .event-list-header-actions {
            display: flex;
            gap: 5px;
        }
        
        .event-list-filter-btn {
            background: transparent;
            border: 1px solid #ddd;
            color: #666;
            padding: 4px 8px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.75rem;
            transition: all 0.2s;
        }
        
        .event-list-filter-btn:hover {
            background: #f0f0f0;
            border-color: #003366;
            color: #003366;
        }
        
        .event-list-filter-btn.active {
            background: #003366;
            border-color: #003366;
            color: white;
        }
        
        .event-list-content {
            flex: 1;
            overflow-y: auto;
            padding: 15px;
            background: #fafafa;
        }
        
        .event-list-empty {
            text-align: center;
            padding: 40px 20px;
            color: #999;
        }
        
        .event-list-empty i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.3;
        }
        
        .event-list-empty-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 8px;
            color: #666;
        }
        
        .event-list-empty-text {
            font-size: 0.875rem;
            color: #999;
        }
        
        .loading-spinner {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 40px;
        }
        
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #003366;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .event-card {
            background: white;
            border: 1px solid #e0e0e0;
            border-left: 4px solid #2196f3;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .event-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--event-color, #2196f3);
            transition: width 0.3s;
        }
        
        
        .event-card-title {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 10px;
            color: #333;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .event-card.past-event {
            opacity: 0.7;
            border-left-color: #999;
        }
        
        .event-card.today-event {
            border-left-color: #4caf50;
            background: linear-gradient(135deg, #ffffff 0%, #f1f8f4 100%);
        }
        
        .event-card.upcoming-event {
            border-left-color: #2196f3;
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

        /* Admin-added half-day holidays (distinct from full-day yellow) */
        .event-card.half-day-holiday-event {
            border-left: 4px solid #bf360c;
            background: linear-gradient(105deg, #fff8e1 0%, #fff8e1 45%, #ffe0b2 45%, #ffe0b2 100%);
        }
        .calendar-event.calendar-event-half-holiday {
            background: repeating-linear-gradient(
                -45deg,
                #e8a317,
                #e8a317 6px,
                #ffc107 6px,
                #ffc107 12px
            ) !important;
            color: #1a1a1a !important;
            font-weight: 600;
            border-left: 3px solid #bf360c;
        }

        /* Add Holiday — multi-date picker */
        .holiday-date-picker-weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 4px;
            margin-bottom: 6px;
            text-align: center;
            font-size: 0.7rem;
            font-weight: 600;
            color: #6c757d;
        }
        .holiday-date-picker-days {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 4px;
        }
        .holiday-date-picker-cell.holiday-date-picker-empty {
            min-height: 36px;
        }
        button.holiday-date-picker-day {
            min-height: 36px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            background: #fff;
            font-size: 0.875rem;
            padding: 0;
            transition: background 0.15s, border-color 0.15s, color 0.15s;
        }
        button.holiday-date-picker-day:hover {
            background: #fff3cd;
            border-color: #ffc107;
        }
        button.holiday-date-picker-day.selected {
            background: #ffc107;
            border-color: #e0a800;
            color: #1a1a1a;
            font-weight: 600;
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
            gap: 8px;
            font-size: 0.875rem;
            color: #666;
        }
        
        .event-detail i {
            width: 16px;
            color: #999;
        }
        
        .event-card-actions {
            display: flex;
            gap: 5px;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #e0e0e0;
        }
        
        .event-action-btn {
            padding: 6px 12px;
            border: 1px solid transparent;
            background: transparent;
            color: #666;
            cursor: pointer;
            border-radius: 6px;
            font-size: 0.8rem;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 500;
        }
        
        .event-action-btn:hover {
            background: #f8f9fa;
            border-color: #e0e0e0;
            transform: translateY(-1px);
        }
        
        .event-action-btn.edit {
            color: #2196f3;
        }
        
        .event-action-btn.edit:hover {
            background: #e3f2fd;
            border-color: #2196f3;
            color: #1976d2;
        }
        
        .event-action-btn.delete {
            color: #f44336;
        }
        
        .event-action-btn.delete:hover {
            background: #ffebee;
            border-color: #f44336;
            color: #d32f2f;
        }
        
        .event-action-btn.reminder {
            color: #9c27b0;
        }
        
        .event-action-btn.reminder:hover {
            background: #f3e5f5;
            border-color: #9c27b0;
            color: #7b1fa2;
        }
        
        .event-modal .modal-body {
            padding: 25px;
        }
        
        .event-modal .form-label {
            font-weight: 600;
            margin-bottom: 8px;
            color: #333;
            font-size: 0.9rem;
        }
        
        .event-modal .form-control,
        .event-modal .form-select {
            margin-bottom: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            padding: 10px 12px;
            transition: all 0.2s;
        }
        
        .event-modal .form-control:focus,
        .event-modal .form-select:focus {
            border-color: #003366;
            box-shadow: 0 0 0 0.2rem rgba(0,51,102,0.1);
        }
        
        .event-modal .form-control.is-invalid,
        .event-modal .form-select.is-invalid {
            border-color: #dc3545;
        }
        
        .event-color-preview {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            border: 2px solid #e0e0e0;
            margin-top: 8px;
            transition: all 0.2s;
        }
        
        
        .form-help-text {
            font-size: 0.8rem;
            color: #666;
            margin-top: -10px;
            margin-bottom: 10px;
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
            background: white;
            border: 1px solid #e0e0e0;
            border-left: 4px solid var(--event-color, #2196f3);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }
        
        
        .day-events-modal .day-event-item:last-child {
            margin-bottom: 0;
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
        
        .calendar-stats {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .stat-card {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px 20px;
            flex: 1;
            min-width: 150px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            transition: all 0.2s;
        }
        
        
        .stat-card-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #003366;
            margin-bottom: 5px;
        }
        
        .stat-card-label {
            font-size: 0.875rem;
            color: #666;
            font-weight: 500;
        }
        
        .stat-card-icon {
            float: right;
            font-size: 2rem;
            opacity: 0.2;
            color: #003366;
        }
        
        .event-search-filter {
            padding: 10px 15px;
            border-bottom: 1px solid #e0e0e0;
            background: #f8f9fa;
        }
        
        .event-search-input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.875rem;
            transition: all 0.2s;
        }
        
        .event-search-input:focus {
            outline: none;
            border-color: #003366;
            box-shadow: 0 0 0 0.2rem rgba(0,51,102,0.1);
        }
        
        /* Ensure confirmation modal appears above event modal */
        .event-modal.show {
            z-index: 9999999 !important;
        }
        
        .confirmation-modal.show {
            z-index: 10000000 !important;
        }
        
        /* Adjust backdrop stacking - second backdrop should be above first */
        .modal-backdrop.show + .modal-backdrop.show {
            z-index: 1050 !important;
        }
        
        /* Force hide backdrops when no modals are open */
        body:not(.modal-open) .modal-backdrop,
        body:not(.modal-open) .modal-backdrop.show {
            display: none !important;
            opacity: 0 !important;
            visibility: hidden !important;
            pointer-events: none !important;
            z-index: -1 !important;
        }
        
        /* Ensure backdrops without show class are hidden */
        .modal-backdrop:not(.show) {
            display: none !important;
            opacity: 0 !important;
            visibility: hidden !important;
            pointer-events: none !important;
        }
        
        .confirmation-modal .modal-body {
            padding: 25px;
            text-align: center;
        }
        
        .confirmation-modal .modal-icon {
            font-size: 3rem;
            color: #ff9800;
            margin-bottom: 15px;
        }
        
        .confirmation-modal .modal-message {
            font-size: 1.1rem;
            color: #333;
            margin-bottom: 20px;
        }
        
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 10000;
            min-width: 300px;
        }
        
        .toast {
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        .calendar-loading {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 400px;
            flex-direction: column;
            gap: 20px;
        }
        
        .calendar-loading-text {
            color: #666;
            font-size: 1rem;
        }
        
        /* TARF Modal Styles */
        .avatar-circle-sm {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
            flex-shrink: 0;
        }
        
        .cursor-pointer {
            cursor: pointer;
        }
        
        #employeeListContainer .form-check {
            border: 1px solid transparent;
            border-radius: 6px;
            transition: all 0.2s;
        }
        
        
        #employeeListContainer .form-check-input:checked ~ label {
            font-weight: 600;
        }
        
        #generatedDates {
            font-size: 0.9rem;
            line-height: 1.6;
        }
        
        @media (max-width: 1024px) {
            .calendar-container {
                flex-direction: column;
            }
            
            .event-list-wrapper {
                width: 100%;
                max-height: 500px;
            }
            
            .calendar-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .calendar-header-info {
                width: 100%;
                justify-content: space-between;
            }
            
            .calendar-nav {
                width: 100%;
                justify-content: center;
            }
            
            .calendar-schedule-setting {
                font-size: 0.8125rem;
            }
            .calendar-schedule-setting .form-check-label strong {
                font-weight: 600;
            }
        }
    </style>
</head>
<body class="layout-admin">
    <?php require_once '../includes/navigation.php';
    include_navigation(); ?>
    
    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <?php
                admin_page_header(
                    'Calendar',
                    '',
                    'fas fa-calendar-alt',
                    [
                        
                    ],
                    '<div class="d-flex gap-2"><button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#eventModal" onclick="openEventModal()"><i class="fas fa-plus me-1"></i>Add Event</button><button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#tarfModal" onclick="openTarfModal()"><i class="fas fa-file-alt me-1"></i>Add TARF</button><button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#holidayModal" onclick="openHolidayModal()"><i class="fas fa-calendar-check me-1"></i>Add Holiday</button></div>'
                );
                ?>

                <?php displayMessage(); ?>

                <div class="calendar-container">
                    <div class="calendar-wrapper">
                        <div class="calendar-schedule-setting alert alert-secondary border-0 py-2 px-3 mb-3 small shadow-sm">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" id="holidayWeekEightHourSwitch" role="switch">
                                <label class="form-check-label" for="holidayWeekEightHourSwitch">
                                    <strong>Holiday week:</strong> when today is inside a Sunday–Saturday week that contains a holiday (from admin holidays or calendar holiday events), late/undertime follow a standard 8-hour schedule (08:00–12:00, 13:00–17:00). Other calendar weeks—and any week viewed after Sunday has moved on—keep each employee's saved official times.
                                </label>
                            </div>
                        </div>
                        <div class="calendar-header">
                            <div class="calendar-header-info">
                                <h3>
                                    <i class="fas fa-calendar-alt"></i>
                                    <?php echo $monthName; ?>
                                    <span class="calendar-event-count" id="calendarEventCount" style="display: none;">
                                        <i class="fas fa-circle" style="font-size: 0.5rem; margin-right: 5px;"></i>
                                        <span id="eventCountText">0 events</span>
                                    </span>
                                </h3>
                                <div class="month-year-selector">
                                    <select id="monthSelector" onchange="changeMonthFromSelector()">
                                        <?php
                                        $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
                                        foreach ($months as $index => $month) {
                                            $monthNum = $index + 1;
                                            $selected = $monthNum == $currentMonth ? 'selected' : '';
                                            echo "<option value=\"$monthNum\" $selected>$month</option>";
                                        }
                                        ?>
                                    </select>
                                    <select id="yearSelector" onchange="changeMonthFromSelector()">
                                        <?php
                                        $startYear = $currentYear - 2;
                                        $endYear = $currentYear + 5;
                                        for ($year = $startYear; $year <= $endYear; $year++) {
                                            $selected = $year == $currentYear ? 'selected' : '';
                                            echo "<option value=\"$year\" $selected>$year</option>";
                                        }
                                        ?>
                                    </select>
                            </div>
                            </div>
                            <div class="calendar-nav">
                                <button class="calendar-nav-btn today-btn" onclick="goToToday()" title="Go to Today">
                                    <i class="fas fa-home"></i>
                                    <span style="margin-left: 5px;">Today</span>
                                </button>
                                <button class="calendar-nav-btn" onclick="changeMonth(<?php echo $prevMonth; ?>, <?php echo $prevYear; ?>)" title="Previous Month">
                                    <i class="fas fa-chevron-left"></i>
                                </button>
                                <button class="calendar-nav-btn" onclick="changeMonth(<?php echo $nextMonth; ?>, <?php echo $nextYear; ?>)" title="Next Month">
                                    <i class="fas fa-chevron-right"></i>
                                </button>
                            </div>
                        </div>
                        <div class="calendar-grid">
                            <div class="calendar-weekdays">
                                <div class="calendar-weekday">Sun</div>
                                <div class="calendar-weekday">Mon</div>
                                <div class="calendar-weekday">Tue</div>
                                <div class="calendar-weekday">Wed</div>
                                <div class="calendar-weekday">Thu</div>
                                <div class="calendar-weekday">Fri</div>
                                <div class="calendar-weekday">Sat</div>
                    </div>
                            <div class="calendar-days" id="calendarDays">
                                <!-- Calendar days will be populated by JavaScript -->
                            </div>
                        </div>
                    </div>
                    
                    <div class="event-list-wrapper">
                        <div class="event-list-header">
                            <div class="event-list-header-left">
                                <i class="fas fa-list-ul"></i>
                                <h4>Event List</h4>
                            </div>
                            <div class="event-list-header-actions">
                                <button class="event-list-filter-btn active" data-filter="all" onclick="filterEvents('all')" title="All Events">
                                    All
                                </button>
                                <button class="event-list-filter-btn" data-filter="upcoming" onclick="filterEvents('upcoming')" title="Upcoming Events">
                                    Upcoming
                                </button>
                            </div>
                        </div>
                        <div class="event-search-filter">
                            <input type="text" class="event-search-input" id="eventSearchInput" placeholder="Search events..." oninput="searchEvents()">
                        </div>
                        <div class="event-list-content" id="eventList">
                            <div class="loading-spinner">
                                <div class="spinner"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Day Events Modal -->
    <div class="modal fade day-events-modal" id="dayEventsModal" tabindex="-1" aria-labelledby="dayEventsModalLabel" aria-hidden="true" data-bs-backdrop="true" data-bs-keyboard="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="dayEventsModalLabel">
                        <i class="fas fa-calendar-day me-2"></i>Events for Day
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="dayEventsModalBody">
                    <!-- Events will be populated by JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Close
                    </button>
                    <button type="button" class="btn btn-primary" id="addEventToDayBtn" onclick="addEventToSelectedDay()">
                        <i class="fas fa-plus me-1"></i>Add Event
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div class="modal fade confirmation-modal" id="confirmationModal" tabindex="-1" aria-labelledby="confirmationModalLabel" aria-hidden="true" data-bs-backdrop="true" data-bs-keyboard="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-body">
                    <div class="modal-icon">
                        <i class="fas fa-exclamation-triangle" id="confirmationIcon"></i>
                    </div>
                    <div class="modal-message" id="confirmationMessage"></div>
                    <div class="d-flex gap-2 justify-content-center">
                        <button type="button" class="btn btn-secondary" id="confirmationCancelBtn" onclick="closeConfirmationModal()">
                            Cancel
                        </button>
                        <button type="button" class="btn btn-danger" id="confirmationConfirmBtn">
                            Confirm
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- TARF Modal -->
    <div class="modal fade event-modal" id="tarfModal" tabindex="-1" aria-labelledby="tarfModalLabel" aria-hidden="true" data-bs-backdrop="true" data-bs-keyboard="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="tarfModalLabel">Add TARF</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="tarfForm">
                    <input type="hidden" id="tarfId" name="tarf_id" value="">
                    <input type="hidden" id="tarfAction" name="action" value="create">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="tarfTitle" class="form-label">Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="tarfTitle" name="title" required placeholder="Enter TARF title">
                        </div>
                        
                        <div class="mb-3">
                            <label for="tarfDescription" class="form-label">Description</label>
                            <textarea class="form-control" id="tarfDescription" name="description" rows="3" placeholder="Enter TARF description (optional)"></textarea>
                        </div>

                        <div class="mb-3">
                            <label for="tarfCalendarKind" class="form-label">Activity type</label>
                            <select class="form-select" id="tarfCalendarKind" name="calendar_kind">
                                <option value="travel">Travel TARF (off-site — auto DTR / no timekeeper)</option>
                                <option value="ntarf">NTARF / on-site activity — employees use timekeeper</option>
                            </select>
                            <small class="form-text text-muted">For NTARF and other on-site activities, participants still record time in, lunch out, lunch in, and time out.</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="tarfFile" class="form-label">TARF File</label>
                            <input type="file" class="form-control" id="tarfFile" name="tarf_file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                            <small class="form-text text-muted">Upload TARF document (PDF, DOC, DOCX, JPG, PNG)</small>
                            <div id="tarfFilePreview" class="mt-2 d-none">
                                <small class="text-muted">Current file: <span id="tarfFileName"></span></small>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="tarfEmployees" class="form-label">Select Employee(s) <span class="text-danger">*</span></label>
                            <div class="border rounded p-3" style="max-height: 300px; overflow-y: auto; background: #f8f9fa;">
                                <div class="mb-2">
                                    <div class="row g-2">
                                        <div class="col-md-8">
                                            <input type="text" class="form-control form-control-sm" id="employeeSearch" placeholder="Search by name or ID..." oninput="filterEmployees()">
                                        </div>
                                        <div class="col-md-4">
                                            <select class="form-select form-select-sm" id="employeeTypeFilter" onchange="filterEmployees()">
                                                <option value="all">All Types</option>
                                                <option value="faculty">Faculty</option>
                                                <option value="staff">Staff</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div id="employeeListContainer">
                                    <!-- Employee list will be populated by JavaScript -->
                                </div>
                                <div id="employeePagination" class="mt-2 d-flex justify-content-between align-items-center">
                                    <!-- Pagination controls will be populated by JavaScript -->
                                </div>
                                <div class="mt-2 text-muted small">
                                    <span id="selectedEmployeeCount">0</span> employee(s) selected
                                </div>
                            </div>
                        </div>
                        
                        <div class="row" id="tarfDateRow">
                            <div class="col-md-6 mb-3">
                                <label for="tarfStartDate" class="form-label">Start Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="tarfStartDate" name="start_date" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="tarfDays" class="form-label">How Many Days <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="tarfDays" name="days" min="1" required placeholder="Enter number of days">
                                <small class="form-help-text">Consecutive days will be generated from the start date</small>
                            </div>
                        </div>
                        
                        <div class="row d-none" id="tarfEditDateRow">
                            <div class="col-md-12 mb-3">
                                <label for="tarfEditDate" class="form-label">Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="tarfEditDate" name="edit_date" required>
                                <small class="form-help-text">Edit mode: Select a single date for this TARF entry</small>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Generated Dates</label>
                            <div id="generatedDates" class="border rounded p-3 bg-light" style="min-height: 50px;">
                                <span class="text-muted">Select start date and number of days to preview dates</span>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-danger d-none" id="deleteTarfBtn" onclick="deleteTarf()">Delete TARF</button>
                        <button type="submit" class="btn btn-success" id="saveTarfBtn">Save TARF</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Holiday Modal -->
    <div class="modal fade event-modal" id="holidayModal" tabindex="-1" aria-labelledby="holidayModalLabel" aria-hidden="true" data-bs-backdrop="true" data-bs-keyboard="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="holidayModalLabel">Add Holiday</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="holidayForm">
                    <input type="hidden" id="holidayId" name="holiday_id" value="">
                    <input type="hidden" id="holidayAction" name="action" value="create">
                    <div class="modal-body">
                        <div class="alert alert-info mb-3">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Note:</strong> This holiday will apply to <strong>all employees</strong>. Credited hours use each person’s <strong>official time</strong> when they have one for that day, or their <strong>actual time entries</strong> if they log in on that holiday. With no official time and no entries, the day is marked <strong>holiday only</strong> (no default 8:00–17:00 credit).
                        </div>

                        <div class="border rounded-3 p-3 mb-3 bg-light border-warning" id="holidayHalfDayCard">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="holidayHalfDay" name="is_half_day" value="1">
                                <label class="form-check-label fw-semibold" for="holidayHalfDay">Half-day holiday</label>
                            </div>
                            <div id="holidayHalfDayPeriodWrap" class="mt-3 d-none">
                                <div class="fw-semibold small mb-2">Which half is the holiday?</div>
                                <div class="d-flex flex-wrap gap-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="half_day_period" id="holidayHalfAm" value="morning" checked>
                                        <label class="form-check-label" for="holidayHalfAm">Morning <span class="text-muted small">(official time in → lunch out)</span></label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="half_day_period" id="holidayHalfPm" value="afternoon">
                                        <label class="form-check-label" for="holidayHalfPm">Afternoon <span class="text-muted small">(official lunch in → time out)</span></label>
                                    </div>
                                </div>
                            </div>
                            <p class="small text-muted mb-0 mt-2" id="holidayHalfDayHelp">
                                When enabled, pick <strong>morning</strong> or <strong>afternoon</strong>. The calendar uses a striped half-day style. For employees with official time, credits follow that half of their schedule; others are holiday only.
                            </p>
                        </div>
                        
                        <div class="mb-3">
                            <label for="holidayTitle" class="form-label">Holiday Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="holidayTitle" name="title" required placeholder="Enter holiday title (e.g., New Year's Day, Christmas)">
                        </div>
                        
                        <div class="row" id="holidayDateRow">
                            <div class="col-12 mb-3">
                                <label class="form-label">Holiday dates <span class="text-danger">*</span></label>
                                <div class="holiday-date-picker border rounded-3 p-3 bg-white">
                                    <div class="d-flex justify-content-between align-items-center mb-2 gap-2">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="holidayDatePickerPrev" aria-label="Previous month">
                                            <i class="fas fa-chevron-left"></i>
                                        </button>
                                        <span id="holidayDatePickerMonthLabel" class="fw-semibold flex-grow-1 text-center"></span>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="holidayDatePickerNext" aria-label="Next month">
                                            <i class="fas fa-chevron-right"></i>
                                        </button>
                                    </div>
                                    <div id="holidayDatePickerGrid" class="holiday-date-picker-grid-wrap"></div>
                                    <div class="d-flex justify-content-between align-items-center mt-2 flex-wrap gap-2">
                                        <button type="button" class="btn btn-sm btn-link p-0 text-secondary" id="holidayDatePickerClear">Clear selection</button>
                                        <span class="small text-muted" id="holidayDatePickerCount">0 dates selected</span>
                                    </div>
                                </div>
                                <small class="form-help-text">Click days on the calendar to select or deselect. Use the arrows to change months. Non-consecutive days are allowed.</small>
                            </div>
                        </div>
                        
                        <div class="row d-none" id="holidayEditDateRow">
                            <div class="col-md-12 mb-3">
                                <label for="holidayEditDate" class="form-label">Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="holidayEditDate" name="edit_date">
                                <small class="form-help-text">Edit mode: Select a single date for this holiday entry</small>
                            </div>
                        </div>
                        
                        <div class="mb-3" id="holidaySelectedDatesSection">
                            <label class="form-label">Selected dates</label>
                            <div id="holidayGeneratedDates" class="border rounded p-3 bg-light" style="min-height: 50px;">
                                <span class="text-muted">Choose one or more days on the calendar above</span>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-danger d-none" id="deleteHolidayBtn" onclick="deleteHoliday()">Delete Holiday</button>
                        <button type="submit" class="btn btn-warning" id="saveHolidayBtn">Save Holiday</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Event Modal -->
    <div class="modal fade event-modal" id="eventModal" tabindex="-1" aria-labelledby="eventModalLabel" aria-hidden="true" data-bs-backdrop="true" data-bs-keyboard="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="eventModalLabel">Add Event</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="eventForm">
                    <div class="modal-body">
                        <input type="hidden" id="eventId" name="event_id">
                        <input type="hidden" id="action" name="action" value="create">
                        
                        <div class="mb-3">
                            <label for="eventTitle" class="form-label">Event Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="eventTitle" name="title" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="eventDescription" class="form-label">Description</label>
                            <textarea class="form-control" id="eventDescription" name="description" rows="3"></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="eventDate" class="form-label">Event Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="eventDate" name="event_date" required>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label for="eventTime" class="form-label">Start Time</label>
                                <input type="time" class="form-control" id="eventTime" name="event_time">
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label for="eventEndTime" class="form-label">End Time</label>
                                <input type="time" class="form-control" id="eventEndTime" name="end_time">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="eventCategory" class="form-label">Category</label>
                                <select class="form-select" id="eventCategory" name="category" onchange="updateEventColor()">
                                    <option value="Training">Training</option>
                                    <option value="Workshop">Workshop</option>
                                    <option value="Seminar">Seminar</option>
                                    <option value="Conference">Conference</option>
                                    <option value="Holiday">Holiday</option>
                                    <option value="University Event">University Event</option>
                                </select>
                                <div class="event-color-preview" id="eventColorPreview" style="background-color: #9c27b0;"></div>
                                <small class="form-help-text">Event color will be set based on category</small>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="eventLocation" class="form-label">Location</label>
                                <input type="text" class="form-control" id="eventLocation" name="location" placeholder="e.g., DICT-DTC Puerto Princesa, Palawan">
                                <small class="form-help-text">Optional: Specify the event location</small>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="eventType" class="form-label">Event Type</label>
                            <select class="form-select" id="eventType" name="event_type">
                                <option value="university_event">University Event</option>
                                <option value="holiday">Holiday</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-danger d-none" id="deleteEventBtn" onclick="deleteEvent()">Delete Event</button>
                        <button type="submit" class="btn btn-primary" id="saveEventBtn">Save Event</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', true); ?>"></script>
    <script>
        let currentMonth = <?php echo $currentMonth; ?>;
        let currentYear = <?php echo $currentYear; ?>;
        let events = [];
        let currentEvent = null;

        let allEvents = [];
        let filteredEvents = [];
        let currentFilter = 'all';
        let searchQuery = '';

        /** Add Holiday — multi-date calendar (create mode) */
        let holidaySelectedDates = new Set();
        let holidayDatePickerMonth = new Date();

        // Load events for the current month
        function loadEvents() {
            const eventList = document.getElementById('eventList');
            if (!eventList) {
                console.error('Event list element not found');
                return;
            }
            
            eventList.innerHTML = '<div class="loading-spinner"><div class="spinner"></div></div>';
            
            const start = `${currentYear}-${String(currentMonth).padStart(2, '0')}-01`;
            const lastDay = new Date(currentYear, currentMonth, 0).getDate();
            const end = `${currentYear}-${String(currentMonth).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`;
            
            fetch(`calendar_api.php?action=event_list&start=${start}&end=${end}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        events = data.events || [];
                        allEvents = data.events || [];
                        filteredEvents = data.events || [];
                        renderCalendar();
                        renderEventList();
                        updateEventCount();
                    } else {
                        eventList.innerHTML = '<div class="event-list-empty"><div class="event-list-empty-title">Error loading events</div></div>';
                        console.error('Failed to load events:', data.message);
                    }
                })
                .catch(error => {
                    console.error('Error loading events:', error);
                    if (eventList) {
                        eventList.innerHTML = '<div class="event-list-empty"><div class="event-list-empty-title">Error loading events</div><div class="event-list-empty-text">Please try again later</div></div>';
                    }
                });
        }

        // Update event count badge
        function updateEventCount() {
            const currentMonthEvents = events.filter(e => {
                const eventDate = new Date(e.date);
                return eventDate.getMonth() + 1 === currentMonth && eventDate.getFullYear() === currentYear;
            });
            
            const eventCount = currentMonthEvents.length;
            const countElement = document.getElementById('calendarEventCount');
            const countText = document.getElementById('eventCountText');
            
            if (eventCount > 0) {
                countElement.style.display = 'inline-flex';
                countText.textContent = `${eventCount} event${eventCount !== 1 ? 's' : ''}`;
            } else {
                countElement.style.display = 'none';
            }
        }

        // Render calendar
        function renderCalendar() {
            const calendarDays = document.getElementById('calendarDays');
            calendarDays.innerHTML = '';
            
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
                // Add class to indicate this day has events
                dayDiv.classList.add('has-events');
                
                // Show only first 3 events (fixed display regardless of total count)
                const eventsToShow = dayEvents.slice(0, 3);
                eventsToShow.forEach(event => {
                    const eventDiv = document.createElement('div');
                    eventDiv.className = 'calendar-event' + (event.isHalfDayHoliday ? ' calendar-event-half-holiday' : '');
                    eventDiv.style.backgroundColor = event.isHalfDayHoliday ? '' : event.color;
                    eventDiv.textContent = event.title;
                    eventDiv.title = `${event.title}${event.time ? ' at ' + event.time : ''}${event.location ? ' - ' + event.location : ''}`;
                    eventDiv.onclick = (e) => {
                        e.stopPropagation();
                        openEventModal(event);
                    };
                    eventsContainer.appendChild(eventDiv);
                });
                
                // If there are more than 3 events, show "+X more" indicator
                if (dayEvents.length > 3) {
                    const moreEvents = dayEvents.length - 3;
                    const moreDiv = document.createElement('div');
                    moreDiv.className = 'calendar-event-more';
                    moreDiv.textContent = `+${moreEvents} more`;
                    moreDiv.title = `Click to see all ${dayEvents.length} events`;
                    moreDiv.onclick = (e) => {
                        e.stopPropagation();
                        showDayEvents(dateStr, dayEvents, day, month, year);
                    };
                    eventsContainer.appendChild(moreDiv);
                }
            }
            
            // Click handler for the day
            dayDiv.onclick = (e) => {
                // Don't trigger if clicking on an event or its children
                if (e.target.closest('.calendar-event') || e.target.closest('.calendar-event-more')) {
                    return;
                }
                    
                if (!isOtherMonth) {
                    // If there are events, show them all
                    if (dayEvents.length > 0) {
                        showDayEvents(dateStr, dayEvents, day, month, year);
                    } else {
                        // If no events, open add event modal
                        document.getElementById('eventDate').value = dateStr;
                        document.getElementById('action').value = 'create';
                        document.getElementById('eventId').value = '';
                        document.getElementById('eventForm').reset();
                        document.getElementById('eventDate').value = dateStr;
                        document.getElementById('eventModalLabel').innerHTML = '<i class="fas fa-plus me-2"></i>Add Event';
                        document.getElementById('deleteEventBtn').classList.add('d-none');
                        updateEventColor();
                        const modal = new bootstrap.Modal(document.getElementById('eventModal'));
                        modal.show();
                    }
                }
            };
            
            return dayDiv;
        }
        
        // Store selected day for adding events
        let selectedDay = null;
        
        // Show all events for a day
        function showDayEvents(dateStr, dayEvents, day, month, year) {
            selectedDay = dateStr;
            
            const modal = new bootstrap.Modal(document.getElementById('dayEventsModal'));
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
            if (dayEvents.length === 0) {
                modalBody.innerHTML += `
                    <div class="event-list-empty">
                        <i class="fas fa-calendar-day"></i>
                        <div class="event-list-empty-title">No events on this day</div>
                        <div class="event-list-empty-text">Click "Add Event" to create one</div>
                    </div>
                `;
            } else {
                dayEvents.forEach((event, index) => {
                    const eventItem = document.createElement('div');
                    eventItem.className = 'day-event-item';
                    eventItem.style.setProperty('--event-color', event.color);
                    eventItem.onclick = () => {
                        modal.hide();
                        setTimeout(() => {
                            if (event.isTARF) {
                                openTarfModal(event.tarfId);
                            } else if (event.isHoliday) {
                                openHolidayModal(event.holidayId);
                            } else {
                                openEventModal(event);
                            }
                        }, 300);
                    };
                    
                    const title = document.createElement('div');
                    title.className = 'day-event-title';
                    const isReadOnly = event.isPhilippinesHoliday || event.isTARF || event.isHoliday;
                    title.innerHTML = `
                        <div class="day-event-color" style="background-color: ${event.color};"></div>
                        <span style="flex: 1;">${event.title}</span>
                        ${!isReadOnly ? '<i class="fas fa-chevron-right" style="color: #999; font-size: 0.8rem;"></i>' : ''}
                    `;
                    eventItem.appendChild(title);
                    
                    const details = document.createElement('div');
                    details.className = 'day-event-details';
                    
                    // Category
                    if (event.category) {
                        const category = document.createElement('div');
                        category.className = 'day-event-detail';
                        category.innerHTML = `<i class="fas fa-tag"></i><span><strong>${event.category}</strong></span>`;
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
                    
                    // Description (truncated)
                    if (event.description) {
                        const description = document.createElement('div');
                        description.className = 'day-event-detail';
                        const truncatedDesc = event.description.length > 100 
                            ? event.description.substring(0, 100) + '...' 
                            : event.description;
                        description.innerHTML = `<i class="fas fa-info-circle"></i><span>${truncatedDesc}</span>`;
                        details.appendChild(description);
                    }
                    
                    // Status badge
                    if (event.statusText && event.statusText.trim() !== '') {
                        const status = document.createElement('div');
                        status.className = 'day-event-detail';
                        const statusColor = event.status === 'today' ? '#4caf50' : (event.status === 'past' ? '#999' : '#2196f3');
                        status.innerHTML = `<i class="fas fa-circle" style="font-size: 0.5rem; color: ${statusColor};"></i><span style="font-weight: 600; color: ${statusColor};">${event.statusText}</span>`;
                        details.appendChild(status);
                    } else if (event.status === 'past') {
                        const status = document.createElement('div');
                        status.className = 'day-event-detail';
                        status.innerHTML = `<i class="fas fa-circle" style="font-size: 0.5rem; color: #999;"></i><span style="font-weight: 600; color: #999;">Past Event</span>`;
                        details.appendChild(status);
                    }
                    
                    eventItem.appendChild(details);
                    modalBody.appendChild(eventItem);
                });
            }
            
            modal.show();
        }
        
        // Add event to selected day
        function addEventToSelectedDay() {
            if (selectedDay) {
                const modal = bootstrap.Modal.getInstance(document.getElementById('dayEventsModal'));
                if (modal) modal.hide();
                
                document.getElementById('eventDate').value = selectedDay;
                document.getElementById('action').value = 'create';
                document.getElementById('eventId').value = '';
                document.getElementById('eventForm').reset();
                document.getElementById('eventDate').value = selectedDay;
                document.getElementById('eventModalLabel').innerHTML = '<i class="fas fa-plus me-2"></i>Add Event';
                document.getElementById('deleteEventBtn').classList.add('d-none');
                updateEventColor();
                const eventModal = new bootstrap.Modal(document.getElementById('eventModal'));
                eventModal.show();
                
                // Focus on title input
                setTimeout(() => {
                    document.getElementById('eventTitle').focus();
                }, 300);
            }
        }

        // Filter events
        function filterEvents(filter) {
            currentFilter = filter;
            
            // Update filter buttons
            document.querySelectorAll('.event-list-filter-btn').forEach(btn => {
                btn.classList.remove('active');
                if (btn.dataset.filter === filter) {
                    btn.classList.add('active');
                }
            });
            
            applyFilters();
        }

        // Search events
        function searchEvents() {
            searchQuery = document.getElementById('eventSearchInput').value.toLowerCase();
            applyFilters();
        }

        // Apply filters and search
        function applyFilters() {
            const eventList = document.getElementById('eventList');
            
            // Get current month's events
            let currentMonthEvents = allEvents.filter(e => {
                const eventDate = new Date(e.date);
                return eventDate.getMonth() + 1 === currentMonth && eventDate.getFullYear() === currentYear;
            });
            
            // Apply filter
            if (currentFilter === 'upcoming') {
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                currentMonthEvents = currentMonthEvents.filter(e => {
                    const eventDate = new Date(e.date);
                    eventDate.setHours(0, 0, 0, 0);
                    return eventDate >= today;
                });
            }
            
            // Apply search
            if (searchQuery) {
                currentMonthEvents = currentMonthEvents.filter(e => {
                    return e.title.toLowerCase().includes(searchQuery) ||
                           (e.description && e.description.toLowerCase().includes(searchQuery)) ||
                           (e.location && e.location.toLowerCase().includes(searchQuery)) ||
                           (e.category && e.category.toLowerCase().includes(searchQuery));
                });
            }
            
            filteredEvents = currentMonthEvents;
            
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
            
            // Clear and render
            eventList.innerHTML = '';
            
            if (currentMonthEvents.length === 0) {
                if (searchQuery || currentFilter === 'upcoming') {
                    eventList.innerHTML = `
                        <div class="event-list-empty">
                            <i class="fas fa-search"></i>
                            <div class="event-list-empty-title">No events found</div>
                            <div class="event-list-empty-text">Try adjusting your search or filter</div>
                        </div>
                    `;
                } else {
                    eventList.innerHTML = `
                        <div class="event-list-empty">
                            <i class="fas fa-calendar-times"></i>
                            <div class="event-list-empty-title">No events this month</div>
                            <div class="event-list-empty-text">Click on a date to add an event</div>
                        </div>
                    `;
                }
                return;
            }
            
            currentMonthEvents.forEach(event => {
                const eventCard = createEventCard(event);
                eventList.appendChild(eventCard);
            });
        }

        // Render event list
        function renderEventList() {
            applyFilters();
        }

        // Create event card
        function createEventCard(event) {
            const card = document.createElement('div');
            card.className = 'event-card' + (event.isHalfDayHoliday ? ' half-day-holiday-event' : '');
            card.style.setProperty('--event-color', event.color);
            
            // Determine event status
            const eventDate = new Date(event.date);
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            eventDate.setHours(0, 0, 0, 0);
            
            if (eventDate < today) {
                card.classList.add('past-event');
            } else if (eventDate.getTime() === today.getTime()) {
                card.classList.add('today-event');
            } else {
                card.classList.add('upcoming-event');
            }
            
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
            
            // Actions (only for non-holiday events)
            if (!event.isPhilippinesHoliday) {
                const actions = document.createElement('div');
                actions.className = 'event-card-actions';
                
                const editBtn = document.createElement('button');
                editBtn.className = 'event-action-btn edit';
                editBtn.innerHTML = '<i class="fas fa-edit"></i> Edit';
                if (event.isTARF) {
                    editBtn.onclick = () => openTarfModal(event.tarfId);
                } else if (event.isHoliday) {
                    editBtn.onclick = () => openHolidayModal(event.holidayId);
                } else {
                    editBtn.onclick = () => openEventModal(event);
                }
                actions.appendChild(editBtn);
                
                card.appendChild(actions);
            }
            
            return card;
        }

        // Change month
        function changeMonth(month, year) {
            currentMonth = month;
            currentYear = year;
            window.location.href = `calendar.php?month=${month}&year=${year}`;
        }

        // Change month from selector
        function changeMonthFromSelector() {
            const month = document.getElementById('monthSelector').value;
            const year = document.getElementById('yearSelector').value;
            changeMonth(parseInt(month), parseInt(year));
        }

        // Go to today
        function goToToday() {
            const today = new Date();
            changeMonth(today.getMonth() + 1, today.getFullYear());
        }

        // Update event color preview
        function updateEventColor() {
            const category = document.getElementById('eventCategory').value;
            const colorMap = {
                'Training': '#9c27b0',
                'Workshop': '#2196f3',
                'Seminar': '#4caf50',
                'Conference': '#f44336',
                'Holiday': '#dc3545',
                'University Event': '#007bff'
            };
            const color = colorMap[category] || '#007bff';
            document.getElementById('eventColorPreview').style.backgroundColor = color;
        }

        // Open event modal
        function openEventModal(event = null) {
            const modal = new bootstrap.Modal(document.getElementById('eventModal'));
            const form = document.getElementById('eventForm');
            
            // Reset form validation
            form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
            
            // Check if this is a TARF event - open in TARF modal for editing
            if (event && event.isTARF) {
                modal.hide();
                setTimeout(() => {
                    openTarfModal(event.tarfId);
                }, 300);
                return;
            }
            
            // Check if this is a Holiday event - open in Holiday modal for editing
            if (event && event.isHoliday) {
                modal.hide();
                setTimeout(() => {
                    openHolidayModal(event.holidayId);
                }, 300);
                return;
            }
            
            // Re-enable form fields for regular events
            form.querySelectorAll('input, textarea, select').forEach(el => {
                el.disabled = false;
            });
            document.getElementById('saveEventBtn').classList.remove('d-none');
            
            if (event) {
                document.getElementById('eventModalLabel').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Event';
                document.getElementById('eventId').value = event.id;
                document.getElementById('action').value = 'update';
                document.getElementById('eventTitle').value = event.title;
                document.getElementById('eventDescription').value = event.description || '';
                document.getElementById('eventDate').value = event.date;
                document.getElementById('eventTime').value = event.time || '';
                document.getElementById('eventEndTime').value = event.endTime || '';
                document.getElementById('eventLocation').value = event.location || '';
                document.getElementById('eventCategory').value = event.category || 'University Event';
                document.getElementById('deleteEventBtn').classList.remove('d-none');
                updateEventColor();
                currentEvent = event;
            } else {
                document.getElementById('eventModalLabel').innerHTML = '<i class="fas fa-plus me-2"></i>Add Event';
                document.getElementById('action').value = 'create';
                form.reset();
                document.getElementById('deleteEventBtn').classList.add('d-none');
                updateEventColor();
                currentEvent = null;
            }
            
            modal.show();
            
            // Focus on title input
            setTimeout(() => {
                document.getElementById('eventTitle').focus();
            }, 300);
        }

        // Submit event form
        document.getElementById('eventForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Validate form
            const title = document.getElementById('eventTitle').value.trim();
            const eventDate = document.getElementById('eventDate').value;
            
            if (!title) {
                document.getElementById('eventTitle').classList.add('is-invalid');
                document.getElementById('eventTitle').focus();
                showMessage('error', 'Event title is required');
                return;
            }
            
            if (!eventDate) {
                document.getElementById('eventDate').classList.add('is-invalid');
                document.getElementById('eventDate').focus();
                showMessage('error', 'Event date is required');
                return;
            }
            
            // Validate end time is after start time
            const startTime = document.getElementById('eventTime').value;
            const endTime = document.getElementById('eventEndTime').value;
            if (startTime && endTime && startTime >= endTime) {
                document.getElementById('eventEndTime').classList.add('is-invalid');
                document.getElementById('eventEndTime').focus();
                showMessage('error', 'End time must be after start time');
                return;
            }
            
            // Disable submit button
            const submitBtn = document.getElementById('saveEventBtn');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Saving...';
            
            const formData = new FormData(this);
            const action = formData.get('action');
            formData.append('action', action);
            
            // Determine color based on category
            const category = formData.get('category');
            const colorMap = {
                'Training': '#9c27b0',
                'Workshop': '#2196f3',
                'Seminar': '#4caf50',
                'Conference': '#f44336',
                'Holiday': '#dc3545',
                'University Event': '#007bff'
            };
            formData.append('color', colorMap[category] || '#007bff');
            
            fetch('calendar_api.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
                
                if (data.success) {
                    const modal = bootstrap.Modal.getInstance(document.getElementById('eventModal'));
                    modal.hide();
                    loadEvents();
                    showMessage('success', data.message || 'Event saved successfully');
                } else {
                    showMessage('error', data.message || 'Error saving event');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
                showMessage('error', 'Error saving event. Please try again.');
            });
        });

        // Remove invalid class on input
        document.getElementById('eventForm').addEventListener('input', function(e) {
            if (e.target.classList.contains('is-invalid')) {
                e.target.classList.remove('is-invalid');
            }
        });

        // Delete event
        function deleteEvent() {
            const eventId = document.getElementById('eventId').value;
            if (!eventId) return;
            
            showConfirmationModal(
                'Delete Event',
                'Are you sure you want to delete this event? This action cannot be undone.',
                'fa-trash',
                () => {
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('event_id', eventId);
            
            fetch('calendar_api.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const modal = bootstrap.Modal.getInstance(document.getElementById('eventModal'));
                    modal.hide();
                            loadEvents();
                    showMessage('success', data.message || 'Event deleted successfully');
                } else {
                    showMessage('error', data.message || 'Error deleting event');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('error', 'Error deleting event');
            });
                }
            );
        }

        // Show confirmation modal
        function showConfirmationModal(title, message, icon, onConfirm) {
            const confirmationModal = document.getElementById('confirmationModal');
            const modal = new bootstrap.Modal(confirmationModal);
            document.getElementById('confirmationMessage').innerHTML = `
                <strong>${title}</strong><br>
                <span style="font-size: 0.9rem; font-weight: normal;">${message}</span>
            `;
            document.getElementById('confirmationIcon').className = `fas ${icon}`;
            
            const confirmBtn = document.getElementById('confirmationConfirmBtn');
            confirmBtn.onclick = () => {
                modal.hide();
                onConfirm();
            };
            
            // Ensure confirmation modal appears above event modal
            modal.show();
            
            // Set z-index after modal is shown to ensure it's above event modal
            setTimeout(() => {
                confirmationModal.style.zIndex = '10000000';
                const backdrops = document.querySelectorAll('.modal-backdrop');
                if (backdrops.length > 1) {
                    // Second backdrop (for confirmation modal) should be above event modal
                    backdrops[backdrops.length - 1].style.zIndex = '9999998';
                }
            }, 10);
        }

        // Close confirmation modal
        function closeConfirmationModal() {
            const modal = bootstrap.Modal.getInstance(document.getElementById('confirmationModal'));
            if (modal) modal.hide();
        }

        // Show message
        function showMessage(type, message) {
            // Remove existing toasts
            document.querySelectorAll('.toast-alert').forEach(toast => toast.remove());
            
            const toast = document.createElement('div');
            toast.className = `alert alert-${type === 'success' ? 'success' : 'danger'} alert-dismissible fade show toast-alert position-fixed`;
            toast.style.cssText = 'top: 20px; right: 20px; z-index: 10000; min-width: 320px; max-width: 400px; box-shadow: 0 4px 12px rgba(0,0,0,0.2);';
            toast.innerHTML = `
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}" style="font-size: 1.2rem;"></i>
                    <span>${message}</span>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            `;
            document.body.appendChild(toast);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.classList.remove('show');
                    setTimeout(() => toast.remove(), 300);
                }
            }, 5000);
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + N: New event
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                openEventModal();
            }
            
            // Escape: Close modals
            if (e.key === 'Escape') {
                const modals = document.querySelectorAll('.modal.show');
                modals.forEach(modal => {
                    const bsModal = bootstrap.Modal.getInstance(modal);
                    if (bsModal) bsModal.hide();
                });
            }
            
            // Arrow keys: Navigate months (when not in input)
            if (!['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) {
                if (e.key === 'ArrowLeft' && (e.ctrlKey || e.metaKey)) {
                    e.preventDefault();
                    changeMonth(<?php echo $prevMonth; ?>, <?php echo $prevYear; ?>);
                } else if (e.key === 'ArrowRight' && (e.ctrlKey || e.metaKey)) {
                    e.preventDefault();
                    changeMonth(<?php echo $nextMonth; ?>, <?php echo $nextYear; ?>);
                } else if (e.key === 'Home' && (e.ctrlKey || e.metaKey)) {
                    e.preventDefault();
                    goToToday();
                }
            }
        });

        // TARF Functions
        let allEmployees = [];
        let filteredEmployees = [];
        let currentPage = 1;
        const itemsPerPage = 5;

        // Load employees for TARF
        async function loadEmployees() {
            try {
                const response = await fetch('../admin/get_employees.php');
                const data = await response.json();
                if (data.success) {
                    allEmployees = data.employees;
                    filteredEmployees = data.employees;
                    currentPage = 1;
                    renderEmployeeList();
                } else {
                    console.error('Error loading employees:', data.message);
                }
            } catch (error) {
                console.error('Error loading employees:', error);
            }
        }

        // Render employee list with pagination
        function renderEmployeeList() {
            const container = document.getElementById('employeeListContainer');
            container.innerHTML = '';
            
            if (filteredEmployees.length === 0) {
                container.innerHTML = '<div class="text-muted text-center py-3">No employees found</div>';
                renderPagination();
                return;
            }
            
            // Calculate pagination
            const totalPages = Math.ceil(filteredEmployees.length / itemsPerPage);
            if (currentPage > totalPages && totalPages > 0) {
                currentPage = totalPages;
            }
            
            const startIndex = (currentPage - 1) * itemsPerPage;
            const endIndex = startIndex + itemsPerPage;
            const paginatedEmployees = filteredEmployees.slice(startIndex, endIndex);
            
            // Store checked state before rendering
            const checkedEmployees = new Set();
            document.querySelectorAll('input[name="employee_ids[]"]:checked').forEach(checkbox => {
                checkedEmployees.add(checkbox.value);
            });
            
            paginatedEmployees.forEach(employee => {
                const div = document.createElement('div');
                div.className = 'form-check mb-2 p-2 rounded';
                div.style.cursor = 'pointer';
                div.style.transition = 'background-color 0.2s';
                div.onmouseover = function() { this.style.backgroundColor = '#e9ecef'; };
                div.onmouseout = function() { this.style.backgroundColor = 'transparent'; };
                
                const roleClass = employee.user_type === 'faculty' ? 'bg-primary bg-opacity-10 text-primary border-primary' : 'bg-success bg-opacity-10 text-success border-success';
                const roleIcon = employee.user_type === 'faculty' ? 'fa-chalkboard-teacher' : 'fa-user-tie';
                const isChecked = checkedEmployees.has(employee.employee_id) ? 'checked' : '';
                
                div.innerHTML = `
                    <input class="form-check-input" type="checkbox" name="employee_ids[]" value="${employee.employee_id}" id="emp_${employee.employee_id}" ${isChecked} onchange="updateSelectedEmployeeCount()">
                    <label class="form-check-label w-100 d-flex align-items-center cursor-pointer" for="emp_${employee.employee_id}" style="cursor: pointer;">
                        <div class="avatar-circle-sm ${roleClass} me-2">
                            <i class="fas ${roleIcon}"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="fw-semibold">${employee.name}</div>
                            <div class="small text-muted">${employee.employee_id || 'N/A'} • ${employee.user_type === 'faculty' ? 'Faculty' : 'Staff'}</div>
                        </div>
                    </label>
                `;
                container.appendChild(div);
            });
            
            renderPagination();
            updateSelectedEmployeeCount();
        }
        
        // Render pagination controls
        function renderPagination() {
            const paginationContainer = document.getElementById('employeePagination');
            const totalPages = Math.ceil(filteredEmployees.length / itemsPerPage);
            
            if (totalPages <= 1) {
                paginationContainer.innerHTML = '';
                return;
            }
            
            let paginationHTML = '<div class="d-flex align-items-center gap-2">';
            
            // Previous button
            if (currentPage > 1) {
                paginationHTML += `<button class="btn btn-sm btn-outline-secondary" onclick="goToPage(${currentPage - 1})" title="Previous">
                    <i class="fas fa-chevron-left"></i>
                </button>`;
            } else {
                paginationHTML += `<button class="btn btn-sm btn-outline-secondary" disabled>
                    <i class="fas fa-chevron-left"></i>
                </button>`;
            }
            
            // Page info
            paginationHTML += `<span class="small text-muted">Page ${currentPage} of ${totalPages} (${filteredEmployees.length} total)</span>`;
            
            // Next button
            if (currentPage < totalPages) {
                paginationHTML += `<button class="btn btn-sm btn-outline-secondary" onclick="goToPage(${currentPage + 1})" title="Next">
                    <i class="fas fa-chevron-right"></i>
                </button>`;
            } else {
                paginationHTML += `<button class="btn btn-sm btn-outline-secondary" disabled>
                    <i class="fas fa-chevron-right"></i>
                </button>`;
            }
            
            paginationHTML += '</div>';
            paginationContainer.innerHTML = paginationHTML;
        }
        
        // Go to specific page
        function goToPage(page) {
            const totalPages = Math.ceil(filteredEmployees.length / itemsPerPage);
            if (page >= 1 && page <= totalPages) {
                currentPage = page;
                renderEmployeeList();
            }
        }

        // Filter employees
        function filterEmployees() {
            const searchTerm = document.getElementById('employeeSearch').value.toLowerCase();
            const typeFilter = document.getElementById('employeeTypeFilter').value;
            
            filteredEmployees = allEmployees.filter(emp => {
                // Filter by type
                const matchesType = typeFilter === 'all' || emp.user_type === typeFilter;
                
                // Filter by search term (name or employee ID)
                const matchesSearch = searchTerm === '' || 
                    emp.name.toLowerCase().includes(searchTerm) ||
                    (emp.employee_id && emp.employee_id.toLowerCase().includes(searchTerm));
                
                return matchesType && matchesSearch;
            });
            
            // Reset to first page when filtering
            currentPage = 1;
            renderEmployeeList();
        }

        // Update selected employee count
        function updateSelectedEmployeeCount() {
            const checked = document.querySelectorAll('input[name="employee_ids[]"]:checked').length;
            document.getElementById('selectedEmployeeCount').textContent = checked;
        }

        // Generate consecutive dates preview
        function generateDatesPreview() {
            const startDate = document.getElementById('tarfStartDate').value;
            const days = parseInt(document.getElementById('tarfDays').value) || 0;
            const container = document.getElementById('generatedDates');
            
            if (!startDate || !days || days < 1) {
                container.innerHTML = '<span class="text-muted">Select start date and number of days to preview dates</span>';
                return;
            }
            
            const dates = [];
            const start = new Date(startDate);
            
            for (let i = 0; i < days; i++) {
                const currentDate = new Date(start);
                currentDate.setDate(start.getDate() + i);
                dates.push(currentDate.toISOString().split('T')[0]);
            }
            
            const dateList = dates.map(date => {
                const dateObj = new Date(date);
                return dateObj.toLocaleDateString('en-US', { 
                    weekday: 'short', 
                    year: 'numeric', 
                    month: 'short', 
                    day: 'numeric' 
                });
            }).join(', ');
            
            container.innerHTML = `
                <div class="fw-semibold mb-2">${days} day(s) from ${new Date(startDate).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })}:</div>
                <div class="small">${dateList}</div>
            `;
        }

        // Open TARF modal
        function openTarfModal(tarfId = null) {
            const modal = new bootstrap.Modal(document.getElementById('tarfModal'));
            const form = document.getElementById('tarfForm');
            
            // Reset form
            form.reset();
            document.getElementById('tarfId').value = '';
            document.getElementById('tarfAction').value = 'create';
            document.getElementById('tarfModalLabel').innerHTML = 'Add TARF';
            document.getElementById('deleteTarfBtn').classList.add('d-none');
            document.getElementById('tarfDateRow').classList.remove('d-none');
            document.getElementById('tarfEditDateRow').classList.add('d-none');
            document.getElementById('tarfStartDate').required = true;
            document.getElementById('tarfDays').required = true;
            document.getElementById('tarfEditDate').required = false;
            document.getElementById('generatedDates').innerHTML = '<span class="text-muted">Select start date and number of days to preview dates</span>';
            document.getElementById('selectedEmployeeCount').textContent = '0';
            document.getElementById('tarfFilePreview').classList.add('d-none');
            document.getElementById('tarfFileName').textContent = '';
            var tck = document.getElementById('tarfCalendarKind');
            if (tck) { tck.value = 'travel'; }
            
            // Reset filters and pagination
            document.getElementById('employeeSearch').value = '';
            document.getElementById('employeeTypeFilter').value = 'all';
            currentPage = 1;
            
            // Load employees if not already loaded
            if (allEmployees.length === 0) {
                loadEmployees();
            } else {
                // Reset filters and re-render
                filteredEmployees = allEmployees;
                renderEmployeeList();
            }
            
            if (tarfId) {
                // Edit mode - load TARF data
                loadTarfForEdit(tarfId, modal);
            } else {
                // Create mode - set today's date as default
                const today = new Date().toISOString().split('T')[0];
                document.getElementById('tarfStartDate').value = today;
                modal.show();
            }
        }
        
        // Load TARF data for editing
        async function loadTarfForEdit(tarfId, modal) {
            try {
                const response = await fetch(`calendar_api.php?action=get_tarf&tarf_id=${tarfId}`);
                const data = await response.json();
                
                if (data.success && data.tarf) {
                    const tarf = data.tarf;
                    
                    // Set form values
                    document.getElementById('tarfId').value = tarf.id;
                    document.getElementById('tarfAction').value = 'update';
                    document.getElementById('tarfTitle').value = tarf.title;
                    document.getElementById('tarfDescription').value = tarf.description || '';
                    var tck = document.getElementById('tarfCalendarKind');
                    if (tck) { tck.value = (tarf.calendar_kind === 'ntarf') ? 'ntarf' : 'travel'; }
                    document.getElementById('tarfEditDate').value = tarf.date;
                    document.getElementById('tarfModalLabel').innerHTML = 'Edit TARF';
                    document.getElementById('deleteTarfBtn').classList.remove('d-none');
                    
                    // Show existing file if available
                    if (tarf.file_path) {
                        const fileName = tarf.file_path.split('/').pop();
                        document.getElementById('tarfFileName').textContent = fileName;
                        document.getElementById('tarfFilePreview').classList.remove('d-none');
                    } else {
                        document.getElementById('tarfFilePreview').classList.add('d-none');
                    }
                    
                    // Switch to edit mode (single date)
                    document.getElementById('tarfDateRow').classList.add('d-none');
                    document.getElementById('tarfEditDateRow').classList.remove('d-none');
                    document.getElementById('tarfStartDate').required = false;
                    document.getElementById('tarfDays').required = false;
                    document.getElementById('tarfEditDate').required = true;
                    
                    // Update generated dates display
                    const dateObj = new Date(tarf.date);
                    document.getElementById('generatedDates').innerHTML = `
                        <div class="fw-semibold mb-2">Selected Date:</div>
                        <div class="small">${dateObj.toLocaleDateString('en-US', { 
                            weekday: 'long', 
                            year: 'numeric', 
                            month: 'long', 
                            day: 'numeric' 
                        })}</div>
                    `;
                    
                    // Select employees
                    if (allEmployees.length === 0) {
                        await loadEmployees();
                    }
                    
                    // Wait a bit for employees to load, then check the checkboxes
                    setTimeout(() => {
                        tarf.employee_ids.forEach(empId => {
                            const checkbox = document.getElementById(`emp_${empId}`);
                            if (checkbox) {
                                checkbox.checked = true;
                            }
                        });
                        updateSelectedEmployeeCount();
                    }, 300);
                    
                    modal.show();
                } else {
                    showMessage('error', data.message || 'Error loading TARF');
                }
            } catch (error) {
                console.error('Error loading TARF:', error);
                showMessage('error', 'Error loading TARF. Please try again.');
            }
        }
        
        // Delete TARF
        function deleteTarf() {
            const tarfId = document.getElementById('tarfId').value;
            if (!tarfId) return;
            
            showConfirmationModal(
                'Delete TARF',
                'Are you sure you want to delete this TARF entry? This action cannot be undone.',
                'fa-trash',
                () => {
                    const formData = new FormData();
                    formData.append('action', 'delete_tarf');
                    formData.append('tarf_id', tarfId);
                    
                    fetch('calendar_api.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            const modal = bootstrap.Modal.getInstance(document.getElementById('tarfModal'));
                            if (modal) {
                                modal.hide();
                            }
                            
                            showMessage('success', data.message || 'TARF deleted successfully');
                            
                            // Wait for modal to fully close before reloading events
                            setTimeout(() => {
                                try {
                                    loadEvents();
                                } catch (error) {
                                    console.error('Error reloading events:', error);
                                    showMessage('warning', 'TARF deleted but page refresh may be needed');
                                }
                            }, 300);
                        } else {
                            showMessage('error', data.message || 'Error deleting TARF');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showMessage('error', 'Error deleting TARF');
                    });
                }
            );
        }

        // TARF form event listeners
        document.getElementById('tarfStartDate').addEventListener('change', generateDatesPreview);
        document.getElementById('tarfDays').addEventListener('input', generateDatesPreview);

        // ========== HOLIDAY FUNCTIONS ==========
        
        // Open Holiday Modal
        function openHolidayModal(holidayId = null) {
            const modal = new bootstrap.Modal(document.getElementById('holidayModal'));
            const form = document.getElementById('holidayForm');
            const modalLabel = document.getElementById('holidayModalLabel');
            const deleteBtn = document.getElementById('deleteHolidayBtn');
            const dateRow = document.getElementById('holidayDateRow');
            const editDateRow = document.getElementById('holidayEditDateRow');
            const selectedDatesSection = document.getElementById('holidaySelectedDatesSection');
            
            // Reset form
            form.reset();
            const saveHolidayBtnEl = document.getElementById('saveHolidayBtn');
            if (saveHolidayBtnEl) {
                saveHolidayBtnEl.disabled = false;
                saveHolidayBtnEl.innerHTML = 'Save Holiday';
            }
            document.getElementById('holidayId').value = '';
            document.getElementById('holidayAction').value = 'create';
            deleteBtn.classList.add('d-none');
            dateRow.classList.remove('d-none');
            editDateRow.classList.add('d-none');
            // Remove required attribute from hidden edit date field
            document.getElementById('holidayEditDate').removeAttribute('required');
            if (selectedDatesSection) {
                selectedDatesSection.classList.remove('d-none');
            }
            holidaySelectedDates = new Set();
            holidayDatePickerMonth = new Date();
            holidayDatePickerMonth.setDate(1);
            renderHolidayDatePicker();
            updateHolidayDatesPreview();
            document.getElementById('holidayGeneratedDates').innerHTML = '<span class="text-muted">Choose one or more days on the calendar above</span>';
            const halfDayEl = document.getElementById('holidayHalfDay');
            if (halfDayEl) halfDayEl.checked = false;
            const amRadio = document.getElementById('holidayHalfAm');
            const pmRadio = document.getElementById('holidayHalfPm');
            if (amRadio) amRadio.checked = true;
            if (pmRadio) pmRadio.checked = false;
            const periodWrap = document.getElementById('holidayHalfDayPeriodWrap');
            if (periodWrap) periodWrap.classList.add('d-none');
            
            if (holidayId) {
                // Edit mode
                modalLabel.innerHTML = '<i class="fas fa-edit me-2"></i>Edit Holiday';
                document.getElementById('holidayAction').value = 'update';
                document.getElementById('holidayId').value = holidayId;
                deleteBtn.classList.remove('d-none');
                
                // Load holiday data
                fetch(`calendar_api.php?action=get_holiday&holiday_id=${holidayId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.holiday) {
                            const holiday = data.holiday;
                            document.getElementById('holidayTitle').value = holiday.title;
                            document.getElementById('holidayEditDate').value = holiday.date;
                            const hEl = document.getElementById('holidayHalfDay');
                            if (hEl) hEl.checked = !!Number(holiday.is_half_day || 0);
                            const hp = (holiday.half_day_period || 'morning') === 'afternoon' ? 'afternoon' : 'morning';
                            const amR = document.getElementById('holidayHalfAm');
                            const pmR = document.getElementById('holidayHalfPm');
                            if (amR && pmR) {
                                if (hp === 'afternoon') {
                                    pmR.checked = true;
                                    amR.checked = false;
                                } else {
                                    amR.checked = true;
                                    pmR.checked = false;
                                }
                            }
                            const pw = document.getElementById('holidayHalfDayPeriodWrap');
                            if (pw) pw.classList.toggle('d-none', !hEl.checked);
                            dateRow.classList.add('d-none');
                            editDateRow.classList.remove('d-none');
                            if (selectedDatesSection) {
                                selectedDatesSection.classList.add('d-none');
                            }
                            // Remove required from create date fields and add to edit date field
                            document.getElementById('holidayEditDate').setAttribute('required', 'required');
                            modal.show();
                        } else {
                            showMessage('error', data.message || 'Error loading holiday');
                        }
                    })
                    .catch(error => {
                        console.error('Error loading holiday:', error);
                        showMessage('error', 'Error loading holiday. Please try again.');
                    });
            } else {
                // Create mode (picker already initialized above)
                modalLabel.innerHTML = '<i class="fas fa-calendar-check me-2"></i>Add Holiday';
                modal.show();
            }
        }

        function renderHolidayDatePicker() {
            const grid = document.getElementById('holidayDatePickerGrid');
            const label = document.getElementById('holidayDatePickerMonthLabel');
            const countEl = document.getElementById('holidayDatePickerCount');
            if (!grid || !label) {
                return;
            }
            const y = holidayDatePickerMonth.getFullYear();
            const m = holidayDatePickerMonth.getMonth();
            label.textContent = holidayDatePickerMonth.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
            if (countEl) {
                const n = holidaySelectedDates.size;
                countEl.textContent = n === 1 ? '1 date selected' : `${n} dates selected`;
            }

            const first = new Date(y, m, 1);
            const firstDow = first.getDay();
            const daysInMonth = new Date(y, m + 1, 0).getDate();
            const pad = (n) => String(n).padStart(2, '0');
            const wds = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
            let html = '<div class="holiday-date-picker-weekdays">';
            wds.forEach((w) => {
                html += `<div class="holiday-date-picker-wd">${w}</div>`;
            });
            html += '</div><div class="holiday-date-picker-days">';
            for (let i = 0; i < firstDow; i++) {
                html += '<div class="holiday-date-picker-cell holiday-date-picker-empty"></div>';
            }
            for (let d = 1; d <= daysInMonth; d++) {
                const iso = `${y}-${pad(m + 1)}-${pad(d)}`;
                const sel = holidaySelectedDates.has(iso);
                html += `<button type="button" class="holiday-date-picker-day${sel ? ' selected' : ''}" data-date="${iso}">${d}</button>`;
            }
            html += '</div>';
            grid.innerHTML = html;
            grid.querySelectorAll('.holiday-date-picker-day').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const iso = btn.getAttribute('data-date');
                    if (holidaySelectedDates.has(iso)) {
                        holidaySelectedDates.delete(iso);
                    } else {
                        holidaySelectedDates.add(iso);
                    }
                    renderHolidayDatePicker();
                    updateHolidayDatesPreview();
                });
            });
        }

        function updateHolidayDatesPreview() {
            const container = document.getElementById('holidayGeneratedDates');
            if (!container) {
                return;
            }
            const dates = Array.from(holidaySelectedDates).sort();
            if (dates.length === 0) {
                container.innerHTML = '<span class="text-muted">Choose one or more days on the calendar above</span>';
                return;
            }
            const dateList = dates.map((date) => {
                const dateObj = new Date(date + 'T12:00:00');
                return dateObj.toLocaleDateString('en-US', {
                    weekday: 'short',
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric'
                });
            }).join(', ');
            container.innerHTML = `<div class="fw-bold mb-2">${dates.length} day(s):</div><div class="text-muted small">${dateList}</div>`;
        }
        
        // Delete Holiday
        function deleteHoliday() {
            const holidayId = document.getElementById('holidayId').value;
            if (!holidayId) return;
            
            showConfirmationModal(
                'Delete Holiday',
                'Are you sure you want to delete this holiday? Holiday-only placeholder rows will be removed. Logs that already have time entries will keep those times and only lose the holiday tag. This action cannot be undone.',
                'fa-trash',
                () => {
                    const formData = new FormData();
                    formData.append('action', 'delete_holiday');
                    formData.append('holiday_id', holidayId);
                    
                    fetch('calendar_api.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            const modal = bootstrap.Modal.getInstance(document.getElementById('holidayModal'));
                            if (modal) {
                                modal.hide();
                            }
                            
                            showMessage('success', data.message || 'Holiday deleted successfully');
                            
                            // Wait for modal to fully close before reloading events
                            setTimeout(() => {
                                try {
                                    loadEvents();
                                } catch (error) {
                                    console.error('Error reloading events:', error);
                                    showMessage('warning', 'Holiday deleted but page refresh may be needed');
                                }
                            }, 300);
                        } else {
                            showMessage('error', data.message || 'Error deleting holiday');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showMessage('error', 'Error deleting holiday');
                    });
                }
            );
        }
        
        // Holiday form event listeners
        document.getElementById('holidayDatePickerPrev').addEventListener('click', () => {
            holidayDatePickerMonth.setMonth(holidayDatePickerMonth.getMonth() - 1);
            renderHolidayDatePicker();
        });
        document.getElementById('holidayDatePickerNext').addEventListener('click', () => {
            holidayDatePickerMonth.setMonth(holidayDatePickerMonth.getMonth() + 1);
            renderHolidayDatePicker();
        });
        document.getElementById('holidayDatePickerClear').addEventListener('click', () => {
            holidaySelectedDates = new Set();
            renderHolidayDatePicker();
            updateHolidayDatesPreview();
        });
        const holidayHalfDayToggle = document.getElementById('holidayHalfDay');
        if (holidayHalfDayToggle) {
            holidayHalfDayToggle.addEventListener('change', function() {
                const wrap = document.getElementById('holidayHalfDayPeriodWrap');
                if (wrap) {
                    wrap.classList.toggle('d-none', !this.checked);
                }
            });
        }
        
        // Submit Holiday form
        document.getElementById('holidayForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const action = document.getElementById('holidayAction').value;
            const holidayId = document.getElementById('holidayId').value;
            const title = document.getElementById('holidayTitle').value.trim();
            
            // Remove required attribute from hidden fields to prevent validation errors
            const dateRow = document.getElementById('holidayDateRow');
            const editDateRow = document.getElementById('holidayEditDateRow');
            if (dateRow.classList.contains('d-none')) {
                /* create-mode date picker hidden in edit mode */
            }
            if (editDateRow.classList.contains('d-none')) {
                document.getElementById('holidayEditDate').removeAttribute('required');
            }
            
            // Validation
            if (!title) {
                showMessage('error', 'Holiday title is required');
                document.getElementById('holidayTitle').focus();
                return;
            }
            
            const apiAction = action === 'create' ? 'create_holiday' : 'update_holiday';
            const formData = new FormData();
            formData.append('action', apiAction);
            formData.append('title', title);
            const halfDayEl = document.getElementById('holidayHalfDay');
            if (halfDayEl && halfDayEl.checked) {
                formData.append('is_half_day', '1');
                const pm = document.getElementById('holidayHalfPm');
                formData.append('half_day_period', (pm && pm.checked) ? 'afternoon' : 'morning');
            } else {
                formData.append('is_half_day', '0');
                formData.append('half_day_period', 'morning');
            }
            
            if (action === 'create') {
                const dates = Array.from(holidaySelectedDates).sort();
                if (dates.length === 0) {
                    showMessage('error', 'Select at least one date on the calendar');
                    const g = document.getElementById('holidayDatePickerGrid');
                    if (g) {
                        g.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                    return;
                }
                formData.append('holiday_dates', JSON.stringify(dates));
            } else {
                const editDate = document.getElementById('holidayEditDate').value;
                
                if (!editDate) {
                    showMessage('error', 'Date is required');
                    document.getElementById('holidayEditDate').focus();
                    return;
                }
                
                formData.append('holiday_id', holidayId);
                formData.append('date', editDate);
            }
            
            // Disable submit button
            const saveBtn = document.getElementById('saveHolidayBtn');
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Saving...';
            
            fetch('calendar_api.php', {
                method: 'POST',
                body: formData
            })
            .then(async response => {
                const responseText = await response.text();
                console.log('Response status:', response.status);
                console.log('Response text:', responseText);
                
                if (!response.ok) {
                    // Try to parse as JSON to get error message
                    let errorMessage = 'Network response was not ok';
                    try {
                        const errorData = JSON.parse(responseText);
                        errorMessage = errorData.message || errorMessage;
                    } catch (e) {
                        errorMessage = responseText.substring(0, 200) || errorMessage;
                    }
                    throw new Error(errorMessage);
                }
                
                try {
                    return JSON.parse(responseText);
                } catch (e) {
                    console.error('Failed to parse JSON:', responseText);
                    throw new Error('Invalid JSON response: ' + responseText.substring(0, 100));
                }
            })
            .then(data => {
                if (data.success) {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = 'Save Holiday';
                    const modal = bootstrap.Modal.getInstance(document.getElementById('holidayModal'));
                    if (modal) {
                        modal.hide();
                    }
                    showMessage('success', data.message || 'Holiday saved successfully');
                    try {
                        loadEvents();
                    } catch (error) {
                        console.error('Error reloading events:', error);
                        showMessage('warning', 'Holiday saved but page refresh may be needed');
                    }
                } else {
                    showMessage('error', data.message || 'Error saving holiday');
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = 'Save Holiday';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showMessage('error', 'Error saving holiday: ' + (error.message || 'Please try again.'));
                saveBtn.disabled = false;
                saveBtn.innerHTML = 'Save Holiday';
            });
        });

        // Submit TARF form
        document.getElementById('tarfForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const action = document.getElementById('tarfAction').value;
            const tarfId = document.getElementById('tarfId').value;
            const title = document.getElementById('tarfTitle').value.trim();
            const selectedEmployees = Array.from(document.querySelectorAll('input[name="employee_ids[]"]:checked')).map(cb => cb.value);
            
            // Validation
            if (!title) {
                showMessage('error', 'Title is required');
                document.getElementById('tarfTitle').focus();
                return;
            }
            
            if (selectedEmployees.length === 0) {
                showMessage('error', 'Please select at least one employee');
                return;
            }
            
            // Disable submit button
            const submitBtn = document.getElementById('saveTarfBtn');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Saving...';
            
            const formData = new FormData();
            const description = document.getElementById('tarfDescription').value.trim();
            const tarfFile = document.getElementById('tarfFile').files[0];
            
            if (action === 'update') {
                // Update mode - single date
                const date = document.getElementById('tarfEditDate').value;
                
                if (!date) {
                    showMessage('error', 'Date is required');
                    document.getElementById('tarfEditDate').focus();
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                    return;
                }
                
                formData.append('action', 'update_tarf');
                formData.append('tarf_id', tarfId);
                formData.append('title', title);
                formData.append('description', description);
                formData.append('date', date);
                formData.append('employee_ids', JSON.stringify(selectedEmployees));
                var tck = document.getElementById('tarfCalendarKind');
                formData.append('calendar_kind', tck ? tck.value : 'travel');
                if (tarfFile) {
                    formData.append('tarf_file', tarfFile);
                }
            } else {
                // Create mode - multiple dates
                const startDate = document.getElementById('tarfStartDate').value;
                const days = parseInt(document.getElementById('tarfDays').value);
                
                if (!startDate) {
                    showMessage('error', 'Start date is required');
                    document.getElementById('tarfStartDate').focus();
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                    return;
                }
                
                if (!days || days < 1) {
                    showMessage('error', 'Please enter a valid number of days');
                    document.getElementById('tarfDays').focus();
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                    return;
                }
                
                // Generate consecutive dates
                const dates = [];
                const start = new Date(startDate);
                for (let i = 0; i < days; i++) {
                    const currentDate = new Date(start);
                    currentDate.setDate(start.getDate() + i);
                    dates.push(currentDate.toISOString().split('T')[0]);
                }
                
                formData.append('action', 'create_tarf');
                formData.append('title', title);
                formData.append('description', description);
                formData.append('dates', JSON.stringify(dates));
                formData.append('employee_ids', JSON.stringify(selectedEmployees));
                var tckCr = document.getElementById('tarfCalendarKind');
                formData.append('calendar_kind', tckCr ? tckCr.value : 'travel');
                if (tarfFile) {
                    formData.append('tarf_file', tarfFile);
                }
            }
            
            fetch('calendar_api.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    throw new TypeError('Response is not JSON');
                }
                return response.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('Invalid JSON response:', text);
                        throw new Error('Invalid JSON response from server');
                    }
                });
            })
            .then(data => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
                
                if (data && data.success) {
                    const modal = bootstrap.Modal.getInstance(document.getElementById('tarfModal'));
                    if (modal) {
                        modal.hide();
                        
                        // Wait for modal and backdrop to be fully removed
                        const checkModalClosed = setInterval(() => {
                            const modalElement = document.getElementById('tarfModal');
                            const backdrop = document.querySelector('.modal-backdrop');
                            
                            if ((!modalElement || !modalElement.classList.contains('show')) && !backdrop) {
                                clearInterval(checkModalClosed);
                                
                                showMessage('success', data.message || (action === 'update' ? 'TARF updated successfully' : 'TARF created successfully'));
                                
                                // Reload events after modal is fully closed
                                try {
                                    loadEvents();
                                } catch (error) {
                                    console.error('Error reloading events:', error);
                                    showMessage('warning', 'TARF saved successfully. Refreshing page...');
                                    setTimeout(() => window.location.reload(), 1000);
                                }
                            }
                        }, 50);
                        
                        // Fallback timeout in case modal doesn't close properly
                        setTimeout(() => {
                            clearInterval(checkModalClosed);
                            showMessage('success', data.message || (action === 'update' ? 'TARF updated successfully' : 'TARF created successfully'));
                            try {
                                loadEvents();
                            } catch (error) {
                                console.error('Error reloading events:', error);
                                window.location.reload();
                            }
                        }, 2000);
                    } else {
                        showMessage('success', data.message || (action === 'update' ? 'TARF updated successfully' : 'TARF created successfully'));
                        loadEvents();
                    }
                } else {
                    showMessage('error', (data && data.message) ? data.message : 'Error saving TARF');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
                showMessage('error', 'Error saving TARF: ' + (error.message || 'Please try again.'));
            });
        });

        // Load events on page load
        document.addEventListener('DOMContentLoaded', function() {
            const hwSw = document.getElementById('holidayWeekEightHourSwitch');
            if (hwSw) {
                fetch('calendar_api.php?action=holiday_week_eight_hour_get')
                    .then(r => r.json())
                    .then(d => {
                        if (d && d.success && typeof d.enabled !== 'undefined') {
                            hwSw.checked = !!d.enabled;
                        }
                    })
                    .catch(() => {});
                hwSw.addEventListener('change', function() {
                    const fd = new FormData();
                    fd.append('action', 'holiday_week_eight_hour_save');
                    fd.append('enabled', hwSw.checked ? '1' : '0');
                    fetch('calendar_api.php', { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(d => {
                            if (!d || !d.success) {
                                hwSw.checked = !hwSw.checked;
                                if (typeof showMessage === 'function') {
                                    showMessage('error', (d && d.message) ? d.message : 'Could not save weekly schedule rule');
                                }
                            }
                        })
                        .catch(() => {
                            hwSw.checked = !hwSw.checked;
                            if (typeof showMessage === 'function') {
                                showMessage('error', 'Could not save weekly schedule rule');
                            }
                        });
                });
            }
            loadEvents();
            updateEventColor();
            
            // Initialize tooltips if Bootstrap tooltips are available
            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
                tooltipTriggerList.map(function (tooltipTriggerEl) {
                    return new bootstrap.Tooltip(tooltipTriggerEl);
                });
            }
            
            // Fix aria-hidden accessibility issue for eventModal
            const eventModal = document.getElementById('eventModal');
            if (eventModal) {
                // Ensure aria-hidden is false when modal is shown
                eventModal.addEventListener('shown.bs.modal', function() {
                    eventModal.setAttribute('aria-hidden', 'false');
                });
                
                // Ensure aria-hidden is true when modal is hidden
                eventModal.addEventListener('hidden.bs.modal', function() {
                    eventModal.setAttribute('aria-hidden', 'true');
                });
                
                // Also handle the show event to ensure aria-hidden is set before focus
                eventModal.addEventListener('show.bs.modal', function() {
                    // Remove aria-hidden before Bootstrap shows the modal
                    eventModal.removeAttribute('aria-hidden');
                });
            }
            
            // Track if a modal is currently opening to prevent backdrop removal
            let modalOpening = false;
            
            // Force remove modal backdrops when modals close
            function forceRemoveBackdrops() {
                // Don't clean up if a modal is opening
                if (modalOpening) {
                    return;
                }
                
                const openModals = document.querySelectorAll('.modal.show');
                const backdrops = document.querySelectorAll('.modal-backdrop');
                
                // If no modals are open, force remove all backdrops
                if (openModals.length === 0) {
                    backdrops.forEach(function(backdrop) {
                        // Force remove all classes and styles
                        backdrop.classList.remove('show', 'fade');
                        backdrop.style.display = 'none';
                        backdrop.style.opacity = '0';
                        backdrop.style.visibility = 'hidden';
                        backdrop.style.pointerEvents = 'none';
                        backdrop.style.zIndex = '-1';
                        
                        // Force remove from DOM
                        if (backdrop && backdrop.parentNode) {
                            backdrop.remove();
                        }
                    });
                    
                    // Remove modal-open class and restore body styles
                    document.body.classList.remove('modal-open');
                    document.body.style.overflow = '';
                    document.body.style.paddingRight = '';
                }
            }
            
            // Add event listeners to all modals to force remove backdrops on close
            document.querySelectorAll('.modal').forEach(function(modal) {
                // Track when modal is about to show
                modal.addEventListener('show.bs.modal', function() {
                    modalOpening = true;
                    // Clear the flag after backdrop should be created
                    setTimeout(function() {
                        modalOpening = false;
                    }, 500);
                });
                
                // Track when modal is shown
                modal.addEventListener('shown.bs.modal', function() {
                    modalOpening = false;
                });
                
                // Clean up backdrops when modal starts hiding
                modal.addEventListener('hide.bs.modal', function() {
                    // Check if this is the last modal
                    const openModals = document.querySelectorAll('.modal.show');
                    if (openModals.length <= 1) {
                        // This is the last modal, prepare to remove backdrops
                        const backdrops = document.querySelectorAll('.modal-backdrop');
                        backdrops.forEach(function(backdrop) {
                            backdrop.classList.remove('show');
                        });
                    }
                });
                
                // Force remove backdrops after modal is fully hidden
                modal.addEventListener('hidden.bs.modal', function() {
                    modalOpening = false;
                    // Immediate cleanup
                    setTimeout(function() {
                        forceRemoveBackdrops();
                    }, 50);
                    // Also do a delayed cleanup to catch any stragglers
                    setTimeout(function() {
                        forceRemoveBackdrops();
                    }, 200);
                    // Additional cleanup after animation completes
                    setTimeout(function() {
                        forceRemoveBackdrops();
                    }, 400);
                });
            });
            
            // Clean up backdrops on page load
            setTimeout(forceRemoveBackdrops, 500);
            
            // Periodic cleanup as fallback (in case Bootstrap doesn't fire events properly)
            // Increased interval to prevent interference with modal opening
            setInterval(forceRemoveBackdrops, 3000);
        });
    </script>
</body>
</html>
