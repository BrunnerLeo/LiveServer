# Benutzerkontenkonzept fuer Liveserver Light

Stand: 2026-05-15  
Bezugsstand: `/Volumes/Data/code/Liveserver`  
Versionsbezug: `00.00.22`

## 1. Kurzueberblick

Benutzerkonten bilden Identitaet, Profil, Theme, Login und Grundlage aller Projektrechte.

Diese Light-Version ist die kompakte Arbeitsfassung. Sie ersetzt nicht die Detailfassung, reicht aber fuer Planung, schnelle Reviews und Umsetzungskontrolle.

## 2. Aktueller Stand

- `users` speichert Username, Passwort-Hash, Realname, Rolle und `theme_json`.
- `signup.php` und `login.php` verwalten Zugang.
- `session_check.php` liefert User und CSRF.
- `update_user_data.php` aendert Profil/Passwort.
- `update_user_theme.php` speichert Farben.

## 3. Minimaler Zielumfang

- Passwoerter nie im Klartext.
- Usernames eindeutig.
- Profil einfach halten.
- Theme sicher validieren.
- Rolle fuer spaetere Rechte vorbereiten.

## 4. Hauptablauf

1. Registrieren.
2. Einloggen.
3. Session pruefen.
4. Profil laden.
5. Profil oder Passwort speichern.
6. Theme speichern.

## 5. Wichtigste Komponenten

- `users`
- `public_user`
- `hash_plain_password`
- `find_user_by_username`
- `update_user_profile`
- `sanitize_user_theme`.

## 6. Sicherheitsregeln kurz

- Argon2id bevorzugen.
- Doppelter Username case-insensitive.
- Passwortwechsel verlangt aktuelles Passwort.
- Theme nur Hexfarben.
- Rolle nicht clientseitig aenderbar.

Die wichtigste Regel lautet: Das Backend entscheidet. Frontendzustand, lokale Entwuerfe, ausgeblendete Buttons oder CSS-Klassen ersetzen keine Rechtepruefung.

## 7. Abnahme

- Signup neu.
- Doppelter Username abgelehnt.
- Falsches Passwort abgelehnt.
- Passwortwechsel mit aktuellem Passwort.
- Ungueltiges Theme abgelehnt.

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

- Admin-Freischaltung.
- Passwortreset.
- CSV-Import.
- SSO/LDAP.
- Konten deaktivieren.

## 11. Kurzfazit

Dieses Konzept passt zu Liveserver, wenn es klein, serverseitig abgesichert und mit den bestehenden Tabellen umgesetzt wird. Wichtig bleiben klare Fehlermeldungen, sichere Pfade, CSRF, zentrale Rechtepruefung und Tests mit echten Projektablaeufen.

## 12. Kompakte Entscheidungsgrundlage

Fuer `Benutzerkonten` reicht die Light-Fassung, wenn schnell entschieden werden muss, ob eine Aenderung in den aktuellen Liveserver-Stand passt. Die entscheidende Frage lautet: Wird vorhandene Struktur wiederverwendet oder entsteht Nebenlogik? Nebenlogik ist riskant, weil Liveserver viele Endpunkte hat, die direkt aufgerufen werden koennen. Wenn zwei Endpunkte dasselbe Recht unterschiedlich pruefen, entsteht spaeter fast sicher ein Fehler.

Vor der Umsetzung sollte deshalb kurz geprueft werden, ob es bereits eine passende Funktion in `php/db_connect.php` gibt. Fuer Zugriff sind das zum Beispiel `user_can_access_project` und `user_can_edit_project`. Fuer Owner-Aktionen ist `require_project_owner` relevant. Fuer Dateien sind `get_project_files`, `find_project_file` und der Uploadpfad wichtig. Fuer Settings sind `load_settings` und `resolve_project_path` wichtig.

## 13. Schnelltest vor Abschluss

Ein schneller Abschlusscheck besteht aus fuenf Punkten. Erstens: Funktioniert der normale Weg im Browser? Zweitens: Scheitert derselbe Vorgang ohne Login oder ohne Recht? Drittens: Scheitert ein POST ohne CSRF? Viertens: Bleiben interne Ordner wie `uploads`, `database` und `logs` gesperrt? Fuenftens: Ist klar, ob gehostete Webpages oder Editorrechte betroffen sind?

Wenn einer dieser Punkte unklar ist, sollte die Aenderung nicht als fertig gelten. Besonders bei Webpage-Projekten darf nicht nur getestet werden, ob die Seite sichtbar ist. Es muss auch getestet werden, ob CSS, JavaScript, Bilder, Unterordner und Editor-Speichern die richtigen Rechte respektieren.

## 14. Kurzentscheidung

`Benutzerkonten` ist sauber umgesetzt, wenn die Bedienung einfach bleibt, die Daten in SQLite konsistent sind, Dateien nur ueber PHP ausgeliefert werden, Fehler verstaendlich bleiben und Rechte serverseitig greifen. Alles, was nur im Frontend verhindert wird, gilt als nicht abgesichert.

