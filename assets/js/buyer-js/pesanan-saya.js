/**
 * Logika Riwayat & Pemantauan Pesanan (Opsi A: Tab Pengembalian, Drag Scroll & Bukti Gambar Sengketa)
 * File: assets/js/buyer-js/pesanan-saya.js
 */

let currentUser = JSON.parse(localStorage.getItem("currentUser")) || null;
let allOrders = [];

// State Tab Aktif
let currentActiveFilter = "unpaid";
let currentHistoryFilter = "selesai";
let isHistoryMode = false;

let selectedReviewTarget = null;
let selectedReturnTarget = null;
let pendingCancelSubOrderId = null;

// State Gambar Ulasan Produk
let tempReviewImageFile = null;
let reviewImgNaturalWidth = 0;
let reviewImgNaturalHeight = 0;
let currentImageScale = 1.0;
let currentImageOffsetX = 0;
let currentImageOffsetY = 0;

// State Gambar Bukti Sengketa/Retur
let tempReturnImageFile = null;
let returnImgNaturalWidth = 0;
let returnImgNaturalHeight = 0;
let returnImageScale = 1.0;
let returnImageOffsetX = 0;
let returnImageOffsetY = 0;

function handleOrderBackNav() {
  const ref = document.referrer || "";
  if (
    ref.includes("pembayaran-qris.html") ||
    ref.includes("pembayaran-va.html") ||
    ref.includes("checkout.html") ||
    ref.includes("login.html") ||
    !ref
  ) {
    window.location.href = "index.html";
    return;
  }
  window.location.href = ref;
}
window.handleOrderBackNav = handleOrderBackNav;

document.addEventListener("DOMContentLoaded", () => {
  initNavbarSearchEvents();
  initStatusTabHorizontalScroll();

  if (!currentUser || !currentUser.user_id) {
    if (typeof showToast === "function") {
      showToast("Silakan masuk terlebih dahulu untuk melihat pesanan!", "warning");
    }
    setTimeout(() => {
      window.location.href = "login.html?redirect=pesanan-saya.html";
    }, 1200);
    return;
  }

  handleUrlTabParameter();
  loadOrdersFromDatabase();
});

// Fitur Mouse Drag & Wheel Scroll Tab Horizontal
function initStatusTabHorizontalScroll() {
  const slider = document.querySelector(".status-tab-scroll-bar");
  if (!slider) return;

  let isDown = false;
  let startX;
  let scrollLeft;

  slider.addEventListener("mousedown", (e) => {
    isDown = true;
    slider.classList.add("active-grab");
    startX = e.pageX - slider.offsetLeft;
    scrollLeft = slider.scrollLeft;
  });

  slider.addEventListener("mouseleave", () => {
    isDown = false;
  });

  slider.addEventListener("mouseup", () => {
    isDown = false;
  });

  slider.addEventListener("mousemove", (e) => {
    if (!isDown) return;
    e.preventDefault();
    const x = e.pageX - slider.offsetLeft;
    const walk = (x - startX) * 1.5;
    slider.scrollLeft = scrollLeft - walk;
  });

  slider.addEventListener("wheel", (e) => {
    if (e.deltaY !== 0) {
      e.preventDefault();
      slider.scrollLeft += e.deltaY;
    }
  }, { passive: false });
}

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

function handleUrlTabParameter() {
  const urlParams = new URLSearchParams(window.location.search);
  const tabParam = urlParams.get("tab");

  if (tabParam === "dikemas") currentActiveFilter = "packed";
  else if (tabParam === "belum_bayar") currentActiveFilter = "unpaid";
  else if (tabParam === "dikirim") currentActiveFilter = "shipped";
  else if (tabParam === "sampai") currentActiveFilter = "arrived";
  else if (tabParam === "pengembalian") currentActiveFilter = "dispute";
  else if (tabParam === "penilaian") currentActiveFilter = "review";
}

function syncActiveTabButtonUI() {
  const buttons = document.querySelectorAll(".status-tab-pill");
  buttons.forEach((pill) => pill.classList.remove("active"));

  const filterMap = {
    unpaid: 0,
    packed: 1,
    shipped: 2,
    arrived: 3,
    dispute: 4,
    review: 5
  };

  const idx = filterMap[currentActiveFilter];
  if (idx !== undefined && buttons[idx]) {
    buttons[idx].classList.add("active");
  }
}

async function loadOrdersFromDatabase() {
  const container = document.getElementById("orderFeedContainer");
  if (!currentUser) return;

  try {
    const res = await fetch(`/on-shop/api/buyer/orders.php?action=list&user_id=${currentUser.user_id}`);
    const result = await res.json();

    if (result.status) {
      allOrders = result.data || [];
      updateBadgeCounters();
      syncActiveTabButtonUI();
      renderCurrentView();

      if (typeof window.checkBuyerDisputeAlerts === "function") {
        window.checkBuyerDisputeAlerts();
      }
    } else {
      if (container) {
        container.innerHTML = `<div class="empty-order-state"><p>${result.message || "Gagal memuat pesanan."}</p></div>`;
      }
    }
  } catch (err) {
    console.error("Koneksi gagal saat memuat pesanan:", err);
    if (container) {
      container.innerHTML = `<div class="empty-order-state"><p>Gagal menghubungkan ke server pesanan.</p></div>`;
    }
  }
}

