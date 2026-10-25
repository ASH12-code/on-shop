/**
 * Logika Alur Lupa Password & Verifikasi OTP 2 Jalur (SMS & Gmail)
 * File: assets/js/buyer-js/forgot-password.js
 */

const FORGOT_API_URL = window.location.pathname.includes("/on-shop/")
  ? "/on-shop/api/buyer/auth_forgot_password.php"
  : "../../api/buyer/auth_forgot_password.php";

let currentUserId = null;
let currentIdentifier = "";
let selectedChannel = "";
let currentResetToken = "";
let resendTimer = null;
let lastTurnstileToken = "";

// Helper Toggle Intip Sandi
function togglePasswordVisibility(inputId, btn) {
  const input = document.getElementById(inputId);
  if (!input) return;

  const icon = btn.querySelector("img");
  if (input.type === "password") {
    input.type = "text";
    if (icon) {
      icon.src = "../../assets/Images/eye-open.png";
      icon.alt = "Sembunyikan Sandi";
    }
  } else {
    input.type = "password";
    if (icon) {
      icon.src = "../../assets/Images/eye-closed.png";
      icon.alt = "Tampilkan Sandi";
    }
  }
}

// Navigasi Antar-Tahap Formulir
function goToStep(stepNumber) {
  document.querySelectorAll(".step-section").forEach((sec) => sec.classList.remove("active"));
  const target = document.getElementById(`step${stepNumber}Form`);
  if (target) {
    target.classList.add("active");
  }

  const titleEl = document.getElementById("forgotHeaderTitle");
  const descEl  = document.getElementById("forgotHeaderDesc");

  if (stepNumber === 1) {
    if (titleEl) titleEl.innerText = "Pemulihan Kata Sandi";
    if (descEl) descEl.innerText = "Masukkan email atau nomor HP untuk menerima kode OTP.";
  } else if (stepNumber === 2) {
    if (titleEl) titleEl.innerText = "Pilih Metode OTP";
    if (descEl) descEl.innerText = "Tentukan saluran pengiriman kode verifikasi.";
  } else if (stepNumber === 3) {
    if (titleEl) titleEl.innerText = "Verifikasi Kode OTP";
    if (descEl) descEl.innerText = "Masukkan 6 digit kode yang telah dikirimkan.";
  } else if (stepNumber === 4) {
    if (titleEl) titleEl.innerText = "Buat Sandi Baru";
    if (descEl) descEl.innerText = "Pastikan kata sandi baru Anda aman dan mudah diingat.";
  }
}

// Mulai Countdown Timer Kirim Ulang OTP (60 Detik)
function startResendCountdown(seconds = 60) {
  clearInterval(resendTimer);
  const btn = document.getElementById("btnResendOtp");
  const span = document.getElementById("resendCountdown");

  let remaining = seconds;
  if (btn) btn.disabled = true;

  resendTimer = setInterval(() => {
    remaining--;
    if (span) span.innerText = remaining;

    if (remaining <= 0) {
      clearInterval(resendTimer);
      if (btn) {
        btn.disabled = false;
        btn.innerText = "Kirim Ulang Kode OTP";
      }
    }
  }, 1000);
}

