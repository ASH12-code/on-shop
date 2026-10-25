/**
 * Logika Checkout & Alur Pembuatan Pesanan
 * Fitur: Multi-Store Checkout, Auto-Apply Best Voucher, Stacking Voucher Toko & Platform
 * File: assets/js/buyer-js/checkout.js
 */

let currentUser = JSON.parse(localStorage.getItem("currentUser")) || null;
let checkoutData = [];
let userAddresses = [];
let selectedAddress = null;
const SERVICE_FEE = 1000;

// State Voucher
let availableVouchers = [];
let activeVoucherTarget = null; // { type: 'PLATFORM_PROD' | 'PLATFORM_SHIP' | 'SHOP_PROD', shopIdx: number }
let pendingOrderSession = null;

// ==========================================
// 0. NAVIGASI KEMBALI
// ==========================================
function handleCheckoutBackNav() {
  const directItem = JSON.parse(sessionStorage.getItem("directCheckoutItem"));
  if (directItem && directItem.product_id) {
    window.location.href = `detail-produk.html?id=${directItem.product_id}`;
    return;
  }

  const cartPayload = sessionStorage.getItem("cartCheckoutPayload");
  if (cartPayload) {
    window.location.href = "keranjang.html";
    return;
  }

  if (
    document.referrer &&
    (document.referrer.includes("keranjang.html") || document.referrer.includes("detail-produk.html"))
  ) {
    window.location.href = document.referrer;
    return;
  }

  window.location.href = "keranjang.html";
}
window.handleCheckoutBackNav = handleCheckoutBackNav;

document.addEventListener("DOMContentLoaded", () => {
  if (!currentUser || !currentUser.user_id) {
    if (typeof showToast === "function") {
      showToast("Silakan masuk ke akun Anda untuk checkout.", "warning");
    }
    setTimeout(() => {
      window.location.href = "login.html";
    }, 1500);
    return;
  }

  initCheckoutPayload();
  loadUserAddresses();
});

// ==========================================
// 1. DATA BELANJA & AUTO-APPLY VOUCHER
// ==========================================
async function initCheckoutPayload() {
  const cartPayload = sessionStorage.getItem("cartCheckoutPayload");
  const directPayload = sessionStorage.getItem("directCheckoutItem");

  if (cartPayload) {
    checkoutData = JSON.parse(cartPayload);
  } else if (directPayload) {
    const item = JSON.parse(directPayload);
    checkoutData = [
      {
        shop_id: item.shop_id || 1,
        shop_name: item.shop_name || "Toko Penjual",
        city: item.city || "Indonesia",
        items: [
          {
            product_id: item.product_id,
            variant_id: item.variant_id,
            name: item.product_name,
            variant_name: item.variant_name,
            price: item.price,
            qty: item.quantity,
            image: item.image || "https://placehold.co/100x100/2e8b57/ffffff?text=Produk"
          }
        ]
      }
    ];
  } else {
    if (typeof showToast === "function") {
      showToast("Tidak ada produk yang dipilih untuk checkout.", "warning");
    }
    setTimeout(() => {
      window.location.href = "keranjang.html";
    }, 1200);
    return;
  }

  // Setup default ekspedisi & voucher slot per toko
  checkoutData.forEach((shop) => {
    shop.shipping_cost = 15000;
    shop.courier_name = "JNE";
    shop.courier_service = "Reguler (2-3 Hari)";
    shop.applied_shop_voucher = null;           // 1 voucher toko (hanya produk)
    shop.applied_platform_prod_voucher = null;  // 1 voucher produk platform per antrean toko
    shop.applied_shipping_voucher = null;       // 1 voucher gratis ongkir per antrean toko
  });

  await loadAvailableVouchers();
  autoApplyAllVouchers();
  renderCheckoutShops();
  recalculateCheckout();
}

async function loadAvailableVouchers() {
  try {
    const res = await fetch(`/on-shop/api/buyer/vouchers.php?action=get_applicable_vouchers&user_id=${currentUser.user_id}`);
    const result = await res.json();
    if (result.status) {
      availableVouchers = result.data || [];
    }
  } catch (err) {
    console.error("Gagal mengambil data voucher:", err);
  }
}

