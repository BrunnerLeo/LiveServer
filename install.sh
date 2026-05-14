#!/usr/bin/env bash

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SERVER_NAME="${SERVER_NAME:-liveserver.local}"
HTTP_PORT="${HTTP_PORT:-80}"
SITE_NAME="${SITE_NAME:-liveserver}"
APACHE_USER="${APACHE_USER:-www-data}"
APACHE_GROUP="${APACHE_GROUP:-www-data}"
SQLITE_DB="${PROJECT_ROOT}/database/liveserver.sqlite"
UPLOAD_DIR="${PROJECT_ROOT}/uploads/projects"
APACHE_CONF="/etc/apache2/sites-available/${SITE_NAME}.conf"

info() {
    printf '\033[1;34m[INFO]\033[0m %s\n' "$1"
}

warn() {
    printf '\033[1;33m[WARN]\033[0m %s\n' "$1"
}

fail() {
    printf '\033[1;31m[ERROR]\033[0m %s\n' "$1" >&2
    exit 1
}

run_root() {
    if [[ "${EUID}" -eq 0 ]]; then
        "$@"
    else
        sudo "$@"
    fi
}

require_ubuntu_or_debian() {
    if [[ ! -f /etc/os-release ]]; then
        fail "Dieses Installationsskript ist für Ubuntu/Debian gedacht."
    fi

    # shellcheck disable=SC1091
    source /etc/os-release
    case "${ID:-}" in
        ubuntu|debian)
            info "System erkannt: ${PRETTY_NAME:-$ID}"
            ;;
        *)
            warn "System ist nicht Ubuntu/Debian (${PRETTY_NAME:-unbekannt}). Ich versuche trotzdem eine apt-basierte Installation."
            ;;
    esac
}

install_packages() {
    info "Installiere Apache, PHP und SQLite-Komponenten..."
    run_root apt-get update
    run_root env DEBIAN_FRONTEND=noninteractive apt-get install -y \
        apache2 \
        php \
        libapache2-mod-php \
        php-sqlite3 \
        php-zip \
        sqlite3
}

ensure_project_storage() {
    info "Bereite Datenbank- und Logordner vor..."
    run_root mkdir -p "${PROJECT_ROOT}/database" "${PROJECT_ROOT}/logs" "${UPLOAD_DIR}"

    local database_htaccess
    local logs_htaccess
    local uploads_htaccess
    local upload_projects_htaccess
    database_htaccess="$(mktemp)"
    logs_htaccess="$(mktemp)"
    uploads_htaccess="$(mktemp)"
    upload_projects_htaccess="$(mktemp)"

    cat > "${database_htaccess}" <<'EOF'
Require all denied
Deny from all
Options -Indexes
EOF

    cat > "${logs_htaccess}" <<'EOF'
Require all denied
Deny from all
Options -Indexes
EOF

    cat > "${uploads_htaccess}" <<'EOF'
Require all denied
Deny from all
Options -Indexes
EOF

    cat > "${upload_projects_htaccess}" <<'EOF'
Require all denied
Deny from all
Options -Indexes
EOF

    run_root mv "${database_htaccess}" "${PROJECT_ROOT}/database/.htaccess"
    run_root mv "${logs_htaccess}" "${PROJECT_ROOT}/logs/.htaccess"
    run_root mv "${uploads_htaccess}" "${PROJECT_ROOT}/uploads/.htaccess"
    run_root mv "${upload_projects_htaccess}" "${UPLOAD_DIR}/.htaccess"
}

init_sqlite_database() {
    info "Initialisiere SQLite-Datenbank..."
    run_root sqlite3 "${SQLITE_DB}" <<'SQL'
PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    realname TEXT NOT NULL DEFAULT '',
    role TEXT NOT NULL DEFAULT 'student',
    theme_json TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_users_role ON users (role);

CREATE TABLE IF NOT EXISTS projects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    type TEXT NOT NULL CHECK (type IN ('file', 'webpage')),
    visibility TEXT NOT NULL CHECK (visibility IN ('public', 'private', 'shared')),
    public_permission TEXT NOT NULL DEFAULT 'read' CHECK (public_permission IN ('read', 'write')),
    upload_kind TEXT NOT NULL DEFAULT 'single' CHECK (upload_kind IN ('single', 'folder')),
    stored_filename TEXT,
    original_filename TEXT,
    mime_type TEXT,
    file_size INTEGER,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_projects_owner_id ON projects (owner_id);
CREATE INDEX IF NOT EXISTS idx_projects_visibility ON projects (visibility);

CREATE TABLE IF NOT EXISTS project_orders (
    user_id INTEGER NOT NULL,
    project_id INTEGER NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, project_id),
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_project_orders_project_id ON project_orders (project_id);

CREATE TABLE IF NOT EXISTS project_files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    stored_filename TEXT NOT NULL,
    original_filename TEXT NOT NULL,
    relative_path TEXT NOT NULL,
    mime_type TEXT NOT NULL DEFAULT 'application/octet-stream',
    file_size INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_project_files_project_id ON project_files (project_id);

CREATE TABLE IF NOT EXISTS project_folders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    relative_path TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    UNIQUE (project_id, relative_path)
);

