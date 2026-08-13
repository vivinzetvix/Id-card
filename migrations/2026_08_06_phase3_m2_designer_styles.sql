-- =============================================================================
-- Phase 3 Module 2 — Template Designer style / object model (additive)
-- DO NOT rename/drop existing columns. Idempotent via IF NOT EXISTS where possible.
-- layout_version current = 2 (object_type model)
-- =============================================================================

SET NAMES utf8mb4;

-- Template-level layout schema version
ALTER TABLE `card_templates`
  ADD COLUMN IF NOT EXISTS `layout_version` INT NOT NULL DEFAULT 1 AFTER `mirror_print`;

-- Object model + styles on template_fields
ALTER TABLE `template_fields`
  ADD COLUMN IF NOT EXISTS `object_type` VARCHAR(32) NOT NULL DEFAULT 'dynamic' AFTER `field_key`,
  ADD COLUMN IF NOT EXISTS `content` TEXT NULL AFTER `show_label`,
  ADD COLUMN IF NOT EXISTS `image_path` VARCHAR(255) NULL AFTER `content`,
  ADD COLUMN IF NOT EXISTS `z_index` INT NOT NULL DEFAULT 0 AFTER `image_path`,
  ADD COLUMN IF NOT EXISTS `font_weight` VARCHAR(16) NULL AFTER `font_family`,
  ADD COLUMN IF NOT EXISTS `font_style` VARCHAR(16) NULL AFTER `font_weight`,
  ADD COLUMN IF NOT EXISTS `text_decoration` VARCHAR(32) NULL AFTER `text_align`,
  ADD COLUMN IF NOT EXISTS `opacity` DECIMAL(4,3) NOT NULL DEFAULT 1.000 AFTER `text_decoration`,
  ADD COLUMN IF NOT EXISTS `border_width` DECIMAL(6,2) NULL DEFAULT NULL AFTER `opacity`,
  ADD COLUMN IF NOT EXISTS `border_color` VARCHAR(32) NULL DEFAULT NULL AFTER `border_width`,
  ADD COLUMN IF NOT EXISTS `border_style` VARCHAR(16) NULL DEFAULT NULL AFTER `border_color`,
  ADD COLUMN IF NOT EXISTS `border_radius` DECIMAL(6,2) NULL DEFAULT NULL AFTER `border_style`;

-- field_key only required for dynamic objects — allow NULL for static/image/etc.
ALTER TABLE `template_fields`
  MODIFY `field_key` VARCHAR(64) NULL DEFAULT NULL;

-- Objects are identified by id; unique (template_id, side, field_key) blocks
-- multiple NULL keys and Duplicate Object. Drop legacy unique indexes if present.
-- (Safe if already dropped.)
-- ALTER TABLE template_fields DROP INDEX uq_template_field_side;
-- ALTER TABLE template_fields DROP INDEX uq_template_field;

-- Backfill object_type heuristics for legacy rows (still dynamic + field_key)
UPDATE `template_fields`
SET `object_type` = CASE
    WHEN LOWER(COALESCE(`field_key`, '')) IN ('photo', 'pic') THEN 'photo'
    WHEN LOWER(COALESCE(`field_key`, '')) IN ('logo') THEN 'logo'
    WHEN LOWER(COALESCE(`field_key`, '')) IN ('qr', 'qrcode') THEN 'qr'
    WHEN LOWER(COALESCE(`field_key`, '')) LIKE '%barcode%' THEN 'barcode'
    WHEN LOWER(COALESCE(`field_key`, '')) LIKE '%signature%' THEN 'signature'
    ELSE 'dynamic'
END
WHERE `object_type` = 'dynamic' OR `object_type` = '' OR `object_type` IS NULL;

-- Mark templates that already have layouts as version 1 (legacy-compatible)
UPDATE `card_templates` t
SET t.layout_version = 1
WHERE t.layout_version IS NULL OR t.layout_version < 1;
