-- Private three-image identity dossier shared by Guest/Host accounts.
CREATE TABLE IF NOT EXISTS identity_verification_documents (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 verification_id BIGINT UNSIGNED NOT NULL,
 document_kind ENUM('front','back','selfie') NOT NULL,
 storage_name VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 mime_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 byte_size INT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 CONSTRAINT fk_identity_document_verification FOREIGN KEY(verification_id) REFERENCES identity_verifications(id) ON DELETE CASCADE,
 UNIQUE KEY uq_identity_document_kind(verification_id,document_kind),
 INDEX idx_identity_document_verification(verification_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE OR REPLACE PROCEDURE DST_sp_identity_verification_submit(IN p_actor BIGINT UNSIGNED,
 IN p_full_name VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
 IN p_phone VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
 IN p_id_number VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
 IN p_front VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin,IN p_front_mime VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin,IN p_front_bytes INT UNSIGNED,
 IN p_back VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin,IN p_back_mime VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin,IN p_back_bytes INT UNSIGNED,
 IN p_selfie VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin,IN p_selfie_mime VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin,IN p_selfie_bytes INT UNSIGNED)
BEGIN
 DECLARE v_own BOOL DEFAULT FALSE; DECLARE v_lock BIGINT UNSIGNED; DECLARE v_verification BIGINT UNSIGNED;
 DECLARE v_name VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; DECLARE v_current_phone VARCHAR(25) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN IF @@in_transaction THEN IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_identity_submit; RELEASE SAVEPOINT h2h_identity_submit; END IF; END IF; RESIGNAL; END;
 SET v_own=(@@in_transaction=0); IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_identity_submit; END IF;
 SET v_name=TRIM(p_full_name);
 IF v_name IS NULL OR CHAR_LENGTH(v_name)<2 OR CHAR_LENGTH(v_name)>150 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Họ tên trên CCCD cần từ 2 đến 150 ký tự.'; END IF;
 IF p_phone IS NULL OR p_phone NOT REGEXP '^0[35789][0-9]{8}$' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Số điện thoại di động Việt Nam không hợp lệ.'; END IF;
 IF p_id_number IS NULL OR p_id_number NOT REGEXP '^[0-9]{12}$' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Số CCCD phải gồm đúng 12 chữ số.'; END IF;
 IF p_front NOT REGEXP '^front-[a-f0-9]{48}\\.(jpg|png|webp)$' OR p_back NOT REGEXP '^back-[a-f0-9]{48}\\.(jpg|png|webp)$' OR p_selfie NOT REGEXP '^selfie-[a-f0-9]{48}\\.(jpg|png|webp)$'
  OR p_front_mime NOT IN ('image/jpeg','image/png','image/webp') OR p_back_mime NOT IN ('image/jpeg','image/png','image/webp') OR p_selfie_mime NOT IN ('image/jpeg','image/png','image/webp')
  OR NOT ((p_front_mime='image/jpeg' AND p_front LIKE '%.jpg') OR (p_front_mime='image/png' AND p_front LIKE '%.png') OR (p_front_mime='image/webp' AND p_front LIKE '%.webp'))
  OR NOT ((p_back_mime='image/jpeg' AND p_back LIKE '%.jpg') OR (p_back_mime='image/png' AND p_back LIKE '%.png') OR (p_back_mime='image/webp' AND p_back LIKE '%.webp'))
  OR NOT ((p_selfie_mime='image/jpeg' AND p_selfie LIKE '%.jpg') OR (p_selfie_mime='image/png' AND p_selfie LIKE '%.png') OR (p_selfie_mime='image/webp' AND p_selfie LIKE '%.webp'))
  OR p_front_bytes NOT BETWEEN 1 AND 5242880 OR p_back_bytes NOT BETWEEN 1 AND 5242880 OR p_selfie_bytes NOT BETWEEN 1 AND 5242880 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bộ ảnh xác minh không hợp lệ.'; END IF;
 SELECT id,phone INTO v_lock,v_current_phone FROM users WHERE id=p_actor AND status='active' AND deleted_at IS NULL FOR UPDATE;
 IF v_lock IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tài khoản không hợp lệ hoặc đã bị khóa.'; END IF;
 IF EXISTS(SELECT 1 FROM identity_verifications v WHERE v.user_id=p_actor AND v.status='approved' AND REGEXP_REPLACE(v_current_phone,'[[:space:].()-]','') IN (v.phone,CONCAT('+84',SUBSTRING(v.phone,2)),CONCAT('84',SUBSTRING(v.phone,2)))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tài khoản đã được xác minh danh tính.'; END IF;
 IF EXISTS(SELECT 1 FROM identity_verifications WHERE user_id=p_actor AND status='pending') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bạn đã có một yêu cầu xác minh đang chờ duyệt.'; END IF;
 INSERT INTO identity_verifications(user_id,full_name_on_card,phone,id_number,status,created_at,updated_at) VALUES(p_actor,v_name,p_phone,p_id_number,'pending',NOW(6),NOW(6));
 SET v_verification=LAST_INSERT_ID();
 INSERT INTO identity_verification_documents(verification_id,document_kind,storage_name,mime_type,byte_size,created_at) VALUES
  (v_verification,'front',p_front,p_front_mime,p_front_bytes,NOW(6)),(v_verification,'back',p_back,p_back_mime,p_back_bytes,NOW(6)),(v_verification,'selfie',p_selfie,p_selfie_mime,p_selfie_bytes,NOW(6));
 SELECT 1 __affected,v_verification __insert_id;
 IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_identity_submit; END IF;
END$$

CREATE OR REPLACE PROCEDURE DST_sp_admin_identity_verifications(IN p_admin BIGINT UNSIGNED,IN p_status VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,IN p_query VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
 DECLARE v_query VARCHAR(600) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(p_query,'\\','\\\\'),'%','\\%'),'_','\\_'),'đ','d'),'Đ','D');
 CALL NTK_sp_admin_assert_role(p_admin); IF p_status IS NULL OR p_status NOT IN ('','pending','approved','rejected') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bộ lọc trạng thái xác minh không hợp lệ.'; END IF;
 SELECT v.id,v.user_id,u.email,u.full_name account_name,v.full_name_on_card,v.phone,CASE WHEN v.status='pending' THEN v.id_number ELSE CONCAT('********',RIGHT(v.id_number,4)) END id_number,
  v.status,v.decision_reason,v.created_at,v.reviewed_at,a.full_name reviewer_name,(SELECT COUNT(*) FROM identity_verification_documents d WHERE d.verification_id=v.id) document_count
 FROM identity_verifications v JOIN users u ON u.id=v.user_id LEFT JOIN users a ON a.id=v.reviewed_by
 WHERE (p_status='' OR v.status=p_status) AND (p_query IS NULL OR p_query='' OR u.email LIKE CONCAT('%',v_query,'%') OR REPLACE(REPLACE(u.full_name,'đ','d'),'Đ','D') LIKE CONCAT('%',v_query,'%') OR REPLACE(REPLACE(v.full_name_on_card,'đ','d'),'Đ','D') LIKE CONCAT('%',v_query,'%'))
 ORDER BY v.status='pending' DESC,IF(v.status='pending',v.created_at,NULL),v.created_at DESC,v.id DESC LIMIT 200;
