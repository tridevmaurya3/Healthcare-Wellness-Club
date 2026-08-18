-- STEP 12 COMPLETE — Product Sales -> Business OS integration layer
-- Healthcare Wellness Club Business OS
-- MySQL 8+ / MariaDB compatible.
-- IMPORTANT: cost/profit is never inferred from MRP, Earn Base, discount tiers or VP.
-- Profit becomes authoritative only when an explicit product_cost_versions row exists.

USE healthcare_wellness_club;

CREATE TABLE IF NOT EXISTS product_cost_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  market_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  listing_id BIGINT UNSIGNED NOT NULL,
  effective_from DATE NOT NULL,
  effective_to DATE NULL,
  unit_cost DECIMAL(16,2) NOT NULL,
  currency_code CHAR(3) NOT NULL DEFAULT 'INR',
  basis_code VARCHAR(60) NOT NULL DEFAULT 'explicit_cost',
  source_reference VARCHAR(700) NULL,
  notes TEXT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_cost_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_cost_market FOREIGN KEY (market_id) REFERENCES product_markets(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_cost_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_cost_listing FOREIGN KEY (listing_id) REFERENCES product_market_listings(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_cost_currency FOREIGN KEY (currency_code) REFERENCES currencies(currency_code),
  UNIQUE KEY uq_product_cost_version (organization_id, listing_id, effective_from),
  KEY idx_product_cost_effective (organization_id, listing_id, status, effective_from),
  KEY idx_product_cost_product (organization_id, product_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_sale_ledger (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  quote_id BIGINT UNSIGNED NULL,
  sale_status VARCHAR(30) NOT NULL DEFAULT 'active',
  payment_status VARCHAR(30) NOT NULL DEFAULT 'unpaid',
  payment_method VARCHAR(50) NULL,
  paid_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  cost_status VARCHAR(30) NOT NULL DEFAULT 'deferred',
  cost_total DECIMAL(16,2) NULL,
  profit_total DECIMAL(16,2) NULL,
  finalized_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cancelled_at DATETIME NULL,
  cancellation_reason TEXT NULL,
  restored_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_sale_ledger_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_sale_ledger_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_sale_ledger_quote FOREIGN KEY (quote_id) REFERENCES product_quotes(id) ON DELETE SET NULL,
  UNIQUE KEY uq_product_sale_ledger_order (organization_id, order_id),
  KEY idx_product_sale_ledger_status (organization_id, sale_status, payment_status, finalized_at),
  KEY idx_product_sale_ledger_quote (organization_id, quote_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_sale_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  sale_ledger_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  payment_date DATE NOT NULL,
  amount DECIMAL(16,2) NOT NULL,
  payment_method VARCHAR(50) NOT NULL DEFAULT 'other',
  reference_no VARCHAR(120) NULL,
  notes TEXT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_sale_payment_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_sale_payment_ledger FOREIGN KEY (sale_ledger_id) REFERENCES product_sale_ledger(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_sale_payment_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  KEY idx_product_sale_payment_order (organization_id, order_id, payment_date),
  KEY idx_product_sale_payment_status (organization_id, status, payment_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_sale_lifecycle_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  sale_ledger_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(50) NOT NULL,
  reason TEXT NULL,
  snapshot_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_sale_event_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_sale_event_ledger FOREIGN KEY (sale_ledger_id) REFERENCES product_sale_ledger(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_sale_event_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  KEY idx_product_sale_event_order (organization_id, order_id, created_at),
  KEY idx_product_sale_event_type (organization_id, event_type, created_at)
) ENGINE=InnoDB;
