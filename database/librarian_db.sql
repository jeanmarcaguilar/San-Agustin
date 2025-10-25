
-- Create the librarian database
CREATE DATABASE IF NOT EXISTS `librarian_db`;
USE `librarian_db`;

-- Librarians table
CREATE TABLE IF NOT EXISTS `librarians` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `librarian_id` varchar(20) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `librarian_id` (`librarian_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Books table
CREATE TABLE IF NOT EXISTS `books` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `isbn` varchar(20) NOT NULL,
  `title` varchar(255) NOT NULL,
  `author` varchar(100) NOT NULL,
  `publisher` varchar(100) DEFAULT NULL,
  `publication_year` year(4) DEFAULT NULL,
  `category` varchar(50) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `available` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `isbn` (`isbn`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Categories table
CREATE TABLE IF NOT EXISTS `categories` (
  `category_id` int(11) NOT NULL AUTO_INCREMENT,
  `category_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`category_id`),
  UNIQUE KEY `category_name` (`category_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Update books table to use category_id as foreign key
ALTER TABLE `books`
ADD COLUMN `category_id` int(11) DEFAULT NULL AFTER `category`,
ADD COLUMN `description` text DEFAULT NULL AFTER `author`,
ADD COLUMN `pages` int(11) DEFAULT NULL AFTER `publisher`,
ADD COLUMN `shelf_location` varchar(50) DEFAULT NULL AFTER `pages`,
ADD COLUMN `cover_image` varchar(255) DEFAULT NULL AFTER `shelf_location`,
ADD COLUMN `status` enum('available','checked_out','lost','damaged') DEFAULT 'available' AFTER `available`,
ADD KEY `category_id` (`category_id`),
MODIFY COLUMN `isbn` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'International Standard Book Number',
MODIFY COLUMN `category` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Deprecated - use category_id instead';

-- Patrons table (library users)
CREATE TABLE IF NOT EXISTS `patrons` (
  `patron_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `membership_date` date NOT NULL,
  `membership_expiry` date DEFAULT NULL,
  `status` enum('active','inactive','suspended') DEFAULT 'active',
  `max_books_allowed` int(11) DEFAULT 5,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`patron_id`),
  UNIQUE KEY `user_id` (`user_id`),
  KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Book Loans table
