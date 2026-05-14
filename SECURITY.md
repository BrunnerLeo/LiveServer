# Security Policy

## Supported version

Security fixes are prepared against the current release line:

| Version | Supported |
| --- | --- |
| 00.00.22 | yes |

## Reporting a vulnerability

Do not open public issues for exploitable vulnerabilities. Send a private report to the maintainer before publishing details.

Include:

- affected version and commit
- deployment type, for example Docker or Apache install script
- clear reproduction steps
- expected and observed behavior
- impact assessment, especially whether authentication, uploaded files, hosted Webpages or SQLite data are affected

## Operational baseline

- Use HTTPS in production and set `security.secureCookie=true`.
- Keep `database`, `uploads` and `logs` blocked from direct web access.
- Do not commit `database/*.sqlite`, uploaded project files or logs.
- Disable open registration if the server is exposed to the internet.
- Treat hosted Webpage projects as untrusted user content.

Detailed guidance is in `docs/SECURITY.md`.
