# Sicherheitskonzept Liveserver

Version: `00.00.22`

## 1. Ziel und Schutzbedarf

Liveserver ist eine Schul-Webanwendung für Benutzerkonten, persönliche Inhalte, Projektdateien und gehostete Webpage-Projekte. Das Sicherheitsziel ist nicht nur, den Server vor direkten Angriffen zu schützen, sondern auch die Trennung zwischen Benutzern, privaten Projekten, geteilten Projekten und öffentlichen Inhalten zuverlässig durchzusetzen.

Der Schutzbedarf ist mittel bis hoch. Es werden keine Zahlungsdaten verarbeitet, aber personenbezogene Daten, Login-Daten, Unterrichtsinhalte und Schülerprojekte. Besonders kritisch sind Session-Cookies, Passwort-Hashes, Freigaben, Upload-Dateien und alle Inhalte, die als gehostete Webpages mit JavaScript ausgeführt werden.

## 2. Systemgrenzen

Das System besteht aus:

- statischem Frontend in `public/html`, `public/css` und `public/js`
- PHP-Backend unter `php`
- SQLite-Datenbank unter `database`
- Upload-Speicher unter `uploads/projects`
- Logdateien unter `logs`
- Konfiguration unter `config/settings.json`

Die Anwendung ist für Apache/PHP gedacht. Die Ordner `database`, `uploads` und `logs` dürfen nicht direkt statisch ausgeliefert werden. Direkter Dateizugriff würde die Autorisierung umgehen und ist deshalb explizit zu verhindern.

## 3. Rollenmodell

Es gibt aktuell ein einfaches Rollenfeld in der Benutzertabelle. Standard ist `student`. Die technische Zugriffskontrolle für Projekte basiert primär auf Projektbeziehungen statt auf einem komplexen Rollenmodell.

Projektzugriff wird über diese Faktoren entschieden:

- Eigentümer des Projekts
- Sichtbarkeit `private`
- Sichtbarkeit `shared`
- Sichtbarkeit `public`
- öffentliche Berechtigung `read` oder `write`
- geteilte Benutzer mit Berechtigung `read` oder `write`

Für neue Funktionen gilt: Schreibende Aktionen dürfen nie allein aus UI-Zustand abgeleitet werden. Jede Änderung muss serverseitig prüfen, ob der aktuelle Benutzer wirklich berechtigt ist.

## 4. Authentifizierung

Benutzer melden sich mit Benutzername und Passwort an. Passwörter werden mit `password_hash` gespeichert. Als bevorzugter Algorithmus ist Argon2id konfiguriert. Falls die PHP-Umgebung Argon2id nicht unterstützt oder anders konfiguriert ist, fällt PHP auf einen unterstützten sicheren Standard zurück.

Klartextpasswörter dürfen nicht gespeichert, geloggt oder in Fehlermeldungen ausgegeben werden. Login-Fehler sollen absichtlich allgemein bleiben, damit nicht zuverlässig erkennbar ist, ob ein Benutzername existiert.

Registrierung ist konfigurierbar. Für öffentlich erreichbare Server ist die sichere Voreinstellung, Registrierung zu deaktivieren und Konten administrativ oder organisatorisch kontrolliert anzulegen.

## 5. Session-Schutz

Sessions werden serverseitig geführt und über Cookies referenziert. Die Cookie-Eigenschaften sind:

- `HttpOnly`, damit JavaScript das Cookie nicht direkt lesen kann
- `SameSite=Strict`, um Cross-Site-Requests zu reduzieren
- `Secure`, wenn HTTPS aktiv ist
- strikter Session-Modus
- Cookies statt URL-basierter Session-IDs

Für Produktivbetrieb mit HTTPS muss `security.secureCookie=true` gesetzt werden. Für lokalen HTTP-Testbetrieb bleibt dieser Wert `false`, weil Browser Secure-Cookies über HTTP nicht mitsenden.

Nach Logout wird die Session beendet. Neue sicherheitskritische Funktionen sollten prüfen, ob Session-Regeneration nach Login oder Rollenwechsel nötig ist.

## 6. CSRF-Schutz

