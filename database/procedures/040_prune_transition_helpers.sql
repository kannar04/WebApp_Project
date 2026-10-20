-- These ten helpers were introduced only during this refactor, then superseded
-- by atomic workflows. None existed in the original 16-routine catalog.
-- No table/data or original team routine is removed.
DELIMITER $$
DROP PROCEDURE IF EXISTS `sp_user_grant_guest`$$
DROP PROCEDURE IF EXISTS `sp_user_roles_clear`$$
DROP PROCEDURE IF EXISTS `sp_user_role_add`$$
DROP PROCEDURE IF EXISTS `sp_listing_amenities_clear`$$
DROP PROCEDURE IF EXISTS `sp_listing_amenity_add`$$
DROP PROCEDURE IF EXISTS `sp_listing_lock_owned`$$
DROP PROCEDURE IF EXISTS `sp_listing_confirmed_count_on_date`$$
DROP PROCEDURE IF EXISTS `sp_listing_availability_upsert`$$
DROP PROCEDURE IF EXISTS `sp_admin_listing_moderate_update`$$
DROP PROCEDURE IF EXISTS `sp_admin_listing_moderation_event`$$
DELIMITER ;
