/**
 * Logika Kelola Pesanan Toko (Rombak Bersih dari Nol)
 * File: assets/js/seller-js/kelola-pesanan.js
 */

var allOrders = [];
var activeFilterTab = "all";
var searchKeyword = "";
var selectedCourier = "";
var activeBookingOrder = null;
var pendingCancelOrderId = null;
var activeProofSubOrderId = null;
var activeRejectSubOrderId = null;
var toastTimeout = null;

const API_ORDERS_URL = "../../api/seller/manage_orders.php";

document.addEventListener("DOMContentLoaded", async () => {
  await fetchOrders();
});

// =========================================================
// 1. SISTEM NOTIFIKASI TOAST (PENGGANTI ALERT)
// =========================================================
function showToast(message, type = "success") {
  const toast = document.getElementById("toastNotification");
  const msgEl = document.getElementById("toastMessage");
  const iconEl = document.getElementById("toastIcon");

  if (!toast || !msgEl) {
    console.log(`[Toast ${type}]: ${message}`);
    return;
  }

  msgEl.innerText = message;
  if (iconEl) {
    iconEl.innerText = (type === "success") ? "✓" : "✕";
  }

  toast.className = `toast-box toast-show ${type === "success" ? "toast-success" : "toast-error"}`;

  clearTimeout(toastTimeout);
  toastTimeout = setTimeout(() => {
    toast.classList.remove("toast-show");
  }, 2800);
}

// =========================================================
// 2. MENGAMBIL DATA PESANAN DARI SERVER
// =========================================================
async function fetchOrders() {
  const storedUser = JSON.parse(localStorage.getItem("currentUser")) || {};
  const authQuery = storedUser.user_id ? `&auth_user_id=${storedUser.user_id}` : "";

  try {
    const res = await fetch(`${API_ORDERS_URL}?action=get_orders${authQuery}`);
    if (!res.ok) return;

    const result = await res.json();
    if (!result.status) {
      console.warn("Gagal memuat:", result.message);
      return;
    }

    allOrders = result.data || [];
    renderOrderFeed();

    // Perbarui counter notifikasi bilah navigasi bawah secara realtime
    const dikemasCount = allOrders.filter(o => o.order_status === "DIKEMAS").length;
    if (typeof applyBadgeCounts === "function") {
      applyBadgeCounts(".badge-slot-orders", dikemasCount);
    }

  } catch (err) {
    console.error("Gagal terhubung ke API pesanan:", err);
    showToast("Terjadi gangguan koneksi data pesanan", "error");
  }
}

// =========================================================
// 3. FILTER TAB STATUS & COUNTER
// =========================================================
function handleTabClick(statusKey, btnElement) {
  document.querySelectorAll(".tab-btn").forEach(btn => btn.classList.remove("active"));
  btnElement.classList.add("active");
  activeFilterTab = statusKey;
  renderOrderFeed();
}

function updateTabCounters() {
  const total      = allOrders.length;
  const verifikasi = allOrders.filter(o => o.order_status === "MENUNGGU_VERIFIKASI").length;
  const dikemas    = allOrders.filter(o => o.order_status === "DIKEMAS").length;
  const dikirim    = allOrders.filter(o => o.order_status === "DIKIRIM").length;
  const selesai    = allOrders.filter(o => o.order_status === "SELESAI").length;
  const batal      = allOrders.filter(o => o.order_status === "BATAL").length;

  const elAll = document.getElementById("countAll");
  const elVerifikasi = document.getElementById("countVerifikasi");
  const elDikemas = document.getElementById("countDikemas");
  const elDikirim = document.getElementById("countDikirim");
  const elSelesai = document.getElementById("countSelesai");
  const elBatal = document.getElementById("countBatal");

  if (elAll) elAll.innerText = total;
  if (elVerifikasi) elVerifikasi.innerText = verifikasi;
  if (elDikemas) elDikemas.innerText = dikemas;
  if (elDikirim) elDikirim.innerText = dikirim;
  if (elSelesai) elSelesai.innerText = selesai;
  if (elBatal) elBatal.innerText = batal;
}

