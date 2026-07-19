# fast-kalk — dokumentacja produktu i systemu

**Wersja pluginu:** 0.6.7 · **Repo #6** w workspace TOP-INSTAL · **Typ:** wtyczka WordPress (leadgen)
**Ekosystem:** [`../knowledge/OS_README.md`](../knowledge/OS_README.md)
**Proof lokalny:** `php scripts/e2e-scenarios-smoke.php` — **39/39 PASS** @8091 (2026-06-08)

---

## Dla marketingu i właściciela

### Po co jest fast-kalk

**Szybki kalkulator na stronie** — dla gościa, który nie chce od razu wypełniać długiego formularza:

1. Kilka prostych kliknięć (typ domu, standard, ogrzewanie).
2. **Chat AI** dopytuje o brakujące rzeczy (metraż, kod pocztowy, CWU…).
3. W ~minutę: **orientacyjny dobór pompy** i **przedział ceny**.
4. Pole e-mail + **Wyślij** → operator dostaje PDF oferty i powiadomienie `[NOWY LEAD]`.

To **narzędzie pozyskiwania leadów**, nie zamiennik pełnego audytu instalacji.

### Co widzi klient

- Widget: shortcode `[topinstal_lead_widget]`
- Nagłówek: _ORIENTACYJNY DOBÓR POMPY CIEPŁA_ / _SPRAWDŹ KOSZT TWOJEJ POMPY_
- Wynik: model pompy, bufor, CWU, przedział ceny (−7% / +16% od brutto)
- **Wyślij** — oferta PDF na e-mail (w trybie testowym tylko do operatora)

### Status wdrożenia (2026-06-08)

| Środowisko              | Status                                                                |
| ----------------------- | --------------------------------------------------------------------- |
| Repo + plugin v0.6.7    | **Gotowe** — funnel, PDF, SMTP, E2E smoke                             |
| Lokalny proof @8091     | **PASS** — kalk-top + generator + mail operatora                      |
| Produkcja topinstal.com | **Do wdrożenia** — skopiuj plugin, ustawienia WP, shortcode Elementor |

---

## Dla developera

### Zasada

**Nie duplikuj silnika HVAC.** Obliczenia wyłącznie przez:

```http
POST /wp-json/topinstal/v1/calculate-offer
```

PDF oferty przez:

```http
POST /wp-json/topinstal/v1/offer-documents/generate
mode: from-offer-dto
```

(autoryzacja: `TOPINSTAL_CALC_AGENT_API_KEY` / `X-Top-Instal-Agent-Key`).

### Przepływ v0.6.7

```text
[Widget JS]
  → REST WP (nonce) topinstal-lead/v1
      /chat | /calculate | /register

[topinstal-lead-widget PHP]
  → class-defaults.php → CalcRequestDTO (jak formDataProcessor / OZC)
  → class-calculator.php → kalk-top calculate-offer
  → cache pełnego OfferDTO (Session_Store)
  → map_offer_to_summary() → UI (przedział −7% / +16% brutto)

[/register + contact_email]
  → class-offer-dispatch.php
  → generator from-offer-dto (pełny OfferDTO + tank.capacity z CWU)
  → wp_mail() — operator [NOWY LEAD] + opcjonalnie klient
  → class-lead-registry.php → async Node B /internal/registry/links

SMTP: mu-plugin topinstal-wp-smtp.php (credentials z cieplo-orchestrator .env)
```

**Nie używa** `cieplo-worker :8000`.

### Decyzje produktowe (2026-06)

| Element                | Status                                                 |
| ---------------------- | ------------------------------------------------------ |
| Post-result refinement | **Wyłączone** — `startRefinement()` nie jest triggerem |
| CTA / przykładowy PDF  | **Zastąpione** polem e-mail + Wyślij                   |
| Badge Podane/Założenia | **Anulowane** — zła UX                                 |
| `dhw_bundle`           | **Rozbite** na `dhw_persons` + `dhw_usage`             |
| `obecne_ogrzewanie`    | Pomijane gdy `standard=w_budowie`                      |

### Plugin — pliki

| Plik                                | Rola                                           |
| ----------------------------------- | ---------------------------------------------- |
| `topinstal-lead-widget.php`         | Bootstrap, ustawienia WP Admin                 |
| `includes/class-calculator.php`     | Proxy kalk-top + cache OfferDTO                |
| `includes/class-offer-dispatch.php` | Generator PDF + wp_mail                        |
| `includes/class-chat.php`           | Anthropic + tool `update_lead_parameters`      |
| `includes/class-defaults.php`       | Heurystyki / mapper OZC / DHW                  |
| `includes/class-lead-registry.php`  | Sync registry Node B + dispatch przy /register |
| `includes/class-session-store.php`  | Transient cache calculate + OfferDTO           |
| `assets/widget.js`                  | UI funnel + submitLeadEmail                    |

