# Rencana Detail Minggu 5: Mobile, Navigasi, & Ukuran (Responsivitas, UX, & Performa)

Dokumen perencanaan kerja teknis untuk pelaksanaan **Minggu 5** pada proyek pembaruan marketplace Onshop. Seluruh pengerjaan pada tahap ini berfokus pada penyempurnaan responsivitas mobile di seluruh panel dan halaman yang belum memiliki media query, penambahan navigasi cerdas (menu hamburger, breadcrumbs, tombol kembali), serta optimasi ukuran aset gambar (WebP & lazy loading) guna mempercepat waktu muat halaman secara signifikan tanpa mengubah desain visual asli.

---

## 🎯 Tujuan Minggu Ini

1. **Responsivitas Mobile Komprehensif (`@media` Queries & Viewport):**
   - Menambahkan dan menyempurnakan aturan `@media (max-width: 768px)` dan `@media (max-width: 480px)` pada 12 berkas CSS yang belum optimal atau masih kosong:
     1. `assets/css/admin-css/admin-bantuan.css` (Tata letak tiket bantuan, balasan chat, dan tabel admin)
     2. `assets/css/admin-css/admin-keuangan.css` (Tabel mutasi, kartu saldo platform, dan modal payout)
     3. `assets/css/admin-css/admin-toko.css` (Daftar verifikasi merchant, modal KYC, dan status badge)
     4. `assets/css/admin-css/admin-user.css` (Tabel pengguna, filter role, dan modal blokir akun)
     5. `assets/css/admin-css/index-admin.css` (Penyempurnaan grid kartu metrik KPI dan tabel ranking di ponsel)
     6. `assets/css/admin-css/sidebar-admin.css` (Sidebar drawer mobile, overlay latar belakang, dan tombol toggle)
     7. `assets/css/buyer-css/qris.css` (Tampilan kartu pembayaran QRIS dan kotak unggah bukti bayar di HP)
     8. `assets/css/buyer-css/va.css` (Tampilan nomor Virtual Account dan salin nomor rekening di HP)
     9. `assets/css/buyer-css/pengiriman.css` (Formulir pengiriman dan pilihan kurir)
     10. `assets/css/buyer-css/riwayat-pesanan.css` (Responsivitas halaman transisi)
     11. `assets/css/buyer-css/daftar-alamat.css` (Modal dan kartu buku alamat di perangkat mobile)
     12. `assets/css/seller-css/daftar-produk.css` (Katalog tabel produk penjual, pagination, dan tombol aksi cepat)
   - Menambahkan `<meta name="viewport" content="width=device-width, initial-scale=1.0">` pada halaman yang belum memilikinya (terutama `pages/buyer/pengiriman.html`).
   - Memastikan tidak ada *horizontal scrollbar* (layar meluber) pada ukuran lebar 360px, 768px, dan 1280px.

2. **Kelengkapan Navigasi & UX (User Experience):**
   - **Tombol Kembali (*Back Navigation*) & Navbar:**
     - Menambahkan tombol kembali cerdas (*smart back button*) di halaman `pengiriman.html`, `daftar-alamat.html`, `login.html`, dan `register.html`.
   - **Menu Hamburger Responsif:**
     - Panel Super Admin (`pages/admin/*.html`): Tombol hamburger pada top bar mobile yang mengontrol buka/tutup *sidebar drawer* secara halus dengan *backdrop overlay*.
     - Panel Penjual (`pages/seller/*.html`): Menu hamburger mobile yang terintegrasi pada `seller-nav-component.js`.
   - **Navigasi Breadcrumbs:**
     - Halaman detail produk (`pages/buyer/detail-produk.html`): `Beranda > Kategori > [Nama Produk]`.
     - Halaman checkout (`pages/buyer/checkout.html`): `Beranda > Keranjang > Checkout Pengiriman`.

3. **Optimasi Ukuran Aset & Performa (*Performance*):**
   - **Konversi Banner Raksasa:** Mengonversi `assets/Images/banner.png` yang berukuran 3,54 MB menjadi format modern **WebP** (`banner.webp`) dengan kompresi berkualitas tinggi (ukuran turun > 85%, di bawah 300 KB).
   - **Penerapan `<picture>` atau Fallback WebP:** Memperbarui berkas HTML/CSS beranda agar memuat `banner.webp` dengan fallback `banner.png`.
   - **Native Lazy Loading:** Menambahkan atribut `loading="lazy"` pada gambar produk di halaman beranda (`index.html`), katalog pencarian (`search.html`), dan detail produk (`detail-produk.html`).

