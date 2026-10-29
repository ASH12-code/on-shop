# Rencana Detail Minggu 6: Kelengkapan, Uji Sistem Terintegrasi, & Dokumentasi (Final)

Dokumen perencanaan kerja teknis untuk pelaksanaan **Minggu 6** pada proyek pembaruan marketplace Onshop. Minggu 6 merupakan minggu penutup (*final week*) yang melengkapi seluruh kebutuhan sistem yang tersisa (Wishlist pembeli, verifikasi alur Lupa Password, halaman Pengaturan Platform admin, dan Audit Log admin), pengujian sistem terintegrasi lintas peran (*end-to-end*), pengujian audit keamanan (*security audit*), serta penyusunan dokumentasi resmi proyek ([README.md](file:///c:/laragon/www/on-shop/README.md)) agar sistem dapat dijalankan dengan mudah pada lingkungan Laragon.

---

## 🎯 Tujuan Minggu Ini

1. **Kelengkapan Fitur Pembeli:**
   - **Halaman Wishlist (Favorit Produk):**
     - Endpoint `api/buyer/wishlist.php` yang mengelola data dari tabel basis data `wishlists` (ambil daftar wishlist dari session `user_id`, tambah produk ke wishlist, dan hapus produk dari wishlist).
     - Antarmuka pengguna `pages/buyer/wishlist.html`, skrip `assets/js/buyer-js/wishlist.js`, dan penataan gaya `assets/css/buyer-css/wishlist.css` (mempertahankan estetika hijau khas Onshop).
     - Tombol interaktif Wishlist (ikon hati / simpan favorit) pada halaman [detail-produk.html](file:///c:/laragon/www/on-shop/pages/buyer/detail-produk.html) dan kartu katalog [index.html](file:///c:/laragon/www/on-shop/pages/buyer/index.html).
   - **Alur Pemulihan Kata Sandi (Lupa Password):**
     - Validasi alur 4 langkah di [forgot-password.html](file:///c:/laragon/www/on-shop/pages/buyer/forgot-password.html) dan `api/buyer/auth_forgot_password.php` (Input identitas -> Pilih channel SMS/Email -> Masukkan OTP 6 digit -> Set kata sandi baru ber-hash bcrypt).
     - Memastikan kode OTP simulasi ditampilkan di layar / alert pengujian lokal Laragon agar verifikasi berjalan mulus tanpa ketergantungan server email riil.

2. **Kelengkapan Fitur Super Admin:**
   - **Halaman Pengaturan Platform (`platform_settings`):**
     - Endpoint `api/admin/manage_platform_settings.php` dengan otorisasi `require_role('admin')` untuk membaca dan memperbarui konfigurasi platform (persentase komisi, biaya layanan, kontak customer care, status operasional).
     - Antarmuka `pages/admin/pengaturan-platform.html`, `assets/js/admin-js/pengaturan-platform.js`, dan `assets/css/admin-css/pengaturan-platform.css`.
   - **Halaman Audit Log Admin (`admin_audit_logs`):**
     - Endpoint `api/admin/get_audit_logs.php` untuk membaca riwayat aktivitas administratif (putusan sengketa, penangguhan toko, pembaruan pengaturan, penarikan dana) yang tercatat di tabel `admin_audit_logs`.
     - Antarmuka `pages/admin/audit-log.html`, `assets/js/admin-js/audit-log.js`, dan `assets/css/admin-css/audit-log.css`.
   - **Integrasi Menu Sidebar Admin:**
     - Menambahkan menu `⚙️ Pengaturan Platform` dan `📜 Log Aktivitas` pada navigasi sidebar admin ([sidebar-admin.css](file:///c:/laragon/www/on-shop/assets/css/admin-css/sidebar-admin.css) dan template admin).

3. **Uji Sistem Terintegrasi Lintas Peran (*End-to-End Simulation*):**
   - Membuat skrip pengujian otomatis `scratch/test_week6_e2e.php` yang mensimulasikan skenario lengkap siklus transaksi marketplace:
     - **Peran Pembeli:** Pendaftaran akun baru -> Tambah produk ke wishlist -> Tambah ke keranjang -> Checkout dengan ongkir simulasi server -> Unggah bukti transfer pembayaran.
     - **Peran Penjual:** Menerima pesanan -> Memverifikasi bukti bayar -> Memproses pesanan menjadi DIKEMAS -> Mengubah status menjadi DIKIRIM.
     - **Peran Pembeli:** Mengonfirmasi pesanan diterima (SELESAI) -> Memberikan ulasan dan rating produk.
     - **Peran Penjual:** Memeriksa saldo dompet toko (*wallet balance*) -> Mengajukan penarikan dana (*payout request*).
     - **Peran Admin:** Memverifikasi pengajuan payout -> Mentransfer dana -> Memeriksa akumulasi GMV dan komisi 5% platform di Dashboard Global.

4. **Uji Keamanan Komprehensif (*Security Audit Test Suite*):**
   - Membuat skrip audit keamanan `scratch/test_week6_security.php` yang menguji:
     - **Anti-Unauthorized Access:** Memanggil endpoint privat admin, penjual, dan pembeli tanpa sesi login terautentikasi (wajib menolak 401 Unauthorized / 403 Forbidden).
     - **Anti-IDOR (Insecure Direct Object References):** Menguji pergantian parameter `user_id` manual via GET/POST/JSON (wajib diabaikan dan tetap membaca `user_id` dari sesi server).
     - **Anti-Tampering:** Menguji manipulasi harga barang atau tarif ongkir lewat payload klien (backend wajib menghitung ulang subtotal dan ongkir berdasarkan database).
     - **Anti-File Traversal & Malicious Upload:** Menguji unggahan berkas non-gambar atau skrip PHP berbahaya (wajib ditolak validasi MIME & `getimagesize`).
     - **Proteksi Direktori Privat:** Memastikan berkas `.htaccess` di direktori bukti pembayaran memblokir akses publik langsung.

5. **Penyusunan Dokumentasi Resmi Proyek (`README.md`):**
   - Menyusun berkas `README.md` komprehensif di root proyek:
     - Deskripsi proyek Onshop Marketplace (Native PHP, arsitektur modular, MySQL via phpMyAdmin Laragon).
     - Panduan instalasi dan konfigurasi lokal step-by-step di Laragon.
     - Daftar kredensial akun uji untuk seluruh peran (Pembeli, Penjual, dan Super Admin).
     - Penjelasan fitur keamanan (Session auth, password hashing bcrypt, Turnstile captcha, RBAC, dan simulasi ongkir).
     - Struktur direktori proyek dan panduan menjalankan automated test suite.

---

## 📌 Alur Kerja Pengembangan (5 Tahap Standar)

1. **Tahap 1: Perencanaan (Architect Phase)** - Penyusunan dokumen arsitektur teknis `minggu-6.md` (Tahap saat ini).
2. **Tahap 2: Pendaftaran Tugas (GitHub Issue)** - Pendaftaran issue kerja resmi di GitHub repository `ASH12-code/on-shop`.
3. **Tahap 3: Isolasi Kode (Feature Branch & Pull Request)** - Pembuatan branch fitur `feature/minggu-6-final-completion` dan pembukaan PR awal di GitHub.
4. **Tahap 4: Eksekusi & Validasi (Worker Phase)** - Pembuatan fitur Wishlist, integrasi Lupa Password, Pengaturan Platform, Audit Log, Test E2E, Test Security, dan README.md.
5. **Tahap 5: Review & Penggabungan (Commit, PR & Merge)** - Verifikasi hasil pengujian, review perubahan kode, dan penggabungan ke branch `master`.

---

## 📋 Rincian Tugas Teknis Berurutan

### Fase 1: Fitur Pembeli (Wishlist & Lupa Password)
1. **Endpoint `api/buyer/wishlist.php`:**
   - Aksi `GET`: Mengambil daftar produk wishlist milik pengguna berdasarkan `$_SESSION['user_id']` dengan join ke tabel `products` dan `shops`.
   - Aksi `POST / add`: Menambahkan produk ke wishlist (mencegah duplikasi produk yang sama).
   - Aksi `DELETE / remove`: Menghapus produk dari wishlist pengguna.
2. **Halaman `pages/buyer/wishlist.html` & Skrip Pendukung:**
   - Membuat tampilan daftar kartu produk favorit dengan tombol "Beli Sekarang", "Hapus dari Wishlist", dan state kosong (*empty state*).
   - Menghubungkan tombol ikon hati di `pages/buyer/detail-produk.html` agar langsung menambahkan produk ke wishlist.
3. **Penyempurnaan Alur Lupa Password:**
   - Memastikan interaksi antara [forgot-password.html](file:///c:/laragon/www/on-shop/pages/buyer/forgot-password.html) dan `api/buyer/auth_forgot_password.php` berjalan mulus dengan menampilkan modal/toast kode OTP demo pada mode lokal.

---

### Fase 2: Fitur Super Admin (Pengaturan Platform & Audit Log)
4. **Endpoint `api/admin/manage_platform_settings.php`:**
   - `require_role('admin')`.
   - Mengambil seluruh daftar parameter konfigurasi dari tabel `platform_settings`.
   - Menyimpan pembaruan nilai setting dan mencatat aksi ke tabel `admin_audit_logs`.
5. **Halaman `pages/admin/pengaturan-platform.html`:**
   - Formulir terstruktur untuk mengatur komisi platform, biaya penanganan, email dukungan, dan status marketplace.
6. **Endpoint `api/admin/get_audit_logs.php`:**
   - `require_role('admin')`.
   - Mengambil log aktivitas berurutan dari yang terbaru (`created_at DESC`) dengan filter jenis aksi dan pencarian admin.
7. **Halaman `pages/admin/audit-log.html`:**
   - Tabel riwayat aktivitas admin dengan badge tipe aksi (`UPDATE_PLATFORM_SETTING`, `RESOLVE_DISPUTE`, `SUSPEND_SHOP`, dll.), cap waktu, dan alamat IP.
8. **Navigasi Sidebar Admin:**
   - Menambahkan menu `Pengaturan Platform` dan `Log Aktivitas` di komponen sidebar admin.

---

### Fase 3: Pengujian Sistem Terintegrasi & Audit Keamanan
9. **Test Suite End-to-End (`scratch/test_week6_e2e.php`):**
   - Menjalankan skenario otomatis transaksi dari pendaftaran hingga verifikasi payout admin (100% PASS).
10. **Test Suite Audit Keamanan (`scratch/test_week6_security.php`):**
    - Menguji skenario 401 Unauthorized, anti-IDOR session integrity, anti-tampering ongkir/harga, upload protection, dan otentikasi bcrypt (100% PASS).
11. **Uji Regresi Minggu 1 s.d. 5:**
    - Memastikan test suite minggu 1, 2, 3, 4, dan 5 tetap 100% PASS tanpa regresi.

---

### Fase 4: Dokumentasi Resmi Proyek
12. **Penyusunan `README.md`:**
    - Menyusun dokumentasi lengkap, rapi, dan profesional mengenai Onshop Marketplace untuk pengguna, pengembang, dan dosen penguji.

---

## 📁 Berkas yang Dibuat dan Diubah

### Berkas Baru:
1. `minggu-6.md` (Dokumen rencana arsitektur teknis Minggu 6).
2. `api/buyer/wishlist.php` (Backend API pengelolaan wishlist pembeli).
3. `pages/buyer/wishlist.html` (Halaman antarmuka daftar wishlist pembeli).
4. `assets/js/buyer-js/wishlist.js` (Logika frontend wishlist).
5. `assets/css/buyer-css/wishlist.css` (Gaya responsif halaman wishlist).
6. `api/admin/manage_platform_settings.php` (Backend API konfigurasi platform).
7. `pages/admin/pengaturan-platform.html` (Halaman antarmuka pengaturan platform).
8. `assets/js/admin-js/pengaturan-platform.js` (Logika frontend pengaturan platform).
9. `assets/css/admin-css/pengaturan-platform.css` (Gaya antarmuka pengaturan platform).
10. `api/admin/get_audit_logs.php` (Backend API pembaca log audit admin).
11. `pages/admin/audit-log.html` (Halaman antarmuka log audit admin).
12. `assets/js/admin-js/audit-log.js` (Logika frontend log audit admin).
13. `assets/css/admin-css/audit-log.css` (Gaya antarmuka log audit admin).
14. `README.md` (Dokumentasi resmi proyek).
15. `scratch/test_week6_e2e.php` (Suite pengujian transaksi terintegrasi lintas peran).
16. `scratch/test_week6_security.php` (Suite audit keamanan sistem).

### Berkas yang Diubah:
1. `pages/buyer/detail-produk.html` & `assets/js/buyer-js/detail-produk.js` (Integrasi tombol tambah ke wishlist).
2. `pages/admin/*.html` & `assets/js/admin-js/sidebar-admin.js` (Penambahan menu pengaturan platform & audit log di sidebar).

---

## 🧪 Kriteria Selesai (Definition of Done)

- [ ] Pembeli dapat menambah, melihat, dan menghapus produk di halaman Wishlist.
- [ ] Alur pemulihan kata sandi (Lupa Password) berhasil dijalankan secara mandiri dengan kode OTP simulasi lokal.
- [ ] Super Admin dapat melihat dan memperbarui parameter pengaturan platform di `pengaturan-platform.html`.
- [ ] Super Admin dapat memantau riwayat aktivitas administratif di `audit-log.html`.
- [ ] Skrip pengujian terintegrasi lintas peran (`test_week6_e2e.php`) lulus 100% (`PASS`).
- [ ] Skrip audit keamanan sistem (`test_week6_security.php`) lulus 100% (`PASS`).
- [ ] Regresi fitur Minggu 1 s.d. 5 tetap lulus 100% (`PASS`).
- [ ] Berkas dokumentasi `README.md` tersaji lengkap, rapi, dan siap digunakan.
- [ ] Pull Request Minggu 6 dibuka di Tahap 3, divalidasi di Tahap 4, dan digabungkan ke `master` di Tahap 5 setelah mendapat ACC dari pengguna.
