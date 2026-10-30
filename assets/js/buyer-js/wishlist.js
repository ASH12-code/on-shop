/**
 * Logika Halaman Wishlist Pembeli ON Shop
 * File: assets/js/buyer-js/wishlist.js
 */

const getWishlistApiUrl = (action = "list") => {
  const isLocalhostSubdir = window.location.pathname.includes("/on-shop/");
  return isLocalhostSubdir
    ? `/on-shop/api/buyer/wishlist.php?action=${action}`
    : `../../api/buyer/wishlist.php?action=${action}`;
};

document.addEventListener("DOMContentLoaded", () => {
  loadBuyerWishlist();
});

async function loadBuyerWishlist() {
  const grid = document.getElementById("wishlistGrid");
  const emptyState = document.getElementById("wishlistEmptyState");
  const countBadge = document.getElementById("wishlistCountBadge");

  if (!grid) return;
  grid.innerHTML = `<p style="grid-column: 1/-1; text-align: center; color: #64748b; padding: 40px;">Memuat daftar produk impian Anda...</p>`;

  try {
    const res = await fetch(getWishlistApiUrl("list"), {
      method: "GET",
      headers: { "Accept": "application/json" },
      credentials: "include"
    });

    if (res.status === 401) {
      if (typeof showToast === "function") {
        showToast("Silakan masuk terlebih dahulu untuk melihat wishlist Anda!", "warning");
      }
      setTimeout(() => {
        window.location.href = `login.html?redirect=${encodeURIComponent(window.location.href)}`;
      }, 1000);
      return;
    }

    const result = await res.json();
    if (!result.status || !result.data) {
      grid.innerHTML = "";
      if (emptyState) emptyState.style.display = "block";
      if (countBadge) countBadge.innerText = "0 Produk";
      return;
    }

    const items = result.data.items || [];
    if (countBadge) countBadge.innerText = `${items.length} Produk`;

    if (items.length === 0) {
      grid.innerHTML = "";
      if (emptyState) emptyState.style.display = "block";
      return;
    }

    if (emptyState) emptyState.style.display = "none";
    renderWishlistItems(items);

  } catch (err) {
    console.error("Gagal memuat wishlist:", err);
    grid.innerHTML = `<p style="grid-column: 1/-1; text-align: center; color: #dc2626; padding: 40px;">Terjadi kendala saat memuat wishlist. Silakan coba lagi.</p>`;
  }
}

function renderWishlistItems(items) {
  const grid = document.getElementById("wishlistGrid");
  if (!grid) return;
  grid.innerHTML = "";

  const defaultImg = "https://placehold.co/300x300/f1f5f9/475569?text=ON+Shop";

  items.forEach(item => {
    const card = document.createElement("div");
    card.className = "wishlist-card";
    card.id = `wishlistCard-${item.wishlist_id}`;

    const priceFormatted = Number(item.display_price || 0).toLocaleString("id-ID");
    const thumbUrl = item.image_url || defaultImg;

    card.innerHTML = `
      <div class="wishlist-thumb-wrapper">
        <a href="detail-produk.html?id=${item.product_id}">
          <img 
            src="${thumbUrl}" 
            alt="${escapeHtml(item.product_name)}" 
            class="wishlist-thumb" 
            loading="lazy" 
            onerror="this.src='${defaultImg}'"
          />
        </a>
        <button 
          type="button" 
          class="btn-remove-wishlist" 
          title="Hapus dari Wishlist"
          onclick="handleRemoveWishlist(${item.wishlist_id}, ${item.product_id}, '${escapeHtml(item.product_name)}')"
        >
          ✕
        </button>
      </div>

      <div class="wishlist-card-body">
        <a href="detail-produk.html?id=${item.product_id}" class="wishlist-prod-title" title="${escapeHtml(item.product_name)}">
          ${escapeHtml(item.product_name)}
        </a>

        <div class="wishlist-shop-info">
          <span>🏪 ${escapeHtml(item.shop_name || "Toko Penjual")}</span>
          <span>• 📍 ${escapeHtml(item.shop_city || "Indonesia")}</span>
        </div>

        <div class="wishlist-price-text">
          Rp ${priceFormatted}
        </div>

        <div class="wishlist-actions">
          <a href="detail-produk.html?id=${item.product_id}" class="btn-buy-wishlist">
            <span>🛒 Beli Sekarang</span>
          </a>
        </div>
      </div>
    `;

    grid.appendChild(card);
  });
}

async function handleRemoveWishlist(wishlistId, productId, prodName) {
  try {
    const res = await fetch(getWishlistApiUrl("remove"), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "include",
      body: JSON.stringify({ wishlist_id: wishlistId, product_id: productId })
    });

    const result = await res.json();
    if (result.status) {
      if (typeof showToast === "function") {
        showToast(`Produk "${prodName}" berhasil dihapus dari Wishlist.`, "info");
      }

      const card = document.getElementById(`wishlistCard-${wishlistId}`);
      if (card) {
        card.style.opacity = "0";
        card.style.transform = "scale(0.9)";
        setTimeout(() => {
          card.remove();
          // Update hitungan badge
          const remaining = document.querySelectorAll(".wishlist-card").length;
          const countBadge = document.getElementById("wishlistCountBadge");
          if (countBadge) countBadge.innerText = `${remaining} Produk`;

          if (remaining === 0) {
            const emptyState = document.getElementById("wishlistEmptyState");
            if (emptyState) emptyState.style.display = "block";
          }
        }, 200);
      }
    } else {
      if (typeof showToast === "function") {
        showToast(result.message || "Gagal menghapus produk dari wishlist.", "error");
      }
    }
  } catch (err) {
    console.error("Error menghapus wishlist:", err);
  }
}

function escapeHtml(text) {
  if (!text) return "";
  const map = { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" };
  return String(text).replace(/[&<>"']/g, (m) => map[m]);
}
