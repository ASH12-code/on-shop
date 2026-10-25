/**
 * Logika Modul Pengaturan Toko (MySQL Read/Update dengan Toast Notification & Credentials)
 * File: assets/js/seller-js/pengaturan-toko.js
 */

const API_SETTINGS_URL = "../../api/seller/manage_shop_settings.php";
let currentLogoData = "";

// State untuk Manipulasi Gambar (Zoom & Drag)
let imgScale = 1;
let imgPosX = 0;
let imgPosY = 0;
let isDragging = false;
let startX = 0;
let startY = 0;
let originalImg = null;
let toastTimeout = null;

document.addEventListener("DOMContentLoaded", async () => {
  initCropDragEvents();
  await loadShopSettings();
});

/**
 * Menampilkan Toast Notification di bawah layar
 */
function showToast(message, type = "success") {
  const toast = document.getElementById("toastNotification");
  const msgEl = document.getElementById("toastMessage");
  if (!toast || !msgEl) return;

  msgEl.innerText = message;
  toast.className = `toast-box toast-show ${type === "success" ? "toast-success" : "toast-error"}`;

  clearTimeout(toastTimeout);
  toastTimeout = setTimeout(() => {
    toast.classList.remove("toast-show");
  }, 3000);
}

async function loadShopSettings() {
  try {
    const storedUser = JSON.parse(localStorage.getItem("currentUser")) || {};
    const authQuery = storedUser.user_id ? `&auth_user_id=${storedUser.user_id}` : "";

    // Menyertakan authQuery dan credentials: 'include'
    const res = await fetch(`${API_SETTINGS_URL}?action=get_settings${authQuery}`, {
      credentials: 'include'
    });
    const result = await res.json();

    if (!result.status) {
      showToast(result.message || "Gagal memuat profil toko", "error");
      return;
    }

    const s = result.data;
    document.getElementById("shopName").value        = s.shop_name || "";
    document.getElementById("shopDescription").value = s.description || "";
    const provEl = document.getElementById("pickupProvince");
    if (provEl) provEl.value = s.pickup_province || "Kepulauan Bangka Belitung";
    document.getElementById("pickupCity").value      = s.pickup_city || "";
    document.getElementById("pickupAddress").value   = s.pickup_address || "";
    
    currentLogoData = s.logo_url || "";

    const initial = (s.shop_name || "TO").substring(0, 2).toUpperCase();
    const sideName = document.getElementById("sidebarShopName");
    const sideInit = document.getElementById("sidebarShopInitial");
    if (sideName) sideName.innerText = s.shop_name;
    if (sideInit) sideInit.innerText = initial;
    
    renderLogoVisual(currentLogoData, initial);
  } catch (err) {
    console.error("Gagal memuat profil toko:", err);
    showToast("Terjadi gangguan koneksi ke server", "error");
  }
}

/**
 * Pemilihan Berkas Foto dari HP / Laptop
 */
function handleLogoFileSelect(event) {
  const file = event.target.files[0];
  if (!file) return;

  if (file.size > 2 * 1024 * 1024) {
    showToast("Ukuran file gambar maksimal 2MB!", "error");
    event.target.value = "";
    return;
  }

  const reader = new FileReader();
  reader.onload = function (e) {
    openCropModal(e.target.result);
  };
  reader.readAsDataURL(file);
}

/**
 * Buka Modal Crop & Zoom
 */
function openCropModal(imageSrc) {
  const cropImg = document.getElementById("rawCropImage");
  const modal = document.getElementById("cropModalOverlay");
  const slider = document.getElementById("zoomSlider");

  imgScale = 1;
  imgPosX = 0;
  imgPosY = 0;
  slider.value = 1;

  cropImg.onload = function () {
    const minSide = Math.min(cropImg.naturalWidth, cropImg.naturalHeight);
    const baseWidth = (cropImg.naturalWidth / minSide) * 200;
    const baseHeight = (cropImg.naturalHeight / minSide) * 200;

    cropImg.style.width = `${baseWidth}px`;
    cropImg.style.height = `${baseHeight}px`;

    originalImg = cropImg;
    updateImageTransform();
    modal.style.display = "flex";
  };
  cropImg.src = imageSrc;
}

function closeCropModal() {
  document.getElementById("cropModalOverlay").style.display = "none";
  const fileInput = document.getElementById("fileLogoInput");
  if (fileInput) fileInput.value = "";
}

function handleZoomChange(val) {
  imgScale = parseFloat(val);
  updateImageTransform();
}

function updateImageTransform() {
  const cropImg = document.getElementById("rawCropImage");
  if (!cropImg) return;
  cropImg.style.transform = `translate(${imgPosX}px, ${imgPosY}px) scale(${imgScale})`;
}