// =========================================================
// 4. RENDER FEED KARTU PESANAN
// =========================================================
function renderOrderFeed() {
  updateTabCounters();
  const container = document.getElementById("ordersContainer");
  if (!container) return;
  container.innerHTML = "";

  const filtered = allOrders.filter(order => {
    if (activeFilterTab !== "all" && order.order_status !== activeFilterTab) return false;
    if (searchKeyword) {
      const matchNo = (order.sub_order_number || "").toLowerCase().includes(searchKeyword.toLowerCase());
      const matchBuyer = (order.buyer_name || "").toLowerCase().includes(searchKeyword.toLowerCase());
      const matchResi = (order.tracking_number || "").toLowerCase().includes(searchKeyword.toLowerCase());
      if (!matchNo && !matchBuyer && !matchResi) return false;
    }
    if (selectedCourier && !(order.courier_name || "").toLowerCase().includes(selectedCourier.toLowerCase())) {
      return false;
    }
    return true;
  });

  const emptyBox = document.getElementById("emptyOrderState");
  if (filtered.length === 0) {
    if (emptyBox) emptyBox.classList.remove("hidden");
    return;
  }
  if (emptyBox) emptyBox.classList.add("hidden");

  filtered.forEach(order => {
    const card = document.createElement("div");
    card.className = "order-card";

    let itemsHtml = "";
    (order.items || []).forEach(item => {
      const imgUrl = item.product_image_url || "https://placehold.co/100x100?text=Produk";
      const formattedPrice = "Rp " + new Intl.NumberFormat("id-ID").format(item.price_at_purchase);

      itemsHtml += `
        <div class="order-item-row">
          <div class="item-left">
            <img src="${imgUrl}" alt="${item.product_name}" class="item-thumb" onerror="this.src='https://placehold.co/100x100?text=Produk'">
            <div class="item-desc">
              <strong>${item.product_name}</strong>
              <div class="item-meta-tags">
                <span class="item-variant">${item.variant_name}</span>
                <span class="item-sku-tag">${item.sku || 'RAK-01'}</span>
              </div>
            </div>
          </div>
          <div class="item-pricing">
            <div class="item-price">${formattedPrice}</div>
            <div class="item-qty">x${item.quantity}</div>
          </div>
        </div>
      `;
    });

    let actionButtons = "";
    let statusBadgeText = "";
    let statusBadgeClass = "";

    const st = (order.order_status || "").toUpperCase();

    if (st === "MENUNGGU_VERIFIKASI") {
      statusBadgeText = "Menunggu Verifikasi";
      statusBadgeClass = "status-verifikasi";
      actionButtons = `
        <button type="button" class="btn-act btn-act-blue" onclick="openProofModal(${order.sub_order_id})">👁️ Bukti Bayar</button>
        <button type="button" class="btn-act btn-act-green" onclick="approvePaymentDirect(${order.sub_order_id})">✅ Setujui</button>
        <button type="button" class="btn-act btn-act-red" onclick="openRejectModal(${order.sub_order_id})">❌ Tolak</button>
        <button type="button" class="btn-act btn-act-blue" onclick="openOrderDetailModal(${order.sub_order_id})">Rincian</button>
      `;
    } else if (st === "MENUNGGU_BAYAR") {
      statusBadgeText = "Menunggu Bayar";
      statusBadgeClass = "status-menunggu-bayar";
      actionButtons = `
        <button type="button" class="btn-act btn-act-blue" onclick="openOrderDetailModal(${order.sub_order_id})">Rincian</button>
      `;
    } else if (st === "DIKEMAS") {
      statusBadgeText = "Perlu Diproses";
      statusBadgeClass = "status-dikemas";
      actionButtons = `
        <button type="button" class="btn-act btn-act-green" onclick="openShippingModal(${order.sub_order_id})">Atur Kirim</button>
        <button type="button" class="btn-act btn-act-blue" onclick="openOrderDetailModal(${order.sub_order_id})">Rincian</button>
        <button type="button" class="btn-act btn-act-red" onclick="openCancelConfirmModal(${order.sub_order_id})">Batalkan</button>
      `;
    } else if (st === "DIKIRIM") {
      statusBadgeText = "Dikirim";
      statusBadgeClass = "status-dikirim";
      actionButtons = `
        <button type="button" class="btn-act btn-act-yellow" onclick="openTrackingModal(${order.sub_order_id})">Lacak</button>
        <button type="button" class="btn-act btn-act-blue" onclick="openDirectThermalModal(${order.sub_order_id})">Cetak Resi</button>
      `;
    } else if (st === "SELESAI") {
      statusBadgeText = "Selesai";
      statusBadgeClass = "status-selesai";
      actionButtons = `
        <button type="button" class="btn-act btn-act-blue" onclick="openOrderDetailModal(${order.sub_order_id})">Rincian</button>
        <button type="button" class="btn-act btn-act-green" onclick="chatBuyer()">Hubungi</button>
      `;
    } else {
      statusBadgeText = "Dibatalkan";
      statusBadgeClass = "status-batal";
      actionButtons = `
        <button type="button" class="btn-act btn-act-blue" onclick="openOrderDetailModal(${order.sub_order_id})">Rincian</button>
      `;
    }

    const totalProductAmount = parseFloat(order.gross_product_amount || 0);
    const shippingCost = parseFloat(order.shipping_cost || 0);
    const totalOrderFormatted = "Rp " + new Intl.NumberFormat("id-ID").format(totalProductAmount + shippingCost);

    card.innerHTML = `
      <div class="order-card-header">
        <div class="order-meta-left">
          <span class="order-number">#${order.sub_order_number}</span>
          <span class="buyer-name">Pembeli: <strong>${order.buyer_name}</strong></span>
          <span class="order-date">${order.created_at_formatted}</span>
        </div>
        <div>
          <span class="badge-status ${statusBadgeClass}">${statusBadgeText}</span>
        </div>
      </div>

      <div class="order-items-list">
        ${itemsHtml}
      </div>

      <div class="order-shipping-meta">
        <div class="meta-block">
          <span>Jasa Pengiriman</span>
          <strong>${order.courier_name} (${order.courier_service || 'Reguler'})</strong>
        </div>
        <div class="meta-block">
          <span>Alamat Penerima</span>
          <strong>${order.full_shipping_address} (${order.shipping_phone})</strong>
        </div>
        <div class="meta-block">
          <span>Nomor Resi / Booking</span>
          <strong>${order.tracking_number ? `<code>${order.tracking_number}</code>` : '<em style="color:#64748b;">Belum ada</em>'}</strong>
        </div>
      </div>

      <div class="order-card-footer">
        <div class="net-amount">
          <span>Total Nilai Pesanan:</span>
          <h4>${totalOrderFormatted}</h4>
        </div>
        <div class="action-buttons">
          ${actionButtons}
        </div>
      </div>
    `;

    container.appendChild(card);
  });
}

