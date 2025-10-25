
-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Oct 14, 2025 at 04:22 PM
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
-- Database: `student_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `priority` enum('low','medium','high') NOT NULL DEFAULT 'medium',
  `start_date` datetime NOT NULL,
  `end_date` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `announcements`
--

INSERT INTO `announcements` (`id`, `title`, `description`, `image_path`, `priority`, `start_date`, `end_date`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'School Event Next Week', 'Join us for the annual school fair next week! There will be games, food, and fun activities for everyone.', 'uploads/announcements/fair.jpg', 'high', '2025-10-01 08:00:00', '2025-10-07 17:00:00', 1, 1, '2025-10-02 01:15:21', '2025-10-02 01:15:21'),
(2, 'Library Books Due', 'Friendly reminder that all library books are due next Monday. Please return or renew them to avoid late fees.', 'uploads/announcements/books.jpg', 'medium', '2025-09-28 08:00:00', '2025-10-05 17:00:00', 1, 1, '2025-10-02 01:15:21', '2025-10-02 01:15:21'),
(3, 'Parent-Teacher Conference', 'Parent-teacher conferences will be held on October 10-12. Please schedule your appointment with the class adviser.', NULL, 'high', '2025-10-01 00:00:00', '2025-10-12 23:59:59', 1, 1, '2025-10-02 01:15:21', '2025-10-02 01:15:21'),
(4, 'Science Fair Winners', 'Congratulations to all participants of the Science Fair! Winners will be announced in the auditorium at 2 PM today.', 'uploads/announcements/science-fair.jpg', 'low', '2025-09-27 08:00:00', '2025-09-27 17:00:00', 1, 1, '2025-10-02 01:15:21', '2025-10-02 01:15:21');

-- --------------------------------------------------------

--
-- Table structure for table `announcement_views`
--

CREATE TABLE `announcement_views` (
  `id` int(11) NOT NULL,
  `announcement_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `category_id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `class_sections`
--

CREATE TABLE `class_sections` (
  `id` int(11) NOT NULL,
  `section` varchar(10) NOT NULL,
  `grade_level` int(11) NOT NULL,
  `school_year` varchar(20) NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `enrollments`
--

CREATE TABLE `enrollments` (
  `id` int(11) NOT NULL,
  `student_id` varchar(20) NOT NULL,
  `grade_level` int(11) NOT NULL,
  `section` varchar(10) DEFAULT NULL,
  `school_year` varchar(20) NOT NULL,
  `status` enum('active','inactive','completed') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `grades`
--

CREATE TABLE `grades` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `subject` varchar(100) NOT NULL,
  `grade_level` int(11) NOT NULL,
  `quarter` enum('1st','2nd','3rd','4th') NOT NULL,
  `written_work` decimal(5,2) DEFAULT 0.00,
  `performance_task` decimal(5,2) DEFAULT 0.00,
  `quarterly_assessment` decimal(5,2) DEFAULT 0.00,
  `final_grade` decimal(5,2) DEFAULT 0.00,
  `remarks` varchar(50) DEFAULT 'In Progress',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `grades`
--

INSERT INTO `grades` (`id`, `student_id`, `subject`, `grade_level`, `quarter`, `written_work`, `performance_task`, `quarterly_assessment`, `final_grade`, `remarks`, `created_at`, `updated_at`) VALUES
(1, 1, 'Mathematics', 5, '1st', 85.50, 88.75, 90.00, 87.50, 'Passed', '2025-10-02 01:15:21', '2025-10-02 01:15:21'),
(2, 1, 'Science', 5, '1st', 90.00, 92.50, 89.50, 90.75, 'Passed', '2025-10-02 01:15:21', '2025-10-02 01:15:21'),
(3, 1, 'English', 5, '1st', 88.00, 85.50, 90.50, 87.75, 'Passed', '2025-10-02 01:15:21', '2025-10-02 01:15:21'),
(4, 1, 'Filipino', 5, '1st', 92.00, 90.25, 91.50, 91.25, 'Passed', '2025-10-02 01:15:21', '2025-10-02 01:15:21'),
(5, 1, 'Araling Panlipunan', 5, '1st', 89.50, 88.00, 90.00, 89.00, 'Passed', '2025-10-02 01:15:21', '2025-10-02 01:15:21'),
(8, 2, 'Mathematics', 5, '1st', 88.50, 90.75, 92.00, 90.25, 'Passed', '2025-10-02 01:15:21', '2025-10-02 01:15:21'),
(9, 2, 'Science', 5, '1st', 92.00, 94.50, 91.50, 92.75, 'Passed', '2025-10-02 01:15:21', '2025-10-02 01:15:21'),
(10, 2, 'English', 5, '1st', 90.00, 87.50, 92.50, 89.75, 'Passed', '2025-10-02 01:15:21', '2025-10-02 01:15:21'),
(11, 3, 'Mathematics', 5, '1st', 82.50, 85.75, 88.00, 85.25, 'Passed', '2025-10-02 01:15:21', '2025-10-02 01:15:21'),
(12, 3, 'Science', 5, '1st', 88.00, 90.50, 87.50, 88.75, 'Passed', '2025-10-02 01:15:21', '2025-10-02 01:15:21'),
(13, 3, 'English', 5, '1st', 85.00, 83.50, 88.50, 85.75, 'Passed', '2025-10-02 01:15:21', '2025-10-02 01:15:21');

-- --------------------------------------------------------

--
-- Table structure for table `librarians`
--

CREATE TABLE `librarians` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `librarian_id` varchar(20) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `modules`
--

CREATE TABLE `modules` (
  `id` int(11) NOT NULL,
  `module_code` varchar(20) NOT NULL,
  `title` varchar(255) NOT NULL,
  `subject` varchar(100) NOT NULL,
  `grade_level` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `thumbnail` varchar(255) DEFAULT 'default_module.jpg',
  `description` text DEFAULT NULL,
  `duration_minutes` int(11) DEFAULT 30,
  `is_active` tinyint(1) DEFAULT 1,
  `order_number` int(11) DEFAULT 0,
  `prerequisite_module_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `modules`
