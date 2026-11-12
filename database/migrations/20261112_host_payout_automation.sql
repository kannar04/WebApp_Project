-- Global Host fee and hourly payout-order automation. Existing payout snapshots remain unchanged.
CREATE TABLE IF NOT EXISTS host_payout_settings (
 id TINYINT UNSIGNED PRIMARY KEY,
 fee_percent DECIMAL(5,2) NOT NULL,
 updated_by BIGINT UNSIGNED NULL,
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 CONSTRAINT fk_host_payout_settings_admin FOREIGN KEY(updated_by) REFERENCES users(id),
 CONSTRAINT chk_host_payout_settings_singleton CHECK(id=1),
 CONSTRAINT chk_host_payout_settings_fee CHECK(fee_percent BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO host_payout_settings(id,fee_percent,updated_by,updated_at) VALUES(1,10.00,NULL,NOW(6)) ON DUPLICATE KEY UPDATE id=VALUES(id);

DELIMITER $$
CREATE OR REPLACE PROCEDURE DST_sp_host_payout_settings_get()
BEGIN
 SELECT fee_percent,updated_by,updated_at FROM host_payout_settings WHERE id=1;
END$$

CREATE OR REPLACE PROCEDURE NMT_sp_host_payout_summary(IN p_host BIGINT UNSIGNED)
BEGIN
 IF NOT EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_host AND role='host') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Host access is required.'; END IF;
 SELECT
  COALESCE((SELECT SUM(net_amount) FROM host_payouts WHERE host_id=p_host AND status='paid'),0) paid_total,
  COALESCE((SELECT SUM(net_amount) FROM host_payouts WHERE host_id=p_host AND status='pending'),0) pending_total,
  COALESCE((SELECT SUM(ROUND(pay.amount_due*(1-(SELECT fee_percent FROM host_payout_settings WHERE id=1)/100),2)) FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN booking_payments pay ON pay.booking_id=b.id LEFT JOIN host_payouts hp ON hp.booking_id=b.id WHERE l.host_id=p_host AND b.status='completed' AND pay.status='paid' AND hp.id IS NULL),0) eligible_total;
END$$

CREATE OR REPLACE PROCEDURE DST_sp_admin_host_payouts(IN p_admin BIGINT UNSIGNED)
BEGIN
 CALL NTK_sp_admin_assert_role(p_admin);
 SELECT 'payout' row_type,p.id payout_id,p.booking_id,p.host_id,u.full_name host_name,u.email host_email,l.title,b.status booking_status,pay.status payment_status,
  p.gross_amount,p.fee_amount,p.net_amount,p.currency,p.reference,p.status payout_status,p.bank_id,p.bank_name,p.account_no,p.account_name,
  'approved' account_status,p.bank_transaction_no,p.note,p.created_at,p.paid_at
 FROM host_payouts p JOIN bookings b ON b.id=p.booking_id JOIN listings l ON l.id=b.listing_id JOIN users u ON u.id=p.host_id JOIN booking_payments pay ON pay.booking_id=b.id
 UNION ALL
 SELECT 'eligible',NULL,b.id,l.host_id,u.full_name,u.email,l.title,b.status,pay.status,pay.amount_due,
  ROUND(pay.amount_due*(SELECT fee_percent FROM host_payout_settings WHERE id=1)/100,2),ROUND(pay.amount_due*(1-(SELECT fee_percent FROM host_payout_settings WHERE id=1)/100),2),l.currency,NULL,NULL,
  a.bank_id,a.bank_name,a.account_no,a.account_name,COALESCE(a.status,'missing'),NULL,'',NULL,NULL
 FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN users u ON u.id=l.host_id JOIN booking_payments pay ON pay.booking_id=b.id
 LEFT JOIN host_payouts p ON p.booking_id=b.id LEFT JOIN host_payout_accounts a ON a.host_id=l.host_id
 WHERE b.status='completed' AND pay.status='paid' AND p.id IS NULL
 ORDER BY row_type='eligible' DESC,created_at DESC,booking_id DESC LIMIT 300;
END$$

CREATE OR REPLACE PROCEDURE LTP_sp_admin_host_payout_create(IN p_booking BIGINT UNSIGNED,IN p_admin BIGINT UNSIGNED)
BEGIN
 DECLARE v_host BIGINT UNSIGNED; DECLARE v_account BIGINT UNSIGNED; DECLARE v_gross DECIMAL(12,2); DECLARE v_fee DECIMAL(12,2); DECLARE v_fee_pct DECIMAL(5,2); DECLARE v_currency CHAR(3); DECLARE v_payout BIGINT UNSIGNED; DECLARE v_own BOOLEAN DEFAULT FALSE;
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN IF @@in_transaction THEN IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT payout_create; RELEASE SAVEPOINT payout_create; END IF; END IF; RESIGNAL; END;
 SET v_own=(@@in_transaction=0); IF v_own THEN START TRANSACTION; ELSE SAVEPOINT payout_create; END IF;
 CALL NTK_sp_admin_assert_role(p_admin);
 SELECT l.host_id,pay.amount_due,l.currency INTO v_host,v_gross,v_currency FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN booking_payments pay ON pay.booking_id=b.id WHERE b.id=p_booking AND b.status='completed' AND pay.status='paid' FOR UPDATE;
 IF v_host IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Only a completed and paid booking can be paid out.'; END IF;
 IF v_host=p_admin THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Another Admin must create your Host payout.'; END IF;
 IF EXISTS(SELECT 1 FROM host_payouts WHERE booking_id=p_booking) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A payout already exists for this booking.'; END IF;
 SELECT id INTO v_account FROM host_payout_accounts WHERE host_id=v_host AND status='approved' FOR UPDATE;
 IF v_account IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='The Host needs an approved payout account.'; END IF;
 SELECT fee_percent INTO v_fee_pct FROM host_payout_settings WHERE id=1 FOR UPDATE;
 SET v_fee=ROUND(v_gross*v_fee_pct/100,2);
 INSERT INTO host_payouts(booking_id,host_id,payout_account_id,bank_id,bank_name,account_no,account_name,gross_amount,fee_amount,net_amount,currency,reference,created_by,created_at,updated_at)
 SELECT p_booking,v_host,id,bank_id,bank_name,account_no,account_name,v_gross,v_fee,v_gross-v_fee,v_currency,CONCAT('H2H-',p_booking,'-',UPPER(SUBSTRING(REPLACE(UUID(),'-',''),1,8))),p_admin,NOW(6),NOW(6) FROM host_payout_accounts WHERE id=v_account;
 SET v_payout=LAST_INSERT_ID();
 INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at) VALUES(p_admin,'create_host_payout','booking',p_booking,JSON_OBJECT('payout_id',v_payout,'gross',v_gross,'fee_percent',v_fee_pct,'fee',v_fee,'net',v_gross-v_fee),NOW(6));
 INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at) VALUES(v_host,p_booking,'in_app','host_payout_created','Đã tạo lệnh chi trả',CONCAT('Home2Home đã tạo lệnh chi trả ',REPLACE(FORMAT(v_gross-v_fee,0),',','.'),'đ cho booking #',p_booking,'.'),'sent',1,NOW(6));
 SELECT * FROM host_payouts WHERE id=v_payout;
 IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT payout_create; END IF;
