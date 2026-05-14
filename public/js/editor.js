(function () {
    "use strict";

    let project = null;
    let files = {};
    let assetFiles = {};
    let binaryFiles = {};
    let folders = new Set();
    let deletedPaths = new Set();
    let activeFile = "";
    let dirty = false;

    function byId(id) {
        return document.getElementById(id);
    }

    function setStatus(text) {
        byId("editor-status").textContent = text;
    }

    function setMessage(text, type = "") {
        const message = byId("editor-message");
        message.textContent = text;
        message.className = `form-message ${type}`.trim();
    }

    function getProjectId() {
        const id = Number(new URLSearchParams(window.location.search).get("id"));
        return Number.isInteger(id) && id > 0 ? id : 0;
    }

    function normalizePermissionValue(value) {
        return value === "write" ? "write" : "read";
    }

    function canEditProject() {
        return Boolean(project && project.canEdit);
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

    function normalizeRelativePath(path) {
        const cleanPath = String(path || "").replace(/\\/g, "/").trim();
        const parts = [];

        cleanPath.split("/").forEach((part) => {
            const safePart = part.trim().replace(/[^a-zA-Z0-9._ -]/g, "_").replace(/^[. ]+|[. ]+$/g, "");
            if (!safePart || safePart === "." || safePart === "..") {
                return;
            }
            parts.push(safePart);
        });

        return parts.join("/");
    }

    function normalizeFolderPath(path) {
        return normalizeRelativePath(path).replace(/\/+$/, "");
    }

    function getUploadPath(file) {
        return normalizeRelativePath(file.webkitRelativePath || file.name || "");
    }

    function detectImportRootPrefix(fileList) {
        const paths = Array.from(fileList || []).map(getUploadPath).filter(Boolean);
        if (paths.length === 0 || !paths[0].includes("/")) {
            return "";
        }

        const root = paths[0].split("/")[0];
        const rootLower = root.toLowerCase();
        const knownProjectFolders = new Set(["assets", "css", "html", "images", "img", "js", "media", "script", "scripts", "style", "styles"]);
        if (knownProjectFolders.has(rootLower)) {
            return "";
        }

        if (!paths.every((path) => path.startsWith(`${root}/`))) {
            return "";
        }

        const lowerPaths = paths.map((path) => path.toLowerCase());
        if (lowerPaths.includes(`${rootLower}/index.html`)) {
            return `${root}/`;
        }

        const rootChildren = new Set(paths.map((path) => path.split("/")[1] || "").filter(Boolean).map((part) => part.toLowerCase()));
        const knownChildCount = Array.from(rootChildren).filter((part) => knownProjectFolders.has(part) || part === "index.html").length;
        return knownChildCount >= 2 ? `${root}/` : "";
    }

    function stripImportRoot(path, importRootPrefix) {
        return importRootPrefix && path.startsWith(importRootPrefix)
            ? path.slice(importRootPrefix.length)
            : path;
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

    function hydrateFolders(folderList, fileMap, assetMap = {}) {
        const nextFolders = new Set();

        (Array.isArray(folderList) ? folderList : []).forEach((folderPath) => {
            const normalized = normalizeFolderPath(folderPath);
            if (!normalized) {
                return;
            }
            nextFolders.add(normalized);
            collectParentPaths(`${normalized}/placeholder.txt`).forEach((parentPath) => nextFolders.add(parentPath));
        });

        Object.keys(fileMap).forEach((filePath) => {
            collectParentPaths(filePath).forEach((folderPath) => nextFolders.add(folderPath));
        });

        Object.keys(assetMap).forEach((filePath) => {
            collectParentPaths(filePath).forEach((folderPath) => nextFolders.add(folderPath));
        });

        return nextFolders;
    }

    function hydrateAssetFiles(assetList) {
        const nextAssets = {};
        (Array.isArray(assetList) ? assetList : []).forEach((asset) => {
            const path = normalizeRelativePath(asset.path || asset.openPath || "");
            if (!path || files[path] !== undefined) {
                return;
            }

            nextAssets[path] = {
                fileSize: Number(asset.fileSize || asset.size || 0),
                mimeType: String(asset.mimeType || "application/octet-stream"),
                openUrl: String(asset.openUrl || "")
            };
        });

        return nextAssets;
    }

    function sortedFileNames() {
        return Object.keys(files).sort((left, right) => {
            if (left === "index.html") {
                return -1;
            }
            if (right === "index.html") {
                return 1;
            }
            return left.localeCompare(right, "de");
        });
    }

    function sortedFolderNames() {
        return Array.from(folders).sort((left, right) => left.localeCompare(right, "de"));
    }

    function sortedProjectFileNames() {
        return Array.from(new Set([
            ...Object.keys(files),
            ...Object.keys(assetFiles),
            ...Object.keys(binaryFiles)
        ])).sort((left, right) => {
            if (left === "index.html") {
                return -1;
            }
            if (right === "index.html") {
                return 1;
            }
            return left.localeCompare(right, "de");
        });
    }

    function removeDeletedPath(path) {
        const normalized = normalizeRelativePath(path).toLowerCase();
        if (normalized) {
            deletedPaths.delete(normalized);
        }
    }

    function markDeletedPath(path) {
        const normalized = normalizeRelativePath(path).toLowerCase();
        if (normalized) {
            deletedPaths.add(normalized);
        }
    }

    function getRequestedPath(fallback) {
        const input = byId("editor-new-path");
        const typedPath = input ? input.value.trim() : "";
        return typedPath || window.prompt("Pfad im Projekt", fallback) || "";
    }

    function clearRequestedPath() {
        const input = byId("editor-new-path");
        if (input) {
            input.value = "";
        }
    }

    function getFileExtension(path) {
        const cleanPath = String(path || "").split("?")[0].split("#")[0];
        const extension = cleanPath.includes(".") ? cleanPath.split(".").pop() : "";
        return extension ? extension.toUpperCase().slice(0, 5) : "FILE";
    }

    function isEditorTextFile(file) {
        const path = String(file.webkitRelativePath || file.name || "").toLowerCase();
        const type = String(file.type || "").toLowerCase();
        const extension = path.includes(".") ? path.split(".").pop() : "";
        const textExtensions = new Set(["html", "htm", "css", "js", "mjs", "json", "txt", "md", "svg", "xml"]);

        return type.startsWith("text/")
            || type === "application/json"
            || type === "image/svg+xml"
            || textExtensions.has(extension);
    }

    function isEditorAssetFile(file) {
        const path = String(file.webkitRelativePath || file.name || "").toLowerCase();
        const type = String(file.type || "").toLowerCase();
        const extension = path.includes(".") ? path.split(".").pop() : "";
        const assetExtensions = new Set(["png", "jpg", "jpeg", "gif", "webp", "ico"]);

        return type.startsWith("image/") || assetExtensions.has(extension);
    }

    function readFileAsText(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.addEventListener("load", () => resolve(String(reader.result || "")));
            reader.addEventListener("error", () => reject(new Error(`Datei "${file.name}" konnte nicht gelesen werden.`)));
            reader.readAsText(file);
        });
    }

    function readFileAsBase64(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.addEventListener("load", () => {
                const result = String(reader.result || "");
                resolve({
                    data: result.includes(",") ? result.slice(result.indexOf(",") + 1) : result,
                    fileSize: file.size,
                    mimeType: file.type || "application/octet-stream"
                });
            });
            reader.addEventListener("error", () => reject(new Error(`Datei "${file.name}" konnte nicht gelesen werden.`)));
            reader.readAsDataURL(file);
        });
    }

    function setDirty(nextDirty) {
        dirty = canEditProject() ? nextDirty : false;
        if (!project) {
            setStatus("Lade");
            return;
        }
        if (!canEditProject()) {
            setStatus("Read only");
            return;
        }
        setStatus(dirty ? "Ungespeichert" : "Bereit");
    }

    function saveActiveFile() {
        if (!activeFile || files[activeFile] === undefined) {
            return;
        }

        files[activeFile] = byId("editor-code").value;
    }

    function openFile(path) {
        saveActiveFile();
        activeFile = path;
        byId("editor-code").value = files[activeFile] || "";
        renderFileTree();
        renderTabs();
        updateEditorStats();
    }

    function ensureFolderChain(path) {
        const normalized = normalizeFolderPath(path);
        if (!normalized) {
            return;
        }

        folders.add(normalized);
        removeDeletedPath(normalized);
        collectParentPaths(`${normalized}/placeholder.txt`).forEach((folderPath) => folders.add(folderPath));
    }

    function createTreeData() {
        const root = { path: "", folders: new Map(), files: [] };

        const ensureFolderNode = (folderPath) => {
            const parts = folderPath.split("/").filter(Boolean);
            let current = root;
            let currentPath = "";

            parts.forEach((part) => {
                currentPath = currentPath ? `${currentPath}/${part}` : part;
                if (!current.folders.has(part)) {
                    current.folders.set(part, { path: currentPath, folders: new Map(), files: [] });
                }
                current = current.folders.get(part);
            });

            return current;
        };

        sortedFolderNames().forEach((folderPath) => ensureFolderNode(folderPath));

        sortedProjectFileNames().forEach((filePath) => {
            const parents = collectParentPaths(filePath);
            const parentNode = parents.length > 0 ? ensureFolderNode(parents[parents.length - 1]) : root;
            parentNode.files.push(filePath);
        });

        return root;
    }

    function createFolderRow(name, child, depth) {
        const row = document.createElement("div");
        row.className = "file-tree-folder-row";
        row.style.paddingLeft = `${10 + depth * 18}px`;
        row.setAttribute("role", "treeitem");
        row.setAttribute("aria-label", `Ordner ${child.path || name}`);

        const chevron = document.createElement("span");
        chevron.className = "file-tree-chevron";
        chevron.textContent = ">";

        const icon = document.createElement("span");
        icon.className = "file-tree-folder-icon";
        icon.textContent = "DIR";

        const label = document.createElement("span");
        label.className = "file-tree-folder-name";
        label.textContent = name;

        row.append(chevron, icon, label);

        if (canEditProject()) {
            const deleteButton = document.createElement("button");
            deleteButton.type = "button";
            deleteButton.className = "file-tree-delete";
            deleteButton.textContent = "Löschen";
            deleteButton.setAttribute("aria-label", `Ordner ${child.path || name} löschen`);
            deleteButton.addEventListener("click", (event) => {
                event.stopPropagation();
                deleteFolder(child.path || name);
            });
            row.append(deleteButton);
        }

        return row;
    }

    function createFileButton(path, depth = 0) {
        const button = document.createElement("div");
        const asset = files[path] === undefined ? (binaryFiles[path] || assetFiles[path] || null) : null;
        button.className = "file-tree-item";
        button.classList.toggle("active", path === activeFile);
        button.style.paddingLeft = `${10 + depth * 18}px`;
        button.setAttribute("role", "treeitem");
        button.setAttribute("tabindex", "0");
        button.setAttribute("aria-label", `Datei ${path}`);
        if (files[path] !== undefined) {
            button.addEventListener("click", () => openFile(path));
            button.addEventListener("keydown", (event) => {
                if (event.key === "Enter" || event.key === " ") {
                    event.preventDefault();
                    openFile(path);
                }
            });
        } else {
            button.classList.add("asset-file");
            button.addEventListener("click", () => setMessage(`"${path}" ist eine Bilddatei und wird beim Speichern mitgehostet.`, ""));
        }

        const chevron = document.createElement("span");
        chevron.className = "file-tree-chevron";

        const extension = document.createElement("span");
        extension.className = "file-ext";
        extension.textContent = getFileExtension(path);

        const name = document.createElement("span");
        name.className = "file-name";
        name.textContent = path.split("/").pop() || path;

        const size = document.createElement("span");
        size.className = "file-size";
        size.textContent = formatBytes(asset ? asset.fileSize : new Blob([files[path] || ""]).size);

        button.append(chevron, extension, name, size);

        if (canEditProject()) {
            const deleteButton = document.createElement("button");
            deleteButton.type = "button";
            deleteButton.className = "file-tree-delete";
            deleteButton.textContent = "Löschen";
            deleteButton.setAttribute("aria-label", `Datei ${path} löschen`);
            deleteButton.addEventListener("click", (event) => {
                event.stopPropagation();
                deleteFile(path);
            });
            button.append(deleteButton);
        }

        return button;
    }

    function selectFallbackFile() {
        const names = sortedFileNames();
        activeFile = names[0] || "";
        byId("editor-code").value = activeFile ? files[activeFile] || "" : "";
    }

    function deleteFile(path) {
        if (!canEditProject()) {
            setMessage("Dieses Projekt ist read-only.", "error");
            return;
        }

        const normalized = normalizeRelativePath(path);
        if (!normalized) {
            return;
        }

        if (normalized === "index.html" && !window.confirm("index.html löschen? Ohne index.html kann das Projekt erst nach einer neuen index.html wieder gespeichert werden.")) {
            return;
        }

        saveActiveFile();
        delete files[normalized];
        delete assetFiles[normalized];
        delete binaryFiles[normalized];
        markDeletedPath(normalized);

        if (activeFile === normalized) {
            selectFallbackFile();
        }

        setDirty(true);
        renderFileTree();
        renderTabs();
        updateEditorStats();
        setMessage(`Datei "${normalized}" wurde entfernt. Speichern löscht sie dauerhaft.`, "success");
    }

    function deleteFolder(path) {
        if (!canEditProject()) {
            setMessage("Dieses Projekt ist read-only.", "error");
            return;
        }

        const normalized = normalizeFolderPath(path);
        if (!normalized) {
            return;
        }

        const prefix = `${normalized}/`;
        const affectedFiles = sortedProjectFileNames().filter((filePath) => filePath === normalized || filePath.startsWith(prefix));
        const affectedFolders = sortedFolderNames().filter((folderPath) => folderPath === normalized || folderPath.startsWith(prefix));

        if (affectedFiles.length === 0 && affectedFolders.length === 0) {
            return;
        }

        if (!window.confirm(`Ordner "${normalized}" mit ${affectedFiles.length} Datei${affectedFiles.length === 1 ? "" : "en"} löschen?`)) {
            return;
        }

        saveActiveFile();
        affectedFiles.forEach((filePath) => {
            delete files[filePath];
            delete assetFiles[filePath];
            delete binaryFiles[filePath];
            markDeletedPath(filePath);
        });
        affectedFolders.forEach((folderPath) => folders.delete(folderPath));

        if (activeFile && (activeFile === normalized || activeFile.startsWith(prefix))) {
            selectFallbackFile();
        }

        setDirty(true);
        renderFileTree();
        renderTabs();
        updateEditorStats();
        setMessage(`Ordner "${normalized}" wurde entfernt. Speichern löscht die Inhalte dauerhaft.`, "success");
    }

    function renderTreeNode(container, node, depth = 0) {
        Array.from(node.folders.entries())
            .sort((left, right) => left[0].localeCompare(right[0], "de"))
            .forEach(([name, child]) => {
                container.append(createFolderRow(name, child, depth));

                const children = document.createElement("div");
                children.className = "file-tree-children";
                renderTreeNode(children, child, depth + 1);
                container.append(children);
            });

        node.files
            .sort((left, right) => left.localeCompare(right, "de"))
            .forEach((path) => container.append(createFileButton(path, depth)));
    }

    function renderFileTree() {
        const tree = byId("editor-file-tree");
        tree.replaceChildren();

        const data = createTreeData();
        if (data.folders.size === 0 && data.files.length === 0) {
            const empty = document.createElement("p");
            empty.className = "empty-state";
            empty.textContent = "Keine Dateien vorhanden.";
            tree.append(empty);
            return;
        }

        renderTreeNode(tree, data);
    }

    function renderTabs() {
        const tabs = byId("editor-file-tabs");
        tabs.replaceChildren();

        sortedFileNames().forEach((path) => {
            const button = document.createElement("button");
            button.type = "button";
            button.className = "editor-tab";
            button.classList.toggle("active", path === activeFile);
            button.textContent = path;
            button.addEventListener("click", () => openFile(path));
            tabs.append(button);
        });
    }

    function updateEditorStats() {
        const textLength = Object.values(files).reduce((sum, content) => sum + String(content || "").length, 0);
        const assetSize = sortedProjectFileNames().reduce((sum, path) => {
            const asset = files[path] === undefined ? (binaryFiles[path] || assetFiles[path] || null) : null;
            return sum + (asset ? Number(asset.fileSize || 0) : 0);
        }, 0);
        byId("editor-subtitle").textContent = `${sortedProjectFileNames().length} Dateien · ${sortedFolderNames().length} Ordner · ${formatBytes(textLength + assetSize) || "0 B"}`;
    }

    function updateProjectChrome() {
        const title = project ? project.title : "Projekt";
        const permissionText = project && normalizePermissionValue(project.publicPermission) === "write" ? "Public write" : "Read only";
        byId("editor-heading").textContent = title;
        byId("editor-subtitle").textContent = project
            ? `${project.fileCount || sortedFileNames().length} Dateien im Projekt${project.canEdit ? "" : ` · ${permissionText}`}`
            : "";
        document.title = `${title} | Editor`;

        const openSite = byId("editor-open-site");
        if (project && project.viewUrl) {
            openSite.href = window.AppApi.apiUrl(project.viewUrl);
            openSite.setAttribute("aria-disabled", "false");
        }

        const editable = canEditProject();
        byId("editor-code").readOnly = !editable;
        byId("editor-save").disabled = !editable;
        byId("editor-add-file").disabled = !editable;
        byId("editor-add-folder").disabled = !editable;
        byId("editor-import-folder").disabled = !editable;
        byId("editor-code").classList.toggle("is-readonly", !editable);

        if (!editable) {
            setMessage("Dieses Projekt ist im Editor read-only.", "");
        }

        setDirty(false);
    }

    async function loadProject() {
        const projectId = getProjectId();
        if (projectId <= 0) {
            throw new Error("Kein gültiges Projekt angegeben.");
        }

        setStatus("Lade");
        setMessage("");

        const data = await window.AppApi.getProjectCode(projectId);
        project = data.project || null;
        files = data.files && typeof data.files === "object" ? data.files : {};
        binaryFiles = {};
        deletedPaths = new Set();
        assetFiles = hydrateAssetFiles(data.assets || []);
        folders = hydrateFolders(data.folders || [], files, assetFiles);

        if (sortedFileNames().length === 0) {
            if (!canEditProject()) {
                throw new Error("Dieses Projekt enthält keine editierbaren Textdateien.");
            }
            files = {
                "index.html": "<!doctype html>\n<html lang=\"de\">\n<head>\n    <meta charset=\"utf-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n    <title>Neue Seite</title>\n</head>\n<body>\n</body>\n</html>"
            };
        }

        activeFile = data.entryFile && files[data.entryFile] !== undefined ? data.entryFile : sortedFileNames()[0];
        byId("editor-code").value = files[activeFile] || "";
        updateProjectChrome();
        renderFileTree();
        renderTabs();
        updateEditorStats();
    }

    function addFolder() {
        if (!canEditProject()) {
            setMessage("Dieses Projekt ist read-only.", "error");
            return;
        }

        const requestedPath = getRequestedPath("assets");
        const path = normalizeFolderPath(requestedPath);
        if (!path) {
            setMessage("Bitte gib einen gültigen Ordnerpfad ein.", "error");
            return;
        }

        ensureFolderChain(path);
        clearRequestedPath();
        setDirty(true);
        renderFileTree();
        updateEditorStats();
        setMessage(`Ordner "${path}" wurde angelegt. Speichern übernimmt ihn dauerhaft.`, "success");
    }

    function addFile() {
        if (!canEditProject()) {
            setMessage("Dieses Projekt ist read-only.", "error");
            return;
        }

        const requestedPath = getRequestedPath("assets/style.css");
        const path = normalizeRelativePath(requestedPath);
        if (!path) {
            setMessage("Bitte gib einen gültigen Dateipfad ein.", "error");
            return;
        }

        if (files[path] !== undefined) {
            openFile(path);
            return;
        }

        saveActiveFile();
        files[path] = "";
        removeDeletedPath(path);
        collectParentPaths(path).forEach((folderPath) => ensureFolderChain(folderPath));
        clearRequestedPath();
        setDirty(true);
        openFile(path);
    }

    async function importFolderFiles(fileList) {
        if (!canEditProject()) {
            setMessage("Dieses Projekt ist read-only.", "error");
            return;
        }

        const selectedFiles = Array.from(fileList || []);
        if (selectedFiles.length === 0) {
            return;
        }

        saveActiveFile();
        setStatus("Importiere");
        setMessage("");

        let importedCount = 0;
        let skippedCount = 0;
        const importedPaths = [];
        const importRootPrefix = detectImportRootPrefix(selectedFiles);

        for (const file of selectedFiles) {
            const rawPath = getUploadPath(file);
            const path = stripImportRoot(rawPath, importRootPrefix);
            if (!path || !isEditorTextFile(file)) {
                if (!path || !isEditorAssetFile(file)) {
                    skippedCount += 1;
                    continue;
                }

                delete files[path];
                removeDeletedPath(path);
                binaryFiles[path] = await readFileAsBase64(file);
                assetFiles[path] = {
                    fileSize: binaryFiles[path].fileSize,
                    mimeType: binaryFiles[path].mimeType,
                    openUrl: ""
                };
            } else {
                files[path] = await readFileAsText(file);
                delete binaryFiles[path];
                delete assetFiles[path];
                removeDeletedPath(path);
            }

            collectParentPaths(path).forEach((folderPath) => ensureFolderChain(folderPath));
            importedPaths.push(path);
            importedCount += 1;
        }

        if (importedCount === 0) {
            setStatus("Bereit");
            setMessage("Keine unterstützten Text-/Webdateien im Ordner gefunden.", "error");
            return;
        }

        const firstTextImport = importedPaths.find((path) => files[path] !== undefined);
        if (importedPaths.includes("index.html")) {
            activeFile = "index.html";
        } else if (firstTextImport) {
            activeFile = firstTextImport;
        }
        byId("editor-code").value = files[activeFile] || byId("editor-code").value;
        setDirty(true);
        renderFileTree();
        renderTabs();
        updateEditorStats();
        const rootMessage = importRootPrefix ? ` Root "${importRootPrefix.slice(0, -1)}" wurde entfernt.` : "";
        setMessage(`${importedCount} Datei${importedCount === 1 ? "" : "en"} inklusive Bilder importiert${skippedCount > 0 ? `, ${skippedCount} übersprungen` : ""}.${rootMessage} Speichern übernimmt den Ordner dauerhaft.`, "success");
    }

    async function saveProject() {
        if (!canEditProject()) {
            setMessage("Dieses Projekt ist read-only.", "error");
            return;
        }

        saveActiveFile();

        if (!files["index.html"] && !sortedFileNames().some((path) => path.toLowerCase().endsWith("/index.html"))) {
            setMessage("Das Projekt braucht eine index.html.", "error");
            return;
        }

        const button = byId("editor-save");
        button.disabled = true;
        setStatus("Speichere");
        setMessage("");

        try {
            const data = await window.AppApi.updateProjectCode({
                id: getProjectId(),
                files,
                binaryFiles,
                folders: sortedFolderNames(),
                deletedPaths: Array.from(deletedPaths)
            });
            project = data.project || project;
            binaryFiles = {};
            deletedPaths = new Set();
            assetFiles = hydrateAssetFiles(data.assets || []);
            folders = hydrateFolders(sortedFolderNames(), files, assetFiles);
            updateProjectChrome();
            renderFileTree();
            renderTabs();
            updateEditorStats();
            setMessage("Projekt wurde gespeichert.", "success");
        } catch (error) {
            setStatus("Fehler");
            setMessage(error.message, "error");
        } finally {
            button.disabled = false;
        }
    }

    function bindEditor() {
        byId("editor-code").addEventListener("input", () => {
            saveActiveFile();
            setDirty(true);
            updateEditorStats();
        });
        byId("editor-save").addEventListener("click", saveProject);
        byId("editor-reload").addEventListener("click", () => {
            if (dirty && !window.confirm("Ungespeicherte Änderungen verwerfen und neu laden?")) {
                return;
            }
            loadProject().catch((error) => {
                setStatus("Fehler");
                setMessage(error.message, "error");
            });
        });
        byId("editor-add-file").addEventListener("click", addFile);
        byId("editor-add-folder").addEventListener("click", addFolder);
        byId("editor-import-folder").addEventListener("click", () => byId("editor-folder-upload").click());
        byId("editor-folder-upload").addEventListener("change", (event) => {
            importFolderFiles(event.currentTarget.files).catch((error) => {
                setStatus("Fehler");
                setMessage(error.message, "error");
            }).finally(() => {
                event.currentTarget.value = "";
            });
        });

        window.addEventListener("beforeunload", (event) => {
            if (!dirty) {
                return;
            }
            event.preventDefault();
            event.returnValue = "";
        });
    }

    async function init() {
        await window.AppApi.initSiteChrome();
        const session = await window.AppApi.ensureSession();
        if (!session) {
            return;
        }

        bindLogoutButtons();
        bindEditor();
        await loadProject();
    }

    document.addEventListener("DOMContentLoaded", () => {
        init().catch((error) => {
            setStatus("Fehler");
            setMessage(error.message, "error");
        });
    });
})();
