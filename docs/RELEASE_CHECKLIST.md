# Release Checklist

## Version

- `VERSION` enthält die neue Versionsnummer.
- `config/settings.json` enthält dieselbe Versionsnummer.
- `config/settings.example.json` enthält dieselbe Versionsnummer.
- `config/settings.production.example.json` enthält dieselbe Versionsnummer.
- `README.md` nennt dieselbe Versionsnummer.
- `CHANGELOG.md` enthält einen Eintrag.

## Repository hygiene

- keine `.DS_Store`
- keine `._*`
- keine SQLite-Datenbank
- keine SQLite-WAL/SHM-Datei
- keine Upload-Inhalte
- keine Logdateien
- keine privaten Serverpfade in Dokumentation oder Konfiguration

## Checks

```bash
node --check public/js/*.js
bash -n install.sh
python3 -m json.tool config/settings.json >/dev/null
python3 -m json.tool config/settings.example.json >/dev/null
python3 -m json.tool config/settings.production.example.json >/dev/null
```

Wenn PHP verfügbar ist:

```bash
find php -name '*.php' -print0 | xargs -0 -n1 php -l
```

## Manual smoke test

- Startseite öffnet.
- Registrierung/Login funktioniert.
- Dashboard lädt.
- eigene Farben werden gespeichert und nach Reload wieder geladen.
- Datei-Projekt kann erstellt und heruntergeladen werden.
- Webpage-Projekt mit `index.html` wird gehostet.
- Editor öffnet ein Webpage-Projekt.
- Datei im Editor kann gelöscht und gespeichert werden.
- Ordner im Editor kann gelöscht und gespeichert werden.
- Projekt kann gelöscht werden.
- `database`, `uploads` und `logs` sind im Browser nicht direkt erreichbar.

## Deployment

- `config/settings.json` ist produktiv angepasst.
- HTTPS ist aktiv.
- `security.secureCookie=true`.
- Registrierung ist bewusst aktiviert oder deaktiviert.
- Backup wurde erstellt.
- Rollback-Pfad ist bekannt.
