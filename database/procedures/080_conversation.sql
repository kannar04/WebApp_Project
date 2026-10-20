-- Nice-to-have conversation: booking messages, check-in reminders. MariaDB 10.4 / XAMPP.
-- Every write owns a transaction only if none is active; otherwise it uses a savepoint.
-- Messaging rule shared by sp_booking_message_state and sp_booking_message_send:
-- pending/confirmed bookings are open; completed bookings stay open until check-out + 14 days (Vietnam date);
-- rejected/cancelled bookings are read-only.
DELIMITER $$

CREATE OR REPLACE PROCEDURE sp_booking_message_assert(IN p_booking BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
BEGIN
    -- Participants are the booking Guest and the listing Host (who must still hold the host role). Admins use the admin routines.
    IF NOT EXISTS(SELECT 1 FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN users u ON u.id=p_actor
        WHERE b.id=p_booking AND u.status='active' AND u.deleted_at IS NULL
        AND (b.guest_id=p_actor OR (l.host_id=p_actor AND EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_actor AND role='host')))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bạn không tham gia cuộc trò chuyện của booking này.';
    END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_booking_message_state(IN p_booking BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
BEGIN
    DECLARE v_today DATE;
    SET v_today=DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR);
    CALL sp_booking_message_assert(p_booking,p_actor);
    SELECT IF(b.guest_id=p_actor,'guest','host') my_role,
        IF(b.guest_id=p_actor,l.host_id,b.guest_id) other_id,
        IF(b.guest_id=p_actor,h.full_name,g.full_name) other_name,
        b.status,b.check_out+INTERVAL 14 DAY closes_on,
        IF(b.status IN ('pending','confirmed') OR (b.status='completed' AND v_today<=b.check_out+INTERVAL 14 DAY),1,0) can_send
    FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN users g ON g.id=b.guest_id JOIN users h ON h.id=l.host_id
    WHERE b.id=p_booking;
END$$

CREATE OR REPLACE PROCEDURE sp_booking_message_send(IN p_booking BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED,
    IN p_body TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_guest BIGINT UNSIGNED;
    DECLARE v_host BIGINT UNSIGNED;
    DECLARE v_recipient BIGINT UNSIGNED;
    DECLARE v_id BIGINT UNSIGNED;
    DECLARE v_unread INT DEFAULT 0;
    DECLARE v_status VARCHAR(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_title VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_sender VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_body TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_check_out DATE;
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
    IF v_status IN ('rejected','cancelled') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Booking đã bị từ chối hoặc hủy nên cuộc trò chuyện chỉ còn để xem.';
    END IF;
    IF v_status='completed' AND DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR)>v_check_out+INTERVAL 14 DAY THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Cuộc trò chuyện đã đóng sau 14 ngày kể từ ngày trả phòng.';
    END IF;
    IF v_status NOT IN ('pending','confirmed','completed') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Booking ở trạng thái này không nhận tin nhắn.';
    END IF;
    SET v_body=REGEXP_REPLACE(COALESCE(p_body,''),'^[[:space:]]+|[[:space:]]+$','');
    IF CHAR_LENGTH(v_body)<1 OR CHAR_LENGTH(v_body)>2000 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tin nhắn cần từ 1 đến 2000 ký tự.';
    END IF;
    SET v_recipient=IF(p_actor=v_guest,v_host,v_guest);
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

CREATE OR REPLACE PROCEDURE sp_booking_messages_thread(IN p_booking BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_message_thread; RELEASE SAVEPOINT h2h_message_thread; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_message_thread; END IF;
    CALL sp_booking_message_assert(p_booking,p_actor);
    -- Opening the thread marks the other participant's messages and this booking's message notifications as read.
    UPDATE booking_messages SET read_at=NOW(6) WHERE booking_id=p_booking AND recipient_id=p_actor AND read_at IS NULL;
    UPDATE notifications SET read_at=NOW(6)
        WHERE user_id=p_actor AND booking_id=p_booking AND channel='in_app' AND event_type='booking_message' AND read_at IS NULL;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_message_thread; END IF;
    SELECT t.* FROM (
        SELECT m.id,m.sender_id,u.full_name sender_name,IF(m.sender_id=p_actor,1,0) is_mine,
            IF(m.hidden_at IS NULL,m.body,NULL) body,IF(m.hidden_at IS NULL,0,1) is_hidden,m.created_at,
            IF(m.sender_id=p_actor,m.read_at,NULL) read_at
        FROM booking_messages m JOIN users u ON u.id=m.sender_id
        WHERE m.booking_id=p_booking ORDER BY m.created_at DESC,m.id DESC LIMIT 500
    ) t ORDER BY t.created_at,t.id;
END$$

CREATE OR REPLACE PROCEDURE sp_message_inbox(IN p_actor BIGINT UNSIGNED)
BEGIN
    SELECT b.id booking_id,b.status,b.check_in,b.check_out,l.title,
        IF(b.guest_id=p_actor,'guest','host') my_role,
        IF(b.guest_id=p_actor,h.full_name,g.full_name) other_name,
        IF(m.hidden_at IS NULL,LEFT(m.body,140),NULL) last_snippet,
        IF(m.hidden_at IS NULL AND CHAR_LENGTH(m.body)>140,1,0) last_truncated,
        IF(m.hidden_at IS NULL,0,1) last_hidden,IF(m.sender_id=p_actor,1,0) last_mine,m.created_at last_at,
        (SELECT COUNT(*) FROM booking_messages x WHERE x.booking_id=b.id AND x.recipient_id=p_actor AND x.read_at IS NULL AND x.hidden_at IS NULL) unread_count
    FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN users g ON g.id=b.guest_id JOIN users h ON h.id=l.host_id
    JOIN booking_messages m ON m.id=(SELECT y.id FROM booking_messages y WHERE y.booking_id=b.id ORDER BY y.created_at DESC,y.id DESC LIMIT 1)
    WHERE EXISTS(SELECT 1 FROM users WHERE id=p_actor AND status='active' AND deleted_at IS NULL)
        AND (b.guest_id=p_actor OR (l.host_id=p_actor AND EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_actor AND role='host')))
    ORDER BY m.created_at DESC,m.id DESC LIMIT 200;
END$$

CREATE OR REPLACE PROCEDURE sp_message_unread_count(IN p_actor BIGINT UNSIGNED)
BEGIN
    -- Navbar badge: one indexed count (recipient_id, read_at) per page load.
    SELECT COUNT(*) unread_count FROM booking_messages WHERE recipient_id=p_actor AND read_at IS NULL AND hidden_at IS NULL;
END$$

CREATE OR REPLACE PROCEDURE sp_admin_messages_search(IN p_actor BIGINT UNSIGNED,
    IN p_keyword VARCHAR(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_booking BIGINT UNSIGNED,
    IN p_user VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_keyword VARCHAR(400) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_user VARCHAR(600) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CALL sp_admin_assert_role(p_actor);
    -- User input is matched literally: LIKE wildcards are escaped.
    SET v_keyword=REPLACE(REPLACE(REPLACE(TRIM(COALESCE(p_keyword,'')),'\\','\\\\'),'%','\\%'),'_','\\_');
    SET v_user=REPLACE(REPLACE(REPLACE(TRIM(COALESCE(p_user,'')),'\\','\\\\'),'%','\\%'),'_','\\_');
    SELECT m.id,m.booking_id,l.title,b.status booking_status,m.sender_id,s.full_name sender_name,s.email sender_email,
        r.full_name recipient_name,r.email recipient_email,m.body,m.created_at,m.read_at,m.hidden_at,m.hidden_reason,a.full_name hidden_by_name
    FROM booking_messages m JOIN bookings b ON b.id=m.booking_id JOIN listings l ON l.id=b.listing_id
        JOIN users s ON s.id=m.sender_id JOIN users r ON r.id=m.recipient_id LEFT JOIN users a ON a.id=m.hidden_by
    WHERE (p_booking IS NULL OR m.booking_id=p_booking)
        AND (v_keyword='' OR m.body LIKE CONCAT('%',v_keyword,'%'))
        AND (v_user='' OR s.full_name LIKE CONCAT('%',v_user,'%') OR s.email LIKE CONCAT('%',v_user,'%')
            OR r.full_name LIKE CONCAT('%',v_user,'%') OR r.email LIKE CONCAT('%',v_user,'%'))
    ORDER BY m.created_at DESC,m.id DESC LIMIT 200;
END$$

CREATE OR REPLACE PROCEDURE sp_admin_message_thread(IN p_actor BIGINT UNSIGNED, IN p_booking BIGINT UNSIGNED)
BEGIN
    CALL sp_admin_assert_role(p_actor);
    -- Admin reading never changes read receipts.
    SELECT t.* FROM (
        SELECT m.id,m.booking_id,l.title,b.status booking_status,g.full_name guest_name,h.full_name host_name,
            m.sender_id,s.full_name sender_name,IF(m.sender_id=b.guest_id,'guest','host') sender_role,
            m.body,m.created_at,m.read_at,m.hidden_at,m.hidden_reason,a.full_name hidden_by_name
        FROM booking_messages m JOIN bookings b ON b.id=m.booking_id JOIN listings l ON l.id=b.listing_id
            JOIN users g ON g.id=b.guest_id JOIN users h ON h.id=l.host_id JOIN users s ON s.id=m.sender_id
            LEFT JOIN users a ON a.id=m.hidden_by
        WHERE m.booking_id=p_booking ORDER BY m.created_at DESC,m.id DESC LIMIT 500
    ) t ORDER BY t.created_at,t.id;
END$$

CREATE OR REPLACE PROCEDURE sp_admin_message_moderate(IN p_id BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED,
    IN p_action VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_reason TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_booking BIGINT UNSIGNED;
    DECLARE v_hidden DATETIME(6);
    DECLARE v_reason TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_message_moderate; RELEASE SAVEPOINT h2h_message_moderate; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_message_moderate; END IF;
    CALL sp_admin_assert_role(p_actor);
    IF p_action IS NULL OR p_action NOT IN ('hide','unhide') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Thao tác kiểm duyệt tin nhắn không hợp lệ.';
    END IF;
    SET v_reason=REGEXP_REPLACE(COALESCE(p_reason,''),'^[[:space:]]+|[[:space:]]+$','');
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
    CALL sp_admin_audit_add(p_actor,CONCAT(p_action,'_message'),'booking_message',p_id,JSON_OBJECT('booking_id',v_booking,'reason',v_reason));
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_message_moderate; END IF;
    SELECT v_booking booking_id;
END$$

CREATE OR REPLACE PROCEDURE sp_checkin_reminders_run(IN p_days INT, IN p_user BIGINT UNSIGNED)
BEGIN
    -- p_user NULL: every participant (CLI job). Otherwise only reminders addressed to p_user (page-load trigger).
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_done BOOL DEFAULT FALSE;
    DECLARE v_created INT DEFAULT 0;
    DECLARE v_today DATE;
    DECLARE v_booking BIGINT UNSIGNED;
    DECLARE v_user BIGINT UNSIGNED;
    DECLARE v_kind VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    DECLARE v_body TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    -- Reminders quote the listing's current check-in time; only the refund deadline keeps the booked one.
    DECLARE v_candidates CURSOR FOR
        SELECT b.id,b.guest_id,'guest_check_in',
            CONCAT('Nhắc lịch: bạn nhận phòng “',l.title,'” vào ',DATE_FORMAT(b.check_in,'%d/%m/%Y'),' từ ',
                TIME_FORMAT(l.check_in_time,'%H:%i'),
                '. Mở booking #',b.id,' để xem địa chỉ và liên hệ Host.')
        FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN users g ON g.id=b.guest_id
        WHERE b.status='confirmed' AND b.check_in BETWEEN v_today AND v_today+INTERVAL p_days DAY
            AND g.status='active' AND g.deleted_at IS NULL AND (p_user IS NULL OR b.guest_id=p_user)
        UNION ALL
        SELECT b.id,l.host_id,'host_check_in',
            CONCAT('Khách ',g.full_name,' sẽ nhận phòng “',l.title,'” vào ',DATE_FORMAT(b.check_in,'%d/%m/%Y'),' từ ',
                TIME_FORMAT(l.check_in_time,'%H:%i'),
                ' (booking #',b.id,', ',b.guest_count,' khách).')
        FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN users g ON g.id=b.guest_id JOIN users h ON h.id=l.host_id
        WHERE b.status='confirmed' AND b.check_in BETWEEN v_today AND v_today+INTERVAL p_days DAY
            AND h.status='active' AND h.deleted_at IS NULL AND EXISTS(SELECT 1 FROM user_roles WHERE user_id=h.id AND role='host')
            AND (p_user IS NULL OR l.host_id=p_user);
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done=TRUE;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_checkin_reminders; RELEASE SAVEPOINT h2h_checkin_reminders; END IF;
        END IF;
        RESIGNAL;
    END;
    IF p_days IS NULL OR p_days<0 OR p_days>30 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Số ngày nhắc lịch phải từ 0 đến 30.';
    END IF;
    SET v_today=DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR);
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_checkin_reminders; END IF;
    OPEN v_candidates;
    candidate_loop: LOOP
        FETCH v_candidates INTO v_booking,v_user,v_kind,v_body;
        IF v_done THEN LEAVE candidate_loop; END IF;
        -- The primary key is the idempotency guard: a concurrent run blocks on the same key, then ignores it.
        -- Selecting the booking again re-checks the confirmed status under a shared lock at insert time.
        INSERT IGNORE INTO booking_reminders(booking_id,user_id,kind,notification_id,created_at)
            SELECT id,v_user,v_kind,NULL,NOW(6) FROM bookings WHERE id=v_booking AND status='confirmed';
        IF ROW_COUNT()=1 THEN
            INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
                VALUES(v_user,v_booking,'in_app','checkin_reminder','Nhắc lịch nhận phòng',v_body,'sent',1,NOW(6));
            UPDATE booking_reminders SET notification_id=LAST_INSERT_ID() WHERE booking_id=v_booking AND user_id=v_user AND kind=v_kind;
            SET v_created=v_created+1;
        END IF;
    END LOOP;
    CLOSE v_candidates;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_checkin_reminders; END IF;
    SELECT v_created created_count;
END$$

DELIMITER ;
