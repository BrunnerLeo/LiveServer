# Digital Lerning Initiativ Liveserver

Bereinigte GitHub-Version des DLI Liveservers.

## Enthalten

- PHP-Backend unter `php/`
- Frontend unter `public/`
- Beispielkonfiguration unter `config/settings.json`
- leere Laufzeitordner mit `.gitkeep`

Nicht enthalten sind Projektdateien, Uploads, Logs, Runtime-Jobs, Samba-Projektordner oder produktive SQLite-Datenbanken.

## Standard-Login

Beim ersten Start legt die Anwendung automatisch den Admin-Benutzer an:

- Benutzername: `admin`
- Passwort: `admin123`

Das Passwort sollte direkt nach der Installation geändert werden.

## Installation

1. Repository in den Webroot kopieren.
2. PHP mit SQLite, cURL, mbstring und Zip bereitstellen.
3. Schreibrechte für diese Ordner setzen:
   - `database/`
   - `logs/`
   - `uploads/projects/`
   - `runtime/jobs/`
   - `smb/projects/`
4. Webserver so konfigurieren, dass `database/`, `logs/`, `uploads/`, `runtime/`, `smb/` und `config/` nicht direkt öffentlich ausgeliefert werden.
5. `index.html` im Browser öffnen und mit `admin` / `admin123` anmelden.

## KI-Konfiguration

In dieser GitHub-Version sind ARIS/Jenny standardmäßig deaktiviert und auf lokale OpenAI-kompatible Platzhalter gesetzt:

- `http://localhost:8080/v1`
- `http://127.0.0.1:8080/v1`
- `http://localhost:11434/v1`
- `http://127.0.0.1:11434/v1`

Es sind keine Tailnet-Hosts, keine API-Keys und keine produktiven AI-small-Verweise enthalten.

## Daten

Die SQLite-Datenbank wird beim ersten Zugriff unter `database/liveserver.sqlite` erstellt. Diese Datei ist in `.gitignore` ausgeschlossen und soll nicht mitcommitted werden.

## Samba

Samba-Sync und User-Provisioning sind in `config/settings.json` standardmäßig deaktiviert. Für produktiven Einsatz müssen die Helper-Pfade und Serverrechte bewusst konfiguriert werden.
