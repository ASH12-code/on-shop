/**
 * Logika Pusat Akun, Buku Alamat, Pengaturan Avatar, & Modal Kustom Bebas Localhost
 * File: assets/js/buyer-js/akun.js
 */

let currentUser = null;
let currentAddresses = [];
let tempAvatarFile = null;

let isEmailLocked = false;
let isPhoneLocked = false;

// Penampung ID alamat saat modal konfirmasi hapus muncul
let pendingDeleteAddressId = null;

// ==========================================
// BAGIAN 0: NAVIGASI KEMBALI CERDAS (GLOBAL)
// ==========================================
function handleAccountBackNav() {
  if (
    document.referrer &&
    !document.referrer.includes("login.html") &&
    !document.referrer.includes("daftar.html")
  ) {
    window.location.href = document.referrer;
    return;
  }
  window.location.href = "index.html";
}

window.handleAccountBackNav = handleAccountBackNav;

document.addEventListener("DOMContentLoaded", async () => {
  initNavbarSearchEvents();

  // 1. Verifikasi sesi login ketat dari server
  const isAuthenticated = await verifyServerSession();
  if (!isAuthenticated) return;

  // 2. Muat data profil, ringkasan belanja, & buku alamat pengguna aktif
  await loadUserProfile();
  await loadBuyerDashboardSummary();
  await loadUserAddresses();
});

function initNavbarSearchEvents() {
  const searchInput = document.getElementById("navSearchInput");
  const searchBtn = document.getElementById("navSearchBtn");

  if (searchBtn && searchInput) {
    searchBtn.onclick = () => {
      const q = searchInput.value.trim();
      if (q) window.location.href = `search.html?keyword=${encodeURIComponent(q)}`;
    };

    searchInput.addEventListener("keypress", (e) => {
      if (e.key === "Enter") {
        e.preventDefault();
        searchBtn.click();
      }
    });
  }
}

// ==========================================
// BAGIAN 1: VERIFIKASI SESI & KONTROL SELLER
// ==========================================
async function verifyServerSession() {
  try {
    const res = await fetch("../../api/buyer/check_session.php");
    const session = await res.json();

    // Jika server menyatakan belum login / sesi habis
    if (!session || !session.logged_in) {
      localStorage.removeItem("currentUser");
      sessionStorage.clear();

      if (typeof showToast === "function") {
        showToast("Silakan masuk terlebih dahulu untuk mengakses akun Anda.", "warning");
      }
      setTimeout(() => {
        window.location.href = "login.html?redirect=akun.html";
      }, 700);
      return false;
    }

    // Sesi valid dari server
    currentUser = session;
    localStorage.setItem("currentUser", JSON.stringify(session));
    updateSellerNavigation(session);
    return true;
  } catch (err) {
    console.error("Gagal memeriksa sesi server:", err);

    // Jangan izinkan akses jika backend sesi tidak merespons
    const local = JSON.parse(localStorage.getItem("currentUser"));
    if (!local || !local.user_id) {
      localStorage.removeItem("currentUser");
      window.location.href = "login.html?redirect=akun.html";
      return false;
    }

    currentUser = local;
    updateSellerNavigation(local);
    return true;
  }
}

function updateSellerNavigation(user) {
  const sellerMenu = document.getElementById("sellerNavMenu");
  if (!sellerMenu) return;

  const hasShop = (user.has_shop == 1 || user.role === "seller");
  if (hasShop) {
    sellerMenu.style.display = "inline-block";
    sellerMenu.innerText = "Toko Saya";
    sellerMenu.href = "../../pages/seller/index.php";
  } else {
    sellerMenu.style.display = "none";
  }
}

// ==========================================
// BAGIAN 2: NAVIGASI TAB
// ==========================================
function switchAccountTab(panelId, btnElement) {
  document.querySelectorAll(".account-tab-panel").forEach((panel) => panel.classList.add("hidden"));
  document.querySelectorAll(".account-tab-btn").forEach((btn) => btn.classList.remove("active"));

  const targetPanel = document.getElementById(panelId);
  if (targetPanel) targetPanel.classList.remove("hidden");
  if (btnElement) btnElement.classList.add("active");
}

