(function () {
    "use strict";

    function detectProjectRoot() {
        const script = document.currentScript || document.querySelector('script[src$="/public/js/api.js"], script[src$="public/js/api.js"]');

        if (script && script.src) {
            const scriptUrl = new URL(script.src, window.location.href);
            return scriptUrl.href.replace(/\/public\/js\/api\.js(?:\?.*)?$/, "");
        }

        const path = window.location.pathname;
        const marker = "/public/html/";
        const markerIndex = path.indexOf(marker);
        const projectPath = markerIndex >= 0 ? path.slice(0, markerIndex) : path.replace(/\/index\.html$/, "");
        return `${window.location.origin}${projectPath}`;
    }

    const projectRoot = detectProjectRoot();
    const apiBase = `${projectRoot}/php`;
    const configUrl = `${projectRoot}/config/settings.json`;
    const themeKeys = ["primary", "secondary", "accent", "background", "surface", "text"];

    let configCache = null;
    let csrfToken = null;

    function getSafeText(value, fallback = "") {
        return typeof value === "string" && value.trim() !== "" ? value : fallback;
    }

    function resolveProjectUrl(value) {
        if (!value || typeof value !== "string") {
            return "";
        }

        try {
            return new URL(value, `${projectRoot}/`).toString();
        } catch (error) {
            return "";
        }
    }

    function shorten(text, maxLength = 260) {
        const compact = text.replace(/\s+/g, " ").trim();
        return compact.length > maxLength ? `${compact.slice(0, maxLength)}...` : compact;
    }

    async function parseJsonResponse(response) {
        const text = await response.text();
        if (!text) {
            return {};
        }

        try {
            return JSON.parse(text);
        } catch (error) {
            const url = response.url || "unbekannter Endpunkt";

            if (text.includes("<?php")) {
                throw new Error(`PHP wird vom Server nicht ausgeführt. Prüfe Apache/PHP-FPM für ${url}.`);
            }

            if (/^\s*</.test(text)) {
                throw new Error(`Der Server lieferte HTML statt JSON von ${url}: ${shorten(text)}`);
            }

            throw new Error(`Die Serverantwort war kein gültiges JSON von ${url}: ${shorten(text)}`);
        }
    }

    async function request(endpoint, options = {}) {
        const method = (options.method || "GET").toUpperCase();
        const headers = new Headers(options.headers || {});
        headers.set("Accept", "application/json");

        const requestOptions = {
            method,
            credentials: "same-origin",
            headers
        };

        if (options.body !== undefined) {
            headers.set("Content-Type", "application/json");
            requestOptions.body = JSON.stringify(options.body);
        }

        if (method === "POST" && csrfToken) {
            headers.set("X-CSRF-Token", csrfToken);
        }

        const response = await fetch(`${apiBase}/${endpoint}`, requestOptions);
        const data = await parseJsonResponse(response);

        if (data && typeof data.csrfToken === "string") {
            csrfToken = data.csrfToken;
        }

        if (!response.ok || data.success === false) {
            const debug = data.debug && data.debug.error ? ` Details: ${data.debug.error}` : "";
            throw new Error(`${data.message || "Die Anfrage konnte nicht verarbeitet werden."}${debug}`);
        }

        return data;
    }

    async function requestForm(endpoint, formData) {
        const headers = new Headers();
        headers.set("Accept", "application/json");

        if (csrfToken) {
            headers.set("X-CSRF-Token", csrfToken);
        }

        const response = await fetch(`${apiBase}/${endpoint}`, {
            method: "POST",
            credentials: "same-origin",
            headers,
            body: formData
        });
        const data = await parseJsonResponse(response);

        if (data && typeof data.csrfToken === "string") {
            csrfToken = data.csrfToken;
        }

        if (!response.ok || data.success === false) {
            const debug = data.debug && data.debug.error ? ` Details: ${data.debug.error}` : "";
            throw new Error(`${data.message || "Die Anfrage konnte nicht verarbeitet werden."}${debug}`);
        }

        return data;
    }

    async function getConfig() {
        if (configCache) {
            return configCache;
        }

        const response = await fetch(configUrl, {
            cache: "no-store",
            credentials: "same-origin"
        });

        if (!response.ok) {
            throw new Error("Die Konfiguration konnte nicht geladen werden.");
        }

        configCache = await response.json();
        return configCache;
    }

    function isThemeObject(value) {
        return value && typeof value === "object" && !Array.isArray(value);
    }

    function getEffectiveTheme(config = {}, user = null) {
        const baseTheme = isThemeObject(config.theme) ? config.theme : {};
        const userTheme = user && isThemeObject(user.theme) ? user.theme : {};
        return Object.assign({}, baseTheme, userTheme);
    }

    function applyThemeValues(theme = {}) {
        const root = document.documentElement;
        const variables = {
            primary: "--color-primary",
            secondary: "--color-secondary",
            accent: "--color-accent",
            background: "--color-background",
            surface: "--color-surface",
            text: "--color-text"
        };

        Object.keys(variables).forEach((key) => {
            if (themeKeys.includes(key) && typeof theme[key] === "string" && /^#[0-9a-f]{6}$/i.test(theme[key])) {
                root.style.setProperty(variables[key], theme[key]);
            }
        });
    }

    function applyTheme(config = {}, user = null) {
        applyThemeValues(getEffectiveTheme(config, user));
    }

    function applyConfigText(config) {
        const school = config.school || {};
        const texts = config.texts || {};
        const textMap = {
            schoolName: getSafeText(school.name, "Liveserver Website"),
            portalVersion: getSafeText(config.version, "00.00.22"),
            loginHint: getSafeText(texts.loginHint, "Melde dich mit deinem Schul-Benutzernamen und Passwort an."),
            publicIntro: getSafeText(texts.publicIntro, "Sicherer Zugriff auf persönliche Schulmaterialien."),
            signupHint: getSafeText(texts.signupHint, "Erstelle dein Konto mit Benutzername, Name und Passwort.")
        };

        document.querySelectorAll("[data-config]").forEach((element) => {
            const key = element.dataset.config;
            if (Object.prototype.hasOwnProperty.call(textMap, key)) {
                element.textContent = textMap[key];
            }
        });

        const logoUrl = resolveProjectUrl(school.logo || "");
        document.querySelectorAll("[data-config-logo]").forEach((image) => {
            if (logoUrl) {
                image.src = logoUrl;
                image.classList.add("has-logo");
            }
        });

        document.title = document.title.replace("Liveserver Website", textMap.schoolName);
    }

    async function initSiteChrome() {
        const config = await getConfig();
        applyTheme(config);
        applyConfigText(config);
        return config;
    }

    async function checkSession() {
        return request("session_check.php");
    }

    async function ensureSession() {
        const data = await checkSession();
        if (!data.loggedIn) {
            const current = encodeURIComponent(window.location.pathname.split("/").pop() || "dashboard.html");
            window.location.replace(`login.html?next=${current}`);
            return null;
        }

        return data;
    }

    function setUserText(user) {
        document.querySelectorAll("[data-user]").forEach((element) => {
            const key = element.dataset.user;
            const value = user && user[key] !== undefined && user[key] !== null ? String(user[key]) : "-";
            element.textContent = value.trim() === "" ? "-" : value;
        });
    }

    function getSafeNextPage() {
        const params = new URLSearchParams(window.location.search);
        const next = params.get("next");
        const allowed = new Set(["dashboard.html", "profil.html", "projekte.html", "editor.html", "einstellungen.html"]);
        return allowed.has(next) ? next : "dashboard.html";
    }

    window.AppApi = {
        apiBase,
        configUrl,
        getConfig,
        initSiteChrome,
        themeKeys,
        getEffectiveTheme,
        applyTheme,
        applyThemeValues,
        checkSession,
        ensureSession,
        setUserText,
        getSafeNextPage,
        apiUrl(endpoint) {
            if (/^(https?:)?\/\//i.test(endpoint) || String(endpoint || "").startsWith("/")) {
                return endpoint;
            }

            return `${apiBase}/${endpoint}`;
        },
        login(credentials) {
            return request("login.php", {
                method: "POST",
                body: credentials
            });
        },
        signup(payload) {
            return request("signup.php", {
                method: "POST",
                body: payload
            });
        },
        logout() {
            return request("logout.php", {
                method: "POST",
                body: {}
            });
        },
        getUserData() {
            return request("get_user_data.php");
        },
        listProjects() {
            return request("list_projects.php");
        },
        createProject(formData) {
            return requestForm("create_project.php", formData);
        },
        createCodeProject(payload) {
            return request("create_code_project.php", {
                method: "POST",
                body: payload
            });
        },
        getProjectCode(projectId) {
            return request(`get_project_code.php?id=${encodeURIComponent(projectId)}`);
        },
        updateProjectCode(payload) {
            return request("update_project_code.php", {
                method: "POST",
                body: payload
            });
        },
        updateProject(payload) {
            return request("update_project.php", {
                method: "POST",
                body: payload
            });
        },
        deleteProject(payload) {
            return request("delete_project.php", {
                method: "POST",
                body: payload
            });
        },
        reorderProjects(projectIds) {
            return request("reorder_projects.php", {
                method: "POST",
                body: { projectIds }
            });
        },
        updateUserData(payload) {
            return request("update_user_data.php", {
                method: "POST",
                body: payload
            });
        },
        updateUserTheme(theme) {
            return request("update_user_theme.php", {
                method: "POST",
                body: { theme }
            });
        }
    };
})();
