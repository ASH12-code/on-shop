-- database/migrations/002_create_shipping_rates.sql
-- Tabel tarif pengiriman internal untuk simulasi ongkos kirim Onshop

CREATE TABLE IF NOT EXISTS `shipping_rates` (
  `rate_id` int NOT NULL AUTO_INCREMENT,
  `courier_code` varchar(20) NOT NULL,
  `courier_name` varchar(50) NOT NULL,
  `service_code` varchar(50) NOT NULL,
  `service_name` varchar(100) NOT NULL,
  `origin_province` varchar(100) NOT NULL,
  `destination_province` varchar(100) NOT NULL,
  `rate_per_kg` decimal(12,2) NOT NULL,
  `etd_days` varchar(50) NOT NULL DEFAULT '2-3 Hari',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`rate_id`),
  UNIQUE KEY `unique_route` (`courier_code`, `service_code`, `origin_province`, `destination_province`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Data Seed Rute Simulasi Populer (Termasuk Bangka Belitung, Jabodetabek, Jawa, Sumatera, Bali)
INSERT INTO `shipping_rates` (`courier_code`, `courier_name`, `service_code`, `service_name`, `origin_province`, `destination_province`, `rate_per_kg`, `etd_days`, `is_active`) VALUES
-- 1. Intra Bangka Belitung (Lokal)
('JNE', 'JNE Express', 'REG', 'Reguler (2-3 Hari)', 'Kepulauan Bangka Belitung', 'Kepulauan Bangka Belitung', 12000.00, '1-2 Hari', 1),
('JNT', 'J&T Express', 'EZ', 'EZ Express (1-2 Hari)', 'Kepulauan Bangka Belitung', 'Kepulauan Bangka Belitung', 14000.00, '1 Hari', 1),
('SICEPAT', 'SiCepat Ekspres', 'SIUNT', 'SiUntung Hemat (3-5 Hari)', 'Kepulauan Bangka Belitung', 'Kepulauan Bangka Belitung', 9000.00, '2-3 Hari', 1),

-- 2. Bangka Belitung -> DKI Jakarta & Sekitarnya
('JNE', 'JNE Express', 'REG', 'Reguler (2-3 Hari)', 'Kepulauan Bangka Belitung', 'DKI Jakarta', 18000.00, '2-3 Hari', 1),
('JNT', 'J&T Express', 'EZ', 'EZ Express (1-2 Hari)', 'Kepulauan Bangka Belitung', 'DKI Jakarta', 22000.00, '1-2 Hari', 1),
('SICEPAT', 'SiCepat Ekspres', 'SIUNT', 'SiUntung Hemat (3-5 Hari)', 'Kepulauan Bangka Belitung', 'DKI Jakarta', 14000.00, '3-4 Hari', 1),

-- 3. Bangka Belitung -> Jawa Barat
('JNE', 'JNE Express', 'REG', 'Reguler (2-3 Hari)', 'Kepulauan Bangka Belitung', 'Jawa Barat', 20000.00, '2-3 Hari', 1),
('JNT', 'J&T Express', 'EZ', 'EZ Express (1-2 Hari)', 'Kepulauan Bangka Belitung', 'Jawa Barat', 24000.00, '2 Hari', 1),
('SICEPAT', 'SiCepat Ekspres', 'SIUNT', 'SiUntung Hemat (3-5 Hari)', 'Kepulauan Bangka Belitung', 'Jawa Barat', 16000.00, '3-5 Hari', 1),

-- 4. Bangka Belitung -> Jawa Timur
('JNE', 'JNE Express', 'REG', 'Reguler (2-3 Hari)', 'Kepulauan Bangka Belitung', 'Jawa Timur', 24000.00, '3-4 Hari', 1),
('JNT', 'J&T Express', 'EZ', 'EZ Express (1-2 Hari)', 'Kepulauan Bangka Belitung', 'Jawa Timur', 28000.00, '2-3 Hari', 1),
('SICEPAT', 'SiCepat Ekspres', 'SIUNT', 'SiUntung Hemat (3-5 Hari)', 'Kepulauan Bangka Belitung', 'Jawa Timur', 19000.00, '4-5 Hari', 1),

-- 5. Bangka Belitung -> Sumatera Selatan
('JNE', 'JNE Express', 'REG', 'Reguler (2-3 Hari)', 'Kepulauan Bangka Belitung', 'Sumatera Selatan', 15000.00, '2 Hari', 1),
('JNT', 'J&T Express', 'EZ', 'EZ Express (1-2 Hari)', 'Kepulauan Bangka Belitung', 'Sumatera Selatan', 18000.00, '1-2 Hari', 1),
('SICEPAT', 'SiCepat Ekspres', 'SIUNT', 'SiUntung Hemat (3-5 Hari)', 'Kepulauan Bangka Belitung', 'Sumatera Selatan', 12000.00, '3 Hari', 1),

-- 6. Bangka Belitung -> Bali
('JNE', 'JNE Express', 'REG', 'Reguler (2-3 Hari)', 'Kepulauan Bangka Belitung', 'Bali', 26000.00, '3-4 Hari', 1),
('JNT', 'J&T Express', 'EZ', 'EZ Express (1-2 Hari)', 'Kepulauan Bangka Belitung', 'Bali', 30000.00, '2-3 Hari', 1),
('SICEPAT', 'SiCepat Ekspres', 'SIUNT', 'SiUntung Hemat (3-5 Hari)', 'Kepulauan Bangka Belitung', 'Bali', 21000.00, '4-5 Hari', 1),

-- 7. DKI Jakarta -> Bangka Belitung
('JNE', 'JNE Express', 'REG', 'Reguler (2-3 Hari)', 'DKI Jakarta', 'Kepulauan Bangka Belitung', 18000.00, '2-3 Hari', 1),
('JNT', 'J&T Express', 'EZ', 'EZ Express (1-2 Hari)', 'DKI Jakarta', 'Kepulauan Bangka Belitung', 22000.00, '1-2 Hari', 1),
('SICEPAT', 'SiCepat Ekspres', 'SIUNT', 'SiUntung Hemat (3-5 Hari)', 'DKI Jakarta', 'Kepulauan Bangka Belitung', 14000.00, '3-4 Hari', 1),

-- 8. Fallback / Default Antar-Provinsi (Jika rute spesifik belum terdaftar)
('JNE', 'JNE Express', 'REG', 'Reguler (2-3 Hari)', 'DEFAULT', 'DEFAULT', 18000.00, '2-4 Hari', 1),
('JNT', 'J&T Express', 'EZ', 'EZ Express (1-2 Hari)', 'DEFAULT', 'DEFAULT', 22000.00, '2-3 Hari', 1),
('SICEPAT', 'SiCepat Ekspres', 'SIUNT', 'SiUntung Hemat (3-5 Hari)', 'DEFAULT', 'DEFAULT', 15000.00, '3-5 Hari', 1)
ON DUPLICATE KEY UPDATE `rate_per_kg` = VALUES(`rate_per_kg`), `etd_days` = VALUES(`etd_days`), `is_active` = VALUES(`is_active`);
