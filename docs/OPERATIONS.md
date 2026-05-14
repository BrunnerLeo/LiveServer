# Operations

## Runtime files

Diese Dateien entstehen im Betrieb und gehören nicht ins Repository:

- `database/liveserver.sqlite`
- `database/liveserver.sqlite-wal`
- `database/liveserver.sqlite-shm`
- `logs/*`
- `uploads/projects/*`

Die Ordner selbst bleiben im Repository, damit Installationen die erwartete Struktur haben.

## Backup

Für eine einfache Installation müssen gesichert werden:

- `config/settings.json`
- `database/liveserver.sqlite`
- `uploads/projects`

Empfehlung:

1. Apache kurz anhalten oder Wartungsfenster setzen.
2. SQLite-Datei inklusive WAL/SHM sichern, falls vorhanden.
3. Upload-Ordner sichern.
4. Apache wieder starten.

Wenn WAL aktiv ist, kann eine reine Kopie der `.sqlite`-Datei unvollständig sein. Sicherer ist:

```bash
sqlite3 database/liveserver.sqlite ".backup '/backup/liveserver.sqlite'"
```

## Restore

1. Code deployen.
2. `config/settings.json` wiederherstellen.
3. Datenbank in `database/liveserver.sqlite` zurücklegen.
4. Uploads nach `uploads/projects` zurücklegen.
5. Rechte setzen:

```bash
sudo chown -R www-data:www-data database uploads logs
sudo chmod -R 775 database uploads logs
```

6. Health-Check aufrufen.

## Updates

Bei Code-Updates:

- Runtime-Daten nicht überschreiben
- `config/settings.json` prüfen
- `php/db_connect.php` übernimmt Schema-Migrationen beim nächsten Zugriff
- `install.sh` ist für frische Installationen gedacht

Beispiel mit `rsync`:

```bash
rsync -a --delete \
  --exclude='database/*.sqlite' \
  --exclude='database/*.sqlite-*' \
  --exclude='uploads/projects/*' \
  --exclude='logs/*' \
  ./ /var/www/liveserver/
```

## Monitoring

Regelmäßig prüfen:

- `/php/health.php`
- freier Speicherplatz
- Schreibrechte auf `database`, `uploads`, `logs`
- Größe der Uploads
- Apache-Fehlerlog
- `logs/api-error.log`

## Incident response

Bei Verdacht auf Missbrauch:

1. Server vom öffentlichen Netz nehmen oder Zugriff einschränken.
2. Kopie von Datenbank, Logs und Uploads sichern.
3. Registrierung deaktivieren.
4. Passwörter der betroffenen Benutzer zurücksetzen.
5. Öffentliche Schreibrechte prüfen.
6. Uploads nach aktiven Inhalten durchsuchen.
7. Ursache dokumentieren und erst danach wieder öffnen.
