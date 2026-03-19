-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 19, 2026 at 01:42 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `jd_water_station`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin_users`
--

CREATE TABLE `admin_users` (
  `id` int(11) NOT NULL,
  `email` varchar(120) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'admin',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `admin_users`
--

INSERT INTO `admin_users` (`id`, `email`, `password`, `full_name`, `role`, `is_active`, `last_login`, `created_at`, `updated_at`) VALUES
(1, 'adminjd@gmail.com', '*2470C0C06DEE42FD1618BB99005ADCA2EC9D1E19', 'Admin JD', 'super_admin', 1, '2026-03-19 11:11:02', '2026-03-10 03:20:15', '2026-03-19 11:11:02');

-- --------------------------------------------------------

--
-- Table structure for table `inventory_categories`
--

CREATE TABLE `inventory_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(120) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `inventory_categories`
--

INSERT INTO `inventory_categories` (`id`, `name`, `created_at`) VALUES
(4, 'Faucet Seal', '2026-03-10 05:42:23'),
(5, 'Big Mouth', '2026-03-10 05:58:13'),
(6, 'Small Seal', '2026-03-10 06:21:09'),
(7, 'Umbrella', '2026-03-10 06:29:53'),
(8, 'Filters', '2026-03-10 06:57:17'),
(9, 'Cap', '2026-03-10 07:45:35'),
(10, 'Filter Housing', '2026-03-10 07:46:18'),
(11, 'Gallons', '2026-03-10 07:49:12'),
(12, 'Label', '2026-03-10 07:58:37'),
(13, 'Cap Cover', '2026-03-10 08:01:33'),
(14, 'Faucet', '2026-03-10 08:08:12'),
(15, 'Fittings', '2026-03-10 08:12:34'),
(16, 'Others', '2026-03-10 08:40:11');

-- --------------------------------------------------------

--
-- Table structure for table `inventory_restock_logs`
--

CREATE TABLE `inventory_restock_logs` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `added_store` int(11) NOT NULL DEFAULT 0,
  `added_stockroom` int(11) NOT NULL DEFAULT 0,
  `notes` varchar(255) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `inventory_restock_logs`
--

