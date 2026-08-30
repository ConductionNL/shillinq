---
sidebar_position: 2
title: NL Payroll Engine — Developer Guide
description: Architecture, algorithm pseudocode, versioning strategy, cumulatieven design, GL-posting automation, and integration points for the Shillinq NL payroll engine.
---

# NL Payroll Engine — Developer Guide

This guide is the engineering counterpart to the
[Gebruikershandleiding](./user-guide-nl.md). It describes the architecture,
the bruto→netto algorithm, the versioning strategy, how cumulatieven are
snapshotted, the GL-posting machine, and every cross-app integration point.

## Architecture (one-line per layer)

```
PayrollController (#[NoAdminRequired], IDOR-safe)
        |
        v
PayrollService  ----calls---->  PayrollCalculator (pure-logic, no IO)
        |                            |
        v                            v
OpenRegister ObjectService     no IO; integer-cent arithmetic
(find / findAll / saveObject)
```

Every read is scoped to the `administrationId` resolved server-side from
the caller's context — the query parameter is validated against
`/^[A-Za-z0-9_.\-]{1,64}$/` then forwarded to the service. The
ObjectService API is the OR canonical contract — `find` / `findAll` /
`saveObject` — `findObject`, `createFromArray`, `deleteFromId` do NOT exist
and are NEVER used (ADR-022, hydra gate-15).

## Data model (lib/Settings/register.d/bookkeeping-payroll-engine-nl.json)

```
Werkgever              loonheffingsnummer, sectorcode, AWF/ZVW tarief,
                       WKR-budget, vakantiegeldUitbetalingMaand
LoonheffingTabel2026   IMMUTABLE; jaar+kleur+periode+metKorting+
                       versienummer; tabelRegels[] = bracket rows
LoonPeriode            werkgever, periodeType (WEEK|4WEKEN|MAAND),
                       periodeStart/Eind, status, loonheffingstabelId
Werknemer              BSN (masked at boundary), pensioenRegeling,
                       sectorcode, contractType, expat30PctRegeling,
                       is_dga, ...
LoonStrook             werknemerId, periodeId, brutoComponenten,
                       fiscaalLoon, premieloon_SV, loonheffing,
                       inhoudingenSV, premiesSVWerkgever, zvw, pensioen,
                       nettoBetaald, cumulatieven (read-only snapshot),
                       vakantieDagenReservering
LHAfdracht             werkgever, periode, totaalLoonheffing,
                       totaalEindheffingenWKR, totaalPremiesSV,
                       totaalZVW, vervaldagAfdracht,
                       status (VOORBEREID|GEVERIFIEERD|VERZONDEN|VERWERKT),
                       sbrInstanceRef
Loonjournaalpost       periodeId, regels[] = GLLine, balanced (bool)
```

The fragment is registered via the ADR-037 register.d mechanism (never the
monolith).

## bruto→netto algorithm (PayrollCalculator)

Pseudocode for one LoonStrook over one (werknemer, periode):

