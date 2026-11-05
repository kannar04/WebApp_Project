-- Additive core workflows for MariaDB 10.4. No table/data reset or legacy routine DROP.
DELIMITER $$

CREATE OR REPLACE PROCEDURE sp_quality_assert_host(IN p_listing BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
BEGIN
    IF NOT EXISTS(SELECT 1 FROM listings l JOIN users u ON u.id=l.host_id JOIN user_roles r ON r.user_id=u.id
        WHERE l.id=p_listing AND l.host_id=p_actor AND l.deleted_at IS NULL AND u.status='active' AND u.deleted_at IS NULL AND r.role='host') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không có quyền quản lý chỗ ở.';
    END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_host_listing_delete(IN p_listing BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE v_conflicts INT;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_host_delete; RELEASE SAVEPOINT h2h_host_delete; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_host_delete; END IF;
    SELECT id INTO v_lock FROM listings WHERE id=p_listing FOR UPDATE;
    CALL sp_quality_assert_host(p_listing,p_actor);
    SELECT COUNT(*) INTO v_conflicts FROM bookings WHERE listing_id=p_listing AND status IN ('pending','confirmed') FOR UPDATE;
    IF v_conflicts>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Hãy xử lý booking đang chờ hoặc đã xác nhận trước khi xóa. Có thể ẩn tin để ngừng nhận yêu cầu mới.'; END IF;
    UPDATE listings SET deleted_at=NOW(6),is_visible=0,updated_at=NOW(6) WHERE id=p_listing;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_host_delete; END IF;
END$$

-- Rejects a listing's pending requests with the same event and Guest notification a manual rejection leaves. The caller holds the transaction and the listing lock.
CREATE OR REPLACE PROCEDURE sp_listing_reject_pending(IN p_listing BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED,
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

CREATE OR REPLACE PROCEDURE sp_host_photos_manage(IN p_listing BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED,
    IN p_ids LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_expected LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE v_i INT DEFAULT 0;
    DECLARE v_id BIGINT UNSIGNED;
    DECLARE v_offset INT;
    DECLARE v_seen LONGTEXT DEFAULT '[]';
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_photos_manage; RELEASE SAVEPOINT h2h_photos_manage; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_photos_manage; END IF;
    SELECT id INTO v_lock FROM listings WHERE id=p_listing FOR UPDATE;
    CALL sp_quality_assert_host(p_listing,p_actor);
    IF p_expected IS NULL OR NOT JSON_VALID(p_expected) OR JSON_TYPE(p_expected)<>'ARRAY'
        OR JSON_LENGTH(p_expected)<>(SELECT COUNT(*) FROM listing_photos WHERE listing_id=p_listing)
        OR EXISTS(SELECT 1 FROM listing_photos WHERE listing_id=p_listing AND NOT JSON_CONTAINS(p_expected,CAST(id AS CHAR))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ảnh đã thay đổi đồng thời. Hãy tải lại trang trước khi lưu.';
    END IF;
    IF p_ids IS NULL OR NOT JSON_VALID(p_ids) OR JSON_TYPE(p_ids)<>'ARRAY' OR JSON_LENGTH(p_ids)>100 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Danh sách ảnh không hợp lệ.';
    END IF;
    WHILE v_i<JSON_LENGTH(p_ids) DO
        IF JSON_TYPE(JSON_EXTRACT(p_ids,CONCAT('$[',v_i,']')))<>'INTEGER' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='ID ảnh không hợp lệ.'; END IF;
        SET v_id=JSON_EXTRACT(p_ids,CONCAT('$[',v_i,']'));
        IF JSON_CONTAINS(v_seen,CAST(v_id AS CHAR)) OR NOT EXISTS(SELECT 1 FROM listing_photos WHERE id=v_id AND listing_id=p_listing) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ảnh không thuộc chỗ ở này hoặc bị lặp.';
        END IF;
        SET v_seen=JSON_ARRAY_APPEND(v_seen,'$',v_id);
        SET v_i=v_i+1;
    END WHILE;
    -- Remove references only. Files may be shared and are never unlinked by this workflow.
    DELETE FROM listing_photos WHERE listing_id=p_listing AND NOT JSON_CONTAINS(p_ids,CAST(id AS CHAR));
    SELECT COALESCE(MAX(sort_order),0)+JSON_LENGTH(p_ids)+1 INTO v_offset FROM listing_photos WHERE listing_id=p_listing;
    UPDATE listing_photos SET sort_order=sort_order+v_offset WHERE listing_id=p_listing;
    SET v_i=0;
    WHILE v_i<JSON_LENGTH(p_ids) DO
        SET v_id=JSON_EXTRACT(p_ids,CONCAT('$[',v_i,']'));
        UPDATE listing_photos SET sort_order=v_i WHERE id=v_id AND listing_id=p_listing;
        SET v_i=v_i+1;
    END WHILE;
    UPDATE listings SET moderation_status='pending',updated_at=NOW(6) WHERE id=p_listing;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_photos_manage; END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_listing_calendar(IN p_listing BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED, IN p_start DATE, IN p_end DATE)
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

CREATE OR REPLACE PROCEDURE sp_quality_assert_booking(IN p_booking BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
BEGIN
    IF NOT EXISTS(SELECT 1 FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN users u ON u.id=p_actor
        WHERE b.id=p_booking AND u.status='active' AND u.deleted_at IS NULL
        AND (b.guest_id=p_actor OR (l.host_id=p_actor AND EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_actor AND role='host'))
            OR EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_actor AND role='admin'))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không có quyền xem booking.';
    END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_booking_detail(IN p_booking BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
BEGIN
    CALL sp_quality_assert_booking(p_booking,p_actor);
    -- current_*: the listing's check-in time now; the refund deadline keeps the time stored in policy_snapshot.
    SELECT b.*,l.title,l.address,l.city,l.host_id,u.full_name guest_name,h.full_name host_name,
        TIME_FORMAT(l.check_in_time,'%H:%i') current_check_in_time,l.time_zone current_time_zone,
        (l.moderation_status='approved' AND l.is_visible=1 AND l.deleted_at IS NULL AND h.status='active' AND h.deleted_at IS NULL) listing_public,
        CASE WHEN b.status IN ('confirmed','completed') OR EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_actor AND role='admin') THEN h.phone END host_phone,
        CASE WHEN b.status IN ('confirmed','completed') OR EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_actor AND role='admin') THEN u.phone END guest_phone
    FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN users u ON u.id=b.guest_id JOIN users h ON h.id=l.host_id WHERE b.id=p_booking;
END$$

CREATE OR REPLACE PROCEDURE sp_booking_detail_nights(IN p_booking BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
BEGIN
    CALL sp_quality_assert_booking(p_booking,p_actor);
    SELECT stay_date,nightly_amount FROM booking_nights WHERE booking_id=p_booking ORDER BY stay_date;
END$$

CREATE OR REPLACE PROCEDURE sp_booking_detail_events(IN p_booking BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
BEGIN
    CALL sp_quality_assert_booking(p_booking,p_actor);
    SELECT from_status,to_status,reason,created_at FROM booking_events WHERE booking_id=p_booking ORDER BY created_at,id;
END$$

-- A paid, confirmed booking's refund terms right now, shared by the cancel and its preview so the two cannot disagree.
-- The snapshot decides; one saved without a time zone or check-in time falls back on the listing's current values (p_default_*).
-- Money the Guest was told comes back stays owed in full, whatever the policy says, even after the listing or Host is restored.
CREATE OR REPLACE PROCEDURE sp_booking_refund_terms(IN p_booking BIGINT UNSIGNED, IN p_policy LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_start DATE, IN p_total DECIMAL(12,2), IN p_fee DECIMAL(12,2),
    IN p_default_time TIME, IN p_default_zone TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, OUT o_percent DECIMAL(5,2), OUT o_base DECIMAL(12,2))
terms: BEGIN
    DECLARE v_zone TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_arrival DATETIME;
    -- The promise is the Guest's own notification from sp_payment_vnpay_result or sp_admin_payment_transfer_decide; nothing deletes it.
    IF EXISTS(SELECT 1 FROM notifications WHERE booking_id=p_booking AND event_type='payment_paid' AND title='Khoản thanh toán sẽ được hoàn lại') THEN
        SET o_percent=100;
        SELECT amount_due INTO o_base FROM booking_payments WHERE booking_id=p_booking;
        LEAVE terms;
    END IF;
    SET v_zone=COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p_policy,'$.time_zone')),'null'),p_default_zone);
    -- XAMPP may not load named timezone tables. Vietnam has a fixed UTC+07 offset.
    SET v_zone=CASE v_zone WHEN 'Asia/Ho_Chi_Minh' THEN '+07:00' WHEN 'UTC' THEN '+00:00' ELSE v_zone END;
    SET v_arrival=CONVERT_TZ(CONCAT(p_start,' ',COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(p_policy,'$.check_in_time')),'null'),CAST(p_default_time AS CHAR))),v_zone,'+00:00');
    IF v_arrival IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chưa cấu hình múi giờ để tính hoàn tiền an toàn.'; END IF;
    SET o_percent=IF(TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),v_arrival)>=JSON_EXTRACT(p_policy,'$.cutoff_hours')*3600,
        JSON_EXTRACT(p_policy,'$.early_refund_pct'),JSON_EXTRACT(p_policy,'$.late_refund_pct'));
    SET o_base=p_total-IF(JSON_UNQUOTE(JSON_EXTRACT(p_policy,'$.refund_fees')) IN ('true','1'),0,p_fee);