Alle zustandsändernden Endpunkte müssen POST verwenden und einen CSRF-Token prüfen. Der Token wird in der Session gespeichert und vom Frontend bei Formularen und JSON-Requests mitgeschickt.

Das Muster für neue Endpunkte ist:

1. Session starten.
2. POST-Methode erzwingen.
3. JSON- oder Formulardaten lesen.
4. CSRF-Token prüfen.
5. eingeloggten Benutzer laden.
6. fachliche Berechtigung prüfen.
7. Änderung durchführen.
8. neuen oder bestehenden CSRF-Token zurückgeben.

Schreibende GET-Endpunkte sind nicht zulässig.

## 7. Datenbank und Migration

SQLite ist die lokale Persistenzschicht. Die Datenbank liegt standardmäßig in `database/liveserver.sqlite`. Das Schema wird in `php/db_connect.php` beim Zugriff abgesichert und migriert. Das Installationsskript erzeugt dasselbe Schema für frische Installationen.

Aktuelle zentrale Tabellen:

- `users`
- `projects`
- `project_orders`
- `project_files`
- `project_folders`
- `project_shares`

Die Anwendung verwendet PDO und Prepared Statements. Dynamische SQL-Fragmente sind nur zulässig, wenn sie aus festen Whitelists stammen. Benutzereingaben dürfen nicht direkt in SQL-Strings eingebaut werden.

SQLite-Dateien, WAL-Dateien und SHM-Dateien sind Laufzeitdaten und dürfen nicht committed werden.

## 8. Upload-Sicherheit

Uploads werden physisch unter `uploads/projects` gespeichert. Die gespeicherten Dateinamen werden serverseitig generiert und sind nicht identisch mit den Originalnamen. Originalnamen und relative Pfade werden normalisiert und nur als Metadaten genutzt.

Schutzmaßnahmen:

- keine direkte statische Auslieferung aus `uploads`
- Zugriff nur über PHP-Endpunkte
- Rechteprüfung vor Download oder Webpage-Auslieferung
- Pfadnormalisierung gegen `..`, Backslashes und leere Bestandteile
- Größenlimit über `projects.maxUploadBytes`
- MIME-Typen werden kontrolliert gesetzt, aber nicht als alleinige Sicherheitsentscheidung verwendet

Für hochgeladene Webpages ist wichtig: HTML, CSS und JavaScript können aktiv sein. Diese Inhalte sind nicht vertrauenswürdig.

## 9. Gehostete Webpage-Projekte

Webpage-Projekte werden über `site.php` oder `php/view_project.php` ausgeliefert. Relative Pfade werden so aufgelöst, dass `index.html`, CSS, JavaScript und Assets zusammen funktionieren.

Das ist funktional nützlich, aber die wichtigste Sicherheitskante des Projekts. Wenn gehostete Schülerseiten unter derselben Origin laufen wie die eigentliche Anwendung, kann JavaScript derselben Origin grundsätzlich Requests an die Anwendung senden. `HttpOnly` schützt vor direktem Cookie-Auslesen, verhindert aber nicht automatisch alle Same-Origin-Interaktionen.

Für produktive oder öffentlichere Umgebungen wird deshalb empfohlen:

- Hauptanwendung unter eigener Origin, zum Beispiel `app.example.org`
- gehostete Seiten unter anderer Origin, zum Beispiel `sites.example.org`
- Cookies auf die App-Origin begrenzen
- öffentliche Schreibrechte streng begrenzen
- Moderation für öffentliche Seiten vorsehen
- Content-Security-Policy je nach Betriebsmodell prüfen

Solange keine Origin-Trennung eingerichtet ist, sollte die Hosting-Funktion als Werkzeug für eine geschlossene, vertrauenswürdige Lerngruppe verstanden werden, nicht als offener öffentlicher Hostingdienst.

## 10. Autorisierung

Jeder Zugriff auf ein Projekt muss serverseitig entschieden werden. Das Frontend darf Menüs ausblenden, aber es ist keine Sicherheitsinstanz.

Lesen:

- Eigentümer darf lesen.
- `public` darf gelesen werden.
- `shared` darf von explizit eingetragenen Benutzern gelesen werden.

Schreiben:

