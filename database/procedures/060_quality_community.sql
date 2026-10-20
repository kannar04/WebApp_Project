-- Community workflows: authorization in routines and transactional admin audit.
DELIMITER $$

CREATE OR REPLACE PROCEDURE sp_host_reviews(IN p_actor BIGINT UNSIGNED)
BEGIN
    SELECT r.*,b.listing_id,l.title,u.full_name guest_name FROM reviews r JOIN bookings b ON b.id=r.booking_id
    JOIN listings l ON l.id=b.listing_id JOIN users u ON u.id=b.guest_id
    WHERE l.host_id=p_actor AND r.moderation_status='visible'
        AND EXISTS(SELECT 1 FROM users h JOIN user_roles ur ON ur.user_id=h.id WHERE h.id=p_actor AND h.status='active' AND ur.role='host')
    ORDER BY r.created_at DESC;
END$$

CREATE OR REPLACE PROCEDURE sp_host_review_reply(IN p_id BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED, IN p_reply TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    IF p_reply IS NULL OR TRIM(p_reply)='' OR CHAR_LENGTH(p_reply)>5000 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Phản hồi cần từ 1 đến 5000 ký tự.'; END IF;
    UPDATE reviews r JOIN bookings b ON b.id=r.booking_id JOIN listings l ON l.id=b.listing_id
        SET r.host_reply=TRIM(p_reply),r.host_replied_at=NOW(6),r.updated_at=NOW(6)
        WHERE r.id=p_id AND l.host_id=p_actor AND r.moderation_status='visible'
            AND EXISTS(SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id=u.id WHERE u.id=p_actor AND u.status='active' AND ur.role='host');
    IF ROW_COUNT()=0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không có quyền phản hồi đánh giá này.'; END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_admin_reviews(IN p_actor BIGINT UNSIGNED)
BEGIN
    CALL sp_admin_assert_role(p_actor);
    SELECT r.*,l.title,u.full_name guest_name FROM reviews r JOIN bookings b ON b.id=r.booking_id
    JOIN listings l ON l.id=b.listing_id JOIN users u ON u.id=b.guest_id ORDER BY r.created_at DESC LIMIT 200;
END$$

CREATE OR REPLACE PROCEDURE sp_admin_review_moderate(IN p_id BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED, IN p_status VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_review_mod; RELEASE SAVEPOINT h2h_review_mod; END IF; END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_review_mod; END IF;
    CALL sp_admin_assert_role(p_actor);
    IF p_status IS NULL OR p_status NOT IN ('hidden','visible') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Trạng thái đánh giá không hợp lệ.'; END IF;
    SELECT id INTO v_lock FROM reviews WHERE id=p_id FOR UPDATE;
    IF v_lock IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Đánh giá không tồn tại.'; END IF;
    -- Soft removal retains the unique booking review and evidence for reports.
    UPDATE reviews SET moderation_status=p_status,updated_at=NOW(6) WHERE id=p_id;
    CALL sp_admin_audit_add(p_actor,'moderate_review','review',p_id,JSON_OBJECT('status',p_status));
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_review_mod; END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_report_create(IN p_actor BIGINT UNSIGNED, IN p_listing BIGINT UNSIGNED, IN p_booking BIGINT UNSIGNED,
    IN p_category VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_description TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    IF NOT EXISTS(SELECT 1 FROM users WHERE id=p_actor AND status='active' AND deleted_at IS NULL)
        OR p_category IS NULL OR p_category NOT IN ('misleading','fraud','complaint','other')
        OR p_description IS NULL OR CHAR_LENGTH(TRIM(p_description))<10 OR CHAR_LENGTH(p_description)>5000 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Báo cáo cần nội dung từ 10 đến 5000 ký tự và danh mục hợp lệ.';
    END IF;
    IF p_booking IS NOT NULL THEN
        IF NOT EXISTS(SELECT 1 FROM bookings WHERE id=p_booking AND guest_id=p_actor) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không có quyền khiếu nại booking này.'; END IF;
        SET p_listing=(SELECT listing_id FROM bookings WHERE id=p_booking);
    ELSEIF p_listing IS NULL OR NOT EXISTS(SELECT 1 FROM listings l JOIN users h ON h.id=l.host_id WHERE l.id=p_listing AND l.deleted_at IS NULL AND l.moderation_status='approved' AND l.is_visible=1 AND h.status='active') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỗ ở không khả dụng để báo cáo.';
    END IF;
    INSERT INTO reports(reporter_id,listing_id,booking_id,category,description,status,created_at)
        VALUES(p_actor,p_listing,p_booking,p_category,TRIM(p_description),'open',NOW(6));
    SELECT 1 __affected,LAST_INSERT_ID() __insert_id;
END$$

CREATE OR REPLACE PROCEDURE sp_reports_for_user(IN p_actor BIGINT UNSIGNED)
BEGIN
    SELECT r.*,l.title FROM reports r LEFT JOIN listings l ON l.id=r.listing_id WHERE r.reporter_id=p_actor
        AND EXISTS(SELECT 1 FROM users WHERE id=p_actor AND status='active') ORDER BY r.created_at DESC;
END$$

CREATE OR REPLACE PROCEDURE sp_admin_reports(IN p_actor BIGINT UNSIGNED)
BEGIN
    CALL sp_admin_assert_role(p_actor);
    SELECT r.*,l.title,u.full_name reporter_name FROM reports r LEFT JOIN listings l ON l.id=r.listing_id
    JOIN users u ON u.id=r.reporter_id ORDER BY r.created_at DESC LIMIT 200;
END$$

CREATE OR REPLACE PROCEDURE sp_admin_report_resolve(IN p_id BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED,
    IN p_status VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_resolution TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_report_resolve; RELEASE SAVEPOINT h2h_report_resolve; END IF; END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_report_resolve; END IF;
    CALL sp_admin_assert_role(p_actor);
    SELECT id INTO v_lock FROM reports WHERE id=p_id FOR UPDATE;
    IF v_lock IS NULL OR p_status IS NULL OR p_status NOT IN ('open','investigating','resolved','dismissed')
        OR CHAR_LENGTH(COALESCE(p_resolution,''))>5000
        OR (p_status IN ('resolved','dismissed') AND TRIM(COALESCE(p_resolution,''))='') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Trạng thái hoặc kết luận xử lý không hợp lệ.';
    END IF;
    UPDATE reports SET status=p_status,assigned_admin_id=p_actor,resolution=NULLIF(TRIM(p_resolution),''),
        resolved_at=IF(p_status IN ('resolved','dismissed'),NOW(6),NULL) WHERE id=p_id;
    CALL sp_admin_audit_add(p_actor,'resolve_report','report',p_id,JSON_OBJECT('status',p_status));
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_report_resolve; END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_admin_catalog(IN p_actor BIGINT UNSIGNED, IN p_kind VARCHAR(12))
BEGIN
    CALL sp_admin_assert_role(p_actor);
    IF p_kind='type' THEN SELECT id,name,description detail,is_active FROM property_types ORDER BY id;
    ELSEIF p_kind='amenity' THEN SELECT id,name,icon_key detail,is_active FROM amenities ORDER BY id;
    ELSE SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Loại danh mục không hợp lệ.'; END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_admin_catalog_save(IN p_actor BIGINT UNSIGNED, IN p_kind VARCHAR(12), IN p_id BIGINT UNSIGNED,
    IN p_name VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_detail TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_active BOOL)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_catalog_save; RELEASE SAVEPOINT h2h_catalog_save; END IF; END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_catalog_save; END IF;
    CALL sp_admin_assert_role(p_actor);
    IF p_name IS NULL OR TRIM(p_name)='' OR CHAR_LENGTH(p_name)>80 OR p_active IS NULL OR p_active NOT IN (0,1) OR p_kind IS NULL OR p_kind NOT IN ('type','amenity')
        OR (p_kind='amenity' AND CHAR_LENGTH(COALESCE(p_detail,''))>80) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Thông tin danh mục không hợp lệ.'; END IF;
    IF p_kind='type' THEN
        IF p_id=0 THEN INSERT INTO property_types(name,description,is_active) VALUES(TRIM(p_name),p_detail,p_active); SET p_id=LAST_INSERT_ID();
        ELSE
            SELECT id INTO v_lock FROM property_types WHERE id=p_id FOR UPDATE;
            IF v_lock IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Danh mục không tồn tại.'; END IF;
            UPDATE property_types SET name=TRIM(p_name),description=p_detail,is_active=p_active WHERE id=p_id;
        END IF;
    ELSE
        IF p_id=0 THEN INSERT INTO amenities(name,icon_key,is_active) VALUES(TRIM(p_name),p_detail,p_active); SET p_id=LAST_INSERT_ID();
        ELSE
            SELECT id INTO v_lock FROM amenities WHERE id=p_id FOR UPDATE;
            IF v_lock IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tiện nghi không tồn tại.'; END IF;
            UPDATE amenities SET name=TRIM(p_name),icon_key=p_detail,is_active=p_active WHERE id=p_id;
        END IF;
    END IF;
    CALL sp_admin_audit_add(p_actor,'save_catalog',p_kind,p_id,JSON_OBJECT('active',p_active));
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_catalog_save; END IF;
    SELECT p_id id;
END$$

CREATE OR REPLACE PROCEDURE sp_user_password_change(IN p_actor BIGINT UNSIGNED,
    IN p_expected VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_new VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_password_change; RELEASE SAVEPOINT h2h_password_change; END IF; END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_password_change; END IF;
    IF p_new IS NULL OR LENGTH(p_new)<40 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Mật khẩu mới không hợp lệ.'; END IF;
    SELECT id INTO v_lock FROM users WHERE id=p_actor AND password_hash=p_expected AND status='active' AND deleted_at IS NULL FOR UPDATE;
    IF v_lock IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Mật khẩu hiện tại đã thay đổi hoặc tài khoản không khả dụng.'; END IF;
    UPDATE users SET password_hash=p_new,updated_at=NOW(6) WHERE id=p_actor AND password_hash=p_expected AND status='active' AND deleted_at IS NULL;
    IF ROW_COUNT()<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Mật khẩu hiện tại đã thay đổi hoặc tài khoản không khả dụng.'; END IF;
    UPDATE password_reset_tokens SET used_at=NOW(6) WHERE user_id=p_actor AND used_at IS NULL;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_password_change; END IF;
END$$

DELIMITER ;