// ==========================================
// BAGIAN 3: BIODATA & PENGUNCIAN KONTAK
// ==========================================
async function loadUserProfile() {
  try {
    const res = await fetch(`/on-shop/api/buyer/profile.php?user_id=${currentUser.user_id}`);
    const result = await res.json();

    if (!result.status) return;
    const u = result.data;

    currentUser = { ...currentUser, ...u };
    localStorage.setItem("currentUser", JSON.stringify(currentUser));

    document.getElementById("headerFullName").innerText = u.name || "Pengguna ON Shop";
    document.getElementById("headerUsername").innerText = u.user_code || "@user";

    const avatarEl = document.getElementById("profileAvatarImg");
    if (avatarEl) {
      avatarEl.src = u.avatar_url
        ? u.avatar_url
        : `https://placehold.co/120x120/2e8b57/ffffff?text=${encodeURIComponent(u.name ? u.name.charAt(0) : "U")}`;
    }

    document.getElementById("fullNameInput").value = u.name || "";
    document.getElementById("usernameInput").value = u.user_code || "";

    // Kunci status email jika sudah terisi
    const emailInput = document.getElementById("emailInput");
    if (u.email && u.email.trim() !== "" && u.email !== "-") {
      emailInput.value = u.email;
      emailInput.disabled = true;
      emailInput.style.backgroundColor = "#e2e8f0";
      emailInput.style.cursor = "not-allowed";
      isEmailLocked = true;
      appendLockBadge(emailInput, "Terkunci 🔒");
    } else {
      emailInput.value = "";
      emailInput.disabled = false;
      emailInput.style.backgroundColor = "#ffffff";
      emailInput.style.cursor = "text";
      isEmailLocked = false;
    }

    // Kunci nomor telepon jika sudah terisi
    const phoneInput = document.getElementById("phoneInput");
    if (u.phone && u.phone.trim() !== "" && u.phone !== "-") {
      phoneInput.value = u.phone;
      phoneInput.disabled = true;
      phoneInput.style.backgroundColor = "#e2e8f0";
      phoneInput.style.cursor = "not-allowed";
      isPhoneLocked = true;
      appendLockBadge(phoneInput, "Terkunci 🔒");
    } else {
      phoneInput.value = "";
      phoneInput.disabled = false;
      phoneInput.style.backgroundColor = "#ffffff";
      phoneInput.style.cursor = "text";
      isPhoneLocked = false;
    }
  } catch (err) {
    console.error("Gagal memuat profil pengguna:", err);
  }
}

function appendLockBadge(inputElement, text) {
  const parent = inputElement.parentElement;
  if (!parent) return;

  const existingBadge = parent.querySelector(".contact-lock-badge");
  if (existingBadge) return;

  const badge = document.createElement("span");
  badge.className = "contact-lock-badge";
  badge.innerText = text;
  badge.style.fontSize = "11px";
  badge.style.color = "#64748b";
  badge.style.marginLeft = "6px";
  badge.style.fontWeight = "bold";

  const label = parent.querySelector("label");
  if (label) label.appendChild(badge);
}

async function handleSaveProfile(e) {
  e.preventDefault();

  const nameVal = document.getElementById("fullNameInput").value.trim();
  const emailInput = document.getElementById("emailInput");
  const phoneInput = document.getElementById("phoneInput");

  if (!nameVal) {
    if (typeof showToast === "function") {
      showToast("Nama lengkap wajib diisi!", "warning");
    }
    return;
  }

  try {
    const resProfile = await fetch("/on-shop/api/buyer/profile.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        user_id: currentUser.user_id,
        name: nameVal
      })
    });
    const resProfJson = await resProfile.json();
    if (!resProfJson.status) {
      if (typeof showToast === "function") {
        showToast(resProfJson.message || "Gagal memperbarui nama profil.", "error");
      }
      return;
    }
  } catch (err) {
    console.error("Kesalahan update profil:", err);
    return;
  }

  const newEmail = !isEmailLocked ? emailInput.value.trim() : null;
  const newPhone = !isPhoneLocked ? phoneInput.value.trim() : null;

  if (newEmail || newPhone) {
    try {
      const resContact = await fetch("../../api/buyer/update_contact.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          email: newEmail,
          phone: newPhone
        })
      });
      const resContJson = await resContact.json();
      if (typeof showToast === "function") {
        showToast(resContJson.message, "info");
      }
    } catch (err) {
      console.error("Kesalahan update kontak:", err);
    }
  } else {
    if (typeof showToast === "function") {
      showToast("Profil berhasil diperbarui!", "success");
    }
  }

  await loadUserProfile();
}