END$$

CREATE OR REPLACE PROCEDURE LTP_sp_admin_host_payout_settings_save(IN p_admin BIGINT UNSIGNED,IN p_fee_pct DECIMAL(5,2))
BEGIN
 CALL NTK_sp_admin_assert_role(p_admin);
 IF p_fee_pct IS NULL OR p_fee_pct<0 OR p_fee_pct>100 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid platform fee percentage.'; END IF;
 UPDATE host_payout_settings SET fee_percent=p_fee_pct,updated_by=p_admin,updated_at=NOW(6) WHERE id=1;
 INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at) VALUES(p_admin,'update_host_payout_fee','system',1,JSON_OBJECT('fee_percent',p_fee_pct),NOW(6));
END$$

CREATE OR REPLACE PROCEDURE NMT_sp_hourly_host_payout_candidates(IN p_admin BIGINT UNSIGNED)
BEGIN
 CALL NTK_sp_admin_assert_role(p_admin);
 SELECT b.id booking_id FROM bookings b JOIN listings l ON l.id=b.listing_id JOIN booking_payments pay ON pay.booking_id=b.id JOIN host_payout_accounts a ON a.host_id=l.host_id AND a.status='approved' LEFT JOIN host_payouts p ON p.booking_id=b.id WHERE b.status='completed' AND pay.status='paid' AND p.id IS NULL AND l.host_id<>p_admin ORDER BY b.id LIMIT 500;
END$$

CREATE OR REPLACE PROCEDURE NTK_sp_host_payout_scheduler_lock(IN p_acquire BOOLEAN)
BEGIN
 IF p_acquire THEN SELECT GET_LOCK(CONCAT('h2h-host-payouts:',MD5(DATABASE())),0) locked;
 ELSE SELECT RELEASE_LOCK(CONCAT('h2h-host-payouts:',MD5(DATABASE()))) locked; END IF;
END$$
DELIMITER ;
