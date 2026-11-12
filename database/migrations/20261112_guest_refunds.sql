-- Semi-automatic guest refunds: approved destination account, VietQR and auditable completion.
CREATE TABLE IF NOT EXISTS guest_refund_accounts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 guest_id BIGINT UNSIGNED NOT NULL UNIQUE,
 bank_id VARCHAR(20) NOT NULL,
 bank_name VARCHAR(100) NOT NULL,
 account_no VARCHAR(19) NOT NULL,
 account_name VARCHAR(150) NOT NULL,
 status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
 rejection_reason VARCHAR(500) NOT NULL DEFAULT '',
 reviewed_by BIGINT UNSIGNED NULL,
 reviewed_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 CONSTRAINT fk_guest_refund_account_guest FOREIGN KEY(guest_id) REFERENCES users(id),
 CONSTRAINT fk_guest_refund_account_reviewer FOREIGN KEY(reviewed_by) REFERENCES users(id),
 INDEX idx_guest_refund_account_status(status,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guest_refunds (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 booking_id BIGINT UNSIGNED NOT NULL UNIQUE,
 guest_id BIGINT UNSIGNED NOT NULL,
 refund_account_id BIGINT UNSIGNED NOT NULL,
 bank_id VARCHAR(20) NOT NULL,
 bank_name VARCHAR(100) NOT NULL,
 account_no VARCHAR(19) NOT NULL,
 account_name VARCHAR(150) NOT NULL,
 amount DECIMAL(12,2) NOT NULL,
 currency CHAR(3) NOT NULL DEFAULT 'VND',
 reference VARCHAR(40) NOT NULL UNIQUE,
 reason VARCHAR(500) NOT NULL,
 status ENUM('pending','paid') NOT NULL DEFAULT 'pending',
 bank_transaction_no VARCHAR(100) NULL,
 note VARCHAR(500) NOT NULL DEFAULT '',
 created_by BIGINT UNSIGNED NOT NULL,
 paid_by BIGINT UNSIGNED NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 paid_at DATETIME(6) NULL,
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 CONSTRAINT fk_guest_refund_booking FOREIGN KEY(booking_id) REFERENCES bookings(id),
 CONSTRAINT fk_guest_refund_guest FOREIGN KEY(guest_id) REFERENCES users(id),
 CONSTRAINT fk_guest_refund_account FOREIGN KEY(refund_account_id) REFERENCES guest_refund_accounts(id),
 CONSTRAINT fk_guest_refund_creator FOREIGN KEY(created_by) REFERENCES users(id),
 CONSTRAINT fk_guest_refund_payer FOREIGN KEY(paid_by) REFERENCES users(id),
 UNIQUE KEY uq_guest_refund_transaction(bank_transaction_no),
 INDEX idx_guest_refund_status(status,created_at),
 INDEX idx_guest_refund_guest(guest_id,status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE OR REPLACE PROCEDURE DST_sp_guest_refund_account_get(IN p_guest BIGINT UNSIGNED)
BEGIN
 IF NOT EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_guest AND role='guest') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Guest access is required.'; END IF;
 SELECT * FROM guest_refund_accounts WHERE guest_id=p_guest;
END$$

CREATE OR REPLACE PROCEDURE LTP_sp_guest_refund_account_save(IN p_guest BIGINT UNSIGNED,
 IN p_bank_id VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
 IN p_bank_name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
 IN p_account_no VARCHAR(19) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
 IN p_account_name VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
 START TRANSACTION;
 IF NOT EXISTS(SELECT 1 FROM users u JOIN user_roles r ON r.user_id=u.id AND r.role='guest' WHERE u.id=p_guest AND u.status='active' AND u.deleted_at IS NULL) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Guest account is unavailable.'; END IF;
 IF p_bank_id IS NULL OR p_bank_id NOT REGEXP '^[A-Za-z0-9]{2,20}$' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid VietQR bank code.'; END IF;
 IF p_bank_name IS NULL OR CHAR_LENGTH(TRIM(p_bank_name)) NOT BETWEEN 2 AND 100 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid bank name.'; END IF;
 IF p_account_no IS NULL OR p_account_no NOT REGEXP '^[0-9]{4,19}$' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bank account number must contain 4 to 19 digits.'; END IF;
 IF p_account_name IS NULL OR CHAR_LENGTH(TRIM(p_account_name)) NOT BETWEEN 2 AND 150 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid account holder name.'; END IF;
 INSERT INTO guest_refund_accounts(guest_id,bank_id,bank_name,account_no,account_name,status,rejection_reason,reviewed_by,reviewed_at,created_at,updated_at)
 VALUES(p_guest,UPPER(TRIM(p_bank_id)),TRIM(p_bank_name),TRIM(p_account_no),UPPER(TRIM(p_account_name)),'pending','',NULL,NULL,NOW(6),NOW(6))
 ON DUPLICATE KEY UPDATE bank_id=VALUES(bank_id),bank_name=VALUES(bank_name),account_no=VALUES(account_no),account_name=VALUES(account_name),status='pending',rejection_reason='',reviewed_by=NULL,reviewed_at=NULL,updated_at=NOW(6);
 COMMIT;
END$$

CREATE OR REPLACE PROCEDURE NMT_sp_admin_refund_accounts(IN p_admin BIGINT UNSIGNED,IN p_status VARCHAR(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
 CALL NTK_sp_admin_assert_role(p_admin);
 IF COALESCE(p_status,'') NOT IN ('','pending','approved','rejected') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid refund account status.'; END IF;
 SELECT a.*,u.full_name guest_name,u.email guest_email,CONCAT(REPEAT('*',GREATEST(0,CHAR_LENGTH(a.account_no)-4)),RIGHT(a.account_no,4)) account_no_masked
 FROM guest_refund_accounts a JOIN users u ON u.id=a.guest_id WHERE p_status='' OR a.status=p_status ORDER BY a.status='pending' DESC,a.updated_at DESC LIMIT 200;
END$$

CREATE OR REPLACE PROCEDURE NTK_sp_admin_refund_account_decide(IN p_account BIGINT UNSIGNED,IN p_admin BIGINT UNSIGNED,
 IN p_decision VARCHAR(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,IN p_reason VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
 DECLARE v_guest BIGINT UNSIGNED;
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
 START TRANSACTION;
 CALL NTK_sp_admin_assert_role(p_admin);
 IF p_decision NOT IN ('approved','rejected') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid decision.'; END IF;
 SELECT guest_id INTO v_guest FROM guest_refund_accounts WHERE id=p_account AND status='pending' FOR UPDATE;
 IF v_guest IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Refund account is unavailable.'; END IF;
 IF v_guest=p_admin THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Another Admin must review your refund account.'; END IF;
 IF p_decision='rejected' AND CHAR_LENGTH(TRIM(COALESCE(p_reason,'')))<3 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A rejection reason is required.'; END IF;
 UPDATE guest_refund_accounts SET status=p_decision,rejection_reason=IF(p_decision='rejected',TRIM(p_reason),''),reviewed_by=p_admin,reviewed_at=NOW(6),updated_at=NOW(6) WHERE id=p_account;
 INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at) VALUES(p_admin,'review_guest_refund_account','guest_refund_account',p_account,JSON_OBJECT('decision',p_decision,'guest_id',v_guest),NOW(6));
 COMMIT;
END$$

CREATE OR REPLACE PROCEDURE DST_sp_admin_guest_refunds(IN p_admin BIGINT UNSIGNED)
BEGIN
 CALL NTK_sp_admin_assert_role(p_admin);
 SELECT 'refund' row_type,r.id refund_id,r.booking_id,r.guest_id,u.full_name guest_name,u.email guest_email,l.title,b.status booking_status,pay.method payment_method,
  r.amount,r.currency,r.reference,r.reason,r.status refund_status,r.bank_id,r.bank_name,r.account_no,r.account_name,'approved' account_status,r.bank_transaction_no,r.note,r.created_at,r.paid_at
 FROM guest_refunds r JOIN bookings b ON b.id=r.booking_id JOIN listings l ON l.id=b.listing_id JOIN users u ON u.id=r.guest_id JOIN booking_payments pay ON pay.booking_id=b.id
 UNION ALL
 SELECT 'eligible',NULL,b.id,b.guest_id,u.full_name,u.email,l.title,b.status,pay.method,
  COALESCE(c.refund_amount,pay.amount_due),l.currency,NULL,'',NULL,a.bank_id,a.bank_name,a.account_no,a.account_name,COALESCE(a.status,'missing'),NULL,'',NULL,NULL
 FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN users u ON u.id=b.guest_id JOIN booking_payments pay ON pay.booking_id=b.id
 LEFT JOIN booking_cancellations c ON c.booking_id=b.id LEFT JOIN guest_refunds r ON r.booking_id=b.id LEFT JOIN guest_refund_accounts a ON a.guest_id=b.guest_id
 WHERE pay.status='paid' AND pay.method IN ('vietqr','vnpay') AND COALESCE(c.refund_amount,pay.amount_due)>0 AND r.id IS NULL
 ORDER BY row_type='eligible' DESC,created_at DESC,booking_id DESC LIMIT 300;
END$$

CREATE OR REPLACE PROCEDURE LTP_sp_admin_guest_refund_create(IN p_booking BIGINT UNSIGNED,IN p_admin BIGINT UNSIGNED,
 IN p_reason VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,IN p_shown_amount DECIMAL(12,2))
BEGIN
 DECLARE v_guest BIGINT UNSIGNED; DECLARE v_account BIGINT UNSIGNED; DECLARE v_amount DECIMAL(12,2); DECLARE v_currency CHAR(3); DECLARE v_refund BIGINT UNSIGNED; DECLARE v_method VARCHAR(20);
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
 START TRANSACTION;
 CALL NTK_sp_admin_assert_role(p_admin);
 IF CHAR_LENGTH(TRIM(COALESCE(p_reason,'')))<3 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A refund reason is required.'; END IF;
 SELECT b.guest_id,COALESCE(c.refund_amount,pay.amount_due),l.currency,pay.method INTO v_guest,v_amount,v_currency,v_method FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN booking_payments pay ON pay.booking_id=b.id LEFT JOIN booking_cancellations c ON c.booking_id=b.id WHERE b.id=p_booking AND pay.status='paid' FOR UPDATE;
 IF v_guest IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Only a paid booking can be refunded.'; END IF;
 IF v_method NOT IN ('vietqr','vnpay') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Pay-at-check-in money was collected by the Host and cannot be refunded from the platform account.'; END IF;
 IF v_amount<=0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='This booking has no refundable amount.'; END IF;
 IF p_shown_amount IS NOT NULL AND p_shown_amount<>v_amount THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='The refundable amount changed. Review it before creating the order.'; END IF;
 IF EXISTS(SELECT 1 FROM guest_refunds WHERE booking_id=p_booking) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A refund order already exists for this booking.'; END IF;
 IF EXISTS(SELECT 1 FROM host_payouts WHERE booking_id=p_booking AND status='paid') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='This booking was already paid out to the Host and requires manual reconciliation.'; END IF;
 SELECT id INTO v_account FROM guest_refund_accounts WHERE guest_id=v_guest AND status='approved' FOR UPDATE;
 IF v_account IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='The Guest needs an approved refund account.'; END IF;
 INSERT INTO guest_refunds(booking_id,guest_id,refund_account_id,bank_id,bank_name,account_no,account_name,amount,currency,reference,reason,created_by,created_at,updated_at)
 SELECT p_booking,v_guest,id,bank_id,bank_name,account_no,account_name,v_amount,v_currency,CONCAT('H2H-RF-',p_booking,'-',UPPER(SUBSTRING(REPLACE(UUID(),'-',''),1,6))),TRIM(p_reason),p_admin,NOW(6),NOW(6) FROM guest_refund_accounts WHERE id=v_account;
 SET v_refund=LAST_INSERT_ID();
 INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at) VALUES(p_admin,'create_guest_refund','booking',p_booking,JSON_OBJECT('refund_id',v_refund,'amount',v_amount,'method',v_method),NOW(6));
 INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at) VALUES(v_guest,p_booking,'in_app','guest_refund_created','Đã tạo lệnh hoàn tiền',CONCAT('Home2Home đã tạo lệnh hoàn ',REPLACE(FORMAT(v_amount,0),',','.'),'đ cho booking #',p_booking,'.'),'sent',1,NOW(6));
 COMMIT;
 SELECT * FROM guest_refunds WHERE id=v_refund;
END$$

CREATE OR REPLACE PROCEDURE NMT_sp_admin_guest_refund_mark_paid(IN p_refund BIGINT UNSIGNED,IN p_admin BIGINT UNSIGNED,
 IN p_transaction_no VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,IN p_note VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
 DECLARE v_guest BIGINT UNSIGNED; DECLARE v_booking BIGINT UNSIGNED; DECLARE v_amount DECIMAL(12,2); DECLARE v_current DECIMAL(12,2);
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
 START TRANSACTION;
 CALL NTK_sp_admin_assert_role(p_admin);
 IF CHAR_LENGTH(TRIM(COALESCE(p_transaction_no,''))) NOT BETWEEN 3 AND 100 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A bank transaction number is required.'; END IF;
 SELECT r.guest_id,r.booking_id,r.amount,COALESCE(c.refund_amount,pay.amount_due) INTO v_guest,v_booking,v_amount,v_current FROM guest_refunds r JOIN booking_payments pay ON pay.booking_id=r.booking_id LEFT JOIN booking_cancellations c ON c.booking_id=r.booking_id WHERE r.id=p_refund AND r.status='pending' AND pay.status='paid' FOR UPDATE;
 IF v_guest IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Refund order is unavailable or its payment changed.'; END IF;
 IF v_guest=p_admin THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Another Admin must complete your refund.'; END IF;
 IF v_amount<>v_current THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='The refundable amount changed. Do not transfer this order.'; END IF;
 IF EXISTS(SELECT 1 FROM guest_refunds WHERE bank_transaction_no=TRIM(p_transaction_no) AND id<>p_refund) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='This bank transaction number is already in use.'; END IF;
 UPDATE guest_refunds SET status='paid',bank_transaction_no=TRIM(p_transaction_no),note=TRIM(COALESCE(p_note,'')),paid_by=p_admin,paid_at=NOW(6),updated_at=NOW(6) WHERE id=p_refund;
 UPDATE booking_payments SET status='refunded',refunded_at=NOW(6),refunded_by=p_admin,note=CONCAT((SELECT reason FROM guest_refunds WHERE id=p_refund),' · Bank transaction: ',TRIM(p_transaction_no)),updated_at=NOW(6) WHERE booking_id=v_booking;
 INSERT INTO booking_payment_events(booking_id,actor_id,from_status,to_status,note,created_at) VALUES(v_booking,p_admin,'paid','refunded',CONCAT('Bank transaction: ',TRIM(p_transaction_no)),NOW(6));
 INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at) VALUES(p_admin,'mark_guest_refund_paid','booking',v_booking,JSON_OBJECT('refund_id',p_refund,'amount',v_amount,'transaction_no',TRIM(p_transaction_no)),NOW(6));
 INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at) VALUES(v_guest,v_booking,'in_app','payment_refunded','Đã hoàn tiền',CONCAT('Home2Home đã hoàn ',REPLACE(FORMAT(v_amount,0),',','.'),'đ cho booking #',v_booking,'. Mã giao dịch: ',TRIM(p_transaction_no),'.'),'sent',1,NOW(6));
 COMMIT;
END$$

CREATE OR REPLACE PROCEDURE NTK_sp_guest_refunds(IN p_guest BIGINT UNSIGNED)
BEGIN
 IF NOT EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_guest AND role='guest') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Guest access is required.'; END IF;
 SELECT r.*,l.title,CONCAT(REPEAT('*',GREATEST(0,CHAR_LENGTH(r.account_no)-4)),RIGHT(r.account_no,4)) account_no_masked FROM guest_refunds r JOIN bookings b ON b.id=r.booking_id JOIN listings l ON l.id=b.listing_id WHERE r.guest_id=p_guest ORDER BY r.created_at DESC LIMIT 100;
END$$
DELIMITER ;