END$$

CREATE OR REPLACE PROCEDURE LTP_sp_admin_identity_verification_decide(IN p_id BIGINT UNSIGNED,IN p_admin BIGINT UNSIGNED,IN p_decision VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,IN p_reason TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
 DECLARE v_own BOOL DEFAULT FALSE; DECLARE v_user BIGINT UNSIGNED; DECLARE v_documents INT DEFAULT 0; DECLARE v_status VARCHAR(10); DECLARE v_phone VARCHAR(10); DECLARE v_name VARCHAR(150); DECLARE v_reason TEXT;
 DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN IF @@in_transaction THEN IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_identity_decide; RELEASE SAVEPOINT h2h_identity_decide; END IF; END IF; RESIGNAL; END;
 SET v_own=(@@in_transaction=0); IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_identity_decide; END IF; CALL NTK_sp_admin_assert_role(p_admin);
 IF p_decision IS NULL OR p_decision NOT IN ('approved','rejected') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Quyết định xác minh không hợp lệ.'; END IF;
 SET v_reason=NULLIF(TRIM(p_reason),''); IF p_decision='rejected' AND (v_reason IS NULL OR CHAR_LENGTH(v_reason)<5) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Vui lòng nhập lý do từ chối (ít nhất 5 ký tự).'; END IF;
 IF CHAR_LENGTH(v_reason)>1000 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ghi chú quyết định tối đa 1000 ký tự.'; END IF;
 SELECT user_id,status,phone,full_name_on_card INTO v_user,v_status,v_phone,v_name FROM identity_verifications WHERE id=p_id FOR UPDATE;
 IF v_user IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Yêu cầu xác minh không tồn tại.'; END IF; IF v_status<>'pending' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Yêu cầu xác minh này đã được xử lý.'; END IF;
 IF v_user=p_admin THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Admin không thể tự duyệt yêu cầu xác minh của chính mình.'; END IF;
 SELECT COUNT(*) INTO v_documents FROM identity_verification_documents WHERE verification_id=p_id;
 IF p_decision='approved' AND v_documents<>3 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Chỉ duyệt khi có đủ mặt trước, mặt sau CCCD và ảnh khuôn mặt.'; END IF;
 UPDATE identity_verifications SET status=p_decision,decision_reason=v_reason,reviewed_by=p_admin,reviewed_at=NOW(6),updated_at=NOW(6),id_number=CONCAT('********',RIGHT(id_number,4)) WHERE id=p_id;
 IF p_decision='approved' THEN UPDATE users SET phone=v_phone,full_name=v_name,updated_at=NOW(6) WHERE id=v_user; END IF;
 INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,change_summary,created_at) VALUES(p_admin,IF(p_decision='approved','approve_identity','reject_identity'),'identity_verification',p_id,JSON_OBJECT('user_id',v_user,'status',p_decision,'reason',v_reason,'documents_reviewed',v_documents),NOW(6));
 INSERT INTO notifications(user_id,booking_id,channel,event_type,title,body,delivery_status,attempt_count,created_at) VALUES(v_user,NULL,'in_app',CONCAT('identity_',p_decision),'Xác minh danh tính',IF(p_decision='approved','Danh tính của bạn đã được xác minh. Huy hiệu “Đã xác minh” hiển thị cạnh tên của bạn.',CONCAT('Yêu cầu xác minh danh tính chưa được chấp nhận. Lý do: ',v_reason,' Bạn có thể gửi lại trong trang Tài khoản.')),'sent',1,NOW(6));
 DELETE FROM identity_verification_documents WHERE verification_id=p_id;
 SELECT 1 __affected,p_id __insert_id; IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_identity_decide; END IF;
