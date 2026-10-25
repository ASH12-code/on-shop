# Rencana Detail Minggu 1: Fondasi Keamanan & Session Management

Dokumen rencana kerja teknis untuk pelaksanaan **Minggu 1** pada proyek perbaikan marketplace Onshop. Seluruh pengerjaan pada tahap ini berfokus murni pada arsitektur keamanan, perbaikan alur sesi, penutupan celah akses data, dan sanitasi sistem tanpa mengubah tampilan antarmuka (UI/CSS).

---

## 🎯 Tujuan Minggu Ini

1. **Menghilangkan Kerentanan IDOR (*Insecure Direct Object References*):** Menghentikan penerimaan identitas pengguna (`user_id`, `role`, `shop_id`) dari parameter browser (`$_GET`, `$_POST`, JSON body), dan menggantinya dengan validasi sesi aman di sisi server (`$_SESSION`).
2. **Standardisasi Autentikasi Modern:** Menerapkan algoritma enkripsi password standar industri (`password_hash` dengan bcrypt) pada alur pendaftaran dan login, serta mengonversi seluruh data password lama yang masih berbentuk teks polos (*plain-text*).
3. **Penerapan Hak Akses Ketat (*Role-Based Access Control* - RBAC):** Mengunci seluruh endpoint administrasi agar hanya dapat diakses oleh sesi admin valid, serta mengunci endpoint penjual agar terikat pada toko yang sah miliknya.
4. **Pembersihan Celah CORS & Informasi Error:** Menghapus header permisif berbahaya (`Access-Control-Allow-Origin: *`) dan menghentikan pembocoran detail teknis database (*database error leakage*) ke pengguna umum.
5. **Standardisasi Berkas Unggahan:** Memvalidasi berkas gambar (*MIME-type*, ekstensi, validasi binary) dan menerapkan penamaan acak guna mencegah serangan *Arbitrary File Upload* dan *Remote Code Execution* (RCE).

---

## 📋 Daftar Tugas Berurutan

### Fase 1: Cadangan & Persiapan Struktur
1. **Pencadangan Proyek:** Melakukan salinan fisik (*backup*) direktori `on-shop/` dan mengekspor basis data `db_on_shop` melalui phpMyAdmin Laragon sebelum menjalankan perubahan apa pun.
2. **Inisialisasi Folder Standar:**
   - Membuat direktori `database/migrations/` untuk menyimpan berkas migrasi SQL.
   - Membuat direktori `api/helpers/` untuk menempatkan modul utilitas pendukung keamanan.

---

### Fase 2: Pembangunan Modul Helper Inti (`api/helpers/`)
3. **Pembuatan `api/helpers/response.php`:**
   - Menyediakan fungsi seragam pengembalian respons API (`json_response($status, $message, $data, $http_code)`).
   - Memastikan header `Content-Type: application/json; charset=UTF-8` disetel otomatis.
4. **Pembuatan `api/helpers/auth.php`:**
   - Memastikan sesi PHP berjalan (`session_start()` yang aman).
   - Menyediakan fungsi pemeriksaan status login `require_auth()` (mengembalikan 401 jika sesi kosong).
   - Menyediakan fungsi pemeriksaan peran pengguna `require_role($allowed_roles)` (mengembalikan 403 jika peran tidak sesuai).
   - Menyediakan fungsi pengambil identitas terpercaya: `get_current_user_id()`, `get_current_role()`, `get_current_shop_id()`.
5. **Pembuatan `api/helpers/csrf.php`:**
   - Menyediakan mekanisme pembuatan token CSRF berbasis sesi (`generate_csrf_token()`) dan validasi token pada *request method* bertipe mutasi (`validate_csrf_token()`).
6. **Pembuatan `api/helpers/upload.php`:**
   - Menyediakan fungsi validasi berkas unggahan: pemeriksaan error upload, pembatasan ukuran maksimal berkas (contoh: 2 MB), pemeriksaan ekstensi berkas yang diizinkan (JPG, JPEG, PNG, WEBP).
   - Validasi struktur gambar asli menggunakan `getimagesize()` dan `mime_content_type()`.
   - Mengenerate nama berkas baru yang unik dan acak (`bin2hex(random_bytes(16))` atau UUID) untuk mencegah penimpaan file dan eksekusi skrip berbahaya.

