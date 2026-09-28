(function(){
    function setCookie(name, value, days) {
        var expires = "";
        if (days) {
            var date = new Date();
            date.setTime(date.getTime() + (days*24*60*60*1000));
            expires = "; expires=" + date.toUTCString();
        }
        document.cookie = name + "=" + (value || "")  + expires + "; path=/";
    }
    function getCookie(name) {
        var nameEQ = name + "=";
        var ca = document.cookie.split(';');
        for(var i=0;i < ca.length;i++) {
            var c = ca[i];
            while (c.charAt(0)==' ') c = c.substring(1,c.length);
            if (c.indexOf(nameEQ) == 0) return c.substring(nameEQ.length,c.length);
        }
        return null;
    }

    function initLocaleSelector(selectId) {
        var select = document.getElementById(selectId || 'page-locale-select');
        if (!select) return;
        var current = getCookie('site_locale') || document.documentElement.lang || 'en';
        try { select.value = current; } catch(e){}
        select.addEventListener('change', function(){
            setCookie('site_locale', this.value, 365);
            location.reload();
        });
    }

    // Load translations JSON for given locale and apply to elements with data-i18n
    async function loadTranslations(locale) {
        locale = locale || getCookie('site_locale') || document.documentElement.lang || 'en';
        var path = '/assets/i18n/' + locale + '.json';
        try {
            var res = await fetch(path);
            if (!res.ok) throw new Error('No translations for ' + locale);
            var dict = await res.json();
            applyTranslations(dict);
            return dict;
        } catch (e) {
            if (locale !== 'en') return loadTranslations('en');
            return {};
        }
    }

    function applyTranslations(dict) {
        document.querySelectorAll('[data-i18n]').forEach(function(el){
            var key = el.getAttribute('data-i18n');
            var txt = dict[key];
            if (txt === undefined) return;
            if (el.placeholder !== undefined && el.tagName === 'INPUT') {
                el.placeholder = txt;
            } else {
                if (el.dataset.i18nHtml === 'true') el.innerHTML = txt; else el.textContent = txt;
            }
        });
    }

    // Initialize language dropdown links with data-lang
    function initLanguageLinks() {
        document.querySelectorAll('[data-lang]').forEach(function(a){
            a.addEventListener('click', function(e){
                e.preventDefault();
                var lang = this.getAttribute('data-lang');
                setCookie('site_locale', lang, 365);
                // try to persist to backend then reload
                tryPersistLocale(lang).finally(function(){
                    loadTranslations(lang).then(function(){ location.reload(); });
                });
            });
        });
    }

    // Update the language button (flag + label)
    function updateLanguageButton(locale) {
        var btn = document.querySelector('[data-language-button]');
        if (!btn) return;
        var map = {
            'fr': '🇫🇷 Français',
            'en': '🇬🇧 English',
            
        };
        var label = map[locale] || map['en'];
        btn.innerHTML = label;
    }

    // Try to persist locale on backend (POST /change-locale). Uses JWT if present, falls back to credentials include.
    function tryPersistLocale(locale) {
        var token = localStorage.getItem('jwt_token');
        var body = '_locale=' + encodeURIComponent(locale);
        var headers = {'Content-Type': 'application/x-www-form-urlencoded'};
        if (token) headers['Authorization'] = 'Bearer ' + token;
        return fetch('/change-locale', {
            method: 'POST',
            headers: headers,
            body: body,
            credentials: 'include'
        }).then(function(res){
            // ignore response; cookie or user preference should be set server-side
            return res;
        }).catch(function(err){
            console.warn('Could not persist locale to backend', err);
        });
    }

    // expose for inline use
    window.__locale = {
        init: initLocaleSelector,
        setCookie: setCookie,
        getCookie: getCookie,
        loadTranslations: loadTranslations,
        applyTranslations: applyTranslations,
        initLanguageLinks: initLanguageLinks
    };

    document.addEventListener('DOMContentLoaded', function(){
        initLanguageLinks();
        var cur = getCookie('site_locale') || document.documentElement.lang || 'en';
        loadTranslations(cur).then(function(){ updateLanguageButton(cur); });
    });
})();
