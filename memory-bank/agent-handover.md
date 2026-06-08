# fast-kalk — agent handover

**Updated:** 2026-06-08

## Stan

Plugin **v0.6.7** — funnel leadgen **domknięty lokalnie** (Gate B).

| Warstwa                           | Status                                      |
| --------------------------------- | ------------------------------------------- |
| UI funnel (chat → wynik → Wyślij) | OK                                          |
| kalk-top calculate-offer          | OK (internal dispatch @8091)                |
| Cache pełnego OfferDTO            | OK (`class-session-store.php`)              |
| Generator PDF from-offer-dto      | OK (internal dispatch + HTTP fallback)      |
| wp_mail SMTP                      | OK (mu-plugin + `config/local-secrets.env`) |
| Registry Node B                   | OK best-effort                              |
| E2E smoke                         | **39/39 PASS** (`e2e-scenarios-smoke.php`)  |

## Proof

```text
php scripts/configure-local.php
php scripts/e2e-scenarios-smoke.php --live-mail
# operator_email: konradswierad@gmail.com, offer_test_mode=1
```

Scenariusze: wolnostojący+podłogówka, w_budowie (skip obecne_ogrzewanie), grzejniki HT, wielorodzinny intensywny CWU, E2E dispatch.

## Otwarte (P0 prod)

1. **Deploy prod** topinstal.com.pl — checklist w `PROJECT_README.md`
2. **Git** — plugin prawie poza historią (stub commit); commit na żądanie operatora
3. **CI** — brak phpunit workflow

## Konfiguracja lokalna

- `scripts/configure-local.php` — junction pluginu do `kalk-top/.runtime-wp`, SMTP z `cieplo-orchestrator/.env`
- Port **8091** (nie 8090 = Daszek)

## Nie implementować (decyzja operatora)

- `startRefinement()` — nie podpinać
- CTA / sample PDF button — zastąpione Wyślij
- Badge Podane/Założenia

## Następny agent

1. Prod deploy + shortcode Elementor
2. Wyłączyć `offer_test_mode` na prod gdy gotowe wysyłanie do klienta
3. Commit pluginu do git fast-kalk na żądanie
