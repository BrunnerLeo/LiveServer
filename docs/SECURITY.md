# Security Notes

## Threat model

Liveserver verarbeitet Schul-Logins, persönliche Profildaten, Projekt-Metadaten und hochgeladene Dateien. Webpage-Projekte können HTML, CSS, JavaScript und Assets enthalten. Diese Inhalte sind als nicht vertrauenswürdig zu behandeln, auch wenn sie von angemeldeten Benutzern stammen.

Wichtige Schutzobjekte:

- Session-Cookies
- Passwort-Hashes
- SQLite-Datenbank
- hochgeladene Dateien
- Projektfreigaben
- private und geteilte Projekte
- Server-Dateisystem außerhalb der Upload-Ordner

## Authentication

Passwörter werden mit `password_hash` gespeichert. Standard ist Argon2id, sofern PHP es unterstützt. Login verwendet `password_verify`.

Die Anwendung speichert keine Klartextpasswörter. Passwortregeln sind aktuell bewusst einfach gehalten; für öffentliche Deployments sollte mindestens organisatorisch festgelegt werden, wer Konten anlegen darf und wie vergessene Passwörter behandelt werden.

## Sessions

Sessions nutzen:

- `HttpOnly`
- `SameSite=Strict`
- optional `Secure`
- `session.use_strict_mode`
- nur Cookies, keine URL-Sessions

Für HTTPS-Produktivbetrieb:

```json
"secureCookie": true
```

Bei lokalem HTTP muss der Wert `false` sein, sonst funktioniert Login nicht zuverlässig.

## CSRF

Alle zustandsändernden JSON- und Upload-Endpunkte prüfen einen CSRF-Token. Der Token wird in der Session gespeichert und nach erfolgreichen Aktionen erneut mitgeliefert.

Wichtig für neue Endpunkte:

- `require_post_request()` verwenden
- `require_csrf_token()` vor Änderungen prüfen
- keine Schreibaktionen per GET

## Database

SQLite liegt standardmäßig in:

```text
database/liveserver.sqlite
```

Die Datenbank darf nie direkt über den Webserver erreichbar sein. Schutzmaßnahmen:

- `.htaccess` in `database`
- Apache-Block im Installationsskript
- `.gitignore` für `database/*.sqlite`
- Runtime-Migrationen in `php/db_connect.php`

SQL-Zugriffe laufen über PDO. Neue Abfragen sollen Prepared Statements verwenden.

## Uploads

Uploads werden unter `uploads/projects` gespeichert und nicht direkt als statische Dateien ausgeliefert. Der Zugriff läuft über PHP-Endpunkte mit Rechteprüfung.

Dateinamen werden serverseitig generiert. Relative Originalpfade werden normalisiert. Pfadbestandteile wie `..` werden entfernt.

Die Upload-Ordner sind per `.htaccess` und Apache-Konfiguration gesperrt. Dadurch sollen Dateien nicht an der Rechteprüfung vorbei geöffnet werden.

## Hosted Webpages

Webpage-Projekte werden gehostet und können aktives JavaScript enthalten. Das ist funktional gewollt, aber sicherheitsrelevant.

Risiko:

- Ein öffentliches Webpage-Projekt läuft standardmäßig unter derselben Origin wie die Anwendung.
- JavaScript einer gehosteten Seite kann deshalb grundsätzlich mit derselben Browser-Origin interagieren.
- Schreibrechte für öffentliche oder geteilte Webpages erhöhen dieses Risiko.

Empfohlener Produktionsbetrieb:

- gehostete Webpages über eine eigene Subdomain ausliefern, zum Beispiel `sites.example.org`
- App über eine andere Subdomain ausliefern, zum Beispiel `app.example.org`
- Session-Cookies auf die App-Subdomain begrenzen
- öffentliche Schreibrechte nur bewusst vergeben
- Moderation oder Freigabeprozess für öffentliche Inhalte einplanen

Solange App und gehostete Seiten dieselbe Origin teilen, ist diese Funktion für geschlossene, vertrauenswürdige Lernumgebungen geeignet, aber nicht für vollständig offene öffentliche Hosting-Plattformen.

## Authorization

Projektzugriff basiert auf:

- Eigentümer
- Sichtbarkeit `private`
- Sichtbarkeit `shared`
- Sichtbarkeit `public`
- optionaler `read`-/`write`-Berechtigung

Neue Endpunkte müssen immer das passende Zugriffshilfsmodell aus `php/db_connect.php` verwenden, zum Beispiel Owner-, Access- oder Editor-Prüfung.

## Logging

API-Fehler werden in `logs/api-error.log` geschrieben. Logs dürfen nicht öffentlich ausgeliefert und nicht committed werden.

In Produktion:

- `security.showDetailedErrors=false`
- Logrotation auf Serverebene einrichten
- Logs nicht in Backups exportieren, wenn dort personenbezogene Daten enthalten sein können

## Configuration

Produktiv empfohlene Werte:

```json
{
  "registration": {
    "enabled": false
  },
  "security": {
    "secureCookie": true,
    "sameSite": "Strict",
    "showDetailedErrors": false
  }
}
```

## Release gate

Vor einem Release prüfen:

- keine SQLite-Datei im Repository
- keine Upload-Dateien im Repository
- keine Logdateien im Repository
- `node --check public/js/*.js`
- `bash -n install.sh`
- JSON-Dateien validieren
- wenn möglich `php -l` für alle PHP-Dateien
- direkte URLs zu `database`, `uploads` und `logs` blockiert
- Login, Upload, Webpage-Hosting und Editor-Speichern manuell getestet
