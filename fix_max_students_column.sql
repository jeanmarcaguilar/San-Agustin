-- First, check if the columns already exist
SET @current_students_exists = 0;
SET @max_students_exists = 0;

SELECT COUNT(*) INTO @current_students_exists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'registrar_db' 
AND TABLE_NAME = 'class_sections' 
AND COLUMN_NAME = 'current_students';

SELECT COUNT(*) INTO @max_students_exists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'registrar_db' 
AND TABLE_NAME = 'class_sections' 
AND COLUMN_NAME = 'max_students';

-- Add current_students column if it doesn't exist
SET @s = IF(@current_students_exists = 0,
    'ALTER TABLE `registrar_db`.`class_sections` 
     ADD COLUMN `current_students` INT NOT NULL DEFAULT 0 
     COMMENT "Current number of students in the section"',
    'SELECT "current_students column already exists" AS message');

PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add max_students column if it doesn't exist
SET @s = IF(@max_students_exists = 0,
    'ALTER TABLE `registrar_db`.`class_sections` 
     ADD COLUMN `max_students` INT NOT NULL DEFAULT 40 
     COMMENT "Maximum number of students allowed in the section"',
    'SELECT "max_students column already exists" AS message');

PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verify the columns were added
SELECT 
    COLUMN_NAME, 
    COLUMN_TYPE, 
    COLUMN_DEFAULT, 
    IS_NULLABLE, 
    EXTRA, 
    COLUMN_COMMENT 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'registrar_db' 
AND TABLE_NAME = 'class_sections' 
AND COLUMN_NAME IN ('current_students', 'max_students');
