# Architecture

Liveserver ist bewusst einfach aufgebaut. Es gibt keinen Build-Prozess und keine zusätzliche Backend-Abstraktionsschicht.

## Frontend

Frontend-Dateien:

```text
public/html
public/css
public/js
```

Die HTML-Seiten laden `public/js/api.js`. Diese Datei kapselt API-Requests, CSRF-Token, Session-Zustand und Konfigurationswerte.

Wichtige Seiten:

- `dashboard.html`
- `projekte.html`
- `editor.html`
- `profil.html`
- `einstellungen.html`

## Backend

Backend-Dateien liegen unter `php`.

`php/db_connect.php` enthält:

- Settings-Laden
- Session-Start
- JSON-Antworten
- CSRF-Funktionen
- SQLite-Verbindung
- Schema-Migrationen
- Benutzerfunktionen
- Projektfunktionen
- Autorisierungsfunktionen

Die einzelnen Endpunkte bleiben dadurch klein und rufen gemeinsame Funktionen auf.

## Persistence

SQLite ist die einzige Datenbank. Der Standardpfad ist:

```text
database/liveserver.sqlite
```

Das Schema wird bei jedem Verbindungsaufbau abgesichert. Frische Installationen bekommen dasselbe Schema über `install.sh`.

## Upload storage

Uploads liegen unter:

```text
uploads/projects
```

Die Dateien werden nicht direkt statisch ausgeliefert. Downloads, Editor-Zugriffe und Webpage-Hosting laufen über PHP und prüfen Rechte.

## Hosted sites

Webpage-Projekte werden über `site.php` ausgeliefert. `site.php` lädt intern `php/view_project.php`. Relative Links aus `index.html` werden so aufgelöst, dass Assets desselben Projekts erreichbar bleiben.

## Configuration

Aktive Konfiguration:

```text
config/settings.json
```

Beispiele:

```text
config/settings.example.json
config/settings.production.example.json
```

Konfigurationswerte werden im Backend über `load_settings()` gelesen und im Frontend über `php/session_check.php` beziehungsweise `public/js/api.js` genutzt.
