-- Normalize legacy utf8mb4 tables to the collation used by current migrations.
-- Back up the database before running this migration: ALTER TABLE may rebuild tables.
-- This targets tables whose default collation is utf8mb4; review ASCII/binary
-- columns separately if the live schema contains legacy overrides.

SET @target_collation = 'utf8mb4_unicode_ci';

DELIMITER $$
CREATE PROCEDURE normalize_utf8mb4_collation()
BEGIN
    DECLARE finished BOOLEAN DEFAULT FALSE;
    DECLARE table_name_value VARCHAR(255);
    DECLARE table_cursor CURSOR FOR
        SELECT TABLE_NAME
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_TYPE = 'BASE TABLE'
          AND TABLE_COLLATION LIKE 'utf8mb4%'
          AND TABLE_COLLATION <> 'utf8mb4_unicode_ci';
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET finished = TRUE;

    OPEN table_cursor;
    normalize_tables: LOOP
        FETCH table_cursor INTO table_name_value;
        IF finished THEN LEAVE normalize_tables; END IF;
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
CALL normalize_utf8mb4_collation()$$
DROP PROCEDURE normalize_utf8mb4_collation$$
DELIMITER ;

-- Keep the database default aligned for tables created after this migration.
SET @database_sql = CONCAT(
    'ALTER DATABASE `', REPLACE(DATABASE(), '`', '``'),
    '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
);
PREPARE database_stmt FROM @database_sql;
EXECUTE database_stmt;
DEALLOCATE PREPARE database_stmt;

