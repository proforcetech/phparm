-- Auction management tables and impound case auction status tracking

SET @has_impound_case_auction_status := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'impound_cases' AND column_name = 'auction_status'
);
SET @impound_case_auction_status_sql := IF(@has_impound_case_auction_status = 0,
    "ALTER TABLE impound_cases ADD COLUMN auction_status VARCHAR(40) NOT NULL DEFAULT 'in_storage' AFTER status",
    'SELECT 1'
);
PREPARE impound_case_auction_status_stmt FROM @impound_case_auction_status_sql;
EXECUTE impound_case_auction_status_stmt;
DEALLOCATE PREPARE impound_case_auction_status_stmt;

SET @has_impound_case_auction_status_updated := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'impound_cases' AND column_name = 'auction_status_updated_at'
);
SET @impound_case_auction_status_updated_sql := IF(@has_impound_case_auction_status_updated = 0,
    'ALTER TABLE impound_cases ADD COLUMN auction_status_updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER auction_status',
    'SELECT 1'
);
PREPARE impound_case_auction_status_updated_stmt FROM @impound_case_auction_status_updated_sql;
EXECUTE impound_case_auction_status_updated_stmt;
DEALLOCATE PREPARE impound_case_auction_status_updated_stmt;

CREATE TABLE IF NOT EXISTS auction_lots (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    impound_case_id INT UNSIGNED NOT NULL,
    lot_number VARCHAR(60) NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'scheduled',
    status_updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    auction_date DATE NULL,
    sale_price DECIMAL(10,2) NULL,
    buyer_name VARCHAR(160) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_auction_lots_case (impound_case_id),
    INDEX idx_auction_lots_status (status),
    INDEX idx_auction_lots_date (auction_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_fk_auction_lots_case := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'auction_lots' AND constraint_name = 'fk_auction_lots_case'
);
SET @has_auction_lots_case_match := (
    SELECT COUNT(*) FROM information_schema.columns c
    JOIN information_schema.columns r
      ON c.table_schema = r.table_schema
     AND c.column_type = r.column_type
     AND r.table_name = 'impound_cases'
     AND r.column_name = 'id'
    WHERE c.table_schema = DATABASE()
      AND c.table_name = 'auction_lots'
      AND c.column_name = 'impound_case_id'
);
SET @fk_auction_lots_case_sql := IF(@has_fk_auction_lots_case = 0 AND @has_auction_lots_case_match = 1,
    'ALTER TABLE auction_lots ADD CONSTRAINT fk_auction_lots_case FOREIGN KEY (impound_case_id) REFERENCES impound_cases (id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE fk_auction_lots_case_stmt FROM @fk_auction_lots_case_sql;
EXECUTE fk_auction_lots_case_stmt;
DEALLOCATE PREPARE fk_auction_lots_case_stmt;
