/**
 * Logika Dasbor Penjualan Toko (Rombak Total Dari Nol)
 * File: assets/js/seller-js/dashbord-toko.js
 */

// Daftarkan plugin label jika Chart.js & datalabels tersedia
if (typeof Chart !== "undefined" && typeof ChartDataLabels !== "undefined") {
  Chart.register(ChartDataLabels);
}

// Instance Chart Global
var salesChartInstance = null;
var topProductsChartInstance = null;

const DASHBOARD_API = "../../api/seller/get_dashboard_data.php";
const CHART_PALETTE = [
  "#2e8b57", "#3b82f6", "#f59e0b", "#ec4899", "#8b5cf6", "#06b6d4", "#ef4444"
];

document.addEventListener("DOMContentLoaded", async () => {
  await loadDashboardData();

  // Sinkronisasi ulang ukuran grafik saat orientasi layar berganti
  window.addEventListener("resize", () => {
    if (salesChartInstance) salesChartInstance.resize();
    if (topProductsChartInstance) topProductsChartInstance.resize();
  });
});

/**
 * 1. MENGAMBIL DATA UTAMA DASBOR DARI SERVER
 */
async function loadDashboardData() {
  const salesPeriodEl = document.getElementById("salesPeriodSelect");
  const prodPeriodEl  = document.getElementById("productsPeriodSelect");

  const salesPeriod = salesPeriodEl ? salesPeriodEl.value : "daily";
  const prodPeriod  = prodPeriodEl ? prodPeriodEl.value : "all_time";

  // Identifikasi sesi multi-seller
  const storedUser = JSON.parse(localStorage.getItem("currentUser")) || {};
  const authQuery  = storedUser.user_id ? `&auth_user_id=${storedUser.user_id}` : "";

  try {
    const res = await fetch(`${DASHBOARD_API}?sales_period=${salesPeriod}&products_period=${prodPeriod}${authQuery}`);
    if (!res.ok) return;

    const result = await res.json();
    if (!result.status) return;

    // A. Sinkron Profil Toko (Null-Safe)
    if (result.shop && result.shop.shop_name) {
      const sName = result.shop.shop_name;
      const nameEl = document.getElementById("sidebarShopName");
      const initEl = document.getElementById("sidebarShopInitial");
      if (nameEl) nameEl.innerText = sName;
      if (initEl) initEl.innerText = sName.substring(0, 2).toUpperCase();
    }

    // B. Kartu Metrik Keuangan & Pesanan
    if (result.metrics) {
      renderFinancialMetrics(result.metrics);
    }

    // C. 5 Pesanan Terbaru
    renderRecentOrders(result.recent_orders || []);

    // D. Inisialisasi Grafik
    if (result.sales_chart) {
      renderSalesChart(result.sales_chart);
    }
    if (result.top_products) {
      renderTopProductsChart(result.top_products);
    }

  } catch (err) {
    console.error("Gagal memuat dasbor penjualan:", err);
  }
}

/**
 * 2. MENGISI KARTU STATISTIK METRIK
 */
function renderFinancialMetrics(m) {
  const elAvailable = document.getElementById("statAvailableBalance");
  const elEscrow    = document.getElementById("statEscrowBalance");
  const elOrders    = document.getElementById("statOrdersToProcess");
  const elRevenue   = document.getElementById("statTotalRevenue");

  if (elAvailable) {
    elAvailable.innerText = "Rp " + new Intl.NumberFormat("id-ID").format(m.available_balance || 0);
  }
  if (elEscrow) {
    elEscrow.innerText = "Rp " + new Intl.NumberFormat("id-ID").format(m.escrow_balance || 0);
  }
  if (elOrders) {
    elOrders.innerHTML = `${m.orders_to_process || 0} <span class="text-small">Pesanan</span>`;
  }
  if (elRevenue) {
    elRevenue.innerText = "Rp " + new Intl.NumberFormat("id-ID").format(m.total_revenue || 0);
  }
}

/**
 * 3. MERENDER 5 PESANAN TERBARU
 */
