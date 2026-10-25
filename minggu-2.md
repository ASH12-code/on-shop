# Rencana Detail Minggu 2: Proteksi Autentikasi (Turnstile), Pemulihan Sandi (OTP 2 Cara) & Logika Ongkir Server-Side

Dokumen rencana kerja teknis untuk pelaksanaan **Minggu 2** pada proyek perbaikan marketplace Onshop. Seluruh pengerjaan pada tahap ini berfokus pada proteksi bot autentikasi menggunakan Cloudflare Turnstile, alur pemulihan kata sandi (*Lupa Password*) dengan verifikasi OTP 2 jalur (SMS & Gmail/Email), pembangunan simulasi ongkir server-side berbasis database, serta eliminasi celah manipulasi harga/ongkir di sisi klien saat proses checkout.

---

## 🎯 Tujuan Minggu Ini

1. **Proteksi Bot & Otomasi Berbahaya (Cloudflare Turnstile):** Mengintegrasikan verifikasi captcha Cloudflare Turnstile pada alur Masuk (*Login*), Pendaftaran (*Register*), dan Permintaan Reset Sandi (*Forgot Password*) untuk mencegah serangan *brute-force*, *credential stuffing*, *SMS/Email bombing*, dan spam pendaftaran akun bot.
2. **Fitur Pemulihan Kata Sandi Mandiri (*Forgot Password* & OTP 2 Cara):** 
   - Menyediakan tautan dan antarmuka *Lupa Password* pada halaman login.
   - Menggunakan form identifikasi pintar (input email atau nomor telepon).
   - Menerapkan **Skenario 1**: jika akun memiliki email dan nomor telepon, pengguna diberikan layar pilihan metode verifikasi (*masking* nomor/email demi privasi):
     - ✉️ Kirim kode OTP ke Email/Gmail
     - 📱 Kirim kode OTP via SMS/WhatsApp
   - Validasi kode OTP 6 digit (kedaluwarsa 5 menit) dan formulir penggantian kata sandi baru yang di-hash dengan standar aman bcrypt.
3. **Mesin Simulasi Ongkir Berbasis Database (*Shipping Engine*):** Membangun tabel tarif pengiriman internal (`shipping_rates`) dan modul utilitas kalkulator ongkos kirim server-side berdasarkan rute provinsi asal toko, provinsi tujuan pembeli, serta berat belanjaan (gram ke kg pembulatan atas).
4. **Kalkulasi Checkout Mandiri (*Anti-Tampering*):** Menghapus ketergantungan kalkulasi nominal ongkir dari payload peramban (*client-side*). Seluruh biaya kirim, diskon voucher produk, diskon voucher ongkir, dan total tagihan dihitung ulang secara independen dan valid di server PHP saat `create_order`.
5. **Pilihan Ekspedisi Dinamis di Antarmuka Checkout:** Memperbarui antarmuka `checkout.html` dan `checkout.js` agar mengambil opsi kurir dan tarif secara dinamis melalui API server, bukan lagi opsi hardcoded statis (`15000|JNE|...`).
6. **Sinkronisasi Wilayah Asal Toko Penjual:** Memperbarui modul pengaturan profil toko penjual (`manage_shop_settings.php` dan `pengaturan-toko.html`) agar provinsi asal pengiriman (`pickup_province`) tersimpan dan terbarui di database sebagai titik tolak kalkulasi ongkir.
7. **Pelacakan Alur Kerja Terpadu (Pull Request Terbuka):** Pembukaan Pull Request (PR) di Tahap 3 yang ditautkan ke Issue #4 untuk memantau setiap perubahan kode secara transparan sebelum penggabungan akhir ke `master`.

---

## 📌 Alur Kerja Pengembangan (5 Tahap)

