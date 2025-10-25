-- SQL to add schedules for each section from Grade 1 to 6
-- This script populates the class_schedules table with a standard schedule for each section

-- Clear existing schedules (be careful with this in production)
-- TRUNCATE TABLE `registrar_db`.`class_schedules`;

-- Function to add a schedule entry
DELIMITER //
CREATE PROCEDURE AddSchedule(
    IN p_grade_level INT,
    IN p_section VARCHAR(10),
    IN p_subject VARCHAR(100),
    IN p_day VARCHAR(10),
    IN p_start_time VARCHAR(8),
    IN p_end_time VARCHAR(8),
    IN p_room VARCHAR(20),
    IN p_school_year VARCHAR(20)
)
BEGIN
    INSERT INTO `registrar_db`.`class_schedules` (
        `grade_level`,
        `section`,
        `subject`,
        `day_of_week`,
        `start_time`,
        `end_time`,
        `room`,
        `school_year`,
        `status`
    ) VALUES (
        p_grade_level,
        p_section,
        p_subject,
        p_day,
        STR_TO_DATE(p_start_time, '%h:%i %p'),
        STR_TO_DATE(p_end_time, '%h:%i %p'),
        p_room,
        p_school_year,
        'active'
    );
END //

-- Function to generate a standard schedule for a grade level
CREATE PROCEDURE GenerateGradeSchedules(IN p_grade_level INT, IN p_school_year VARCHAR(20))
BEGIN
    DECLARE section_name VARCHAR(10);
    DECLARE room_number VARCHAR(20);
    DECLARE i INT DEFAULT 1;
    
    -- Loop through sections A-J for the grade
    WHILE i <= 10 DO
        SET section_name = CONCAT('Section ', CHAR(64 + i)); -- A-J
        SET room_number = CONCAT('G', p_grade_level, '-', LPAD(i, 2, '0')); -- G1-01, G1-02, etc.
        
        -- Monday Schedule
        CALL AddSchedule(p_grade_level, section_name, 'Reading', 'Monday', '7:30 AM', '8:20 AM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Math', 'Monday', '8:20 AM', '9:10 AM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Recess', 'Monday', '9:10 AM', '9:30 AM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Language', 'Monday', '9:30 AM', '10:20 AM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Science', 'Monday', '10:20 AM', '11:10 AM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Lunch', 'Monday', '11:10 AM', '12:00 PM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Filipino', 'Monday', '12:00 PM', '12:50 PM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Araling Panlipunan', 'Monday', '12:50 PM', '1:40 PM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'MAPEH', 'Monday', '1:40 PM', '2:30 PM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Values Education', 'Monday', '2:30 PM', '3:20 PM', room_number, p_school_year);
        
        -- Tuesday Schedule
        CALL AddSchedule(p_grade_level, section_name, 'Math', 'Tuesday', '7:30 AM', '8:20 AM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Reading', 'Tuesday', '8:20 AM', '9:10 AM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Recess', 'Tuesday', '9:10 AM', '9:30 AM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Science', 'Tuesday', '9:30 AM', '10:20 AM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Language', 'Tuesday', '10:20 AM', '11:10 AM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Lunch', 'Tuesday', '11:10 AM', '12:00 PM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Filipino', 'Tuesday', '12:00 PM', '12:50 PM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Edukasyon sa Pagpapakatao', 'Tuesday', '12:50 PM', '1:40 PM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'MAPEH', 'Tuesday', '1:40 PM', '2:30 PM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Computer', 'Tuesday', '2:30 PM', '3:20 PM', room_number, p_school_year);
        
        -- Wednesday Schedule
        CALL AddSchedule(p_grade_level, section_name, 'Language', 'Wednesday', '7:30 AM', '8:20 AM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Math', 'Wednesday', '8:20 AM', '9:10 AM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Recess', 'Wednesday', '9:10 AM', '9:30 AM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Reading', 'Wednesday', '9:30 AM', '10:20 AM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Science', 'Wednesday', '10:20 AM', '11:10 AM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Lunch', 'Wednesday', '11:10 AM', '12:00 PM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Filipino', 'Wednesday', '12:00 PM', '12:50 PM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Araling Panlipunan', 'Wednesday', '12:50 PM', '1:40 PM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'MAPEH', 'Wednesday', '1:40 PM', '2:30 PM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Computer', 'Wednesday', '2:30 PM', '3:20 PM', room_number, p_school_year);
        
        -- Thursday Schedule
        CALL AddSchedule(p_grade_level, section_name, 'Math', 'Thursday', '7:30 AM', '8:20 AM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Science', 'Thursday', '8:20 AM', '9:10 AM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Recess', 'Thursday', '9:10 AM', '9:30 AM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Reading', 'Thursday', '9:30 AM', '10:20 AM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Language', 'Thursday', '10:20 AM', '11:10 AM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Lunch', 'Thursday', '11:10 AM', '12:00 PM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Filipino', 'Thursday', '12:00 PM', '12:50 PM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Edukasyon sa Pagpapakatao', 'Thursday', '12:50 PM', '1:40 PM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'MAPEH', 'Thursday', '1:40 PM', '2:30 PM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Computer', 'Thursday', '2:30 PM', '3:20 PM', room_number, p_school_year);
        
        -- Friday Schedule
        CALL AddSchedule(p_grade_level, section_name, 'Reading', 'Friday', '7:30 AM', '8:20 AM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Language', 'Friday', '8:20 AM', '9:10 AM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Recess', 'Friday', '9:10 AM', '9:30 AM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Math', 'Friday', '9:30 AM', '10:20 AM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Science', 'Friday', '10:20 AM', '11:10 AM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Lunch', 'Friday', '11:10 AM', '12:00 PM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Filipino', 'Friday', '12:00 PM', '12:50 PM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Araling Panlipunan', 'Friday', '12:50 PM', '1:40 PM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'MAPEH', 'Friday', '1:40 PM', '2:30 PM', room_number, p_school_year);
        CALL AddSchedule(p_grade_level, section_name, 'Values Education', 'Friday', '2:30 PM', '3:20 PM', room_number, p_school_year);
        
        SET i = i + 1;
    END WHILE;
END //
DELIMITER ;

-- Set the school year (adjust as needed)
SET @school_year = '2024-2025';

-- Generate schedules for each grade level
CALL GenerateGradeSchedules(1, @school_year);
CALL GenerateGradeSchedules(2, @school_year);
CALL GenerateGradeSchedules(3, @school_year);
CALL GenerateGradeSchedules(4, @school_year);
CALL GenerateGradeSchedules(5, @school_year);
CALL GenerateGradeSchedules(6, @school_year);

-- Clean up
DROP PROCEDURE IF EXISTS AddSchedule;
DROP PROCEDURE IF EXISTS GenerateGradeSchedules;

-- Verify the schedules were added
SELECT 
    grade_level,
    section,
    day_of_week,
    subject,
    TIME_FORMAT(start_time, '%h:%i %p') as start_time,
    TIME_FORMAT(end_time, '%h:%i %p') as end_time,
    room
FROM 
    `registrar_db`.`class_schedules`
WHERE 
    grade_level = 1 
    AND section = 'Section A'
ORDER BY 
    FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'),
    start_time
LIMIT 20;
