CREATE TABLE IF NOT EXISTS generated_cards (
  id INT AUTO_INCREMENT PRIMARY KEY,
  member_id INT NOT NULL,
  template_id INT NOT NULL,
  image_path VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_generated_member (member_id),
  INDEX idx_generated_template (template_id),
  INDEX idx_generated_created (created_at),
  CONSTRAINT fk_generated_member FOREIGN KEY (member_id)
    REFERENCES id_members(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE
);