1. **Tahap 1: Perencanaan (Architect Phase)** - Penyusunan dokumen teknis `minggu-2.md`. *(Selesai)*
2. **Tahap 2: Pendaftaran Tugas (GitHub Issue)** - Pendaftaran tugas resmi di GitHub Issue #4. *(Selesai)*
3. **Tahap 3: Isolasi Kode (Feature Branch & Pull Request)** - Pembuatan branch `feature/minggu-2-turnstile-ongkir-otp` dan pembukaan Pull Request awal. *(Sedang Berjalan)*
4. **Tahap 4: Eksekusi & Validasi (Worker Phase)** - Implementasi kode, migrasi database, dan pengujian menyeluruh.
5. **Tahap 5: Review & Penggabungan (Commit, PR & Merge)** - Peninjauan akhir perubahan, persetujuan pengguna, dan merge PR ke `master`.

---

## 📋 Daftar Tugas Berurutan

### Fase 1: Skema Database & Migrasi 002 & 003 (`database/migrations/`)
1. **Pembuatan Berkas Migrasi SQL 002 (`shipping_rates`):**
   - Membuat berkas `database/migrations/002_create_shipping_rates.sql`.
   - Membuat tabel `shipping_rates`:
     - `rate_id INT AUTO_INCREMENT PRIMARY KEY`
     - `courier_code VARCHAR(20) NOT NULL` (contoh: `'JNE'`, `'JNT'`, `'SICEPAT'`, `'POS'`)
     - `courier_name VARCHAR(50) NOT NULL` (contoh: `'JNE Express'`, `'J&T Express'`, `'SiCepat Ekspres'`, `'Pos Indonesia'`)
     - `service_code VARCHAR(50) NOT NULL` (contoh: `'REG'`, `'EXP'`, `'ECO'`)
     - `service_name VARCHAR(100) NOT NULL` (contoh: `'Reguler (2-3 Hari)'`, `'Express (1-2 Hari)'`, `'Hemat (3-5 Hari)'`)
     - `origin_province VARCHAR(100) NOT NULL`
     - `destination_province VARCHAR(100) NOT NULL`
     - `rate_per_kg DECIMAL(12,2) NOT NULL`
     - `etd_days VARCHAR(50) NOT NULL DEFAULT '2-3 Hari'`
     - `is_active TINYINT(1) NOT NULL DEFAULT 1`
     - `created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP`
     - Indeks/Unique Key pada `(courier_code, service_code, origin_province, destination_province)`.
   - Mengisi data *seed* rute pengiriman simulasi antar-provinsi populer di Indonesia (Kepulauan Bangka Belitung, DKI Jakarta, Jawa Barat, Jawa Tengah, Jawa Timur, Sumatera Selatan, Banten, Bali) serta tarif rute umum (*fallback default*).
2. **Pembuatan Berkas Migrasi SQL 003 (`password_resets`):**
   - Membuat berkas `database/migrations/003_create_password_resets.sql`.
   - Membuat tabel `password_resets`:
     - `reset_id INT AUTO_INCREMENT PRIMARY KEY`
     - `user_id INT NOT NULL`
     - `otp_code VARCHAR(10) NOT NULL`
     - `target_type ENUM('EMAIL','PHONE') NOT NULL`
     - `target_destination VARCHAR(100) NOT NULL`
     - `reset_token VARCHAR(64) DEFAULT NULL` (token acak untuk otorisasi ganti password setelah OTP valid)
     - `expires_at DATETIME NOT NULL`
     - `is_used TINYINT(1) NOT NULL DEFAULT 0`
     - `created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP`
     - Indeks pada `(user_id, otp_code, is_used)`.
3. **Eksekusi Migrasi SQL ke Basis Data `db_on_shop`:**
   - Menjalankan migrasi 002 dan 003 melalui phpMyAdmin atau MySQL CLI Laragon.

---

