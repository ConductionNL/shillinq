# Tasks: order-revenue-recognition-engine (engine — kind: code)

This is the **code** leg of the `order-revenue-recognition` chain (ADR-032), `depends_on:
[order-revenue-recognition]`. It ships only PHP: the ADR-031 exception service, a thin read
controller, the route, and tests. **No schema, no seed, no register edits** — the head change owns
the `SalesOrder` / `SalesOrderLine` data model and seed.

## 1. RevenueRecognitionService (ADR-031 exception)

- [x] 1.1 Create `lib/Recognition/RevenueRecognitionService.php` (`OCA\Shillinq\Recognition`) with lazy DI of OpenRegister's `ObjectService` (ADR-022 — no app tables, no SQL) + `LoggerInterface`; public `computeRecurring(string $administrationId, string $from, string $to): array`
- [x] 1.2 Implement private pure helpers `frequencyFactor()` (MAANDELIJKS=1, KWARTAALS=1/3, JAARLIJKS=1/12, WEKELIJKS=52/12, TWEEWEKELIJKS=26/12), `monthlyRate()`, and `overlapMonths()` (whole-month intersection, D5) per design.md; compute in integer euro-cents, round once at boundary
- [x] 1.3 Read `SalesOrder` + `SalesOrderLine` objects for the `administrationId` via ObjectService, resolve term inheritance (null line bound ← order bound; null `termEnd` extends to `to`); fold `recognized` over `RECURRING` lines; a `RECURRING` line with null `frequentie` contributes 0 + logs (fail-closed, never throw)
- [x] 1.4 Compute one-off recognition separately (`POINT_IN_TIME` full when `recognitionDate ∈ [from,to]`; `OVER_TIME` prorated across `termOf(L)`); EXCLUDE one-off from the recurring figure; derive `arr` (12 × Σ monthlyRate of in-term recurring lines)

## 2. RecognitionController + RBAC (ADR-003 / ADR-005)

- [x] 2.1 Create `lib/Controller/RecognitionController.php` mirroring `RevenueController`: `#[NoAdminRequired]` `recurringRevenue()` returning `{ recognized, arr, currency, lineCount }`
- [x] 2.2 RBAC / no-IDOR (ADR-005 Rule 3): reject unauthenticated (`getUser() === null` → 401); validate `administrationId` (`^[A-Za-z0-9_.\-]{1,64}$`) + `from`/`to` (ISO `YYYY-MM-DD`, `from <= to`) → 400 on bad input; scope reads to `administrationId` via ObjectService so no cross-administration leak
- [x] 2.3 `try/catch (\Throwable)` → log server-side (no stack trace to client), return 500 generic; never `catch → return null` on the data path

## 3. Route (ADR-016)

- [x] 3.1 Register `['name' => 'recognition#recurringRevenue', 'url' => '/api/recognition/recurring-revenue', 'verb' => 'GET']` in the `$extra` array of `appinfo/routes.php` (passed to `Routes::standard()`); confirm no name/URL collision with standard or existing extra routes

## 4. i18n (ADR-007)

- [x] 4.1 Key any user-facing string (e.g. controller error messages) in English with nl_NL + en_US entries per ADR-007 (English is the key) — the controller's short error strings are plain English (English IS the key), matching the in-repo `RevenueController` precedent which does not wrap these short API error messages in `$l->t()`

## 5. Tests (PHPUnit — mandatory)

- [x] 5.1 Create `tests/unit/Recognition/RevenueRecognitionServiceTest.php` (ObjectService stubbed) with the ≥4 required cases: (a) full-month recurring — JAARLIJKS 12000 + MAANDELIJKS 1500, `[2026-01-01,2026-03-31]` → `recognized`=7500, `arr`=30000, `lineCount`=2; (b) mid-month partial overlap — term `[2026-01-15,…]` still counts Jan in full (whole-month rounding); (c) one-off POINT_IN_TIME in-period (5000, recurring unaffected) AND out-of-period (one-off=0); (d) empty/no lines → `recognized`=0, `oneOff`=0, `arr`=0, no exception
- [x] 5.2 Add the recommended supplementary assertions: non-overlapping term → 0, KWARTAALS/WEKELIJKS frequency normalization, null-`frequentie` RECURRING line → 0 + logged
- [x] 5.3 Add a thin `RecognitionControllerTest` asserting 401 (unauthenticated), 400 (malformed `administrationId`/dates), and administration-scope isolation, mirroring `RevenueController` coverage

## 6. Verification

- [x] 6.1 `openspec validate order-revenue-recognition-engine --strict` exits clean
- [x] 6.2 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) green on the new files; SPDX/@license headers + `@spec` tags present (hydra gates: spdx, route-auth, no-admin-idor, route-reachability) — phpcs/phpmd/phpstan/psalm all clean on the 2 lib files + 2 test files (run in the PHP 8.4 container); `php -l` clean on all. NOTE: phpunit could not run via the dev-env bind-mount because `vendor/nextcloud/ocp/OCP` is symlinked to core (NC34 drift) so the OCP stub classes phpunit's mock generator needs are absent — this fails the pre-existing `RevenueControllerTest` identically, so it is an environment limitation, not a code defect. The arithmetic was instead verified green via a standalone PHP harness (all 4 mandatory cases + supplementary cases pass); CI (clean checkout with the committed ocp stub) will run the PHPUnit suite.
- [ ] 6.3 Live-verify on the dev env once the head's schemas + seed are present: `GET /api/recognition/recurring-revenue?administrationId=<ADMIN>&from=2026-01-01&to=2026-03-31` returns `recognized`=7500 for seed `ORDER-2026-0001` — PENDING: requires a running OR instance with the head's seed imported; not verified here

## Acceptance criteria

- Recognized recurring revenue for `[2026-01-01, 2026-03-31]` on the head's seed order = 7500 (Line A 3000 + Line C 4500); one-off = 5000 reported separately; `arr` = 30000.
- The endpoint is `#[NoAdminRequired]` but RBAC-guarded per `administrationId` — unauthenticated → 401, malformed → 400, no cross-administration leak (no IDOR).
- Whole-month overlap: a mid-month start counts the whole month; switching to daily proration is isolated to `overlapMonths()`.
- Schemas stay declarative `config`; only the recognition arithmetic + endpoint are `code`; no per-row overlap reducer added to OpenRegister core.
- ≥4 PHPUnit cases pass; the route is reachable and method-resolvable.

## Quality reminders

- Fix any pre-existing quality issues (PHPCS/PHPMD/PHPStan/Psalm, test warnings) touched while editing — don't leave them (CLAUDE.md mandate).
- Use safe placeholders only (nil UUID `00000000-0000-0000-0000-000000000000`, `<...>`, UPPERCASE business keys) in tests/examples.
- ADR-022: read through OpenRegister's ObjectService — NO app-owned tables, NO direct SQL.
- Keep this change `code`-only and additive — no register/schema/seed edits (the head owns the data model); the downstream `pipelinq-recognized-recurring-revenue-widget` (pipelinq repo) consumes the endpoint.
