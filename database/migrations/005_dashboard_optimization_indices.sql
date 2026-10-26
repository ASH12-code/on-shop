-- database/migrations/005_dashboard_optimization_indices.sql
-- Penambahan indeks untuk optimasi performa dashboard analitik admin & pembeli

-- 1. Indeks pada sub_orders untuk agregasi status pesanan & rentang tanggal
ALTER TABLE `sub_orders`
  ADD INDEX `idx_so_status_created` (`order_status`, `created_at`),
  ADD INDEX `idx_so_shop_status` (`shop_id`, `order_status`);

-- 2. Indeks pada order_items untuk percepatan query produk terlaris
ALTER TABLE `order_items`
  ADD INDEX `idx_oi_sub_prod` (`sub_order_id`, `product_id`);

-- 3. Indeks pada users untuk percepatan kalkulasi pembeli baru per periode
ALTER TABLE `users`
  ADD INDEX `idx_users_role_created` (`role`, `created_at`);