- Eigentümer darf schreiben.
- öffentliche Schreibrechte erlauben Bearbeitung nur, wenn `public_permission=write`.
- geteilte Schreibrechte erlauben Bearbeitung nur, wenn der Benutzer in `project_shares` mit `permission=write` steht.

Projektlöschung bleibt Eigentümern vorbehalten.

## 11. Fehlerbehandlung und Logging

Öffentliche Fehlermeldungen sollen verständlich, aber nicht intern verräterisch sein. Technische Details werden in `logs/api-error.log` geschrieben. `security.showDetailedErrors` darf in Produktion nicht aktiviert sein.

Logs können personenbezogene Daten oder Pfade enthalten. Sie müssen deshalb wie sensible Betriebsdaten behandelt werden:

- nicht committen
- nicht direkt ausliefern
- nur berechtigten Administratoren zugänglich machen
- regelmäßig rotieren oder löschen

## 12. Konfiguration

Wichtige sicherheitsrelevante Optionen:

- `registration.enabled`
- `security.secureCookie`
- `security.sameSite`
- `security.showDetailedErrors`
- `database.sqlitePath`
- `projects.uploadPath`
- `projects.maxUploadBytes`

Produktiv empfohlen:

- Registrierung deaktiviert, außer sie ist organisatorisch abgesichert
- Secure-Cookies aktiv
- detaillierte Fehler deaktiviert
- Upload-Limit bewusst niedrig genug
- Datenbank und Uploads außerhalb öffentlich beschreibbarer Bereiche oder per Apache blockiert

## 13. Backup und Wiederherstellung

Backups müssen Datenbank und Uploads gemeinsam betrachten. Projekt-Metadaten ohne Dateien oder Dateien ohne Datenbank sind inkonsistent.

Sicher zu sichern:

- `config/settings.json`
- `database/liveserver.sqlite`
- `uploads/projects`

Bei aktivem WAL-Modus ist eine SQLite-Backup-Operation sicherer als eine reine Dateikopie. Beispiel:

```bash
sqlite3 database/liveserver.sqlite ".backup '/backup/liveserver.sqlite'"
```

Backups enthalten personenbezogene Daten und müssen entsprechend geschützt werden.

## 14. Open-Source-Veröffentlichung

Vor Veröffentlichung dürfen keine Laufzeitdaten enthalten sein. Besonders zu prüfen:

- keine SQLite-Dateien
- keine Upload-Dateien
- keine Logdateien
- keine echten Benutzernamen oder Passwörter in Beispielen
- keine privaten Servernamen, Tokens oder Pfade
- keine `.DS_Store` oder `._*`

Das Pushready-Paket enthält nur Strukturdateien wie `.htaccess` und `.gitkeep`.

## 15. Prüfroutine

Technische Checks:

```bash
node --check public/js/*.js
bash -n install.sh
python3 -m json.tool config/settings.json >/dev/null
python3 -m json.tool config/settings.example.json >/dev/null
python3 -m json.tool config/settings.production.example.json >/dev/null
```

Wenn PHP installiert ist:

```bash
find php -name '*.php' -print0 | xargs -0 -n1 php -l
```

Manuelle Sicherheitschecks:

- Direktaufruf von `database/` blockiert.
- Direktaufruf von `uploads/` blockiert.
- Direktaufruf von `logs/` blockiert.
- Nicht eingeloggter Benutzer kann private Projekte nicht laden.
- Shared-Projekt ist nur für eingetragene Benutzer sichtbar.
- Schreibrechte werden serverseitig geprüft.
- Editor-Löschungen wirken nur auf erlaubte Projektdateien.

## 16. Restrisiken

Die größte offene Architekturfrage ist die Origin-Trennung für gehostete Webpages. Ohne diese Trennung ist der Betrieb für geschlossene Lernumgebungen vertretbar, aber für offene öffentliche Plattformen nur eingeschränkt geeignet.

Weitere Restrisiken:

- keine administrative Benutzerverwaltung
- keine Rate-Limits für Login
- keine zentrale Audit-Tabelle
- kein Virenscan für Uploads
- kein automatisierter Browser-Sicherheitstest

Diese Punkte sind bewusst dokumentiert, damit sie vor einer größeren öffentlichen Nutzung priorisiert werden können.
