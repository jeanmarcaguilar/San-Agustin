-- First, let's create a temporary table to store the subject mappings
CREATE TEMPORARY TABLE temp_subject_mapping (
    id INT AUTO_INCREMENT PRIMARY KEY,
    grade_level INT NOT NULL,
    section_id INT NOT NULL,
    subject_name VARCHAR(100) NOT NULL,
    subject_order INT NOT NULL,
    UNIQUE KEY unique_mapping (grade_level, section_id, subject_order)
);

-- Insert base subjects for each grade level with random ordering per section
INSERT INTO temp_subject_mapping (grade_level, section_id, subject_name, subject_order)
SELECT 
    cs.grade_level,
    cs.section_id,
    s.subject_name,
    @row_number := IF(@current_grade = cs.grade_level AND @current_section = cs.section_id, 
                     @row_number + 1, 
                     1 + FLOOR(RAND() * 10) % 5) AS subject_order,
    @current_grade := cs.grade_level,
    @current_section := cs.section_id
FROM 
    (SELECT DISTINCT grade_level, section_id FROM class_sections) cs
CROSS JOIN 
    (SELECT DISTINCT subject_name FROM subjects) s
CROSS JOIN
    (SELECT @row_number := 0, @current_grade := 0, @current_section := 0) r
ORDER BY 
    cs.grade_level, cs.section_id, RAND();

-- Update the class_schedules table with the randomized subjects
UPDATE class_schedules cs
JOIN (
    SELECT 
        cs.id,
        tsm.subject_name
    FROM 
        class_schedules cs
    JOIN 
        class_sections sec ON cs.grade_level = sec.grade_level AND cs.section = sec.section
    JOIN 
        temp_subject_mapping tsm ON cs.grade_level = tsm.grade_level 
                                AND sec.id = tsm.section_id
                                AND (cs.id % (SELECT COUNT(DISTINCT subject_name) FROM subjects)) + 1 = tsm.subject_order
) AS updates ON cs.id = updates.id
SET cs.subject = updates.subject_name;

-- Update the subject_id in class_schedules to match the subjects table
UPDATE class_schedules cs
JOIN subjects s ON cs.subject = s.subject_name AND cs.grade_level = s.grade_level
SET cs.subject_id = s.id
WHERE cs.subject_id IS NULL;

-- Clean up
DROP TEMPORARY TABLE IF EXISTS temp_subject_mapping;

-- Update the class_schedules to ensure no two consecutive periods have the same subject
UPDATE class_schedules cs1
JOIN class_schedules cs2 ON cs1.grade_level = cs2.grade_level 
                        AND cs1.section = cs2.section 
                        AND cs1.day_of_week = cs2.day_of_week
                        AND cs1.start_time < cs2.start_time
                        AND NOT EXISTS (
                            SELECT 1 FROM class_schedules cs3 
                            WHERE cs3.grade_level = cs1.grade_level 
                            AND cs3.section = cs1.section 
                            AND cs3.day_of_week = cs1.day_of_week
                            AND cs3.start_time > cs1.start_time 
                            AND cs3.start_time < cs2.start_time
                        )
JOIN subjects s ON s.grade_level = cs1.grade_level 
                AND s.subject_name != cs1.subject 
                AND s.subject_name != cs2.subject
SET cs2.subject = s.subject_name, cs2.subject_id = s.id
WHERE cs1.subject = cs2.subject;

-- Update teacher assignments to be more varied
UPDATE class_schedules cs
JOIN (
    SELECT 
        cs.id,
        t.id as teacher_id
    FROM 
        class_schedules cs
    JOIN 
        teachers t
    WHERE 
        t.status = 'Active'
    ORDER BY 
        RAND()
    LIMIT 1
) AS teacher_updates ON cs.id = teacher_updates.id
SET cs.teacher_id = teacher_updates.teacher_id
WHERE cs.teacher_id IS NULL;

-- Ensure no teacher has overlapping classes
UPDATE class_schedules cs1
JOIN class_schedules cs2 ON cs1.teacher_id = cs2.teacher_id
    AND cs1.day_of_week = cs2.day_of_week
    AND cs1.id != cs2.id
    AND (
        (cs1.start_time BETWEEN cs2.start_time AND cs2.end_time)
        OR (cs1.end_time BETWEEN cs2.start_time AND cs2.end_time)
        OR (cs2.start_time BETWEEN cs1.start_time AND cs1.end_time)
    )
JOIN (
    SELECT 
        t.id as teacher_id,
        COUNT(*) as teacher_count
    FROM 
        teachers t
    JOIN 
        class_schedules cs ON t.id = cs.teacher_id
    GROUP BY 
        t.id
    ORDER BY 
        teacher_count
    LIMIT 1
) AS available_teacher
SET cs2.teacher_id = available_teacher.teacher_id
WHERE cs1.id < cs2.id;