function updateBadgeCounters() {
  const unpaidOrders = allOrders.filter((o) => o.order_status === "MENUNGGU_BAYAR" || o.order_status === "MENUNGGU_VERIFIKASI");
  const packedOrders = allOrders.filter((o) => o.order_status === "DIKEMAS");
  const shippedOrders = allOrders.filter((o) => o.order_status === "DIKIRIM" && !o.delivered_at);
  const arrivedOrders = allOrders.filter((o) => o.order_status === "DIKIRIM" && Boolean(o.delivered_at));
  const disputeOrders = allOrders.filter((o) => o.order_status === "DIKOMPLAIN");
  const reviewOrders = allOrders.filter((o) => o.order_status === "SELESAI" && o.items && o.items.some((it) => !it.review_id));

  const selesaiOrders = allOrders.filter((o) => o.order_status === "SELESAI");
  const returOrders = allOrders.filter(
    (o) => o.order_status === "DIKOMPLAIN" || (o.dispute && o.dispute.dispute_status === "REFUNDED")
  );
  const batalOrders = allOrders.filter((o) => o.order_status === "BATAL");

  const unpaidEl = document.getElementById("unpaidCountText");
  const packedEl = document.getElementById("packedCountText");
  const shippedEl = document.getElementById("shippedCountText");
  const arrivedEl = document.getElementById("arrivedCountText");
  const returnEl = document.getElementById("returnCountText");
  const reviewEl = document.getElementById("reviewCountText");

  if (unpaidEl) unpaidEl.innerText = unpaidOrders.length;
  if (packedEl) packedEl.innerText = packedOrders.length;
  if (shippedEl) shippedEl.innerText = shippedOrders.length;
  if (arrivedEl) arrivedEl.innerText = arrivedOrders.length;
  if (returnEl) {
    returnEl.innerText = disputeOrders.length;
    if (disputeOrders.length > 0) {
      returnEl.classList.add("has-alert");
    } else {
      returnEl.classList.remove("has-alert");
    }
  }
  if (reviewEl) reviewEl.innerText = reviewOrders.length;

  const countSelesaiEl = document.getElementById("countHistorySelesai");
  const countReturEl = document.getElementById("countHistoryRetur");
  const countBatalEl = document.getElementById("countHistoryBatal");
  const btnToggleHistory = document.getElementById("btnToggleHistory");

  if (countSelesaiEl) countSelesaiEl.innerText = selesaiOrders.length;
  if (countBatalEl) countBatalEl.innerText = batalOrders.length;

  // Indikator Notifikasi Merah untuk Riwayat Pengembalian
  if (countReturEl) {
    countReturEl.innerText = returOrders.length;
    const readDisputeIds = JSON.parse(localStorage.getItem(`read_disputes_${currentUser.user_id}`)) || [];
    const hasUnread = returOrders.some(
      (o) => o.dispute && o.dispute.dispute_id && !readDisputeIds.includes(parseInt(o.dispute.dispute_id, 10))
    );

    if (hasUnread) {
      countReturEl.style.backgroundColor = "#ef4444";
      countReturEl.style.color = "#ffffff";
      countReturEl.style.padding = "2px 6px";
      countReturEl.style.borderRadius = "8px";

      if (btnToggleHistory && !btnToggleHistory.querySelector(".history-btn-alert-dot")) {
        btnToggleHistory.style.position = "relative";
        const dot = document.createElement("span");
        dot.className = "history-btn-alert-dot";
        dot.style.cssText =
          "position: absolute; top: -3px; right: -3px; width: 9px; height: 9px; background-color: #ef4444; border-radius: 50%; border: 1.5px solid #ffffff; box-shadow: 0 0 4px rgba(239, 68, 68, 0.8); pointer-events: none;";
        btnToggleHistory.appendChild(dot);
      }
    } else {
      countReturEl.style.backgroundColor = "";
      countReturEl.style.color = "";
      countReturEl.style.padding = "";
      countReturEl.style.borderRadius = "";

      if (btnToggleHistory) {
        const dot = btnToggleHistory.querySelector(".history-btn-alert-dot");
        if (dot) dot.remove();
      }
    }
  }
}

function switchOrderStatus(statusKey, element) {
  currentActiveFilter = statusKey;

  document.querySelectorAll(".status-tab-pill").forEach((pill) => {
    pill.classList.remove("active");
  });

  if (element) {
    element.classList.add("active");
  }

  renderCurrentView();
}

function switchHistoryTab(tabKey, element) {
  currentHistoryFilter = tabKey;

  document.querySelectorAll(".history-tab-btn").forEach((btn) => {
    btn.classList.remove("active");
  });

  if (element) {
    element.classList.add("active");
  }

  // Bersihkan notifikasi merah saat sub-tab pengembalian dibuka
  if (tabKey === "pengembalian" && currentUser) {
    const disputeIds = allOrders
      .filter((o) => o.dispute && o.dispute.dispute_id)
      .map((o) => parseInt(o.dispute.dispute_id, 10));

    localStorage.setItem(`read_disputes_${currentUser.user_id}`, JSON.stringify(disputeIds));

    const countReturEl = document.getElementById("countHistoryRetur");
    if (countReturEl) {
      countReturEl.style.backgroundColor = "";
      countReturEl.style.color = "";
      countReturEl.style.padding = "";
      countReturEl.style.borderRadius = "";
    }

    const btnToggleHistory = document.getElementById("btnToggleHistory");
    if (btnToggleHistory) {
      const dot = btnToggleHistory.querySelector(".history-btn-alert-dot");
      if (dot) dot.remove();
    }

    if (typeof window.checkBuyerDisputeAlerts === "function") {
      window.checkBuyerDisputeAlerts();
    }
  }

  renderCurrentView();
}

function toggleHistoryView() {
  isHistoryMode = !isHistoryMode;
  const activeSection = document.getElementById("activeOrdersSection");
  const historySection = document.getElementById("historySection");
  const btnToggle = document.getElementById("btnToggleHistory");
  const mainTitle = document.getElementById("mainPageTitle");

  if (isHistoryMode) {
    if (activeSection) activeSection.classList.add("hidden");
    if (historySection) historySection.classList.remove("hidden");
    if (btnToggle) btnToggle.innerText = "📦 Kembali ke Pesanan Aktif";
    if (mainTitle) mainTitle.innerText = "Riwayat Transaksi";
  } else {
    if (activeSection) activeSection.classList.remove("hidden");
    if (historySection) historySection.classList.add("hidden");
    if (btnToggle) btnToggle.innerText = "📜 Riwayat Transaksi";
    if (mainTitle) mainTitle.innerText = "Pesanan Saya";
  }

  renderCurrentView();
}

function renderCurrentView() {
  if (isHistoryMode) {
    renderHistoryFeed();
  } else {
    renderActiveFeed();
  }
}