--

INSERT INTO `modules` (`id`, `module_code`, `title`, `subject`, `grade_level`, `file_path`, `thumbnail`, `description`, `duration_minutes`, `is_active`, `order_number`, `prerequisite_module_id`, `created_at`, `updated_at`) VALUES
(1, 'MATH-501', 'Introduction to Fractions', 'Mathematics', 5, 'math_fractions.pdf', 'default_module.jpg', 'Learn the basics of fractions and how to work with them.', 45, 1, 1, NULL, '2025-10-02 01:15:20', '2025-10-02 01:15:20'),
(2, 'SCI-501', 'The Solar System', 'Science', 5, 'solar_system.pdf', 'default_module.jpg', 'Explore our solar system and its planets.', 60, 1, 2, NULL, '2025-10-02 01:15:20', '2025-10-02 01:15:20'),
(3, 'ENG-501', 'Reading Comprehension', 'English', 5, 'reading_comp.pdf', 'default_module.jpg', 'Improve your reading and understanding skills.', 30, 1, 3, NULL, '2025-10-02 01:15:20', '2025-10-02 01:15:20'),
(4, 'MATH-502', 'Decimal Numbers', 'Mathematics', 5, 'math_decimals.pdf', 'default_module.jpg', 'Learn about decimal numbers and operations.', 50, 1, 4, NULL, '2025-10-02 01:15:20', '2025-10-02 01:15:20'),
(5, 'SCI-502', 'States of Matter', 'Science', 5, 'states_matter.pdf', 'default_module.jpg', 'Understand the different states of matter.', 40, 1, 5, NULL, '2025-10-02 01:15:20', '2025-10-02 01:15:20'),
(6, 'MATH-1-001', 'Basic Addition', 'Mathematics', 1, 'modules/math1_addition.pdf', 'math1.jpg', 'Introduction to basic addition for grade 1 students', 30, 1, 1, NULL, '2025-10-02 01:15:21', '2025-10-02 01:15:21'),
(7, 'MATH-1-002', 'Basic Subtraction', 'Mathematics', 1, 'modules/math1_subtraction.pdf', 'math1.jpg', 'Introduction to basic subtraction for grade 1 students', 30, 1, 2, 6, '2025-10-02 01:15:21', '2025-10-02 01:15:21'),
(8, 'ENG-1-001', 'Alphabet and Phonics', 'English', 1, 'modules/eng1_alphabet.pdf', 'english1.jpg', 'Learning the alphabet and basic phonics', 45, 1, 1, NULL, '2025-10-02 01:15:21', '2025-10-02 01:15:21'),
(9, 'MATH-2-001', 'Two-Digit Addition', 'Mathematics', 2, 'modules/math2_addition.pdf', 'math2.jpg', 'Learn to add two-digit numbers', 35, 1, 1, NULL, '2025-10-02 01:15:21', '2025-10-02 01:15:21'),
(10, 'SCI-2-001', 'Parts of Plants', 'Science', 2, 'modules/sci2_plants.pdf', 'science2.jpg', 'Learn about different parts of plants and their functions', 40, 1, 1, NULL, '2025-10-02 01:15:21', '2025-10-02 01:15:21'),
(11, 'ENG-3-001', 'Basic Grammar', 'English', 3, 'modules/eng3_grammar.pdf', 'english3.jpg', 'Introduction to basic English grammar rules', 45, 1, 1, NULL, '2025-10-02 01:15:21', '2025-10-02 01:15:21'),
(12, 'MATH-3-001', 'Multiplication Basics', 'Mathematics', 3, 'modules/math3_multiplication.pdf', 'math3.jpg', 'Learn the basics of multiplication', 40, 1, 1, NULL, '2025-10-02 01:15:21', '2025-10-02 01:15:21'),
(13, 'FIL-4-001', 'Pangngalan', 'Filipino', 4, 'modules/fil4_pangngalan.pdf', 'filipino4.jpg', 'Aralin tungkol sa mga uri ng pangngalan', 35, 1, 1, NULL, '2025-10-02 01:15:21', '2025-10-02 01:15:21'),
(14, 'SCI-4-001', 'Water Cycle', 'Science', 4, 'modules/sci4_water_cycle.pdf', 'science4.jpg', 'Understanding the water cycle and its importance', 45, 1, 1, NULL, '2025-10-02 01:15:21', '2025-10-02 01:15:21'),
(15, 'MATH-5-001', 'Fractions', 'Mathematics', 5, 'modules/math5_fractions.pdf', 'math5.jpg', 'Understanding and working with fractions', 50, 1, 1, NULL, '2025-10-02 01:15:21', '2025-10-02 01:15:21'),
(16, 'MATH-5-002', 'Decimals', 'Mathematics', 5, 'modules/math5_decimals.pdf', 'math5.jpg', 'Introduction to decimal numbers', 45, 1, 2, 15, '2025-10-02 01:15:21', '2025-10-02 01:15:21'),
(17, 'SCI-5-001', 'Human Body Systems', 'Science', 5, 'modules/sci5_human_body.pdf', 'science5.jpg', 'Explore different systems of the human body', 55, 1, 1, NULL, '2025-10-02 01:15:21', '2025-10-02 01:15:21'),
(18, 'SCI-6-001', 'Solar System', 'Science', 6, 'modules/sci6_solar_system.pdf', 'science6.jpg', 'Exploring our solar system and planets', 60, 1, 1, NULL, '2025-10-02 01:15:21', '2025-10-02 01:15:21'),
(19, 'MATH-6-001', 'Basic Algebra', 'Mathematics', 6, 'modules/math6_algebra.pdf', 'math6.jpg', 'Introduction to basic algebraic concepts', 50, 1, 1, NULL, '2025-10-02 01:15:21', '2025-10-02 01:15:21'),
(20, 'ENG-6-001', 'Reading Comprehension', 'English', 6, 'modules/eng6_reading.pdf', 'english6.jpg', 'Improving reading comprehension skills', 45, 1, 1, NULL, '2025-10-02 01:15:21', '2025-10-02 01:15:21');

