-- ============================================================================
-- VillonFarm POS - Database update script
-- Creates/updates every table EXCEPT the UOM feature and the procurement
-- feature, which are applied afterwards by `php artisan migrate --force`.
-- ============================================================================
-- How to use:
--   1. Open phpMyAdmin -> select the application database.
--   2. Go to the "SQL" tab, paste this file's contents, and run it.
--      (Or use the "Import" tab and upload this file.)
--   3. THIS SCRIPT IS IDEMPOTENT: tables use CREATE TABLE IF NOT EXISTS and
--      column alterations are guarded, so re-running it is safe.
--   4. After running this, THREE migrations must still be applied from the
--      application itself: run `php artisan migrate --force` in the cPanel
--      terminal. Those apply:
--        - the UOM migration (unit_types/units/product_units, products.base_unit_id,
--          sale_items.unit_id, stock_movements.unit_id, drops products.unit,
--          and seeds the unit catalog + backfills existing products),
--        - the procurement migration (purchase_orders / purchase_order_items), and
--        - the consignment migration (consignment_partners / consignments /
--          consignment_items / consignment_sales / consignment_adjustments /
--          consignment_settlements).
--      The procurement tables are intentionally left out of this script:
--      purchase_order_items has a foreign key to units, which only exists once
--      the UOM migration has run, so Laravel itself must create them. The
--      consignment tables ARE created here (their unit_id columns have no
--      foreign key), and the migration is recorded in the `migrations` table
--      so Laravel skips re-creating them.
--      This script records the migrations it DID apply in the `migrations`
--      table (see the bottom of the file), so step 4 only runs the three above.
--   5. If this is a BRAND-NEW/empty database, you must also create an initial
--      login user + product catalog: run `php artisan db:seed --force` after
--      `php artisan migrate --force`. Do NOT run the seeders on a database
--      that already has production data.
--
-- NOTE: No `USE <dbname>` statement is included on purpose; phpMyAdmin runs
-- this on whichever database is currently selected.
-- ============================================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- users, password_reset_tokens, sessions
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    email_verified_at TIMESTAMP NULL DEFAULT NULL,
    password VARCHAR(255) NOT NULL,
    remember_token VARCHAR(100) NULL DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY users_email_unique (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    email VARCHAR(255) NOT NULL,
    token VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(255) NOT NULL,
    user_id BIGINT UNSIGNED NULL DEFAULT NULL,
    ip_address VARCHAR(45) NULL DEFAULT NULL,
    user_agent TEXT NULL,
    payload LONGTEXT NOT NULL,
    last_activity INT NOT NULL,
    PRIMARY KEY (id),
    KEY sessions_user_id_index (user_id),
    KEY sessions_last_activity_index (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- cache, cache_locks
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cache (
    key VARCHAR(255) NOT NULL,
    value MEDIUMTEXT NOT NULL,
    expiration BIGINT NOT NULL,
    PRIMARY KEY (key),
    KEY cache_expiration_index (expiration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cache_locks (
    key VARCHAR(255) NOT NULL,
    owner VARCHAR(255) NOT NULL,
    expiration BIGINT NOT NULL,
    PRIMARY KEY (key),
    KEY cache_locks_expiration_index (expiration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- jobs, job_batches, failed_jobs
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    queue VARCHAR(255) NOT NULL,
    payload LONGTEXT NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL,
    reserved_at INT UNSIGNED NULL DEFAULT NULL,
    available_at INT UNSIGNED NOT NULL,
    created_at INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY jobs_queue_index (queue)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS job_batches (
    id VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    total_jobs INT NOT NULL,
    pending_jobs INT NOT NULL,
    failed_jobs INT NOT NULL,
    failed_job_ids LONGTEXT NOT NULL,
    options MEDIUMTEXT NULL,
    cancelled_at INT NULL DEFAULT NULL,
    created_at INT NOT NULL,
    finished_at INT NULL DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS failed_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid VARCHAR(255) NOT NULL,
    connection TEXT NOT NULL,
    queue TEXT NOT NULL,
    payload LONGTEXT NOT NULL,
    exception LONGTEXT NOT NULL,
    failed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY failed_jobs_uuid_unique (uuid),
    KEY failed_jobs_connection_queue_failed_at_index (connection(255), queue(255), failed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- customers
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    farm_name VARCHAR(255) NULL DEFAULT NULL,
    phone VARCHAR(255) NULL DEFAULT NULL,
    location VARCHAR(255) NULL DEFAULT NULL,
    crop VARCHAR(255) NULL DEFAULT NULL,
    hectares DECIMAL(8,2) NULL DEFAULT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- products (final pre-UOM shape: keeps `unit`, has no base_unit_id yet)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    active_ingredient VARCHAR(255) NULL DEFAULT NULL,
    batch_number VARCHAR(255) NULL DEFAULT NULL,
    expiry_date DATE NULL DEFAULT NULL,
    price DECIMAL(10,2) NULL DEFAULT NULL,
    stock DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    unit VARCHAR(10) NOT NULL DEFAULT 'L',
    category VARCHAR(255) NULL DEFAULT NULL,
    cost_price DECIMAL(10,2) NULL DEFAULT NULL,
    dealers_price_cod DECIMAL(10,2) NULL DEFAULT NULL,
    terms_30_days DECIMAL(10,2) NULL DEFAULT NULL,
    note TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY products_category_index (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- sales
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sales (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NULL DEFAULT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    discount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(255) NOT NULL DEFAULT 'cash',
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    CONSTRAINT sales_customer_id_foreign
        FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- sale_items
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sale_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sale_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    line_total DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    CONSTRAINT sale_items_sale_id_foreign
        FOREIGN KEY (sale_id) REFERENCES sales (id) ON DELETE CASCADE,
    CONSTRAINT sale_items_product_id_foreign
        FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- suppliers
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS suppliers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    contact_person VARCHAR(255) NULL DEFAULT NULL,
    phone VARCHAR(255) NULL DEFAULT NULL,
    location VARCHAR(255) NULL DEFAULT NULL,
    note VARCHAR(255) NULL DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- stock_movements
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS stock_movements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(255) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit_cost DECIMAL(10,2) NULL DEFAULT NULL,
    reason VARCHAR(255) NULL DEFAULT NULL,
    supplier_id BIGINT UNSIGNED NULL DEFAULT NULL,
    ref_type VARCHAR(255) NULL DEFAULT NULL,
    ref_id BIGINT UNSIGNED NULL DEFAULT NULL,
    user_id BIGINT UNSIGNED NULL DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY stock_movements_product_id_type_created_at_index (product_id, type, created_at),
    CONSTRAINT stock_movements_product_id_foreign
        FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
    CONSTRAINT stock_movements_supplier_id_foreign
        FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL,
    CONSTRAINT stock_movements_user_id_foreign
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- refunds
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS refunds (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sale_id BIGINT UNSIGNED NOT NULL,
    sale_item_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    line_total DECIMAL(10,2) NOT NULL,
    note VARCHAR(255) NULL DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    CONSTRAINT refunds_sale_id_foreign
        FOREIGN KEY (sale_id) REFERENCES sales (id) ON DELETE CASCADE,
    CONSTRAINT refunds_sale_item_id_foreign
        FOREIGN KEY (sale_item_id) REFERENCES sale_items (id) ON DELETE CASCADE,
    CONSTRAINT refunds_product_id_foreign
        FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Pricing/category columns added to products by the migration
-- "add_pricing_and_category_to_products_table" (guarded for existing tables)
-- ============================================================================
SET @db = DATABASE();

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'products' AND COLUMN_NAME = 'category');
SET @sql = IF(@col = 0,
    'ALTER TABLE products ADD COLUMN category VARCHAR(255) NULL DEFAULT NULL, ADD KEY products_category_index (category)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'products' AND COLUMN_NAME = 'cost_price');
SET @sql = IF(@col = 0,
    'ALTER TABLE products ADD COLUMN cost_price DECIMAL(10,2) NULL DEFAULT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'products' AND COLUMN_NAME = 'dealers_price_cod');
SET @sql = IF(@col = 0,
    'ALTER TABLE products ADD COLUMN dealers_price_cod DECIMAL(10,2) NULL DEFAULT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'products' AND COLUMN_NAME = 'terms_30_days');
SET @sql = IF(@col = 0,
    'ALTER TABLE products ADD COLUMN terms_30_days DECIMAL(10,2) NULL DEFAULT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'products' AND COLUMN_NAME = 'note');
SET @sql = IF(@col = 0,
    'ALTER TABLE products ADD COLUMN note TEXT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Make price nullable (the pricing migration switches it to nullable)
SET @col = (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'products'
                AND COLUMN_NAME = 'price' AND IS_NULLABLE = 'NO');
SET @sql = IF(@col > 0,
    'ALTER TABLE products MODIFY COLUMN price DECIMAL(10,2) NULL DEFAULT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- Consignment feature tables (all 6, in dependency order).
-- NOTE: unit_id columns below intentionally have NO foreign key to units;
-- the migration creates one, but units only exist once the UOM migration has
-- run on the server. The app validates every unit_id it writes against the
-- units table instead.
-- ----------------------------------------------------------------------------

-- consignment_partners
CREATE TABLE IF NOT EXISTS consignment_partners (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    contact_person VARCHAR(255) NULL DEFAULT NULL,
    phone VARCHAR(255) NULL DEFAULT NULL,
    location VARCHAR(255) NULL DEFAULT NULL,
    note VARCHAR(255) NULL DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- consignments
CREATE TABLE IF NOT EXISTS consignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    partner_id BIGINT UNSIGNED NULL DEFAULT NULL,
    received_at DATE NOT NULL,
    note TEXT NULL,
    created_by BIGINT UNSIGNED NULL DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY consignments_partner_id_received_at_index (partner_id, received_at),
    CONSTRAINT consignments_partner_id_foreign
        FOREIGN KEY (partner_id) REFERENCES consignment_partners (id) ON DELETE SET NULL,
    CONSTRAINT consignments_created_by_foreign
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- consignment_items
CREATE TABLE IF NOT EXISTS consignment_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    consignment_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit_id BIGINT UNSIGNED NULL DEFAULT NULL,
    unit_cost DECIMAL(10,2) NOT NULL DEFAULT '0.00',
    line_total DECIMAL(10,2) NOT NULL DEFAULT '0.00',
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY consignment_items_unit_id_index (unit_id),
    CONSTRAINT consignment_items_consignment_id_foreign
        FOREIGN KEY (consignment_id) REFERENCES consignments (id) ON DELETE CASCADE,
    CONSTRAINT consignment_items_product_id_foreign
        FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- consignment_sales
CREATE TABLE IF NOT EXISTS consignment_sales (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    partner_id BIGINT UNSIGNED NULL DEFAULT NULL,
    sale_id BIGINT UNSIGNED NULL DEFAULT NULL,
    customer_id BIGINT UNSIGNED NULL DEFAULT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit_id BIGINT UNSIGNED NULL DEFAULT NULL,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT '0.00',
    line_total DECIMAL(10,2) NOT NULL DEFAULT '0.00',
    unit_cost DECIMAL(10,2) NOT NULL DEFAULT '0.00',
    payable_amount DECIMAL(10,2) NOT NULL DEFAULT '0.00',
    sold_at TIMESTAMP NOT NULL,
    created_by BIGINT UNSIGNED NULL DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY consignment_sales_unit_id_index (unit_id),
    CONSTRAINT consignment_sales_partner_id_foreign
        FOREIGN KEY (partner_id) REFERENCES consignment_partners (id) ON DELETE SET NULL,
    CONSTRAINT consignment_sales_sale_id_foreign
        FOREIGN KEY (sale_id) REFERENCES sales (id) ON DELETE SET NULL,
    CONSTRAINT consignment_sales_customer_id_foreign
        FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL,
    CONSTRAINT consignment_sales_product_id_foreign
        FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT,
    CONSTRAINT consignment_sales_created_by_foreign
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- consignment_adjustments
CREATE TABLE IF NOT EXISTS consignment_adjustments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    partner_id BIGINT UNSIGNED NULL DEFAULT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    reason VARCHAR(255) NOT NULL DEFAULT 'return',
    value DECIMAL(10,2) NOT NULL DEFAULT '0.00',
    adjusted_at DATE NOT NULL,
    note TEXT NULL,
    created_by BIGINT UNSIGNED NULL DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    CONSTRAINT consignment_adjustments_partner_id_foreign
        FOREIGN KEY (partner_id) REFERENCES consignment_partners (id) ON DELETE SET NULL,
    CONSTRAINT consignment_adjustments_product_id_foreign
        FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT,
    CONSTRAINT consignment_adjustments_created_by_foreign
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- consignment_settlements
CREATE TABLE IF NOT EXISTS consignment_settlements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    partner_id BIGINT UNSIGNED NULL DEFAULT NULL,
    amount DECIMAL(10,2) NOT NULL,
    settled_at DATE NOT NULL,
    note TEXT NULL,
    created_by BIGINT UNSIGNED NULL DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    CONSTRAINT consignment_settlements_partner_id_foreign
        FOREIGN KEY (partner_id) REFERENCES consignment_partners (id) ON DELETE SET NULL,
    CONSTRAINT consignment_settlements_created_by_foreign
        FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @db = NULL, @col = NULL, @sql = NULL;

-- ============================================================================
-- migrations table: record every migration this script applied so that
-- `php artisan migrate --force` (step 4) only runs the UOM and procurement
-- migrations. Rows that already exist are skipped; re-running is safe.
-- ============================================================================
CREATE TABLE IF NOT EXISTS migrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    migration VARCHAR(255) NOT NULL,
    batch INT NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @next_batch = (SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations);

INSERT INTO migrations (migration, batch)
SELECT '0001_01_01_000000_create_users_table', @next_batch
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '0001_01_01_000000_create_users_table');

INSERT INTO migrations (migration, batch)
SELECT '0001_01_01_000001_create_cache_table', @next_batch
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '0001_01_01_000001_create_cache_table');

INSERT INTO migrations (migration, batch)
SELECT '0001_01_01_000002_create_jobs_table', @next_batch
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '0001_01_01_000002_create_jobs_table');

INSERT INTO migrations (migration, batch)
SELECT '2026_08_07_114506_create_customers_table', @next_batch
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_08_07_114506_create_customers_table');

INSERT INTO migrations (migration, batch)
SELECT '2026_08_07_114507_create_products_table', @next_batch
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_08_07_114507_create_products_table');

INSERT INTO migrations (migration, batch)
SELECT '2026_08_07_114507_create_sales_table', @next_batch
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_08_07_114507_create_sales_table');

INSERT INTO migrations (migration, batch)
SELECT '2026_08_07_114508_create_sale_items_table', @next_batch
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_08_07_114508_create_sale_items_table');

INSERT INTO migrations (migration, batch)
SELECT '2026_09_23_041424_add_pricing_and_category_to_products_table', @next_batch
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_09_23_041424_add_pricing_and_category_to_products_table');

INSERT INTO migrations (migration, batch)
SELECT '2026_09_23_051838_create_suppliers_table', @next_batch
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_09_23_051838_create_suppliers_table');

INSERT INTO migrations (migration, batch)
SELECT '2026_09_23_051839_create_stock_movements_table', @next_batch
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_09_23_051839_create_stock_movements_table');

INSERT INTO migrations (migration, batch)
SELECT '2026_09_23_051840_create_refunds_table', @next_batch
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_09_23_051840_create_refunds_table');

INSERT INTO migrations (migration, batch)
SELECT '2026_09_23_083008_create_consignment_tables', @next_batch
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_09_23_083008_create_consignment_tables');

SET @next_batch = NULL;