(function () {
    "use strict";

    const form = document.getElementById("login-form");
    const submitButton = document.getElementById("login-submit");
    const message = document.getElementById("login-message");
    const registrationNote = document.getElementById("registration-note");
    const usernameInput = document.getElementById("username");
    const passwordInput = document.getElementById("password");

    function setMessage(text, type) {
        message.textContent = text;
        message.className = `form-message ${type || ""}`.trim();
    }

    function setFieldError(id, text) {
        const element = document.getElementById(id);
        if (element) {
            element.textContent = text;
        }
    }

    function validate() {
        const username = usernameInput.value.trim();
        const password = passwordInput.value;
        let valid = true;

        setFieldError("username-error", "");
        setFieldError("password-error", "");

        if (!username) {
            setFieldError("username-error", "Bitte gib deinen Benutzernamen ein.");
            valid = false;
        }

        if (!password) {
            setFieldError("password-error", "Bitte gib dein Passwort ein.");
            valid = false;
        }

        return valid;
    }

    async function init() {
        try {
            const config = await window.AppApi.initSiteChrome();
            const registration = config.registration || {};
            document.querySelectorAll("[data-registration-link]").forEach((link) => {
                link.hidden = !registration.enabled;
            });

            registrationNote.textContent = registration.enabled
                ? registration.message || "Registrierung ist für diese Schule aktiviert."
                : registration.message || "Neue Konten werden durch die Schule angelegt.";

            if (registration.enabled) {
                const signupLink = document.createElement("a");
                signupLink.href = "signup.html";
                signupLink.textContent = "Jetzt registrieren";
                signupLink.className = "inline-link";
                registrationNote.append(" ");
                registrationNote.append(signupLink);
            }

            const session = await window.AppApi.checkSession();
            if (session.loggedIn) {
                window.location.replace("dashboard.html");
            }
        } catch (error) {
            setMessage(error.message, "error");
        }
    }

    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        setMessage("", "");

        if (!validate()) {
            return;
        }

        submitButton.disabled = true;
        submitButton.textContent = "Login wird geprüft";

        try {
            await window.AppApi.login({
                username: usernameInput.value.trim(),
                password: passwordInput.value
            });
            setMessage("Login erfolgreich. Du wirst weitergeleitet.", "success");
            window.location.replace(window.AppApi.getSafeNextPage());
        } catch (error) {
            setMessage(error.message, "error");
        } finally {
            submitButton.disabled = false;
            submitButton.textContent = "Einloggen";
        }
    });

    init();
})();
