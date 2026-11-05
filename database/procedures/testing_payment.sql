-- LOCAL TESTS ONLY (online payment). Installed only with the explicit test opt-in; every routine is sp_test_ prefixed.
-- Fixture listings are titled 'Audit fixture PAY %' in city 'Audit'.
DELIMITER $$
CREATE OR REPLACE PROCEDURE sp_test_payment_inspect(IN p_booking BIGINT UNSIGNED)
BEGIN
    SELECT b.status booking_status,pay.status payment_status,pay.method payment_method,pay.amount_due,pay.paid_by,pay.note payment_note,
        (SELECT COUNT(*) FROM booking_payment_events WHERE booking_id=b.id) payment_events,
        (SELECT COUNT(*) FROM payment_transactions WHERE booking_id=b.id) attempts,
        (SELECT GROUP_CONCAT(CONCAT(provider,':',status) ORDER BY id SEPARATOR ',') FROM payment_transactions WHERE booking_id=b.id) attempt_states,
        (SELECT COUNT(*) FROM notifications WHERE booking_id=b.id AND user_id=b.guest_id AND event_type='payment_paid') paid_notices,
        (SELECT COUNT(*) FROM notifications WHERE booking_id=b.id AND user_id=b.guest_id AND event_type='payment_transfer_rejected') rejected_notices,
        (SELECT COUNT(*) FROM notifications n JOIN user_roles r ON r.user_id=n.user_id AND r.role='admin'
            WHERE n.booking_id=b.id AND n.event_type='payment_transfer_submitted') admin_submitted_notices,
        (SELECT COUNT(*) FROM notifications n JOIN user_roles r ON r.user_id=n.user_id AND r.role='admin'
            WHERE n.booking_id=b.id AND n.event_type='payment_duplicate') admin_duplicate_notices,
        (SELECT COUNT(*) FROM notifications n JOIN user_roles r ON r.user_id=n.user_id AND r.role='admin'
            WHERE n.booking_id=b.id AND n.event_type='payment_refund_needed') admin_refund_notices,
        (SELECT COUNT(*) FROM notifications n JOIN user_roles r ON r.user_id=n.user_id AND r.role='admin'
            WHERE n.booking_id=b.id AND n.event_type='payment_cancelled_paid') admin_cancelled_paid_notices,
        (SELECT n.body FROM notifications n JOIN user_roles r ON r.user_id=n.user_id AND r.role='admin'
            WHERE n.booking_id=b.id AND n.event_type IN ('payment_refund_needed','payment_cancelled_paid') ORDER BY n.id DESC LIMIT 1) admin_refund_body,
        (SELECT n.body FROM notifications n WHERE n.booking_id=b.id AND n.user_id=b.guest_id AND n.event_type='booking_cancelled') guest_cancel_body,
        (SELECT n.body FROM notifications n WHERE n.booking_id=b.id AND n.user_id=b.guest_id AND n.event_type='payment_refunded') guest_refund_body,
        (SELECT refund_amount FROM booking_cancellations WHERE booking_id=b.id) cancel_refund,
        (SELECT JSON_UNQUOTE(JSON_EXTRACT(change_summary,'$.amount')) FROM admin_audit_logs WHERE entity_type='booking' AND entity_id=b.id AND action='refund_payment' ORDER BY id DESC LIMIT 1) refund_audit_amount,
        (SELECT COUNT(*) FROM notifications WHERE booking_id=b.id AND user_id=b.guest_id AND event_type='payment_duplicate') guest_duplicate_notices,
        (SELECT COUNT(*) FROM notifications WHERE booking_id=b.id AND user_id=b.guest_id AND event_type='payment_duplicate_refunded') guest_duplicate_refunded_notices,
        (SELECT COUNT(*) FROM admin_audit_logs WHERE entity_type='booking' AND entity_id=b.id AND action='resolve_duplicate_payment') duplicate_audits,
        (SELECT n.body FROM notifications n WHERE n.booking_id=b.id AND n.user_id=b.guest_id AND n.event_type='payment_duplicate_transfer_closed') guest_transfer_closed_body,
        (SELECT n.body FROM notifications n WHERE n.booking_id=b.id AND n.user_id=b.guest_id AND n.event_type='payment_duplicate_refunded' ORDER BY n.id DESC LIMIT 1) guest_duplicate_refunded_body,
        (SELECT COUNT(*) FROM admin_audit_logs WHERE entity_type='booking' AND entity_id=b.id AND action='close_duplicate_transfer') transfer_close_audits,
        (SELECT COUNT(*) FROM admin_audit_logs WHERE entity_type='booking' AND entity_id=b.id AND action IN ('confirm_bank_transfer','reject_bank_transfer')) transfer_audits
    FROM bookings b LEFT JOIN booking_payments pay ON pay.booking_id=b.id WHERE b.id=p_booking;
END$$