---

### Fase 3: Skema Basis Data & Konversi Password Hash
7. **Pencatatan Migrasi Skema 001:**
   - Membuat berkas migrasi `database/migrations/001_ensure_password_hash_length.sql` yang memastikan kolom `password` pada tabel `users` berukuran `VARCHAR(255)`.
8. **Pembuatan Skrip Konversi Data Password:**
   - Membuat skrip sekali pakai `database/migrate_hash_passwords.php` untuk membaca pengguna lama yang password-nya belum di-hash (belum menggunakan pola bcrypt `$2y$`), lalu memperbaruinya dengan `password_hash($plain, PASSWORD_BCRYPT)`.
   - Menjalankan skrip melalui terminal/browser lokal, memvalidasi hasilnya di phpMyAdmin, kemudian menghapus file skrip tersebut demi keamanan.
9. **Pembaruan Endpoint Registrasi (`api/buyer/auth_register.php`):**
   - Menghapus penyimpanan password langsung (teks polos).
   - Mewajibkan pengacakan password menggunakan `password_hash($password, PASSWORD_BCRYPT)`.
10. **Pembaruan Endpoint Login (`api/buyer/auth_login.php`):**
    - Menghapus perbandingan string langsung (`$password === $user['password']`).
    - Mewajibkan verifikasi hanya menggunakan `password_verify($password, $user['password'])`.
    - Mengintegrasikan helper `response.php` dan membersihkan header CORS wildcard.

---

### Fase 4: Pengamanan Penuh Endpoint Admin (12 File)
11. **Penerapan Guard Peran Admin:**
    - Memasang `require_once __DIR__ . '/../helpers/auth.php'` dan memanggil fungsi `require_role('admin')` pada baris awal seluruh file di direktori `api/admin/`.
12. **Pembersihan CORS Wildcard & Sanitasi Respons:**
    - Menghapus header `Access-Control-Allow-Origin: *` dari seluruh 12 file admin.
    - Mengganti penanganan `catch (PDOException $e)` agar tidak lagi mencetak `$e->getMessage()` ke publik; gunakan pesan umum seperti `"Terjadi kesalahan pada sistem."` dan catat error ke log internal.

---

### Fase 5: Pengamanan Penuh Endpoint Penjual (`api/seller/`)
13. **Refaktorisasi Penjaga Sesi Toko (`api/seller/seller_auth.php`):**
    - Menghapus penerimaan `auth_user_id` dari `$_GET` dan JSON body.
    - Menghapus mekanisme *fallback* otomatis (seperti `user_id = 12` atau toko acak `shop_id = 5`).
    - Mengambil `user_id` murni dari `$_SESSION['user_id']` (wajib login).
    - Memverifikasi kepemilikan toko secara nyata ke basis data: toko harus aktif dan memiliki `user_id` yang cocok dengan sesi login saat ini. Jika tidak memiliki toko, tolak dengan kode status 403 Forbidden.
14. **Standardisasi Akses Seluruh Endpoint Seller:**
    - Memastikan 9 file kerja seller lainnya (`manage_catalog.php`, `manage_orders.php`, `manage_reviews.php`, `manage_shop_settings.php`, `manage_wallet.php`, `get_dashboard_data.php`, `get_unread_counters.php`, `manage_chats.php`, `submit_review.php`) mengimpor `seller_auth.php` dan tidak mengambil `shop_id` / `user_id` sembarangan dari browser.

---