// ==========================================
// BAGIAN 4: FOTO PROFIL (AVATAR)
// ==========================================
function handleAvatarUpload(e) {
  const file = e.target.files[0];
  if (!file) return;

  const allowedTypes = ["image/jpeg", "image/png", "image/webp"];
  if (!allowedTypes.includes(file.type)) {
    if (typeof showToast === "function") {
      showToast("Format gambar tidak didukung!", "warning");
    }
    e.target.value = "";
    return;
  }

  if (file.size > 3 * 1024 * 1024) {
    if (typeof showToast === "function") {
      showToast("Ukuran berkas maksimal 3MB.", "warning");
    }
    e.target.value = "";
    return;
  }

  tempAvatarFile = file;

  const reader = new FileReader();
  reader.onload = function (evt) {
    const previewImg = document.getElementById("previewAdjustImg");
    previewImg.src = evt.target.result;

    document.getElementById("avatarPosX").value = 50;
    document.getElementById("avatarPosY").value = 50;
    updateAvatarPosition();

    document.getElementById("cropAvatarModal").classList.remove("hidden");
  };
  reader.readAsDataURL(file);
}

function updateAvatarPosition() {
  const x = document.getElementById("avatarPosX").value;
  const y = document.getElementById("avatarPosY").value;
  const img = document.getElementById("previewAdjustImg");
  if (img) img.style.objectPosition = `${x}% ${y}%`;
}

function closeCropModal() {
  document.getElementById("cropAvatarModal").classList.add("hidden");
  document.getElementById("avatarUploadInput").value = "";
  tempAvatarFile = null;
}

async function saveAdjustedAvatar() {
  if (!tempAvatarFile) return;

  const posX = parseInt(document.getElementById("avatarPosX").value, 10);
  const posY = parseInt(document.getElementById("avatarPosY").value, 10);

  const img = new Image();
  img.src = document.getElementById("previewAdjustImg").src;

  img.onload = async function () {
    const canvas = document.createElement("canvas");
    const ctx = canvas.getContext("2d");

    const outputSize = 300;
    canvas.width = outputSize;
    canvas.height = outputSize;

    const minDim = Math.min(img.width, img.height);
    const maxOffsetX = img.width - minDim;
    const maxOffsetY = img.height - minDim;

    const sx = maxOffsetX * (posX / 100);
    const sy = maxOffsetY * (posY / 100);

    ctx.drawImage(img, sx, sy, minDim, minDim, 0, 0, outputSize, outputSize);

    canvas.toBlob(
      async function (blob) {
        if (!blob) return;

        const formData = new FormData();
        formData.append("user_id", currentUser.user_id);
        formData.append("avatar", blob, "avatar.webp");

        try {
          const res = await fetch("/on-shop/api/buyer/upload_avatar.php", {
            method: "POST",
            body: formData
          });
          const result = await res.json();

          if (result.status) {
            currentUser.avatar_url = result.avatar_url;
            localStorage.setItem("currentUser", JSON.stringify(currentUser));

            const profileImg = document.getElementById("profileAvatarImg");
            if (profileImg) profileImg.src = result.avatar_url;

            closeCropModal();
            if (typeof showToast === "function") {
              showToast("Foto profil berhasil diperbarui!", "success");
            }
          }
        } catch (err) {
          console.error("Gagal mengirim avatar:", err);
        }
      },
      "image/webp",
      0.9
    );
  };
}

// ==========================================
// BAGIAN 5: BUKU ALAMAT PENGIRIMAN
// ==========================================
async function loadUserAddresses() {
  const container = document.getElementById("addressCardsFeed");
  if (!container) return;

  container.innerHTML = "<p style='color:#777; font-size: 13px;'>Memuat daftar alamat...</p>";

  try {
    const res = await fetch(`/on-shop/api/buyer/address.php?action=list&user_id=${currentUser.user_id}`);
    const result = await res.json();

    if (result.status) {
      currentAddresses = result.data;
      renderAddressCards(currentAddresses);
    }
  } catch (err) {
    console.error("Gagal mengambil data alamat:", err);
    container.innerHTML = "<p style='color:#e74c3c; font-size: 13px;'>Gagal menghubungkan ke database alamat.</p>";
  }
}