---

## 📌 Alur Kerja Pengembangan (5 Tahap Standar)

1. **Tahap 1: Perencanaan (Architect Phase)** - Penyusunan dokumen arsitektur teknis `minggu-5.md` (Tahap saat ini).
2. **Tahap 2: Pendaftaran Tugas (GitHub Issue)** - Pendaftaran issue kerja resmi di GitHub repository `ASH12-code/on-shop`.
3. **Tahap 3: Isolasi Kode (Feature Branch & Pull Request)** - Pembuatan branch fitur `feature/minggu-5-mobile-nav-performance` dan pembukaan PR awal di GitHub.
4. **Tahap 4: Eksekusi & Validasi (Worker Phase)** - Penambahan 12 media query CSS, pembuatan menu hamburger admin & seller, breadcrumb, optimasi WebP, lazy loading, dan test suite.
5. **Tahap 5: Review & Penggabungan (Commit, PR & Merge)** - Review kode akhir, verifikasi layout responsif di browser, presentasi diff, dan penggabungan ke branch `master`.

---

## 📋 Rincian Tugas Teknis Berurutan

### Fase 1: Responsivitas 12 Berkas CSS & Meta Viewport
1. Pasang tag `<meta name="viewport" content="width=device-width, initial-scale=1.0">` pada `pages/buyer/pengiriman.html`.
2. Perbarui 6 berkas CSS Admin:
   - `admin-bantuan.css`: Format kartu pesan bantuan fleksibel vertikal pada layar mobile.
   - `admin-keuangan.css`: Pembungkusan tabel transaksi dengan `overflow-x: auto` dan penataan kartu metrik saldo.
   - `admin-toko.css`: Penataan ulang tabel verifikasi toko menjadi kartu vertikal pada layar sempit.
   - `admin-user.css`: Penataan tabel user dan form filter pencarian.
   - `index-admin.css`: Optimasi grid 6 kartu metrik KPI menjadi 1 kolom (360px) dan 2 kolom (768px).
   - `sidebar-admin.css`: Mengubah sidebar menjadi off-canvas drawer dengan transformasi `transform: translateX(-100%)` di mobile, aktif saat kelas `.show-mobile` terpasang.
3. Perbarui 5 berkas CSS Pembeli:
   - `qris.css`: Penyesuaian ukuran gambar QRIS responsif dan padding kartu pembayaran.
   - `va.css`: Ukuran font nomor Virtual Account responsif agar tidak terpotong di layar 360px.
   - `pengiriman.css`: Form alamat dan pilihan metode kirim responsif.
   - `daftar-alamat.css`: Form modal alamat responsif penuh (*full width modal*) di mobile.
   - `riwayat-pesanan.css`: Penyesuaian kotak redirect agar pas di layar ponsel.
4. Perbarui 1 berkas CSS Penjual:
   - `daftar-produk.css`: Penataan tabel produk seller dengan scroll horizontal halus dan penyesuaian tombol aksi cepat.

---

### Fase 2: Navigasi Cerdas (Hamburger Menu, Tombol Kembali, & Breadcrumb)
5. **Hamburger Menu Panel Admin:**
   - Tambahkan tombol toggle hamburger di header bar mobile `pages/admin/*.html`.
   - Tambahkan fungsi interaktif pada `assets/js/admin-js/sidebar-admin.js` untuk membuka/menutup drawer dan menutup otomatis saat overlay diklik.
6. **Hamburger Menu Panel Seller:**
   - Sinkronisasi toggle drawer pada komponen navigasi `assets/js/seller-js/seller-nav-component.js`.
7. **Tombol Kembali (*Back Navigation*):**
   - Pasang tautan/tombol kembali di `pengiriman.html`, `daftar-alamat.html`, `login.html`, dan `register.html` mengarah ke riwayat sebelumnya atau beranda.
