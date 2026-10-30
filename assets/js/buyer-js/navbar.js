/**
 * ON Shop - Universal Navbar Logic (Minimalist PC Icons, Dropdown, & 5-Slot Mobile Navigation)
 * Terintegrasi Pencegat Tamu (Guest Blocker) & Notifikasi Sengketa / Refund (Desktop & Mobile)
 * File: assets/js/buyer-js/navbar.js
 */

document.addEventListener("DOMContentLoaded", function () {
  initNavbarSearch();
  initNavbarCartBadge();
  initNavbarChatBadge();
  initSellerSlotStatus();
  initAccountDropdown();
  protectAccountLinks();
  checkBuyerDisputeAlerts();

  // Sinkronisasi badge obrolan & keranjang berkala (setiap 8 detik)
  setInterval(function () {
    initNavbarCartBadge();
    initNavbarChatBadge();
  }, 8000);
});

window.addEventListener("load", function () {
  initNavbarCartBadge();
  initNavbarChatBadge();
  initSellerSlotStatus();
  checkBuyerDisputeAlerts();
});

// Pantau perubahan sesi & penyimpanan data antar tab
window.addEventListener("storage", function (e) {
  if (
    e.key === "cartMultiData" ||
    e.key === "on_shop_cart" ||
    e.key === "currentUser" ||
    (e.key && e.key.startsWith("read_disputes_"))
  ) {
    initNavbarCartBadge();
    initNavbarChatBadge();
    initSellerSlotStatus();
    initAccountDropdown();
    checkBuyerDisputeAlerts();
  }
});

/**
 * 0. Helper: Cek Akun Pengguna Aktif
 */
function getNavbarCurrentUser() {
  try {
    const raw = localStorage.getItem("currentUser");
    const parsed = raw ? JSON.parse(raw) : null;
    return (parsed && parsed.user_id) ? parsed : null;
  } catch (err) {
    return null;
  }
}

/**
 * 1. Logika Kolom Pencarian Navbar
 */
function initNavbarSearch() {
  const searchInput = document.getElementById("navSearchInput") || document.getElementById("pageSearchInput");
  const searchBtn = document.getElementById("navSearchBtn") || document.getElementById("pageSearchBtn");

  if (!searchInput || !searchBtn) return;

  function executeSearch() {
    const keyword = searchInput.value.trim();
    if (keyword === "") {
      if (typeof showToast === "function") {
        showToast("Silakan masukkan kata kunci pencarian!", "warning");
      }
      return;
    }
    window.location.href = `search.html?keyword=${encodeURIComponent(keyword)}`;
  }

  searchBtn.addEventListener("click", function (e) {
    e.preventDefault();
    executeSearch();
  });

  searchInput.addEventListener("keypress", function (e) {
    if (e.key === "Enter") {
      e.preventDefault();
      executeSearch();
    }
  });
}

/**
 * 2. Inisialisasi & Sinkronisasi Badge Keranjang
 */
async function initNavbarCartBadge() {
  const badgeDesktop = document.getElementById("navbarCartBadge");
  const badgeBottom = document.getElementById("bottomNavCartBadge");
  const allBadges = [badgeDesktop, badgeBottom].filter(Boolean);

  if (allBadges.length === 0) return;

  const currentUser = getNavbarCurrentUser();
  let totalQty = 0;

  if (currentUser) {
    try {
      const res = await fetch(`/on-shop/api/buyer/cart.php?action=list&user_id=${currentUser.user_id}`);
      const result = await res.json();

      if (result.status && Array.isArray(result.data)) {
        result.data.forEach((shop) => {
          if (Array.isArray(shop.items)) {
            shop.items.forEach((item) => {
              const q = Number(item.qty || 1);
              totalQty += isNaN(q) ? 1 : q;
            });
          }
        });
        renderBadgeUI(allBadges, totalQty);
        return;
      }
    } catch (err) {
      console.warn("Gagal membaca keranjang database:", err);
    }
  }

  renderBadgeUI(allBadges, totalQty);
}

/**
 * 3. Inisialisasi & Sinkronisasi Badge Chat Belum Dibaca
 */
async function initNavbarChatBadge() {
  const badgeDesk = document.getElementById("navbarChatBadge");
  const badgeMob = document.getElementById("bottomNavChatBadge");
  const badges = [badgeDesk, badgeMob].filter(Boolean);

  if (badges.length === 0) return;

  const currentUser = getNavbarCurrentUser();
  if (!currentUser) {
    renderBadgeUI(badges, 0);
    return;
  }

  try {
    const res = await fetch(`/on-shop/api/buyer/manage_chats.php?action=get_unread_count&auth_user_id=${currentUser.user_id}`);
    const result = await res.json();

    if (result.status && typeof result.unread_count !== "undefined") {
      renderBadgeUI(badges, parseInt(result.unread_count, 10));
    } else if (result.status && Array.isArray(result.data)) {
      const totalUnread = result.data.reduce((acc, conv) => acc + parseInt(conv.unread_count || 0, 10), 0);
      renderBadgeUI(badges, totalUnread);
    }
  } catch (err) {
    console.warn("Gagal mengecek pesan belum dibaca:", err);
  }
}