### Fase 6: Penutupan Celah IDOR pada Endpoint Pembeli (`api/buyer/`)
15. **Migrasi `user_id` ke Sesi Server:**
    - Mengganti seluruh pembacaan `$_GET['user_id']`, `$_POST['user_id']`, maupun `$input['user_id']` menjadi `get_current_user_id()` pada file-file berikut:
      - `cart.php`: Melihat, menambah, memperbarui, dan menghapus isi keranjang hanya untuk user yang sedang aktif login.
      - `address.php`: Mengelola alamat pengiriman milik diri sendiri.
      - `orders.php`: Menampilkan daftar pesanan dan detail invoice hanya milik pembeli yang login.
      - `checkout.php`: Menautkan pesanan baru langsung ke `user_id` dari sesi.
      - `vouchers.php`: Mengklaim dan mengecek voucher atas nama user dari sesi.
      - `open_shop.php`: Membuka toko untuk user dari sesi (mencegah pembuatan toko untuk akun orang lain).
      - `update_contact.php` & `profile.php`: Memperbarui data profil sendiri tanpa parameter manipulatif.
      - `help_ticket.php` & `manage_chats.php`: Membuka tiket bantuan dan bertukar pesan hanya menggunakan identitas sesi pengirim.
      - `upload_avatar.php`: Memperbarui foto profil akun yang sedang login.

---

### Fase 7: Pengamanan Validasi Upload & Penutupan Kebocoran Error
16. **Penerapan Helper Upload:**
    - Mengintegrasikan `api/helpers/upload.php` ke dalam `api/buyer/upload_avatar.php`, `api/seller/manage_catalog.php` (gambar produk), dan `api/seller/manage_shop_settings.php` (logo toko).
17. **Sanitasi Konfigurasi Database (`config/database.php`):**
    - Menghapus pencetakan error eksplisit (`$e->getMessage()`) pada respons JSON gagal koneksi basis data.
    - Menyiapkan konfigurasi agar pesan teknis SQL disimpan di log server (`error_log`) dan tidak terekspos ke klien.

---

### Fase 8: Pembersihan File Sampah & Pengujian Regresi
18. **Eliminasi File Tidak Terpakai:**
    - Menghapus berkas demonstrasi yang tidak lagi digunakan: `pages/buyer/test-opsi-a.html` dan `pages/buyer/test-opsi-b.html`.
    - Menghapus folder `uji-coba/`.

---

## 📁 File yang Akan Diubah atau Dibuat

### File Baru (Created)
| Path File | Fungsi / Tujuan |
|---|---|
| `database/migrations/001_ensure_password_hash_length.sql` | Memastikan kolom `users.password` memiliki panjang yang cukup (`VARCHAR(255)`) untuk hash bcrypt. |
| `database/migrate_hash_passwords.php` | Skrip utilitas satu kali jalan untuk mengonversi data password teks polos lama menjadi hash bcrypt (dihapus setelah selesai). |
| `api/helpers/response.php` | Standardisasi struktur respons JSON dan manajemen kode HTTP seragam. |
| `api/helpers/auth.php` | Helper manajemen sesi, autentikasi wajib, dan penjaga hak akses per peran (*role guard*). |
| `api/helpers/csrf.php` | Pembuatan dan verifikasi token CSRF untuk transaksi mutasi state. |
| `api/helpers/upload.php` | Validasi keamanan berkas gambar (ukuran, ekstensi, binary mime-check) dan penamaan acak. |