END$$

CREATE OR REPLACE PROCEDURE NTK_sp_identity_verification_status(IN p_actor BIGINT UNSIGNED)
BEGIN
 SELECT v.id,v.status,v.full_name_on_card,v.phone,CONCAT('********',RIGHT(v.id_number,4)) id_number_masked,v.decision_reason,v.created_at,v.reviewed_at,
  COALESCE(v.status='approved' AND REGEXP_REPLACE(u.phone,'[[:space:].()-]','') IN (v.phone,CONCAT('+84',SUBSTRING(v.phone,2)),CONCAT('84',SUBSTRING(v.phone,2))),FALSE) is_current,
  (SELECT COUNT(*) FROM identity_verification_documents d WHERE d.verification_id=v.id) document_count
 FROM identity_verifications v JOIN users u ON u.id=v.user_id WHERE v.user_id=p_actor AND u.status='active' AND u.deleted_at IS NULL ORDER BY is_current DESC,v.created_at DESC,v.id DESC LIMIT 1;
END$$

CREATE OR REPLACE PROCEDURE DST_sp_identity_document_for_admin(IN p_admin BIGINT UNSIGNED,IN p_verification BIGINT UNSIGNED,IN p_kind VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin)
BEGIN
 CALL NTK_sp_admin_assert_role(p_admin); SELECT d.storage_name,d.mime_type,d.byte_size FROM identity_verification_documents d JOIN identity_verifications v ON v.id=d.verification_id WHERE d.verification_id=p_verification AND d.document_kind=p_kind AND v.status='pending';
END$$
CREATE OR REPLACE PROCEDURE LTP_sp_identity_documents_for_admin(IN p_admin BIGINT UNSIGNED,IN p_verification BIGINT UNSIGNED)
BEGIN
 CALL NTK_sp_admin_assert_role(p_admin); SELECT d.document_kind,d.storage_name,d.mime_type,d.byte_size FROM identity_verification_documents d JOIN identity_verifications v ON v.id=d.verification_id WHERE d.verification_id=p_verification AND v.status='pending' ORDER BY FIELD(d.document_kind,'front','back','selfie');
END$$
CREATE OR REPLACE PROCEDURE NMT_sp_identity_document_for_user(IN p_user BIGINT UNSIGNED,IN p_verification BIGINT UNSIGNED,IN p_kind VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin)
BEGIN
 SELECT d.storage_name,d.mime_type,d.byte_size FROM identity_verification_documents d JOIN identity_verifications v ON v.id=d.verification_id JOIN users u ON u.id=v.user_id WHERE d.verification_id=p_verification AND d.document_kind=p_kind AND v.user_id=p_user AND v.status='pending' AND u.status='active' AND u.deleted_at IS NULL;
END$$
CREATE OR REPLACE PROCEDURE NTK_sp_identity_documents_for_user(IN p_user BIGINT UNSIGNED,IN p_verification BIGINT UNSIGNED)
BEGIN
 SELECT d.document_kind,d.storage_name,d.mime_type,d.byte_size FROM identity_verification_documents d JOIN identity_verifications v ON v.id=d.verification_id WHERE d.verification_id=p_verification AND v.user_id=p_user AND v.status='pending' ORDER BY FIELD(d.document_kind,'front','back','selfie');
END$$
DELIMITER ;