INSERT INTO `inventory_restock_logs` (`id`, `product_id`, `added_store`, `added_stockroom`, `notes`, `created_at`) VALUES
(1, 110, 1, 0, '', '2026-03-10 09:06:25');

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `product_name` varchar(150) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `color_specs` varchar(255) NOT NULL,
  `brand` varchar(120) NOT NULL,
  `stock_store` int(11) NOT NULL DEFAULT 0,
  `stock_stockroom` int(11) NOT NULL DEFAULT 0,
  `cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `supplier` varchar(150) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `product_name`, `category_id`, `color_specs`, `brand`, `stock_store`, `stock_stockroom`, `cost`, `price`, `supplier`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Faucet Seal', 4, 'Blue', 'Royal Seal', 115, 159, 52.50, 75.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 05:42:23', '2026-03-19 11:21:52'),
(2, 'Faucet Seal', 4, 'White', 'Royal Seal', 103, 0, 52.50, 75.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 05:42:54', '2026-03-16 11:53:36'),
(3, 'Faucet Seal', 4, 'Violet', 'Royal Seal', 131, 0, 52.50, 75.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 05:43:30', '2026-03-10 09:13:24'),
(4, 'Faucet Seal', 4, 'Green', 'Royal Seal', 47, 0, 52.50, 75.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 05:44:02', '2026-03-10 05:44:02'),
(5, 'Faucet Seal', 4, 'Orange', 'Royal Seal', 45, 0, 52.50, 75.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 05:44:51', '2026-03-10 05:44:51'),
(6, 'Faucet Seal', 4, 'Red', 'Royal Seal', 64, 10, 52.50, 75.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 05:45:46', '2026-03-10 05:45:46'),
(7, 'Faucet Seal', 4, 'Yellow', 'Royal Seal', 43, 15, 52.50, 75.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 05:46:30', '2026-03-10 05:46:30'),
(8, 'Faucet Seal', 4, 'Pink', 'Royal Seal', 0, 5, 52.50, 75.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 05:47:16', '2026-03-10 05:47:16'),
(9, 'Faucet Seal', 4, 'NEW', 'WAVE', 3, 0, 70.00, 80.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 05:57:07', '2026-03-10 05:57:07'),
(10, 'Big Mouth Seal', 5, 'Blue', 'Royal Seal', 48, 101, 100.00, 120.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 05:58:13', '2026-03-16 12:51:54'),
(11, 'Big Mouth Seal', 5, 'White', 'Royal Seal', 51, 18, 100.00, 120.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 05:58:57', '2026-03-16 12:50:50'),
(12, 'Big Mouth Seal', 5, 'Violet', 'Royal Seal', 42, 18, 100.00, 120.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 05:59:44', '2026-03-16 13:15:34'),
(13, 'Big Mouth Seal', 5, 'Green', 'Royal Seal', 15, 10, 100.00, 120.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 06:04:36', '2026-03-16 12:51:54'),
(14, 'Big Mouth Seal', 5, 'Orange', 'Royal Seal', 6, 15, 100.00, 120.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 06:05:09', '2026-03-16 12:51:54'),
(15, 'Big Mouth Seal', 5, 'Red', 'Royal Seal', 41, 30, 100.00, 120.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 06:07:41', '2026-03-16 12:51:54'),
(16, 'Big Mouth Seal', 5, 'Yellow', 'Royal Seal', 16, 33, 100.00, 120.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 06:09:28', '2026-03-10 06:09:28'),
(17, 'Big Mouth Seal', 5, 'Pink', 'Royal Seal', 2, 0, 100.00, 120.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 06:10:02', '2026-03-10 06:10:02'),
(18, 'Big Mouth Seal', 5, 'NEW', 'WAVE', 5, 0, 115.00, 125.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 06:16:05', '2026-03-10 06:16:25'),
(19, 'Small Seal', 6, 'White', 'Royal Seal', 70, 12, 40.00, 50.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 06:21:09', '2026-03-10 06:46:00'),
(20, 'Small Seal', 6, 'Blue', 'Royal Seal', 70, 36, 40.00, 50.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 06:21:47', '2026-03-10 06:21:47'),
(21, 'Small Seal', 6, 'Violet', 'Royal Seal', 64, 25, 40.00, 50.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 06:23:01', '2026-03-10 06:23:01'),
(22, 'Small Seal', 6, 'Green', 'Royal Seal', 10, 10, 40.00, 50.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 06:23:48', '2026-03-10 06:23:48'),
(23, 'Small Seal', 6, 'Orange', 'Royal Seal', 13, 10, 40.00, 50.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 06:24:45', '2026-03-10 06:24:45'),
(24, 'Small Seal', 6, 'Red', 'Royal Seal', 70, 17, 40.00, 50.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 06:25:15', '2026-03-10 06:25:15'),
(25, 'Small Seal', 6, 'Yellow', 'Royal Seal', 11, 10, 40.00, 50.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 06:25:51', '2026-03-10 06:25:51'),
(26, 'Small Seal', 6, 'Pink', 'Royal Seal', 10, 0, 40.00, 50.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 06:27:07', '2026-03-10 06:27:07'),
(27, 'Small Seal', 6, 'NEW', 'WAVE', 0, 0, 45.00, 55.00, 'JHE - ANN WATER SUPPLIES', 0, '2026-03-10 06:27:42', '2026-03-10 06:46:56'),
(28, 'Small Seal', 6, 'Apple Green', 'Royal Seal', 0, 1, 40.00, 50.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 06:28:53', '2026-03-10 06:28:53'),
(29, 'Umbrella Seal', 7, 'Blue', 'Royal Seal', 10, 35, 105.00, 130.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 06:29:53', '2026-03-10 06:29:53'),
(30, 'Umbrella Seal', 7, 'White', 'Royal Seal', 9, 12, 105.00, 130.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 06:30:32', '2026-03-10 06:52:24'),
(31, 'Umbrella Seal', 7, 'Violet', 'Royal Seal', 9, 8, 105.00, 130.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 06:31:08', '2026-03-10 06:31:08'),
(32, 'Umbrella Seal', 7, 'Green', 'Royal Seal', 9, 4, 105.00, 130.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 06:32:01', '2026-03-10 06:32:01'),
(33, 'Umbrella Seal', 7, 'Orange', 'Royal Seal', 5, 0, 105.00, 130.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 06:32:33', '2026-03-10 06:32:33'),
(34, 'Umbrella Seal', 7, 'Red', 'Royal Seal', 9, 7, 105.00, 130.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 06:33:22', '2026-03-10 06:33:22'),
(35, 'Umbrella Seal', 7, 'Yellow', 'Royal Seal', 9, 6, 105.00, 130.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 06:33:59', '2026-03-10 06:47:49'),
(36, 'Umbrella Seal', 6, 'Pink', 'Royal Seal', 3, 0, 105.00, 130.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 06:34:28', '2026-03-10 06:48:26'),
(37, 'Micron  1', 8, 'SL 20', 'Hydrosep', 25, 14, 65.00, 100.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 06:57:17', '2026-03-10 07:29:12'),
(38, 'Micron 5', 8, 'SL 20', 'Hydrosep', 25, 24, 65.00, 100.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 06:59:01', '2026-03-10 07:28:58'),
(39, 'Micron 10', 8, 'SL 20', 'Hydrosep', 25, 16, 65.00, 100.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 06:59:33', '2026-03-10 07:29:36'),
(40, 'Micron 20', 8, 'SL 20', 'Hydrosep', 25, 12, 65.00, 100.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 07:00:23', '2026-03-10 07:27:46'),
(41, 'Micron  1', 8, 'SL 10', 'Hydrosep', 2, 0, 31.00, 75.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 07:20:16', '2026-03-10 07:28:02'),
(42, 'Micron 5', 8, 'SL 10', 'Hydrosep', 1, 0, 31.00, 75.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 07:20:46', '2026-03-10 07:30:00'),
(43, 'Micron 10', 8, 'SL 10', 'Hydrosep', 2, 0, 31.00, 75.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 07:21:28', '2026-03-10 07:29:23'),
(44, 'Micron 20', 8, 'SL 20', 'Hydrosep', 0, 0, 31.00, 75.00, 'JHE - ANN WATER SUPPLIES', 0, '2026-03-10 07:21:56', '2026-03-10 07:29:48'),
(45, 'Carbon', 8, 'SL 20 Green', 'Cocopure', 4, 7, 350.00, 650.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 07:23:37', '2026-03-10 07:23:37'),
(46, 'Carbon', 8, 'SL 20 Brown', 'Hydrosep', 7, 6, 350.00, 700.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 07:24:15', '2026-03-10 07:24:15'),
(47, 'Pentech', 8, 'SL 20 White', 'Hydrosep', 0, 0, 450.00, 850.00, 'JHE - ANN WATER SUPPLIES', 0, '2026-03-10 07:25:19', '2026-03-10 07:25:19'),
(48, 'Carbon', 8, 'SL 10 Green', 'Cocopure', 0, 0, 208.00, 550.00, 'JHE - ANN WATER SUPPLIES', 0, '2026-03-10 07:26:02', '2026-03-10 07:26:02'),
(49, 'Carbon', 8, 'SL 10 Brown', 'Hydrosep', 2, 0, 205.00, 550.00, 'JHE - ANN WATER SUPPLIES', 1, '2026-03-10 07:26:46', '2026-03-10 07:26:46'),
(50, 'Membrane', 8, '40x40 ULP11', 'VONTRON', 0, 0, 4700.00, 7500.00, 'WaterHealth', 0, '2026-03-10 07:43:51', '2026-03-10 07:43:51'),
(51, 'Membrane', 8, '40x40 ULP21', 'VONTRON', 2, 0, 4700.00, 7500.00, 'WaterHealth', 1, '2026-03-10 07:44:44', '2026-03-10 07:44:44'),
(52, 'NCAP', 9, 'BLACK', 'RO VESSEL', 1, 0, 250.00, 650.00, 'WaterHealth', 1, '2026-03-10 07:45:35', '2026-03-10 07:45:35'),
(53, 'Blue Housing', 10, 'SL 10', 'Elephant', 0, 0, 280.00, 850.00, 'WaterHealth', 0, '2026-03-10 07:46:18', '2026-03-10 07:46:18'),
(54, 'Blue Housing', 10, 'SL 20', 'Elephant', 2, 0, 450.00, 1200.00, 'WaterHealth', 1, '2026-03-10 07:46:56', '2026-03-10 07:46:56'),
(55, 'Slim Gallon Pull up', 11, 'Blue', 'PMC', 82, 0, 115.00, 130.00, 'ECOPURE', 1, '2026-03-10 07:49:12', '2026-03-10 07:49:12'),
(56, 'Slim Gallon Pull up', 11, 'Orange', 'PMC', 1, 31, 115.00, 130.00, 'ECOPURE', 1, '2026-03-10 07:49:59', '2026-03-10 07:49:59'),
(57, 'Slim Gallon Pull up', 11, 'Green', 'PMC', 30, 85, 115.00, 130.00, 'ECOPURE', 1, '2026-03-10 07:50:35', '2026-03-10 07:50:35'),
(58, 'Slim Gallon Pull up', 11, 'Yellow', 'PMC', 1, 44, 115.00, 130.00, 'ECOPURE', 1, '2026-03-10 07:51:10', '2026-03-10 07:51:10'),
(59, 'Slim Gallon Rotary', 11, 'Blue', 'PMC', 0, 30, 115.00, 130.00, 'ECOPURE', 1, '2026-03-10 07:52:29', '2026-03-10 07:52:29'),
(60, 'Round Gallon', 11, 'Blue', 'PMC', 0, 44, 1155.00, 130.00, 'ECOPURE', 1, '2026-03-10 07:53:16', '2026-03-10 07:53:16'),
(61, 'Round Gallon', 11, 'Orange', 'PMC', 0, 10, 115.00, 130.00, 'ECOPURE', 1, '2026-03-10 07:53:50', '2026-03-10 07:53:50'),
(62, 'Round Gallon', 11, 'Green', 'PMC', 0, 2, 115.00, 130.00, 'ECOPURE', 1, '2026-03-10 07:54:30', '2026-03-10 07:54:30'),
(63, 'Pet Bottle', 11, '350ml', 'Fresh', 0, 2, 657.00, 750.00, 'ECOPURE', 1, '2026-03-10 07:55:51', '2026-03-10 07:55:51'),
(64, 'Pet Bottle', 11, '500ml', 'Fresh', 1, 0, 560.00, 650.00, 'ECOPURE', 1, '2026-03-10 07:56:28', '2026-03-10 07:56:28'),
(65, 'Pet Bottle', 11, '1 Liter', 'Fresh', 2, 0, 552.00, 65.00, 'ECOPURE', 1, '2026-03-10 07:57:21', '2026-03-10 07:57:21'),
(66, 'Label', 12, '350ml', 'Fresh', 0, 1828, 0.38, 0.55, 'ECOPURE', 1, '2026-03-10 07:58:37', '2026-03-10 07:58:37'),
(67, 'Label', 12, '500ml', 'Fresh', 0, 752, 0.45, 0.60, 'ECOPURE', 1, '2026-03-10 07:59:23', '2026-03-10 07:59:23'),
(68, 'Label', 12, '1 Liter', 'Fresh', 0, 1888, 0.50, 0.70, 'ECOPURE', 1, '2026-03-10 08:00:08', '2026-03-10 08:00:08'),
(69, 'Small cap Cover', 13, 'Blue', 'PMC', 273, 1881, 1.00, 10.00, 'ECOPURE', 1, '2026-03-10 08:01:33', '2026-03-10 08:01:33'),
(70, 'Small cap Cover', 13, 'Orange', 'PMC', 15, 0, 1.00, 10.00, 'ECOPURE', 1, '2026-03-10 08:02:11', '2026-03-10 08:02:11'),
(71, 'Small cap Cover', 13, 'Green', 'PMC', 29, 0, 1.00, 10.00, 'ECOPURE', 1, '2026-03-10 08:02:42', '2026-03-10 08:02:42'),
(72, 'Small cap Cover', 13, 'Yellow', 'PMC', 36, 0, 1.00, 10.00, 'ECOPURE', 1, '2026-03-10 08:03:25', '2026-03-10 08:03:25'),
(73, 'Big cap Cover', 13, 'Blue', 'PMC', 28, 10, 11.00, 20.00, 'ECOPURE', 1, '2026-03-10 08:04:27', '2026-03-18 03:32:46'),
(74, 'Big cap Cover', 13, 'Orange', 'PMC', 5, 61, 11.00, 20.00, 'ECOPURE', 1, '2026-03-10 08:04:54', '2026-03-16 12:51:10'),
(75, 'Big cap Cover', 13, 'Green', 'PMC', 12, 27, 11.00, 20.00, 'ECOPURE', 1, '2026-03-10 08:05:20', '2026-03-10 08:05:20'),
(76, 'Big cap Cover', 13, 'Yellow', 'PMC', 35, 49, 11.00, 20.00, 'ECOPURE', 1, '2026-03-10 08:05:43', '2026-03-10 08:05:43'),
(77, 'Inner Plate', 13, 'White', 'PMC', 141, 493, 4.00, 10.00, 'ECOPURE', 1, '2026-03-10 08:06:24', '2026-03-10 08:06:24'),
(78, 'Nonspill Cover', 13, 'Blue', 'PMC', 535, 0, 1.00, 5.00, 'ECOPURE', 1, '2026-03-10 08:07:13', '2026-03-10 08:07:13'),
(79, 'Slim Faucet', 14, 'Pullup  Blue', 'PMC', 71, 50, 6.00, 30.00, 'ECOPURE', 1, '2026-03-10 08:08:12', '2026-03-10 08:08:12'),
(80, 'Slim Faucet', 14, 'Rotary Green/Red', 'PMC', 75, 225, 6.00, 30.00, 'ECOPURE', 1, '2026-03-10 08:09:02', '2026-03-10 08:09:02'),
(81, 'Dispenser Faucet', 14, 'Blue', 'PMC', 5, 75, 6.00, 85.00, 'ECOPURE', 1, '2026-03-10 08:09:33', '2026-03-10 08:09:33'),
(82, 'Dispenser Faucet', 14, 'Red', 'PMC', 4, 42, 6.00, 85.00, 'ECOPURE', 1, '2026-03-10 08:10:06', '2026-03-10 08:10:06'),
(83, 'Elbow', 15, '1/2', 'N/A', 114, 0, 15.00, 25.00, 'N/A', 1, '2026-03-10 08:12:34', '2026-03-10 08:12:34'),
(84, 'Elbow', 15, '3/4', 'N/A', 40, 0, 25.00, 35.00, 'N/A', 1, '2026-03-10 08:13:09', '2026-03-10 08:13:09'),
(85, 'Elbow', 15, '1', 'N/A', 24, 0, 35.00, 45.00, 'N/A', 1, '2026-03-10 08:20:34', '2026-03-10 08:20:34'),
(86, 'T', 15, '1/2', 'N/A', 11, 0, 35.00, 55.00, 'N/A', 1, '2026-03-10 08:21:18', '2026-03-10 08:21:18'),
(87, 'T', 15, '3/4', 'N/A', 6, 0, 55.00, 65.00, 'N/A', 1, '2026-03-10 08:21:58', '2026-03-10 08:21:58'),
(88, 'T', 15, '1', 'N/A', 5, 0, 65.00, 75.00, 'N/A', 1, '2026-03-10 08:23:14', '2026-03-10 08:23:14'),
(89, 'Female Adaptor', 15, '1/2', 'N/A', 7, 0, 35.00, 45.00, 'N/A', 1, '2026-03-10 08:24:46', '2026-03-10 08:24:46'),
(90, 'Female Adaptor', 15, '3/4', 'N/A', 4, 0, 55.00, 65.00, 'N/A', 1, '2026-03-10 08:25:15', '2026-03-10 08:25:15'),
(91, 'Female Adaptor', 15, '1', 'N/A', 8, 0, 65.00, 75.00, 'N/A', 1, '2026-03-10 08:25:46', '2026-03-10 08:25:46'),
(92, 'Male Adaptor', 15, '1/2', 'N/A', 9, 0, 35.00, 45.00, 'N/A', 1, '2026-03-10 08:26:32', '2026-03-10 08:26:32'),
(93, 'Male Adaptor', 15, '3/4', 'N/A', 5, 0, 55.00, 65.00, 'N/A', 1, '2026-03-10 08:27:02', '2026-03-10 08:27:02'),
(94, 'Male Adaptor', 15, '1', 'N/A', 8, 0, 65.00, 75.00, 'N/A', 1, '2026-03-10 08:27:38', '2026-03-10 08:27:38'),
(95, 'Patente', 15, '3/4', 'N/A', 4, 0, 35.00, 65.00, 'N/A', 1, '2026-03-10 08:28:54', '2026-03-10 08:28:54'),
(96, 'Reducer', 15, '1 3/4', 'N/A', 2, 0, 35.00, 65.00, 'N/A', 1, '2026-03-10 08:30:03', '2026-03-10 08:30:03'),
(97, 'Cupling', 15, '1/2', 'N/A', 2, 0, 15.00, 25.00, 'N/A', 1, '2026-03-10 08:30:33', '2026-03-10 08:30:33'),
(98, 'Inside Reducer', 15, '1/2', 'N/A', 2, 0, 15.00, 25.00, 'N/A', 1, '2026-03-10 08:31:05', '2026-03-10 08:31:05'),
(99, 'Inside Reducer', 15, '3/4', 'N/A', 7, 0, 25.00, 35.00, 'N/A', 1, '2026-03-10 08:31:36', '2026-03-10 08:31:36'),
(100, 'Teflon', 15, '1/2', 'N/A', 12, 0, 25.00, 35.00, 'N/A', 1, '2026-03-10 08:32:45', '2026-03-10 08:32:45'),
(101, 'Apron', 16, 'Blue', 'N/A', 0, 0, 85.00, 210.00, 'N/A', 1, '2026-03-10 08:40:11', '2026-03-18 03:30:15'),
(102, 'Pipe Cutter', 16, 'Orange', 'N/A', 1, 0, 350.00, 750.00, 'N/A', 1, '2026-03-10 08:40:44', '2026-03-10 08:40:44'),
(103, 'Blue Housing Oppener', 16, 'Reguklar', 'N/A', 3, 0, 65.00, 95.00, 'N/A', 1, '2026-03-10 08:41:23', '2026-03-10 08:41:23'),
(104, 'Blue Housing Oppener', 16, 'Heavyduty', 'N/A', 3, 0, 75.00, 150.00, 'N/A', 1, '2026-03-10 08:42:02', '2026-03-10 08:42:02'),
(105, 'Float Ball', 16, '1/2', 'Float Ball', 1, 0, 248.00, 850.00, 'N/A', 1, '2026-03-10 08:43:14', '2026-03-10 08:43:14'),
(106, 'Jacko Fittings', 15, '1/2', 'N/A', 6, 0, 65.00, 130.00, 'N/A', 1, '2026-03-10 08:44:07', '2026-03-10 08:44:07'),
(107, 'Masterchef Salt', 16, '50 kg', 'Masterchef', 30, 0, 310.00, 445.00, 'WaterHealth', 1, '2026-03-10 08:47:17', '2026-03-10 08:47:17'),
(108, 'Clara Vida', 16, 'Bag  12.5kg', 'Clara Vida', 0, 0, 165.00, 205.00, 'WaterHealth', 0, '2026-03-10 08:48:26', '2026-03-10 08:48:26'),
(109, 'Automatic Pump Control - Round', 16, 'Blue', 'Tinanium', 0, 0, 850.00, 2200.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-15 00:38:07'),
(110, 'Automatic Pump Control - Square', 16, 'Blue', 'Tinanium', 1, 0, 900.00, 2500.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-10 09:06:25'),
(111, 'Manual Head - Carbon Head', 16, 'Silver', 'Royo', 1, 0, 700.00, 1800.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-10 08:56:28'),
(112, 'Manual Head - Softener Head', 16, 'Silver', 'Royo', 1, 0, 900.00, 2200.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-10 08:56:28'),
(113, 'Radar Switch - Control Switch', 16, 'Yellow', 'HydroPure', 1, 0, 262.00, 850.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-10 08:56:28'),
(114, 'Float Switch - Fluid Lever Control', 16, 'Yellow/Blue', 'Float Switch', 1, 0, 113.00, 950.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-10 08:56:28'),
(115, 'Pedal Switch - Small', 16, 'Brown', 'Treddle Switch', 1, 0, 233.00, 950.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-10 08:56:28'),
(116, 'Low Pressure Switch', 16, 'White', 'HydroPure', 1, 0, 350.00, 950.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-10 08:56:28'),
(117, 'Solenoid Valve 1/2', 16, '', 'Hydro Pure', 1, 0, 800.00, 1500.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-10 08:56:28'),
(118, 'Solenoid Valve 3/4', 16, '', 'Oceanic Pure', 2, 0, 950.00, 2200.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-10 08:56:28'),
(119, 'Check Valve 1', 16, '', 'Clayton Mark', 1, 0, 250.00, 550.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-10 08:56:28'),
(120, 'Ball Valve 1/2', 16, '', 'Ball Valve', 3, 0, 185.00, 350.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-18 03:32:46'),
(121, 'Flow Meter With Regulator', 16, 'Clear', 'WaterMaster', 1, 0, 850.00, 1850.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-10 08:56:28'),
(122, 'Flow Meter Without Regulator', 16, 'Clear', 'WaterMaster', 1, 0, 550.00, 1250.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-10 08:56:28'),
(123, 'UV Ballast 6gpm', 16, 'Black', 'UV', 1, 0, 588.00, 2500.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-10 08:56:28'),
(124, 'UV Lamp 6gpm Short', 16, 'Clear', 'UV Light', 0, 0, 558.00, 1800.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-10 08:56:28'),
(125, 'UV Lamp 6gpm Long', 16, 'Clear', 'UV Light', 2, 0, 745.00, 2200.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-10 08:56:28'),
(126, 'Pressure Gauge 100psi', 16, '', 'Oceanic Pure', 2, 0, 350.00, 950.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-10 08:56:28'),
(127, 'Pressure Gauge 150psi', 16, '', 'Oceanic Pure', 1, 0, 450.00, 1100.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-10 08:56:28'),
(128, 'TDS Meter', 16, 'White', 'TDS', 1, 0, 185.00, 450.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-10 08:56:28'),
(129, 'Gallon Plastic Cover', 16, 'Clear', '', 15, 0, 87.00, 110.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-10 08:56:28'),
(130, 'Shrinkable Plastic', 16, 'Clear', '', 9, 0, 25.00, 55.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-10 08:56:28'),
(131, 'Heatgun', 16, 'Red', 'TGK', 2, 0, 588.00, 950.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-10 08:56:28'),
(132, 'Gallon Brush', 16, 'Brown/White', '', 3, 0, 57.00, 150.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-10 08:56:28'),
(133, 'Gallon Holder', 16, 'Green', '', 2, 0, 55.00, 150.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-10 08:56:28'),
(134, 'Liquid Soap', 16, 'Clear', '', 7, 0, 70.00, 150.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-10 08:56:28'),
(135, 'Pressure Hose 6mm', 16, 'Blue', '', 3, 0, 37.00, 95.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-10 08:56:28'),
(136, 'Pressure Hose 8mm', 16, 'Blue', '', 5, 0, 45.00, 120.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-10 08:56:28'),
(137, 'Pressure Hose 10mm', 16, 'Blue', '', 6, 0, 55.00, 140.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-10 08:56:28'),
(138, 'Pressure Hose 12mm', 16, 'Blue', '', 7, 0, 65.00, 150.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-10 08:56:28'),
(139, 'Lower Strainer Regular', 16, 'Grey', '', 1, 0, 95.00, 550.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-10 08:56:28'),
(140, 'Upper Strainer Regular', 16, 'Grey', '', 6, 0, 85.00, 350.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-10 08:56:28'),
(141, 'Upper Strainer Heavy Duty', 16, 'White', '', 4, 0, 250.00, 750.00, 'WaterHealth', 1, '2026-03-10 08:56:28', '2026-03-10 08:56:28');

-- --------------------------------------------------------

--
-- Table structure for table `reports`
--

CREATE TABLE `reports` (
  `id` int(11) NOT NULL,
  `report_code` varchar(60) NOT NULL,
  `report_type` varchar(60) NOT NULL DEFAULT 'Sales',
  `range_key` varchar(40) NOT NULL,
  `range_label` varchar(120) NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'Completed',
  `total_sales` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_orders` int(11) NOT NULL DEFAULT 0,
  `total_customers` int(11) NOT NULL DEFAULT 0,
  `average_order_value` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` varchar(255) NOT NULL DEFAULT '',
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `reports`
--

INSERT INTO `reports` (`id`, `report_code`, `report_type`, `range_key`, `range_label`, `start_date`, `end_date`, `status`, `total_sales`, `total_orders`, `total_customers`, `average_order_value`, `notes`, `generated_at`) VALUES
(1, 'RPT-20260310-101216-466', 'Sales', 'today', 'Today', '2026-03-10', '2026-03-10', 'Completed', 1618.00, 1, 1, 1618.00, 'March 10 reports', '2026-03-10 09:12:16'),
(2, 'RPT-20260310-101346-724', 'Sales', 'today', 'Today', '2026-03-10', '2026-03-10', 'Completed', 1685.50, 2, 2, 842.75, 'dasd', '2026-03-10 09:13:46'),
(3, 'RPT-20260316-141636-272', 'Sales', 'today', 'Today', '2026-03-16', '2026-03-16', 'Completed', 205.00, 4, 4, 51.25, 'march 16 report', '2026-03-16 13:16:36'),
(4, 'RPT-20260319-121551-290', 'Sales', 'today', 'Today', '2026-03-19', '2026-03-19', 'Completed', 112.50, 1, 1, 112.50, 'march 19 report', '2026-03-19 11:15:51'),
(5, 'RPT-20260319-123101-807', 'Sales', 'custom', 'Custom Range', '2026-03-18', '2026-03-19', 'Completed', 689.00, 4, 3, 172.25, 'march 19 report', '2026-03-19 11:31:01');

-- --------------------------------------------------------

--
-- Table structure for table `sales_orders`
--

CREATE TABLE `sales_orders` (
  `id` int(11) NOT NULL,
  `receipt_no` varchar(50) NOT NULL,
  `customer_name` varchar(150) NOT NULL DEFAULT 'Walk-in Customer',
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tax_rate` decimal(5,2) NOT NULL DEFAULT 12.00,
  `tax_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `change_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sales_orders`
--

INSERT INTO `sales_orders` (`id`, `receipt_no`, `customer_name`, `subtotal`, `discount_percent`, `discount_amount`, `tax_rate`, `tax_amount`, `total_amount`, `payment_amount`, `change_amount`, `created_at`) VALUES
(1, 'OR-20260310100455-953', 'Walk-in Customer', 2540.00, 0.00, 0.00, 0.00, 0.00, 2540.00, 5000.00, 2460.00, '2026-03-10 09:04:55'),
(2, 'OR-20260310101324-974', 'Jonelle', 225.00, 0.00, 0.00, 0.00, 0.00, 225.00, 350.00, 125.00, '2026-03-10 09:13:24'),
(3, 'OR-20260310101802-354', 'Walk-in Customer', 240.00, 0.00, 0.00, 0.00, 0.00, 240.00, 500.00, 260.00, '2026-03-10 09:18:02'),
(4, 'OR-20260314014032-710', 'pangongors', 240.00, 0.00, 0.00, 0.00, 0.00, 240.00, 250.00, 10.00, '2026-03-14 00:40:32'),
(5, 'OR-20260315013807-381', 'Walk-in Customer', 2410.00, 0.00, 0.00, 0.00, 0.00, 2410.00, 5000.00, 2590.00, '2026-03-15 00:38:07'),
(6, 'OR-20260316125336-508', 'albin the great', 150.00, 5.00, 7.50, 0.00, 0.00, 142.50, 150.00, 7.50, '2026-03-16 11:53:36'),
(7, 'OR-20260316125931-230', 'Jonelle', 240.00, 0.00, 0.00, 0.00, 0.00, 240.00, 500.00, 260.00, '2026-03-16 11:59:31'),
(10, 'OR-20260316135154-356', 'mun', 480.00, 0.00, 0.00, 0.00, 0.00, 480.00, 0.00, 0.00, '2026-03-16 12:51:54'),
(11, 'OR-20260316141322-140', 'Walk-in Customer', 240.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, '2026-03-16 13:13:22'),
(12, 'OR-20260318043015-255', 'Walk-in Customer', 560.00, 0.00, 0.00, 0.00, 0.00, 560.00, 600.00, 40.00, '2026-03-18 03:30:15'),
(13, 'OR-20260318043246-261', 'Walk-in Customer', 370.00, 0.00, 0.00, 0.00, 0.00, 370.00, 500.00, 130.00, '2026-03-18 03:32:46'),
(14, 'OR-20260319121423-532', 'junjun', 375.00, 3.00, 11.25, 0.00, 0.00, 363.75, 363.75, 0.00, '2026-03-19 11:14:23'),
(15, 'OR-20260319122152-224', 'jonelle', 375.00, 0.00, 0.00, 0.00, 0.00, 375.00, 500.00, 125.00, '2026-03-19 11:21:52');

-- --------------------------------------------------------

--
-- Table structure for table `sales_order_items`
--

CREATE TABLE `sales_order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(150) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `line_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sales_order_items`
--

