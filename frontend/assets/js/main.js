/* =========================================================
   AUTH GUARD + THÈME GLOBAL (mode sombre)
   ========================================================= */
(function () {
  "use strict";

  var TOKEN_KEY = "jwt_token";
  var LOGIN_URL = "/auth/login.html";
  var THEME_KEY = "adminHMD.colorTheme";

  // Pages accessibles sans token
  var PUBLIC_AUTH_PAGES = [
    "/auth/login.html",
    "/auth/register.html",
    "/auth/forgot-password.html"
  ];

  // ---------- Helpers Auth ----------
  function getToken() {
    try {
      return localStorage.getItem(TOKEN_KEY);
    } catch (e) {
      return null;
    }
  }

  function decodeToken(token) {
    try {
      var parts = token.split(".");
      if (parts.length !== 3) return null;

      var base64 = parts[1].replace(/-/g, "+").replace(/_/g, "/");
      var jsonPayload = decodeURIComponent(
        atob(base64)
          .split("")
          .map(function (c) {
            return "%" + ("00" + c.charCodeAt(0).toString(16)).slice(-2);
          })
          .join("")
      );
      return JSON.parse(jsonPayload);
    } catch (e) {
      return null;
    }
  }

  function isTokenExpired(token) {
    var payload = decodeToken(token);
    if (!payload || !payload.exp) return true;
    return payload.exp * 1000 <= Date.now();
  }

  function clearSession() {
    try {
      localStorage.removeItem(TOKEN_KEY);
      localStorage.removeItem("user");
      localStorage.removeItem("roles");
    } catch (e) {}
  }

  // ---------- Auth Guard ----------
  var currentPath = window.location.pathname;

  var isPublicAuthPage = PUBLIC_AUTH_PAGES.some(function (page) {
    return currentPath.includes(page);
  });

  if (!isPublicAuthPage) {
    var token = getToken();
    if (!token || isTokenExpired(token)) {
      clearSession();
      window.location.replace(LOGIN_URL);
    }
  }

  // ---------- Thème global (mode sombre) ----------
  function getPreferredTheme() {
    try {
      var saved = localStorage.getItem(THEME_KEY) || localStorage.getItem("theme");
      if (saved === "dark" || saved === "light") return saved;
    } catch (e) {}

    if (window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches) {
      return "dark";
    }
    return "light";
  }

  function applyTheme(theme) {
    document.documentElement.setAttribute("data-bs-theme", theme);
    document.documentElement.setAttribute("data-theme", theme);

    try {
      localStorage.setItem(THEME_KEY, theme);
      localStorage.setItem("theme", theme); // compatibilité anciennes pages
    } catch (e) {}

    // Met à jour les icônes lune / soleil
    document.querySelectorAll("[data-theme-icon]").forEach(function (icon) {
      icon.className = theme === "dark" ? "bi bi-sun" : "bi bi-moon-stars";
    });
  }

  // Applique le thème immédiatement (évite le flash blanc)
  applyTheme(getPreferredTheme());

  // Boutons de bascule sur toutes les pages
  document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll("[data-theme-toggle]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var current = document.documentElement.getAttribute("data-bs-theme") === "dark" ? "dark" : "light";
        applyTheme(current === "dark" ? "light" : "dark");
      });
    });
  });
})();