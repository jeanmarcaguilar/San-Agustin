
-- Create the main login database
CREATE DATABASE IF NOT EXISTS `login_db`;
USE `login_db`;

-- Users table for authentication
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `role` enum('student','teacher','librarian','registrar') NOT NULL,
  `twofa_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `twofa_code_hash` varchar(255) DEFAULT NULL,
  `twofa_expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_users_twofa_expires` (`twofa_expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert sample users (passwords are hashed with password_hash('password123', PASSWORD_DEFAULT))
INSERT INTO `users` (`username`, `password`, `email`, `role`) VALUES
('student1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'jeanmarcaguilar829+student@gmail.com', 'student'),
('teacher1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'jeanmarcaguilar829+teacher@gmail.com', 'teacher'),
('librarian1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'jeanmarcaguilar829+librarian@gmail.com', 'librarian'),
('registrar1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'jeanmarcaguilar829+registrar@gmail.com', 'registrar');