function renderAddressCards(addresses) {
  const container = document.getElementById("addressCardsFeed");
  if (!container) return;
  container.innerHTML = "";

  if (addresses.length === 0) {
    container.innerHTML = "<p style='color:#888; font-size:14px; padding: 20px 0;'>Belum ada alamat tersimpan. Klik '+ Tambah Alamat Baru'.</p>";
    return;
  }

  addresses.forEach((addr) => {
    const isPrimary = addr.is_default == 1 || addr.is_primary == 1;
    const card = document.createElement("div");
    card.className = `address-card-item ${isPrimary ? "is-primary" : ""}`;

    card.innerHTML = `
      <div class="address-card-content">
        <div class="address-meta-row">
          <span class="address-label-badge">${addr.label || "Alamat"}</span>
          ${isPrimary ? '<span class="address-primary-badge">Alamat Utama</span>' : ""}
        </div>
        <p class="address-recipient">${addr.recipient_name} <span class="address-phone">(${addr.phone})</span></p>
        <p class="address-detail-text">${addr.street_address || addr.full_address}, ${addr.city}, ${addr.province || ""} ${addr.postal_code || ""}</p>
      </div>

      <div class="address-actions">
        ${!isPrimary ? `<button type="button" class="btn-set-primary" onclick="setAsPrimaryAddress(${addr.address_id})">Jadikan Utama</button>` : ""}
        <div class="address-sub-actions">
          <button type="button" class="btn-addr-link" onclick="openEditAddressModal(${addr.address_id})">Ubah</button>
          ${!isPrimary ? `<button type="button" class="btn-addr-link delete" onclick="confirmDeleteAddress(${addr.address_id})">Hapus</button>` : ""}
        </div>
      </div>
    `;

    container.appendChild(card);
  });
}

function openAddressModal() {
  document.getElementById("addressModalTitle").innerText = "Tambah Alamat Baru";
  document.getElementById("editAddressId").value = "";
  document.getElementById("addressForm").reset();
  document.getElementById("addressModal").classList.remove("hidden");
}

function openEditAddressModal(addrId) {
  const addr = currentAddresses.find((a) => a.address_id == addrId);
  if (!addr) return;

  document.getElementById("addressModalTitle").innerText = "Ubah Alamat";
  document.getElementById("editAddressId").value = addr.address_id;
  document.getElementById("addrLabelInput").value = addr.label || "Rumah";
  document.getElementById("addrRecipientInput").value = addr.recipient_name;
  document.getElementById("addrPhoneInput").value = addr.phone;
  document.getElementById("addrCityInput").value = addr.city;
  document.getElementById("addrPostalInput").value = addr.postal_code || "";
  document.getElementById("addrStreetInput").value = addr.street_address || addr.full_address || "";
  document.getElementById("addrIsPrimaryInput").checked = addr.is_default == 1 || addr.is_primary == 1;

  document.getElementById("addressModal").classList.remove("hidden");
}

function closeAddressModal() {
  document.getElementById("addressModal").classList.add("hidden");
}

async function handleSaveAddress(e) {
  e.preventDefault();

  const editId = document.getElementById("editAddressId").value;
  const isPrimary = document.getElementById("addrIsPrimaryInput").checked;

  const payload = {
    user_id: currentUser.user_id,
    address_id: editId ? parseInt(editId, 10) : 0,
    label: document.getElementById("addrLabelInput").value.trim(),
    recipient_name: document.getElementById("addrRecipientInput").value.trim(),
    phone: document.getElementById("addrPhoneInput").value.trim(),
    city: document.getElementById("addrCityInput").value.trim(),
    province: "Kepulauan Bangka Belitung",
    postal_code: document.getElementById("addrPostalInput").value.trim(),
    street_address: document.getElementById("addrStreetInput").value.trim(),
    full_address: document.getElementById("addrStreetInput").value.trim(),
    is_default: isPrimary ? 1 : 0
  };

  if (!payload.label || !payload.recipient_name || !payload.phone || !payload.city || !payload.street_address) {
    if (typeof showToast === "function") {
      showToast("Mohon lengkapi seluruh field alamat yang wajib!", "warning");
    }
    return;
  }

  try {
    const res = await fetch("/on-shop/api/buyer/address.php?action=save", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });

    const result = await res.json();
    if (typeof showToast === "function") {
      showToast(result.message, result.status ? "success" : "error");
    }

    if (result.status) {
      closeAddressModal();
      loadUserAddresses();
    }
  } catch (err) {
    console.error("Gagal menyimpan alamat:", err);
  }
}

