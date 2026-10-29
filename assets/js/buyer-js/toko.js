/**
 * Logika Publik Toko ON Shop
 * File: assets/js/buyer-js/toko.js
 */

let allStoreProducts = [];

// =========================================================
// NAVIGASI KEMBALI HANYA KE DETAIL PRODUK
// =========================================================
function handleBackToDetailProduct() {
  const urlParams = new URLSearchParams(window.location.search);
  const lastProductId = urlParams.get("product_id") || sessionStorage.getItem("last_viewed_product_id");

  if (lastProductId && lastProductId !== "0") {
    window.location.href = `detail-produk.html?id=${lastProductId}`;
    return;
  }

  if (document.referrer && document.referrer.includes("detail-produk.html")) {
    window.location.href = document.referrer;
    return;
  }

  // Fallback default
  window.location.href = `detail-produk.html?id=37`;
}

window.handleBackToDetailProduct = handleBackToDetailProduct;

document.addEventListener("DOMContentLoaded", () => {
  const urlParams = new URLSearchParams(window.location.search);
  const shopId = urlParams.get("id") || urlParams.get("shop_id") || 5;
  const productId = urlParams.get("product_id");

  if (productId) {
    sessionStorage.setItem("last_viewed_product_id", productId);
  }

  fetchStoreProfileAndProducts(shopId);
});

// Ambil data toko & barang dari database
async function fetchStoreProfileAndProducts(shopId) {
  try {
    const res = await fetch(`/on-shop/api/buyer/get_shop_profile.php?shop_id=${shopId}`);
    const json = await res.json();

    if (json.status && json.data) {
      const shop = json.data.shop;
      allStoreProducts = json.data.products || [];

      // Update Info Header Toko
      document.getElementById("storeHeroName").innerText = shop.shop_name;
      
      const avatarEl = document.getElementById("storeHeroAvatar");
      if (shop.logo_url) {
        avatarEl.innerHTML = `<img src="${shop.logo_url}" alt="${shop.shop_name}" />`;
      } else {
        avatarEl.innerText = shop.shop_name.substring(0, 2).toUpperCase();
      }

      document.getElementById("storeHeroLocation").innerText = `📍 ${shop.pickup_city || "Pangkalpinang"} • Aktif hari ini`;
      document.getElementById("statTotalProducts").innerText = `${allStoreProducts.length} Barang`;
      document.title = `${shop.shop_name} - ON Shop`;

      // Hubungkan tombol chat
      const chatBtn = document.getElementById("btnStoreChat");
      if (chatBtn) {
        const lastPid = sessionStorage.getItem("last_viewed_product_id") || (allStoreProducts[0] ? allStoreProducts[0].product_id : 0);
        chatBtn.href = `chat.html?seller_id=${shop.user_id}&shop_id=${shop.shop_id}&product_id=${lastPid}`;
      }

      renderProductsGrid(allStoreProducts);
    } else {
      if (typeof showToast === "function") {
        showToast(json.message || "Gagal memuat toko", "error");
      }
    }
  } catch (err) {
    console.error("Fetch toko error:", err);
  }
}

// Render grid produk toko
function renderProductsGrid(products) {
  const grid = document.getElementById("storeProductGrid");
  if (!products || products.length === 0) {
    grid.innerHTML = `<p style="grid-column: 1/-1; text-align: center; color: #888; padding: 40px;">Toko ini belum memiliki produk.</p>`;
    return;
  }

  grid.innerHTML = "";
  products.forEach((p) => {
    const card = document.createElement("div");
    card.className = "product-card";
    card.innerHTML = `
      <a href="detail-produk.html?id=${p.product_id}" style="text-decoration: none; color: inherit;">
        <div class="product-img-wrapper">
          <img src="${p.image_url}" alt="${escapeHtml(p.name)}" class="product-thumb" loading="lazy" />
        </div>
        <h4 class="product-title">${escapeHtml(p.name)}</h4>
        <p class="product-price">Rp ${Number(p.price || 0).toLocaleString("id-ID")}</p>
        <div style="display: flex; justify-content: space-between; font-size: 11px; color: #777; margin: 4px 8px 8px 8px;">
          <span>📍 Tersedia</span>
          <span>⭐ 5.0</span>
        </div>
      </a>
    `;
    grid.appendChild(card);
  });
}

// Cari barang di toko
function handleSearchInStore(query) {
  const q = query.toLowerCase().trim();
  const filtered = allStoreProducts.filter((p) => p.name.toLowerCase().includes(q));
  renderProductsGrid(filtered);
}

// Filter Tab
function switchStoreTab(tabKey, btn) {
  document.querySelectorAll(".store-tab-btn").forEach((b) => b.classList.remove("active"));
  btn.classList.add("active");

  if (tabKey === "pinned") {
    const pinned = allStoreProducts.filter((p) => p.is_pinned == 1);
    renderProductsGrid(pinned);
  } else if (tabKey === "promo") {
    renderProductsGrid(allStoreProducts.slice(0, 4));
  } else {
    renderProductsGrid(allStoreProducts);
  }

  if (typeof showToast === "function") {
    showToast(`Menampilkan: ${btn.innerText}`, "info");
  }
}

// Ikuti Toko
function toggleFollowStore(btn) {
  if (btn.innerText.includes("Ikuti")) {
    btn.innerText = "✓ Mengikuti";
    btn.style.background = "#ffffff";
    btn.style.color = "#2e8b57";
    if (typeof showToast === "function") showToast("Berhasil mengikuti toko!", "success");
  } else {
    btn.innerText = "+ Ikuti Toko";
    btn.style.background = "transparent";
    btn.style.color = "#ffffff";
    if (typeof showToast === "function") showToast("Batal mengikuti toko.", "info");
  }
}

function escapeHtml(text) {
  if (!text) return "";
  const map = { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" };
  return String(text).replace(/[&<>"']/g, (m) => map[m]);
}

window.handleSearchInStore = handleSearchInStore;
window.switchStoreTab = switchStoreTab;
window.toggleFollowStore = toggleFollowStore;