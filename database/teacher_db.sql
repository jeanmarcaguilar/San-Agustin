
-- Create the teacher database
CREATE DATABASE IF NOT EXISTS `teacher_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `teacher_db`;

-- Teachers table
CREATE TABLE IF NOT EXISTS `teachers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `teacher_id` varchar(20) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `subject` varchar(100) DEFAULT NULL,
  `grade_level` int(11) DEFAULT NULL,
  `section` varchar(10) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `teacher_id` (`teacher_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Classes table
CREATE TABLE IF NOT EXISTS `classes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` varchar(20) NOT NULL,
  `subject` varchar(100) NOT NULL,
  `grade_level` int(11) NOT NULL,
  `section` varchar(10) NOT NULL,
  `schedule` varchar(100) DEFAULT NULL,
  `room` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `teacher_id` (`teacher_id`),
  CONSTRAINT `classes_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`teacher_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Activities table
CREATE TABLE IF NOT EXISTS `activities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` varchar(20) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `activity_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `teacher_id` (`teacher_id`),
  CONSTRAINT `activities_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`teacher_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notices table
CREATE TABLE IF NOT EXISTS `notices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` varchar(20) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `status` enum('pending','read') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `teacher_id` (`teacher_id`),
  CONSTRAINT `notices_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`teacher_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert sample teacher data
INSERT INTO `teachers` (`user_id`, `teacher_id`, `first_name`, `last_name`, `subject`, `grade_level`, `section`, `contact_number`) VALUES
(2, 'T-001', 'Jean Marc', 'Aguilar', 'Mathematics', 6, 'A', '09123456789');

-- Insert sample class data
INSERT INTO `classes` (`teacher_id`, `subject`, `grade_level`, `section`, `schedule`, `room`) VALUES
('T-001', 'Mathematics', 6, 'A', 'MWF 8:00 AM - 9:00 AM', 'Room 101'),
('T-001', 'Science', 6, 'B', 'TTH 1:00 PM - 2:00 PM', 'Room 102');

-- Insert sample activity
INSERT INTO `activities` (`teacher_id`, `title`, `description`, `activity_date`) VALUES
('T-001', 'Math Quiz', 'Chapter 1: Basic Arithmetic', '2023-10-15');

-- Insert sample notice
INSERT INTO `notices` (`teacher_id`, `title`, `message`, `status`) VALUES
('T-001', 'Parent-Teacher Meeting', 'Scheduled for next week', 'pending');

-- Students table (for teacher's reference)
CREATE TABLE IF NOT EXISTS `students` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `student_id` varchar(20) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `grade_level` int(11) DEFAULT NULL,
  `section` varchar(10) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `gender` enum('Male','Female','Other') DEFAULT NULL,
  `address` text DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_id` (`student_id`),
  KEY `user_id` (`user_id`),
  KEY `grade_section` (`grade_level`, `section`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Student-class relationship table (renamed to class_students to match application expectations)
CREATE TABLE IF NOT EXISTS `class_students` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` varchar(20) NOT NULL,
  `class_id` int(11) NOT NULL,
  `enrollment_date` date NOT NULL,
  `status` enum('active','inactive','dropped') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_class` (`student_id`, `class_id`),
  KEY `class_id` (`class_id`),
  CONSTRAINT `class_students_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  CONSTRAINT `class_students_ibfk_2` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Attendance table to track student attendance
CREATE TABLE IF NOT EXISTS `attendance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` varchar(20) NOT NULL,
  `class_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `status` enum('present','absent','late','excused') NOT NULL DEFAULT 'present',
  `notes` text DEFAULT NULL,
  `recorded_by` varchar(20) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `attendance_record` (`student_id`, `class_id`, `attendance_date`),
  KEY `class_id` (`class_id`),
  KEY `date` (`attendance_date`),
  KEY `recorded_by` (`recorded_by`),
  CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_ibfk_2` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_ibfk_3` FOREIGN KEY (`recorded_by`) REFERENCES `teachers` (`teacher_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert sample student data
INSERT INTO `students` (`user_id`, `student_id`, `first_name`, `last_name`, `grade_level`, `section`, `birth_date`, `gender`, `contact_number`, `email`) VALUES
(3, 'S-001', 'Juan', 'Dela Cruz', 6, 'A', '2012-05-15', 'Male', '09123456789', 'juan.delacruz@example.com'),
(4, 'S-002', 'Maria', 'Santos', 6, 'A', '2012-07-22', 'Female', '09123456788', 'maria.santos@example.com'),
(5, 'S-003', 'Jose', 'Reyes', 6, 'A', '2012-03-10', 'Male', '09123456787', 'jose.reyes@example.com');

-- Enroll students in class
INSERT INTO `class_students` (`student_id`, `class_id`, `enrollment_date`, `status`) VALUES
('S-001', 1, '2023-09-01', 'active'),
('S-002', 1, '2023-09-01', 'active'),
('S-003', 1, '2023-09-01', 'active');

-- Sample attendance data
INSERT INTO `attendance` (`student_id`, `class_id`, `attendance_date`, `status`, `notes`, `recorded_by`) VALUES
('S-001', 1, '2023-09-28', 'present', 'On time', 'T-001'),
('S-002', 1, '2023-09-28', 'late', 'Arrived 10 minutes late', 'T-001'),
('S-003', 1, '2023-09-28', 'absent', 'Sick leave', 'T-001');

-- Grades table to track student grades
CREATE TABLE IF NOT EXISTS `grades` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` varchar(20) NOT NULL,
  `class_id` int(11) NOT NULL,
  `grading_period` enum('1st Quarter','2nd Quarter','3rd Quarter','4th Quarter','Midterm','Final') NOT NULL,
  `assessment_type` enum('quiz','exam','project','homework','participation','other') NOT NULL,
  `title` varchar(255) NOT NULL,
  `score` decimal(5,2) NOT NULL,
  `max_score` decimal(5,2) NOT NULL DEFAULT 100.00,
  `percentage` decimal(5,2) GENERATED ALWAYS AS ((`score` / `max_score`) * 100) STORED,
  `grade_date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `recorded_by` varchar(20) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`),
  KEY `class_id` (`class_id`),
  KEY `assessment_type` (`assessment_type`),
  KEY `grade_date` (`grade_date`),
  KEY `grading_period` (`grading_period`),
  KEY `recorded_by` (`recorded_by`),
  CONSTRAINT `grades_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  CONSTRAINT `grades_ibfk_2` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `grades_ibfk_3` FOREIGN KEY (`recorded_by`) REFERENCES `teachers` (`teacher_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Student Assignments table to track student submissions
-- This table is created after the assignments table due to foreign key dependency

-- Add attendance_rate column to attendance table
ALTER TABLE `attendance` 
ADD COLUMN IF NOT EXISTS `attendance_rate` DECIMAL(5,2) GENERATED ALWAYS AS (
  CASE 
    WHEN status = 'present' THEN 100.00 
    WHEN status = 'late' THEN 50.00 
    ELSE 0.00 
  END
) STORED AFTER `status`;

-- Create a view for student grades summary
CREATE OR REPLACE VIEW `student_grades` AS
SELECT 
    g.id,
    g.student_id,
    g.class_id,
    c.teacher_id,
    c.subject,
    g.grading_period,
    g.assessment_type,
    g.title,
    g.score,
    g.max_score,
    g.percentage as final_grade,
    g.grade_date,
    g.notes,
    g.recorded_by,
    g.created_at,
    g.updated_at,
    (SELECT AVG(percentage) 
     FROM grades g2 
     WHERE g2.student_id = g.student_id 
     AND g2.class_id = g.class_id 
     AND g2.grading_period = g.grading_period) as period_average,
    (SELECT AVG(percentage) 
     FROM grades g3 
     WHERE g3.student_id = g.student_id 
     AND g3.class_id = g.class_id) as overall_average
FROM 
    grades g
JOIN 
    classes c ON g.class_id = c.id;

-- Create a view for attendance summary
CREATE OR REPLACE VIEW `attendance_summary` AS
SELECT 
    a.student_id,
    s.first_name,
    s.last_name,
    a.class_id,
    c.teacher_id,
    c.subject,
    c.grade_level,
    c.section,
    COUNT(a.id) as total_days,
    SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_days,
    SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_days,
    SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_days,
    ROUND((SUM(CASE WHEN a.status IN ('present', 'late') THEN 1 ELSE 0 END) / NULLIF(COUNT(a.id), 0)) * 100, 1) as attendance_rate
FROM 
    attendance a
JOIN 
    students s ON a.student_id = s.student_id
JOIN 
    classes c ON a.class_id = c.id
GROUP BY 
    a.student_id, a.class_id, c.teacher_id, c.subject, s.first_name, s.last_name, c.grade_level, c.section;

-- Sample grades data with grading periods
INSERT INTO `grades` (`student_id`, `class_id`, `grading_period`, `assessment_type`, `title`, `score`, `max_score`, `grade_date`, `notes`, `recorded_by`) VALUES
('S-001', 1, '1st Quarter', 'quiz', 'Basic Arithmetic Quiz', 45.00, 50.00, '2023-09-15', 'Good effort, needs to show work', 'T-001'),
('S-001', 1, '1st Quarter', 'exam', 'Midterm Exam', 85.00, 100.00, '2023-09-30', 'Excellent performance', 'T-001'),
('S-002', 1, '1st Quarter', 'quiz', 'Basic Arithmetic Quiz', 48.00, 50.00, '2023-09-15', 'Perfect score', 'T-001'),
('S-002', 1, '1st Quarter', 'exam', 'Midterm Exam', 92.00, 100.00, '2023-09-30', 'Outstanding work', 'T-001'),
('S-003', 1, '1st Quarter', 'quiz', 'Basic Arithmetic Quiz', 40.00, 50.00, '2023-09-15', 'Needs improvement', 'T-001'),
('S-003', 1, '1st Quarter', 'exam', 'Midterm Exam', 78.00, 100.00, '2023-09-30', 'Shows understanding but room for improvement', 'T-001');

-- Assignments table to store assignments given by teachers
CREATE TABLE IF NOT EXISTS `assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` varchar(20) NOT NULL,
  `class_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `assignment_type` enum('homework','quiz','project','essay','presentation','other') NOT NULL,
  `due_date` date NOT NULL,
  `total_points` decimal(5,2) NOT NULL DEFAULT 100.00,
  `grading_period` enum('1st Quarter','2nd Quarter','3rd Quarter','4th Quarter','Midterm','Final') NOT NULL,
  `status` enum('draft','published','graded','archived') NOT NULL DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `teacher_id` (`teacher_id`),
  KEY `class_id` (`class_id`),
  KEY `due_date` (`due_date`),
  KEY `grading_period` (`grading_period`),
  KEY `status` (`status`),
  FULLTEXT KEY `title_description` (`title`, `description`),
  CONSTRAINT `assignments_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`teacher_id`) ON DELETE CASCADE,
  CONSTRAINT `assignments_ibfk_2` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Student Assignments table to track student submissions
CREATE TABLE IF NOT EXISTS `student_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `assignment_id` int(11) NOT NULL,
  `student_id` varchar(20) NOT NULL,
  `submission_date` datetime DEFAULT NULL,
  `score` decimal(5,2) DEFAULT NULL,
  `status` enum('submitted','late','missing','graded') NOT NULL DEFAULT 'missing',
  `feedback` text DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `assignment_student` (`assignment_id`, `student_id`),
  KEY `student_id` (`student_id`),
  CONSTRAINT `student_assignments_ibfk_1` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_assignments_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sample assignments data
INSERT INTO `assignments` (`teacher_id`, `class_id`, `title`, `description`, `assignment_type`, `due_date`, `total_points`, `grading_period`, `status`) VALUES
('T-001', 1, 'Basic Arithmetic Quiz', 'Quiz covering addition, subtraction, multiplication, and division of whole numbers.', 'quiz', '2023-09-15', 50.00, '1st Quarter', 'graded'),
('T-001', 1, 'Problem Set 1', 'Practice problems on basic arithmetic operations and word problems.', 'homework', '2023-09-20', 50.00, '1st Quarter', 'graded'),
('T-001', 1, 'Midterm Exam', 'Midterm examination covering all topics from the first half of the quarter.', 'quiz', '2023-09-30', 100.00, '1st Quarter', 'graded'),
('T-001', 1, 'Fractions Project', 'Create a presentation demonstrating understanding of fractions in real-life situations.', 'project', '2023-10-15', 100.00, '1st Quarter', 'published');

-- Announcements table to store teacher announcements
CREATE TABLE IF NOT EXISTS `announcements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` varchar(20) NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `target_audience` enum('all','specific_class','specific_grade','specific_section') NOT NULL DEFAULT 'all',
  `target_class_id` int(11) DEFAULT NULL,
  `target_grade` int(11) DEFAULT NULL,
  `target_section` varchar(10) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('draft','published','archived') NOT NULL DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `teacher_id` (`teacher_id`),
  KEY `target_class_id` (`target_class_id`),
  KEY `target_grade_section` (`target_grade`, `target_section`),
  KEY `status` (`status`),
  KEY `dates` (`start_date`, `end_date`),
  FULLTEXT KEY `title_content` (`title`, `content`),
  CONSTRAINT `announcements_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`teacher_id`) ON DELETE CASCADE,
  CONSTRAINT `announcements_ibfk_2` FOREIGN KEY (`target_class_id`) REFERENCES `classes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Announcement views table to track which users have seen which announcements
CREATE TABLE IF NOT EXISTS `announcement_views` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `announcement_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_type` enum('teacher','student','parent') NOT NULL,
  `viewed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_view` (`announcement_id`, `user_id`, `user_type`),
  KEY `announcement_id` (`announcement_id`),
  KEY `user` (`user_id`, `user_type`),
  CONSTRAINT `announcement_views_ibfk_1` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sample announcements data
INSERT INTO `announcements` (
  `teacher_id`, `title`, `content`, `target_audience`, `target_class_id`, `target_grade`, `target_section`, 
  `start_date`, `end_date`, `is_pinned`, `status`
) VALUES 
('T-001', 'Welcome Back to School!', 'Dear students, welcome back to a new academic year! We have many exciting lessons planned for you.', 'all', NULL, NULL, NULL, '2023-09-01', '2023-09-30', 1, 'published'),
('T-001', 'Math Quiz Next Week', 'There will be a quiz on Chapter 1 next Monday. Please review sections 1.1 to 1.5.', 'specific_class', 1, NULL, NULL, '2023-09-20', '2023-09-25', 0, 'published'),
('T-001', 'Parent-Teacher Conference', 'Parent-teacher conferences are scheduled for next week. Please sign up for a time slot.', 'specific_grade', NULL, 6, NULL, '2023-10-10', '2023-10-15', 1, 'published');

-- Reports table to store generated reports
-- Lesson Plans table
CREATE TABLE IF NOT EXISTS `lesson_plans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` varchar(20) NOT NULL,
  `title` varchar(255) NOT NULL,
  `subject` varchar(100) NOT NULL,
  `grade_level` int(2) NOT NULL,
  `objectives` text DEFAULT NULL,
  `materials` text DEFAULT NULL,
  `activities` text DEFAULT NULL,
  `assessment` text DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `teacher_id` (`teacher_id`),
  KEY `subject` (`subject`),
  KEY `grade_level` (`grade_level`),
  KEY `due_date` (`due_date`),
  CONSTRAINT `lesson_plans_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`teacher_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reports table
CREATE TABLE IF NOT EXISTS `reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` varchar(20) NOT NULL,
  `report_type` enum('attendance','grades','progress','behavioral','custom') NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `filters` text DEFAULT NULL COMMENT 'JSON string of filters used to generate the report',
  `file_path` varchar(512) DEFAULT NULL COMMENT 'Path to generated report file if exported',
  `format` enum('pdf','csv','excel','html') DEFAULT NULL,
  `status` enum('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
  `scheduled_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `teacher_id` (`teacher_id`),
  KEY `report_type` (`report_type`),
  KEY `status` (`status`),
  KEY `created_at` (`created_at`),
  FULLTEXT KEY `title_description` (`title`, `description`),
  CONSTRAINT `reports_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`teacher_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Report items table to store individual report entries (for dynamic reports)
CREATE TABLE IF NOT EXISTS `report_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `report_id` int(11) NOT NULL,
  `student_id` varchar(20) DEFAULT NULL,
  `class_id` int(11) DEFAULT NULL,
  `item_type` varchar(50) NOT NULL,
  `item_key` varchar(100) NOT NULL,
  `item_value` text DEFAULT NULL,
  `metadata` text DEFAULT NULL COMMENT 'Additional metadata in JSON format',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `report_id` (`report_id`),
  KEY `student_id` (`student_id`),
  KEY `class_id` (`class_id`),
  KEY `item_lookup` (`item_type`, `item_key`),
  CONSTRAINT `report_items_ibfk_1` FOREIGN KEY (`report_id`) REFERENCES `reports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `report_items_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE SET NULL,
  CONSTRAINT `report_items_ibfk_3` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sample reports data
INSERT INTO `reports` (
  `teacher_id`, `report_type`, `title`, `description`, `filters`, `format`, `status`, `created_at`
) VALUES 
('T-001', 'attendance', 'September 2023 Attendance', 'Monthly attendance report for September 2023', '{"month": 9, "year": 2023, "class_id": 1}', 'pdf', 'completed', '2023-10-01 10:00:00'),
('T-001', 'grades', 'Q1 Progress Report', 'Quarter 1 progress report for all students', '{"quarter": 1, "year": 2023}', 'excel', 'completed', '2023-10-15 14:30:00'),
('T-001', 'progress', 'Student Progress Overview', 'Student progress overview as of October 2023', '{"as_of": "2023-10-20"}', 'pdf', 'pending', '2023-10-20 09:15:00');

-- Sample report items for the first report
INSERT INTO `report_items` (
  `report_id`, `student_id`, `class_id`, `item_type`, `item_key`, `item_value`, `metadata`
) VALUES
(1, 'S-001', 1, 'attendance_summary', 'days_present', '20', '{"total_days": 22}'),
(1, 'S-001', 1, 'attendance_summary', 'days_absent', '2', '{"total_days": 22}'),
(1, 'S-002', 1, 'attendance_summary', 'days_present', '18', '{"total_days": 22}'),
(1, 'S-002', 1, 'attendance_summary', 'days_absent', '4', '{"total_days": 22}'),
(1, 'S-003', 1, 'attendance_summary', 'days_present', '21', '{"total_days": 22}'),
(1, 'S-003', 1, 'attendance_summary', 'days_absent', '1', '{"total_days": 22}');