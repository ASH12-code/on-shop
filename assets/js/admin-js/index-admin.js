/**
 * Logika Super Admin Dashboard (Backend-Connected, Titik Angka Presisi, & CSV Export)
 * Menggabungkan Patch Bug 2 (Credentials) & Bug 4 (Format Skala Titik Angka Grafik)
 * File: assets/js/admin-js/index-admin.js
 */

const ANALYTICS_API = "../../api/admin/get_dashboard_analytics.php";
var chartInstance = null;
var currentRecentFilter = "all";
var allRecentTransactions = [];

document.addEventListener("DOMContentLoaded", async () => {
  initChartWithPointLabels();
  await fetchDashboardMetrics();
});

// =========================================================
// 1. PLUGIN CHART.JS: FORMAT ANGKA TITIK & PADDING ATAS AMAN
// =========================================================
const pointLabelsPlugin = {
  id: "pointLabelsPlugin",
  afterDatasetsDraw(chart) {
    const { ctx } = chart;
    ctx.save();
    ctx.font = "bold 10.5px sans-serif";
    ctx.textAlign = "center";
    ctx.textBaseline = "bottom";

    chart.data.datasets.forEach((dataset, datasetIndex) => {
      const meta = chart.getDatasetMeta(datasetIndex);
      if (meta.hidden) return;

      meta.data.forEach((element, index) => {
        const rawVal = dataset.data[index];
        const numVal = parseFloat(rawVal) || 0;
        
        // Tampilkan label hanya jika bernilai lebih dari 0
        if (numVal > 0) {
          const displayLabel = numVal >= 1 
            ? `${numVal.toFixed(1)}Jt` 
            : `${(numVal * 1000).toFixed(0)}Rb`;

          ctx.fillStyle = dataset.borderColor;
          ctx.fillText(displayLabel, element.x, element.y - 6);
        }
      });
    });
    ctx.restore();
  }
};

function initChartWithPointLabels() {
  const canvasEl = document.getElementById("adminMetricsChart");
  if (!canvasEl) return;

  const ctx = canvasEl.getContext("2d");

  chartInstance = new Chart(ctx, {
    type: "line",
    data: {
      labels: ["Minggu 1", "Minggu 2", "Minggu 3", "Minggu 4"],
      datasets: [
        {
          label: "GMV Transaksi",
          data: [0, 0, 0, 0],
          borderColor: "#2e8b57",
          backgroundColor: "rgba(46, 139, 87, 0.12)",
          fill: true,
          tension: 0.35,
          borderWidth: 2.5,
          pointRadius: 5,
          pointHoverRadius: 7,
          pointBackgroundColor: "#ffffff",
          pointBorderColor: "#2e8b57",
          pointBorderWidth: 2.5
        },
        {
          label: "Komisi 5% Platform",
          data: [0, 0, 0, 0],
          borderColor: "#ea580c",
          backgroundColor: "rgba(234, 88, 12, 0.08)",
          fill: false,
          tension: 0.35,
          borderWidth: 2,
          pointRadius: 5,
          pointHoverRadius: 7,
          pointBackgroundColor: "#ffffff",
          pointBorderColor: "#ea580c",
          pointBorderWidth: 2
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      layout: { 
        padding: { top: 28, right: 12, left: 6, bottom: 6 } 
      },
      plugins: { 
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: function(context) {
              const val = context.parsed.y || 0;
              return `${context.dataset.label}: Rp ${(val * 1000000).toLocaleString("id-ID")}`;
            }
          }
        }
      },
      scales: {
        y: { 
          beginAtZero: true, 
          grid: { color: "#f1f5f9" },
          ticks: {
            callback: function(val) {
              return val + " Jt";
            }
          }
        },
        x: { grid: { display: false } }
      }
    },
    plugins: [pointLabelsPlugin]
  });
}

