# API

Alle JSON-Endpunkte liegen unter `php/`. POST-Endpunkte erwarten einen gültigen CSRF-Token. Der Token wird nach Login, Session-Check und den meisten erfolgreichen POST-Antworten zurückgegeben.

## Antwortformat

Erfolgreiche Antworten enthalten in der Regel:

```json
{
  "success": true
}
```

Fehler enthalten:

```json
{
  "success": false,
  "message": "Fehlertext"
}
```

Wenn `security.showDetailedErrors` aktiv ist, können interne Debug-Felder ergänzt werden. In Produktion muss dieser Wert `false` bleiben.

## Session und Benutzer

### GET `/php/health.php`

Prüft PHP-Erweiterungen, SQLite-Verbindung und Upload-Schreibrechte.

### GET `/php/session_check.php`

Gibt den aktuellen Login-Zustand zurück. Bei aktiver Session enthält die Antwort den öffentlichen Benutzerdatensatz und einen CSRF-Token.

### POST `/php/signup.php`

Erstellt ein Benutzerkonto, wenn Registrierung aktiviert ist.

JSON:

```json
{
  "username": "max.mustermann",
  "password": "geheim",
  "realname": "Max Mustermann"
}
```

### POST `/php/login.php`

Meldet einen Benutzer an.

JSON:

```json
{
  "username": "max.mustermann",
  "password": "geheim"
}
```

### POST `/php/logout.php`

Beendet die aktuelle Session.

### GET `/php/get_user_data.php`

Liefert Profildaten des eingeloggten Benutzers.

### POST `/php/update_user_data.php`

Aktualisiert Profilname und optional Passwort.

### POST `/php/update_user_theme.php`

Speichert persönliche Farben in `users.theme_json`.

JSON:

```json
{
  "csrfToken": "...",
  "theme": {
    "primary": "#155eef",
    "secondary": "#0f766e",
    "accent": "#f59e0b",
    "background": "#f7f9fc",
    "surface": "#ffffff",
    "text": "#172033"
  }
}
```

## Projekte

### GET `/php/list_projects.php`

Liefert alle Projekte, auf die der aktuelle Benutzer Zugriff hat. Öffentliche Projekte, eigene Projekte und geteilte Projekte werden zusammengeführt.

### POST `/php/create_project.php`

Erstellt ein Datei- oder Webpage-Projekt per `multipart/form-data`.

Wichtige Felder:

- `csrfToken`
- `title`
- `type`: `file` oder `webpage`
- `visibility`: `private`, `shared` oder `public`
- `publicPermission`: `read` oder `write`
- `sharedUsernames`
- `sharedPermissions`
- `uploadKind`: `single` oder `folder`
- `projectFiles[]`
- `projectRelativePaths[]`
- `projectFolderPaths[]`

Für Webpage-Projekte muss eine `index.html` enthalten sein. HTML wird nicht als separates Textfeld gespeichert; eine Webpage entsteht durch gehostete Dateien.

### POST `/php/create_code_project.php`

Erstellt ein Webpage-Projekt aus Editor-Inhalten.

JSON:

```json
{
  "csrfToken": "...",
  "title": "Meine Seite",
  "visibility": "private",
  "publicPermission": "read",
  "files": {
    "index.html": "<!doctype html><html>...</html>",
    "style.css": "body { font-family: sans-serif; }",
    "script.js": "console.log('ok');"
  }
}
```

### GET `/php/get_project_code.php?id=1`

Lädt ein Webpage-Projekt für den Editor. Die Antwort enthält Textdateien, Asset-Metadaten, Ordner und einen CSRF-Token.

### POST `/php/update_project_code.php`

Speichert Editor-Änderungen.

JSON:

```json
{
  "csrfToken": "...",
  "id": 1,
  "files": {
    "index.html": "<!doctype html><html>...</html>"
  },
  "binaryFiles": {},
  "folders": ["assets"],
  "deletedPaths": ["old.css"]
}
```

`deletedPaths` entfernt vorhandene Dateien. Ordner werden über die übergebene `folders`-Liste neu synchronisiert.

### POST `/php/update_project.php`

Ändert Sichtbarkeit und Freigaben eines eigenen Projekts.

### POST `/php/delete_project.php`

Löscht ein eigenes Projekt inklusive zugehöriger gespeicherter Dateien.

### POST `/php/reorder_projects.php`

Speichert die benutzerspezifische Reihenfolge der Projektkarten.

## Auslieferung und Downloads

### GET `/site.php/{projectId}/`

Liefert die `index.html` eines Webpage-Projekts aus.

### GET `/site.php/{projectId}/assets/app.js`

Liefert relative Assets derselben Webpage aus.

### GET `/php/view_project.php/{projectId}/index.html`

Technischer Endpunkt hinter `site.php`. Neue Links sollten `site.php` verwenden.

### GET `/php/download_project.php?id=1`

Lädt Datei-Projekte herunter. Einzeldateien werden direkt gestreamt, Ordnerprojekte werden als ZIP erzeugt.

Optional:

```text
/php/download_project.php?id=1&file_id=7
```

lädt eine einzelne Datei aus einem Ordnerprojekt.
