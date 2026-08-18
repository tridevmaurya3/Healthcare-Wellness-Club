-- STEP 23 • Public Product Portal & Online Order Readiness
-- Public submissions remain order requests until staff review/final sale conversion.
USE healthcare_wellness_club;

CREATE TABLE IF NOT EXISTS public_store_settings (
  organization_id BIGINT UNSIGNED PRIMARY KEY,
  storefront_enabled TINYINT(1) NOT NULL DEFAULT 1,
  payment_mode VARCHAR(30) NOT NULL DEFAULT 'review_only',
  public_price_mode VARCHAR(30) NOT NULL DEFAULT 'mrp',
  checkout_note VARCHAR(500) NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_public_store_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_public_store_user FOREIGN KEY (updated_by) REFERENCES system_users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS public_order_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  order_code VARCHAR(90) NOT NULL,
  lead_id BIGINT UNSIGNED NULL,
  customer_id BIGINT UNSIGNED NULL,
  quote_id BIGINT UNSIGNED NULL,
  customer_name VARCHAR(190) NOT NULL,
  mobile VARCHAR(40) NOT NULL,
  email VARCHAR(190) NULL,
  address_text TEXT NULL,
  postal_code VARCHAR(20) NULL,
  delivery_mode VARCHAR(30) NOT NULL DEFAULT 'club_pickup',
  subtotal_mrp DECIMAL(16,2) NOT NULL DEFAULT 0,
  total_vp DECIMAL(16,3) NOT NULL DEFAULT 0,
  delivery_charge DECIMAL(16,2) NOT NULL DEFAULT 0,
  estimated_total DECIMAL(16,2) NOT NULL DEFAULT 0,
  tax_status VARCHAR(30) NOT NULL DEFAULT 'not_calculated',
  order_status VARCHAR(30) NOT NULL DEFAULT 'submitted',
  payment_status VARCHAR(30) NOT NULL DEFAULT 'not_requested',
  consent_to_contact TINYINT(1) NOT NULL DEFAULT 1,
  duplicate_key_hash CHAR(64) NULL,
  status_token_hash CHAR(64) NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  user_agent VARCHAR(500) NULL,
  source_path VARCHAR(255) NULL,
  customer_note TEXT NULL,
  internal_note TEXT NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_public_order_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_public_order_lead FOREIGN KEY (lead_id) REFERENCES crm_leads(id) ON DELETE SET NULL,
  CONSTRAINT fk_public_order_customer FOREIGN KEY (customer_id) REFERENCES crm_customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_public_order_quote FOREIGN KEY (quote_id) REFERENCES product_quotes(id) ON DELETE SET NULL,
  CONSTRAINT fk_public_order_reviewer FOREIGN KEY (reviewed_by) REFERENCES system_users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_public_order_code (organization_id,order_code),
  KEY idx_public_order_status (organization_id,order_status,created_at),
  KEY idx_public_order_mobile (organization_id,mobile,created_at),
  KEY idx_public_order_duplicate (organization_id,duplicate_key_hash,created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS public_order_request_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_order_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  listing_id BIGINT UNSIGNED NOT NULL,
  price_version_id BIGINT UNSIGNED NOT NULL,
  stock_no VARCHAR(100) NOT NULL,
  product_name_snapshot VARCHAR(190) NOT NULL,
  quantity DECIMAL(12,3) NOT NULL,
  unit_mrp DECIMAL(16,2) NOT NULL,
  unit_vp DECIMAL(16,3) NOT NULL,
  line_mrp DECIMAL(16,2) NOT NULL,
  line_vp DECIMAL(16,3) NOT NULL,
  availability_status VARCHAR(30) NOT NULL DEFAULT 'review',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_public_item_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_public_item_order FOREIGN KEY (public_order_id) REFERENCES public_order_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_public_item_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_public_item_listing FOREIGN KEY (listing_id) REFERENCES product_market_listings(id),
  CONSTRAINT fk_public_item_price FOREIGN KEY (price_version_id) REFERENCES product_price_versions(id),
  KEY idx_public_item_order (organization_id,public_order_id,id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS public_order_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_order_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(50) NOT NULL,
  old_status VARCHAR(30) NULL,
  new_status VARCHAR(30) NULL,
  note VARCHAR(500) NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_public_event_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_public_event_order FOREIGN KEY (public_order_id) REFERENCES public_order_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_public_event_user FOREIGN KEY (actor_user_id) REFERENCES system_users(id) ON DELETE SET NULL,
  KEY idx_public_event_order (organization_id,public_order_id,created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS public_checkout_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  attempt_status VARCHAR(30) NOT NULL,
  reason_code VARCHAR(60) NULL,
  cart_line_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_public_attempt_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  KEY idx_public_attempt_ip (organization_id,ip_hash,created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS public_payment_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  public_order_id BIGINT UNSIGNED NOT NULL,
  payment_mode VARCHAR(30) NOT NULL DEFAULT 'review_only',
  provider_code VARCHAR(80) NULL,
  provider_reference VARCHAR(190) NULL,
  amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  currency_code CHAR(3) NOT NULL DEFAULT 'INR',
  payment_status VARCHAR(30) NOT NULL DEFAULT 'not_requested',
  provider_payload_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_public_payment_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_public_payment_order FOREIGN KEY (public_order_id) REFERENCES public_order_requests(id) ON DELETE CASCADE,
  UNIQUE KEY uq_public_payment_order (organization_id,public_order_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS public_store_exports (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  export_type VARCHAR(40) NOT NULL,
  row_count INT UNSIGNED NOT NULL DEFAULT 0,
  exported_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_public_export_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_public_export_user FOREIGN KEY (exported_by) REFERENCES system_users(id) ON DELETE SET NULL,
  KEY idx_public_export_date (organization_id,created_at)
) ENGINE=InnoDB;