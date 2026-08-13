-- =============================================================================
-- Phase 2 — FINAL Additive Upgrade Migration (strict / verified only)
-- Source of truth: Database/id.sql
-- Date: 2026-08-06
--
-- DO NOT EXECUTE until explicitly approved.
--
-- Rules:
-- - Additive only (no renames, no drops, no data deletion)
-- - Idempotent (IF NOT EXISTS / INSERT IGNORE / guarded indexes)
-- - Excludes items marked "Needs Verification" (see footer)
-- =============================================================================

SET NAMES utf8mb4;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';


-- =============================================================================
-- 1) template_fields.text_align
-- JUSTIFICATION (verified):
--   Designer must persist text alignment. Current columns are
--   x/y/width/height/font_size/font_family/color/show_label/visible —
--   none can store alignment. card_renderer.php position CSS has no
--   text-align today; column is required before Module 3 designer work.
-- =============================================================================
ALTER TABLE `template_fields`
  ADD COLUMN IF NOT EXISTS `text_align` ENUM('left','center','right') NOT NULL DEFAULT 'left'
  AFTER `color`;


-- =============================================================================
-- 2) template_input_fields.default_value
-- JUSTIFICATION (verified):
--   Field rules require Default Value. Existing columns
--   (placeholder, validation_rules, bilingual_mode) do not hold defaults.
-- =============================================================================
ALTER TABLE `template_input_fields`
  ADD COLUMN IF NOT EXISTS `default_value` TEXT NULL
  AFTER `placeholder`;


-- =============================================================================
-- 3) archived_at — soft archive (never hard-delete fields with member data)
-- JUSTIFICATION (verified):
--   is_enabled / visible are booleans only; no timestamp for archive/restore
--   audit. Soft-archive is required so member_dynamic_values and historical
--   cards remain valid after a field is retired.
-- =============================================================================
ALTER TABLE `template_input_fields`
  ADD COLUMN IF NOT EXISTS `archived_at` TIMESTAMP NULL DEFAULT NULL
  AFTER `is_enabled`;

ALTER TABLE `template_fields`
  ADD COLUMN IF NOT EXISTS `archived_at` TIMESTAMP NULL DEFAULT NULL
  AFTER `visible`;


-- =============================================================================
-- 4) audit_log.organization_id
-- JUSTIFICATION (verified):
--   Requirement stores organization on every audit row. Today org is only
--   inferred via JOIN users.organization_id (breaks when user is deleted
--   or has NULL org). Column does not exist in Database/id.sql.
-- =============================================================================
ALTER TABLE `audit_log`
  ADD COLUMN IF NOT EXISTS `organization_id` INT NULL DEFAULT NULL
  AFTER `user_id`;

-- Best-effort backfill from acting user (no invented org IDs)
UPDATE `audit_log` a
INNER JOIN `users` u ON u.id = a.user_id
SET a.organization_id = u.organization_id
WHERE a.organization_id IS NULL
  AND u.organization_id IS NOT NULL;


-- =============================================================================
-- 5) generated_cards.organization_id BACKFILL ONLY
-- JUSTIFICATION (verified):
--   Column ALREADY EXISTS (migrations/2026_07_31 + Database/id.sql).
--   Relationship verified:
--     generated_cards.member_id  →  id_members.id
--     id_members.organization_id = source of truth for card ownership
--   generate_id_card.php INSERT currently omits organization_id → rows NULL.
--   This UPDATE repairs existing rows. Code to set it on INSERT = Phase 3 M1.
--   Members with NULL organization_id leave matching cards NULL (no invention).
-- =============================================================================
UPDATE `generated_cards` g
INNER JOIN `id_members` m ON m.id = g.member_id
SET g.organization_id = m.organization_id
WHERE g.organization_id IS NULL
  AND m.organization_id IS NOT NULL;