// =========================================================
// 2. AMBIL METRIK & GRAFIK DARI BACKEND MYSQL
// =========================================================
async function fetchDashboardMetrics() {
  const filterSelect = document.getElementById("periodFilterSelect");
  const filterKey = filterSelect ? filterSelect.value : "9_2026";

  try {
    const response = await fetch(`${ANALYTICS_API}?period_type=monthly&filter_key=${filterKey}`, {
      method: "GET",
      headers: { "Accept": "application/json" },
      credentials: "include"
    });
    const result = await response.json();

    if (!result.status) {
      console.error("Gagal membaca data analitik:", result.message);
      return;
    }

    const d = result.data;

    // Pasang Metrik Kartu KPI Nyata
    document.getElementById("gmvValueText").innerText = formatRupiah(d.gmv);
    document.getElementById("platformCommissionText").innerText = formatRupiah(d.commission);
    document.getElementById("totalOrdersText").innerText = `${d.total_orders} Pesanan`;
    document.getElementById("activeMerchantText").innerText = `${d.active_merchants} Merchant`;
    document.getElementById("totalMerchantNote").innerText = `Dari ${d.total_merchants} toko terdaftar`;
    document.getElementById("avgOrderValueText").innerText = `Rata-rata ${formatRupiah(d.avg_order_value)} / order`;

    // Metrik Tambahan Minggu 4: Sales Units & Pembeli
    const salesEl = document.getElementById("totalSalesUnitsText");
    if (salesEl) salesEl.innerText = `${Number(d.total_sales_units || 0).toLocaleString("id-ID")} Unit`;

    const buyersEl = document.getElementById("totalBuyersText");
    if (buyersEl) buyersEl.innerText = `${Number(d.total_buyers || 0).toLocaleString("id-ID")} Pembeli`;

    const newBuyersEl = document.getElementById("newBuyersNote");
    if (newBuyersEl) newBuyersEl.innerText = `+${Number(d.new_buyers || 0)} pembeli baru`;

    // Pasang Titik Angka Skala Juta ke Grafik
    if (chartInstance && d.chart) {
      chartInstance.data.labels = d.chart.labels;
      
      const cleanGmvPoints = d.chart.gmv_points.map(v => Number(parseFloat(v).toFixed(3)));
      const cleanCommPoints = cleanGmvPoints.map(v => Number((v * 0.05).toFixed(3)));

      chartInstance.data.datasets[0].data = cleanGmvPoints;
      chartInstance.data.datasets[1].data = cleanCommPoints;
      chartInstance.update();
    }

    // Render Widget Top 5 Produk & Top 5 Seller
    renderTopProducts(d.top_products || []);
    renderTopSellers(d.top_sellers || []);

    // Simpan & Render Transaksi Terkini
    allRecentTransactions = d.recent_transactions || [];
    renderRecentTransactions();

  } catch (err) {
    console.error("Kendala jaringan analitik database:", err);
  }
}

// =========================================================
// 3. TAB FILTER & TABEL TRANSAKSI TERKINI
// =========================================================
function switchRecentTab(filterKey, btnEl) {
  currentRecentFilter = filterKey;
  document.querySelectorAll(".table-tab-filter .tab-btn-pill").forEach(b => b.classList.remove("active"));
  if (btnEl) btnEl.classList.add("active");
  renderRecentTransactions();
}

function renderRecentTransactions() {
  const tbody = document.getElementById("recentTransactionsTableBody");
  if (!tbody) return;
  tbody.innerHTML = "";

  let list = allRecentTransactions;
  if (currentRecentFilter === "lunas") {
    list = list.filter(t => t.status === "Lunas");
  } else if (currentRecentFilter === "proses") {
    list = list.filter(t => t.status === "Sedang Dikemas" || t.status === "Diantar");
  }

  if (list.length === 0) {
    tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:18px; color:#64748b;">Belum ada pesanan pada kategori ini.</td></tr>`;
    return;
  }

  list.forEach(t => {
    const tr = document.createElement("tr");
    const comm = Math.round(t.amount * 0.05);

    let statusClass = "success";
    if (t.status === "Sedang Dikemas" || t.status === "Diantar") statusClass = "pending";
    if (t.status === "Batal" || t.status === "Sengketa") statusClass = "danger";

    tr.innerHTML = `
      <td><code>${escapeHtml(t.sub_order_number)}</code></td>
      <td><strong>${escapeHtml(t.shop)}</strong></td>
      <td><span class="prod-category-tag">(${escapeHtml(t.category)})</span> ${escapeHtml(t.item_name)}</td>
      <td><strong>${formatRupiah(t.amount)}</strong></td>
      <td style="color: #2e8b57; font-weight: bold;">${formatRupiah(comm)}</td>
      <td>${escapeHtml(t.method)}</td>
      <td><span class="status-pill ${statusClass}">${t.status}</span></td>
    `;
    tbody.appendChild(tr);
  });
}

// =========================================================
// 3b. WIDGET RANKING TOP 5 PRODUK & TOP 5 SELLER
// =========================================================
function renderTopProducts(products) {
  const tbody = document.getElementById("topProductsTableBody");
  if (!tbody) return;
  tbody.innerHTML = "";

  if (!products || products.length === 0) {
    tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; padding:16px; color:#64748b;">Belum ada data penjualan produk pada periode ini.</td></tr>`;
    return;
  }

  products.forEach((p, idx) => {
    const rankNum = idx + 1;
    const rankClass = rankNum === 1 ? "rank-1" : rankNum === 2 ? "rank-2" : rankNum === 3 ? "rank-3" : "rank-other";
    const imgUrl = p.product_image_url || "https://placehold.co/80x80?text=Produk";

    const tr = document.createElement("tr");
    tr.innerHTML = `
      <td style="text-align:center;"><span class="rank-badge ${rankClass}">${rankNum}</span></td>
      <td>
        <div class="prod-cell-flex">
          <img src="${escapeHtml(imgUrl)}" alt="${escapeHtml(p.product_name)}" class="prod-thumb-mini" onerror="this.src='https://placehold.co/80x80?text=Produk'">
          <span style="font-weight:600; font-size:13px; color:#1e293b; max-width:180px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="${escapeHtml(p.product_name)}">${escapeHtml(p.product_name)}</span>
        </div>
      </td>
      <td style="text-align:center;"><span class="qty-pill">${Number(p.total_sold || 0).toLocaleString("id-ID")} unit</span></td>
      <td style="text-align:right; font-weight:700; color:#0f172a; font-size:13px;">${formatRupiah(p.total_revenue)}</td>
    `;
    tbody.appendChild(tr);
  });
}