```
INPUT: werknemer, werkgever, periode, tabelRegels (LoonheffingTabel2026)

# 1. Bruto componenten
basis              = werknemer.periodeBruto OR jaarloon / weken_in_jaar(periodeType)
thuiswerkdagen     = werknemer.thuiswerkdagenPerWeek * weken_in_periode(periodeType)
thuiswerkvergoed   = calculator.thuiswerkvergoeding(thuiswerkdagen)    # 2,40/dag cap
expatVrij          = calculator.expat30PctVrijstelling(basis, werknemer.expat30PctRegeling)

brutoComponenten   = { basissalaris, thuiswerkvergoeding }
totaalBruto        = totaalBruto(brutoComponenten)
belastingvrij      = thuiswerkvergoed + expatVrij        # in cents
fiscaalLoon        = totaalBruto - belastingvrij
premieloonSV       = basis

# 2. Loonheffing — uit LoonheffingTabel2026 bracket lookup
loonheffing        = calculator.loonheffingUitTabel(fiscaalLoon, tabelRegels)

# 3. Premies werknemersverzekeringen — caps + pro-rata per periode
svWerkgever        = calculator.premiesSVWerkgever(
                        premieloonSV, periodeType,
                        werkgever.awfTarief,
                        kleineWerkgever=true,
                        whkTarief=werknemer.whkTarief2026,
                        wkoTarief=werknemer.wkoTarief2026)

# 4. ZVW werkgever
zvw                = calculator.zvwWerkgever(
                        premieloonSV, periodeType, werkgever.zvwTarief)

# 5. Pensioen
pensioen           = calculator.pensioen(
                        basis,
                        werknemer.pensioenPremiePctWerkgever,
                        werknemer.pensioenPremiePctWerknemer)

# 6. Netto
nettoBetaald       = calculator.nettoBetaald(
                        fiscaalLoon, loonheffing,
                        inhoudingSVWerknemer=0,
                        pensioenWerknemer=pensioen.premie_wn_aandeel,
                        belastingvrijTotaal=belastingvrij)

# 7. Vakantietoeslag opbouw (8%)
vakantieOpbouw     = calculator.vakantiegeldOpbouw(basis, werknemer.vakantiegeldPct)

# 8. Cumulatieven — read-only snapshot
cumulatieven       = stampCumulatieven(adm, werknemer.id, fiscaalLoon, vakantieOpbouw)

RETURN LoonStrook payload
```

All arithmetic happens in **integer cents** (`PayrollCalculator::toCents` /
`fromCents`) — mirrors `TrialBalanceCalculator` and `BalanceGuard` so no
IEEE-754 drift cascades into year-end totals.

## Versioning strategy — tax tables are immutable

`LoonheffingTabel2026` rows are immutable once `geldigVan` is in the past.
Belastingdienst correction tables (e.g. 1 July 2026) produce a NEW row with
`geldigVan: 2026-07-01`; the period resolver picks the row whose
`geldigVan <= periode.geldigVan` and `geldigTot` is null or `>=`. An auditor
can ask in 2031 "what was the May 2026 table?" and the answer is in the DB.

When a new tax year is loaded:

1. Insert new `LoonheffingTabel2026` rows with `versienummer` (e.g.
   `2026-W47`)
2. Verify `geldigVan: 2027-01-01`, `geldigTot: null`
3. Add updated premium percentages to the calculator class constants
   (`AWF_LAAG_2027`, `ZVW_TARIEF_LAAG_2027`, etc.) — never mutate the
   2026 constants
4. Update the calculator's lookup methods to fan out per `geldigVan`

## Cumulatieven design (D3)

Each `LoonStrook` carries an immutable `cumulatieven` snapshot
(`fiscaalloon_ytd`, `vakantiegeld_reservering_ytd`). The snapshot is
computed at strook-bouw time as `sum(prior_stroken_this_year.fiscaalLoon) +
current.fiscaalLoon` and stored to prevent floating-point recalculation
errors during the year. The jaaropgave validates that
`sum(periode_stroken.fiscaalLoon) == last_strook.cumulatieven.fiscaalloon_ytd`
— a mismatch flips `cumulatievenConsistent=false` and refuses persistence.

## GL-posting automation (REQ-PAY-012)

`PayrollService::bouwLoonjournaalpost`:

1. Reads all `LoonStrook` for the period (admin-scoped)
2. Aggregates in cents into 7 buckets: bruto-belastbaar, belastingvrij,
   SV-WG, ZVW-WG, pensioen-WG, pensioen-WN, LH, netto
3. Emits 9 GL lines using `PayrollChartOfAccountsMapping` (4001/4002/4010/
   4012/4020 debet; 1610/1620/1630/1640 credit)
4. Checks `sum(debet) == sum(credit)` in integer cents → sets `balanced`
5. `persistLoonjournaalpost` refuses when `balanced=false`

## Integration points

