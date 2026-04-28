-- STORED PROCEDURE VERSION: Fix duplicate/zero IDs in faculty_pds table
-- This version uses a stored procedure for more reliable ID assignment

DELIMITER $$

-- Drop procedure if it exists
DROP PROCEDURE IF EXISTS fix_faculty_pds_ids$$

CREATE PROCEDURE fix_faculty_pds_ids()
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE v_faculty_id INT;
    DECLARE v_new_id INT;
    DECLARE cur CURSOR FOR 
        SELECT faculty_id 
        FROM faculty_pds 
        WHERE id = 0 
        ORDER BY faculty_id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    -- Get starting ID
    SET v_new_id = (SELECT COALESCE(MAX(id), 0) FROM faculty_pds WHERE id > 0);
    
    -- Fix rows with id = 0
    OPEN cur;
    read_loop: LOOP
        FETCH cur INTO v_faculty_id;
        IF done THEN
            LEAVE read_loop;
        END IF;
        
        SET v_new_id = v_new_id + 1;
        UPDATE faculty_pds 
        SET id = v_new_id 
        WHERE faculty_id = v_faculty_id AND id = 0;
    END LOOP;
    CLOSE cur;
    
    -- Reset done flag for next cursor
    SET done = FALSE;
    
    -- Fix duplicate IDs
    -- Get new starting point
    SET v_new_id = (SELECT COALESCE(MAX(id), 0) FROM faculty_pds);
    
    -- Create cursor for duplicate rows (excluding first occurrence)
    BEGIN
        DECLARE v_dup_id INT;
        DECLARE v_dup_faculty_id INT;
        DECLARE v_prev_id INT DEFAULT -1;
        DECLARE v_row_num INT DEFAULT 0;
        DECLARE cur_dup CURSOR FOR 
            SELECT id, faculty_id 
            FROM faculty_pds 
            WHERE id IN (
                SELECT id FROM (
                    SELECT id 
                    FROM faculty_pds 
                    GROUP BY id 
                    HAVING COUNT(*) > 1
                ) AS dup_ids
            )
            ORDER BY id, faculty_id;
        DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
        
        OPEN cur_dup;
        read_dup_loop: LOOP
            FETCH cur_dup INTO v_dup_id, v_dup_faculty_id;
            IF done THEN
                LEAVE read_dup_loop;
            END IF;
            
            IF v_dup_id != v_prev_id THEN
                SET v_row_num = 1;
                SET v_prev_id = v_dup_id;
            ELSE
                SET v_row_num = v_row_num + 1;
                IF v_row_num > 1 THEN
                    SET v_new_id = v_new_id + 1;
                    UPDATE faculty_pds 
                    SET id = v_new_id 
                    WHERE id = v_dup_id AND faculty_id = v_dup_faculty_id;
                END IF;
            END IF;
        END LOOP;
        CLOSE cur_dup;
    END;
END$$

DELIMITER ;

-- Run the procedure
CALL fix_faculty_pds_ids();

-- Drop the procedure after use
DROP PROCEDURE IF EXISTS fix_faculty_pds_ids;

-- Verify no duplicates remain
-- SELECT id, COUNT(*) as count 
-- FROM faculty_pds 
-- GROUP BY id 
-- HAVING count > 1;

-- Add PRIMARY KEY
ALTER TABLE `faculty_pds` 
MODIFY COLUMN `id` int(11) NOT NULL AUTO_INCREMENT,
ADD PRIMARY KEY (`id`);

