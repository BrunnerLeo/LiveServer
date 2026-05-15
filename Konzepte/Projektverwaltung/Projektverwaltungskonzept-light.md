# Projektverwaltungskonzept fuer Liveserver Light

Stand: 2026-05-15  
Bezugsstand: `/Volumes/Data/code/Liveserver`  
Versionsbezug: `00.00.22`

## 1. Kurzueberblick

Die Projektverwaltung beschreibt, wie Nutzer Projekte erstellen, finden, sortieren, oeffnen, bearbeiten, teilen und loeschen.

Diese Light-Version ist die kompakte Arbeitsfassung. Sie ersetzt nicht die Detailfassung, reicht aber fuer Planung, schnelle Reviews und Umsetzungskontrolle.

## 2. Aktueller Stand

- `projekte.html` ist die Projektkonsole.
- `projects.js` verwaltet Suche, Filter, Sortierung, Entwuerfe und Projektkarten.
- `list_projects.php` liefert erreichbare Projekte.
- `update_project.php` aendert Sichtbarkeit und Rechte.
- `delete_project.php` loescht eigene Projekte.

## 3. Minimaler Zielumfang

- Nutzer sehen alle erreichbaren Projekte.
- Fremde private Projekte bleiben unsichtbar.
- Eigene Projekte sind verwaltbar.
- Webpage-Projekte koennen im Editor geoeffnet werden.
- Projektliste bleibt auch bei vielen Projekten bedienbar.

## 4. Hauptablauf

1. Session pruefen.
2. Projektliste laden.
3. Filter und Suche anwenden.
4. Projektaktion ausfuehren.
5. Backend prueft Rechte.
6. Liste oder Karte aktualisieren.

## 5. Wichtigste Komponenten

- Projektformular
- Projekttoolbar
- Projektkarten
- `project_orders` fuer manuelle Reihenfolge
- `project_shares` fuer Freigaben
- `site.php` fuer Webpage-Oeffnen

## 6. Sicherheitsregeln kurz

- Liste darf nur erlaubte Projekte enthalten.
- Owner-Aktionen brauchen `require_project_owner`.
- Editoraktionen brauchen `user_can_edit_project`.
- Loeschen ist POST mit CSRF.
- Share-Details werden nur Ownern gezeigt.

Die wichtigste Regel lautet: Das Backend entscheidet. Frontendzustand, lokale Entwuerfe, ausgeblendete Buttons oder CSS-Klassen ersetzen keine Rechtepruefung.

## 7. Abnahme

- Owner sieht eigene Projekte.
- Shared-Nutzer sieht freigegebene Projekte.
- Fremder Nutzer sieht private Projekte nicht.
- Sortierung bleibt nach Reload erhalten.
- Loeschen entfernt Karte und Dateien.

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

- Serverseitige Pagination.
- Tags und Projektbeschreibung.
- Archiv statt Hard-Delete.
- Admin-Projektuebersicht.
- Versionshistorie.

## 11. Kurzfazit

Dieses Konzept passt zu Liveserver, wenn es klein, serverseitig abgesichert und mit den bestehenden Tabellen umgesetzt wird. Wichtig bleiben klare Fehlermeldungen, sichere Pfade, CSRF, zentrale Rechtepruefung und Tests mit echten Projektablaeufen.

## 12. Kompakte Entscheidungsgrundlage

Fuer `Projektverwaltung` reicht die Light-Fassung, wenn schnell entschieden werden muss, ob eine Aenderung in den aktuellen Liveserver-Stand passt. Die entscheidende Frage lautet: Wird vorhandene Struktur wiederverwendet oder entsteht Nebenlogik? Nebenlogik ist riskant, weil Liveserver viele Endpunkte hat, die direkt aufgerufen werden koennen. Wenn zwei Endpunkte dasselbe Recht unterschiedlich pruefen, entsteht spaeter fast sicher ein Fehler.

Vor der Umsetzung sollte deshalb kurz geprueft werden, ob es bereits eine passende Funktion in `php/db_connect.php` gibt. Fuer Zugriff sind das zum Beispiel `user_can_access_project` und `user_can_edit_project`. Fuer Owner-Aktionen ist `require_project_owner` relevant. Fuer Dateien sind `get_project_files`, `find_project_file` und der Uploadpfad wichtig. Fuer Settings sind `load_settings` und `resolve_project_path` wichtig.

## 13. Schnelltest vor Abschluss

Ein schneller Abschlusscheck besteht aus fuenf Punkten. Erstens: Funktioniert der normale Weg im Browser? Zweitens: Scheitert derselbe Vorgang ohne Login oder ohne Recht? Drittens: Scheitert ein POST ohne CSRF? Viertens: Bleiben interne Ordner wie `uploads`, `database` und `logs` gesperrt? Fuenftens: Ist klar, ob gehostete Webpages oder Editorrechte betroffen sind?

Wenn einer dieser Punkte unklar ist, sollte die Aenderung nicht als fertig gelten. Besonders bei Webpage-Projekten darf nicht nur getestet werden, ob die Seite sichtbar ist. Es muss auch getestet werden, ob CSS, JavaScript, Bilder, Unterordner und Editor-Speichern die richtigen Rechte respektieren.

## 14. Kurzentscheidung

`Projektverwaltung` ist sauber umgesetzt, wenn die Bedienung einfach bleibt, die Daten in SQLite konsistent sind, Dateien nur ueber PHP ausgeliefert werden, Fehler verstaendlich bleiben und Rechte serverseitig greifen. Alles, was nur im Frontend verhindert wird, gilt als nicht abgesichert.

