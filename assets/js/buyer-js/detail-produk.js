/**
 * Logika Detail Produk Bersih & Terpadu (Dari Nol)
 * Navigasi Cerdas: Kembali ke Pencarian (jika datang dari search) atau ke Beranda
 * File: assets/js/buyer-js/detail-produk.js
 */

let productData = null;
let variantsList = [];
let allGalleryImages = [];
let selectedVariantIndex = 0;
let defaultMainProductImage = "";

// =============================================================
// REKAM ASAL PENJELAJAHAN (SEARCH / TOKO / BERANDA)
// =============================================================
(function recordSafeOrigin() {
  const ref = document.referrer || "";
  // Rekam URL pencarian beserta kata kunci jika datang dari halaman search
  if (ref.includes("search.html")) {
    sessionStorage.setItem("product_source_search", ref);
  }
})();

function handleDetailBackNav() {
  const urlParams = new URLSearchParams(window.location.search);
  const fromParam = urlParams.get("from");
  const searchOrigin = sessionStorage.getItem("product_source_search");

  // 1. Jika asal adalah pencarian, prioritaskan kembali ke search
  if (fromParam === "search" || (searchOrigin && !document.referrer.includes("keranjang.html") && !document.referrer.includes("chat.html"))) {
    sessionStorage.removeItem("product_source_search");
    window.location.href = searchOrigin || "search.html";
    return;
  }

  // 2. Jika dari etalase toko
  if (fromParam === "toko" && productData && productData.shop_id) {
    window.location.href = `toko.html?id=${productData.shop_id}`;
    return;
  }

  // 3. Standar kembali ke Beranda (Index)
  window.location.href = "index.html";
}

window.handleDetailBackNav = handleDetailBackNav;

document.addEventListener("DOMContentLoaded", () => {
  fetchProductDetail();
  initQuantityControls();
});

function getLoggedInUser() {
  try {
    const user = JSON.parse(localStorage.getItem("currentUser"));
    if (user && user.user_id) return user;
  } catch (e) {
    return null;
  }
  return null;
}

function requireLoginRedirect(message, pendingAction = null) {
  if (typeof showToast === "function") {
    showToast(message || "Silakan masuk terlebih dahulu!", "warning");
  } else {
    alert(message || "Silakan masuk terlebih dahulu!");
  }

  const currentUrl = window.location.href;
  sessionStorage.setItem("redirectAfterLogin", currentUrl);

  if (pendingAction) {
    sessionStorage.setItem("pendingProductAction", JSON.stringify(pendingAction));
  }

  setTimeout(() => {
    window.location.href = `login.html?redirect=${encodeURIComponent(currentUrl)}`;
  }, 800);
}

