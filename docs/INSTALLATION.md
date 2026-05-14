# Installation

## Voraussetzungen

Liveserver benötigt:

- Apache 2 mit `mod_rewrite` und `mod_headers`
- PHP mit `pdo`, `pdo_sqlite`, `session`, `json` und `zip`
- SQLite 3
- Schreibrechte für den Apache-Benutzer auf `database`, `uploads` und `logs`

Die Anwendung funktioniert ohne Node-Build-Schritt. Das Frontend wird direkt aus `public/html`, `public/css` und `public/js` ausgeliefert.

## Docker-Schnellstart

```bash
docker compose up --build
```

Danach ist die Anwendung unter `http://localhost:8080/` erreichbar.

Beim Start legt der Container die Laufzeitordner an und setzt Schreibrechte für `www-data`. Die Daten liegen wegen des Bind-Mounts im Projektordner. Für einen kurzlebigen Test kann der Container gelöscht werden; die SQLite-Datei bleibt im Ordner `database`.

## Ubuntu-/Debian-Installation

Projekt in den Zielordner kopieren:

```bash
sudo mkdir -p /var/www/liveserver
sudo rsync -a ./ /var/www/liveserver/
cd /var/www/liveserver
```

Installation starten:

```bash
chmod +x install.sh
SERVER_NAME=schule.example.org HTTP_PORT=80 ./install.sh
```

Das Skript:

- installiert Apache, PHP, SQLite und ZIP-Unterstützung
- erzeugt `database/liveserver.sqlite`
- erzeugt das aktuelle SQLite-Schema
- schreibt eine Apache-Site in `/etc/apache2/sites-available`
- aktiviert `rewrite` und `headers`
- sperrt `database`, `uploads` und `logs`
- setzt Schreibrechte für den Apache-Benutzer

## Manuelle Apache-Konfiguration

Wenn Apache bereits vorbereitet ist, reicht dieses Prinzip:

```apache
<VirtualHost *:80>
    ServerName schule.example.org
    DocumentRoot "/var/www/liveserver"
    DirectoryIndex index.html

    <Directory "/var/www/liveserver">
        Require all granted
        Options -Indexes
        AllowOverride All
    </Directory>

    <Directory "/var/www/liveserver/database">
        Require all denied
    </Directory>

    <Directory "/var/www/liveserver/logs">
        Require all denied
    </Directory>

    <Directory "/var/www/liveserver/uploads">
        Require all denied
    </Directory>
</VirtualHost>
```

## Rechte

Apache/PHP braucht Schreibrechte auf:

```text
database
uploads
logs
```

Beispiel:

```bash
sudo chown -R www-data:www-data /var/www/liveserver/database /var/www/liveserver/uploads /var/www/liveserver/logs
sudo chmod -R 775 /var/www/liveserver/database /var/www/liveserver/uploads /var/www/liveserver/logs
```

## Konfiguration

Die aktive Konfiguration liegt in `config/settings.json`.

Für Produktion:

```bash
cp config/settings.production.example.json config/settings.json
```

Danach anpassen:

- `school.name`
- `school.logo`
- `registration.enabled`
- `security.secureCookie`
- `database.sqlitePath`
- `projects.uploadPath`
- `projects.maxUploadBytes`

Wenn HTTPS aktiv ist, muss `security.secureCookie` auf `true` stehen. Bei rein lokalem HTTP-Testbetrieb bleibt der Wert `false`, sonst wird das Session-Cookie vom Browser nicht gesendet.

## Health-Check

```text
/php/health.php
```

Erwartet wird JSON mit:

- `success: true`
- `sqliteLoaded: true`
- `zipLoaded: true`
- `database.connected: true`
- `uploads.writable: true`

## Erste Inbetriebnahme

1. Anwendung öffnen.
2. Registrierung prüfen oder deaktivieren.
3. Erstes Benutzerkonto erstellen.
4. Login testen.
5. Datei-Projekt hochladen.
6. Webpage-Projekt mit `index.html` hochladen.
7. Webpage im Editor öffnen, speichern und wieder öffnen.
8. `database`, `uploads` und `logs` direkt im Browser testen; sie müssen gesperrt sein.
