/**
 * Logika Pengaturan Platform Super Admin ON Shop
 * File: assets/js/admin-js/pengaturan-platform.js
 */

const getSettingsApiUrl = () => {
  const isLocalhostSubdir = window.location.pathname.includes("/on-shop/");
  return isLocalhostSubdir
    ? "/on-shop/api/admin/manage_platform_settings.php"
    : "../../api/admin/manage_platform_settings.php";
};

document.addEventListener("DOMContentLoaded", () => {
  fetchPlatformSettings();
});

async function fetchPlatformSettings() {
  try {
    const res = await fetch(getSettingsApiUrl(), {
      method: "GET",
      headers: { "Accept": "application/json" },
      credentials: "include"
    });

    if (res.status === 401 || res.status === 403) {
      showAdminToast("Akses ditolak. Sesi admin tidak valid.", "error");
      setTimeout(() => { window.location.href = "../buyer/login.html"; }, 1200);
      return;
    }

    const result = await res.json();
    if (!result.status || !result.data) {
      showAdminToast(result.message || "Gagal memuat pengaturan.", "error");
      return;
    }

    const map = result.data.map || {};
    populateSettingsForm(map);

    const lastUpdatedEl = document.getElementById("lastUpdatedText");
    if (lastUpdatedEl) {
      const now = new Date().toLocaleTimeString("id-ID");
      lastUpdatedEl.innerText = `Terakhir disinkronkan: ${now} WIB`;
    }

  } catch (err) {
    console.error("Fetch settings error:", err);
    showAdminToast("Gagal menyambung ke server pengaturan.", "error");
  }
}

function populateSettingsForm(map) {
  const commission = document.getElementById("commissionInput");
  const handling   = document.getElementById("handlingFeeInput");
  const email      = document.getElementById("supportEmailInput");
  const phone      = document.getElementById("supportPhoneInput");
  const status     = document.getElementById("marketplaceStatusSelect");

  if (commission) commission.value = map.seller_commission_percentage ?? "5.0";
  if (handling)   handling.value   = map.handling_fee_fixed ?? "1000";
  if (email)      email.value      = map.platform_support_email ?? "support@onshop.id";
  if (phone)      phone.value      = map.platform_support_phone ?? "0812-3456-7890";
  if (status)     status.value     = map.marketplace_status ?? "ACTIVE";
}

async function handleSavePlatformSettings(e) {
  if (e) e.preventDefault();

  const commission = document.getElementById("commissionInput")?.value;
  const handling   = document.getElementById("handlingFeeInput")?.value;
  const email      = document.getElementById("supportEmailInput")?.value;
  const phone      = document.getElementById("supportPhoneInput")?.value;
  const status     = document.getElementById("marketplaceStatusSelect")?.value;

  const btn = document.getElementById("btnSaveSettings");
  if (btn) {
    btn.disabled = true;
    btn.innerText = "⏳ Menyimpan...";
  }

  try {
    const payload = {
      settings: {
        seller_commission_percentage: commission,
        handling_fee_fixed: handling,
        platform_support_email: email,
        platform_support_phone: phone,
        marketplace_status: status
      }
    };

    const res = await fetch(getSettingsApiUrl(), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "include",
      body: JSON.stringify(payload)
    });

    const result = await res.json();
    if (result.status) {
      showAdminToast("Pengaturan platform berhasil disimpan dan dicatat ke Audit Log!", "success");
      const lastUpdatedEl = document.getElementById("lastUpdatedText");
      if (lastUpdatedEl) {
        lastUpdatedEl.innerText = `Terakhir diperbarui: Baru saja`;
      }
    } else {
      showAdminToast(result.message || "Gagal menyimpan pengaturan.", "error");
    }

  } catch (err) {
    console.error("Save settings error:", err);
    showAdminToast("Terjadi kendala saat menyimpan pengaturan.", "error");
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.innerText = "💾 Simpan Perubahan Pengaturan";
    }
  }
}

function showAdminToast(msg, type = "success") {
  const box = document.getElementById("toastBox");
  const text = document.getElementById("toastMessage");
  if (!box || !text) return;

  text.innerText = msg;
  box.className = `toast-box show ${type === "error" ? "toast-error" : ""}`;

  setTimeout(() => {
    box.classList.remove("show");
  }, 3500);
}

window.handleSavePlatformSettings = handleSavePlatformSettings;
