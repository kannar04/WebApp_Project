-- Home2Home: database update for the UI redesign and the bug fixes.
-- Run this on the shared db_home2home once, at the same time the PHP goes onto dev: 63 routines change and 10 are new.
-- 7 of the changed routines take different parameters, so a caller with the old list fails: do not run this ahead of the PHP, or leave the PHP waiting after it.
-- Safe to run again: it only replaces routines and adds one index. Tables and data are not touched.
-- Names are the owner-prefixed ones used on dev.

USE db_home2home;

CREATE INDEX IF NOT EXISTS idx_admin_audit_logs_entity ON admin_audit_logs (entity_type, entity_id, action);

-- ---- New routines: 10

-- 050_quality_core.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE DST_sp_booking_refund_terms(IN p_booking BIGINT UNSIGNED, IN p_policy LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_start DATE, IN p_total DECIMAL(12,2), IN p_fee DECIMAL(12,2),
    IN p_default_time TIME, IN p_default_zone TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, OUT o_percent DECIMAL(5,2), OUT o_base DECIMAL(12,2))
terms: BEGIN
    DECLARE v_zone TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_time TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_moment VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_arrival DATETIME;
    -- The promise is the Guest's own notification from NMT_sp_payment_vnpay_result or NMT_sp_admin_payment_transfer_decide; nothing deletes it.
    IF EXISTS(SELECT 1 FROM notifications WHERE booking_id=p_booking AND event_type='payment_paid' AND title='Khoản thanh toán sẽ được hoàn lại') THEN
        SET o_percent=100;
        SELECT amount_due INTO o_base FROM booking_payments WHERE booking_id=p_booking;
        LEAVE terms;
    END IF;
    SET v_time=JSON_UNQUOTE(JSON_EXTRACT(p_policy,'$.check_in_time'));
    SET v_time=CASE WHEN v_time REGEXP '^([01][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$' THEN v_time WHEN CAST(p_default_time AS CHAR) REGEXP '^([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$' THEN CAST(p_default_time AS CHAR) ELSE '14:00:00' END;
    SET v_moment=CONCAT(p_start,' ',v_time);
    SET v_zone=JSON_UNQUOTE(JSON_EXTRACT(p_policy,'$.time_zone'));
    -- XAMPP may not load named timezone tables. Vietnam has a fixed UTC+07 offset. CONVERT_TZ gives NULL for a zone it cannot read, which moves on to the next one.
    SET v_arrival=COALESCE(CONVERT_TZ(v_moment,CASE v_zone WHEN 'Asia/Ho_Chi_Minh' THEN '+07:00' WHEN 'UTC' THEN '+00:00' ELSE v_zone END,'+00:00'),
        CONVERT_TZ(v_moment,CASE p_default_zone WHEN 'Asia/Ho_Chi_Minh' THEN '+07:00' WHEN 'UTC' THEN '+00:00' ELSE p_default_zone END,'+00:00'),
        CONVERT_TZ(v_moment,'+07:00','+00:00'));
    SET o_percent=IF(TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),v_arrival)>=JSON_EXTRACT(p_policy,'$.cutoff_hours')*3600,
        JSON_EXTRACT(p_policy,'$.early_refund_pct'),JSON_EXTRACT(p_policy,'$.late_refund_pct'));
    SET o_base=p_total-IF(JSON_UNQUOTE(JSON_EXTRACT(p_policy,'$.refund_fees')) IN ('true','1'),0,p_fee);
END$$
DELIMITER ;

-- 060_quality_community.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE DST_sp_listing_catalog_choices(IN p_kind VARCHAR(12), IN p_listing BIGINT UNSIGNED, IN p_host BIGINT UNSIGNED)
BEGIN
    IF p_kind='type' THEN
        SELECT pt.id,pt.name,pt.description,pt.is_active FROM property_types pt
        WHERE pt.is_active=1 OR pt.id=(SELECT l.property_type_id FROM listings l WHERE l.id=p_listing AND l.host_id=p_host AND l.deleted_at IS NULL)
        ORDER BY pt.name;
    ELSEIF p_kind='amenity' THEN
        SELECT a.id,a.name,a.icon_key,a.is_active FROM amenities a
        WHERE a.is_active=1 OR EXISTS(SELECT 1 FROM listing_amenities la JOIN listings l ON l.id=la.listing_id
            WHERE la.listing_id=p_listing AND la.amenity_id=a.id AND l.host_id=p_host AND l.deleted_at IS NULL)
        ORDER BY a.name;
    ELSE SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Loại danh mục không hợp lệ.'; END IF;
END$$
DELIMITER ;

-- 010_models.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE `DST_sp_user_host_role_removed`(IN p_1 bigint unsigned)
BEGIN
    SELECT COALESCE((SELECT a.action FROM admin_audit_logs a WHERE a.entity_type='user' AND a.entity_id=p_1 AND a.action IN ('remove_host_role','grant_host_role') ORDER BY a.id DESC LIMIT 1),'')='remove_host_role' removed;
END$$
DELIMITER ;

-- 100_focused_experience.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE LTP_sp_focused_destinations()
BEGIN
 SELECT l.city,COUNT(*) listing_count
 FROM listings l JOIN users u ON u.id=l.host_id
 WHERE l.moderation_status='approved' AND l.is_visible=1 AND l.deleted_at IS NULL
 AND u.status='active' AND u.deleted_at IS NULL AND TRIM(l.city)<>''
 GROUP BY l.city ORDER BY COUNT(*) DESC,l.city LIMIT 1000;
END$$
DELIMITER ;

-- 050_quality_core.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE LTP_sp_listing_reject_pending(IN p_listing BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED,
    IN p_event TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_cause VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, OUT p_rejected INT)
BEGIN
    SELECT COUNT(*) INTO p_rejected FROM bookings WHERE listing_id=p_listing AND status='pending' FOR UPDATE;
    INSERT INTO booking_events(booking_id,actor_id,from_status,to_status,reason,created_at)
        SELECT id,p_actor,'pending','rejected',p_event,NOW(6) FROM bookings WHERE listing_id=p_listing AND status='pending';
    INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
        SELECT b.guest_id,b.id,'in_app','booking_rejected','Cập nhật booking',CONCAT('Yêu cầu đặt chỗ #',b.id,' cho “',l.title,'” đã bị từ chối vì ',p_cause,'.'),'sent',1,NOW(6)
        FROM bookings b JOIN listings l ON l.id=b.listing_id WHERE b.listing_id=p_listing AND b.status='pending';
    UPDATE bookings SET status='rejected',updated_at=NOW(6) WHERE listing_id=p_listing AND status='pending';
END$$
DELIMITER ;

-- 100_focused_experience.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE NMT_sp_focused_listing_search(
    IN p_location VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,IN p_guests INT,IN p_type BIGINT UNSIGNED,IN p_min DECIMAL(12,2),IN p_max DECIMAL(12,2),IN p_bedrooms INT,
    IN p_start DATE,IN p_end DATE,IN p_sort VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,IN p_limit INT,IN p_offset INT, IN p_amenities LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_city VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_bounds LONGTEXT)
BEGIN
    -- đ has its own weight in every collation of MariaDB 10.4, unlike the other Vietnamese accents, so "Da Lat" only finds "Đà Lạt" with it folded on both sides. REPLACE is case-sensitive, hence both cases.
    DECLARE v_location VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT REPLACE(REPLACE(p_location,'đ','d'),'Đ','D');
    -- JSON_VALUE returns text, and text compares "-140.1" above "-139.9": the box edges are numbers from here on.
    DECLARE v_south DECIMAL(10,6) DEFAULT CAST(JSON_VALUE(p_bounds,'$.south') AS DECIMAL(10,6));
    DECLARE v_north DECIMAL(10,6) DEFAULT CAST(JSON_VALUE(p_bounds,'$.north') AS DECIMAL(10,6));
    DECLARE v_west DECIMAL(10,6) DEFAULT CAST(JSON_VALUE(p_bounds,'$.west') AS DECIMAL(10,6));
    DECLARE v_east DECIMAL(10,6) DEFAULT CAST(JSON_VALUE(p_bounds,'$.east') AS DECIMAL(10,6));
    DECLARE v_i INT DEFAULT 0;
    DECLARE v_amenity BIGINT UNSIGNED;
    IF p_amenities IS NULL OR NOT JSON_VALID(p_amenities) OR JSON_TYPE(p_amenities)<>'ARRAY' OR JSON_LENGTH(p_amenities)>100 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tiện nghi lọc không hợp lệ.';
    END IF;
    WHILE v_i<JSON_LENGTH(p_amenities) DO
        IF JSON_TYPE(JSON_EXTRACT(p_amenities,CONCAT('$[',v_i,']')))<>'INTEGER' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ID tiện nghi không hợp lệ.'; END IF;
        SET v_amenity=JSON_EXTRACT(p_amenities,CONCAT('$[',v_i,']'));
        IF NOT EXISTS(SELECT 1 FROM amenities WHERE id=v_amenity AND is_active=1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tiện nghi lọc đã ngừng sử dụng hoặc không tồn tại.'; END IF;
        SET v_i=v_i+1;
    END WHILE;
    IF p_limit<1 OR p_limit>100 OR p_offset<0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Phân trang không hợp lệ.'; END IF;
    SELECT SQL_CALC_FOUND_ROWS l.id,l.host_id,l.property_type_id,l.cancellation_policy_id,l.title,l.description,l.address,l.city,l.country_code,l.latitude,l.longitude,l.time_zone,l.base_nightly_rate,l.fee_amount,l.currency,l.max_guests,l.room_count,l.bedroom_count,l.bed_count,l.check_in_time,l.check_out_time,l.smoking_allowed,l.pets_allowed,l.parties_allowed,l.house_rules,l.moderation_status,l.moderation_reason,l.reviewed_by,l.reviewed_at,l.is_visible,l.created_at,l.updated_at,l.deleted_at,l.base_nightly_rate nightly_price,l.fee_amount cleaning_fee,pt.name property_type,u.full_name host_name,
        (SELECT image_url FROM listing_photos WHERE listing_id=l.id ORDER BY sort_order,id LIMIT 1) image_url,
        COALESCE((SELECT AVG(r.rating) FROM reviews r JOIN bookings b ON b.id=r.booking_id WHERE b.listing_id=l.id AND r.moderation_status='visible'),0) rating,
        (SELECT COUNT(*) FROM reviews r JOIN bookings b ON b.id=r.booking_id WHERE b.listing_id=l.id AND r.moderation_status='visible') review_count
    FROM listings l JOIN property_types pt ON pt.id=l.property_type_id JOIN users u ON u.id=l.host_id
    WHERE l.moderation_status='approved' AND l.is_visible=1 AND l.deleted_at IS NULL AND u.status='active' AND u.deleted_at IS NULL
        AND (p_location='' OR REPLACE(REPLACE(l.city,'đ','d'),'Đ','D') LIKE CONCAT('%',v_location,'%') OR REPLACE(REPLACE(l.address,'đ','d'),'Đ','D') LIKE CONCAT('%',v_location,'%') OR REPLACE(REPLACE(l.title,'đ','d'),'Đ','D') LIKE CONCAT('%',v_location,'%'))
        AND (p_city='' OR l.city=p_city)
        AND (p_bounds IS NULL OR (l.latitude BETWEEN v_south AND v_north
          AND ((v_west<v_east AND l.longitude BETWEEN v_west AND v_east)
          OR (v_west>v_east AND (l.longitude>=v_west OR l.longitude<=v_east)))))
        AND (p_guests=0 OR l.max_guests>=p_guests) AND (p_type=0 OR l.property_type_id=p_type)
        AND (p_min=0 OR l.base_nightly_rate>=p_min) AND (p_max=0 OR l.base_nightly_rate<=p_max)
        AND (p_bedrooms=0 OR l.bedroom_count>=p_bedrooms)
        AND NOT EXISTS(SELECT 1 FROM amenities wanted WHERE JSON_CONTAINS(p_amenities,CAST(wanted.id AS CHAR))
            AND NOT EXISTS(SELECT 1 FROM listing_amenities la WHERE la.listing_id=l.id AND la.amenity_id=wanted.id))
        AND (p_start IS NULL OR p_end IS NULL OR (
            NOT EXISTS(SELECT 1 FROM listing_availability a WHERE a.listing_id=l.id AND a.is_open=0 AND a.stay_date>=p_start AND a.stay_date<p_end)
            AND NOT EXISTS(SELECT 1 FROM bookings b WHERE b.listing_id=l.id AND b.status IN ('pending','confirmed') AND (b.status='confirmed' OR b.check_in>=DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR)) AND b.check_in<p_end AND b.check_out>p_start)))
    ORDER BY CASE WHEN p_sort='map' THEN rating END DESC,CASE WHEN p_sort='map' THEN review_count END DESC,CASE WHEN p_sort='map' THEN l.id END DESC,
        CASE WHEN p_sort='price_asc' THEN l.base_nightly_rate END ASC,
        CASE WHEN p_sort='price_desc' THEN l.base_nightly_rate END DESC,
        CASE WHEN p_sort='rating' THEN rating END DESC,l.created_at DESC,l.id DESC
    LIMIT p_limit OFFSET p_offset;
    SELECT FOUND_ROWS() __total;
END$$
DELIMITER ;

-- 050_quality_core.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE NMT_sp_listing_reviews_for_admin(IN p_listing BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
BEGIN
    CALL NTK_sp_admin_assert_role(p_actor);
    SELECT r.id,r.booking_id,r.rating,r.comment,r.host_reply,r.host_replied_at,r.moderation_status,r.created_at,r.updated_at,r.host_reply host_response,u.full_name guest_name
    FROM reviews r JOIN bookings b ON b.id=r.booking_id JOIN listings l ON l.id=b.listing_id JOIN users u ON u.id=b.guest_id
    WHERE b.listing_id=p_listing AND r.moderation_status='visible' AND l.deleted_at IS NULL ORDER BY r.created_at DESC;
END$$
DELIMITER ;

-- 095_online_payment.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE NTK_sp_admin_payment_duplicate_resolve(IN p_transaction BIGINT UNSIGNED, IN p_admin BIGINT UNSIGNED,
    IN p_note VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_booking BIGINT UNSIGNED;
    DECLARE v_listing BIGINT UNSIGNED;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE v_guest BIGINT UNSIGNED;
    DECLARE v_provider VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_status VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_reference VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_amount DECIMAL(12,2);
    DECLARE v_stored_note VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_note VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_payment VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_duplicate_resolve; RELEASE SAVEPOINT h2h_duplicate_resolve; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_duplicate_resolve; END IF;
    CALL NTK_sp_admin_assert_role(p_admin);
    SET v_note=NULLIF(TRIM(COALESCE(p_note,'')),'');
    IF v_note IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Vui lòng ghi chú lý do hoàn tiền.'; END IF;
    SELECT booking_id INTO v_booking FROM payment_transactions WHERE id=p_transaction;
    SELECT listing_id INTO v_listing FROM bookings WHERE id=v_booking;
    SELECT id INTO v_lock FROM listings WHERE id=v_listing FOR UPDATE;
    SELECT guest_id INTO v_guest FROM bookings WHERE id=v_booking FOR UPDATE;
    SELECT status INTO v_payment FROM booking_payments WHERE booking_id=v_booking FOR UPDATE;
    SELECT provider,status,reference,ROUND(amount,0),note INTO v_provider,v_status,v_reference,v_amount,v_stored_note FROM payment_transactions WHERE id=p_transaction FOR UPDATE;
    -- A first VNPay success stores no note; only the duplicate branch of NMT_sp_payment_vnpay_result leaves succeeded + note.
    IF v_guest IS NULL OR v_provider<>'vnpay' OR v_status<>'succeeded' OR v_stored_note IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Giao dịch này không phải thanh toán trùng đang chờ xử lý.';
    END IF;
    -- The fixed text below takes 32 of the column's 500 characters: a longer note is refused, never cut.
    IF CHAR_LENGTH(v_note)>468 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ghi chú hoàn khoản trùng tối đa 468 ký tự.'; END IF;
    UPDATE payment_transactions SET status='cancelled',decided_by=p_admin,decided_at=NOW(6),
        note=CONCAT('Đã hoàn khoản thanh toán trùng: ',v_note),updated_at=NOW(6) WHERE id=p_transaction;
    INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
        VALUES(v_guest,v_booking,'in_app','payment_duplicate_refunded','Đã hoàn khoản thanh toán trùng',
        CONCAT('Khoản VNPay ',REPLACE(FORMAT(v_amount,0),',','.'),'đ (mã ',v_reference,') của booking #',v_booking,' đã được hoàn lại.',
            IF(v_payment='refunded',' Khoản thanh toán chính của booking cũng đã được hoàn trước đó.',' Booking vẫn được giữ là đã thanh toán.')),'sent',1,NOW(6));
    INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at)
        VALUES(p_admin,'resolve_duplicate_payment','booking',v_booking,JSON_OBJECT('transaction_id',p_transaction,'reference',v_reference,'amount',v_amount,'note',v_note),NOW(6));
    SELECT 1 __affected,p_transaction __insert_id;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_duplicate_resolve; END IF;
    SELECT v_payment payment_status;
END$$
DELIMITER ;

-- 100_focused_experience.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE NTK_sp_focused_review_lock(IN p_listing BIGINT UNSIGNED)
BEGIN
 SELECT id FROM listings WHERE id=p_listing FOR UPDATE;
END$$
DELIMITER ;

-- 065_password_recovery.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE NTK_sp_password_reset_usable(IN p_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin)
BEGIN
    SELECT EXISTS(SELECT 1 FROM password_reset_tokens t JOIN users u ON u.id=t.user_id
        WHERE t.token_hash=p_hash AND t.used_at IS NULL AND t.expires_at>NOW(6) AND u.status='active' AND u.deleted_at IS NULL) usable;
END$$
DELIMITER ;

-- ---- Changed routines: 63

-- 075_trust.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE DST_sp_admin_identity_verifications(IN p_admin BIGINT UNSIGNED,
    IN p_status VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_query VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    -- User input is matched literally: LIKE wildcards are escaped.
    -- đ has its own weight in every collation here, unlike the other Vietnamese accents, so it is folded on both sides as in location search (NTK_sp_listing_search). REPLACE is case-sensitive, hence both cases.
    DECLARE v_query VARCHAR(600) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(p_query,'\\','\\\\'),'%','\\%'),'_','\\_'),'đ','d'),'Đ','D');
    CALL NTK_sp_admin_assert_role(p_admin);
    IF p_status IS NULL OR p_status NOT IN ('','pending','approved','rejected') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bộ lọc trạng thái xác minh không hợp lệ.';
    END IF;
    -- Full CCCD is only projected for pending rows; decided rows are already masked in storage.
    SELECT v.id,v.user_id,u.email,u.full_name account_name,v.full_name_on_card,v.phone,
        CASE WHEN v.status='pending' THEN v.id_number ELSE CONCAT('********',RIGHT(v.id_number,4)) END id_number,
        v.status,v.decision_reason,v.created_at,v.reviewed_at,a.full_name reviewer_name
    FROM identity_verifications v JOIN users u ON u.id=v.user_id LEFT JOIN users a ON a.id=v.reviewed_by
    WHERE (p_status='' OR v.status=p_status)
        AND (p_query IS NULL OR p_query='' OR u.email LIKE CONCAT('%',v_query,'%') OR REPLACE(REPLACE(u.full_name,'đ','d'),'Đ','D') LIKE CONCAT('%',v_query,'%')
            OR REPLACE(REPLACE(v.full_name_on_card,'đ','d'),'Đ','D') LIKE CONCAT('%',v_query,'%'))
    ORDER BY v.status='pending' DESC,IF(v.status='pending',v.created_at,NULL),v.created_at DESC,v.id DESC LIMIT 200;
END$$
DELIMITER ;

-- 030_atomic_writes.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE DST_sp_admin_listing_moderate(IN p_1 bigint unsigned, IN p_2 bigint unsigned, IN p_3 varchar(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_4 text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE v_old_status VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_rejected INT DEFAULT 0;
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
    SELECT id,moderation_status INTO v_lock,v_old_status FROM listings WHERE id=p_1 AND deleted_at IS NULL FOR UPDATE;
    IF v_lock IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỗ ở không tồn tại.'; END IF;
    IF p_3 IS NULL OR p_3 NOT IN ('approved','rejected') OR (p_3='rejected' AND (p_4 IS NULL OR TRIM(p_4)='')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Quyết định kiểm duyệt hoặc lý do không hợp lệ.';
    END IF;
    IF CHAR_LENGTH(TRIM(p_4))>5000 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Lý do hoặc ghi chú kiểm duyệt tối đa 5000 ký tự.'; END IF;
    UPDATE listings SET moderation_status=p_3,moderation_reason=p_4,reviewed_by=p_2,reviewed_at=NOW(6),updated_at=NOW(6) WHERE id=p_1;
    INSERT INTO listing_moderation_events(listing_id,admin_id,action,reason,created_at) VALUES(p_1,p_2,IF(p_3='approved','approve','reject'),p_4,NOW(6));
    -- A rejected listing cannot honour its requests, same as a take-down. Confirmed stays are left for people to sort out.
    IF p_3='rejected' THEN
        CALL LTP_sp_listing_reject_pending(p_1,p_2,'Admin từ chối tin đăng nên yêu cầu bị từ chối tự động.','chỗ ở không được duyệt',v_rejected);
        INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
            SELECT host_id,NULL,'in_app','listing_rejected','Tin đăng bị từ chối',CONCAT('Tin “',title,'” đã bị Admin từ chối. Lý do: ',TRIM(p_4)),'sent',1,NOW(6) FROM listings WHERE id=p_1;
    END IF;
    -- Approving again with a new note changes nothing for the Host, so it tells them nothing. The text says approved, not public: the listing may be hidden.
    IF p_3='approved' AND v_old_status<>'approved' THEN
        INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
            SELECT host_id,NULL,'in_app','listing_approved','Tin đăng được duyệt',CONCAT('Tin “',title,'” đã được Admin duyệt.'),'sent',1,NOW(6) FROM listings WHERE id=p_1;
    END IF;
    INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at)
        VALUES(p_2,'moderate_listing','listing',p_1,JSON_OBJECT('details',CONCAT(p_3,': ',p_4),'rejected_pending',v_rejected),NOW(6));
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_admin_listing_moderate; END IF;
END$$
DELIMITER ;

-- 080_conversation.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE DST_sp_admin_messages_search(IN p_actor BIGINT UNSIGNED,
    IN p_keyword VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_booking BIGINT UNSIGNED,
    IN p_user VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_keyword VARCHAR(400) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_user VARCHAR(600) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CALL NTK_sp_admin_assert_role(p_actor);
    -- User input is matched literally: LIKE wildcards are escaped.
    -- đ has its own weight in every collation here, unlike the other Vietnamese accents, so it is folded on both sides as in location search (NTK_sp_listing_search). REPLACE is case-sensitive, hence both cases.
    SET v_keyword=REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(p_keyword,'')),'\\','\\\\'),'%','\\%'),'_','\\_'),'đ','d'),'Đ','D');
    SET v_user=REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(p_user,'')),'\\','\\\\'),'%','\\%'),'_','\\_'),'đ','d'),'Đ','D');
    SELECT m.id,m.booking_id,l.title,b.status booking_status,m.sender_id,s.full_name sender_name,s.email sender_email,
        r.full_name recipient_name,r.email recipient_email,m.body,m.created_at,m.read_at,m.hidden_at,m.hidden_reason,a.full_name hidden_by_name
    FROM booking_messages m JOIN bookings b ON b.id=m.booking_id JOIN listings l ON l.id=b.listing_id
        JOIN users s ON s.id=m.sender_id JOIN users r ON r.id=m.recipient_id LEFT JOIN users a ON a.id=m.hidden_by
    WHERE (p_booking IS NULL OR m.booking_id=p_booking)
        AND (v_keyword='' OR REPLACE(REPLACE(m.body,'đ','d'),'Đ','D') LIKE CONCAT('%',v_keyword,'%'))
        AND (v_user='' OR REPLACE(REPLACE(s.full_name,'đ','d'),'Đ','D') LIKE CONCAT('%',v_user,'%') OR s.email LIKE CONCAT('%',v_user,'%')
            OR REPLACE(REPLACE(r.full_name,'đ','d'),'Đ','D') LIKE CONCAT('%',v_user,'%') OR r.email LIKE CONCAT('%',v_user,'%'))
    ORDER BY m.created_at DESC,m.id DESC LIMIT 200;
END$$
DELIMITER ;