### Fase 2: Pembangunan Modul Helper Keamanan & Turnstile (`api/helpers/captcha.php`)
4. **Pembuatan Helper `api/helpers/captcha.php`:**
   - Menyediakan fungsi `verify_turnstile($token, $remoteIp = null)` yang memvalidasi token Turnstile ke API resmi Cloudflare (`https://challenges.cloudflare.com/turnstile/v0/siteverify`).
   - Menggunakan kredensial resmi pengujian Cloudflare (*Cloudflare Test Keys*) secara default:
     - Sitekey: `1x00000000000000000000AA` (selalu lolos / *Always passes*)
     - Secret Key: `1x0000000000000000000000000000000000000000000000000000000000000000`
   - Penanganan kegagalan jaringan yang aman (*graceful fallback*) dengan pencatatan log error server jika API verifikasi eksternal tidak dapat dihubungi.
5. **Proteksi Endpoint Autentikasi Backend:**
   - `api/buyer/auth_login.php`: Membaca parameter `turnstile_token` dari JSON payload dan memanggil `verify_turnstile()`. Jika tidak valid atau token kosong, tolak dengan kode HTTP 400 (*Captcha tidak valid*).
   - `api/buyer/auth_register.php`: Membaca parameter `turnstile_token` dan memanggil `verify_turnstile()` sebelum memproses pembuatan akun pengguna baru.
6. **Integrasi Widget Turnstile pada Frontend Login & Register:**
   - `pages/buyer/login.html`: Memuat skrip Cloudflare Turnstile `<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>` dan menambahkan kontainer widget Turnstile di atas tombol submit.
   - `assets/js/buyer-js/login.js`: Mengambil nilai respons widget Turnstile dan menyertakannya ke payload JSON saat submit login. Melakukan reset widget jika verifikasi kredensial gagal.
   - `pages/buyer/register.html`: Memuat skrip Turnstile dan kontainer widget Turnstile pada formulir registrasi.
   - `assets/js/buyer-js/register.js`: Mengambil token Turnstile dan menyertakannya ke payload JSON pendaftaran akun. Melakukan reset widget jika pendaftaran gagal.

---

### Fase 3: Fitur Pemulihan Kata Sandi Mandiri (OTP 2 Jalur: SMS & Gmail)
7. **Pembuatan Helper OTP & Notifikasi (`api/helpers/otp.php`):**
   - Fungsi `generate_otp($length = 6)`: Menghasilkan 6 digit angka acak aman cryptographically (`random_int(100000, 999999)`).
   - Fungsi `send_otp_notification($destination, $type, $otpCode)`:
     - Mengirim OTP ke email via PHP `mail()` / PHPMailer jika tersedia, atau mode simulasi log/respons dev di lingkungan lokal Laragon.
     - Mengirim OTP ke SMS/WhatsApp via API gateway jika tersedia, atau mode simulasi log/respons dev di lingkungan lokal.
8. **Pembuatan Endpoint Backend `api/buyer/auth_forgot_password.php`:**
   - Aksi `request_otp`:
     - Membaca identifier (`email_or_phone`) dan `turnstile_token` (divalidasi via `verify_turnstile()`).
     - Mencari user di database (`WHERE email = :val OR phone = :val`).
     - Jika user tidak ditemukan, kembalikan respon santun tanpa membocorkan eksistensi data (*anti-account enumeration*).
     - Jika user ditemukan:
       - Cek ketersediaan email dan telepon.
       - Jika memiliki keduanya dan parameter `selected_channel` belum dikirim: kembalikan respon `need_channel_selection` dengan data bertopeng (*masked*: contoh `b***4@gmail.com` dan `0812****7894`).
       - Jika channel telah dipilih (atau hanya memiliki 1 channel): simpan OTP baru ke tabel `password_resets` (masa aktif 5 menit), kirimkan OTP via `send_otp_notification()`.
   - Aksi `verify_otp`:
     - Memvalidasi 6 digit kode OTP untuk user terkait.
     - Jika cocok dan belum kedaluwarsa, ubah status `is_used = 1` dan hasilkan `reset_token` unik (masa berlaku 15 menit) untuk tahap ganti sandi.
   - Aksi `reset_password`:
     - Memvalidasi `reset_token`.
     - Meng-hash kata sandi baru menggunakan `password_hash($new_password, PASSWORD_BCRYPT)`.
     - Memperbarui kolom `password` pada tabel `users`.