### File Diubah (Modified)
| Path File | Pokok Perubahan |
|---|---|
| `config/database.php` | Sembunyikan pesan `$e->getMessage()` pada koneksi gagal agar tidak membocorkan kredensial/port/host. |
| `api/buyer/auth_register.php` | Wajibkan hashing password dengan `PASSWORD_BCRYPT`, hapus simpan teks polos. |
| `api/buyer/auth_login.php` | Gunakan `password_verify()`, hapus pengecekan teks polos, kelola session aman. |
| `api/buyer/cart.php` | Ambil `user_id` dari sesi, hapus `$_GET['user_id']` / JSON payload. |
| `api/buyer/address.php` | Kunci mutasi alamat berdasarkan sesi pemilik, cegah manipulasi alamat pengguna lain. |
| `api/buyer/orders.php` | Cegah pengguna A melihat data pesanan pengguna B dengan mengunci filter `buyer_id` ke sesi. |
| `api/buyer/checkout.php` | Ambil `user_id` pemesan dari sesi server. |
| `api/buyer/vouchers.php` | Validasi kepemilikan voucher berdasarkan sesi. |
| `api/buyer/open_shop.php` | Ambil akun pemohon toko dari sesi login. |
| `api/buyer/upload_avatar.php` | Gunakan helper `upload.php` dan ikat avatar ke user sesi saat ini. |
| `api/buyer/update_contact.php` | Perbarui kontak akun pemilik sesi saat ini. |
| `api/buyer/profile.php` | Ambil profil murni dari sesi login aktif. |
| `api/buyer/help_ticket.php` | Kaitkan pembuatan dan pembacaan tiket hanya pada user sesi. |
| `api/buyer/manage_chats.php` | Amankan pengirim pesan agar identitas tidak dapat dipalsukan. |
| `api/seller/seller_auth.php` | Hapus fallback ID hardcoded (12 / 5), validasi sesi murni & cek relasi toko di database. |
| `api/seller/manage_catalog.php` | Gunakan helper `upload.php` untuk berkas produk dan validasi kepemilikan toko dari sesi. |
| `api/seller/manage_orders.php` | Pastikan pesanan yang dikelola benar-benar berada di bawah `shop_id` dari sesi. |
| `api/seller/manage_reviews.php` | Batasi ulasan hanya untuk produk milik toko sesi. |
| `api/seller/manage_shop_settings.php` | Gunakan helper upload untuk logo toko dan batasi perubahan hanya untuk toko sesi. |
| `api/seller/manage_wallet.php` | Kunci informasi dompet toko ke akun pemilik sesi yang sah. |
| `api/seller/get_dashboard_data.php` | Muat metrik analitik toko murni berdasarkan `shop_id` sesi. |
| `api/seller/get_unread_counters.php` | Ambil notifikasi berdasarkan toko sesi. |
| `api/seller/manage_chats.php` | Validasi sesi penjual pada alur percakapan toko. |
| `api/seller/submit_review.php` | Pastikan balasan ulasan terikat pada identitas toko sesi yang sah. |
| `api/admin/*.php` (seluruh 12 file) | Pasang `require_role('admin')`, hapus `Access-Control-Allow-Origin: *`, dan sanitasi pesan error SQL. |

### File Dihapus (Deleted)
| Path File | Alasan Penghapusan |
|---|---|
| `pages/buyer/test-opsi-a.html` | Berkas pengujian sementara yang tidak dipakai dalam alur produksi. |
| `pages/buyer/test-opsi-b.html` | Berkas pengujian sementara yang tidak dipakai dalam alur produksi. |
| `uji-coba/` (seluruh folder) | Folder eksperimen kosong/sementara yang tidak menjadi bagian dari aplikasi. |

---

## 🧪 Cara Menguji Hasilnya

### Skenario 1: Uji Perlindungan Hak Akses Admin (RBAC)
1. Buka browser dalam mode *Incognito* / *Private Window* (tanpa sesi login).
2. Akses langsung endpoint admin melalui URL browser atau ekstensi HTTP client (misal: `GET http://localhost/on-shop/api/admin/get_users.php`).
   - **Hasil yang diharapkan:** Mendapatkan status kode **401 Unauthorized** dengan pesan penolakan format JSON terstruktur.
3. Login sebagai akun dengan peran `buyer`, lalu ulangi akses ke endpoint admin di atas.
   - **Hasil yang diharapkan:** Mendapatkan status kode **403 Forbidden** (akses ditolak karena role tidak mencukupi).

### Skenario 2: Uji Penutupan Celah IDOR Pembeli
1. Login sebagai Pembeli A (misal: `user_id = 11`), tambahkan produk ke keranjang belanja.
2. Login sebagai Pembeli B (misal: `user_id = 201`) pada jendela penyamaran terpisah.
3. Melalui Postman / cURL / Fetch Console, coba panggil `GET http://localhost/on-shop/api/buyer/cart.php?action=list&user_id=11` menggunakan sesi Pembeli B.
   - **Hasil yang diharapkan:** Sistem mengabaikan parameter `user_id=11` pada URL dan hanya mengembalikan daftar keranjang milik Pembeli B (bukan Pembeli A).