function renderBadgeUI(badges, count) {
  badges.forEach((badge) => {
    if (count > 0) {
      badge.textContent = count > 99 ? "99+" : count;
      badge.classList.remove("nav-hidden");
      badge.style.display = "inline-flex";
    } else {
      badge.textContent = "0";
      badge.classList.add("nav-hidden");
      badge.style.display = "none";
    }
  });
}

/**
 * 4. Status Tombol Buka Toko / Toko Saya
 */
function initSellerSlotStatus() {
  const sellerSlotDesktop = document.getElementById("navSellerSlot");
  const sellerSlotMobile = document.getElementById("navMobileShopSlot");
  const currentUser = getNavbarCurrentUser();

  const hasShop = currentUser && (currentUser.has_shop == 1 || currentUser.role === "seller");

  if (sellerSlotDesktop) {
    sellerSlotDesktop.classList.remove("nav-hidden");
    if (hasShop) {
      sellerSlotDesktop.textContent = "Toko Saya";
      sellerSlotDesktop.href = "/on-shop/pages/seller/index.html";
    } else {
      sellerSlotDesktop.textContent = "Mulai Jual";
      sellerSlotDesktop.href = "buka-toko.html";
    }
  }

  if (sellerSlotMobile) {
    if (hasShop) {
      sellerSlotMobile.innerHTML = "🏪 Toko Saya";
      sellerSlotMobile.href = "/on-shop/pages/seller/index.html";
    } else {
      sellerSlotMobile.innerHTML = "✨ Buka Toko";
      sellerSlotMobile.href = "buka-toko.html";
    }
  }
}

/**
 * 5. Inisialisasi Dropdown Akun (Tamu vs Pengguna Terverifikasi)
 */
function initAccountDropdown() {
  const trigger = document.getElementById("navAccountTrigger");
  const menu = document.getElementById("navAccountMenu");
  const nameLabel = document.getElementById("navAccountNameText");
  const avatarBadge = document.getElementById("navAccountAvatarBadge");

  const currentUser = getNavbarCurrentUser();

  if (currentUser) {
    const rawName = currentUser.name || "Akun";
    if (nameLabel) nameLabel.innerText = rawName;
    if (avatarBadge) avatarBadge.innerText = rawName.substring(0, 2).toUpperCase();

    if (menu) {
      menu.innerHTML = `
        <a href="akun.html" class="nav-dropdown-item">👤 Profil Saya</a>
        <a href="pesanan-saya.html" class="nav-dropdown-item" id="navItemPesananSaya" style="display: flex; align-items: center; justify-content: space-between;">
          <span>📦 Pesanan Saya</span>
          <span id="navDisputeCountBadge" style="display: none; background: #ef4444; color: #ffffff; font-size: 10.5px; font-weight: bold; padding: 2px 7px; border-radius: 10px; margin-left: 8px;"></span>
        </a>
        <a href="pusat-bantuan.html" class="nav-dropdown-item">❓ Pusat Bantuan</a>
        <div class="nav-dropdown-divider"></div>
        <button type="button" class="nav-dropdown-item nav-dropdown-logout" onclick="handleBuyerLogout()">
          🚪 Log Out
        </button>
      `;
    }
  } else {
    if (nameLabel) nameLabel.innerText = "Masuk";
    if (avatarBadge) avatarBadge.innerText = "👤";

    if (menu) {
      menu.innerHTML = `
        <a href="login.html" class="nav-dropdown-item">🔑 Masuk Akun</a>
        <a href="daftar.html" class="nav-dropdown-item">📝 Daftar Akun</a>
        <a href="pusat-bantuan.html" class="nav-dropdown-item">❓ Pusat Bantuan</a>
      `;
    }
  }

  if (!trigger || !menu) return;

  trigger.onclick = function (e) {
    e.stopPropagation();
    menu.classList.toggle("show");
  };

  document.addEventListener("click", function () {
    menu.classList.remove("show");
  });
}

/**
 * 6. Deteksi Notifikasi Putusan Admin / Refund Sengketa (Desktop & Mobile Bottom Nav)
 */
