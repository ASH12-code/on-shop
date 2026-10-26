/**
 * Logika Login Buyer & Super Admin ON Shop (Dengan Full Toast Support)
 * File: assets/js/buyer-js/login.js
 */

// 1. Fitur Toggle Intip Sandi
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

// 2. Helper Deteksi URL Endpoint API
function getLoginApiUrl() {
  return window.location.pathname.includes("/on-shop/")
    ? "/on-shop/api/buyer/auth_login.php"
    : "../../api/buyer/auth_login.php";
}

// 3. Penanganan Submit Login Terpadu
document.addEventListener("DOMContentLoaded", () => {
  const loginForm = document.getElementById("loginForm");
  const submitBtn = document.getElementById("btnLoginSubmit");

  if (!loginForm) return;

  loginForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    e.stopPropagation();

    const usernameInput = document.getElementById("username");
    const passwordInput = document.getElementById("password");

    const username = usernameInput ? usernameInput.value.trim() : "";
    const password = passwordInput ? passwordInput.value.trim() : "";

    // Validasi Form via Toast
    if (!username || !password) {
      if (typeof showToast === "function") {
        showToast("Email/Kontak dan Kata Sandi wajib diisi!", "warning");
      }
      return false;
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
      return false;
    }

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.innerText = "Memverifikasi...";
    }

    try {
      const response = await fetch(getLoginApiUrl(), {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ username, password, turnstile_token: turnstileToken })
      });

      const result = await response.json();
      const userData = result.user || (result.data && result.data.user) || (result.data && result.data.user_id ? result.data : null);

      if (result.status && userData) {
        // Simpan sesi user ke localStorage
        localStorage.setItem("currentUser", JSON.stringify(userData));

        const role = String(userData.role || "").toLowerCase().trim();
        const namaUser = userData.name || "Pengguna";

        // Tampilkan Toast Sukses
        if (typeof showToast === "function") {
          showToast(`Selamat datang kembali, ${namaUser}!`, "success", 2500);
        }

        // Cek URL pengalihan (Redirect)
        const urlParams = new URLSearchParams(window.location.search);
        const redirectParam = urlParams.get("redirect");
        const redirectSession = sessionStorage.getItem("redirectAfterLogin");
        const serverRedirect = result.redirect || (result.data && result.data.redirect);
        const targetRedirect = redirectParam || redirectSession || serverRedirect;

        sessionStorage.removeItem("redirectAfterLogin");

        // Beri jeda agar animasi toast terlihat sebelum pindah halaman
        setTimeout(() => {
          if (role === "admin") {
            window.location.replace("/on-shop/pages/admin/index.html");
          } else if (targetRedirect && !targetRedirect.includes("login.html")) {
            window.location.replace(targetRedirect);
          } else {
            window.location.replace("/on-shop/pages/buyer/index.html");
          }
        }, 1400);

      } else {
        if (typeof showToast === "function") {
          showToast(result.message || "Data akun atau kata sandi tidak cocok!", "error");
        }
        if (typeof turnstile !== "undefined") {
          turnstile.reset();
        }
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerText = "Masuk";
        }
      }
    } catch (err) {
      console.error("Login Error:", err);
      if (typeof showToast === "function") {
        showToast("Koneksi gagal. Pastikan Apache & MySQL aktif.", "error");
      }
      if (typeof turnstile !== "undefined") {
        turnstile.reset();
      }
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerText = "Masuk";
      }
    }

    return false;
  });
});