// =========================================================
// 5. ATUR PENGIRIMAN & BOOKING KURIR
// =========================================================
function openShippingModal(subOrderId) {
  const target = allOrders.find(o => o.sub_order_id === subOrderId);
  if (!target) return;

  activeBookingOrder = target;

  if (!target.tracking_number) {
    const courier = (target.courier_name || "EXP").toUpperCase();
    const randNum = Math.floor(100000000 + Math.random() * 900000000);
    target.generated_booking_code = `${courier}-${randNum}`;
  } else {
    target.generated_booking_code = target.tracking_number;
  }

  document.getElementById("modalOrderInvText").innerText = `#${target.sub_order_number} • ${target.courier_name} (${target.courier_service || 'Reguler'})`;
  document.getElementById("modalBookingCode").innerText = target.generated_booking_code;
  document.getElementById("shippingModal").style.display = "flex";
}

function closeShippingModal() {
  document.getElementById("shippingModal").style.display = "none";
}

async function confirmPackageHandover() {
  if (!activeBookingOrder) return;

  const storedUser = JSON.parse(localStorage.getItem("currentUser")) || {};
  const authQuery = storedUser.user_id ? `&auth_user_id=${storedUser.user_id}` : "";

  try {
    const res = await fetch(`${API_ORDERS_URL}?action=handover_courier${authQuery}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        sub_order_id: activeBookingOrder.sub_order_id,
        tracking_number: activeBookingOrder.generated_booking_code
      })
    });
    const result = await res.json();

    if (result.status) {
      closeShippingModal();
      showToast(result.message || "Paket berhasil diserahkan ke kurir!", "success");
      await fetchOrders();
    } else {
      showToast(result.message || "Gagal memperbarui status pengiriman", "error");
    }
  } catch (err) {
    console.error("Gagal menyerahkan paket:", err);
    showToast("Terjadi kendala jaringan", "error");
  }
}

// =========================================================
// 6. MODAL RESI THERMAL & BARCODE VISUAL
// =========================================================
function openThermalPrintModal() {
  if (!activeBookingOrder) return;
  setupThermalPaper(activeBookingOrder);
  document.getElementById("thermalLabelModal").style.display = "flex";
}

function openDirectThermalModal(subOrderId) {
  const target = allOrders.find(o => o.sub_order_id === subOrderId);
  if (!target) return;
  activeBookingOrder = target;
  target.generated_booking_code = target.tracking_number || `EXP-${Math.floor(100000000 + Math.random() * 900000000)}`;
  setupThermalPaper(target);
  document.getElementById("thermalLabelModal").style.display = "flex";
}

function setupThermalPaper(order) {
  document.getElementById("lblCourierName").innerText = `${order.courier_name.toUpperCase()} ${(order.courier_service || 'REG').toUpperCase()}`;
  document.getElementById("lblTrackingCode").innerText = order.generated_booking_code;
  document.getElementById("lblRecipientName").innerText = order.shipping_recipient_name || order.buyer_name;
  document.getElementById("lblRecipientPhone").innerText = order.shipping_phone || "-";
  document.getElementById("lblRecipientAddress").innerText = order.full_shipping_address;
  document.getElementById("lblPrintDate").innerText = new Date().toLocaleDateString("id-ID");

  try {
    if (typeof JsBarcode === "function") {
      JsBarcode("#barcodeCanvas", order.generated_booking_code, {
        format: "CODE128",
        lineColor: "#000",
        width: 2,
        height: 44,
        displayValue: false
      });
    }
  } catch (e) {
    console.error("Barcode gagal dibuat:", e);
  }

  const ul = document.getElementById("lblItemsList");
  ul.innerHTML = "";
  (order.items || []).forEach(item => {
    const li = document.createElement("li");
    li.innerHTML = `<span>${item.product_name} (${item.variant_name})</span><strong>x${item.quantity}</strong>`;
    ul.appendChild(li);
  });
}

function closeThermalModal() {
  document.getElementById("thermalLabelModal").style.display = "none";
}

function triggerNativePrint() {
  window.print();
}

// =========================================================
// 7. LINIMASA PELACAKAN KURIR
// =========================================================
function openTrackingModal(subOrderId) {
  const order = allOrders.find(o => o.sub_order_id === subOrderId);
  if (!order) return;

  document.getElementById("trkCourierText").innerText = `${order.courier_name} (${order.courier_service || 'Reguler'})`;
  document.getElementById("trkResiCode").innerText = order.tracking_number || "Belum ada resi";

  const timelineContainer = document.getElementById("timelineList");
  timelineContainer.innerHTML = `
    <div class="timeline-item active">
      <div class="timeline-dot"></div>
      <div class="timeline-time">${order.created_at_formatted}</div>
      <div class="timeline-status">Paket Diterima di Drop Point</div>
      <div class="timeline-desc">Paket telah diterima petugas kurir di gerai Pangkalpinang dan siap menuju Hub Sortir.</div>
    </div>
    <div class="timeline-item">
      <div class="timeline-dot"></div>
      <div class="timeline-time">Dalam Proses</div>
      <div class="timeline-status">Menuju Kota Tujuan</div>
      <div class="timeline-desc">Paket sedang dalam perjalanan transit logistik antar-wilayah.</div>
    </div>
    <div class="timeline-item">
      <div class="timeline-dot"></div>
      <div class="timeline-time">Estimasi Tiba</div>
      <div class="timeline-status">Kurir Mengantar Paket</div>
      <div class="timeline-desc">Paket dibawa kurir menuju alamat tujuan (${order.shipping_recipient_name || order.buyer_name}).</div>
    </div>
  `;

  document.getElementById("trackingTimelineModal").style.display = "flex";
}

function closeTrackingModal() {
  document.getElementById("trackingTimelineModal").style.display = "none";
}

// =========================================================
// 8. NOTA RINCIAN TRANSAKSI
// =========================================================
function openOrderDetailModal(subOrderId) {
  const order = allOrders.find(o => o.sub_order_id === subOrderId);
  if (!order) return;

  const content = document.getElementById("receiptContent");
  const gross = parseFloat(order.gross_product_amount || 0);
  const ship = parseFloat(order.shipping_cost || 0);
  const net = parseFloat(order.net_seller_amount || 0);
  const fee = gross * 0.05;

  content.innerHTML = `
    <div class="receipt-row"><span>No. Faktur Toko</span><strong>#${order.sub_order_number}</strong></div>
    <div class="receipt-row"><span>Tanggal</span><span>${order.created_at_formatted}</span></div>
    <div class="receipt-row"><span>Pembeli</span><strong>${order.buyer_name}</strong></div>
    <div class="receipt-row"><span>Status Bayar</span><strong style="color:#2e8b57;">LUNAS (PAID)</strong></div>
    <div class="receipt-row"><span>Kurir</span><span>${order.courier_name} (${order.courier_service || 'Reguler'})</span></div>
    <div class="receipt-row"><span>Total Produk</span><span>Rp ${gross.toLocaleString("id-ID")}</span></div>
    <div class="receipt-row"><span>Ongkos Kirim</span><span>Rp ${ship.toLocaleString("id-ID")}</span></div>
    <div class="receipt-row" style="color:#ef4444;"><span>Komisi Platform (5%)</span><span>- Rp ${fee.toLocaleString("id-ID")}</span></div>
    <div class="receipt-row total-row"><span>Penghasilan Bersih</span><span>Rp ${net.toLocaleString("id-ID")}</span></div>
  `;

  document.getElementById("orderDetailModal").style.display = "flex";
}

function closeDetailModal() {
  document.getElementById("orderDetailModal").style.display = "none";
}

// =========================================================
// 9. BATALKAN PESANAN (MODAL KUSTOM & TOAST)
// =========================================================
function openCancelConfirmModal(subOrderId) {
  pendingCancelOrderId = subOrderId;
  const modal = document.getElementById("cancelConfirmModal");
  const execBtn = document.getElementById("btnExecuteCancel");

  if (modal && execBtn) {
    execBtn.onclick = executeCancelOrder;
    modal.style.display = "flex";
  }
}

function closeCancelConfirmModal() {
  const modal = document.getElementById("cancelConfirmModal");
  if (modal) modal.style.display = "none";
  pendingCancelOrderId = null;
}

async function executeCancelOrder() {
  if (!pendingCancelOrderId) return;
  const subOrderId = pendingCancelOrderId;
  closeCancelConfirmModal();

  const storedUser = JSON.parse(localStorage.getItem("currentUser")) || {};
  const authQuery = storedUser.user_id ? `&auth_user_id=${storedUser.user_id}` : "";

  try {
    const res = await fetch(`${API_ORDERS_URL}?action=cancel_order${authQuery}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ sub_order_id: subOrderId })
    });
    const result = await res.json();

    if (result.status) {
      showToast(result.message || "Pesanan berhasil dibatalkan", "success");
      await fetchOrders();
    } else {
      showToast(result.message || "Gagal membatalkan pesanan", "error");
    }
  } catch (err) {
    console.error("Gagal membatalkan pesanan:", err);
    showToast("Terjadi gangguan koneksi", "error");
  }
}

