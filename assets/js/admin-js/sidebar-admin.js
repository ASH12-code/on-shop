/**
 * Komponen & Sinkronisasi Notifikasi Sidebar Super Admin ON Shop (Rombak dari 0)
 * File: assets/js/admin-js/sidebar-admin.js
 */

// Deteksi base URL otomatis agar tidak terjadi 404 pada path relatif maupun absolut
const getBadgeApiUrl = () => {
  const isLocalhostSubdir = window.location.pathname.includes("/on-shop/");
  return isLocalhostSubdir
    ? "/on-shop/api/admin/get_sidebar_badges.php"
    : "../../api/admin/get_sidebar_badges.php";
};

document.addEventListener("DOMContentLoaded", () => {
  fetchGlobalSidebarBadges();
  initAdminMobileSidebar();
});

/**
 * 1. AMBIL JUMLAH NOTIFIKASI PENDING DARI DATABASE
 */
async function fetchGlobalSidebarBadges() {
  try {
    const res = await fetch(getBadgeApiUrl(), {
      method: "GET",
      headers: { "Accept": "application/json" },
      credentials: "include"
    });

    if (!res.ok) {
      console.warn("Gagal memanggil API badge sidebar, status:", res.status);
      return;
    }

    const result = await res.json();
    if (!result.status || !result.data) return;

    const data = result.data;

    // A. Badge Manajemen Toko (Toko butuh verifikasi)
    updateBadgeElement("badgePendingShops", data.pending_shops);

    // B. Badge Keuangan & Payout (Antrean tarik dana butuh transfer)
    updateBadgeElement("badgePendingPayouts", data.pending_payouts);

    // C. Badge Pusat Bantuan (Kasus sengketa butuh mediasi)
    updateBadgeElement("badgeOpenDisputes", data.pending_disputes);

  } catch (err) {
    console.warn("Kendala sinkronisasi badge sidebar admin:", err);
  }
}

/**
 * 2. TAMPILKAN ATAU SEMBUNYIKAN BADGE MERAH
 */
function updateBadgeElement(elementId, count) {
  const el = document.getElementById(elementId);
  if (!el) return;

  const num = parseInt(count, 10) || 0;
  if (num > 0) {
    el.innerText = num > 99 ? "99+" : num;
    el.style.display = "inline-block";
  } else {
    el.innerText = "";
    el.style.display = "none";
  }
}

/**
 * 3. LOGIKA KELUAR (LOGOUT) SUPER ADMIN (Tujuan Redirect Diperbaiki ke buyer/login.html)
 */
async function handleAdminLogout() {
  try {
    // Bersihkan sesi browser lokal
    localStorage.removeItem("currentUser");
    localStorage.removeItem("adminToken");
    sessionStorage.clear();

    // Hapus sesi PHP backend jika endpoint logout tersedia
    const logoutUrl = window.location.pathname.includes("/on-shop/")
      ? "/on-shop/api/auth/logout.php"
      : "../../api/auth/logout.php";

    await fetch(logoutUrl, { 
      method: "POST", 
      credentials: "include" 
    }).catch(() => {});
  } catch (err) {
    console.warn("Logout error:", err);
  } finally {
    // Alihkan langsung ke halaman login resmi pembeli/seller
    window.location.href = "../buyer/login.html";
  }
}

// Buka fungsi ke level window agar bisa dipanggil dari elemen HTML dan skrip modul lain
window.fetchGlobalSidebarBadges = fetchGlobalSidebarBadges;
window.handleAdminLogout = handleAdminLogout;

/**
 * 4. INISIALISASI MOBILE TOPBAR & OFF-CANVAS SIDEBAR (MINGGU 5)
 */
function initAdminMobileSidebar() {
  const sidebar = document.querySelector(".admin-sidebar");
  if (!sidebar) return;

  // Cek apakah backdrop sudah ada, jika belum buat dinamis
  let backdrop = document.querySelector(".admin-sidebar-backdrop");
  if (!backdrop) {
    backdrop = document.createElement("div");
    backdrop.className = "admin-sidebar-backdrop";
    document.body.appendChild(backdrop);
  }

  // Cek apakah mobile topbar sudah ada, jika belum buat dinamis
  let mobileTopbar = document.querySelector(".admin-mobile-topbar");
  if (!mobileTopbar) {
    mobileTopbar = document.createElement("header");
    mobileTopbar.className = "admin-mobile-topbar";
    mobileTopbar.innerHTML = `
      <div class="admin-mobile-brand">
        <span style="background:#2e8b57;color:#fff;border-radius:50%;width:26px;height:26px;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:bold;">ON</span>
        <span>ON Shop Admin</span>
      </div>
      <button type="button" class="admin-hamburger-btn" id="btnAdminHamburger" aria-label="Toggle Menu Admin">
        <span></span>
        <span></span>
        <span></span>
      </button>
    `;
    document.body.insertBefore(mobileTopbar, sidebar);
  }

  const toggleSidebar = () => {
    sidebar.classList.toggle("show-mobile");
    backdrop.classList.toggle("show-mobile");
  };

  const closeSidebar = () => {
    sidebar.classList.remove("show-mobile");
    backdrop.classList.remove("show-mobile");
  };

  const hamburgerBtn = document.getElementById("btnAdminHamburger") || mobileTopbar.querySelector(".admin-hamburger-btn");
  if (hamburgerBtn) {
    hamburgerBtn.addEventListener("click", toggleSidebar);
  }

  backdrop.addEventListener("click", closeSidebar);

  // Tutup sidebar saat link navigasi diklik pada mobile
  sidebar.querySelectorAll(".nav-link").forEach(link => {
    link.addEventListener("click", closeSidebar);
  });
}

window.initAdminMobileSidebar = initAdminMobileSidebar;