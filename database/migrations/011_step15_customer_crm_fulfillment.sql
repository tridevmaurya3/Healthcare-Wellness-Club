-- STEP 15 COMPLETE — Customer CRM + Sales Fulfillment & After-Sales
-- Healthcare Wellness Club Business OS
-- MySQL 8+ / MariaDB compatible.

USE healthcare_wellness_club;

CREATE TABLE IF NOT EXISTS crm_customers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  member_id BIGINT UNSIGNED NULL,
  customer_code VARCHAR(80) NOT NULL,
  customer_name VARCHAR(190) NOT NULL,
  mobile VARCHAR(40) NULL,
  email VARCHAR(190) NULL,
  customer_type VARCHAR(40) NOT NULL DEFAULT 'retail',
  notes TEXT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_crm_customer_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_crm_customer_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL,
  UNIQUE KEY uq_crm_customer_code (organization_id, customer_code),
  UNIQUE KEY uq_crm_customer_member (organization_id, member_id),
  KEY idx_crm_customer_name (organization_id, customer_name),
  KEY idx_crm_customer_mobile (organization_id, mobile),
  KEY idx_crm_customer_status (organization_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS crm_customer_addresses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  address_type VARCHAR(40) NOT NULL DEFAULT 'delivery',
  address_line1 VARCHAR(255) NOT NULL,
  address_line2 VARCHAR(255) NULL,
  city VARCHAR(120) NULL,
  district VARCHAR(120) NULL,
  state_name VARCHAR(120) NULL,
  postal_code VARCHAR(30) NULL,
  country_code CHAR(2) NOT NULL DEFAULT 'IN',
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_crm_address_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_crm_address_customer FOREIGN KEY (customer_id) REFERENCES crm_customers(id) ON DELETE CASCADE,
  KEY idx_crm_address_customer (organization_id, customer_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_customer_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  customer_name_snapshot VARCHAR(190) NULL,
  mobile_snapshot VARCHAR(40) NULL,
  email_snapshot VARCHAR(190) NULL,
  address_snapshot TEXT NULL,
  linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_sales_customer_link_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_customer_link_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_customer_link_customer FOREIGN KEY (customer_id) REFERENCES crm_customers(id) ON DELETE SET NULL,
  UNIQUE KEY uq_sales_customer_order (organization_id, order_id),
  KEY idx_sales_customer_customer (organization_id, customer_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_invoices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  invoice_number VARCHAR(100) NOT NULL,
  invoice_date DATE NOT NULL,
  customer_name_snapshot VARCHAR(190) NULL,
  mobile_snapshot VARCHAR(40) NULL,
  address_snapshot TEXT NULL,
  gross_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  discount_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  tax_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  net_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  volume_points DECIMAL(16,3) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  raw_source_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sales_invoice_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_invoice_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_invoice_customer FOREIGN KEY (customer_id) REFERENCES crm_customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_sales_invoice_raw FOREIGN KEY (raw_source_id) REFERENCES raw_source_records(id) ON DELETE SET NULL,
  UNIQUE KEY uq_sales_invoice_order (organization_id, order_id),
  UNIQUE KEY uq_sales_invoice_number (organization_id, invoice_number),
  KEY idx_sales_invoice_date (organization_id, invoice_date, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_deliveries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  dispatch_number VARCHAR(100) NOT NULL,
  dispatch_date DATE NOT NULL,
  delivery_mode VARCHAR(40) NOT NULL DEFAULT 'club_pickup',
  carrier_name VARCHAR(150) NULL,
  tracking_number VARCHAR(150) NULL,
  delivered_date DATE NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'dispatched',
  notes TEXT NULL,
  raw_source_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_sales_delivery_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_delivery_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_delivery_customer FOREIGN KEY (customer_id) REFERENCES crm_customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_sales_delivery_raw FOREIGN KEY (raw_source_id) REFERENCES raw_source_records(id) ON DELETE SET NULL,
  UNIQUE KEY uq_sales_dispatch_number (organization_id, dispatch_number),
  KEY idx_sales_delivery_order (organization_id, order_id, status),
  KEY idx_sales_delivery_date (organization_id, dispatch_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_delivery_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  delivery_id BIGINT UNSIGNED NOT NULL,
  order_item_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  quantity_dispatched DECIMAL(16,3) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sales_delivery_item_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_delivery_item_delivery FOREIGN KEY (delivery_id) REFERENCES sales_deliveries(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_delivery_item_order_item FOREIGN KEY (order_item_id) REFERENCES product_order_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_delivery_item_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  KEY idx_sales_delivery_item_order (organization_id, order_item_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_returns (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  return_number VARCHAR(100) NOT NULL,
  return_date DATE NOT NULL,
  reason TEXT NOT NULL,
  total_credit DECIMAL(16,2) NOT NULL DEFAULT 0,
  status VARCHAR(30) NOT NULL DEFAULT 'posted',
  raw_source_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sales_return_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_return_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_return_customer FOREIGN KEY (customer_id) REFERENCES crm_customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_sales_return_raw FOREIGN KEY (raw_source_id) REFERENCES raw_source_records(id) ON DELETE SET NULL,
  UNIQUE KEY uq_sales_return_number (organization_id, return_number),
  KEY idx_sales_return_order (organization_id, order_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_return_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  sales_return_id BIGINT UNSIGNED NOT NULL,
  order_item_id BIGINT UNSIGNED NOT NULL,
  inventory_allocation_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  quantity_returned DECIMAL(16,3) NOT NULL,
  credit_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  inventory_transaction_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sales_return_item_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_return_item_return FOREIGN KEY (sales_return_id) REFERENCES sales_returns(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_return_item_order_item FOREIGN KEY (order_item_id) REFERENCES product_order_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_return_item_allocation FOREIGN KEY (inventory_allocation_id) REFERENCES inventory_sale_allocations(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_return_item_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_return_item_tx FOREIGN KEY (inventory_transaction_id) REFERENCES inventory_transactions(id) ON DELETE CASCADE,
  KEY idx_sales_return_item_allocation (organization_id, inventory_allocation_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_refunds (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  sales_return_id BIGINT UNSIGNED NULL,
  refund_date DATE NOT NULL,
  amount DECIMAL(16,2) NOT NULL,
  refund_method VARCHAR(40) NOT NULL DEFAULT 'other',
  reference_no VARCHAR(150) NULL,
  reason TEXT NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  raw_source_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sales_refund_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_refund_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_refund_return FOREIGN KEY (sales_return_id) REFERENCES sales_returns(id) ON DELETE SET NULL,
  CONSTRAINT fk_sales_refund_raw FOREIGN KEY (raw_source_id) REFERENCES raw_source_records(id) ON DELETE SET NULL,
  KEY idx_sales_refund_order (organization_id, order_id, status),
  KEY idx_sales_refund_date (organization_id, refund_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS crm_interactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NULL,
  interaction_type VARCHAR(40) NOT NULL DEFAULT 'note',
  interaction_date DATETIME NOT NULL,
  subject VARCHAR(190) NULL,
  notes TEXT NOT NULL,
  next_followup_at DATETIME NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'done',
  raw_source_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_crm_interaction_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_crm_interaction_customer FOREIGN KEY (customer_id) REFERENCES crm_customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_crm_interaction_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
  CONSTRAINT fk_crm_interaction_raw FOREIGN KEY (raw_source_id) REFERENCES raw_source_records(id) ON DELETE SET NULL,
  KEY idx_crm_interaction_followup (organization_id, status, next_followup_at),
  KEY idx_crm_interaction_customer (organization_id, customer_id, interaction_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_fulfillment_ledger (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  invoice_id BIGINT UNSIGNED NULL,
  delivery_status VARCHAR(30) NOT NULL DEFAULT 'pending',
  original_charge DECIMAL(16,2) NOT NULL DEFAULT 0,
  return_credit DECIMAL(16,2) NOT NULL DEFAULT 0,
  gross_collected DECIMAL(16,2) NOT NULL DEFAULT 0,
  refund_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  effective_charge DECIMAL(16,2) NOT NULL DEFAULT 0,
  net_collected DECIMAL(16,2) NOT NULL DEFAULT 0,
  receivable_amount DECIMAL(16,2) NOT NULL DEFAULT 0,
  customer_credit_due DECIMAL(16,2) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sales_fulfillment_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_fulfillment_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_sales_fulfillment_customer FOREIGN KEY (customer_id) REFERENCES crm_customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_sales_fulfillment_invoice FOREIGN KEY (invoice_id) REFERENCES sales_invoices(id) ON DELETE SET NULL,
  UNIQUE KEY uq_sales_fulfillment_order (organization_id, order_id),
  KEY idx_sales_fulfillment_receivable (organization_id, receivable_amount),
  KEY idx_sales_fulfillment_delivery (organization_id, delivery_status)
) ENGINE=InnoDB;