-- 070_booking_extras.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE DST_sp_admin_payment_totals(IN p_actor BIGINT UNSIGNED)
BEGIN
    CALL NTK_sp_admin_assert_role(p_actor);
    SELECT COALESCE(SUM(CASE WHEN x.payment_status='paid' THEN x.amount_due WHEN x.payment_status='refunded' THEN x.amount_due-x.refunded END),0) collected_amount,
        COALESCE(SUM(x.payment_status='paid' OR (x.payment_status='refunded' AND x.amount_due>x.refunded)),0) paid_count, -- Every booking the collected money comes from.
        COALESCE(SUM(x.refunded),0) refunded_amount,
        COALESCE(SUM(x.payment_status='refunded'),0) refunded_count,
        COALESCE(SUM(CASE WHEN COALESCE(x.payment_status,'unpaid')='unpaid' AND x.booking_status IN ('confirmed','completed') THEN COALESCE(x.amount_due,x.total_amount) END),0) outstanding_amount,
        COALESCE(SUM(COALESCE(x.payment_status,'unpaid')='unpaid' AND x.booking_status IN ('confirmed','completed')),0) outstanding_count
    FROM (
        SELECT b.status booking_status,b.total_amount,pay.status payment_status,pay.amount_due,
            CASE WHEN pay.status='refunded' THEN COALESCE((SELECT CAST(JSON_VALUE(a.change_summary,'$.amount') AS DECIMAL(12,2)) FROM admin_audit_logs a WHERE a.action='refund_payment' AND a.entity_type='booking' AND a.entity_id=b.id ORDER BY a.id DESC LIMIT 1),pay.amount_due) END refunded
        FROM bookings b LEFT JOIN booking_payments pay ON pay.booking_id=b.id
    ) x;
END$$
DELIMITER ;

-- 060_quality_community.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE DST_sp_admin_report_resolve(IN p_id BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED,
    IN p_status VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_resolution TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE v_old_status VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_old_resolution TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_resolution TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_report_resolve; RELEASE SAVEPOINT h2h_report_resolve; END IF; END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_report_resolve; END IF;
    CALL NTK_sp_admin_assert_role(p_actor);
    SELECT id,status,resolution INTO v_lock,v_old_status,v_old_resolution FROM reports WHERE id=p_id FOR UPDATE;
    IF v_lock IS NULL OR p_status IS NULL OR p_status NOT IN ('open','investigating','resolved','dismissed')
        OR CHAR_LENGTH(COALESCE(p_resolution,''))>5000
        OR (p_status IN ('resolved','dismissed') AND TRIM(COALESCE(p_resolution,''))='') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Trạng thái hoặc kết luận xử lý không hợp lệ.';
    END IF;
    SET v_resolution=NULLIF(TRIM(p_resolution),'');
    UPDATE reports SET status=p_status,assigned_admin_id=p_actor,resolution=v_resolution,
        resolved_at=IF(p_status IN ('resolved','dismissed'),NOW(6),NULL) WHERE id=p_id;
    CALL DST_sp_admin_audit_add(p_actor,'resolve_report','report',p_id,JSON_OBJECT('status',p_status));
    -- The reporter hears each real change once. Re-saving the same form says nothing new; the binary compare still sees a corrected accent. An Admin's own report needs no notice.
    IF v_old_status<>p_status OR COALESCE(v_old_resolution,'') COLLATE utf8mb4_bin<>COALESCE(v_resolution,'') COLLATE utf8mb4_bin THEN
        INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
        SELECT r.reporter_id,r.booking_id,'in_app',CONCAT('report_',p_status),'Cập nhật báo cáo',
            CASE p_status
                WHEN 'resolved' THEN CONCAT('Báo cáo #',r.id,' về “',l.title,'” đã được giải quyết. Kết luận: ',v_resolution)
                WHEN 'dismissed' THEN CONCAT('Báo cáo #',r.id,' về “',l.title,'” không được chấp nhận. Kết luận: ',v_resolution)
                WHEN 'investigating' THEN CONCAT('Admin đang xử lý báo cáo #',r.id,' về “',l.title,'”.',IF(v_resolution IS NULL,'',CONCAT(' Ghi chú: ',v_resolution)))
                ELSE CONCAT('Báo cáo #',r.id,' về “',l.title,'” đang chờ Admin xử lý.',IF(v_resolution IS NULL,'',CONCAT(' Ghi chú: ',v_resolution)))
            END,'sent',1,NOW(6)
        FROM reports r
        LEFT JOIN bookings b ON b.id=r.booking_id
        JOIN listings l ON l.id=COALESCE(r.listing_id,b.listing_id)
        JOIN users u ON u.id=r.reporter_id
        WHERE r.id=p_id AND r.reporter_id<>p_actor AND u.deleted_at IS NULL;
    END IF;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_report_resolve; END IF;
END$$
DELIMITER ;

-- 020_booking.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE DST_sp_booking_create(IN p_listing BIGINT UNSIGNED, IN p_guest BIGINT UNSIGNED, IN p_start DATE, IN p_end DATE, IN p_guests INT,
    IN p_promo VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_shown_total DECIMAL(12,2))
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_host BIGINT UNSIGNED;
    DECLARE v_id BIGINT UNSIGNED;
    DECLARE v_message VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_title VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_currency CHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_rate DECIMAL(12,2);
    DECLARE v_fee DECIMAL(12,2);
    DECLARE v_policy LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_day DATE;
    DECLARE v_conflicts INT DEFAULT 0;
    DECLARE v_nights INT;
    DECLARE v_base DECIMAL(12,2);
    DECLARE v_weekend_nights INT;
    DECLARE v_weekend_rate DECIMAL(12,2);
    DECLARE v_holiday_nights INT;
    DECLARE v_holiday_rate DECIMAL(12,2);
    DECLARE v_subtotal DECIMAL(12,2);
    DECLARE v_length_type VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_length_pct DECIMAL(5,2);
    DECLARE v_length_amount DECIMAL(12,2);
    DECLARE v_promo_id BIGINT UNSIGNED;
    DECLARE v_promo_code VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_promo_amount DECIMAL(12,2);
    DECLARE v_promo_message VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_total DECIMAL(12,2);
    DECLARE v_instant BOOL;
    DECLARE v_night DECIMAL(12,2);
    DECLARE v_kind VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_status VARCHAR(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
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
    IF NOT EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_guest AND role='guest') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tài khoản của bạn không có vai trò Guest nên không thể đặt chỗ. Hãy liên hệ Admin nếu cần.';
    END IF;
    -- A current locking read avoids a stale caller REPEATABLE READ snapshot.
    SELECT COUNT(*) INTO v_conflicts FROM bookings WHERE listing_id=p_listing AND status IN ('pending','confirmed') AND (status='confirmed' OR check_in>=DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR)) AND check_in<p_end AND check_out>p_start FOR UPDATE;
    IF v_conflicts>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Khoảng ngày này đã có yêu cầu đặt chỗ khác.'; END IF;
    SELECT COUNT(*) INTO v_conflicts FROM listing_availability WHERE listing_id=p_listing AND is_open=0 AND stay_date>=p_start AND stay_date<p_end FOR UPDATE;
    IF v_conflicts>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Khoảng ngày đã chọn có ngày không cho thuê.'; END IF;
    CALL NTK_sp_booking_validate(p_listing,p_start,p_end,p_guests);
    -- Same pricing routine as the quote; strict mode re-validates the promo code under a row lock.
    CALL NMT_sp_booking_extras_price(p_listing,p_start,p_end,p_promo,TRUE,v_nights,v_base,v_weekend_nights,v_weekend_rate,v_holiday_nights,v_holiday_rate,
        v_subtotal,v_length_type,v_length_pct,v_length_amount,v_promo_id,v_promo_code,v_promo_amount,v_promo_message,v_fee,v_total,v_instant);
    -- The form carries the total the quote showed. NULL means no quote was on screen (no JavaScript, or before any quote).
    IF p_shown_total IS NOT NULL AND p_shown_total<>v_total THEN
        SET v_message=CONCAT('Giá đã thay đổi nên chưa có booking nào được tạo. Tổng tiền mới: ',REPLACE(FORMAT(v_total,0),',','.'),'đ. Hãy xem lại rồi đặt lại.');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT=v_message;
    END IF;
    -- Instant Book confirms inside this transaction: overlaps and closed dates were already checked under the listing lock.
    SET v_status=IF(v_instant,'confirmed','pending');
    SELECT JSON_OBJECT('name',cp.name,'cutoff_hours',cp.cutoff_hours,'early_refund_pct',cp.early_refund_pct,
        'late_refund_pct',cp.late_refund_pct,'refund_fees',cp.refund_fees,'check_in_time',CAST(l.check_in_time AS CHAR),'time_zone',l.time_zone)
        INTO v_policy FROM listings l JOIN cancellation_policies cp ON cp.id=l.cancellation_policy_id WHERE l.id=p_listing;
    INSERT INTO bookings(listing_id,guest_id,check_in,check_out,guest_count,status,currency,fee_amount,total_amount,policy_snapshot,created_at,updated_at)
        VALUES(p_listing,p_guest,p_start,p_end,p_guests,v_status,v_currency,v_fee,v_total,v_policy,NOW(6),NOW(6));
    SET v_id=LAST_INSERT_ID(); SET v_day=p_start;
    WHILE v_day<p_end DO
        CALL LTP_sp_booking_extras_night_rate(v_day,v_rate,v_weekend_rate,v_holiday_rate,v_night,v_kind);
        INSERT INTO booking_nights(booking_id,stay_date,nightly_amount) VALUES(v_id,v_day,v_night);
        SET v_day=DATE_ADD(v_day,INTERVAL 1 DAY);
    END WHILE;
    INSERT INTO booking_price_breakdowns(booking_id,nights_subtotal,length_discount_type,length_discount_pct,length_discount_amount,promotion_id,promotion_code,
        promotion_discount_amount,fee_amount,total_amount,is_instant_book,created_at)
        VALUES(v_id,v_subtotal,v_length_type,v_length_pct,v_length_amount,v_promo_id,v_promo_code,v_promo_amount,v_fee,v_total,v_instant,NOW(6));
    INSERT INTO booking_payments(booking_id,method,amount_due,status,paid_at,paid_by,refunded_at,refunded_by,note,created_at,updated_at)
        VALUES(v_id,'pay_at_checkin',v_total,'unpaid',NULL,NULL,NULL,NULL,NULL,NOW(6),NOW(6));
    INSERT INTO booking_events(booking_id,actor_id,from_status,to_status,reason,created_at) VALUES(v_id,p_guest,NULL,'pending','Khách gửi yêu cầu đặt chỗ.',NOW(6));
    IF v_instant THEN
        INSERT INTO booking_events(booking_id,actor_id,from_status,to_status,reason,created_at)
            VALUES(v_id,NULL,'pending','confirmed','Đặt ngay (Instant Book): hệ thống tự xác nhận.',NOW(6));
        INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
            VALUES(p_guest,v_id,'in_app','booking_confirmed','Cập nhật booking',CONCAT('Đặt ngay thành công: booking #',v_id,' đã được tự động xác nhận.'),'sent',1,NOW(6));
        INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
            VALUES(v_host,v_id,'in_app','booking_instant','Cập nhật booking',CONCAT('Bạn có booking Đặt ngay mới cho “',v_title,'” (#',v_id,'), đã được tự động xác nhận.'),'sent',1,NOW(6));
    ELSE
        INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
            VALUES(v_host,v_id,'in_app','booking_created','Cập nhật booking',CONCAT('Bạn có yêu cầu đặt chỗ mới cho “',v_title,'”.'),'sent',1,NOW(6));
    END IF;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_booking_create; END IF;
    SELECT v_id booking_id,v_status booking_status;
END$$
DELIMITER ;

-- 070_booking_extras.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE DST_sp_booking_extras_detail(IN p_booking BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
BEGIN
    CALL DST_sp_quality_assert_booking(p_booking,p_actor);
    SELECT b.id booking_id,b.status booking_status,b.check_in,b.total_amount,b.fee_amount,(bp.booking_id IS NOT NULL) has_breakdown,
        bp.nights_subtotal,bp.length_discount_type,bp.length_discount_pct,bp.length_discount_amount,bp.promotion_code,
        COALESCE(bp.promotion_discount_amount,0) promotion_discount_amount,COALESCE(bp.is_instant_book,0) is_instant_book,
        COALESCE(pay.method,'pay_at_checkin') payment_method,COALESCE(pay.amount_due,b.total_amount) amount_due,COALESCE(pay.status,'unpaid') payment_status,
        CASE WHEN COALESCE(pay.status,'unpaid')='unpaid' AND b.status IN ('rejected','cancelled') THEN 'not_required' ELSE COALESCE(pay.status,'unpaid') END payment_state,
        (COALESCE(pay.status,'unpaid')='unpaid' AND b.status IN ('confirmed','completed') AND b.check_in<=DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR)) can_mark_paid,
        -- Online payment (095_online_payment.sql): only the Guest of a confirmed, unpaid VND booking may pay online; a taken-down listing or a suspended or deleted Host only for a finished stay. host_locked gives the page the reason for a suspension.
        (b.guest_id=p_actor AND COALESCE(pay.status,'unpaid')='unpaid' AND b.status='confirmed' AND b.currency='VND' AND COALESCE(pay.amount_due,b.total_amount)>0 AND ((l.deleted_at IS NULL AND h.status='active' AND h.deleted_at IS NULL) OR b.check_out<=DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR))) can_pay_online,
        (l.deleted_at IS NULL AND h.status='suspended' AND h.deleted_at IS NULL AND b.status IN ('pending','confirmed') AND b.check_out>DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR)) host_locked,
        t.id open_transfer_id,t.status open_transfer_status,t.reference open_transfer_reference,t.amount open_transfer_amount,
        pay.paid_at,pay.refunded_at,pay.note payment_note,payer.full_name paid_by_name,refunder.full_name refunded_by_name,
        COALESCE(c.refund_amount,pay.amount_due,b.total_amount) refund_amount,
        COALESCE(pay.paid_at>c.created_at,0) paid_after_cancel, -- Money that arrived after the cancel goes back whole, not under the policy.
        CASE WHEN pay.status='refunded' THEN COALESCE((SELECT CAST(JSON_VALUE(a.change_summary,'$.amount') AS DECIMAL(12,2)) FROM admin_audit_logs a WHERE a.action='refund_payment' AND a.entity_type='booking' AND a.entity_id=b.id ORDER BY a.id DESC LIMIT 1),pay.amount_due) END refunded_amount
    FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN users h ON h.id=l.host_id LEFT JOIN booking_price_breakdowns bp ON bp.booking_id=b.id LEFT JOIN booking_payments pay ON pay.booking_id=b.id
        LEFT JOIN booking_cancellations c ON c.booking_id=b.id
        LEFT JOIN users payer ON payer.id=pay.paid_by LEFT JOIN users refunder ON refunder.id=pay.refunded_by
        LEFT JOIN payment_transactions t ON t.id=(SELECT x.id FROM payment_transactions x
            WHERE x.booking_id=b.id AND x.provider='vietqr' AND x.status IN ('pending','submitted') ORDER BY x.id DESC LIMIT 1)
    WHERE b.id=p_booking;
END$$
DELIMITER ;

-- 080_conversation.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE DST_sp_booking_message_state(IN p_booking BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
BEGIN
    DECLARE v_today DATE;
    SET v_today=DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR);
    CALL NMT_sp_booking_message_assert(p_booking,p_actor);
    SELECT IF(b.guest_id=p_actor,'guest','host') my_role,
        IF(b.guest_id=p_actor,l.host_id,b.guest_id) other_id,
        IF(b.guest_id=p_actor,h.full_name,g.full_name) other_name,
        IF(b.guest_id=p_actor,h.deleted_at,g.deleted_at) IS NOT NULL other_deleted,
        b.status,b.check_out+INTERVAL 14 DAY closes_on,
        IF(IF(b.guest_id=p_actor,h.deleted_at,g.deleted_at) IS NULL AND (b.status IN ('pending','confirmed') OR (b.status='completed' AND v_today<=b.check_out+INTERVAL 14 DAY)),1,0) can_send
    FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN users g ON g.id=b.guest_id JOIN users h ON h.id=l.host_id
    WHERE b.id=p_booking;
END$$
DELIMITER ;

-- 075_trust.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE DST_sp_checkin_guide_for_booking(IN p_booking BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
BEGIN
    -- No row unless the booking is confirmed/completed and the actor is its Guest, the listing Host or an Admin.
    -- Door code and Wi-Fi come back only while the booking is confirmed, from the day before check-in (the lead time of
    -- CheckInReminder::DEFAULT_DAYS) through the check-out date, Vietnam time. Only the listing Host, who wrote them, always gets them.
    DECLARE v_today DATE DEFAULT DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR);
    SELECT s.arrival_instructions,IF(s.codes_state='open',s.door_code,NULL) door_code,IF(s.codes_state='open',s.wifi_name,NULL) wifi_name,
        IF(s.codes_state='open',s.wifi_password,NULL) wifi_password,s.note,s.updated_at,s.codes_state,s.codes_from
    FROM (SELECT g.arrival_instructions,g.door_code,g.wifi_name,g.wifi_password,g.note,g.updated_at,b.check_in-INTERVAL 1 DAY codes_from,
            CASE WHEN g.door_code IS NULL AND g.wifi_name IS NULL AND g.wifi_password IS NULL THEN 'none'
                WHEN l.host_id=p_actor AND EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_actor AND role='host') THEN 'open'
                WHEN b.status<>'confirmed' OR v_today>b.check_out THEN 'ended'
                WHEN v_today<b.check_in-INTERVAL 1 DAY THEN 'upcoming'
                ELSE 'open' END codes_state
        FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN listing_checkin_guides g ON g.listing_id=b.listing_id
            JOIN users a ON a.id=p_actor
        WHERE b.id=p_booking AND b.status IN ('confirmed','completed') AND a.status='active' AND a.deleted_at IS NULL
            AND (b.guest_id=p_actor
                OR (l.host_id=p_actor AND EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_actor AND role='host'))
                OR EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_actor AND role='admin'))) s;
END$$
DELIMITER ;

