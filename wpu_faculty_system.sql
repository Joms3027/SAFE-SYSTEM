-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Dec 04, 2025 at 02:30 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `wpu_faculty_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_log`
--

CREATE TABLE `activity_log` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `activity_type` varchar(50) NOT NULL,
  `description` text NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `related_entity` varchar(50) DEFAULT NULL COMMENT 'submission, pds, requirement, etc.',
  `related_id` int(11) DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `priority` enum('low','normal','high','urgent') DEFAULT 'normal',
  `target_audience` enum('all','faculty','admin') DEFAULT 'all',
  `is_active` tinyint(1) DEFAULT 1,
  `expires_at` datetime DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `announcements`
--

INSERT INTO `announcements` (`id`, `title`, `content`, `priority`, `target_audience`, `is_active`, `expires_at`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'WALANG PASOK', 'SSSSSS', 'normal', 'faculty', 1, '2025-11-21 00:00:00', 1, '2025-11-22 08:50:02', NULL),
(2, 'HELLO', 'HII', 'normal', 'faculty', 1, '2025-11-27 00:00:00', 1, '2025-11-26 09:45:04', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `employee_id` varchar(50) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `attendance_date` date NOT NULL,
  `time_in` datetime DEFAULT NULL,
  `lunch_out` datetime DEFAULT NULL,
  `lunch_in` datetime DEFAULT NULL,
  `time_out` datetime DEFAULT NULL,
  `ot_in` datetime DEFAULT NULL,
  `ot_out` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`id`, `user_id`, `employee_id`, `name`, `attendance_date`, `time_in`, `lunch_out`, `lunch_in`, `time_out`, `ot_in`, `ot_out`, `created_at`, `updated_at`) VALUES
(1, 3, 'WPU-2025-00001', 'Enkie Echague', '2025-11-20', '2025-11-20 07:23:38', '2025-11-20 07:30:45', NULL, '2025-11-20 07:31:54', NULL, NULL, '2025-11-20 14:23:38', '2025-11-20 14:31:54'),
(3, 5, 'WPU-2025-00003', 'Jomari Recalde', '2025-11-20', '2025-11-20 15:06:41', '2025-11-20 15:06:55', '2025-11-20 15:07:12', '2025-11-20 15:07:36', NULL, NULL, '2025-11-20 15:06:41', '2025-11-20 15:07:36'),
(4, 3, 'WPU-2025-00001', 'Enkie Echague', '2025-11-21', '2025-11-21 10:44:47', NULL, NULL, NULL, NULL, NULL, '2025-11-21 10:44:47', '2025-11-21 10:44:47');

-- --------------------------------------------------------

--
-- Table structure for table `attendance_logs`
--

CREATE TABLE `attendance_logs` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(50) NOT NULL,
  `log_date` date NOT NULL,
  `time_in` time DEFAULT NULL,
  `lunch_out` time DEFAULT NULL,
  `lunch_in` time DEFAULT NULL,
  `time_out` time DEFAULT NULL,
  `station_id` int(11) DEFAULT NULL,
  `timekeeper_id` int(11) DEFAULT NULL,
  `ot_in` time DEFAULT NULL,
  `ot_out` time DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `remarks` varchar(500) DEFAULT NULL,
  `tarf_id` int(11) DEFAULT NULL,
  `holiday_id` int(11) DEFAULT NULL,
  `total_ot` time DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance_logs`
--

INSERT INTO `attendance_logs` (`id`, `employee_id`, `log_date`, `time_in`, `lunch_out`, `lunch_in`, `time_out`, `station_id`, `timekeeper_id`, `ot_in`, `ot_out`, `created_at`, `remarks`, `tarf_id`, `holiday_id`, `total_ot`) VALUES
(409, 'WPU-2025-00003', '2025-11-22', '06:59:54', '08:18:46', '10:06:03', '11:32:29', 1, 2, NULL, NULL, '2025-11-21 22:59:54', NULL, NULL, NULL, NULL),
(410, 'WPU-2025-00001', '2025-11-22', '06:58:00', '12:00:00', '13:45:00', '15:45:00', 3, NULL, NULL, NULL, '2025-11-22 05:58:09', NULL, NULL, NULL, NULL),
(411, 'WPU-2025-00005', '2025-11-29', '09:28:00', '13:31:00', '14:00:00', '17:48:00', 1, 3, NULL, NULL, '2025-11-29 01:28:10', NULL, NULL, NULL, NULL),
(413, 'WPU-2025-00005', '2025-12-01', '09:07:45', '10:32:14', '10:32:23', '10:32:40', 1, 4, '10:32:47', '10:33:19', '2025-12-01 01:07:45', NULL, NULL, NULL, NULL),
(414, 'WPU-2025-00001', '2025-12-03', '11:07:27', '11:08:52', NULL, NULL, 1, 5, NULL, NULL, '2025-12-03 03:07:27', NULL, NULL, NULL, NULL),
(415, 'WPU-2025-00003', '2025-12-03', '11:12:45', '11:13:37', '11:13:45', '11:13:49', 1, 5, NULL, NULL, '2025-12-03 03:12:45', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `calendar_events`
--

CREATE TABLE `calendar_events` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `event_date` date NOT NULL,
  `event_time` time DEFAULT NULL,
  `event_type` enum('university_event','holiday','other') DEFAULT 'university_event',
  `is_philippines_holiday` tinyint(1) DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `end_time` time DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `category` varchar(50) DEFAULT 'university_event',
  `color` varchar(7) DEFAULT '#007bff',
  `is_archived` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `calendar_events`
--

INSERT INTO `calendar_events` (`id`, `title`, `description`, `event_date`, `event_time`, `event_type`, `is_philippines_holiday`, `created_by`, `created_at`, `updated_at`, `end_time`, `location`, `category`, `color`, `is_archived`) VALUES
(5, 'DICT EVENT', 'mag punta kayo', '2025-11-18', '08:00:00', 'university_event', 0, 1, '2025-11-17 05:39:52', '2025-11-17 05:39:52', '12:00:00', 'WPU', 'Workshop', '#2196f3', 0),
(6, 'HOLIDAY', 'walang kayo', '2025-11-21', '10:48:00', 'university_event', 0, 1, '2025-11-20 02:48:26', '2025-11-20 02:48:26', '22:48:00', '', 'Training', '#9c27b0', 0),
(7, 'OUP meeting', 'SAFE SYSTEM MEETING', '2025-12-03', '09:00:00', 'university_event', 0, 1, '2025-12-03 00:18:30', '2025-12-03 00:18:30', '12:00:00', '', 'Conference', '#f44336', 0);

-- --------------------------------------------------------

--
-- Table structure for table `campuses`
--

CREATE TABLE `campuses` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `campuses`
--

INSERT INTO `campuses` (`id`, `name`, `created_at`, `updated_at`) VALUES
(1, 'Aborlan', '2025-12-03 11:46:44', '2025-12-03 11:46:44'),
(2, 'Main Campus - Puerto Princesa', '2025-12-03 11:46:44', '2025-12-03 11:46:44'),
(3, 'Cuyo', '2025-12-03 11:46:44', '2025-12-03 11:46:44'),
(4, 'Narra', '2025-12-03 11:46:44', '2025-12-03 11:46:44'),
(5, 'Quezon', '2025-12-03 11:46:44', '2025-12-03 11:46:44'),
(6, 'Rizal', '2025-12-03 11:46:44', '2025-12-03 11:46:44'),
(7, 'Roxas', '2025-12-03 11:46:44', '2025-12-03 11:46:44'),
(8, 'San Vicente', '2025-12-03 11:46:44', '2025-12-03 11:46:44'),
(9, 'Taytay', '2025-12-03 11:46:44', '2025-12-03 11:46:44');

-- --------------------------------------------------------

--
-- Table structure for table `chat_messages`
--

CREATE TABLE `chat_messages` (
  `id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `sender_type` enum('faculty','admin','staff') NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `receiver_type` enum('faculty','admin','staff') NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `chat_messages`
--

INSERT INTO `chat_messages` (`id`, `sender_id`, `sender_type`, `receiver_id`, `receiver_type`, `message`, `is_read`, `created_at`) VALUES
(1, 2, 'faculty', 1, 'admin', 'Sir kailan po ako sasahod?', 1, '2025-11-21 00:00:58'),
(2, 2, 'faculty', 1, 'admin', 'Hi', 1, '2025-11-21 00:01:23'),
(3, 4, 'faculty', 1, 'admin', 'Hello', 1, '2025-11-21 00:01:23'),
(4, 1, 'admin', 4, 'faculty', 'hello', 1, '2025-11-21 04:52:20'),
(5, 1, 'admin', 4, 'faculty', 'hello', 1, '2025-11-21 04:54:15'),
(6, 1, 'admin', 2, 'faculty', 'matagal pa', 0, '2025-11-21 04:56:05'),
(7, 4, 'faculty', 1, 'admin', 'hello', 1, '2025-11-26 02:05:21'),
(8, 13, 'faculty', 1, 'admin', 'hello', 1, '2025-12-03 02:30:36');

-- --------------------------------------------------------

--
-- Table structure for table `deductions`
--

