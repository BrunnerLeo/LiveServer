# Contributing

## Scope

Liveserver is intentionally small. Contributions should keep the project easy to install on a simple Apache/PHP/SQLite server.

## Before changing code

- Read `README.md`, `docs/INSTALLATION.md` and `docs/SECURITY.md`.
- Keep runtime data out of commits.
- Prefer small changes with clear behavior.
- Do not add build tooling unless the benefit is concrete.

## Local checks

```bash
node --check public/js/*.js
bash -n install.sh
python3 -m json.tool config/settings.json >/dev/null
python3 -m json.tool config/settings.example.json >/dev/null
python3 -m json.tool config/settings.production.example.json >/dev/null
```

If PHP is installed:

```bash
find php -name '*.php' -print0 | xargs -0 -n1 php -l
```

## Pull request expectations

- Explain the user-visible change.
- Mention database schema changes.
- Mention security-sensitive changes.
- Update documentation when endpoints, install steps or configuration keys change.
- Add migration-safe schema changes in `php/db_connect.php`; update `install.sh` for fresh installs.
