-- STEP 12A — Product Order -> Business OS integration foundation
-- Healthcare Wellness Club Business OS
-- MySQL 8+ / MariaDB compatible.
-- Saved Product & Price Pro quotes can be finalized into normalized Business OS orders
-- without rewriting legacy Excel facts or inventing profit/cost basis.

USE healthcare_wellness_club;

CREATE TABLE IF NOT EXISTS product_quote_order_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  quote_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pqol_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_pqol_quote FOREIGN KEY (quote_id) REFERENCES product_quotes(id) ON DELETE CASCADE,
  CONSTRAINT fk_pqol_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  UNIQUE KEY uq_pqol_quote (organization_id, quote_id),
  UNIQUE KEY uq_pqol_order (organization_id, order_id),
  KEY idx_pqol_created (organization_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_order_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  quote_id BIGINT UNSIGNED NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  listing_id BIGINT UNSIGNED NOT NULL,
  price_version_id BIGINT UNSIGNED NOT NULL,
  stock_no VARCHAR(100) NOT NULL,
  product_name_snapshot VARCHAR(190) NOT NULL,
  pricing_tier_code VARCHAR(60) NOT NULL,
  quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
  unit_mrp DECIMAL(16,2) NOT NULL DEFAULT 0,
  unit_price DECIMAL(16,2) NOT NULL DEFAULT 0,
  unit_vp DECIMAL(16,3) NOT NULL DEFAULT 0,
  line_mrp DECIMAL(16,2) NOT NULL DEFAULT 0,
  line_price DECIMAL(16,2) NOT NULL DEFAULT 0,
  line_vp DECIMAL(16,3) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_order_item_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_order_item_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_order_item_quote FOREIGN KEY (quote_id) REFERENCES product_quotes(id) ON DELETE SET NULL,
  CONSTRAINT fk_product_order_item_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_product_order_item_listing FOREIGN KEY (listing_id) REFERENCES product_market_listings(id),
  CONSTRAINT fk_product_order_item_price FOREIGN KEY (price_version_id) REFERENCES product_price_versions(id),
  KEY idx_product_order_item_order (organization_id, order_id, id),
  KEY idx_product_order_item_product (organization_id, product_id),
  KEY idx_product_order_item_quote (organization_id, quote_id)
) ENGINE=InnoDB;
