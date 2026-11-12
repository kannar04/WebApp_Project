-- Additive: existing users, passwords and roles are not changed.
CREATE TABLE IF NOT EXISTS oauth_identities (
    user_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subject VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (provider,subject),
    UNIQUE KEY uq_oauth_user_provider (user_id,provider),
    CONSTRAINT fk_oauth_identity_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT chk_oauth_provider CHECK (provider='google')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE OR REPLACE PROCEDURE DST_sp_oauth_identity_find(IN p_subject VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin)
BEGIN
    SELECT user_id FROM oauth_identities WHERE provider='google' AND subject=p_subject;
END$$
CREATE OR REPLACE PROCEDURE DST_sp_oauth_identity_link(IN p_user BIGINT UNSIGNED, IN p_subject VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin)
BEGIN
    IF p_subject IS NULL OR p_subject='' OR NOT EXISTS(SELECT 1 FROM users WHERE id=p_user AND status='active' AND deleted_at IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không thể liên kết tài khoản Google.';
    END IF;
    IF EXISTS(SELECT 1 FROM oauth_identities WHERE provider='google' AND subject=p_subject AND user_id<>p_user) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tài khoản Google này đã được liên kết với tài khoản khác.';
    END IF;
    IF EXISTS(SELECT 1 FROM oauth_identities WHERE provider='google' AND user_id=p_user AND subject<>p_subject) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bạn đã liên kết một tài khoản Google khác.';
    END IF;
    INSERT INTO oauth_identities(user_id,provider,subject)
        SELECT p_user,'google',p_subject WHERE NOT EXISTS(SELECT 1 FROM oauth_identities WHERE provider='google' AND subject=p_subject AND user_id=p_user);
END$$
CREATE OR REPLACE PROCEDURE LTP_sp_oauth_identity_status(IN p_user BIGINT UNSIGNED)
BEGIN
    SELECT provider,created_at FROM oauth_identities WHERE user_id=p_user AND provider='google';
END$$
DELIMITER ;