// Menghitung potensi potongan nominal (Rp)
function calculateNominalDiscount(voucher, baseAmount) {
  if (!voucher || baseAmount < voucher.min_purchase) return 0;

  let discount = 0;
  if (voucher.discount_type === "FIXED") {
    discount = voucher.discount_value;
  } else if (voucher.discount_type === "PERCENTAGE") {
    discount = (voucher.discount_value / 100) * baseAmount;
    if (voucher.max_discount && voucher.max_discount > 0) {
      discount = Math.min(discount, voucher.max_discount);
    }
  }
  return Math.min(discount, baseAmount);
}

// LOGIKA AUTO-APPLY TERBAIK (Greedy Priority)
function autoApplyAllVouchers() {
  if (availableVouchers.length === 0) return;

  // 1. Voucher Toko: Hanya 1 voucher terbaik per toko (menggabungkan seluruh item dalam toko)
  checkoutData.forEach((shop) => {
    const shopGross = shop.items.reduce((sum, it) => sum + (it.price * it.qty), 0);
    const candidateShopVouchers = availableVouchers.filter((v) => v.shop_id === shop.shop_id && v.voucher_category === 'PRODUCT_DISCOUNT');

    let bestVoucher = null;
    let maxNominal = 0;
    candidateShopVouchers.forEach((v) => {
      const nom = calculateNominalDiscount(v, shopGross);
      if (nom > maxNominal) {
        maxNominal = nom;
        bestVoucher = { ...v, calculated_discount: nom };
      }
    });
    shop.applied_shop_voucher = bestVoucher;
  });

  // 2. Voucher Produk Platform: Diurutkan dari toko ke-1, toko ke-2 dst. (1 voucher per toko)
  let usedPlatProdIds = new Set();
  checkoutData.forEach((shop) => {
    const shopGross = shop.items.reduce((sum, it) => sum + (it.price * it.qty), 0);
    const candidatePlatProds = availableVouchers.filter(
      (v) => v.shop_id === null && v.voucher_category === 'PRODUCT_DISCOUNT' && !usedPlatProdIds.has(v.user_voucher_id)
    );

    let bestVoucher = null;
    let maxNominal = 0;
    candidatePlatProds.forEach((v) => {
      const nom = calculateNominalDiscount(v, shopGross);
      if (nom > maxNominal) {
        maxNominal = nom;
        bestVoucher = { ...v, calculated_discount: nom };
      }
    });

    if (bestVoucher) {
      shop.applied_platform_prod_voucher = bestVoucher;
      usedPlatProdIds.add(bestVoucher.user_voucher_id);
    }
  });

  // 3. Voucher Gratis Ongkir Platform: Diurutkan dari toko ke-1, toko ke-2 dst. (1 voucher per toko)
  let usedShippingIds = new Set();
  checkoutData.forEach((shop) => {
    const candidateShippings = availableVouchers.filter(
      (v) => v.shop_id === null && v.voucher_category === 'FREE_SHIPPING' && !usedShippingIds.has(v.user_voucher_id)
    );

    let bestVoucher = null;
    let maxNominal = 0;
    candidateShippings.forEach((v) => {
      const nom = calculateNominalDiscount(v, shop.shipping_cost);
      if (nom > maxNominal) {
        maxNominal = nom;
        bestVoucher = { ...v, calculated_discount: nom };
      }
    });

    if (bestVoucher) {
      shop.applied_shipping_voucher = bestVoucher;
      usedShippingIds.add(bestVoucher.user_voucher_id);
    }
  });
}