### Ustawienia WP

| Opcja                         | Cel                                                        |
| ----------------------------- | ---------------------------------------------------------- |
| `anthropic_api_key`           | Chat krok B                                                |
| `calc_agent_api_key`          | calculate-offer (lub const `TOPINSTAL_CALC_AGENT_API_KEY`) |
| `calc_rest_url`               | URL calculate-offer (domyślnie REST tej samej WP)          |
| `generator_url`               | Baza WP z top-instal-generator                             |
| `generator_agent_key`         | Klucz generatora (puste = jak calc)                        |
| `operator_email`              | `[NOWY LEAD]` — np. konradswierad@gmail.com                |
| `lead_email_override`         | Test: oferta klienta na ten adres                          |
| `offer_test_mode`             | `1` = tylko operator (bez maila do klienta)                |
| `mail_from`                   | From w mailach (domyślnie SMTP_FROM)                       |
| `node_b_registry_url` + token | gmail-agent Node B `:8766` (host; `:8765` if port unset)   |

### Lokalny dev (kalk-top runtime)

| Port | Usługa                                       |
| ---- | -------------------------------------------- |
| 8091 | kalk-top + generator + lead-widget (proof)   |
| 8090 | Daszek sandbox — **nie** używać do fast-kalk |

```powershell
# 1. Runtime WP
cd ..\kalk-top\scripts
.\start-runtime-wp.ps1

# 2. Konfiguracja (SMTP z cieplo-orchestrator/.env, junction pluginu)
cd ..\..\fast-kalk
php scripts/configure-local.php

# 3. Smoke
php scripts/e2e-scenarios-smoke.php          # bez maila na końcu
php scripts/e2e-scenarios-smoke.php --live-mail   # pełny E2E + mail operatora
```

### Skrypty testowe

| Skrypt                                | Zakres                                       |
| ------------------------------------- | -------------------------------------------- |
| `scripts/e2e-scenarios-smoke.php`     | 4 scenariusze formularza + dispatch PDF/mail |
| `scripts/local-rest-smoke.php`        | chat + calculate + mapowanie                 |
| `scripts/buffer-hydraulics-smoke.php` | HT grzejników, bufor, refinement fields      |
| `scripts/insulation-pending-test.php` | pending questions ocieplenia                 |

### Wdrożenie prod (checklist) — **deferred**

> **Firma w zawieszeniu (2026-06-17):** ten checklist jest na przyszłość. Agent **nie** wykonuje deploy prod dopóki operator nie powiadomi o wznowieniu działalności (`OPERATOR_DECISIONS` §2026-06-17).

1. Skopiuj / zsynchronizuj `wp-content/plugins/topinstal-lead-widget/` na prod WP
2. Aktywuj plugin + **kalk-top** + **top-instal-generator** na tej samej WP (lub ustaw `generator_url` na zewnętrzną instancję)
3. Ustawienia → TOP-INSTAL Lead Widget: klucze agenta, `operator_email`, `generator_url`
4. SMTP: mu-plugin `topinstal-wp-smtp.php` + `config/local-secrets.env` (lub WP Mail SMTP plugin z tymi samymi credentials co cieplo-orchestrator)
5. Shortcode `[topinstal_lead_widget]` na stronie Elementor
6. Wyczyść cache WP/Elementor
7. Smoke na stagingu: calculate → Wyślij → PDF w skrzynce operatora

### Powiązania

| System               | Relacja                                                        |
| -------------------- | -------------------------------------------------------------- |
| kalk-top             | Jedyny silnik OfferDTO                                         |
| top-instal-generator | PDF `from-offer-dto`                                           |
| cieplo-orchestrator  | Ten sam SMTP / agent keys (referencja konfiguracji)            |
| gmail-agent          | Registry `calc_request_snapshot`, `canonical_trace`            |
| RAG                  | Wspólny `company_context.md` (treść chatu, nie pipeline ofert) |

### Mapowanie pól

[`wp-content/plugins/topinstal-lead-widget/docs/LEAD_WIDGET_CALC_MAPPING.md`](wp-content/plugins/topinstal-lead-widget/docs/LEAD_WIDGET_CALC_MAPPING.md)

---

_Techniczny quick-start: [`README.md`](README.md) · Agent handover: [`memory-bank/agent-handover.md`](memory-bank/agent-handover.md)_
