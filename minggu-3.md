# Rencana Detail Minggu 3: Alur Pembayaran, Unggah Bukti Transfer & Verifikasi Penjual

Dokumen rencana kerja teknis untuk pelaksanaan **Minggu 3** pada proyek perbaikan marketplace Onshop. Seluruh pengerjaan pada tahap ini berfokus pada penggantian alur simulasi pembayaran instan menjadi alur transaksi riil berbasis verifikasi bukti transfer oleh penjual, isolasi pengamanan akses berkas bukti bayar, penyajian QRIS lokal independen, serta sinkronisasi status pesanan di sisi pembeli dan penjual.

---

## 🎯 Tujuan Minggu Ini

1. **Eliminasi Simulasi Bayar Instan:** Menghilangkan tombol pembayaran otomatis palsu (*fake auto-success*) pada halaman `pembayaran-qris.html` dan `pembayaran-va.html`, menggantinya dengan alur unggah bukti transfer/pembayaran yang sah.
2. **Kemandirian Aset QRIS Lokal:** Menghapus ketergantungan API pihak ketiga (`api.qrserver.com`) dan menggantinya dengan aset statis QRIS resmi internal Onshop yang andal tanpa risiko kegagalan koneksi pihak luar.
3. **Unggah Bukti Bayar Aman (*Secure Proof Upload*):**
   - Memvalidasi berkas bukti transfer pembeli menggunakan helper `upload.php` (ekstensi JPG, PNG, WEBP, ukuran maksimal 2 MB, dan validasi binary gambar via `getimagesize()`).
   - Menyimpan berkas di direktori terisolasi `uploads/payment_proofs/` yang dilindungi berkas `.htaccess` (`Deny from all`) guna mencegah akses publik langsung (*direct URL hotlinking*).
4. **Penyajian Berkas Terproteksi (*Access-Controlled File Serving*):**
   - Membangun endpoint `api/files/payment_proof.php` yang memverifikasi sesi server (`require_auth()`): hanya pembeli pemilik pesanan, penjual dari toko terkait dalam sub-order, atau administrator sistem yang diizinkan melihat/mengunduh gambar bukti pembayaran.
5. **Workflow Verifikasi Penjual (*Seller Verification Workflow*):**
   - Menambahkan tab filter status **"Menunggu Verifikasi"** pada panel penjual (`kelola-pesanan.html` dan `manage_orders.php`).
   - Menyediakan modal pratinjau bukti bayar bagi penjual.
   - Aksi **Setujui Pembayaran**: mengubah status pembayaran menjadi `PAID` dan pesanan menjadi `DIKEMAS`, mencatat waktu verifikasi (`verified_at`) dan penjual yang memverifikasi.
   - Aksi **Tolak Pembayaran**: modal input alasan penolakan (contoh: nominal transfer tidak sesuai, bukti buram/palsu), mengembalikan status pesanan menjadi dapat diunggah ulang bukti bayar baru oleh pembeli.
6. **Sinkronisasi Status Pesanan Pembeli (*Real-Time Order Tracking*):**
   - Memperbarui halaman `pesanan-saya.html` dan `pesanan-saya.js`:
     - Badge informatif: `Menunggu Pembayaran` (sebelum unggah), `Menunggu Verifikasi Penjual` (setelah unggah), `Pembayaran Ditolak` (disertai alasan penolakan & tombol unggah ulang), serta `Sedang Dikemas` (setelah disetujui).
7. **Jejak Audit Verifikasi (*Audit Trail*):**
   - Mencatat setiap aksi persetujuan dan penolakan pembayaran ke tabel `payment_verification_logs` untuk kebutuhan transparansi dan penanganan sengketa/dispute admin.
8. **Pelacakan Alur Kerja Terpadu (Pull Request Terbuka):**
   - Membuka Pull Request (PR) sejak awal di Tahap 3 yang ditautkan ke GitHub Issue terkait agar seluruh perubahan kode terpantau secara transparan.

---

## 📌 Alur Kerja Pengembangan (5 Tahap Standar)

1. **Tahap 1: Perencanaan (Architect Phase)** - Penyusunan dokumen teknis `minggu-3.md`.
2. **Tahap 2: Pendaftaran Tugas (GitHub Issue)** - Pendaftaran tugas resmi di GitHub Issue.
3. **Tahap 3: Isolasi Kode (Feature Branch & Pull Request)** - Pembuatan branch fitur `feature/minggu-3-pembayaran-verifikasi` dan pembukaan Pull Request awal.
4. **Tahap 4: Eksekusi & Validasi (Worker Phase)** - Implementasi kode, migrasi database, dan pengujian menyeluruh.
5. **Tahap 5: Review & Penggabungan (Commit, PR & Merge)** - Peninjauan akhir perubahan, persetujuan pengguna, dan merge PR ke `master`.