function chatBuyer() {
  window.location.href = "chat-pelanggan.html";
}

function handleOrderSearch() {
  searchKeyword = document.getElementById("orderSearchInput").value.trim();
  renderOrderFeed();
}

function handleCourierFilter() {
  selectedCourier = document.getElementById("courierFilter").value;
  renderOrderFeed();
}

// =========================================================
// 8. VERIFIKASI BUKTI TRANSFER PEMBAYARAN (SELLER WORKFLOW)
// =========================================================
function openProofModal(subOrderId) {
  const target = allOrders.find(o => o.sub_order_id === subOrderId);
  if (!target) return;

  activeProofSubOrderId = subOrderId;
  const invEl = document.getElementById("proofOrderInvText");
  const imgEl = document.getElementById("proofModalImg");

  if (invEl) {
    invEl.innerText = `#${target.sub_order_number} • Pembeli: ${target.buyer_name} • Total: Rp ${Number(target.gross_product_amount || 0).toLocaleString('id-ID')}`;
  }

  if (imgEl) {
    imgEl.src = target.proof_view_url || (`../../api/files/payment_proof.php?sub_order_id=${subOrderId}`);
  }

  const modal = document.getElementById("proofModal");
  if (modal) modal.style.display = "flex";
}

function closeProofModal() {
  const modal = document.getElementById("proofModal");
  if (modal) modal.style.display = "none";
  activeProofSubOrderId = null;
}

