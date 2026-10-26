-- database/migrations/004_payment_proofs_and_verification.sql
-- Penambahan kolom bukti transfer dan tabel audit verifikasi pembayaran oleh penjual

-- 1. Modifikasi tabel payments
ALTER TABLE `payments`
  ADD `proof_image_url` VARCHAR(255) DEFAULT NULL AFTER `va_number`,
  ADD `rejection_reason` TEXT DEFAULT NULL AFTER `proof_image_url`,
  ADD `verified_at` DATETIME DEFAULT NULL AFTER `paid_at`,
  ADD `verified_by` INT DEFAULT NULL AFTER `verified_at`,
  MODIFY COLUMN `payment_status` ENUM('PENDING','MENUNGGU_VERIFIKASI','PAID','REJECTED','EXPIRED','FAILED') DEFAULT 'PENDING';

-- 2. Modifikasi tabel sub_orders untuk status MENUNGGU_VERIFIKASI
ALTER TABLE `sub_orders`
  MODIFY COLUMN `order_status` ENUM('MENUNGGU_BAYAR','MENUNGGU_VERIFIKASI','DIKEMAS','DIKIRIM','SELESAI','DIKOMPLAIN','BATAL') DEFAULT 'MENUNGGU_BAYAR';

-- 3. Pembuatan tabel audit log verifikasi pembayaran
CREATE TABLE IF NOT EXISTS `payment_verification_logs` (
  `log_id` INT NOT NULL AUTO_INCREMENT,
  `payment_id` INT NOT NULL,
  `master_order_id` INT NOT NULL,
  `sub_order_id` INT DEFAULT NULL,
  `action` ENUM('SUBMIT_PROOF','APPROVE','REJECT') NOT NULL,
  `actor_user_id` INT NOT NULL,
  `actor_role` ENUM('buyer','seller','admin') NOT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `idx_payment_master` (`payment_id`, `master_order_id`),
  KEY `idx_sub_order` (`sub_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
