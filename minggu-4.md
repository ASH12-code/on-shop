# Rencana Detail Minggu 4: Dashboard Admin & Dashboard Pembeli

Dokumen perencanaan kerja teknis untuk pelaksanaan **Minggu 4** pada proyek pembaruan marketplace Onshop. Seluruh pengerjaan pada tahap ini berfokus pada penyempurnaan analitik dan agregasi performa marketplace di sisi Super Admin, penyediaan dashboard ringkasan aktivitas belanja di sisi Pembeli (`akun.html`), konsolidasi halaman riwayat transaksi, serta optimasi indeks database untuk mendukung kueri agregasi performa tinggi.

---

## 🎯 Tujuan Minggu Ini

1. **Penyempurnaan Analitik Dashboard Admin (`pages/admin/index.html` & `api/admin/get_dashboard_analytics.php`):**
   - **Metrik Eksisting Dipertahankan:** GMV (Gross Merchandise Value), Komisi Platform 5%, Jumlah Order Selesai, Toko Aktif Bertransaksi, Nilai Rata-rata Pesanan (AOV), Grafik Tren Mingguan, dan Tabel Transaksi Terkini.
   - **Metrik Baru yang Ditambahkan:**
     - **Jumlah Sales (Unit Produk Terjual):** Akumulasi kuantitas produk yang sukses terjual (`SUM(quantity)` dari `order_items` berstatus `SELESAI`).
     - **Jumlah Pembeli:** Total pembeli yang pernah bertransaksi dan metrik **Pembeli Baru** yang mendaftar pada rentang filter periode terpilih.
     - **Top 5 Produk Terlaris:** Widget ranking 5 produk dengan penjualan unit dan omzet tertinggi, dilengkapi foto produk dan varian.
     - **Top 5 Seller Berprestasi:** Widget ranking 5 toko dengan volume pesanan dan total nilai GMV terbesar.
   - **Filter Periode Dinamis:** Mendukung filter bulanan (`M_YYYY`), filter tahunan, dan seluruh riwayat (*All-Time*), di mana seluruh metrik dan widget ranking terhitung akurat sesuai periode aktif.

2. **Penyediaan Dashboard Pengguna Pembeli (`pages/buyer/akun.html` & `assets/js/buyer-js/akun.js`):**
   - Menambahkan kartu ringkasan belanja di bagian atas halaman akun:
     - **Total Pesanan:** Akumulasi seluruh pesanan yang pernah dibuat pembeli.
     - **Pesanan Berjalan:** Jumlah pesanan aktif yang sedang diproses (`MENUNGGU_BAYAR`, `MENUNGGU_VERIFIKASI`, `DIKEMAS`, `DIKIRIM`).
     - **Total Belanja:** Akumulasi nominal uang (Rp) yang telah dibelanjakan pada pesanan berstatus `SELESAI`.
   - **Daftar Pesanan Terkini:** Menampilkan 3-5 transaksi belanja terbaru langsung di halaman akun dengan badge status interaktif dan tombol cepat menuju detail pesanan di `pesanan-saya.html`.

3. **Konsolidasi Halaman Riwayat Transaksi:**
   - Menghapus ketergantungan pada halaman duplikat `riwayat-pesanan.html`.
   - Mengalihkan (*redirect*) pengguna yang mengakses `riwayat-pesanan.html` secara otomatis ke `pesanan-saya.html?tab=history` guna menjaga konsistensi alur transaksi.

4. **Optimasi Indeks Kueri Basis Data (`database/migrations/005_dashboard_optimization_indices.sql`):**
   - Menambahkan indeks komposit pada `sub_orders(order_status, created_at)` dan `sub_orders(shop_id, order_status)`.
   - Menambahkan indeks pada `order_items(sub_order_id, product_id)`.
   - Menambahkan indeks pada `users(role, created_at)`.
   - Memastikan kueri analitik dan dashboard berjalan cepat tanpa hambatan *full table scan*.

5. **Konsistensi UI/UX & Standar Keamanan:**
   - Menjaga estetika desain CSS yang ada tanpa merusak layout desktop maupun mobile.
   - Seluruh data pembeli di dashboard diambil berdasarkan sesi server (`require_auth()`).
   - Endpoint admin tetap terkunci di bawah otorisasi ketat `require_role('admin')`.

---

## 📌 Alur Kerja Pengembangan (5 Tahap Standar)

