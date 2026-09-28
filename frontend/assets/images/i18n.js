/* ============================================================
   adminHMD - i18n
   Path : /assets/i18n/{fr|en}.json
   Bouton langue = drapeau uniquement (🇫🇷 / 🇺🇸)
============================================================ */

(function () {
    "use strict";

    const I18N_PATH = "/assets/i18n/";
    const DEFAULT_LANGUAGE = "fr";

    // Drapeau uniquement sur le bouton
    const languageLabels = {
        fr: "🇫🇷",
        en: "🇺🇸"
    };

    let currentLanguage = localStorage.getItem("language") || DEFAULT_LANGUAGE;
    let translations = {};

    // t() toujours disponible → évite "t is not defined"
    function t(key) {
        return (translations && translations[key]) || key;
    }
    window.t = t;

    async function loadLanguage(language) {
        if (!["fr", "en"].includes(language)) language = DEFAULT_LANGUAGE;

        try {
            const url = I18N_PATH + language + ".json";
            console.log("[i18n] Loading →", url);

            const response = await fetch(url, { cache: "no-cache" });
            if (!response.ok) {
                throw new Error("Fichier de langue introuvable : " + language);
            }

            translations = await response.json();
            currentLanguage = language;
            localStorage.setItem("language", language);

            applyTranslations();
            updateLanguageButton();

            document.dispatchEvent(new CustomEvent("languageChanged", {
                detail: { language: currentLanguage }
            }));

            console.log("[i18n] Language loaded:", language);
        } catch (error) {
            console.error("Erreur de traduction :", error);
            if (language !== DEFAULT_LANGUAGE) {
                await loadLanguage(DEFAULT_LANGUAGE);
            }
        }
    }

    function applyTranslations() {
        document.documentElement.lang = currentLanguage;

        document.querySelectorAll("[data-i18n]").forEach(function (el) {
            var key = el.getAttribute("data-i18n");
            var value = t(key);
            if (value && value !== key) el.textContent = value;
        });

        document.querySelectorAll("[data-i18n-placeholder]").forEach(function (el) {
            var key = el.getAttribute("data-i18n-placeholder");
            var value = t(key);
            if (value && value !== key) el.placeholder = value;
        });

        var titleEl = document.querySelector("[data-i18n-title]");
        if (titleEl) {
            document.title = t(titleEl.getAttribute("data-i18n-title"));
        }

        document.querySelectorAll("[data-i18n-title-attr]").forEach(function (el) {
            el.title = t(el.getAttribute("data-i18n-title-attr"));
        });
    }

    function updateLanguageButton() {
        var button = document.getElementById("languageButton")
                  || document.querySelector("[data-language-button]");
        if (button) {
            // Affiche uniquement le drapeau
            button.textContent = languageLabels[currentLanguage] || languageLabels.fr;
        }
    }

    function initLanguageSelector() {
        document.querySelectorAll(".language-option, [data-lang]").forEach(function (btn) {
            btn.addEventListener("click", async function (e) {
                e.preventDefault();
                var lang = this.dataset.lang || this.getAttribute("data-lang");
                if (lang && lang !== currentLanguage) {
                    await loadLanguage(lang);
                }
            });
        });
    }

    window.i18n = {
        loadLanguage: loadLanguage,
        applyTranslations: applyTranslations,
        getLanguage: function () { return currentLanguage; },
        t: t
    };

    document.addEventListener("DOMContentLoaded", async function () {
        initLanguageSelector();
        await loadLanguage(currentLanguage);
    });
})();