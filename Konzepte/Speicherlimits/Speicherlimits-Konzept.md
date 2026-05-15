# Speicherlimits-Konzept fuer Liveserver

Stand: 2026-05-15  
Bezugsstand: `/Volumes/Data/code/Liveserver`  
Versionsbezug: `00.00.22`

## 1. Zweck

Speicherlimits schuetzen Serverressourcen und definieren Grenzen fuer Uploads, Editor-Saves, ZIPs und Backups.

Dieses Dokument ist die ausfuehrliche Fassung. Es ist als Arbeitsgrundlage fuer Umsetzung, Review, Test, Betrieb und spaetere Erweiterungen gedacht. Liveserver besteht aus einem statischen Frontend unter `public`, PHP-Endpunkten unter `php`, einer SQLite-Datenbank unter `database/liveserver.sqlite`, geschuetzten Uploads unter `uploads/projects` und gehosteten Webpage-Projekten ueber `site.php`.

## 2. Aktueller Projektstand

- `projects.maxUploadBytes` steht in `settings.json`.
- `get_max_upload_bytes` liefert Limit.
- Upload- und Editor-Endpunkte pruefen Summen.
- `project_files.file_size` speichert Einzelgroessen.
- `projects.file_size` braucht nach Editor-Saves konsequente Pflege.

Der beruecksichtigte Stand ist Version `00.00.22`. In diesem Stand sind besonders wichtig: Projektkonsole, gehostete Webpage-Projekte, separater Editor, Read/Write-Freigaben, Projektloeschung, manuelle Projekt-Reihenfolge, Dashboard-Aktivitaet und zentrale Security-Helfer in `php/db_connect.php`.

## 3. Zielbild

- Globales Limit serverseitig erzwingen.
- Nutzer sehen verstaendliche Limits.
- Editor und Upload gleich behandeln.
- Speicher pro Nutzer auswertbar machen.
- Verwaiste Dateien finden.

Das Zielbild folgt drei Grundsaetzen: Erstens bleibt die Bedienung fuer Schueler einfach. Zweitens prueft das Backend jede fachliche und sicherheitsrelevante Entscheidung selbst. Drittens muessen Datenbank, Dateisystem, Frontendzustand und gehostete Auslieferung konsistent bleiben.

## 4. Betroffene Komponenten

- `settings.json`
- `get_max_upload_bytes`
- `project_files.file_size`
- `projects.file_size`
- Admin-Speicheruebersicht.

Bei Aenderungen an diesem Bereich sollten diese Komponenten zuerst gelesen werden. Neue Funktionen sollen vorhandene Hilfsfunktionen verwenden, statt aehnliche Logik in mehreren Endpunkten neu zu implementieren.

## 5. Fachlicher Ablauf

1. Frontend zeigt Dateigroessen.
2. Backend summiert Bytes.
3. Limit pruefen.
4. Dateigroessen speichern.
5. Admin berechnet Verbrauch.

Der Ablauf muss sowohl ueber die normale Benutzeroberflaeche als auch bei direkten HTTP-Aufrufen korrekt sein. Ein ausgeblendeter Button oder ein lokaler UI-Zustand ist keine Sicherheitsgrenze. Jeder PHP-Endpunkt muss Eingaben, Session, CSRF und Rechte eigenstaendig pruefen.

## 6. Datenmodell

Liveserver nutzt fuer diese Konzepte vor allem die Tabellen `users`, `projects`, `project_files`, `project_folders`, `project_shares` und `project_orders`. `projects` enthaelt Projektkopf und Sichtbarkeit. `project_files` verbindet sichtbare Projektpfade mit zufaelligen gespeicherten Dateinamen. `project_folders` modelliert Ordner fuer Webpage-Projekte und Editor. `project_shares` speichert gezielte Freigaben inklusive `permission`. `project_orders` speichert nutzerbezogene Reihenfolgen.

Schemaaenderungen sollten idempotent sein. Der aktuelle Runtime-Ansatz liegt in `ensure_sqlite_schema()` und `ensure_sqlite_column()`. Fuer groessere Erweiterungen ist eine explizite Migrationsversion sinnvoll, damit `install.sh`, README und Runtime nicht auseinanderlaufen.

## 7. Sicherheitsregeln

- Clientanzeige nicht vertrauen.
- PHP-Ini-Limits beachten.
- Viele kleine Dateien begrenzen.
- Temp-Speicher fuer ZIPs beachten.
- Quota-Ausnahmen protokollieren.