9. **Pembuatan Halaman & Antarmuka Lupa Password:**
   - `pages/buyer/forgot-password.html`: Antarmuka bersih, responsif, dan konsisten dengan desain `login.html`.
     - Tautan dari `login.html` diarahkan ke `forgot-password.html`.
     - Tahap 1: Input Email/No. Telepon + Widget Cloudflare Turnstile.
     - Tahap 2: Layar Pilihan Channel OTP (jika akun memiliki email & telepon).
     - Tahap 3: Input 6 digit OTP dengan penghitung mundur (*countdown timer*) 60 detik untuk kirim ulang OTP.
     - Tahap 4: Form Kata Sandi Baru & Konfirmasi Sandi Baru.
   - `assets/js/buyer-js/forgot-password.js`: Logika interaktif multi-step dengan animasi toast notifikasi.

---

### Fase 4: Pembangunan Engine Logika Ongkir Server-Side (`api/helpers/shipping.php`)
10. **Pembuatan Helper `api/helpers/shipping.php`:**
    - Fungsi `get_shop_shipping_options($conn, $shopId, $destinationProvince, $totalWeightGrams)`:
      - Mengambil provinsi asal toko dari `shops.pickup_province` (default: `'Kepulauan Bangka Belitung'`).
      - Menghitung berat yang ditagihkan: `billed_kg = ceil(max(100, $totalWeightGrams) / 1000)`.
      - Mencari tarif kurir yang cocok di tabel `shipping_rates` untuk rute provinsi asal ke provinsi tujuan.
      - Mengembalikan daftar opsi kurir (JNE, J&T, SiCepat) beserta `rate_per_kg`, `total_shipping_cost`, dan `etd_days`.
    - Fungsi `calculate_shop_shipping($conn, $shopId, $destinationProvince, $totalWeightGrams, $courierCode, $serviceCode)`:
      - Mengalkulasi biaya pengiriman aktual secara pasti di server untuk satu kombinasi kurir dan layanan yang dipilih pembeli.
11. **Pembuatan Endpoint Dinamis `api/buyer/get_shipping_rates.php`:**
    - Menerima `shop_id`, `address_id` (atau `destination_province`), dan daftar `items`.
    - Mengembalikan daftar kurir beserta tarif kalkulasi resmi dalam format JSON untuk dirender di halaman checkout.

---

### Fase 5: Penguatan Kalkulasi Mandiri pada Endpoint Checkout (`api/buyer/checkout.php`)
12. **Penutupan Kerentanan Manipulasi Biaya Kirim (*DevTools Price Tampering*):**
    - Menghapus pembacaan langsung `$shippingCost = floatval($shop['shipping_cost'])` dari payload input klien.
    - Mengambil identitas alamat tujuan dari `addresses` milik user yang aktif login (`$_SESSION['user_id']`).
    - Menghitung total berat per toko dari basis data: membaca `products.weight_grams` dikali kuantitas barang belanjaan.
    - Menghitung ulang nilai ongkir resmi menggunakan fungsi `calculate_shop_shipping()` berdasarkan kurir yang dipilih (`courier_code` & `service_code`).
13. **Kalkulasi Ulang Diskon Voucher & Batas Bawah Transaksi (*Floor Price*):**
    - Menghitung diskon voucher produk toko dan voucher platform berdasarkan harga riil dari `product_variants` / `products` di database.
    - Memastikan diskon voucher gratis ongkir diterapkan hanya pada nilai ongkir server yang sah (maksimal sebesar nilai ongkir itu sendiri).
    - Menghitung `subtotal`, `total_shipping`, `total_discount`, `service_fee` (Rp 1.000), dan `grand_total` murni dari angka server terverifikasi.
    - Menyimpan nilai hasil verifikasi server ke `master_orders` dan `sub_orders`.

---

