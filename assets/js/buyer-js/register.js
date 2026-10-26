/**
 * Logika Registrasi Akun & Visibilitas Password (Menggunakan Full Toast Support)
 * File: assets/js/buyer-js/register.js
 */

// 1. Fitur Toggle Intip Sandi
function togglePasswordVisibility(inputId, toggleEl) {
  const inputField = document.getElementById(inputId);
  const imgEl = toggleEl.querySelector(".eye-icon");
  if (!inputField || !imgEl) return;

  if (inputField.type === "password") {
    inputField.type = "text";
    imgEl.src = "../../assets/Images/eye-open.png";
    imgEl.alt = "Sembunyikan Sandi";
    toggleEl.setAttribute("title", "Sembunyikan Sandi");
  } else {
    inputField.type = "password";
    imgEl.src = "../../assets/Images/eye-closed.png";
    imgEl.alt = "Tampilkan Sandi";
    toggleEl.setAttribute("title", "Tampilkan Sandi");
  }

  const len = inputField.value.length;
  inputField.setSelectionRange(len, len);
}

// 2. Helper Deteksi URL Endpoint API
function getRegisterApiUrl() {
  return window.location.pathname.includes("/on-shop/")
    ? "/on-shop/api/buyer/auth_register.php"
    : "../../api/buyer/auth_register.php";
}

// 3. Penanganan Submit Registrasi Akun
document.addEventListener("DOMContentLoaded", () => {
  const regForm = document.getElementById("registerForm");
  if (!regForm) return;

  regForm.addEventListener("submit", async (e) => {
    e.preventDefault();

    const fullname = document.getElementById("fullname").value.trim();
    const email = document.getElementById("regEmail").value.trim();
    const phone = document.getElementById("regPhone").value.trim();
    const password = document.getElementById("password").value;
    const confirmPassword = document.getElementById("confirmPassword").value;

    // Validasi Kontak
    if (!email && !phone) {
      if (typeof showToast === "function") {
        showToast("Harap isi salah satu: Email atau Nomor Telepon!", "warning");
      }
      return;
    }

    // Validasi Kesesuaian Sandi
    if (password !== confirmPassword) {
      if (typeof showToast === "function") {
        showToast("Konfirmasi kata sandi tidak cocok!", "warning");
      }
      return;
    }

    // Ambil Token Cloudflare Turnstile
    let turnstileToken = "";
    if (typeof turnstile !== "undefined") {
      turnstileToken = turnstile.getResponse() || "";
    }
    if (!turnstileToken) {
      const turnstileInput = document.querySelector('[name="cf-turnstile-response"]');
      if (turnstileInput) turnstileToken = turnstileInput.value;
    }

    if (!turnstileToken) {
      if (typeof showToast === "function") {
        showToast("Harap selesaikan verifikasi captcha Turnstile!", "warning");
      }
      return;
    }

    const btn = document.getElementById("btnRegSubmit");
    if (btn) {
      btn.disabled = true;
      btn.innerText = "Membuat Akun...";
    }

    try {
      const res = await fetch(getRegisterApiUrl(), {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ 
          name: fullname, 
          email: email, 
          phone: phone, 
          password: password,
          turnstile_token: turnstileToken
        })
      });

      const data = await res.json();
      const userData = data.user || (data.data && data.data.user) || data.data;

      if (data.status && userData) {
        localStorage.setItem("currentUser", JSON.stringify(userData));

        if (typeof showToast === "function") {
          showToast(`Selamat datang di ON Shop, ${userData.name || 'Pengguna'}!`, "success", 2500);
        }

        setTimeout(() => {
          window.location.href = "index.html";
        }, 1400);

      } else {
        if (typeof showToast === "function") {
          showToast(data.message || "Pendaftaran gagal. Silakan coba lagi.", "error");
        }
        if (typeof turnstile !== "undefined") {
          turnstile.reset();
        }
      }
    } catch (err) {
      console.error("Gagal mendaftar:", err);
      if (typeof showToast === "function") {
        showToast("Terjadi kendala koneksi server saat pendaftaran.", "error");
      }
      if (typeof turnstile !== "undefined") {
        turnstile.reset();
      }
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.innerText = "Daftar Akun";
      }
    }
  });
});