// =============================================================
// 1. PENGAMBILAN DATA PRODUK DARI DATABASE
// =============================================================
async function fetchProductDetail() {
  const urlParams = new URLSearchParams(window.location.search);
  const productId = parseInt(urlParams.get("id"), 10) || 0;

  if (productId <= 0) {
    if (typeof showToast === "function") {
      showToast("ID produk tidak valid!", "error");
    }
    setTimeout(() => { window.location.href = "index.html"; }, 1200);
    return;
  }

  sessionStorage.setItem("last_viewed_product_id", productId);
  sessionStorage.setItem("last_chat_product_id", productId);

  try {
    const response = await fetch(`/on-shop/api/buyer/get_product_detail.php?id=${productId}`);
    const result = await response.json();

    if (!result.status || !result.data || !result.data.product) {
      if (typeof showToast === "function") {
        showToast(result.message || "Produk tidak ditemukan!", "error");
      }
      setTimeout(() => { window.location.href = "index.html"; }, 1500);
      return;
    }

    productData = result.data.product;
    variantsList = result.data.variants || [];
    const images = result.data.images || [];
    const reviews = result.data.reviews || [];

    const prodTitleEl = document.getElementById("prodTitle");
    if (prodTitleEl) prodTitleEl.innerText = productData.product_name;

    const breadcrumbTitleEl = document.getElementById("breadcrumbTitle");
    if (breadcrumbTitleEl) breadcrumbTitleEl.innerText = productData.product_name;

    const breadcrumbCategoryEl = document.getElementById("breadcrumbCategory");
    if (breadcrumbCategoryEl) breadcrumbCategoryEl.innerText = productData.category_name || "Produk";

    const ratingEl = document.getElementById("prodRatingText");
    if (ratingEl) ratingEl.innerText = `⭐ ${productData.rating_avg || '5.0'}`;

    const reviewCountEl = document.getElementById("reviewCountText");
    if (reviewCountEl) reviewCountEl.innerText = `${productData.total_reviews || 0} Penilaian`;

    const soldCountEl = document.getElementById("soldCountText");
    if (soldCountEl) soldCountEl.innerText = `Berat: ${productData.weight_grams || 0} gr`;

    const shopNameEl = document.getElementById("shopProfileName");
    if (shopNameEl) shopNameEl.innerText = productData.shop_name;

    const shopCityEl = document.getElementById("shopCityLocation");
    if (shopCityEl) shopCityEl.innerText = `📍 ${productData.shop_city || 'Indonesia'}`;

    const shopAvatarEl = document.getElementById("shopAvatarBadge");
    if (shopAvatarEl) shopAvatarEl.innerText = productData.shop_name.substring(0, 2).toUpperCase();

    const visitShopBtn = document.getElementById("btnDetailVisitShop");
    if (visitShopBtn && productData.shop_id) {
      visitShopBtn.href = `toko.html?id=${productData.shop_id}&product_id=${productData.product_id}`;
    }

    bindChatButtonEvents();
    setupGalleryImages(images, variantsList);
    renderVariants(variantsList);

    const specCategory = document.getElementById("specCategoryText");
    if (specCategory) specCategory.innerText = productData.category_name || "-";

    const specOrigin = document.getElementById("specOriginCity");
    if (specOrigin) specOrigin.innerText = productData.shop_city || "Indonesia";

    const descContainer = document.getElementById("prodDescriptionFull");
    if (descContainer) {
      descContainer.innerHTML = `<p>${(productData.description || 'Tidak ada deskripsi tersedia.').replace(/\n/g, "<br>")}</p>`;
    }

    renderReviews(reviews, productData.rating_avg);
    processPendingAction();
    checkInitialWishlistStatus();

  } catch (error) {
    console.error("Gagal memuat rincian produk:", error);
  }
}

function bindChatButtonEvents() {
  const sellerUserId = productData.seller_id || productData.user_id || 0;
  const isFromSearch = Boolean(sessionStorage.getItem("product_source_search"));
  const fromParam = isFromSearch ? "search" : "home";

  // Oper link chat dengan menyematkan product_id dan parameter rutenya
  const chatUrl = `chat.html?seller_id=${sellerUserId}&shop_id=${productData.shop_id || 0}&product_id=${productData.product_id}&from=${fromParam}`;

  const triggerChat = (e) => {
    if (e) {
      e.preventDefault();
      e.stopPropagation();
    }
    const user = getLoggedInUser();
    if (!user) {
      requireLoginRedirect("Silakan masuk terlebih dahulu untuk mengirim pesan ke penjual!", {
        type: "chat",
        targetUrl: chatUrl
      });
      return false;
    }
    window.location.href = chatUrl;
    return false;
  };

  const btnDesktop = document.getElementById("btnDetailChatSeller");
  if (btnDesktop) {
    btnDesktop.removeAttribute("href");
    btnDesktop.onclick = triggerChat;
  }

  const btnMobile = document.getElementById("btnMobileChatLink");
  if (btnMobile) {
    btnMobile.removeAttribute("href");
    btnMobile.onclick = triggerChat;
  }
}