function renderActiveFeed() {
  const container = document.getElementById("orderFeedContainer");
  if (!container) return;
  container.innerHTML = "";

  let filtered = [];

  if (currentActiveFilter === "unpaid") {
    filtered = allOrders.filter((o) => o.order_status === "MENUNGGU_BAYAR" || o.order_status === "MENUNGGU_VERIFIKASI");
  } else if (currentActiveFilter === "packed") {
    filtered = allOrders.filter((o) => o.order_status === "DIKEMAS");
  } else if (currentActiveFilter === "shipped") {
    filtered = allOrders.filter((o) => o.order_status === "DIKIRIM" && !o.delivered_at);
  } else if (currentActiveFilter === "arrived") {
    filtered = allOrders.filter((o) => o.order_status === "DIKIRIM" && Boolean(o.delivered_at));
  } else if (currentActiveFilter === "dispute") {
    filtered = allOrders.filter((o) => o.order_status === "DIKOMPLAIN");
  } else if (currentActiveFilter === "review") {
    filtered = allOrders.filter((o) => o.order_status === "SELESAI" && o.items && o.items.some((it) => !it.review_id));
  }

  if (filtered.length === 0) {
    container.innerHTML = `<div class="empty-order-state"><p>Tidak ada pesanan pada status ini.</p></div>`;
    return;
  }

  filtered.forEach((order) => {
    container.appendChild(createOrderCardElement(order, false));
  });
}

function renderHistoryFeed() {
  const container = document.getElementById("historyFeedContainer");
  if (!container) return;
  container.innerHTML = "";

  let filtered = [];

  if (currentHistoryFilter === "selesai") {
    filtered = allOrders.filter((o) => o.order_status === "SELESAI");
  } else if (currentHistoryFilter === "pengembalian") {
    filtered = allOrders.filter(
      (o) => o.order_status === "DIKOMPLAIN" || (o.dispute && o.dispute.dispute_status === "REFUNDED")
    );
  } else if (currentHistoryFilter === "dibatalkan") {
    filtered = allOrders.filter((o) => o.order_status === "BATAL");
  }

  if (filtered.length === 0) {
    container.innerHTML = `<div class="empty-order-state"><p>Belum ada riwayat pesanan.</p></div>`;
    return;
  }

  filtered.forEach((order) => {
    container.appendChild(createOrderCardElement(order, true));
  });
}