INSERT INTO `sales_order_items` (`id`, `order_id`, `product_id`, `product_name`, `unit_price`, `quantity`, `line_total`, `created_at`) VALUES
(1, 1, 110, 'Automatic Pump Control - Square', 2500.00, 1, 2500.00, '2026-03-10 09:04:55'),
(2, 1, 74, 'Big cap Cover', 20.00, 1, 20.00, '2026-03-10 09:04:55'),
(3, 1, 73, 'Big cap Cover', 20.00, 1, 20.00, '2026-03-10 09:04:55'),
(4, 2, 1, 'Faucet Seal', 75.00, 1, 75.00, '2026-03-10 09:13:24'),
(5, 2, 2, 'Faucet Seal', 75.00, 1, 75.00, '2026-03-10 09:13:24'),
(6, 2, 3, 'Faucet Seal', 75.00, 1, 75.00, '2026-03-10 09:13:24'),
(7, 3, 10, 'Big Mouth Seal', 120.00, 2, 240.00, '2026-03-10 09:18:02'),
(8, 4, 10, 'Big Mouth Seal', 120.00, 1, 120.00, '2026-03-14 00:40:32'),
(9, 4, 11, 'Big Mouth Seal', 120.00, 1, 120.00, '2026-03-14 00:40:32'),
(10, 5, 109, 'Automatic Pump Control - Round', 2200.00, 1, 2200.00, '2026-03-15 00:38:07'),
(11, 5, 101, 'Apron', 210.00, 1, 210.00, '2026-03-15 00:38:07'),
(12, 6, 1, 'Faucet Seal', 75.00, 1, 75.00, '2026-03-16 11:53:36'),
(13, 6, 2, 'Faucet Seal', 75.00, 1, 75.00, '2026-03-16 11:53:36'),
(14, 7, 11, 'Big Mouth Seal', 120.00, 1, 120.00, '2026-03-16 11:59:31'),
(15, 7, 10, 'Big Mouth Seal', 120.00, 1, 120.00, '2026-03-16 11:59:31'),
(22, 10, 14, 'Big Mouth Seal', 120.00, 1, 120.00, '2026-03-16 12:51:54'),
(23, 10, 13, 'Big Mouth Seal', 120.00, 1, 120.00, '2026-03-16 12:51:54'),
(24, 10, 10, 'Big Mouth Seal', 120.00, 1, 120.00, '2026-03-16 12:51:54'),
(25, 10, 15, 'Big Mouth Seal', 120.00, 1, 120.00, '2026-03-16 12:51:54'),
(26, 11, 12, 'Big Mouth Seal', 120.00, 2, 240.00, '2026-03-16 13:13:22'),
(27, 12, 101, 'Apron', 210.00, 1, 210.00, '2026-03-18 03:30:15'),
(28, 12, 120, 'Ball Valve 1/2', 350.00, 1, 350.00, '2026-03-18 03:30:15'),
(29, 13, 120, 'Ball Valve 1/2', 350.00, 1, 350.00, '2026-03-18 03:32:46'),
(30, 13, 73, 'Big cap Cover', 20.00, 1, 20.00, '2026-03-18 03:32:46'),
(31, 14, 1, 'Faucet Seal', 75.00, 5, 375.00, '2026-03-19 11:14:23'),
(32, 15, 1, 'Faucet Seal', 75.00, 5, 375.00, '2026-03-19 11:21:52');