-- 085_discovery.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE DST_sp_discovery_recommendations(IN p_user BIGINT UNSIGNED, IN p_limit INT)
BEGIN
    DECLARE v_history BOOL DEFAULT FALSE;
    DECLARE v_view_price DECIMAL(14,2);
    DECLARE v_search_price DECIMAL(14,2);
    DECLARE v_price DECIMAL(14,2);
    IF p_limit IS NULL OR p_limit<1 OR p_limit>24 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Số gợi ý phải từ 1 đến 24.';
    END IF;
    IF NOT EXISTS(SELECT 1 FROM users WHERE id=p_user AND status='active' AND deleted_at IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tài khoản không hợp lệ để nhận gợi ý.';
    END IF;
    SET v_history=EXISTS(SELECT 1 FROM listing_views WHERE user_id=p_user) OR EXISTS(SELECT 1 FROM search_history WHERE user_id=p_user);
    SET v_view_price=(SELECT SUM(l.base_nightly_rate*LEAST(v.view_count,5))/SUM(LEAST(v.view_count,5))
        FROM listing_views v JOIN listings l ON l.id=v.listing_id WHERE v.user_id=p_user);
    SET v_search_price=(SELECT AVG(CASE WHEN min_price IS NOT NULL AND max_price IS NOT NULL THEN (min_price+max_price)/2 ELSE COALESCE(max_price,min_price) END)
        FROM search_history WHERE user_id=p_user AND (min_price IS NOT NULL OR max_price IS NOT NULL));
    SET v_price=CASE WHEN v_view_price IS NOT NULL AND v_search_price IS NOT NULL THEN (v_view_price+v_search_price)/2 ELSE COALESCE(v_view_price,v_search_price) END;
    SELECT c.id,c.title,c.city,c.property_type,c.max_guests,c.nightly_price,c.currency,c.image_url,c.rating,c.review_count,c.seen_count,
        v_history personalized,
        ROUND((CASE WHEN c.city_views>0 THEN 3+LEAST(c.city_views-1,4)*0.25 ELSE 0 END)
            +(CASE WHEN c.search_hits>0 THEN 2+LEAST(c.search_hits-1,4)*0.2 ELSE 0 END)
            +(CASE WHEN c.type_hits>0 THEN 1.5 ELSE 0 END)
            +1.5*c.price_fit
            +c.rating/5+LEAST(c.review_count,20)/40+LEAST(c.viewer_count,10)/20
            -(CASE WHEN c.seen_count>0 THEN 2.5 ELSE 0 END),3) score,
        CASE WHEN c.seen_count>0 THEN 'Bạn đã xem chỗ ở này gần đây'
            WHEN c.city_views>0 THEN CONCAT('Vì bạn đã xem chỗ ở tại ',c.city)
            WHEN c.search_hits>0 THEN CONCAT('Vì bạn đã tìm kiếm “',c.search_location,'”')
            WHEN c.type_hits>0 THEN CONCAT('Cùng loại “',c.property_type,'” bạn quan tâm')
            WHEN c.price_fit>=0.75 THEN 'Mức giá gần với lựa chọn của bạn'
            WHEN c.review_count>0 AND c.rating>=4 THEN 'Được khách đánh giá cao'
            WHEN c.viewer_count>0 THEN 'Nhiều người đang quan tâm'
            ELSE 'Chỗ ở nổi bật trên Home2Home' END reason
    FROM (
        SELECT l.id,l.title,l.city,pt.name property_type,l.max_guests,l.base_nightly_rate nightly_price,l.currency,
            (SELECT image_url FROM listing_photos WHERE listing_id=l.id ORDER BY sort_order,id LIMIT 1) image_url,
            COALESCE((SELECT AVG(r.rating) FROM reviews r JOIN bookings b ON b.id=r.booking_id WHERE b.listing_id=l.id AND r.moderation_status='visible'),0) rating,
            (SELECT COUNT(*) FROM reviews r JOIN bookings b ON b.id=r.booking_id WHERE b.listing_id=l.id AND r.moderation_status='visible') review_count,
            (SELECT COUNT(*) FROM listing_views pv WHERE pv.listing_id=l.id) viewer_count,
            COALESCE((SELECT v.view_count FROM listing_views v WHERE v.user_id=p_user AND v.listing_id=l.id),0) seen_count,
            COALESCE((SELECT SUM(LEAST(v.view_count,5)) FROM listing_views v JOIN listings vl ON vl.id=v.listing_id
                WHERE v.user_id=p_user AND vl.id<>l.id AND vl.city=l.city),0) city_views,
            (SELECT COUNT(*) FROM search_history s WHERE s.user_id=p_user AND s.location IS NOT NULL
                AND (REPLACE(REPLACE(l.city,'đ','d'),'Đ','D') LIKE CONCAT('%',REPLACE(REPLACE(s.location,'đ','d'),'Đ','D'),'%') OR REPLACE(REPLACE(l.address,'đ','d'),'Đ','D') LIKE CONCAT('%',REPLACE(REPLACE(s.location,'đ','d'),'Đ','D'),'%') OR REPLACE(REPLACE(l.title,'đ','d'),'Đ','D') LIKE CONCAT('%',REPLACE(REPLACE(s.location,'đ','d'),'Đ','D'),'%'))) search_hits,
            (SELECT s.location FROM search_history s WHERE s.user_id=p_user AND s.location IS NOT NULL
                AND (REPLACE(REPLACE(l.city,'đ','d'),'Đ','D') LIKE CONCAT('%',REPLACE(REPLACE(s.location,'đ','d'),'Đ','D'),'%') OR REPLACE(REPLACE(l.address,'đ','d'),'Đ','D') LIKE CONCAT('%',REPLACE(REPLACE(s.location,'đ','d'),'Đ','D'),'%') OR REPLACE(REPLACE(l.title,'đ','d'),'Đ','D') LIKE CONCAT('%',REPLACE(REPLACE(s.location,'đ','d'),'Đ','D'),'%'))
                ORDER BY s.id DESC LIMIT 1) search_location,
            (SELECT COUNT(*) FROM listing_views v JOIN listings vl ON vl.id=v.listing_id WHERE v.user_id=p_user AND vl.id<>l.id AND vl.property_type_id=l.property_type_id)
                +(SELECT COUNT(*) FROM search_history s WHERE s.user_id=p_user AND s.property_type_id=l.property_type_id) type_hits,
            CASE WHEN v_price IS NULL OR v_price<=0 THEN 0 ELSE GREATEST(0,1-ABS(l.base_nightly_rate-v_price)/v_price) END price_fit
        FROM listings l JOIN property_types pt ON pt.id=l.property_type_id JOIN users u ON u.id=l.host_id
        WHERE l.moderation_status='approved' AND l.is_visible=1 AND l.deleted_at IS NULL AND u.status='active' AND u.deleted_at IS NULL
            AND l.host_id<>p_user
    ) c
    ORDER BY score DESC,c.rating DESC,c.review_count DESC,c.id DESC
    LIMIT p_limit;
END$$
DELIMITER ;

-- 070_booking_extras.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE DST_sp_host_booking_settings_save(IN p_listing BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED,
    IN p_weekend DECIMAL(20,2), IN p_holiday DECIMAL(20,2), IN p_weekly DECIMAL(5,2), IN p_monthly DECIMAL(5,2), IN p_instant BOOL)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_booking_settings_save; RELEASE SAVEPOINT h2h_booking_settings_save; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_booking_settings_save; END IF;
    -- Same first lock as DST_sp_booking_create, so a booking never prices with half-saved rules.
    SELECT id INTO v_lock FROM listings WHERE id=p_listing FOR UPDATE;
    CALL LTP_sp_quality_assert_host(p_listing,p_actor);
    -- A rate that rounds to 0 would make those nights free; leave the field empty to keep the base price.
    IF ROUND(p_weekend,0)<1 OR ROUND(p_holiday,0)<1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Giá cuối tuần/ngày lễ phải từ 1đ trở lên.';
    END IF;
    IF p_weekend>9999999999.99 OR p_holiday>9999999999.99 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Giá cuối tuần/ngày lễ tối đa là 9.999.999.999đ.';
    END IF;
    IF (p_weekly IS NOT NULL AND (p_weekly<0 OR p_weekly>90)) OR (p_monthly IS NOT NULL AND (p_monthly<0 OR p_monthly>90)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Phần trăm giảm giá phải từ 0 đến 90.';
    END IF;
    IF p_instant IS NULL OR p_instant NOT IN (0,1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tùy chọn Đặt ngay không hợp lệ.'; END IF;
    INSERT INTO listing_booking_settings(listing_id,weekend_nightly_rate,holiday_nightly_rate,weekly_discount_pct,monthly_discount_pct,instant_book,created_at,updated_at)
        VALUES(p_listing,p_weekend,p_holiday,p_weekly,p_monthly,p_instant,NOW(6),NOW(6))
        ON DUPLICATE KEY UPDATE weekend_nightly_rate=VALUES(weekend_nightly_rate),holiday_nightly_rate=VALUES(holiday_nightly_rate),
            weekly_discount_pct=VALUES(weekly_discount_pct),monthly_discount_pct=VALUES(monthly_discount_pct),instant_book=VALUES(instant_book),updated_at=NOW(6);
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_booking_settings_save; END IF;
END$$
DELIMITER ;

-- 050_quality_core.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE DST_sp_listing_calendar(IN p_listing BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED, IN p_start DATE, IN p_end DATE)
BEGIN
    IF p_start IS NULL OR p_end IS NULL OR p_end<=p_start OR DATEDIFF(p_end,p_start)>62 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Khoảng lịch không hợp lệ (tối đa 62 ngày).';
    END IF;
    IF NOT EXISTS(SELECT 1 FROM listings l JOIN users h ON h.id=l.host_id WHERE l.id=p_listing AND l.deleted_at IS NULL
        AND ((l.moderation_status='approved' AND l.is_visible=1 AND h.status='active')
            OR (l.host_id=p_actor AND h.status='active' AND EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_actor AND role='host'))
            OR EXISTS(SELECT 1 FROM users a JOIN user_roles r ON r.user_id=a.id WHERE a.id=p_actor AND a.status='active' AND r.role='admin'))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỗ ở không khả dụng.';
    END IF;
    WITH RECURSIVE days AS (SELECT p_start stay_date UNION ALL SELECT DATE_ADD(stay_date,INTERVAL 1 DAY) FROM days WHERE DATE_ADD(stay_date,INTERVAL 1 DAY)<p_end)
    SELECT d.stay_date,
        CASE WHEN d.stay_date<DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR) THEN 'past'
            WHEN EXISTS(SELECT 1 FROM bookings b WHERE b.listing_id=p_listing AND b.status='confirmed' AND b.check_in<=d.stay_date AND b.check_out>d.stay_date) THEN 'confirmed'
            WHEN a.is_open=0 THEN 'blocked'
            WHEN EXISTS(SELECT 1 FROM bookings b WHERE b.listing_id=p_listing AND b.status='pending' AND b.check_in>=DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR) AND b.check_in<=d.stay_date AND b.check_out>d.stay_date) THEN 'pending'
            ELSE 'available' END day_status,
        CASE WHEN EXISTS(SELECT 1 FROM listings WHERE id=p_listing AND host_id=p_actor) THEN a.block_reason ELSE NULL END block_reason
    FROM days d LEFT JOIN listing_availability a ON a.listing_id=p_listing AND a.stay_date=d.stay_date ORDER BY d.stay_date;
END$$
DELIMITER ;

-- 055_quality_search.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE DST_sp_listing_search_filtered(
    IN p_location VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,IN p_guests INT,IN p_type BIGINT UNSIGNED,IN p_min DECIMAL(12,2),IN p_max DECIMAL(12,2),IN p_bedrooms INT,
    IN p_start DATE,IN p_end DATE,IN p_sort VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,IN p_limit INT,IN p_offset INT, IN p_amenities LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    -- đ has its own weight in every collation of MariaDB 10.4, unlike the other Vietnamese accents, so "Da Lat" only finds "Đà Lạt" with it folded on both sides. REPLACE is case-sensitive, hence both cases.
    DECLARE v_location VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT REPLACE(REPLACE(p_location,'đ','d'),'Đ','D');
    DECLARE v_i INT DEFAULT 0;
    DECLARE v_amenity BIGINT UNSIGNED;
    IF p_amenities IS NULL OR NOT JSON_VALID(p_amenities) OR JSON_TYPE(p_amenities)<>'ARRAY' OR JSON_LENGTH(p_amenities)>100 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tiện nghi lọc không hợp lệ.';
    END IF;
    WHILE v_i<JSON_LENGTH(p_amenities) DO
        IF JSON_TYPE(JSON_EXTRACT(p_amenities,CONCAT('$[',v_i,']')))<>'INTEGER' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ID tiện nghi không hợp lệ.'; END IF;
        SET v_amenity=JSON_EXTRACT(p_amenities,CONCAT('$[',v_i,']'));
        IF NOT EXISTS(SELECT 1 FROM amenities WHERE id=v_amenity AND is_active=1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tiện nghi lọc đã ngừng sử dụng hoặc không tồn tại.'; END IF;
        SET v_i=v_i+1;
    END WHILE;
    IF p_limit<1 OR p_limit>100 OR p_offset<0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Phân trang không hợp lệ.'; END IF;
    SELECT SQL_CALC_FOUND_ROWS l.id,l.host_id,l.property_type_id,l.cancellation_policy_id,l.title,l.description,l.address,l.city,l.country_code,l.latitude,l.longitude,l.time_zone,l.base_nightly_rate,l.fee_amount,l.currency,l.max_guests,l.room_count,l.bedroom_count,l.bed_count,l.check_in_time,l.check_out_time,l.smoking_allowed,l.pets_allowed,l.parties_allowed,l.house_rules,l.moderation_status,l.moderation_reason,l.reviewed_by,l.reviewed_at,l.is_visible,l.created_at,l.updated_at,l.deleted_at,l.base_nightly_rate nightly_price,l.fee_amount cleaning_fee,pt.name property_type,u.full_name host_name,
        (SELECT image_url FROM listing_photos WHERE listing_id=l.id ORDER BY sort_order,id LIMIT 1) image_url,
        COALESCE((SELECT AVG(r.rating) FROM reviews r JOIN bookings b ON b.id=r.booking_id WHERE b.listing_id=l.id AND r.moderation_status='visible'),0) rating,
        (SELECT COUNT(*) FROM reviews r JOIN bookings b ON b.id=r.booking_id WHERE b.listing_id=l.id AND r.moderation_status='visible') review_count
    FROM listings l JOIN property_types pt ON pt.id=l.property_type_id JOIN users u ON u.id=l.host_id
    WHERE l.moderation_status='approved' AND l.is_visible=1 AND l.deleted_at IS NULL AND u.status='active' AND u.deleted_at IS NULL
        AND (p_location='' OR REPLACE(REPLACE(l.city,'đ','d'),'Đ','D') LIKE CONCAT('%',v_location,'%') OR REPLACE(REPLACE(l.address,'đ','d'),'Đ','D') LIKE CONCAT('%',v_location,'%') OR REPLACE(REPLACE(l.title,'đ','d'),'Đ','D') LIKE CONCAT('%',v_location,'%'))
        AND (p_guests=0 OR l.max_guests>=p_guests) AND (p_type=0 OR l.property_type_id=p_type)
        AND (p_min=0 OR l.base_nightly_rate>=p_min) AND (p_max=0 OR l.base_nightly_rate<=p_max)
        AND (p_bedrooms=0 OR l.bedroom_count>=p_bedrooms)
        AND NOT EXISTS(SELECT 1 FROM amenities wanted WHERE JSON_CONTAINS(p_amenities,CAST(wanted.id AS CHAR))
            AND NOT EXISTS(SELECT 1 FROM listing_amenities la WHERE la.listing_id=l.id AND la.amenity_id=wanted.id))
        AND (p_start IS NULL OR p_end IS NULL OR (
            NOT EXISTS(SELECT 1 FROM listing_availability a WHERE a.listing_id=l.id AND a.is_open=0 AND a.stay_date>=p_start AND a.stay_date<p_end)
            AND NOT EXISTS(SELECT 1 FROM bookings b WHERE b.listing_id=l.id AND b.status IN ('pending','confirmed') AND (b.status='confirmed' OR b.check_in>=DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR)) AND b.check_in<p_end AND b.check_out>p_start)))
    ORDER BY CASE WHEN p_sort='price_asc' THEN l.base_nightly_rate END ASC,
        CASE WHEN p_sort='price_desc' THEN l.base_nightly_rate END DESC,
        CASE WHEN p_sort='rating' THEN rating END DESC,l.created_at DESC,l.id DESC
    LIMIT p_limit OFFSET p_offset;
    SELECT FOUND_ROWS() __total;
END$$
DELIMITER ;

-- 095_online_payment.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE DST_sp_payment_transaction_get(IN p_transaction BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
BEGIN
    IF NOT EXISTS(SELECT 1 FROM payment_transactions t JOIN bookings b ON b.id=t.booking_id JOIN users u ON u.id=p_actor
        WHERE t.id=p_transaction AND u.status='active' AND u.deleted_at IS NULL
        AND (b.guest_id=p_actor OR EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_actor AND role='admin'))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không tìm thấy giao dịch thanh toán.';
    END IF;
    SELECT t.id transaction_id,t.booking_id,t.provider,t.reference,t.amount,t.status,t.submitted_at,t.decided_at,t.note,t.created_at,
        b.status booking_status,b.check_in,b.check_out,b.guest_id,l.title,COALESCE(pay.status,'unpaid') payment_status,COALESCE(pay.method,'pay_at_checkin') payment_method,
        ((l.deleted_at IS NOT NULL OR h.status='deleted' OR h.deleted_at IS NOT NULL) AND b.check_out>DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR)) listing_removed,
        (l.deleted_at IS NULL AND h.status='suspended' AND h.deleted_at IS NULL AND b.status IN ('pending','confirmed') AND b.check_out>DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR)) host_locked
    FROM payment_transactions t JOIN bookings b ON b.id=t.booking_id JOIN listings l ON l.id=b.listing_id JOIN users h ON h.id=l.host_id LEFT JOIN booking_payments pay ON pay.booking_id=b.id
    WHERE t.id=p_transaction;
END$$
DELIMITER ;

-- 010_models.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE `DST_sp_review_create`(IN p_1 smallint, IN p_2 text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_3 bigint unsigned, IN p_4 bigint unsigned)
BEGIN
    IF p_1 IS NULL OR p_1<1 OR p_1>5 OR p_2 IS NULL OR TRIM(p_2)='' OR NOT EXISTS(SELECT 1 FROM users WHERE id=p_4 AND status='active') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Đánh giá không hợp lệ.';
    END IF;
    -- Same limit as Review::MAX_COMMENT_LENGTH. Outside strict mode a longer comment would be cut at 64 KB by this TEXT parameter and still count as over the limit here.
    IF CHAR_LENGTH(p_2)>5000 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Nhận xét tối đa 5000 ký tự.'; END IF;
    INSERT INTO reviews(booking_id,rating,comment,moderation_status,created_at,updated_at) SELECT b.id,p_1,p_2,'visible',NOW(6),NOW(6) FROM bookings b WHERE b.id=p_3 AND b.guest_id=p_4 AND b.status='completed' AND b.check_out<=CURDATE() AND NOT EXISTS(SELECT 1 FROM reviews WHERE booking_id=b.id);
    SELECT ROW_COUNT() __affected,LAST_INSERT_ID() __insert_id;
END$$
DELIMITER ;

-- 030_atomic_writes.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE DST_sp_user_roles_replace(IN p_1 bigint unsigned, IN p_2 longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_3 bigint unsigned)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_i INT DEFAULT 0;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE v_had_host BOOL DEFAULT FALSE;
    DECLARE v_role VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_listings INT DEFAULT 0;
    DECLARE v_bookings INT DEFAULT 0;
    DECLARE v_message VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
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
    -- Without the Host role nobody can answer the listing's requests, so it stays until the listings and their bookings are dealt with.
    IF NOT JSON_CONTAINS(p_2,'"host"') AND EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_1 AND role='host') THEN
        SELECT COUNT(*) INTO v_listings FROM listings WHERE host_id=p_1 AND deleted_at IS NULL FOR UPDATE;
        SELECT COUNT(*) INTO v_bookings FROM bookings b JOIN listings l ON l.id=b.listing_id WHERE l.host_id=p_1 AND b.status IN ('pending','confirmed') FOR UPDATE;
        IF v_listings>0 OR v_bookings>0 THEN
            SET v_message=CONCAT('Không thể bỏ vai trò Host: tài khoản còn ',v_listings,' chỗ ở và ',v_bookings,' booking chưa kết thúc. Hãy gỡ chỗ ở và xử lý booking trước.');
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT=v_message;
        END IF;
    END IF;
    SELECT id INTO v_lock FROM users WHERE id=p_1 AND deleted_at IS NULL FOR UPDATE;
    IF v_lock IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tài khoản không tồn tại.'; END IF;
    IF p_3 IS NOT NULL THEN CALL NTK_sp_admin_assert_role(p_3); END IF;
    SET v_had_host=EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_1 AND role='host');
    DELETE FROM user_roles WHERE user_id=p_1;
    WHILE v_i<JSON_LENGTH(p_2) DO
        SET v_role=JSON_UNQUOTE(JSON_EXTRACT(p_2,CONCAT('$[',v_i,']')));
        IF v_role NOT IN ('guest','host','admin') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Vai trò không hợp lệ.'; END IF;
        INSERT INTO user_roles(user_id,role,granted_at) VALUES(p_1,v_role,NOW(6)) ON DUPLICATE KEY UPDATE role=VALUES(role);
        SET v_i=v_i+1;
    END WHILE;
    -- LTP_sp_user_grant_host reads these back: the latest row says whether /profile/become-host is open to this account.
    IF p_3 IS NOT NULL AND v_had_host<>JSON_CONTAINS(p_2,'"host"') THEN
        INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at)
            VALUES(p_3,IF(v_had_host,'remove_host_role','grant_host_role'),'user',p_1,JSON_OBJECT('details',IF(v_had_host,'Host role removed','Host role granted')),NOW(6));
    END IF;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_user_roles_replace; END IF;
END$$
DELIMITER ;

-- 050_quality_core.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE LTP_sp_admin_booking_search(IN p_actor BIGINT UNSIGNED, IN p_query VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_status VARCHAR(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_number BIGINT UNSIGNED DEFAULT NULL;
    -- User input is matched literally: LIKE wildcards are escaped.
    -- đ has its own weight in every collation here, unlike the other Vietnamese accents, so it is folded on both sides as in location search (NTK_sp_listing_search). REPLACE is case-sensitive, hence both cases.
    DECLARE v_query VARCHAR(600) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(p_query,'\\','\\\\'),'%','\\%'),'_','\\_'),'đ','d'),'Đ','D');
    CALL NTK_sp_admin_assert_role(p_actor);
    IF p_status NOT IN ('','pending','confirmed','rejected','cancelled','completed') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Trạng thái lọc không hợp lệ.'; END IF;
    -- The site prints a booking as #12, so searching #12 means exactly that booking; anything else stays a text search.
    IF p_query REGEXP '^#[0-9]{1,18}$' THEN SET v_number=SUBSTRING(p_query,2); END IF;
    SELECT b.*,l.title,u.full_name guest_name,h.full_name host_name
    FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN users u ON u.id=b.guest_id JOIN users h ON h.id=l.host_id
    WHERE (p_status='' OR b.status=p_status) AND (b.id=v_number OR v_number IS NULL AND (p_query='' OR CAST(b.id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci=p_query OR REPLACE(REPLACE(l.title,'đ','d'),'Đ','D') LIKE CONCAT('%',v_query,'%') OR REPLACE(REPLACE(u.full_name,'đ','d'),'Đ','D') LIKE CONCAT('%',v_query,'%') OR REPLACE(REPLACE(h.full_name,'đ','d'),'Đ','D') LIKE CONCAT('%',v_query,'%')))
    ORDER BY b.created_at DESC,b.id DESC LIMIT 200;
END$$
DELIMITER ;

-- 030_atomic_writes.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE LTP_sp_admin_listing_soft_delete(IN p_1 bigint unsigned, IN p_2 bigint unsigned)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE v_count INT DEFAULT 0;
    DECLARE v_message VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_admin_listing_soft_delete; RELEASE SAVEPOINT h2h_admin_listing_soft_delete; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_admin_listing_soft_delete; END IF;
    CALL NTK_sp_admin_assert_role(p_2);
    SELECT id INTO v_lock FROM listings WHERE id=p_1 AND deleted_at IS NULL FOR UPDATE;
    IF v_lock IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỗ ở không tồn tại.'; END IF;
    -- A stay is finished once it can be completed (check-out date reached). No admin route cancels a confirmed stay, so the message points to the people involved.
    SELECT COUNT(*) INTO v_count FROM bookings WHERE listing_id=p_1 AND status='confirmed' AND check_out>DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR) FOR UPDATE;
    IF v_count>0 THEN
        SET v_message=CONCAT('Không thể gỡ chỗ ở: còn ',v_count,' booking đã xác nhận chưa kết thúc. Hãy liên hệ Host và khách để xử lý trước. Có thể ẩn tin để ngừng nhận yêu cầu mới.');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT=v_message;
    END IF;
    -- Pending requests cannot be honoured any more.
    CALL LTP_sp_listing_reject_pending(p_1,p_2,'Admin gỡ chỗ ở nên yêu cầu bị từ chối tự động.','chỗ ở đã được gỡ khỏi hệ thống',v_count);
    UPDATE listings SET is_visible=0,deleted_at=NOW(6),updated_at=NOW(6) WHERE id=p_1;
    INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
        SELECT host_id,NULL,'in_app','listing_removed','Chỗ ở đã bị gỡ',CONCAT('Admin đã gỡ chỗ ở “',title,'” khỏi hệ thống.'),'sent',1,NOW(6) FROM listings WHERE id=p_1;
    INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at) VALUES(p_2,'delete_listing','listing',p_1,JSON_OBJECT('details','Soft delete','rejected_pending',v_count),NOW(6));
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_admin_listing_soft_delete; END IF;
END$$
DELIMITER ;