function createOrderCardElement(order, isHistory) {
  const card = document.createElement("div");
  card.className = "order-item-card";

  const totalOrderPrice = parseFloat(order.gross_product_amount || 0) + parseFloat(order.shipping_cost || 0);

  let statusBadgeText = order.order_status;
  if (order.order_status === "MENUNGGU_VERIFIKASI") {
    statusBadgeText = "Menunggu Verifikasi Penjual";
  } else if (order.order_status === "MENUNGGU_BAYAR") {
    statusBadgeText = (order.payment_status === "REJECTED") ? "Bukti Ditolak Penjual" : "Menunggu Pembayaran";
  } else if (order.order_status === "DIKEMAS") {
    statusBadgeText = "Sedang Dikemas (Saldo Tertahan)";
  } else if (order.order_status === "DIKIRIM") {
    statusBadgeText = order.delivered_at ? "Paket Telah Tiba di Lokasi" : "Dalam Pengiriman Kurir";
  } else if (order.order_status === "SELESAI") {
    statusBadgeText = "Selesai (Saldo Siap Tarik)";
  } else if (order.order_status === "DIKOMPLAIN") {
    statusBadgeText = "Dalam Pengajuan Retur";
  } else if (order.order_status === "BATAL") {
    statusBadgeText = order.dispute && order.dispute.dispute_status === "REFUNDED" ? "Dana Dikembalikan" : "Dibatalkan";
  }

  let itemsHtml = "";
  if (order.items && order.items.length > 0) {
    order.items.forEach((item) => {
      const reviewImgBadge = item.review_image_url
        ? `<span style="font-size: 11px; color: #2e8b57; display: block; margin-top: 2px;">📷 Ada Foto Ulasan</span>`
        : "";
      itemsHtml += `
        <div class="order-product-row">
          <div class="order-thumb-box">
            <img src="${item.product_image_url}" alt="${escapeHtml(item.product_name)}" class="order-thumb" onerror="this.src='https://placehold.co/70x70?text=Produk'" />
          </div>
          <div class="order-info-box">
            <div class="order-prod-title">${escapeHtml(item.product_name)}</div>
            <span class="order-prod-variant">Varian: ${escapeHtml(item.variant_name || "Standar")}</span>
            <span class="order-qty">x${item.quantity}</span>
            ${reviewImgBadge}
          </div>
          <div class="order-price-box">
            <span class="order-price">Rp ${parseFloat(item.total_price || 0).toLocaleString("id-ID")}</span>
          </div>
        </div>
      `;
    });
  }

  let disputeAlertHtml = "";
  let rejectionAlertHtml = "";
  let actionButtonsHtml = "";

  if (order.payment_status === "REJECTED" && order.rejection_reason) {
    rejectionAlertHtml = `
      <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 10px 14px; margin: 10px 16px; font-size: 12px; color: #991b1b; line-height: 1.5;">
        <strong style="display:block; margin-bottom: 2px;">⚠️ Catatan Penolakan dari Penjual:</strong>
        <span>${escapeHtml(order.rejection_reason)}</span>
      </div>
    `;
  }

  if (order.order_status === "MENUNGGU_VERIFIKASI") {
    let paymentDetail = "";
    if (order.payment_method === "QRIS") {
      paymentDetail = `<span style="font-size: 11.5px; color: #2e8b57; font-weight: bold; margin-right: 6px;">QRIS</span>`;
    } else if (order.va_number) {
      paymentDetail = `<span style="font-size: 11.5px; color: #2e8b57; font-weight: bold; margin-right: 6px;">VA: ${order.va_number}</span>`;
    }

    actionButtonsHtml = `
      ${paymentDetail}
      <span style="font-size: 11.5px; color: #8b5cf6; font-weight: 600; margin-right: 8px;">⏳ Bukti sedang diperiksa penjual</span>
      <button type="button" class="btn-action-outline" onclick="openReuploadModal(${order.sub_order_id})">Unggah Ulang Bukti</button>
    `;
  } else if (order.order_status === "MENUNGGU_BAYAR") {
    let paymentDetail = "";
    if (order.payment_method === "QRIS") {
      paymentDetail = `<span style="font-size: 12px; color: #2e8b57; font-weight: bold; margin-right: 8px;">Scan QRIS (${order.va_number || "-"})</span>`;
    } else if (order.va_number) {
      paymentDetail = `<span style="font-size: 12px; color: #2e8b57; font-weight: bold; margin-right: 8px;">No. VA: ${order.va_number}</span>`;
    }

    if (order.payment_status === "REJECTED") {
      actionButtonsHtml = `
        ${paymentDetail}
        <button type="button" class="btn-action-cancel" onclick="cancelBuyerOrder(${order.sub_order_id})">Batalkan</button>
        <button type="button" class="btn-action-primary" style="background: #ea580c;" onclick="openReuploadModal(${order.sub_order_id})">Unggah Ulang Bukti</button>
      `;
    } else {
      actionButtonsHtml = `
        ${paymentDetail}
        <button type="button" class="btn-action-cancel" onclick="cancelBuyerOrder(${order.sub_order_id})">Batalkan</button>
        <button type="button" class="btn-action-primary" onclick="goToPaymentPage(${order.sub_order_id})">Bayar / Unggah Bukti</button>
      `;
    }
  } else if (order.order_status === "DIKEMAS") {
    actionButtonsHtml = `
      <span style="font-size: 11.5px; color: #64748b; margin-right: 8px;">Saldo tersimpan di Saldo Tertahan Toko</span>
      <button type="button" class="btn-action-cancel" onclick="cancelBuyerOrder(${order.sub_order_id})">Batalkan</button>
      <button type="button" class="btn-action-outline" style="border: 1.5px dashed #ea580c; color: #ea580c; font-weight: bold;" onclick="simulateSellerShipOrder(${order.sub_order_id})">
        ⚡ Simulasi Seller Kirim (Resi JNE)
      </button>
    `;
  } else if (order.order_status === "DIKIRIM" && !order.delivered_at) {
    actionButtonsHtml = `
      <span style="font-size: 12px; color: #555; margin-right: 10px;">
        Resi: <strong>${order.tracking_number || "JNE-REG"}</strong>
      </span>
      <button type="button" class="btn-action-outline" style="background:#f0f9ff; color:#0284c7; border: 1.5px solid #0284c7; font-weight: 700;" onclick="simulateCourierDeliver(${order.sub_order_id})">
        🚚 Kurir Antar (Sampai Tujuan)
      </button>
    `;
  } else if (order.order_status === "DIKIRIM" && Boolean(order.delivered_at)) {
    actionButtonsHtml = `
      <span style="font-size: 11.5px; color: #16a34a; font-weight: bold; margin-right: 8px;">
        ✓ Paket tiba di alamat tujuan
      </span>
      <button type="button" class="btn-action-outline" onclick="openReturnModal(${order.sub_order_id}, '${escapeHtml(order.items[0]?.product_name || "Pesanan")}')">
        Ajukan Pengembalian
      </button>
      <button type="button" class="btn-action-outline" style="font-size: 11.5px; padding: 6px 12px; border: 1px dashed #64748b;" onclick="confirmOrderReceived(${order.sub_order_id})">
        ⏩ Lewati 3 Hari
      </button>
      <button type="button" class="btn-action-primary" onclick="confirmOrderReceived(${order.sub_order_id})">
        Barang Diterima
      </button>
    `;
  } else if (order.order_status === "DIKOMPLAIN") {
    const d = order.dispute;
    const isApprovedByAdmin = d && (d.dispute_status === "IN_REVIEW" || d.dispute_status === "REFUNDED" || Boolean(d.admin_notes));
    const disputeImgHtml = d && d.dispute_image_url
      ? `<div style="margin-top: 6px;"><a href="${d.dispute_image_url}" target="_blank" style="font-size: 11.5px; color: #0284c7; text-decoration: underline;">📷 Lihat Bukti Foto Terlampir</a></div>`
      : "";

    if (isApprovedByAdmin) {
      disputeAlertHtml = `
        <div class="dispute-verdict-box">
          <strong>⚖️ Keputusan Sidang Admin:</strong> ${escapeHtml(d.admin_notes || "Pengembalian disetujui. Silakan serahkan paket retur ke counter JNE terdekat.")}
          ${disputeImgHtml}
        </div>
      `;
    } else {
      disputeAlertHtml = `
        <div class="dispute-box-alert">
          <strong>⏳ Status Pengajuan:</strong> Menunggu peninjauan sidang mediasi oleh Admin ON Shop.
          ${disputeImgHtml}
        </div>
      `;
    }

    actionButtonsHtml = `
      <button type="button" class="btn-action-jne" onclick="simulateDropToJne(${order.sub_order_id})">
        🚚 Serahkan Paket ke JNE (Simulasi Selesai & Refund)
      </button>
    `;
  } else if (order.order_status === "SELESAI") {
    const unratedItem = order.items && order.items.find((it) => !it.review_id);
    if (unratedItem) {
      actionButtonsHtml = `
        <button type="button" class="btn-action-primary" onclick="openRatingModal(${order.sub_order_id}, ${unratedItem.product_id}, '${escapeHtml(unratedItem.product_name)}')">
          ⭐ Beri Penilaian
        </button>
      `;
    } else {
      actionButtonsHtml = `<span style="font-size: 12px; color: #2e8b57; font-weight: bold;">✔ Ulasan Terkirim</span>`;
    }
  } else if (order.order_status === "BATAL") {
    if (order.dispute && order.dispute.dispute_status === "REFUNDED") {
      actionButtonsHtml = `<span style="font-size: 12px; color: #16a34a; font-weight: bold;">✔ Dana Berhasil Dikembalikan (Refund Selesai)</span>`;
    } else {
      actionButtonsHtml = `<span style="font-size: 12px; color: #ef4444; font-weight: bold;">Pesanan Dibatalkan</span>`;
    }
  }

  card.innerHTML = `
    <div class="order-card-header">
      <div>
        <span class="order-shop-name">🏪 ${escapeHtml(order.shop_name)}</span>
        <span style="font-size: 11px; color: #888; margin-left: 8px;">(${order.sub_order_number})</span>
      </div>
      <span class="order-status-tag">${statusBadgeText}</span>
    </div>
    <div class="order-items-wrapper">${itemsHtml}</div>
    ${rejectionAlertHtml}
    ${disputeAlertHtml}
    <div class="order-card-footer">
      <div class="order-total-sum">
        Total Pesanan: <strong>Rp ${totalOrderPrice.toLocaleString("id-ID")}</strong>
      </div>
      <div class="order-action-buttons">${actionButtonsHtml}</div>
    </div>
  `;

  return card;
}