function renderCheckoutShops() {
  const container = document.getElementById("checkoutShopsContainer");
  if (!container) return;
  container.innerHTML = "";

  checkoutData.forEach((shop, shopIdx) => {
    const shopCard = document.createElement("section");
    shopCard.className = "shop-checkout-card";

    let itemsHtml = "";
    shop.items.forEach((item) => {
      const subtotal = item.price * item.qty;
      itemsHtml += `
        <div class="checkout-item-row">
          <img src="${item.image}" alt="${escapeHtml(item.name)}" class="item-img" onerror="this.src='https://placehold.co/60x60?text=No+Img'" />
          <div class="item-meta">
            <div class="item-name">${escapeHtml(item.name)}</div>
            <div class="item-sub">Varian: ${escapeHtml(item.variant_name || "Standar")} | Jumlah: ${item.qty} pcs</div>
          </div>
          <div class="item-price">Rp ${subtotal.toLocaleString("id-ID")}</div>
        </div>
      `;
    });

    // 1. Baris Voucher ON Shop Produk (Atas)
    const hasPltProd = Boolean(shop.applied_platform_prod_voucher);
    const pltProdName = hasPltProd ? shop.applied_platform_prod_voucher.voucher_name : "Tidak ada voucher diskon produk";
    const pltProdNominal = hasPltProd ? `-Rp ${shop.applied_platform_prod_voucher.calculated_discount.toLocaleString("id-ID")}` : "-Rp 0";
    const pltProdBtnText = hasPltProd ? "Ganti" : "Gunakan";

    const platformProdHtml = `
      <div class="shop-voucher-row platform-voucher-row">
        <div class="voucher-info-left">
          <span class="voucher-icon-badge">✨</span>
          <div class="voucher-text-group">
            <span class="voucher-title">Voucher ON Shop (Diskon Produk)</span>
            <span class="voucher-status-empty ${hasPltProd ? 'voucher-applied-text' : ''}">${escapeHtml(pltProdName)}</span>
          </div>
        </div>
        <div class="voucher-info-right">
          <span class="voucher-amount ${hasPltProd ? 'text-green' : ''}">${pltProdNominal}</span>
          <button type="button" class="btn-select-voucher" onclick="openVoucherDialog('PLATFORM_PROD', ${shopIdx})">
            ${pltProdBtnText}
          </button>
        </div>
      </div>
    `;

    // 2. Baris Voucher Gratis Ongkir (Tengah Atas Ekspedisi)
    const hasShipVoucher = Boolean(shop.applied_shipping_voucher);
    const shipVName = hasShipVoucher ? shop.applied_shipping_voucher.voucher_name : "Tidak ada voucher gratis ongkir";
    const shipVNominal = hasShipVoucher ? `-Rp ${shop.applied_shipping_voucher.calculated_discount.toLocaleString("id-ID")}` : "-Rp 0";
    const shipVBtnText = hasShipVoucher ? "Ganti" : "Gunakan";

    const shippingVoucherHtml = `
      <div class="shop-voucher-row platform-shipping-voucher-row">
        <div class="voucher-info-left">
          <span class="voucher-icon-badge">🚚</span>
          <div class="voucher-text-group">
            <span class="voucher-title">Voucher Gratis Ongkir (Platform)</span>
            <span class="voucher-status-empty ${hasShipVoucher ? 'voucher-applied-text' : ''}">${escapeHtml(shipVName)}</span>
          </div>
        </div>
        <div class="voucher-info-right">
          <span class="voucher-amount ${hasShipVoucher ? 'text-green' : ''}">${shipVNominal}</span>
          <button type="button" class="btn-select-voucher" onclick="openVoucherDialog('PLATFORM_SHIP', ${shopIdx})">
            ${shipVBtnText}
          </button>
        </div>
      </div>
    `;

    // 3. Baris Voucher Toko (Hanya Produk, di Bawah Ekspedisi)
    const hasShopVoucher = Boolean(shop.applied_shop_voucher);
    const shopVName = hasShopVoucher ? shop.applied_shop_voucher.voucher_name : "Tidak ada voucher toko";
    const shopVNominal = hasShopVoucher ? `-Rp ${shop.applied_shop_voucher.calculated_discount.toLocaleString("id-ID")}` : "-Rp 0";
    const shopVBtnText = hasShopVoucher ? "Ganti" : "Gunakan";

    const shopVoucherHtml = `
      <div class="shop-voucher-row">
        <div class="voucher-info-left">
          <span class="voucher-icon-badge">🎟️️</span>
          <div class="voucher-text-group">
            <span class="voucher-title">Voucher Toko (Produk)</span>
            <span class="voucher-status-empty ${hasShopVoucher ? 'voucher-applied-text' : ''}">${escapeHtml(shopVName)}</span>
          </div>
        </div>
        <div class="voucher-info-right">
          <span class="voucher-amount ${hasShopVoucher ? 'text-green' : ''}">${shopVNominal}</span>
          <button type="button" class="btn-select-voucher" onclick="openVoucherDialog('SHOP_PROD', ${shopIdx})">
            ${shopVBtnText}
          </button>
        </div>
      </div>
    `;

    // PILIHAN EKSPEDISI KURIR DINAMIS DARI SERVER
    let shippingOptionsHtml = '';
    if (shop.shipping_options && shop.shipping_options.length > 0) {
      shippingOptionsHtml = shop.shipping_options.map((opt) => {
        const isSelected = (opt.courier_code === shop.courier_code && opt.service_code === shop.service_code) ||
                           (Math.abs(opt.total_cost - shop.shipping_cost) < 0.1 && !shop.courier_code);
        return `<option value="${opt.total_cost}|${opt.courier_code}|${opt.service_code}|${escapeHtml(opt.courier_name)}|${escapeHtml(opt.service_name)}" ${isSelected ? 'selected' : ''}>
          ${escapeHtml(opt.label)} - Est. ${escapeHtml(opt.etd_days)}
        </option>`;
      }).join('');
    } else {
      shippingOptionsHtml = `
        <option value="15000|JNE|REG|JNE Express|Reguler (2-3 Hari)" ${shop.shipping_cost === 15000 ? 'selected' : ''}>JNE Reguler (Rp 15.000)</option>
        <option value="22000|JNT|EZ|J&T Express|EZ Express (1-2 Hari)" ${shop.shipping_cost === 22000 ? 'selected' : ''}>J&T Express (Rp 22.000)</option>
        <option value="10000|SICEPAT|SIUNT|SiCepat Ekspres|SiUntung Hemat (3-5 Hari)" ${shop.shipping_cost === 10000 ? 'selected' : ''}>SiCepat Hemat (Rp 10.000)</option>
      `;
    }

    shopCard.innerHTML = `
      <div class="shop-meta-header">
        <span>🏪 ${escapeHtml(shop.shop_name)}</span>
        <span class="shop-city-badge">📍 ${escapeHtml(shop.city || "Indonesia")}</span>
      </div>
      <div class="shop-items-list">${itemsHtml}</div>
      
      <!-- MENU VOUCHER ON SHOP (PRODUK & GRATIS ONGKIR) -->
      ${platformProdHtml}
      ${shippingVoucherHtml}

      <!-- PILIHAN EKSPEDISI KURIR -->
      <div class="shop-shipping-box">
        <div>
          <label style="font-size: 12px; font-weight: bold; color: #555; display: block; margin-bottom: 4px;">Pilihan Ekspedisi:</label>
          <select class="shipping-select" onchange="handleShippingChange(${shopIdx}, this.value)">
            ${shippingOptionsHtml}
          </select>
        </div>
        <div style="text-align: right;">
          <span style="font-size: 12px; color: #777;">Ongkir Toko Ini:</span>
          <div class="shipping-cost-label" id="shopShippingCostDisplay_${shopIdx}">Rp ${(shop.shipping_cost).toLocaleString("id-ID")}</div>
        </div>
      </div>

      <!-- MENU VOUCHER TOKO (DI BAWAH EKSPEDISI) -->
      ${shopVoucherHtml}
    `;

    container.appendChild(shopCard);
  });
}

