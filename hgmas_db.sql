-- phpMyAdmin SQL Dump
-- version 5.0.4
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 14, 2026 at 04:27 PM
-- Server version: 10.4.17-MariaDB
-- PHP Version: 8.0.0

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `hgmas_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `role` varchar(20) NOT NULL,
  `action` varchar(50) NOT NULL,
  `description` text NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `timestamp` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`log_id`, `user_id`, `username`, `role`, `action`, `description`, `ip_address`, `timestamp`) VALUES
(29, 10, '222', 'receptionist', 'DELETE GUEST', '⚠️ PERMANENT DELETE: Guest Gilbert SASAS (Room: ZEBRA, Phone: 0672454057) was deleted manually by receptionist.', '127.0.0.1', '2026-01-14 02:29:30'),
(30, 9, '111', 'manager', 'Login', 'User logged in successfully.', '127.0.0.1', '2026-01-14 02:29:43'),
(31, 10, '222', 'receptionist', 'Login', 'User logged in successfully.', '127.0.0.1', '2026-01-14 02:36:40'),
(32, 10, '222', 'receptionist', 'Payment Received', 'Received TZS 240,000.00 from Gilbert SASAS (Room: ELEPHANT). Method: Cash', '127.0.0.1', '2026-01-14 02:36:48'),
(33, 10, '222', 'receptionist', 'Extend Stay', 'Added 1 day for guest: Gilbert SASAS. Total days: 4', '127.0.0.1', '2026-01-14 02:37:51'),
(34, 10, '222', 'receptionist', 'Reduce Stay', 'Reduced 1 day for guest: Gilbert SASAS. Total days: 3', '127.0.0.1', '2026-01-14 02:37:54'),
(35, 10, '222', 'receptionist', 'Payment Received', 'Received TZS 120,000.00 from Gilbert SASAS (Room: ELEPHANT). Method: Cash', '127.0.0.1', '2026-01-14 02:38:10'),
(36, 10, '222', 'receptionist', 'Check-Out', 'Checked out guest: Gilbert SASAS from Room: ELEPHANT. Bill Cleared: 360,000', '127.0.0.1', '2026-01-14 02:38:26'),
(37, 10, '222', 'receptionist', 'Payment Received', 'Received TZS 160,000.00 from Gilbert SASAS (Room: GIRAFFE). Method: Credit Card', '127.0.0.1', '2026-01-14 02:38:46'),
(38, 10, '222', 'receptionist', 'Payment Received', 'Received TZS 0.00 from Gilbert SASAS (Room: GIRAFFE). Method: Credit Card', '127.0.0.1', '2026-01-14 02:39:19'),
(39, 10, '222', 'receptionist', 'Reduce Stay', 'Reduced 1 day for guest: Gilbert SASAS. Total days: 2', '127.0.0.1', '2026-01-14 02:39:50'),
(40, 10, '222', 'receptionist', 'Reduce Stay', 'Reduced 1 day for guest: Gilbert SASAS. Total days: 2', '127.0.0.1', '2026-01-14 02:39:57'),
(41, 10, '222', 'receptionist', 'Reduce Stay', 'Reduced 1 day for guest: Gilbert SASAS. Total days: 1', '127.0.0.1', '2026-01-14 02:39:59'),
(42, 10, '222', 'receptionist', 'Payment Received', 'Received TZS 80,000.00 from Gilbert SASAS (Room: FLAMINGO). Method: Cash', '127.0.0.1', '2026-01-14 02:40:34'),
(43, 10, '222', 'receptionist', 'Payment Received', 'Received TZS 200,000.00 from Gilbert SASAS (Room: LION). Method: Mobile Payment', '127.0.0.1', '2026-01-14 02:41:00'),
(44, 10, '222', 'receptionist', 'Payment Received', 'Received TZS 0.00 from Gilbert SASAS (Room: LION). Method: Mobile Payment', '127.0.0.1', '2026-01-14 02:41:30'),
(45, 10, '222', 'receptionist', 'Extend Stay', 'Added 1 day for guest: Gilbert SASAS. Total days: 4', '127.0.0.1', '2026-01-14 02:41:42'),
(46, 10, '222', 'receptionist', 'Reduce Stay', 'Reduced 1 day for guest: Gilbert SASAS. Total days: 3', '127.0.0.1', '2026-01-14 02:41:45'),
(47, 10, '222', 'receptionist', 'Reduce Stay', 'Reduced 1 day for guest: Gilbert SASAS. Total days: 2', '127.0.0.1', '2026-01-14 02:41:48'),
(48, 10, '222', 'receptionist', 'Check-Out', 'Checked out guest: Gilbert SASAS from Room: LION. Bill Cleared: 200,000', '127.0.0.1', '2026-01-14 02:41:58'),
(49, 9, '111', 'manager', 'Login', 'User logged in successfully.', '127.0.0.1', '2026-01-14 02:42:11'),
(50, 10, '222', 'receptionist', 'Login', 'User logged in successfully.', '127.0.0.1', '2026-01-14 17:23:00'),
(51, 10, '222', 'receptionist', 'Extend Stay', 'Added 1 day for guest: Gilbert SASAS. Total days: 4', '127.0.0.1', '2026-01-14 17:23:48'),
(52, 9, '111', 'manager', 'Login', 'User logged in successfully.', '127.0.0.1', '2026-01-14 17:24:55'),
(53, 13, '555', 'receptionist', 'Login', 'User logged in', '127.0.0.1', '2026-01-14 17:31:42'),
(54, 13, '555', 'receptionist', 'Login', 'User logged in', '127.0.0.1', '2026-01-14 17:32:26'),
(56, 9, '111', 'manager', 'Login', 'User logged in successfully.', '127.0.0.1', '2026-01-14 17:54:03'),
(57, 14, 'GILBERT', 'manager', 'Login', 'User logged in', '127.0.0.1', '2026-01-14 17:56:16'),
(58, 15, '222', 'receptionist', 'Login', 'User logged in', '127.0.0.1', '2026-01-14 18:02:45'),
(59, 15, '222', 'receptionist', 'Check-in', 'Group Check-in: George Guthrie Co (Leader: Lev Watkins). Rooms: ELEPHANT, CHEATER, ZEBRA. Total Bill: TZS 280,000', '127.0.0.1', '2026-01-14 18:03:56'),
(60, 15, '222', 'receptionist', 'Payment Received', 'Received TZS 320,000.00 from Gilbert SASAS (Room: BUFFALO). Method: Credit Card [Extra: 545,546] [Discount: 5,757]', '127.0.0.1', '2026-01-14 18:06:03'),
(61, 13, '555', 'receptionist', 'Login', 'User logged in', '127.0.0.1', '2026-01-14 18:08:02'),
(62, 14, 'GILBERT', 'manager', 'Login', 'User logged in', '127.0.0.1', '2026-01-14 18:08:32');

-- --------------------------------------------------------

--
-- Table structure for table `checkin_checkout`
--

CREATE TABLE `checkin_checkout` (
  `checkin_id` int(11) NOT NULL,
  `guest_id` int(11) NOT NULL,
  `room_name` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `room_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `checkin_time` datetime NOT NULL,
  `days_stayed` int(11) DEFAULT 1,
  `total_amount` decimal(10,2) NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'Checked In',
  `checkout_actual_date` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `checkin_checkout`