-- --------------------------------------------------------

--
-- Table structure for table `module_resources`
--

CREATE TABLE `module_resources` (
  `id` int(11) NOT NULL,
  `module_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_type` varchar(50) NOT NULL,
  `file_size` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `student_id` varchar(20) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `grade_level` int(11) NOT NULL,
  `section` varchar(10) NOT NULL,
  `school_year` varchar(20) NOT NULL,
  `birthdate` date DEFAULT NULL,
  `gender` enum('Male','Female','Other') DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `parent_name` varchar(100) DEFAULT NULL,
  `parent_contact` varchar(20) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `status` enum('Active','Inactive','Graduated','Transferred') DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`id`, `user_id`, `student_id`, `first_name`, `last_name`, `middle_name`, `grade_level`, `section`, `school_year`, `birthdate`, `gender`, `address`, `parent_name`, `parent_contact`, `phone`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, '2023-0001', 'John', 'Doe', NULL, 5, 'A', '', '2015-05-15', NULL, '123 Main St', 'Jane Doe', '09123456789', NULL, 'Active', '2025-10-02 01:15:20', '2025-10-02 01:15:20'),
(2, 2, '2023-0002', 'Maria', 'Santos', NULL, 5, 'A', '', '2012-07-22', NULL, '456 Oak St, San Agustin', 'Juan Santos', '09123456780', NULL, 'Active', '2025-10-02 01:15:21', '2025-10-02 01:15:21'),
(3, 3, '2023-0003', 'Pedro', 'Reyes', NULL, 5, 'A', '', '2012-03-10', NULL, '789 Pine St, San Agustin', 'Ana Reyes', '09123456781', NULL, 'Active', '2025-10-02 01:15:21', '2025-10-02 01:15:21');

-- --------------------------------------------------------

--
-- Table structure for table `student_module_progress`
--