-- 080_conversation.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE LTP_sp_admin_message_moderate(IN p_id BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED,
    IN p_action VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_reason TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_booking BIGINT UNSIGNED;
    DECLARE v_hidden DATETIME(6);
    DECLARE v_reason TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_length INT;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_message_moderate; RELEASE SAVEPOINT h2h_message_moderate; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_message_moderate; END IF;
    CALL NTK_sp_admin_assert_role(p_actor);
    IF p_action IS NULL OR p_action NOT IN ('hide','unhide') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Thao tác kiểm duyệt tin nhắn không hợp lệ.';
    END IF;
    -- Trimmed with TRIM as NTK_sp_booking_message_send does: REGEXP_REPLACE on text turns every 4-byte character into '?' on MariaDB 10.4.
    SET v_reason=COALESCE(p_reason,'');
    REPEAT
        SET v_length=CHAR_LENGTH(v_reason);
        SET v_reason=TRIM(BOTH ' ' FROM TRIM(BOTH '\t' FROM TRIM(BOTH '\n' FROM TRIM(BOTH '\r' FROM TRIM(BOTH _utf8mb4 x'0B' FROM TRIM(BOTH _utf8mb4 x'0C' FROM v_reason))))));
    UNTIL CHAR_LENGTH(v_reason)=v_length END REPEAT;
    IF CHAR_LENGTH(v_reason)<5 OR CHAR_LENGTH(v_reason)>500 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Lý do kiểm duyệt cần từ 5 đến 500 ký tự.';
    END IF;
    SELECT booking_id,hidden_at INTO v_booking,v_hidden FROM booking_messages WHERE id=p_id FOR UPDATE;
    IF v_booking IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tin nhắn không tồn tại.'; END IF;
    IF (p_action='hide' AND v_hidden IS NOT NULL) OR (p_action='unhide' AND v_hidden IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tin nhắn đã ở trạng thái này.';
    END IF;
    IF p_action='hide' THEN
        UPDATE booking_messages SET hidden_at=NOW(6),hidden_by=p_actor,hidden_reason=v_reason WHERE id=p_id;
    ELSE
        UPDATE booking_messages SET hidden_at=NULL,hidden_by=NULL,hidden_reason=NULL WHERE id=p_id;
    END IF;
    CALL DST_sp_admin_audit_add(p_actor,CONCAT(p_action,'_message'),'booking_message',p_id,JSON_OBJECT('booking_id',v_booking,'reason',v_reason));
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_message_moderate; END IF;
    SELECT v_booking booking_id;
END$$
DELIMITER ;

-- 070_booking_extras.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE LTP_sp_admin_promotion_save(IN p_actor BIGINT UNSIGNED, IN p_id BIGINT UNSIGNED,
    IN p_code VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_description VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_type VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_value DECIMAL(12,2), IN p_max DECIMAL(12,2),
    IN p_min_nights INT, IN p_min_subtotal DECIMAL(12,2), IN p_from DATE, IN p_to DATE, IN p_limit INT, IN p_active BOOL)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_id BIGINT UNSIGNED;
    DECLARE v_old_code VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_code VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_description VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_promotion_save; RELEASE SAVEPOINT h2h_promotion_save; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_promotion_save; END IF;
    CALL NTK_sp_admin_assert_role(p_actor);
    SET v_code=UPPER(TRIM(COALESCE(p_code,''))); SET v_description=TRIM(COALESCE(p_description,''));
    IF NOT (CAST(v_code AS BINARY) REGEXP '^[A-Z0-9]{4,20}$') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Mã khuyến mãi phải gồm 4–20 chữ cái A–Z hoặc số.'; END IF;
    IF v_description='' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Vui lòng nhập mô tả khuyến mãi.'; END IF;
    IF p_type IS NULL OR p_type NOT IN ('percent','fixed') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Loại giảm giá không hợp lệ.'; END IF;
    IF p_value IS NULL OR p_value<=0 OR (p_type='percent' AND (p_value<1 OR p_value>100)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Giá trị giảm không hợp lệ (phần trăm từ 1 đến 100).'; END IF;
    -- A discount is whole dong: under 0.5 it rounds to nothing and would use up the code for no discount.
    IF p_type='fixed' AND ROUND(p_value,0)<1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Giá trị giảm không hợp lệ (số tiền từ 1đ trở lên).'; END IF;
    IF (p_max IS NOT NULL AND ROUND(p_max,0)<1) OR (p_min_nights IS NOT NULL AND (p_min_nights<1 OR p_min_nights>365))
        OR (p_min_subtotal IS NOT NULL AND p_min_subtotal<0) OR (p_limit IS NOT NULL AND p_limit<1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Điều kiện áp dụng khuyến mãi không hợp lệ.';
    END IF;
    IF p_from IS NULL OR p_to IS NULL OR p_to<p_from THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ngày kết thúc phải sau hoặc bằng ngày bắt đầu.'; END IF;
    IF p_active IS NULL OR p_active NOT IN (0,1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Trạng thái khuyến mãi không hợp lệ.'; END IF;
    IF p_type='fixed' THEN SET p_max=NULL; END IF;
    IF p_id IS NULL OR p_id=0 THEN
        IF EXISTS(SELECT 1 FROM promotions WHERE code=v_code) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Mã khuyến mãi đã tồn tại.'; END IF;
        INSERT INTO promotions(code,description,discount_type,discount_value,max_discount_amount,min_nights,min_subtotal,valid_from,valid_to,usage_limit,is_active,created_by,created_at,updated_at)
            VALUES(v_code,v_description,p_type,p_value,p_max,p_min_nights,p_min_subtotal,p_from,p_to,p_limit,p_active,p_actor,NOW(6),NOW(6));
        SET v_id=LAST_INSERT_ID();
    ELSE
        SELECT id,code INTO v_id,v_old_code FROM promotions WHERE id=p_id FOR UPDATE;
        IF v_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Khuyến mãi không tồn tại.'; END IF;
        IF v_old_code<>v_code THEN
            IF EXISTS(SELECT 1 FROM booking_price_breakdowns WHERE promotion_id=v_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không thể đổi mã đã được dùng cho booking.'; END IF;
            IF EXISTS(SELECT 1 FROM promotions WHERE code=v_code AND id<>v_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Mã khuyến mãi đã tồn tại.'; END IF;
        END IF;
        UPDATE promotions SET code=v_code,description=v_description,discount_type=p_type,discount_value=p_value,max_discount_amount=p_max,min_nights=p_min_nights,
            min_subtotal=p_min_subtotal,valid_from=p_from,valid_to=p_to,usage_limit=p_limit,is_active=p_active,updated_at=NOW(6) WHERE id=v_id;
    END IF;
    INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at)
        VALUES(p_actor,'save_promotion','promotion',v_id,JSON_OBJECT('code',v_code,'type',p_type,'value',p_value,'active',p_active),NOW(6));
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_promotion_save; END IF;
    SELECT v_id id;
END$$
DELIMITER ;

-- 060_quality_community.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE LTP_sp_admin_reviews(IN p_actor BIGINT UNSIGNED, IN p_limit INT, IN p_offset INT)
BEGIN
    CALL NTK_sp_admin_assert_role(p_actor);
    IF p_limit IS NULL OR p_offset IS NULL OR p_limit<1 OR p_limit>100 OR p_offset<0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Phân trang không hợp lệ.'; END IF;
    SELECT SQL_CALC_FOUND_ROWS r.*,l.title,u.full_name guest_name FROM reviews r JOIN bookings b ON b.id=r.booking_id
    JOIN listings l ON l.id=b.listing_id JOIN users u ON u.id=b.guest_id ORDER BY r.created_at DESC,r.id DESC LIMIT p_limit OFFSET p_offset;
    SELECT FOUND_ROWS() __total;
END$$
DELIMITER ;

-- 050_quality_core.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE LTP_sp_booking_detail(IN p_booking BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
BEGIN
    CALL DST_sp_quality_assert_booking(p_booking,p_actor);
    -- current_*: the listing's check-in time now; the refund deadline keeps the time stored in policy_snapshot. policy_description: the snapshot holds the terms but not their words, and policy names are unique.
    SELECT b.*,l.title,l.address,l.city,l.host_id,u.full_name guest_name,h.full_name host_name,
        (SELECT cp.description FROM cancellation_policies cp WHERE cp.name=CONVERT(JSON_VALUE(b.policy_snapshot,'$.name') USING utf8mb4) COLLATE utf8mb4_unicode_ci) policy_description,
        TIME_FORMAT(l.check_in_time,'%H:%i') current_check_in_time,l.time_zone current_time_zone,
        (l.moderation_status='approved' AND l.is_visible=1 AND l.deleted_at IS NULL AND h.status='active' AND h.deleted_at IS NULL) listing_public,
        EXISTS(SELECT 1 FROM reviews r WHERE r.booking_id=b.id) has_review,
        CASE WHEN b.status IN ('confirmed','completed') OR EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_actor AND role='admin') THEN h.phone END host_phone,
        CASE WHEN b.status IN ('confirmed','completed') OR EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_actor AND role='admin') THEN u.phone END guest_phone
    FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN users u ON u.id=b.guest_id JOIN users h ON h.id=l.host_id WHERE b.id=p_booking;
END$$
DELIMITER ;

-- 070_booking_extras.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE LTP_sp_booking_extras_night_rate(IN p_day DATE, IN p_base DECIMAL(20,2), IN p_weekend DECIMAL(20,2), IN p_holiday DECIMAL(20,2),
    OUT o_rate DECIMAL(20,2), OUT o_kind VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    IF p_holiday IS NOT NULL AND EXISTS(SELECT 1 FROM public_holidays WHERE holiday_date=p_day) THEN
        SET o_rate=ROUND(p_holiday,0); SET o_kind='holiday';
    ELSEIF p_weekend IS NOT NULL AND DAYOFWEEK(p_day) IN (6,7) THEN
        SET o_rate=ROUND(p_weekend,0); SET o_kind='weekend';
    ELSE
        SET o_rate=ROUND(p_base,0); SET o_kind='base';
    END IF;
END$$
DELIMITER ;

-- 070_booking_extras.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE LTP_sp_booking_payment_list(IN p_actor BIGINT UNSIGNED, IN p_scope VARCHAR(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    IF p_scope IS NULL OR p_scope NOT IN ('guest','host') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Phạm vi thanh toán không hợp lệ.'; END IF;
    IF NOT EXISTS(SELECT 1 FROM users WHERE id=p_actor AND status='active' AND deleted_at IS NULL)
        OR (p_scope='host' AND NOT EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_actor AND role='host')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không có quyền xem thanh toán.';
    END IF;
    SELECT b.id booking_id,COALESCE(pay.method,'pay_at_checkin') payment_method,COALESCE(pay.amount_due,b.total_amount) amount_due,COALESCE(pay.status,'unpaid') payment_status,
        CASE WHEN COALESCE(pay.status,'unpaid')='unpaid' AND b.status IN ('rejected','cancelled') THEN 'not_required' ELSE COALESCE(pay.status,'unpaid') END payment_state,
        (COALESCE(pay.status,'unpaid')='unpaid' AND b.status IN ('confirmed','completed') AND b.check_in<=DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR)) can_mark_paid,
        (p_scope='guest' AND COALESCE(pay.status,'unpaid')='unpaid' AND b.status='confirmed' AND b.currency='VND' AND COALESCE(pay.amount_due,b.total_amount)>0 AND ((l.deleted_at IS NULL AND h.status='active' AND h.deleted_at IS NULL) OR b.check_out<=DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR))) can_pay_online,
        EXISTS(SELECT 1 FROM payment_transactions x WHERE x.booking_id=b.id AND x.status='submitted') transfer_submitted,
        COALESCE(bp.is_instant_book,0) is_instant_book,COALESCE(bp.promotion_discount_amount,0)+COALESCE(bp.length_discount_amount,0) discount_amount
    FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN users h ON h.id=l.host_id LEFT JOIN booking_payments pay ON pay.booking_id=b.id LEFT JOIN booking_price_breakdowns bp ON bp.booking_id=b.id
    WHERE (p_scope='guest' AND b.guest_id=p_actor) OR (p_scope='host' AND l.host_id=p_actor)
    ORDER BY b.id DESC;
END$$
DELIMITER ;

-- 050_quality_core.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE LTP_sp_booking_refund_preview(IN p_booking BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
BEGIN
    DECLARE v_status VARCHAR(12);
    DECLARE v_start DATE;
    DECLARE v_total DECIMAL(12,2);
    DECLARE v_fee DECIMAL(12,2);
    DECLARE v_policy LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_percent DECIMAL(5,2) DEFAULT 0;
    DECLARE v_base DECIMAL(12,2) DEFAULT 0;
    DECLARE v_default_zone TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_default_time TIME;
    DECLARE v_payment VARCHAR(10);
    CALL DST_sp_quality_assert_booking(p_booking,p_actor);
    SELECT b.status,b.check_in,b.total_amount,b.fee_amount,b.policy_snapshot,l.check_in_time,l.time_zone INTO v_status,v_start,v_total,v_fee,v_policy,v_default_time,v_default_zone
        FROM bookings b JOIN listings l ON l.id=b.listing_id WHERE b.id=p_booking;
    SELECT status INTO v_payment FROM booking_payments WHERE booking_id=p_booking;
    IF v_status='cancelled' THEN
        SELECT refund_amount,calculation_details,reason,created_at FROM booking_cancellations WHERE booking_id=p_booking;
    ELSE
        IF COALESCE(v_payment,'unpaid') IN ('unpaid','refunded') THEN SET v_percent=0; SET v_base=0; -- Nothing held (not collected, or already refunded), nothing to refund.
        ELSEIF v_status='pending' THEN SET v_percent=100; SET v_base=v_total;
        ELSEIF v_status='confirmed' THEN CALL DST_sp_booking_refund_terms(p_booking,v_policy,v_start,v_total,v_fee,v_default_time,v_default_zone,v_percent,v_base);
        END IF;
        SELECT v_percent refund_percent,ROUND(GREATEST(0,v_base)*v_percent/100,0) refund_amount;
    END IF;
END$$
DELIMITER ;

-- 010_models.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE `LTP_sp_listing_create`(IN p_1 bigint unsigned, IN p_2 bigint unsigned, IN p_3 bigint unsigned, IN p_4 varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_5 text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_6 varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_7 text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_8 decimal(12,2), IN p_9 decimal(12,2), IN p_10 int, IN p_11 int, IN p_12 int, IN p_13 int, IN p_14 time, IN p_15 time, IN p_16 bool, IN p_17 bool, IN p_18 bool, IN p_19 decimal(10,6), IN p_20 decimal(10,6))
BEGIN
    IF p_8 IS NULL OR ROUND(p_8,0)<1 OR p_9 IS NULL OR p_9<0 OR p_10<1 OR p_11<1 OR p_12<1 OR p_13<1
        OR p_4 IS NULL OR TRIM(p_4)='' OR p_5 IS NULL OR TRIM(p_5)='' OR p_6 IS NULL OR TRIM(p_6)='' OR p_7 IS NULL OR TRIM(p_7)=''
        OR NOT EXISTS(SELECT 1 FROM users u JOIN user_roles r ON r.user_id=u.id WHERE u.id=p_1 AND u.status='active' AND u.deleted_at IS NULL AND r.role='host')
        OR NOT EXISTS(SELECT 1 FROM property_types WHERE id=p_2 AND is_active=1)
        OR NOT EXISTS(SELECT 1 FROM cancellation_policies WHERE id=p_3 AND is_active=1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Thông tin hoặc quyền tạo chỗ ở không hợp lệ.';
    END IF;
    IF (p_19 IS NULL)<>(p_20 IS NULL) OR p_19 NOT BETWEEN -90 AND 90 OR p_20 NOT BETWEEN -180 AND 180 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tọa độ cần có cả vĩ độ (-90 đến 90) và kinh độ (-180 đến 180), hoặc để trống cả hai.';
    END IF;
    INSERT INTO listings (host_id, property_type_id, cancellation_policy_id, title, address, city, country_code, latitude, longitude, time_zone, description, base_nightly_rate, fee_amount, currency, max_guests, room_count, bedroom_count, bed_count, check_in_time, check_out_time, smoking_allowed, pets_allowed, parties_allowed, moderation_status, is_visible, created_at, updated_at) VALUES (p_1,p_2,p_3,p_4,p_5,p_6,'VN',p_19,p_20,'Asia/Ho_Chi_Minh',p_7,p_8,p_9,'VND',p_10,p_11,p_12,p_13,p_14,p_15,p_16,p_17,p_18,'pending',1,NOW(6),NOW(6));
    SELECT ROW_COUNT() __affected,LAST_INSERT_ID() __insert_id;
END$$
DELIMITER ;

-- 010_models.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE `LTP_sp_listing_set_visible`(IN p_1 bool, IN p_2 bigint unsigned, IN p_3 bigint unsigned)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE v_visible BOOL;
    DECLARE v_affected INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_listing_set_visible; RELEASE SAVEPOINT h2h_listing_set_visible; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_listing_set_visible; END IF;
    -- The listing lock serializes this with NTK_sp_admin_listing_visibility.
    SELECT id,is_visible INTO v_lock,v_visible FROM listings WHERE id=p_2 AND host_id=p_3 AND deleted_at IS NULL FOR UPDATE;
    IF p_1 IS NULL OR p_1 NOT IN (0,1) OR v_lock IS NULL OR NOT EXISTS(SELECT 1 FROM users u JOIN user_roles r ON r.user_id=u.id WHERE u.id=p_3 AND u.status='active' AND r.role='host') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không có quyền thay đổi hiển thị chỗ ở.';
    END IF;
    -- A hide by an Admin holds until an Admin shows the listing. Same rule as admin_hidden in NMT_sp_listing_get_by_host: with no audit row, the Host hid it.
    IF p_1=1 AND v_visible=0 AND COALESCE((SELECT a.action FROM admin_audit_logs a WHERE a.entity_type='listing' AND a.entity_id=p_2 AND a.action IN ('hide_listing','restore_listing') ORDER BY a.id DESC LIMIT 1),'')='hide_listing' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Admin đã ẩn chỗ ở này nên chỉ Admin mới hiện lại được. Hãy liên hệ Admin nếu bạn cần.';
    END IF;
    UPDATE listings SET is_visible=p_1 WHERE id=p_2 AND host_id=p_3;
    SET v_affected=ROW_COUNT();
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_listing_set_visible; END IF;
    SELECT v_affected __affected,LAST_INSERT_ID() __insert_id;
END$$
DELIMITER ;

-- 050_quality_core.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE LTP_sp_notifications_list(IN p_actor BIGINT UNSIGNED)
BEGIN
    SELECT n.id,n.booking_id,n.event_type,n.title,n.body,n.read_at,n.created_at,
        -- Same rule as DST_sp_quality_assert_booking, so the page offers a link only when it opens.
        EXISTS(SELECT 1 FROM bookings b JOIN listings l ON l.id=b.listing_id WHERE b.id=n.booking_id
            AND (b.guest_id=p_actor OR (l.host_id=p_actor AND EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_actor AND role='host'))
                OR EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_actor AND role='admin'))) can_open_booking
    FROM notifications n
    WHERE n.user_id=p_actor AND n.channel='in_app' AND EXISTS(SELECT 1 FROM users WHERE id=p_actor AND status='active' AND deleted_at IS NULL)
    ORDER BY n.created_at DESC,n.id DESC LIMIT 200;
END$$
DELIMITER ;

-- 095_online_payment.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE LTP_sp_payment_booking_transactions(IN p_booking BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
BEGIN
    CALL DST_sp_quality_assert_booking(p_booking,p_actor);
    SELECT t.id transaction_id,t.provider,t.reference,t.amount,t.status,t.gateway_transaction_no,t.gateway_response_code,t.bank_code,
        t.submitted_at,t.decided_at,t.note,t.created_at,a.full_name decided_by_name,
        (t.provider='vnpay' AND t.status='succeeded' AND t.note IS NOT NULL) is_open_duplicate
    FROM payment_transactions t LEFT JOIN users a ON a.id=t.decided_by
    WHERE t.booking_id=p_booking ORDER BY t.id DESC LIMIT 20;
END$$
DELIMITER ;

-- 095_online_payment.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE LTP_sp_payment_transfer_submit(IN p_transaction BIGINT UNSIGNED, IN p_guest BIGINT UNSIGNED)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_booking BIGINT UNSIGNED;
    DECLARE v_listing BIGINT UNSIGNED;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE v_guest BIGINT UNSIGNED;
    DECLARE v_booking_status VARCHAR(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_payment VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_provider VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_status VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_reference VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_amount DECIMAL(12,2);
    DECLARE v_host BIGINT UNSIGNED;
    DECLARE v_removed DATETIME(6);
    DECLARE v_end DATE;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_payment_submit; RELEASE SAVEPOINT h2h_payment_submit; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_payment_submit; END IF;
    SELECT booking_id INTO v_booking FROM payment_transactions WHERE id=p_transaction;
    SELECT listing_id INTO v_listing FROM bookings WHERE id=v_booking;
    SELECT id,deleted_at,host_id INTO v_lock,v_removed,v_host FROM listings WHERE id=v_listing FOR UPDATE;
    -- A deleted Host counts as a removed listing: the stay will not happen. A suspended Host does not stop this report: the code was issued before the suspension, the money may already have left, and the Admins must still check it.
    SELECT IF(status='deleted' OR deleted_at IS NOT NULL,COALESCE(v_removed,NOW(6)),v_removed) INTO v_removed FROM users WHERE id=v_host LOCK IN SHARE MODE;
    SELECT guest_id,status,check_out INTO v_guest,v_booking_status,v_end FROM bookings WHERE id=v_booking FOR UPDATE;
    IF v_guest IS NULL OR v_guest<>p_guest OR NOT EXISTS(SELECT 1 FROM users WHERE id=p_guest AND status='active' AND deleted_at IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không tìm thấy giao dịch thanh toán.';
    END IF;
    IF v_removed IS NOT NULL AND v_end>DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỗ ở này đã bị gỡ nên không thể thanh toán online.'; END IF;
    SELECT status INTO v_payment FROM booking_payments WHERE booking_id=v_booking FOR UPDATE;
    SELECT provider,status,reference,amount INTO v_provider,v_status,v_reference,v_amount FROM payment_transactions WHERE id=p_transaction FOR UPDATE;
    IF v_provider<>'vietqr' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỉ giao dịch chuyển khoản mới cần báo đã chuyển.'; END IF;
    IF v_status='submitted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bạn đã báo chuyển khoản rồi; vui lòng chờ Admin xác nhận.'; END IF;
    IF v_status<>'pending' OR COALESCE(v_payment,'unpaid')<>'unpaid' OR v_booking_status<>'confirmed' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Giao dịch này đã đóng; vui lòng tải lại trang thanh toán.';
    END IF;
    UPDATE payment_transactions SET status='submitted',submitted_at=NOW(6),updated_at=NOW(6) WHERE id=p_transaction;
    -- The Guest chose this transfer: an abandoned VNPay tab must not stay open as a second way to pay.
    UPDATE payment_transactions SET status='cancelled',decided_at=NOW(6),note='Khách đã chọn chuyển khoản nên giao dịch VNPay này đã đóng.',updated_at=NOW(6)
        WHERE booking_id=v_booking AND provider='vnpay' AND status='pending';
    INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
        SELECT r.user_id,v_booking,'in_app','payment_transfer_submitted','Khách báo đã chuyển khoản',
            CONCAT('Booking #',v_booking,': khách báo đã chuyển ',REPLACE(FORMAT(v_amount,0),',','.'),'đ với nội dung ',v_reference,'. Vui lòng kiểm tra tài khoản và xác nhận.'),
            'sent',1,NOW(6)
        FROM user_roles r JOIN users u ON u.id=r.user_id WHERE r.role='admin' AND u.status='active' AND u.deleted_at IS NULL;
    SELECT 1 __affected,p_transaction __insert_id;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_payment_submit; END IF;
END$$
DELIMITER ;

-- 010_models.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE `LTP_sp_user_grant_host`(IN p_1 bigint unsigned)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE v_affected INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_user_grant_host; RELEASE SAVEPOINT h2h_user_grant_host; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_user_grant_host; END IF;
    -- The users row lock serializes this with DST_sp_user_roles_replace.
    SELECT id INTO v_lock FROM users WHERE id=p_1 AND status='active' AND deleted_at IS NULL FOR UPDATE;
    IF v_lock IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tài khoản không khả dụng.'; END IF;
    -- Same rule as DST_sp_user_host_role_removed: a removal by an Admin holds until an Admin gives the role back.
    IF COALESCE((SELECT a.action FROM admin_audit_logs a WHERE a.entity_type='user' AND a.entity_id=p_1 AND a.action IN ('remove_host_role','grant_host_role') ORDER BY a.id DESC LIMIT 1),'')='remove_host_role' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Admin đã gỡ vai trò Host của bạn. Hãy liên hệ Admin nếu bạn muốn làm Host trở lại.';
    END IF;
    INSERT IGNORE INTO user_roles (user_id, role, granted_at) VALUES (p_1, 'host', NOW(6));
    SET v_affected=ROW_COUNT();
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_user_grant_host; END IF;
    SELECT v_affected __affected,LAST_INSERT_ID() __insert_id;
END$$
DELIMITER ;

-- 011_queries.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE LTP_sp_user_search(IN p_query VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    -- User input is matched literally: LIKE wildcards are escaped.
    -- đ has its own weight in every collation here, unlike the other Vietnamese accents, so it is folded on both sides as in location search (NTK_sp_listing_search). REPLACE is case-sensitive, hence both cases.
    DECLARE v_query VARCHAR(600) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(p_query,'\\','\\\\'),'%','\\%'),'_','\\_'),'đ','d'),'Đ','D');
    SELECT u.id,u.email,u.full_name,u.phone,u.status,u.created_at,GROUP_CONCAT(ur.role ORDER BY ur.role) roles
    FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id
    WHERE p_query='' OR u.email LIKE CONCAT('%',v_query,'%') OR REPLACE(REPLACE(u.full_name,'đ','d'),'Đ','D') LIKE CONCAT('%',v_query,'%')
    GROUP BY u.id ORDER BY u.deleted_at IS NOT NULL,u.created_at DESC,u.id DESC;
END$$
DELIMITER ;

-- 090_content.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE NMT_sp_admin_articles(IN p_actor BIGINT UNSIGNED, IN p_query VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_category VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_status VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_limit INT, IN p_offset INT)
BEGIN
    -- User input is matched literally: LIKE wildcards are escaped.
    -- đ has its own weight in every collation here, unlike the other Vietnamese accents, so it is folded on both sides as in location search (NTK_sp_listing_search). REPLACE is case-sensitive, hence both cases.
    DECLARE v_query VARCHAR(400) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(p_query),'\\','\\\\'),'%','\\%'),'_','\\_'),'đ','d'),'Đ','D');
    CALL NTK_sp_admin_assert_role(p_actor);
    IF p_query IS NULL OR p_category IS NULL OR p_status IS NULL OR p_category NOT IN ('','news','guide') OR p_status NOT IN ('','draft','published') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bộ lọc bài viết không hợp lệ.';
    END IF;
    IF p_limit IS NULL OR p_offset IS NULL OR p_limit<1 OR p_limit>100 OR p_offset<0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Phân trang không hợp lệ.'; END IF;
    SELECT SQL_CALC_FOUND_ROWS a.id,a.category,a.title,a.slug,a.status,a.published_at,a.created_at,a.updated_at,a.cover_image_url,u.full_name author_name
    FROM articles a JOIN users u ON u.id=a.author_id
    WHERE a.deleted_at IS NULL AND (p_category='' OR a.category=p_category) AND (p_status='' OR a.status=p_status)
        AND (v_query='' OR REPLACE(REPLACE(a.title,'đ','d'),'Đ','D') LIKE CONCAT('%',v_query,'%') OR a.slug LIKE CONCAT('%',v_query,'%'))
    ORDER BY a.updated_at DESC,a.id DESC
    LIMIT p_limit OFFSET p_offset;
    SELECT FOUND_ROWS() __total;
END$$
DELIMITER ;

-- 030_atomic_writes.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE NMT_sp_admin_listing_update(IN p_1 varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_2 text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_3 varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_4 text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_5 decimal(12,2), IN p_6 decimal(12,2), IN p_7 int, IN p_8 bigint unsigned, IN p_9 bigint unsigned)
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
    CALL NTK_sp_admin_assert_role(p_9);
    SELECT id INTO v_lock FROM listings WHERE id=p_8 AND deleted_at IS NULL FOR UPDATE;
    IF v_lock IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỗ ở không tồn tại.'; END IF;
    IF p_1 IS NULL OR TRIM(p_1)='' OR p_2 IS NULL OR TRIM(p_2)='' OR p_3 IS NULL OR TRIM(p_3)='' OR p_4 IS NULL OR TRIM(p_4)=''
        OR p_5 IS NULL OR ROUND(p_5,0)<1 OR p_6 IS NULL OR p_6<0 OR p_7 IS NULL OR p_7<1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Thông tin chỗ ở không hợp lệ.'; END IF;
    UPDATE listings SET title=p_1,address=p_2,city=p_3,description=p_4,base_nightly_rate=p_5,fee_amount=p_6,max_guests=p_7,updated_at=NOW(6) WHERE id=p_8;
    INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at) VALUES(p_9,'update_listing','listing',p_8,JSON_OBJECT('details','Admin updated listing'),NOW(6));
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_admin_listing_update; END IF;
END$$
DELIMITER ;

-- 010_models.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE `NMT_sp_admin_listings`()
BEGIN
    SELECT l.id,l.host_id,l.property_type_id,l.cancellation_policy_id,l.title,l.description,l.address,l.city,l.country_code,l.latitude,l.longitude,l.time_zone,l.base_nightly_rate,l.fee_amount,l.currency,l.max_guests,l.room_count,l.bedroom_count,l.bed_count,l.check_in_time,l.check_out_time,l.smoking_allowed,l.pets_allowed,l.parties_allowed,l.house_rules,l.moderation_status,l.moderation_reason,l.reviewed_by,l.reviewed_at,l.is_visible,l.created_at,l.updated_at,l.deleted_at,l.moderation_reason moderation_note,u.full_name host_name,pt.name property_type,
        CASE WHEN l.is_visible<>0 THEN NULL ELSE COALESCE((SELECT IF(a.action<>'hide_listing','host',IF(JSON_VALUE(a.change_summary,'$.host_hidden')=1,'both','admin')) FROM admin_audit_logs a WHERE a.entity_type='listing' AND a.entity_id=l.id AND a.action IN ('hide_listing','restore_listing') ORDER BY a.id DESC LIMIT 1),'host') END hidden_by
        FROM listings l JOIN users u ON u.id=l.host_id JOIN property_types pt ON pt.id=l.property_type_id WHERE l.deleted_at IS NULL ORDER BY l.created_at DESC;
END$$
DELIMITER ;

