-- One-use hashed reset tokens. Mail transport is a deployment dependency, not a fake UI.
DELIMITER $$

CREATE OR REPLACE PROCEDURE sp_password_reset_issue(IN p_user BIGINT UNSIGNED, IN p_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_reset_issue; RELEASE SAVEPOINT h2h_reset_issue; END IF; END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_reset_issue; END IF;
    SELECT id INTO v_lock FROM users WHERE id=p_user AND status='active' AND deleted_at IS NULL FOR UPDATE;
    IF v_lock IS NULL OR p_hash IS NULL OR p_hash NOT REGEXP '^[a-f0-9]{64}$' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Yêu cầu khôi phục không hợp lệ.'; END IF;
    IF EXISTS(SELECT 1 FROM password_reset_tokens WHERE user_id=p_user AND created_at>NOW(6)-INTERVAL 5 MINUTE) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Vui lòng chờ trước khi yêu cầu khôi phục lại.';
    END IF;
    UPDATE password_reset_tokens SET used_at=NOW(6) WHERE user_id=p_user AND used_at IS NULL;
    INSERT INTO password_reset_tokens(user_id,token_hash,created_at,expires_at) VALUES(p_user,p_hash,NOW(6),NOW(6)+INTERVAL 45 MINUTE);
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_reset_issue; END IF;
END$$

CREATE OR REPLACE PROCEDURE sp_password_reset_consume(IN p_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin,
    IN p_password VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    DECLARE v_own BOOL DEFAULT FALSE;
    DECLARE v_user BIGINT UNSIGNED;
    DECLARE v_lock BIGINT UNSIGNED;
    DECLARE v_token BIGINT UNSIGNED;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF @@in_transaction THEN IF v_own THEN ROLLBACK; ELSE ROLLBACK TO SAVEPOINT h2h_reset_consume; RELEASE SAVEPOINT h2h_reset_consume; END IF; END IF;
        RESIGNAL;
    END;
    SET v_own=(@@in_transaction=0);
    IF v_own THEN START TRANSACTION; ELSE SAVEPOINT h2h_reset_consume; END IF;
    SELECT user_id INTO v_user FROM password_reset_tokens WHERE token_hash=p_hash;
    -- Consistent user -> token lock order for concurrent issuance/consumption.
    SELECT id INTO v_lock FROM users WHERE id=v_user AND status='active' AND deleted_at IS NULL FOR UPDATE;
    SELECT id INTO v_token FROM password_reset_tokens WHERE user_id=v_user AND token_hash=p_hash AND used_at IS NULL AND expires_at>NOW(6) FOR UPDATE;
    IF v_lock IS NULL OR v_token IS NULL OR p_password IS NULL OR LENGTH(p_password)<40 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Liên kết không hợp lệ, đã hết hạn hoặc đã sử dụng.';
    END IF;
    UPDATE users SET password_hash=p_password,updated_at=NOW(6) WHERE id=v_user;
    UPDATE password_reset_tokens SET used_at=NOW(6) WHERE user_id=v_user AND used_at IS NULL;
    IF v_own THEN COMMIT; ELSE RELEASE SAVEPOINT h2h_reset_consume; END IF;
END$$

DELIMITER ;