-- --------------------------------------------------------

--
-- Table structure for table `system_reset_logs`
--

CREATE TABLE `system_reset_logs` (
  `id` int(11) NOT NULL,
  `reset_timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `admin_user` varchar(100) DEFAULT 'System Admin',
  `total_sales_orders_before` int(11) DEFAULT 0,
  `total_sales_items_before` int(11) DEFAULT 0,
  `total_inventory_logs_before` int(11) DEFAULT 0,
  `total_reports_before` int(11) DEFAULT 0,
  `total_stock_value_before` decimal(12,2) DEFAULT 0.00,
  `reset_reason` varchar(255) DEFAULT 'Manual system reset',
  `backup_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`backup_data`)),
  `status` enum('completed','failed') DEFAULT 'completed',
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_reset_logs`
--

INSERT INTO `system_reset_logs` (`id`, `reset_timestamp`, `admin_user`, `total_sales_orders_before`, `total_sales_items_before`, `total_inventory_logs_before`, `total_reports_before`, `total_stock_value_before`, `reset_reason`, `backup_data`, `status`, `notes`) VALUES
(1, '2026-03-10 03:54:19', 'System Admin', 5, 7, 0, 2, 25485.00, 'Manual system reset via admin panel', '{\"last_5_orders\":[{\"id\":\"5\",\"receipt_no\":\"OR-20260309072502-283\",\"customer_name\":\"Walk-in Customer\",\"total_amount\":\"200.00\",\"created_at\":\"2026-03-09 14:25:02\"},{\"id\":\"4\",\"receipt_no\":\"OR-20260309065433-312\",\"customer_name\":\"Walk-in Customer\",\"total_amount\":\"200.00\",\"created_at\":\"2026-03-09 13:54:33\"},{\"id\":\"3\",\"receipt_no\":\"OR-20260309065245-147\",\"customer_name\":\"albin the great\",\"total_amount\":\"150.00\",\"created_at\":\"2026-03-09 13:52:45\"},{\"id\":\"2\",\"receipt_no\":\"OR-20260309064242-305\",\"customer_name\":\"Walk-in Customer\",\"total_amount\":\"525.00\",\"created_at\":\"2026-03-09 13:42:42\"},{\"id\":\"1\",\"receipt_no\":\"OR-20260309063650-107\",\"customer_name\":\"Walk-in Customer\",\"total_amount\":\"75.00\",\"created_at\":\"2026-03-09 13:36:50\"}],\"last_5_logs\":[],\"current_stock_summary\":[{\"product_name\":\"Faucet Seal\",\"stock_store\":\"115\",\"stock_stockroom\":\"159\",\"cost\":\"52.50\"},{\"product_name\":\"Slim Gallon\",\"stock_store\":\"48\",\"stock_stockroom\":\"100\",\"cost\":\"75.00\"}]}', 'completed', NULL),
(2, '2026-03-10 05:39:38', 'System Admin', 0, 0, 0, 0, 0.00, 'Manual system reset via admin panel', '{\"last_5_orders\":[],\"last_5_logs\":[],\"current_stock_summary\":[]}', 'completed', NULL),
(3, '2026-03-10 05:40:26', 'System Admin', 0, 0, 0, 0, 2000.00, 'Manual system reset via admin panel', '{\"last_5_orders\":[],\"last_5_logs\":[],\"current_stock_summary\":[{\"product_name\":\"Tinted Sunscreen SPF50\",\"stock_store\":\"20\",\"stock_stockroom\":\"20\",\"cost\":\"50.00\"}]}', 'completed', NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin_users`
--
ALTER TABLE `admin_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_admin_email` (`email`),
  ADD KEY `idx_admin_email` (`email`),
  ADD KEY `idx_admin_role` (`role`),
  ADD KEY `idx_admin_active` (`is_active`);