function handleShippingChange(shopIdx, selectVal) {
  const parts = selectVal.split("|");
  const cost = parseFloat(parts[0]) || 0;
  const courierCode = parts[1] || "JNE";
  const serviceCode = parts[2] || "REG";
  const courierName = parts[3] || "JNE Express";
  const courierService = parts[4] || "Reguler (2-3 Hari)";

  checkoutData[shopIdx].shipping_cost = cost;
  checkoutData[shopIdx].courier_code = courierCode;
  checkoutData[shopIdx].service_code = serviceCode;
  checkoutData[shopIdx].courier_name = courierName;
  checkoutData[shopIdx].courier_service = courierService;

  if (checkoutData[shopIdx].applied_shipping_voucher) {
    checkoutData[shopIdx].applied_shipping_voucher.calculated_discount = calculateNominalDiscount(
      checkoutData[shopIdx].applied_shipping_voucher,
      cost
    );
  }

  renderCheckoutShops();
  recalculateCheckout();
}

function recalculateCheckout() {
  let subtotalGoods = 0;
  let totalShipping = 0;
  let totalProductDiscount = 0;
  let totalShippingDiscount = 0;

  checkoutData.forEach((shop) => {
    const shopGross = shop.items.reduce((sum, it) => sum + (it.price * it.qty), 0);
    subtotalGoods += shopGross;
    totalShipping += (shop.shipping_cost || 0);

    let shopVoucherNom = shop.applied_shop_voucher ? shop.applied_shop_voucher.calculated_discount : 0;
    let platformProdNom = shop.applied_platform_prod_voucher ? shop.applied_platform_prod_voucher.calculated_discount : 0;

    // Batas bawah produk per toko (Floor price Rp 1.000)
    let totalProdDisc = shopVoucherNom + platformProdNom;
    if ((shopGross - totalProdDisc) < 1000) {
      totalProdDisc = Math.max(0, shopGross - 1000);
    }
    totalProductDiscount += totalProdDisc;

    // Diskon ongkir toko ini (Boleh Rp 0)
    if (shop.applied_shipping_voucher) {
      totalShippingDiscount += shop.applied_shipping_voucher.calculated_discount;
    }
  });

  const finalNetProduct = Math.max(1000, subtotalGoods - totalProductDiscount);
  const finalNetShipping = Math.max(0, totalShipping - totalShippingDiscount);
  const grandTotal = finalNetProduct + finalNetShipping + SERVICE_FEE;

  document.getElementById("subtotalGoodsDisplay").innerText = `Rp ${subtotalGoods.toLocaleString("id-ID")}`;
  document.getElementById("totalProductDiscountDisplay").innerText = `-Rp ${totalProductDiscount.toLocaleString("id-ID")}`;
  document.getElementById("totalShippingDisplay").innerText = `Rp ${totalShipping.toLocaleString("id-ID")}`;
  document.getElementById("totalShippingDiscountDisplay").innerText = `-Rp ${totalShippingDiscount.toLocaleString("id-ID")}`;
  document.getElementById("serviceFeeDisplay").innerText = `Rp ${SERVICE_FEE.toLocaleString("id-ID")}`;
  document.getElementById("grandTotalDisplay").innerText = `Rp ${grandTotal.toLocaleString("id-ID")}`;
}

