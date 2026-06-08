# fast-kalk

WordPress leadgen widget — orientacyjny dobór pompy ciepła. Deleguje HVAC do **kalk-top**, PDF do **top-instal-generator**.

**Plugin:** `wp-content/plugins/topinstal-lead-widget/` (v0.6.7)
**Pełna dokumentacja:** [`PROJECT_README.md`](PROJECT_README.md)

## Quick start (lokalnie)

```powershell
cd ..\kalk-top\scripts && .\start-runtime-wp.ps1
cd ..\..\fast-kalk
php scripts/configure-local.php
php scripts/e2e-scenarios-smoke.php
```

Runtime: `http://127.0.0.1:8091` (nie 8090).

## REST API (`topinstal-lead/v1`)

| Endpoint          | Opis                                  |
| ----------------- | ------------------------------------- |
| `POST /chat`      | Chat AI + ekstrakcja parametrów       |
| `POST /calculate` | Kalkulacja + cache OfferDTO           |
| `POST /register`  | Registry Node B + Wyślij (PDF + mail) |

## Struktura repo

```text
fast-kalk/
  wp-content/plugins/topinstal-lead-widget/   # plugin
  wp-content/mu-plugins/topinstal-wp-smtp.php  # SMTP dla wp_mail (lokalnie)
  config/local-secrets.env.example            # szablon SMTP (gitignored .env)
  scripts/
    configure-local.php                       # junction + opcje WP + SMTP
    e2e-scenarios-smoke.php                   # E2E regression
    local-rest-smoke.php
    buffer-hydraulics-smoke.php
```

## Agent

[`AGENTS.md`](AGENTS.md) → [`../AGENTS.md`](../AGENTS.md)