// =============================================================
// 2. GALERI & VARIAN
// =============================================================
function setupGalleryImages(mainImages, variants) {
  allGalleryImages = [];
  const addedUrls = new Set();

  if (Array.isArray(mainImages)) {
    mainImages.forEach((img) => {
      if (img.image_url && !addedUrls.has(img.image_url)) {
        addedUrls.add(img.image_url);
        allGalleryImages.push({
          url: img.image_url,
          variantIndex: -1
        });
      }
    });
  }

  if (Array.isArray(variants)) {
    variants.forEach((v, idx) => {
      if (v.image_url && v.image_url.trim() !== "" && !addedUrls.has(v.image_url)) {
        addedUrls.add(v.image_url);
        allGalleryImages.push({
          url: v.image_url,
          variantIndex: idx
        });
      }
    });
  }

  renderGalleryUI();
}

function renderGalleryUI() {
  const mainImage = document.getElementById("mainImage");
  const thumbnailList = document.getElementById("thumbnailList");
  if (!thumbnailList || !mainImage) return;

  thumbnailList.innerHTML = "";
  const defaultImg = "https://placehold.co/450x450/2e8b57/ffffff?text=ON+Shop";

  if (allGalleryImages.length === 0) {
    defaultMainProductImage = productData.image_url || defaultImg;
    mainImage.src = defaultMainProductImage;
    return;
  }

  defaultMainProductImage = allGalleryImages[0].url;
  mainImage.src = defaultMainProductImage;

  allGalleryImages.forEach((item, idx) => {
    const thumb = document.createElement("img");
    thumb.src = item.url;
    thumb.loading = "lazy";
    thumb.className = `thumb-item ${idx === 0 ? "active" : ""}`;
    thumb.style.width = "60px";
    thumb.style.height = "60px";
    thumb.style.objectFit = "cover";
    thumb.style.cursor = "pointer";
    thumb.style.marginRight = "8px";
    thumb.style.borderRadius = "6px";
    thumb.style.border = (idx === 0) ? "2px solid #2e8b57" : "1px solid #cbd5e1";

    thumb.onclick = () => {
      setActiveMainImage(item.url);
      if (item.variantIndex >= 0) {
        selectVariant(item.variantIndex, false);
      } else {
        const matchedIdx = variantsList.findIndex((v) => v.image_url === item.url);
        if (matchedIdx >= 0) {
          selectVariant(matchedIdx, false);
        }
      }
    };

    thumbnailList.appendChild(thumb);
  });
}

function setActiveMainImage(url) {
  const mainImage = document.getElementById("mainImage");
  if (mainImage) mainImage.src = url;

  document.querySelectorAll(".thumb-item").forEach((t) => {
    if (t.src === url) {
      t.classList.add("active");
      t.style.border = "2px solid #2e8b57";
    } else {
      t.classList.remove("active");
      t.style.border = "1px solid #cbd5e1";
    }
  });
}

function renderVariants(variants) {
  const variantContainer = document.getElementById("variantOptionsContainer");
  if (!variantContainer) return;

  variantContainer.innerHTML = "";

  if (!variants || variants.length === 0) {
    variantContainer.innerHTML = `<span style="font-size:13px; color:#64748b;">Standar (Tanpa varian)</span>`;
    const stockEl = document.getElementById("stockDisplay");
    if (stockEl) stockEl.innerText = `Tersisa ${productData.stock || 10} buah`;

    const currPrice = document.getElementById("currentPriceDisplay");
    if (currPrice) {
      currPrice.innerText = `Rp ${Number(productData.price || 0).toLocaleString("id-ID")}`;
    }
    return;
  }

  variants.forEach((v, index) => {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = `variant-pill ${index === 0 ? "active" : ""}`;
    btn.innerText = v.variant_combination;
    btn.onclick = () => selectVariant(index, true);
    variantContainer.appendChild(btn);
  });

  selectVariant(0, true);
}

