-- Normalised percentage layout used by the shared card renderer.
ALTER TABLE template_fields MODIFY x DECIMAL(7,3) NOT NULL DEFAULT 0;
ALTER TABLE template_fields MODIFY y DECIMAL(7,3) NOT NULL DEFAULT 0;
ALTER TABLE template_fields MODIFY width DECIMAL(7,3) NOT NULL DEFAULT 0;
ALTER TABLE template_fields MODIFY height DECIMAL(7,3) NOT NULL DEFAULT 0;
ALTER TABLE template_fields ADD COLUMN side VARCHAR(10) NOT NULL DEFAULT 'front' AFTER field_key;
ALTER TABLE template_fields ADD COLUMN font_family VARCHAR(100) NULL AFTER font_size;
ALTER TABLE template_fields ADD COLUMN color VARCHAR(32) NULL AFTER font_family;
ALTER TABLE template_fields ADD COLUMN show_label TINYINT(1) NOT NULL DEFAULT 1 AFTER color;
ALTER TABLE template_fields DROP INDEX uq_template_field;
ALTER TABLE template_fields ADD UNIQUE KEY uq_template_field_side (template_id, side, field_key);
