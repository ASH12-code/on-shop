# Planning Perbaikan Onshop

Rencana perbaikan dan penyempurnaan platform marketplace Onshop dalam jangka waktu 6 minggu. Proyek ini fokus pada peningkatan keamanan, keandalan alur bisnis, serta optimasi penggunaan tanpa mengubah identitas visual yang sudah ada.

---

## 📌 Ketentuan Utama & Prinsip Kerja

1. **PHP Murni (Native):** Tetap menggunakan PHP tanpa framework/library berat pihak ketiga.
2. **Konsistensi Desain:** Tampilan visual, struktur layout, dan CSS yang ada dipertahankan (perubahan visual hanya untuk responsivitas mobile dan komponen baru yang selaras).
3. **Database & Migrasi Terstruktur:** Menggunakan MySQL via phpMyAdmin (Laragon). Setiap modifikasi skema tabel wajib dicatat dalam file migrasi SQL terurut (`database/migrations/`).
4. **Session-First Security:** Identitas pengguna (`user_id`, `role`, `shop_id`) wajib bersumber dari Server Session, bukan dari payload request/browser (`$_GET`, `$_POST`, JSON body).
5. **Backup Awal:** Pencadangan folder proyek dan ekspor database wajib dilakukan sebelum fase pengerjaan dimulai.

---

## 🎯 Keputusan Arsitektur yang Ditetapkan

- **Simulasi Ongkir:** Menggunakan tabel tarif internal (`shipping_rates`) berdasarkan rute provinsi dan berat barang, tanpa integrasi API kurir berbayar/eksternal.
- **Perlindungan Bot/Spam:** Menggunakan Cloudflare Turnstile pada autentikasi.
- **Verifikasi Pembayaran:** Berbasis unggah bukti transfer yang diverifikasi langsung oleh penjual terkait sebelum pesanan diproses.

---

## 🗓️ Roadmap Pengerjaan (6 Minggu)

### Minggu 1: Fondasi Keamanan & Session Management
- **Sentralisasi Helper:** Standardisasi fungsi keamanan autentikasi, proteksi CSRF, format respons JSON, dan validasi berkas unggahan.
- **Pembersihan Parameter Sensitif:** Mengganti semua input `user_id` / identitas dari client dengan validasi session server di seluruh endpoint pembeli, penjual, dan admin.
- **Autentikasi Aman:** Penerapan hashing password (bcrypt) serta proteksi ketat hak akses per role (RBAC).

### Minggu 2: Proteksi Autentikasi & Logika Ongkir Server-Side
- **Integrasi Turnstile:** Pemasangan verifikasi captcha pada form login dan pendaftaran akun.
- **Engine Ongkir Simulasi:** Pembuatan logika hitung ongkir di server berdasarkan berat belanjaan dan wilayah asal-tujuan toko.
- **Kalkulasi Checkout Mandiri:** Memastikan seluruh nominal transaksi, ongkir, dan diskon dihitung ulang di server untuk mencegah manipulasi client-side.

### Minggu 3: Alur Pembayaran & Verifikasi Penjual
- **Unggah Bukti Bayar:** Fasilitas upload bukti transfer yang aman oleh pembeli (validasi format dan isolasi akses berkas).
- **Workflow Verifikasi Penjual:** Antarmuka dan endpoint bagi penjual untuk menyetujui atau menolak bukti pembayaran sebelum pesanan masuk status kemas/kirim.
- **Sinkronisasi Status Pesanan:** Pembaruan riwayat pesanan pembeli agar mencerminkan status pembayaran dan catatan penolakan secara real-time.

### Minggu 4: Penguatan Dashboard & Analitik
- **Dashboard Admin:** Penyempurnaan metrik analitik bisnis (total sales, pertumbuhan pembeli, produk terlaris, dan performa penjual) sesuai rentang filter waktu.
- **Dashboard Pembeli:** Pemusatan riwayat transaksi, ringkasan belanja, dan status pesanan berjalan pada halaman akun pengguna.

### Minggu 5: Responsivitas Mobile, Navigasi & Optimasi Aset
- **Adaptasi Mobile:** Penyesuaian media queries agar seluruh halaman utama nyaman digunakan di berbagai ukuran layar smartphone/tablet.
- **Penyempurnaan Navigasi:** Perbaikan alur navigasi antar halaman, tombol kembali, serta breadcrumb transaksi.
- **Optimasi Media:** Kompresi aset gambar statis dan penerapan lazy-loading untuk mempercepat waktu pemuatan halaman.

### Minggu 6: Fitur Pendukung, Pengujian Menyeluruh & Dokumentasi
- **Fitur Tambahan:** Integrasi halaman wishlist dan alur simulasi pemulihan password.
- **Audit & Konfigurasi:** Antarmuka log aktivitas sistem dan pengaturan platform untuk kebutuhan admin.
- **Pengujian End-to-End:** Uji coba skenario lengkap belanja dari registrasi hingga transaksi tuntas, serta verifikasi celah keamanan dasar.
- **Dokumentasi Proyek:** Penyusunan panduan instalasi di Laragon dan daftar akun pengujian.