function renderRecentOrders(orders) {
  const container = document.getElementById("recentOrdersContainer");
  if (!container) return;

  container.innerHTML = "";

  if (!orders || orders.length === 0) {
    container.innerHTML = `<p style="text-align:center; padding:18px; color:#64748b; font-size:12px;">Belum ada riwayat pesanan.</p>`;
    return;
  }

  // Tampilan Tabel Desktop
  let tableHtml = `
    <table class="desktop-order-table">
      <thead>
        <tr>
          <th>No. Pesanan</th>
          <th>Pembeli</th>
          <th>Total Belanja</th>
          <th>Kurir</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
  `;

  // Tampilan Kartu List Mobile (360px)
  let mobileCardsHtml = "";

  orders.forEach(order => {
    let badgeClass = "badge-warning";
    const st = (order.order_status || "").toUpperCase();

    if (st === "DIKIRIM") badgeClass = "badge-info";
    else if (st === "SELESAI") badgeClass = "badge-success";
    else if (st === "BATAL" || st === "DIKOMPLAIN") badgeClass = "badge-danger";

    const formattedAmount = "Rp " + new Intl.NumberFormat("id-ID").format(order.gross_product_amount || 0);
    const orderCode = order.sub_order_number || `ORD-${order.sub_order_id}`;
    const courierInfo = `${order.courier_name || 'JNE'} (${order.courier_service || 'Reguler'})`;

    tableHtml += `
      <tr>
        <td><code>${orderCode}</code></td>
        <td><strong>${order.buyer_name || 'Pembeli'}</strong></td>
        <td>${formattedAmount}</td>
        <td>${courierInfo}</td>
        <td><span class="badge ${badgeClass}">${st}</span></td>
      </tr>
    `;

    mobileCardsHtml += `
      <div class="mobile-order-card">
        <div class="mobile-order-row">
          <span class="label">No. Pesanan</span>
          <span class="val"><code>${orderCode}</code></span>
        </div>
        <div class="mobile-order-row">
          <span class="label">Pembeli</span>
          <span class="val">${order.buyer_name || 'Pembeli'}</span>
        </div>
        <div class="mobile-order-row">
          <span class="label">Total</span>
          <span class="val" style="color:#2e8b57; font-weight:bold;">${formattedAmount}</span>
        </div>
        <div class="mobile-order-row">
          <span class="label">Kurir</span>
          <span class="val">${courierInfo}</span>
        </div>
        <div class="mobile-order-row">
          <span class="label">Status</span>
          <span class="badge ${badgeClass}">${st}</span>
        </div>
      </div>
    `;
  });

  tableHtml += `</tbody></table>`;
  container.innerHTML = tableHtml + mobileCardsHtml;
}

/**
 * 4. GRAFIK OMZET BERSIH
 */