function selectVariant(index, updateImage = true) {
  selectedVariantIndex = index;
  const buttons = document.querySelectorAll(".variant-pill");
  buttons.forEach((b, i) => {
    b.classList.toggle("active", i === index);
  });

  const v = variantsList[selectedVariantIndex];
  if (v) {
    const priceNum = Number(v.price);
    const currPrice = document.getElementById("currentPriceDisplay");
    const origPrice = document.getElementById("originalPriceDisplay");
    const discBadge = document.getElementById("discountBadge");
    const stockDisp = document.getElementById("stockDisplay");

    if (currPrice) currPrice.innerText = `Rp ${priceNum.toLocaleString("id-ID")}`;
    if (origPrice) {
      origPrice.innerText = `Rp ${(priceNum * 1.2).toLocaleString("id-ID")}`;
      origPrice.style.display = "inline";
    }
    if (discBadge) {
      discBadge.innerText = `-15%`;
      discBadge.style.display = "inline-block";
    }
    if (stockDisp) stockDisp.innerText = `Tersisa ${v.stock} buah`;

    const qtyInput = document.getElementById("qtyInput");
    if (qtyInput && parseInt(qtyInput.value, 10) > v.stock) {
      qtyInput.value = v.stock > 0 ? 1 : 0;
    }

    if (updateImage) {
      if (v.image_url && v.image_url.trim() !== "") {
        setActiveMainImage(v.image_url);
      } else if (defaultMainProductImage) {
        setActiveMainImage(defaultMainProductImage);
      }
    }
  }
}

// =============================================================
// 3. KUANTITAS & KERANJANG
// =============================================================
function initQuantityControls() {
  const btnMin = document.getElementById("btnMin");
  const btnPlus = document.getElementById("btnPlus");
  const qtyInput = document.getElementById("qtyInput");
  if (!btnMin || !btnPlus || !qtyInput) return;

  btnPlus.onclick = () => {
    const currentStock = (variantsList && variantsList[selectedVariantIndex])
      ? parseInt(variantsList[selectedVariantIndex].stock, 10)
      : (productData ? parseInt(productData.stock, 10) : 99);

    let val = parseInt(qtyInput.value, 10) || 1;
    if (val < currentStock) {
      qtyInput.value = val + 1;
    } else {
      if (typeof showToast === "function") {
        showToast(`Maksimal pembelian ${currentStock} pcs sesuai sisa stok.`, "warning");
      }
    }
  };

  btnMin.onclick = () => {
    let val = parseInt(qtyInput.value, 10) || 1;
    if (val > 1) {
      qtyInput.value = val - 1;
    }
  };
}

async function handleAddToCart() {
  const user = getLoggedInUser();
  const qtyInput = document.getElementById("qtyInput");
  const qty = qtyInput ? parseInt(qtyInput.value, 10) : 1;

  if (!user) {
    requireLoginRedirect("Silakan masuk terlebih dahulu untuk menambah produk ke keranjang!", {
      type: "add_to_cart",
      variantIndex: selectedVariantIndex,
      qty: qty
    });
    return;
  }

  if (!productData) {
    if (typeof showToast === "function") showToast("Data produk belum selesai dimuat.", "warning");
    return;
  }

  const selectedVariant = (variantsList && variantsList[selectedVariantIndex]) ? variantsList[selectedVariantIndex] : null;

  if (selectedVariant && parseInt(selectedVariant.stock, 10) <= 0) {
    if (typeof showToast === "function") showToast("Stok varian ini telah habis!", "error");
    return;
  }

  try {
    const res = await fetch("/on-shop/api/buyer/cart.php?action=add", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        user_id: user.user_id,
        product_id: parseInt(productData.product_id, 10),
        variant_id: selectedVariant ? parseInt(selectedVariant.variant_id, 10) : null,
        qty: qty
      })
    });

    const result = await res.json();

    if (result.status) {
      if (typeof showToast === "function") {
        showToast(result.message || `${qty} produk berhasil ditambahkan ke keranjang!`, "success");
      } else {
        alert(result.message || `${qty} produk berhasil ditambahkan ke keranjang!`);
      }

      if (typeof window.initNavbarCartBadge === "function") {
        window.initNavbarCartBadge();
      }
      window.dispatchEvent(new Event("cartUpdated"));
    } else {
      if (typeof showToast === "function") {
        showToast(result.message || "Gagal menambahkan produk ke keranjang.", "warning");
      } else {
        alert(result.message || "Gagal menambahkan produk ke keranjang.");
      }
    }
  } catch (err) {
    console.error("Gagal mengirim data ke keranjang:", err);
  }
}