CREATE TABLE IF NOT EXISTS `book_loans` (
  `loan_id` int(11) NOT NULL AUTO_INCREMENT,
  `book_id` int(11) NOT NULL,
  `patron_id` int(11) NOT NULL,
  `librarian_id` int(11) DEFAULT NULL,
  `checkout_date` datetime NOT NULL,
  `due_date` datetime NOT NULL,
  `return_date` datetime DEFAULT NULL,
  `status` enum('checked_out','returned','overdue','lost') NOT NULL DEFAULT 'checked_out',
  `fine_amount` decimal(10,2) DEFAULT 0.00,
  `fine_paid` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`loan_id`),
  KEY `book_id` (`book_id`),
  KEY `patron_id` (`patron_id`),
  KEY `librarian_id` (`librarian_id`),
  KEY `status` (`status`),
  KEY `due_date` (`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fines table
CREATE TABLE IF NOT EXISTS `fines` (
  `fine_id` int(11) NOT NULL AUTO_INCREMENT,
  `loan_id` int(11) NOT NULL,
  `patron_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `reason` enum('overdue','damaged','lost') NOT NULL,
  `status` enum('pending','paid','waived') NOT NULL DEFAULT 'pending',
  `paid_date` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`fine_id`),
  KEY `loan_id` (`loan_id`),
  KEY `patron_id` (`patron_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Events table
CREATE TABLE IF NOT EXISTS `events` (
  `event_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `event_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`event_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `events_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `librarians` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Book Transactions table (for borrowing history)
CREATE TABLE IF NOT EXISTS `transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `book_id` int(11) NOT NULL,
  `patron_id` int(11) NOT NULL,
  `librarian_id` int(11) DEFAULT NULL,
  `checkout_date` datetime NOT NULL,
  `due_date` datetime NOT NULL,
  `return_date` datetime DEFAULT NULL,
  `status` enum('checked_out','returned','overdue','lost') NOT NULL DEFAULT 'checked_out',
  `fine_amount` decimal(10,2) DEFAULT 0.00,
  `fine_paid` tinyint(1) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `book_id` (`book_id`),
  KEY `patron_id` (`patron_id`),
  KEY `librarian_id` (`librarian_id`),
  KEY `status` (`status`),
  KEY `checkout_date` (`checkout_date`),
  KEY `due_date` (`due_date`),
  KEY `return_date` (`return_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add foreign key constraints for transactions table
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`patron_id`) REFERENCES `patrons` (`patron_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `transactions_ibfk_3` FOREIGN KEY (`librarian_id`) REFERENCES `librarians` (`id`) ON DELETE SET NULL;

-- Reservations table
CREATE TABLE IF NOT EXISTS `reservations` (
  `reservation_id` int(11) NOT NULL AUTO_INCREMENT,
  `book_id` int(11) NOT NULL,
  `patron_id` int(11) NOT NULL,
  `reservation_date` datetime NOT NULL,
  `expiry_date` datetime NOT NULL,
  `status` enum('pending','fulfilled','cancelled','expired') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`reservation_id`),
  KEY `book_id` (`book_id`),
  KEY `patron_id` (`patron_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create authors table
CREATE TABLE IF NOT EXISTS `authors` (
  `author_id` int(11) NOT NULL AUTO_INCREMENT,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `biography` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`author_id`),
  KEY `author_name` (`last_name`, `first_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create book_authors junction table
CREATE TABLE IF NOT EXISTS `book_authors` (
  `book_id` int(11) NOT NULL,
  `author_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`book_id`, `author_id`),
  KEY `author_id` (`author_id`),
  CONSTRAINT `book_authors_ibfk_1` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE,
  CONSTRAINT `book_authors_ibfk_2` FOREIGN KEY (`author_id`) REFERENCES `authors` (`author_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add foreign key constraints
ALTER TABLE `books`
ADD CONSTRAINT `books_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`) ON DELETE SET NULL;

ALTER TABLE `book_loans`
ADD CONSTRAINT `book_loans_ibfk_1` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE,
ADD CONSTRAINT `book_loans_ibfk_2` FOREIGN KEY (`patron_id`) REFERENCES `patrons` (`patron_id`) ON DELETE CASCADE,
ADD CONSTRAINT `book_loans_ibfk_3` FOREIGN KEY (`librarian_id`) REFERENCES `librarians` (`id`) ON DELETE SET NULL;

ALTER TABLE `fines`
ADD CONSTRAINT `fines_ibfk_1` FOREIGN KEY (`loan_id`) REFERENCES `book_loans` (`loan_id`) ON DELETE CASCADE,
ADD CONSTRAINT `fines_ibfk_2` FOREIGN KEY (`patron_id`) REFERENCES `patrons` (`patron_id`) ON DELETE CASCADE;

ALTER TABLE `reservations`
ADD CONSTRAINT `reservations_ibfk_1` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE,
ADD CONSTRAINT `reservations_ibfk_2` FOREIGN KEY (`patron_id`) REFERENCES `patrons` (`patron_id`) ON DELETE CASCADE;

-- Insert sample librarian data
INSERT INTO `librarians` (`user_id`, `librarian_id`, `first_name`, `last_name`, `contact_number`) VALUES
(3, 'L-001', 'Jean Marc', 'Aguilar', '09123456789');

-- Insert sample categories
INSERT INTO `categories` (`category_name`, `description`) VALUES
('Fiction', 'Novels, short stories, and other works of fiction'),
('Science Fiction', 'Speculative fiction dealing with futuristic concepts'),
('Mystery', 'Crime and detective stories'),
('Biography', 'Non-fiction accounts of people''s lives'),
('Science', 'Books about scientific topics and discoveries'),
('History', 'Historical accounts and analysis'),
('Self-Help', 'Personal development and self-improvement books'),
('Technology', 'Books about computers, programming, and technology'),
('Children', 'Books for young readers'),
('Reference', 'Dictionaries, encyclopedias, and other reference materials');

-- First, clean up any invalid references in book_loans
-- Temporarily disable foreign key checks
SET FOREIGN_KEY_CHECKS = 0;

-- Remove any book_loans that reference non-existent books
DELETE bl FROM book_loans bl
LEFT JOIN books b ON bl.book_id = b.id
WHERE b.id IS NULL;

-- Remove any book_loans that reference non-existent patrons
DELETE bl FROM book_loans bl
LEFT JOIN patrons p ON bl.patron_id = p.patron_id
WHERE p.patron_id IS NULL AND bl.patron_id IS NOT NULL;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- Drop existing foreign key constraints if they exist
SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
     WHERE CONSTRAINT_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'book_loans' 
     AND CONSTRAINT_NAME = 'book_loans_ibfk_1') > 0,
    'ALTER TABLE `book_loans` DROP FOREIGN KEY `book_loans_ibfk_1`',
    'SELECT 1'
));
PREPARE dropIfExists FROM @preparedStatement;
EXECUTE dropIfExists;
DEALLOCATE PREPARE dropIfExists;

SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
     WHERE CONSTRAINT_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'book_loans' 
     AND CONSTRAINT_NAME = 'book_loans_ibfk_2') > 0,
    'ALTER TABLE `book_loans` DROP FOREIGN KEY `book_loans_ibfk_2`',
    'SELECT 1'
));
PREPARE dropIfExists FROM @preparedStatement;
EXECUTE dropIfExists;
DEALLOCATE PREPARE dropIfExists;

SET @preparedStatement = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
     WHERE CONSTRAINT_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'book_loans' 
     AND CONSTRAINT_NAME = 'book_loans_ibfk_3') > 0,
    'ALTER TABLE `book_loans` DROP FOREIGN KEY `book_loans_ibfk_3`',
    'SELECT 1'
));
PREPARE dropIfExists FROM @preparedStatement;
EXECUTE dropIfExists;
DEALLOCATE PREPARE dropIfExists;

-- Add foreign key constraints for book_loans table
ALTER TABLE `book_loans`
ADD CONSTRAINT `book_loans_ibfk_1` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`) ON DELETE CASCADE,
ADD CONSTRAINT `book_loans_ibfk_2` FOREIGN KEY (`patron_id`) REFERENCES `patrons` (`patron_id`) ON DELETE CASCADE,
ADD CONSTRAINT `book_loans_ibfk_3` FOREIGN KEY (`librarian_id`) REFERENCES `librarians` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;