1. **Tahap 1: Perencanaan (Architect Phase)** - Penyusunan dokumen arsitektur teknis `minggu-4.md`.
2. **Tahap 2: Pendaftaran Tugas (GitHub Issue)** - Pendaftaran issue kerja resmi di GitHub repository `ASH12-code/on-shop`.
3. **Tahap 3: Isolasi Kode (Feature Branch & Pull Request)** - Pembuatan branch fitur `feature/minggu-4-dashboard-admin-buyer` dan pembukaan PR awal di GitHub.
4. **Tahap 4: Eksekusi & Validasi (Worker Phase)** - Pengerjaan migrasi 005, perombakan analitik admin, perakitan dashboard pembeli, dan automated test suite.
5. **Tahap 5: Review & Penggabungan (Commit, PR & Merge)** - Review kode akhir, presentasi diff, dan penggabungan ke branch `master`.

---

## 📋 Rincian Tugas Teknis Berurutan

### Fase 1: Migrasi Indeks Database 005 (`database/migrations/`)
1. Buat berkas migrasi `database/migrations/005_dashboard_optimization_indices.sql`:
   - Indeks komposit `idx_so_status_created` pada `sub_orders(order_status, created_at)`.
   - Indeks komposit `idx_so_shop_status` pada `sub_orders(shop_id, order_status)`.
   - Indeks komposit `idx_users_role_created` pada `users(role, created_at)`.
2. Eksekusi migrasi ke basis data `db_on_shop` via MySQL Laragon.

---

### Fase 2: Backend Analitik Dashboard Admin (`api/admin/`)
3. Perbarui skrip `api/admin/get_dashboard_analytics.php`:
   - Penanganan parameter `period_type` dan `filter_key` (parsing rentang tanggal `startDate` dan `endDate`).
   - Kueri KPI utama dengan filter tanggal:
     - `total_gmv`, `platform_commission`, `total_orders`, `avg_order_value`.
     - `total_sales_units`: `SUM(oi.quantity)` dari `order_items`.
     - `total_buyers`: `COUNT(DISTINCT mo.buyer_id)`.
     - `new_buyers_period`: `COUNT(user_id)` dari `users WHERE role = 'buyer' AND created_at BETWEEN ...`.
     - `active_merchants` dan `total_merchants`.
   - Kueri **Top 5 Produk Terlaris**:
     - Menghitung ranking produk berdasarkan `SUM(oi.quantity) DESC`, menyertakan nama produk, foto, total terjual, dan omzet kotor.
   - Kueri **Top 5 Merchant**:
     - Menghitung ranking toko berdasarkan omzet `SUM(so.gross_product_amount) DESC`, menyertakan nama toko, kota asal, jumlah transaksi, dan total pendapatan.
   - Kueri Transaksi Terkini & Data Titik Grafik Mingguan disesuaikan dengan periode terpilih.

---

### Fase 3: Antarmuka Dashboard Admin (`pages/admin/` & `assets/js/admin-js/`)
4. Perbarui `pages/admin/index.html`:
   - Tambahkan 2 kartu KPI baru pada grid kartu metrik:
     - **Total Produk Terjual (Sales Units)**
     - **Pertumbuhan Pembeli (Total & Baru)**
   - Tambahkan kontainer grid dua kolom di bawah grafik analitik:
     - Widget **Top 5 Produk Terlaris** (tabel/list berisi ranking, thumbnail, nama produk, dan total unit terjual).
     - Widget **Top 5 Toko Terbaik** (ranking toko, badge toko, total transaksi selesai, dan omzet toko).
5. Perbarui `assets/js/admin-js/index-admin.js`:
   - Sinkronisasi render data KPI baru.
   - Render widget Top 5 Produk Terlaris dan Top 5 Toko Terbaik.
   - Hubungkan dropdown filter periode dengan pembaruan dinamis seluruh widget.
6. Perbarui `assets/css/admin-css/index-admin.css`:
   - Styling kartu KPI 6 kolom responsif dan kartu widget ranking Top Products / Top Sellers dengan tata letak rapi.

---

### Fase 4: Dashboard Ringkasan Pembeli (`api/buyer/` & `pages/buyer/`)
7. Tambahkan aksi `dashboard_summary` pada `api/buyer/orders.php`:
   - Menghitung:
     - `total_orders`: Total pesanan pembeli.
     - `active_orders`: Pesanan yang sedang berlangsung (`MENUNGGU_BAYAR`, `MENUNGGU_VERIFIKASI`, `DIKEMAS`, `DIKIRIM`).
     - `total_spent`: Total pengeluaran lunas (`SELESAI`).
     - `recent_orders`: 3 pesanan terbaru lengkap dengan ringkasan barang dan status.