8. **Breadcrumb Navigasi:**
   - Pasang baris breadcrumb di `detail-produk.html` (`Beranda / [Kategori] / [Nama Produk]`).
   - Pasang baris breadcrumb di `checkout.html` (`Beranda / Keranjang / Checkout Pengiriman`).

---

### Fase 3: Optimasi Ukuran Gambar & Performa Muat
9. Konversi `assets/Images/banner.png` (3,54 MB) menjadi format `banner.webp` menggunakan pustaka GD PHP bawaan Laragon (`imagecreatefrompng` dan `imagewebp`).
10. Perbarui pemanggilan banner di `pages/buyer/index.html` menggunakan tag `<picture>`:
    ```html
    <picture>
        <source srcset="../../assets/Images/banner.webp" type="image/webp">
        <img src="../../assets/Images/banner.png" alt="Promo ON Shop" class="banner-img">
    </picture>
    ```
11. Tambahkan atribut `loading="lazy"` pada gambar produk di kartu katalog (`index.html`, `search.html`, `toko.html`, dan `detail-produk.html`).

---

### Fase 4: Pengujian & Validasi
12. Validasi sintaks seluruh file PHP & HTML.
13. Buat dan jalankan automated test suite `scratch/test_week5.php`:
    - Uji keberadaan aturan `@media` pada ke-12 berkas CSS target.
    - Uji keberadaan tag meta viewport pada `pengiriman.html`.
    - Uji keberadaan dan ukuran berkas `banner.webp` (memastikan ukuran < 500 KB dan rasio kompresi berhasil).
    - Uji keberadaan tombol kembali dan breadcrumbs pada berkas HTML target.
    - Uji atribut `loading="lazy"` pada gambar-gambar produk.
    - Uji regresi fitur Minggu 1 s.d. 4.

---

## 📁 Berkas yang Dibuat dan Diubah

### Berkas Baru:
1. `minggu-5.md` (Dokumen perencanaan teknis Minggu 5).
2. `assets/Images/banner.webp` (Aset banner beranda modern yang dioptimasi).

### Berkas yang Diubah:
1. `assets/css/admin-css/admin-bantuan.css`
2. `assets/css/admin-css/admin-keuangan.css`
3. `assets/css/admin-css/admin-toko.css`
4. `assets/css/admin-css/admin-user.css`
5. `assets/css/admin-css/index-admin.css`
6. `assets/css/admin-css/sidebar-admin.css`
7. `assets/css/buyer-css/qris.css`
8. `assets/css/buyer-css/va.css`
9. `assets/css/buyer-css/pengiriman.css`
10. `assets/css/buyer-css/riwayat-pesanan.css`
11. `assets/css/buyer-css/daftar-alamat.css`
12. `assets/css/seller-css/daftar-produk.css`
13. `pages/buyer/pengiriman.html`
14. `pages/buyer/daftar-alamat.html`
15. `pages/buyer/detail-produk.html`
16. `pages/buyer/checkout.html`
17. `pages/buyer/index.html`
18. `assets/js/admin-js/sidebar-admin.js`

---

## 🧪 Kriteria Selesai (Definition of Done)

- [ ] Seluruh 12 berkas CSS target memiliki aturan responsivitas `@media (max-width: 768px)` dan `@media (max-width: 480px)`.
- [ ] Berkas `pages/buyer/pengiriman.html` memiliki tag meta viewport dan tombol kembali.
- [ ] Berkas `assets/Images/banner.webp` berhasil dibuat dengan ukuran < 500 KB (penurunan > 85% dari ukuran asli 3,54 MB).
- [ ] Beranda `pages/buyer/index.html` memuat aset gambar `banner.webp` dan lazy loading pada thumbnail produk.
- [ ] Breadcrumbs terpasang rapi pada `detail-produk.html` dan `checkout.html`.
- [ ] Sidebar panel admin dan seller memiliki menu hamburger responsif yang nyaman dibuka-tutup di layar ponsel.
- [ ] Seluruh antarmuka nyaman digunakan pada viewport 360px, 768px, dan 1280px tanpa horizontal scrollbar.
- [ ] Automated test suite Minggu 5 lulus 100% (`PASS`).
- [ ] PR dibuat di Tahap 3, direview di Tahap 5, dan di-merge ke branch `master`.
