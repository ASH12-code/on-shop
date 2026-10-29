/**
 * Logika Pencarian & Pengurutan Produk ON Shop (Dari Nol)
 * Fitur: Rute Eksplisit Anti-Loop (&from=search), Simpan History Search Sesi,
 * Dual-Param Support (?keyword= & ?q=), dan Filter Sortir Real-Time
 * File: assets/js/buyer-js/search.js
 */

let currentKeyword = "";
let currentSortMode = "terkait";

document.addEventListener("DOMContentLoaded", () => {
  // 1. Ekstrak kata kunci dari URL (?keyword=... atau ?q=...)
  const urlParams = new URLSearchParams(window.location.search);
  currentKeyword = (urlParams.get("keyword") || urlParams.get("q") || "").trim();

  // 2. Rekam URL halaman pencarian saat ini agar detail produk tahu rute kembali yang presisi
  sessionStorage.setItem("last_search_url", window.location.href);

  // 3. Pasang kata kunci ke teks header dan kotak input pencarian
  const keywordHeader = document.getElementById("searchedKeywordText");
  const searchInput = document.getElementById("pageSearchInput");

  if (keywordHeader) {
    keywordHeader.innerText = currentKeyword ? `'${currentKeyword}'` : "Semua Produk";
  }
  if (searchInput) {
    searchInput.value = currentKeyword;
  }

  initSearchControls();
  fetchSearchResults();
});

// =============================================================
// KONTROL SORTIR & EVENT INPUT PENCARIAN
// =============================================================
function initSearchControls() {
  const sortButtons = document.querySelectorAll(".sort-btn");
  sortButtons.forEach((btn) => {
    btn.addEventListener("click", function () {
      sortButtons.forEach((b) => b.classList.remove("active"));
      this.classList.add("active");

      const priceSelect = document.getElementById("priceSortSelect");
      if (priceSelect) priceSelect.value = "";

      currentSortMode = this.getAttribute("data-sort");
      fetchSearchResults();
    });
  });

  const searchInput = document.getElementById("pageSearchInput");
  const searchBtn = document.getElementById("pageSearchBtn");

  function triggerSearch() {
    const val = searchInput ? searchInput.value.trim() : "";
    if (!val) {
      if (typeof showToast === "function") {
        showToast("Ketik nama barang yang ingin dicari!", "warning");
      }
      return;
    }
    window.location.href = `search.html?keyword=${encodeURIComponent(val)}`;
  }

  if (searchBtn) searchBtn.addEventListener("click", triggerSearch);
  if (searchInput) {
    searchInput.addEventListener("keypress", (e) => {
      if (e.key === "Enter") triggerSearch();
    });
  }
}

// Handler Dropdown Urutan Harga
function handlePriceSortChange(selectEl) {
  if (!selectEl || selectEl.value === "") return;

  document.querySelectorAll(".sort-btn").forEach((b) => b.classList.remove("active"));
  currentSortMode = selectEl.value;
  fetchSearchResults();
}

// =============================================================
// PENGAMBILAN DATA PENCARIAN DARI API
// =============================================================
async function fetchSearchResults() {
  const grid = document.getElementById("searchProductGrid");
  const emptyBox = document.getElementById("searchEmptyState");
  if (!grid) return;

  grid.innerHTML = `<p style="grid-column: 1/-1; text-align: center; color: #888; padding: 40px;">Mencari produk...</p>`;
  if (emptyBox) emptyBox.classList.add("hidden");

  try {
    const targetUrl = `/on-shop/api/buyer/search_products.php?keyword=${encodeURIComponent(currentKeyword)}&sort=${encodeURIComponent(currentSortMode)}`;
    const response = await fetch(targetUrl);
    const result = await response.json();

    if (!result.status || !result.data || result.data.length === 0) {
      grid.innerHTML = "";
      if (emptyBox) emptyBox.classList.remove("hidden");
      return;
    }

    renderSearchResults(result.data);

  } catch (error) {
    console.error("Gagal melakukan pencarian:", error);
    grid.innerHTML = `<p style="grid-column: 1/-1; text-align: center; color: #ef4444; padding: 30px;">Terjadi kendala saat memuat hasil pencarian.</p>`;
    if (typeof showToast === "function") {
      showToast("Gagal menyambung ke server pencarian.", "error");
    }
  }
}

// =============================================================
// RENDER KARTU PRODUK (MEMBAWA PARAMETER RUTE &from=search)
// =============================================================
function renderSearchResults(products) {
  const grid = document.getElementById("searchProductGrid");
  const emptyBox = document.getElementById("searchEmptyState");
  if (!grid) return;

  grid.innerHTML = "";
  if (emptyBox) emptyBox.classList.add("hidden");

  // Perbarui jejak sesi URL pencarian
  sessionStorage.setItem("last_search_url", window.location.href);

  products.forEach((prod) => {
    const card = document.createElement("div");
    card.className = "product-card";

    const imageSrc = prod.image_url
      ? prod.image_url
      : "https://placehold.co/300x300/cccccc/333333?text=No+Image";

    // Sematkan &from=search pada link menuju detail produk
    card.innerHTML = `
      <a href="detail-produk.html?id=${prod.product_id}&from=search" style="text-decoration: none; color: inherit;">
        <div class="product-img-wrapper">
          <img 
            src="${imageSrc}" 
            alt="${escapeHtml(prod.product_name)}" 
            class="product-thumb"
            loading="lazy"
            onerror="this.src='https://placehold.co/300x300/cccccc/333333?text=No+Image'"
          >
        </div>
        <div class="product-card-body">
          <h4 class="product-title" title="${escapeHtml(prod.product_name)}">${escapeHtml(prod.product_name)}</h4>
          <p class="product-price">Rp ${Number(prod.display_price).toLocaleString("id-ID")}</p>
          <div class="product-meta-row">
            <span class="product-location">📍 ${escapeHtml(prod.shop_city || "Indonesia")}</span>
            <span class="product-rating">⭐ ${prod.rating_avg || "5.0"}</span>
          </div>
        </div>
      </a>
    `;

    grid.appendChild(card);
  });
}

function escapeHtml(text) {
  if (!text) return "";
  const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
  return String(text).replace(/[&<>"']/g, (m) => map[m]);
}

window.handlePriceSortChange = handlePriceSortChange;