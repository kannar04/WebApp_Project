-- Expand the existing identity table; Google procedures and rows remain unchanged.
ALTER TABLE oauth_identities
    DROP CONSTRAINT IF EXISTS chk_oauth_provider,
    ADD CONSTRAINT chk_oauth_provider CHECK (provider IN ('google','facebook'));
DELIMITER $$
CREATE OR REPLACE PROCEDURE NMT_sp_facebook_identity_find(IN p_subject VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin)
BEGIN
    SELECT user_id FROM oauth_identities WHERE provider='facebook' AND subject=p_subject;
END$$
CREATE OR REPLACE PROCEDURE LTP_sp_facebook_identity_status(IN p_user BIGINT UNSIGNED)
BEGIN
    SELECT provider,created_at FROM oauth_identities WHERE provider='facebook' AND user_id=p_user;
END$$
CREATE OR REPLACE PROCEDURE DST_sp_facebook_identity_link(IN p_user BIGINT UNSIGNED,IN p_subject VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin)
BEGIN
    IF p_subject IS NULL OR p_subject NOT REGEXP '^[0-9]{1,64}$' OR NOT EXISTS(SELECT 1 FROM users WHERE id=p_user AND status='active' AND deleted_at IS NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Không thể liên kết tài khoản Facebook.';
    END IF;
    IF EXISTS(SELECT 1 FROM oauth_identities WHERE provider='facebook' AND subject=p_subject AND user_id<>p_user) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tài khoản Facebook này đã được liên kết với tài khoản khác.';
    END IF;
    IF EXISTS(SELECT 1 FROM oauth_identities WHERE provider='facebook' AND user_id=p_user AND subject<>p_subject) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Bạn đã liên kết một tài khoản Facebook khác.';
    END IF;
    INSERT INTO oauth_identities(user_id,provider,subject)
        SELECT p_user,'facebook',p_subject WHERE NOT EXISTS(SELECT 1 FROM oauth_identities WHERE provider='facebook' AND subject=p_subject AND user_id=p_user);
END$$
DELIMITER ;
