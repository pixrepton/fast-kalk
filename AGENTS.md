# Scope: fast-kalk

**Rola:** Widget leadgen WordPress (Node A). Nie jest silnikiem HVAC — deleguje kalkulację do `kalk-top`, PDF do `top-instal-generator`, registry do `gmail-agent` Node B.

**Wersja:** 0.6.7 · **Proof:** `php scripts/e2e-scenarios-smoke.php` @8091 (2026-06-08)

## Granice

- **Nie** implementuj OfferDTO / BufferEngine / OZC — to `kalk-top`
- **Nie** implementuj mapowania PDF — to `top-instal-generator` (`from-offer-dto`)
- **Nie** duplikuj pipeline cieplo-orchestrator — współdziel tylko env SMTP / agent keys
- Pełny OfferDTO do generatora (nie `result_summary`)

## Lokalny runtime

| Port | Co                                                           |
| ---- | ------------------------------------------------------------ |
| 8091 | kalk-top `.runtime-wp` — calculate + generator + lead-widget |
| 8090 | Daszek — **nie** ten WP                                      |

```powershell
kalk-top\scripts\start-runtime-wp.ps1
fast-kalk: php scripts/configure-local.php
```

## Kluczowe pliki

- `assets/widget.js` — UI funnel, `submitLeadEmail()`
- `includes/class-offer-dispatch.php` — generator + wp_mail
- `includes/class-calculator.php` — internal REST dispatch (PHP -S deadlock fix)
- `includes/class-defaults.php` — mapper + DHW split

## Decyzje produktowe (nie cofać bez operatora)

- Refinement po wyniku: **off**
- Wyślij zamiast CTA / sample PDF
- Badge parametrów: **off**

## Handover

Ostatni stan: `../knowledge/memory/ACTIVE_WORKSPACE.md`

**Prod deploy:** deferred — firma w zawieszeniu (2026-06-17). Lokalny proof = `e2e-scenarios-smoke.php` @8091.

Pełne instrukcje workspace: [`../AGENTS.md`](../AGENTS.md)
