-- STEP 11A — Product & Price Pro world-ready foundation
-- Healthcare Wellness Club Business OS
-- MySQL 8+ / MariaDB compatible.
-- No product names, prices or health claims are seeded here.

USE healthcare_wellness_club;

CREATE TABLE IF NOT EXISTS product_markets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  market_code VARCHAR(40) NOT NULL,
  market_name VARCHAR(120) NOT NULL,
  country_code CHAR(2) NOT NULL,
  currency_code CHAR(3) NOT NULL,
  locale VARCHAR(20) NOT NULL DEFAULT 'en',
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_market_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_market_country FOREIGN KEY (country_code) REFERENCES countries(country_code),
  CONSTRAINT fk_product_market_currency FOREIGN KEY (currency_code) REFERENCES currencies(currency_code),
  UNIQUE KEY uq_product_market_code (organization_id, market_code),
  KEY idx_product_market_country (organization_id, country_code, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  category_code VARCHAR(80) NOT NULL,
  category_name VARCHAR(150) NOT NULL,
  description TEXT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_category_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  UNIQUE KEY uq_product_category_code (organization_id, category_code),
  KEY idx_product_category_status (organization_id, status, sort_order)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS products (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NULL,
  product_code VARCHAR(100) NOT NULL,
  sku VARCHAR(100) NULL,
  product_name VARCHAR(190) NOT NULL,
  short_name VARCHAR(120) NULL,
  brand_name VARCHAR(120) NULL,
  description TEXT NULL,
  pack_size DECIMAL(12,3) NULL,
  pack_unit VARCHAR(40) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_category FOREIGN KEY (category_id) REFERENCES product_categories(id) ON DELETE SET NULL,
  UNIQUE KEY uq_product_code (organization_id, product_code),
  KEY idx_product_sku (organization_id, sku),
  KEY idx_product_name (organization_id, product_name),
  KEY idx_product_category_status (organization_id, category_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_images (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,
  image_url VARCHAR(600) NOT NULL,
  alt_text VARCHAR(220) NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_image_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  KEY idx_product_image_order (product_id, is_primary, sort_order)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_market_listings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  market_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  market_sku VARCHAR(100) NULL,
  source_record_id BIGINT UNSIGNED NULL,
  source_reference VARCHAR(255) NULL,
  active_from DATE NULL,
  active_to DATE NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_listing_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_listing_market FOREIGN KEY (market_id) REFERENCES product_markets(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_listing_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_listing_source FOREIGN KEY (source_record_id) REFERENCES raw_source_records(id) ON DELETE SET NULL,
  UNIQUE KEY uq_product_market_listing (organization_id, market_id, product_id),
  KEY idx_product_listing_status (organization_id, market_id, status),
  KEY idx_product_listing_market_sku (organization_id, market_id, market_sku)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_price_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  listing_id BIGINT UNSIGNED NOT NULL,
  effective_from DATE NOT NULL,
  effective_to DATE NULL,
  mrp DECIMAL(16,2) NOT NULL DEFAULT 0,
  volume_points DECIMAL(16,3) NOT NULL DEFAULT 0,
  currency_code CHAR(3) NOT NULL,
  source_record_id BIGINT UNSIGNED NULL,
  source_reference VARCHAR(255) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_price_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_price_listing FOREIGN KEY (listing_id) REFERENCES product_market_listings(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_price_currency FOREIGN KEY (currency_code) REFERENCES currencies(currency_code),
  CONSTRAINT fk_product_price_source FOREIGN KEY (source_record_id) REFERENCES raw_source_records(id) ON DELETE SET NULL,
  UNIQUE KEY uq_product_price_version (organization_id, listing_id, effective_from),
  KEY idx_product_price_active (organization_id, listing_id, status, effective_from),
  KEY idx_product_price_period (organization_id, effective_from, effective_to)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_discount_tiers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  market_id BIGINT UNSIGNED NOT NULL,
  tier_code VARCHAR(60) NOT NULL,
  tier_name VARCHAR(120) NOT NULL,
  customer_type VARCHAR(60) NULL,
  discount_percent DECIMAL(6,3) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_tier_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_tier_market FOREIGN KEY (market_id) REFERENCES product_markets(id) ON DELETE CASCADE,
  UNIQUE KEY uq_product_tier_code (organization_id, market_id, tier_code),
  KEY idx_product_tier_order (organization_id, market_id, status, sort_order)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_tier_prices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  price_version_id BIGINT UNSIGNED NOT NULL,
  discount_tier_id BIGINT UNSIGNED NOT NULL,
  price_amount DECIMAL(16,2) NULL,
  pricing_method VARCHAR(30) NOT NULL DEFAULT 'computed',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_tier_price_version FOREIGN KEY (price_version_id) REFERENCES product_price_versions(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_tier_price_tier FOREIGN KEY (discount_tier_id) REFERENCES product_discount_tiers(id) ON DELETE CASCADE,
  UNIQUE KEY uq_product_tier_price (price_version_id, discount_tier_id)
) ENGINE=InnoDB;

SET @hwc_org_id = (SELECT id FROM organizations WHERE organization_code='HWC-001' LIMIT 1);

INSERT INTO product_markets
  (organization_id, market_code, market_name, country_code, currency_code, locale, status)
SELECT @hwc_org_id, 'IN', 'India', 'IN', 'INR', 'en-IN', 'active'
WHERE @hwc_org_id IS NOT NULL
ON DUPLICATE KEY UPDATE market_name=VALUES(market_name), currency_code=VALUES(currency_code), locale=VALUES(locale), status='active';

SET @india_market_id = (
  SELECT id FROM product_markets WHERE organization_id=@hwc_org_id AND market_code='IN' LIMIT 1
);

-- Discount tiers are configuration only. Product prices remain empty until an authoritative source is imported.
INSERT INTO product_discount_tiers
  (organization_id, market_id, tier_code, tier_name, customer_type, discount_percent, sort_order, status)
SELECT @hwc_org_id, @india_market_id, 'PC15', 'Preferred Customer 15%', 'preferred_customer', 15.000, 10, 'active'
WHERE @hwc_org_id IS NOT NULL AND @india_market_id IS NOT NULL
ON DUPLICATE KEY UPDATE tier_name=VALUES(tier_name), customer_type=VALUES(customer_type), discount_percent=VALUES(discount_percent), sort_order=VALUES(sort_order), status='active';

INSERT INTO product_discount_tiers
  (organization_id, market_id, tier_code, tier_name, customer_type, discount_percent, sort_order, status)
SELECT @hwc_org_id, @india_market_id, 'PC25', 'Preferred Customer 25%', 'preferred_customer', 25.000, 20, 'active'
WHERE @hwc_org_id IS NOT NULL AND @india_market_id IS NOT NULL
ON DUPLICATE KEY UPDATE tier_name=VALUES(tier_name), customer_type=VALUES(customer_type), discount_percent=VALUES(discount_percent), sort_order=VALUES(sort_order), status='active';

INSERT INTO product_discount_tiers
  (organization_id, market_id, tier_code, tier_name, customer_type, discount_percent, sort_order, status)
SELECT @hwc_org_id, @india_market_id, 'PC35', 'Preferred Customer 35%', 'preferred_customer', 35.000, 30, 'active'
WHERE @hwc_org_id IS NOT NULL AND @india_market_id IS NOT NULL
ON DUPLICATE KEY UPDATE tier_name=VALUES(tier_name), customer_type=VALUES(customer_type), discount_percent=VALUES(discount_percent), sort_order=VALUES(sort_order), status='active';

INSERT INTO product_discount_tiers
  (organization_id, market_id, tier_code, tier_name, customer_type, discount_percent, sort_order, status)
SELECT @hwc_org_id, @india_market_id, 'AS42', 'Associate 42%', 'associate', 42.000, 40, 'active'
WHERE @hwc_org_id IS NOT NULL AND @india_market_id IS NOT NULL
ON DUPLICATE KEY UPDATE tier_name=VALUES(tier_name), customer_type=VALUES(customer_type), discount_percent=VALUES(discount_percent), sort_order=VALUES(sort_order), status='active';

INSERT INTO product_discount_tiers
  (organization_id, market_id, tier_code, tier_name, customer_type, discount_percent, sort_order, status)
SELECT @hwc_org_id, @india_market_id, 'AS50', 'Associate 50%', 'associate', 50.000, 50, 'active'
WHERE @hwc_org_id IS NOT NULL AND @india_market_id IS NOT NULL
ON DUPLICATE KEY UPDATE tier_name=VALUES(tier_name), customer_type=VALUES(customer_type), discount_percent=VALUES(discount_percent), sort_order=VALUES(sort_order), status='active';