// -------------------------------------------------------------
// AKSI UNGGAH ULANG BUKTI PEMBAYARAN & SINKRONISASI
// -------------------------------------------------------------
let selectedReuploadFile = null;
let activeReuploadSubOrder = null;

function goToPaymentPage(subOrderId) {
  const order = allOrders.find((o) => o.sub_order_id === subOrderId);
  if (!order) return;

  sessionStorage.setItem("activeOrderSession", JSON.stringify(order));
  const method = (order.payment_method || "").toUpperCase();
  if (method === "QRIS") {
    window.location.href = "pembayaran-qris.html";
  } else if (method.startsWith("VA_")) {
    const bank = method.replace("VA_", "");
    window.location.href = `pembayaran-va.html?bank=${bank}`;
  } else {
    openReuploadModal(subOrderId);
  }
}

function openReuploadModal(subOrderId) {
  const order = allOrders.find((o) => o.sub_order_id === subOrderId);
  if (!order) return;

  activeReuploadSubOrder = order;
  selectedReuploadFile = null;

  const invText = document.getElementById("reuploadOrderInvText");
  if (invText) {
    invText.innerText = `#${order.sub_order_number} • Total: Rp ${parseFloat(order.grand_total || order.gross_product_amount || 0).toLocaleString("id-ID")}`;
  }

  const banner = document.getElementById("rejectionReasonBanner");
  const reasonText = document.getElementById("rejectionReasonText");
  if (order.rejection_reason && order.payment_status === "REJECTED") {
    if (banner) banner.style.display = "block";
    if (reasonText) reasonText.innerText = order.rejection_reason;
  } else {
    if (banner) banner.style.display = "none";
  }

  removeReuploadFile();

  const modal = document.getElementById("reuploadProofModal");
  if (modal) modal.classList.remove("hidden");
}

function closeReuploadModal() {
  const modal = document.getElementById("reuploadProofModal");
  if (modal) modal.classList.add("hidden");
  activeReuploadSubOrder = null;
  selectedReuploadFile = null;
}

function handleReuploadFileSelect(event) {
  const file = event.target.files && event.target.files[0];
  if (!file) return;

  const maxBytes = 2 * 1024 * 1024;
  if (file.size > maxBytes) {
    if (typeof showToast === "function") showToast("Ukuran file maksimal 2 MB", "error");
    else alert("Ukuran file maksimal 2 MB");
    event.target.value = "";
    return;
  }

  const allowedTypes = ["image/jpeg", "image/png", "image/webp", "image/jpg"];
  if (!allowedTypes.includes(file.type)) {
    if (typeof showToast === "function") showToast("Format file harus berupa gambar (JPG, PNG, WEBP)", "error");
    else alert("Format file harus berupa gambar (JPG, PNG, WEBP)");
    event.target.value = "";
    return;
  }

  selectedReuploadFile = file;

  const previewBox = document.getElementById("reuploadPreviewBox");
  const previewImg = document.getElementById("reuploadPreviewImg");
  const fileNameText = document.getElementById("reuploadFileNameText");
  const dropzone = document.getElementById("reuploadDropzone");
  const btnSubmit = document.getElementById("btnSubmitReupload");

  const reader = new FileReader();
  reader.onload = (e) => {
    if (previewImg) previewImg.src = e.target.result;
    if (fileNameText) fileNameText.innerText = `${file.name} (${(file.size / 1024).toFixed(1)} KB)`;
    if (previewBox) previewBox.style.display = "block";
    if (dropzone) dropzone.style.display = "none";
    if (btnSubmit) btnSubmit.disabled = false;
  };
  reader.readAsDataURL(file);
}

function removeReuploadFile() {
  selectedReuploadFile = null;
  const fileInput = document.getElementById("reuploadFileInput");
  if (fileInput) fileInput.value = "";

  const previewBox = document.getElementById("reuploadPreviewBox");
  const dropzone = document.getElementById("reuploadDropzone");
  const btnSubmit = document.getElementById("btnSubmitReupload");

  if (previewBox) previewBox.style.display = "none";
  if (dropzone) dropzone.style.display = "block";
  if (btnSubmit) btnSubmit.disabled = true;
}

async function submitReuploadProof() {
  if (!activeReuploadSubOrder || !selectedReuploadFile) {
    if (typeof showToast === "function") showToast("Silakan pilih file bukti transfer terlebih dahulu", "warning");
    return;
  }

  const btnSubmit = document.getElementById("btnSubmitReupload");
  if (btnSubmit) {
    btnSubmit.disabled = true;
    btnSubmit.innerText = "Mengirim...";
  }

  const formData = new FormData();
  formData.append("master_order_id", activeReuploadSubOrder.master_order_id || 0);
  formData.append("sub_order_id", activeReuploadSubOrder.sub_order_id || 0);
  formData.append("proof_image", selectedReuploadFile);

  try {
    const res = await fetch("/on-shop/api/buyer/upload_payment_proof.php", {
      method: "POST",
      body: formData
    });
    const result = await res.json();

    if (result.status) {
      closeReuploadModal();
      if (typeof showToast === "function") {
        showToast("Bukti pembayaran berhasil dikirim! Menunggu verifikasi penjual.", "success");
      }
      await loadOrdersFromDatabase();
    } else {
      if (typeof showToast === "function") {
        showToast(result.message || "Gagal mengirim bukti pembayaran.", "error");
      } else {
        alert(result.message || "Gagal mengirim bukti pembayaran.");
      }
      if (btnSubmit) {
        btnSubmit.disabled = false;
        btnSubmit.innerText = "Kirim Bukti";
      }
    }
  } catch (err) {
    console.error("Gagal mengirim bukti pembayaran:", err);
    if (typeof showToast === "function") {
      showToast("Terjadi gangguan koneksi", "error");
    }
    if (btnSubmit) {
      btnSubmit.disabled = false;
      btnSubmit.innerText = "Kirim Bukti";
    }
  }
}