### Fase 6: Integrasi Antarmuka Checkout Dinamis (`checkout.html` & `checkout.js`)
14. **Refaktorisasi Pilihan Kurir di Frontend (`assets/js/buyer-js/checkout.js`):**
    - Mengganti opsi kurir statis/hardcoded di dalam `renderCheckoutShops()`.
    - Menambahkan pemanggilan asynchronous ke `api/buyer/get_shipping_rates.php` saat alamat pengiriman dipilih atau diubah.
    - Menampilkan opsi kurir dinamis (JNE Reguler, J&T Express, SiCepat Hemat) dengan biaya dan estimasi tiba yang sesuai dengan berat riil barang dan wilayah tujuan.
    - Menyesuaikan event `onchange` kurir agar memperbarui `courier_code`, `service_code`, dan memicu kalkulasi ulang ringkasan belanja di tampilan secara akurat.

---

### Fase 7: Sinkronisasi Wilayah Asal Toko Penjual (`api/seller/` & `pages/seller/`)
15. **Pembaruan Endpoint `api/seller/manage_shop_settings.php`:**
    - Memasukkan kolom `pickup_province` ke dalam kueri `UPDATE shops` pada aksi `update_settings`.
    - Menyertakan validasi agar provinsi asal toko tidak boleh kosong jika diubah.
16. **Pembaruan Halaman Pengaturan Toko:**
    - `pages/seller/pengaturan-toko.html`: Menambahkan input pilihan/teks Provinsi Asal Pengiriman (`pickupProvince`) berdampingan dengan Kota Asal Pengiriman.
    - `assets/js/seller-js/pengaturan-toko.js`: Membaca dan mengisi nilai `pickup_province` saat halaman dimuat, serta menyertakannya ke payload simpan pengaturan toko.

---

## 📁 Berkas yang Dibuat, Diubah, dan Dihapus

### Berkas Baru:
1. `database/migrations/002_create_shipping_rates.sql` (Skema tabel `shipping_rates` dan data rute simulasi).
2. `database/migrations/003_create_password_resets.sql` (Skema tabel `password_resets` untuk kode OTP).
3. `api/helpers/captcha.php` (Helper verifikasi Cloudflare Turnstile).
4. `api/helpers/otp.php` (Helper generasi & pengiriman OTP).
5. `api/helpers/shipping.php` (Engine hitung ongkos kirim server-side).
6. `api/buyer/auth_forgot_password.php` (Endpoint alur lupa password & reset).
7. `api/buyer/get_shipping_rates.php` (Endpoint penyedia opsi dan tarif kurir dinamis).
8. `pages/buyer/forgot-password.html` (Halaman antarmuka alur lupa password).
9. `assets/js/buyer-js/forgot-password.js` (Logika frontend lupa password & verifikasi OTP).
10. `minggu-2.md` (Dokumentasi rencana kerja Minggu 2).

### Berkas yang Diubah:
1. `api/buyer/auth_login.php` (Validasi Turnstile token sebelum proses autentikasi).
2. `api/buyer/auth_register.php` (Validasi Turnstile token sebelum pembuatan akun).
3. `pages/buyer/login.html` (Tautan lupa password ke `forgot-password.html`, penyematan skrip & komponen Turnstile).
4. `assets/js/buyer-js/login.js` (Ekstraksi Turnstile token & reset saat gagal).
5. `pages/buyer/register.html` (Penyematan skrip & komponen Turnstile).
6. `assets/js/buyer-js/register.js` (Ekstraksi Turnstile token & reset saat gagal).
7. `api/buyer/checkout.php` (Kalkulasi ulang ongkir dan total tagihan mandiri di server).
8. `pages/buyer/checkout.html` (Penyesuaian markup kontainer kurir dinamis).
9. `assets/js/buyer-js/checkout.js` (Pengambilan kurir dinamis dari API server).
10. `api/seller/manage_shop_settings.php` (Penyimpanan kolom `pickup_province`).
11. `pages/seller/pengaturan-toko.html` (Elemen input `pickupProvince`).
12. `assets/js/seller-js/pengaturan-toko.js` (Binding data `pickup_province`).