-- 080_conversation.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE NMT_sp_admin_message_thread(IN p_actor BIGINT UNSIGNED, IN p_booking BIGINT UNSIGNED)
BEGIN
    CALL NTK_sp_admin_assert_role(p_actor);
    -- Admin reading never changes read receipts.
    SELECT t.* FROM (
        SELECT m.id,m.booking_id,l.title,b.status booking_status,g.full_name guest_name,h.full_name host_name,
            g.deleted_at IS NOT NULL guest_deleted,h.deleted_at IS NOT NULL host_deleted,
            m.sender_id,s.full_name sender_name,IF(m.sender_id=b.guest_id,'guest','host') sender_role,
            m.body,m.created_at,m.read_at,m.hidden_at,m.hidden_reason,a.full_name hidden_by_name
        FROM booking_messages m JOIN bookings b ON b.id=m.booking_id JOIN listings l ON l.id=b.listing_id
            JOIN users g ON g.id=b.guest_id JOIN users h ON h.id=l.host_id JOIN users s ON s.id=m.sender_id
            LEFT JOIN users a ON a.id=m.hidden_by
        WHERE m.booking_id=p_booking ORDER BY m.created_at DESC,m.id DESC LIMIT 500
    ) t ORDER BY t.created_at,t.id;
END$$
DELIMITER ;

-- 095_online_payment.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE NMT_sp_admin_payment_transfer_decide(IN p_transaction BIGINT UNSIGNED, IN p_admin BIGINT UNSIGNED,
    IN p_decision VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_note VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_booking BIGINT UNSIGNED;
    DECLARE v_listing BIGINT UNSIGNED;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE v_guest BIGINT UNSIGNED;
    DECLARE v_total DECIMAL(12,2);
    DECLARE v_payment VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_provider VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_status VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_reference VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_amount DECIMAL(12,2);
    DECLARE v_note VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_host BIGINT UNSIGNED;
    DECLARE v_removed DATETIME(6);
    DECLARE v_suspended BOOL DEFAULT FALSE;
    DECLARE v_booking_status VARCHAR(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_end DATE;
    DECLARE v_duplicate BOOL DEFAULT FALSE;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_transfer_decide; RELEASE SAVEPOINT h2h_transfer_decide; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_transfer_decide; END IF;
    CALL NTK_sp_admin_assert_role(p_admin);
    IF p_decision IS NULL OR p_decision NOT IN ('confirm','reject') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Quyết định chuyển khoản không hợp lệ.'; END IF;
    SET v_note=NULLIF(TRIM(COALESCE(p_note,'')),'');
    SELECT booking_id INTO v_booking FROM payment_transactions WHERE id=p_transaction;
    SELECT listing_id INTO v_listing FROM bookings WHERE id=v_booking;
    SELECT id,deleted_at,host_id INTO v_lock,v_removed,v_host FROM listings WHERE id=v_listing FOR UPDATE;
    -- A deleted Host counts as a removed listing: the stay will not happen. A suspension is reversible and the stay still exists: the money is a normal payment and the Admins are asked to check the stay.
    SELECT IF(status='deleted' OR deleted_at IS NOT NULL,COALESCE(v_removed,NOW(6)),v_removed),status='suspended' INTO v_removed,v_suspended FROM users WHERE id=v_host LOCK IN SHARE MODE;
    SELECT guest_id,total_amount,status,check_out INTO v_guest,v_total,v_booking_status,v_end FROM bookings WHERE id=v_booking FOR UPDATE;
    IF v_guest IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không tìm thấy giao dịch thanh toán.'; END IF;
    -- A finished stay really happened: money for it is a normal payment even if the listing was taken down afterwards.
    IF v_end<=DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR) THEN SET v_removed=NULL; SET v_suspended=FALSE; END IF;
    SELECT status INTO v_payment FROM booking_payments WHERE booking_id=v_booking FOR UPDATE;
    SELECT provider,status,reference,ROUND(amount,0) INTO v_provider,v_status,v_reference,v_amount FROM payment_transactions WHERE id=p_transaction FOR UPDATE;
    IF v_provider<>'vietqr' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỉ xác nhận giao dịch chuyển khoản VietQR.'; END IF;
    IF v_status NOT IN ('pending','submitted') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Giao dịch chuyển khoản này đã được xử lý.'; END IF;
    SET v_duplicate=(p_decision='reject' AND COALESCE(v_payment,'unpaid')<>'unpaid');
    IF p_decision='reject' AND (v_note IS NULL OR CHAR_LENGTH(v_note)<5) THEN
        IF v_duplicate THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Vui lòng ghi chú lý do đóng giao dịch trùng (ít nhất 5 ký tự).'; END IF;
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Vui lòng nhập lý do chưa nhận được tiền (ít nhất 5 ký tự).';
    END IF;
    IF p_decision='confirm' THEN
        IF COALESCE(v_payment,'unpaid')<>'unpaid' THEN
            IF v_payment='refunded' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Booking này đã được hoàn tiền; hãy đóng giao dịch trùng và hoàn tiền cho khách.'; END IF;
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Booking này đã được ghi nhận thanh toán; hãy đóng giao dịch trùng và hoàn tiền cho khách.';
        END IF;
        UPDATE payment_transactions SET status='succeeded',decided_by=p_admin,decided_at=NOW(6),note=v_note,updated_at=NOW(6) WHERE id=p_transaction;
        UPDATE payment_transactions SET status='cancelled',decided_at=NOW(6),note='Booking đã được thanh toán bằng giao dịch khác.',updated_at=NOW(6)
            WHERE booking_id=v_booking AND id<>p_transaction AND status='pending';
        IF v_payment IS NULL THEN
            -- Bookings created before payment tracking get their record lazily, as in NMT_sp_booking_payment_mark_paid.
            INSERT INTO booking_payments(booking_id,method,amount_due,status,paid_at,paid_by,refunded_at,refunded_by,note,created_at,updated_at)
                VALUES(v_booking,'vietqr',v_amount,'paid',NOW(6),p_admin,NULL,NULL,CONCAT('VietQR ',v_reference),NOW(6),NOW(6));
        ELSE
            UPDATE booking_payments SET method='vietqr',amount_due=v_amount,status='paid',paid_at=NOW(6),paid_by=p_admin,
                note=CONCAT('VietQR ',v_reference),updated_at=NOW(6) WHERE booking_id=v_booking;
        END IF;
        INSERT INTO booking_payment_events(booking_id,actor_id,from_status,to_status,note,created_at)
            VALUES(v_booking,p_admin,'unpaid','paid',CONCAT('VietQR ',v_reference),NOW(6));
        INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
            VALUES(v_guest,v_booking,'in_app','payment_paid',IF(v_booking_status NOT IN ('confirmed','completed') OR v_removed IS NOT NULL,'Khoản thanh toán sẽ được hoàn lại','Đã ghi nhận thanh toán'),
            CASE WHEN v_booking_status NOT IN ('confirmed','completed') THEN CONCAT('Booking #',v_booking,' đã hủy nhưng đã nhận chuyển khoản ',REPLACE(FORMAT(v_amount,0),',','.'),'đ (mã ',v_reference,'). Admin sẽ hoàn lại khoản này cho bạn.')
                WHEN v_removed IS NOT NULL THEN CONCAT('Chỗ ở của booking #',v_booking,' đã bị gỡ nhưng đã nhận chuyển khoản ',REPLACE(FORMAT(v_amount,0),',','.'),'đ (mã ',v_reference,'). Admin sẽ hoàn lại khoản này cho bạn.')
                ELSE CONCAT('Booking #',v_booking,' đã được xác nhận thanh toán chuyển khoản ',REPLACE(FORMAT(v_amount,0),',','.'),'đ (mã ',v_reference,').') END,'sent',1,NOW(6));
        IF v_booking_status NOT IN ('confirmed','completed') THEN
            -- The Guest cancelled with this transfer still open: the money is recorded as paid so the Admin can record the refund of all of it.
            UPDATE booking_cancellations SET refund_amount=v_amount WHERE booking_id=v_booking;
            INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
                SELECT r.user_id,v_booking,'in_app','payment_refund_needed','Cần hoàn tiền',
                    CONCAT('Booking #',v_booking,' đã hủy nhưng vẫn nhận ',REPLACE(FORMAT(v_amount,0),',','.'),'đ qua chuyển khoản (mã ',v_reference,'). Vui lòng hoàn tiền cho khách.'),
                    'sent',1,NOW(6)
                FROM user_roles r JOIN users u ON u.id=r.user_id WHERE r.role='admin' AND u.status='active' AND u.deleted_at IS NULL;
        ELSEIF v_removed IS NOT NULL THEN
            -- The money did reach the account, so it is recorded; the stay is gone, so the Admins are asked to return it.
            INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
                SELECT r.user_id,v_booking,'in_app','payment_refund_needed','Cần hoàn tiền',
                    CONCAT('Booking #',v_booking,' thuộc chỗ ở đã gỡ nhưng vẫn nhận ',REPLACE(FORMAT(v_amount,0),',','.'),'đ qua chuyển khoản (mã ',v_reference,'). Vui lòng hoàn tiền cho khách.'),
                    'sent',1,NOW(6)
                FROM user_roles r JOIN users u ON u.id=r.user_id WHERE r.role='admin' AND u.status='active' AND u.deleted_at IS NULL;
        ELSEIF v_suspended THEN
            INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
                SELECT r.user_id,v_booking,'in_app','payment_host_suspended','Cần kiểm tra chuyến ở',
                    CONCAT('Booking #',v_booking,' vừa nhận ',REPLACE(FORMAT(v_amount,0),',','.'),'đ qua chuyển khoản (mã ',v_reference,') trong khi tài khoản Host đang tạm khóa. Vui lòng kiểm tra chuyến ở này.'),
                    'sent',1,NOW(6)
                FROM user_roles r JOIN users u ON u.id=r.user_id WHERE r.role='admin' AND u.status='active' AND u.deleted_at IS NULL;
        END IF;
    ELSEIF v_duplicate THEN
        -- The booking was paid another way while this transfer was open. Closing it says nothing about the money not arriving: if it did arrive, it goes back to the Guest.
        -- The fixed text below takes 148 of the column's 500 characters: a longer note is refused, never cut.
        IF CHAR_LENGTH(v_note)>352 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ghi chú đóng giao dịch trùng tối đa 352 ký tự.'; END IF;
        UPDATE payment_transactions SET status='cancelled',decided_by=p_admin,decided_at=NOW(6),
            note=CONCAT('Đã đóng chuyển khoản trùng vì booking đã được thanh toán bằng giao dịch khác; nếu khoản này đã vào tài khoản, Admin sẽ hoàn lại cho khách. Ghi chú: ',v_note),updated_at=NOW(6) WHERE id=p_transaction;
        INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
            VALUES(v_guest,v_booking,'in_app','payment_duplicate_transfer_closed','Đã đóng chuyển khoản trùng',
            CONCAT('Booking #',v_booking,' đã được thanh toán bằng giao dịch khác nên chuyển khoản ',REPLACE(FORMAT(v_amount,0),',','.'),'đ (mã ',v_reference,') đã được đóng. Nếu khoản này đã vào tài khoản, Admin sẽ hoàn lại cho bạn. Ghi chú: ',v_note),'sent',1,NOW(6));
    ELSE
        UPDATE payment_transactions SET status='failed',decided_by=p_admin,decided_at=NOW(6),note=v_note,updated_at=NOW(6) WHERE id=p_transaction;
        INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
            VALUES(v_guest,v_booking,'in_app','payment_transfer_rejected','Chưa xác nhận được chuyển khoản',
            CONCAT('Chưa xác nhận được chuyển khoản cho booking #',v_booking,' (mã ',v_reference,'). Lý do: ',v_note),'sent',1,NOW(6));
    END IF;
    INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at)
        VALUES(p_admin,IF(p_decision='confirm','confirm_bank_transfer',IF(v_duplicate,'close_duplicate_transfer','reject_bank_transfer')),'booking',v_booking,
            JSON_OBJECT('transaction_id',p_transaction,'reference',v_reference,'amount',v_amount,'note',v_note),NOW(6));
    SELECT 1 __affected,p_transaction __insert_id;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_transfer_decide; END IF;
    SELECT v_duplicate duplicate_closed;
END$$
DELIMITER ;

-- 070_booking_extras.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE NMT_sp_booking_extras_price(IN p_listing BIGINT UNSIGNED, IN p_start DATE, IN p_end DATE,
    IN p_promo VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_strict BOOL,
    OUT o_nights INT, OUT o_base DECIMAL(12,2), OUT o_weekend_nights INT, OUT o_weekend_rate DECIMAL(12,2),
    OUT o_holiday_nights INT, OUT o_holiday_rate DECIMAL(12,2), OUT o_subtotal DECIMAL(12,2),
    OUT o_length_type VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, OUT o_length_pct DECIMAL(5,2), OUT o_length_amount DECIMAL(12,2),
    OUT o_promo_id BIGINT UNSIGNED, OUT o_promo_code VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, OUT o_promo_amount DECIMAL(12,2),
    OUT o_promo_message VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, OUT o_fee DECIMAL(12,2), OUT o_total DECIMAL(12,2), OUT o_instant BOOL)
BEGIN
    DECLARE v_day DATE;
    DECLARE v_rate DECIMAL(20,2);
    DECLARE v_kind VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_weekly DECIMAL(5,2);
    DECLARE v_monthly DECIMAL(5,2);
    DECLARE v_after DECIMAL(12,2);
    -- Wider than the money columns: outside strict mode a DECIMAL(12,2) would silently cap at 9,999,999,999.99.
    DECLARE v_nights_sum DECIMAL(20,2) DEFAULT 0;
    DECLARE v_base DECIMAL(20,2);
    DECLARE v_weekend DECIMAL(20,2);
    DECLARE v_holiday DECIMAL(20,2);
    DECLARE v_fee DECIMAL(20,2);
    DECLARE v_total DECIMAL(20,2);
    DECLARE v_code VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_pid BIGINT UNSIGNED;
    DECLARE v_type VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_value DECIMAL(12,2);
    DECLARE v_max DECIMAL(12,2);
    DECLARE v_min_nights INT;
    DECLARE v_min_subtotal DECIMAL(12,2);
    DECLARE v_from DATE;
    DECLARE v_to DATE;
    DECLARE v_limit INT;
    DECLARE v_active BOOL;
    DECLARE v_used INT DEFAULT 0;
    DECLARE v_today DATE DEFAULT DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR);
    DECLARE v_message VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    SET o_weekend_rate=NULL; SET o_holiday_rate=NULL; SET v_weekly=NULL; SET v_monthly=NULL; SET o_instant=FALSE;
    IF p_strict THEN
        SELECT base_nightly_rate,fee_amount INTO o_base,o_fee FROM listings WHERE id=p_listing FOR UPDATE;
        SELECT weekend_nightly_rate,holiday_nightly_rate,weekly_discount_pct,monthly_discount_pct,instant_book=1
            INTO o_weekend_rate,o_holiday_rate,v_weekly,v_monthly,o_instant FROM listing_booking_settings WHERE listing_id=p_listing LOCK IN SHARE MODE;
    ELSE
        SELECT base_nightly_rate,fee_amount INTO o_base,o_fee FROM listings WHERE id=p_listing;
        SELECT weekend_nightly_rate,holiday_nightly_rate,weekly_discount_pct,monthly_discount_pct,instant_book=1
            INTO o_weekend_rate,o_holiday_rate,v_weekly,v_monthly,o_instant FROM listing_booking_settings WHERE listing_id=p_listing;
    END IF;
    IF o_base IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỗ ở không khả dụng.'; END IF;
    SET o_instant=COALESCE(o_instant,FALSE);
    -- Hosts and Admins may type decimals: VND has no fractions, so every price component is whole dong before anything is added up.
    -- 9,999,999,999.50 rounds up to 10,000,000,000, which only the wide locals hold: a rate this stay uses and the fee meet the guards below; a rate it does not use refuses nothing.
    SET v_base=ROUND(o_base,0); SET v_fee=ROUND(o_fee,0); SET v_weekend=ROUND(o_weekend_rate,0); SET v_holiday=ROUND(o_holiday_rate,0);
    SET o_base=LEAST(v_base,9999999999.99); SET o_weekend_rate=LEAST(v_weekend,9999999999.99); SET o_holiday_rate=LEAST(v_holiday,9999999999.99);
    SET o_nights=DATEDIFF(p_end,p_start); SET o_weekend_nights=0; SET o_holiday_nights=0; SET v_day=p_start;
    WHILE v_day<p_end DO
        CALL LTP_sp_booking_extras_night_rate(v_day,v_base,v_weekend,v_holiday,v_rate,v_kind);
        SET v_nights_sum=v_nights_sum+v_rate;
        IF v_kind='weekend' THEN SET o_weekend_nights=o_weekend_nights+1; ELSEIF v_kind='holiday' THEN SET o_holiday_nights=o_holiday_nights+1; END IF;
        SET v_day=DATE_ADD(v_day,INTERVAL 1 DAY);
    END WHILE;
    IF v_nights_sum>9999999999.99 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tổng tiền của kỳ ở vượt quá giới hạn của hệ thống. Vui lòng chọn ít đêm hơn.';
    END IF;
    SET o_subtotal=v_nights_sum;
    -- Monthly replaces weekly; the two are never stacked.
    IF o_nights>=28 AND COALESCE(v_monthly,0)>0 THEN SET o_length_type='monthly'; SET o_length_pct=v_monthly;
    ELSEIF o_nights>=7 AND COALESCE(v_weekly,0)>0 THEN SET o_length_type='weekly'; SET o_length_pct=v_weekly;
    ELSE SET o_length_type='none'; SET o_length_pct=0;
    END IF;
    SET o_length_amount=ROUND(o_subtotal*o_length_pct/100,0);
    SET v_after=o_subtotal-o_length_amount;
    SET o_promo_id=NULL; SET o_promo_code=NULL; SET o_promo_amount=0; SET o_promo_message=NULL;
    SET v_code=UPPER(TRIM(COALESCE(p_promo,'')));
    IF v_code<>'' THEN
        IF NOT (CAST(v_code AS BINARY) REGEXP '^[A-Z0-9]{4,20}$') THEN
            SET o_promo_message='Mã khuyến mãi không hợp lệ (4–20 chữ cái hoặc số).';
        ELSE
            IF p_strict THEN
                SELECT id,discount_type,discount_value,max_discount_amount,min_nights,min_subtotal,valid_from,valid_to,usage_limit,is_active=1
                    INTO v_pid,v_type,v_value,v_max,v_min_nights,v_min_subtotal,v_from,v_to,v_limit,v_active FROM promotions WHERE code=v_code FOR UPDATE;
                IF v_pid IS NOT NULL THEN
                    -- Locking read: a caller's stale REPEATABLE READ snapshot must not hide recent uses.
                    -- A pending request whose check-in has passed can never be confirmed (as in NTK_sp_booking_validate), so it stops using up the limit.
                    SELECT COUNT(*) INTO v_used FROM booking_price_breakdowns bp JOIN bookings b ON b.id=bp.booking_id
                        WHERE bp.promotion_id=v_pid AND (b.status IN ('confirmed','completed') OR (b.status='pending' AND b.check_in>=v_today)) LOCK IN SHARE MODE;
                END IF;
            ELSE
                SELECT id,discount_type,discount_value,max_discount_amount,min_nights,min_subtotal,valid_from,valid_to,usage_limit,is_active=1
                    INTO v_pid,v_type,v_value,v_max,v_min_nights,v_min_subtotal,v_from,v_to,v_limit,v_active FROM promotions WHERE code=v_code;
                IF v_pid IS NOT NULL THEN
                    SELECT COUNT(*) INTO v_used FROM booking_price_breakdowns bp JOIN bookings b ON b.id=bp.booking_id
                        WHERE bp.promotion_id=v_pid AND (b.status IN ('confirmed','completed') OR (b.status='pending' AND b.check_in>=v_today));
                END IF;
            END IF;
            IF v_pid IS NULL THEN SET o_promo_message='Mã khuyến mãi không tồn tại.';
            ELSEIF NOT v_active THEN SET o_promo_message='Mã khuyến mãi đã ngừng áp dụng.';
            ELSEIF v_today<v_from THEN SET o_promo_message=CONCAT('Mã khuyến mãi chưa đến ngày áp dụng (từ ',DATE_FORMAT(v_from,'%d/%m/%Y'),').');
            ELSEIF v_today>v_to THEN SET o_promo_message='Mã khuyến mãi đã hết hạn.';
            ELSEIF v_limit IS NOT NULL AND v_used>=v_limit THEN SET o_promo_message='Mã khuyến mãi đã hết lượt sử dụng.';
            ELSEIF v_min_nights IS NOT NULL AND o_nights<v_min_nights THEN
                SET o_promo_message=CONCAT('Không đủ điều kiện: mã chỉ áp dụng cho kỳ ở từ ',v_min_nights,' đêm.');
            ELSEIF v_min_subtotal IS NOT NULL AND v_after<v_min_subtotal THEN
                SET o_promo_message=CONCAT('Không đủ điều kiện: tiền phòng tối thiểu ',REPLACE(FORMAT(v_min_subtotal,0),',','.'),'đ.');
            ELSE
                SET o_promo_id=v_pid; SET o_promo_code=v_code;
                IF v_type='percent' THEN
                    SET o_promo_amount=ROUND(v_after*v_value/100,0);
                    IF v_max IS NOT NULL THEN SET o_promo_amount=LEAST(o_promo_amount,v_max); END IF;
                ELSE
                    SET o_promo_amount=v_value;
                END IF;
                -- Discount applies to the nights subtotal after the stay-length discount and never goes below zero.
                SET o_promo_amount=ROUND(LEAST(o_promo_amount,v_after),0);
                -- A discount that rounds to nothing (a promotion saved before the limit, or 1% of a few dong) must not use up the code.
                IF o_promo_amount<=0 THEN
                    SET o_promo_id=NULL; SET o_promo_code=NULL; SET o_promo_amount=0;
                    SET o_promo_message='Không đủ điều kiện: mã này không giảm được gì cho kỳ ở này.';
                END IF;
            END IF;
        END IF;
        IF p_strict AND o_promo_message IS NOT NULL THEN
            SET v_message=o_promo_message;
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT=v_message;
        END IF;
    END IF;
    SET v_total=ROUND(v_after-o_promo_amount+v_fee,0);
    IF v_total>9999999999.99 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tổng tiền của kỳ ở vượt quá giới hạn của hệ thống. Vui lòng chọn ít đêm hơn.';
    END IF;
    SET o_fee=v_fee;
    SET o_total=v_total;
END$$
DELIMITER ;