function openRejectModal(subOrderId) {
  const target = allOrders.find(o => o.sub_order_id === subOrderId);
  if (!target) return;

  activeRejectSubOrderId = subOrderId;
  const invEl = document.getElementById("rejectOrderInvText");
  if (invEl) {
    invEl.innerText = `#${target.sub_order_number} • Pembeli: ${target.buyer_name}`;
  }

  const preset = document.getElementById("rejectPresetSelect");
  if (preset) preset.value = "";
  const textarea = document.getElementById("rejectReasonTextarea");
  if (textarea) textarea.value = "";

  const modal = document.getElementById("rejectModal");
  if (modal) modal.style.display = "flex";
}

function closeRejectModal() {
  const modal = document.getElementById("rejectModal");
  if (modal) modal.style.display = "none";
  activeRejectSubOrderId = null;
}

function handleRejectPresetChange() {
  const preset = document.getElementById("rejectPresetSelect");
  const textarea = document.getElementById("rejectReasonTextarea");
  if (preset && textarea && preset.value) {
    textarea.value = preset.value;
  }
}

function openRejectModalFromProof() {
  const subId = activeProofSubOrderId;
  closeProofModal();
  if (subId) {
    openRejectModal(subId);
  }
}

async function approvePaymentFromProof() {
  const subId = activeProofSubOrderId;
  closeProofModal();
  if (subId) {
    await approvePaymentDirect(subId);
  }
}