--

INSERT INTO `checkin_checkout` (`checkin_id`, `guest_id`, `room_name`, `room_type`, `checkin_time`, `days_stayed`, `total_amount`, `status`, `checkout_actual_date`, `created_at`, `updated_at`) VALUES
(67, 100, 'RHINO', 'TWIN', '2026-01-14 02:26:00', 4, '640000.00', 'Checked In', NULL, '2026-01-13 23:27:26', '2026-01-14 14:23:47'),
(68, 101, 'BUFFALO', 'TWIN', '2026-01-14 02:26:00', 3, '480000.00', 'Checked In', NULL, '2026-01-13 23:27:26', '2026-01-13 23:27:26'),
(73, 106, 'FLAMINGO', 'DOUBLE', '2026-01-14 02:26:00', 1, '80000.00', 'Checked In', NULL, '2026-01-13 23:27:26', '2026-01-13 23:39:59'),
(74, 107, 'GIRAFFE', 'DOUBLE', '2026-01-14 02:26:00', 2, '160000.00', 'Checked In', NULL, '2026-01-13 23:27:26', '2026-01-13 23:39:50'),
(75, 108, 'ELEPHANT', 'TRIPLE', '2006-07-09 12:04:00', 1, '120000.00', 'Checked In', NULL, '2026-01-14 15:03:56', '2026-01-14 15:03:56'),
(76, 109, 'CHEATER', 'DOUBLE', '2006-07-09 12:04:00', 1, '80000.00', 'Checked In', NULL, '2026-01-14 15:03:56', '2026-01-14 15:03:56'),
(77, 110, 'ZEBRA', 'DOUBLE', '2006-07-09 12:04:00', 1, '80000.00', 'Checked In', NULL, '2026-01-14 15:03:56', '2026-01-14 15:03:56');