async function setAsPrimaryAddress(addrId) {
  try {
    const res = await fetch("/on-shop/api/buyer/address.php?action=set_primary", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ user_id: currentUser.user_id, address_id: addrId })
    });
    const result = await res.json();
    if (result.status) {
      if (typeof showToast === "function") showToast("Alamat utama diperbarui.", "success");
      loadUserAddresses();
    }
  } catch (err) {
    console.error("Gagal mengatur alamat utama:", err);
  }
}

// =========================================================================
// BAGIAN 6: MODAL KONFIRMASI HAPUS ALAMAT (BEBAS LOCALHOST)
// =========================================================================
function confirmDeleteAddress(addrId) {
  pendingDeleteAddressId = addrId;
  const modal = document.getElementById("deleteAddressConfirmModal");
  if (modal) {
    modal.classList.remove("hidden");
  }
}

function closeDeleteAddressModal() {
  pendingDeleteAddressId = null;
  const modal = document.getElementById("deleteAddressConfirmModal");
  if (modal) {
    modal.classList.add("hidden");
  }
}

async function executeDeleteAddress() {
  if (!pendingDeleteAddressId) return;

  const btnConfirm = document.getElementById("btnConfirmProceedDeleteAddr");
  if (btnConfirm) {
    btnConfirm.disabled = true;
    btnConfirm.innerText = "Menghapus...";
  }

  try {
    const res = await fetch("/on-shop/api/buyer/address.php?action=delete", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ 
        user_id: currentUser.user_id, 
        address_id: pendingDeleteAddressId 
      })
    });
    const result = await res.json();

    closeDeleteAddressModal();

    if (typeof showToast === "function") {
      showToast(result.message || "Alamat berhasil dihapus.", result.status ? "success" : "error");
    }

    if (result.status) {
      loadUserAddresses();
    }
  } catch (err) {
    console.error("Gagal menghapus alamat:", err);
    closeDeleteAddressModal();
    if (typeof showToast === "function") {
      showToast("Gagal menghapus alamat karena kendala koneksi.", "error");
    }
  } finally {
    if (btnConfirm) {
      btnConfirm.disabled = false;
      btnConfirm.innerText = "Ya, Hapus";
    }
    pendingDeleteAddressId = null;
  }
}

// =========================================================================
// BAGIAN 7: MODAL KONFIRMASI LOG OUT (BEBAS LOCALHOST)
// =========================================================================
function confirmLogout() {
  const modal = document.getElementById("logoutConfirmModal");
  if (modal) {
    modal.classList.remove("hidden");
  }
}

function closeLogoutModal() {
  const modal = document.getElementById("logoutConfirmModal");
  if (modal) {
    modal.classList.add("hidden");
  }
}

function executeLogout() {
  localStorage.removeItem("currentUser");
  sessionStorage.clear();
  window.location.href = "login.html";
}

// ==========================================
// BAGIAN 8: RINGKASAN DASHBOARD BELANJA PEMBELI (MINGGU 4)
// ==========================================
async function loadBuyerDashboardSummary() {
  const kpiSpent = document.getElementById("buyerTotalSpent");
  const kpiCompleted = document.getElementById("buyerCompletedOrdersNote");
  const kpiActive = document.getElementById("buyerActiveOrders");
  const kpiTotal = document.getElementById("buyerTotalOrders");
  const recentContainer = document.getElementById("recentBuyerOrdersList");

  if (!kpiSpent || !recentContainer) return;

  try {
    const res = await fetch("../../api/buyer/orders.php?action=dashboard_summary", {
      method: "GET",
      headers: { "Accept": "application/json" },
      credentials: "include"
    });
    const result = await res.json();

    if (!result.status || !result.data) {
      recentContainer.innerHTML = '<div class="recent-empty-state">Belum dapat memuat ringkasan transaksi.</div>';
      return;
    }

    const d = result.data;
    kpiSpent.innerText = formatRupiah(d.total_spent);
    if (kpiCompleted) kpiCompleted.innerText = `${Number(d.completed_orders || 0)} pesanan selesai`;
    if (kpiActive) kpiActive.innerText = Number(d.active_orders || 0);
    if (kpiTotal) kpiTotal.innerText = Number(d.total_orders || 0);

    renderRecentBuyerOrders(d.recent_orders || []);
  } catch (err) {
    console.error("Gagal memuat ringkasan dashboard pembeli:", err);
    recentContainer.innerHTML = '<div class="recent-empty-state">Terjadi kendala saat memuat ringkasan pesanan.</div>';
  }
}