-- 070_booking_extras.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE NMT_sp_booking_payment_mark_paid(IN p_booking BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED, IN p_admin BOOL,
    IN p_note VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_listing BIGINT UNSIGNED;
    DECLARE v_host BIGINT UNSIGNED;
    DECLARE v_guest BIGINT UNSIGNED;
    DECLARE v_status VARCHAR(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_start DATE;
    DECLARE v_total DECIMAL(12,2);
    DECLARE v_payment VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_due DECIMAL(12,2);
    DECLARE v_note VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_payment_paid; RELEASE SAVEPOINT h2h_payment_paid; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_payment_paid; END IF;
    SELECT listing_id INTO v_listing FROM bookings WHERE id=p_booking;
    SELECT host_id INTO v_host FROM listings WHERE id=v_listing FOR UPDATE;
    SELECT guest_id,status,check_in,total_amount INTO v_guest,v_status,v_start,v_total FROM bookings WHERE id=p_booking FOR UPDATE;
    IF v_guest IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Booking không tồn tại.'; END IF;
    IF p_admin IS NULL OR p_admin NOT IN (0,1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Quyền cập nhật không hợp lệ.'; END IF;
    IF NOT EXISTS(SELECT 1 FROM users WHERE id=p_actor AND status='active' AND deleted_at IS NULL) OR
        (p_admin=1 AND NOT EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_actor AND role='admin')) OR
        (p_admin=0 AND (v_host<>p_actor OR NOT EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_actor AND role='host'))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bạn không có quyền cập nhật thanh toán của booking này.';
    END IF;
    SET v_note=NULLIF(TRIM(COALESCE(p_note,'')),'');
    SELECT status,amount_due INTO v_payment,v_due FROM booking_payments WHERE booking_id=p_booking FOR UPDATE;
    IF v_payment='refunded' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Booking này đã được hoàn tiền.'; END IF;
    IF COALESCE(v_payment,'unpaid')<>'unpaid' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Booking này đã được ghi nhận thanh toán trước đó.'; END IF;
    IF v_status NOT IN ('confirmed','completed') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỉ ghi nhận thanh toán cho booking đã xác nhận hoặc đã hoàn thành.';
    END IF;
    IF v_start>DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỉ ghi nhận đã thu tiền từ ngày nhận phòng trở đi.'; END IF;
    IF v_payment IS NULL THEN
        -- Bookings created before payment tracking get their record lazily.
        SET v_due=v_total;
        INSERT INTO booking_payments(booking_id,method,amount_due,status,paid_at,paid_by,refunded_at,refunded_by,note,created_at,updated_at)
            VALUES(p_booking,'pay_at_checkin',v_due,'paid',NOW(6),p_actor,NULL,NULL,v_note,NOW(6),NOW(6));
    ELSE
        UPDATE booking_payments SET status='paid',paid_at=NOW(6),paid_by=p_actor,note=v_note,updated_at=NOW(6) WHERE booking_id=p_booking;
    END IF;
    INSERT INTO booking_payment_events(booking_id,actor_id,from_status,to_status,note,created_at) VALUES(p_booking,p_actor,'unpaid','paid',v_note,NOW(6));
    INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
        VALUES(v_guest,p_booking,'in_app','payment_paid','Đã ghi nhận thanh toán',
        CONCAT('Booking #',p_booking,' đã được ghi nhận thanh toán ',REPLACE(FORMAT(v_due,0),',','.'),'đ khi nhận phòng.'),'sent',1,NOW(6));
    IF p_admin THEN
        INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at)
            VALUES(p_actor,'mark_payment_paid','booking',p_booking,JSON_OBJECT('amount',v_due,'note',v_note),NOW(6));
    END IF;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_payment_paid; END IF;
END$$
DELIMITER ;

-- 020_booking.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE NMT_sp_booking_transition(IN p_booking BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED, IN p_target VARCHAR(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_admin BOOL)
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
    DECLARE v_deleted DATETIME(6);
    DECLARE v_moderation VARCHAR(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_account_ok BOOL;
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
    SELECT host_id,deleted_at,moderation_status INTO v_host,v_deleted,v_moderation FROM listings WHERE id=v_listing FOR UPDATE;
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
        -- A past stay on a removed listing can still be completed; a request can no longer be accepted.
        IF v_deleted IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỗ ở đã bị gỡ nên không thể xác nhận booking này.'; END IF;
        -- Only an Admin rejection closes a listing to requests; a Host edit back to review ('pending') or a hidden listing still honours them.
        IF v_moderation='rejected' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỗ ở đã bị từ chối kiểm duyệt nên không thể xác nhận yêu cầu này. Bạn vẫn có thể từ chối.'; END IF;
        -- Locking reads: a suspension committed while this routine waited on the locks above must be seen.
        SELECT status='active' AND deleted_at IS NULL INTO v_account_ok FROM users WHERE id=v_guest LOCK IN SHARE MODE;
        IF NOT v_account_ok THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tài khoản khách đang bị khóa hoặc đã xóa nên không thể xác nhận yêu cầu này. Bạn vẫn có thể từ chối.'; END IF;
        SELECT status='active' AND deleted_at IS NULL INTO v_account_ok FROM users WHERE id=v_host LOCK IN SHARE MODE;
        IF NOT v_account_ok THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tài khoản Host đang bị khóa hoặc đã xóa nên không thể xác nhận yêu cầu này. Bạn vẫn có thể từ chối.'; END IF;
        IF v_start<DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Đã qua ngày nhận phòng nên không thể xác nhận yêu cầu này. Bạn vẫn có thể từ chối.'; END IF;
        SELECT COUNT(*) INTO v_conflicts FROM bookings WHERE listing_id=v_listing AND id<>p_booking AND status='confirmed' AND check_in<v_end AND check_out>v_start FOR UPDATE;
        IF v_conflicts>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ngày này vừa được booking khác xác nhận.'; END IF;
        SELECT COUNT(*) INTO v_conflicts FROM listing_availability WHERE listing_id=v_listing AND is_open=0 AND stay_date>=v_start AND stay_date<v_end FOR UPDATE;
        -- Only the Host has the calendar, so each actor is told what they can do.
        IF v_conflicts>0 AND p_admin THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Khoảng ngày có ngày Host đang chặn. Hãy nhờ Host mở lại ngày đó trong Lịch khả dụng, hoặc từ chối yêu cầu này.'; END IF;
        IF v_conflicts>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Khoảng ngày có ngày đang bị chặn. Hãy mở lại ngày đó trong Lịch khả dụng rồi xác nhận, hoặc từ chối yêu cầu này.'; END IF;
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
    INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
        VALUES(v_host,p_booking,'in_app',CONCAT('booking_',p_target),'Cập nhật booking',CONCAT('Booking #',p_booking,' chuyển sang trạng thái ',CASE p_target WHEN 'confirmed' THEN 'Đã xác nhận' WHEN 'rejected' THEN 'Bị từ chối' ELSE 'Hoàn thành' END,'.'),'sent',1,NOW(6));
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_booking_transition; END IF;
END$$
DELIMITER ;

-- 085_discovery.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE NMT_sp_discovery_map_search(
    IN p_north DECIMAL(10,6), IN p_south DECIMAL(10,6), IN p_east DECIMAL(10,6), IN p_west DECIMAL(10,6),
    IN p_location VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_guests INT, IN p_type BIGINT UNSIGNED,
    IN p_min DECIMAL(12,2), IN p_max DECIMAL(12,2), IN p_start DATE, IN p_end DATE, IN p_limit INT)
BEGIN
    -- đ has its own weight in every collation of MariaDB 10.4, unlike the other Vietnamese accents, so "Da Lat" only finds "Đà Lạt" with it folded on both sides. REPLACE is case-sensitive, hence both cases.
    DECLARE v_location VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT REPLACE(REPLACE(TRIM(COALESCE(p_location,'')),'đ','d'),'Đ','D');
    DECLARE v_box BOOL DEFAULT p_north IS NOT NULL;
    IF (p_north IS NULL)<>(p_south IS NULL) OR (p_north IS NULL)<>(p_east IS NULL) OR (p_north IS NULL)<>(p_west IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Khung bản đồ cần đủ bốn cạnh bắc, nam, đông, tây.';
    END IF;
    IF v_box AND (p_north>90 OR p_south<-90 OR p_south>=p_north OR p_east<-180 OR p_east>180 OR p_west<-180 OR p_west>180 OR p_east=p_west) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Khung bản đồ không hợp lệ: vĩ độ từ -90 đến 90, kinh độ từ -180 đến 180 và cạnh nam phải thấp hơn cạnh bắc.';
    END IF;
    IF p_limit IS NULL OR p_limit<1 OR p_limit>100 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Số kết quả trên bản đồ phải từ 1 đến 100.';
    END IF;
    IF COALESCE(p_guests,0)<0 OR COALESCE(p_min,0)<0 OR COALESCE(p_max,0)<0
        OR (COALESCE(p_min,0)>0 AND COALESCE(p_max,0)>0 AND p_min>p_max) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Số khách hoặc khoảng giá không hợp lệ.';
    END IF;
    IF (p_start IS NULL)<>(p_end IS NULL) OR p_end<=p_start OR p_start<CURDATE() THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Vui lòng chọn đủ ngày và bảo đảm ngày trả phòng sau ngày nhận phòng.';
    END IF;
    SELECT SQL_CALC_FOUND_ROWS l.id,l.title,l.city,pt.name property_type,l.max_guests,l.base_nightly_rate nightly_price,l.currency,l.latitude,l.longitude,
        (SELECT image_url FROM listing_photos WHERE listing_id=l.id ORDER BY sort_order,id LIMIT 1) image_url,
        COALESCE((SELECT AVG(r.rating) FROM reviews r JOIN bookings b ON b.id=r.booking_id WHERE b.listing_id=l.id AND r.moderation_status='visible'),0) rating,
        (SELECT COUNT(*) FROM reviews r JOIN bookings b ON b.id=r.booking_id WHERE b.listing_id=l.id AND r.moderation_status='visible') review_count
    FROM listings l JOIN property_types pt ON pt.id=l.property_type_id JOIN users u ON u.id=l.host_id
    WHERE l.moderation_status='approved' AND l.is_visible=1 AND l.deleted_at IS NULL AND u.status='active' AND u.deleted_at IS NULL
        AND l.latitude IS NOT NULL AND l.longitude IS NOT NULL
        AND (NOT v_box OR (l.latitude BETWEEN p_south AND p_north
            AND ((p_west<p_east AND l.longitude BETWEEN p_west AND p_east) OR (p_west>p_east AND (l.longitude>=p_west OR l.longitude<=p_east)))))
        AND (v_location='' OR REPLACE(REPLACE(l.city,'đ','d'),'Đ','D') LIKE CONCAT('%',v_location,'%') OR REPLACE(REPLACE(l.address,'đ','d'),'Đ','D') LIKE CONCAT('%',v_location,'%') OR REPLACE(REPLACE(l.title,'đ','d'),'Đ','D') LIKE CONCAT('%',v_location,'%'))
        AND (COALESCE(p_guests,0)=0 OR l.max_guests>=p_guests) AND (COALESCE(p_type,0)=0 OR l.property_type_id=p_type)
        AND (COALESCE(p_min,0)=0 OR l.base_nightly_rate>=p_min) AND (COALESCE(p_max,0)=0 OR l.base_nightly_rate<=p_max)
        AND (p_start IS NULL OR (
            NOT EXISTS(SELECT 1 FROM listing_availability a WHERE a.listing_id=l.id AND a.is_open=0 AND a.stay_date>=p_start AND a.stay_date<p_end)
            AND NOT EXISTS(SELECT 1 FROM bookings b WHERE b.listing_id=l.id AND b.status IN ('pending','confirmed') AND (b.status='confirmed' OR b.check_in>=DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR)) AND b.check_in<p_end AND b.check_out>p_start)))
    ORDER BY rating DESC,review_count DESC,l.id DESC
    LIMIT p_limit;
    SELECT FOUND_ROWS() __total;
END$$
DELIMITER ;

-- 050_quality_core.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE NMT_sp_host_monthly_revenue(IN p_actor BIGINT UNSIGNED)
BEGIN
    IF NOT EXISTS(SELECT 1 FROM users u JOIN user_roles r ON r.user_id=u.id WHERE u.id=p_actor AND u.status='active' AND r.role='host') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không có quyền Host.'; END IF;
    -- Month of completed stay's check-out, not request/confirmation date. Refunded money is not revenue (see NTK_sp_booking_payment_refund).
    SELECT DATE_FORMAT(b.check_out,'%Y-%m') revenue_month,COUNT(*) completed_count,
        SUM(GREATEST(0,b.total_amount-CASE WHEN pay.status='refunded' THEN COALESCE((SELECT CAST(JSON_VALUE(a.change_summary,'$.amount') AS DECIMAL(12,2)) FROM admin_audit_logs a WHERE a.action='refund_payment' AND a.entity_type='booking' AND a.entity_id=b.id ORDER BY a.id DESC LIMIT 1),pay.amount_due) ELSE 0 END)) revenue
    FROM bookings b JOIN listings l ON l.id=b.listing_id LEFT JOIN booking_payments pay ON pay.booking_id=b.id WHERE l.host_id=p_actor AND b.status='completed'
    GROUP BY DATE_FORMAT(b.check_out,'%Y-%m') ORDER BY revenue_month DESC;
END$$
DELIMITER ;

-- 010_models.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE `NMT_sp_host_stats`(IN p_1 bigint unsigned)
BEGIN
    SELECT COUNT(DISTINCT CASE WHEN l.deleted_at IS NULL THEN l.id END) listings,COUNT(DISTINCT b.id) bookings,COALESCE(SUM(b.status='completed'),0) completed,COALESCE(SUM(CASE WHEN b.status='completed' THEN GREATEST(0,b.total_amount-CASE WHEN pay.status='refunded' THEN COALESCE((SELECT CAST(JSON_VALUE(a.change_summary,'$.amount') AS DECIMAL(12,2)) FROM admin_audit_logs a WHERE a.action='refund_payment' AND a.entity_type='booking' AND a.entity_id=b.id ORDER BY a.id DESC LIMIT 1),pay.amount_due) ELSE 0 END) ELSE 0 END),0) revenue FROM listings l LEFT JOIN bookings b ON b.listing_id=l.id LEFT JOIN booking_payments pay ON pay.booking_id=b.id WHERE l.host_id=p_1;
END$$
DELIMITER ;

-- 010_models.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE `NMT_sp_listing_get_by_host`(IN p_1 bigint unsigned)
BEGIN
    SELECT l.id,l.host_id,l.property_type_id,l.cancellation_policy_id,l.title,l.description,l.address,l.city,l.country_code,l.latitude,l.longitude,l.time_zone,l.base_nightly_rate,l.fee_amount,l.currency,l.max_guests,l.room_count,l.bedroom_count,l.bed_count,l.check_in_time,l.check_out_time,l.smoking_allowed,l.pets_allowed,l.parties_allowed,l.house_rules,l.moderation_status,l.moderation_reason,l.reviewed_by,l.reviewed_at,l.is_visible,l.created_at,l.updated_at,l.deleted_at, l.base_nightly_rate nightly_price, l.fee_amount cleaning_fee, pt.name property_type, (SELECT image_url FROM listing_photos WHERE listing_id=l.id ORDER BY sort_order,id LIMIT 1) image_url, (l.is_visible=0 AND COALESCE((SELECT a.action FROM admin_audit_logs a WHERE a.entity_type='listing' AND a.entity_id=l.id AND a.action IN ('hide_listing','restore_listing') ORDER BY a.id DESC LIMIT 1),'')='hide_listing') admin_hidden FROM listings l JOIN property_types pt ON pt.id=l.property_type_id WHERE l.host_id=p_1 AND l.deleted_at IS NULL ORDER BY l.created_at DESC;
END$$
DELIMITER ;

-- 010_models.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE `NMT_sp_listing_update`(IN p_1 bigint unsigned, IN p_2 bigint unsigned, IN p_3 varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_4 text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_5 varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_6 text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_7 decimal(12,2), IN p_8 decimal(12,2), IN p_9 int, IN p_10 int, IN p_11 int, IN p_12 int, IN p_13 time, IN p_14 time, IN p_15 bool, IN p_16 bool, IN p_17 bool, IN p_18 bigint unsigned, IN p_19 bigint unsigned, IN p_20 decimal(10,6), IN p_21 decimal(10,6))
BEGIN
    IF p_7 IS NULL OR ROUND(p_7,0)<1 OR p_8 IS NULL OR p_8<0 OR p_9<1 OR p_10<1 OR p_11<1 OR p_12<1
        OR p_3 IS NULL OR TRIM(p_3)='' OR p_4 IS NULL OR TRIM(p_4)='' OR p_5 IS NULL OR TRIM(p_5)='' OR p_6 IS NULL OR TRIM(p_6)=''
        OR NOT EXISTS(SELECT 1 FROM listings l JOIN users u ON u.id=l.host_id JOIN user_roles r ON r.user_id=u.id WHERE l.id=p_18 AND l.host_id=p_19 AND l.deleted_at IS NULL AND u.status='active' AND r.role='host')
        OR NOT EXISTS(SELECT 1 FROM property_types pt WHERE pt.id=p_1 AND (pt.is_active=1 OR pt.id=(SELECT l.property_type_id FROM listings l WHERE l.id=p_18 AND l.host_id=p_19)))
        OR NOT EXISTS(SELECT 1 FROM cancellation_policies WHERE id=p_2 AND is_active=1) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Thông tin hoặc quyền sửa chỗ ở không hợp lệ.';
    END IF;
    IF (p_20 IS NULL)<>(p_21 IS NULL) OR p_20 NOT BETWEEN -90 AND 90 OR p_21 NOT BETWEEN -180 AND 180 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tọa độ cần có cả vĩ độ (-90 đến 90) và kinh độ (-180 đến 180), hoặc để trống cả hai.';
    END IF;
    UPDATE listings SET property_type_id=p_1, cancellation_policy_id=p_2, title=p_3, address=p_4, city=p_5, description=p_6, base_nightly_rate=p_7, fee_amount=p_8, max_guests=p_9, room_count=p_10, bedroom_count=p_11, bed_count=p_12, check_in_time=p_13, check_out_time=p_14, smoking_allowed=p_15, pets_allowed=p_16, parties_allowed=p_17, latitude=p_20, longitude=p_21, moderation_status='pending', updated_at=NOW(6) WHERE id=p_18 AND host_id=p_19 AND deleted_at IS NULL;
    SELECT ROW_COUNT() __affected,LAST_INSERT_ID() __insert_id;
END$$
DELIMITER ;

-- 095_online_payment.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE NMT_sp_payment_checkout(IN p_booking BIGINT UNSIGNED, IN p_guest BIGINT UNSIGNED)
BEGIN
    IF NOT EXISTS(SELECT 1 FROM bookings b JOIN users u ON u.id=b.guest_id
        WHERE b.id=p_booking AND b.guest_id=p_guest AND u.status='active' AND u.deleted_at IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không có quyền thanh toán booking này.';
    END IF;
    SELECT b.id booking_id,b.status booking_status,b.check_in,b.check_out,b.currency,l.title,
        COALESCE(pay.amount_due,b.total_amount) amount_due,COALESCE(pay.status,'unpaid') payment_status,COALESCE(pay.method,'pay_at_checkin') payment_method,
        -- A taken-down listing or a deleted Host refuses payment only for a stay that will not happen; a suspended Host only stops new payments for now (host_locked), the stay still exists. A finished stay (check-out day reached, as in the take-down rule) really happened and is paid normally.
        (COALESCE(pay.status,'unpaid')='unpaid' AND b.status='confirmed' AND b.currency='VND' AND COALESCE(pay.amount_due,b.total_amount)>0 AND ((l.deleted_at IS NULL AND h.status='active' AND h.deleted_at IS NULL) OR b.check_out<=DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR))) can_pay_online,
        ((l.deleted_at IS NOT NULL OR h.status='deleted' OR h.deleted_at IS NOT NULL) AND b.check_out>DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR)) listing_removed,
        (l.deleted_at IS NULL AND h.status='suspended' AND h.deleted_at IS NULL AND b.status IN ('pending','confirmed') AND b.check_out>DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR)) host_locked,
        t.id transfer_id,t.reference transfer_reference,t.status transfer_status,t.amount transfer_amount,t.created_at transfer_created_at,t.submitted_at transfer_submitted_at
    FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN users h ON h.id=l.host_id LEFT JOIN booking_payments pay ON pay.booking_id=b.id
        LEFT JOIN payment_transactions t ON t.id=(SELECT x.id FROM payment_transactions x
            WHERE x.booking_id=b.id AND x.provider='vietqr' AND x.status IN ('pending','submitted') ORDER BY x.id DESC LIMIT 1)
    WHERE b.id=p_booking;
END$$
DELIMITER ;

-- 095_online_payment.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE NMT_sp_payment_vnpay_result(IN p_reference VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_amount DECIMAL(12,2), IN p_response_code VARCHAR(4) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_transaction_status VARCHAR(4) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_transaction_no VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_bank_code VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_attempt BIGINT UNSIGNED;
    DECLARE v_booking BIGINT UNSIGNED;
    DECLARE v_listing BIGINT UNSIGNED;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE v_guest BIGINT UNSIGNED;
    DECLARE v_booking_status VARCHAR(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_payment VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_status VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_amount DECIMAL(12,2);
    DECLARE v_decided BIGINT UNSIGNED;
    DECLARE v_host BIGINT UNSIGNED;
    DECLARE v_removed DATETIME(6);
    DECLARE v_suspended BOOL DEFAULT FALSE;
    DECLARE v_end DATE;
    DECLARE v_result VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'ok';
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_vnpay_result; RELEASE SAVEPOINT h2h_vnpay_result; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_vnpay_result; END IF;
    SELECT id,booking_id INTO v_attempt,v_booking FROM payment_transactions WHERE reference=p_reference AND provider='vnpay';
    IF v_attempt IS NULL THEN
        SET v_result='not_found';
    ELSE
        SELECT listing_id INTO v_listing FROM bookings WHERE id=v_booking;
        SELECT id,deleted_at,host_id INTO v_lock,v_removed,v_host FROM listings WHERE id=v_listing FOR UPDATE;
        -- A deleted Host counts as a removed listing: the stay will not happen. A suspension is reversible and the stay still exists: the money is a normal payment and the Admins are asked to check the stay.
        SELECT IF(status='deleted' OR deleted_at IS NOT NULL,COALESCE(v_removed,NOW(6)),v_removed),status='suspended' INTO v_removed,v_suspended FROM users WHERE id=v_host LOCK IN SHARE MODE;
        SELECT guest_id,status,check_out INTO v_guest,v_booking_status,v_end FROM bookings WHERE id=v_booking FOR UPDATE;
        -- A finished stay really happened: money for it is a normal payment even if the listing was taken down afterwards.
        IF v_end<=DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR) THEN SET v_removed=NULL; SET v_suspended=FALSE; END IF;
        SELECT status INTO v_payment FROM booking_payments WHERE booking_id=v_booking FOR UPDATE;
        -- VNPay was asked for the whole-dong amount, even if this attempt was stored with a decimal before amounts were rounded.
        SELECT status,ROUND(amount,0),decided_by INTO v_status,v_amount,v_decided FROM payment_transactions WHERE id=v_attempt FOR UPDATE;
        IF p_amount IS NULL OR p_amount<>v_amount THEN
            SET v_result='invalid_amount';
        ELSEIF (v_status NOT IN ('pending','cancelled') AND NOT (v_status='failed' AND p_response_code='00' AND p_transaction_status='00')) OR v_decided IS NOT NULL THEN
            -- decided_by is only set on a VNPay attempt when an Admin closed it as a refunded duplicate.
            -- A signed success after a signed non-success on this reference is money taken, so it goes on to be recorded below; every other repeat is ignored.
            SET v_result='already';
        ELSEIF p_response_code='00' AND p_transaction_status='00' THEN
            IF COALESCE(v_payment,'unpaid')='unpaid' THEN
                UPDATE payment_transactions SET status='succeeded',gateway_transaction_no=NULLIF(p_transaction_no,''),gateway_response_code=p_response_code,
                    bank_code=NULLIF(p_bank_code,''),decided_at=NOW(6),note=NULL,updated_at=NOW(6) WHERE id=v_attempt;
                UPDATE payment_transactions SET status='cancelled',decided_at=NOW(6),note='Booking đã được thanh toán bằng giao dịch khác.',updated_at=NOW(6)
                    WHERE booking_id=v_booking AND id<>v_attempt AND status='pending';
                IF v_payment IS NULL THEN
                    INSERT INTO booking_payments(booking_id,method,amount_due,status,paid_at,paid_by,refunded_at,refunded_by,note,created_at,updated_at)
                        VALUES(v_booking,'vnpay',v_amount,'paid',NOW(6),v_guest,NULL,NULL,CONCAT('VNPay ',COALESCE(NULLIF(p_transaction_no,''),p_reference)),NOW(6),NOW(6));
                ELSE
                    UPDATE booking_payments SET method='vnpay',amount_due=v_amount,status='paid',paid_at=NOW(6),paid_by=v_guest,
                        note=CONCAT('VNPay ',COALESCE(NULLIF(p_transaction_no,''),p_reference)),updated_at=NOW(6) WHERE booking_id=v_booking;
                END IF;
                INSERT INTO booking_payment_events(booking_id,actor_id,from_status,to_status,note,created_at)
                    VALUES(v_booking,v_guest,'unpaid','paid',CONCAT('VNPay ',COALESCE(NULLIF(p_transaction_no,''),p_reference)),NOW(6));
                INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
                    VALUES(v_guest,v_booking,'in_app','payment_paid',IF(v_booking_status NOT IN ('confirmed','completed') OR v_removed IS NOT NULL,'Khoản thanh toán sẽ được hoàn lại','Đã ghi nhận thanh toán'),
                    CASE WHEN v_booking_status NOT IN ('confirmed','completed') THEN CONCAT('Booking #',v_booking,' đã hủy nhưng VNPay vẫn nhận ',REPLACE(FORMAT(v_amount,0),',','.'),'đ. Admin sẽ hoàn lại khoản này cho bạn.')
                        WHEN v_removed IS NOT NULL THEN CONCAT('Chỗ ở của booking #',v_booking,' đã bị gỡ nhưng VNPay vẫn nhận ',REPLACE(FORMAT(v_amount,0),',','.'),'đ. Admin sẽ hoàn lại khoản này cho bạn.')
                        ELSE CONCAT('Booking #',v_booking,' đã thanh toán thành công qua VNPay ',REPLACE(FORMAT(v_amount,0),',','.'),'đ.') END,'sent',1,NOW(6));
                IF v_booking_status NOT IN ('confirmed','completed') THEN
                    -- The Guest cancelled while VNPay was open: the money is recorded as paid so the Admin can record the refund of all of it.
                    UPDATE booking_cancellations SET refund_amount=v_amount WHERE booking_id=v_booking;
                    INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
                        SELECT r.user_id,v_booking,'in_app','payment_refund_needed','Cần hoàn tiền',
                            CONCAT('Booking #',v_booking,' đã hủy nhưng vẫn nhận ',REPLACE(FORMAT(v_amount,0),',','.'),'đ qua VNPay (mã ',p_reference,'). Vui lòng hoàn tiền cho khách.'),
                            'sent',1,NOW(6)
                        FROM user_roles r JOIN users u ON u.id=r.user_id WHERE r.role='admin' AND u.status='active' AND u.deleted_at IS NULL;
                ELSEIF v_removed IS NOT NULL THEN
                    -- A VNPay tab left open while the listing was taken down: same as above, the money is recorded and goes back.
                    INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
                        SELECT r.user_id,v_booking,'in_app','payment_refund_needed','Cần hoàn tiền',
                            CONCAT('Booking #',v_booking,' thuộc chỗ ở đã gỡ nhưng vẫn nhận ',REPLACE(FORMAT(v_amount,0),',','.'),'đ qua VNPay (mã ',p_reference,'). Vui lòng hoàn tiền cho khách.'),
                            'sent',1,NOW(6)
                        FROM user_roles r JOIN users u ON u.id=r.user_id WHERE r.role='admin' AND u.status='active' AND u.deleted_at IS NULL;
                ELSEIF v_suspended THEN
                    INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
                        SELECT r.user_id,v_booking,'in_app','payment_host_suspended','Cần kiểm tra chuyến ở',
                            CONCAT('Booking #',v_booking,' vừa nhận ',REPLACE(FORMAT(v_amount,0),',','.'),'đ qua VNPay (mã ',p_reference,') trong khi tài khoản Host đang tạm khóa. Vui lòng kiểm tra chuyến ở này.'),
                            'sent',1,NOW(6)
                        FROM user_roles r JOIN users u ON u.id=r.user_id WHERE r.role='admin' AND u.status='active' AND u.deleted_at IS NULL;
                END IF;
            ELSE
                -- The money arrived after the booking was paid another way: keep the record and ask the Admins to refund it.
                UPDATE payment_transactions SET status='succeeded',gateway_transaction_no=NULLIF(p_transaction_no,''),gateway_response_code=p_response_code,
                    bank_code=NULLIF(p_bank_code,''),decided_at=NOW(6),note='Thanh toán trùng: booking đã được thanh toán trước đó, cần hoàn tiền cho khách.',updated_at=NOW(6)
                    WHERE id=v_attempt;
                INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
                    SELECT r.user_id,v_booking,'in_app','payment_duplicate','Thanh toán trùng',
                        CONCAT('Booking #',v_booking,' nhận thêm ',REPLACE(FORMAT(v_amount,0),',','.'),'đ qua VNPay (mã ',p_reference,') sau khi đã thanh toán. Vui lòng hoàn tiền cho khách.'),
                        'sent',1,NOW(6)
                    FROM user_roles r JOIN users u ON u.id=r.user_id WHERE r.role='admin' AND u.status='active' AND u.deleted_at IS NULL;
                INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
                    VALUES(v_guest,v_booking,'in_app','payment_duplicate','Thanh toán trùng',
                    CONCAT('Booking #',v_booking,' đã được thanh toán trước đó nên khoản VNPay ',REPLACE(FORMAT(v_amount,0),',','.'),'đ (mã ',p_reference,') là thanh toán trùng. Admin sẽ hoàn lại khoản này cho bạn.'),'sent',1,NOW(6));
            END IF;
        ELSE
            UPDATE payment_transactions SET status='failed',gateway_transaction_no=NULLIF(p_transaction_no,''),gateway_response_code=p_response_code,
                bank_code=NULLIF(p_bank_code,''),decided_at=NOW(6),updated_at=NOW(6) WHERE id=v_attempt;
        END IF;
    END IF;
    SELECT v_result result,v_booking booking_id,(SELECT status FROM payment_transactions WHERE id=v_attempt) transaction_status,
        (SELECT gateway_response_code FROM payment_transactions WHERE id=v_attempt) response_code,
        -- Also true when the IPN recorded the duplicate first and the return URL only repeats it. A first success stores no note.
        (SELECT status='succeeded' AND gateway_response_code='00' AND note IS NOT NULL FROM payment_transactions WHERE id=v_attempt) duplicate_payment,
        -- An Admin has since closed that duplicate as refunded, so a reloaded return URL must not promise the refund again.
        (SELECT status='cancelled' AND gateway_response_code='00' AND decided_by IS NOT NULL FROM payment_transactions WHERE id=v_attempt) duplicate_refunded,
        (SELECT status FROM booking_payments WHERE booking_id=v_booking) payment_status,
        -- Why money on this attempt goes back to the Guest, if it does, and how much is owed.
        (SELECT CASE WHEN status NOT IN ('confirmed','completed') THEN 'cancelled' WHEN v_removed IS NOT NULL THEN 'removed' END FROM bookings WHERE id=v_booking) return_reason,
        COALESCE((SELECT refund_amount FROM booking_cancellations WHERE booking_id=v_booking),v_amount) refund_owed;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_vnpay_result; END IF;
END$$
DELIMITER ;

-- 010_models.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE `NMT_sp_user_set_status`(IN p_1 varchar(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_2 bigint unsigned)
BEGIN
    DECLARE v_restored INT DEFAULT 0;
    IF p_1 IS NULL OR p_1 NOT IN ('active','suspended','restore') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Trạng thái tài khoản không hợp lệ.'; END IF;
    IF p_1 = 'restore' THEN
        -- Only an explicit restore clears deleted_at: 'active' on a deleted account stays a no-op, so a stale unlock form cannot bring it back.
        UPDATE users SET status='active',deleted_at=NULL,updated_at=NOW(6) WHERE id = p_2 AND deleted_at IS NOT NULL;
        SET v_restored = ROW_COUNT();
        IF v_restored = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không tìm thấy tài khoản đã xóa để khôi phục.'; END IF;
        SELECT v_restored __affected,LAST_INSERT_ID() __insert_id;
    ELSE
        UPDATE users SET status = p_1 WHERE id = p_2 AND deleted_at IS NULL;
        SELECT ROW_COUNT() __affected,LAST_INSERT_ID() __insert_id;
    END IF;
END$$
DELIMITER ;

-- 030_atomic_writes.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE NTK_sp_admin_listing_visibility(IN p_1 bool, IN p_2 bigint unsigned, IN p_3 bigint unsigned)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE v_was_visible BOOL;
    DECLARE v_latest_hide BOOL;
    DECLARE v_latest_host_hidden BOOL;
    DECLARE v_admin_hidden BOOL DEFAULT FALSE;
    DECLARE v_host_hidden BOOL DEFAULT FALSE;
    DECLARE v_visible BOOL;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_admin_listing_visibility; RELEASE SAVEPOINT h2h_admin_listing_visibility; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_admin_listing_visibility; END IF;
    CALL NTK_sp_admin_assert_role(p_3);
    SELECT id,is_visible INTO v_lock,v_was_visible FROM listings WHERE id=p_2 AND deleted_at IS NULL FOR UPDATE;
    IF v_lock IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỗ ở không tồn tại.'; END IF;
    IF p_1 IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Hiển thị không hợp lệ.'; END IF;
    -- The latest of these audit rows says who holds a hidden listing: LTP_sp_listing_set_visible refuses the Host while it is hide_listing.
    -- An Admin hide over the Host's own hide records host_hidden, so ending the Admin's hold hands the listing back still hidden.
    SELECT a.action='hide_listing',COALESCE(JSON_VALUE(a.change_summary,'$.host_hidden'),0)=1 INTO v_latest_hide,v_latest_host_hidden
        FROM admin_audit_logs a WHERE a.entity_type='listing' AND a.entity_id=p_2 AND a.action IN ('hide_listing','restore_listing') ORDER BY a.id DESC LIMIT 1;
    SET v_admin_hidden=(v_was_visible=0 AND COALESCE(v_latest_hide,FALSE));
    SET v_host_hidden=(v_was_visible=0 AND (NOT v_admin_hidden OR COALESCE(v_latest_host_hidden,FALSE)));
    IF p_1<>0 AND v_was_visible=0 AND NOT v_admin_hidden THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Host đã tự ẩn chỗ ở này nên chỉ Host mới hiện lại được.';
    END IF;
    SET v_visible=(p_1<>0 AND NOT v_host_hidden);
    UPDATE listings SET is_visible=v_visible,updated_at=NOW(6) WHERE id=p_2;
    INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at) VALUES(p_3,IF(p_1,'restore_listing','hide_listing'),'listing',p_2,JSON_OBJECT('details',CONCAT('visibility=',v_visible),'host_hidden',IF(v_host_hidden,1,0)),NOW(6));
    -- A repeat click from a stale page changes nothing for the Host, so it tells them nothing either.
    IF (p_1=0 AND NOT v_admin_hidden) OR (p_1<>0 AND v_was_visible=0) THEN
        INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
            SELECT l.host_id,NULL,'in_app',
                CASE WHEN p_1=0 THEN 'listing_hidden' WHEN v_visible THEN 'listing_shown' ELSE 'listing_hold_lifted' END,
                CASE WHEN p_1=0 THEN 'Tin đăng bị ẩn' WHEN v_visible THEN 'Tin đăng được hiện lại' ELSE 'Admin đã bỏ ẩn tin đăng' END,
                CASE WHEN p_1=0 AND v_host_hidden THEN CONCAT('Admin đã ẩn tin “',l.title,'”, tin mà bạn đang tự ẩn. Bạn chỉ hiện lại được sau khi Admin bỏ ẩn; hãy liên hệ Admin nếu bạn cần.')
                    WHEN p_1=0 THEN CONCAT('Admin đã ẩn tin “',l.title,'”. Tin không hiển thị với khách cho đến khi Admin hiện lại; hãy liên hệ Admin nếu bạn cần.')
                    WHEN v_visible THEN CONCAT('Admin đã hiện lại tin “',l.title,'”.')
                    ELSE CONCAT('Admin đã bỏ ẩn tin “',l.title,'”. Tin vẫn đang ẩn vì bạn đã tự ẩn trước đó; bạn có thể tự hiện lại khi sẵn sàng.') END,
                'sent',1,NOW(6)
            FROM listings l JOIN users u ON u.id=l.host_id WHERE l.id=p_2 AND u.deleted_at IS NULL;
    END IF;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_admin_listing_visibility; END IF;
END$$
DELIMITER ;

-- 070_booking_extras.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE NTK_sp_admin_payments(IN p_actor BIGINT UNSIGNED, IN p_status VARCHAR(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_query VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_number BIGINT UNSIGNED DEFAULT NULL;
    -- User input is matched literally: LIKE wildcards are escaped.
    -- đ has its own weight in every collation here, unlike the other Vietnamese accents, so it is folded on both sides as in location search (NTK_sp_listing_search). REPLACE is case-sensitive, hence both cases.
    DECLARE v_query VARCHAR(600) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(p_query,'')),'\\','\\\\'),'%','\\%'),'_','\\_'),'đ','d'),'Đ','D');
    CALL NTK_sp_admin_assert_role(p_actor);
    IF p_status IS NULL OR p_status NOT IN ('','unpaid','paid','refunded','not_required') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Trạng thái thanh toán lọc không hợp lệ.'; END IF;
    SET p_query=TRIM(COALESCE(p_query,''));
    -- The site prints a booking as #12, so searching #12 means exactly that booking; anything else stays a text search.
    IF p_query REGEXP '^#[0-9]{1,18}$' THEN SET v_number=SUBSTRING(p_query,2); END IF;
    SELECT x.* FROM (
        SELECT b.id booking_id,b.listing_id,b.status booking_status,b.check_in,b.check_out,b.total_amount,b.currency,b.created_at,l.title,u.full_name guest_name,u.email guest_email,h.full_name host_name,
            COALESCE(pay.method,'pay_at_checkin') payment_method,COALESCE(pay.amount_due,b.total_amount) amount_due,COALESCE(pay.status,'unpaid') payment_status,
            CASE WHEN COALESCE(pay.status,'unpaid')='unpaid' AND b.status IN ('rejected','cancelled') THEN 'not_required' ELSE COALESCE(pay.status,'unpaid') END payment_state,
            (COALESCE(pay.status,'unpaid')='unpaid' AND b.status IN ('confirmed','completed') AND b.check_in<=DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR)) can_mark_paid,
            EXISTS(SELECT 1 FROM payment_transactions x WHERE x.booking_id=b.id AND x.status='submitted') transfer_submitted,
            pay.paid_at,pay.refunded_at,pay.note payment_note,COALESCE(bp.promotion_code,'') promotion_code,
            dup.id duplicate_id,dup.reference duplicate_reference,dup.amount duplicate_amount,COALESCE(c.refund_amount,pay.amount_due,b.total_amount) refund_amount,COALESCE(pay.paid_at>c.created_at,0) paid_after_cancel,
            CASE WHEN pay.status='refunded' THEN COALESCE((SELECT CAST(JSON_VALUE(a.change_summary,'$.amount') AS DECIMAL(12,2)) FROM admin_audit_logs a WHERE a.action='refund_payment' AND a.entity_type='booking' AND a.entity_id=b.id ORDER BY a.id DESC LIMIT 1),pay.amount_due) END refunded_amount
        FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN users u ON u.id=b.guest_id JOIN users h ON h.id=l.host_id
            LEFT JOIN booking_payments pay ON pay.booking_id=b.id LEFT JOIN booking_price_breakdowns bp ON bp.booking_id=b.id
            LEFT JOIN booking_cancellations c ON c.booking_id=b.id
            LEFT JOIN payment_transactions dup ON dup.id=(SELECT MAX(d.id) FROM payment_transactions d
                WHERE d.booking_id=b.id AND d.provider='vnpay' AND d.status='succeeded' AND d.note IS NOT NULL)
    ) x
    WHERE (p_status='' OR x.payment_state=p_status)
        AND (x.booking_id=v_number OR v_number IS NULL AND (p_query='' OR CAST(x.booking_id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci=p_query OR REPLACE(REPLACE(x.title,'đ','d'),'Đ','D') LIKE CONCAT('%',v_query,'%')
            OR REPLACE(REPLACE(x.guest_name,'đ','d'),'Đ','D') LIKE CONCAT('%',v_query,'%') OR x.guest_email LIKE CONCAT('%',v_query,'%')))
    ORDER BY x.created_at DESC,x.booking_id DESC LIMIT 200;
END$$
DELIMITER ;

-- 070_booking_extras.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE NTK_sp_admin_promotions(IN p_actor BIGINT UNSIGNED)
BEGIN
    CALL NTK_sp_admin_assert_role(p_actor);
    SELECT p.id,p.code,p.description,p.discount_type,p.discount_value,p.max_discount_amount,p.min_nights,p.min_subtotal,p.valid_from,p.valid_to,
        p.usage_limit,p.is_active,p.created_at,p.updated_at,
        (SELECT COUNT(*) FROM booking_price_breakdowns bp JOIN bookings b ON b.id=bp.booking_id WHERE bp.promotion_id=p.id AND (b.status IN ('confirmed','completed') OR (b.status='pending' AND b.check_in>=DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR)))) used_count,
        (SELECT COUNT(*) FROM booking_price_breakdowns bp WHERE bp.promotion_id=p.id) booking_count
    FROM promotions p ORDER BY p.is_active DESC,p.valid_to DESC,p.id DESC;
