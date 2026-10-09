-- MariaDB 10.4 / XAMPP. Each workflow owns a transaction only if none is active.
DELIMITER $$

CREATE OR REPLACE PROCEDURE sp_booking_validate(IN p_listing BIGINT UNSIGNED, IN p_start DATE, IN p_end DATE, IN p_guests INT)
BEGIN
    IF p_start IS NULL OR p_end IS NULL OR p_start < DATE(UTC_TIMESTAMP() + INTERVAL 7 HOUR) OR p_end <= p_start THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ngày nhận hoặc trả phòng không hợp lệ.';
    END IF;
    IF NOT EXISTS(SELECT 1 FROM listings l JOIN users h ON h.id=l.host_id WHERE l.id=p_listing AND l.moderation_status='approved' AND l.is_visible=1 AND l.deleted_at IS NULL AND h.status='active') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỗ ở không khả dụng.';
    END IF;
    IF p_guests IS NULL OR p_guests<1 OR p_guests>(SELECT max_guests FROM listings WHERE id=p_listing) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Số khách vượt quá sức chứa.';
    END IF;
    IF EXISTS(SELECT 1 FROM listing_availability WHERE listing_id=p_listing AND is_open=0 AND stay_date>=p_start AND stay_date<p_end) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Khoảng ngày đã chọn có ngày không cho thuê.';
    END IF;
    IF EXISTS(SELECT 1 FROM bookings WHERE listing_id=p_listing AND status IN ('pending','confirmed') AND check_in<p_end AND check_out>p_start) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Khoảng ngày này đã có yêu cầu đặt chỗ khác.';
    END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_booking_quote(IN p_listing BIGINT UNSIGNED, IN p_start DATE, IN p_end DATE, IN p_guests INT)
BEGIN
    CALL sp_booking_validate(p_listing,p_start,p_end,p_guests);
    SELECT DATEDIFF(p_end,p_start) nights,base_nightly_rate nightly_price,
        base_nightly_rate*DATEDIFF(p_end,p_start) subtotal,fee_amount fee,
        base_nightly_rate*DATEDIFF(p_end,p_start)+fee_amount total FROM listings WHERE id=p_listing;
END$$