-- --------------------------------------------------------

--
-- Table structure for table `guest`
--

CREATE TABLE `guest` (
  `guest_id` int(11) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `gender` enum('Male','Female') NOT NULL,
  `resident_status` enum('Resident','Non-Resident') NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `passport_id` varchar(50) DEFAULT NULL,
  `passport_country` varchar(100) DEFAULT NULL,
  `passport_expiry` date DEFAULT NULL,
  `company_name` varchar(150) DEFAULT NULL,
  `company_address` varchar(255) DEFAULT NULL,
  `room_type` varchar(100) DEFAULT NULL,
  `room_name` varchar(100) DEFAULT NULL,
  `room_rate` decimal(10,2) DEFAULT NULL,
  `checkin_date` date DEFAULT NULL,
  `checkin_time` time DEFAULT NULL,
  `checkout_date` date DEFAULT NULL,
  `checkout_time` time DEFAULT NULL,
  `car_number` varchar(50) DEFAULT NULL,
  `check_in` datetime DEFAULT NULL,
  `check_out` datetime DEFAULT NULL,
  `status` enum('Checked-In','Checked-Out','Reserved') DEFAULT 'Checked-In',
  `car_available` varchar(5) DEFAULT 'No',
  `car_plate` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `booking_type` varchar(20) DEFAULT 'individual'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `guest`
--

INSERT INTO `guest` (`guest_id`, `first_name`, `last_name`, `gender`, `resident_status`, `phone`, `email`, `address`, `city`, `country`, `passport_id`, `passport_country`, `passport_expiry`, `company_name`, `company_address`, `room_type`, `room_name`, `room_rate`, `checkin_date`, `checkin_time`, `checkout_date`, `checkout_time`, `car_number`, `check_in`, `check_out`, `status`, `car_available`, `car_plate`, `created_at`, `booking_type`) VALUES
(100, 'Gilbert', 'SASAS', 'Male', 'Resident', '0672454057', 'gilbertamani29@gmail.com', '7240 MOSHI', 'Moshi', 'Tanzania', NULL, NULL, NULL, 'SAFRI PARK', '7240 MOSHI', 'TWIN', 'RHINO', '160000.00', '2026-01-14', '02:26:00', '2026-01-18', '10:00:00', NULL, NULL, NULL, 'Checked-In', 'No', '', '2026-01-13 23:27:26', 'group'),
(101, 'Gilbert', 'SASAS', 'Male', 'Resident', '0672454057', 'gilbertamani29@gmail.com', '7240 MOSHI', 'Moshi', 'Tanzania', NULL, NULL, NULL, 'SAFRI PARK', '7240 MOSHI', 'TWIN', 'BUFFALO', '160000.00', '2026-01-14', '02:26:00', '2026-01-16', '10:00:00', NULL, NULL, NULL, 'Checked-In', 'No', '', '2026-01-13 23:27:26', 'group'),
(106, 'Gilbert', 'SASAS', 'Male', 'Resident', '0672454057', 'gilbertamani29@gmail.com', '7240 MOSHI', 'Moshi', 'Tanzania', NULL, NULL, NULL, 'SAFRI PARK', '7240 MOSHI', 'DOUBLE', 'FLAMINGO', '80000.00', '2026-01-14', '02:26:00', '2026-01-15', '10:00:00', NULL, NULL, NULL, 'Checked-In', 'No', '', '2026-01-13 23:27:26', 'group'),
(107, 'Gilbert', 'SASAS', 'Male', 'Resident', '0672454057', 'gilbertamani29@gmail.com', '7240 MOSHI', 'Moshi', 'Tanzania', NULL, NULL, NULL, 'SAFRI PARK', '7240 MOSHI', 'DOUBLE', 'GIRAFFE', '80000.00', '2026-01-14', '02:26:00', '2026-01-16', '10:00:00', NULL, NULL, NULL, 'Checked-In', 'No', '', '2026-01-13 23:27:26', 'group'),
(108, 'Lev', 'Watkins', 'Male', 'Resident', '+1 (537) 232-4393', 'boqag@mailinator.com', 'Quinn Hamilton Associates', 'In veniam officiis ', 'Expedita maiores cul', NULL, NULL, NULL, 'George Guthrie Co', 'Quinn Hamilton Associates', 'TRIPLE', 'ELEPHANT', '120000.00', '2006-07-09', '12:04:00', '1990-09-07', '06:35:00', NULL, NULL, NULL, 'Checked-In', 'No', '', '2026-01-14 15:03:56', 'group'),
(109, 'Lev', 'Watkins', 'Male', 'Resident', '+1 (537) 232-4393', 'boqag@mailinator.com', 'Quinn Hamilton Associates', 'In veniam officiis ', 'Expedita maiores cul', NULL, NULL, NULL, 'George Guthrie Co', 'Quinn Hamilton Associates', 'DOUBLE', 'CHEATER', '80000.00', '2006-07-09', '12:04:00', '1990-09-07', '06:35:00', NULL, NULL, NULL, 'Checked-In', 'No', '', '2026-01-14 15:03:56', 'group'),
(110, 'Lev', 'Watkins', 'Male', 'Resident', '+1 (537) 232-4393', 'boqag@mailinator.com', 'Quinn Hamilton Associates', 'In veniam officiis ', 'Expedita maiores cul', NULL, NULL, NULL, 'George Guthrie Co', 'Quinn Hamilton Associates', 'DOUBLE', 'ZEBRA', '80000.00', '2006-07-09', '12:04:00', '1990-09-07', '06:35:00', NULL, NULL, NULL, 'Checked-In', 'No', '', '2026-01-14 15:03:56', 'group');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` int(11) NOT NULL,
  `guest_id` int(11) NOT NULL,
  `guest_name` varchar(255) DEFAULT NULL,
  `room_id` int(11) DEFAULT NULL,
  `room_name` varchar(100) DEFAULT NULL,
  `room_type` varchar(100) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(50) NOT NULL COMMENT 'Cash, Credit Card, Mobile Payment',
  `payment_date` date NOT NULL,
  `checkin_date` datetime DEFAULT NULL,
  `checkout_date` datetime DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL COMMENT 'Transaction/Receipt number',
  `payment_status` text NOT NULL,
  `discount` decimal(10,2) DEFAULT 0.00,
  `extra_charges` decimal(10,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` varchar(20) NOT NULL DEFAULT 'Paid'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`payment_id`, `guest_id`, `guest_name`, `room_id`, `room_name`, `room_type`, `amount`, `payment_method`, `payment_date`, `checkin_date`, `checkout_date`, `reference_number`, `payment_status`, `discount`, `extra_charges`, `notes`, `created_at`, `updated_at`, `status`) VALUES
