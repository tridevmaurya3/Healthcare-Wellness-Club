-- STEP 11 COMPLETE - Product & Price Pro support layer
-- MySQL 8+ / MariaDB compatible. Existing STEP 11A tables remain source-of-truth.
USE healthcare_wellness_club;

ALTER TABLE product_images ADD COLUMN IF NOT EXISTS source_page_url VARCHAR(700) NULL AFTER image_url;
ALTER TABLE product_images ADD COLUMN IF NOT EXISTS source_name VARCHAR(160) NULL AFTER source_page_url;
ALTER TABLE product_images ADD COLUMN IF NOT EXISTS verification_status VARCHAR(30) NOT NULL DEFAULT 'needs_review' AFTER source_name;
ALTER TABLE product_images ADD COLUMN IF NOT EXISTS verified_at DATETIME NULL AFTER verification_status;

CREATE TABLE IF NOT EXISTS product_source_documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  market_id BIGINT UNSIGNED NOT NULL,
  document_type VARCHAR(50) NOT NULL,
  document_title VARCHAR(255) NOT NULL,
  effective_date DATE NULL,
  file_sha256 CHAR(64) NULL,
  source_reference VARCHAR(700) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_doc_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_doc_market FOREIGN KEY (market_id) REFERENCES product_markets(id) ON DELETE CASCADE,
  UNIQUE KEY uq_product_doc_hash (organization_id, market_id, document_type, file_sha256),
  KEY idx_product_doc_effective (organization_id, market_id, effective_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_delivery_rules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  market_id BIGINT UNSIGNED NOT NULL,
  rule_code VARCHAR(60) NOT NULL,
  rule_name VARCHAR(160) NOT NULL,
  applies_to VARCHAR(40) NOT NULL DEFAULT 'nutrition',
  min_vp DECIMAL(16,3) NULL,
  max_vp DECIMAL(16,3) NULL,
  charge_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  currency_code CHAR(3) NOT NULL,
  note TEXT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_delivery_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_delivery_market FOREIGN KEY (market_id) REFERENCES product_markets(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_delivery_currency FOREIGN KEY (currency_code) REFERENCES currencies(currency_code),
  UNIQUE KEY uq_product_delivery_rule (organization_id, market_id, rule_code)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_favorites (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_favorite_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_favorite_user FOREIGN KEY (user_id) REFERENCES system_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_favorite_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  UNIQUE KEY uq_product_favorite (organization_id, user_id, product_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_quotes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  market_id BIGINT UNSIGNED NOT NULL,
  quote_code VARCHAR(80) NOT NULL,
  customer_name VARCHAR(180) NULL,
  customer_type VARCHAR(60) NOT NULL DEFAULT 'preferred',
  pricing_tier_code VARCHAR(60) NOT NULL,
  subtotal_mrp DECIMAL(16,2) NOT NULL DEFAULT 0,
  payable_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  saving_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  total_vp DECIMAL(16,3) NOT NULL DEFAULT 0,
  delivery_charge DECIMAL(16,2) NOT NULL DEFAULT 0,
  grand_total DECIMAL(16,2) NOT NULL DEFAULT 0,
  status VARCHAR(30) NOT NULL DEFAULT 'draft',
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_quote_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_quote_market FOREIGN KEY (market_id) REFERENCES product_markets(id) ON DELETE CASCADE,
  UNIQUE KEY uq_product_quote_code (organization_id, quote_code),
  KEY idx_product_quote_status (organization_id, status, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_quote_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quote_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  listing_id BIGINT UNSIGNED NOT NULL,
  price_version_id BIGINT UNSIGNED NOT NULL,
  stock_no VARCHAR(100) NOT NULL,
  product_name VARCHAR(190) NOT NULL,
  quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
  unit_mrp DECIMAL(16,2) NOT NULL DEFAULT 0,
  unit_price DECIMAL(16,2) NOT NULL DEFAULT 0,
  unit_vp DECIMAL(16,3) NOT NULL DEFAULT 0,
  line_mrp DECIMAL(16,2) NOT NULL DEFAULT 0,
  line_price DECIMAL(16,2) NOT NULL DEFAULT 0,
  line_vp DECIMAL(16,3) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_quote_item_quote FOREIGN KEY (quote_id) REFERENCES product_quotes(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_quote_item_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_product_quote_item_listing FOREIGN KEY (listing_id) REFERENCES product_market_listings(id),
  CONSTRAINT fk_product_quote_item_price FOREIGN KEY (price_version_id) REFERENCES product_price_versions(id),
  KEY idx_product_quote_item_quote (quote_id, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_import_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  market_id BIGINT UNSIGNED NOT NULL,
  import_type VARCHAR(40) NOT NULL DEFAULT 'price_pdf',
  original_file_name VARCHAR(255) NULL,
  file_sha256 CHAR(64) NULL,
  effective_date DATE NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'staged',
  total_rows INT UNSIGNED NOT NULL DEFAULT 0,
  matched_rows INT UNSIGNED NOT NULL DEFAULT 0,
  review_rows INT UNSIGNED NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_import_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_import_market FOREIGN KEY (market_id) REFERENCES product_markets(id) ON DELETE CASCADE,
  KEY idx_product_import_status (organization_id, market_id, status, created_at)
) ENGINE=InnoDB;
