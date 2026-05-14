(function () {
    "use strict";

    function byId(id) {
        return document.getElementById(id);
    }

    const themeFields = [
        ["primary", "Primär"],
        ["secondary", "Sekundär"],
        ["accent", "Akzent"],
        ["background", "Hintergrund"],
        ["surface", "Flächen"],
        ["text", "Text"]
    ];

    function isHexColor(value) {
        return typeof value === "string" && /^#[0-9a-f]{6}$/i.test(value);
    }

    function getThemeInput(key) {
        return byId(`theme-${key}`);
    }

    function getThemeValueLabel(key) {
        return byId(`theme-${key}-value`);
    }

    function readThemeForm() {
        const theme = {};

        themeFields.forEach(([key]) => {
            const input = getThemeInput(key);
            if (input && isHexColor(input.value)) {
                theme[key] = input.value.toLowerCase();
            }
        });

        return theme;
    }

    function setThemeForm(theme) {
        themeFields.forEach(([key]) => {
            const input = getThemeInput(key);
            const label = getThemeValueLabel(key);
            const value = isHexColor(theme[key]) ? theme[key].toLowerCase() : "#000000";

            if (input) {
                input.value = value;
            }

            if (label) {
                label.textContent = value;
            }
        });
    }

    function formatProjectDate(value) {
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return "";
        }

        return new Intl.DateTimeFormat("de-DE", {
            day: "2-digit",
            month: "2-digit",
            year: "numeric"
        }).format(date);
    }

    function createDashboardProjectItem(project) {
        const article = document.createElement("article");
        article.className = "content-item";

        const title = document.createElement("a");
        title.href = window.AppApi.apiUrl(project.viewUrl);
        title.target = "_blank";
        title.rel = "noopener";
        title.textContent = project.title || "Projekt";

        const description = document.createElement("p");
        const owner = project.isOwner ? "Eigenes Projekt" : `Von ${project.ownerUsername}`;
        const created = formatProjectDate(project.createdAt);
        description.textContent = created ? `${owner} seit ${created}` : owner;

        const meta = document.createElement("span");
        meta.className = "content-meta";
        meta.textContent = `${project.type === "webpage" ? "Webpage" : "Datei"} · ${project.visibility}`;

        article.append(title, description, meta);
        return article;
    }

    function renderDashboardProjects(projects) {
        const list = byId("dashboard-project-list");
        const count = byId("dashboard-project-count");
        if (!list) {
            return;
        }

        const safeProjects = Array.isArray(projects) ? projects : [];
        const latestProjects = safeProjects.slice(0, 3);
        list.replaceChildren();

        if (count) {
            count.textContent = String(safeProjects.length);
        }

        if (latestProjects.length === 0) {
            const empty = document.createElement("p");
            empty.className = "empty-state";
            empty.textContent = "Noch keine Projekte verfügbar.";
            list.append(empty);
            return;
        }

        latestProjects.forEach((project) => {
            list.append(createDashboardProjectItem(project));
        });
    }

    function renderThemePreview(theme) {
        const preview = byId("theme-preview");
        if (!preview) {
            return;
        }

        preview.replaceChildren();
        themeFields.forEach(([key, label]) => {
            const color = isHexColor(theme[key]) ? theme[key] : "#6b7280";
            const swatch = document.createElement("div");
            swatch.className = "theme-swatch";
            swatch.style.background = color;
            swatch.style.color = key === "background" || key === "surface" ? "var(--color-text)" : "#ffffff";
            swatch.textContent = label;
            preview.append(swatch);
        });
    }

    function bindThemeSettings(config, user) {
        const form = byId("theme-form");
        const reset = byId("theme-reset");
        const message = byId("theme-message");
        if (!form) {
            return;
        }

        const baseTheme = window.AppApi.getEffectiveTheme(config);
        const currentTheme = window.AppApi.getEffectiveTheme(config, user);

        setThemeForm(currentTheme);
        renderThemePreview(currentTheme);

        themeFields.forEach(([key]) => {
            const input = getThemeInput(key);
            if (!input) {
                return;
            }

            input.addEventListener("input", () => {
                const theme = readThemeForm();
                const label = getThemeValueLabel(key);
                if (label) {
                    label.textContent = input.value.toLowerCase();
                }
                window.AppApi.applyThemeValues(theme);
                renderThemePreview(theme);
            });
        });

        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            const submit = form.querySelector('button[type="submit"]');
            const theme = readThemeForm();

            if (message) {
                message.textContent = "";
                message.className = "form-message";
            }

            if (submit) {
                submit.disabled = true;
            }

            try {
                const result = await window.AppApi.updateUserTheme(theme);
                window.AppApi.applyTheme(config, result.user);
                window.AppApi.setUserText(result.user);
                setThemeForm(window.AppApi.getEffectiveTheme(config, result.user));
                renderThemePreview(window.AppApi.getEffectiveTheme(config, result.user));

                if (message) {
                    message.textContent = "Farben gespeichert.";
                    message.className = "form-message success";
                }
            } catch (error) {
                if (message) {
                    message.textContent = error.message;
                    message.className = "form-message error";
                }
            } finally {
                if (submit) {
                    submit.disabled = false;
                }
            }
        });

        if (reset) {
            reset.addEventListener("click", async () => {
                if (message) {
                    message.textContent = "";
                    message.className = "form-message";
                }

                reset.disabled = true;

                try {
                    const result = await window.AppApi.updateUserTheme(null);
                    window.AppApi.applyTheme(config, result.user);
                    setThemeForm(baseTheme);
                    renderThemePreview(baseTheme);

                    if (message) {
                        message.textContent = "Standardfarben wiederhergestellt.";
                        message.className = "form-message success";
                    }
                } catch (error) {
                    if (message) {
                        message.textContent = error.message;
                        message.className = "form-message error";
                    }
                } finally {
                    reset.disabled = false;
                }
            });
        }
    }

    async function bindLogoutButtons() {
        document.querySelectorAll("[data-logout]").forEach((button) => {
            button.addEventListener("click", async () => {
                button.disabled = true;
                try {
                    await window.AppApi.logout();
                    window.location.replace("login.html");
                } catch (error) {
                    button.disabled = false;
                    window.alert(error.message);
                }
            });
        });
    }

    async function initIndex() {
        const config = await window.AppApi.initSiteChrome();
        const registration = config.registration || {};
        document.querySelectorAll("[data-registration-link]").forEach((link) => {
            link.hidden = !registration.enabled;
        });

        try {
            const session = await window.AppApi.checkSession();
            const loginLink = document.querySelector('[data-auth-link="login"]');
            const dashboardLink = document.querySelector('[data-auth-link="dashboard"]');

            if (session.loggedIn) {
                window.AppApi.applyTheme(config, session.user);
                if (loginLink) {
                    loginLink.hidden = true;
                }
                document.querySelectorAll("[data-registration-link]").forEach((link) => {
                    link.hidden = true;
                });
                if (dashboardLink) {
                    dashboardLink.hidden = false;
                }
            }
        } catch (error) {
            // The public page remains usable even if the API is not reachable yet.
        }
    }

    async function initProtectedPage(page) {
        const config = await window.AppApi.initSiteChrome();
        const session = await window.AppApi.ensureSession();
        if (!session) {
            return;
        }

        window.AppApi.applyTheme(config, session.user);
        window.AppApi.setUserText(session.user);
        await bindLogoutButtons();

        if (page === "dashboard") {
            const userData = await window.AppApi.getUserData();
            window.AppApi.setUserText(userData.user);
            window.AppApi.applyTheme(config, userData.user);
            try {
                const projectData = await window.AppApi.listProjects();
                renderDashboardProjects(projectData.projects);
            } catch (error) {
                renderDashboardProjects([]);
            }

            const status = byId("session-status");
            if (status) {
                status.textContent = "Angemeldet";
            }
        }

        if (page === "profil") {
            await initProfileForm(session.user);
        }

        if (page === "einstellungen") {
            const registration = config.registration || {};
            const state = byId("registration-state");
            if (state) {
                state.textContent = registration.enabled ? "Aktiviert" : "Deaktiviert";
            }
            bindThemeSettings(config, session.user);
        }
    }

    async function initProfileForm(user) {
        const form = byId("profile-form");
        const realname = byId("realname");
        const message = byId("profile-message");
        const newPassword = byId("new-password");
        const currentPassword = byId("current-password");
        const realnameError = byId("realname-error");
        const passwordError = byId("new-password-error");

        if (!form || !realname) {
            return;
        }

        realname.value = user.realname || "";

        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            message.textContent = "";
            message.className = "form-message";
            realnameError.textContent = "";
            passwordError.textContent = "";

            const name = realname.value.trim();
            const newPasswordValue = newPassword.value;
            const currentPasswordValue = currentPassword.value;
            let valid = true;

            if (!name) {
                realnameError.textContent = "Bitte gib deinen Namen ein.";
                valid = false;
            }

            if (newPasswordValue && newPasswordValue.length < 8) {
                passwordError.textContent = "Das neue Passwort muss mindestens 8 Zeichen lang sein.";
                valid = false;
            }

            if (newPasswordValue && !currentPasswordValue) {
                passwordError.textContent = "Bitte gib dein aktuelles Passwort ein.";
                valid = false;
            }

            if (!valid) {
                return;
            }

            const submit = form.querySelector('button[type="submit"]');
            submit.disabled = true;

            try {
                const result = await window.AppApi.updateUserData({
                    realname: name,
                    currentPassword: currentPasswordValue,
                    newPassword: newPasswordValue
                });

                window.AppApi.setUserText(result.user);
                currentPassword.value = "";
                newPassword.value = "";
                message.textContent = "Profil gespeichert.";
                message.className = "form-message success";
            } catch (error) {
                message.textContent = error.message;
                message.className = "form-message error";
            } finally {
                submit.disabled = false;
            }
        });
    }

    document.addEventListener("DOMContentLoaded", async () => {
        const page = document.body.dataset.page;

        try {
            if (page === "index") {
                await initIndex();
                return;
            }

            await initProtectedPage(page);
        } catch (error) {
            const status = byId("session-status") || byId("profile-message");
            if (status) {
                status.textContent = error.message;
                status.classList.add("error");
            } else {
                window.alert(error.message);
            }
        }
    });
})();