--
-- Indexes for table `inventory_categories`
--
ALTER TABLE `inventory_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_category_name` (`name`);

--
-- Indexes for table `inventory_restock_logs`
--
ALTER TABLE `inventory_restock_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_restock_product_id` (`product_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_product_name` (`product_name`),
  ADD KEY `idx_category_id` (`category_id`),
  ADD KEY `idx_is_active` (`is_active`);

--
-- Indexes for table `reports`
--
ALTER TABLE `reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_report_code` (`report_code`),
  ADD KEY `idx_range_key` (`range_key`),
  ADD KEY `idx_generated_at` (`generated_at`);

--
-- Indexes for table `sales_orders`
--
ALTER TABLE `sales_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_receipt_no` (`receipt_no`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `sales_order_items`
--
ALTER TABLE `sales_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order_id` (`order_id`),
  ADD KEY `idx_product_id` (`product_id`);

--
-- Indexes for table `system_reset_logs`
--
ALTER TABLE `system_reset_logs`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin_users`
--
ALTER TABLE `admin_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `inventory_categories`
--
ALTER TABLE `inventory_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `inventory_restock_logs`
--
ALTER TABLE `inventory_restock_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=142;

--
-- AUTO_INCREMENT for table `reports`
--
ALTER TABLE `reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `sales_orders`
--
ALTER TABLE `sales_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `sales_order_items`
--
ALTER TABLE `sales_order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `system_reset_logs`
--
ALTER TABLE `system_reset_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `inventory_restock_logs`
--
ALTER TABLE `inventory_restock_logs`
  ADD CONSTRAINT `fk_restock_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `inventory_categories` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `sales_order_items`
--
ALTER TABLE `sales_order_items`
  ADD CONSTRAINT `fk_sales_item_order` FOREIGN KEY (`order_id`) REFERENCES `sales_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sales_item_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
