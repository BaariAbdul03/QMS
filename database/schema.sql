-- ---------------------------------------------------------------------------
-- Smart Queue Management System (QMS) — schema
-- Target: MySQL 8.0.1+ / MariaDB 10.6+   (needs SELECT ... FOR UPDATE SKIP LOCKED)
-- Re-runnable: every statement is guarded with IF NOT EXISTS.
--   Fresh install : mysql -u root < database/schema.sql
--   Demo reset    : php database/reset.php   (see Phase 6)
-- ---------------------------------------------------------------------------

CREATE DATABASE IF NOT EXISTS qms
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE qms;

-- Services a visitor can take a token for (General OPD, Billing, ...).
CREATE TABLE IF NOT EXISTS services (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  prefix CHAR(1) NOT NULL UNIQUE,                            -- 'A', 'B' ...
  avg_service_minutes DECIMAL(5,2) NOT NULL DEFAULT 7.00,    -- fallback only, real data wins
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- Physical service desks. service_id NULL == handles every service.
CREATE TABLE IF NOT EXISTS counters (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL,
  service_id INT NULL,
  staff_name VARCHAR(50) NULL,
  is_open TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_counters_service FOREIGN KEY (service_id) REFERENCES services(id)
) ENGINE=InnoDB;

-- Source of truth for "next number": exactly one row per service per day.
-- A single row lock serialises concurrent issuers without gap locks.
CREATE TABLE IF NOT EXISTS queue_counters (
  service_id INT NOT NULL,
  queue_date DATE NOT NULL,
  last_number INT UNSIGNED NOT NULL,
  PRIMARY KEY (service_id, queue_date),
  CONSTRAINT fk_queue_counters_service FOREIGN KEY (service_id) REFERENCES services(id)
) ENGINE=InnoDB;

-- Tokens (the queue itself).
CREATE TABLE IF NOT EXISTS tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(16) NOT NULL UNIQUE,              -- random, the only id visitors ever see
  service_id INT NOT NULL,
  counter_id INT NULL,                             -- set when called
  queue_date DATE NOT NULL,                        -- computed in Asia/Kolkata, not CURDATE()
  number INT UNSIGNED NOT NULL,
  display_code VARCHAR(10) NOT NULL,               -- 'A-014'
  status ENUM('waiting','called','serving','completed','no_show','cancelled')
         NOT NULL DEFAULT 'waiting',
  taken_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  called_at    DATETIME NULL,
  serving_at   DATETIME NULL,                      -- ETA measures serving_at -> completed_at
  completed_at DATETIME NULL,
  UNIQUE KEY uq_token (service_id, queue_date, number),      -- safety net against duplicates
  KEY idx_queue (service_id, queue_date, status, number),    -- position + callNext
  KEY idx_done  (service_id, status, completed_at),          -- ETA sample lookup
  CONSTRAINT fk_tokens_service FOREIGN KEY (service_id) REFERENCES services(id),
  CONSTRAINT fk_tokens_counter FOREIGN KEY (counter_id) REFERENCES counters(id)
) ENGINE=InnoDB;
