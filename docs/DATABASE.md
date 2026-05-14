# Database

Liveserver nutzt SQLite. Der Standardpfad ist:

```text
database/liveserver.sqlite
```

Der Zugriff wird zentral in `php/db_connect.php` aufgebaut. Die Funktion `get_db_connection()` erstellt eine PDO-Verbindung, aktiviert Foreign Keys und ruft `ensure_sqlite_schema()` auf.

## Runtime-Dateien

Nicht committen:

- `database/*.sqlite`
- `database/*.sqlite-wal`
- `database/*.sqlite-shm`
- `database/*.db`

## Tabellen

### `users`

Speichert Benutzerkonten.

Wichtige Spalten:

- `id`
- `username`
- `password_hash`
- `realname`
- `role`
- `theme_json`
- `created_at`
- `updated_at`

`theme_json` enthält persönliche Farben eines Benutzers.

### `projects`

Speichert Projekt-Metadaten.

Wichtige Spalten:

- `id`
- `owner_id`
- `title`
- `type`: `file` oder `webpage`
- `visibility`: `private`, `shared` oder `public`
- `public_permission`: `read` oder `write`
- `upload_kind`: `single` oder `folder`
- `stored_filename`
- `original_filename`
- `mime_type`
- `file_size`
- `created_at`
- `updated_at`

Webpages werden nicht als direktes HTML-Feld gespeichert. Sie bestehen aus Dateien in `project_files`, darunter eine `index.html`.

### `project_files`

Speichert die Dateien eines Projekts.

Wichtige Spalten:

- `id`
- `project_id`
- `stored_filename`
- `original_filename`
- `relative_path`
- `mime_type`
- `file_size`
- `created_at`

`stored_filename` ist der serverseitig generierte Dateiname im Upload-Speicher.

### `project_folders`

Speichert leere oder explizit angelegte Ordner im Webpage-Editor.

Wichtige Spalten:

- `id`
- `project_id`
- `relative_path`
- `created_at`

### `project_shares`

Speichert geteilte Benutzer und ihre Berechtigung.

Wichtige Spalten:

- `id`
- `project_id`
- `username`
- `permission`: `read` oder `write`
- `created_at`

### `project_orders`

Speichert die persönliche Projekt-Reihenfolge pro Benutzer.

Wichtige Spalten:

- `user_id`
- `project_id`
- `sort_order`
- `updated_at`

## Migrationen

Neue Spalten müssen migrationssicher in `php/db_connect.php` ergänzt werden. Für neue Installationen muss `install.sh` dieselbe Struktur erzeugen.

Regel:

- `php/db_connect.php` ist maßgeblich für bestehende Installationen.
- `install.sh` ist maßgeblich für frische Installationen.
- Beide müssen nach Schemaänderungen zusammen aktualisiert werden.