/**
 * Event Listener Menggeser Gambar (Mouse & Touch Screen)
 */
function initCropDragEvents() {
  const container = document.getElementById("cropContainer");
  if (!container) return;

  container.addEventListener("mousedown", (e) => {
    isDragging = true;
    startX = e.clientX - imgPosX;
    startY = e.clientY - imgPosY;
  });

  window.addEventListener("mousemove", (e) => {
    if (!isDragging) return;
    imgPosX = e.clientX - startX;
    imgPosY = e.clientY - startY;
    updateImageTransform();
  });

  window.addEventListener("mouseup", () => {
    isDragging = false;
  });

  container.addEventListener("touchstart", (e) => {
    if (e.touches.length === 1) {
      isDragging = true;
      startX = e.touches[0].clientX - imgPosX;
      startY = e.touches[0].clientY - imgPosY;
    }
  }, { passive: true });

  window.addEventListener("touchmove", (e) => {
    if (!isDragging || e.touches.length !== 1) return;
    imgPosX = e.touches[0].clientX - startX;
    imgPosY = e.touches[0].clientY - startY;
    updateImageTransform();
  }, { passive: true });

  window.addEventListener("touchend", () => {
    isDragging = false;
  });
}

/**
 * Potong dan Simpan Hasil Zoom/Posisi ke Kanvas Persegi
 */
function applyCroppedImage() {
  const cropImg = document.getElementById("rawCropImage");
  if (!cropImg) return;

  const canvas = document.createElement("canvas");
  const ctx = canvas.getContext("2d");
  const size = 300;
  canvas.width = size;
  canvas.height = size;

  const ratio = size / 200;
  const currentRenderWidth = parseFloat(cropImg.style.width) * imgScale;
  const currentRenderHeight = parseFloat(cropImg.style.height) * imgScale;

  const drawX = (size - currentRenderWidth * ratio) / 2 + (imgPosX * ratio);
  const drawY = (size - currentRenderHeight * ratio) / 2 + (imgPosY * ratio);

  ctx.drawImage(cropImg, drawX, drawY, currentRenderWidth * ratio, currentRenderHeight * ratio);

  currentLogoData = canvas.toDataURL("image/jpeg", 0.9);
  renderLogoVisual(currentLogoData, "");
  closeCropModal();
  showToast("Foto siap disimpan!");
}

function renderLogoVisual(imgUrl, fallbackInitial) {
  const box = document.getElementById("previewLogoBox");
  const sideLogo = document.getElementById("sidebarShopInitial");

  if (imgUrl && imgUrl.trim() !== "") {
    if (box) {
      box.style.backgroundImage = `url('${imgUrl}')`;
      box.innerText = "";
    }
    if (sideLogo) {
      sideLogo.style.backgroundImage = `url('${imgUrl}')`;
      sideLogo.innerText = "";
    }
  } else {
    if (box) {
      box.style.backgroundImage = "none";
      box.innerText = fallbackInitial || "TO";
    }
    if (sideLogo) {
      sideLogo.style.backgroundImage = "none";
      sideLogo.innerText = fallbackInitial || "TO";
    }
  }
}

/**
 * Simpan Data Lengkap ke Backend MySQL
 */
async function handleSaveSettings(e) {
  e.preventDefault();
  const btn = document.getElementById("btnSaveShop");
  if (btn) {
    btn.disabled = true;
    btn.innerText = "Menyimpan...";
  }

  const storedUser = JSON.parse(localStorage.getItem("currentUser")) || {};
  const authQuery = storedUser.user_id ? `?auth_user_id=${storedUser.user_id}` : "";

  const provEl = document.getElementById("pickupProvince");
  const payload = {
    shop_name:       document.getElementById("shopName").value.trim(),
    description:     document.getElementById("shopDescription").value.trim(),
    pickup_province: provEl ? provEl.value.trim() : "Kepulauan Bangka Belitung",
    pickup_city:     document.getElementById("pickupCity").value.trim(),
    pickup_address:  document.getElementById("pickupAddress").value.trim(),
    logo_url:        currentLogoData
  };

  try {
    const res = await fetch(`${API_SETTINGS_URL}?action=update_settings${authQuery.replace('?', '&')}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: 'include',
      body: JSON.stringify(payload)
    });
    const result = await res.json();

    if (result.status) {
      showToast(result.message || "Berhasil menyimpan profil baru!");
      await loadShopSettings();
    } else {
      showToast(result.message || "Gagal menyimpan perubahan.", "error");
    }
  } catch (err) {
    console.error("Gagal menyimpan:", err);
    showToast("Terjadi gangguan koneksi ke server.", "error");
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.innerText = "💾 Simpan Perubahan";
    }
  }
}