window.goToPaymentPage = goToPaymentPage;
window.openReuploadModal = openReuploadModal;
window.closeReuploadModal = closeReuploadModal;
window.handleReuploadFileSelect = handleReuploadFileSelect;
window.removeReuploadFile = removeReuploadFile;
window.submitReuploadProof = submitReuploadProof;

// -------------------------------------------------------------
// AKSI TRANSAKSI DEMO & INTEGRASI SALDO
// -------------------------------------------------------------
async function simulatePayOrder(masterOrderId) {
  if (!currentUser) return;

  try {
    const res = await fetch("/on-shop/api/buyer/orders.php?action=pay_order", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        master_order_id: masterOrderId,
        user_id: currentUser.user_id
      })
    });

    const result = await res.json();
    if (typeof showToast === "function") {
      showToast(result.message, result.status ? "success" : "error");
    }
    if (result.status) {
      currentActiveFilter = "packed";
      loadOrdersFromDatabase();
    }
  } catch (err) {
    console.error("Gagal bayar:", err);
  }
}

async function simulateSellerShipOrder(subOrderId) {
  const dummyResi = "JNE" + Math.floor(1000000000 + Math.random() * 9000000000);

  try {
    const res = await fetch("/on-shop/api/buyer/orders.php?action=ship_order", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        sub_order_id: subOrderId,
        tracking_number: dummyResi
      })
    });

    const result = await res.json();
    if (typeof showToast === "function") {
      showToast(result.message, result.status ? "success" : "error");
    }
    if (result.status) {
      currentActiveFilter = "shipped";
      loadOrdersFromDatabase();
    }
  } catch (err) {
    console.error("Gagal kirim pesanan:", err);
  }
}

async function simulateCourierDeliver(subOrderId) {
  try {
    const res = await fetch("/on-shop/api/buyer/orders.php?action=deliver_order", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ sub_order_id: subOrderId })
    });

    const result = await res.json();
    if (typeof showToast === "function") {
      showToast("Paket berhasil diantar kurir ke alamat tujuan!", "info");
    }
    if (result.status) {
      currentActiveFilter = "arrived";
      loadOrdersFromDatabase();
    }
  } catch (err) {
    console.error("Gagal update kurir:", err);
  }
}

async function confirmOrderReceived(subOrderId) {
  if (!currentUser) return;

  try {
    const res = await fetch("/on-shop/api/buyer/orders.php?action=confirm_received", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        sub_order_id: subOrderId,
        user_id: currentUser.user_id
      })
    });

    const result = await res.json();
    if (typeof showToast === "function") {
      showToast(result.message, result.status ? "success" : "error");
    }
    if (result.status) {
      currentActiveFilter = "review";
      loadOrdersFromDatabase();
    }
  } catch (err) {
    console.error("Gagal konfirmasi pesanan:", err);
  }
}

// -------------------------------------------------------------
// PENGELOLAAN PENGAJUAN PENGEMBALIAN & BUKTI FOTO
// -------------------------------------------------------------
function openReturnModal(subOrderId, productName) {
  selectedReturnTarget = subOrderId;
  const prodNameEl = document.getElementById("returnProductName");
  const notesEl = document.getElementById("returnNotesInput");

  if (prodNameEl) prodNameEl.innerText = productName;
  if (notesEl) notesEl.value = "";

  removeReturnImage();

  const modal = document.getElementById("returnModal");
  if (modal) modal.classList.remove("hidden");
}

function closeReturnModal() {
  selectedReturnTarget = null;
  removeReturnImage();
  const modal = document.getElementById("returnModal");
  if (modal) modal.classList.add("hidden");
}

function handleReturnImageSelect(e) {
  const file = e.target.files[0];
  if (!file) return;

  const validTypes = ["image/jpeg", "image/png", "image/webp"];
  if (!validTypes.includes(file.type)) {
    if (typeof showToast === "function") showToast("Format gambar harus JPG, PNG, atau WEBP!", "warning");
    e.target.value = "";
    return;
  }

  tempReturnImageFile = file;

  const reader = new FileReader();
  reader.onload = (evt) => {
    const img = document.getElementById("returnImgPreview");
    img.src = evt.target.result;

    img.onload = () => {
      returnImgNaturalWidth = img.naturalWidth;
      returnImgNaturalHeight = img.naturalHeight;
      returnImageScale = 1.0;
      returnImageOffsetX = 0;
      returnImageOffsetY = 0;

      const zoomSlider = document.getElementById("returnImgZoom");
      const posXSlider = document.getElementById("returnImgPosX");
      const posYSlider = document.getElementById("returnImgPosY");
      if (zoomSlider) zoomSlider.value = 1;
      if (posXSlider) posXSlider.value = 0;
      if (posYSlider) posYSlider.value = 0;

      updateReturnImageTransform();
      document.getElementById("returnImageAdjustContainer").style.display = "block";
      document.getElementById("btnRemoveReturnImg").style.display = "inline-block";
    };
  };
  reader.readAsDataURL(file);
}

function updateReturnImageTransform() {
  const img = document.getElementById("returnImgPreview");
  const zoomSlider = document.getElementById("returnImgZoom");
  const posXSlider = document.getElementById("returnImgPosX");
  const posYSlider = document.getElementById("returnImgPosY");

  if (zoomSlider) returnImageScale = parseFloat(zoomSlider.value);
  if (posXSlider) returnImageOffsetX = parseInt(posXSlider.value, 10);
  if (posYSlider) returnImageOffsetY = parseInt(posYSlider.value, 10);

  const zoomText = document.getElementById("zoomValReturnText");
  const posXText = document.getElementById("posXValReturnText");
  const posYText = document.getElementById("posYValReturnText");

  if (zoomText) zoomText.innerText = `${returnImageScale.toFixed(1)}x`;
  if (posXText) posXText.innerText = `${returnImageOffsetX}px`;
  if (posYText) posYText.innerText = `${returnImageOffsetY}px`;

  if (img) {
    img.style.left = "50%";
    img.style.top = "50%";
    img.style.transform = `translate(calc(-50% + ${returnImageOffsetX}px), calc(-50% + ${returnImageOffsetY}px)) scale(${returnImageScale})`;
  }
}