function handleDirectBuy() {
  const user = getLoggedInUser();
  const qtyInput = document.getElementById("qtyInput");
  const qty = qtyInput ? parseInt(qtyInput.value, 10) : 1;

  if (!user) {
    requireLoginRedirect("Silakan masuk terlebih dahulu untuk membeli produk ini!", {
      type: "direct_buy",
      variantIndex: selectedVariantIndex,
      qty: qty
    });
    return;
  }

  const selectedVariant = (variantsList && variantsList[selectedVariantIndex]) ? variantsList[selectedVariantIndex] : null;

  if (selectedVariant && parseInt(selectedVariant.stock, 10) <= 0) {
    if (typeof showToast === "function") showToast("Stok varian ini telah habis!", "error");
    return;
  }

  const checkoutItem = {
    product_id: parseInt(productData.product_id, 10),
    variant_id: selectedVariant ? parseInt(selectedVariant.variant_id, 10) : null,
    product_name: productData.product_name,
    variant_name: selectedVariant ? selectedVariant.variant_combination : "Default",
    price: selectedVariant ? Number(selectedVariant.price) : Number(productData.price || 0),
    quantity: qty,
    shop_id: parseInt(productData.shop_id, 10),
    shop_name: productData.shop_name,
    city: productData.shop_city,
    image: document.getElementById("mainImage") ? document.getElementById("mainImage").src : ""
  };

  sessionStorage.setItem("directCheckoutItem", JSON.stringify(checkoutItem));
  sessionStorage.removeItem("cartCheckoutPayload");
  window.location.href = "checkout.html";
}

function switchTab(tabId, buttonEl) {
  document.querySelectorAll(".tab-panel").forEach((p) => p.classList.remove("active"));
  document.querySelectorAll(".tab-item-btn").forEach((b) => b.classList.remove("active"));

  const target = document.getElementById(tabId);
  if (target) target.classList.add("active");
  if (buttonEl) buttonEl.classList.add("active");
}

function renderReviews(reviews, avgRating) {
  const container = document.getElementById("productReviewsFeed");
  if (!container) return;

  container.innerHTML = "";
  const tabCounter = document.getElementById("tabReviewCounter");
  if (tabCounter) tabCounter.innerText = reviews ? reviews.length : 0;

  const scoreSummary = document.getElementById("reviewSummaryAverage");
  if (scoreSummary) scoreSummary.innerText = avgRating || "5.0";

  if (!reviews || reviews.length === 0) {
    container.innerHTML = `<p style="padding: 24px; text-align: center; color: #94a3b8;">Belum ada ulasan untuk produk ini.</p>`;
    return;
  }

  reviews.forEach((r) => {
    const div = document.createElement("div");
    div.className = "review-card-item";

    const starCount = parseInt(r.rating, 10) || 5;

    div.innerHTML = `
      <div class="rev-user-meta">
          <div class="rev-avatar">${r.buyer_name ? r.buyer_name.substring(0, 2).toUpperCase() : 'US'}</div>
          <div>
              <strong>${escapeHtml(r.buyer_name || 'Pembeli')}</strong>
              <div class="stars-gold" style="font-size: 13px;">${"★".repeat(starCount)}</div>
          </div>
      </div>
      <div class="rev-subtext">${r.created_at || ''}</div>
      <p class="rev-comment-text">"${escapeHtml(r.comment || '')}"</p>
    `;
    container.appendChild(div);
  });
}