(46, 94, 'Gilbert BRIAN', 8, 'LION', NULL, '100000.00', 'Cash', '2026-01-13', NULL, NULL, '', '', '0.00', '0.00', '', '2026-01-13 20:44:37', '2026-01-13 20:44:37', 'Paid'),
(47, 96, 'Gilbert meme', 11, 'ZEBRA', NULL, '60000.00', 'Credit Card', '2026-01-13', NULL, NULL, '', '', '0.00', '0.00', '', '2026-01-13 20:46:21', '2026-01-13 20:47:31', 'Paid'),
(48, 96, 'Gilbert meme', 11, 'ZEBRA', NULL, '500000.00', 'Credit Card', '2026-01-13', NULL, NULL, '', '', '0.00', '0.00', '', '2026-01-13 20:47:31', '2026-01-13 20:47:31', 'Paid'),
(49, 91, 'Gilbert SAULO', 13, 'GIRAFFE', NULL, '160000.00', 'Mobile Payment', '2026-01-13', NULL, NULL, '', '', '0.00', '0.00', '', '2026-01-13 20:48:27', '2026-01-13 20:48:27', 'Paid'),
(50, 93, 'Gilbert BRIAN', 7, 'BUFFALO', NULL, '160000.00', 'Cash', '2026-01-13', NULL, NULL, '', '', '5000.00', '70000.00', '[Extra Charge: Drinks]', '2026-01-13 21:03:23', '2026-01-13 21:03:23', 'Paid'),
(51, 97, 'Gilbert WEWEEE', 16, 'TOUR GUIDES ROOM 1', NULL, '300000.00', 'Cash', '2026-01-14', NULL, NULL, '', '', '0.00', '0.00', '', '2026-01-13 22:28:40', '2026-01-13 22:28:40', 'Paid'),
(52, 92, 'Gilbert BRIAN', 6, 'RHINO', NULL, '480000.00', 'Cash', '2026-01-14', NULL, NULL, '', '', '0.00', '0.00', '', '2026-01-13 22:41:29', '2026-01-13 22:41:29', 'Paid'),
(53, 99, 'SELEMANI SAULO', 12, 'FLAMINGO', NULL, '480000.00', 'Cash', '2026-01-14', NULL, NULL, '', '', '0.00', '60000.00', '[Extra Charge: Drinks]', '2026-01-13 23:02:39', '2026-01-13 23:02:39', 'Paid'),
(54, 103, 'Gilbert SASAS', 9, 'ELEPHANT', NULL, '240000.00', 'Cash', '2026-01-14', NULL, NULL, '', '', '0.00', '0.00', '', '2026-01-13 23:36:48', '2026-01-13 23:36:48', 'Paid'),
(55, 103, 'Gilbert SASAS', 9, 'ELEPHANT', NULL, '120000.00', 'Cash', '2026-01-14', NULL, NULL, '', '', '0.00', '0.00', '', '2026-01-13 23:38:10', '2026-01-13 23:38:10', 'Paid'),
(56, 107, 'Gilbert SASAS', 13, 'GIRAFFE', NULL, '160000.00', 'Credit Card', '2026-01-14', NULL, NULL, '', '', '0.00', '0.00', '', '2026-01-13 23:38:46', '2026-01-13 23:38:46', 'Paid'),
(57, 107, 'Gilbert SASAS', 13, 'GIRAFFE', NULL, '0.00', 'Credit Card', '2026-01-14', NULL, NULL, '', '', '0.00', '0.00', '', '2026-01-13 23:39:19', '2026-01-13 23:39:19', 'Paid'),
(58, 106, 'Gilbert SASAS', 12, 'FLAMINGO', NULL, '80000.00', 'Cash', '2026-01-14', NULL, NULL, '', '', '0.00', '0.00', '', '2026-01-13 23:40:34', '2026-01-13 23:40:34', 'Paid'),
(59, 102, 'Gilbert SASAS', 8, 'LION', NULL, '200000.00', 'Mobile Payment', '2026-01-14', NULL, NULL, '', '', '0.00', '0.00', '', '2026-01-13 23:41:00', '2026-01-13 23:41:00', 'Paid'),
(60, 102, 'Gilbert SASAS', 8, 'LION', NULL, '0.00', 'Mobile Payment', '2026-01-14', NULL, NULL, '', '', '0.00', '0.00', '', '2026-01-13 23:41:30', '2026-01-13 23:41:30', 'Paid'),
(61, 101, 'Gilbert SASAS', 7, 'BUFFALO', NULL, '320000.00', 'Credit Card', '2026-01-14', NULL, NULL, '', '', '5757.00', '545546.00', '[Extra Charge: Drinks]', '2026-01-14 15:06:03', '2026-01-14 15:06:03', 'Paid');

