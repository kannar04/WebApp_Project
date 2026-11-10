-- Additive contact verification. Existing accounts remain usable; booking requires verified contacts.
CREATE TABLE IF NOT EXISTS contact_verifications (
 user_id BIGINT UNSIGNED PRIMARY KEY,
 email VARCHAR(254) NOT NULL,
 email_verified_at DATETIME(6) NOT NULL,
 verified_phone VARCHAR(20) NULL,
 phone_verified_at DATETIME(6) NULL,
 FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS email_challenges (
 id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 user_id BIGINT UNSIGNED NULL,
 email VARCHAR(254) NOT NULL,
 full_name VARCHAR(150) NOT NULL,
 password_hash VARCHAR(255) NULL,
 phone VARCHAR(20) NULL,
 code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
 expires_at DATETIME(6) NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 INDEX ix_challenge_email(email,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DELIMITER $$
CREATE OR REPLACE PROCEDURE NMT_sp_email_challenge_get(IN p_id CHAR(64),IN p_actor BIGINT UNSIGNED)
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
CREATE OR REPLACE PROCEDURE NMT_sp_email_challenge_issue(IN p_id CHAR(64),IN p_user BIGINT UNSIGNED,IN p_email VARCHAR(254),IN p_name VARCHAR(150),IN p_password VARCHAR(255),IN p_phone VARCHAR(20),IN p_hash CHAR(64))
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
CREATE OR REPLACE PROCEDURE NMT_sp_email_challenge_consume(IN p_id CHAR(64),IN p_hash CHAR(64),IN p_actor BIGINT UNSIGNED)
proc: BEGIN
 DECLARE v_id CHAR(64); DECLARE v_user BIGINT UNSIGNED; DECLARE v_email VARCHAR(254); DECLARE v_name VARCHAR(150);
 DECLARE v_password VARCHAR(255); DECLARE v_phone VARCHAR(20); DECLARE v_hash CHAR(64); DECLARE v_attempts INT; DECLARE v_expires DATETIME(6);
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
CREATE OR REPLACE PROCEDURE NMT_sp_phone_verify(IN p_user BIGINT UNSIGNED,IN p_phone VARCHAR(20))
BEGIN
 UPDATE contact_verifications c JOIN users u ON u.id=c.user_id
 SET c.verified_phone=p_phone,c.phone_verified_at=NOW(6)
 WHERE c.user_id=p_user AND c.email=u.email AND u.phone=p_phone AND u.status='active' AND u.deleted_at IS NULL;
 IF ROW_COUNT()=0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Thông tin đã thay đổi. Hãy lưu hồ sơ và xác minh lại.'; END IF;
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS direct_conversations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 member_a BIGINT UNSIGNED NOT NULL,
 member_b BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY uq_direct_members(member_a,member_b),
 FOREIGN KEY(member_a) REFERENCES users(id), FOREIGN KEY(member_b) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS direct_messages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 conversation_id BIGINT UNSIGNED NOT NULL,
 sender_id BIGINT UNSIGNED NOT NULL,
 body TEXT NOT NULL,
 read_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 INDEX ix_direct_thread(conversation_id,id),
 FOREIGN KEY(conversation_id) REFERENCES direct_conversations(id), FOREIGN KEY(sender_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DELIMITER $$
CREATE OR REPLACE PROCEDURE NMT_sp_direct_open(IN p_actor BIGINT UNSIGNED,IN p_listing BIGINT UNSIGNED)
BEGIN
 DECLARE v_other BIGINT UNSIGNED;
 IF NOT EXISTS(SELECT 1 FROM users WHERE id=p_actor AND status='active' AND deleted_at IS NULL) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Vui lòng đăng nhập lại.';
 END IF;
 IF p_listing>0 THEN
  SELECT l.host_id INTO v_other FROM listings l JOIN users u ON u.id=l.host_id
  WHERE l.id=p_listing AND l.moderation_status='approved' AND l.is_visible=1 AND COALESCE(l.admin_hidden,0)=0 AND l.deleted_at IS NULL
  AND u.status='active' AND u.deleted_at IS NULL AND EXISTS(SELECT 1 FROM user_roles WHERE user_id=l.host_id AND role='host');
 ELSE
  SELECT MIN(u.id) INTO v_other FROM users u JOIN user_roles r ON r.user_id=u.id
  WHERE r.role='admin' AND u.id<>p_actor AND u.status='active' AND u.deleted_at IS NULL;
 END IF;
 IF v_other IS NULL OR v_other=p_actor THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không có người nhận phù hợp.'; END IF;
 INSERT INTO direct_conversations(member_a,member_b) VALUES(LEAST(p_actor,v_other),GREATEST(p_actor,v_other))
 ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id);
 SELECT id FROM direct_conversations WHERE member_a=LEAST(p_actor,v_other) AND member_b=GREATEST(p_actor,v_other);
END$$
CREATE OR REPLACE PROCEDURE NMT_sp_direct_state(IN p_chat BIGINT UNSIGNED,IN p_actor BIGINT UNSIGNED)
BEGIN
 SELECT c.id,u.full_name AS other_name,'guest' AS my_role,'pending' AS status,
 (u.status='active' AND u.deleted_at IS NULL) AS can_send, (u.deleted_at IS NOT NULL) AS other_deleted
 FROM direct_conversations c JOIN users u ON u.id=IF(c.member_a=p_actor,c.member_b,c.member_a)
 JOIN users me ON me.id=p_actor AND me.status='active' AND me.deleted_at IS NULL
 WHERE c.id=p_chat AND p_actor IN(c.member_a,c.member_b);
END$$
CREATE OR REPLACE PROCEDURE NMT_sp_direct_thread(IN p_chat BIGINT UNSIGNED,IN p_actor BIGINT UNSIGNED)
BEGIN
 IF NOT EXISTS(SELECT 1 FROM direct_conversations c JOIN users u ON u.id=p_actor AND u.status='active' AND u.deleted_at IS NULL WHERE c.id=p_chat AND p_actor IN(c.member_a,c.member_b)) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không tìm thấy cuộc trò chuyện.';
 END IF;
 UPDATE direct_messages SET read_at=COALESCE(read_at,NOW(6)) WHERE conversation_id=p_chat AND sender_id<>p_actor;
 SELECT recent.*,u.full_name AS sender_name,(recent.sender_id=p_actor) AS is_mine,0 AS is_hidden
 FROM (SELECT * FROM direct_messages WHERE conversation_id=p_chat ORDER BY id DESC LIMIT 500) recent
 JOIN users u ON u.id=recent.sender_id ORDER BY recent.id;
END$$
CREATE OR REPLACE PROCEDURE NMT_sp_direct_send(IN p_chat BIGINT UNSIGNED,IN p_actor BIGINT UNSIGNED,IN p_body TEXT)
BEGIN
 DECLARE v_id BIGINT UNSIGNED;
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
 START TRANSACTION;
 SELECT id INTO v_id FROM direct_conversations WHERE id=p_chat AND p_actor IN(member_a,member_b) FOR UPDATE;
 IF v_id IS NULL OR EXISTS(SELECT 1 FROM direct_conversations c JOIN users u ON u.id IN(c.member_a,c.member_b) WHERE c.id=p_chat AND (u.status<>'active' OR u.deleted_at IS NOT NULL)) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không thể gửi tin nhắn tới cuộc trò chuyện này.';
 END IF;
 IF CHAR_LENGTH(TRIM(p_body))<1 OR CHAR_LENGTH(p_body)>2000 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tin nhắn cần từ 1 đến 2000 ký tự.'; END IF;
 IF EXISTS(SELECT 1 FROM direct_messages WHERE sender_id=p_actor AND conversation_id=p_chat AND created_at>NOW(6)-INTERVAL 2 SECOND) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Vui lòng chờ một chút trước khi gửi tiếp.';
 END IF;
 INSERT INTO direct_messages(conversation_id,sender_id,body) VALUES(p_chat,p_actor,p_body);
 COMMIT;
END$$
CREATE OR REPLACE PROCEDURE NMT_sp_direct_inbox(IN p_actor BIGINT UNSIGNED)
BEGIN
 SELECT c.id,u.full_name AS other_name,
 (SELECT body FROM direct_messages WHERE conversation_id=c.id ORDER BY id DESC LIMIT 1) AS last_body,
 (SELECT COUNT(*) FROM direct_messages WHERE conversation_id=c.id AND sender_id<>p_actor AND read_at IS NULL) AS unread_count
 FROM direct_conversations c JOIN users u ON u.id=IF(c.member_a=p_actor,c.member_b,c.member_a)
 JOIN users me ON me.id=p_actor AND me.status='active' AND me.deleted_at IS NULL
 WHERE p_actor IN(c.member_a,c.member_b)
 ORDER BY COALESCE((SELECT MAX(created_at) FROM direct_messages WHERE conversation_id=c.id),c.created_at) DESC LIMIT 200;
END$$
DELIMITER ;