function processPendingAction() {
  const pendingRaw = sessionStorage.getItem("pendingProductAction");
  if (!pendingRaw || !getLoggedInUser()) return;

  try {
    const action = JSON.parse(pendingRaw);
    sessionStorage.removeItem("pendingProductAction");

    if (action.variantIndex !== undefined && variantsList[action.variantIndex]) {
      selectVariant(action.variantIndex, true);
    }
    const qtyInput = document.getElementById("qtyInput");
    if (qtyInput && action.qty) {
      qtyInput.value = action.qty;
    }

    if (action.type === "add_to_cart") {
      setTimeout(() => { handleAddToCart(); }, 400);
    } else if (action.type === "direct_buy") {
      setTimeout(() => { handleDirectBuy(); }, 400);
    } else if (action.type === "chat" && action.targetUrl) {
      window.location.href = action.targetUrl;
    }
  } catch (e) {
    console.error("Gagal mengeksekusi pending action:", e);
  }
}

function escapeHtml(text) {
  if (!text) return "";
  const map = { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" };
  return String(text).replace(/[&<>"']/g, (m) => map[m]);
}

window.handleAddToCart = handleAddToCart;
window.handleDirectBuy = handleDirectBuy;
window.switchTab = switchTab;

/**
 * 8. INTEGRASI WISHLIST PEMBELI (MINGGU 6)
 */
let isProductInWishlist = false;

async function checkInitialWishlistStatus() {
  if (!productData || !productData.product_id) return;
  const user = getLoggedInUser();
  if (!user) return;

  try {
    const isSubdir = window.location.pathname.includes("/on-shop/");
    const url = isSubdir
      ? `/on-shop/api/buyer/wishlist.php?action=check&product_id=${productData.product_id}`
      : `../../api/buyer/wishlist.php?action=check&product_id=${productData.product_id}`;

    const res = await fetch(url, { credentials: "include" });
    if (res.ok) {
      const result = await res.json();
      if (result.status && result.data) {
        updateWishlistButtonUI(result.data.in_wishlist);
      }
    }
  } catch (e) {
    // abaikan jika offline
  }
}

function updateWishlistButtonUI(inWishlist) {
  isProductInWishlist = Boolean(inWishlist);
  const iconD = document.getElementById("wishlistIconDesktop");
  const textD = document.getElementById("wishlistTextDesktop");
  const btnD  = document.getElementById("btnWishlistDesktop");
  const iconM = document.getElementById("wishlistIconMobile");

  if (iconD) iconD.innerText = isProductInWishlist ? "❤️" : "🤍";
  if (textD) textD.innerText = isProductInWishlist ? "Disimpan" : "Favorit";
  if (btnD) {
    btnD.style.borderColor = isProductInWishlist ? "#ef4444" : "#cbd5e1";
    btnD.style.color = isProductInWishlist ? "#ef4444" : "#64748b";
    btnD.style.backgroundColor = isProductInWishlist ? "#fef2f2" : "#ffffff";
  }
  if (iconM) iconM.innerText = isProductInWishlist ? "❤️" : "🤍";
}

async function handleToggleWishlist() {
  const user = getLoggedInUser();
  if (!user) {
    requireLoginRedirect("Silakan masuk terlebih dahulu untuk menyimpan produk ke Wishlist!");
    return;
  }

  if (!productData || !productData.product_id) return;

  try {
    const isSubdir = window.location.pathname.includes("/on-shop/");
    const url = isSubdir
      ? `/on-shop/api/buyer/wishlist.php?action=toggle`
      : `../../api/buyer/wishlist.php?action=toggle`;

    const res = await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "include",
      body: JSON.stringify({ product_id: productData.product_id })
    });

    const result = await res.json();
    if (result.status) {
      updateWishlistButtonUI(result.data.in_wishlist);
      if (typeof showToast === "function") {
        showToast(result.message, result.data.in_wishlist ? "success" : "info");
      }
    } else {
      if (typeof showToast === "function") {
        showToast(result.message || "Gagal mengubah wishlist.", "error");
      }
    }
  } catch (err) {
    console.error("Toggle wishlist error:", err);
  }
}

window.handleToggleWishlist = handleToggleWishlist;