END$$
DELIMITER ;

-- 060_quality_community.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE NTK_sp_admin_reports(IN p_actor BIGINT UNSIGNED, IN p_limit INT, IN p_offset INT)
BEGIN
    CALL NTK_sp_admin_assert_role(p_actor);
    IF p_limit IS NULL OR p_offset IS NULL OR p_limit<1 OR p_limit>100 OR p_offset<0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Phân trang không hợp lệ.'; END IF;
    SELECT SQL_CALC_FOUND_ROWS r.*,l.title,l.deleted_at listing_deleted_at,u.full_name reporter_name FROM reports r LEFT JOIN listings l ON l.id=r.listing_id
    JOIN users u ON u.id=r.reporter_id ORDER BY r.created_at DESC,r.id DESC LIMIT p_limit OFFSET p_offset;
    SELECT FOUND_ROWS() __total;
END$$
DELIMITER ;

-- 010_models.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE `NTK_sp_admin_total_revenue`()
BEGIN
    -- Refunded money is not revenue; the refund audit row holds what really went back (see NTK_sp_booking_payment_refund).
    SELECT COALESCE(SUM(GREATEST(0,b.total_amount-CASE WHEN pay.status='refunded' THEN COALESCE((SELECT CAST(JSON_VALUE(a.change_summary,'$.amount') AS DECIMAL(12,2)) FROM admin_audit_logs a WHERE a.action='refund_payment' AND a.entity_type='booking' AND a.entity_id=b.id ORDER BY a.id DESC LIMIT 1),pay.amount_due) ELSE 0 END)),0)
    FROM bookings b LEFT JOIN booking_payments pay ON pay.booking_id=b.id WHERE b.status='completed';
END$$
DELIMITER ;

-- 020_booking.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE NTK_sp_booking_cancel(IN p_booking BIGINT UNSIGNED, IN p_guest BIGINT UNSIGNED, IN p_reason TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_listing BIGINT UNSIGNED;
    DECLARE v_host BIGINT UNSIGNED;
    DECLARE v_guest BIGINT UNSIGNED;
    DECLARE v_status VARCHAR(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_start DATE;
    DECLARE v_end DATE;
    DECLARE v_total DECIMAL(12,2);
    DECLARE v_fee DECIMAL(12,2);
    DECLARE v_policy LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_percent DECIMAL(5,2);
    DECLARE v_base DECIMAL(12,2);
    DECLARE v_refund DECIMAL(12,2);
    DECLARE v_payment VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_default_zone TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_default_time TIME;
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
    SELECT guest_id,status,check_in,check_out,total_amount,fee_amount,policy_snapshot INTO v_guest,v_status,v_start,v_end,v_total,v_fee,v_policy FROM bookings WHERE id=p_booking FOR UPDATE;
    IF v_guest IS NULL OR v_guest<>p_guest OR NOT EXISTS(SELECT 1 FROM users WHERE id=p_guest AND status='active') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Booking không tồn tại hoặc không thuộc về bạn.';
    END IF;
    IF v_status NOT IN ('pending','confirmed') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Booking ở trạng thái này không thể hủy.'; END IF;
    -- Vietnam date, as everywhere in booking rules: from the check-out day on, the Host can complete the stay.
    IF v_end<=DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chuyến ở đã kết thúc nên không thể hủy booking.'; END IF;
    SELECT status INTO v_payment FROM booking_payments WHERE booking_id=p_booking FOR UPDATE;
    -- refund_amount records what is still owed: nothing collected, or everything already handed back by an Admin (a refund before the cancellation is always the full amount), owes 0. What was really refunded is read from the refund audit row.
    IF COALESCE(v_payment,'unpaid') IN ('unpaid','refunded') THEN SET v_percent=0; SET v_base=0;
    ELSEIF v_status='pending' THEN SET v_percent=100; SET v_base=v_total;
    ELSE
        CALL DST_sp_booking_refund_terms(p_booking,v_policy,v_start,v_total,v_fee,v_default_time,v_default_zone,v_percent,v_base);
    END IF;
    SET v_refund=ROUND(GREATEST(0,v_base)*v_percent/100,0);
    UPDATE bookings SET status='cancelled',updated_at=NOW(6) WHERE id=p_booking;
    INSERT INTO booking_cancellations(booking_id,cancelled_by,reason,refund_amount,calculation_details,created_at)
        VALUES(p_booking,p_guest,TRIM(p_reason),v_refund,JSON_OBJECT('policy',JSON_EXTRACT(v_policy,'$'),'refund_percent',v_percent,'refundable_base',v_base),NOW(6));
    INSERT INTO booking_events(booking_id,actor_id,from_status,to_status,reason,created_at) VALUES(p_booking,p_guest,v_status,'cancelled','Khách hủy booking.',NOW(6));
    INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
        VALUES(v_host,p_booking,'in_app','booking_cancelled','Cập nhật booking','Khách đã hủy booking.','sent',1,NOW(6));
    INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
        VALUES(v_guest,p_booking,'in_app','booking_cancelled','Đã hủy booking',
        IF(COALESCE(v_payment,'unpaid')='unpaid',CONCAT('Booking #',p_booking,' đã hủy.'),
            IF(v_payment='refunded',CONCAT('Booking #',p_booking,' đã hủy. Khoản thanh toán đã được hoàn trước đó nên không có khoản hoàn thêm.'),
            IF(v_payment='paid' AND v_refund=0,CONCAT('Booking #',p_booking,' đã hủy. Theo chính sách hủy, khoản đã thanh toán không được hoàn.'),
            CONCAT('Booking #',p_booking,' đã hủy. Mức hoàn: ',REPLACE(FORMAT(v_refund,0),',','.'),'đ (',TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM v_percent)),'%).')))),'sent',1,NOW(6));
    IF v_payment='paid' THEN
        -- Money was collected: every Admin learns what is owed, in the same transaction as the cancellation.
        INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
            SELECT r.user_id,p_booking,'in_app',IF(v_refund>0,'payment_refund_needed','payment_cancelled_paid'),IF(v_refund>0,'Cần hoàn tiền','Booking đã thanh toán bị hủy'),
                IF(v_refund>0,CONCAT('Booking #',p_booking,' đã hủy khi đã thanh toán. Cần hoàn ',REPLACE(FORMAT(v_refund,0),',','.'),'đ cho khách theo chính sách (',TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM v_percent)),'%).'),
                    CONCAT('Booking #',p_booking,' đã hủy khi đã thanh toán. Theo chính sách hủy, khoản hoàn là 0đ nên không cần hoàn tiền.')),
                'sent',1,NOW(6)
            FROM user_roles r JOIN users u ON u.id=r.user_id WHERE r.role='admin' AND u.status='active' AND u.deleted_at IS NULL;
    END IF;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_booking_cancel; END IF;
    SELECT v_percent refund_percent,v_refund refund_amount,COALESCE(v_payment='paid',0) was_paid;
END$$
DELIMITER ;