async function checkBuyerDisputeAlerts() {
  const currentUser = getNavbarCurrentUser();
  const trigger = document.getElementById("navAccountTrigger");
  const countBadge = document.getElementById("navDisputeCountBadge");

  // Deteksi tautan menu Pesanan di Mobile Bottom Navigation
  const mobileOrderLink =
    document.querySelector('.bottom-nav a[href*="pesanan-saya.html"], .mobile-nav a[href*="pesanan-saya.html"], a.bottom-nav-item[href*="pesanan-saya.html"]') ||
    Array.from(document.querySelectorAll("a")).find((el) => {
      const text = el.textContent.trim().toLowerCase();
      return text.includes("pesanan") && el.querySelector("img, svg, span, i");
    });

  if (!currentUser || !currentUser.user_id) {
    removeNavAlertDot(trigger, countBadge, mobileOrderLink);
    return;
  }

  try {
    const res = await fetch(`/on-shop/api/buyer/orders.php?action=get_buyer_alerts&user_id=${currentUser.user_id}`);
    const result = await res.json();

    if (result.status && Array.isArray(result.disputes)) {
      // Ambil daftar dispute_id yang sudah dibaca user dari localStorage
      const readDisputeIds = JSON.parse(localStorage.getItem(`read_disputes_${currentUser.user_id}`)) || [];

      // Saring sengketa yang belum dibaca
      const unreadAlerts = result.disputes.filter((d) => !readDisputeIds.includes(parseInt(d.dispute_id, 10)));

      if (unreadAlerts.length > 0) {
        // A. DESKTOP: Titik merah di samping nama akun
        if (trigger && !trigger.querySelector(".nav-alert-dot")) {
          const dot = document.createElement("span");
          dot.className = "nav-alert-dot";
          dot.style.cssText =
            "width: 8px; height: 8px; background-color: #ef4444; border-radius: 50%; display: inline-block; margin-left: 5px; vertical-align: middle; border: 1.5px solid #ffffff; box-shadow: 0 0 4px rgba(239, 68, 68, 0.8);";
          trigger.appendChild(dot);
        }

        // B. DESKTOP: Angka badge di menu dropdown "Pesanan Saya"
        if (countBadge) {
          countBadge.innerText = unreadAlerts.length;
          countBadge.style.display = "inline-block";
        }

        // C. MOBILE: Titik merah melayang di atas ikon "Pesanan" bottom navigation
        if (mobileOrderLink && !mobileOrderLink.querySelector(".mobile-order-alert-dot")) {
          mobileOrderLink.style.position = "relative";
          const mDot = document.createElement("span");
          mDot.className = "mobile-order-alert-dot";
          mDot.style.cssText =
            "position: absolute; top: 4px; right: calc(50% - 15px); width: 8.5px; height: 8.5px; background-color: #ef4444; border-radius: 50%; border: 1.5px solid #ffffff; box-shadow: 0 0 5px rgba(239, 68, 68, 0.9); z-index: 20; pointer-events: none;";
          mobileOrderLink.appendChild(mDot);
        }
      } else {
        removeNavAlertDot(trigger, countBadge, mobileOrderLink);
      }
    } else {
      removeNavAlertDot(trigger, countBadge, mobileOrderLink);
    }
  } catch (err) {
    console.warn("Gagal memeriksa notifikasi sengketa akun:", err);
  }
}

function removeNavAlertDot(trigger, countBadge, mobileOrderLink) {
  if (trigger) {
    const dot = trigger.querySelector(".nav-alert-dot");
    if (dot) dot.remove();
  }
  if (countBadge) {
    countBadge.style.display = "none";
    countBadge.innerText = "";
  }
  if (mobileOrderLink) {
    const mDot = mobileOrderLink.querySelector(".mobile-order-alert-dot");
    if (mDot) mDot.remove();
  }
}

/**
 * 7. Pencegat Akses Halaman Terproteksi untuk Tamu (Guest Blocker)
 */
function protectAccountLinks() {
  const currentUser = getNavbarCurrentUser();

  if (!currentUser) {
    const protectedSelectors = [
      'a[href*="akun.html"]',
      'a[href*="pesanan-saya.html"]',
      'a[href*="keranjang.html"]'
    ];

    document.querySelectorAll(protectedSelectors.join(",")).forEach((elem) => {
      if (elem.closest(".nav-dropdown-menu")) return;

      elem.addEventListener("click", function (e) {
        e.preventDefault();
        e.stopPropagation();

        const targetHref = elem.getAttribute("href") || "akun.html";
        sessionStorage.setItem("redirectAfterLogin", targetHref);

        if (typeof showToast === "function") {
          showToast("Silakan masuk terlebih dahulu!", "warning");
        }

        setTimeout(() => {
          window.location.href = `login.html?redirect=${encodeURIComponent(targetHref)}`;
        }, 600);
      });
    });
  }
}

/**
 * 8. Fungsi Keluar / Log Out
 */
function handleBuyerLogout() {
  localStorage.removeItem("currentUser");
  sessionStorage.clear();

  fetch("../../api/buyer/logout.php").catch(() => {});

  if (typeof showToast === "function") {
    showToast("Berhasil keluar dari akun.", "info");
  }

  setTimeout(() => {
    window.location.href = "login.html";
  }, 800);
}

window.handleBuyerLogout = handleBuyerLogout;
window.initNavbarCartBadge = initNavbarCartBadge;
window.initNavbarChatBadge = initNavbarChatBadge;
window.checkBuyerDisputeAlerts = checkBuyerDisputeAlerts;