-- Remove Foreign Key Constraint from registrar_db.students table
-- This allows students to be synced even if user_id doesn't exist in login_db

USE registrar_db;

-- Drop the foreign key constraint
ALTER TABLE students DROP FOREIGN KEY students_ibfk_1;

-- Verify it's removed
SELECT 
    CONSTRAINT_NAME,
    TABLE_NAME,
    REFERENCED_TABLE_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'registrar_db'
AND TABLE_NAME = 'students'
AND CONSTRAINT_NAME LIKE '%ibfk%';

-- If the query above returns no rows, the constraint was successfully removed
