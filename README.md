# Liveserver

Version: `00.00.22`

Liveserver ist eine schlanke Schul-Webanwendung mit Login, persönlichem Dashboard, Projektverwaltung, Datei-Uploads und gehosteten Webpage-Projekten. Das Frontend liegt als statisches HTML/CSS/JS im Ordner `public`, das Backend besteht aus PHP-Endpunkten mit SQLite als lokaler Datenbank.

## Status

Diese Ausgabe ist als Pushready-/Open-Source-Paket vorbereitet. Laufzeitdaten sind nicht enthalten:

- keine SQLite-Datenbankdatei
- keine hochgeladenen Projektdateien
- keine Logdateien
- keine macOS-Metadaten

Die benötigten Ordner bleiben mit `.htaccess` und `.gitkeep` erhalten.

## Hauptfunktionen

- Benutzerregistrierung, Login, Logout und Session-Prüfung
- persönliche Profile und eigene Benutzerfarben
- Dashboard mit Projektaktivitäten
- Projektverwaltung für Dateien und Webpages
- Sichtbarkeit `private`, `shared` und `public`
- Lese-/Schreibrechte für öffentliche und geteilte Webpage-Projekte
- Upload von Einzeldateien, Ordnern und Webpage-Projekten mit `index.html`
- separater Webpage-Code-Editor mit Datei- und Ordnerlöschung
- gehostete Webpage-Auslieferung über `site.php`
- SQLite-Schema-Migrationen beim Start
- abgesperrte Ordner für Datenbank, Uploads und Logs

## Schnellstart mit Docker

```bash
docker compose up --build
```

Danach öffnen:

```text
http://localhost:8080/
```

Der Health-Check liegt unter:

```text
http://localhost:8080/php/health.php
```

## Installation auf Ubuntu/Debian

```bash
chmod +x install.sh
SERVER_NAME=schule.example.org HTTP_PORT=80 ./install.sh
```

Das Skript installiert Apache, PHP, SQLite und ZIP-Unterstützung, erstellt die Apache-Site und setzt Schreibrechte auf:

- `database`
- `uploads`
- `logs`

Weitere Details stehen in [docs/INSTALLATION.md](docs/INSTALLATION.md).

## Konfiguration

Die Anwendung liest ihre Konfiguration aus:

```text
config/settings.json
```

Vorlagen:

- `config/settings.example.json`
- `config/settings.production.example.json`

Für HTTPS-Produktivbetrieb muss `security.secureCookie` auf `true` stehen. Öffentliche Registrierung sollte nur aktiviert bleiben, wenn sie wirklich gebraucht wird.

## Dokumentation

- [Installation](docs/INSTALLATION.md)
- [API](docs/API.md)
- [Architektur](docs/ARCHITECTURE.md)
- [Datenbank](docs/DATABASE.md)
- [Sicherheit](docs/SECURITY.md)
- [Betrieb](docs/OPERATIONS.md)
- [Release-Checkliste](docs/RELEASE_CHECKLIST.md)
- [Ausführliches Sicherheitskonzept](Konzepts/Sicherheitskonzept.md)
- [Sicherheitskonzept light](Konzepts/Sicherheitskonzept-light.md)

## Entwicklung

```bash
node --check public/js/*.js
bash -n install.sh
python3 -m json.tool config/settings.json >/dev/null
```

Wenn PHP lokal installiert ist:

```bash
find php -name '*.php' -print0 | xargs -0 -n1 php -l
```

Die gleichen Basiskontrollen laufen in GitHub Actions.

## Sicherheit

Bitte lies vor einem öffentlichen Deployment mindestens [SECURITY.md](SECURITY.md) und [docs/SECURITY.md](docs/SECURITY.md). Besonders wichtig ist die Trennung zwischen Anwendung, Upload-Speicher und gehosteten Schüler-Webpages.

## Lizenz

MIT, siehe [LICENSE](LICENSE).