---

## 📋 Daftar Tugas Berurutan

### Fase 1: Skema Database & Migrasi 004 (`database/migrations/`)
1. **Pembuatan Berkas Migrasi SQL 004:**
   - Membuat berkas `database/migrations/004_payment_proofs_and_verification.sql`.
   - Menambahkan kolom pendukung pada tabel `payments`:
     - `proof_image_url VARCHAR(255) DEFAULT NULL`
     - `rejection_reason TEXT DEFAULT NULL`
     - `verified_at DATETIME DEFAULT NULL`
     - `verified_by INT DEFAULT NULL`
     - Penyesuaian nilai ENUM `payment_status`: `'PENDING'`, `'MENUNGGU_VERIFIKASI'`, `'PAID'`, `'REJECTED'`, `'EXPIRED'`, `'FAILED'`.
   - Penyesuaian nilai ENUM `sub_orders.order_status`: menyertakan `'MENUNGGU_VERIFIKASI'` (untuk pesanan non-COD yang sudah unggah bukti bayar namun belum diverifikasi penjual).
   - Membuat tabel log audit `payment_verification_logs`:
     - `log_id INT AUTO_INCREMENT PRIMARY KEY`
     - `payment_id INT NOT NULL`
     - `master_order_id INT NOT NULL`
     - `sub_order_id INT DEFAULT NULL`
     - `action ENUM('SUBMIT_PROOF','APPROVE','REJECT') NOT NULL`
     - `actor_user_id INT NOT NULL`
     - `actor_role ENUM('buyer','seller','admin') NOT NULL`
     - `notes TEXT DEFAULT NULL`
     - `created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP`
     - Indeks pada `(payment_id, master_order_id)`.
2. **Eksekusi Migrasi 004 ke Basis Data `db_on_shop`:**
   - Menjalankan migrasi SQL melalui MySQL CLI Laragon.

---

### Fase 2: Pengamanan Folder Berkas & Endpoint Penyaji Privat
3. **Inisialisasi Direktori Terproteksi:**
   - Membuat folder `uploads/payment_proofs/`.
   - Meletakkan berkas `uploads/payment_proofs/.htaccess` dengan konfigurasi `Deny from all` sehingga berkas tidak bisa diakses langsung via URL browser.
4. **Pembuatan Skrip Penyaji Berkas Privat `api/files/payment_proof.php`:**
   - Memastikan sesi autentikasi pengguna aktif (`require_auth()`).
   - Menerima parameter nama berkas/payment ID yang diminta.
   - Memeriksa hak akses secara ketat:
     - Apakah pengguna adalah pembeli pemilik pesanan? (`mo.buyer_id === current_user_id`), ATAU
     - Apakah pengguna adalah penjual dari toko terkait? (`so.shop_id === current_shop_id`), ATAU
     - Apakah pengguna adalah admin platform? (`role === 'admin'`).
   - Jika berhak: kirim header `Content-Type` yang sesuai (image/jpeg, image/png, image/webp) dan sajikan isi biner berkas via `readfile()`.
   - Jika tidak berhak: tolak dengan `403 Forbidden`.

---

### Fase 3: Endpoint Unggah Bukti Bayar Pembeli (`api/buyer/`)
5. **Pembuatan Endpoint `api/buyer/upload_payment_proof.php`:**
   - Hanya menerima request bertipe `POST (multipart/form-data)`.
   - Mengambil identitas pembeli dari sesi server (`$userId = require_auth()`).
   - Validasi parameter `master_order_id`:
     - Memastikan pesanan berstatus valid untuk dibayar (`payment_status IN ('PENDING', 'REJECTED')`).
     - Memastikan pesanan benar milik `$userId`.
   - Validasi berkas bukti transfer menggunakan helper `handle_image_upload()` dari `api/helpers/upload.php`:
     - Pembatasan ukuran maksimal 2 MB.
     - Ekstensi diizinkan: JPG, JPEG, PNG, WEBP.
     - Verifikasi binary gambar asli via `getimagesize()`.
     - Penamaan acak aman: `proof_{master_order_id}_{timestamp}_{random}.webp`.
   - Memperbarui data pembayaran:
     - `payments.proof_image_url = $fileName`
     - `payments.payment_status = 'MENUNGGU_VERIFIKASI'`
     - `payments.rejection_reason = NULL`
     - `sub_orders.order_status = 'MENUNGGU_VERIFIKASI'` (untuk seluruh sub-order terkait pesanan tersebut).
   - Mencatat aktivitas ke tabel `payment_verification_logs` dengan aksi `'SUBMIT_PROOF'`.
   - Mengembalikan respon JSON terstandar.

---