function renderTopSellers(sellers) {
  const tbody = document.getElementById("topSellersTableBody");
  if (!tbody) return;
  tbody.innerHTML = "";

  if (!sellers || sellers.length === 0) {
    tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; padding:16px; color:#64748b;">Belum ada data merchant pada periode ini.</td></tr>`;
    return;
  }

  sellers.forEach((s, idx) => {
    const rankNum = idx + 1;
    const rankClass = rankNum === 1 ? "rank-1" : rankNum === 2 ? "rank-2" : rankNum === 3 ? "rank-3" : "rank-other";
    const initial = (s.shop_name || "Toko").charAt(0).toUpperCase();

    const tr = document.createElement("tr");
    tr.innerHTML = `
      <td style="text-align:center;"><span class="rank-badge ${rankClass}">${rankNum}</span></td>
      <td>
        <div class="shop-cell-flex">
          <div class="shop-avatar-mini">${escapeHtml(initial)}</div>
          <div style="line-height:1.2;">
            <div style="font-weight:600; font-size:13px; color:#1e293b;">${escapeHtml(s.shop_name)}</div>
            <small style="color:#64748b; font-size:11px;">📍 ${escapeHtml(s.shop_city || 'Pangkalpinang')}</small>
          </div>
        </div>
      </td>
      <td style="text-align:center;"><span style="font-weight:600; font-size:12.5px; color:#334155;">${Number(s.total_orders || 0).toLocaleString("id-ID")} pesanan</span></td>
      <td style="text-align:right; font-weight:700; color:#2e8b57; font-size:13px;">${formatRupiah(s.total_revenue)}</td>
    `;
    tbody.appendChild(tr);
  });
}

// =========================================================
// 4. EKSPOR LAPORAN CSV & TOAST
// =========================================================
function handleExportReportCSV() {
  const filterSelect = document.getElementById("periodFilterSelect");
  const periodLabel = filterSelect ? filterSelect.options[filterSelect.selectedIndex].text : "Periode";

  const gmv = document.getElementById("gmvValueText").innerText.replace(/[^0-9]/g, "");
  const comm = document.getElementById("platformCommissionText").innerText.replace(/[^0-9]/g, "");
  const orders = document.getElementById("totalOrdersText").innerText.replace(/[^0-9]/g, "");
  const merchants = document.getElementById("activeMerchantText").innerText.replace(/[^0-9]/g, "");
  const salesUnits = document.getElementById("totalSalesUnitsText") ? document.getElementById("totalSalesUnitsText").innerText.replace(/[^0-9]/g, "") : "0";
  const buyers = document.getElementById("totalBuyersText") ? document.getElementById("totalBuyersText").innerText.replace(/[^0-9]/g, "") : "0";

  const today = new Date().toLocaleDateString("id-ID", { day: "numeric", month: "long", year: "numeric" });

  let csvContent = "data:text/csv;charset=utf-8,";
  csvContent += "Laporan Ringkasan Eksekutif Super Admin - ON Shop\n";
  csvContent += `Periode,${periodLabel}\n`;
  csvContent += `Tanggal Unduh,${today}\n\n`;
  csvContent += "Metrik Platform,Nilai Database\n";
  csvContent += `Gross Merchandise Value (GMV),Rp ${Number(gmv).toLocaleString("id-ID")}\n`;
  csvContent += `Komisi Bersih Platform (5%),Rp ${Number(comm).toLocaleString("id-ID")}\n`;
  csvContent += `Total Transaksi Selesai,${orders} Pesanan\n`;
  csvContent += `Volume Produk Terjual,${salesUnits} Unit\n`;
  csvContent += `Total Pembeli Terdaftar,${buyers} Akun\n`;
  csvContent += `Merchant Aktif Bertransaksi,${merchants} Toko\n`;

  const encodedUri = encodeURI(csvContent);
  const link = document.createElement("a");
  link.setAttribute("href", encodedUri);
  link.setAttribute("download", `laporan_superadmin_on_shop_${Date.now()}.csv`);
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);

  showToast(`Laporan ${periodLabel} berhasil diekspor (.CSV)!`);
}

function showToast(message) {
  const toast = document.getElementById("toastNotification");
  const msgEl = document.getElementById("toastMessage");
  if (!toast || !msgEl) return;

  msgEl.innerText = message;
  toast.classList.add("show");
  setTimeout(() => { toast.classList.remove("show"); }, 2800);
}

function formatRupiah(num) {
  return "Rp " + Number(num || 0).toLocaleString("id-ID");
}

function escapeHtml(text) {
  if (!text) return "";
  const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
  return String(text).replace(/[&<>"']/g, m => map[m]);
}