CREATE INDEX IF NOT EXISTS idx_project_folders_project_id ON project_folders (project_id);

CREATE TABLE IF NOT EXISTS project_shares (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    username TEXT NOT NULL COLLATE NOCASE,
    permission TEXT NOT NULL DEFAULT 'read' CHECK (permission IN ('read', 'write')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    UNIQUE (project_id, username)
);

CREATE INDEX IF NOT EXISTS idx_project_shares_username ON project_shares (username);
SQL
}

set_permissions() {
    info "Setze Rechte für Apache-Benutzer ${APACHE_USER}:${APACHE_GROUP}..."
    run_root chown -R "${APACHE_USER}:${APACHE_GROUP}" "${PROJECT_ROOT}/database" "${PROJECT_ROOT}/logs" "${PROJECT_ROOT}/uploads"
    run_root find "${PROJECT_ROOT}/database" "${PROJECT_ROOT}/logs" "${PROJECT_ROOT}/uploads" -type d -exec chmod 775 {} +
    run_root find "${PROJECT_ROOT}/database" "${PROJECT_ROOT}/logs" "${PROJECT_ROOT}/uploads" -type f -exec chmod 664 {} +
}

write_apache_site() {
    info "Schreibe Apache-Site: ${APACHE_CONF}"
    local listen_line=""

    if [[ "${HTTP_PORT}" != "80" ]]; then
        listen_line="Listen ${HTTP_PORT}"
    fi

    local tmp_conf
    tmp_conf="$(mktemp)"

    cat > "${tmp_conf}" <<EOF
${listen_line}
<VirtualHost *:${HTTP_PORT}>
    ServerName ${SERVER_NAME}
    ServerAlias localhost
    DocumentRoot "${PROJECT_ROOT}"
    DirectoryIndex index.html

    <Directory "${PROJECT_ROOT}">
        Require all granted
        Options -Indexes
        AllowOverride All
    </Directory>

    <Directory "${PROJECT_ROOT}/database">
        Require all denied
    </Directory>

    <Directory "${PROJECT_ROOT}/logs">
        Require all denied
    </Directory>

    <Directory "${PROJECT_ROOT}/uploads">
        Require all denied
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/${SITE_NAME}_error.log
    CustomLog \${APACHE_LOG_DIR}/${SITE_NAME}_access.log combined
</VirtualHost>
EOF

    run_root mv "${tmp_conf}" "${APACHE_CONF}"
}

enable_apache_site() {
    info "Aktiviere Apache-Module und Site..."
    local php_module
    php_module="php$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null || true)"

    run_root a2dismod mpm_event >/dev/null 2>&1 || true
    run_root a2enmod mpm_prefork
    run_root a2enmod rewrite headers
    if [[ "${php_module}" != "php" ]]; then
        run_root a2enmod "${php_module}" || true
    fi
    run_root a2ensite "${SITE_NAME}.conf"
    run_root apache2ctl configtest
    run_root systemctl restart apache2
}

check_php_modules() {
    info "Prüfe PHP-Module..."
    php -m | grep -Eiq '^pdo$' || fail "PHP-Modul pdo fehlt."
    php -m | grep -Eiq '^pdo_sqlite$' || fail "PHP-Modul pdo_sqlite fehlt."
    php -m | grep -Eiq '^zip$' || fail "PHP-Modul zip fehlt."
    php -m | grep -Eiq '^session$' || fail "PHP-Modul session fehlt."
    php -m | grep -Eiq '^json$' || fail "PHP-Modul json fehlt."
    info "PHP-Module sind OK."
}

print_done() {
    cat <<EOF

Fertig.

Projektpfad:
  ${PROJECT_ROOT}

SQLite-Datenbank:
  ${SQLITE_DB}

Upload-Speicher:
  ${UPLOAD_DIR}

Apache-Site:
  ${APACHE_CONF}

Öffnen:
  http://${SERVER_NAME}:${HTTP_PORT}/

Wenn ${SERVER_NAME} nicht auflöst, füge lokal oder am Server hinzu:
  127.0.0.1 ${SERVER_NAME}

Health-Check:
  http://${SERVER_NAME}:${HTTP_PORT}/php/health.php

EOF
}

main() {
    require_ubuntu_or_debian
    install_packages
    ensure_project_storage
    init_sqlite_database
    set_permissions
    write_apache_site
    enable_apache_site
    check_php_modules
    print_done
}

main "$@"
