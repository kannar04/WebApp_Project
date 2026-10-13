-- LOCAL TESTS ONLY (booking extras). Installed only with the explicit test opt-in; every routine is sp_test_ prefixed.
-- Fixture listings are titled 'Audit fixture BX %' in city 'Audit'; fixture promo codes start with TBX.
DELIMITER $$
CREATE OR REPLACE PROCEDURE sp_test_booking_extras_inspect(IN p_booking BIGINT UNSIGNED)
BEGIN
    SELECT b.status,b.total_amount,b.fee_amount,bp.nights_subtotal,bp.length_discount_type,bp.length_discount_amount,bp.promotion_id,bp.promotion_code,
        bp.promotion_discount_amount,bp.is_instant_book,bp.total_amount breakdown_total,pay.status payment_status,pay.amount_due,pay.paid_by,pay.refunded_by,
        (SELECT COUNT(*) FROM booking_events WHERE booking_id=b.id) events,
        (SELECT COUNT(*) FROM booking_events WHERE booking_id=b.id AND from_status='pending' AND to_status='confirmed' AND actor_id IS NULL
            AND reason='Đặt ngay (Instant Book): hệ thống tự xác nhận.') instant_events,
        (SELECT COUNT(*) FROM notifications WHERE booking_id=b.id AND user_id=b.guest_id AND event_type='booking_confirmed') guest_confirmed_notices,
        (SELECT COUNT(*) FROM notifications n JOIN listings l ON l.id=b.listing_id WHERE n.booking_id=b.id AND n.user_id=l.host_id AND n.event_type='booking_instant') host_instant_notices,
        (SELECT COUNT(*) FROM notifications WHERE booking_id=b.id AND event_type IN ('payment_paid','payment_refunded')) payment_notices,
        (SELECT COUNT(*) FROM booking_payment_events WHERE booking_id=b.id) payment_events,
        (SELECT COUNT(*) FROM admin_audit_logs WHERE entity_type='booking' AND entity_id=b.id AND action IN ('mark_payment_paid','refund_payment')) payment_audits,
        (SELECT GROUP_CONCAT(CONCAT(stay_date,'=',CAST(nightly_amount AS DECIMAL(12,0))) ORDER BY stay_date SEPARATOR ',') FROM booking_nights WHERE booking_id=b.id) nights
    FROM bookings b LEFT JOIN booking_price_breakdowns bp ON bp.booking_id=b.id LEFT JOIN booking_payments pay ON pay.booking_id=b.id WHERE b.id=p_booking;
END$$

CREATE OR REPLACE PROCEDURE sp_test_booking_extras_listing_bookings(IN p_listing BIGINT UNSIGNED)
BEGIN
    SELECT b.id,b.status FROM bookings b JOIN listings l ON l.id=b.listing_id
    WHERE l.id=p_listing AND l.city='Audit' AND l.title LIKE 'Audit fixture BX %' ORDER BY b.id;
END$$

-- Moves a fixture booking so check-in was yesterday (Vietnam date); nights move with it.
CREATE OR REPLACE PROCEDURE sp_test_booking_extras_backdate(IN p_booking BIGINT UNSIGNED)
BEGIN
    DECLARE v_offset INT;
    IF NOT EXISTS(SELECT 1 FROM bookings b JOIN listings l ON l.id=b.listing_id WHERE b.id=p_booking AND l.city='Audit' AND l.title LIKE 'Audit fixture BX %') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Refusing to move a non-fixture booking.';
    END IF;
    SELECT DATEDIFF(DATE(UTC_TIMESTAMP()+INTERVAL 7 HOUR)-INTERVAL 1 DAY,check_in) INTO v_offset FROM bookings WHERE id=p_booking;
    UPDATE booking_nights SET stay_date=stay_date+INTERVAL v_offset DAY WHERE booking_id=p_booking ORDER BY stay_date;
    UPDATE bookings SET check_in=check_in+INTERVAL v_offset DAY,check_out=check_out+INTERVAL v_offset DAY WHERE id=p_booking;
END$$

-- Simulates a booking created before payment tracking existed.
CREATE OR REPLACE PROCEDURE sp_test_booking_extras_forget_payment(IN p_booking BIGINT UNSIGNED)
BEGIN
    IF NOT EXISTS(SELECT 1 FROM bookings b JOIN listings l ON l.id=b.listing_id WHERE b.id=p_booking AND l.city='Audit' AND l.title LIKE 'Audit fixture BX %') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Refusing to change a non-fixture booking.';
    END IF;
    DELETE FROM booking_payment_events WHERE booking_id=p_booking;
    DELETE FROM booking_payments WHERE booking_id=p_booking;