function renderRecentBuyerOrders(orders) {
  const container = document.getElementById("recentBuyerOrdersList");
  if (!container) return;
  container.innerHTML = "";

  if (!orders || orders.length === 0) {
    container.innerHTML = `
      <div class="recent-empty-state">
        <p style="margin-bottom:6px;">Belum ada riwayat pesanan.</p>
        <a href="index.html">Mulai jelajahi produk menarik di ON Shop &rarr;</a>
      </div>
    `;
    return;
  }

  const statusMap = {
    'MENUNGGU_BAYAR': { text: 'Menunggu Pembayaran', class: 'recent-status-menunggu-bayar' },
    'MENUNGGU_VERIFIKASI': { text: 'Verifikasi Pembayaran', class: 'recent-status-menunggu-verifikasi' },
    'DIKEMAS': { text: 'Sedang Dikemas', class: 'recent-status-dikemas' },
    'DIKIRIM': { text: 'Sedang Dikirim', class: 'recent-status-dikirim' },
    'SELESAI': { text: 'Selesai', class: 'recent-status-selesai' },
    'BATAL': { text: 'Dibatalkan', class: 'recent-status-batal' },
    'DITOLAK': { text: 'Ditolak', class: 'recent-status-ditolak' }
  };

  orders.forEach(order => {
    const item = document.createElement("div");
    item.className = "recent-order-item";

    const st = statusMap[order.order_status] || { text: order.order_status, class: 'recent-status-menunggu-bayar' };
    
    let prodName = `Pesanan #${escapeHtml(order.sub_order_number)}`;
    let thumb = "https://placehold.co/80x80?text=Produk";

    if (order.first_item && order.first_item.product_name) {
      const extra = order.other_items_count > 0 ? ` <span style="color:#64748b; font-weight:normal;">(+${order.other_items_count} barang lainnya)</span>` : "";
      prodName = `${escapeHtml(order.first_item.product_name)}${extra}`;
      if (order.first_item.product_image_url) {
        thumb = order.first_item.product_image_url;
      }
    }

    item.innerHTML = `
      <div class="recent-order-left">
        <img src="${escapeHtml(thumb)}" alt="Produk" class="recent-order-thumb" onerror="this.src='https://placehold.co/80x80?text=Produk'">
        <div class="recent-order-meta">
          <div class="recent-order-number">${escapeHtml(order.sub_order_number)} &bull; ${escapeHtml(order.shop_name || 'Toko')}</div>
          <div class="recent-order-product-name">${prodName}</div>
          <div class="recent-order-shop-time">${escapeHtml(order.created_at)}</div>
        </div>
      </div>
      <div class="recent-order-right">
        <div class="recent-order-amount-wrap">
          <span class="recent-order-amount">${formatRupiah(order.total_amount)}</span>
          <span class="recent-status-badge ${st.class}">${st.text}</span>
        </div>
        <a href="pesanan-saya.html" class="btn-detail-recent">Lihat Detail</a>
      </div>
    `;
    container.appendChild(item);
  });
}

function formatRupiah(num) {
  return "Rp " + Number(num || 0).toLocaleString("id-ID");
}

function escapeHtml(text) {
  if (!text) return "";
  const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
  return String(text).replace(/[&<>"']/g, m => map[m]);
}

// Daftarkan semua fungsi ke window global agar dapat dipanggil onclick
window.switchAccountTab = switchAccountTab;
window.handleSaveProfile = handleSaveProfile;
window.handleAvatarUpload = handleAvatarUpload;
window.updateAvatarPosition = updateAvatarPosition;
window.closeCropModal = closeCropModal;
window.saveAdjustedAvatar = saveAdjustedAvatar;
window.openAddressModal = openAddressModal;
window.openEditAddressModal = openEditAddressModal;
window.closeAddressModal = closeAddressModal;
window.handleSaveAddress = handleSaveAddress;
window.setAsPrimaryAddress = setAsPrimaryAddress;
window.confirmDeleteAddress = confirmDeleteAddress;
window.closeDeleteAddressModal = closeDeleteAddressModal;
window.executeDeleteAddress = executeDeleteAddress;
window.confirmLogout = confirmLogout;
window.closeLogoutModal = closeLogoutModal;
window.executeLogout = executeLogout;
window.loadBuyerDashboardSummary = loadBuyerDashboardSummary;