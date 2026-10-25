-- database/migrations/003_create_password_resets.sql
-- Tabel penyimpan kode OTP dan token reset sandi untuk fitur Lupa Password Onshop

CREATE TABLE IF NOT EXISTS `password_resets` (
  `reset_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `otp_code` varchar(10) NOT NULL,
  `target_type` enum('EMAIL','PHONE') NOT NULL,
  `target_destination` varchar(100) NOT NULL,
  `reset_token` varchar(64) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `is_used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`reset_id`),
  KEY `idx_user_otp` (`user_id`, `otp_code`, `is_used`),
  KEY `idx_reset_token` (`reset_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