Sicherheit ist hier nicht optionaler Zusatz, sondern Teil der Funktion. Besonders kritisch sind Uploads, gehostete Webpages, Editor-Saves, Downloads, Freigaben und alle Aktionen mit Schreibrecht. Fuer produktiven Betrieb muessen HTTPS, geschuetzte interne Ordner und ausgeschaltete Detailfehler vorausgesetzt werden.

## 8. UI- und Bedienregeln

Die Oberflaeche soll ruhig, klar und zweckmaessig bleiben. Nutzer sollen erkennen, welche Aktion moeglich ist und welchen Status ein Projekt hat. Technische Details wie physische Dateinamen, SQLite-IDs, interne Pfade oder Debugmeldungen gehoeren nicht in normale Nutzeransichten. Read-only- und Write-Zustaende duerfen angezeigt werden, muessen aber serverseitig erneut geprueft werden.

Fehler sollten nahe an der Aktion erscheinen. Fuer Formulare bedeutet das Feldfehler und eine kurze Gesamtmeldung. Fuer Projektkarten bedeutet das klare Statusmeldungen und anschliessendes Neuladen oder Aktualisieren der Karte.

## 9. Fehlerfaelle und Grenzbereiche

Typische Grenzbereiche sind abgelaufene Sessions, parallele Editor-Tabs, fehlende CSRF-Token, grosse Uploads, Sonderzeichen in Pfaden, fehlende PHP-Module, gesperrte Dateirechte, direkte API-Aufrufe, Public-Write-Freigaben und Same-Origin-Verhalten gehosteter Webpages. Diese Faelle gehoeren in die manuelle oder automatisierte Abnahme.

## 10. Test- und Abnahmekriterien

- Unter Limit ok.
- Ueber Limit abgelehnt.
- Editor ueber Limit abgelehnt.
- Mehrere Dateien als Summe.
- Projektgroesse korrekt.

Diese Tests sind Mindestkriterien. Fuer Releases sollten zusaetzlich PHP-Syntaxchecks, ein Healthcheck, ein Login-Test, ein negativer Rechtefall und mindestens ein echter Projektablauf ausgefuehrt werden.

## 11. Betrieb und Wartung

Im Betrieb muss dieses Konzept mit Backup, Restore, Logs, Healthcheck und Versionspflege zusammenspielen. Nach Aenderungen sollte geprueft werden, ob `config/settings.json`, README, `install.sh`, Security-Konzept oder Admin-Dokumentation angepasst werden muessen. Bei Datei- und Webpage-Funktionen ist immer zu pruefen, ob `uploads`, `database` und `logs` weiterhin nicht direkt erreichbar sind.

## 12. Review-Kriterien

Ein Review sollte folgende Fragen beantworten:

- Wird jede schreibende Aktion per POST und CSRF geschuetzt?
- Gibt es eine serverseitige Rechtepruefung ohne Vertrauen in die UI?
- Werden Dateinamen, Pfade und IDs validiert oder normalisiert?
- Bleiben interne Pfade, Passwort-Hashes, Tokens und Debugdetails verborgen?
- Sind Datenbank und Dateisystem nach Fehlern konsistent?
- Gibt es mindestens einen negativen Testfall fuer fehlende Rechte?
- Sind gehostete Webpages und Same-Origin-Risiken beruecksichtigt?

## 13. Weiterentwicklung

- Quota pro Nutzer.
- Dateianzahl-Limit.
- Warnung bei 80 Prozent.
- Wartungsjob.
- Speicherdashboard.

Die Weiterentwicklung sollte schrittweise erfolgen. Zuerst bestehende Funktionen stabilisieren, dann Admin- und Auditfunktionen ausbauen. Neue Features sollten klein starten, klar getestet werden und die vorhandene Architektur respektieren.

## 14. Zusammenfassung

Speicherlimits-Konzept fuer Liveserver beschreibt einen konkreten Baustein von Liveserver. Entscheidend ist, dass Bedienkomfort, Datenmodell, Rechtepruefung und Betrieb zusammenpassen. Wenn diese Grenzen sauber bleiben, kann Liveserver weiter wachsen, ohne unuebersichtlich oder unsicher zu werden.

## 15. Detaillierte technische Leitlinien