### Fase 4: Integrasi Halaman Pembayaran & Kemandirian QRIS
6. **Penyediaan Aset QRIS Lokal:**
   - Menyediakan berkas gambar QRIS statis Onshop pada `assets/images/qris-onshop.webp` / `qris-onshop.png`.
7. **Pembaruan Halaman `pembayaran-qris.html` & `qris.js`:**
   - Mengganti tautan `api.qrserver.com` dengan aset QRIS lokal Onshop.
   - Menghapus tombol simulasi instan `handlePaySuccess()`.
   - Menyediakan kartu formulir unggah bukti transfer QRIS (input file gambar, pratinjau thumbnail sebelum kirim, tombol "Kirim Bukti Pembayaran").
8. **Pembaruan Halaman `pembayaran-va.html` & `va.js`:**
   - Menghapus countdown deteksi palsu 5 detik yang otomatis menandai lunas.
   - Menampilkan nomor Virtual Account resmi dan instruksi transfer bank.
   - Menyediakan formulir unggah struk / mutasi bukti transfer bank.

---

### Fase 5: Workflow Verifikasi Penjual (`api/seller/` & `pages/seller/`)
9. **Pembaruan Endpoint `api/seller/manage_orders.php`:**
   - Aksi `get_orders`:
     - Mengikutsertakan kolom `p.proof_image_url`, `p.payment_status`, `p.rejection_reason`, `p.verified_at` dari join tabel `payments`.
     - Mendukung filter status `MENUNGGU_VERIFIKASI`.
   - Aksi baru `approve_payment`:
     - Menerima `sub_order_id`.
     - Memverifikasi kepemilikan toko penjual (`so.shop_id === current_shop_id`).
     - Memperbarui `payments.payment_status = 'PAID'` dan `payments.verified_by = $userId`, `payments.verified_at = NOW()`.
     - Memperbarui `sub_orders.order_status = 'DIKEMAS'` (pesanan siap masuk antrean kemas toko).
     - Mencatat log audit verifikasi `'APPROVE'`.
   - Aksi baru `reject_payment`:
     - Menerima `sub_order_id` dan `rejection_reason` (alasan penolakan wajib diisi).
     - Memperbarui `payments.payment_status = 'REJECTED'` dan `payments.rejection_reason = :reason`.
     - Memperbarui `sub_orders.order_status = 'MENUNGGU_BAYAR'`.
     - Mencatat log audit verifikasi `'REJECT'`.
10. **Pembaruan Antarmuka `kelola-pesanan.html` & `kelola-pesanan.js`:**
    - Menambahkan tab **"Menunggu Verifikasi"** beserta indikator badge counter jumlah pesanan pending.
    - Menambahkan tombol aksi pada kartu pesanan:
      - 👁️ **Lihat Bukti Bayar** (Membuka modal pratinjau gambar bukti transfer).
      - ✅ **Setujui** (Verifikasi pembayaran sah -> status pesanan beralih ke `Perlu Diproses`).
      - ❌ **Tolak** (Membuka modal input catatan alasan penolakan).

---

### Fase 6: Sinkronisasi Status & Riwayat di Sisi Pembeli (`pages/buyer/`)
11. **Pembaruan Endpoint `api/buyer/orders.php`:**
    - Aksi `list`: menyertakan status `proof_image_url`, `rejection_reason`, dan status verifikasi pembayaran.
    - Aksi `get_detail`: menyertakan riwayat bukti transfer dan status verifikasi.
12. **Pembaruan Halaman `pesanan-saya.html` & `pesanan-saya.js`:**
    - Menampilkan badge status:
      - `Menunggu Pembayaran`: tombol "Bayar / Unggah Bukti".
      - `Menunggu Verifikasi Penjual`: status sedang diperiksa penjual toko terkait.
      - `Bukti Ditolak`: banner peringatan berwarna oranye/merah yang menampilkan catatan alasan penolakan dari penjual, serta tombol "Unggah Ulang Bukti".
    - Menambahkan modal dialog untuk unggah bukti bayar langsung dari daftar pesanan saya tanpa harus kembali ke halaman checkout.

---

## 📁 Berkas yang Dibuat, Diubah, dan Dihapus

### Berkas Baru:
1. `database/migrations/004_payment_proofs_and_verification.sql` (Migrasi skema bukti bayar dan tabel audit log verifikasi).
2. `api/buyer/upload_payment_proof.php` (Endpoint unggah bukti transfer pembeli).
3. `api/files/payment_proof.php` (Endpoint penyaji privat gambar bukti bayar dengan verifikasi RBAC).
4. `uploads/payment_proofs/.htaccess` (Proteksi berkas langsung direktori bukti pembayaran).
5. `assets/images/qris-onshop.png` (Aset gambar QR code statis resmi Onshop).
6. `minggu-3.md` (Dokumen perencanaan teknis Minggu 3).

