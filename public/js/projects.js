(function () {
    "use strict";

    const DRAFT_KEY = "liveserver-project-draft";
    const CODE_DRAFT_KEY = "liveserver-code-project-draft";
    const PROJECT_COLLAPSE_KEY = "liveserver-collapsed-projects";
    const starterCode = {
        "index.html": `<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Meine Webpage</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <main class="page">
        <section class="hero">
            <p class="eyebrow">Liveserver</p>
            <h1>Meine Webpage</h1>
            <p>Diese Seite wurde direkt im Code-Editor gebaut.</p>
            <button id="action-button" type="button">Interaktion testen</button>
        </section>
    </main>
    <script src="script.js"></script>
</body>
</html>`,
        "style.css": `body {
    margin: 0;
    color: #111827;
    background: #f4f1ea;
    font-family: Arial, sans-serif;
}

.page {
    min-height: 100vh;
    display: grid;
    place-items: center;
    padding: 32px;
}

.hero {
    width: min(100%, 760px);
    padding: 42px;
    color: #ffffff;
    background: linear-gradient(135deg, #2563eb, #0f9f6e);
    border-radius: 8px;
    box-shadow: 0 24px 70px rgba(17, 24, 39, 0.18);
}

.eyebrow {
    color: #fed7aa;
    font-weight: 700;
    text-transform: uppercase;
}

h1 {
    margin: 0;
    font-size: clamp(2.4rem, 7vw, 5rem);
}

button {
    min-height: 42px;
    padding: 10px 16px;
    color: #1e3a8a;
    background: #ffffff;
    border: 0;
    border-radius: 6px;
    font-weight: 700;
}`,
        "script.js": `const button = document.getElementById("action-button");

button?.addEventListener("click", () => {
    button.textContent = "Funktioniert";
});`
    };
    let codeFiles = { ...starterCode };
    let activeCodeFile = "index.html";
    let webpageMode = "upload";
    let editingProjectId = null;
    let allProjects = [];
    let collapsedProjectIds = readCollapsedProjectIds();
    let orderSaveRun = 0;

    function byId(id) {
        return document.getElementById(id);
    }

    function setMessage(text, type = "") {
        const message = byId("project-message");
        if (!message) {
            return;
        }
        message.textContent = text;
        message.className = `form-message ${type}`.trim();
    }

    function setStatus(text) {
        const status = byId("project-status");
        if (status) {
            status.textContent = text;
        }
    }

    function clearErrors() {
        ["project-title-error", "project-file-error", "shared-usernames-error"].forEach((id) => {
            const element = byId(id);
            if (element) {
                element.textContent = "";
            }
        });
    }

    function normalizePermissionValue(value) {
        return value === "write" ? "write" : "read";
    }

    function parseSharedUsernames(rawValue) {
        const map = new Map();
        String(rawValue || "")
            .split(/[,;\n\r]+/)
            .map((value) => value.trim())
            .filter(Boolean)
            .forEach((username) => {
                map.set(username.toLowerCase(), username);
            });
        return Array.from(map.values());
    }

    function collectParentPaths(path) {
        const parts = String(path || "").split("/").filter(Boolean);
        const parents = [];
        parts.pop();
        while (parts.length > 0) {
            parents.unshift(parts.join("/"));
            parts.pop();
        }
        return parents;
    }

    function normalizeRelativePath(path) {
        const parts = [];
        String(path || "").replace(/\\/g, "/").split("/").forEach((part) => {
            const safePart = part.trim().replace(/[^a-zA-Z0-9._ -]/g, "_").replace(/^[. ]+|[. ]+$/g, "");
            if (!safePart || safePart === "." || safePart === "..") {
                return;
            }
            parts.push(safePart);
        });

        return parts.join("/");
    }

    function getUploadPath(file) {
        return normalizeRelativePath(file.webkitRelativePath || file.name || "");
    }

    function appendProjectUploadFiles(formData) {
        const type = byId("project-type").value;
        const input = byId("project-file");
        const files = Array.from(input.files || []);
        const folderPaths = new Set();

        formData.delete("projectFiles[]");
        formData.delete("projectFile");
        formData.delete("projectRelativePaths[]");
        formData.delete("projectFolderPaths[]");

        files.forEach((file) => {
            const rawPath = getUploadPath(file);
            const relativePath = normalizeRelativePath(file.name || rawPath);

            if (!relativePath) {
                return;
            }

            formData.append("projectFiles[]", file, file.name || relativePath.split("/").pop() || "upload.bin");
            formData.append("projectRelativePaths[]", relativePath);
            collectParentPaths(relativePath).forEach((folderPath) => folderPaths.add(folderPath));
        });

        Array.from(folderPaths)
            .sort((left, right) => left.localeCompare(right, "de"))
            .forEach((folderPath) => formData.append("projectFolderPaths[]", folderPath));
    }

    function getProjectFilePath(file) {
        return file.openPath || file.path || "Datei";
    }

    function readCollapsedProjectIds() {
        try {
            const value = JSON.parse(localStorage.getItem(PROJECT_COLLAPSE_KEY) || "[]");
            return new Set(Array.isArray(value) ? value.map((id) => Number(id)).filter((id) => Number.isFinite(id)) : []);
        } catch (error) {
            return new Set();
        }
    }

    function writeCollapsedProjectIds() {
        try {
            localStorage.setItem(PROJECT_COLLAPSE_KEY, JSON.stringify(Array.from(collapsedProjectIds)));
        } catch (error) {
            // Collapse state is only a UI preference.
        }
    }

    function isProjectCollapsed(projectId) {
        return collapsedProjectIds.has(Number(projectId));
    }

    function setProjectCollapsed(projectId, collapsed) {
        const numericId = Number(projectId);
        if (!Number.isFinite(numericId)) {
            return;
        }

        if (collapsed) {
            collapsedProjectIds.add(numericId);
        } else {
            collapsedProjectIds.delete(numericId);
        }
        writeCollapsedProjectIds();
    }

    function readSharePermissionList(container) {
        if (!container) {
            return {};
        }

        const permissions = {};
        container.querySelectorAll("[data-share-permission]").forEach((select) => {
            const username = String(select.dataset.username || "").trim();
            if (username) {
                permissions[username] = normalizePermissionValue(select.value);
            }
        });
        return permissions;
    }

    function renderSharePermissionList(container, usernames, permissions = {}) {
        if (!container) {
            return;
        }

        container.replaceChildren();
        if (usernames.length === 0) {
            return;
        }

        usernames.forEach((username) => {
            const row = document.createElement("label");
            row.className = "shared-permission-item";

            const name = document.createElement("span");
            name.textContent = username;

            const select = document.createElement("select");
            select.dataset.sharePermission = "true";
            select.dataset.username = username;

            [
                ["read", "Read only"],
                ["write", "Bearbeiten erlaubt"]
            ].forEach(([value, label]) => {
                const option = document.createElement("option");
                option.value = value;
                option.textContent = label;
                select.append(option);
            });

            select.value = normalizePermissionValue(permissions[username] || permissions[username.toLowerCase()] || "read");
            row.append(name, select);
            container.append(row);
        });
    }

    function syncCreateSharePermissions() {
        const container = byId("shared-permissions");
        const current = readSharePermissionList(container);
        renderSharePermissionList(container, parseSharedUsernames(byId("shared-usernames").value), current);
    }

    function getActiveUploadKind() {
        return "single";
    }

    function updateConditionalFields() {
        const type = byId("project-type").value;
        const uploadKind = getActiveUploadKind();
        const visibility = byId("project-visibility").value;
        const isCodeMode = type === "webpage" && webpageMode === "editor";
        const fileInput = byId("project-file");
        const fileLabel = byId("project-file-label");

        byId("single-file-field").hidden = isCodeMode || uploadKind !== "single";
        byId("code-workbench").hidden = type !== "webpage";
        byId("editor-shell").hidden = !isCodeMode;
        byId("shared-field").hidden = visibility !== "shared";
        byId("public-permission-field").hidden = visibility !== "public";
        fileInput.multiple = type === "webpage";
        byId("upload-kind").value = type === "webpage" ? "folder" : "single";
        fileLabel.textContent = type === "webpage" ? "Top-Level-Dateien" : "Datei";

        const singleHint = byId("single-file-hint");
        if (singleHint) {
            singleHint.textContent = type === "webpage"
                ? "Nur index.html und andere Top-Level-Dateien auswählen. Unterordner müssen danach im Editor hochgeladen werden."
                : "Die Datei wird geschützt gespeichert und nur per Projektzugriff ausgeliefert.";
        }
        updateWebpageModeButtons();
        syncCreateSharePermissions();
        updateCodePreview();
    }

    function hasIndexHtml(files) {
        return files.some((file) => {
            return String(file.name || "").trim().toLowerCase() === "index.html";
        });
    }

    function validateForm() {
        clearErrors();
        const title = byId("project-title").value.trim();
        const type = byId("project-type").value;
        const uploadKind = getActiveUploadKind();
        const visibility = byId("project-visibility").value;
        const fileInput = byId("project-file");
        const sharedInput = byId("shared-usernames");
        let valid = true;

        if (!title) {
            byId("project-title-error").textContent = "Bitte gib einen Titel ein.";
            valid = false;
        }

        if (uploadKind === "single" && fileInput.files.length === 0) {
            byId("project-file-error").textContent = type === "webpage"
                ? "Bitte wähle index.html und weitere Top-Level-Dateien aus."
                : "Bitte wähle eine Datei aus.";
            valid = false;
        }

        if (type === "webpage" && webpageMode === "upload" && fileInput.files.length > 0 && !hasIndexHtml(Array.from(fileInput.files))) {
            byId("project-file-error").textContent = "Die Webpage-Dateien müssen eine index.html enthalten.";
            valid = false;
        }

        if (visibility === "shared" && sharedInput.value.trim() === "") {
            byId("shared-usernames-error").textContent = "Bitte gib mindestens einen Benutzernamen ein.";
            valid = false;
        }

        return valid;
    }

    function getPreviewHtml() {
        return codeFiles["index.html"]
            .replace("</head>", `<style>${codeFiles["style.css"]}</style></head>`)
            .replace("</body>", `<script>${codeFiles["script.js"]}<\/script></body>`);
    }

    function saveActiveCodeFile() {
        const editor = byId("code-editor");
        if (editor) {
            codeFiles[activeCodeFile] = editor.value;
        }
    }

    function loadActiveCodeFile() {
        const editor = byId("code-editor");
        if (editor) {
            editor.value = codeFiles[activeCodeFile] || "";
        }
    }

    function sortedCodeFileNames() {
        return Object.keys(codeFiles).sort((left, right) => {
            if (left === "index.html") {
                return -1;
            }
            if (right === "index.html") {
                return 1;
            }
            return left.localeCompare(right, "de");
        });
    }

    function updateCodeTabs() {
        const tabs = document.querySelector(".editor-tabs");
        if (!tabs) {
            return;
        }

        tabs.replaceChildren();
        sortedCodeFileNames().forEach((fileName) => {
            const button = document.createElement("button");
            button.className = "editor-tab";
            button.type = "button";
            button.dataset.editorFile = fileName;
            button.textContent = fileName;
            button.classList.toggle("active", fileName === activeCodeFile);
            button.addEventListener("click", () => {
                saveActiveCodeFile();
                activeCodeFile = fileName;
                loadActiveCodeFile();
                updateCodeTabs();
            });
            tabs.append(button);
        });
    }

    function updateWebpageModeButtons() {
        document.querySelectorAll("[data-webpage-mode]").forEach((button) => {
            button.classList.toggle("active", button.dataset.webpageMode === webpageMode);
        });
    }

    function persistCodeDraft() {
        try {
            localStorage.setItem(CODE_DRAFT_KEY, JSON.stringify(codeFiles));
        } catch (error) {
            // Code drafts are a convenience only; project saving still works.
        }
    }

    function restoreCodeDraft() {
        try {
            const draft = JSON.parse(localStorage.getItem(CODE_DRAFT_KEY) || "null");
            if (draft && typeof draft === "object") {
                codeFiles = {
                    "index.html": typeof draft["index.html"] === "string" ? draft["index.html"] : starterCode["index.html"],
                    "style.css": typeof draft["style.css"] === "string" ? draft["style.css"] : starterCode["style.css"],
                    "script.js": typeof draft["script.js"] === "string" ? draft["script.js"] : starterCode["script.js"]
                };
            }
        } catch (error) {
            codeFiles = { ...starterCode };
        }
    }

    function updateCodePreview() {
        const frame = byId("code-preview-frame");
        const state = byId("code-preview-state");
        if (!frame || !state || byId("editor-shell").hidden) {
            return;
        }

        frame.srcdoc = getPreviewHtml();
        state.textContent = `${codeFiles["index.html"].length + codeFiles["style.css"].length + codeFiles["script.js"].length} Zeichen`;
    }

    function resetStarterCode() {
        codeFiles = { ...starterCode };
        activeCodeFile = "index.html";
        editingProjectId = null;
        loadActiveCodeFile();
        updateCodeTabs();
        updateCodePreview();
        byId("code-save-project").textContent = "Als Webpage speichern";
        persistCodeDraft();
    }

    async function saveCodeProject() {
        saveActiveCodeFile();
        const error = byId("code-editor-error");
        error.textContent = "";

        const title = byId("project-title").value.trim();
        const visibility = byId("project-visibility").value;
        const sharedUsernames = byId("shared-usernames").value.trim();
        const publicPermission = byId("public-permission").value;
        const sharedPermissions = readSharePermissionList(byId("shared-permissions"));

        if (!title) {
            byId("project-title-error").textContent = "Bitte gib einen Titel ein.";
            return;
        }

        if (visibility === "shared" && !sharedUsernames) {
            byId("shared-usernames-error").textContent = "Bitte gib mindestens einen Benutzernamen ein.";
            return;
        }

        if (!codeFiles["index.html"].trim()) {
            error.textContent = "index.html darf nicht leer sein.";
            return;
        }

        const button = byId("code-save-project");
        button.disabled = true;
        setStatus("Speichere");

        try {
            if (editingProjectId) {
                await window.AppApi.updateProjectCode({
                    id: editingProjectId,
                    files: codeFiles
                });
                setMessage("Projekt wurde im Editor gespeichert.", "success");
            } else {
                await window.AppApi.createCodeProject({
                    title,
                    visibility,
                    publicPermission,
                    sharedUsernames,
                    sharedPermissions,
                    files: codeFiles
                });
                setMessage("Webpage-Projekt wurde aus dem Code gespeichert.", "success");
            }
            persistCodeDraft();
            await loadProjects();
        } catch (caughtError) {
            error.textContent = caughtError.message;
            setStatus("Fehler");
        } finally {
            button.disabled = false;
        }
    }

    async function openProjectInEditor(project) {
        setStatus("Lade Editor");
        setMessage("");

        try {
            const data = await window.AppApi.getProjectCode(project.id);
            const files = data.files && typeof data.files === "object" ? data.files : {};
            if (Object.keys(files).length === 0) {
                throw new Error("Dieses Projekt enthält keine editierbaren Textdateien.");
            }

            editingProjectId = project.id;
            codeFiles = files;
            activeCodeFile = data.entryFile && files[data.entryFile] !== undefined ? data.entryFile : sortedCodeFileNames()[0];
            webpageMode = "editor";

            byId("project-title").value = project.title || "";
            byId("project-type").value = "webpage";
            byId("project-visibility").value = project.visibility || "private";
            byId("public-permission").value = project.publicPermission || "read";
            byId("shared-usernames").value = Array.isArray(project.shares) ? project.shares.join(", ") : "";
            byId("code-save-project").textContent = "Änderungen speichern";

            updateConditionalFields();
            renderSharePermissionList(byId("shared-permissions"), parseSharedUsernames(byId("shared-usernames").value), project.sharePermissions || {});
            loadActiveCodeFile();
            updateCodeTabs();
            updateCodePreview();
            byId("code-workbench").scrollIntoView({ behavior: "smooth", block: "start" });
            setStatus("Editor");
            setMessage(`Projekt "${project.title}" wurde im Editor geöffnet.`, "success");
        } catch (error) {
            setStatus("Fehler");
            setMessage(error.message, "error");
        }
    }

    function formatBytes(bytes) {
        const numericBytes = Number(bytes);
        if (!Number.isFinite(numericBytes) || numericBytes <= 0) {
            return "";
        }

        const units = ["B", "KB", "MB", "GB"];
        let value = numericBytes;
        let unitIndex = 0;

        while (value >= 1024 && unitIndex < units.length - 1) {
            value /= 1024;
            unitIndex += 1;
        }

        return `${value.toFixed(unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`;
    }

    function formatDate(value) {
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

    function labelFor(value) {
        const labels = {
            file: "Datei",
            webpage: "Webpage",
            private: "Privat",
            public: "Public",
            shared: "Shared"
        };

        return labels[value] || value;
    }

    function describeProjectType(project) {
        if (project.type === "webpage") {
            return "Gehostete Website";
        }

        return project.fileCount && project.fileCount > 1 ? "Dateiordner" : "Einzeldatei";
    }

    function createBadge(value) {
        const badge = document.createElement("span");
        badge.className = "tag-badge";
        badge.textContent = labelFor(value);
        return badge;
    }

    function getProjectUrl(project) {
        return new URL(window.AppApi.apiUrl(project.viewUrl), window.location.href).toString();
    }

    function getProjectEditorUrl(project) {
        return `editor.html?id=${encodeURIComponent(project.id)}`;
    }

    function getProjectTime(project) {
        const time = new Date(project.createdAt).getTime();
        return Number.isFinite(time) ? time : 0;
    }

    function compareManualProjects(left, right) {
        const leftOrder = Number(left.sortOrder);
        const rightOrder = Number(right.sortOrder);
        const leftHasOrder = Number.isFinite(leftOrder) && leftOrder > 0;
        const rightHasOrder = Number.isFinite(rightOrder) && rightOrder > 0;

        if (leftHasOrder && rightHasOrder && leftOrder !== rightOrder) {
            return leftOrder - rightOrder;
        }

        if (leftHasOrder !== rightHasOrder) {
            return leftHasOrder ? -1 : 1;
        }

        const dateDifference = getProjectTime(right) - getProjectTime(left);
        if (dateDifference !== 0) {
            return dateDifference;
        }

        return Number(right.id) - Number(left.id);
    }

    function getManualOrderedProjects() {
        return [...allProjects].sort(compareManualProjects);
    }

    function setProjectOrderFromIds(projectIds) {
        const sortMap = new Map();
        projectIds.forEach((projectId, index) => {
            const numericId = Number(projectId);
            if (Number.isFinite(numericId)) {
                sortMap.set(numericId, (index + 1) * 10);
            }
        });

        allProjects = allProjects.map((project) => {
            const numericId = Number(project.id);
            return sortMap.has(numericId) ? { ...project, sortOrder: sortMap.get(numericId) } : project;
        });
    }

    function mergeVisibleProjectOrder(visibleProjectIds) {
        const visibleSet = new Set(visibleProjectIds.map((projectId) => Number(projectId)));
        const nextVisibleIds = visibleProjectIds.map((projectId) => Number(projectId));

        return getManualOrderedProjects().map((project) => {
            const numericId = Number(project.id);
            return visibleSet.has(numericId) ? nextVisibleIds.shift() : numericId;
        });
    }

    async function saveProjectOrder(projectIds) {
        const run = ++orderSaveRun;
        setStatus("Speichere Reihenfolge");

        try {
            await window.AppApi.reorderProjects(projectIds);
            if (run === orderSaveRun) {
                setStatus("Bereit");
                setMessage("Projektreihenfolge wurde gespeichert.", "success");
            }
        } catch (error) {
            if (run === orderSaveRun) {
                setStatus("Fehler");
                setMessage(error.message, "error");
                await loadProjects();
            }
        }
    }

    function applyVisibleProjectOrder(visibleProjectIds) {
        const fullProjectIds = mergeVisibleProjectOrder(visibleProjectIds);
        setProjectOrderFromIds(fullProjectIds);
        renderProjects();
        saveProjectOrder(fullProjectIds);
    }

    function moveProject(projectId, direction) {
        const projectIds = getFilteredProjects().map((project) => Number(project.id));
        const currentIndex = projectIds.indexOf(Number(projectId));
        const nextIndex = currentIndex + direction;

        if (currentIndex < 0 || nextIndex < 0 || nextIndex >= projectIds.length) {
            return;
        }

        [projectIds[currentIndex], projectIds[nextIndex]] = [projectIds[nextIndex], projectIds[currentIndex]];
        applyVisibleProjectOrder(projectIds);
    }

    function moveProjectBefore(draggedProjectId, targetProjectId) {
        const draggedId = Number(draggedProjectId);
        const targetId = Number(targetProjectId);
        if (!Number.isFinite(draggedId) || !Number.isFinite(targetId) || draggedId === targetId) {
            return;
        }

        const projectIds = getFilteredProjects().map((project) => Number(project.id));
        const draggedIndex = projectIds.indexOf(draggedId);
        const targetIndex = projectIds.indexOf(targetId);
        if (draggedIndex < 0 || targetIndex < 0) {
            return;
        }

        projectIds.splice(draggedIndex, 1);
        projectIds.splice(projectIds.indexOf(targetId), 0, draggedId);
        applyVisibleProjectOrder(projectIds);
    }

    function getProjectFileExtension(path) {
        const cleanPath = String(path || "").split("?")[0].split("#")[0];
        const extension = cleanPath.includes(".") ? cleanPath.split(".").pop() : "";
        return extension ? extension.toUpperCase().slice(0, 5) : "FILE";
    }

    function createInfoBadge(text) {
        const badge = document.createElement("span");
        badge.className = "tag-badge";
        badge.textContent = text;
        return badge;
    }

    function createProjectTreeData(project) {
        const root = { path: "", folders: new Map(), files: [] };
        const folderSet = new Set(Array.isArray(project.folders) ? project.folders : []);
        const files = Array.isArray(project.files) ? project.files : [];

        files.forEach((file) => {
            collectParentPaths(getProjectFilePath(file)).forEach((folderPath) => folderSet.add(folderPath));
        });

        const ensureFolderNode = (folderPath) => {
            const parts = folderPath.split("/").filter(Boolean);
            let current = root;

            parts.forEach((part) => {
                if (!current.folders.has(part)) {
                    current.folders.set(part, { folders: new Map(), files: [], path: current.path ? `${current.path}/${part}` : part });
                }
                current = current.folders.get(part);
            });

            return current;
        };

        Array.from(folderSet)
            .filter(Boolean)
            .sort((left, right) => left.localeCompare(right, "de"))
            .forEach((folderPath) => ensureFolderNode(folderPath));

        files.forEach((file) => {
            const path = getProjectFilePath(file);
            const parentPaths = collectParentPaths(path);
            const parent = parentPaths.length > 0 ? ensureFolderNode(parentPaths[parentPaths.length - 1]) : root;
            parent.files.push(file);
        });

        return root;
    }

    function createProjectFolderRow(name, child, depth) {
        const row = document.createElement("div");
        row.className = "project-folder-row";
        row.style.paddingLeft = `${12 + depth * 18}px`;

        const chevron = document.createElement("span");
        chevron.className = "project-tree-chevron";
        chevron.textContent = ">";

        const icon = document.createElement("span");
        icon.className = "project-tree-icon folder";
        icon.textContent = "DIR";

        const label = document.createElement("span");
        label.className = "project-folder-name";
        label.textContent = name;

        const meta = document.createElement("span");
        meta.className = "project-file-meta";
        meta.textContent = `${child.folders.size + child.files.length}`;

        row.append(chevron, icon, label, meta);
        return row;
    }

    function renderProjectTree(container, node, depth = 0) {
        Array.from(node.folders.entries())
            .sort((left, right) => left[0].localeCompare(right[0], "de"))
            .forEach(([name, child]) => {
                container.append(createProjectFolderRow(name, child, depth));

                const children = document.createElement("div");
                children.className = "project-folder-children";
                renderProjectTree(children, child, depth + 1);
                container.append(children);
            });

        node.files
            .sort((left, right) => getProjectFilePath(left).localeCompare(getProjectFilePath(right), "de"))
            .forEach((file) => {
                const link = document.createElement("a");
                link.className = "project-file-link";
                link.href = window.AppApi.apiUrl(file.openUrl);
                link.target = "_blank";
                link.rel = "noopener";
                link.style.paddingLeft = `${12 + depth * 18}px`;

                const chevron = document.createElement("span");
                chevron.className = "project-tree-chevron";

                const icon = document.createElement("span");
                icon.className = "project-tree-icon file";
                icon.textContent = getProjectFileExtension(getProjectFilePath(file));

                const path = document.createElement("span");
                path.className = "project-file-path";
                path.textContent = getProjectFilePath(file).split("/").pop() || "Datei";

                const meta = document.createElement("span");
                meta.className = "project-file-meta";
                meta.textContent = formatBytes(file.fileSize);

                link.append(chevron, icon, path, meta);
                container.append(link);
            });
    }

    function createProjectFileExplorer(project) {
        const files = Array.isArray(project.files) ? project.files : [];
        const folders = Array.isArray(project.folders) ? project.folders : [];
        if (files.length === 0 && folders.length === 0) {
            return null;
        }

        const details = document.createElement("details");
        details.className = "project-file-explorer";
        details.open = true;

        const summary = document.createElement("summary");
        summary.textContent = `${files.length} Datei${files.length === 1 ? "" : "en"} · ${folders.length} Ordner`;
        details.append(summary);

        const tree = document.createElement("div");
        tree.className = "project-file-tree";
        renderProjectTree(tree, createProjectTreeData(project));
        details.append(tree);
        return details;
    }

    function createProjectEditor(project) {
        const editor = document.createElement("div");
        editor.className = "project-editor";

        const visibilityGroup = document.createElement("label");
        visibilityGroup.className = "mini-field";
        const visibilityLabel = document.createElement("span");
        visibilityLabel.textContent = "Sichtbarkeit";
        const visibilitySelect = document.createElement("select");
        [
            ["private", "Privat"],
            ["shared", "Shared"],
            ["public", "Public"]
        ].forEach(([value, label]) => {
            const option = document.createElement("option");
            option.value = value;
            option.textContent = label;
            visibilitySelect.append(option);
        });
        visibilitySelect.value = project.visibility;
        visibilityGroup.append(visibilityLabel, visibilitySelect);

        const publicPermissionGroup = document.createElement("label");
        publicPermissionGroup.className = "mini-field";
        const publicPermissionLabel = document.createElement("span");
        publicPermissionLabel.textContent = "Public Zugriff";
        const publicPermissionSelect = document.createElement("select");
        [
            ["read", "Read only"],
            ["write", "Bearbeiten erlaubt"]
        ].forEach(([value, label]) => {
            const option = document.createElement("option");
            option.value = value;
            option.textContent = label;
            publicPermissionSelect.append(option);
        });
        publicPermissionSelect.value = normalizePermissionValue(project.publicPermission || "read");
        publicPermissionGroup.append(publicPermissionLabel, publicPermissionSelect);

        const sharedGroup = document.createElement("label");
        sharedGroup.className = "mini-field shared-editor";
        const sharedLabel = document.createElement("span");
        sharedLabel.textContent = "Shared Usernames";
        const sharedInput = document.createElement("input");
        sharedInput.type = "text";
        sharedInput.placeholder = "max.mustermann, anna.beispiel";
        sharedInput.value = Array.isArray(project.shares) ? project.shares.join(", ") : "";
        const sharedPermissions = document.createElement("div");
        sharedPermissions.className = "shared-permission-list";
        sharedGroup.append(sharedLabel, sharedInput, sharedPermissions);

        const updateSharedVisibility = () => {
            sharedGroup.hidden = visibilitySelect.value !== "shared";
            publicPermissionGroup.hidden = visibilitySelect.value !== "public";
            renderSharePermissionList(
                sharedPermissions,
                parseSharedUsernames(sharedInput.value),
                readSharePermissionList(sharedPermissions)
            );
        };
        updateSharedVisibility();
        visibilitySelect.addEventListener("change", updateSharedVisibility);
        sharedInput.addEventListener("input", updateSharedVisibility);
        renderSharePermissionList(sharedPermissions, parseSharedUsernames(sharedInput.value), project.sharePermissions || {});

        const saveButton = document.createElement("button");
        saveButton.className = "button subtle compact";
        saveButton.type = "button";
        saveButton.textContent = "Sichtbarkeit speichern";
        saveButton.addEventListener("click", async () => {
            saveButton.disabled = true;
            setStatus("Speichere");

            try {
                await window.AppApi.updateProject({
                    id: project.id,
                    visibility: visibilitySelect.value,
                    publicPermission: publicPermissionSelect.value,
                    sharedUsernames: sharedInput.value,
                    sharedPermissions: readSharePermissionList(sharedPermissions)
                });
                setMessage("Sichtbarkeit wurde aktualisiert.", "success");
                await loadProjects();
            } catch (error) {
                setMessage(error.message, "error");
                setStatus("Fehler");
            } finally {
                saveButton.disabled = false;
            }
        });

        const deleteButton = document.createElement("button");
        deleteButton.className = "button danger compact";
        deleteButton.type = "button";
        deleteButton.textContent = "Löschen";
        deleteButton.addEventListener("click", async () => {
            const confirmed = window.confirm(`Projekt "${project.title}" wirklich löschen? Diese Aktion entfernt auch die gespeicherten Upload-Dateien.`);
            if (!confirmed) {
                return;
            }

            deleteButton.disabled = true;
            setStatus("Lösche");

            try {
                await window.AppApi.deleteProject({ id: project.id });
                setMessage("Projekt wurde gelöscht.", "success");
                await loadProjects();
            } catch (error) {
                setMessage(error.message, "error");
                setStatus("Fehler");
            } finally {
                deleteButton.disabled = false;
            }
        });

        const editorActions = document.createElement("div");
        editorActions.className = "project-editor-actions";
        editorActions.append(saveButton, deleteButton);

        editor.append(visibilityGroup, publicPermissionGroup, sharedGroup, editorActions);
        return editor;
    }

    function createProjectOrderControls(project, index, total) {
        const controls = document.createElement("div");
        controls.className = "project-order-controls";

        const handle = document.createElement("span");
        handle.className = "project-drag-handle";
        handle.textContent = "Reihen";
        handle.title = "Projekt per Drag-and-drop verschieben";

        const upButton = document.createElement("button");
        upButton.className = "order-button";
        upButton.type = "button";
        upButton.textContent = "Hoch";
        upButton.disabled = index <= 0;
        upButton.addEventListener("click", () => moveProject(project.id, -1));

        const downButton = document.createElement("button");
        downButton.className = "order-button";
        downButton.type = "button";
        downButton.textContent = "Runter";
        downButton.disabled = index >= total - 1;
        downButton.addEventListener("click", () => moveProject(project.id, 1));

        controls.append(handle, upButton, downButton);
        return controls;
    }

    function createProjectCard(project, options = {}) {
        const manualMode = options.manualMode === true;
        const index = Number(options.index || 0);
        const total = Number(options.total || 0);
        const collapsed = isProjectCollapsed(project.id);
        const article = document.createElement("article");
        article.className = "project-card";
        article.dataset.projectId = String(project.id);
        article.classList.toggle("is-collapsed", collapsed);
        article.draggable = manualMode;
        if (project.isOwner) {
            article.classList.add("owned");
        }
        if (project.visibility === "public") {
            article.classList.add("public-project");
        }

        const header = document.createElement("div");
        header.className = "project-card-header";

        const titleGroup = document.createElement("div");
        titleGroup.className = "project-title-group";

        const title = document.createElement("h3");
        title.textContent = project.title;

        const badges = document.createElement("div");
        badges.className = "project-badges";
        badges.append(createBadge(project.type), createBadge(project.visibility));
        if (project.isOwner) {
            badges.append(createBadge("Eigenes Projekt"));
        } else if (project.type === "webpage") {
            badges.append(createInfoBadge(project.canEdit ? "Bearbeitbar" : "Read only"));
        }

        titleGroup.append(title, badges);

        const headerControls = document.createElement("div");
        headerControls.className = "project-card-controls";
        if (manualMode) {
            headerControls.append(createProjectOrderControls(project, index, total));
        }

        const collapseButton = document.createElement("button");
        collapseButton.className = "project-collapse-button";
        collapseButton.type = "button";
        collapseButton.textContent = collapsed ? "Aufklappen" : "Zuklappen";
        collapseButton.setAttribute("aria-expanded", collapsed ? "false" : "true");
        headerControls.append(collapseButton);

        header.append(titleGroup, headerControls);

        const body = document.createElement("div");
        body.className = "project-card-body";
        body.hidden = collapsed;

        collapseButton.addEventListener("click", () => {
            const nextCollapsed = !body.hidden;
            body.hidden = nextCollapsed;
            article.classList.toggle("is-collapsed", nextCollapsed);
            collapseButton.textContent = nextCollapsed ? "Aufklappen" : "Zuklappen";
            collapseButton.setAttribute("aria-expanded", nextCollapsed ? "false" : "true");
            setProjectCollapsed(project.id, nextCollapsed);
        });

        if (manualMode) {
            article.addEventListener("dragstart", (event) => {
                article.classList.add("is-dragging");
                event.dataTransfer.effectAllowed = "move";
                event.dataTransfer.setData("text/plain", String(project.id));
            });

            article.addEventListener("dragend", () => {
                article.classList.remove("is-dragging");
                article.classList.remove("is-drop-target");
            });

            article.addEventListener("dragover", (event) => {
                event.preventDefault();
                if (Number(event.dataTransfer.getData("text/plain")) !== Number(project.id)) {
                    article.classList.add("is-drop-target");
                }
            });

            article.addEventListener("dragleave", () => {
                article.classList.remove("is-drop-target");
            });

            article.addEventListener("drop", (event) => {
                event.preventDefault();
                article.classList.remove("is-drop-target");
                moveProjectBefore(event.dataTransfer.getData("text/plain"), project.id);
            });
        }

        const meta = document.createElement("p");
        meta.className = "content-meta";
        const owner = project.isOwner ? "dir" : project.ownerUsername;
        const countInfo = project.fileCount && project.fileCount > 1 ? ` · ${project.fileCount} Dateien` : "";
        const fileInfo = project.fileSize ? ` · ${formatBytes(project.fileSize)}` : "";
        const dateInfo = formatDate(project.createdAt) ? ` · ${formatDate(project.createdAt)}` : "";
        meta.textContent = `${describeProjectType(project)} · Von ${owner}${countInfo}${fileInfo}${dateInfo}`;

        const actions = document.createElement("div");
        actions.className = "project-actions";

        const openLink = document.createElement("a");
        openLink.className = "button secondary compact";
        openLink.href = window.AppApi.apiUrl(project.viewUrl);
        openLink.target = "_blank";
        openLink.rel = "noopener";
        openLink.textContent = project.type === "webpage" ? "Öffnen" : "Details";
        actions.append(openLink);

        if (project.type === "webpage") {
            const editorLink = document.createElement("a");
            editorLink.className = "button secondary compact";
            editorLink.href = getProjectEditorUrl(project);
            editorLink.target = "_blank";
            editorLink.rel = "noopener";
            editorLink.textContent = project.canEdit ? "Editor" : "Editor lesen";
            actions.append(editorLink);
        }

        if (project.downloadUrl) {
            const downloadLink = document.createElement("a");
            downloadLink.className = "button primary compact";
            downloadLink.href = window.AppApi.apiUrl(project.downloadUrl);
            downloadLink.textContent = project.fileCount && project.fileCount > 1 ? "ZIP" : "Download";
            actions.append(downloadLink);
        }

        const copyButton = document.createElement("button");
        copyButton.className = "button secondary compact";
        copyButton.type = "button";
        copyButton.textContent = "Link";
        copyButton.addEventListener("click", async () => {
            const projectUrl = getProjectUrl(project);

            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(projectUrl);
                } else {
                    window.prompt("Projektlink kopieren", projectUrl);
                }
                copyButton.textContent = "Kopiert";
                window.setTimeout(() => {
                    copyButton.textContent = "Link";
                }, 1600);
            } catch (error) {
                window.prompt("Projektlink kopieren", projectUrl);
            }
        });
        actions.append(copyButton);

        const foot = document.createElement("div");
        foot.className = "project-foot";
        const access = document.createElement("span");
        access.className = "content-meta";
        access.textContent = project.isOwner ? "Eigene Ablage" : "Freigegeben für dich";
        foot.append(access, actions);

        const fileExplorer = createProjectFileExplorer(project);

        if (Array.isArray(project.shares) && project.shares.length > 0) {
            const shares = document.createElement("p");
            shares.className = "content-meta";
            shares.textContent = `Geteilt mit: ${project.shares.map((username) => `${username} (${normalizePermissionValue((project.sharePermissions || {})[username]) === "write" ? "write" : "read"})`).join(", ")}`;
            body.append(meta, shares, foot);
            if (fileExplorer) {
                body.append(fileExplorer);
            }
            if (project.isOwner) {
                body.append(createProjectEditor(project));
            }
            article.append(header, body);
            return article;
        }

        body.append(meta, foot);
        if (fileExplorer) {
            body.append(fileExplorer);
        }
        if (project.isOwner) {
            body.append(createProjectEditor(project));
        }
        article.append(header, body);
        return article;
    }

    function getFilterState() {
        return {
            search: (byId("project-search")?.value || "").trim().toLowerCase(),
            type: byId("project-type-filter")?.value || "all",
            visibility: byId("project-visibility-filter")?.value || "all",
            sort: byId("project-sort")?.value || "manual"
        };
    }

    function getSearchHaystack(project) {
        return [
            project.title,
            project.ownerUsername,
            project.type,
            project.visibility,
            Array.isArray(project.shares) ? project.shares.join(" ") : ""
        ].join(" ").toLowerCase();
    }

    function getFilteredProjects() {
        const filter = getFilterState();
        const filtered = allProjects.filter((project) => {
            const matchesSearch = filter.search === "" || getSearchHaystack(project).includes(filter.search);
            const matchesType = filter.type === "all" || project.type === filter.type;
            const matchesVisibility = filter.visibility === "all" || project.visibility === filter.visibility;

            return matchesSearch && matchesType && matchesVisibility;
        });

        return filtered.sort((left, right) => {
            if (filter.sort === "manual") {
                return compareManualProjects(left, right);
            }

            if (filter.sort === "title") {
                return String(left.title).localeCompare(String(right.title), "de");
            }

            if (filter.sort === "owner") {
                return String(left.ownerUsername).localeCompare(String(right.ownerUsername), "de");
            }

            return new Date(right.createdAt).getTime() - new Date(left.createdAt).getTime();
        });
    }

    function updateMetrics(projects) {
        byId("metric-total").textContent = String(projects.length);
        byId("metric-owned").textContent = String(projects.filter((project) => project.isOwner).length);
        byId("metric-webpages").textContent = String(projects.filter((project) => project.type === "webpage").length);
    }

    function renderProjects() {
        const list = byId("project-list");
        const count = byId("project-count");
        const filter = getFilterState();
        const projects = getFilteredProjects();
        const manualMode = filter.sort === "manual";

        list.replaceChildren();
        list.classList.toggle("manual-order-mode", manualMode);
        count.textContent = projects.length === allProjects.length ? String(projects.length) : `${projects.length}/${allProjects.length}`;

        if (projects.length === 0) {
            const empty = document.createElement("p");
            empty.className = "empty-state";
            empty.textContent = allProjects.length === 0 ? "Noch keine Projekte verfügbar." : "Keine Projekte passen zu den Filtern.";
            list.append(empty);
            return;
        }

        projects.forEach((project, index) => {
            list.append(createProjectCard(project, {
                manualMode,
                index,
                total: projects.length
            }));
        });
    }

    async function loadProjects() {
        const list = byId("project-list");
        list.replaceChildren();
        setStatus("Lade");

        try {
            const data = await window.AppApi.listProjects();
            allProjects = Array.isArray(data.projects) ? data.projects : [];
            updateMetrics(allProjects);
            renderProjects();
            setStatus("Bereit");
        } catch (error) {
            setStatus("Fehler");
            updateMetrics([]);
            const empty = document.createElement("p");
            empty.className = "empty-state";
            empty.textContent = error.message;
            list.append(empty);
        }
    }

    function updateFileSummary(inputId, outputId) {
        const input = byId(inputId);
        const output = byId(outputId);
        const files = input && input.files ? Array.from(input.files) : [];

        output.replaceChildren();
        output.classList.toggle("has-files", files.length > 0);

        if (files.length === 0) {
            return;
        }

        const totalBytes = files.reduce((sum, file) => sum + file.size, 0);
        const summary = document.createElement("span");
        summary.textContent = files.length === 1
            ? `${files[0].name} · ${formatBytes(totalBytes)}`
            : `${files.length} Dateien · ${formatBytes(totalBytes)}`;
        output.append(summary);

        files.slice(0, 4).forEach((file) => {
            const item = document.createElement("span");
            item.textContent = file.webkitRelativePath || file.name;
            output.append(item);
        });
    }

    function getDraftPayload() {
        return {
            title: byId("project-title").value,
            type: byId("project-type").value,
            uploadKind: getActiveUploadKind(),
            visibility: byId("project-visibility").value,
            sharedUsernames: byId("shared-usernames").value,
            publicPermission: byId("public-permission").value,
            sharedPermissions: readSharePermissionList(byId("shared-permissions")),
            savedAt: new Date().toISOString()
        };
    }

    function saveDraft() {
        try {
            localStorage.setItem(DRAFT_KEY, JSON.stringify(getDraftPayload()));
            setMessage("Entwurf wurde lokal gemerkt.", "success");
        } catch (error) {
            setMessage("Entwurf konnte nicht gemerkt werden.", "error");
        }
    }

    function loadDraft() {
        const rawDraft = localStorage.getItem(DRAFT_KEY);
        if (!rawDraft) {
            setMessage("Kein Entwurf vorhanden.", "error");
            return;
        }

        try {
            const draft = JSON.parse(rawDraft);
            byId("project-title").value = draft.title || "";
            byId("project-type").value = draft.type || "file";
            byId("upload-kind").value = "single";
            byId("project-visibility").value = draft.visibility || "private";
            byId("shared-usernames").value = draft.sharedUsernames || "";
            byId("public-permission").value = normalizePermissionValue(draft.publicPermission || "read");
            updateConditionalFields();
            renderSharePermissionList(
                byId("shared-permissions"),
                parseSharedUsernames(byId("shared-usernames").value),
                draft.sharedPermissions || {}
            );
            setMessage("Entwurf geladen. Dateien müssen neu gewählt werden.", "success");
        } catch (error) {
            setMessage("Entwurf konnte nicht geladen werden.", "error");
        }
    }

    function bindProjectFilters() {
        ["project-search", "project-type-filter", "project-visibility-filter", "project-sort"].forEach((id) => {
            const element = byId(id);
            if (!element) {
                return;
            }

            element.addEventListener(id === "project-search" ? "input" : "change", renderProjects);
        });
    }

    function bindLogoutButtons() {
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

    async function init() {
        await window.AppApi.initSiteChrome();
        const session = await window.AppApi.ensureSession();
        if (!session) {
            return;
        }

        window.AppApi.setUserText(session.user);
        bindLogoutButtons();
        bindProjectFilters();

        byId("project-type").addEventListener("change", updateConditionalFields);
        byId("project-visibility").addEventListener("change", updateConditionalFields);
        byId("shared-usernames").addEventListener("input", syncCreateSharePermissions);
        byId("project-file").addEventListener("change", () => updateFileSummary("project-file", "single-file-summary"));
        byId("project-draft-save").addEventListener("click", saveDraft);
        byId("project-draft-load").addEventListener("click", loadDraft);
        restoreCodeDraft();
        loadActiveCodeFile();
        updateCodeTabs();
        byId("code-editor").addEventListener("input", () => {
            saveActiveCodeFile();
            updateCodePreview();
            persistCodeDraft();
        });
        byId("code-reset").addEventListener("click", resetStarterCode);
        byId("code-save-project").addEventListener("click", saveCodeProject);
        document.querySelectorAll("[data-webpage-mode]").forEach((button) => {
            button.addEventListener("click", () => {
                webpageMode = button.dataset.webpageMode;
                updateConditionalFields();
            });
        });
        updateConditionalFields();

        byId("project-form").addEventListener("submit", async (event) => {
            event.preventDefault();
            setMessage("");

            if (byId("project-type").value === "webpage" && webpageMode === "editor") {
                await saveCodeProject();
                return;
            }

            if (!validateForm()) {
                return;
            }

            const form = event.currentTarget;
            const submit = byId("project-submit");
            const formData = new FormData(form);
            formData.set("publicPermission", byId("public-permission").value);
            formData.set("sharedPermissions", JSON.stringify(readSharePermissionList(byId("shared-permissions"))));
            if (byId("project-type").value === "webpage") {
                formData.set("uploadKind", "folder");
            }
            appendProjectUploadFiles(formData);
            submit.disabled = true;
            setStatus("Speichere");

            try {
                await window.AppApi.createProject(formData);
                form.reset();
                byId("single-file-summary").classList.remove("has-files");
                byId("single-file-summary").replaceChildren();
                updateConditionalFields();
                setMessage("Projekt wurde erstellt.", "success");
                await loadProjects();
            } catch (error) {
                setMessage(error.message, "error");
                setStatus("Fehler");
            } finally {
                submit.disabled = false;
            }
        });

        await loadProjects();
    }

    document.addEventListener("DOMContentLoaded", () => {
        init().catch((error) => {
            setStatus("Fehler");
            setMessage(error.message, "error");
        });
    });
})();