document.addEventListener("DOMContentLoaded", () => {
  const step1Form = document.getElementById("step1Form");
  const step2Form = document.getElementById("step2Form");
  const step3Form = document.getElementById("step3Form");
  const step4Form = document.getElementById("step4Form");
  const btnResend = document.getElementById("btnResendOtp");

  // =========================================================================
  // TAHAP 1: KIRIM IDENTIFIER & VERIFIKASI TURNSTILE
  // =========================================================================
  if (step1Form) {
    step1Form.addEventListener("submit", async (e) => {
      e.preventDefault();

      const inputEl = document.getElementById("identifierInput");
      const identifier = inputEl ? inputEl.value.trim() : "";

      if (!identifier) {
        if (typeof showToast === "function") showToast("Email atau Nomor HP wajib diisi!", "warning");
        return;
      }

      // Ambil Turnstile Token
      let turnstileToken = "";
      if (typeof turnstile !== "undefined") {
        turnstileToken = turnstile.getResponse() || "";
      }
      if (!turnstileToken) {
        const tInput = document.querySelector('[name="cf-turnstile-response"]');
        if (tInput) turnstileToken = tInput.value;
      }

      if (!turnstileToken) {
        if (typeof showToast === "function") showToast("Harap selesaikan verifikasi captcha Turnstile!", "warning");
        return;
      }

      lastTurnstileToken = turnstileToken;
      currentIdentifier  = identifier;

      const submitBtn = document.getElementById("btnSubmitStep1");
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerText = "Memeriksa Akun...";
      }

      try {
        const res = await fetch(`${FORGOT_API_URL}?action=request_otp`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            identifier: identifier,
            turnstile_token: turnstileToken
          })
        });

        const result = await res.json();

        if (result.status) {
          currentUserId = result.data.user_id;

          // Skenario 1: Jika memiliki Email DAN No HP
          if (result.data.need_channel_selection || result.need_channel_selection) {
            const channels = (result.data && result.data.channels) || result.channels || [];
            renderChannelSelection(channels);
            goToStep(2);
          } else {
            // Langsung terkirim ke 1 kontak
            handleOtpDispatched(result.data, result.message);
            goToStep(3);
          }
        } else {
          if (typeof showToast === "function") {
            showToast(result.message || "Akun tidak ditemukan!", "error");
          }
          if (typeof turnstile !== "undefined") turnstile.reset();
        }
      } catch (err) {
        console.error("Forgot Password Error:", err);
        if (typeof showToast === "function") showToast("Koneksi gagal. Silakan coba lagi.", "error");
        if (typeof turnstile !== "undefined") turnstile.reset();
      } finally {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerText = "Kirim Kode OTP";
        }
      }
    });
  }

  // Render Pilihan Channel (Skenario 1)
  function renderChannelSelection(channels) {
    const container = document.getElementById("channelsContainer");
    if (!container) return;

    container.innerHTML = "";
    selectedChannel = channels.length > 0 ? channels[0].type : "EMAIL";

    channels.forEach((ch, idx) => {
      const card = document.createElement("div");
      card.className = `channel-choice-card ${idx === 0 ? "selected" : ""}`;
      card.dataset.channel = ch.type;

      const icon = ch.type === "EMAIL" ? "✉️" : "📱";
      card.innerHTML = `
        <span class="channel-icon">${icon}</span>
        <div class="channel-info">
          <strong>Kirim via ${ch.label}</strong>
          <small>${ch.masked}</small>
        </div>
      `;

      card.addEventListener("click", () => {
        document.querySelectorAll(".channel-choice-card").forEach((c) => c.classList.remove("selected"));
        card.classList.add("selected");
        selectedChannel = ch.type;
      });

      container.appendChild(card);
    });
  }

  // =========================================================================
  // TAHAP 2: PILIH METODE PENGIRIMAN OTP & DISPATCH
  // =========================================================================
  if (step2Form) {
    step2Form.addEventListener("submit", async (e) => {
      e.preventDefault();

      if (!selectedChannel) {
        if (typeof showToast === "function") showToast("Silakan pilih saluran pengiriman OTP!", "warning");
        return;
      }

      const submitBtn = document.getElementById("btnSubmitStep2");
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerText = "Mengirim Kode...";
      }

      try {
        const res = await fetch(`${FORGOT_API_URL}?action=request_otp`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            identifier: currentIdentifier,
            selected_channel: selectedChannel,
            turnstile_token: lastTurnstileToken || "TEST_PASS_TOKEN_MOCK"
          })
        });

        const result = await res.json();

        if (result.status) {
          handleOtpDispatched(result.data, result.message);
          goToStep(3);
        } else {
          if (typeof showToast === "function") showToast(result.message || "Gagal mengirim OTP.", "error");
        }
      } catch (err) {
        console.error("Step 2 Error:", err);
        if (typeof showToast === "function") showToast("Gagal terhubung ke server.", "error");
      } finally {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerText = "Lanjut Kirim Kode OTP";
        }
      }
    });
  }

  function handleOtpDispatched(data, msg) {
    if (typeof showToast === "function") {
      showToast(msg || "Kode OTP berhasil dikirim!", "success", 3000);
    }

    // Tampilkan banner demo jika ada debug_otp
    const demoBox = document.getElementById("demoOtpBox");
    if (demoBox && data.debug_otp) {
      demoBox.style.display = "block";
      demoBox.innerHTML = `<strong>KODE OTP DEMO:</strong> <span style="font-size:16px; font-weight:bold; letter-spacing:2px;">${data.debug_otp}</span><br><small style="color:#64748b;">(Tercatat di server log / email tujuan)</small>`;
    }

    startResendCountdown(60);
  }

  // =========================================================================
  // TAHAP 3: VERIFIKASI KODE OTP
  // =========================================================================
  if (step3Form) {
    step3Form.addEventListener("submit", async (e) => {
      e.preventDefault();

      const otpInput = document.getElementById("otpCodeInput");
      const otpCode  = otpInput ? otpInput.value.trim() : "";

      if (!otpCode || otpCode.length !== 6) {
        if (typeof showToast === "function") showToast("Masukkan 6 digit kode OTP yang valid!", "warning");
        return;
      }

      const submitBtn = document.getElementById("btnSubmitStep3");
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerText = "Memverifikasi...";
      }

      try {
        const res = await fetch(`${FORGOT_API_URL}?action=verify_otp`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            user_id: currentUserId,
            otp_code: otpCode
          })
        });

        const result = await res.json();

        if (result.status && result.data && result.data.reset_token) {
          currentResetToken = result.data.reset_token;
          if (typeof showToast === "function") {
            showToast("Kode OTP valid! Silakan tentukan kata sandi baru.", "success", 2500);
          }
          goToStep(4);
        } else {
          if (typeof showToast === "function") {
            showToast(result.message || "Kode OTP salah atau kedaluwarsa!", "error");
          }
        }
      } catch (err) {
        console.error("Step 3 Error:", err);
        if (typeof showToast === "function") showToast("Gagal memverifikasi OTP.", "error");
      } finally {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerText = "Verifikasi Kode OTP";
        }
      }
    });
  }

  // Tombol Kirim Ulang OTP
  if (btnResend) {
    btnResend.addEventListener("click", async () => {
      btnResend.disabled = true;
      btnResend.innerText = "Mengirim ulang...";

      try {
        const res = await fetch(`${FORGOT_API_URL}?action=request_otp`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            identifier: currentIdentifier,
            selected_channel: selectedChannel || undefined,
            turnstile_token: lastTurnstileToken || "TEST_PASS_TOKEN_MOCK"
          })
        });

        const result = await res.json();
        if (result.status) {
          handleOtpDispatched(result.data, "Kode OTP baru telah dikirimkan!");
        } else {
          if (typeof showToast === "function") showToast(result.message || "Gagal mengirim ulang OTP.", "error");
          btnResend.disabled = false;
          btnResend.innerText = "Kirim Ulang Kode OTP";
        }
      } catch (err) {
        console.error("Resend Error:", err);
        btnResend.disabled = false;
        btnResend.innerText = "Kirim Ulang Kode OTP";
      }
    });
  }

  // =========================================================================
  // TAHAP 4: ATUR KATA SANDI BARU
  // =========================================================================
  if (step4Form) {
    step4Form.addEventListener("submit", async (e) => {
      e.preventDefault();

      const newPassInput     = document.getElementById("newPassword");
      const confirmPassInput = document.getElementById("confirmNewPassword");

      const newPassword     = newPassInput ? newPassInput.value.trim() : "";
      const confirmPassword = confirmPassInput ? confirmPassInput.value.trim() : "";

      if (!newPassword || newPassword.length < 6) {
        if (typeof showToast === "function") showToast("Kata sandi baru minimal 6 karakter!", "warning");
        return;
      }

      if (newPassword !== confirmPassword) {
        if (typeof showToast === "function") showToast("Konfirmasi kata sandi tidak cocok!", "warning");
        return;
      }

      const submitBtn = document.getElementById("btnSubmitStep4");
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerText = "Menyimpan Sandi...";
      }

      try {
        const res = await fetch(`${FORGOT_API_URL}?action=reset_password`, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            reset_token: currentResetToken,
            new_password: newPassword,
            confirm_password: confirmPassword
          })
        });

        const result = await res.json();

        if (result.status) {
          if (typeof showToast === "function") {
            showToast("Kata sandi berhasil diperbarui! Mengalihkan ke halaman masuk...", "success", 2500);
          }
          setTimeout(() => {
            window.location.href = "login.html";
          }, 1800);
        } else {
          if (typeof showToast === "function") {
            showToast(result.message || "Gagal mengubah kata sandi.", "error");
          }
        }
      } catch (err) {
        console.error("Step 4 Error:", err);
        if (typeof showToast === "function") showToast("Terjadi kesalahan saat menyimpan sandi.", "error");
      } finally {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerText = "Simpan Kata Sandi Baru";
        }
      }
    });
  }
});
