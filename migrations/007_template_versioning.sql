-- ============================================================
-- Migration 007: Template Versioning & Member Data Safety
-- Run once against the live database.
-- ============================================================

-- 1. Add versioning columns to card_templates
ALTER TABLE `card_templates`
  ADD COLUMN `parent_template_id` INT NULL DEFAULT NULL AFTER `organization_id`,
  ADD COLUMN `version`            SMALLINT NOT NULL DEFAULT 1 AFTER `parent_template_id`,
  ADD COLUMN `first_used_at`      TIMESTAMP NULL DEFAULT NULL AFTER `status`,
  ADD KEY `idx_template_parent`   (`parent_template_id`);

-- 2. Back-fill first_used_at for templates that already have members
UPDATE `card_templates` ct
  JOIN (
    SELECT template_id, MIN(created_at) AS first_used
    FROM id_members
    WHERE deleted_at IS NULL AND template_id IS NOT NULL AND template_id > 0
    GROUP BY template_id
  ) m ON m.template_id = ct.id
SET ct.first_used_at = m.first_used
WHERE ct.first_used_at IS NULL;

-- 3. Ensure member_dynamic_values has a broader unique key
-- Allow multiple template_ids per member+field_key (field values are
-- template-version-scoped for custom fields, template_id=0 for common).
-- The existing unique key (member_id, template_id, field_key) is already correct.

-- 4. Add a helper view for the "latest version" of each template family
CREATE OR REPLACE VIEW `v_template_latest_version` AS
  SELECT
    COALESCE(parent_template_id, id) AS root_id,
    id,
    version,
    name,
    organization_id,
    status,
    deleted_at
  FROM card_templates
  WHERE deleted_at IS NULL
  ORDER BY COALESCE(parent_template_id, id), version DESC;
