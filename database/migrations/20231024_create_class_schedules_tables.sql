-- Create subjects table if not exists
CREATE TABLE IF NOT EXISTS `subjects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_code` varchar(20) NOT NULL,
  `subject_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `grade_level` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `subject_code` (`subject_code`),
  KEY `grade_level` (`grade_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Update class_schedules table to use subject_id and add school_year
ALTER TABLE `class_schedules` 
ADD COLUMN IF NOT EXISTS `subject_id` int(11) DEFAULT NULL AFTER `section`,
ADD COLUMN IF NOT EXISTS `school_year` varchar(20) NOT NULL DEFAULT '2024-2025' AFTER `subject_id`,
ADD COLUMN IF NOT EXISTS `room_number` varchar(20) DEFAULT NULL AFTER `school_year`,
ADD KEY IF NOT EXISTS `subject_id` (`subject_id`),
ADD KEY IF NOT EXISTS `school_year` (`school_year`);

-- Add foreign key constraint
ALTER TABLE `class_schedules`
ADD CONSTRAINT IF NOT EXISTS `class_schedules_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE SET NULL;

-- Add unique constraint to prevent duplicate schedules
ALTER TABLE `class_schedules`
ADD UNIQUE KEY IF NOT EXISTS `unique_schedule` (`grade_level`, `section`, `day_of_week`, `start_time`, `end_time`, `school_year`);

-- Insert sample subjects
INSERT INTO `subjects` (`subject_code`, `subject_name`, `grade_level`) VALUES
('MATH-1', 'Mathematics 1', 1),
('ENG-1', 'English 1', 1),
('SCI-1', 'Science 1', 1),
('FIL-1', 'Filipino 1', 1),
('MAPEH-1', 'MAPEH 1', 1),
('MATH-2', 'Mathematics 2', 2),
('ENG-2', 'English 2', 2),
('SCI-2', 'Science 2', 2),
('FIL-2', 'Filipino 2', 2),
('MAPEH-2', 'MAPEH 2', 2),
('MATH-3', 'Mathematics 3', 3),
('ENG-3', 'English 3', 3),
('SCI-3', 'Science 3', 3),
('FIL-3', 'Filipino 3', 3),
('MAPEH-3', 'MAPEH 3', 3),
('MATH-4', 'Mathematics 4', 4),
('ENG-4', 'English 4', 4),
('SCI-4', 'Science 4', 4),
('FIL-4', 'Filipino 4', 4),
('MAPEH-4', 'MAPEH 4', 4),
('MATH-5', 'Mathematics 5', 5),
('ENG-5', 'English 5', 5),
('SCI-5', 'Science 5', 5),
('FIL-5', 'Filipino 5', 5),
('MAPEH-5', 'MAPEH 5', 5),
('MATH-6', 'Mathematics 6', 6),
('ENG-6', 'English 6', 6),
('SCI-6', 'Science 6', 6),
('FIL-6', 'Filipino 6', 6),
('MAPEH-6', 'MAPEH 6', 6);