CREATE TABLE `student_module_progress` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `module_id` int(11) NOT NULL,
  `status` enum('not_started','in_progress','completed') NOT NULL DEFAULT 'not_started',
  `progress` int(3) DEFAULT 0,
  `last_accessed` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_module_progress`
--

INSERT INTO `student_module_progress` (`id`, `student_id`, `module_id`, `status`, `progress`, `last_accessed`, `completed_at`, `created_at`, `updated_at`) VALUES
(1, 1, 15, 'completed', 100, '2025-09-30 01:15:21', '2025-09-27 01:15:21', '2025-10-02 01:15:21', '2025-10-02 01:15:21'),
(2, 1, 16, 'in_progress', 65, '2025-10-01 01:15:21', NULL, '2025-10-02 01:15:21', '2025-10-02 01:15:21'),
(3, 1, 17, 'not_started', 0, NULL, NULL, '2025-10-02 01:15:21', '2025-10-02 01:15:21');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `role` enum('student','teacher','librarian','registrar') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `email`, `role`, `created_at`, `updated_at`) VALUES
(1, 'student1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student1@example.com', 'student', '2025-10-02 04:19:33', '2025-10-02 04:19:33'),
(2, 'teacher1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'teacher1@example.com', 'teacher', '2025-10-02 04:19:33', '2025-10-02 04:19:33'),
(3, 'librarian1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'librarian1@example.com', 'librarian', '2025-10-02 04:19:33', '2025-10-02 04:19:33'),
(4, 'registrar1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'registrar1@example.com', 'registrar', '2025-10-02 04:19:33', '2025-10-02 04:19:33');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_priority` (`priority`),
  ADD KEY `idx_active` (`is_active`),
  ADD KEY `idx_dates` (`start_date`,`end_date`);

--
-- Indexes for table `announcement_views`
--
ALTER TABLE `announcement_views`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_announcement_student` (`announcement_id`,`student_id`),
  ADD KEY `idx_student_read` (`student_id`,`is_read`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`category_id`),
  ADD UNIQUE KEY `category_name` (`category_name`);

--
-- Indexes for table `class_sections`
--
ALTER TABLE `class_sections`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_section` (`section`,`grade_level`,`school_year`);

--
-- Indexes for table `enrollments`
--
ALTER TABLE `enrollments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_enrollment` (`student_id`,`school_year`);

--
-- Indexes for table `grades`
--
ALTER TABLE `grades`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_grade_entry` (`student_id`,`subject`,`grade_level`,`quarter`),
  ADD KEY `idx_student` (`student_id`),
  ADD KEY `idx_subject` (`subject`),
  ADD KEY `idx_grade_level` (`grade_level`);

--
-- Indexes for table `librarians`
--
ALTER TABLE `librarians`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `librarian_id` (`librarian_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `modules`
--
ALTER TABLE `modules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `module_code` (`module_code`),
  ADD KEY `idx_subject` (`subject`),
  ADD KEY `idx_grade_level` (`grade_level`),
  ADD KEY `idx_active` (`is_active`),
  ADD KEY `fk_prerequisite_module` (`prerequisite_module_id`);

--
-- Indexes for table `module_resources`
--
ALTER TABLE `module_resources`
  ADD PRIMARY KEY (`id`),
  ADD KEY `module_id` (`module_id`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `student_id` (`student_id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `student_module_progress`
--
ALTER TABLE `student_module_progress`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_student_module` (`student_id`,`module_id`),
  ADD KEY `module_id` (`module_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `announcement_views`
--
ALTER TABLE `announcement_views`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `category_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `class_sections`
--
ALTER TABLE `class_sections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `enrollments`
--
ALTER TABLE `enrollments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `grades`
--
ALTER TABLE `grades`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `librarians`
--
ALTER TABLE `librarians`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `modules`
--
ALTER TABLE `modules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `module_resources`
--
ALTER TABLE `module_resources`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `student_module_progress`
--
ALTER TABLE `student_module_progress`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `announcement_views`
--
ALTER TABLE `announcement_views`
  ADD CONSTRAINT `fk_av_announcement` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_av_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `enrollments`
--
ALTER TABLE `enrollments`
  ADD CONSTRAINT `fk_enrollment_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE;

--
-- Constraints for table `grades`
--
ALTER TABLE `grades`
  ADD CONSTRAINT `fk_grade_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `modules`
--
ALTER TABLE `modules`
  ADD CONSTRAINT `fk_prerequisite_module` FOREIGN KEY (`prerequisite_module_id`) REFERENCES `modules` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `module_resources`
--
ALTER TABLE `module_resources`
  ADD CONSTRAINT `fk_resource_module` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `student_module_progress`
--
ALTER TABLE `student_module_progress`
  ADD CONSTRAINT `fk_smp_module` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_smp_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`user_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;