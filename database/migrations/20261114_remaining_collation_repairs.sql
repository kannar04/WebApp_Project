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

-- Recreate the affected routines with explicit parameter and local variable collations.
DELIMITER $$
CREATE OR REPLACE PROCEDURE NTK_sp_booking_email_finish(IN p_id BIGINT UNSIGNED,IN p_sent BOOL,IN p_terminal BOOL,IN p_reason VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    UPDATE notifications SET delivery_status=IF(p_sent,'sent','failed'),sent_at=IF(p_sent,NOW(6),NULL),
        attempt_count=IF(p_terminal,5,attempt_count),body=IF(p_sent,body,CONCAT('Delivery note: ',LEFT(p_reason,40)))
        WHERE id=p_id AND channel='email' AND event_type='booking_confirmed' AND delivery_status<>'sent';
END$$

CREATE OR REPLACE PROCEDURE DST_sp_listing_catalog_choices(IN p_kind VARCHAR(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_listing BIGINT UNSIGNED, IN p_host BIGINT UNSIGNED)
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
CREATE OR REPLACE PROCEDURE NMT_sp_focused_listing_search(
    IN p_location VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,IN p_guests INT,IN p_type BIGINT UNSIGNED,IN p_min DECIMAL(12,2),IN p_max DECIMAL(12,2),IN p_bedrooms INT,
    IN p_start DATE,IN p_end DATE,IN p_sort VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,IN p_limit INT,IN p_offset INT, IN p_amenities LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_city VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_bounds LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
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
CREATE OR REPLACE PROCEDURE LTP_sp_booking_refund_preview(IN p_booking BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
BEGIN
    DECLARE v_status VARCHAR(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_start DATE;
    DECLARE v_total DECIMAL(12,2);
    DECLARE v_fee DECIMAL(12,2);
    DECLARE v_policy LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_percent DECIMAL(5,2) DEFAULT 0;
    DECLARE v_base DECIMAL(12,2) DEFAULT 0;
    DECLARE v_default_zone TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_default_time TIME;
    DECLARE v_payment VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
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

CREATE OR REPLACE PROCEDURE NMT_sp_direct_send(IN p_chat BIGINT UNSIGNED,IN p_actor BIGINT UNSIGNED,IN p_body TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
 DECLARE v_id BIGINT UNSIGNED;
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
 START TRANSACTION;
 SELECT id INTO v_id FROM direct_conversations WHERE id=p_chat AND p_actor IN(member_a,member_b) FOR UPDATE;
 IF v_id IS NULL OR EXISTS(SELECT 1 FROM direct_conversations c JOIN users u ON u.id IN(c.member_a,c.member_b) WHERE c.id=p_chat AND (u.status<>'active' OR u.deleted_at IS NOT NULL)) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không thể gửi tin nhắn tới cuộc trò chuyện này.';
 END IF;
 IF CHAR_LENGTH(TRIM(p_body))<1 OR CHAR_LENGTH(p_body)>2000 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tin nhắn cần từ 1 đến 2000 ký tự.'; END IF;
 IF EXISTS(SELECT 1 FROM direct_messages WHERE sender_id=p_actor AND conversation_id=p_chat AND created_at>NOW(6)-INTERVAL 2 SECOND) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Vui lòng chờ một chút trước khi gửi tiếp.';
 END IF;
 INSERT INTO direct_messages(conversation_id,sender_id,body) VALUES(p_chat,p_actor,p_body);
 COMMIT;
END$$

CREATE OR REPLACE PROCEDURE NMT_sp_admin_host_request_decide(IN p_admin BIGINT UNSIGNED,IN p_user BIGINT UNSIGNED,IN p_decision VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,IN p_reason VARCHAR(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,IN p_allow_debug BOOLEAN)
BEGIN
 DECLARE v_user BIGINT UNSIGNED;
 DECLARE v_debug BOOLEAN;
 DECLARE v_own BOOLEAN DEFAULT FALSE;
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN IF @@in_transaction THEN IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT host_decide; RELEASE SAVEPOINT host_decide; END IF; END IF; RESIGNAL; END;
 SET v_own=(@@in_transaction=0);
 IF v_own THEN START TRANSACTION; ELSE SAVEPOINT host_decide; END IF;
 CALL NTK_sp_admin_assert_role(p_admin);
 IF p_admin=p_user THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='You cannot review your own host request.'; END IF;
 IF p_decision NOT IN ('approved','rejected') OR p_decision IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid decision.'; END IF;
 IF p_decision='rejected' AND TRIM(COALESCE(p_reason,''))='' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A rejection reason is required.'; END IF;
 SELECT id INTO v_user FROM users WHERE id=p_user AND status='active' AND deleted_at IS NULL FOR UPDATE;
 IF v_user IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Account unavailable.'; END IF;
 IF NOT EXISTS(SELECT 1 FROM host_requests WHERE user_id=p_user AND status='pending') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Request is no longer pending.'; END IF;
 SELECT is_debug INTO v_debug FROM host_requests WHERE user_id=p_user FOR UPDATE;
 IF p_decision='approved' THEN
 IF COALESCE((SELECT action FROM admin_audit_logs WHERE entity_type='user' AND entity_id=p_user AND action IN ('remove_host_role','grant_host_role') ORDER BY id DESC LIMIT 1),'')='remove_host_role' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Host role was revoked. Restore access through user management first.'; END IF;
 IF NOT EXISTS(SELECT 1 FROM host_requests r JOIN users u ON u.id=r.user_id WHERE r.user_id=p_user AND r.email=u.email AND r.phone=u.phone) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Profile changed. Reject this request and ask the user to reapply.'; END IF;
 IF v_debug AND NOT p_allow_debug THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Debug requests can only be approved locally with debug verification enabled.'; END IF;
 IF NOT v_debug AND NOT EXISTS(SELECT 1 FROM users u JOIN contact_verifications c ON c.user_id=u.id WHERE u.id=p_user AND c.email=u.email AND c.email_verified_at IS NOT NULL AND c.verified_phone=u.phone AND c.phone_verified_at IS NOT NULL AND EXISTS(SELECT 1 FROM identity_verifications v WHERE v.user_id=u.id AND v.status='approved' AND REGEXP_REPLACE(u.phone,'[[:space:].()-]','') IN (v.phone,CONCAT('+84',SUBSTRING(v.phone,2)),CONCAT('84',SUBSTRING(v.phone,2))))) THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Current contact and identity verification is required.'; END IF;
 END IF;
 UPDATE host_requests SET status=p_decision,reason=TRIM(COALESCE(p_reason,'')),reviewed_by=p_admin,reviewed_at=NOW(6) WHERE user_id=p_user;
 IF p_decision='approved' THEN
 INSERT IGNORE INTO user_roles(user_id,role,granted_at) VALUES(p_user,'host',NOW(6));
 END IF;
 INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at) VALUES(p_admin,IF(p_decision='approved','grant_host_role','reject_host_request'),'user',p_user,JSON_OBJECT('reason',p_reason,'debug',v_debug),NOW(6));
 IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT host_decide; END IF;
END$$
DELIMITER ;

DELIMITER $$
CREATE OR REPLACE PROCEDURE NMT_sp_email_challenge_get(IN p_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin,IN p_actor BIGINT UNSIGNED)
BEGIN
 SELECT email,full_name,password_hash,phone FROM email_challenges
 WHERE id=p_id AND (user_id <=> p_actor) AND created_at>NOW()-INTERVAL 1 DAY;
END$$
CREATE OR REPLACE PROCEDURE NMT_sp_contact_status(IN p_user BIGINT UNSIGNED)
BEGIN
 SELECT u.phone,u.email,c.email_verified_at,
 (c.email=u.email AND c.email_verified_at IS NOT NULL) AS email_verified,
 (c.email=u.email AND c.verified_phone=u.phone AND c.phone_verified_at IS NOT NULL) AS phone_verified
 FROM users u LEFT JOIN contact_verifications c ON c.user_id=u.id
 WHERE u.id=p_user AND u.status='active' AND u.deleted_at IS NULL;
END$$
CREATE OR REPLACE PROCEDURE NMT_sp_email_challenge_issue(IN p_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin,IN p_user BIGINT UNSIGNED,IN p_email VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,IN p_name VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,IN p_password VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,IN p_phone VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,IN p_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin)
BEGIN
 -- Serializes requests for the same email, including requests from different sessions.
 DECLARE v_locked INT DEFAULT 0;
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN IF v_locked=1 THEN DO RELEASE_LOCK(CONCAT('h2h-email-',LEFT(SHA2(LOWER(p_email),256),48))); END IF; RESIGNAL; END;
 SELECT GET_LOCK(CONCAT('h2h-email-',LEFT(SHA2(LOWER(p_email),256),48)),3) INTO v_locked;
 IF COALESCE(v_locked,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Vui lòng thử lại sau.'; END IF;
 IF EXISTS(SELECT 1 FROM email_challenges WHERE email=p_email AND created_at>NOW()-INTERVAL 60 SECOND) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Vui lòng chờ 60 giây trước khi gửi lại mã email.';
 END IF;
 IF p_user IS NOT NULL AND NOT EXISTS(SELECT 1 FROM users WHERE id=p_user AND email=p_email AND status='active' AND deleted_at IS NULL) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tài khoản không hợp lệ.';
 END IF;
 IF p_user IS NULL AND EXISTS(SELECT 1 FROM users WHERE email=p_email) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Email đã được sử dụng. Vui lòng đăng nhập hoặc khôi phục mật khẩu.';
 END IF;
 DELETE FROM email_challenges WHERE email=p_email OR expires_at<NOW()-INTERVAL 1 DAY;
 INSERT INTO email_challenges(id,user_id,email,full_name,password_hash,phone,code_hash,expires_at)
 VALUES(p_id,p_user,LOWER(p_email),p_name,p_password,NULLIF(p_phone,''),p_hash,NOW()+INTERVAL 10 MINUTE);
 DO RELEASE_LOCK(CONCAT('h2h-email-',LEFT(SHA2(LOWER(p_email),256),48)));
END$$
CREATE OR REPLACE PROCEDURE NMT_sp_email_challenge_consume(IN p_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin,IN p_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin,IN p_actor BIGINT UNSIGNED)
proc: BEGIN
 DECLARE v_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin; DECLARE v_user BIGINT UNSIGNED; DECLARE v_email VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; DECLARE v_name VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 DECLARE v_password VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; DECLARE v_phone VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; DECLARE v_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin; DECLARE v_attempts INT; DECLARE v_expires DATETIME(6);
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
 START TRANSACTION;
 SELECT id,user_id,email,full_name,password_hash,phone,code_hash,attempts,expires_at
 INTO v_id,v_user,v_email,v_name,v_password,v_phone,v_hash,v_attempts,v_expires
 FROM email_challenges WHERE id=p_id FOR UPDATE;
 IF v_id IS NULL OR NOT (v_user <=> p_actor) OR v_expires<NOW() OR v_attempts>=5 THEN
  COMMIT; SELECT 0 AS user_id; LEAVE proc;
 END IF;
 UPDATE email_challenges SET attempts=attempts+1 WHERE id=p_id;
 IF BINARY v_hash<>BINARY p_hash THEN COMMIT; SELECT 0 AS user_id; LEAVE proc; END IF;
 IF v_user IS NULL THEN
  CALL NMT_sp_user_insert(v_email,v_password,v_name,v_phone);
  SELECT id INTO v_user FROM users WHERE email=v_email;
 ELSEIF NOT EXISTS(SELECT 1 FROM users WHERE id=v_user AND email=v_email AND status='active' AND deleted_at IS NULL) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tài khoản không hợp lệ.';
 END IF;
 INSERT INTO contact_verifications(user_id,email,email_verified_at) VALUES(v_user,v_email,NOW(6))
 ON DUPLICATE KEY UPDATE email=VALUES(email),email_verified_at=VALUES(email_verified_at);
 DELETE FROM email_challenges WHERE id=p_id;
 COMMIT;
 SELECT v_user AS user_id;
END$$
CREATE OR REPLACE PROCEDURE NMT_sp_phone_verify(IN p_user BIGINT UNSIGNED,IN p_phone VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
 UPDATE contact_verifications c JOIN users u ON u.id=c.user_id
 SET c.verified_phone=p_phone,c.phone_verified_at=NOW(6)
 WHERE c.user_id=p_user AND c.email=u.email AND u.phone=p_phone AND u.status='active' AND u.deleted_at IS NULL;
 IF ROW_COUNT()=0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Thông tin đã thay đổi. Hãy lưu hồ sơ và xác minh lại.'; END IF;
END$$
DELIMITER ;
