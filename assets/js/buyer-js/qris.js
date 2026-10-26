/**
 * Logika Pembayaran QRIS Resmi & Unggah Bukti Transfer
 * File: assets/js/buyer-js/qris.js
 */

let activeOrder = null;
let selectedProofFile = null;

document.addEventListener("DOMContentLoaded", () => {
  const sessionData = sessionStorage.getItem("activeOrderSession");

  if (!sessionData) {
    if (typeof showToast === "function") {
      showToast("Data transaksi tidak ditemukan. Mengalihkan...", "warning");
    }
    setTimeout(() => {
      window.location.href = "pesanan-saya.html?tab=belum_bayar";
    }, 1500);
    return;
  }

  try {
    activeOrder = JSON.parse(sessionData);
  } catch (e) {
    console.error("Gagal parse activeOrderSession:", e);
    window.location.href = "pesanan-saya.html?tab=belum_bayar";
    return;
  }

  const totalDisplay = document.getElementById("qrisGrandTotal");
  if (totalDisplay) {
    totalDisplay.innerText = `Rp ${Number(activeOrder.grand_total || 0).toLocaleString("id-ID")}`;
  }
});

function handleProofFileSelected(event) {
  const file = event.target.files && event.target.files[0];
  if (!file) return;

  // Validasi ukuran maksimal 2MB
  const maxBytes = 2 * 1024 * 1024;
  if (file.size > maxBytes) {
    if (typeof showToast === "function") {
      showToast("Ukuran file terlalu besar. Maksimal 2 MB.", "error");
    } else {
      alert("Ukuran file terlalu besar. Maksimal 2 MB.");
    }
    event.target.value = "";
    return;
  }

  // Validasi tipe berkas
  const allowedTypes = ["image/jpeg", "image/png", "image/webp", "image/jpg"];
  if (!allowedTypes.includes(file.type)) {
    if (typeof showToast === "function") {
      showToast("Format file harus berupa gambar (JPG, PNG, atau WEBP).", "error");
    } else {
      alert("Format file harus berupa gambar (JPG, PNG, atau WEBP).");
    }
    event.target.value = "";
    return;
  }

  selectedProofFile = file;

  // Pratinjau gambar
  const previewBox = document.getElementById("proofPreviewBox");
  const previewImg = document.getElementById("proofPreviewImg");
  const fileNameText = document.getElementById("proofFileName");
  const dropzone = document.getElementById("uploadDropzone");
  const btnSubmit = document.getElementById("btnSubmitProof");

  const reader = new FileReader();
  reader.onload = (e) => {
    if (previewImg) previewImg.src = e.target.result;
    if (fileNameText) fileNameText.innerText = `${file.name} (${(file.size / 1024).toFixed(1)} KB)`;
    if (previewBox) previewBox.style.display = "block";
    if (dropzone) dropzone.style.display = "none";
    if (btnSubmit) {
      btnSubmit.disabled = false;
      btnSubmit.innerText = "Kirim Bukti Pembayaran";
    }
  };
  reader.readAsDataURL(file);
}

function removeSelectedFile() {
  selectedProofFile = null;
  const fileInput = document.getElementById("proofFileInput");
  if (fileInput) fileInput.value = "";

  const previewBox = document.getElementById("proofPreviewBox");
  const dropzone = document.getElementById("uploadDropzone");
  const btnSubmit = document.getElementById("btnSubmitProof");

  if (previewBox) previewBox.style.display = "none";
  if (dropzone) dropzone.style.display = "block";
  if (btnSubmit) {
    btnSubmit.disabled = true;
    btnSubmit.innerText = "Kirim Bukti Pembayaran";
  }
}

async function submitPaymentProof() {
  if (!activeOrder || !selectedProofFile) {
    if (typeof showToast === "function") {
      showToast("Silakan pilih file bukti transfer terlebih dahulu.", "warning");
    }
    return;
  }

  const btnSubmit = document.getElementById("btnSubmitProof");
  if (btnSubmit) {
    btnSubmit.disabled = true;
    btnSubmit.innerText = "Mengunggah Bukti...";
  }

  const formData = new FormData();
  formData.append("master_order_id", activeOrder.master_order_id || 0);
  if (activeOrder.sub_order_id) {
    formData.append("sub_order_id", activeOrder.sub_order_id);
  }
  formData.append("proof_image", selectedProofFile);

  try {
    const res = await fetch("/on-shop/api/buyer/upload_payment_proof.php", {
      method: "POST",
      body: formData
    });

    const result = await res.json();

    if (result.status) {
      sessionStorage.removeItem("activeOrderSession");
      const modal = document.getElementById("paymentSuccessModal");
      if (modal) {
        modal.classList.remove("hidden");
      } else {
        redirectToOrders();
      }
    } else {
      if (typeof showToast === "function") {
        showToast(result.message || "Gagal mengunggah bukti pembayaran.", "error");
      } else {
        alert(result.message || "Gagal mengunggah bukti pembayaran.");
      }
      if (btnSubmit) {
        btnSubmit.disabled = false;
        btnSubmit.innerText = "Kirim Bukti Pembayaran";
      }
    }
  } catch (err) {
    console.error("Gagal mengunggah bukti pembayaran:", err);
    if (typeof showToast === "function") {
      showToast("Terjadi kendala jaringan saat mengunggah berkas.", "error");
    } else {
      alert("Terjadi kendala jaringan saat mengunggah berkas.");
    }
    if (btnSubmit) {
      btnSubmit.disabled = false;
      btnSubmit.innerText = "Kirim Bukti Pembayaran";
    }
  }
}

function redirectToOrders() {
  window.location.href = "pesanan-saya.html?tab=belum_bayar";
}

window.handleProofFileSelected = handleProofFileSelected;
window.removeSelectedFile = removeSelectedFile;
window.submitPaymentProof = submitPaymentProof;
window.redirectToOrders = redirectToOrders;