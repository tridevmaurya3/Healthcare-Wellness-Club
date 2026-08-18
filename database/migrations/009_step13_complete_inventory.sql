-- STEP 13 COMPLETE — Inventory & Stock Management System
-- Healthcare Wellness Club Business OS
-- MySQL 8+ / MariaDB compatible.

USE healthcare_wellness_club;

CREATE TABLE IF NOT EXISTS inventory_locations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  club_id BIGINT UNSIGNED NULL,
  location_code VARCHAR(60) NOT NULL,
  location_name VARCHAR(150) NOT NULL,
  location_type VARCHAR(40) NOT NULL DEFAULT 'store',
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_inventory_location_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_location_club FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE SET NULL,
  UNIQUE KEY uq_inventory_location_code (organization_id, location_code),
  KEY idx_inventory_location_status (organization_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_product_settings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  listing_id BIGINT UNSIGNED NOT NULL,
  track_stock TINYINT(1) NOT NULL DEFAULT 1,
  allow_negative TINYINT(1) NOT NULL DEFAULT 0,
  reorder_level DECIMAL(16,3) NOT NULL DEFAULT 0,
  target_stock DECIMAL(16,3) NOT NULL DEFAULT 0,
  expiry_alert_days INT UNSIGNED NOT NULL DEFAULT 60,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_inventory_setting_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_setting_location FOREIGN KEY (location_id) REFERENCES inventory_locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_setting_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_setting_listing FOREIGN KEY (listing_id) REFERENCES product_market_listings(id) ON DELETE CASCADE,
  UNIQUE KEY uq_inventory_setting (organization_id, location_id, listing_id),
  KEY idx_inventory_setting_reorder (organization_id, location_id, track_stock, reorder_level)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_batches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  listing_id BIGINT UNSIGNED NOT NULL,
  batch_code VARCHAR(120) NOT NULL,
  manufacture_date DATE NULL,
  expiry_date DATE NULL,
  supplier_name VARCHAR(190) NULL,
  purchase_reference VARCHAR(190) NULL,
  unit_cost DECIMAL(16,2) NULL,
  received_quantity DECIMAL(16,3) NOT NULL DEFAULT 0,
  current_quantity DECIMAL(16,3) NOT NULL DEFAULT 0,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_inventory_batch_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_batch_location FOREIGN KEY (location_id) REFERENCES inventory_locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_batch_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_batch_listing FOREIGN KEY (listing_id) REFERENCES product_market_listings(id) ON DELETE CASCADE,
  UNIQUE KEY uq_inventory_batch (organization_id, location_id, listing_id, batch_code),
  KEY idx_inventory_batch_stock (organization_id, location_id, listing_id, status, current_quantity),
  KEY idx_inventory_batch_expiry (organization_id, location_id, expiry_date, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  club_id BIGINT UNSIGNED NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  listing_id BIGINT UNSIGNED NOT NULL,
  batch_id BIGINT UNSIGNED NULL,
  movement_type VARCHAR(50) NOT NULL,
  movement_date DATE NOT NULL,
  quantity_delta DECIMAL(16,3) NOT NULL,
  unit_cost DECIMAL(16,2) NULL,
  reference_type VARCHAR(60) NULL,
  reference_id BIGINT UNSIGNED NULL,
  source_reference VARCHAR(190) NULL,
  notes TEXT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  reversal_of_id BIGINT UNSIGNED NULL,
  raw_source_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_inventory_tx_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_tx_club FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE SET NULL,
  CONSTRAINT fk_inventory_tx_location FOREIGN KEY (location_id) REFERENCES inventory_locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_tx_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_tx_listing FOREIGN KEY (listing_id) REFERENCES product_market_listings(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_tx_batch FOREIGN KEY (batch_id) REFERENCES inventory_batches(id) ON DELETE SET NULL,
  CONSTRAINT fk_inventory_tx_reversal FOREIGN KEY (reversal_of_id) REFERENCES inventory_transactions(id) ON DELETE SET NULL,
  CONSTRAINT fk_inventory_tx_raw FOREIGN KEY (raw_source_id) REFERENCES raw_source_records(id) ON DELETE SET NULL,
  KEY idx_inventory_tx_date (organization_id, location_id, movement_date, id),
  KEY idx_inventory_tx_product (organization_id, location_id, listing_id, movement_date),
  KEY idx_inventory_tx_reference (organization_id, reference_type, reference_id),
  KEY idx_inventory_tx_status (organization_id, status, movement_type)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_sale_allocations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  order_item_id BIGINT UNSIGNED NOT NULL,
  batch_id BIGINT UNSIGNED NOT NULL,
  inventory_transaction_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(16,3) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_inventory_alloc_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_alloc_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_alloc_item FOREIGN KEY (order_item_id) REFERENCES product_order_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_alloc_batch FOREIGN KEY (batch_id) REFERENCES inventory_batches(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_alloc_tx FOREIGN KEY (inventory_transaction_id) REFERENCES inventory_transactions(id) ON DELETE CASCADE,
  KEY idx_inventory_alloc_order (organization_id, order_id, status),
  KEY idx_inventory_alloc_item (organization_id, order_item_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_stock_counts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  count_date DATE NOT NULL,
  reference_no VARCHAR(120) NULL,
  notes TEXT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'posted',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_inventory_count_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_count_location FOREIGN KEY (location_id) REFERENCES inventory_locations(id) ON DELETE CASCADE,
  KEY idx_inventory_count_date (organization_id, location_id, count_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_stock_count_lines (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stock_count_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  listing_id BIGINT UNSIGNED NOT NULL,
  system_quantity DECIMAL(16,3) NOT NULL,
  counted_quantity DECIMAL(16,3) NOT NULL,
  variance_quantity DECIMAL(16,3) NOT NULL,
  adjustment_transaction_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_inventory_count_line_count FOREIGN KEY (stock_count_id) REFERENCES inventory_stock_counts(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_count_line_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_count_line_listing FOREIGN KEY (listing_id) REFERENCES product_market_listings(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_count_line_tx FOREIGN KEY (adjustment_transaction_id) REFERENCES inventory_transactions(id) ON DELETE SET NULL,
  KEY idx_inventory_count_line_listing (stock_count_id, listing_id)
) ENGINE=InnoDB;