| Spec | Hand-off service | Output |
| --- | --- | --- |
| bookkeeping-loonaangifte-sbr | `PayrollSbrConversionService::toSbrInstancePayload` | LA-XX-2026 instance payload + deterministic `sbrInstanceRef` |
| bookkeeping-ap-ar | `PayrollApArHandoffService::toApTransactionPayloads` | Two APTransaction payloads (Belastingdienst + UWV) with breakdown |
| bookkeeping-upa-pensioen | `PayrollUpaHandoffService::toUpaSubmissionPayloads` | Per-pensioenuitvoerder UPA payloads grouped by `pensioenRegeling` |
| bookkeeping-wkr | `PayrollWkrHandoffService::toWkrLoonsomPayload` | Period loonsom (sum of `fiscaalLoon`) for WKR ceiling-tracking |
| bookkeeping-liv-lkv (future) | `PayrollLivLkvHandoffService::toLivLkvEligibilityPayload` | Per-(werknemer, jaar) inkomenniveau + fiscaalLoonJaar + lkvCategorie |
| bookkeeping-chart-of-accounts | `PayrollChartOfAccountsMapping::all` | Canonical RGS 3.5 account map |

Every hand-off is a **pure** computation — no cross-app HTTP, no transport.
The downstream app calls the appropriate service via DI to get the payload
shape.

## Security model (ADR-005, AVG)

- BSN is **never** logged; `PayrollService::maskBsn` masks to last 2 digits
  for any human-visible surface
- `administrationId` is the trust boundary — every read forces it as a
  filter; the controller validates `/^[A-Za-z0-9_.\-]{1,64}$/` and rejects
  blank values with 400 before service entry
- Endpoints are `#[NoAdminRequired]` (any authenticated user with access to
  the administration in NC) — IDOR is prevented because the OR
  ObjectService enforces multitenancy / RBAC at the data layer
- No stack traces are returned to the client; controllers catch
  `\Throwable` and return generic Dutch error messages with a 500 +
  structured log

## Testing

| Test class | Scope |
| --- | --- |
| `PayrollCalculatorTest` | Every arithmetic rule against the Belastingdienst oranje-boek + UWV tabellen 2026 |
| `PayrollServiceTest` | OR-backed wiring; scope isolation; balanced journaalpost; BSN masking |
| `PayrollControllerTest` | Endpoint validation; 400/401/404/500 paths; no stack traces |
| `PayrollFragmentTest` | ADR-037 register.d fragment unioning |
| `PayrollSbrConversionServiceTest` | Deterministic ref; payload echo |
| `PayrollJaaropgaveServiceTest` | YTD aggregate; cumulatieven consistency refusal |
| `PayrollApArHandoffServiceTest` | Belastingdienst + UWV split |
| `PayrollUpaHandoffServiceTest` | Per-uitvoerder grouping; scope guard |
| `PayrollWkrHandoffServiceTest` | Loonsom sum; scope guard |
| `PayrollLivLkvHandoffServiceTest` | Eligibility shape; cross-admin guard |
| `PayrollChartOfAccountsMappingTest` | Canonical RGS 3.5 contract |

Run from the worktree (PHP 8.3, Nextcloud-aware bootstrap):

```bash
docker exec nextcloud bash -c 'cd /var/www/html/custom_apps/shillinq && \
    php vendor/bin/phpunit --filter Payroll'
```

## Hydra quality gates

All 16 gates apply:

1. SPDX headers — every PHP file under `lib/` carries `@license` + `@copyright`
2. Forbidden patterns — no `var_dump` / `die` / `error_log` / `print_r`
3. Stub scan — no `In a complete implementation` comments
4. Composer audit — no known CVEs
5. Route auth — every route has `#[NoAdminRequired]` or
   `#[AuthorizedAdminSetting(...)]`
6. Orphan auth — no defined-but-unused auth methods
7. No admin IDOR — `#[NoAdminRequired]` methods carry per-object guards
8. Unsafe auth resolver — no `catch (\Throwable) { return null; }` on auth
9. Semantic auth — annotation matches method body
10. Initial state — no DOM `dataset` reads in Vue
11. Admin router — admin Vue pages NOT in vue-router
12. NC input labels — `<NcSelect>` carries `inputLabel`
13. Modal isolation — modals live in own `.vue` files
14. Route reachability — every controller method has a registered route
15. OR ObjectService API — only `find` / `findAll` / `saveObject`
16. Conflict markers — no `<<<<<<<` / `=======` / `>>>>>>>`
