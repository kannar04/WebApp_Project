-- Semi-automatic Host payouts: verified payout account, Admin-created VietQR order and auditable completion.
CREATE TABLE IF NOT EXISTS host_payout_accounts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 host_id BIGINT UNSIGNED NOT NULL UNIQUE,
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
 CONSTRAINT fk_host_payout_account_host FOREIGN KEY(host_id) REFERENCES users(id),
 CONSTRAINT fk_host_payout_account_reviewer FOREIGN KEY(reviewed_by) REFERENCES users(id),
 INDEX idx_host_payout_account_status(status,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS host_payouts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 booking_id BIGINT UNSIGNED NOT NULL UNIQUE,
 host_id BIGINT UNSIGNED NOT NULL,
 payout_account_id BIGINT UNSIGNED NOT NULL,
 bank_id VARCHAR(20) NOT NULL,
 bank_name VARCHAR(100) NOT NULL,
 account_no VARCHAR(19) NOT NULL,
 account_name VARCHAR(150) NOT NULL,
 gross_amount DECIMAL(12,2) NOT NULL,
 fee_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
 net_amount DECIMAL(12,2) NOT NULL,
 currency CHAR(3) NOT NULL DEFAULT 'VND',
 reference VARCHAR(40) NOT NULL UNIQUE,
 status ENUM('pending','paid') NOT NULL DEFAULT 'pending',
 bank_transaction_no VARCHAR(100) NULL,
 note VARCHAR(500) NOT NULL DEFAULT '',
 created_by BIGINT UNSIGNED NOT NULL,
 paid_by BIGINT UNSIGNED NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 paid_at DATETIME(6) NULL,
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 CONSTRAINT fk_host_payout_booking FOREIGN KEY(booking_id) REFERENCES bookings(id),
 CONSTRAINT fk_host_payout_host FOREIGN KEY(host_id) REFERENCES users(id),
 CONSTRAINT fk_host_payout_account FOREIGN KEY(payout_account_id) REFERENCES host_payout_accounts(id),
 CONSTRAINT fk_host_payout_creator FOREIGN KEY(created_by) REFERENCES users(id),
 CONSTRAINT fk_host_payout_payer FOREIGN KEY(paid_by) REFERENCES users(id),
 UNIQUE KEY uq_host_payout_transaction(bank_transaction_no),
 INDEX idx_host_payout_status(status,created_at),
 INDEX idx_host_payout_host(host_id,status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE UNIQUE INDEX IF NOT EXISTS uq_host_payout_transaction ON host_payouts(bank_transaction_no);

DELIMITER $$
CREATE OR REPLACE PROCEDURE DST_sp_host_payout_account_get(IN p_host BIGINT UNSIGNED)
BEGIN
 IF NOT EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_host AND role='host') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Host access is required.'; END IF;
 SELECT * FROM host_payout_accounts WHERE host_id=p_host;
END$$

CREATE OR REPLACE PROCEDURE LTP_sp_host_payout_account_save(IN p_host BIGINT UNSIGNED,
 IN p_bank_id VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
 IN p_bank_name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
 IN p_account_no VARCHAR(19) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
 IN p_account_name VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
 DECLARE v_own BOOLEAN DEFAULT FALSE;
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN IF @@in_transaction THEN IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT payout_account_save; RELEASE SAVEPOINT payout_account_save; END IF; END IF; RESIGNAL; END;
 SET v_own=(@@in_transaction=0); IF v_own THEN START TRANSACTION; ELSE SAVEPOINT payout_account_save; END IF;
 IF NOT EXISTS(SELECT 1 FROM users u JOIN user_roles r ON r.user_id=u.id AND r.role='host' WHERE u.id=p_host AND u.status='active' AND u.deleted_at IS NULL) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Host account is unavailable.'; END IF;
 IF NOT EXISTS(SELECT 1 FROM users u JOIN identity_verifications v ON v.user_id=u.id AND v.status='approved' WHERE u.id=p_host AND REGEXP_REPLACE(u.phone,'[[:space:].()-]','') IN (v.phone,CONCAT('+84',SUBSTRING(v.phone,2)),CONCAT('84',SUBSTRING(v.phone,2)))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Current identity verification is required before adding a payout account.'; END IF;
 IF p_bank_id IS NULL OR p_bank_id NOT REGEXP '^[A-Za-z0-9]{2,20}$' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid VietQR bank code.'; END IF;
 IF p_bank_name IS NULL OR CHAR_LENGTH(TRIM(p_bank_name)) NOT BETWEEN 2 AND 100 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid bank name.'; END IF;
 IF p_account_no IS NULL OR p_account_no NOT REGEXP '^[0-9]{4,19}$' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bank account number must contain 4 to 19 digits.'; END IF;
 IF p_account_name IS NULL OR CHAR_LENGTH(TRIM(p_account_name)) NOT BETWEEN 2 AND 150 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid account holder name.'; END IF;
 INSERT INTO host_payout_accounts(host_id,bank_id,bank_name,account_no,account_name,status,rejection_reason,reviewed_by,reviewed_at,created_at,updated_at)
 VALUES(p_host,UPPER(TRIM(p_bank_id)),TRIM(p_bank_name),TRIM(p_account_no),UPPER(TRIM(p_account_name)),'pending','',NULL,NULL,NOW(6),NOW(6))
 ON DUPLICATE KEY UPDATE bank_id=VALUES(bank_id),bank_name=VALUES(bank_name),account_no=VALUES(account_no),account_name=VALUES(account_name),status='pending',rejection_reason='',reviewed_by=NULL,reviewed_at=NULL,updated_at=NOW(6);
 IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT payout_account_save; END IF;
END$$

CREATE OR REPLACE PROCEDURE NMT_sp_admin_payout_accounts(IN p_admin BIGINT UNSIGNED,
 IN p_status VARCHAR(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
 CALL NTK_sp_admin_assert_role(p_admin);
 IF COALESCE(p_status,'') NOT IN ('','pending','approved','rejected') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid payout account status.'; END IF;
 SELECT a.*,u.full_name host_name,u.email host_email,CONCAT(REPEAT('*',GREATEST(0,CHAR_LENGTH(a.account_no)-4)),RIGHT(a.account_no,4)) account_no_masked,
  r.full_name reviewer_name
 FROM host_payout_accounts a JOIN users u ON u.id=a.host_id LEFT JOIN users r ON r.id=a.reviewed_by
 WHERE p_status='' OR a.status=p_status ORDER BY a.status='pending' DESC,a.updated_at DESC LIMIT 200;
END$$

CREATE OR REPLACE PROCEDURE NTK_sp_admin_payout_account_decide(IN p_account BIGINT UNSIGNED,IN p_admin BIGINT UNSIGNED,
 IN p_decision VARCHAR(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
 IN p_reason VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
 DECLARE v_host BIGINT UNSIGNED; DECLARE v_own BOOLEAN DEFAULT FALSE;
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN IF @@in_transaction THEN IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT payout_account_decide; RELEASE SAVEPOINT payout_account_decide; END IF; END IF; RESIGNAL; END;
 SET v_own=(@@in_transaction=0); IF v_own THEN START TRANSACTION; ELSE SAVEPOINT payout_account_decide; END IF;
 CALL NTK_sp_admin_assert_role(p_admin);
 IF p_decision NOT IN ('approved','rejected') OR p_decision IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid payout account decision.'; END IF;
 IF p_decision='rejected' AND TRIM(COALESCE(p_reason,''))='' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A rejection reason is required.'; END IF;
 SELECT host_id INTO v_host FROM host_payout_accounts WHERE id=p_account AND status='pending' FOR UPDATE;
 IF v_host IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Payout account is no longer pending.'; END IF;
 IF v_host=p_admin THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='You cannot review your own payout account.'; END IF;
 UPDATE host_payout_accounts SET status=p_decision,rejection_reason=IF(p_decision='rejected',TRIM(p_reason),''),reviewed_by=p_admin,reviewed_at=NOW(6),updated_at=NOW(6) WHERE id=p_account;
 INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at) VALUES(p_admin,IF(p_decision='approved','approve_payout_account','reject_payout_account'),'user',v_host,JSON_OBJECT('payout_account_id',p_account,'reason',TRIM(COALESCE(p_reason,''))),NOW(6));
 INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at) VALUES(v_host,NULL,'in_app','payout_account_decision','Tài khoản nhận tiền',IF(p_decision='approved','Tài khoản nhận tiền của bạn đã được Admin duyệt.',CONCAT('Tài khoản nhận tiền chưa được duyệt. Lý do: ',TRIM(p_reason))),'sent',1,NOW(6));
 IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT payout_account_decide; END IF;
END$$

CREATE OR REPLACE PROCEDURE NMT_sp_host_payout_summary(IN p_host BIGINT UNSIGNED,IN p_fee_pct DECIMAL(5,2))
BEGIN
 IF NOT EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_host AND role='host') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Host access is required.'; END IF;
 SELECT
  COALESCE((SELECT SUM(net_amount) FROM host_payouts WHERE host_id=p_host AND status='paid'),0) paid_total,
  COALESCE((SELECT SUM(net_amount) FROM host_payouts WHERE host_id=p_host AND status='pending'),0) pending_total,
  COALESCE((SELECT SUM(ROUND(pay.amount_due*(1-GREATEST(0,LEAST(100,p_fee_pct))/100),2)) FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN booking_payments pay ON pay.booking_id=b.id LEFT JOIN host_payouts hp ON hp.booking_id=b.id WHERE l.host_id=p_host AND b.status='completed' AND pay.status='paid' AND hp.id IS NULL),0) eligible_total;
END$$

CREATE OR REPLACE PROCEDURE NTK_sp_host_payouts(IN p_host BIGINT UNSIGNED)
BEGIN
 IF NOT EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_host AND role='host') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Host access is required.'; END IF;
 SELECT p.id,p.booking_id,p.gross_amount,p.fee_amount,p.net_amount,p.currency,p.reference,p.status,p.bank_name,
  CONCAT(REPEAT('*',GREATEST(0,CHAR_LENGTH(p.account_no)-4)),RIGHT(p.account_no,4)) account_no_masked,p.bank_transaction_no,p.note,p.created_at,p.paid_at,l.title
 FROM host_payouts p JOIN bookings b ON b.id=p.booking_id JOIN listings l ON l.id=b.listing_id WHERE p.host_id=p_host ORDER BY p.created_at DESC LIMIT 200;
END$$

CREATE OR REPLACE PROCEDURE DST_sp_admin_host_payouts(IN p_admin BIGINT UNSIGNED,IN p_fee_pct DECIMAL(5,2))
BEGIN
 CALL NTK_sp_admin_assert_role(p_admin);
 SELECT 'payout' row_type,p.id payout_id,p.booking_id,p.host_id,u.full_name host_name,u.email host_email,l.title,b.status booking_status,pay.status payment_status,
  p.gross_amount,p.fee_amount,p.net_amount,p.currency,p.reference,p.status payout_status,p.bank_id,p.bank_name,p.account_no,p.account_name,
  'approved' account_status,p.bank_transaction_no,p.note,p.created_at,p.paid_at
 FROM host_payouts p JOIN bookings b ON b.id=p.booking_id JOIN listings l ON l.id=b.listing_id JOIN users u ON u.id=p.host_id JOIN booking_payments pay ON pay.booking_id=b.id
 UNION ALL
 SELECT 'eligible',NULL,b.id,l.host_id,u.full_name,u.email,l.title,b.status,pay.status,pay.amount_due,
  ROUND(pay.amount_due*GREATEST(0,LEAST(100,p_fee_pct))/100,2),ROUND(pay.amount_due*(1-GREATEST(0,LEAST(100,p_fee_pct))/100),2),l.currency,NULL,NULL,
  a.bank_id,a.bank_name,a.account_no,a.account_name,COALESCE(a.status,'missing'),NULL,'',NULL,NULL
 FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN users u ON u.id=l.host_id JOIN booking_payments pay ON pay.booking_id=b.id
 LEFT JOIN host_payouts p ON p.booking_id=b.id LEFT JOIN host_payout_accounts a ON a.host_id=l.host_id
 WHERE b.status='completed' AND pay.status='paid' AND p.id IS NULL
 ORDER BY row_type='eligible' DESC,created_at DESC,booking_id DESC LIMIT 300;
END$$

CREATE OR REPLACE PROCEDURE LTP_sp_admin_host_payout_create(IN p_booking BIGINT UNSIGNED,IN p_admin BIGINT UNSIGNED,IN p_fee_pct DECIMAL(5,2))
BEGIN
 DECLARE v_host BIGINT UNSIGNED; DECLARE v_account BIGINT UNSIGNED; DECLARE v_gross DECIMAL(12,2); DECLARE v_fee DECIMAL(12,2); DECLARE v_currency CHAR(3); DECLARE v_payout BIGINT UNSIGNED; DECLARE v_own BOOLEAN DEFAULT FALSE;
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN IF @@in_transaction THEN IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT payout_create; RELEASE SAVEPOINT payout_create; END IF; END IF; RESIGNAL; END;
 SET v_own=(@@in_transaction=0); IF v_own THEN START TRANSACTION; ELSE SAVEPOINT payout_create; END IF;
 CALL NTK_sp_admin_assert_role(p_admin);
 IF p_fee_pct IS NULL OR p_fee_pct<0 OR p_fee_pct>100 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid platform fee percentage.'; END IF;
 SELECT l.host_id,pay.amount_due,l.currency INTO v_host,v_gross,v_currency FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN booking_payments pay ON pay.booking_id=b.id WHERE b.id=p_booking AND b.status='completed' AND pay.status='paid' FOR UPDATE;
 IF v_host IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Only a completed and paid booking can be paid out.'; END IF;
 IF v_host=p_admin THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Another Admin must create your Host payout.'; END IF;
 IF EXISTS(SELECT 1 FROM host_payouts WHERE booking_id=p_booking) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A payout already exists for this booking.'; END IF;
 SELECT id INTO v_account FROM host_payout_accounts WHERE host_id=v_host AND status='approved' FOR UPDATE;
 IF v_account IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='The Host needs an approved payout account.'; END IF;
 SET v_fee=ROUND(v_gross*p_fee_pct/100,2);
 INSERT INTO host_payouts(booking_id,host_id,payout_account_id,bank_id,bank_name,account_no,account_name,gross_amount,fee_amount,net_amount,currency,reference,created_by,created_at,updated_at)
 SELECT p_booking,v_host,id,bank_id,bank_name,account_no,account_name,v_gross,v_fee,v_gross-v_fee,v_currency,CONCAT('H2H-',p_booking,'-',UPPER(SUBSTRING(REPLACE(UUID(),'-',''),1,8))),p_admin,NOW(6),NOW(6) FROM host_payout_accounts WHERE id=v_account;
 SET v_payout=LAST_INSERT_ID();
 INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at) VALUES(p_admin,'create_host_payout','booking',p_booking,JSON_OBJECT('payout_id',v_payout,'gross',v_gross,'fee',v_fee,'net',v_gross-v_fee),NOW(6));
 INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at) VALUES(v_host,p_booking,'in_app','host_payout_created','Đã tạo lệnh chi trả',CONCAT('Home2Home đã tạo lệnh chi trả ',REPLACE(FORMAT(v_gross-v_fee,0),',','.'),'đ cho booking #',p_booking,'.'),'sent',1,NOW(6));
 SELECT * FROM host_payouts WHERE id=v_payout;
 IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT payout_create; END IF;
END$$

CREATE OR REPLACE PROCEDURE NMT_sp_admin_host_payout_mark_paid(IN p_payout BIGINT UNSIGNED,IN p_admin BIGINT UNSIGNED,
 IN p_transaction_no VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
 IN p_note VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
 DECLARE v_host BIGINT UNSIGNED; DECLARE v_booking BIGINT UNSIGNED; DECLARE v_amount DECIMAL(12,2); DECLARE v_own BOOLEAN DEFAULT FALSE;
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN IF @@in_transaction THEN IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT payout_paid; RELEASE SAVEPOINT payout_paid; END IF; END IF; RESIGNAL; END;
 SET v_own=(@@in_transaction=0); IF v_own THEN START TRANSACTION; ELSE SAVEPOINT payout_paid; END IF;
 CALL NTK_sp_admin_assert_role(p_admin);
 IF p_transaction_no IS NULL OR CHAR_LENGTH(TRIM(p_transaction_no)) NOT BETWEEN 3 AND 100 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A bank transaction number is required.'; END IF;
 IF CHAR_LENGTH(TRIM(COALESCE(p_note,'')))>500 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Payout note is too long.'; END IF;
 SELECT p.host_id,p.booking_id,p.net_amount INTO v_host,v_booking,v_amount FROM host_payouts p JOIN booking_payments pay ON pay.booking_id=p.booking_id WHERE p.id=p_payout AND p.status='pending' AND pay.status='paid' FOR UPDATE;
 IF v_host IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Payout is unavailable or the booking payment changed.'; END IF;
 IF v_host=p_admin THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Another Admin must complete your Host payout.'; END IF;
 IF EXISTS(SELECT 1 FROM host_payouts WHERE bank_transaction_no=TRIM(p_transaction_no) AND id<>p_payout) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='This bank transaction number is already in use.'; END IF;
 UPDATE host_payouts SET status='paid',bank_transaction_no=TRIM(p_transaction_no),note=TRIM(COALESCE(p_note,'')),paid_by=p_admin,paid_at=NOW(6),updated_at=NOW(6) WHERE id=p_payout;
 INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at) VALUES(p_admin,'mark_host_payout_paid','booking',v_booking,JSON_OBJECT('payout_id',p_payout,'amount',v_amount,'transaction_no',TRIM(p_transaction_no)),NOW(6));
 INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at) VALUES(v_host,v_booking,'in_app','host_payout_paid','Đã chuyển tiền cho Host',CONCAT('Home2Home đã chuyển ',REPLACE(FORMAT(v_amount,0),',','.'),'đ cho booking #',v_booking,'. Mã giao dịch: ',TRIM(p_transaction_no),'.'),'sent',1,NOW(6));
 IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT payout_paid; END IF;
END$$

CREATE OR REPLACE PROCEDURE NTK_sp_admin_host_payout_totals(IN p_admin BIGINT UNSIGNED)
BEGIN
 CALL NTK_sp_admin_assert_role(p_admin);
 SELECT COALESCE(SUM(status='pending'),0) pending_count,COALESCE(SUM(CASE WHEN status='pending' THEN net_amount ELSE 0 END),0) pending_amount,
  COALESCE(SUM(status='paid'),0) paid_count,COALESCE(SUM(CASE WHEN status='paid' THEN net_amount ELSE 0 END),0) paid_amount FROM host_payouts;
END$$
DELIMITER ;
