-- Normalize every utf8mb4 column, including columns whose table default is already correct.
-- Safe to run repeatedly. Run against the configured application database.

SET @target_collation = 'utf8mb4_unicode_ci';

DELIMITER $$

DROP PROCEDURE IF EXISTS normalize_all_utf8mb4_collations$$
CREATE PROCEDURE normalize_all_utf8mb4_collations()
BEGIN
    DECLARE finished BOOLEAN DEFAULT FALSE;
    DECLARE table_name_value VARCHAR(255);
    DECLARE table_cursor CURSOR FOR
        SELECT DISTINCT c.TABLE_NAME
        FROM information_schema.COLUMNS c
        WHERE c.TABLE_SCHEMA = DATABASE()
          AND c.CHARACTER_SET_NAME = 'utf8mb4'
          AND c.COLLATION_NAME <> 'utf8mb4_unicode_ci';
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET finished = TRUE;

    OPEN table_cursor;
    normalize_tables: LOOP
        FETCH table_cursor INTO table_name_value;
        IF finished THEN
            LEAVE normalize_tables;
        END IF;

        SET @normalize_sql = CONCAT(
            'ALTER TABLE `', REPLACE(table_name_value, '`', '``'),
            '` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        PREPARE normalize_stmt FROM @normalize_sql;
        EXECUTE normalize_stmt;
        DEALLOCATE PREPARE normalize_stmt;
    END LOOP;
    CLOSE table_cursor;
END$$

CALL normalize_all_utf8mb4_collations()$$
DROP PROCEDURE normalize_all_utf8mb4_collations$$

DELIMITER ;

SET @database_sql = CONCAT(
    'ALTER DATABASE `', REPLACE(DATABASE(), '`', '``'),
    '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
);
PREPARE database_stmt FROM @database_sql;
EXECUTE database_stmt;
DEALLOCATE PREPARE database_stmt;
