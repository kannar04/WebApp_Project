-- MariaDB collation repair. Select the application database before running.
-- Back up schema and data first: ALTER TABLE commits implicitly and may rebuild tables.
-- Retry is supported. Restore the backup to roll back original collation semantics.
-- Preserve ASCII and binary-collated columns, defaults, nullability and comments.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DELIMITER $$
DROP PROCEDURE IF EXISTS normalize_all_utf8mb4_collations$$
CREATE PROCEDURE normalize_all_utf8mb4_collations()
BEGIN
    DECLARE finished BOOLEAN DEFAULT FALSE;
    DECLARE table_name_value VARCHAR(255);
    DECLARE column_name_value VARCHAR(255);
    DECLARE column_type_value LONGTEXT;
    DECLARE nullable_value VARCHAR(3);
    DECLARE default_value LONGTEXT;
    DECLARE comment_value LONGTEXT;
    DECLARE column_cursor CURSOR FOR
        SELECT c.TABLE_NAME,c.COLUMN_NAME,c.COLUMN_TYPE,c.IS_NULLABLE,c.COLUMN_DEFAULT,c.COLUMN_COMMENT
        FROM information_schema.COLUMNS c
        JOIN information_schema.TABLES t
          ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME
        WHERE c.TABLE_SCHEMA=DATABASE() AND t.TABLE_TYPE='BASE TABLE'
          AND c.CHARACTER_SET_NAME='utf8mb4'
          AND c.COLLATION_NAME<>'utf8mb4_unicode_ci'
          AND RIGHT(c.COLLATION_NAME,4)<>'_bin'
        ORDER BY c.TABLE_NAME,c.ORDINAL_POSITION;
    DECLARE table_cursor CURSOR FOR
        SELECT TABLE_NAME FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'
          AND TABLE_COLLATION LIKE 'utf8mb4%'
          AND TABLE_COLLATION<>'utf8mb4_unicode_ci'
          AND RIGHT(TABLE_COLLATION,4)<>'_bin';
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET finished=TRUE;

    IF DATABASE() IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Select the application database before running this migration.';
    END IF;
    -- Do not silently lose generated/invisible column attributes during MODIFY.
    IF EXISTS(
        SELECT 1 FROM information_schema.COLUMNS c
        JOIN information_schema.TABLES t
          ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME
        WHERE c.TABLE_SCHEMA=DATABASE() AND t.TABLE_TYPE='BASE TABLE'
          AND c.CHARACTER_SET_NAME='utf8mb4'
          AND c.COLLATION_NAME<>'utf8mb4_unicode_ci'
          AND RIGHT(c.COLLATION_NAME,4)<>'_bin'
          AND COALESCE(c.EXTRA,'')<>''
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Review special text column attributes before normalization.';
    END IF;
    OPEN column_cursor;
    normalize_columns: LOOP
        FETCH column_cursor INTO table_name_value,column_name_value,column_type_value,nullable_value,default_value,comment_value;
        IF finished THEN LEAVE normalize_columns; END IF;
        -- MariaDB COLUMN_DEFAULT contains quoted literals or SQL expressions.
        SET @normalize_sql=CONCAT(
            'ALTER TABLE `',REPLACE(table_name_value,'`','``'),
            '` MODIFY COLUMN `',REPLACE(column_name_value,'`','``'),'` ',
            column_type_value,' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            IF(nullable_value='YES',' NULL',' NOT NULL'),
            IF(default_value IS NULL,'',CONCAT(' DEFAULT ',default_value)),
            ' COMMENT ',QUOTE(comment_value)
        );
        PREPARE normalize_stmt FROM @normalize_sql;
        EXECUTE normalize_stmt;
        DEALLOCATE PREPARE normalize_stmt;
    END LOOP;
    CLOSE column_cursor;
    SET finished=FALSE;
    OPEN table_cursor;
    normalize_defaults: LOOP
        FETCH table_cursor INTO table_name_value;
        IF finished THEN LEAVE normalize_defaults; END IF;
        SET @normalize_sql=CONCAT(
            'ALTER TABLE `',REPLACE(table_name_value,'`','``'),
            '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
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

SET @database_sql=CONCAT(
    'ALTER DATABASE `',REPLACE(DATABASE(),'`','``'),
    '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
);
PREPARE database_stmt FROM @database_sql;
EXECUTE database_stmt;
DEALLOCATE PREPARE database_stmt;

