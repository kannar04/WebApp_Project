-- Nice-to-have online payment: VietQR bank transfer (Guest reports it, Admin confirms) and VNPay (signed gateway result).
-- A booking can be paid online once it is confirmed and still unpaid. Locks follow listing → booking → payment → attempt.
-- The VNPay secret never reaches the database: PHP verifies the signature before calling sp_payment_vnpay_result.
DELIMITER $$

-- What the Guest's payment page needs: amount, whether online payment is open, and the open bank transfer if any.
CREATE OR REPLACE PROCEDURE sp_payment_checkout(IN p_booking BIGINT UNSIGNED, IN p_guest BIGINT UNSIGNED)
BEGIN
    IF NOT EXISTS(SELECT 1 FROM bookings b JOIN users u ON u.id=b.guest_id
        WHERE b.id=p_booking AND b.guest_id=p_guest AND u.status='active' AND u.deleted_at IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không có quyền thanh toán booking này.';
    END IF;
    SELECT b.id booking_id,b.status booking_status,b.check_in,b.check_out,b.currency,l.title,
        COALESCE(pay.amount_due,b.total_amount) amount_due,COALESCE(pay.status,'unpaid') payment_status,COALESCE(pay.method,'pay_at_checkin') payment_method,
        (COALESCE(pay.status,'unpaid')='unpaid' AND b.status='confirmed' AND b.currency='VND' AND COALESCE(pay.amount_due,b.total_amount)>0) can_pay_online,
        t.id transfer_id,t.reference transfer_reference,t.status transfer_status,t.amount transfer_amount,t.created_at transfer_created_at,t.submitted_at transfer_submitted_at
    FROM bookings b JOIN listings l ON l.id=b.listing_id LEFT JOIN booking_payments pay ON pay.booking_id=b.id
        LEFT JOIN payment_transactions t ON t.id=(SELECT x.id FROM payment_transactions x
            WHERE x.booking_id=b.id AND x.provider='vietqr' AND x.status IN ('pending','submitted') ORDER BY x.id DESC LIMIT 1)
    WHERE b.id=p_booking;
END$$

-- Starts (or, for an unchanged VietQR amount, reuses) an online payment attempt for the Guest's confirmed, unpaid booking.
CREATE OR REPLACE PROCEDURE sp_payment_start(IN p_booking BIGINT UNSIGNED, IN p_guest BIGINT UNSIGNED,
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
    SELECT id INTO v_lock FROM listings WHERE id=v_listing FOR UPDATE;
    SELECT guest_id,status,currency,total_amount INTO v_guest,v_status,v_currency,v_total FROM bookings WHERE id=p_booking FOR UPDATE;
    IF v_guest IS NULL OR v_guest<>p_guest OR NOT EXISTS(SELECT 1 FROM users WHERE id=p_guest AND status='active' AND deleted_at IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không có quyền thanh toán booking này.';
    END IF;
    SELECT status,amount_due INTO v_payment,v_due FROM booking_payments WHERE booking_id=p_booking FOR UPDATE;
    SET v_due=COALESCE(v_due,v_total);
    IF COALESCE(v_payment,'unpaid')<>'unpaid' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Booking này đã được ghi nhận thanh toán.'; END IF;
    IF v_status<>'confirmed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỉ thanh toán online cho booking đã được xác nhận.'; END IF;
    IF v_currency<>'VND' OR v_due<=0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Booking này không hỗ trợ thanh toán online.'; END IF;
    IF EXISTS(SELECT 1 FROM payment_transactions WHERE booking_id=p_booking AND status='submitted') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bạn đã báo chuyển khoản cho booking này; vui lòng chờ Admin xác nhận.';
    END IF;
    IF p_provider='vietqr' THEN
        -- Reloading the QR page keeps the same transfer content, so the Guest never sees two codes for one booking.
        SELECT id INTO v_attempt FROM payment_transactions
        WHERE booking_id=p_booking AND provider='vietqr' AND status='pending' AND amount=v_due ORDER BY id DESC LIMIT 1 FOR UPDATE;
    END IF;
    IF v_attempt IS NULL THEN
        INSERT INTO payment_transactions(booking_id,provider,reference,amount,status,created_at,updated_at)
            VALUES(p_booking,p_provider,CONCAT('H2H',p_booking,'X',UPPER(LEFT(MD5(CONCAT(UUID(),RAND(),p_booking)),5))),v_due,'pending',NOW(6),NOW(6));
        SET v_attempt=LAST_INSERT_ID();
    END IF;
    SELECT id transaction_id,booking_id,provider,reference,amount,status,created_at FROM payment_transactions WHERE id=v_attempt;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_payment_start; END IF;
END$$

-- One attempt for the bank transfer page: the paying Guest or an Admin.
CREATE OR REPLACE PROCEDURE sp_payment_transaction_get(IN p_transaction BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
BEGIN
    IF NOT EXISTS(SELECT 1 FROM payment_transactions t JOIN bookings b ON b.id=t.booking_id JOIN users u ON u.id=p_actor
        WHERE t.id=p_transaction AND u.status='active' AND u.deleted_at IS NULL
        AND (b.guest_id=p_actor OR EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_actor AND role='admin'))) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không tìm thấy giao dịch thanh toán.';
    END IF;
    SELECT t.id transaction_id,t.booking_id,t.provider,t.reference,t.amount,t.status,t.submitted_at,t.decided_at,t.note,t.created_at,
        b.status booking_status,b.check_in,b.check_out,b.guest_id,l.title,COALESCE(pay.status,'unpaid') payment_status,COALESCE(pay.method,'pay_at_checkin') payment_method
    FROM payment_transactions t JOIN bookings b ON b.id=t.booking_id JOIN listings l ON l.id=b.listing_id LEFT JOIN booking_payments pay ON pay.booking_id=b.id
    WHERE t.id=p_transaction;
END$$

-- The Guest reports that the bank transfer was sent; every active Admin is asked to check the account.
CREATE OR REPLACE PROCEDURE sp_payment_transfer_submit(IN p_transaction BIGINT UNSIGNED, IN p_guest BIGINT UNSIGNED)
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
    SELECT id INTO v_lock FROM listings WHERE id=v_listing FOR UPDATE;
    SELECT guest_id,status INTO v_guest,v_booking_status FROM bookings WHERE id=v_booking FOR UPDATE;
    IF v_guest IS NULL OR v_guest<>p_guest OR NOT EXISTS(SELECT 1 FROM users WHERE id=p_guest AND status='active' AND deleted_at IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không tìm thấy giao dịch thanh toán.';
    END IF;
    SELECT status INTO v_payment FROM booking_payments WHERE booking_id=v_booking FOR UPDATE;
    SELECT provider,status,reference,amount INTO v_provider,v_status,v_reference,v_amount FROM payment_transactions WHERE id=p_transaction FOR UPDATE;
    IF v_provider<>'vietqr' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỉ giao dịch chuyển khoản mới cần báo đã chuyển.'; END IF;
    IF v_status='submitted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bạn đã báo chuyển khoản rồi; vui lòng chờ Admin xác nhận.'; END IF;
    IF v_status<>'pending' OR COALESCE(v_payment,'unpaid')<>'unpaid' OR v_booking_status<>'confirmed' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Giao dịch này đã đóng; vui lòng tải lại trang thanh toán.';
    END IF;
    UPDATE payment_transactions SET status='submitted',submitted_at=NOW(6),updated_at=NOW(6) WHERE id=p_transaction;
    INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
        SELECT r.user_id,v_booking,'in_app','payment_transfer_submitted','Khách báo đã chuyển khoản',
            CONCAT('Booking #',v_booking,': khách báo đã chuyển ',REPLACE(FORMAT(v_amount,0),',','.'),'đ với nội dung ',v_reference,'. Vui lòng kiểm tra tài khoản và xác nhận.'),
            'sent',1,NOW(6)
        FROM user_roles r JOIN users u ON u.id=r.user_id WHERE r.role='admin' AND u.status='active' AND u.deleted_at IS NULL;
    SELECT 1 __affected,p_transaction __insert_id;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_payment_submit; END IF;
END$$

-- Admin checks the bank account: 'confirm' records the payment, 'reject' (reason required) reopens payment for the Guest.
CREATE OR REPLACE PROCEDURE sp_admin_payment_transfer_decide(IN p_transaction BIGINT UNSIGNED, IN p_admin BIGINT UNSIGNED,
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
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN
            IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_transfer_decide; RELEASE SAVEPOINT h2h_transfer_decide; END IF;
        END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_transfer_decide; END IF;
    CALL sp_admin_assert_role(p_admin);
    IF p_decision IS NULL OR p_decision NOT IN ('confirm','reject') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Quyết định chuyển khoản không hợp lệ.'; END IF;
    SET v_note=NULLIF(TRIM(COALESCE(p_note,'')),'');
    IF p_decision='reject' AND (v_note IS NULL OR CHAR_LENGTH(v_note)<5) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Vui lòng nhập lý do chưa nhận được tiền (ít nhất 5 ký tự).';
    END IF;
    SELECT booking_id INTO v_booking FROM payment_transactions WHERE id=p_transaction;
    SELECT listing_id INTO v_listing FROM bookings WHERE id=v_booking;
    SELECT id INTO v_lock FROM listings WHERE id=v_listing FOR UPDATE;
    SELECT guest_id,total_amount INTO v_guest,v_total FROM bookings WHERE id=v_booking FOR UPDATE;
    IF v_guest IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không tìm thấy giao dịch thanh toán.'; END IF;
    SELECT status INTO v_payment FROM booking_payments WHERE booking_id=v_booking FOR UPDATE;
    SELECT provider,status,reference,amount INTO v_provider,v_status,v_reference,v_amount FROM payment_transactions WHERE id=p_transaction FOR UPDATE;
    IF v_provider<>'vietqr' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỉ xác nhận giao dịch chuyển khoản VietQR.'; END IF;
    IF v_status NOT IN ('pending','submitted') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Giao dịch chuyển khoản này đã được xử lý.'; END IF;
    IF p_decision='confirm' THEN
        IF COALESCE(v_payment,'unpaid')<>'unpaid' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Booking này đã được ghi nhận thanh toán; hãy từ chối giao dịch trùng và hoàn tiền cho khách.';
        END IF;
        UPDATE payment_transactions SET status='succeeded',decided_by=p_admin,decided_at=NOW(6),note=v_note,updated_at=NOW(6) WHERE id=p_transaction;
        UPDATE payment_transactions SET status='cancelled',decided_at=NOW(6),note='Booking đã được thanh toán bằng giao dịch khác.',updated_at=NOW(6)
            WHERE booking_id=v_booking AND id<>p_transaction AND status='pending';
        IF v_payment IS NULL THEN
            -- Bookings created before payment tracking get their record lazily, as in sp_booking_payment_mark_paid.
            INSERT INTO booking_payments(booking_id,method,amount_due,status,paid_at,paid_by,refunded_at,refunded_by,note,created_at,updated_at)
                VALUES(v_booking,'vietqr',v_amount,'paid',NOW(6),p_admin,NULL,NULL,CONCAT('VietQR ',v_reference),NOW(6),NOW(6));
        ELSE
            UPDATE booking_payments SET method='vietqr',amount_due=v_amount,status='paid',paid_at=NOW(6),paid_by=p_admin,
                note=CONCAT('VietQR ',v_reference),updated_at=NOW(6) WHERE booking_id=v_booking;
        END IF;
        INSERT INTO booking_payment_events(booking_id,actor_id,from_status,to_status,note,created_at)
            VALUES(v_booking,p_admin,'unpaid','paid',CONCAT('VietQR ',v_reference),NOW(6));
        INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
            VALUES(v_guest,v_booking,'in_app','payment_paid','Đã ghi nhận thanh toán',
            CONCAT('Booking #',v_booking,' đã được xác nhận thanh toán chuyển khoản ',REPLACE(FORMAT(v_amount,0),',','.'),'đ (mã ',v_reference,').'),'sent',1,NOW(6));
    ELSE
        UPDATE payment_transactions SET status='failed',decided_by=p_admin,decided_at=NOW(6),note=v_note,updated_at=NOW(6) WHERE id=p_transaction;
        INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
            VALUES(v_guest,v_booking,'in_app','payment_transfer_rejected','Chưa xác nhận được chuyển khoản',
            CONCAT('Chưa xác nhận được chuyển khoản cho booking #',v_booking,' (mã ',v_reference,'). Lý do: ',v_note),'sent',1,NOW(6));
    END IF;
    INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at)
        VALUES(p_admin,IF(p_decision='confirm','confirm_bank_transfer','reject_bank_transfer'),'booking',v_booking,
            JSON_OBJECT('transaction_id',p_transaction,'reference',v_reference,'amount',v_amount,'note',v_note),NOW(6));
    SELECT 1 __affected,p_transaction __insert_id;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_transfer_decide; END IF;
END$$

-- Records a VNPay result whose signature PHP has already verified (return URL and IPN share it; safe to repeat).
-- result: ok | not_found | invalid_amount | already — the IPN answers VNPay with 00 / 01 / 04 / 02.
CREATE OR REPLACE PROCEDURE sp_payment_vnpay_result(IN p_reference VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
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
        SELECT id INTO v_lock FROM listings WHERE id=v_listing FOR UPDATE;
        SELECT guest_id,status INTO v_guest,v_booking_status FROM bookings WHERE id=v_booking FOR UPDATE;
        SELECT status INTO v_payment FROM booking_payments WHERE booking_id=v_booking FOR UPDATE;
        SELECT status,amount INTO v_status,v_amount FROM payment_transactions WHERE id=v_attempt FOR UPDATE;
        IF p_amount IS NULL OR p_amount<>v_amount THEN
            SET v_result='invalid_amount';
        ELSEIF v_status NOT IN ('pending','cancelled') THEN
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
                    VALUES(v_guest,v_booking,'in_app','payment_paid','Đã ghi nhận thanh toán',
                    CONCAT('Booking #',v_booking,' đã thanh toán thành công qua VNPay ',REPLACE(FORMAT(v_amount,0),',','.'),'đ.'),'sent',1,NOW(6));
                IF v_booking_status NOT IN ('confirmed','completed') THEN
                    -- The Guest cancelled while VNPay was open: the money is recorded as paid so the Admin can record the refund.
                    INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at)
                        SELECT r.user_id,v_booking,'in_app','payment_refund_needed','Cần hoàn tiền',
                            CONCAT('Booking #',v_booking,' đã hủy nhưng vẫn nhận ',REPLACE(FORMAT(v_amount,0),',','.'),'đ qua VNPay (mã ',p_reference,'). Vui lòng hoàn tiền cho khách.'),
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
            END IF;
        ELSE
            UPDATE payment_transactions SET status='failed',gateway_transaction_no=NULLIF(p_transaction_no,''),gateway_response_code=p_response_code,
                bank_code=NULLIF(p_bank_code,''),decided_at=NOW(6),updated_at=NOW(6) WHERE id=v_attempt;
        END IF;
    END IF;
    SELECT v_result result,v_booking booking_id,(SELECT status FROM payment_transactions WHERE id=v_attempt) transaction_status,
        (SELECT gateway_response_code FROM payment_transactions WHERE id=v_attempt) response_code;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_vnpay_result; END IF;
END$$

-- Online payment attempts of one booking, newest first, for anyone who may see the booking.
CREATE OR REPLACE PROCEDURE sp_payment_booking_transactions(IN p_booking BIGINT UNSIGNED, IN p_actor BIGINT UNSIGNED)
BEGIN
    CALL sp_quality_assert_booking(p_booking,p_actor);
    SELECT t.id transaction_id,t.provider,t.reference,t.amount,t.status,t.gateway_transaction_no,t.gateway_response_code,t.bank_code,
        t.submitted_at,t.decided_at,t.note,t.created_at,a.full_name decided_by_name
    FROM payment_transactions t LEFT JOIN users a ON a.id=t.decided_by
    WHERE t.booking_id=p_booking ORDER BY t.id DESC LIMIT 20;
END$$

-- Admin queue: transfers the Guest reported, then open QR codes of confirmed, unpaid bookings (money may arrive unreported).
CREATE OR REPLACE PROCEDURE sp_admin_payment_transfers(IN p_admin BIGINT UNSIGNED)
BEGIN
    CALL sp_admin_assert_role(p_admin);
    SELECT t.id transaction_id,t.booking_id,t.reference,t.amount,t.status,t.submitted_at,t.created_at,b.status booking_status,
        l.title,u.full_name guest_name,u.email guest_email,COALESCE(pay.status,'unpaid') payment_status
    FROM payment_transactions t JOIN bookings b ON b.id=t.booking_id JOIN listings l ON l.id=b.listing_id JOIN users u ON u.id=b.guest_id
        LEFT JOIN booking_payments pay ON pay.booking_id=b.id
    WHERE t.provider='vietqr' AND (t.status='submitted'
        OR (t.status='pending' AND b.status='confirmed' AND COALESCE(pay.status,'unpaid')='unpaid'))
    ORDER BY t.status='submitted' DESC,t.submitted_at,t.id LIMIT 100;
END$$

DELIMITER ;