function removeReturnImage() {
  tempReturnImageFile = null;
  const input = document.getElementById("returnImageInput");
  if (input) input.value = "";

  const container = document.getElementById("returnImageAdjustContainer");
  if (container) container.style.display = "none";

  const btnRemove = document.getElementById("btnRemoveReturnImg");
  if (btnRemove) btnRemove.style.display = "none";
}

function generateCroppedReturnImageBlob() {
  return new Promise((resolve) => {
    if (!tempReturnImageFile) {
      resolve(null);
      return;
    }

    const img = document.getElementById("returnImgPreview");
    const canvas = document.createElement("canvas");
    const ctx = canvas.getContext("2d");
    const targetSize = 400;
    canvas.width = targetSize;
    canvas.height = targetSize;

    ctx.fillStyle = "#ffffff";
    ctx.fillRect(0, 0, targetSize, targetSize);

    ctx.save();
    ctx.translate(targetSize / 2, targetSize / 2);
    ctx.scale(returnImageScale, returnImageScale);

    let drawWidth = targetSize;
    let drawHeight = targetSize;
    if (returnImgNaturalWidth > returnImgNaturalHeight) {
      drawWidth = (returnImgNaturalWidth / returnImgNaturalHeight) * targetSize;
    } else {
      drawHeight = (returnImgNaturalHeight / returnImgNaturalWidth) * targetSize;
    }

    ctx.drawImage(img, -drawWidth / 2, -drawHeight / 2, drawWidth, drawHeight);
    ctx.restore();

    canvas.toBlob((blob) => resolve(blob), "image/webp", 0.88);
  });
}

async function submitReturnRequest() {
  if (!selectedReturnTarget || !currentUser) return;

  const reason = document.getElementById("returnReasonSelect").value;
  const notes = document.getElementById("returnNotesInput").value.trim();

  const btn = document.getElementById("btnSubmitReturn");
  if (btn) {
    btn.disabled = true;
    btn.innerText = "Mengirim...";
  }

  try {
    const croppedBlob = await generateCroppedReturnImageBlob();
    let imageBase64 = null;

    if (croppedBlob) {
      imageBase64 = await new Promise((resolve) => {
        const reader = new FileReader();
        reader.onloadend = () => resolve(reader.result);
        reader.readAsDataURL(croppedBlob);
      });
    }

    const res = await fetch("/on-shop/api/buyer/orders.php?action=submit_return", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        sub_order_id: selectedReturnTarget,
        user_id: currentUser.user_id,
        reason: reason,
        complaint: notes,
        dispute_image_base64: imageBase64
      })
    });
    const result = await res.json();

    if (result.status) {
      if (typeof showToast === "function") showToast(result.message, "success");
      closeReturnModal();
      currentActiveFilter = "dispute";
      await loadOrdersFromDatabase();
    } else {
      if (typeof showToast === "function") showToast(result.message || "Gagal mengajukan retur.", "error");
    }
  } catch (err) {
    console.error("Gagal mengajukan pengembalian:", err);
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.innerText = "Kirim Pengajuan";
    }
  }
}

async function simulateDropToJne(subOrderId) {
  if (!currentUser) return;

  try {
    const res = await fetch("/on-shop/api/buyer/orders.php?action=confirm_return_shipping", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        sub_order_id: subOrderId,
        user_id: currentUser.user_id
      })
    });
    const result = await res.json();

    if (result.status) {
      if (typeof showToast === "function") showToast(result.message, "success");

      if (!isHistoryMode) toggleHistoryView();
      const returTabBtn = document.querySelectorAll(".history-tab-btn")[1];
      switchHistoryTab("pengembalian", returTabBtn);
      await loadOrdersFromDatabase();
    } else {
      if (typeof showToast === "function") showToast(result.message || "Gagal konfirmasi kurir JNE.", "error");
    }
  } catch (err) {
    console.error("Gagal konfirmasi kurir JNE:", err);
  }
}

// -------------------------------------------------------------
// PEMBATALAN PESANAN
// -------------------------------------------------------------
function cancelBuyerOrder(subOrderId) {
  pendingCancelSubOrderId = subOrderId;
  const modal = document.getElementById("cancelConfirmModal");
  if (modal) modal.classList.remove("hidden");
}

function closeCancelConfirmModal() {
  pendingCancelSubOrderId = null;
  const modal = document.getElementById("cancelConfirmModal");
  if (modal) modal.classList.add("hidden");
}

async function executeCancelOrder() {
  if (!pendingCancelSubOrderId || !currentUser) return;

  const btnConfirm = document.getElementById("btnConfirmProceedCancel");
  if (btnConfirm) {
    btnConfirm.disabled = true;
    btnConfirm.innerText = "Membatalkan...";
  }

  try {
    const res = await fetch("/on-shop/api/buyer/orders.php?action=cancel_order", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        sub_order_id: pendingCancelSubOrderId,
        user_id: currentUser.user_id
      })
    });

    const result = await res.json();
    closeCancelConfirmModal();

    if (typeof showToast === "function") {
      showToast(result.message, result.status ? "info" : "error");
    }
    if (result.status) {
      loadOrdersFromDatabase();
    }
  } catch (err) {
    console.error("Gagal membatalkan pesanan:", err);
    closeCancelConfirmModal();
  } finally {
    if (btnConfirm) {
      btnConfirm.disabled = false;
      btnConfirm.innerText = "Ya, Batalkan";
    }
  }
}

// -------------------------------------------------------------
// ULASAN PRODUK
// -------------------------------------------------------------
function openRatingModal(subOrderId, productId, productName) {
  selectedReviewTarget = { subOrderId, productId };
  const prodNameEl = document.getElementById("ratingProductName");
  const commentEl = document.getElementById("ratingCommentInput");

  if (prodNameEl) prodNameEl.innerText = productName;
  if (commentEl) commentEl.value = "";

  removeReviewImage();
  setRating(5);

  const modal = document.getElementById("ratingModal");
  if (modal) modal.classList.remove("hidden");
}

function closeRatingModal() {
  const modal = document.getElementById("ratingModal");
  if (modal) modal.classList.add("hidden");
}

function setRating(val) {
  const hiddenInput = document.getElementById("selectedStarValue");
  if (hiddenInput) hiddenInput.value = val;

  const stars = document.querySelectorAll("#starSelector .star-clickable");
  stars.forEach((s, idx) => {
    if (idx < val) s.classList.add("gold");
    else s.classList.remove("gold");
  });
}

