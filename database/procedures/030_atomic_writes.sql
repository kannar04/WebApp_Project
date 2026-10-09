DELIMITER $$

CREATE OR REPLACE PROCEDURE sp_user_insert(IN p_1 varchar(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_2 varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_3 varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_4 varchar(25) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_id BIGINT UNSIGNED;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_user_insert; RELEASE SAVEPOINT h2h_user_insert; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_user_insert; END IF;
    
    IF p_1 IS NULL OR TRIM(p_1)='' OR p_2 IS NULL OR LENGTH(p_2)<40 OR p_3 IS NULL OR TRIM(p_3)='' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Thông tin tài khoản không hợp lệ.';
    END IF;
    INSERT INTO users(email,password_hash,full_name,phone,status,created_at,updated_at) VALUES(LOWER(TRIM(p_1)),p_2,TRIM(p_3),p_4,'active',NOW(6),NOW(6));
    SET v_id=LAST_INSERT_ID();
    INSERT INTO user_roles(user_id,role,granted_at) VALUES(v_id,'guest',NOW(6));
    SELECT 1 __affected,v_id __insert_id;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_user_insert; END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_user_roles_replace(IN p_1 bigint unsigned, IN p_2 longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_i INT DEFAULT 0;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE v_role VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_user_roles_replace; RELEASE SAVEPOINT h2h_user_roles_replace; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_user_roles_replace; END IF;
    
    IF p_2 IS NULL OR NOT JSON_VALID(p_2) OR JSON_TYPE(p_2)<>'ARRAY' OR JSON_LENGTH(p_2)<1 OR JSON_LENGTH(p_2)>3 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Vai trò không hợp lệ.';
    END IF;
    SELECT id INTO v_lock FROM users WHERE id=p_1 AND deleted_at IS NULL FOR UPDATE;
    IF v_lock IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tài khoản không tồn tại.'; END IF;
    DELETE FROM user_roles WHERE user_id=p_1;
    WHILE v_i<JSON_LENGTH(p_2) DO
        SET v_role=JSON_UNQUOTE(JSON_EXTRACT(p_2,CONCAT('$[',v_i,']')));
        IF v_role NOT IN ('guest','host','admin') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Vai trò không hợp lệ.'; END IF;
        INSERT INTO user_roles(user_id,role,granted_at) VALUES(p_1,v_role,NOW(6)) ON DUPLICATE KEY UPDATE role=VALUES(role);
        SET v_i=v_i+1;
    END WHILE;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_user_roles_replace; END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_user_update_profile(IN p_1 varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_2 varchar(25) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_3 text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_4 bigint unsigned)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_avatar TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_user_update_profile; RELEASE SAVEPOINT h2h_user_update_profile; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_user_update_profile; END IF;
    
    SELECT avatar_url INTO v_avatar FROM users WHERE id=p_4 AND status='active' AND deleted_at IS NULL FOR UPDATE;
    CALL DST_sp_update_profile(p_4,p_1,p_2,COALESCE(p_3,v_avatar));
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_user_update_profile; END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_listing_amenities_replace(IN p_1 bigint unsigned, IN p_2 bigint unsigned, IN p_3 longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE v_id BIGINT UNSIGNED;
    DECLARE v_i INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_listing_amenities_replace; RELEASE SAVEPOINT h2h_listing_amenities_replace; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_listing_amenities_replace; END IF;
    
    SELECT id INTO v_lock FROM listings WHERE id=p_1 AND host_id=p_2 AND deleted_at IS NULL FOR UPDATE;
    IF v_lock IS NULL OR NOT EXISTS(SELECT 1 FROM users u JOIN user_roles r ON r.user_id=u.id WHERE u.id=p_2 AND u.status='active' AND r.role='host') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không có quyền sửa chỗ ở.';
    END IF;
    IF p_3 IS NULL OR NOT JSON_VALID(p_3) OR JSON_TYPE(p_3)<>'ARRAY' OR JSON_LENGTH(p_3)>100 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tiện nghi không hợp lệ.'; END IF;
    DELETE FROM listing_amenities WHERE listing_id=p_1;
    WHILE v_i<JSON_LENGTH(p_3) DO
        SET v_id=JSON_UNQUOTE(JSON_EXTRACT(p_3,CONCAT('$[',v_i,']')));
        IF NOT EXISTS(SELECT 1 FROM amenities WHERE id=v_id AND is_active=1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tiện nghi không tồn tại hoặc đã ngừng sử dụng.'; END IF;
        INSERT INTO listing_amenities(listing_id,amenity_id) VALUES(p_1,v_id) ON DUPLICATE KEY UPDATE amenity_id=VALUES(amenity_id);
        SET v_i=v_i+1;
    END WHILE;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_listing_amenities_replace; END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_listing_photo_add(IN p_1 bigint unsigned, IN p_2 text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_3 text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_4 bigint unsigned)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE v_order INT;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_listing_photo_add; RELEASE SAVEPOINT h2h_listing_photo_add; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_listing_photo_add; END IF;
    
    SELECT id INTO v_lock FROM listings WHERE id=p_1 AND host_id=p_4 AND deleted_at IS NULL FOR UPDATE;
    IF v_lock IS NULL OR NOT EXISTS(SELECT 1 FROM users u JOIN user_roles r ON r.user_id=u.id WHERE u.id=p_4 AND u.status='active' AND r.role='host') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không có quyền thêm ảnh chỗ ở.';
    END IF;
    IF p_2 IS NULL OR p_2 NOT LIKE '/uploads/%' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Đường dẫn ảnh không hợp lệ.'; END IF;
    SELECT COALESCE(MAX(sort_order),0)+1 INTO v_order FROM listing_photos WHERE listing_id=p_1;
    INSERT INTO listing_photos(listing_id,image_url,alt_text,sort_order,created_at) VALUES(p_1,p_2,p_3,v_order,NOW(6));
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_listing_photo_add; END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_listing_availability_set(IN p_1 bigint unsigned, IN p_2 bigint unsigned, IN p_3 date, IN p_4 bool, IN p_5 text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE v_conflicts INT;
    DECLARE v_ok BOOL DEFAULT FALSE;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_listing_availability_set; RELEASE SAVEPOINT h2h_listing_availability_set; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_listing_availability_set; END IF;
    
    SELECT id INTO v_lock FROM listings WHERE id=p_1 AND host_id=p_2 AND deleted_at IS NULL FOR UPDATE;
    IF p_3 IS NULL OR p_3<DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR) OR p_4 IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ngày không hợp lệ.'; END IF;
    IF v_lock IS NOT NULL AND EXISTS(SELECT 1 FROM users u JOIN user_roles r ON r.user_id=u.id WHERE u.id=p_2 AND u.status='active' AND r.role='host') THEN
        SELECT COUNT(*) INTO v_conflicts FROM bookings WHERE listing_id=p_1 AND status='confirmed' AND check_in<=p_3 AND check_out>p_3 FOR UPDATE;
        IF p_4 OR v_conflicts=0 THEN
            INSERT INTO listing_availability(listing_id,stay_date,is_open,block_reason,updated_at) VALUES(p_1,p_3,p_4,IF(p_4,NULL,p_5),NOW(6))
                ON DUPLICATE KEY UPDATE is_open=VALUES(is_open),block_reason=VALUES(block_reason),updated_at=NOW(6);
            SET v_ok=1;
        END IF;
    END IF;
    SELECT v_ok updated;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_listing_availability_set; END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_admin_listing_moderate(IN p_1 bigint unsigned, IN p_2 bigint unsigned, IN p_3 varchar(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_4 text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_admin_listing_moderate; RELEASE SAVEPOINT h2h_admin_listing_moderate; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_admin_listing_moderate; END IF;
    
    IF NOT EXISTS(SELECT 1 FROM users u JOIN user_roles r ON r.user_id=u.id WHERE u.id=p_2 AND u.status='active' AND r.role='admin') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bạn không có quyền kiểm duyệt.';
    END IF;
    SELECT id INTO v_lock FROM listings WHERE id=p_1 AND deleted_at IS NULL FOR UPDATE;
    IF v_lock IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỗ ở không tồn tại.'; END IF;
    IF p_3 IS NULL OR p_3 NOT IN ('approved','rejected') OR (p_3='rejected' AND (p_4 IS NULL OR TRIM(p_4)='')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Quyết định kiểm duyệt hoặc lý do không hợp lệ.';
    END IF;
    UPDATE listings SET moderation_status=p_3,moderation_reason=p_4,reviewed_by=p_2,reviewed_at=NOW(6),updated_at=NOW(6) WHERE id=p_1;
    INSERT INTO listing_moderation_events(listing_id,admin_id,action,reason,created_at) VALUES(p_1,p_2,IF(p_3='approved','approve','reject'),p_4,NOW(6));
    INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at)
        VALUES(p_2,'moderate_listing','listing',p_1,JSON_OBJECT('details',CONCAT(p_3,': ',p_4)),NOW(6));
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_admin_listing_moderate; END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_admin_assert_role(IN p_admin BIGINT UNSIGNED)
BEGIN
    IF NOT EXISTS(SELECT 1 FROM users u JOIN user_roles r ON r.user_id=u.id WHERE u.id=p_admin AND u.status='active' AND u.deleted_at IS NULL AND r.role='admin') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bạn không có quyền quản trị.';
    END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_admin_listing_update(IN p_1 varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_2 text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_3 varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_4 text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_5 decimal(12,2), IN p_6 decimal(12,2), IN p_7 int, IN p_8 bigint unsigned, IN p_9 bigint unsigned)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_admin_listing_update; RELEASE SAVEPOINT h2h_admin_listing_update; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_admin_listing_update; END IF;
    CALL sp_admin_assert_role(p_9);
    SELECT id INTO v_lock FROM listings WHERE id=p_8 AND deleted_at IS NULL FOR UPDATE;
    IF v_lock IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỗ ở không tồn tại.'; END IF;
    IF p_1 IS NULL OR TRIM(p_1)='' OR p_2 IS NULL OR TRIM(p_2)='' OR p_3 IS NULL OR TRIM(p_3)='' OR p_4 IS NULL OR TRIM(p_4)=''
        OR p_5 IS NULL OR p_5<=0 OR p_6 IS NULL OR p_6<0 OR p_7 IS NULL OR p_7<1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Thông tin chỗ ở không hợp lệ.'; END IF;
    UPDATE listings SET title=p_1,address=p_2,city=p_3,description=p_4,base_nightly_rate=p_5,fee_amount=p_6,max_guests=p_7,updated_at=NOW(6) WHERE id=p_8;
    INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at) VALUES(p_9,'update_listing','listing',p_8,JSON_OBJECT('details','Admin updated listing'),NOW(6));
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_admin_listing_update; END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_admin_listing_visibility(IN p_1 bool, IN p_2 bigint unsigned, IN p_3 bigint unsigned)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_admin_listing_visibility; RELEASE SAVEPOINT h2h_admin_listing_visibility; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_admin_listing_visibility; END IF;
    CALL sp_admin_assert_role(p_3);
    SELECT id INTO v_lock FROM listings WHERE id=p_2 AND deleted_at IS NULL FOR UPDATE;
    IF v_lock IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỗ ở không tồn tại.'; END IF;
    IF p_1 IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Hiển thị không hợp lệ.'; END IF;
    UPDATE listings SET is_visible=p_1,updated_at=NOW(6) WHERE id=p_2;
    INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at) VALUES(p_3,IF(p_1,'restore_listing','hide_listing'),'listing',p_2,JSON_OBJECT('details',CONCAT('visibility=',p_1)),NOW(6));
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_admin_listing_visibility; END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_admin_listing_soft_delete(IN p_1 bigint unsigned, IN p_2 bigint unsigned)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_admin_listing_soft_delete; RELEASE SAVEPOINT h2h_admin_listing_soft_delete; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_admin_listing_soft_delete; END IF;
    CALL sp_admin_assert_role(p_2);
    SELECT id INTO v_lock FROM listings WHERE id=p_1 AND deleted_at IS NULL FOR UPDATE;
    IF v_lock IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỗ ở không tồn tại.'; END IF;
    UPDATE listings SET is_visible=0,deleted_at=NOW(6),updated_at=NOW(6) WHERE id=p_1;
    INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at) VALUES(p_2,'delete_listing','listing',p_1,JSON_OBJECT('details','Soft delete'),NOW(6));
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_admin_listing_soft_delete; END IF;
END$$

DELIMITER ;
