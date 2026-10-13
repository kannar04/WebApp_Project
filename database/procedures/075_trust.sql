-- Nice-to-have trust: identity verification, trusted Host badge, check-in guide.
-- Authorization lives in every routine; CCCD numbers are masked as soon as an Admin decides.
DELIMITER $$

CREATE OR REPLACE PROCEDURE sp_identity_verification_submit(IN p_actor BIGINT UNSIGNED,
    IN p_full_name VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_phone VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_id_number VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE v_name VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_identity_submit; RELEASE SAVEPOINT h2h_identity_submit; END IF; END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_identity_submit; END IF;
    SET v_name=TRIM(p_full_name);
    IF v_name IS NULL OR CHAR_LENGTH(v_name)<2 OR CHAR_LENGTH(v_name)>150 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Họ tên trên CCCD cần từ 2 đến 150 ký tự.';
    END IF;
    IF p_phone IS NULL OR p_phone NOT REGEXP '^0[35789][0-9]{8}$' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Số điện thoại di động Việt Nam không hợp lệ (10 chữ số, bắt đầu bằng 03, 05, 07, 08 hoặc 09).';
    END IF;
    IF p_id_number IS NULL OR p_id_number NOT REGEXP '^[0-9]{12}$' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Số CCCD phải gồm đúng 12 chữ số.';
    END IF;
    -- The account row lock serializes concurrent submissions: at most one pending request per user.
    SELECT id INTO v_lock FROM users WHERE id=p_actor AND status='active' AND deleted_at IS NULL FOR UPDATE;
    IF v_lock IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tài khoản không hợp lệ hoặc đã bị khóa.'; END IF;
    IF EXISTS(SELECT 1 FROM identity_verifications WHERE user_id=p_actor AND status='approved') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tài khoản đã được xác minh danh tính.';
    END IF;
    IF EXISTS(SELECT 1 FROM identity_verifications WHERE user_id=p_actor AND status='pending') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bạn đã có một yêu cầu xác minh đang chờ duyệt.';
    END IF;
    INSERT INTO identity_verifications(user_id,full_name_on_card,phone,id_number,status,created_at,updated_at)
        VALUES(p_actor,v_name,p_phone,p_id_number,'pending',NOW(6),NOW(6));
    SELECT 1 __affected,LAST_INSERT_ID() __insert_id;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_identity_submit; END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_identity_verification_status(IN p_actor BIGINT UNSIGNED)
BEGIN
    -- The owner only ever receives the masked number, even while the request is pending.
    SELECT v.id,v.status,v.full_name_on_card,v.phone,CONCAT('********',RIGHT(v.id_number,4)) id_number_masked,
        v.decision_reason,v.created_at,v.reviewed_at
    FROM identity_verifications v JOIN users u ON u.id=v.user_id
    WHERE v.user_id=p_actor AND u.status='active' AND u.deleted_at IS NULL
    ORDER BY v.status='approved' DESC,v.created_at DESC,v.id DESC LIMIT 1;
END$$

CREATE OR REPLACE PROCEDURE sp_admin_identity_verifications(IN p_admin BIGINT UNSIGNED,
    IN p_status VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_query VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    CALL sp_admin_assert_role(p_admin);
    IF p_status IS NULL OR p_status NOT IN ('','pending','approved','rejected') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bộ lọc trạng thái xác minh không hợp lệ.';
    END IF;
    -- Full CCCD is only projected for pending rows; decided rows are already masked in storage.
    SELECT v.id,v.user_id,u.email,u.full_name account_name,v.full_name_on_card,v.phone,
        CASE WHEN v.status='pending' THEN v.id_number ELSE CONCAT('********',RIGHT(v.id_number,4)) END id_number,
        v.status,v.decision_reason,v.created_at,v.reviewed_at,a.full_name reviewer_name
    FROM identity_verifications v JOIN users u ON u.id=v.user_id LEFT JOIN users a ON a.id=v.reviewed_by
    WHERE (p_status='' OR v.status=p_status)
        AND (p_query IS NULL OR p_query='' OR u.email LIKE CONCAT('%',p_query,'%') OR u.full_name LIKE CONCAT('%',p_query,'%')
            OR v.full_name_on_card LIKE CONCAT('%',p_query,'%'))
    ORDER BY v.status='pending' DESC,IF(v.status='pending',v.created_at,NULL),v.created_at DESC,v.id DESC LIMIT 200;
END$$

CREATE OR REPLACE PROCEDURE sp_admin_identity_verification_decide(IN p_id BIGINT UNSIGNED, IN p_admin BIGINT UNSIGNED,
    IN p_decision VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_reason TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_user BIGINT UNSIGNED;
    DECLARE v_status VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_reason TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_identity_decide; RELEASE SAVEPOINT h2h_identity_decide; END IF; END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_identity_decide; END IF;
    CALL sp_admin_assert_role(p_admin);
    IF p_decision IS NULL OR p_decision NOT IN ('approved','rejected') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Quyết định xác minh không hợp lệ.';
    END IF;
    SET v_reason=NULLIF(TRIM(p_reason),'');
    IF p_decision='rejected' AND (v_reason IS NULL OR CHAR_LENGTH(v_reason)<5) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Vui lòng nhập lý do từ chối (ít nhất 5 ký tự).';
    END IF;
    IF CHAR_LENGTH(v_reason)>1000 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ghi chú quyết định tối đa 1000 ký tự.'; END IF;
    SELECT user_id,status INTO v_user,v_status FROM identity_verifications WHERE id=p_id FOR UPDATE;
    IF v_user IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Yêu cầu xác minh không tồn tại.'; END IF;
    IF v_status<>'pending' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Yêu cầu xác minh này đã được xử lý.'; END IF;
    IF v_user=p_admin THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Admin không thể tự duyệt yêu cầu xác minh của chính mình.'; END IF;
    UPDATE identity_verifications SET status=p_decision,decision_reason=v_reason,reviewed_by=p_admin,reviewed_at=NOW(6),updated_at=NOW(6),
        id_number=CONCAT('********',RIGHT(id_number,4))
    WHERE id=p_id;
    -- The audit trail never stores the CCCD number.
    INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at)
        VALUES(p_admin,IF(p_decision='approved','approve_identity','reject_identity'),'identity_verification',p_id,
            JSON_OBJECT('user_id',v_user,'status',p_decision,'reason',v_reason),NOW(6));
    INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
        VALUES(v_user,NULL,'in_app',CONCAT('identity_',p_decision),'Xác minh danh tính',
            IF(p_decision='approved','Danh tính của bạn đã được xác minh. Huy hiệu “Đã xác minh” hiển thị cạnh tên của bạn.',
                CONCAT('Yêu cầu xác minh danh tính chưa được chấp nhận. Lý do: ',v_reason,' Bạn có thể gửi lại trong trang Tài khoản.')),
            'sent',1,NOW(6));
    SELECT 1 __affected,p_id __insert_id;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_identity_decide; END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_trust_badges(IN p_user_ids VARCHAR(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_actor BIGINT UNSIGNED)
BEGIN
    -- Trusted Host thresholds (single source of truth; the UI help text reads these columns).
    DECLARE v_min_reviews INT DEFAULT 3;
    DECLARE v_min_rating DECIMAL(3,2) DEFAULT 4.50;
    DECLARE v_max_cancel_pct DECIMAL(5,2) DEFAULT 10.00;
    DECLARE v_min_decided INT DEFAULT 3;
    DECLARE v_window_months INT DEFAULT 12;
    DECLARE v_admin BOOL DEFAULT FALSE;
    IF p_user_ids IS NULL OR p_user_ids NOT REGEXP '^[0-9]{1,20}(,[0-9]{1,20}){0,199}$' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Danh sách tài khoản không hợp lệ.';
    END IF;
    SET v_admin=EXISTS(SELECT 1 FROM users u JOIN user_roles r ON r.user_id=u.id
        WHERE u.id=p_actor AND u.status='active' AND u.deleted_at IS NULL AND r.role='admin');
    -- Badges are public; cancellation metrics are returned only to the Host themself or an Admin.
    SELECT m.user_id,m.is_verified,m.is_host,
        (m.is_host=1 AND m.review_count>=v_min_reviews AND m.average_rating>=v_min_rating
            AND (m.decided_requests<v_min_decided OR m.host_side_cancellations*100<=v_max_cancel_pct*m.decided_requests)) is_trusted_host,
        m.review_count,ROUND(m.average_rating,2) average_rating,
        CASE WHEN m.user_id=p_actor OR v_admin THEN m.decided_requests END decided_requests,
        CASE WHEN m.user_id=p_actor OR v_admin THEN m.host_side_cancellations END host_side_cancellations,
        CASE WHEN m.user_id=p_actor OR v_admin THEN ROUND(100*m.host_side_cancellations/NULLIF(m.decided_requests,0),1) END cancel_pct,
        v_min_reviews min_reviews,v_min_rating min_rating,v_max_cancel_pct max_cancel_pct,v_min_decided min_decided_requests,v_window_months window_months
    FROM (
        SELECT u.id user_id,
            EXISTS(SELECT 1 FROM identity_verifications v WHERE v.user_id=u.id AND v.status='approved') is_verified,
            EXISTS(SELECT 1 FROM user_roles r WHERE r.user_id=u.id AND r.role='host') is_host,
            (SELECT COUNT(*) FROM reviews rv JOIN bookings b ON b.id=rv.booking_id JOIN listings l ON l.id=b.listing_id
                WHERE l.host_id=u.id AND rv.moderation_status='visible') review_count,
            COALESCE((SELECT AVG(rv.rating) FROM reviews rv JOIN bookings b ON b.id=rv.booking_id JOIN listings l ON l.id=b.listing_id
                WHERE l.host_id=u.id AND rv.moderation_status='visible'),0) average_rating,
            (SELECT COUNT(*) FROM bookings b JOIN listings l ON l.id=b.listing_id
                WHERE l.host_id=u.id AND b.status<>'pending' AND b.created_at>=NOW(6)-INTERVAL v_window_months MONTH) decided_requests,
            (SELECT COUNT(*) FROM bookings b JOIN listings l ON l.id=b.listing_id
                WHERE l.host_id=u.id AND b.created_at>=NOW(6)-INTERVAL v_window_months MONTH
                    AND (b.status='rejected' OR (b.status='cancelled'
                        AND EXISTS(SELECT 1 FROM booking_cancellations c WHERE c.booking_id=b.id AND c.cancelled_by=l.host_id)))) host_side_cancellations
        FROM users u WHERE FIND_IN_SET(u.id,p_user_ids)>0 AND u.status='active' AND u.deleted_at IS NULL
    ) m ORDER BY m.user_id;
END$$

CREATE OR REPLACE PROCEDURE sp_trust_review_badges(IN p_listing BIGINT UNSIGNED)
BEGIN
    SELECT r.id review_id,EXISTS(SELECT 1 FROM identity_verifications v WHERE v.user_id=b.guest_id AND v.status='approved') reviewer_verified
    FROM reviews r JOIN bookings b ON b.id=r.booking_id JOIN listings l ON l.id=b.listing_id
    WHERE b.listing_id=p_listing AND r.moderation_status='visible' AND l.deleted_at IS NULL ORDER BY r.id;
END$$

CREATE OR REPLACE PROCEDURE sp_checkin_guide_for_host(IN p_listing BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
BEGIN
    CALL sp_quality_assert_host(p_listing,p_actor);
    SELECT listing_id,arrival_instructions,door_code,wifi_name,wifi_password,note,updated_at
    FROM listing_checkin_guides WHERE listing_id=p_listing;
END$$

CREATE OR REPLACE PROCEDURE sp_checkin_guide_save(IN p_listing BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED,
    IN p_arrival TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_door_code VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_wifi_name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_wifi_password VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_note TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE v_arrival TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_door VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_wifi_name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_wifi_password VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_note TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_checkin_guide_save; RELEASE SAVEPOINT h2h_checkin_guide_save; END IF; END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_checkin_guide_save; END IF;
    CALL sp_quality_assert_host(p_listing,p_actor);
    SELECT id INTO v_lock FROM listings WHERE id=p_listing AND host_id=p_actor AND deleted_at IS NULL FOR UPDATE;
    SET v_arrival=TRIM(p_arrival),v_door=NULLIF(TRIM(p_door_code),''),v_wifi_name=NULLIF(TRIM(p_wifi_name),''),
        v_wifi_password=NULLIF(TRIM(p_wifi_password),''),v_note=NULLIF(TRIM(p_note),'');
    IF v_arrival IS NULL OR CHAR_LENGTH(v_arrival)<10 OR CHAR_LENGTH(v_arrival)>3000 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Hướng dẫn nhận phòng cần từ 10 đến 3000 ký tự.';
    END IF;
    IF CHAR_LENGTH(v_door)>60 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Mã cửa / hộp khóa tối đa 60 ký tự.'; END IF;
    IF CHAR_LENGTH(v_wifi_name)>100 OR CHAR_LENGTH(v_wifi_password)>100 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tên và mật khẩu Wi-Fi tối đa 100 ký tự.';
    END IF;
    IF CHAR_LENGTH(v_note)>2000 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ghi chú tối đa 2000 ký tự.'; END IF;
    INSERT INTO listing_checkin_guides(listing_id,arrival_instructions,door_code,wifi_name,wifi_password,note,updated_at)
        VALUES(p_listing,v_arrival,v_door,v_wifi_name,v_wifi_password,v_note,NOW(6))
    ON DUPLICATE KEY UPDATE arrival_instructions=VALUES(arrival_instructions),door_code=VALUES(door_code),wifi_name=VALUES(wifi_name),
        wifi_password=VALUES(wifi_password),note=VALUES(note),updated_at=VALUES(updated_at);
    SELECT 1 __affected,p_listing __insert_id;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_checkin_guide_save; END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_checkin_guide_for_booking(IN p_booking BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
BEGIN
    -- No row unless the booking is confirmed/completed and the actor is its Guest, the listing Host or an Admin.
    SELECT g.arrival_instructions,g.door_code,g.wifi_name,g.wifi_password,g.note,g.updated_at
    FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN listing_checkin_guides g ON g.listing_id=b.listing_id
        JOIN users a ON a.id=p_actor
    WHERE b.id=p_booking AND b.status IN ('confirmed','completed') AND a.status='active' AND a.deleted_at IS NULL
        AND (b.guest_id=p_actor
            OR (l.host_id=p_actor AND EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_actor AND role='host'))
            OR EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_actor AND role='admin'));
END$$

DELIMITER ;
