# Backup- und Restore-Konzept fuer Liveserver Light

Stand: 2026-05-15  
Bezugsstand: `/Volumes/Data/code/Liveserver`  
Versionsbezug: `00.00.22`

## 1. Kurzueberblick

Backups sichern Datenbank, Uploads, Konfiguration und Wiederherstellbarkeit des Liveserver-Systems.

Diese Light-Version ist die kompakte Arbeitsfassung. Sie ersetzt nicht die Detailfassung, reicht aber fuer Planung, schnelle Reviews und Umsetzungskontrolle.

## 2. Aktueller Stand

- Datenbank in `database`.
- Uploads in `uploads/projects`.
- Config in `config/settings.json`.
- Logs in `logs`.
- Kein dediziertes Backupskript.

## 3. Minimaler Zielumfang

- Konsistente Sicherung.
- Restore auf neuem Server moeglich.
- Backups nicht im Webroot.
- Rechte nach Restore korrekt.
- Vor Updates Backup erstellen.

## 4. Hauptablauf

1. SQLite-Kopie erzeugen.
2. Uploads sichern.
3. Config sichern.
4. Archiv datieren.
5. Restore einspielen.
6. Rechte setzen und testen.

## 5. Wichtigste Komponenten

- SQLite `.backup`
- `uploads/projects`
- `settings.json`
- tar/rsync
- Cron
- Healthcheck.

## 6. Sicherheitsregeln kurz

- Backups enthalten private Daten.
- Backups verschluesseln oder streng berechtigen.
- Retention definieren.
- Nicht per Apache ausliefern.
- Restore darf Schutzdateien nicht verlieren.

Die wichtigste Regel lautet: Das Backend entscheidet. Frontendzustand, lokale Entwuerfe, ausgeblendete Buttons oder CSS-Klassen ersetzen keine Rechtepruefung.

## 7. Abnahme

- Backup enthaelt DB und Uploads.
- Restore ermoeglicht Login.
- Download funktioniert.
- Webpage funktioniert.
- Direktzugriff bleibt gesperrt.

## 8. Zwei-Seiten-Checkliste

Vor einer Aenderung an diesem Bereich sollten diese Fragen kurz beantwortet werden:

1. Welche Nutzergruppe ist betroffen: Owner, Shared-Read, Shared-Write, Public-Read, Public-Write oder Admin?
2. Welche PHP-Datei nimmt die Anfrage entgegen?
3. Welche Hilfsfunktion prueft Zugriff oder Schreibrecht?
4. Welche Tabellen werden gelesen oder geaendert?
5. Werden Dateien unter `uploads/projects` gelesen, geschrieben oder geloescht?
6. Gibt es einen negativen Testfall ohne Berechtigung?
7. Muss `settings.json`, README, `install.sh` oder das Security-Konzept angepasst werden?
8. Ist eine gehostete Webpage oder Same-Origin-Verhalten betroffen?

## 9. Betriebscheck

Nach der Umsetzung sollten Login, Projektliste, eine betroffene Aktion, ein negativer Rechtefall und `php/health.php` getestet werden. Wenn Dateien beteiligt sind, muss zusaetzlich geprueft werden, dass `uploads`, `database` und `logs` weiterhin nicht direkt abrufbar sind.

## 10. Spaeter moeglich

- backup.sh.
- restore-checklist.md.
- Taeglicher Cron.
- Offsite-Backup.
- Admin-Backupstatus.

## 11. Kurzfazit

Dieses Konzept passt zu Liveserver, wenn es klein, serverseitig abgesichert und mit den bestehenden Tabellen umgesetzt wird. Wichtig bleiben klare Fehlermeldungen, sichere Pfade, CSRF, zentrale Rechtepruefung und Tests mit echten Projektablaeufen.

## 12. Kompakte Entscheidungsgrundlage

Fuer `Backup-Restore` reicht die Light-Fassung, wenn schnell entschieden werden muss, ob eine Aenderung in den aktuellen Liveserver-Stand passt. Die entscheidende Frage lautet: Wird vorhandene Struktur wiederverwendet oder entsteht Nebenlogik? Nebenlogik ist riskant, weil Liveserver viele Endpunkte hat, die direkt aufgerufen werden koennen. Wenn zwei Endpunkte dasselbe Recht unterschiedlich pruefen, entsteht spaeter fast sicher ein Fehler.

Vor der Umsetzung sollte deshalb kurz geprueft werden, ob es bereits eine passende Funktion in `php/db_connect.php` gibt. Fuer Zugriff sind das zum Beispiel `user_can_access_project` und `user_can_edit_project`. Fuer Owner-Aktionen ist `require_project_owner` relevant. Fuer Dateien sind `get_project_files`, `find_project_file` und der Uploadpfad wichtig. Fuer Settings sind `load_settings` und `resolve_project_path` wichtig.

## 13. Schnelltest vor Abschluss

Ein schneller Abschlusscheck besteht aus fuenf Punkten. Erstens: Funktioniert der normale Weg im Browser? Zweitens: Scheitert derselbe Vorgang ohne Login oder ohne Recht? Drittens: Scheitert ein POST ohne CSRF? Viertens: Bleiben interne Ordner wie `uploads`, `database` und `logs` gesperrt? Fuenftens: Ist klar, ob gehostete Webpages oder Editorrechte betroffen sind?

Wenn einer dieser Punkte unklar ist, sollte die Aenderung nicht als fertig gelten. Besonders bei Webpage-Projekten darf nicht nur getestet werden, ob die Seite sichtbar ist. Es muss auch getestet werden, ob CSS, JavaScript, Bilder, Unterordner und Editor-Speichern die richtigen Rechte respektieren.

## 14. Kurzentscheidung

`Backup-Restore` ist sauber umgesetzt, wenn die Bedienung einfach bleibt, die Daten in SQLite konsistent sind, Dateien nur ueber PHP ausgeliefert werden, Fehler verstaendlich bleiben und Rechte serverseitig greifen. Alles, was nur im Frontend verhindert wird, gilt als nicht abgesichert.