Fuer `Speicherlimits` gilt, dass jede technische Aenderung zuerst aus der bestehenden Liveserver-Struktur heraus gedacht werden muss. Die Anwendung ist absichtlich einfach aufgebaut: HTML-Dateien liefern die Oberflaeche, JavaScript ruft PHP-Endpunkte auf, PHP prueft Berechtigungen und SQLite speichert den fachlichen Zustand. Diese Einfachheit ist ein Vorteil, solange neue Funktionen nicht an den vorhandenen Helfern vorbeigebaut werden.

Eine Umsetzung sollte deshalb immer an `php/db_connect.php` beginnen. Dort liegen zentrale Funktionen fuer Sessions, CSRF, Datenbankverbindung, Nutzer, Projekte, Freigaben, Dateipfade, Projektordner und oeffentliche Projektantworten. Wenn eine neue Funktion dieselbe Art von Pruefung braucht wie eine bestehende Funktion, soll sie dieselbe Hilfsfunktion verwenden. Beispiele sind `require_logged_in_user`, `require_project_owner`, `require_project_access`, `user_can_access_project`, `user_can_edit_project`, `get_project_files`, `find_project_file`, `get_project_upload_directory` und `get_max_upload_bytes`.

Frontendcode darf den Nutzer fuehren, aber er darf keine fachliche Wahrheit erzeugen, die das Backend ungeprueft uebernimmt. Lokale Entwuerfe, Collapse-State, Sortierung, sichtbare Buttons, ausgewaehlte Optionen oder berechnete Dateigroessen sind Bedienhilfen. Das Backend muss weiterhin kontrollieren, ob der Nutzer angemeldet ist, ob der CSRF-Token stimmt, ob IDs zu echten Datensaetzen gehoeren, ob der Nutzer lesen oder schreiben darf und ob Dateipfade innerhalb der erlaubten Struktur bleiben.

## 16. Datenfluss und Zustandskonsistenz

Bei `Speicherlimits` ist besonders wichtig, dass der Zustand an mehreren Orten zusammenpasst. Liveserver hat mindestens vier Zustandsbereiche: Browserzustand, PHP-Session, SQLite-Datenbank und Dateisystem. Bei Webpage-Projekten kommt zusaetzlich die gehostete Auslieferung ueber `site.php` hinzu. Ein Fehler entsteht oft nicht dadurch, dass ein einzelner Schritt scheitert, sondern dadurch, dass ein Schritt erfolgreich war und der naechste abbricht.

Darum sollten Aktionen, die mehrere Tabellen betreffen, in Transaktionen laufen. Projektanlage ist ein gutes Beispiel: `projects`, `project_files`, `project_folders` und `project_shares` gehoeren fachlich zusammen. Wenn eine Datei bereits physisch geschrieben wurde und danach die Datenbankaktion scheitert, muss die Datei wieder entfernt werden. Umgekehrt darf eine Datenbankzeile nicht auf eine Datei zeigen, die nie erfolgreich geschrieben wurde.

Bei spaeteren Erweiterungen sollte ausserdem klar entschieden werden, welcher Zustand fuehrend ist. Fuer Projektmetadaten ist SQLite fuehrend. Fuer physische Inhalte ist das Dateisystem notwendig, aber die Berechtigung kommt weiterhin aus SQLite. Fuer UI-Praeferenzen wie aufgeklappte Projektkarten kann der Browser fuehrend sein, weil dieser Zustand keine Sicherheitsbedeutung hat.

## 17. Rechte- und Rollenmatrix

Die wichtigste Matrix fuer Liveserver besteht aktuell aus diesen Faellen:

- Nicht angemeldeter Nutzer.
- Angemeldeter Nutzer ohne Projektbezug.
- Projektbesitzer.
- Shared-Read-Nutzer.
- Shared-Write-Nutzer.
- Public-Read-Zugriff.
- Public-Write-Zugriff bei Webpage-Projekten.
- Zukuenftiger Admin.

Fuer `Speicherlimits` muss jede Aktion in diese Matrix eingeordnet werden. Eine Lesefunktion kann fuer Public erlaubt sein, waehrend eine Schreibfunktion nur Ownern oder Write-Freigaben offensteht. Eine Metadatenfunktion kann Owner-only bleiben, auch wenn ein Shared-Write-Nutzer den Webpage-Code bearbeiten darf. Genau diese Trennung verhindert, dass Editorrechte versehentlich zu Verwaltungsrechten werden.

