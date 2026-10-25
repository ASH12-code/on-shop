-- database/migrations/001_ensure_password_hash_length.sql
-- Memastikan kolom password pada tabel users minimal VARCHAR(255) untuk menampung hash bcrypt (60 karakter)

ALTER TABLE `users` MODIFY COLUMN `password` VARCHAR(255) NOT NULL;