-- =============================================================================
-- 6) Performance indexes (behavior-neutral)
-- JUSTIFICATION (verified):
--   Hot filters used by members list, designer, audit, template listing.
--   Guarded create for MariaDB 10.4 (no CREATE INDEX IF NOT EXISTS).
-- =============================================================================

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'audit_log'
    AND INDEX_NAME = 'idx_audit_organization'
);
SET @sql := IF(@idx = 0,
  'ALTER TABLE `audit_log` ADD KEY `idx_audit_organization` (`organization_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'id_members'
    AND INDEX_NAME = 'idx_member_template'
);
SET @sql := IF(@idx = 0,
  'ALTER TABLE `id_members` ADD KEY `idx_member_template` (`template_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'id_members'
    AND INDEX_NAME = 'idx_member_deleted'
);
SET @sql := IF(@idx = 0,
  'ALTER TABLE `id_members` ADD KEY `idx_member_deleted` (`deleted_at`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'template_input_fields'
    AND INDEX_NAME = 'idx_tif_template_enabled'
);
SET @sql := IF(@idx = 0,
  'ALTER TABLE `template_input_fields` ADD KEY `idx_tif_template_enabled` (`template_id`, `is_enabled`, `archived_at`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'template_fields'
    AND INDEX_NAME = 'idx_tf_template_side'
);
SET @sql := IF(@idx = 0,
  'ALTER TABLE `template_fields` ADD KEY `idx_tf_template_side` (`template_id`, `side`, `archived_at`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'card_templates'
    AND INDEX_NAME = 'idx_template_org_status'
);
SET @sql := IF(@idx = 0,
  'ALTER TABLE `card_templates` ADD KEY `idx_template_org_status` (`organization_id`, `status`, `deleted_at`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- =============================================================================
-- 7) role_permissions seed (INSERT IGNORE — never overwrites existing grants)
-- JUSTIFICATION (verified):
--   Dump had empty role_permissions; live DB already has SA/OrgAdmin/Registrar
--   grants. INSERT IGNORE fills gaps only. Role names NOT renamed.
--   Organization Admin identifier preserved (UI label "Admin" is code-only).
-- =============================================================================

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `roles` r
CROSS JOIN `permissions` p
WHERE LOWER(r.role_name) IN ('super admin', 'super_admin');

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `roles` r
JOIN `permissions` p ON (
     (p.module_name = 'Dashboard'     AND p.permission_name IN ('View', 'Export'))
  OR (p.module_name = 'Organizations' AND p.permission_name IN ('View', 'Edit', 'Print', 'Export'))
  OR (p.module_name = 'Members'       AND p.permission_name IN ('View', 'Create', 'Edit', 'Delete', 'Print', 'Export', 'Import'))
  OR (p.module_name = 'Templates'     AND p.permission_name IN ('View', 'Create', 'Edit', 'Delete', 'Print', 'Export', 'Import'))
  OR (p.module_name = 'Generate ID'   AND p.permission_name IN ('View', 'Create', 'Edit', 'Delete', 'Print', 'Export'))
  OR (p.module_name = 'Reports'       AND p.permission_name IN ('View', 'Print', 'Export'))
)
WHERE LOWER(r.role_name) IN ('organization admin', 'organization_admin');

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `roles` r
JOIN `permissions` p ON (
     (p.module_name = 'Dashboard'   AND p.permission_name = 'View')
  OR (p.module_name = 'Members'     AND p.permission_name IN ('View', 'Create', 'Edit', 'Import', 'Export', 'Print'))
  OR (p.module_name = 'Templates'   AND p.permission_name = 'View')
  OR (p.module_name = 'Generate ID' AND p.permission_name IN ('View', 'Print'))
)
WHERE LOWER(r.role_name) = 'registrar';


-- =============================================================================
-- 8) Soft-deactivate unused "Finace" role
-- JUSTIFICATION (verified):
--   Typo/test role (description "Hello"), zero users on role_id=7.
--   Soft-deactivate only — row retained, no DELETE, no rename to Finance.
-- =============================================================================
UPDATE `roles`
SET `status` = 0,
    `description` = 'Archived test role (typo of Finance). Deactivated by Phase 2 migration. Safe to delete manually if unused.'
WHERE `id` = 7
  AND `role_name` = 'Finace'
  AND `status` = 1;


-- =============================================================================
-- END — verified additive migration
-- =============================================================================
--
-- NEEDS VERIFICATION / DEFERRED (NOT in this migration):
-- -----------------------------------------------------------------------------
-- card_templates.size_unit
-- card_templates.card_width / card_height datatype widen (INT → DECIMAL)
--
-- Why deferred:
--   Current runtime already works without these schema changes.
--   - Designer + card_renderer default to integer px (533×864 / 864×533)
--   - Print CSS hardcodes 8.64cm / 5.33cm by orientation
--   - Forms label "cm" and accept step=0.01, but INT storage truncates;
--     existing code casts and falls back to px defaults when null/zero
--   Requirement (custom sizes + default cm labels) can be satisfied in
--   application code by treating stored INT as px (current convention) OR
--   converting cm↔px at save/display — without ALTER until that
--   convention is chosen and verified in Template Management (Module 2).
--
-- Revisit in Phase 3 Module 2 if app-layer convention proves insufficient.
-- =============================================================================