-- One online payment attempt as stored (gateway fields included).
CREATE OR REPLACE PROCEDURE sp_test_payment_attempt(IN p_transaction BIGINT UNSIGNED)
BEGIN
    SELECT id,booking_id,provider,reference,amount,status,gateway_transaction_no,gateway_response_code,bank_code,submitted_at,decided_by,decided_at,note
    FROM payment_transactions WHERE id=p_transaction;
END$$

-- Simulates a booking saved before amounts were whole dong: its total, amount due and open attempts hold a decimal.
CREATE OR REPLACE PROCEDURE sp_test_payment_set_amount(IN p_booking BIGINT UNSIGNED, IN p_amount DECIMAL(12,2))
BEGIN
    IF NOT EXISTS(SELECT 1 FROM bookings b JOIN listings l ON l.id=b.listing_id WHERE b.id=p_booking AND l.city='Audit' AND l.title LIKE 'Audit fixture PAY %') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Refusing to change a non-fixture booking.';
    END IF;
    UPDATE bookings SET total_amount=p_amount WHERE id=p_booking;
    UPDATE booking_payments SET amount_due=p_amount WHERE booking_id=p_booking;
    UPDATE payment_transactions SET amount=p_amount WHERE booking_id=p_booking AND status='pending';
END$$

-- A fixture listing taken down (p_removed=1) or back (p_removed=0): only the soft-delete mark moves.
CREATE OR REPLACE PROCEDURE sp_test_payment_listing_removed(IN p_listing BIGINT UNSIGNED, IN p_removed BOOL)
BEGIN
    IF NOT EXISTS(SELECT 1 FROM listings WHERE id=p_listing AND city='Audit' AND title LIKE 'Audit fixture PAY %') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Refusing to change a non-fixture listing.';
    END IF;
    UPDATE listings SET deleted_at=IF(p_removed,NOW(6),NULL) WHERE id=p_listing;
END$$

-- Puts a refunded fixture booking in the shape older code left: that code refunded the full amount_due whatever the policy owed,
-- so its audit row holds amount_due while booking_cancellations keeps the policy share. p_keep_audit=0 removes the audit row too.
CREATE OR REPLACE PROCEDURE sp_test_payment_old_refund(IN p_booking BIGINT UNSIGNED, IN p_keep_audit BOOL)
BEGIN
    IF NOT EXISTS(SELECT 1 FROM bookings b JOIN listings l ON l.id=b.listing_id WHERE b.id=p_booking AND l.city='Audit' AND l.title LIKE 'Audit fixture PAY %') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Refusing to change a non-fixture booking.';
    END IF;
    IF p_keep_audit THEN
        UPDATE admin_audit_logs a JOIN booking_payments pay ON pay.booking_id=a.entity_id
        SET a.change_summary=JSON_OBJECT('amount',pay.amount_due,'note',pay.note)
        WHERE a.action='refund_payment' AND a.entity_type='booking' AND a.entity_id=p_booking;
    ELSE
        DELETE FROM admin_audit_logs WHERE action='refund_payment' AND entity_type='booking' AND entity_id=p_booking;
    END IF;
END$$

-- Removes every booking of a fixture listing (with children, payment attempts included), then the listing itself.
CREATE OR REPLACE PROCEDURE sp_test_payment_cleanup(IN p_listing BIGINT UNSIGNED)
BEGIN
    IF NOT EXISTS(SELECT 1 FROM listings WHERE id=p_listing AND city='Audit' AND title LIKE 'Audit fixture PAY %') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Refusing to remove a non-fixture listing.';
    END IF;
    DELETE n FROM notifications n JOIN bookings b ON b.id=n.booking_id WHERE b.listing_id=p_listing;
    DELETE c FROM booking_cancellations c JOIN bookings b ON b.id=c.booking_id WHERE b.listing_id=p_listing;
    DELETE e FROM booking_events e JOIN bookings b ON b.id=e.booking_id WHERE b.listing_id=p_listing;
    DELETE bn FROM booking_nights bn JOIN bookings b ON b.id=bn.booking_id WHERE b.listing_id=p_listing;
    DELETE t FROM payment_transactions t JOIN bookings b ON b.id=t.booking_id WHERE b.listing_id=p_listing;
    DELETE pe FROM booking_payment_events pe JOIN bookings b ON b.id=pe.booking_id WHERE b.listing_id=p_listing;
    DELETE pay FROM booking_payments pay JOIN bookings b ON b.id=pay.booking_id WHERE b.listing_id=p_listing;
    DELETE bp FROM booking_price_breakdowns bp JOIN bookings b ON b.id=bp.booking_id WHERE b.listing_id=p_listing;
    DELETE a FROM admin_audit_logs a JOIN bookings b ON b.id=a.entity_id WHERE a.entity_type='booking' AND b.listing_id=p_listing;
    DELETE FROM bookings WHERE listing_id=p_listing;
    DELETE FROM listing_booking_settings WHERE listing_id=p_listing;
    CALL sp_test_cleanup_listing(p_listing);
END$$
DELIMITER ;
