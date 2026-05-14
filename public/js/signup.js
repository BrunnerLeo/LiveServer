(function () {
    "use strict";

    const form = document.getElementById("signup-form");
    const submitButton = document.getElementById("signup-submit");
    const message = document.getElementById("signup-message");
    const fields = {
        realname: document.getElementById("realname"),
        username: document.getElementById("username"),
        password: document.getElementById("password"),
        passwordConfirm: document.getElementById("password-confirm")
    };

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

    function clearErrors() {
        ["realname-error", "username-error", "password-error", "password-confirm-error"].forEach((id) => {
            setFieldError(id, "");
        });
    }

    function validate() {
        clearErrors();
        let valid = true;
        const realname = fields.realname.value.trim();
        const username = fields.username.value.trim();
        const password = fields.password.value;
        const passwordConfirm = fields.passwordConfirm.value;

        if (!realname) {
            setFieldError("realname-error", "Bitte gib deinen Namen ein.");
            valid = false;
        }

        if (!/^[a-zA-Z0-9._-]{3,100}$/.test(username)) {
            setFieldError("username-error", "Der Benutzername muss 3 bis 100 gültige Zeichen enthalten.");
            valid = false;
        }

        if (password.length < 8) {
            setFieldError("password-error", "Das Passwort muss mindestens 8 Zeichen lang sein.");
            valid = false;
        }

        if (password !== passwordConfirm) {
            setFieldError("password-confirm-error", "Die Passwörter stimmen nicht überein.");
            valid = false;
        }

        return valid;
    }

    async function init() {
        try {
            const config = await window.AppApi.initSiteChrome();
            const registration = config.registration || {};

            await window.AppApi.checkSession();

            if (!registration.enabled) {
                form.hidden = true;
                setMessage(registration.message || "Registrierung ist aktuell deaktiviert.", "error");
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
        submitButton.textContent = "Konto wird erstellt";

        try {
            await window.AppApi.signup({
                realname: fields.realname.value.trim(),
                username: fields.username.value.trim(),
                password: fields.password.value,
                passwordConfirm: fields.passwordConfirm.value
            });
            setMessage("Konto erstellt. Du wirst weitergeleitet.", "success");
            window.location.replace("dashboard.html");
        } catch (error) {
            setMessage(error.message, "error");
        } finally {
            submitButton.disabled = false;
            submitButton.textContent = "Registrieren";
        }
    });

    init();
})();