-- --------------------------------------------------------

--
-- Table structure for table `rooms`
--

CREATE TABLE `rooms` (
  `room_id` int(11) NOT NULL,
  `room_name` varchar(100) NOT NULL,
  `room_type` varchar(50) NOT NULL,
  `room_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('Available','Occupied','Reserved','Under Maintenance') NOT NULL DEFAULT 'Available',
  `created_by` enum('admin','receptionist') NOT NULL DEFAULT 'admin',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `rooms`
--

INSERT INTO `rooms` (`room_id`, `room_name`, `room_type`, `room_rate`, `status`, `created_by`, `created_at`, `updated_at`) VALUES
(6, 'RHINO', 'TWIN', '160000.00', 'Occupied', 'admin', '2025-10-11 11:30:20', '2026-01-13 23:27:26'),
(7, 'BUFFALO', 'TWIN', '160000.00', 'Occupied', 'admin', '2025-10-11 11:31:49', '2026-01-13 23:27:26'),
(8, 'LION', 'TWIN', '100000.00', 'Available', 'admin', '2025-10-11 11:32:09', '2026-01-13 23:41:58'),
(9, 'ELEPHANT', 'TRIPLE', '120000.00', 'Occupied', 'admin', '2025-10-11 11:32:29', '2026-01-14 15:03:56'),
(10, 'CHEATER', 'DOUBLE', '80000.00', 'Occupied', 'admin', '2025-10-11 11:36:02', '2026-01-14 15:03:56'),
(11, 'ZEBRA', 'DOUBLE', '80000.00', 'Occupied', 'admin', '2025-10-11 11:36:49', '2026-01-14 15:03:56'),
(12, 'FLAMINGO', 'DOUBLE', '80000.00', 'Occupied', 'admin', '2025-10-11 11:37:10', '2026-01-13 23:27:26'),
(13, 'GIRAFFE', 'DOUBLE', '80000.00', 'Occupied', 'admin', '2025-10-11 11:37:28', '2026-01-13 23:27:26'),
(14, 'OSTRICH', 'DOUBLE', '80000.00', 'Available', 'admin', '2025-10-11 11:38:09', '2026-01-13 20:16:20'),
(15, 'LEOPARD', 'DOUBLE', '80000.00', 'Available', 'admin', '2025-10-11 11:38:40', '2026-01-08 12:47:03'),
(16, 'TOUR GUIDES ROOM 1', 'DOUBLE', '50000.00', 'Available', 'admin', '2025-10-11 11:39:12', '2026-01-13 22:44:41'),
(18, 'TOUR GUIDES ROOM 2', 'DOUBLE', '50000.00', 'Available', 'admin', '2025-10-17 21:24:40', '2026-01-13 21:09:56');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `fullname` varchar(100) NOT NULL,
  `role` enum('manager','receptionist') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `fullname`, `role`) VALUES
(9, '111', '123', 'System Admin', 'manager'),
(13, '555', '$2y$10$F/7Za1y0EoZAu8WM6tg1FOAIyYUvcks0VOv2gg1.wW.SFvsmWjN3q', 'Abdallah Kiuno', 'receptionist'),
(14, 'GILBERT', '$2y$10$HvwvYB6GqIRt2WffKhJxd.k4wAsaddJT28GEIa6dRqs7yP3IACA/O', 'Gilbert', 'manager'),
(15, '222', '$2y$10$S6DuGLAhP8y1vDTO63R9ZeDmnZvvtxqL3Ml3X3Nvh6QKqT5ryM5km', 'MWAJUMA', 'receptionist');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`log_id`);

--
-- Indexes for table `checkin_checkout`
--
ALTER TABLE `checkin_checkout`
  ADD PRIMARY KEY (`checkin_id`),
  ADD UNIQUE KEY `unique_guest` (`guest_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_checkin_time` (`checkin_time`);

--
-- Indexes for table `guest`
--
ALTER TABLE `guest`
  ADD PRIMARY KEY (`guest_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `guest_id` (`guest_id`),
  ADD KEY `room_id` (`room_id`),
  ADD KEY `payment_date` (`payment_date`),
  ADD KEY `payment_status` (`payment_status`(768));

--
-- Indexes for table `rooms`
--
ALTER TABLE `rooms`
  ADD PRIMARY KEY (`room_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=63;

--
-- AUTO_INCREMENT for table `checkin_checkout`
--
ALTER TABLE `checkin_checkout`
  MODIFY `checkin_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=78;

--
-- AUTO_INCREMENT for table `guest`
--
ALTER TABLE `guest`
  MODIFY `guest_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=111;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=62;

--
-- AUTO_INCREMENT for table `rooms`
--
ALTER TABLE `rooms`
  MODIFY `room_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `checkin_checkout`
--
ALTER TABLE `checkin_checkout`
  ADD CONSTRAINT `checkin_checkout_ibfk_1` FOREIGN KEY (`guest_id`) REFERENCES `guest` (`guest_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`room_id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
