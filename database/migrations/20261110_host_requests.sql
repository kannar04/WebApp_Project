CREATE TABLE IF NOT EXISTS host_requests (
 user_id BIGINT UNSIGNED PRIMARY KEY,
 status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
 email VARCHAR(254) NOT NULL,
 phone VARCHAR(20) NOT NULL,
 is_debug BOOLEAN NOT NULL DEFAULT FALSE,
 reason VARCHAR(1000) NOT NULL DEFAULT '',
 reviewed_by BIGINT UNSIGNED NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 reviewed_at DATETIME(6) NULL,
 FOREIGN KEY(user_id) REFERENCES users(id),
 FOREIGN KEY(reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DELIMITER $$
CREATE OR REPLACE PROCEDURE NMT_sp_host_request_status(IN p_user BIGINT UNSIGNED)
BEGIN
 SELECT * FROM host_requests WHERE user_id=p_user;
END$$
CREATE OR REPLACE PROCEDURE NMT_sp_host_request_submit(IN p_user BIGINT UNSIGNED, IN p_debug BOOLEAN)
BEGIN
 DECLARE v_user BIGINT UNSIGNED;
 DECLARE v_own BOOLEAN DEFAULT FALSE;
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN IF @@in_transaction THEN IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT host_submit; RELEASE SAVEPOINT host_submit; END IF; END IF; RESIGNAL; END;
 SET v_own=(@@in_transaction=0);
 IF v_own THEN START TRANSACTION; ELSE SAVEPOINT host_submit; END IF;
 SELECT id INTO v_user FROM users WHERE id=p_user AND status='active' AND deleted_at IS NULL FOR UPDATE;
 IF v_user IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Account unavailable.'; END IF;
 IF EXISTS(SELECT 1 FROM user_roles WHERE user_id=p_user AND role='host') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Already a host.'; END IF;
 IF COALESCE((SELECT action FROM admin_audit_logs WHERE entity_type='user' AND entity_id=p_user AND action IN ('remove_host_role','grant_host_role') ORDER BY id DESC LIMIT 1),'')='remove_host_role' THEN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Host access was removed by an admin. Contact an admin.'; END IF;
 IF EXISTS(SELECT 1 FROM host_requests WHERE user_id=p_user AND status='pending') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Your host request is already pending.'; END IF;
 INSERT INTO host_requests(user_id,email,phone,is_debug) SELECT id,email,phone,p_debug FROM users WHERE id=p_user
 ON DUPLICATE KEY UPDATE status='pending',email=VALUES(email),phone=VALUES(phone),is_debug=VALUES(is_debug),reason='',reviewed_by=NULL,created_at=NOW(6),reviewed_at=NULL;
 IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT host_submit; END IF;
END$$
CREATE OR REPLACE PROCEDURE NMT_sp_admin_host_requests(IN p_admin BIGINT UNSIGNED)
BEGIN
 CALL NTK_sp_admin_assert_role(p_admin);
 SELECT r.*,u.full_name,u.status account_status FROM host_requests r JOIN users u ON u.id=r.user_id ORDER BY r.status='pending' DESC,r.created_at DESC;
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
CREATE OR REPLACE PROCEDURE LTP_sp_user_grant_host(IN p_user BIGINT UNSIGNED)
BEGIN
 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Host access requires admin approval. Submit a host request.';
END$$
DELIMITER ;
