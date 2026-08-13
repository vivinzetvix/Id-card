CREATE TABLE IF NOT EXISTS organizations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_name VARCHAR(150) NOT NULL,
    organization_code VARCHAR(50) UNIQUE,
    logo VARCHAR(255) NULL,
    address TEXT NULL,
    phone VARCHAR(20) NULL,
    email VARCHAR(100) NULL,
    website VARCHAR(150) NULL,
    organization_type ENUM('school','college','company','government','hospital','ngo','other') DEFAULT 'company',
    project_type ENUM('residence','corporate') DEFAULT 'corporate',
    status TINYINT(1) DEFAULT 1,
    created_by INT NULL,
    updated_by INT NULL,
    deleted_by INT NULL,
    deleted_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

ALTER TABLE id_members ADD COLUMN IF NOT EXISTS organization_id INT NULL;
ALTER TABLE card_templates ADD COLUMN IF NOT EXISTS organization_id INT NULL;
ALTER TABLE generated_cards ADD COLUMN IF NOT EXISTS organization_id INT NULL;
