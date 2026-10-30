# 🛍️ On Shop — Platform Marketplace C2C / B2C Berbasis PHP Native

[![PHP Native](https://img.shields.io/badge/PHP-8.2%2B%20Native-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Database](https://img.shields.io/badge/Database-MySQL%20%2F%20phpMyAdmin-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com/)
[![Environment](https://img.shields.io/badge/Environment-Laragon%20WAMP-0E71EB?logo=laragon&logoColor=white)](https://laragon.org/)
[![Style](https://img.shields.io/badge/UI%2FUX-Vanilla%20CSS%20Responsive-2E8B57)](pages/buyer/index.html)
[![Security](https://img.shields.io/badge/Security-RBAC%20%7C%20Bcrypt%20%7C%20Anti--IDOR-success)](#-fitur-keamanan--integritas-data)
[![Test Suites](https://img.shields.io/badge/Tests-100%25%20Passing-brightgreen)](#-skrip-pengujian-otomatis-automated-test-suites)

**On Shop** adalah platform marketplace modern yang dibangun dengan arsitektur **PHP Murni (Native)** tanpa framework, memadukan fleksibilitas tinggi, keamanan data mutakhir, serta antarmuka pengguna yang bersih, responsif, dan ramah seluler (*mobile-friendly*). 

Proyek ini mencakup ekosistem perdagangan elektronik lengkap yang menghubungkan tiga peran utama: **Pembeli (Buyer)**, **Penjual (Seller)**, dan **Super Admin**.

---

## 📑 Daftar Isi
- [Arsitektur & Tumpukan Teknologi](#-arsitektur--tumpukan-teknologi)
- [Fitur Utama Berdasarkan Peran](#-fitur-utama-berdasarkan-peran)
  - [1. Pembeli (Buyer)](#1-pembeli-buyer)
  - [2. Penjual (Seller)](#2-penjual-seller)
  - [3. Super Administrator](#3-super-administrator)
- [Fitur Keamanan & Integritas Data](#-fitur-keamanan--integritas-data)
- [Struktur Direktori Proyek](#-struktur-direktori-proyek)
- [Panduan Instalasi & Penggunaan](#-panduan-instalasi--penggunaan)
- [Kredensial Akun Pengujian](#-kredensial-akun-pengujian)
- [Skrip Pengujian Otomatis (Automated Test Suites)](#-skrip-pengujian-otomatis-automated-test-suites)

---

## 🏛️ Arsitektur & Tumpukan Teknologi

- **Backend**: PHP 8.2+ Native (PDO Driver, Prepared Statements, Arsitektur REST-like JSON API).
- **Database**: MySQL / MariaDB via phpMyAdmin di lingkungan Laragon WAMP.
- **Frontend**: Semantic HTML5, Vanilla JavaScript (ES6+ Asynchronous Fetch API), dan Vanilla CSS dengan palet warna elegan *SeaGreen* (`#2e8b57`).
- **Optimalisasi Performa**: Kompresi WebP untuk banner aset berukuran besar (menghemat >87% ukuran berkas) dan *Native Lazy Loading* (`loading="lazy"`) pada kartu katalog produk.
- **Lingkungan Pengujian**: PHP CLI Test Runner dengan assertion engine terintegrasi untuk E2E dan Pentest keamanan.

---

## 🌟 Fitur Utama Berdasarkan Peran

### 1. Pembeli (Buyer)
- **Katalog & Navigasi**:
  - Penjelajahan produk berdasarkan kategori (*Pakaian Pria, Elektronik, dll.*).
  - Pencarian interaktif dengan filter harga, lokasi toko, dan pengurutan (terlaris, termurah, termahal, terbaru).
  - Navigasi breadcrumb dan tombol kembali kontekstual pada alur pemesanan.
- **Wishlist Produk**:
  - Menyimpan produk favorit ke daftar keinginan pribadi.
  - Tambah/hapus cepat dari halaman detail produk maupun halaman khusus Wishlist.
- **Keranjang Belanja & Checkout Multi-Toko**:
  - Mengelompokkan item belanja secara dinamis per toko penjual (*sub-orders*).
  - Penerapan voucher diskon belanja dan potongan ongkos kirim.
  - Perhitungan ongkos kirim server-side otomatis berdasarkan berat barang dan jarak zona pengiriman.
- **Opsi Pembayaran Lengkap**:
  - **COD (Cash on Delivery)**: Pesanan langsung masuk tahap pengemasan oleh penjual.
  - **QRIS Dinamis & Statis**: Tampilan kode QRIS resmi dengan panduan langkah pembayaran.
  - **Virtual Account Bank (BCA, Mandiri, BRI)**: Nomor VA unik per transaksi dengan tenggat kedaluwarsa.
  - **Unggah Bukti Transfer**: Pembeli dapat mengunggah struk/tangkapan layar bukti bayar dengan validasi format MIME (*jpg, jpeg, png, webp*) dan ukuran maksimal.
- **Pelacakan & Ulasan Pesanan**:
  - Pelacakan status bertahap: *Menunggu Bayar ➔ Menunggu Verifikasi ➔ Dikemas ➔ Dikirim (Nomor Resi) ➔ Selesai / Dibatalkan*.
  - Konfirmasi barang sampai dan pemberian rating bintang (1-5) beserta testimoni ulasan.
- **Manajemen Akun & Pemulihan Kata Sandi**:
  - Pembaruan profil, foto avatar, nomor WhatsApp, dan multi-alamat pengiriman (pilihan alamat utama).
  - Fitur **Lupa Password** dengan validasi kode OTP demo lokal untuk reset kata sandi secara mandiri.

### 2. Penjual (Seller)
- **Dashboard Toko & Metrik**:
  - Ringkasan total pendapatan, pesanan baru, produk aktif, serta saldo siap ditarik.
- **Katalog Produk & Variasi**:
  - Tambah produk dengan variasi kombinasi warna dan ukuran (*SKU*, harga individual, dan stok real-time).
  - Galeri multi-foto produk dengan tanda gambar utama (*is_primary*).
- **Proses Pesanan & Verifikasi Bukti Bayar**:
  - Memeriksa keabsahan foto bukti transfer pembeli dengan penampil berkas aman (*safe file streamer*).
  - Aksi menyetujui (*Approve*) untuk memasukkan pesanan ke antrean kemas, atau menolak (*Reject*) dengan alasan penjelasan.
  - Input kurir ekspedisi dan nomor resi pengiriman aktual.
- **Dompet Toko (Wallet) & Payout**:
  - Pemisahan dana aman: *Pending Balance* (saat barang dalam perjalanan) dan *Available Balance* (setelah pembeli konfirmasi selesai).
  - Pengajuan pencairan saldo (*Withdrawal*) ke rekening bank pilihan.

### 3. Super Administrator
- **Executive Analytics Dashboard**:
  - Gross Merchandise Value (GMV) platform dan total komisi bersih (5%).
  - Total unit produk terjual dan pertumbuhan akun pengguna terdaftar.
  - Tabel peringkat Top 5 Produk Terlaris dan Top 5 Toko Berkinerja Terbaik.
  - Fasilitas ekspor laporan penjualan ke format berkas Spreadsheet CSV.
- **Manajemen Toko & Pengguna**:
  - Verifikasi pendaftaran toko baru, suspensi pedagang yang melanggar ketentuan, dan aktivasi kembali.
- **Pusat Resolusi Sengketa (Dispute Resolution)**:
  - Penengah sengketa antara pembeli dan penjual dengan opsi keputusan adil: *Refund* dana ke pembeli atau pelepasan dana ke penjual.
- **Pencairan Dana (Payout Approval)**:
  - Verifikasi dan persetujuan permohonan transfer saldo toko ke rekening bank mitra.
- **Pengaturan Platform Global (Platform Settings)**:
  - Konfigurasi parameter persentase komisi pedagang, biaya layanan pesanan, kontak dukungan, dan status pemeliharaan.
- **Log Audit Aktivitas (Audit Trail)**:
  - Perekaman kronologis seluruh tindakan administratif lengkap dengan tanda waktu (*timestamp*), ID admin pelaksana, dan alamat IP.

---

## 🛡️ Fitur Keamanan & Integritas Data

1. **Role-Based Access Control (RBAC)**:
   - Verifikasi sesi berbasis peran pada seluruh endpoint API (`require_auth()` dan `require_role()`).
   - Penolakan akses tamu dengan kode status `401 Unauthorized` dan pencegahan eskalasi hak akses dengan kode status `403 Forbidden`.
2. **Kriptografi Kata Sandi (Bcrypt)**:
   - Seluruh password dienkripsi dengan algoritma standar industri `PASSWORD_BCRYPT` (garam otomatis ber-prefix `$2y$`).
3. **Pencegahan Manipulasi Parameter (Anti-IDOR)**:
   - Identitas pengguna (`user_id`, `shop_id`) diambil secara mutlak dari sesi terenkripsi server (`$_SESSION`), bukan dari parameter input klien (*GET/POST*).
4. **Validasi Harga Sisi Server (Anti-Price Tampering)**:
   - Kalkulasi subtotal, diskon kupon, dan tarif ongkir dihitung ulang secara independen di backend dengan membaca langsung dari tabel basis data saat proses checkout berlangsung.
5. **Proteksi Berkas Unggahan & Direktori Aman**:
   - Berkas bukti pembayaran disimpan di direktori privat `uploads/payment_proofs/` yang dilindungi konfigurasi `.htaccess` untuk mematikan eksekusi skrip PHP jahat.
   - Pengecekan biner ganda melalui ekstensi, `finfo(FILEINFO_MIME_TYPE)`, dan `getimagesize()`.
6. **Resistensi Terhadap Serangan Injeksi SQL**:
   - Penggunaan 100% *Prepared Statements* berparameter pada seluruh kueri manipulasi data (*PDO::prepare*).

---

## 📂 Struktur Direktori Proyek

```text
on-shop/
├── api/
│   ├── admin/             # API backend untuk operasional Super Admin
│   │   ├── get_analytics.php
│   │   ├── get_audit_logs.php
│   │   ├── manage_platform_settings.php
│   │   ├── process_payout.php
│   │   └── ...
│   ├── buyer/             # API backend untuk aktivitas Pembeli
│   │   ├── auth_login.php
│   │   ├── auth_register.php
│   │   ├── auth_forgot_password.php
│   │   ├── checkout.php
│   │   ├── wishlist.php
│   │   ├── upload_payment_proof.php
│   │   └── ...
│   ├── seller/            # API backend untuk operasional Penjual
│   │   ├── get_dashboard_data.php
│   │   ├── manage_orders.php
│   │   ├── manage_wallet.php
│   │   └── ...
│   └── helpers/           # Modul utilitas bersama (auth, database, upload, shipping)
├── assets/
│   ├── css/               # Berkas gaya responsif (admin-css, buyer-css, seller-css)
│   ├── js/                # Berkas skrip interaktif frontend
│   └── Images/            # Aset visual (banner.webp, logo, QRIS statis)
├── config/
│   └── database.php       # Konfigurasi koneksi PDO MySQL Laragon
├── pages/
│   ├── admin/             # Halaman antarmuka Super Admin
│   ├── buyer/             # Halaman antarmuka Pembeli
│   └── seller/            # Halaman antarmuka Penjual
├── uploads/
│   ├── avatars/           # Folder foto profil pengguna
│   ├── payment_proofs/    # Folder bukti transfer terproteksi (.htaccess)
│   └── products/          # Folder galeri produk pedagang
├── db_on_shop.sql         # Skrip dump struktur basis data & seed data awal
└── README.md              # Dokumentasi teknis resmi proyek
```

---

## 🚀 Panduan Instalasi & Penggunaan

### Prasyarat Sistem
- Windows OS dengan **Laragon** (PHP 8.2 atau 8.3, Apache 2.4, MySQL 8.0/MariaDB).
- Git for Windows.

### Langkah-Langkah Pemasangan:
1. **Clone Repositori ke Direktori Web Laragon**:
   ```bash
   cd C:\laragon\www
   git clone https://github.com/ASH12-code/on-shop.git
   cd on-shop
   ```
2. **Jalankan Layanan Laragon**:
   - Buka aplikasi Laragon, lalu klik tombol **Start All** (Apache & MySQL).
3. **Impor Basis Data MySQL**:
   - Buka peramban dan akses phpMyAdmin di `http://localhost/phpmyadmin`.
   - Buat basis data baru dengan nama `onshop_db`.
   - Pilih tab **Import**, lalu unggah berkas `db_on_shop.sql` dari root proyek dan klik tombol **Go / Kirim**.
4. **Verifikasi Konfigurasi Database**:
   - Berkas `config/database.php` telah dikonfigurasi default untuk Laragon:
     ```php
     $host = 'localhost';
     $db_name = 'onshop_db';
     $username = 'root';
     $password = ''; // kosong secara default di Laragon
     ```
5. **Akses Marketplace di Peramban**:
   - Beranda Pembeli: `http://localhost/on-shop/pages/buyer/index.html` (atau `http://on-shop.test/pages/buyer/index.html`)
   - Portal Admin: `http://localhost/on-shop/pages/admin/login-admin.html`
   - Portal Penjual: `http://localhost/on-shop/pages/seller/index.html`

---

## 🔑 Kredensial Akun Pengujian

| Peran (Role) | Alamat Email | Kata Sandi (Password) | Hak Akses Utama |
| :--- | :--- | :--- | :--- |
| **Super Admin** | `admin@onshop.com` | `admin123` | Akses penuh dashboard analitik, pengaturan platform, log audit, payout & dispute |
| **Penjual (Seller)** | `seller@onshop.com` | `seller123` | Kelola katalog produk variasi, proses pesanan masuk, cek bukti bayar, dompet toko |
| **Pembeli (Buyer)** | `buyer@onshop.com` | `buyer123` | Belanja, keranjang, checkout, wishlist, pembayaran QRIS/VA, riwayat pesanan |

---

## 🧪 Skrip Pengujian Otomatis (Automated Test Suites)

Sistem dilengkapi suite pengujian otomatis mandiri yang dapat dijalankan langsung melalui antarmuka baris perintah (*CLI*) menggunakan PHP Laragon:

```powershell
# 1. Menjalankan Uji Transaksi & Bukti Pembayaran (Minggu 3)
& "C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe" scratch\test_week3.php

# 2. Menjalankan Uji Dashboard Analitik & Konsolidasi (Minggu 4)
& "C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe" scratch\test_week4.php

# 3. Menjalankan Uji Responsivitas Mobile, Navigasi & Aset WebP (Minggu 5)
& "C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe" scratch\test_week5.php

# 4. Menjalankan Uji Transaksi Terintegrasi End-to-End Lintas Peran (Minggu 6)
& "C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe" scratch\test_week6_e2e.php

# 5. Menjalankan Audit Keamanan Sistem & Pentest Otomatis (Minggu 6)
& "C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe" scratch\test_week6_security.php
```

### Rekapitulasi Status Uji Keseluruhan:
- ✅ **Test Suite Minggu 3**: 27 / 27 Lulus (**100% PASS**)
- ✅ **Test Suite Minggu 4**: 38 / 38 Lulus (**100% PASS**)
- ✅ **Test Suite Minggu 5**: 45 / 45 Lulus (**100% PASS**)
- ✅ **Test Suite Minggu 6 (E2E)**: 20 / 20 Lulus (**100% PASS**)
- ✅ **Test Suite Minggu 6 (Keamanan)**: 12 / 12 Lulus (**100% PASS**)
- 🎯 **Total Pengujian**: **142 Test Cases Lulus Sempurna (0 Gagal)**.

---

*Dikembangkan dengan dedikasi untuk arsitektur web modern yang tangguh, aman, dan berkinerja tinggi.*
