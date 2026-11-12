-- Reuse the existing email notification queue. No historical bookings are backfilled.
CREATE INDEX IF NOT EXISTS idx_notifications_email_queue ON notifications(channel,event_type,delivery_status,attempt_count,id);

DELIMITER $$
CREATE OR REPLACE TRIGGER h2h_booking_confirmation_email AFTER INSERT ON booking_events FOR EACH ROW
BEGIN
    IF NEW.to_status='confirmed' THEN
        INSERT INTO notifications(user_id,booking_id,booking_event_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
            SELECT u.id,b.id,NEW.id,'email','booking_confirmed','Home2Home: xác nhận đặt chỗ',CONCAT('Booking #',b.id,' đã được xác nhận.'),'pending',0,NOW(6)
            FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN users u ON u.id IN (b.guest_id,l.host_id)
            WHERE b.id=NEW.booking_id;
    END IF;
END$$
CREATE OR REPLACE PROCEDURE NMT_sp_booking_email_lock(IN p_acquire BOOL)
BEGIN
    IF p_acquire THEN SELECT GET_LOCK(CONCAT('h2h-booking-mail:',MD5(DATABASE())),0) acquired;
    ELSE SELECT RELEASE_LOCK(CONCAT('h2h-booking-mail:',MD5(DATABASE()))) released; END IF;
END$$
CREATE OR REPLACE PROCEDURE DST_sp_booking_email_for_booking(IN p_limit INT,IN p_booking_id BIGINT UNSIGNED)
BEGIN
    IF p_limit<1 OR p_limit>100 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid email batch size.'; END IF;
    SELECT n.id,n.booking_id,n.user_id,n.attempt_count,u.email,u.full_name recipient_name,u.status recipient_status,u.deleted_at recipient_deleted_at,
        b.status booking_status,b.guest_id,b.check_in,b.check_out,b.guest_count,b.total_amount,b.currency,
        l.host_id,l.title listing_title,l.address,l.city,guest.full_name guest_name,host.full_name host_name
    FROM notifications n JOIN users u ON u.id=n.user_id JOIN bookings b ON b.id=n.booking_id
        JOIN listings l ON l.id=b.listing_id JOIN users guest ON guest.id=b.guest_id JOIN users host ON host.id=l.host_id
    WHERE n.channel='email' AND n.event_type='booking_confirmed' AND n.delivery_status IN ('pending','failed') AND n.attempt_count<5
        AND n.user_id IN (b.guest_id,l.host_id)
        AND (p_booking_id IS NULL OR n.booking_id=p_booking_id)
    ORDER BY n.id LIMIT p_limit;
END$$
CREATE OR REPLACE PROCEDURE DST_sp_booking_email_pending(IN p_limit INT)
BEGIN
    CALL DST_sp_booking_email_for_booking(p_limit,NULL);
END$$
CREATE OR REPLACE PROCEDURE LTP_sp_booking_email_attempt(IN p_id BIGINT UNSIGNED)
BEGIN
    UPDATE notifications SET attempt_count=attempt_count+1
        WHERE id=p_id AND channel='email' AND event_type='booking_confirmed' AND delivery_status IN ('pending','failed') AND attempt_count<5;
    SELECT ROW_COUNT() claimed;
END$$
CREATE OR REPLACE PROCEDURE NTK_sp_booking_email_finish(IN p_id BIGINT UNSIGNED,IN p_sent BOOL,IN p_terminal BOOL,IN p_reason VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    UPDATE notifications SET delivery_status=IF(p_sent,'sent','failed'),sent_at=IF(p_sent,NOW(6),NULL),
        attempt_count=IF(p_terminal,5,attempt_count),body=IF(p_sent,body,CONCAT('Delivery note: ',LEFT(p_reason,40)))
        WHERE id=p_id AND channel='email' AND event_type='booking_confirmed' AND delivery_status<>'sent';
END$$
DELIMITER ;
