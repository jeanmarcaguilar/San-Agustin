-- Add a composite unique constraint to ensure unique schedules per section
ALTER TABLE `class_schedules` 
ADD UNIQUE INDEX `unique_schedule` (`grade_level`, `section`, `day_of_week`, `start_time`, `end_time`);

-- Add a column to track the school year for each schedule
ALTER TABLE `class_schedules`
ADD COLUMN `school_year` VARCHAR(20) NOT NULL DEFAULT '2024-2025' AFTER `section`,
ADD INDEX `idx_school_year` (`school_year`);

-- Update existing schedules to use the default school year
UPDATE `class_schedules` SET `school_year` = '2024-2025' WHERE `school_year` = '' OR `school_year` IS NULL;