async function approvePaymentDirect(subOrderId) {
  const storedUser = JSON.parse(localStorage.getItem("currentUser")) || {};
  const authQuery = storedUser.user_id ? `&auth_user_id=${storedUser.user_id}` : "";

  try {
    const res = await fetch(`${API_ORDERS_URL}?action=approve_payment${authQuery}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ sub_order_id: subOrderId })
    });
    const result = await res.json();

    if (result.status) {
      showToast(result.message || "Pembayaran berhasil disetujui!", "success");
      await fetchOrders();
    } else {
      showToast(result.message || "Gagal menyetujui pembayaran.", "error");
    }
  } catch (err) {
    console.error("Gagal menyetujui pembayaran:", err);
    showToast("Terjadi gangguan koneksi sistem", "error");
  }
}

async function submitRejectPayment() {
  if (!activeRejectSubOrderId) return;

  const textarea = document.getElementById("rejectReasonTextarea");
  const reason = textarea ? textarea.value.trim() : "";

  if (!reason) {
    showToast("Harap isi alasan penolakan bukti pembayaran", "error");
    return;
  }

  const storedUser = JSON.parse(localStorage.getItem("currentUser")) || {};
  const authQuery = storedUser.user_id ? `&auth_user_id=${storedUser.user_id}` : "";

  try {
    const res = await fetch(`${API_ORDERS_URL}?action=reject_payment${authQuery}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        sub_order_id: activeRejectSubOrderId,
        rejection_reason: reason
      })
    });
    const result = await res.json();

    if (result.status) {
      closeRejectModal();
      showToast(result.message || "Bukti pembayaran berhasil ditolak", "success");
      await fetchOrders();
    } else {
      showToast(result.message || "Gagal menolak bukti pembayaran.", "error");
    }
  } catch (err) {
    console.error("Gagal menolak bukti pembayaran:", err);
    showToast("Terjadi gangguan koneksi sistem", "error");
  }
}

window.openProofModal = openProofModal;
window.closeProofModal = closeProofModal;
window.openRejectModal = openRejectModal;
window.closeRejectModal = closeRejectModal;
window.handleRejectPresetChange = handleRejectPresetChange;
window.openRejectModalFromProof = openRejectModalFromProof;
window.approvePaymentFromProof = approvePaymentFromProof;
window.approvePaymentDirect = approvePaymentDirect;
window.submitRejectPayment = submitRejectPayment;