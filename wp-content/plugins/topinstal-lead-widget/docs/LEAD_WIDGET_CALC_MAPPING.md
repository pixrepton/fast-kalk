# Lead widget → kalk-top CalcRequestDTO

Wersja wtyczki: **0.6.7** · kanał `fast-kalk` · źródło `lead_widget`.

## Przepływ

```text
collected (widget JS)
  → Topinstal_Lead_Widget_Defaults::sanitize_collected()
  → Topinstal_Lead_Widget_Defaults::to_calc_request()
  → POST /wp-json/topinstal/v1/calculate-offer
  → OfferDTO → cache (Session_Store) + map_offer_to_summary()

contact_email na /register:
  → Topinstal_Lead_Widget_Offer_Dispatch::maybe_dispatch()
  → POST offer-documents/generate (mode: from-offer-dto, pełny offerDto)
  → wp_mail operator + opcjonalnie klient
```

## Pola `collected` → building / preferences

| collected                                   | CalcRequestDTO                                                     | Uwagi                                                                   |
| ------------------------------------------- | ------------------------------------------------------------------ | ----------------------------------------------------------------------- |
| `typ_budynku`                               | `building.building_type`                                           | map_building_type                                                       |
| `standard`                                  | `building.construction_year`, `construction_type`, okna/drzwi      | building_profile_for_standard                                           |
| `powierzchnia`                              | `building.heated_area`, `total_area`, `floor_area`                 | 40–500 m²                                                               |
| `insulation_level` + `insulation_confirmed` | pola OZC z insulation_level_to_ozc_fields                          | bez confirmed → `average` lub skip-default                              |
| `postal_code`                               | `building.location_id`, lat/lon                                    | resolve_location                                                        |
| `emitter_type`                              | `preferences.heating.emitterType`                                  | podlogowka / radiators / mixed                                          |
| `dhw_persons`                               | `building.hot_water_persons`                                       | 2–3→3, 4–5→5, więcej→6                                                  |
| `dhw_usage`                                 | `preferences.dhw.usageProfile`                                     | shower / shower_bath / bath                                             |
| `obecne_ogrzewanie`                         | `building.secondary_source_type`, `bivalent_enabled`               | **pomijane** gdy `should_assume_existing_heat_pump()` (np. `w_budowie`) |
| `ventilation_type`                          | `building.ventilation_type`, `preferences.heating.ventilationType` | warunkowe rok ≥ 2000                                                    |
| `on_corner`                                 | `building.on_corner`                                               | tylko szeregowiec                                                       |
| `contact_email`                             | `lead.contact.email` + dispatch                                    | **nie** wchodzi w fingerprint cache                                     |

## Hydraulika bufora (`context.configurator.hydraulics_inputs`)

| collected                  | hydraulics_inputs                 | Warunek wysyłki                                            |
| -------------------------- | --------------------------------- | ---------------------------------------------------------- |
| `radiators_is_ht`          | `radiators_is_ht` (bool)          | emiter grzejniki/mieszane **oraz** `hydraulics_confirmed`  |
| `has_underfloor_actuators` | `has_underfloor_actuators` (bool) | emiter mieszane, po odpowiedzi                             |
| `obecne_ogrzewanie`        | `bivalent_source_type`            | `gas_boiler` / `solid_fuel_boiler` gdy bivalent w building |
| —                          | `bivalent_enabled`                | z `building.bivalent_enabled`                              |

**Nie wysyłamy** `radiators_is_ht` bez potwierdzenia użytkownika — BufferEngine domyślnie traktuje brak jako `false` (niskotemperaturowe).

## Generator payload (dispatch)

Z `engineering.cwu.recommendedCapacityL` → `payload.tank.capacity` (Trinnity), żeby PDF CWU nie zgadywał z katalogu KIT.

## Refinement (kod istnieje, UI wyłączone)

Kolejność `REFINEMENT_FIELD_PRIORITY` w `class-defaults.php` — backend gotowy, ale **`startRefinement()` nie jest wywoływane** w widget.js (decyzja produktowa 2026-06).

## Cache

`Session_Store::fingerprint_collected()` — hash `collected` **bez** `contact_email`. Zmiana hydrauliki lub `calc_revision` unieważnia cache.

## Ceny w UI vs PDF

- Widget: `gross × 0.93` … `gross × 1.16` (orientacyjny przedział)
- PDF / mail operatora: pojedyncza cena brutto z `pricing.totals.gross`