// ==========================================
// 2. MODAL PEMILIHAN VOUCHER
// ==========================================
function openVoucherDialog(type, shopIdx) {
  activeVoucherTarget = { type, shopIdx };
  const modal = document.getElementById("voucherSelectorModal");
  const title = document.getElementById("voucherModalTitle");
  const listContainer = document.getElementById("voucherModalListContainer");

  if (!modal || !listContainer) return;
  listContainer.innerHTML = "";

  const targetShop = checkoutData[shopIdx];
  const shopGross = targetShop.items.reduce((sum, it) => sum + (it.price * it.qty), 0);

  // Ambil ID voucher yang sedang digunakan di toko lain agar tidak bentrok
  let usedInOtherShops = new Set();
  checkoutData.forEach((s, idx) => {
    if (idx !== shopIdx) {
      if (s.applied_shop_voucher) usedInOtherShops.add(s.applied_shop_voucher.user_voucher_id);
      if (s.applied_platform_prod_voucher) usedInOtherShops.add(s.applied_platform_prod_voucher.user_voucher_id);
      if (s.applied_shipping_voucher) usedInOtherShops.add(s.applied_shipping_voucher.user_voucher_id);
    }
  });

  let vouchersToShow = [];
  if (type === "PLATFORM_PROD") {
    title.innerText = "Pilih Voucher Diskon Produk ON Shop";
    vouchersToShow = availableVouchers.filter((v) => v.shop_id === null && v.voucher_category === "PRODUCT_DISCOUNT");
  } else if (type === "PLATFORM_SHIP") {
    title.innerText = "Pilih Voucher Gratis Ongkir";
    vouchersToShow = availableVouchers.filter((v) => v.shop_id === null && v.voucher_category === "FREE_SHIPPING");
  } else {
    title.innerText = `Pilih Voucher Toko (${targetShop.shop_name})`;
    vouchersToShow = availableVouchers.filter((v) => v.shop_id === targetShop.shop_id && v.voucher_category === "PRODUCT_DISCOUNT");
  }

  // Opsi Kosongkan
  const noVoucherDiv = document.createElement("div");
  noVoucherDiv.className = "modal-voucher-item";
  noVoucherDiv.innerHTML = `
    <div>
      <strong style="color: #64748b; font-size: 13px;">Tidak Menggunakan Voucher</strong>
      <p style="font-size: 11.5px; color: #94a3b8;">Gunakan harga reguler</p>
    </div>
    <button type="button" class="btn-select-voucher" onclick="selectVoucherOption(null)">Pilih</button>
  `;
  listContainer.appendChild(noVoucherDiv);

  if (vouchersToShow.length === 0) {
    const emptyNotice = document.createElement("p");
    emptyNotice.style.cssText = "font-size: 12.5px; color: #94a3b8; text-align: center; padding: 16px;";
    emptyNotice.innerText = "Tidak ada voucher yang tersedia untuk kategori ini.";
    listContainer.appendChild(emptyNotice);
  } else {
    vouchersToShow.forEach((v) => {
      const isUsedElsewhere = usedInOtherShops.has(v.user_voucher_id);
      let baseAmount = (type === "PLATFORM_SHIP") ? targetShop.shipping_cost : shopGross;
      const nominal = calculateNominalDiscount(v, baseAmount);
      const isEligible = (baseAmount >= v.min_purchase) && !isUsedElsewhere;

      const itemDiv = document.createElement("div");
      itemDiv.className = `modal-voucher-item ${isEligible ? '' : 'disabled'}`;
      itemDiv.innerHTML = `
        <div style="flex: 1;">
          <div style="display: flex; align-items: center; gap: 6px;">
            <strong style="font-size: 13px; color: #1e293b;">${escapeHtml(v.voucher_name)}</strong>
            <span style="font-size: 10px; background: #e0f2fe; color: #0284c7; padding: 2px 6px; border-radius: 4px; font-weight: bold;">${v.voucher_code}</span>
          </div>
          <p style="font-size: 11.5px; color: #64748b; margin-top: 3px;">Min. belanja: Rp ${v.min_purchase.toLocaleString("id-ID")}</p>
          <strong style="font-size: 12.5px; color: #16a34a;">Potongan: Rp ${nominal.toLocaleString("id-ID")}</strong>
          ${isUsedElsewhere ? '<span style="font-size: 11px; color: #ef4444; display: block; margin-top: 2px;">Sedang dipakai di toko lain</span>' : ''}
        </div>
        <button type="button" class="btn-select-voucher" ${isEligible ? '' : 'disabled'} onclick='selectVoucherOption(${JSON.stringify(v)})'>
          Gunakan
        </button>
      `;
      listContainer.appendChild(itemDiv);
    });
  }

  modal.classList.remove("hidden");
}