CREATE TABLE `deductions` (
  `id` int(11) NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `type` enum('Add','Deduct','Other') NOT NULL,
  `object_code` varchar(50) NOT NULL,
  `dr_cr` enum('Dr','Cr') NOT NULL,
  `account_title` varchar(255) NOT NULL,
  `order_num` int(11) NOT NULL,
  `remarks` varchar(500) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `deductions`
--

INSERT INTO `deductions` (`id`, `item_name`, `type`, `object_code`, `dr_cr`, `account_title`, `order_num`, `remarks`, `amount`, `is_active`, `created_at`, `updated_at`) VALUES
(397, 'Salary 506', 'Add', '1069403000', 'Dr', 'Construction in Progress', 1, '', 500.00, 0, '2025-11-18 07:56:34', '2025-11-19 08:18:55'),
(398, 'Salary', 'Add', '5010101001', 'Dr', 'Salaries and Wages - Regular', 1, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(399, 'Salary - Casual/Contractual', 'Add', '5010102000', 'Dr', 'Salaries and Wages - Casual/Contractual', 1, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(400, 'Salary LW-Research', 'Add', '50207020', 'Dr', 'LW-Research', 1, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(401, 'Salary WPU Hatchery', 'Add', '5020702000', 'Dr', 'Research, Exploration and Development Expenses', 1, 'Use only in WPU Hatchery', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(402, 'Salary OPS', 'Add', '5021199000', 'Dr', 'Other Professional Services', 1, '', 0.00, 0, '2025-11-18 07:56:34', '2025-11-18 08:00:14'),
(403, 'Salary 020', 'Add', '50212020', 'Dr', 'Janitorial Services', 1, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(404, 'Salary LW-Security', 'Add', '5021203000', 'Dr', 'Labor and Wages-Security', 1, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(405, 'Salary 502', 'Add', '5021304002', 'Dr', 'Repairs and Maintenance - Buildings and Other Structures-School Buildings', 1, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(406, 'Salary LW', 'Add', '5021601000', 'Dr', 'Labor and Wages', 1, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(407, 'Salary CO-SUCwide', 'Add', '5060404002', 'Dr', 'Co-SUCWIDE', 1, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(408, 'Salary Construction', 'Add', '506040409', 'Dr', 'Construction', 1, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(409, 'Additional Premium 506', 'Add', '1069403000', 'Dr', 'Construction in Progress', 2, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(410, 'PERA Casual/Contractual', 'Add', '5010102000', 'Dr', 'Salaries and Wages - Casual/Contractual', 2, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(411, 'PERA', 'Add', '5010201000', 'Dr', 'PERA', 2, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(412, 'Additional Premium WPU Hatchery', 'Add', '5020702000', 'Dr', 'Research, Exploration and Development Expenses', 2, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(413, 'Additional Premium OPS', 'Add', '5021199000', 'Dr', 'Other Professional Services', 2, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(414, 'Additional Premium-Security', 'Add', '5021203000', 'Dr', 'Labor and Wages-Security', 2, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(415, 'Additional Premium 502', 'Add', '5021304002', 'Dr', 'Repairs and Maintenance - Buildings and Other Structures-School Buildings', 2, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(416, 'Additional Premium LW', 'Add', '5021601000', 'Dr', 'Labor and Wages', 2, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(417, 'Additional Premium-LW 20%', 'Add', '5021601000', 'Dr', 'Labor and Wages', 2, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(418, 'Absences & Undertime 506', '', '1069403000', 'Cr', 'Construction in Progress', 3, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(419, 'Absences/Undertime LW-Research', '', '2010101000', 'Cr', 'Accounts Payable', 3, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(420, 'Absences/Undertime', '', '5010101001', 'Cr', 'Salaries and Wages - Regular', 3, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(421, 'PVP', '', '5010101001', 'Cr', 'Salaries and Wages - Regular', 3, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(422, 'Absences - Casual/Contractual', '', '5010102000', 'Cr', 'Salaries and Wages - Casual/Contractual', 3, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(423, 'Overpayment', '', '5010102000', 'Cr', 'Salaries and Wages - Casual/Contractual', 3, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(424, 'Absences & Undertime WPU Hatchery', '', '5020702000', 'Cr', 'Research, Exploration and Development Expenses', 3, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(425, 'Absences & Undertime OPS', '', '5021199000', 'Cr', 'Other Professional Services', 3, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(426, 'Absences & Undertime 020', '', '50212020', 'Cr', 'Janitorial Services', 3, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(427, 'Absences & Undertime 502', '', '5021304002', 'Cr', 'Repairs and Maintenance - Buildings and Other Structures-School Buildings', 3, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(428, 'Absences & Undertime LW', '', '5021601000', 'Cr', 'Labor and Wages', 3, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(429, 'Absences & Undertime- CONSTRUCTION', '', '506040409', 'Cr', 'Construction', 3, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(430, 'Refund of Hazard Pay, Subsistence and Laundry Allowance', '', '1030501000', 'Cr', 'Receivables-Disallowances/Charges', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(431, 'Due from Officers and Employees', '', '1030502000', 'Cr', 'Due from Officers and Employees', 4, '-', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(432, '164 - Overpayment', '', '1030599000', 'Cr', 'Other Receivables', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(433, '164-Cattle/Carabao', '', '1030599000', 'Cr', 'Other Receivables', 4, 'to be used in 164 payroll only', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(434, '164-Cottage Rental', '', '1030599000', 'Cr', 'Other Receivables', 4, 'use only in 164 payroll', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(435, '164-Fish Pond PPC', '', '1030599000', 'Cr', 'Other Receivables', 4, 'use only in 164 payroll', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(436, '164-Land Rental', '', '1030599000', 'Cr', 'Other Receivables', 4, 'use only in 164 payroll', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(437, '164-Lodging', '', '1030599000', 'Cr', 'Other Receivables', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(438, '164-Nursery', '', '1030599000', 'Cr', 'Other Receivables', 4, 'use only in 164 payroll', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(439, '164-Rice Project', '', '1030599000', 'Cr', 'Other Receivables', 4, 'use only in 164 payrolls', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(440, '164-Swine', '', '1030599000', 'Cr', 'Other Receivables', 4, 'use only in 164 payrolls', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(441, '164-Tuition', '', '1030599000', 'Cr', 'Other Receivables', 4, 'use only in 164 payrolls', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(442, 'Other Receivables', '', '1030599000', 'Cr', 'Other Receivables', 4, '-', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(443, 'Withholding Tax', '', '2020101000', 'Cr', 'Due to BIR', 4, 'wrtrwetret', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(444, 'GSIS GFAL2', '', '2020102000', 'Cr', 'Due to GSIS', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(445, 'ECIP', '', '2020102000', 'Cr', 'Due to GSIS', 4, 'Use only in Government Counterpart', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(446, 'GSIS Computer Loan', '', '2020102000', 'Dr', 'Due to GSIS', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(447, 'GSIS Conso Loan', '', '2020102000', 'Cr', 'Due to GSIS', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(448, 'GSIS Conso Loan Arrears', '', '2020102000', 'Cr', 'Due to GSIS', 4, '-', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(449, 'GSIS CPL', '', '2020102000', 'Cr', 'Due to GSIS', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(450, 'GSIS CPL Arrears', '', '2020102000', 'Cr', 'Due to GSIS', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(451, 'GSIS Educational Assistance', '', '2020102000', 'Cr', 'Due to GSIS', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(452, 'GSIS Emergency Loan', '', '2020102000', 'Cr', 'Due to GSIS', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(453, 'GSIS GFAL Arrears', '', '2020102000', 'Cr', 'Due to GSIS', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(454, 'GSIS MPL', '', '2020102000', 'Cr', 'Due to GSIS', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(455, 'GSIS MPL Arrears', '', '2020102000', 'Cr', 'Due to GSIS', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(456, 'GSIS MPL LYT', '', '2020102000', 'Cr', 'Due to GSIS', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(457, 'GSIS MPL LYT Arrears', '', '2020102000', 'Cr', 'Due to GSIS', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(458, 'GSIS Policy Loan', '', '2020102000', 'Cr', 'Due to GSIS', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(459, 'GSIS Policy Loan Arrears', '', '2020102000', 'Cr', 'Due to GSIS', 4, '-', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(460, 'GSIS-SOS', '', '2020102000', 'Cr', 'Due to GSIS', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(461, 'Life & Retirement Premium', '', '2020102000', 'Cr', 'Due to GSIS', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(462, 'Life & Retirement Premium Arrears', '', '2020102000', 'Cr', 'Due to GSIS', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(463, 'Premium Arrears', '', '2020102000', 'Cr', 'Due to GSIS', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(464, 'Pag-IBIG Calamity Loan', '', '2020103000', 'Cr', 'Due to Pag-IBIG', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(465, 'Pag-IBIG Contribution', '', '2020103000', 'Cr', 'Due to Pag-IBIG', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(466, 'Pag-IBIG Contribution Arrears', '', '2020103000', 'Cr', 'Due to Pag-IBIG', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(467, 'Pag-IBIG Housing Loan', '', '2020103000', 'Cr', 'Due to Pag-IBIG', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(468, 'Pag-IBIG MP2', '', '2020103000', 'Cr', 'Due to Pag-IBIG', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(469, 'Pag-IBIG Multipurpose Loan', '', '2020103000', 'Cr', 'Due to Pag-IBIG', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(470, 'Philhealth', '', '2020104000', 'Cr', 'Due to PhilHealth', 4, 'egdkfjghdny', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(471, 'PhilHealth Contribution Arrears', '', '2020104000', 'Cr', 'Due to PhilHealth', 4, 'egdkfjghdny', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(472, 'LBP-Salary Loan', '', '2020106000', 'Cr', 'Due to GOCCs', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(473, 'SSS Contribution', '', '2020106000', 'Cr', 'Due to GOCCs', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(474, 'SSS Contribution 2025', '', '2020106000', 'Cr', 'Due to GOCCs', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(475, 'AEOP', '', '2030105000', 'Cr', 'Due to Other Funds', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(476, 'Income-Cattle/Carabao', '', '2030105000', 'Cr', 'Due to Other Funds', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(477, 'Income-Cottage Rental', '', '2030105000', 'Cr', 'Due to Other Funds', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(478, 'Income-Cottage Rental Arrears', '', '2030105000', 'Cr', 'Due to Other Funds', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(479, 'Income-Fish Pond PPC', '', '2030105000', 'Cr', 'Due to Other Funds', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(480, 'Income-Land Rental', '', '2030105000', 'Cr', 'Due to Other Funds', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(481, 'Income-Lodging', '', '2030105000', 'Cr', 'Due to Other Funds', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(482, 'Income-Nursery', '', '2030105000', 'Cr', 'Due to Other Funds', 4, '-', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(483, 'Income-Rice Project', '', '2030105000', 'Cr', 'Due to Other Funds', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(484, 'Income-Swine', '', '2030105000', 'Cr', 'Due to Other Funds', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(485, 'Income-Tuition', '', '2030105000', 'Cr', 'Due to Other Funds', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(486, 'SAFE LOAN', '', '2030105000', 'Cr', 'Due to Other Funds', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(487, 'Credit Union', '', '2999999000', 'Cr', 'Other Payables', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(488, 'PRIMCO', '', '2999999000', 'Cr', 'Other Payables', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(489, 'Tulong Kapwa', '', '2999999000', 'Cr', 'Other Payables', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(490, 'Tulong Kapwa One Day Salary', '', '2999999000', 'Cr', 'Other Payables', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(491, 'WPU-MCFA', '', '2999999000', 'Cr', 'Other Payables', 4, 'starts october 2024', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(492, 'WPU-NAPO', '', '2999999000', 'Cr', 'Other Payables', 4, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(493, 'Due to 1st Quincena', '', '2010102000', 'Cr', 'Due to Officers and Employees', 5, 'for accounting entry only', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(494, 'Due to 2nd Quincena', '', '2010102000', 'Cr', 'Due to Officers and Employees', 5, 'for accounting entry only', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34'),
(495, 'Salary Const. 040', 'Add', '1069403000', 'Dr', 'Construction in Progress', 88, '', 0.00, 1, '2025-11-18 07:56:34', '2025-11-18 07:56:34');

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `name`, `created_at`) VALUES
(2, 'CAS', '2025-11-05 12:08:08'),
(3, 'CFINS', '2025-11-05 12:27:35'),
(4, 'CCJE', '2025-11-05 12:27:44'),
(5, 'CPAM', '2025-11-05 12:27:48'),
(6, 'CED', '2025-11-05 12:28:01'),
(7, 'CET', '2025-11-05 12:28:05'),
(8, 'CCAS', '2025-11-05 12:28:11');

-- --------------------------------------------------------

--
-- Table structure for table `designations`
--

CREATE TABLE `designations` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `designations`
--

INSERT INTO `designations` (`id`, `name`, `created_at`) VALUES
(1, 'Dean', '2025-12-03 11:58:53'),
(2, 'Program Chair', '2025-12-03 11:58:53'),
(3, 'Department Head', '2025-12-03 11:58:53'),
(4, 'Coordinator', '2025-12-03 11:58:53'),
(5, 'Faculty Member', '2025-12-03 11:58:53');

-- --------------------------------------------------------

--
-- Table structure for table `email_templates`
--

CREATE TABLE `email_templates` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `variables` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Available template variables' CHECK (json_valid(`variables`)),
  `type` varchar(50) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `email_templates`
--

INSERT INTO `email_templates` (`id`, `name`, `subject`, `body`, `variables`, `type`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'submission_approved', 'Submission Approved - {{requirement_title}}', '<p>Dear {{faculty_name}},</p><p>Your submission for <strong>{{requirement_title}}</strong> has been approved.</p><p>Thank you for your timely submission.</p><p>Best regards,<br>WPU Administration</p>', '[\"faculty_name\", \"requirement_title\", \"submission_date\"]', 'submission', 1, '2025-11-01 20:22:23', '2025-11-12 08:38:57'),
(2, 'submission_rejected', 'Submission Needs Revision - {{requirement_title}}', '<p>Dear {{faculty_name}},</p><p>Your submission for <strong>{{requirement_title}}</strong> needs revision.</p><p><strong>Feedback:</strong><br>{{feedback}}</p><p>Please resubmit after making the necessary corrections.</p><p>Best regards,<br>WPU Administration</p>', '[\"faculty_name\", \"requirement_title\", \"feedback\", \"submission_date\"]', 'submission', 1, '2025-11-01 20:22:23', '2025-11-12 08:38:57'),
(3, 'deadline_reminder', 'Deadline Reminder - {{requirement_title}}', '<p>Dear {{faculty_name}},</p><p>This is a reminder that the deadline for <strong>{{requirement_title}}</strong> is approaching.</p><p><strong>Deadline:</strong> {{deadline}}<br><strong>Days Remaining:</strong> {{days_left}}</p><p>Please ensure you submit before the deadline.</p><p>Best regards,<br>WPU Administration</p>', '[\"faculty_name\", \"requirement_title\", \"deadline\", \"days_left\"]', 'reminder', 1, '2025-11-01 20:22:23', '2025-11-12 08:38:57'),
(4, 'pds_approved', 'Personal Data Sheet Approved', '<p>Dear {{faculty_name}},</p><p>Your Personal Data Sheet (PDS) has been reviewed and approved.</p><p>Thank you for completing your profile.</p><p>Best regards,<br>WPU Administration</p>', '[\"faculty_name\", \"approval_date\"]', 'pds', 1, '2025-11-01 20:22:23', '2025-11-12 08:38:57'),
(5, 'new_requirement', 'New Requirement - {{requirement_title}}', '<p>Dear {{faculty_name}},</p><p>A new requirement has been added: <strong>{{requirement_title}}</strong></p><p>{{description}}</p><p><strong>Deadline:</strong> {{deadline}}</p><p>Please log in to the system to submit your documents.</p><p>Best regards,<br>WPU Administration</p>', '[\"faculty_name\", \"requirement_title\", \"description\", \"deadline\"]', 'requirement', 1, '2025-11-01 20:22:23', '2025-11-12 08:38:57');

-- --------------------------------------------------------

--
-- Table structure for table `employee_deductions`
--

CREATE TABLE `employee_deductions` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(50) NOT NULL,
  `deduction_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `remarks` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `employee_deductions`
--

INSERT INTO `employee_deductions` (`id`, `employee_id`, `deduction_id`, `amount`, `start_date`, `end_date`, `remarks`, `is_active`, `created_at`, `updated_at`) VALUES
(5, '000-012', 409, 500.00, '2025-11-19', '2025-11-20', '', 1, '2025-11-19 00:12:32', '2025-11-19 07:57:25'),
(6, '000-012', 467, 200.00, '2025-11-19', '2025-12-06', '', 1, '2025-11-19 00:13:46', '2025-11-19 07:59:07'),
(7, '000-004', 401, 2000.00, '2025-11-19', '2025-12-06', '', 1, '2025-11-19 01:43:38', '2025-11-19 01:43:38'),
(8, '000-006', 399, 3000.00, '2025-11-19', '2025-12-06', '', 1, '2025-11-19 01:44:08', '2025-11-19 01:44:08'),
(9, '000-012', 408, 3000.00, '2025-11-19', '2025-12-06', '', 1, '2025-11-19 01:57:03', '2025-11-19 01:57:03'),
(11, '000-012', 398, 30.00, '2025-11-19', NULL, '', 1, '2025-11-19 07:53:57', '2025-11-19 07:58:50'),
(12, '000-012', 415, 20.00, '2025-11-19', NULL, '', 1, '2025-11-19 07:56:11', '2025-11-19 07:56:11'),
(13, 'WPU-2025-00001', 403, 77.00, '2025-09-01', NULL, '', 1, '2025-11-20 03:36:53', '2025-11-20 08:04:32');

-- --------------------------------------------------------

--
-- Table structure for table `employee_official_times`
--

CREATE TABLE `employee_official_times` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(50) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `weekday` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
  `time_in` time DEFAULT '08:00:00',
  `lunch_out` time DEFAULT '12:00:00',
  `lunch_in` time DEFAULT '13:00:00',
  `time_out` time DEFAULT '17:00:00',
  `effective_from` date NOT NULL DEFAULT '2025-01-01',
  `effective_to` date NOT NULL DEFAULT '2099-12-31'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `employee_official_times`
--

INSERT INTO `employee_official_times` (`id`, `employee_id`, `start_date`, `end_date`, `weekday`, `time_in`, `lunch_out`, `lunch_in`, `time_out`, `effective_from`, `effective_to`) VALUES
(2, 'WPU-2025-00001', '2025-09-01', '2025-11-25', 'Tuesday', '05:48:00', '12:48:00', '05:49:00', '17:48:00', '2025-01-01', '2099-12-31'),
(3, 'WPU-2025-00001', '2025-10-01', '2025-11-30', 'Wednesday', '07:08:00', '12:08:00', '17:08:00', '13:08:00', '2025-01-01', '2099-12-31'),
(4, 'WPU-2025-00001', '2025-09-01', '2025-11-25', 'Thursday', '07:14:00', '12:14:00', '17:14:00', '13:14:00', '2025-01-01', '2099-12-31'),
(9, 'WPU-2025-00001', '2025-11-21', '2026-02-21', 'Friday', '07:00:00', '13:00:00', '18:00:00', '12:00:00', '2025-01-01', '2099-12-31'),
(10, 'WPU-2025-00003', '2025-11-21', '2026-03-21', 'Monday', '07:00:00', '12:00:00', '13:00:00', '17:00:00', '2025-01-01', '2099-12-31'),
(11, 'WPU-2025-00003', '2025-11-21', '2026-03-21', 'Tuesday', '07:00:00', '12:00:00', '13:00:00', '17:00:00', '2025-01-01', '2099-12-31'),
(12, 'WPU-2025-00003', '2025-11-21', '2026-03-21', 'Thursday', '07:00:00', '12:00:00', '13:00:00', '17:00:00', '2025-01-01', '2099-12-31'),
(16, 'WPU-2025-00003', '2025-11-21', '2026-03-21', 'Wednesday', '07:00:00', '12:00:00', '13:00:00', '17:00:00', '2025-01-01', '2099-12-31'),
(17, 'WPU-2025-00003', '2025-11-21', '2026-03-21', 'Saturday', '07:00:00', '12:00:00', '13:00:00', '17:00:00', '2025-01-01', '2099-12-31'),
(18, 'WPU-2025-00003', '2025-11-21', '2026-03-21', 'Friday', '07:00:00', '12:00:00', '13:00:00', '17:00:00', '2025-01-01', '2099-12-31'),
(20, 'WPU-2025-00001', '2025-10-21', NULL, 'Friday', '07:30:00', '12:30:00', '12:57:00', '17:30:00', '2025-01-01', '2099-12-31'),
(21, 'WPU-2025-00001', '2025-11-04', NULL, 'Monday', '07:07:00', '12:12:00', '12:43:00', '17:00:00', '2025-01-01', '2099-12-31'),
(22, 'WPU-2025-00001', '2025-11-17', '2025-11-24', 'Monday', '07:07:00', '12:12:00', '12:43:00', '17:00:00', '2025-01-01', '2099-12-31'),
(23, 'WPU-2025-00001', '2025-11-04', NULL, 'Saturday', '07:07:00', NULL, '13:01:00', '17:00:00', '2025-01-01', '2099-12-31'),
(24, 'WPU-2025-00005', '2025-11-29', '2026-09-28', 'Tuesday', '07:00:00', '12:00:00', '13:00:00', '17:00:00', '2025-01-01', '2099-12-31'),
(25, 'WPU-2025-00005', '2025-11-29', '2026-09-28', 'Wednesday', '07:00:00', '12:00:00', '13:00:00', '17:00:00', '2025-01-01', '2099-12-31'),
(26, 'WPU-2025-00005', '2025-11-29', '2026-09-28', 'Thursday', '07:00:00', '12:00:00', '13:00:00', '17:00:00', '2025-01-01', '2099-12-31'),
(27, 'WPU-2025-00005', '2025-11-29', '2026-09-28', 'Monday', '07:00:00', '12:00:00', '13:00:00', '17:00:00', '2025-01-01', '2099-12-31'),
(28, 'WPU-2025-00005', '2025-11-29', '2026-09-28', 'Saturday', '07:00:00', '12:00:00', '13:00:00', '17:00:00', '2025-01-01', '2099-12-31'),
(29, 'WPU-2025-00005', '2025-11-29', '2026-09-28', 'Friday', '07:00:00', '12:00:00', '13:00:00', '17:00:00', '2025-01-01', '2099-12-31');

-- --------------------------------------------------------

--
-- Table structure for table `employment_statuses`
--

CREATE TABLE `employment_statuses` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `employment_statuses`
--

INSERT INTO `employment_statuses` (`id`, `name`, `created_at`) VALUES
(2, 'CONTRACT', '2025-11-12 06:37:46'),
(3, 'PERMANENT', '2025-11-12 06:37:55'),
(4, 'TEMPORARY', '2025-11-12 06:38:03'),
(5, 'JOB ORDER', '2025-11-12 06:38:10'),
(6, 'CASUAL', '2025-12-03 01:19:32');

-- --------------------------------------------------------

--
-- Table structure for table `events`
--

CREATE TABLE `events` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `date` date NOT NULL,
  `type` enum('event','holiday') NOT NULL DEFAULT 'event'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `events`
--

INSERT INTO `events` (`id`, `title`, `date`, `type`) VALUES
(1, 'testing', '2025-11-07', 'event'),
(3, 'try', '2025-11-15', 'holiday'),
(4, 'christmas', '2025-12-25', 'holiday'),
(5, 'example', '2025-12-08', 'holiday'),
(6, 'Screenshot (1).png', '2025-11-11', 'event');

-- --------------------------------------------------------

--
-- Table structure for table `faculty_civil_service_eligibility`
--

CREATE TABLE `faculty_civil_service_eligibility` (
  `id` int(11) NOT NULL,
  `pds_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `rating` varchar(50) DEFAULT NULL,
  `date_of_exam` varchar(100) DEFAULT NULL,
  `place_of_exam` varchar(255) DEFAULT NULL,
  `license_number` varchar(100) DEFAULT NULL,
  `date_of_validity` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `faculty_civil_service_eligibility`
--

INSERT INTO `faculty_civil_service_eligibility` (`id`, `pds_id`, `title`, `rating`, `date_of_exam`, `place_of_exam`, `license_number`, `date_of_validity`, `created_at`, `updated_at`) VALUES
(1, 1, 'CSC', '10.5', '2025-11-09', 'Palawan', NULL, NULL, '2025-11-26 01:52:00', '2025-11-26 01:52:00'),
(3, 2, 'CSC', '10.5', '2025-10-28', 'Palawan', NULL, NULL, '2025-11-29 04:33:29', '2025-11-29 04:33:29'),
(4, 4, 'CSC', '10.5', '2025-12-02', 'Palawan', '65616116', NULL, '2025-12-03 11:05:01', '2025-12-03 11:05:01');

-- --------------------------------------------------------

--
-- Table structure for table `faculty_pds`
--

CREATE TABLE `faculty_pds` (
  `id` int(11) NOT NULL,
  `faculty_id` int(11) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `name_extension` varchar(10) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `place_of_birth` varchar(255) DEFAULT NULL,
  `sex` enum('Male','Female') DEFAULT NULL,
  `civil_status` enum('Single','Married','Widowed','Separated','Annulled','Unknown') DEFAULT NULL,
  `height` decimal(5,2) DEFAULT NULL,
  `weight` decimal(5,2) DEFAULT NULL,
  `blood_type` varchar(10) DEFAULT NULL,
  `citizenship` varchar(100) DEFAULT 'Filipino',
  `gsis_id` varchar(50) DEFAULT NULL,
  `pagibig_id` varchar(50) DEFAULT NULL,
  `philhealth_id` varchar(50) DEFAULT NULL,
  `sss_id` varchar(50) DEFAULT NULL,
  `tin` varchar(50) DEFAULT NULL,
  `agency_employee_no` varchar(50) DEFAULT NULL,
  `residential_address` text DEFAULT NULL,
  `residential_zipcode` varchar(10) DEFAULT NULL,
  `residential_telno` varchar(20) DEFAULT NULL,
  `permanent_address` text DEFAULT NULL,
  `permanent_zipcode` varchar(10) DEFAULT NULL,
  `permanent_telno` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `mobile_no` varchar(20) DEFAULT NULL,
  `email_alt` varchar(255) DEFAULT NULL,
  `mobile_no_alt` varchar(20) DEFAULT NULL,
  `spouse_last_name` varchar(100) DEFAULT NULL,
  `spouse_first_name` varchar(100) DEFAULT NULL,
  `spouse_middle_name` varchar(100) DEFAULT NULL,
  `spouse_occupation` varchar(100) DEFAULT NULL,
  `spouse_employer` varchar(255) DEFAULT NULL,
  `spouse_business_address` text DEFAULT NULL,
  `spouse_telno` varchar(20) DEFAULT NULL,
  `father_last_name` varchar(100) DEFAULT NULL,
  `father_first_name` varchar(100) DEFAULT NULL,
  `father_middle_name` varchar(100) DEFAULT NULL,
  `father_name_extension` varchar(10) DEFAULT NULL,
  `mother_last_name` varchar(100) DEFAULT NULL,
  `mother_first_name` varchar(100) DEFAULT NULL,
  `mother_middle_name` varchar(100) DEFAULT NULL,
  `children_info` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`children_info`)),
  `educational_background` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`educational_background`)),
  `civil_service_eligibility` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`civil_service_eligibility`)),
  `work_experience` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`work_experience`)),
  `voluntary_work` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`voluntary_work`)),
  `learning_development` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`learning_development`)),
  `other_info` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`other_info`)),
  `status` enum('draft','submitted','approved','rejected') DEFAULT 'draft',
  `admin_notes` text DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `additional_questions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`additional_questions`)),
  `position` varchar(100) DEFAULT NULL,
  `date_accomplished` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `faculty_pds`
--

INSERT INTO `faculty_pds` (`id`, `faculty_id`, `last_name`, `first_name`, `middle_name`, `name_extension`, `date_of_birth`, `place_of_birth`, `sex`, `civil_status`, `height`, `weight`, `blood_type`, `citizenship`, `gsis_id`, `pagibig_id`, `philhealth_id`, `sss_id`, `tin`, `agency_employee_no`, `residential_address`, `residential_zipcode`, `residential_telno`, `permanent_address`, `permanent_zipcode`, `permanent_telno`, `email`, `mobile_no`, `email_alt`, `mobile_no_alt`, `spouse_last_name`, `spouse_first_name`, `spouse_middle_name`, `spouse_occupation`, `spouse_employer`, `spouse_business_address`, `spouse_telno`, `father_last_name`, `father_first_name`, `father_middle_name`, `father_name_extension`, `mother_last_name`, `mother_first_name`, `mother_middle_name`, `children_info`, `educational_background`, `civil_service_eligibility`, `work_experience`, `voluntary_work`, `learning_development`, `other_info`, `status`, `admin_notes`, `submitted_at`, `reviewed_at`, `reviewed_by`, `created_at`, `updated_at`, `additional_questions`, `position`, `date_accomplished`) VALUES
(1, 5, 'Recalde', 'Jomari', 'Barraquio', NULL, '2025-11-12', 'Sta.Rosa', 'Male', 'Single', 1.57, 65.00, 'O-', 'Filipino', '1323123123', '31234234', '5146131', '6546546546', '353216843135', NULL, 'IHAI SUBDIVISION LOMBOY ST BRGY SAN JOSE', '5300', '65161616', 'IHAI SUBDIVISION LOMBOY ST BRGY SAN JOSE', '5300', '65161661', 'jomari.recalde@wpu.edu.ph', '09686132511', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '[{\"name\":\"\",\"dob\":\"\"}]', '[{\"level\":\"\",\"school\":\"\",\"degree\":\"\",\"from_date\":\"\",\"to_date\":\"\",\"year_graduated\":\"\",\"units_earned\":\"\",\"academic_honors\":\"\"}]', '[{\"title\":\"\",\"rating\":\"\",\"date_of_exam\":\"\",\"place_of_exam\":\"\",\"license_number\":\"\"}]', '[{\"dates\":\"\",\"position\":\"\",\"company\":\"\",\"salary\":\"\",\"salary_grade\":\"\",\"employment_status\":\"\",\"appointment_status\":\"\",\"gov_service\":\"\"}]', '[{\"org\":\"\",\"dates\":\"\"}]', '[{\"title\":\"\",\"dates\":\"\",\"hours\":\"\",\"type\":\"\",\"conducted_by\":\"\",\"venue\":\"\",\"has_certificate\":\"\",\"certificate_details\":\"\"}]', '{\"skills\":\"\",\"distinctions\":\"\",\"memberships\":\"\",\"references\":[{\"name\":\"\",\"address\":\"\",\"phone\":\"\"}],\"dual_citizenship_country\":\"Philippines\",\"umid_id\":\"2123123123\",\"philsys_number\":\"23423423\",\"residential_house_no\":\"Block 17\",\"residential_street\":\"Lomboy\",\"residential_subdivision\":\"Ihai\",\"residential_barangay\":\"San Jose\",\"residential_city\":\"PUERTO PRINCESA CITY\",\"residential_province\":\"Palawan\",\"permanent_house_no\":\"Block 17\",\"permanent_street\":\"Lomboy\",\"permanent_subdivision\":\"Ihai\",\"permanent_barangay\":\"San Jose\",\"permanent_city\":\"PUERTO PRINCESA CITY\",\"permanent_province\":\"Palawan\",\"spouse_name_extension\":\"\",\"sworn_date\":\"\",\"government_id_number\":\"\",\"government_id_issue_date\":\"\",\"government_id_issue_place\":\"\"}', 'submitted', NULL, '2025-11-19 23:40:11', NULL, NULL, '2025-11-19 21:45:38', '2025-11-19 23:40:11', '{\"related_authority_third\":\"\",\"related_authority_fourth\":\"\",\"related_authority_details\":\"\",\"found_guilty_admin\":\"\",\"criminally_charged\":\"\",\"criminal_charge_date\":\"\",\"criminal_charge_status\":\"\",\"convicted_crime\":\"\",\"separated_service\":\"\",\"candidate_election\":\"\",\"resigned_for_election\":\"\",\"immigrant_status\":\"\",\"indigenous_group\":\"\",\"person_with_disability\":\"\",\"solo_parent\":\"\"}', 'INFORMATION SYSTEMS ANALYST I', '2025-11-20 00:00:00'),
(3, 14, 'Saik', 'Marvin', 'Quillo', NULL, '2025-12-02', 'balabac', 'Male', 'Single', 1.55, 58.00, 'O-', 'Filipino', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'PUERTO PRINCESA, Philippines', '5300', NULL, 'marvin.saik@wpu.edu.ph', '09686132511', NULL, NULL, 'Recalde', 'Jomari', NULL, NULL, NULL, 'IHAI SUBDIVISION LOMBOY ST BRGY SAN JOSE', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '[{\"name\":\"\",\"dob\":\"\"}]', '[{\"level\":\"\",\"school\":\"\",\"degree\":\"\",\"from_date\":\"\",\"to_date\":\"\",\"year_graduated\":\"\",\"units_earned\":\"\",\"academic_honors\":\"\"}]', '[{\"title\":\"\",\"rating\":\"\",\"date_of_exam\":\"\",\"place_of_exam\":\"\",\"license_number\":\"\"}]', '[{\"dates\":\"\",\"position\":\"\",\"company\":\"\",\"salary\":\"\",\"salary_grade\":\"\",\"employment_status\":\"\",\"appointment_status\":\"\",\"gov_service\":\"\"}]', '[{\"org\":\"\",\"dates\":\"\"}]', '[{\"title\":\"\",\"dates\":\"\",\"hours\":\"\",\"type\":\"\",\"conducted_by\":\"\",\"venue\":\"\",\"has_certificate\":\"\",\"certificate_details\":\"\"}]', '{\"skills\":\"\",\"distinctions\":\"\",\"memberships\":\"\",\"references\":[{\"name\":\"\",\"address\":\"\",\"phone\":\"\"}],\"dual_citizenship_country\":\"\",\"umid_id\":\"\",\"philsys_number\":\"\",\"residential_house_no\":\"\",\"residential_street\":\"\",\"residential_subdivision\":\"\",\"residential_barangay\":\"\",\"residential_city\":\"\",\"residential_province\":\"\",\"permanent_house_no\":\"\",\"permanent_street\":\"\",\"permanent_subdivision\":\"\",\"permanent_barangay\":\"\",\"permanent_city\":\"PUERTO PRINCESA\",\"permanent_province\":\"Philippines\",\"spouse_name_extension\":\"Jomari Recalde\",\"sworn_date\":\"\",\"government_id_number\":\"\",\"government_id_issue_date\":\"\",\"government_id_issue_place\":\"\"}', 'submitted', NULL, '2025-12-03 02:37:42', NULL, NULL, '2025-12-03 02:29:45', '2025-12-03 02:37:42', '{\"related_authority_third\":\"\",\"related_authority_fourth\":\"\",\"related_authority_details\":\"\",\"found_guilty_admin\":\"\",\"criminally_charged\":\"\",\"criminal_charge_date\":\"\",\"criminal_charge_status\":\"\",\"convicted_crime\":\"\",\"separated_service\":\"\",\"candidate_election\":\"\",\"resigned_for_election\":\"\",\"immigrant_status\":\"\",\"indigenous_group\":\"\",\"person_with_disability\":\"\",\"solo_parent\":\"\"}', 'Instructor 1', '2025-12-03 00:00:00'),
(5, 16, 'Recalde', 'Jomari', 'Barraquio', NULL, '2001-10-27', 'Sta.Rosa', 'Male', 'Single', 1.57, 65.00, 'O+', 'Filipino', '1323123123', '5645464654', '5146131', NULL, '353216843135', NULL, 'Lomboy, Ihai, San Jose, PUERTO PRINCESA CITY, Palawan', '5300', NULL, 'Lomboy, Ihai, San Jose, PUERTO PRINCESA CITY, Palawan', '5300', NULL, 'jomari.recalde@wpu.edu.ph', '09686132511', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'Recalde', 'Joselito', 'Pastorpide', NULL, 'Barraquio', 'Mariflor', 'Dichoso', '[{\"name\":\"\",\"dob\":\"\"}]', '[{\"level\":\"\",\"school\":\"\",\"degree\":\"\",\"from_date\":\"\",\"to_date\":\"\",\"year_graduated\":\"\",\"units_earned\":\"\",\"academic_honors\":\"\"}]', '[{\"title\":\"\",\"rating\":\"\",\"date_of_exam\":\"\",\"place_of_exam\":\"\",\"license_number\":\"\"}]', '[{\"dates\":\"\",\"position\":\"\",\"company\":\"\",\"salary\":\"\",\"salary_grade\":\"\",\"employment_status\":\"\",\"appointment_status\":\"\",\"gov_service\":\"\"}]', '[{\"org\":\"\",\"dates\":\"\"}]', '[{\"title\":\"\",\"dates\":\"\",\"hours\":\"\",\"type\":\"\",\"conducted_by\":\"\",\"venue\":\"\",\"has_certificate\":\"\",\"certificate_details\":\"\"}]', '{\"skills\":\"\",\"distinctions\":\"\",\"memberships\":\"\",\"references\":[{\"name\":\"Atoz narag\",\"address\":\"IHAI SUBDIVISION LOMBOY ST\",\"phone\":\"2334242342\"}],\"dual_citizenship_country\":\"Philippines\",\"umid_id\":\"2123123123\",\"philsys_number\":\"23423423\",\"residential_house_no\":\"\",\"residential_street\":\"Lomboy\",\"residential_subdivision\":\"Ihai\",\"residential_barangay\":\"San Jose\",\"residential_city\":\"PUERTO PRINCESA CITY\",\"residential_province\":\"Palawan\",\"permanent_house_no\":\"\",\"permanent_street\":\"Lomboy\",\"permanent_subdivision\":\"Ihai\",\"permanent_barangay\":\"San Jose\",\"permanent_city\":\"PUERTO PRINCESA CITY\",\"permanent_province\":\"Palawan\",\"spouse_name_extension\":\"\",\"sworn_date\":\"\",\"government_id_number\":\"\",\"government_id_issue_date\":\"\",\"government_id_issue_place\":\"\"}', 'draft', NULL, NULL, NULL, NULL, '2025-12-03 11:12:00', '2025-12-03 11:15:53', '{\"related_authority_third\":\"\",\"related_authority_fourth\":\"\",\"related_authority_details\":\"\",\"found_guilty_admin\":\"\",\"criminally_charged\":\"\",\"criminal_charge_date\":\"\",\"criminal_charge_status\":\"\",\"convicted_crime\":\"\",\"separated_service\":\"\",\"candidate_election\":\"\",\"resigned_for_election\":\"\",\"immigrant_status\":\"\",\"indigenous_group\":\"\",\"person_with_disability\":\"\",\"solo_parent\":\"\"}', 'ADMINISTRATIVE AIDE VI', '2025-12-03 00:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `faculty_profiles`
--

CREATE TABLE `faculty_profiles` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `employee_id` varchar(50) DEFAULT NULL COMMENT 'Format: WPU-YYYY-#####',
  `gender` enum('Male','Female','Other','Prefer not to say') DEFAULT NULL COMMENT 'Employee gender',
  `campus` varchar(100) DEFAULT NULL COMMENT 'Campus location',
  `department` varchar(100) DEFAULT NULL,
  `position` varchar(100) DEFAULT NULL,
  `designation` varchar(100) DEFAULT NULL,
  `employment_status` enum('Full-time','Part-time','Contract','Adjunct') DEFAULT NULL,
  `hire_date` date DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `emergency_contact_name` varchar(100) DEFAULT NULL,
  `emergency_contact_phone` varchar(20) DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `employment_type` varchar(50) DEFAULT NULL,
  `qr_code` varchar(255) DEFAULT NULL COMMENT 'Path to QR code image file for attendance',
  `tutorial_completed` tinyint(1) DEFAULT 0,
  `full_name` varchar(255) DEFAULT NULL COMMENT 'Full name for attendance system compatibility'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `faculty_profiles`
--

INSERT INTO `faculty_profiles` (`id`, `user_id`, `employee_id`, `gender`, `campus`, `department`, `position`, `designation`, `employment_status`, `hire_date`, `phone`, `address`, `emergency_contact_name`, `emergency_contact_phone`, `profile_picture`, `created_at`, `updated_at`, `employment_type`, `qr_code`, `tutorial_completed`, `full_name`) VALUES
(13, 14, 'WPU-2025-00001', NULL, NULL, 'CAS', 'Instructor 1', NULL, '', NULL, NULL, NULL, NULL, NULL, NULL, '2025-12-03 02:06:40', '2025-12-03 02:24:42', NULL, 'qr_codes/qr_14.png', 1, NULL),
(14, 15, 'WPU-2025-00002', NULL, NULL, 'CCAS', 'ADMINISTRATIVE OFFICER III', NULL, '', NULL, NULL, NULL, NULL, NULL, NULL, '2025-12-03 02:06:48', '2025-12-03 02:06:48', NULL, 'qr_codes/qr_15.png', 0, NULL),
(15, 16, 'WPU-2025-00003', 'Male', 'Main Campus - Puerto Princesa', 'CAS', 'INTERNAL AUDITOR III', 'Dean', 'Contract', '2025-06-03', '09686132511', 'IHAI SUBDIVISION LOMBOY ST BRGY SAN JOSE', NULL, NULL, '69301c91c9542_1764760721.jpg', '2025-12-03 02:13:13', '2025-12-03 12:06:53', NULL, 'qr_codes/qr_16.png', 1, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `faculty_requirements`
--

CREATE TABLE `faculty_requirements` (
  `id` int(11) NOT NULL,
  `requirement_id` int(11) NOT NULL,
  `faculty_id` int(11) NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `assigned_by` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `faculty_submissions`
--

CREATE TABLE `faculty_submissions` (
  `id` int(11) NOT NULL,
  `faculty_id` int(11) NOT NULL,
  `requirement_id` int(11) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `file_size` int(11) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `version` int(11) NOT NULL DEFAULT 1,
  `viewed_by_admin` tinyint(1) DEFAULT 0,
  `viewed_at` datetime DEFAULT NULL,
  `previous_submission_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `holidays`
--

CREATE TABLE `holidays` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_requests`
--

CREATE TABLE `leave_requests` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `employee_id` varchar(50) DEFAULT NULL,
  `leave_type` varchar(50) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `days` decimal(5,2) NOT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `type` varchar(100) NOT NULL,
  `title` varchar(255) NOT NULL DEFAULT 'Notification',
  `message` text NOT NULL,
  `link_url` varchar(500) DEFAULT NULL,
  `priority` enum('low','normal','high','urgent') DEFAULT 'normal',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` timestamp NULL DEFAULT NULL,
  `is_hidden` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `type`, `title`, `message`, `link_url`, `priority`, `is_read`, `read_at`, `is_hidden`, `created_at`) VALUES
(17, 1, 'announcement', '📢 ULAN', 'WALANG PASOK', NULL, 'high', 1, '2025-11-04 12:48:25', 1, '2025-11-04 12:41:34'),
(18, NULL, 'announcement', '📢 ULAN', 'WALANG PASOK', NULL, 'high', 0, NULL, 0, '2025-11-04 12:41:38'),
(19, NULL, 'new_requirement', '📋 New Requirement', 'A new requirement \'SSS\' has been added. Deadline: Nov 7, 2025', 'requirements.php?id=17', 'normal', 1, '2025-11-06 01:20:25', 0, '2025-11-06 01:20:06'),
(20, NULL, 'submission', 'Notification', 'Faculty #24 submitted/updated requirement: SSS', NULL, 'normal', 0, NULL, 0, '2025-11-06 01:20:36'),
(21, NULL, 'announcement', '📢 ssss', 'ssss', NULL, 'normal', 0, NULL, 1, '2025-11-12 00:43:28'),
(22, NULL, 'new_requirement', '📋 New Requirement', 'A new requirement \'RESUME\' has been added. Deadline: Nov 14, 2025', 'requirements.php?id=18', 'normal', 1, '2025-11-12 01:53:48', 1, '2025-11-12 01:21:57'),
(23, NULL, 'submission', 'Notification', 'Faculty #25 submitted/updated requirement: RESUME', NULL, 'normal', 0, NULL, 0, '2025-11-12 01:22:11'),
(24, NULL, 'announcement', '📢 dddd', 'dddd', NULL, 'normal', 1, '2025-11-12 01:53:47', 1, '2025-11-12 01:53:38'),
(25, NULL, 'new_requirement', '📋 New Requirement', 'A new requirement \'PHILHEALTH\' has been added. Deadline: Nov 14, 2025', 'requirements.php?id=19', 'normal', 1, '2025-11-12 02:11:30', 1, '2025-11-12 02:07:37'),
(26, NULL, 'new_requirement', '📋 New Requirement', 'A new requirement \'SSS\' has been added. Deadline: Nov 7, 2025', 'requirements.php?id=17', 'normal', 1, '2025-11-12 02:11:30', 1, '2025-11-12 02:09:16'),
(27, NULL, 'new_requirement', '📋 New Requirement', 'A new requirement \'GSIS\' has been added. Deadline: Nov 13, 2025', 'requirements.php?id=20', 'normal', 1, '2025-11-12 02:11:30', 1, '2025-11-12 02:09:46'),
(28, NULL, 'new_requirement', '📋 New Requirement', 'A new requirement \'SSS\' has been added. Deadline: Nov 14, 2025', 'requirements.php?id=21', 'normal', 1, '2025-11-13 01:15:06', 1, '2025-11-13 01:04:35'),
(29, NULL, 'submission', 'Notification', 'Faculty #25 submitted/updated requirement: SSS', NULL, 'normal', 0, NULL, 0, '2025-11-13 05:11:53'),
(30, NULL, 'new_requirement', '📋 New Requirement', 'A new requirement \'GSIS\' has been added. Deadline: Nov 13, 2025', 'requirements.php?id=20', 'normal', 1, '2025-11-13 06:19:43', 1, '2025-11-13 06:18:57'),
(31, NULL, 'new_requirement', '📋 New Requirement', 'A new requirement \'GSIS\' has been added. Deadline: Nov 14, 2025', 'requirements.php?id=22', 'normal', 1, '2025-11-13 06:28:25', 1, '2025-11-13 06:20:03'),
(32, NULL, 'submission', 'Notification', 'Faculty #25 submitted/updated requirement: GSIS', NULL, 'normal', 0, NULL, 0, '2025-11-13 06:20:16'),
(33, NULL, 'new_requirement', '📋 New Requirement', 'A new requirement \'sdasdasd\' has been added. Deadline: Nov 15, 2025', 'requirements.php?id=23', 'normal', 1, '2025-11-15 03:29:10', 1, '2025-11-13 07:08:21'),
(34, NULL, 'new_requirement', '📋 New Requirement', 'A new requirement \'SSS\' has been added. Deadline: Nov 15, 2025', 'requirements.php?id=24', 'normal', 1, '2025-11-15 03:29:10', 1, '2025-11-15 03:25:52'),
(35, NULL, 'new_requirement', '📋 New Requirement', 'A new requirement \'PHILHEALTH\' has been added. Deadline: Nov 15, 2025', 'requirements.php?id=25', 'normal', 1, '2025-11-15 03:29:10', 1, '2025-11-15 03:26:17'),
(36, 1, 'submission', '📄 New Submission Received', 'Jomari Recalde submitted a file for requirement: PHILHEALTH', 'submissions.php?id=19', 'normal', 1, '2025-11-15 03:29:32', 1, '2025-11-15 03:26:58'),
(37, 1, 'submission', '📄 New Submission Received', 'Jomari Recalde submitted a file for requirement: SSS', 'submissions.php?id=20', 'normal', 1, '2025-11-15 03:42:57', 1, '2025-11-15 03:35:39'),
(38, NULL, 'pds_status', '❌ PDS Needs Revision', 'Your Personal Data Sheet needs revision. Please check the admin notes.', 'pds.php', 'high', 1, '2025-11-15 03:46:24', 1, '2025-11-15 03:43:10'),
(39, 1, 'pds_status', '📋 New PDS Submission', 'Jomari Recalde submitted their Personal Data Sheet for review', 'pds_review.php?id=3', 'normal', 1, '2025-11-15 03:44:45', 1, '2025-11-15 03:43:54'),
(40, NULL, 'pds_status', '❌ PDS Needs Revision', 'Your Personal Data Sheet needs revision. Please check the admin notes.', 'pds.php', 'high', 1, '2025-11-15 03:46:24', 1, '2025-11-15 03:46:15'),
(41, 1, 'pds_status', '📋 New PDS Submission', 'Jomari Recalde submitted their Personal Data Sheet for review', 'pds_review.php?id=3', 'normal', 1, '2025-11-15 03:47:13', 1, '2025-11-15 03:47:03'),
(42, NULL, 'new_requirement', '📋 New Requirement', 'A new requirement \'SSS\' has been added. Deadline: Nov 17, 2025', 'requirements.php?id=26', 'normal', 1, '2025-11-15 04:36:41', 1, '2025-11-15 04:32:02'),
(43, NULL, 'new_requirement', '📋 New Requirement', 'A new requirement \'SSS\' has been added. Deadline: Nov 17, 2025', 'requirements.php?id=27', 'normal', 1, '2025-11-15 04:36:41', 1, '2025-11-15 04:32:46'),
(44, NULL, 'new_requirement', '📋 New Requirement', 'A new requirement \'SSS\' has been added. Deadline: Nov 17, 2025', 'requirements.php?id=28', 'normal', 1, '2025-11-15 04:36:41', 1, '2025-11-15 04:35:56'),
(45, NULL, 'new_requirement', '📋 New Requirement', 'A new requirement \'SSS\' has been added. Deadline: Nov 17, 2025', 'requirements.php?id=29', 'normal', 1, '2025-11-15 04:46:41', 0, '2025-11-15 04:37:05'),
(46, NULL, 'new_requirement', '📋 New Requirement', 'A new requirement \'SSS\' has been added. Deadline: Nov 17, 2025', 'requirements.php?id=30', 'normal', 1, '2025-11-15 04:46:41', 1, '2025-11-15 04:41:32'),
(47, NULL, 'new_requirement', '📋 New Requirement', 'A new requirement \'SSS\' has been added. Deadline: Nov 17, 2025', 'requirements.php?id=31', 'normal', 1, '2025-11-15 04:46:41', 1, '2025-11-15 04:45:43'),
(48, NULL, 'new_requirement', '📋 New Requirement', 'A new requirement \'SSS\' has been added. Deadline: Nov 17, 2025', 'requirements.php?id=32', 'normal', 1, '2025-11-15 04:46:41', 0, '2025-11-15 04:46:06'),
(50, 1, 'announcement', '📢 SSS', 'SSS', NULL, 'high', 0, NULL, 1, '2025-11-17 04:35:40'),
(51, NULL, 'announcement', '📢 PHILHEALTH', 'PASA NA', NULL, 'high', 0, NULL, 0, '2025-11-17 05:07:14'),
(52, NULL, 'announcement', '📢 PHILHEALTH', 'PASA NA', NULL, 'high', 0, NULL, 0, '2025-11-17 05:07:25'),
(53, NULL, 'announcement', '📢 PHILHEALTH', 'PASA NA', NULL, 'high', 0, NULL, 0, '2025-11-17 05:07:29'),
(54, NULL, 'new_requirement', '📋 New Requirement', 'A new requirement \'PHILHEALTH\' has been added. Deadline: Nov 17, 2025', 'requirements.php?id=33', 'normal', 0, NULL, 0, '2025-11-17 05:12:34'),
(55, 1, 'submission', '📄 New Submission Received', 'jomari barraquio submitted a file for requirement: PHILHEALTH', 'submissions.php?id=21', 'normal', 1, '2025-11-17 05:17:35', 1, '2025-11-17 05:13:03'),
(56, 1, 'pds_status', '📋 New PDS Submission', 'Jomari Recalde submitted their Personal Data Sheet for review', 'pds_review.php?id=', 'normal', 1, '2025-11-17 06:30:03', 1, '2025-11-17 06:29:54'),
(57, 1, 'pds_status', '📋 New PDS Submission', 'Jomari Recalde submitted their Personal Data Sheet for review', 'pds_review.php?id=4', 'normal', 1, '2025-11-17 06:34:53', 1, '2025-11-17 06:32:50'),
(58, 1, 'pds_status', '📋 New PDS Submission', 'Jomari Recalde submitted their Personal Data Sheet for review', 'pds_review.php?id=4', 'normal', 1, '2025-11-18 23:51:32', 0, '2025-11-17 07:58:52'),
(59, 1, 'pds_status', '📋 New PDS Submission', 'Jomari Recalde submitted their Personal Data Sheet for review', 'pds_review.php?id=1', 'normal', 1, '2025-11-20 10:48:53', 0, '2025-11-20 05:48:15'),
(60, 1, 'pds_status', '📋 New PDS Submission', 'Jomari Recalde submitted their Personal Data Sheet for review', 'pds_review.php?id=1', 'normal', 1, '2025-11-20 10:48:53', 0, '2025-11-20 07:40:11'),
(62, 1, 'submission', '📄 New Submission Received', 'Jomari Recalde submitted a file for requirement: Philhealth', 'submissions.php?id=0', 'normal', 1, '2025-11-22 00:50:29', 0, '2025-11-22 00:46:38'),
(68, 1, 'pds_status', '📋 New PDS Submission', 'Jomari Recalde submitted their Personal Data Sheet for review', 'pds_review.php?id=1', 'normal', 0, NULL, 1, '2025-11-26 01:52:13'),
(72, 1, 'submission', '📄 New Submission Received', 'Jomari Recalde submitted a file for requirement: GSIS', 'submissions.php?id=2', 'normal', 1, '2025-11-26 02:08:30', 0, '2025-11-26 02:08:20'),
(73, 1, 'pds_status', '📋 New PDS Submission', 'Jomari Recalde submitted their Personal Data Sheet for review', 'pds_review.php?id=2', 'normal', 1, '2025-11-29 00:04:01', 0, '2025-11-29 00:03:52'),
(76, 1, 'submission', '📄 New Submission Received', 'Jomari Recalde submitted a file for requirement: GSIS', 'submissions.php?id=3', 'normal', 1, '2025-11-29 03:05:10', 0, '2025-11-29 03:04:49'),
(77, 1, 'submission', '📄 New Submission Received', 'Jomari Recalde submitted a file for requirement: Philhealth', 'submissions.php?id=4', 'normal', 1, '2025-11-29 03:05:10', 0, '2025-11-29 03:05:00'),
(78, 1, 'pds_status', '📋 New PDS Submission', 'Marvin Saik submitted their Personal Data Sheet for review', 'pds_review.php?id=3', 'normal', 1, '2025-12-03 02:53:04', 0, '2025-12-03 02:37:42');

-- --------------------------------------------------------

--
-- Table structure for table `pardon_requests`
--

CREATE TABLE `pardon_requests` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(50) NOT NULL,
  `log_id` int(11) NOT NULL,
  `log_date` date NOT NULL,
  `original_time_in` time DEFAULT NULL,
  `original_lunch_out` time DEFAULT NULL,
  `original_lunch_in` time DEFAULT NULL,
  `original_time_out` time DEFAULT NULL,
  `requested_time_in` time DEFAULT NULL,
  `requested_lunch_out` time DEFAULT NULL,
  `requested_lunch_in` time DEFAULT NULL,
  `requested_time_out` time DEFAULT NULL,
  `reason` text NOT NULL,
  `supporting_documents` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `review_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pardon_requests`
--

INSERT INTO `pardon_requests` (`id`, `employee_id`, `log_id`, `log_date`, `original_time_in`, `original_lunch_out`, `original_lunch_in`, `original_time_out`, `requested_time_in`, `requested_lunch_out`, `requested_lunch_in`, `requested_time_out`, `reason`, `supporting_documents`, `status`, `reviewed_by`, `reviewed_at`, `review_notes`, `created_at`, `updated_at`) VALUES
(11, 'WPU-2025-00003', 415, '2025-12-03', '11:12:45', '11:13:37', '11:13:45', '11:13:49', '07:00:00', '12:00:00', '13:00:00', '17:00:00', 'no internet connection', NULL, 'pending', NULL, NULL, NULL, '2025-12-04 01:12:26', '2025-12-04 01:12:26');

-- --------------------------------------------------------

--
-- Table structure for table `payroll_settings`
--

CREATE TABLE `payroll_settings` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(50) NOT NULL,
  `deduction_name` varchar(50) NOT NULL,
  `employee_share` decimal(10,2) NOT NULL,
  `employer_share` decimal(10,2) NOT NULL,
  `is_optional` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pds_children`
--

CREATE TABLE `pds_children` (
  `id` int(11) NOT NULL,
  `pds_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `dob` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pds_children`
--

INSERT INTO `pds_children` (`id`, `pds_id`, `name`, `dob`, `created_at`, `updated_at`) VALUES
(1, 1, 'Luffy D. Monkey', '2025-11-04', '2025-11-26 01:52:00', '2025-11-26 01:52:00'),
(3, 2, 'Luffy D. Monkey', '2025-11-13', '2025-11-29 04:33:29', '2025-11-29 04:33:29'),
(4, 4, 'Luffy D. Monkey', '2025-12-01', '2025-12-03 11:05:01', '2025-12-03 11:05:01');

-- --------------------------------------------------------

--
-- Table structure for table `pds_education`
--

CREATE TABLE `pds_education` (
  `id` int(11) NOT NULL,
  `pds_id` int(11) NOT NULL,
  `level` varchar(100) DEFAULT NULL,
  `school` varchar(255) DEFAULT NULL,
  `degree` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `from_date` varchar(100) DEFAULT NULL,
  `to_date` varchar(100) DEFAULT NULL,
  `units_earned` varchar(100) DEFAULT NULL,
  `year_graduated` varchar(50) DEFAULT NULL,
  `academic_honors` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pds_education`
--

INSERT INTO `pds_education` (`id`, `pds_id`, `level`, `school`, `degree`, `created_at`, `updated_at`, `from_date`, `to_date`, `units_earned`, `year_graduated`, `academic_honors`) VALUES
(1, 1, '1st - 4th year', 'Fulbright College', 'BSIT', '2025-11-26 01:52:00', '2025-11-26 01:52:00', '2021', '2025', NULL, '2025', NULL),
(3, 2, '1st - 4th year', 'Fulbright College', 'BSIT', '2025-11-29 04:33:29', '2025-11-29 04:33:29', '2021', '2025', NULL, '2025', NULL),
(4, 4, '1st - 4th year', 'Fulbright College', 'BSIT', '2025-12-03 11:05:01', '2025-12-03 11:05:01', '2021', '2025', NULL, '2025', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `pds_experience`
--

CREATE TABLE `pds_experience` (
  `id` int(11) NOT NULL,
  `pds_id` int(11) NOT NULL,
  `dates` varchar(100) DEFAULT NULL,
  `position` varchar(255) DEFAULT NULL,
  `company` varchar(255) DEFAULT NULL,
  `salary` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `employment_status` varchar(100) DEFAULT NULL,
  `salary_grade` varchar(50) DEFAULT NULL,
  `appointment_status` varchar(100) DEFAULT NULL,
  `gov_service` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pds_experience`
--

INSERT INTO `pds_experience` (`id`, `pds_id`, `dates`, `position`, `company`, `salary`, `created_at`, `updated_at`, `employment_status`, `salary_grade`, `appointment_status`, `gov_service`) VALUES
(1, 1, 'none', 'none', 'Deliverya', '9,000', '2025-11-26 01:52:00', '2025-11-26 01:52:00', 'Casual', NULL, NULL, 0),
(3, 2, '', '', 'Deliverya', '9,000', '2025-11-29 04:33:29', '2025-11-29 04:33:29', 'Casual', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `pds_learning`
--

CREATE TABLE `pds_learning` (
  `id` int(11) NOT NULL,
  `pds_id` int(11) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `dates` varchar(100) DEFAULT NULL,
  `hours` varchar(50) DEFAULT NULL,
  `type` varchar(100) DEFAULT NULL,
  `conducted_by` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `venue` varchar(255) DEFAULT NULL,
  `has_certificate` tinyint(1) DEFAULT 0,
  `certificate_details` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pds_references`
--

CREATE TABLE `pds_references` (
  `id` int(11) NOT NULL,
  `pds_id` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `address` varchar(500) DEFAULT NULL,
  `phone` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pds_references`
--

INSERT INTO `pds_references` (`id`, `pds_id`, `name`, `address`, `phone`, `created_at`, `updated_at`) VALUES
(1, 1, 'Atoz narag', 'IHAI SUBDIVISION LOMBOY ST', '2334242342', '2025-11-26 01:52:00', '2025-11-26 01:52:00'),
(3, 2, 'Atoz narag', 'IHAI SUBDIVISION LOMBOY ST', '2334242342', '2025-11-29 04:33:29', '2025-11-29 04:33:29'),
(4, 4, 'Atoz narag', 'IHAI SUBDIVISION LOMBOY ST', '2334242342', '2025-12-03 11:05:01', '2025-12-03 11:05:01'),
(5, 5, 'Atoz narag', 'IHAI SUBDIVISION LOMBOY ST', '2334242342', '2025-12-03 11:15:53', '2025-12-03 11:15:53');

-- --------------------------------------------------------

--
-- Table structure for table `pds_voluntary`
--

CREATE TABLE `pds_voluntary` (
  `id` int(11) NOT NULL,
  `pds_id` int(11) NOT NULL,
  `org` varchar(255) DEFAULT NULL,
  `dates` varchar(100) DEFAULT NULL,
  `hours` varchar(50) DEFAULT NULL,
  `position` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pds_voluntary`
--

INSERT INTO `pds_voluntary` (`id`, `pds_id`, `org`, `dates`, `hours`, `position`, `created_at`, `updated_at`) VALUES
(1, 1, 'IHAI SUBDIVISION LOMBOY ST', '', NULL, NULL, '2025-11-26 01:52:00', '2025-11-26 01:52:00'),
(3, 2, 'IHAI SUBDIVISION LOMBOY ST', '', NULL, NULL, '2025-11-29 04:33:29', '2025-11-29 04:33:29');

-- --------------------------------------------------------

--
-- Table structure for table `positions`
--

CREATE TABLE `positions` (
  `id` int(11) NOT NULL,
  `position_name` varchar(100) NOT NULL,
  `monthly_rate` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `positions`
--

INSERT INTO `positions` (`id`, `position_name`, `monthly_rate`) VALUES
(1, 'ACCOUNTANT I', 32245.00),
(2, 'ADMINISTRATIVE AIDE I', 14061.00),
(3, 'ADMINISTRATIVE AIDE II', 14925.00),
(4, 'ADMINISTRATIVE AIDE III', 15851.00),
(5, 'ADMINISTRATIVE AIDE IV', 16833.00),
(6, 'ADMINISTRATIVE AIDE V', 17866.00),
(7, 'ADMINISTRATIVE AIDE VI', 18957.00),
(8, 'ADMINISTRATIVE ASSISTANT I', 20110.00),
(9, 'ADMINISTRATIVE ASSISTANT II', 21448.00),
(10, 'ADMINISTRATIVE ASSISTANT III', 23226.00),
(11, 'ADMINISTRATIVE ASSISTANT V', 30024.00),
(12, 'ADMINISTRATIVE OFFICER I', 25586.00),
(13, 'ADMINISTRATIVE OFFICER II', 30024.00),
(14, 'ADMINISTRATIVE OFFICER III', 37024.00),
(15, 'ADMINISTRATIVE OFFICER IV', 40208.00),
(16, 'ADMINISTRATIVE OFFICER V', 51304.00),
(17, 'AGRICULTURAL TECHNICIAN II', 21448.00),
(18, 'ASSISTANT PROFESSOR I', 40208.00),
(19, 'ASSISTANT PROFESSOR II', 43560.00),
(20, 'ASSISTANT PROFESSOR III', 47247.00),
(21, 'ASSISTANT PROFESSOR IV', 51304.00),
(22, 'ASSOCIATE PROFESSOR I', 56390.00),
(23, 'ASSOCIATE PROFESSOR II', 62967.00),
(24, 'ASSOCIATE PROFESSOR III', 70013.00),
(25, 'ASSOCIATE PROFESSOR IV', 78162.00),
(26, 'ASSOCIATE PROFESSOR V', 87315.00),
(27, 'ATTORNEY IV', 87315.00),
(28, 'BOARD SECRETARY I', 37024.00),
(29, 'BOATSWAIN', 16833.00),
(30, 'CHIEF ADMINISTRATIVE OFFICER', 98185.00),
(31, 'DENTIST II', 47247.00),
(32, 'ENGINEER III', 56390.00),
(33, 'EXECUTIVE ASSISTANT III', 62967.00),
(34, 'FARM SUPERINTENDENT I', 30024.00),
(35, 'FARM WORKER I', 14925.00),
(36, 'FARM WORKER II', 16833.00),
(37, 'FISHERMAN', 15852.00),
(38, 'GUIDANCE COUNSELOR I', 30024.00),
(39, 'INFORMATION OFFICER I', 30024.00),
(40, 'INFORMATION OFFICER II', 40208.00),
(41, 'INFORMATION OFFICER III', 51304.00),
(42, 'INFORMATION SYSTEMS ANALYST I', 32245.00),
(43, 'INFORMATION SYSTEMS ANALYST II', 43560.00),
(44, 'INFORMATION TECHNOLOGY OFFICER I', 56390.00),
(45, 'INSTRUCTOR I', 32245.00),
(46, 'INSTRUCTOR II', 34421.00),
(47, 'INSTRUCTOR III', 37024.00),
(48, 'INTERNAL AUDITOR I', 30024.00),
(49, 'INTERNAL AUDITOR II', 40208.00),
(50, 'INTERNAL AUDITOR III', 51304.00),
(51, 'LAUNCH SERVICE SUPERVISOR', 32245.00),
(52, 'LEGAL ASSISTANT III', 37024.00),
(53, 'LIBRARIAN I', 30024.00),
(54, 'LIBRARIAN II', 40208.00),
(55, 'LIGHT EQUIPMENT OPERATOR', 14925.00),
(56, 'MASTER FISHERMAN II', 21448.00),
(57, 'MEDICAL OFFICER IV', 87315.00),
(58, 'NURSE I', 40208.00),
(59, 'NURSE II', 43560.00),
(60, 'PLANNING OFFICER I', 30024.00),
(61, 'PLANNING OFFICER II', 40208.00),
(62, 'PLANNING OFFICER III', 51304.00),
(63, 'PROFESSOR I', 98185.00),
(64, 'PROFESSOR III', 126252.00),
(65, 'PROFESSOR V', 160469.00),
(66, 'PROFESSOR VI', 180492.00),
(67, 'PROJECT DEVELOPMENT OFFICER I', 30024.00),
(68, 'PROJECT DEVELOPMENT OFFICER II', 40208.00),
(69, 'PROJECT DEVELOPMENT OFFICER III', 51304.00),
(70, 'REGISTRAR III', 51304.00),
(71, 'SCIENCE RESEARCH ANALYST', 30024.00),
(72, 'SECURITY GUARD II', 17866.00),
(73, 'SECURITY GUARD III', 21448.00),
(74, 'SUC PRESIDENT III', 180492.00),
(75, 'SUPERVISING ADMINISTRATIVE OFFICER', 78162.00);

-- --------------------------------------------------------

--
-- Table structure for table `position_salary`
--

CREATE TABLE `position_salary` (
  `id` int(11) NOT NULL,
  `position_title` varchar(100) DEFAULT NULL,
  `salary_grade` int(11) DEFAULT NULL,
  `annual_salary` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `position_salary`
--

INSERT INTO `position_salary` (`id`, `position_title`, `salary_grade`, `annual_salary`) VALUES
(1, 'ADMINISTRATIVE ASSISTANT I', 7, 20110.00),
(2, 'ADMINISTRATIVE OFFICER IV', 15, 40208.00),
(3, 'ADMINISTRATIVE AIDE II', 2, 14925.00),
(4, 'ADMINISTRATIVE OFFICER IV', 15, 40208.00),
(5, 'ADMINISTRATIVE AIDE I', 1, 14061.00),
(6, 'ADMINISTRATIVE AIDE VI', 6, 18957.00),
(7, 'ADMINISTRATIVE ASSISTANT III', 9, 23226.00),
(8, 'ADMINISTRATIVE OFFICER I', 10, 25586.00),
(9, 'ADMINISTRATIVE OFFICER II', 11, 30024.00),
(10, 'ADMINISTRATIVE OFFICER III', 14, 37024.00),
(11, 'GUIDANCE COUNSELOR I', 11, 30024.00),
(12, 'GUIDANCE COUNSELOR I', 11, 30024.00),
(13, 'ADMINISTRATIVE OFFICER II', 11, 30024.00),
(14, 'ADMINISTRATIVE AIDE I', 1, 14061.00),
(15, 'ADMINISTRATIVE OFFICER III', 14, 37024.00),
(16, 'ADMINISTRATIVE OFFICER IV', 15, 40208.00),
(17, 'INTERNAL AUDITOR II', 15, 40208.00),
(18, 'BOARD SECRETARY I', 14, 37024.00),
(19, 'PLANNING OFFICER I', 11, 30024.00),
(20, 'ADMINISTRATIVE AIDE I', 1, 14061.00),
(21, 'ADMINISTRATIVE OFFICER V', 18, 51304.00),
(22, 'ADMINISTRATIVE AIDE VI', 6, 18957.00),
(23, 'ADMINISTRATIVE OFFICER III', 14, 37024.00),
(24, 'ADMINISTRATIVE AIDE I', 1, 14061.00),
(25, 'INTERNAL AUDITOR I', 11, 30024.00),
(27, 'PROJECT DEVELOPMENT OFFICER II', 15, 40208.00),
(28, 'ADMINISTRATIVE OFFICER III', 14, 37024.00),
(29, 'ADMINISTRATIVE OFFICER I', 10, 25586.00),
(30, 'ADMINISTRATIVE ASSISTANT III', 9, 23226.00),
(31, 'ADMINISTRATIVE AIDE VI', 6, 18957.00),
(32, 'ADMINISTRATIVE OFFICER V', 18, 51304.00),
(33, 'ADMINISTRATIVE AIDE IV', 4, 16833.00),
(34, 'ADMINISTRATIVE OFFICER II', 11, 30024.00),
(36, 'ADMINISTRATIVE AIDE VI', 6, 18957.00),
(37, 'INFORMATION SYSTEMS ANALYST I', 12, 32245.00),
(38, 'SUPERVISING ADMINISTRATIVE OFFICER', 22, 78162.00),
(39, 'INTERNAL AUDITOR III', 18, 51304.00),
(40, 'ADMINISTRATIVE AIDE III', 3, 15852.00),
(41, 'ADMINISTRATIVE AIDE VI', 6, 18957.00),
(42, 'ADMINISTRATIVE OFFICER V', 18, 51304.00),
(43, 'ADMINISTRATIVE AIDE IV', 4, 16833.00),
(44, 'ADMINISTRATIVE OFFICER III', 14, 37024.00),
(45, 'ADMINISTRATIVE AIDE VI', 6, 18957.00),
(46, 'ADMINISTRATIVE OFFICER IV', 15, 40208.00),
(47, 'ADMINISTRATIVE AIDE I', 1, 14061.00),
(48, 'ADMINISTRATIVE OFFICER II', 11, 30024.00),
(49, 'ADMINISTRATIVE AIDE VI', 6, 18957.00),
(50, 'ADMINISTRATIVE OFFICER III', 14, 37024.00),
(51, 'ADMINISTRATIVE AIDE I', 1, 14061.00),
(52, 'ADMINISTRATIVE OFFICER IV', 15, 40208.00),
(53, 'ADMINISTRATIVE AIDE VI', 6, 18957.00),
(54, 'ADMINISTRATIVE OFFICER V', 18, 51304.00),
(55, 'ADMINISTRATIVE AIDE IV', 4, 16833.00),
(56, 'ADMINISTRATIVE OFFICER III', 14, 37024.00),
(57, 'Instructor 1', 1, 18333.00);

-- --------------------------------------------------------

--
-- Table structure for table `requirements`
--

CREATE TABLE `requirements` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `file_types_allowed` varchar(255) DEFAULT 'pdf,doc,docx',
  `file_types` varchar(255) DEFAULT NULL,
  `max_file_size` int(11) DEFAULT 5242880,
  `deadline` date DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `requirement_attachments`
--

CREATE TABLE `requirement_attachments` (
  `id` int(11) NOT NULL,
  `requirement_id` int(11) NOT NULL,
  `faculty_id` int(11) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `original_filename` varchar(255) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `security_logs`
--

CREATE TABLE `security_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `event_type` varchar(100) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `security_logs`
--

INSERT INTO `security_logs` (`id`, `user_id`, `event_type`, `details`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 5, 'LOGIN_SUCCESS', 'Successful login: jomari.recalde@wpu.edu.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-23 23:44:17'),
(2, 1, 'LOGIN_SUCCESS', 'Successful login: admin@wpu.edu.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-23 23:48:28'),
(3, 5, 'LOGIN_SUCCESS', 'Successful login: jomari.recalde@wpu.edu.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-23 23:49:55'),
(4, 5, 'LOGIN_SUCCESS', 'Successful login: jomari.recalde@wpu.edu.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-23 23:53:55'),
(5, 5, 'LOGIN_SUCCESS', 'Successful login: jomari.recalde@wpu.edu.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-24 00:02:33'),
(6, 1, 'LOGIN_SUCCESS', 'Successful login: admin@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-25 03:08:15'),
(7, NULL, 'LOGIN_SUCCESS', 'Successful login: enkiecarl.echague@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 04:37:39'),
(8, NULL, 'LOGIN_FAILED', 'Failed login attempt for: enkiecarl.echague@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 05:03:07'),
(9, NULL, 'LOGIN_FAILED', 'Failed login attempt for: enkiecarl.echague@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 05:03:25'),
(10, NULL, 'LOGIN_FAILED', 'Failed login attempt for: enkiecarl.echague@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 05:03:28'),
(11, NULL, 'CSRF_TOKEN_INVALID', 'Failed CSRF validation for email: enkiecarl.echague@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 05:03:35'),
(12, NULL, 'CSRF_TOKEN_INVALID', 'Failed CSRF validation for email: enkiecarl.echague@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 05:03:40'),
(13, NULL, 'LOGIN_FAILED', 'Failed login attempt for: enkiecarl.echague@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 05:04:16'),
(14, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: enkiecarl.echague@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 05:05:39'),
(15, NULL, 'CSRF_TOKEN_INVALID', 'Failed CSRF validation for email: enkiecarl.echague@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 05:06:25'),
(16, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: enkiecarl.echague@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 05:06:44'),
(17, NULL, 'CSRF_TOKEN_INVALID', 'Failed CSRF validation for email: enkiecarl.echague@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 05:08:15'),
(18, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: enkiecarl.echague@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 05:08:38'),
(19, 5, 'LOGIN_SUCCESS', 'Successful login: jomari.recalde@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 05:08:57'),
(20, 7, 'LOGIN_SUCCESS', 'Successful login: enkiecarl.echague@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 05:22:04'),
(21, 7, 'LOGIN_SUCCESS', 'Successful login: enkiecarl.echague@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 05:25:22'),
(22, 7, 'LOGIN_SUCCESS', 'Successful login: enkiecarl.echague@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 05:30:10'),
(23, 7, 'LOGIN_SUCCESS', 'Successful login: enkiecarl.echague@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 05:30:59'),
(24, 7, 'LOGIN_SUCCESS', 'Successful login: enkiecarl.echague@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 05:33:25'),
(25, 7, 'LOGIN_SUCCESS', 'Successful login: enkiecarl.echague@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:11:41'),
(26, 7, 'LOGIN_SUCCESS', 'Successful login: enkiecarl.echague@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-25 06:16:35'),
(27, 7, 'LOGIN_SUCCESS', 'Successful login: enkiecarl.echague@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Cursor/2.1.19 Chrome/138.0.7204.251 Electron/37.7.0 Safari/537.36', '2025-11-25 06:18:05'),
(28, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:23'),
(29, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:23'),
(30, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:23'),
(31, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:23'),
(32, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:23'),
(33, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:24'),
(34, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:24'),
(35, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:24'),
(36, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:24'),
(37, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:24'),
(38, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:24'),
(39, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:24'),
(40, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:25'),
(41, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:25'),
(42, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:25'),
(43, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:25'),
(44, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:25'),
(45, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:25'),
(46, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:26'),
(47, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:26'),
(48, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:26'),
(49, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:26'),
(50, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:26'),
(51, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:26'),
(52, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:27'),
(53, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:27'),
(54, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:27'),
(55, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:27'),
(56, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:27'),
(57, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:27'),
(58, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:27'),
(59, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:28'),
(60, NULL, 'RATE_LIMIT_EXCEEDED', 'Too many login attempts for: recaldejoms2729@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:24:28'),
(61, 1, 'LOGIN_SUCCESS', 'Successful login: admin@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-25 06:28:19'),
(62, 7, 'LOGIN_SUCCESS', 'Successful login: enkiecarl.echague@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-25 06:29:17'),
(63, 7, 'LOGIN_SUCCESS', 'Successful login: enkiecarl.echague@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Cursor/2.1.19 Chrome/138.0.7204.251 Electron/37.7.0 Safari/537.36', '2025-11-25 06:38:02'),
(64, 7, 'LOGIN_SUCCESS', 'Successful login: enkiecarl.echague@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:38:31'),
(65, 7, 'LOGIN_SUCCESS', 'Successful login: enkiecarl.echague@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:38:39'),
(66, 7, 'LOGIN_SUCCESS', 'Successful login: enkiecarl.echague@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:39:06'),
(67, 1, 'LOGIN_SUCCESS', 'Successful login: admin@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:42:23'),
(68, 5, 'LOGIN_SUCCESS', 'Successful login: jomari.recalde@wpu.edu.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-26 01:42:50'),
(69, 1, 'LOGIN_SUCCESS', 'Successful login: admin@wpu.edu.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-26 01:44:03'),
(70, NULL, 'CSRF_TOKEN_INVALID', 'Failed CSRF validation for email: jomari.recalde@wpu.edu.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-28 22:56:01'),
(71, 5, 'LOGIN_SUCCESS', 'Successful login: jomari.recalde@wpu.edu.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-28 22:56:07'),
(72, 5, 'LOGIN_SUCCESS', 'Successful login: jomari.recalde@wpu.edu.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-28 22:59:14'),
(73, 1, 'LOGIN_SUCCESS', 'Successful login: admin@wpu.edu.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-28 23:11:33'),
(74, 8, 'LOGIN_SUCCESS', 'Successful login: jomari.recalde@wpu.edu.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-28 23:14:12'),
(75, 8, 'LOGIN_SUCCESS', 'Successful login: jomari.recalde@wpu.edu.ph', '127.0.0.1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36 EdgA/131.0.0.0', '2025-11-28 23:34:36'),
(76, 8, 'LOGIN_SUCCESS', 'Successful login: jomari.recalde@wpu.edu.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-28 23:38:04'),
(77, 8, 'PASSWORD_CHANGE_SUCCESS', 'Password changed for user ID: 8', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-29 00:07:16'),
(78, 1, 'LOGIN_SUCCESS', 'Successful login: admin@wpu.edu.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-29 00:10:12'),
(79, 1, 'LOGIN_SUCCESS', 'Successful login: admin@wpu.edu.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-29 01:16:48'),
(80, NULL, 'LOGIN_FAILED', 'Failed login attempt for: jomari.recalde@wpu.edu.ph', '127.0.0.1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36 EdgA/131.0.0.0', '2025-11-29 01:21:29'),
(81, 8, 'LOGIN_SUCCESS', 'Successful login: jomari.recalde@wpu.edu.ph', '127.0.0.1', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36 EdgA/131.0.0.0', '2025-11-29 01:21:39'),
(82, 1, 'LOGIN_SUCCESS', 'Successful login: admin@wpu.edu.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-29 04:21:16'),
(83, 8, 'LOGIN_SUCCESS', 'Successful login: jomari.recalde@wpu.edu.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-29 04:32:26'),
(84, 8, 'LOGIN_SUCCESS', 'Successful login: jomari.recalde@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-01 00:56:22'),
(85, 1, 'LOGIN_SUCCESS', 'Successful login: admin@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-01 01:03:31'),
(86, 8, 'LOGIN_SUCCESS', 'Successful login: jomari.recalde@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-01 02:31:30'),
(87, 1, 'LOGIN_SUCCESS', 'Successful login: admin@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-01 02:33:56'),
(88, 1, 'LOGIN_SUCCESS', 'Successful login: admin@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-01 02:37:27'),
(89, 8, 'LOGIN_SUCCESS', 'Successful login: jomari.recalde@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-12-01 02:45:38'),
(90, 8, 'LOGIN_SUCCESS', 'Successful login: jomari.recalde@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-01 09:11:49'),
(91, NULL, 'CSRF_TOKEN_INVALID', 'Failed CSRF validation for email: jomari.recalde@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-12-03 00:13:50'),
(92, 8, 'LOGIN_SUCCESS', 'Successful login: jomari.recalde@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-12-03 00:14:14'),
(93, 1, 'LOGIN_SUCCESS', 'Successful login: admin@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-03 00:14:22'),
(94, NULL, 'LOGIN_FAILED', 'Failed login attempt for: jomari.recalde@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-12-03 02:19:14'),
(95, 16, 'LOGIN_SUCCESS', 'Successful login: jomari.recalde@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-12-03 02:19:25'),
(96, NULL, 'LOGIN_FAILED', 'Failed login attempt for: marvin.saik@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-12-03 02:21:07'),
(97, NULL, 'LOGIN_FAILED', 'Failed login attempt for: marvin.saik@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-12-03 02:21:20'),
(98, 14, 'LOGIN_SUCCESS', 'Successful login: marvin.saik@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-12-03 02:21:42'),
(99, 16, 'LOGIN_SUCCESS', 'Successful login: jomari.recalde@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-12-03 03:49:25'),
(100, 1, 'LOGIN_SUCCESS', 'Successful login: admin@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-12-03 10:54:47'),
(101, 16, 'LOGIN_SUCCESS', 'Successful login: jomari.recalde@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-03 10:56:02'),
(102, 16, 'PASSWORD_CHANGE_SUCCESS', 'Password changed for user ID: 16', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-03 10:56:29'),
(103, NULL, 'LOGIN_FAILED', 'Failed login attempt for: jomari.recalde@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2025-12-04 01:07:43'),
(104, NULL, 'LOGIN_FAILED', 'Failed login attempt for: jomari.recalde@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2025-12-04 01:07:48'),
(105, 16, 'LOGIN_SUCCESS', 'Successful login: jomari.recalde@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2025-12-04 01:07:56'),
(106, 1, 'LOGIN_SUCCESS', 'Successful login: admin@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-12-04 01:10:49');

-- --------------------------------------------------------

--
-- Table structure for table `stations`
--

CREATE TABLE `stations` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `department_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `stations`
--

INSERT INTO `stations` (`id`, `name`, `department_id`, `created_at`, `updated_at`) VALUES
(1, 'Station 1', 2, '2025-11-21 05:13:16', '2025-11-21 05:13:16'),
(2, 'Station 2', 4, '2025-11-21 05:19:04', '2025-11-21 05:19:04'),
(3, 'Station 3', 6, '2025-11-22 05:48:05', '2025-11-22 05:48:05');

-- --------------------------------------------------------

--
-- Table structure for table `system_logs`
--

CREATE TABLE `system_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_logs`
--

INSERT INTO `system_logs` (`id`, `user_id`, `action`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 1, 'CALENDAR_EVENT_CREATE', 'Created calendar event: HOLIDAY on 2025-11-21', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-20 02:48:26'),
(2, 1, 'FACULTY_DELETED', 'Deleted faculty account: Jomari Recalde (ID: 19)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-20 02:50:53'),
(3, 1, 'CREATE_FACULTY_BATCH', 'Admin created account: jomari.recalde@wpu.edu.ph (Employee ID: WPU-2025-00001)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-20 02:52:51'),
(5, 1, 'FACULTY_DELETED', 'Deleted faculty account: Jomari Recalde (ID: 8)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-20 04:44:20'),
(6, 1, 'CREATE_FACULTY', 'Admin created faculty account: jomari.recalde@wpu.edu.ph (Employee ID: WPU-2025-00001)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-20 04:53:01'),
(7, 1, 'FACULTY_DELETED', 'Deleted faculty account: Jomari Recalde (ID: 2)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-20 04:53:15'),
(8, 1, 'CREATE_FACULTY_BATCH', 'Admin created account: enkiecarl.echague@wpu.edu.ph (Employee ID: WPU-2025-00001)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-20 04:53:31'),
(9, 1, 'CREATE_FACULTY_BATCH', 'Admin created account: marvin.saik@wpu.edu.ph (Employee ID: WPU-2025-00002)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-20 04:53:35'),
(10, 1, 'ADMIN_FACULTY_UPDATE', 'Updated personal information for faculty ID: 4', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-20 04:54:39'),
(11, 1, 'ADMIN_FACULTY_PROFILE_UPDATE', 'Updated faculty and staff profile information for faculty ID: 4', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-20 04:54:39'),
(12, 1, 'CREATE_FACULTY', 'Admin created faculty account: jomari.recalde@wpu.edu.ph (Employee ID: WPU-2025-00003)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-20 04:54:53'),
(13, 1, 'ADMIN_FACULTY_UPDATE', 'Updated personal information for faculty ID: 5', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-20 04:55:18'),
(14, 1, 'ADMIN_FACULTY_PROFILE_UPDATE', 'Updated faculty and staff profile information for faculty ID: 5', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-20 04:55:18'),
(21, 1, 'ACCOUNT_STATUS_UPDATE', 'Updated user status for ID: 5', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-20 07:47:31'),
(22, 1, 'ACCOUNT_STATUS_UPDATE', 'Updated user status for ID: 5', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-20 08:11:14'),
(23, 1, 'FILE_DOWNLOAD', 'Downloaded file: pardon_requests/pardon_WPU-2025-00003_2_1763627404.JPG', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-20 08:35:32'),
(24, 1, 'FILE_DOWNLOAD', 'Downloaded file: pardon_requests/pardon_WPU-2025-00003_2_1763627404.JPG', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-20 08:35:54'),
(25, 1, 'LOGIN', 'User logged in: admin@wpu.edu.ph (Type: admin)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-20 10:48:35'),
(30, 1, 'LOGIN', 'User logged in: admin@wpu.edu.ph (Type: admin)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-20 23:27:08'),
(31, 1, 'LOGIN', 'User logged in: admin@wpu.edu.ph (Type: admin)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-20 23:49:42'),
(37, 1, 'FILE_DOWNLOAD', 'Downloaded file: pardon_requests/pardon_WPU-2025-00003_2_1763627404.JPG', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-21 00:50:07'),
(42, 1, 'STATION_CREATE', 'Created station: Station 1', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-21 05:13:16'),
(43, 1, 'TIMEKEEPER_CREATE', 'Created timekeeper for user ID: 3', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-21 05:18:26'),
(44, 1, 'STATION_CREATE', 'Created station: Station 2', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-21 05:19:04'),
(45, 1, 'ADMIN_FACULTY_UPDATE', 'Updated personal information for faculty ID: 5', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-21 05:19:41'),
(46, 1, 'ADMIN_FACULTY_PROFILE_UPDATE', 'Updated faculty and staff profile information for faculty ID: 5', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-21 05:19:41'),
(80, 1, 'LOGOUT', 'User logged out: admin@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-21 06:53:02'),
(82, 1, 'LOGIN', 'User logged in: admin@wpu.edu.ph (Type: admin)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-21 06:59:37'),
(83, 1, 'TIMEKEEPER_CREATE', 'Created timekeeper for user ID: 5', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-21 07:02:12'),
(105, 1, 'LOGIN', 'User logged in: admin@wpu.edu.ph (Type: admin)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-21 07:41:34'),
(106, 1, 'TARF_CREATE', 'Created TARF: test for 3 date(s) with 2 employee(s)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-21 07:53:07'),
(107, 1, 'TARF_DELETE', 'Deleted TARF ID: 2 - test', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-21 07:58:00'),
(108, 1, 'TARF_DELETE', 'Deleted TARF ID: 1 - test', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-21 07:58:09'),
(109, 1, 'TARF_DELETE', 'Deleted TARF ID: 3 - test', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-21 07:58:15'),
(110, 1, 'TARF_CREATE', 'Created TARF: testing for 2 date(s) with 2 employee(s)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-21 07:58:58'),
(111, 1, 'TARF_DELETE', 'Deleted TARF ID: 5 - testing', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-21 08:01:10'),
(112, 1, 'TARF_DELETE', 'Deleted TARF ID: 4 - testing', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-21 08:01:32'),
(113, 1, 'TARF_CREATE', 'Created TARF: testing today for 1 date(s) with 2 employee(s)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-21 08:10:36'),
(114, 1, 'HOLIDAY_CREATE', 'Created holiday: asdfghrtyui for 1 day(s)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-21 08:33:31'),
(115, 1, 'HOLIDAY_CREATE', 'Created holiday: holiday000 for 2 day(s)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-21 08:35:01'),
(116, 1, 'TARF_DELETE', 'Deleted TARF ID: 6 - testing today', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-21 08:42:22'),
(117, 1, 'HOLIDAY_CREATE', 'Created holiday: testing for 1 day(s)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-21 08:42:36'),
(119, 1, 'LOGIN', 'User logged in: admin@wpu.edu.ph (Type: admin)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-21 10:56:02'),
(127, 1, 'LOGIN', 'User logged in: admin@wpu.edu.ph (Type: admin)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-21 14:16:44'),
(130, 1, 'LOGIN', 'User logged in: admin@wpu.edu.ph (Type: admin)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-21 22:52:57'),
(133, 1, 'HOLIDAY_DELETE', 'Deleted holiday ID: 32 - asdfghjkl', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-21 23:37:18'),
(134, 1, 'HOLIDAY_DELETE', 'Deleted holiday ID: 35 - asdfghrtyui', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-21 23:37:24'),
(135, 1, 'HOLIDAY_DELETE', 'Deleted holiday ID: 37 - holiday000', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-21 23:37:31'),
(136, 1, 'HOLIDAY_DELETE', 'Deleted holiday ID: 36 - holiday000', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-21 23:37:37'),
(137, 1, 'HOLIDAY_DELETE', 'Deleted holiday ID: 33 - asdfghjkl', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-21 23:37:47'),
(138, 1, 'HOLIDAY_DELETE', 'Deleted holiday ID: 38 - testing', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-21 23:37:53'),
(139, 1, 'POSITION_DELETE', 'Deleted position: ADMINISTRATIVE AIDE I', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-21 23:52:18'),
(140, 1, 'POSITION_DELETE', 'Deleted position: ADMINISTRATIVE AIDE I', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-21 23:52:22'),
(144, 1, 'REQUIREMENT_UPDATE', 'Updated requirement: Philhealth', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-22 00:42:41'),
(146, 1, 'ANNOUNCEMENT_CREATE', 'Created announcement: WALANG PASOK', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-22 00:50:15'),
(150, 1, 'POSITION_CREATE', 'Created position: Instructor 1', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-22 03:00:54'),
(152, 1, 'STATION_CREATE', 'Created station: Station 3', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-22 05:48:05'),
(153, 1, 'TIMEKEEPER_UPDATE', 'Updated timekeeper ID: 1', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-22 05:49:31'),
(159, 1, 'LOGIN', 'User logged in: admin@wpu.edu.ph (Type: admin)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-23 23:48:28'),
(165, 1, 'LOGIN', 'User logged in: admin@wpu.edu.ph (Type: admin)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-25 03:08:15'),
(167, 1, 'FACULTY_DELETED', 'Deleted faculty account: Enkie Echague (ID: 3)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-25 04:59:58'),
(168, 1, 'CREATE_FACULTY', 'Admin created faculty account: enkiecarl.echague@wpu.edu.ph (Employee ID: WPU-2025-00004)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-25 05:00:53'),
(169, 1, 'FACULTY_DELETED', 'Deleted faculty account: Enkie Carl Echague (ID: 6)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-25 05:07:21'),
(170, 1, 'CREATE_FACULTY', 'Admin created faculty account: enkiecarl.echague@wpu.edu.ph (Employee ID: WPU-2025-00004)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-25 05:07:51'),
(188, 1, 'LOGIN', 'User logged in: admin@wpu.edu.ph (Type: admin)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-25 06:28:19'),
(189, 1, 'LOGOUT', 'User logged out: admin@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-11-25 06:28:46'),
(200, 1, 'LOGIN', 'User logged in: admin@wpu.edu.ph (Type: admin)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-25 06:42:23'),
(202, 1, 'LOGIN', 'User logged in: admin@wpu.edu.ph (Type: admin)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-26 01:44:03'),
(203, 1, 'ANNOUNCEMENT_CREATE', 'Created announcement: HELLO', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-26 01:45:22'),
(204, 1, 'REQUIREMENT_CREATE', 'Created requirement: GSIS with 3 assignments', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-26 02:06:49'),
(209, 1, 'LOGIN', 'User logged in: admin@wpu.edu.ph (Type: admin)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-28 23:11:33'),
(210, 1, 'FACULTY_DELETED', 'Deleted faculty account: Jomari Recalde (ID: 5)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-28 23:12:27'),
(211, 1, 'CREATE_FACULTY', 'Admin created faculty account: jomari.recalde@wpu.edu.ph (Employee ID: WPU-2025-00005)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-28 23:12:58'),
(219, 1, 'FACULTY_DELETED', 'Deleted faculty account: enkie carl echague (ID: 7)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-29 00:09:04'),
(220, 1, 'FACULTY_DELETED', 'Deleted staff account: Marvin Saik (ID: 4)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-29 00:09:08'),
(221, 1, 'CREATE_FACULTY_BATCH', 'Admin created account: enkiecarl.echague@wpu.edu.ph (Employee ID: WPU-2025-00006)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-29 00:09:42'),
(222, 1, 'CREATE_FACULTY_BATCH', 'Admin created account: marvin.saik@wpu.edu.ph (Employee ID: WPU-2025-00007)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-29 00:09:47'),
(223, 1, 'LOGIN', 'User logged in: admin@wpu.edu.ph (Type: admin)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-29 00:10:12'),
(224, 1, 'ADMIN_FACULTY_UPDATE', 'Updated personal information for faculty ID: 10', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-29 00:12:50'),
(225, 1, 'ADMIN_FACULTY_PROFILE_UPDATE', 'Updated faculty and staff profile information for faculty ID: 10', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-29 00:12:50'),
(226, 1, 'LOGIN', 'User logged in: admin@wpu.edu.ph (Type: admin)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-29 01:16:48'),
(227, 1, 'REQUIREMENT_UPDATE', 'Updated requirement: GSIS', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-29 01:17:12'),
(228, 1, 'REQUIREMENT_UPDATE', 'Updated requirement: Philhealth', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-29 01:17:26'),
(229, 1, 'REQUIREMENT_UPDATE', 'Updated requirement: Philhealth', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-29 01:19:36'),
(231, 1, 'TIMEKEEPER_DELETE', 'Deleted timekeeper:  ', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-29 01:23:28'),
(232, 1, 'TIMEKEEPER_CREATE', 'Created timekeeper for user ID: 8', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-29 01:23:46'),
(241, 1, 'FILE_DOWNLOAD', 'Downloaded file: pardon_requests/pardon_WPU-2025-00005_411_1764383582_0.pdf', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-29 02:34:13'),
(244, 1, 'FILE_VIEW', 'Viewed file: submissions/692a62dce5f51_1764385500.pdf', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-29 03:05:37'),
(245, 1, 'LOGIN', 'User logged in: admin@wpu.edu.ph (Type: admin)', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-29 04:21:15'),
(246, 1, 'TIMEKEEPER_DELETE', 'Deleted timekeeper: Jomari Recalde', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-29 04:22:54'),
(247, 1, 'TIMEKEEPER_CREATE', 'Created timekeeper for user ID: 8', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-11-29 04:25:43'),
(254, 1, 'LOGIN', 'User logged in: admin@wpu.edu.ph (Type: admin)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-01 01:03:31'),
(255, 1, 'HOLIDAY_DELETE', 'Deleted holiday ID: 34 - asdfghjkl', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-01 01:03:41'),
(258, 1, 'LOGOUT', 'User logged out: admin@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-01 02:31:21'),
(266, 1, 'LOGIN', 'User logged in: admin@wpu.edu.ph (Type: admin)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-01 02:33:56'),
(267, 1, 'LOGOUT', 'User logged out: admin@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-01 02:37:18'),
(268, 1, 'LOGIN', 'User logged in: admin@wpu.edu.ph (Type: admin)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-01 02:37:27'),
(272, 1, 'LOGIN', 'User logged in: admin@wpu.edu.ph (Type: admin)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-03 00:14:22'),
(273, 1, 'FACULTY_DELETED', 'Deleted staff account: Marvin Saik (ID: 10)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-03 00:14:32'),
(274, 1, 'FACULTY_DELETED', 'Deleted faculty account: Enkie Echague (ID: 9)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-03 00:14:36'),
(275, 1, 'CREATE_FACULTY_BATCH', 'Admin created account: enkiecarl.echague@wpu.edu.ph (Employee ID: WPU-2025-00006)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-03 00:15:44'),
(276, 1, 'CREATE_FACULTY_BATCH', 'Admin created account: marvin.saik@wpu.edu.ph (Employee ID: WPU-2025-00007)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-03 00:15:51'),
(277, 1, 'CALENDAR_EVENT_CREATE', 'Created calendar event: OUP meeting on 2025-12-03', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-03 00:18:30'),
(278, 1, 'ADMIN_FACULTY_UPDATE', 'Updated personal information for faculty ID: 12', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-03 00:33:30'),
(279, 1, 'ADMIN_FACULTY_PROFILE_UPDATE', 'Updated faculty and staff profile information for faculty ID: 12', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-03 00:33:30'),
(280, 1, 'ADMIN_FACULTY_PICTURE_UPDATE', 'Updated profile picture for faculty ID: 12 to: 692f857d8f2b9_1764722045.png', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-03 00:34:05'),
(283, 1, 'FACULTY_DELETED', 'Deleted faculty account: Enkie Echague (ID: 11)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-03 01:14:35'),
(284, 1, 'FACULTY_DELETED', 'Deleted faculty account: Jomari Recalde (ID: 8)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-03 01:14:49'),
(285, 1, 'FACULTY_DELETED', 'Deleted staff account: Marvin Saik (ID: 12)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-03 01:14:52'),
(286, 1, 'CREATE_FACULTY', 'Admin created faculty account: jomari.recalde@wpu.edu.ph (Employee ID: WPU-2025-00001)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-03 01:15:55'),
(287, 1, 'FACULTY_DELETED', 'Deleted faculty account: Jomari Recalde (ID: 13)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-03 01:16:21'),
(288, 1, 'EMP_STATUS_ADDED', 'Added employment status: CASUAL', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-03 01:19:32'),
(289, 1, 'CREATE_FACULTY_BATCH', 'Admin created account: marvin.saik@wpu.edu.ph (Employee ID: WPU-2025-00001)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-03 02:06:48'),
(290, 1, 'CREATE_FACULTY_BATCH', 'Admin created account: enkiecarl.echague@wpu.edu.ph (Employee ID: WPU-2025-00002)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-03 02:06:54'),
(291, 1, 'CREATE_FACULTY', 'Admin created faculty account: jomari.recalde@wpu.edu.ph (Employee ID: WPU-2025-00003)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-03 02:13:13'),
(292, 16, 'LOGIN', 'User logged in: jomari.recalde@wpu.edu.ph (Type: faculty)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-12-03 02:19:25'),
(293, 16, 'LOGOUT', 'User logged out: jomari.recalde@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-12-03 02:20:43'),
(294, 14, 'LOGIN', 'User logged in: marvin.saik@wpu.edu.ph (Type: faculty)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-12-03 02:21:42'),
(295, 1, 'TIMEKEEPER_DELETE', 'Deleted timekeeper:  ', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-03 02:44:28'),
(296, 1, 'TIMEKEEPER_CREATE', 'Created timekeeper for user ID: 16', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-03 03:00:59'),
(297, 16, 'TIMEKEEPER_LOGIN', 'Timekeeper logged in: WPU-2025-00003 - Station 1', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:145.0) Gecko/20100101 Firefox/145.0', '2025-12-03 03:04:55'),
(298, 16, 'LOGOUT', 'User logged out: jomari.recalde@wpu.edu.ph', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:145.0) Gecko/20100101 Firefox/145.0', '2025-12-03 03:05:46'),
(299, 16, 'TIMEKEEPER_LOGIN', 'Timekeeper logged in: WPU-2025-00003 - Station 1', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:145.0) Gecko/20100101 Firefox/145.0', '2025-12-03 03:06:07'),
(300, 16, 'TIMEKEEPER_ATTENDANCE', 'Recorded time_in for employee: WPU-2025-00001', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:145.0) Gecko/20100101 Firefox/145.0', '2025-12-03 03:07:27'),
(301, 16, 'TIMEKEEPER_ATTENDANCE', 'Recorded lunch_out for employee: WPU-2025-00001', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:145.0) Gecko/20100101 Firefox/145.0', '2025-12-03 03:08:52'),
(302, 16, 'TIMEKEEPER_ATTENDANCE', 'Recorded time_in for employee: WPU-2025-00003', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:145.0) Gecko/20100101 Firefox/145.0', '2025-12-03 03:12:45'),
(303, 16, 'TIMEKEEPER_ATTENDANCE', 'Recorded lunch_out for employee: WPU-2025-00003', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:145.0) Gecko/20100101 Firefox/145.0', '2025-12-03 03:13:37'),
(304, 16, 'TIMEKEEPER_ATTENDANCE', 'Recorded lunch_in for employee: WPU-2025-00003', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:145.0) Gecko/20100101 Firefox/145.0', '2025-12-03 03:13:45'),
(305, 16, 'TIMEKEEPER_ATTENDANCE', 'Recorded time_out for employee: WPU-2025-00003', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:145.0) Gecko/20100101 Firefox/145.0', '2025-12-03 03:13:49'),
(306, 14, 'LOGOUT', 'User logged out: marvin.saik@wpu.edu.ph', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-12-03 03:49:17'),
(307, 16, 'LOGIN', 'User logged in: jomari.recalde@wpu.edu.ph (Type: faculty)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-12-03 03:49:25'),
(308, 1, 'LOGIN', 'User logged in: admin@wpu.edu.ph (Type: admin)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-12-03 10:54:47'),
(309, 16, 'LOGIN', 'User logged in: jomari.recalde@wpu.edu.ph (Type: faculty)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-03 10:56:02'),
(310, 16, 'PASSWORD_CHANGE', 'Changed password', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-03 10:56:29'),
(311, 16, 'PROFILE_PICTURE_UPDATE', 'Updated profile picture to: 69301c91c9542_1764760721.jpg', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', '2025-12-03 11:18:41'),
(312, 1, 'ADMIN_FACULTY_UPDATE', 'Updated personal information for faculty ID: 16', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-12-03 11:24:57'),
(313, 1, 'ADMIN_FACULTY_PROFILE_UPDATE', 'Updated faculty and staff profile information for faculty ID: 16', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-12-03 11:24:57'),
(314, 1, 'ADMIN_FACULTY_UPDATE', 'Updated personal information for faculty ID: 16', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-12-03 11:25:12'),
(315, 1, 'ADMIN_FACULTY_PROFILE_UPDATE', 'Updated faculty and staff profile information for faculty ID: 16', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-12-03 11:25:12'),
(316, 1, 'ADMIN_FACULTY_UPDATE', 'Updated personal information for faculty ID: 16', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-12-03 11:51:44'),
(317, 1, 'ADMIN_FACULTY_PROFILE_UPDATE', 'Updated faculty and staff profile information for faculty ID: 16', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-12-03 11:51:44'),
(318, 1, 'ADMIN_FACULTY_UPDATE', 'Updated personal information for faculty ID: 16', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-12-03 11:51:51'),
(319, 1, 'ADMIN_FACULTY_PROFILE_UPDATE', 'Updated faculty and staff profile information for faculty ID: 16', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-12-03 11:51:51'),
(320, 1, 'ADMIN_FACULTY_UPDATE', 'Updated personal information for faculty ID: 16', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-12-03 12:06:53'),
(321, 1, 'ADMIN_FACULTY_PROFILE_UPDATE', 'Updated faculty and staff profile information for faculty ID: 16', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-12-03 12:06:53'),
(322, 16, 'LOGIN', 'User logged in: jomari.recalde@wpu.edu.ph (Type: faculty)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '2025-12-04 01:07:56'),
(323, 1, 'LOGIN', 'User logged in: admin@wpu.edu.ph (Type: admin)', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '2025-12-04 01:10:49');

-- --------------------------------------------------------

--
-- Table structure for table `tarf`
--

CREATE TABLE `tarf` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tarf_employees`
--

CREATE TABLE `tarf_employees` (
  `id` int(11) NOT NULL,
  `tarf_id` int(11) NOT NULL,
  `employee_id` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `timekeepers`
--

CREATE TABLE `timekeepers` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `station_id` int(11) NOT NULL,
  `password` varchar(255) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `timekeepers`
--

INSERT INTO `timekeepers` (`id`, `user_id`, `station_id`, `password`, `is_active`, `created_at`, `updated_at`) VALUES
(5, 16, 1, '$2y$10$q7NTxMq.hF9k1/pd1i4DiO7HXaMb/ByJWmxgdtgjREEZBzEbMEOQy', 1, '2025-12-03 03:00:59', '2025-12-03 03:00:59');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `user_type` enum('admin','faculty','staff') NOT NULL DEFAULT 'faculty',
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `is_verified` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `email`, `password`, `user_type`, `first_name`, `last_name`, `middle_name`, `is_verified`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'admin@wpu.edu.ph', '$2y$10$jm2XOfBu51Lu.aTAkPmZ5OFTJPaOLRnN82qE4yVhrIwm2plHhrjvG', 'admin', 'System', 'Administrator', NULL, 1, 1, '2025-10-25 01:55:45', '2025-10-25 02:13:48'),
(14, 'marvin.saik@wpu.edu.ph', '$2y$10$oWdsYDenSM4j5Kj8YMf1.OUw9u1oeOMyf49Nyog3FuZaWxafeX8dS', 'faculty', 'Marvin', 'Saik', 'Quillo', 1, 1, '2025-12-03 02:06:40', '2025-12-03 02:06:40'),
(15, 'enkiecarl.echague@wpu.edu.ph', '$2y$10$2v3SarWKHomA4ZNGnC1P2uhaHb6h/EUKzRdpiI0HgOiqkqhnJ5.ai', 'staff', 'Enkie', 'Echague', 'BonBon', 1, 1, '2025-12-03 02:06:48', '2025-12-03 02:06:48'),
(16, 'jomari.recalde@wpu.edu.ph', '$2y$10$PD5uIA9umJBRA11W23rmXOePEufYyTwVrZZq02.Hv6QvXk7PPSP6G', 'faculty', 'Jomari', 'Recalde', 'Barraquio', 1, 1, '2025-12-03 02:13:13', '2025-12-03 12:06:53');

--
-- Triggers `users`
--
DELIMITER $$
CREATE TRIGGER `users_email_lowercase_before_insert` BEFORE INSERT ON `users` FOR EACH ROW BEGIN
    SET NEW.email = LOWER(TRIM(NEW.email));
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `users_email_lowercase_before_update` BEFORE UPDATE ON `users` FOR EACH ROW BEGIN
    SET NEW.email = LOWER(TRIM(NEW.email));
END
$$
DELIMITER ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `attendance_logs`
--
ALTER TABLE `attendance_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `calendar_events`
--
ALTER TABLE `calendar_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_is_archived` (`is_archived`);

--
-- Indexes for table `campuses`
--
ALTER TABLE `campuses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `idx_name` (`name`);

--
-- Indexes for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `deductions`
--
ALTER TABLE `deductions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `designations`
--
ALTER TABLE `designations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `email_templates`
--
ALTER TABLE `email_templates`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `employee_deductions`
--
ALTER TABLE `employee_deductions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `employee_official_times`
--
ALTER TABLE `employee_official_times`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `employment_statuses`
--
ALTER TABLE `employment_statuses`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `events`
--
ALTER TABLE `events`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `faculty_civil_service_eligibility`
--
ALTER TABLE `faculty_civil_service_eligibility`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `faculty_pds`
--
ALTER TABLE `faculty_pds`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `faculty_profiles`
--
ALTER TABLE `faculty_profiles`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `faculty_requirements`
--
ALTER TABLE `faculty_requirements`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `faculty_submissions`
--
ALTER TABLE `faculty_submissions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `holidays`
--
ALTER TABLE `holidays`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `leave_requests`
--
ALTER TABLE `leave_requests`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `pardon_requests`
--
ALTER TABLE `pardon_requests`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `payroll_settings`
--
ALTER TABLE `payroll_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `pds_children`
--
ALTER TABLE `pds_children`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `pds_education`
--
ALTER TABLE `pds_education`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `pds_experience`
--
ALTER TABLE `pds_experience`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `pds_learning`
--
ALTER TABLE `pds_learning`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `pds_references`
--
ALTER TABLE `pds_references`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `pds_voluntary`
--
ALTER TABLE `pds_voluntary`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `positions`
--
ALTER TABLE `positions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `position_salary`
--
ALTER TABLE `position_salary`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `requirements`
--
ALTER TABLE `requirements`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `requirement_attachments`
--
ALTER TABLE `requirement_attachments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `security_logs`
--
ALTER TABLE `security_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `stations`
--
ALTER TABLE `stations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `system_logs`
--
ALTER TABLE `system_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tarf`
--
ALTER TABLE `tarf`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tarf_employees`
--
ALTER TABLE `tarf_employees`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `timekeepers`
--
ALTER TABLE `timekeepers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_log`
--
ALTER TABLE `activity_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `attendance_logs`
--
ALTER TABLE `attendance_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=416;

--
-- AUTO_INCREMENT for table `calendar_events`
--
ALTER TABLE `calendar_events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `campuses`
--
ALTER TABLE `campuses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `chat_messages`
--
ALTER TABLE `chat_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `deductions`
--
ALTER TABLE `deductions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=496;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `designations`
--
ALTER TABLE `designations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `email_templates`
--
ALTER TABLE `email_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `employee_deductions`
--
ALTER TABLE `employee_deductions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `employee_official_times`
--
ALTER TABLE `employee_official_times`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `employment_statuses`
--
ALTER TABLE `employment_statuses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `events`
--
ALTER TABLE `events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `faculty_civil_service_eligibility`
--
ALTER TABLE `faculty_civil_service_eligibility`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `faculty_pds`
--
ALTER TABLE `faculty_pds`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `faculty_profiles`
--
ALTER TABLE `faculty_profiles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `faculty_requirements`
--
ALTER TABLE `faculty_requirements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `faculty_submissions`
--
ALTER TABLE `faculty_submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `holidays`
--
ALTER TABLE `holidays`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `leave_requests`
--
ALTER TABLE `leave_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=79;

--
-- AUTO_INCREMENT for table `pardon_requests`
--
ALTER TABLE `pardon_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `payroll_settings`
--
ALTER TABLE `payroll_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pds_children`
--
ALTER TABLE `pds_children`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `pds_education`
--
ALTER TABLE `pds_education`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `pds_experience`
--
ALTER TABLE `pds_experience`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `pds_learning`
--
ALTER TABLE `pds_learning`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pds_references`
--
ALTER TABLE `pds_references`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `pds_voluntary`
--
ALTER TABLE `pds_voluntary`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `positions`
--
ALTER TABLE `positions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=76;

--
-- AUTO_INCREMENT for table `position_salary`
--
ALTER TABLE `position_salary`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=58;

--
-- AUTO_INCREMENT for table `requirements`
--
ALTER TABLE `requirements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- AUTO_INCREMENT for table `requirement_attachments`
--
ALTER TABLE `requirement_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `security_logs`
--
ALTER TABLE `security_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=107;

--
-- AUTO_INCREMENT for table `stations`
--
ALTER TABLE `stations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `system_logs`
--
ALTER TABLE `system_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=324;

--
-- AUTO_INCREMENT for table `tarf`
--
ALTER TABLE `tarf`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tarf_employees`
--
ALTER TABLE `tarf_employees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `timekeepers`
--
ALTER TABLE `timekeepers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