### Skenario 3: Uji Akses Toko Penjual Tanpa Otorisasi
1. Panggil endpoint seller (misal: `GET http://localhost/on-shop/api/seller/get_dashboard_data.php?auth_user_id=12`) tanpa sesi login atau dengan sesi pembeli biasa yang belum memiliki toko.
   - **Hasil yang diharapkan:** Sistem menolak permintaan dengan status kode **401/403** dan tidak melakukan fallback ke toko orang lain (tidak menampilkan data toko ID 5 atau user 12).

### Skenario 4: Uji Enkripsi Password & Penolakan Teks Polos
1. Buka halaman registrasi (`pages/buyer/register.html`) dan daftarkan akun baru dengan password `rahasia123`.
2. Periksa tabel `users` pada phpMyAdmin di Laragon.
   - **Hasil yang diharapkan:** Kolom `password` pada akun baru berisikan string acak sepanjang 60 karakter yang diawali `$2y$10$...` (hash bcrypt), bukan tulisan `rahasia123`.
3. Buka halaman login (`pages/buyer/login.html`), masukkan akun baru tersebut beserta kata sandi yang benar.
   - **Hasil yang diharapkan:** Berhasil masuk ke dalam aplikasi.
4. Uji login kembali dengan memasukkan kata sandi yang salah.
   - **Hasil yang diharapkan:** Login gagal dengan pesan peringatan yang tepat dan aman.

### Skenario 5: Uji Keamanan Berkas Unggahan (Upload Security)
1. Siapkan berkas teks yang dinamai `malicious.php.jpg` atau skrip PHP murni `script.php`.
2. Coba kirimkan berkas tersebut ke endpoint upload avatar (`api/buyer/upload_avatar.php`).
   - **Hasil yang diharapkan:** Permintaan ditolak karena berkas gagal melewati validasi `getimagesize()` dan ekstensi berkas.
3. Unggah berkas gambar valid format JPG/PNG/WEBP.
   - **Hasil yang diharapkan:** Gambar berhasil disimpan di folder tujuan dengan nama acak unik (misal: `a7f9c2e0b1d3.webp`), tanpa mempertahankan nama berkas asli dari pengguna.

### Skenario 6: Uji Kerahasiaan Error Basis Data
1. Lakukan simulasi kondisi gagal (misal: manipulasi sementara nama tabel atau kueri di salah satu API).
2. Panggil API tersebut dari browser.
   - **Hasil yang diharapkan:** Muncul status HTTP 500 dengan pesan JSON umum (`"Terjadi kesalahan pada sistem"`), tanpa menampilkan baris kode SQL, query string, nama kolom, atau pesan pengecualian PDO (`$e->getMessage()`).

---

## ✅ Kriteria Selesai (Definition of Done)

Minggu 1 dinyatakan **SELESAI** apabila seluruh butir berikut terpenuhi tanpa pengecualian:

- [ ] Seluruh tabel `users` memiliki nilai password terenkripsi bcrypt (tidak ada lagi kata sandi teks polos di database).
- [ ] Proses registrasi akun baru selalu menyimpan password dalam format hash bcrypt.
- [ ] Alur login hanya memverifikasi kredensial menggunakan fungsi `password_verify()`.
- [ ] Seluruh 12 endpoint di `api/admin/*.php` menolak akses tanpa sesi admin yang sah (HTTP 401/403).
- [ ] Header `Access-Control-Allow-Origin: *` telah dihapus dari seluruh API internal.
- [ ] Tidak ada lagi pembacaan `user_id` dari `$_GET`, `$_POST`, atau JSON input pada endpoint pembeli (`api/buyer/`).
- [ ] Endpoint penjual (`api/seller/`) bersih dari parameter palsu `auth_user_id` dan *hardcoded fallback*.
- [ ] Berkas unggahan telah divalidasi binary dan disimpan dengan nama acak unik.
- [ ] Folder uji coba dan berkas demonstrasi `test-opsi-*.html` telah dihapus dari repositori.
- [ ] Tidak ada perubahan pada file CSS, layout visual antarmuka tetap berjalan normal seperti sedia kala.
