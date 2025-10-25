-- First, ensure the table has all required columns
ALTER TABLE `registrar_db`.`class_sections` 
ADD COLUMN IF NOT EXISTS `room_number` VARCHAR(20) NULL DEFAULT NULL AFTER `adviser_id`,
ADD COLUMN IF NOT EXISTS `schedule` TEXT NULL DEFAULT NULL AFTER `room_number`,
ADD COLUMN IF NOT EXISTS `current_students` INT NOT NULL DEFAULT 0 AFTER `schedule`,
ADD COLUMN IF NOT EXISTS `max_students` INT NOT NULL DEFAULT 40 AFTER `current_students`;

-- Clear existing sections (be careful with this in production)
-- TRUNCATE TABLE `registrar_db`.`class_sections`;

-- Insert sections for each grade level (1-6)
INSERT INTO `registrar_db`.`class_sections` 
(`grade_level`, `section`, `room_number`, `max_students`, `status`)
VALUES
-- Grade 1 Sections
(1, 'Section A', 'G1-101', 50, 'active'),
(1, 'Section B', 'G1-102', 50, 'active'),
(1, 'Section C', 'G1-103', 50, 'active'),
(1, 'Section D', 'G1-104', 50, 'active'),
(1, 'Section E', 'G1-105', 50, 'active'),
(1, 'Section F', 'G1-106', 50, 'active'),
(1, 'Section G', 'G1-107', 50, 'active'),
(1, 'Section H', 'G1-108', 50, 'active'),
(1, 'Section I', 'G1-109', 50, 'active'),
(1, 'Section J', 'G1-110', 50, 'active'),

-- Grade 2 Sections
(2, 'Section A', 'G2-201', 50, 'active'),
(2, 'Section B', 'G2-202', 50, 'active'),
(2, 'Section C', 'G2-203', 50, 'active'),
(2, 'Section D', 'G2-204', 50, 'active'),
(2, 'Section E', 'G2-205', 50, 'active'),
(2, 'Section F', 'G2-206', 50, 'active'),
(2, 'Section G', 'G2-207', 50, 'active'),
(2, 'Section H', 'G2-208', 50, 'active'),
(2, 'Section I', 'G2-209', 50, 'active'),
(2, 'Section J', 'G2-210', 50, 'active'),

-- Grade 3 Sections
(3, 'Section A', 'G3-301', 50, 'active'),
(3, 'Section B', 'G3-302', 50, 'active'),
(3, 'Section C', 'G3-303', 50, 'active'),
(3, 'Section D', 'G3-304', 50, 'active'),
(3, 'Section E', 'G3-305', 50, 'active'),
(3, 'Section F', 'G3-306', 50, 'active'),
(3, 'Section G', 'G3-307', 50, 'active'),
(3, 'Section H', 'G3-308', 50, 'active'),
(3, 'Section I', 'G3-309', 50, 'active'),
(3, 'Section J', 'G3-310', 50, 'active'),

-- Grade 4 Sections
(4, 'Section A', 'G4-401', 50, 'active'),
(4, 'Section B', 'G4-402', 50, 'active'),
(4, 'Section C', 'G4-403', 50, 'active'),
(4, 'Section D', 'G4-404', 50, 'active'),
(4, 'Section E', 'G4-405', 50, 'active'),
(4, 'Section F', 'G4-406', 50, 'active'),
(4, 'Section G', 'G4-407', 50, 'active'),
(4, 'Section H', 'G4-408', 50, 'active'),
(4, 'Section I', 'G4-409', 50, 'active'),
(4, 'Section J', 'G4-410', 50, 'active'),

-- Grade 5 Sections
(5, 'Section A', 'G5-501', 50, 'active'),
(5, 'Section B', 'G5-502', 50, 'active'),
(5, 'Section C', 'G5-503', 50, 'active'),
(5, 'Section D', 'G5-504', 50, 'active'),
(5, 'Section E', 'G5-505', 50, 'active'),
(5, 'Section F', 'G5-506', 50, 'active'),
(5, 'Section G', 'G5-507', 50, 'active'),
(5, 'Section H', 'G5-508', 50, 'active'),
(5, 'Section I', 'G5-509', 50, 'active'),
(5, 'Section J', 'G5-510', 50, 'active'),

-- Grade 6 Sections
(6, 'Section A', 'G6-601', 50, 'active'),
(6, 'Section B', 'G6-602', 50, 'active'),
(6, 'Section C', 'G6-603', 50, 'active'),
(6, 'Section D', 'G6-604', 50, 'active'),
(6, 'Section E', 'G6-605', 50, 'active'),
(6, 'Section F', 'G6-606', 50, 'active'),
(6, 'Section G', 'G6-607', 50, 'active'),
(6, 'Section H', 'G6-608', 50, 'active'),
(6, 'Section I', 'G6-609', 50, 'active'),
(6, 'Section J', 'G6-610', 50, 'active');

-- Verify the sections were added
SELECT 
    grade_level,
    section,
    room_number,
    max_students,
    status
FROM 
    `registrar_db`.`class_sections`
ORDER BY 
    grade_level, 
    section;