8. Perbarui `pages/buyer/akun.html`:
   - Tambahkan blok **Dashboard Belanja Saya** di atas formulir profil:
     - 3 kartu ringkasan belanja (Total Belanja, Pesanan Berjalan, Riwayat Transaksi).
     - Bagian **Pesanan Terbaru** dengan tombol langsung ke pelacakan status.
9. Perbarui `assets/js/buyer-js/akun.js`:
   - Memanggil endpoint ringkasan belanja dan merender kartu metrik serta daftar pesanan terkini secara asinkron.
10. Perbarui `assets/css/buyer-css/akun.css`:
    - Styling kartu ringkasan metrik pembeli agar selaras dengan estetika tema hijau ON Shop.
11. Konsolidasi `pages/buyer/riwayat-pesanan.html`:
    - Mengubah file agar otomatis melakukan redirect JavaScript & Meta Refresh ke `pesanan-saya.html?tab=history`.

---

### Fase 5: Pengujian & Validasi
12. Validasi syntax linting `php -l` pada semua berkas API.
13. Buat dan jalankan automated test suite `scratch/test_week4.php`:
    - Uji indeks migrasi 005.
    - Uji kalkulasi analitik admin (GMV, komisi, total units, pembeli baru, top products, top sellers) dan perbandingannya dengan data riil database.
    - Uji filter periode bulanan dan all-time.
    - Uji endpoint dashboard ringkasan pembeli (`orders.php?action=dashboard_summary`).
    - Uji pengalihan halaman `riwayat-pesanan.html`.
    - Uji regresi fitur Minggu 1, Minggu 2, dan Minggu 3.

---

## 📁 Berkas yang Dibuat, Diubah, dan Dihapus

### Berkas Baru:
1. `database/migrations/005_dashboard_optimization_indices.sql` (Migrasi penambahan indeks komposit analitik).
2. `minggu-4.md` (Dokumen perencanaan teknis Minggu 4).

### Berkas yang Diubah:
1. `api/admin/get_dashboard_analytics.php` (Penambahan KPI sales, pembeli, top products, top sellers, filter periode).
2. `pages/admin/index.html` (Penambahan widget kartu sales, pembeli, top 5 produk, top 5 seller).
3. `assets/js/admin-js/index-admin.js` (Logika render metrik baru dan ranking top sellers/products).
4. `assets/css/admin-css/index-admin.css` (Gaya visual widget ranking dan grid metrik admin).
5. `api/buyer/orders.php` (Aksi baru `dashboard_summary` untuk ringkasan belanja pengguna).
6. `pages/buyer/akun.html` (Komponen kartu metrik pesanan dan transaksi belanja terkini pembeli).
7. `assets/js/buyer-js/akun.js` (Logika pemanggilan dan render ringkasan akun pembeli).
8. `assets/css/buyer-css/akun.css` (Styling kartu ringkasan pembeli).
9. `pages/buyer/riwayat-pesanan.html` (Pengalihan permanen ke `pesanan-saya.html`).

---

## 🧪 Kriteria Selesai (Definition of Done)

- [x] Migrasi `005_dashboard_optimization_indices.sql` sukses terpasang di database `db_on_shop`.
- [x] Dashboard Admin menampilkan metrik sales unit, total pembeli, pembeli baru, Top 5 Produk Terlaris, dan Top 5 Merchant.
- [x] Filter periode pada Dashboard Admin menghitung seluruh metrik dan ranking secara akurat sesuai periode yang dipilih.
- [x] Halaman Akun Pembeli (`akun.html`) menampilkan ringkasan belanja (Total Pesanan, Pesanan Berjalan, Total Belanja) dan daftar pesanan terbaru.
- [x] Halaman `riwayat-pesanan.html` mengalihkan pengguna ke `pesanan-saya.html` secara mulus.
- [x] Seluruh skrip PHP bebas dari kesalahan sintaks (`php -l`).
- [x] Test suite Minggu 4 lulus 100% tanpa kegagalan (`PASS`).
- [ ] PR dibuat di Tahap 3, direview di Tahap 5, dan di-merge ke branch `master`.
