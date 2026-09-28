/* ============================================================
   adminHMD - i18n
   Bouton = drapeau de la langue CHOISIE (reste synchronisé à chaque clic)
============================================================ */

(function () {
    "use strict";

    const I18N_PATH = "/assets/i18n/";
    const DEFAULT_LANGUAGE = "fr";

    const FLAG_FR =
        '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="14" viewBox="0 0 3 2" aria-hidden="true" style="display:block;border-radius:2px;box-shadow:0 0 0 1px rgba(0,0,0,.12)">' +
        '<rect width="1" height="2" fill="#002395"/><rect x="1" width="1" height="2" fill="#fff"/><rect x="2" width="1" height="2" fill="#ED2939"/>' +
        '</svg>';
    const FLAG_US =
        '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="14" viewBox="0 0 19 10" aria-hidden="true" style="display:block;border-radius:2px;box-shadow:0 0 0 1px rgba(0,0,0,.12)">' +
        '<rect width="19" height="10" fill="#B22234"/>' +
        '<path d="M0 1.1h19M0 3.3h19M0 5.5h19M0 7.7h19M0 9.9h19" stroke="#fff" stroke-width="1.1"/>' +
        '<rect width="7.6" height="5.4" fill="#3C3B6E"/>' +
        '</svg>';

    const languageFlags = { fr: FLAG_FR, en: FLAG_US };
    const languageNames = { fr: "Français", en: "English" };

    let currentLanguage = localStorage.getItem("language") || DEFAULT_LANGUAGE;
    let translations = {};
    let loading = false;

    function t(key) {
        return (translations && translations[key]) || key;
    }
    window.t = t;

    function setButtonFlag(lang) {
        var button = document.getElementById("languageButton")
                  || document.querySelector("[data-language-button]");
        if (!button) return;

        var flagHtml = languageFlags[lang] || languageFlags.fr;
        button.innerHTML =
            '<span class="lang-flag" data-lang-flag="current" aria-hidden="true">' +
            flagHtml +
            '</span>';
        button.setAttribute("data-current-lang", lang);
        button.setAttribute("aria-label", languageNames[lang] || lang);
        button.title = languageNames[lang] || lang;
    }

    function markActiveOption(lang) {
        document.querySelectorAll(".language-option[data-lang]").forEach(function (el) {
            if (el.getAttribute("data-lang") === lang) {
                el.classList.add("active");
                el.setAttribute("aria-current", "true");
            } else {
                el.classList.remove("active");
                el.removeAttribute("aria-current");
            }
        });
    }

    function decorateLanguageOptions() {
        document.querySelectorAll("a.language-option[data-lang], .language-option[data-lang]").forEach(function (el) {
            var lang = el.getAttribute("data-lang");
            if (!lang || !languageFlags[lang]) return;
            // Ne pas écraser si déjà décoré correctement
            var existing = el.querySelector(".lang-flag svg");
            var labelSpan = el.querySelector("span:not(.lang-flag)");
            if (existing && labelSpan) {
                markActiveOption(currentLanguage);
                return;
            }
            el.innerHTML =
                '<span class="lang-flag" aria-hidden="true">' + languageFlags[lang] + '</span>' +
                '<span>' + (languageNames[lang] || lang) + '</span>';
            el.classList.add("d-flex", "align-items-center", "gap-2");
        });
        markActiveOption(currentLanguage);
    }

    function updateLanguageButton() {
        setButtonFlag(currentLanguage);
        markActiveOption(currentLanguage);
    }

    async function loadLanguage(language) {
        if (!["fr", "en"].includes(language)) language = DEFAULT_LANGUAGE;

        // Affichage immédiat du drapeau (avant le fetch)
        setButtonFlag(language);
        markActiveOption(language);

        if (loading) return;
        loading = true;

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
            setButtonFlag(currentLanguage);
            decorateLanguageOptions();
            markActiveOption(currentLanguage);

            document.dispatchEvent(new CustomEvent("languageChanged", {
                detail: { language: currentLanguage }
            }));

            console.log("[i18n] Language loaded:", language);
        } catch (error) {
            console.error("Erreur de traduction :", error);
            // En cas d'échec EN, revenir à FR + drapeau FR
            if (language !== DEFAULT_LANGUAGE) {
                currentLanguage = DEFAULT_LANGUAGE;
                localStorage.setItem("language", DEFAULT_LANGUAGE);
                setButtonFlag(DEFAULT_LANGUAGE);
                try {
                    const r = await fetch(I18N_PATH + DEFAULT_LANGUAGE + ".json", { cache: "no-cache" });
                    if (r.ok) {
                        translations = await r.json();
                        applyTranslations();
                    }
                } catch (_) {}
            }
        } finally {
            loading = false;
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

    /** Délégation d'événements : marche à chaque clic, même après re-rendu */
    function initLanguageSelector() {
        document.addEventListener("click", function (e) {
            var opt = e.target.closest(".language-option[data-lang], a[data-lang].language-option");
            if (!opt) return;
            // Ne pas intercepter d'autres data-lang hors menu langue
            if (!opt.classList.contains("language-option") && !opt.closest(".dropdown-menu")) return;

            e.preventDefault();
            e.stopPropagation();

            var lang = opt.getAttribute("data-lang");
            if (!lang || !languageFlags[lang]) return;

            // Drapeau tout de suite
            setButtonFlag(lang);
            markActiveOption(lang);

            loadLanguage(lang);
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
        decorateLanguageOptions();
        setButtonFlag(currentLanguage);
        await loadLanguage(currentLanguage);
    });
})();