function selectVoucherOption(voucher) {
  if (!activeVoucherTarget) return;

  const { type, shopIdx } = activeVoucherTarget;
  const targetShop = checkoutData[shopIdx];
  const shopGross = targetShop.items.reduce((sum, it) => sum + (it.price * it.qty), 0);

  if (type === "PLATFORM_PROD") {
    targetShop.applied_platform_prod_voucher = voucher ? {
      ...voucher,
      calculated_discount: calculateNominalDiscount(voucher, shopGross)
    } : null;
  } else if (type === "PLATFORM_SHIP") {
    targetShop.applied_shipping_voucher = voucher ? {
      ...voucher,
      calculated_discount: calculateNominalDiscount(voucher, targetShop.shipping_cost)
    } : null;
  } else {
    // Voucher Toko (Maksimal 1 voucher untuk toko ini)
    targetShop.applied_shop_voucher = voucher ? {
      ...voucher,
      calculated_discount: calculateNominalDiscount(voucher, shopGross)
    } : null;
  }

  closeVoucherModal();
  renderCheckoutShops();
  recalculateCheckout();
}

function closeVoucherModal() {
  activeVoucherTarget = null;
  const modal = document.getElementById("voucherSelectorModal");
  if (modal) modal.classList.add("hidden");
}

// ==========================================
// 3. ALAMAT & METODE PEMBAYARAN & ONGKIR SERVER
// ==========================================
async function updateShippingRatesFromServer() {
  if (!selectedAddress || !checkoutData || checkoutData.length === 0) return;

  try {
    const payload = {
      address_id: selectedAddress.address_id,
      destination_province: selectedAddress.province,
      shops: checkoutData.map((shop) => ({
        shop_id: shop.shop_id,
        items: shop.items.map((it) => ({
          product_id: it.product_id,
          qty: it.qty
        }))
      }))
    };

    const res = await fetch("/on-shop/api/buyer/get_shipping_rates.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    const result = await res.json();

    if (result.status && result.data && result.data.shops_shipping) {
      result.data.shops_shipping.forEach((shopShip) => {
        const targetShop = checkoutData.find((s) => s.shop_id === shopShip.shop_id);
        if (targetShop) {
          targetShop.shipping_options = shopShip.options || [];
          const currentKey = `${targetShop.courier_code || ''}|${targetShop.service_code || ''}`;
          const matchedOpt = targetShop.shipping_options.find((o) => o.value_key === currentKey);
          if (matchedOpt) {
            targetShop.shipping_cost = matchedOpt.total_cost;
            targetShop.courier_name = matchedOpt.courier_name;
            targetShop.courier_service = matchedOpt.service_name;
          } else if (targetShop.shipping_options.length > 0) {
            const firstOpt = targetShop.shipping_options[0];
            targetShop.courier_code = firstOpt.courier_code;
            targetShop.service_code = firstOpt.service_code;
            targetShop.shipping_cost = firstOpt.total_cost;
            targetShop.courier_name = firstOpt.courier_name;
            targetShop.courier_service = firstOpt.service_name;
          }
        }
      });
      renderCheckoutShops();
      recalculateCheckout();
    }
  } catch (err) {
    console.error("Gagal mengambil tarif kurir dari server:", err);
  }
}

async function loadUserAddresses() {
  const addressBox = document.getElementById("currentAddressBox");
  try {
    const res = await fetch(`/on-shop/api/buyer/checkout.php?action=get_addresses&user_id=${currentUser.user_id}`);
    const result = await res.json();

    if (result.status && result.data.length > 0) {
      userAddresses = result.data;
      selectedAddress = userAddresses.find((a) => a.is_default == 1) || userAddresses[0];
      renderCurrentAddress();
      await updateShippingRatesFromServer();
    } else {
      addressBox.innerHTML = `
        <p style="color: #e74c3c; font-size: 13px;">Anda belum memiliki alamat pengiriman tersimpan.</p>
        <a href="akun.html" style="color: #2e8b57; font-size: 13px; font-weight: bold; text-decoration: underline;">+ Tambah Alamat Baru di Menu Akun</a>
      `;
    }
  } catch (err) {
    console.error("Gagal mengambil data alamat:", err);
  }
}

function renderCurrentAddress() {
  const addressBox = document.getElementById("currentAddressBox");
  if (!addressBox || !selectedAddress) return;

  addressBox.innerHTML = `
    <strong>${escapeHtml(selectedAddress.recipient_name)}</strong> 
    <span>(${escapeHtml(selectedAddress.phone)})</span>
    <p style="margin-top: 4px; color: #475569;">
      <span style="font-size: 11px; background: #e2e8f0; padding: 2px 6px; border-radius: 4px; font-weight: bold; color: #334155;">${escapeHtml(selectedAddress.label)}</span>
      ${escapeHtml(selectedAddress.street_address)}, ${escapeHtml(selectedAddress.city)}, ${escapeHtml(selectedAddress.province)} ${escapeHtml(selectedAddress.postal_code)}
    </p>
  `;
}

function openAddressModal() {
  const container = document.getElementById("addressListModalContainer");
  if (!container) return;

  container.innerHTML = "";
  if (userAddresses.length === 0) {
    container.innerHTML = `<p style="padding: 15px 0; color: #777;">Belum ada daftar alamat lain.</p>`;
  } else {
    userAddresses.forEach((addr) => {
      const isSelected = selectedAddress && (selectedAddress.address_id === addr.address_id);
      const div = document.createElement("div");
      div.className = `modal-address-item ${isSelected ? "selected" : ""}`;
      div.onclick = () => selectAddressFromModal(addr.address_id);

      div.innerHTML = `
        <div style="font-size: 13px; font-weight: bold; color: #1e293b; margin-bottom: 2px;">
          ${escapeHtml(addr.recipient_name)} (${escapeHtml(addr.phone)})
          ${addr.is_default == 1 ? '<span style="font-size: 10px; background: #dcfce7; color: #166534; padding: 2px 6px; border-radius: 4px; margin-left: 6px;">Utama</span>' : ""}
        </div>
        <p style="font-size: 12px; color: #64748b; line-height: 1.4;">${escapeHtml(addr.street_address)}, ${escapeHtml(addr.city)}, ${escapeHtml(addr.province)} ${escapeHtml(addr.postal_code)}</p>
      `;
      container.appendChild(div);
    });
  }

  document.getElementById("addressModal").classList.remove("hidden");
}

function selectAddressFromModal(addrId) {
  const target = userAddresses.find((a) => a.address_id === addrId);
  if (target) {
    selectedAddress = target;
    renderCurrentAddress();
    closeAddressModal();
    updateShippingRatesFromServer();
  }
}

function closeAddressModal() {
  document.getElementById("addressModal").classList.add("hidden");
}

function handlePaymentChange(val) {
  const subBank = document.getElementById("subBankContainer");
  if (!subBank) return;

  if (val === "VA") {
    subBank.classList.remove("hidden");
  } else {
    subBank.classList.add("hidden");
  }
}

// ==========================================
// 4. PROSES BUAT PESANAN KE BACKEND
// ==========================================
async function processCreateOrder() {
  if (!selectedAddress) {
    if (typeof showToast === "function") {
      showToast("Silakan pilih alamat pengiriman Anda terlebih dahulu!", "warning");
    }
    return;
  }

  const paymentRadio = document.querySelector('input[name="payment_method"]:checked');
  let selectedMethod = paymentRadio ? paymentRadio.value : "COD";

  if (selectedMethod === "VA") {
    const bankRadio = document.querySelector('input[name="selected_bank"]:checked');
    selectedMethod = bankRadio ? bankRadio.value : "VA_BCA";
  } else if (selectedMethod === "QRIS") {
    selectedMethod = "QRIS";
  } else {
    selectedMethod = "COD";
  }

  const btnSubmit = document.getElementById("btnSubmitOrder");
  btnSubmit.disabled = true;
  btnSubmit.innerText = "Memproses...";

  const payloadShops = checkoutData.map((shop) => ({
    shop_id: shop.shop_id,
    shipping_cost: shop.shipping_cost,
    courier_code: shop.courier_code || "JNE",
    service_code: shop.service_code || "REG",
    courier_name: shop.courier_name || "JNE Express",
    courier_service: shop.courier_service || "Reguler (2-3 Hari)",
    shop_voucher_id: shop.applied_shop_voucher ? shop.applied_shop_voucher.user_voucher_id : null,
    platform_prod_voucher_id: shop.applied_platform_prod_voucher ? shop.applied_platform_prod_voucher.user_voucher_id : null,
    shipping_voucher_id: shop.applied_shipping_voucher ? shop.applied_shipping_voucher.user_voucher_id : null,
    items: shop.items
  }));

  const payload = {
    user_id: currentUser.user_id,
    address_id: selectedAddress.address_id,
    payment_method: selectedMethod,
    shops: payloadShops
  };

  try {
    const res = await fetch("/on-shop/api/buyer/checkout.php?action=create_order", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });

    const result = await res.json();

    if (result.status) {
      sessionStorage.removeItem("cartCheckoutPayload");
      sessionStorage.removeItem("directCheckoutItem");

      if (typeof window.initNavbarCartBadge === "function") {
        window.initNavbarCartBadge();
      }

      if (selectedMethod === "COD") {
        if (typeof showToast === "function") {
          showToast("Pesanan COD berhasil dibuat!", "success");
        }
        setTimeout(() => {
          window.location.href = "pesanan-saya.html?tab=dikemas";
        }, 1200);
        return;
      }

      pendingOrderSession = {
        master_order_id: result.master_order_id,
        invoice_number: result.invoice_number,
        grand_total: result.grand_total,
        payment_method: selectedMethod,
        va_number: result.va_number || "8800122358178"
      };

      sessionStorage.setItem("activeOrderSession", JSON.stringify(pendingOrderSession));

      document.getElementById("modalGrandTotal").innerText = `Rp ${Number(pendingOrderSession.grand_total).toLocaleString("id-ID")}`;
      document.getElementById("modalPaymentMethod").innerText = selectedMethod.replace("_", " ");
      document.getElementById("paymentChoiceModal").classList.remove("hidden");
    } else {
      if (typeof showToast === "function") {
        showToast(result.message || "Gagal membuat pesanan.", "error");
      }
      btnSubmit.disabled = false;
      btnSubmit.innerText = "Buat Pesanan";
    }
  } catch (err) {
    console.error("Gagal menghubungi server checkout:", err);
    btnSubmit.disabled = false;
    btnSubmit.innerText = "Buat Pesanan";
  }
}

function handleConfirmPayNow() {
  if (!pendingOrderSession) return;
  const method = pendingOrderSession.payment_method;

  if (method === "QRIS") {
    window.location.href = "pembayaran-qris.html";
  } else if (method.startsWith("VA")) {
    const bank = method.split("_")[1] || "BCA";
    window.location.href = `pembayaran-va.html?bank=${bank}`;
  }
}

function handleConfirmPayLater() {
  window.location.href = "pesanan-saya.html?tab=belum_bayar";
}

function escapeHtml(text) {
  if (!text) return "";
  const map = { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" };
  return String(text).replace(/[&<>"']/g, (m) => map[m]);
}

window.handleShippingChange = handleShippingChange;
window.openVoucherDialog = openVoucherDialog;
window.selectVoucherOption = selectVoucherOption;
window.closeVoucherModal = closeVoucherModal;
window.openAddressModal = openAddressModal;
window.selectAddressFromModal = selectAddressFromModal;
window.closeAddressModal = closeAddressModal;
window.handlePaymentChange = handlePaymentChange;
window.processCreateOrder = processCreateOrder;
window.handleConfirmPayNow = handleConfirmPayNow;
window.handleConfirmPayLater = handleConfirmPayLater;