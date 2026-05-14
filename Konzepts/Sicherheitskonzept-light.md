# Sicherheitskonzept light

Version: `00.00.22`

## Ziel

Liveserver schützt Benutzerkonten, Projektdateien, persönliche Einstellungen und gehostete Webpage-Projekte. Die Anwendung ist für einen Schulkontext gebaut und soll einfach betreibbar bleiben. Trotzdem dürfen Datenbank, Uploads, Logs und private Projekte nicht direkt öffentlich erreichbar sein.

## Wichtigste Schutzmaßnahmen

Passwörter werden nicht im Klartext gespeichert. Die Anwendung nutzt PHPs `password_hash` und `password_verify`; bevorzugt wird Argon2id.

Sessions verwenden sichere Cookie-Einstellungen:

- `HttpOnly`
- `SameSite=Strict`
- optional `Secure` bei HTTPS

Alle schreibenden Aktionen verwenden POST und prüfen einen CSRF-Token. Das schützt gegen fremde Webseiten, die unbemerkt Aktionen im Namen eines eingeloggten Benutzers auslösen wollen.

Die SQLite-Datenbank liegt in `database/liveserver.sqlite`. Dieser Ordner ist per `.htaccess` und Apache-Konfiguration gesperrt. Uploads und Logs sind ebenfalls gesperrt.

## Projekte und Rechte

Projekte können `private`, `shared` oder `public` sein.

- `private`: nur Eigentümer
- `shared`: Eigentümer und eingetragene Benutzer
- `public`: öffentlich lesbar

Für Webpage-Projekte gibt es zusätzlich `read` und `write`. Öffentliche oder geteilte Schreibrechte sollten nur bewusst vergeben werden.

## Webpage-Hosting

Webpage-Projekte können HTML, CSS und JavaScript enthalten. Das ist die wichtigste Sicherheitsstelle im Projekt. Solche Inhalte sind als nicht vertrauenswürdig zu behandeln.

Für produktive Umgebungen ist empfohlen:

- App auf `app.example.org`
- gehostete Seiten auf `sites.example.org`
- Session-Cookies nur für die App-Origin
- öffentliche Schreibrechte restriktiv verwenden

Ohne getrennte Origin ist die Funktion vor allem für geschlossene Lerngruppen geeignet.

## Betrieb

Nicht ins Repository gehören:

- `database/*.sqlite`
- `database/*.sqlite-*`
- `uploads/projects/*`
- `logs/*`
- `.DS_Store`
- `._*`

Vor einem Release prüfen:

```bash
node --check public/js/*.js
bash -n install.sh
python3 -m json.tool config/settings.json >/dev/null
```

Wenn PHP vorhanden ist:

```bash
find php -name '*.php' -print0 | xargs -0 -n1 php -l
```

Für Produktion:

- HTTPS aktivieren
- `security.secureCookie=true`
- `security.showDetailedErrors=false`
- Registrierung deaktivieren, wenn sie nicht aktiv gebraucht wird
- regelmäßige Backups von Datenbank und Uploads einrichten