CREATE OR REPLACE PROCEDURE sp_booking_create(IN p_listing BIGINT UNSIGNED, IN p_guest BIGINT UNSIGNED, IN p_start DATE, IN p_end DATE, IN p_guests INT)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_host BIGINT UNSIGNED;
    DECLARE v_id BIGINT UNSIGNED;
    DECLARE v_title VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_currency CHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_rate DECIMAL(12,2);
    DECLARE v_fee DECIMAL(12,2);
    DECLARE v_policy LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_day DATE;
    DECLARE v_conflicts INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_booking_create; RELEASE SAVEPOINT h2h_booking_create; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_booking_create; END IF;
    -- All writes serialize on the listing before taking any booking locks.
    SELECT host_id,title,currency,base_nightly_rate,fee_amount INTO v_host,v_title,v_currency,v_rate,v_fee
        FROM listings WHERE id=p_listing FOR UPDATE;
    IF v_host IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỗ ở không khả dụng.'; END IF;
    IF v_host=p_guest THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bạn không thể đặt chỗ ở của chính mình.'; END IF;
    IF NOT EXISTS(SELECT 1 FROM users WHERE id=p_guest AND status='active' AND deleted_at IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tài khoản không khả dụng.';
    END IF;
    -- A current locking read avoids a stale caller REPEATABLE READ snapshot.
    SELECT COUNT(*) INTO v_conflicts FROM bookings WHERE listing_id=p_listing AND status IN ('pending','confirmed') AND check_in<p_end AND check_out>p_start FOR UPDATE;
    IF v_conflicts>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Khoảng ngày này đã có yêu cầu đặt chỗ khác.'; END IF;
    SELECT COUNT(*) INTO v_conflicts FROM listing_availability WHERE listing_id=p_listing AND is_open=0 AND stay_date>=p_start AND stay_date<p_end FOR UPDATE;
    IF v_conflicts>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Khoảng ngày đã chọn có ngày không cho thuê.'; END IF;
    CALL sp_booking_validate(p_listing,p_start,p_end,p_guests);
    SELECT JSON_OBJECT('name',cp.name,'cutoff_hours',cp.cutoff_hours,'early_refund_pct',cp.early_refund_pct,
        'late_refund_pct',cp.late_refund_pct,'refund_fees',cp.refund_fees,'check_in_time',CAST(l.check_in_time AS CHAR),'time_zone',l.time_zone)
        INTO v_policy FROM listings l JOIN cancellation_policies cp ON cp.id=l.cancellation_policy_id WHERE l.id=p_listing;
    INSERT INTO bookings(listing_id,guest_id,check_in,check_out,guest_count,status,currency,fee_amount,total_amount,policy_snapshot,created_at,updated_at)
        VALUES(p_listing,p_guest,p_start,p_end,p_guests,'pending',v_currency,v_fee,v_rate*DATEDIFF(p_end,p_start)+v_fee,v_policy,NOW(6),NOW(6));
    SET v_id=LAST_INSERT_ID(); SET v_day=p_start;
    WHILE v_day<p_end DO
        INSERT INTO booking_nights(booking_id,stay_date,nightly_amount) VALUES(v_id,v_day,v_rate);
        SET v_day=DATE_ADD(v_day,INTERVAL 1 DAY);
    END WHILE;
    INSERT INTO booking_events(booking_id,actor_id,from_status,to_status,reason,created_at) VALUES(v_id,p_guest,NULL,'pending','Khách gửi yêu cầu đặt chỗ.',NOW(6));
    INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
        VALUES(v_host,v_id,'in_app','booking_created','Cập nhật booking',CONCAT('Bạn có yêu cầu đặt chỗ mới cho “',v_title,'”.'),'sent',1,NOW(6));
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_booking_create; END IF;
    SELECT v_id booking_id;
END$$

CREATE OR REPLACE PROCEDURE sp_booking_transition(IN p_booking BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED, IN p_target VARCHAR(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_admin BOOL)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_listing BIGINT UNSIGNED;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE v_host BIGINT UNSIGNED;
    DECLARE v_guest BIGINT UNSIGNED;
    DECLARE v_status VARCHAR(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_start DATE;
    DECLARE v_end DATE;
    DECLARE v_conflicts INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_booking_transition; RELEASE SAVEPOINT h2h_booking_transition; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_booking_transition; END IF;
    SELECT listing_id INTO v_listing FROM bookings WHERE id=p_booking;
    SELECT host_id INTO v_host FROM listings WHERE id=v_listing FOR UPDATE;
    SELECT guest_id,status,check_in,check_out INTO v_guest,v_status,v_start,v_end FROM bookings WHERE id=p_booking FOR UPDATE;
    IF v_guest IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Booking không tồn tại.'; END IF;
    IF p_admin IS NULL OR p_admin NOT IN (0,1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Quyền cập nhật không hợp lệ.'; END IF;
    IF NOT EXISTS(SELECT 1 FROM users WHERE id=p_actor AND status='active' AND deleted_at IS NULL) OR
        (p_admin=1 AND NOT EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_actor AND role='admin')) OR
        (p_admin=0 AND (v_host<>p_actor OR NOT EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_actor AND role='host'))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bạn không có quyền cập nhật booking này.';
    END IF;
    IF p_target IS NULL OR NOT ((v_status='pending' AND p_target IN ('confirmed','rejected')) OR (v_status='confirmed' AND p_target='completed')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chuyển trạng thái booking không hợp lệ.';
    END IF;
    IF p_target='completed' AND v_end>DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỉ có thể hoàn thành booking sau ngày trả phòng.';
    END IF;
    IF p_target='confirmed' THEN
        SELECT COUNT(*) INTO v_conflicts FROM bookings WHERE listing_id=v_listing AND id<>p_booking AND status='confirmed' AND check_in<v_end AND check_out>v_start FOR UPDATE;
        IF v_conflicts>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ngày này vừa được booking khác xác nhận.'; END IF;
        SELECT COUNT(*) INTO v_conflicts FROM listing_availability WHERE listing_id=v_listing AND is_open=0 AND stay_date>=v_start AND stay_date<v_end FOR UPDATE;
        IF v_conflicts>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Khoảng ngày có ngày đang bị chặn.'; END IF;
    END IF;
    UPDATE bookings SET status=p_target,updated_at=NOW(6) WHERE id=p_booking;
    INSERT INTO booking_events(booking_id,actor_id,from_status,to_status,reason,created_at)
        VALUES(p_booking,p_actor,v_status,p_target,IF(p_admin,'Admin cập nhật trạng thái.','Host cập nhật trạng thái.'),NOW(6));
    INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
        VALUES(v_guest,p_booking,'in_app',CONCAT('booking_',p_target),'Cập nhật booking',
        CASE p_target WHEN 'confirmed' THEN 'Yêu cầu đặt chỗ đã được xác nhận.' WHEN 'rejected' THEN 'Yêu cầu đặt chỗ đã bị từ chối.' ELSE 'Chuyến ở đã hoàn thành.' END,'sent',1,NOW(6));
    IF p_admin THEN
        INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at)
            VALUES(p_actor,'transition_booking','booking',p_booking,JSON_OBJECT('details',p_target),NOW(6));
    END IF;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_booking_transition; END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_booking_cancel(IN p_booking BIGINT UNSIGNED, IN p_guest BIGINT UNSIGNED, IN p_reason TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_listing BIGINT UNSIGNED;
    DECLARE v_host BIGINT UNSIGNED;
    DECLARE v_guest BIGINT UNSIGNED;
    DECLARE v_status VARCHAR(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_start DATE;
    DECLARE v_total DECIMAL(12,2);
    DECLARE v_fee DECIMAL(12,2);
    DECLARE v_policy LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_percent DECIMAL(5,2);
    DECLARE v_base DECIMAL(12,2);
    DECLARE v_refund DECIMAL(12,2);
    DECLARE v_zone TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_default_zone TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_default_time TIME;
    DECLARE v_arrival DATETIME;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_booking_cancel; RELEASE SAVEPOINT h2h_booking_cancel; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_booking_cancel; END IF;
    SELECT listing_id INTO v_listing FROM bookings WHERE id=p_booking;
    SELECT host_id,check_in_time,time_zone INTO v_host,v_default_time,v_default_zone FROM listings WHERE id=v_listing FOR UPDATE;
    SELECT guest_id,status,check_in,total_amount,fee_amount,policy_snapshot INTO v_guest,v_status,v_start,v_total,v_fee,v_policy FROM bookings WHERE id=p_booking FOR UPDATE;
    IF v_guest IS NULL OR v_guest<>p_guest OR NOT EXISTS(SELECT 1 FROM users WHERE id=p_guest AND status='active') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Booking không tồn tại hoặc không thuộc về bạn.';
    END IF;
    IF v_status NOT IN ('pending','confirmed') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Booking ở trạng thái này không thể hủy.'; END IF;
    IF v_status='pending' THEN SET v_percent=100; SET v_base=v_total;
    ELSE
        SET v_zone=COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(v_policy,'$.time_zone')),'null'),v_default_zone);
        -- XAMPP may not load named timezone tables. Vietnam has a fixed UTC+07 offset.
        SET v_zone=CASE v_zone WHEN 'Asia/Ho_Chi_Minh' THEN '+07:00' WHEN 'UTC' THEN '+00:00' ELSE v_zone END;
        SET v_arrival=CONVERT_TZ(CONCAT(v_start,' ',COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(v_policy,'$.check_in_time')),'null'),CAST(v_default_time AS CHAR))),v_zone,'+00:00');
        IF v_arrival IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chưa cấu hình múi giờ để tính hoàn tiền an toàn.'; END IF;
        SET v_percent=IF(TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),v_arrival)>=JSON_EXTRACT(v_policy,'$.cutoff_hours')*3600,
            JSON_EXTRACT(v_policy,'$.early_refund_pct'),JSON_EXTRACT(v_policy,'$.late_refund_pct'));
        SET v_base=v_total-IF(JSON_UNQUOTE(JSON_EXTRACT(v_policy,'$.refund_fees')) IN ('true','1'),0,v_fee);
    END IF;
    SET v_refund=ROUND(GREATEST(0,v_base)*v_percent/100,2);
    UPDATE bookings SET status='cancelled',updated_at=NOW(6) WHERE id=p_booking;
    INSERT INTO booking_cancellations(booking_id,cancelled_by,reason,refund_amount,calculation_details,created_at)
        VALUES(p_booking,p_guest,TRIM(p_reason),v_refund,JSON_OBJECT('policy',JSON_EXTRACT(v_policy,'$'),'refund_percent',v_percent,'refundable_base',v_base),NOW(6));
    INSERT INTO booking_events(booking_id,actor_id,from_status,to_status,reason,created_at) VALUES(p_booking,p_guest,v_status,'cancelled','Khách hủy booking.',NOW(6));
    INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
        VALUES(v_host,p_booking,'in_app','booking_cancelled','Cập nhật booking','Khách đã hủy booking.','sent',1,NOW(6));
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_booking_cancel; END IF;
    SELECT v_percent refund_percent,v_refund refund_amount;
END$$

DELIMITER ;
