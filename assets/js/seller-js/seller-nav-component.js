/**
 * Komponen Navigasi Global Terpusat Penjual
 * File: assets/js/seller-js/seller-nav-component.js
 */

window.SELLER_NAV_ITEMS = window.SELLER_NAV_ITEMS || [
  { key: "products",  name: "Produk & Etalase", shortName: "Produk",  icon: "🏪", url: "index.html",          badgeClass: "badge-slot-stock" },
  { key: "dashboard", name: "Dasbor Penjualan", shortName: "Dasbor",  icon: "📊", url: "dashbord-toko.html",   badgeClass: "" },
  { key: "orders",    name: "Kelola Pesanan",   shortName: "Pesanan", icon: "📋", url: "kelola-pesanan.html", badgeClass: "badge-slot-orders" },
  { key: "chats",     name: "Pesan Pelanggan",  shortName: "Pesan",   icon: "💬", url: "chat-pelanggan.html", badgeClass: "badge-slot-chats" },
  { key: "reviews",   name: "Rating & Ulasan",  shortName: "Ulasan",  icon: "⭐", url: "ulasan-toko.html",    badgeClass: "badge-slot-reviews" }
];

document.addEventListener("DOMContentLoaded", () => {
  renderSellerNavigation();
  initMobileScrollNavBehavior();
  syncShopProfileAndBadges();

  // Polling pembaruan data setiap 8 detik
  setInterval(syncShopProfileAndBadges, 8000);
});

/**
 * 1. Render Otomatis Sidebar Desktop & Bottom Nav Mobile
 */
function renderSellerNavigation() {
  const currentPath = window.location.pathname.split("/").pop() || "index.html";

  // A. Sidebar Desktop
  const sidebarSlot = document.getElementById("sellerSidebarSlot");
  if (sidebarSlot) {
    let sidebarLinks = "";
    SELLER_NAV_ITEMS.forEach(item => {
      const isActive = currentPath.includes(item.url);
      const badgeHtml = item.badgeClass 
        ? `<span class="global-badge-pill ${item.badgeClass}" style="display:none;">0</span>` 
        : "";

      sidebarLinks += `
        <a href="${item.url}" class="seller-sidebar-link ${isActive ? 'active' : ''}">
          <span>${item.icon} ${item.name}</span>
          ${badgeHtml}
        </a>
      `;
    });

    sidebarSlot.innerHTML = `
      <aside class="seller-sidebar-component">
        <a href="pengaturan-toko.html" class="seller-profile-anchor" title="Buka Pengaturan Toko">
          <div class="seller-sidebar-logo" id="navShopLogoBox">ON</div>
          <div class="seller-sidebar-name" id="navShopNameText">Toko Saya</div>
          <span class="seller-sidebar-settings-btn">⚙️ Pengaturan Toko</span>
        </a>
        <nav class="seller-sidebar-menu">
          ${sidebarLinks}
          <a href="../buyer/index.html" class="seller-sidebar-link" style="margin-top: 10px; border-top: 1px dashed #cbd5e1;">
            <span>🚪 Kembali ke Toko</span>
          </a>
        </nav>
      </aside>
    `;
  }

  // B. Bottom Nav Mobile
  const bottomSlot = document.getElementById("sellerBottomNavSlot");
  if (bottomSlot) {
    let navItemsHtml = "";
    SELLER_NAV_ITEMS.forEach(item => {
      const isActive = currentPath.includes(item.url);
      const badgeHtml = item.badgeClass 
        ? `<span class="mobile-badge-pill ${item.badgeClass}">0</span>` 
        : "";

      navItemsHtml += `
        <a href="${item.url}" class="seller-bottom-nav-item ${isActive ? 'active' : ''}">
          <span class="seller-bottom-nav-icon">${item.icon}</span>
          <span>${item.shortName}</span>
          ${badgeHtml}
        </a>
      `;
    });

    bottomSlot.innerHTML = `
      <nav class="seller-bottom-nav-component" id="globalBottomNav">
        ${navItemsHtml}
      </nav>
    `;
  }
}

/**
 * 2. Sinkronisasi Logo/Nama Toko & Badge Angka
 */
async function syncShopProfileAndBadges() {
  try {
    const storedUser = JSON.parse(localStorage.getItem("currentUser")) || {};
    const authQuery = storedUser.user_id ? `&auth_user_id=${storedUser.user_id}` : "";

    // Ambil Profil Toko
    const resProfile = await fetch(`../../api/seller/manage_shop_settings.php?action=get_settings${authQuery}`);
    if (resProfile.ok) {
      const resultProfile = await resProfile.json();
      if (resultProfile.status && resultProfile.data) {
        const s = resultProfile.data;
        const sName = s.shop_name || "Toko Saya";
        const initial = sName.substring(0, 2).toUpperCase();

        // Update Desktop
        const nameEl = document.getElementById("navShopNameText");
        const logoEl = document.getElementById("navShopLogoBox");
        if (nameEl) nameEl.innerText = sName;
        if (logoEl) {
          if (s.logo_url) {
            logoEl.style.backgroundImage = `url('${s.logo_url}')`;
            logoEl.innerText = "";
          } else {
            logoEl.style.backgroundImage = "none";
            logoEl.innerText = initial;
          }
        }

        // Update Header Mobile
        const mobName = document.getElementById("mobileHeaderShopName");
        const mobLogo = document.getElementById("mobileHeaderShopLogo");
        if (mobName) mobName.innerText = sName;
        if (mobLogo) {
          if (s.logo_url) {
            mobLogo.style.backgroundImage = `url('${s.logo_url}')`;
            mobLogo.innerText = "";
          } else {
            mobLogo.style.backgroundImage = "none";
            mobLogo.innerText = initial;
          }
        }
      }
    }

    // Ambil Angka Notifikasi
    const resCounters = await fetch(`../../api/seller/get_unread_counters.php?action=counters${authQuery}`);
    if (resCounters.ok) {
      const counters = await resCounters.json();
      if (counters.status) {
        applyBadgeCounts(".badge-slot-stock", counters.empty_stock);
        applyBadgeCounts(".badge-slot-orders", counters.pending_orders);
        applyBadgeCounts(".badge-slot-chats", counters.unread_chats);
        applyBadgeCounts(".badge-slot-reviews", counters.new_reviews);
      }
    }
  } catch (err) {
    console.error("Gagal sinkron navigasi:", err);
  }
}

function applyBadgeCounts(selector, count) {
  document.querySelectorAll(selector).forEach(el => {
    if (count > 0) {
      const num = parseInt(count, 10) || 0;
      el.innerText = num > 99 ? "99+" : num;
      el.style.display = "inline-flex";
    } else {
      el.style.display = "none";
    }
  });
}

/**
 * 3. Auto-Hide Bottom Nav Saat Scroll di Layar HP
 */
function initMobileScrollNavBehavior() {
  const nav = document.getElementById("globalBottomNav");
  if (!nav) return;

  let lastScrollY = window.scrollY || document.documentElement.scrollTop;
  const threshold = 8;

  window.addEventListener("scroll", () => {
    const currentScrollY = window.scrollY || document.documentElement.scrollTop;

    if (currentScrollY <= 15) {
      nav.classList.remove("nav-hidden");
      lastScrollY = currentScrollY;
      return;
    }

    if (Math.abs(currentScrollY - lastScrollY) > threshold) {
      if (currentScrollY > lastScrollY) {
        nav.classList.remove("nav-hidden");
      } else {
        nav.classList.add("nav-hidden");
      }
      lastScrollY = currentScrollY;
    }
  }, { passive: true });
}