### Berkas yang Diubah:
1. `pages/buyer/pembayaran-qris.html` (Formulir unggah bukti transfer QRIS dan aset lokal).
2. `assets/js/buyer-js/qris.js` (Logika unggah bukti QRIS, menghapus simulasi instan).
3. `pages/buyer/pembayaran-va.html` (Formulir unggah bukti transfer VA).
4. `assets/js/buyer-js/va.js` (Logika unggah bukti VA, menghapus timer palsu).
5. `pages/seller/kelola-pesanan.html` (Tab "Menunggu Verifikasi", modal pratinjau bukti bayar, modal alasan penolakan).
6. `assets/js/seller-js/kelola-pesanan.js` (Logika setujui/tolak pembayaran dan render bukti).
7. `api/seller/manage_orders.php` (Aksi `approve_payment`, `reject_payment`, dan join bukti bayar).
8. `pages/buyer/pesanan-saya.html` (Modal unggah ulang bukti & banner status penolakan).
9. `assets/js/buyer-js/pesanan-saya.js` (Penanganan status verifikasi pembayaran dan alasan tolak).
10. `api/buyer/orders.php` (Pembaruan status query pesanan pembeli).

### Berkas yang Dihapus:
*Tidak ada berkas yang dihapus.*

---

## 🧪 Panduan Pengujian & Validasi

1. **Uji Unggah Bukti Bayar:**
   - Lakukan checkout dengan metode QRIS atau Virtual Account -> Pesanan berstatus `MENUNGGU_BAYAR`.
   - Unggah berkas bukan gambar (misal file .txt atau .php) -> Wajib ditolak oleh server (400 Bad Request).
   - Unggah gambar bukti sah (JPG/PNG < 2MB) -> Berhasil diunggah, status pesanan beralih ke `MENUNGGU_VERIFIKASI`.
2. **Uji Keamanan Berkas Privat (.htaccess & RBAC):**
   - Salin URL fisik berkas bukti di `uploads/payment_proofs/proof_xxx.webp` dan buka langsung di tab peramban baru -> Server wajib mengembalikan `403 Forbidden`.
   - Akses via `api/files/payment_proof.php`:
     - Login sebagai pembeli pemilik -> Gambar tampil dengan benar.
     - Login sebagai pembeli lain yang tidak terkait -> Wajib ditolak (403 Forbidden).
     - Login sebagai penjual dari toko yang menerima pesanan -> Gambar tampil dengan benar.
3. **Uji Workflow Verifikasi Penjual (Setujui & Tolak):**
   - Buka panel penjual `kelola-pesanan.html` pada tab "Menunggu Verifikasi".
   - Klik pratinjau bukti -> Gambar bukti transfer muncul di modal.
   - Skenario Penolakan: Klik "Tolak", masukkan alasan "Nominal transfer kurang Rp 5.000" -> Pesanan kembali ke status menunggu bayar, alasan tercatat di database dan tabel log.
   - Periksa di akun pembeli `pesanan-saya.html`: Muncul banner penolakan "Nominal transfer kurang Rp 5.000" beserta tombol unggah ulang.
   - Skenario Persetujuan: Pembeli unggah bukti baru -> Penjual klik "Setujui" -> Status pembayaran berubah jadi `PAID` dan pesanan berpindah ke tab "Perlu Diproses" (`DIKEMAS`).
4. **Uji Kemandirian Aset QRIS:**
   - Buka halaman `pembayaran-qris.html` tanpa koneksi ke `api.qrserver.com` -> Gambar QRIS tetap muncul tajam menggunakan aset statis lokal Onshop.

---

## 🏁 Kriteria Selesai (Definition of Done)

- [ ] Skema database `payments` dan tabel log audit `payment_verification_logs` berhasil dimigrasikan via `004_payment_proofs_and_verification.sql`.
- [ ] Berkas bukti bayar tersimpan aman di direktori terproteksi `.htaccess` dan hanya bisa diakses melalui otorisasi `api/files/payment_proof.php`.
- [ ] Tombol simulasi instan palsu di `pembayaran-qris.html` dan `pembayaran-va.html` berhasil digantikan dengan alur unggah bukti transfer.
- [ ] Aset QRIS lokal terpasang dan tidak lagi bergantung pada layanan pihak ketiga.
- [ ] Penjual dapat melihat bukti bayar, menyetujui (pesanan menjadi `DIKEMAS`), atau menolak dengan catatan alasan di `kelola-pesanan.html`.
- [ ] Pembeli dapat melihat status verifikasi, membaca alasan penolakan jika ditolak, dan mengunggah ulang bukti bayar di `pesanan-saya.html`.
- [ ] Seluruh skrip PHP bebas dari *syntax error* (`php -l`).
- [ ] Pull Request (PR) dibuat di Tahap 3 dan berhasil ditinjau serta di-merge ke `master` pada Tahap 5.
