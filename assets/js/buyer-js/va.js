/**
 * Logika Pembayaran Virtual Account (VA) & Unggah Bukti Transfer
 * File: assets/js/buyer-js/va.js
 */

let activeOrder = null;
let selectedVaProofFile = null;

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

  const urlParams = new URLSearchParams(window.location.search);
  const bank = (urlParams.get("bank") || "BCA").toUpperCase();

  const bankTitle = document.getElementById("bankNameDisplay");
  if (bankTitle) {
    bankTitle.innerText = `${bank} Virtual Account`;
  }

  const vaNumberEl = document.getElementById("vaNumberDisplay");
  if (vaNumberEl) {
    vaNumberEl.innerText = formatVaNumber(activeOrder.va_number || generateDummyVa(bank));
  }

  const totalDisplay = document.getElementById("vaGrandTotal");
  if (totalDisplay) {
    totalDisplay.innerText = `Rp ${Number(activeOrder.grand_total || 0).toLocaleString("id-ID")}`;
  }
});

function formatVaNumber(va) {
  const str = String(va).replace(/\s+/g, "");
  return str.match(/.{1,4}/g)?.join(" ") || str;
}

function generateDummyVa(bank) {
  const prefix = bank === "MANDIRI" ? "8900" : bank === "BRI" ? "8801" : "8800";
  return prefix + Math.floor(1000000000 + Math.random() * 9000000000);
}

function copyVaNumber() {
  const vaEl = document.getElementById("vaNumberDisplay");
  if (!vaEl) return;
  const rawNumber = vaEl.innerText.replace(/\s+/g, "");

  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(rawNumber).then(() => {
      if (typeof showToast === "function") {
        showToast("Nomor VA berhasil disalin!", "success");
      } else {
        alert("Nomor VA berhasil disalin: " + rawNumber);
      }
    }).catch(() => {
      fallbackCopyText(rawNumber);
    });
  } else {
    fallbackCopyText(rawNumber);
  }
}

function fallbackCopyText(text) {
  const tempInput = document.createElement("input");
  tempInput.value = text;
  document.body.appendChild(tempInput);
  tempInput.select();
  document.execCommand("copy");
  document.body.removeChild(tempInput);
  if (typeof showToast === "function") {
    showToast("Nomor VA berhasil disalin!", "success");
  } else {
    alert("Nomor VA berhasil disalin: " + text);
  }
}

function handleVaProofFileSelected(event) {
  const file = event.target.files && event.target.files[0];
  if (!file) return;

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

  selectedVaProofFile = file;

  const previewBox = document.getElementById("vaProofPreviewBox");
  const previewImg = document.getElementById("vaProofPreviewImg");
  const fileNameText = document.getElementById("vaProofFileName");
  const dropzone = document.getElementById("vaUploadDropzone");
  const btnSubmit = document.getElementById("btnSubmitVaProof");

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

function removeSelectedVaFile() {
  selectedVaProofFile = null;
  const fileInput = document.getElementById("vaProofFileInput");
  if (fileInput) fileInput.value = "";

  const previewBox = document.getElementById("vaProofPreviewBox");
  const dropzone = document.getElementById("vaUploadDropzone");
  const btnSubmit = document.getElementById("btnSubmitVaProof");

  if (previewBox) previewBox.style.display = "none";
  if (dropzone) dropzone.style.display = "block";
  if (btnSubmit) {
    btnSubmit.disabled = true;
    btnSubmit.innerText = "Kirim Bukti Pembayaran";
  }
}

async function submitVaPaymentProof() {
  if (!activeOrder || !selectedVaProofFile) {
    if (typeof showToast === "function") {
      showToast("Silakan pilih berkas bukti transfer terlebih dahulu.", "warning");
    }
    return;
  }

  const btnSubmit = document.getElementById("btnSubmitVaProof");
  if (btnSubmit) {
    btnSubmit.disabled = true;
    btnSubmit.innerText = "Mengunggah Bukti...";
  }

  const formData = new FormData();
  formData.append("master_order_id", activeOrder.master_order_id || 0);
  if (activeOrder.sub_order_id) {
    formData.append("sub_order_id", activeOrder.sub_order_id);
  }
  formData.append("proof_image", selectedVaProofFile);

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
    console.error("Gagal mengunggah bukti pembayaran VA:", err);
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

window.copyVaNumber = copyVaNumber;
window.handleVaProofFileSelected = handleVaProofFileSelected;
window.removeSelectedVaFile = removeSelectedVaFile;
window.submitVaPaymentProof = submitVaPaymentProof;
window.redirectToOrders = redirectToOrders;