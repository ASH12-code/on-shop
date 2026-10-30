/**
 * Logika Riwayat Audit Log Super Admin ON Shop
 * File: assets/js/admin-js/audit-log.js
 */

const getAuditLogsApiUrl = () => {
  const isLocalhostSubdir = window.location.pathname.includes("/on-shop/");
  return isLocalhostSubdir
    ? "/on-shop/api/admin/get_audit_logs.php"
    : "../../api/admin/get_audit_logs.php";
};

let searchTimeout = null;

document.addEventListener("DOMContentLoaded", () => {
  fetchAuditLogs();
});

function handleAuditSearch(e) {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => {
    fetchAuditLogs();
  }, 350);
}

async function fetchAuditLogs() {
  const tbody = document.getElementById("auditTableBody");
  const counterEl = document.getElementById("auditTotalCounter");
  const actionFilter = document.getElementById("actionTypeFilterSelect")?.value || "ALL";
  const searchVal = document.getElementById("searchAuditInput")?.value.trim() || "";

  if (!tbody) return;
  tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:30px; color:#64748b;">Memuat riwayat log aktivitas...</td></tr>`;

  try {
    const params = new URLSearchParams({
      action_type: actionFilter,
      search: searchVal,
      limit: 100
    });

    const res = await fetch(`${getAuditLogsApiUrl()}?${params.toString()}`, {
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
      tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:30px; color:#dc2626;">Gagal memuat log audit.</td></tr>`;
      return;
    }

    const logs = result.data.logs || [];
    if (counterEl) {
      counterEl.innerText = `Menampilkan ${logs.length} dari ${result.data.total || logs.length} riwayat tindakan`;
    }

    if (logs.length === 0) {
      tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:40px; color:#64748b;">Belum ada catatan aktivitas audit yang sesuai kriteria pencarian.</td></tr>`;
      return;
    }

    renderAuditTableRows(logs);

  } catch (err) {
    console.error("Fetch audit logs error:", err);
    tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:30px; color:#dc2626;">Terjadi kendala koneksi saat memuat audit log.</td></tr>`;
  }
}

function renderAuditTableRows(logs) {
  const tbody = document.getElementById("auditTableBody");
  if (!tbody) return;
  tbody.innerHTML = "";

  logs.forEach(log => {
    const tr = document.createElement("tr");

    let badgeClass = "badge-default";
    let badgeText = log.action_type;

    if (log.action_type === "UPDATE_PLATFORM_SETTING") {
      badgeClass = "badge-setting";
      badgeText = "Pengaturan";
    } else if (log.action_type === "RESOLVE_DISPUTE") {
      badgeClass = "badge-dispute";
      badgeText = "Mediasi Sengketa";
    } else if (log.action_type === "SUSPEND_SHOP") {
      badgeClass = "badge-shop-suspend";
      badgeText = "Toko Ditangguhkan";
    } else if (log.action_type === "ACTIVATE_SHOP") {
      badgeClass = "badge-shop-active";
      badgeText = "Toko Diaktifkan";
    } else if (log.action_type === "DELETE_PRODUCT") {
      badgeClass = "badge-shop-suspend";
      badgeText = "Hapus Produk";
    }

    const formattedDate = log.created_at ? log.created_at.replace("T", " ") : "-";

    tr.innerHTML = `
      <td><strong>#${log.log_id}</strong></td>
      <td style="color: #64748b; font-size: 12px; white-space: nowrap;">${formattedDate}</td>
      <td>
        <strong style="color: #0f172a; display: block;">${escapeHtml(log.admin_name)}</strong>
        <small style="color: #64748b; font-size: 11.5px;">${escapeHtml(log.admin_email)}</small>
      </td>
      <td>
        <span class="action-badge ${badgeClass}">${badgeText}</span>
      </td>
      <td style="line-height: 1.45;">
        ${escapeHtml(log.description)}
      </td>
      <td>
        <span class="ip-tag">${escapeHtml(log.ip_address || "127.0.0.1")}</span>
      </td>
    `;

    tbody.appendChild(tr);
  });
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

function escapeHtml(text) {
  if (!text) return "";
  const map = { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" };
  return String(text).replace(/[&<>"']/g, (m) => map[m]);
}

window.fetchAuditLogs = fetchAuditLogs;
window.handleAuditSearch = handleAuditSearch;
