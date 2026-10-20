-- LOCAL TESTS ONLY (conversation feature). Installed only with the explicit test opt-in.
-- Every routine is restricted to listings titled 'Conversation fixture %' in city 'Audit'.
DELIMITER $$

CREATE OR REPLACE PROCEDURE sp_test_conversation_listing_approve(IN p_id BIGINT UNSIGNED)
BEGIN
    UPDATE listings SET moderation_status='approved',is_visible=1 WHERE id=p_id AND city='Audit' AND title LIKE 'Conversation fixture %';
    IF ROW_COUNT()=0 AND NOT EXISTS(SELECT 1 FROM listings WHERE id=p_id AND city='Audit' AND title LIKE 'Conversation fixture %') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Refusing to approve a non-fixture listing.';
    END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_test_conversation_complete(IN p_booking BIGINT UNSIGNED, IN p_days_since_checkout INT)
BEGIN
    -- Moves a fixture stay into the past so check_out = Vietnam today - p_days_since_checkout, then marks it completed.
    DECLARE v_shift INT;
    IF NOT EXISTS(SELECT 1 FROM bookings b JOIN listings l ON l.id=b.listing_id
        WHERE b.id=p_booking AND l.city='Audit' AND l.title LIKE 'Conversation fixture %' AND b.status IN ('confirmed','completed')) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Refusing to age a non-fixture booking.';
    END IF;
    SELECT DATEDIFF(check_out,DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR)-INTERVAL p_days_since_checkout DAY) INTO v_shift FROM bookings WHERE id=p_booking;
    IF v_shift>0 THEN
        UPDATE booking_nights SET stay_date=stay_date-INTERVAL v_shift DAY WHERE booking_id=p_booking ORDER BY stay_date;
    ELSE
        UPDATE booking_nights SET stay_date=stay_date-INTERVAL v_shift DAY WHERE booking_id=p_booking ORDER BY stay_date DESC;
    END IF;
    UPDATE bookings SET check_in=check_in-INTERVAL v_shift DAY,check_out=check_out-INTERVAL v_shift DAY,status='completed',updated_at=NOW(6) WHERE id=p_booking;
END$$

CREATE OR REPLACE PROCEDURE sp_test_conversation_inspect(IN p_booking BIGINT UNSIGNED, IN p_user BIGINT UNSIGNED)
BEGIN
    SELECT (SELECT COUNT(*) FROM booking_messages WHERE booking_id=p_booking) messages,
        (SELECT COUNT(*) FROM booking_messages WHERE booking_id=p_booking AND recipient_id=p_user AND read_at IS NULL) unread_messages,
        (SELECT COUNT(*) FROM notifications WHERE booking_id=p_booking AND user_id=p_user AND event_type='booking_message') message_notifications,
        (SELECT COUNT(*) FROM notifications WHERE booking_id=p_booking AND user_id=p_user AND event_type='booking_message' AND read_at IS NULL) unread_message_notifications,
        (SELECT COUNT(*) FROM booking_reminders WHERE booking_id=p_booking) reminders,
        (SELECT COUNT(*) FROM booking_reminders WHERE booking_id=p_booking AND notification_id IS NOT NULL) linked_reminders,
        (SELECT COUNT(*) FROM notifications WHERE booking_id=p_booking AND user_id=p_user AND event_type='checkin_reminder') reminder_notifications,
        (SELECT body FROM notifications WHERE booking_id=p_booking AND user_id=p_user AND event_type='checkin_reminder' ORDER BY id DESC LIMIT 1) reminder_body,
        (SELECT COUNT(*) FROM admin_audit_logs a JOIN booking_messages m ON m.id=a.entity_id
            WHERE a.entity_type='booking_message' AND m.booking_id=p_booking) message_audits;
END$$

CREATE OR REPLACE PROCEDURE sp_test_conversation_cleanup(IN p_listing BIGINT UNSIGNED)
BEGIN
    IF NOT EXISTS(SELECT 1 FROM listings WHERE id=p_listing AND city='Audit' AND title LIKE 'Conversation fixture %') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Refusing to remove a non-fixture listing.';
    END IF;
    DELETE a FROM admin_audit_logs a JOIN booking_messages m ON m.id=a.entity_id JOIN bookings b ON b.id=m.booking_id
        WHERE a.entity_type='booking_message' AND b.listing_id=p_listing;
    DELETE r FROM booking_reminders r JOIN bookings b ON b.id=r.booking_id WHERE b.listing_id=p_listing;
    DELETE m FROM booking_messages m JOIN bookings b ON b.id=m.booking_id WHERE b.listing_id=p_listing;
    DELETE n FROM notifications n JOIN bookings b ON b.id=n.booking_id WHERE b.listing_id=p_listing;
    DELETE x FROM reviews x JOIN bookings b ON b.id=x.booking_id WHERE b.listing_id=p_listing;
    DELETE c FROM booking_cancellations c JOIN bookings b ON b.id=c.booking_id WHERE b.listing_id=p_listing;
    DELETE e FROM booking_events e JOIN bookings b ON b.id=e.booking_id WHERE b.listing_id=p_listing;
    DELETE n FROM booking_nights n JOIN bookings b ON b.id=n.booking_id WHERE b.listing_id=p_listing;
    DELETE a FROM admin_audit_logs a JOIN bookings b ON b.id=a.entity_id WHERE a.entity_type='booking' AND b.listing_id=p_listing;
    DELETE FROM bookings WHERE listing_id=p_listing;
    DELETE FROM listing_moderation_events WHERE listing_id=p_listing;
    DELETE FROM listing_photos WHERE listing_id=p_listing;
    DELETE FROM listing_amenities WHERE listing_id=p_listing;
    DELETE FROM listing_availability WHERE listing_id=p_listing;
    DELETE FROM wishlist_items WHERE listing_id=p_listing;
    DELETE FROM admin_audit_logs WHERE entity_type='listing' AND entity_id=p_listing;
    DELETE FROM listings WHERE id=p_listing;
END$$

DELIMITER ;