END$$

CREATE OR REPLACE PROCEDURE sp_booking_refund_preview(IN p_booking BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
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
    CALL sp_quality_assert_booking(p_booking,p_actor);
    SELECT b.status,b.check_in,b.total_amount,b.fee_amount,b.policy_snapshot,l.check_in_time,l.time_zone INTO v_status,v_start,v_total,v_fee,v_policy,v_default_time,v_default_zone
        FROM bookings b JOIN listings l ON l.id=b.listing_id WHERE b.id=p_booking;
    SELECT status INTO v_payment FROM booking_payments WHERE booking_id=p_booking;
    IF v_status='cancelled' THEN
        SELECT refund_amount,calculation_details,reason,created_at FROM booking_cancellations WHERE booking_id=p_booking;
    ELSE
        IF COALESCE(v_payment,'unpaid') IN ('unpaid','refunded') THEN SET v_percent=0; SET v_base=0; -- Nothing held (not collected, or already refunded), nothing to refund.
        ELSEIF v_status='pending' THEN SET v_percent=100; SET v_base=v_total;
        ELSEIF v_status='confirmed' THEN CALL sp_booking_refund_terms(p_booking,v_policy,v_start,v_total,v_fee,v_default_time,v_default_zone,v_percent,v_base);
        END IF;
        SELECT v_percent refund_percent,ROUND(GREATEST(0,v_base)*v_percent/100,0) refund_amount;
    END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_admin_booking_search(IN p_actor BIGINT UNSIGNED, IN p_query VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_status VARCHAR(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_number BIGINT UNSIGNED DEFAULT NULL;
    CALL sp_admin_assert_role(p_actor);
    IF p_status NOT IN ('','pending','confirmed','rejected','cancelled','completed') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Trạng thái lọc không hợp lệ.'; END IF;
    -- The site prints a booking as #12, so searching #12 means exactly that booking; anything else stays a text search.
    IF p_query REGEXP '^#[0-9]{1,18}$' THEN SET v_number=SUBSTRING(p_query,2); END IF;
    SELECT b.*,l.title,u.full_name guest_name,h.full_name host_name
    FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN users u ON u.id=b.guest_id JOIN users h ON h.id=l.host_id
    WHERE (p_status='' OR b.status=p_status) AND (b.id=v_number OR v_number IS NULL AND (p_query='' OR CAST(b.id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci=p_query OR l.title LIKE CONCAT('%',p_query,'%') OR u.full_name LIKE CONCAT('%',p_query,'%') OR h.full_name LIKE CONCAT('%',p_query,'%')))
    ORDER BY b.created_at DESC,b.id DESC LIMIT 200;
END$$

CREATE OR REPLACE PROCEDURE sp_host_monthly_revenue(IN p_actor BIGINT UNSIGNED)
BEGIN
    IF NOT EXISTS(SELECT 1 FROM users u JOIN user_roles r ON r.user_id=u.id WHERE u.id=p_actor AND u.status='active' AND r.role='host') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không có quyền Host.'; END IF;
    -- Month of completed stay's check-out, not request/confirmation date. Refunded money is not revenue (see sp_booking_payment_refund).
    SELECT DATE_FORMAT(b.check_out,'%Y-%m') revenue_month,COUNT(*) completed_count,
        SUM(GREATEST(0,b.total_amount-CASE WHEN pay.status='refunded' THEN COALESCE((SELECT CAST(JSON_VALUE(a.change_summary,'$.amount') AS DECIMAL(12,2)) FROM admin_audit_logs a WHERE a.action='refund_payment' AND a.entity_type='booking' AND a.entity_id=b.id ORDER BY a.id DESC LIMIT 1),pay.amount_due) ELSE 0 END)) revenue
    FROM bookings b JOIN listings l ON l.id=b.listing_id LEFT JOIN booking_payments pay ON pay.booking_id=b.id WHERE l.host_id=p_actor AND b.status='completed'
    GROUP BY DATE_FORMAT(b.check_out,'%Y-%m') ORDER BY revenue_month DESC;
END$$

CREATE OR REPLACE PROCEDURE sp_listing_photos_for_actor(IN p_listing BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
BEGIN
    IF NOT EXISTS(SELECT 1 FROM listings l JOIN users u ON u.id=p_actor WHERE l.id=p_listing AND l.deleted_at IS NULL AND u.status='active'
        AND ((l.host_id=p_actor AND EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_actor AND role='host'))
            OR EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_actor AND role='admin'))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không có quyền xem ảnh quản lý.';
    END IF;
    SELECT id,image_url,alt_text,sort_order FROM listing_photos WHERE listing_id=p_listing ORDER BY sort_order,id;
END$$

CREATE OR REPLACE PROCEDURE sp_listing_admin_preview(IN p_listing BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
BEGIN
    CALL sp_admin_assert_role(p_actor);
    SELECT l.*,l.base_nightly_rate nightly_price,l.fee_amount cleaning_fee,l.smoking_allowed allows_smoking,l.pets_allowed allows_pets,l.parties_allowed allows_parties,
        pt.name property_type,cp.name cancellation_name,cp.description cancellation_description,h.full_name host_name,h.avatar_url host_avatar
    FROM listings l JOIN property_types pt ON pt.id=l.property_type_id JOIN cancellation_policies cp ON cp.id=l.cancellation_policy_id JOIN users h ON h.id=l.host_id
    WHERE l.id=p_listing AND l.deleted_at IS NULL;
END$$

CREATE OR REPLACE PROCEDURE sp_listing_amenities_for_admin(IN p_listing BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
BEGIN
    CALL sp_admin_assert_role(p_actor);
    SELECT a.id,a.name,a.icon_key FROM listing_amenities la JOIN amenities a ON a.id=la.amenity_id
    JOIN listings l ON l.id=la.listing_id WHERE l.id=p_listing AND l.deleted_at IS NULL ORDER BY a.name;
END$$

CREATE OR REPLACE PROCEDURE sp_admin_count_guests()
BEGIN
    SELECT COUNT(*) FROM user_roles r JOIN users u ON u.id=r.user_id WHERE r.role='guest' AND u.deleted_at IS NULL;
END$$

CREATE OR REPLACE PROCEDURE sp_notifications_list(IN p_actor BIGINT UNSIGNED)
BEGIN
    SELECT n.id,n.booking_id,n.event_type,n.title,n.body,n.read_at,n.created_at,
        -- Same rule as sp_quality_assert_booking, so the page offers a link only when it opens.
        EXISTS(SELECT 1 FROM bookings b JOIN listings l ON l.id=b.listing_id WHERE b.id=n.booking_id
            AND (b.guest_id=p_actor OR (l.host_id=p_actor AND EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_actor AND role='host'))
                OR EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_actor AND role='admin'))) can_open_booking
    FROM notifications n
    WHERE n.user_id=p_actor AND n.channel='in_app' AND EXISTS(SELECT 1 FROM users WHERE id=p_actor AND status='active' AND deleted_at IS NULL)
    ORDER BY n.created_at DESC,n.id DESC LIMIT 200;
END$$

CREATE OR REPLACE PROCEDURE sp_notification_read(IN p_id BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
BEGIN
    UPDATE notifications SET read_at=COALESCE(read_at,NOW(6)) WHERE id=p_id AND user_id=p_actor AND channel='in_app'
        AND EXISTS(SELECT 1 FROM users WHERE id=p_actor AND status='active' AND deleted_at IS NULL);
    IF ROW_COUNT()=0 AND NOT EXISTS(SELECT 1 FROM notifications WHERE id=p_id AND user_id=p_actor AND channel='in_app') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Thông báo không tồn tại.';
    END IF;
END$$

DELIMITER ;