Zukuenftige Rollen wie `teacher` oder `admin` sollten diese Matrix erweitern, nicht ersetzen. Ein Admin kann spaeter Sonderrechte erhalten, aber diese Sonderrechte sollten explizit in einer Funktion wie `require_role('admin')` sichtbar sein. Lehrerrechte brauchen ein eigenes Konzept mit Klassen- oder Kursbezug, damit nicht pauschal alle Schuelerprojekte sichtbar werden.

## 18. Sicherheitsrisiken und Gegenmassnahmen

Die groessten Risiken fuer `Speicherlimits` entstehen aus direktem Dateizugriff, fehlerhafter Rechtepruefung, ungeprueften Pfaden, Same-Origin-Webpages, zu detaillierten Fehlermeldungen und unvollstaendigen Migrationen. Gegen diese Risiken helfen keine einzelnen Tricks, sondern konsequente Wiederholung derselben Regeln.

Dateien aus `uploads/projects` duerfen nicht direkt statisch ausgeliefert werden. Alle Zugriffe laufen ueber PHP, weil nur PHP die Projektberechtigung kennt. Pfade aus dem Browser werden nie als echte Serverpfade verwendet. Sie werden normalisiert, als relative Projektpfade gespeichert und beim Lesen mit gespeicherten Dateinamen verbunden. Fuer Datenbankzugriffe gelten Prepared Statements. Fuer alle POST-Aktionen gilt CSRF. Fuer alle Fehler gilt: Nutzer bekommen eine verstaendliche Meldung, Logs bekommen technische Details, aber normale Antworten enthalten keine internen Pfade oder Stacktraces.

Das Same-Origin-Risiko gehosteter Webpages bleibt ein Sonderfall. Solange Nutzer-Websites unter derselben Origin laufen wie die Portaloberflaeche, koennen aktive Inhalte grundsaetzlich Requests an dieselbe Anwendung senden. `HttpOnly` schuetzt Cookies vor direktem Auslesen, aber nicht vor allen Requests. Fuer produktive Umgebungen ist daher eine getrennte Origin fuer gehostete Seiten die sauberste Zielarchitektur.

## 19. Teststrategie im Detail

Eine sinnvolle Teststrategie fuer `Speicherlimits` besteht aus positiven und negativen Tests. Positive Tests pruefen, dass der normale Nutzerfluss funktioniert. Negative Tests pruefen, dass unerlaubte Zugriffe scheitern. Negative Tests sind hier besonders wichtig, weil die Anwendung viele direkte PHP-Endpunkte hat.

Mindestens getestet werden sollten: Zugriff ohne Login, Zugriff mit falschem Nutzer, Zugriff mit Shared-Read statt Shared-Write, fehlender CSRF-Token, falsche Projekt-ID, geloeschtes Projekt, Sonderzeichen in Pfaden, grosse Dateien, leere Eingaben und direkte URL-Aufrufe. Bei Webpage-Projekten kommen Tests fuer relative Assets, Unterordner, absolute `/assets/...`-Pfade, `index.html` und Editor-Speichern hinzu. Bei Downloads kommen Tests fuer Einzeldatei, ZIP und `file_id` hinzu.

Langfristig koennen diese Tests automatisiert werden. Kurzfristig reicht eine feste manuelle Checkliste pro Release. Wichtig ist, dass sie wirklich gegen den Serverstand laeuft, nicht nur gegen einzelne Funktionen im Kopf. Nach jeder groesseren Aenderung sollte `php/health.php` aufgerufen werden und mindestens ein Projektablauf von Start bis Ende geprueft werden.

## 20. Betriebs- und Release-Regeln

Fuer den Betrieb ist `Speicherlimits` nur dann sauber, wenn Dokumentation und ausgelieferter Stand zusammenpassen. Die Version in `config/settings.json`, README und Asset-Querystrings sollte synchron sein. Wenn ein Konzeptverhalten geaendert wird, sollte entweder die passende Konzeptdatei oder die README angepasst werden. Wenn sich Sicherheitsverhalten aendert, muss das Security-Konzept mitgezogen werden.

Vor einem Deployment sollte ein Backup existieren. Das betrifft mindestens `database/liveserver.sqlite`, moegliche SQLite-WAL/SHM-Dateien, `uploads/projects` und `config/settings.json`. Nach dem Deployment werden Healthcheck, Login, Projektliste, ein betroffener Schreibfluss, ein betroffener Lesefluss und ein negativer Rechtefall getestet. Wenn diese Pruefungen bestanden sind, ist die Aenderung nicht automatisch perfekt, aber sie hat die wichtigsten Betriebsrisiken abgedeckt.