function handleReviewImageSelect(e) {
  const file = e.target.files[0];
  if (!file) return;

  const validTypes = ["image/jpeg", "image/png", "image/webp"];
  if (!validTypes.includes(file.type)) {
    if (typeof showToast === "function") showToast("Format gambar harus JPG, PNG, atau WEBP!", "warning");
    e.target.value = "";
    return;
  }

  tempReviewImageFile = file;

  const reader = new FileReader();
  reader.onload = (evt) => {
    const img = document.getElementById("reviewImgPreview");
    img.src = evt.target.result;

    img.onload = () => {
      reviewImgNaturalWidth = img.naturalWidth;
      reviewImgNaturalHeight = img.naturalHeight;
      currentImageScale = 1.0;
      currentImageOffsetX = 0;
      currentImageOffsetY = 0;

      updateReviewImageTransform();
      document.getElementById("reviewImageAdjustContainer").style.display = "block";
      document.getElementById("btnRemoveReviewImg").style.display = "inline-block";
    };
  };
  reader.readAsDataURL(file);
}

function updateReviewImageTransform() {
  const img = document.getElementById("reviewImgPreview");
  const zoomText = document.getElementById("zoomValText");
  if (zoomText) zoomText.innerText = `${currentImageScale.toFixed(1)}x`;

  if (img) {
    img.style.left = "50%";
    img.style.top = "50%";
    img.style.transform = `translate(calc(-50% + ${currentImageOffsetX}px), calc(-50% + ${currentImageOffsetY}px)) scale(${currentImageScale})`;
  }
}

function removeReviewImage() {
  tempReviewImageFile = null;
  const input = document.getElementById("reviewImageInput");
  if (input) input.value = "";

  const container = document.getElementById("reviewImageAdjustContainer");
  if (container) container.style.display = "none";

  const btnRemove = document.getElementById("btnRemoveReviewImg");
  if (btnRemove) btnRemove.style.display = "none";
}

function generateCroppedReviewImageBlob() {
  return new Promise((resolve) => {
    if (!tempReviewImageFile) {
      resolve(null);
      return;
    }

    const img = document.getElementById("reviewImgPreview");
    const canvas = document.createElement("canvas");
    const ctx = canvas.getContext("2d");
    const targetSize = 400;
    canvas.width = targetSize;
    canvas.height = targetSize;

    ctx.fillStyle = "#ffffff";
    ctx.fillRect(0, 0, targetSize, targetSize);

    ctx.save();
    ctx.translate(targetSize / 2, targetSize / 2);
    ctx.scale(currentImageScale, currentImageScale);

    let drawWidth = targetSize;
    let drawHeight = targetSize;
    if (reviewImgNaturalWidth > reviewImgNaturalHeight) {
      drawWidth = (reviewImgNaturalWidth / reviewImgNaturalHeight) * targetSize;
    } else {
      drawHeight = (reviewImgNaturalHeight / reviewImgNaturalWidth) * targetSize;
    }

    ctx.drawImage(img, -drawWidth / 2, -drawHeight / 2, drawWidth, drawHeight);
    ctx.restore();

    canvas.toBlob((blob) => resolve(blob), "image/webp", 0.88);
  });
}

async function submitRating() {
  if (!selectedReviewTarget || !currentUser) return;

  const starVal = parseInt(document.getElementById("selectedStarValue").value, 10);
  const comment = document.getElementById("ratingCommentInput").value.trim();

  const btnSubmit = document.getElementById("btnSubmitReview");
  btnSubmit.disabled = true;
  btnSubmit.innerText = "Mengirim...";

  try {
    const croppedBlob = await generateCroppedReviewImageBlob();

    let imageBase64OrUrl = null;
    if (croppedBlob) {
      imageBase64OrUrl = await new Promise((resolve) => {
        const reader = new FileReader();
        reader.onloadend = () => resolve(reader.result);
        reader.readAsDataURL(croppedBlob);
      });
    }

    const res = await fetch("/on-shop/api/buyer/orders.php?action=submit_review", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        sub_order_id: selectedReviewTarget.subOrderId,
        product_id: selectedReviewTarget.productId,
        user_id: currentUser.user_id,
        rating: starVal,
        comment: comment,
        review_image_base64: imageBase64OrUrl
      })
    });

    const result = await res.json();
    if (result.status) {
      if (typeof showToast === "function") showToast("Penilaian berhasil dikirim!", "success");
      closeRatingModal();
      loadOrdersFromDatabase();
    } else {
      if (typeof showToast === "function") showToast(result.message || "Gagal mengirim ulasan.", "error");
    }
  } catch (err) {
    console.error("Gagal mengirim ulasan:", err);
  } finally {
    btnSubmit.disabled = false;
    btnSubmit.innerText = "Kirim Penilaian";
  }
}

function escapeHtml(text) {
  if (!text) return "";
  const map = { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" };
  return String(text).replace(/[&<>"']/g, (m) => map[m]);
}

window.switchOrderStatus = switchOrderStatus;
window.switchHistoryTab = switchHistoryTab;
window.toggleHistoryView = toggleHistoryView;
window.simulatePayOrder = simulatePayOrder;
window.simulateSellerShipOrder = simulateSellerShipOrder;
window.simulateCourierDeliver = simulateCourierDeliver;
window.confirmOrderReceived = confirmOrderReceived;
window.openReturnModal = openReturnModal;
window.closeReturnModal = closeReturnModal;
window.submitReturnRequest = submitReturnRequest;
window.handleReturnImageSelect = handleReturnImageSelect;
window.updateReturnImageTransform = updateReturnImageTransform;
window.removeReturnImage = removeReturnImage;
window.simulateDropToJne = simulateDropToJne;
window.cancelBuyerOrder = cancelBuyerOrder;
window.closeCancelConfirmModal = closeCancelConfirmModal;
window.executeCancelOrder = executeCancelOrder;
window.openRatingModal = openRatingModal;
window.closeRatingModal = closeRatingModal;
window.setRating = setRating;
window.handleReviewImageSelect = handleReviewImageSelect;
window.removeReviewImage = removeReviewImage;
window.submitRating = submitRating;