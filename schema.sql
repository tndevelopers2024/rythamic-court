-- Schema for Rhythmic Beat Database (Compatible with Localhost XAMPP & Hostinger phpMyAdmin)

CREATE TABLE IF NOT EXISTS workshop_slots (
    workshop_id VARCHAR(64) PRIMARY KEY,
    title VARCHAR(120) NOT NULL,
    tier1_limit INT NOT NULL DEFAULT 10,
    tier2_limit INT NOT NULL DEFAULT 20,
    tier1_price INT NOT NULL DEFAULT 899,
    tier2_price INT NOT NULL DEFAULT 999,
    tier3_price INT NOT NULL DEFAULT 1099,
    max_seats INT NOT NULL DEFAULT 50,
    manual_offset INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO workshop_slots (workshop_id, title, tier1_limit, tier2_limit, tier1_price, tier2_price, tier3_price, max_seats, manual_offset)
VALUES ('pookkal-pookkum', 'Pookkal Pookum Tharunam', 10, 20, 899, 999, 1099, 50, 0)
ON DUPLICATE KEY UPDATE 
    title=VALUES(title),
    tier1_limit=VALUES(tier1_limit),
    tier2_limit=VALUES(tier2_limit),
    tier1_price=VALUES(tier1_price),
    tier2_price=VALUES(tier2_price),
    tier3_price=VALUES(tier3_price),
    max_seats=VALUES(max_seats);

CREATE TABLE IF NOT EXISTS workshop_bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    workshop_id VARCHAR(64) NOT NULL,
    name VARCHAR(120),
    phone VARCHAR(30),
    email VARCHAR(120),
    tier VARCHAR(64),
    qty INT NOT NULL DEFAULT 1,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    ref VARCHAR(64) UNIQUE,
    payment_id VARCHAR(64),
    order_id VARCHAR(64),
    method VARCHAR(64),
    status VARCHAR(64) DEFAULT 'Paid & Confirmed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_workshop_status (workshop_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