END$$

-- Removes every booking of a fixture listing (with children), then the listing itself.
CREATE OR REPLACE PROCEDURE sp_test_booking_extras_cleanup(IN p_listing BIGINT UNSIGNED)
BEGIN
    IF NOT EXISTS(SELECT 1 FROM listings WHERE id=p_listing AND city='Audit' AND title LIKE 'Audit fixture BX %') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Refusing to remove a non-fixture listing.';
    END IF;
    DELETE n FROM notifications n JOIN bookings b ON b.id=n.booking_id WHERE b.listing_id=p_listing;
    DELETE r FROM reviews r JOIN bookings b ON b.id=r.booking_id WHERE b.listing_id=p_listing;
    DELETE c FROM booking_cancellations c JOIN bookings b ON b.id=c.booking_id WHERE b.listing_id=p_listing;
    DELETE e FROM booking_events e JOIN bookings b ON b.id=e.booking_id WHERE b.listing_id=p_listing;
    DELETE bn FROM booking_nights bn JOIN bookings b ON b.id=bn.booking_id WHERE b.listing_id=p_listing;
    DELETE pe FROM booking_payment_events pe JOIN bookings b ON b.id=pe.booking_id WHERE b.listing_id=p_listing;
    DELETE pay FROM booking_payments pay JOIN bookings b ON b.id=pay.booking_id WHERE b.listing_id=p_listing;
    DELETE bp FROM booking_price_breakdowns bp JOIN bookings b ON b.id=bp.booking_id WHERE b.listing_id=p_listing;
    DELETE a FROM admin_audit_logs a JOIN bookings b ON b.id=a.entity_id WHERE a.entity_type='booking' AND b.listing_id=p_listing;
    DELETE FROM bookings WHERE listing_id=p_listing;
    DELETE FROM listing_booking_settings WHERE listing_id=p_listing;
    CALL sp_test_cleanup_listing(p_listing);
END$$

-- p_id is the ID the fixture got when created; audit rows of an already deleted fixture code are removed too.
CREATE OR REPLACE PROCEDURE sp_test_booking_extras_cleanup_promotion(IN p_code VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_id BIGINT UNSIGNED)
BEGIN
    DECLARE v_id BIGINT UNSIGNED;
    IF p_code IS NULL OR p_code NOT LIKE 'TBX%' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Refusing to remove a non-fixture promotion.'; END IF;
    SELECT id INTO v_id FROM promotions WHERE code=p_code;
    IF v_id IS NOT NULL THEN
        IF EXISTS(SELECT 1 FROM booking_price_breakdowns WHERE promotion_id=v_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture promotion still referenced by a booking.';
        END IF;
        DELETE FROM admin_audit_logs WHERE entity_type='promotion' AND entity_id=v_id;
        DELETE FROM promotions WHERE id=v_id;
    END IF;
    IF p_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM promotions WHERE id=p_id) AND EXISTS(SELECT 1 FROM admin_audit_logs WHERE entity_type='promotion'
        AND entity_id=p_id AND action IN ('save_promotion','delete_promotion') AND CONVERT(JSON_UNQUOTE(JSON_EXTRACT(change_summary,'$.code')) USING utf8mb4) COLLATE utf8mb4_unicode_ci=p_code) THEN
        DELETE FROM admin_audit_logs WHERE entity_type='promotion' AND entity_id=p_id;
    END IF;
END$$

-- Removes a fixture holiday ('Audit fixture%' name) and every audit row recorded for that date and fixture name.
CREATE OR REPLACE PROCEDURE sp_test_booking_extras_cleanup_holiday(IN p_date DATE)
BEGIN
    DELETE FROM admin_audit_logs WHERE entity_type='holiday' AND action IN ('save_holiday','delete_holiday')
        AND CONVERT(JSON_UNQUOTE(JSON_EXTRACT(change_summary,'$.date')) USING utf8mb4) COLLATE utf8mb4_unicode_ci=CAST(p_date AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci
        AND CONVERT(JSON_UNQUOTE(JSON_EXTRACT(change_summary,'$.name')) USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE 'Audit fixture%';
    DELETE FROM public_holidays WHERE holiday_date=p_date AND name LIKE 'Audit fixture%';
END$$

CREATE OR REPLACE PROCEDURE sp_test_booking_extras_audit_count(IN p_action VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci, IN p_entity BIGINT UNSIGNED)
BEGIN
    SELECT COUNT(*) audits FROM admin_audit_logs WHERE action=p_action AND entity_id=p_entity;
END$$
DELIMITER ;