function renderSalesChart(chartData) {
  const canvas = document.getElementById("salesChart");
  if (!canvas || typeof Chart === "undefined") return;

  const ctx = canvas.getContext("2d");
  const isMobile = window.innerWidth <= 768;

  if (salesChartInstance) {
    salesChartInstance.data.labels = chartData.labels || [];
    salesChartInstance.data.datasets[0].data = chartData.data || [];
    salesChartInstance.data.datasets[0].backgroundColor = CHART_PALETTE.slice(0, (chartData.labels || []).length);
    salesChartInstance.update();
    return;
  }

  salesChartInstance = new Chart(ctx, {
    type: "bar",
    data: {
      labels: chartData.labels || [],
      datasets: [
        {
          label: "Omzet Bersih (Rp)",
          data: chartData.data || [],
          backgroundColor: CHART_PALETTE.slice(0, (chartData.labels || []).length),
          borderRadius: 4,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      layout: { padding: { top: 18, left: 0, right: 0, bottom: 0 } },
      scales: {
        y: { 
          beginAtZero: true,
          ticks: {
            color: "#64748b",
            callback: function(val) {
              if (val >= 1000000) return (val / 1000000).toFixed(0) + "Jt";
              if (val >= 1000) return (val / 1000).toFixed(0) + "Rb";
              return val;
            },
            font: { size: isMobile ? 8.5 : 10 }
          },
          grid: { color: "#f1f5f9" }
        },
        x: {
          ticks: { color: "#64748b", font: { size: isMobile ? 8.5 : 10 } },
          grid: { display: false }
        }
      },
      plugins: {
        legend: { display: false },
        datalabels: {
          anchor: "end",
          align: "top",
          offset: 1,
          color: "#1e293b",
          font: { size: isMobile ? 8 : 10, weight: "bold" },
          formatter: function(val) {
            if (val >= 1000000) return (val / 1000000).toFixed(1) + "Jt";
            if (val >= 1000) return (val / 1000).toFixed(0) + "Rb";
            return val > 0 ? val : "";
          }
        }
      }
    }
  });
}

/**
 * 5. GRAFIK TOP 5 PRODUK TERLARIS
 */
function renderTopProductsChart(prodData) {
  const canvas = document.getElementById("topProductsChart");
  if (!canvas || typeof Chart === "undefined") return;

  const ctx = canvas.getContext("2d");
  const isMobile = window.innerWidth <= 768;

  const maxLen = isMobile ? 12 : 22;
  const rawLabels = (prodData.labels && prodData.labels.length > 0) ? prodData.labels : ["Belum Ada Penjualan"];
  const prodDataValues = (prodData.data && prodData.data.length > 0) ? prodData.data : [0];
  const cleanLabels = rawLabels.map(name => {
    return name.length > maxLen ? name.substring(0, maxLen - 2) + ".." : name;
  });

  if (topProductsChartInstance) {
    topProductsChartInstance.data.labels = cleanLabels;
    topProductsChartInstance.data.datasets[0].data = prodDataValues;
    topProductsChartInstance.data.datasets[0].backgroundColor = CHART_PALETTE.slice(0, cleanLabels.length);
    topProductsChartInstance.update();
    return;
  }

  topProductsChartInstance = new Chart(ctx, {
    type: "bar",
    data: {
      labels: cleanLabels,
      datasets: [
        {
          label: "Unit Terjual",
          data: prodDataValues,
          backgroundColor: CHART_PALETTE.slice(0, cleanLabels.length),
          borderRadius: 4,
        },
      ],
    },
    options: {
      indexAxis: "y",
      responsive: true,
      maintainAspectRatio: false,
      layout: { padding: { left: 0, right: 24 } },
      scales: {
        x: { 
          beginAtZero: true,
          ticks: { stepSize: 1, precision: 0, color: "#64748b", font: { size: isMobile ? 8.5 : 10 } },
          grid: { color: "#f1f5f9" }
        },
        y: {
          ticks: { color: "#1e293b", font: { size: isMobile ? 8.5 : 10, weight: "600" } },
          grid: { display: false }
        }
      },
      plugins: {
        legend: { display: false },
        datalabels: {
          anchor: "end",
          align: "right",
          offset: 3,
          color: "#1e293b",
          font: { size: isMobile ? 8.5 : 10, weight: "bold" },
          formatter: function(val) { return val > 0 ? val : ""; }
        }
      }
    }
  });
}

/**
 * 6. FILTER DINAMIS FILTER PERIODE
 */
async function loadSalesChartOnly() {
  const salesPeriodEl = document.getElementById("salesPeriodSelect");
  const salesPeriod   = salesPeriodEl ? salesPeriodEl.value : "daily";

  const storedUser = JSON.parse(localStorage.getItem("currentUser")) || {};
  const authQuery  = storedUser.user_id ? `&auth_user_id=${storedUser.user_id}` : "";

  try {
    const res = await fetch(`${DASHBOARD_API}?sales_period=${salesPeriod}${authQuery}`);
    const result = await res.json();
    if (result.status && result.sales_chart) {
      renderSalesChart(result.sales_chart);
    }
  } catch (err) {
    console.error("Gagal menyaring omzet:", err);
  }
}

async function loadTopProductsChartOnly() {
  const prodPeriodEl = document.getElementById("productsPeriodSelect");
  const prodPeriod   = prodPeriodEl ? prodPeriodEl.value : "all_time";

  const storedUser = JSON.parse(localStorage.getItem("currentUser")) || {};
  const authQuery  = storedUser.user_id ? `&auth_user_id=${storedUser.user_id}` : "";

  try {
    const res = await fetch(`${DASHBOARD_API}?products_period=${prodPeriod}${authQuery}`);
    const result = await res.json();
    if (result.status && result.top_products) {
      renderTopProductsChart(result.top_products);
    }
  } catch (err) {
    console.error("Gagal menyaring produk:", err);
  }
}