-- 080_conversation.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE NTK_sp_booking_message_send(IN p_booking BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED,
    IN p_body TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_guest BIGINT UNSIGNED;
    DECLARE v_host BIGINT UNSIGNED;
    DECLARE v_recipient BIGINT UNSIGNED;
    DECLARE v_recipient_deleted DATETIME(6);
    DECLARE v_id BIGINT UNSIGNED;
    DECLARE v_unread INT DEFAULT 0;
    DECLARE v_status VARCHAR(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_title VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_sender VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_body TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_check_out DATE;
    DECLARE v_code INT DEFAULT 0;
    DECLARE v_length INT;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_message_send; RELEASE SAVEPOINT h2h_message_send; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_message_send; END IF;
    -- The booking row lock serializes sends with status changes and with each other (anti-flood check below).
    SELECT guest_id,status,check_out INTO v_guest,v_status,v_check_out FROM bookings WHERE id=p_booking FOR UPDATE;
    SELECT l.host_id,l.title INTO v_host,v_title FROM bookings b JOIN listings l ON l.id=b.listing_id WHERE b.id=p_booking;
    IF v_guest IS NULL OR NOT EXISTS(SELECT 1 FROM users WHERE id=p_actor AND status='active' AND deleted_at IS NULL)
        OR NOT (v_guest=p_actor OR (v_host=p_actor AND EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_actor AND role='host'))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bạn không tham gia cuộc trò chuyện của booking này.';
    END IF;
    SET v_recipient=IF(p_actor=v_guest,v_host,v_guest);
    -- Nobody can read what is sent to a deleted account. Worked out live, so restoring the account reopens the thread.
    SELECT deleted_at INTO v_recipient_deleted FROM users WHERE id=v_recipient LOCK IN SHARE MODE;
    IF v_recipient_deleted IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tài khoản của người nhận không còn tồn tại nên cuộc trò chuyện chỉ còn để xem.';
    END IF;
    IF v_status IN ('rejected','cancelled') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Booking đã bị từ chối hoặc hủy nên cuộc trò chuyện chỉ còn để xem.';
    END IF;
    IF v_status='completed' AND DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR)>v_check_out+INTERVAL 14 DAY THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Cuộc trò chuyện đã đóng sau 14 ngày kể từ ngày trả phòng.';
    END IF;
    IF v_status NOT IN ('pending','confirmed','completed') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Booking ở trạng thái này không nhận tin nhắn.';
    END IF;
    -- Same rule as BookingMessage::normalize: no control characters but newline and tab are stored, and a body that draws nothing is empty.
    -- REGEXP_REPLACE on text turns every 4-byte character into '?' on MariaDB 10.4, so it may judge the body but never rewrite it: REPLACE and TRIM keep emoji intact.
    SET v_body=COALESCE(p_body,'');
    WHILE v_code<=159 DO
        IF (v_code<32 AND v_code NOT IN (9,10)) OR v_code>=127 THEN
            SET v_body=REPLACE(v_body,CONVERT(CHAR(v_code USING ucs2) USING utf8mb4) COLLATE utf8mb4_unicode_ci,'');
        END IF;
        SET v_code=v_code+1;
    END WHILE;
    REPEAT
        SET v_length=CHAR_LENGTH(v_body);
        SET v_body=TRIM(BOTH ' ' FROM TRIM(BOTH '\t' FROM TRIM(BOTH '\n' FROM v_body)));
    UNTIL CHAR_LENGTH(v_body)=v_length END REPEAT;
    -- The inner call works on bytes, because the blank 4-byte characters (tags and variation selectors, U+E0000-E01FF) would read as a visible '?' otherwise.
    IF CHAR_LENGTH(v_body)>2000 OR CHAR_LENGTH(REGEXP_REPLACE(CONVERT(REGEXP_REPLACE(CAST(v_body AS BINARY),'\\xF3\\xA0[\\x80-\\x87][\\x80-\\xBF]','') USING utf8mb4),'[\\p{Cc}\\p{Cf}\\p{Z}\\x{034F}\\x{FE00}-\\x{FE0F}\\x{2800}\\x{3164}\\x{FFA0}\\x{115F}\\x{1160}]',''))<1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tin nhắn cần từ 1 đến 2000 ký tự.';
    END IF;
    INSERT INTO booking_messages(booking_id,sender_id,recipient_id,body,created_at) VALUES(p_booking,p_actor,v_recipient,v_body,NOW(6));
    SET v_id=LAST_INSERT_ID();
    -- Anti-flood: a recipient keeps at most one unread message notification per booking.
    SELECT COUNT(*) INTO v_unread FROM notifications
        WHERE user_id=v_recipient AND booking_id=p_booking AND channel='in_app' AND event_type='booking_message' AND read_at IS NULL FOR UPDATE;
    IF v_unread=0 THEN
        SELECT full_name INTO v_sender FROM users WHERE id=p_actor;
        INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
            VALUES(v_recipient,p_booking,'in_app','booking_message','Tin nhắn mới',
            CONCAT(v_sender,' đã gửi tin nhắn về booking #',p_booking,' · “',v_title,'”.'),'sent',1,NOW(6));
    END IF;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_message_send; END IF;
    SELECT v_id message_id;
END$$
DELIMITER ;

-- 070_booking_extras.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE NTK_sp_booking_payment_refund(IN p_booking BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED,
    IN p_note VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_shown_amount DECIMAL(12,2))
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_listing BIGINT UNSIGNED;
    DECLARE v_host BIGINT UNSIGNED;
    DECLARE v_guest BIGINT UNSIGNED;
    DECLARE v_payment VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_due DECIMAL(12,2);
    DECLARE v_refund DECIMAL(12,2);
    DECLARE v_note VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_message VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_payment_refund; RELEASE SAVEPOINT h2h_payment_refund; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_payment_refund; END IF;
    CALL NTK_sp_admin_assert_role(p_actor);
    SELECT listing_id INTO v_listing FROM bookings WHERE id=p_booking;
    SELECT host_id INTO v_host FROM listings WHERE id=v_listing FOR UPDATE;
    SELECT guest_id INTO v_guest FROM bookings WHERE id=p_booking FOR UPDATE;
    IF v_guest IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Booking không tồn tại.'; END IF;
    SET v_note=NULLIF(TRIM(COALESCE(p_note,'')),'');
    IF v_note IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Vui lòng ghi chú lý do hoàn tiền.'; END IF;
    SELECT status,amount_due INTO v_payment,v_due FROM booking_payments WHERE booking_id=p_booking FOR UPDATE;
    IF v_payment='refunded' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Booking này đã được hoàn tiền.'; END IF;
    IF v_payment IS NULL OR v_payment<>'paid' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỉ hoàn tiền cho booking đã ghi nhận thanh toán.'; END IF;
    SELECT refund_amount INTO v_refund FROM booking_cancellations WHERE booking_id=p_booking;
    SET v_refund=COALESCE(v_refund,v_due);
    IF v_refund<=0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Theo chính sách hủy, booking này không có khoản hoàn nên không thể ghi nhận hoàn tiền.'; END IF;
    -- The form carries the figure its dialog showed. NULL means the request came with no figure; the caller then reports the amount this returns.
    IF p_shown_amount IS NOT NULL AND p_shown_amount<>v_refund THEN
        SET v_message=CONCAT('Số tiền hoàn của booking #',p_booking,' đã đổi nên chưa ghi nhận. Cần hoàn: ',REPLACE(FORMAT(v_refund,0),',','.'),'đ. Hãy xem lại rồi ghi nhận lại.');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT=v_message;
    END IF;
    UPDATE booking_payments SET status='refunded',refunded_at=NOW(6),refunded_by=p_actor,note=v_note,updated_at=NOW(6) WHERE booking_id=p_booking;
    INSERT INTO booking_payment_events(booking_id,actor_id,from_status,to_status,note,created_at) VALUES(p_booking,p_actor,'paid','refunded',v_note,NOW(6));
    INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
        VALUES(v_guest,p_booking,'in_app','payment_refunded','Đã hoàn tiền',CONCAT('Booking #',p_booking,' đã được ghi nhận hoàn tiền ',REPLACE(FORMAT(v_refund,0),',','.'),'đ.'),'sent',1,NOW(6));
    INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at)
        VALUES(p_actor,'refund_payment','booking',p_booking,JSON_OBJECT('amount',v_refund,'note',v_note),NOW(6));
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_payment_refund; END IF;
    SELECT v_refund refund_amount;
END$$
DELIMITER ;

-- 020_booking.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE NTK_sp_booking_validate(IN p_listing BIGINT UNSIGNED, IN p_start DATE, IN p_end DATE, IN p_guests INT)
BEGIN
    IF p_start IS NULL OR p_end IS NULL OR p_start < DATE(UTC_TIMESTAMP() + INTERVAL 7 HOUR) OR p_end <= p_start THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ngày nhận hoặc trả phòng không hợp lệ.';
    END IF;
    -- Same limit as BookingService::MAX_NIGHTS.
    IF DATEDIFF(p_end,p_start)>365 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Kỳ ở tối đa 365 đêm. Vui lòng chọn khoảng ngày ngắn hơn.';
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
    -- A pending request whose check-in has passed can never be confirmed, so it holds no dates. Same rule in DST_sp_booking_create, the search routines and DST_sp_listing_calendar.
    IF EXISTS(SELECT 1 FROM bookings WHERE listing_id=p_listing AND status IN ('pending','confirmed') AND (status='confirmed' OR check_in>=DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR)) AND check_in<p_end AND check_out>p_start) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Khoảng ngày này đã có yêu cầu đặt chỗ khác.';
    END IF;
END$$
DELIMITER ;

-- 030_atomic_writes.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE NTK_sp_listing_amenities_replace(IN p_1 bigint unsigned, IN p_2 bigint unsigned, IN p_3 longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
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
    -- A retired amenity may stay on the listing that already has it; only a newly added one must be active.
    DELETE FROM listing_amenities WHERE listing_id=p_1 AND NOT JSON_CONTAINS(p_3,CAST(amenity_id AS CHAR));
    WHILE v_i<JSON_LENGTH(p_3) DO
        SET v_id=JSON_UNQUOTE(JSON_EXTRACT(p_3,CONCAT('$[',v_i,']')));
        IF NOT EXISTS(SELECT 1 FROM amenities a WHERE a.id=v_id AND (a.is_active=1 OR EXISTS(SELECT 1 FROM listing_amenities la WHERE la.listing_id=p_1 AND la.amenity_id=a.id))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tiện nghi không tồn tại hoặc đã ngừng sử dụng.'; END IF;
        INSERT INTO listing_amenities(listing_id,amenity_id) VALUES(p_1,v_id) ON DUPLICATE KEY UPDATE amenity_id=VALUES(amenity_id);
        SET v_i=v_i+1;
    END WHILE;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_listing_amenities_replace; END IF;
END$$
DELIMITER ;

-- 011_queries.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE NTK_sp_listing_search(
    IN p_location VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,IN p_guests INT,IN p_type BIGINT UNSIGNED,IN p_min DECIMAL(12,2),IN p_max DECIMAL(12,2),IN p_bedrooms INT,
    IN p_start DATE,IN p_end DATE,IN p_sort VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,IN p_limit INT,IN p_offset INT)
BEGIN
    -- đ has its own weight in every collation of MariaDB 10.4, unlike the other Vietnamese accents, so "Da Lat" only finds "Đà Lạt" with it folded on both sides. REPLACE is case-sensitive, hence both cases.
    DECLARE v_location VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT REPLACE(REPLACE(p_location,'đ','d'),'Đ','D');
    IF p_limit<1 OR p_limit>100 OR p_offset<0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Phân trang không hợp lệ.'; END IF;
    SELECT l.id,l.host_id,l.property_type_id,l.cancellation_policy_id,l.title,l.description,l.address,l.city,l.country_code,l.latitude,l.longitude,l.time_zone,l.base_nightly_rate,l.fee_amount,l.currency,l.max_guests,l.room_count,l.bedroom_count,l.bed_count,l.check_in_time,l.check_out_time,l.smoking_allowed,l.pets_allowed,l.parties_allowed,l.house_rules,l.moderation_status,l.moderation_reason,l.reviewed_by,l.reviewed_at,l.is_visible,l.created_at,l.updated_at,l.deleted_at,l.base_nightly_rate nightly_price,l.fee_amount cleaning_fee,pt.name property_type,u.full_name host_name,
        (SELECT image_url FROM listing_photos WHERE listing_id=l.id ORDER BY sort_order,id LIMIT 1) image_url,
        COALESCE((SELECT AVG(r.rating) FROM reviews r JOIN bookings b ON b.id=r.booking_id WHERE b.listing_id=l.id AND r.moderation_status='visible'),0) rating,
        (SELECT COUNT(*) FROM reviews r JOIN bookings b ON b.id=r.booking_id WHERE b.listing_id=l.id AND r.moderation_status='visible') review_count
    FROM listings l JOIN property_types pt ON pt.id=l.property_type_id JOIN users u ON u.id=l.host_id
    WHERE l.moderation_status='approved' AND l.is_visible=1 AND l.deleted_at IS NULL AND u.status='active' AND u.deleted_at IS NULL
        AND (p_location='' OR REPLACE(REPLACE(l.city,'đ','d'),'Đ','D') LIKE CONCAT('%',v_location,'%') OR REPLACE(REPLACE(l.address,'đ','d'),'Đ','D') LIKE CONCAT('%',v_location,'%') OR REPLACE(REPLACE(l.title,'đ','d'),'Đ','D') LIKE CONCAT('%',v_location,'%'))
        AND (p_guests=0 OR l.max_guests>=p_guests) AND (p_type=0 OR l.property_type_id=p_type)
        AND (p_min=0 OR l.base_nightly_rate>=p_min) AND (p_max=0 OR l.base_nightly_rate<=p_max)
        AND (p_bedrooms=0 OR l.bedroom_count>=p_bedrooms)
        AND (p_start IS NULL OR p_end IS NULL OR (
            NOT EXISTS(SELECT 1 FROM listing_availability a WHERE a.listing_id=l.id AND a.is_open=0 AND a.stay_date>=p_start AND a.stay_date<p_end)
            AND NOT EXISTS(SELECT 1 FROM bookings b WHERE b.listing_id=l.id AND b.status IN ('pending','confirmed') AND (b.status='confirmed' OR b.check_in>=DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR)) AND b.check_in<p_end AND b.check_out>p_start)))
    ORDER BY CASE WHEN p_sort='price_asc' THEN l.base_nightly_rate END ASC,
        CASE WHEN p_sort='price_desc' THEN l.base_nightly_rate END DESC,
        CASE WHEN p_sort='rating' THEN rating END DESC,l.created_at DESC,l.id DESC
    LIMIT p_limit OFFSET p_offset;
END$$
DELIMITER ;

-- 095_online_payment.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE NTK_sp_payment_start(IN p_booking BIGINT UNSIGNED, IN p_guest BIGINT UNSIGNED,
    IN p_provider VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_listing BIGINT UNSIGNED;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE v_guest BIGINT UNSIGNED;
    DECLARE v_status VARCHAR(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_currency CHAR(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_total DECIMAL(12,2);
    DECLARE v_payment VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_due DECIMAL(12,2);
    DECLARE v_host BIGINT UNSIGNED;
    DECLARE v_removed DATETIME(6);
    DECLARE v_suspended BOOL DEFAULT FALSE;
    DECLARE v_end DATE;
    DECLARE v_attempt BIGINT UNSIGNED;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_payment_start; RELEASE SAVEPOINT h2h_payment_start; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_payment_start; END IF;
    IF p_provider IS NULL OR p_provider NOT IN ('vietqr','vnpay') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Phương thức thanh toán không hợp lệ.'; END IF;
    SELECT listing_id INTO v_listing FROM bookings WHERE id=p_booking;
    SELECT id,deleted_at,host_id INTO v_lock,v_removed,v_host FROM listings WHERE id=v_listing FOR UPDATE;
    -- A deleted Host counts as a removed listing: the stay will not happen. A suspension is reversible: the stay still exists, but no new payment starts while it lasts.
    SELECT IF(status='deleted' OR deleted_at IS NOT NULL,COALESCE(v_removed,NOW(6)),v_removed),status='suspended' INTO v_removed,v_suspended FROM users WHERE id=v_host LOCK IN SHARE MODE;
    SELECT guest_id,status,currency,total_amount,check_out INTO v_guest,v_status,v_currency,v_total,v_end FROM bookings WHERE id=p_booking FOR UPDATE;
    IF v_guest IS NULL OR v_guest<>p_guest OR NOT EXISTS(SELECT 1 FROM users WHERE id=p_guest AND status='active' AND deleted_at IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không có quyền thanh toán booking này.';
    END IF;
    SELECT status,amount_due INTO v_payment,v_due FROM booking_payments WHERE booking_id=p_booking FOR UPDATE;
    -- VND has no fractions: a booking saved with a decimal amount is charged in whole dong, the same figure on VietQR and VNPay.
    SET v_due=ROUND(COALESCE(v_due,v_total),0);
    IF v_payment='refunded' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Booking này đã được hoàn tiền.'; END IF;
    IF COALESCE(v_payment,'unpaid')<>'unpaid' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Booking này đã được ghi nhận thanh toán.'; END IF;
    IF v_status<>'confirmed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỉ thanh toán online cho booking đã được xác nhận.'; END IF;
    IF v_removed IS NOT NULL AND v_end>DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỗ ở này đã bị gỡ nên không thể thanh toán online.'; END IF;
    IF v_suspended AND v_end>DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tài khoản Host đang tạm khóa nên hiện chưa thể thanh toán online. Hãy liên hệ Admin nếu cần.'; END IF;
    IF v_currency<>'VND' OR v_due<=0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Booking này không hỗ trợ thanh toán online.'; END IF;
    IF EXISTS(SELECT 1 FROM payment_transactions WHERE booking_id=p_booking AND status='submitted') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bạn đã báo chuyển khoản cho booking này; vui lòng chờ Admin xác nhận.';
    END IF;
    IF p_provider='vietqr' THEN
        -- Reloading the QR page keeps the same transfer content, so the Guest never sees two codes for one booking.
        SELECT id INTO v_attempt FROM payment_transactions
        WHERE booking_id=p_booking AND provider='vietqr' AND status='pending' AND ROUND(amount,0)=v_due ORDER BY id DESC LIMIT 1 FOR UPDATE;
    END IF;
    IF v_attempt IS NULL THEN
        INSERT INTO payment_transactions(booking_id,provider,reference,amount,status,created_at,updated_at)
            VALUES(p_booking,p_provider,CONCAT('H2H',p_booking,'X',UPPER(LEFT(MD5(CONCAT(UUID(),RAND(),p_booking)),5))),v_due,'pending',NOW(6),NOW(6));
        SET v_attempt=LAST_INSERT_ID();
    END IF;
    SELECT id transaction_id,booking_id,provider,reference,amount,status,created_at FROM payment_transactions WHERE id=v_attempt;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_payment_start; END IF;
END$$
DELIMITER ;

-- 060_quality_community.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE NTK_sp_report_create(IN p_actor BIGINT UNSIGNED, IN p_listing BIGINT UNSIGNED, IN p_booking BIGINT UNSIGNED,
    IN p_category VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_description TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE v_existing BIGINT UNSIGNED;
    DECLARE v_id BIGINT UNSIGNED;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_report_create; RELEASE SAVEPOINT h2h_report_create; END IF; END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_report_create; END IF;
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
    -- A double submit waits here, then its locking read sees the first report and adds nothing: 0 __affected, same id.
    SELECT id INTO v_lock FROM listings WHERE id=p_listing FOR UPDATE;
    SELECT id INTO v_existing FROM reports WHERE reporter_id=p_actor AND listing_id=p_listing AND booking_id<=>p_booking AND status IN ('open','investigating') ORDER BY id LIMIT 1 FOR UPDATE;
    IF v_existing IS NULL THEN
        INSERT INTO reports(reporter_id,listing_id,booking_id,category,description,status,created_at)
            VALUES(p_actor,p_listing,p_booking,p_category,TRIM(p_description),'open',NOW(6));
        SET v_id=LAST_INSERT_ID();
    END IF;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_report_create; END IF;
    SELECT IF(v_existing IS NULL,1,0) __affected,COALESCE(v_existing,v_id) __insert_id;
END$$
DELIMITER ;

-- 075_trust.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE NTK_sp_trust_badges(IN p_user_ids VARCHAR(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_actor BIGINT UNSIGNED)
BEGIN
    -- Trusted Host thresholds (single source of truth; the UI help text reads these columns).
    DECLARE v_min_reviews INT DEFAULT 3;
    DECLARE v_min_rating DECIMAL(3,2) DEFAULT 4.50;
    DECLARE v_max_cancel_pct DECIMAL(5,2) DEFAULT 10.00;
    DECLARE v_min_decided INT DEFAULT 3;
    DECLARE v_window_months INT DEFAULT 12;
    DECLARE v_admin BOOL DEFAULT FALSE;
    -- Seconds from server time to the Vietnam time the Accept rule in NMT_sp_booking_transition uses, as booking_events stores server time.
    DECLARE v_vn_shift INT DEFAULT TIMESTAMPDIFF(SECOND,NOW(),UTC_TIMESTAMP())+7*3600;
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
        -- ROUND can reach the minimum from below, so the page reads these two to judge and show a rating.
        m.average_rating>=v_min_rating rating_met,TRUNCATE(m.average_rating,2) average_rating_floor,
        CASE WHEN m.user_id=p_actor OR v_admin THEN m.decided_requests END decided_requests,
        CASE WHEN m.user_id=p_actor OR v_admin THEN m.host_side_cancellations END host_side_cancellations,
        CASE WHEN m.user_id=p_actor OR v_admin THEN ROUND(100*m.host_side_cancellations/NULLIF(m.decided_requests,0),1) END cancel_pct,
        -- ROUND can reach the maximum from above, so the page reads these two to judge and show a rate; whole-number arithmetic keeps the round-up exact.
        CASE WHEN m.user_id=p_actor OR v_admin THEN m.host_side_cancellations*100<=v_max_cancel_pct*m.decided_requests END cancel_met,
        CASE WHEN m.user_id=p_actor OR v_admin THEN ((10000*m.host_side_cancellations+m.decided_requests-1) DIV NULLIF(m.decided_requests,0))/100 END cancel_pct_ceil,
        v_min_reviews min_reviews,v_min_rating min_rating,v_max_cancel_pct max_cancel_pct,v_min_decided min_decided_requests,v_window_months window_months
    FROM (
        SELECT u.id user_id,
            EXISTS(SELECT 1 FROM identity_verifications v WHERE v.user_id=u.id AND v.status='approved'
                AND REGEXP_REPLACE(u.phone,'[[:space:].()-]','') IN (v.phone,CONCAT('+84',SUBSTRING(v.phone,2)),CONCAT('84',SUBSTRING(v.phone,2)))) is_verified,
            EXISTS(SELECT 1 FROM user_roles r WHERE r.user_id=u.id AND r.role='host') is_host,
            (SELECT COUNT(*) FROM reviews rv JOIN bookings b ON b.id=rv.booking_id JOIN listings l ON l.id=b.listing_id
                WHERE l.host_id=u.id AND rv.moderation_status='visible' AND l.deleted_at IS NULL) review_count,
            COALESCE((SELECT AVG(rv.rating) FROM reviews rv JOIN bookings b ON b.id=rv.booking_id JOIN listings l ON l.id=b.listing_id
                WHERE l.host_id=u.id AND rv.moderation_status='visible' AND l.deleted_at IS NULL),0) average_rating,
            -- booking_events says who rejected and when: a request closed automatically, by an Admin, or after its check-in day (when Accept is refused and Reject is the only way to tidy it up) is neither a Host refusal nor a Host decision.
            (SELECT COUNT(*) FROM bookings b JOIN listings l ON l.id=b.listing_id
                WHERE l.host_id=u.id AND b.status<>'pending' AND b.created_at>=NOW(6)-INTERVAL v_window_months MONTH
                    AND (b.status<>'rejected' OR EXISTS(SELECT 1 FROM booking_events e WHERE e.booking_id=b.id AND e.to_status='rejected' AND e.actor_id=l.host_id AND DATE(e.created_at+INTERVAL v_vn_shift SECOND)<=b.check_in))) decided_requests,
            (SELECT COUNT(*) FROM bookings b JOIN listings l ON l.id=b.listing_id
                WHERE l.host_id=u.id AND b.created_at>=NOW(6)-INTERVAL v_window_months MONTH
                    AND ((b.status='rejected' AND EXISTS(SELECT 1 FROM booking_events e WHERE e.booking_id=b.id AND e.to_status='rejected' AND e.actor_id=l.host_id AND DATE(e.created_at+INTERVAL v_vn_shift SECOND)<=b.check_in))
                        OR (b.status='cancelled' AND EXISTS(SELECT 1 FROM booking_cancellations c WHERE c.booking_id=b.id AND c.cancelled_by=l.host_id)))) host_side_cancellations
        FROM users u WHERE FIND_IN_SET(u.id,p_user_ids)>0 AND u.status='active' AND u.deleted_at IS NULL
    ) m ORDER BY m.user_id;
END$$
DELIMITER ;

-- 010_models.sql
DELIMITER $$
CREATE OR REPLACE PROCEDURE `NTK_sp_user_soft_delete`(IN p_1 bigint unsigned)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_listing BIGINT UNSIGNED;
    DECLARE v_count INT DEFAULT 0;
    DECLARE v_stays INT DEFAULT 0;
    DECLARE v_affected INT DEFAULT 0;
    DECLARE v_message VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_user_soft_delete; RELEASE SAVEPOINT h2h_user_soft_delete; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_user_soft_delete; END IF;
    -- Lock order listing -> booking, like NMT_sp_booking_transition, so a confirm running now is either seen here or refused afterwards.
    SELECT COUNT(*) INTO v_count FROM listings WHERE host_id=p_1 FOR UPDATE;
    SELECT COUNT(*) INTO v_count FROM listings WHERE id IN (SELECT listing_id FROM bookings WHERE guest_id=p_1 AND status IN ('pending','confirmed')) FOR UPDATE;
    -- Same rule as an Admin take-down: an unfinished confirmed stay needs a person to sort out the money.
    SELECT COUNT(*) INTO v_stays FROM bookings WHERE guest_id=p_1 AND status='confirmed' AND check_out>DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR) FOR UPDATE;
    SELECT COUNT(*) INTO v_count FROM bookings b JOIN listings l ON l.id=b.listing_id WHERE l.host_id=p_1 AND b.status='confirmed' AND b.check_out>DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR) FOR UPDATE;
    SET v_stays=v_stays+v_count;
    IF v_stays>0 THEN
        SET v_message=CONCAT('Không thể xóa tài khoản: còn ',v_stays,' booking đã xác nhận chưa kết thúc (với tư cách khách hoặc Host). Hãy liên hệ các bên để xử lý trước.');
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT=v_message;
    END IF;
    -- Requests involving this account cannot be honoured any more. No actor: the Admin who deleted the account is in the audit log of the same transaction.
    SELECT MIN(id) INTO v_listing FROM listings WHERE host_id=p_1;
    WHILE v_listing IS NOT NULL DO
        CALL LTP_sp_listing_reject_pending(v_listing,NULL,'Tài khoản Host đã bị xóa nên yêu cầu bị từ chối tự động.','tài khoản Host đã bị xóa',v_count);
        SELECT MIN(id) INTO v_listing FROM listings WHERE host_id=p_1 AND id>v_listing;
    END WHILE;
    INSERT INTO booking_events(booking_id,actor_id,from_status,to_status,reason,created_at)
        SELECT id,NULL,'pending','rejected','Tài khoản khách đã bị xóa nên yêu cầu bị từ chối tự động.',NOW(6) FROM bookings WHERE guest_id=p_1 AND status='pending';
    INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
        SELECT l.host_id,b.id,'in_app','booking_rejected','Cập nhật booking',CONCAT('Yêu cầu đặt chỗ #',b.id,' cho “',l.title,'” đã bị từ chối vì tài khoản khách đã bị xóa.'),'sent',1,NOW(6)
        FROM bookings b JOIN listings l ON l.id=b.listing_id WHERE b.guest_id=p_1 AND b.status='pending';
    UPDATE bookings SET status='rejected',updated_at=NOW(6) WHERE guest_id=p_1 AND status='pending';
    UPDATE users SET status='deleted',deleted_at=NOW(6),updated_at=NOW(6) WHERE id=p_1;
    SET v_affected=ROW_COUNT();
    SELECT v_affected __affected,LAST_INSERT_ID() __insert_id;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_user_soft_delete; END IF;
END$$
DELIMITER ;

