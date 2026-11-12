-- Repair contact verification routines created under a different database collation.
-- Run after the table collation normalization migrations, against the selected app database.
-- Explicit declarations preserve ASCII challenge IDs/hashes and Unicode email comparisons.
-- Replaces routines only; existing challenges, users and verification records are preserved.
-- Repeatable. For rollback, restore these five routine definitions from a pre-run schema backup.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DELIMITER $$
CREATE OR REPLACE PROCEDURE NMT_sp_email_challenge_get(IN p_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin,IN p_actor BIGINT UNSIGNED)
BEGIN
 SELECT email,full_name,password_hash,phone FROM email_challenges
 WHERE id=p_id AND (user_id <=> p_actor) AND created_at>NOW()-INTERVAL 1 DAY;
END$$
CREATE OR REPLACE PROCEDURE NMT_sp_contact_status(IN p_user BIGINT UNSIGNED)
BEGIN
 SELECT u.phone,u.email,c.email_verified_at,
 (c.email=u.email AND c.email_verified_at IS NOT NULL) AS email_verified,
 (c.email=u.email AND c.verified_phone=u.phone AND c.phone_verified_at IS NOT NULL) AS phone_verified
 FROM users u LEFT JOIN contact_verifications c ON c.user_id=u.id
 WHERE u.id=p_user AND u.status='active' AND u.deleted_at IS NULL;
END$$
CREATE OR REPLACE PROCEDURE NMT_sp_email_challenge_issue(IN p_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin,IN p_user BIGINT UNSIGNED,IN p_email VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,IN p_name VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,IN p_password VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,IN p_phone VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,IN p_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin)
BEGIN
 -- Serializes requests for the same email, including requests from different sessions.
 DECLARE v_locked INT DEFAULT 0;
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN IF v_locked=1 THEN DO RELEASE_LOCK(CONCAT('h2h-email-',LEFT(SHA2(LOWER(p_email),256),48))); END IF; RESIGNAL; END;
 SELECT GET_LOCK(CONCAT('h2h-email-',LEFT(SHA2(LOWER(p_email),256),48)),3) INTO v_locked;
 IF COALESCE(v_locked,0)<>1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Vui lòng thử lại sau.'; END IF;
 IF EXISTS(SELECT 1 FROM email_challenges WHERE email=p_email AND created_at>NOW()-INTERVAL 60 SECOND) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Vui lòng chờ 60 giây trước khi gửi lại mã email.';
 END IF;
 IF p_user IS NOT NULL AND NOT EXISTS(SELECT 1 FROM users WHERE id=p_user AND email=p_email AND status='active' AND deleted_at IS NULL) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tài khoản không hợp lệ.';
 END IF;
 IF p_user IS NULL AND EXISTS(SELECT 1 FROM users WHERE email=p_email) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Email đã được sử dụng. Vui lòng đăng nhập hoặc khôi phục mật khẩu.';
 END IF;
 DELETE FROM email_challenges WHERE email=p_email OR expires_at<NOW()-INTERVAL 1 DAY;
 INSERT INTO email_challenges(id,user_id,email,full_name,password_hash,phone,code_hash,expires_at)
 VALUES(p_id,p_user,LOWER(p_email),p_name,p_password,NULLIF(p_phone,''),p_hash,NOW()+INTERVAL 10 MINUTE);
 DO RELEASE_LOCK(CONCAT('h2h-email-',LEFT(SHA2(LOWER(p_email),256),48)));
END$$
CREATE OR REPLACE PROCEDURE NMT_sp_email_challenge_consume(IN p_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin,IN p_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin,IN p_actor BIGINT UNSIGNED)
proc: BEGIN
 DECLARE v_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin; DECLARE v_user BIGINT UNSIGNED; DECLARE v_email VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; DECLARE v_name VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 DECLARE v_password VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; DECLARE v_phone VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; DECLARE v_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin; DECLARE v_attempts INT; DECLARE v_expires DATETIME(6);
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
 START TRANSACTION;
 SELECT id,user_id,email,full_name,password_hash,phone,code_hash,attempts,expires_at
 INTO v_id,v_user,v_email,v_name,v_password,v_phone,v_hash,v_attempts,v_expires
 FROM email_challenges WHERE id=p_id FOR UPDATE;
 IF v_id IS NULL OR NOT (v_user <=> p_actor) OR v_expires<NOW() OR v_attempts>=5 THEN
  COMMIT; SELECT 0 AS user_id; LEAVE proc;
 END IF;
 UPDATE email_challenges SET attempts=attempts+1 WHERE id=p_id;
 IF BINARY v_hash<>BINARY p_hash THEN COMMIT; SELECT 0 AS user_id; LEAVE proc; END IF;
 IF v_user IS NULL THEN
  CALL NMT_sp_user_insert(v_email,v_password,v_name,v_phone);
  SELECT id INTO v_user FROM users WHERE email=v_email;
 ELSEIF NOT EXISTS(SELECT 1 FROM users WHERE id=v_user AND email=v_email AND status='active' AND deleted_at IS NULL) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tài khoản không hợp lệ.';
 END IF;
 INSERT INTO contact_verifications(user_id,email,email_verified_at) VALUES(v_user,v_email,NOW(6))
 ON DUPLICATE KEY UPDATE email=VALUES(email),email_verified_at=VALUES(email_verified_at);
 DELETE FROM email_challenges WHERE id=p_id;
 COMMIT;
 SELECT v_user AS user_id;
END$$
CREATE OR REPLACE PROCEDURE NMT_sp_phone_verify(IN p_user BIGINT UNSIGNED,IN p_phone VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
 UPDATE contact_verifications c JOIN users u ON u.id=c.user_id
 SET c.verified_phone=p_phone,c.phone_verified_at=NOW(6)
 WHERE c.user_id=p_user AND c.email=u.email AND u.phone=p_phone AND u.status='active' AND u.deleted_at IS NULL;
 IF ROW_COUNT()=0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Thông tin đã thay đổi. Hãy lưu hồ sơ và xác minh lại.'; END IF;
END$$
DELIMITER ;