### Berkas yang Dihapus:
*Tidak ada berkas yang dihapus.*

---

## 🧪 Panduan Pengujian & Validasi

1. **Uji Validasi Turnstile pada Login, Registrasi & Lupa Password:**
   - Coba submit login/register/forgot-password tanpa token Turnstile (atau simulasi payload kosong) -> Server wajib merespons 400 Bad Request dengan pesan peringatan Turnstile.
   - Lakukan submit normal dengan widget Turnstile aktif (test keys) -> Proses verifikasi berhasil.
2. **Uji Alur Pemulihan Kata Sandi (Lupa Password):**
   - Masukkan email/nomor telepon akun yang memiliki kedua kontak terdaftar -> Muncul pilihan saluran OTP (SMS atau Gmail).
   - Pilih SMS atau Gmail -> Kode OTP 6 digit tercatat di tabel `password_resets` dan dikirimkan.
   - Masukkan kode OTP yang salah -> Muncul notifikasi error dan form tidak lanjut ke tahap reset.
   - Masukkan kode OTP yang benar -> Berhasil masuk ke form atur kata sandi baru.
   - Masukkan sandi baru -> Password di-update menggunakan bcrypt, coba login dengan sandi baru -> Login sukses.
3. **Uji Perhitungan Ongkir Berdasarkan Berat & Wilayah:**
   - Tambah produk dengan berat 500 gram (kuantitas 1 = 500g -> dihitung 1 kg).
   - Tambah kuantitas menjadi 3 buah (1.500g -> dihitung 2 kg).
   - Pastikan tarif ongkir yang ditampilkan berlipat ganda sesuai tarif per kg di database `shipping_rates`.
   - Ubah alamat pengiriman dari Bangka Belitung ke DKI Jakarta -> Tarif ongkir berubah sesuai rute antar-provinsi.
4. **Uji Anti-Tampering (Manipulasi Client DevTools pada Checkout):**
   - Buka DevTools Network, lakukan checkout dengan mencegat atau memanipulasi payload: ubah `shipping_cost: 0` atau `shipping_cost: 100`.
   - Periksa respons pembuatan pesanan dan data di tabel `sub_orders`: nilai ongkir yang tersimpan tetap nilai sah hasil perhitungan server, bukan nilai manipulasi dari klien.
5. **Uji Pembaruan Profil Toko Penjual:**
   - Masuk sebagai penjual ke halaman `pengaturan-toko.html`.
   - Ubah provinsi gudang pengiriman, simpan, lalu muat ulang halaman.
   - Pastikan data provinsi yang baru tersimpan dengan benar di tabel `shops`.

---

## 🏁 Kriteria Selesai (Definition of Done)

- [ ] Tabel `shipping_rates` dan `password_resets` berhasil dibuat melalui migrasi SQL 002 dan 003.
- [ ] Widget Cloudflare Turnstile tampil dan aktif pada halaman login, register, dan forgot-password.
- [ ] Endpoint `auth_login.php`, `auth_register.php`, dan `auth_forgot_password.php` menolak request tanpa verifikasi Turnstile yang sah.
- [ ] Alur lupa password berhasil memverifikasi akun, menampilkan pilihan saluran OTP (Skenario 1), memverifikasi 6 digit OTP, dan berhasil mereset password dengan enkripsi bcrypt.
- [ ] Helper `shipping.php` dan endpoint `get_shipping_rates.php` mengembalikan kalkulasi ongkir yang akurat berdasarkan berat produk dan provinsi.
- [ ] Endpoint `checkout.php` menghitung ulang seluruh komponen biaya (ongkir, voucher, total) di server dan kebal terhadap manipulasi DevTools.
- [ ] Seluruh skrip PHP bebas dari *syntax error* (`php -l`).
- [ ] Tampilan antarmuka (CSS/UI) tetap konsisten dengan desain Onshop yang sudah ada.
- [ ] Pull Request (PR) dibuat di Tahap 3 dan berhasil ditinjau serta di-merge ke `master` pada Tahap 5.
