# Design: shillinq-mcp-adoption

## Context

ADR-063 makes OpenRegister the single MCP registry: a leaf app declares
`configuration["x-openregister-mcp"]` on a schema and OR derives `{appId}.{schema}.{verb}`
tools (`search|get|create|update|delete`). Exposure is default-OFF.

Shillinq's register at HEAD:

| | count |
|---|---|
| schema entries across all files | **771** |
| unique schema slugs | **482** |
| slugs redefined in >1 file (union-merged) | 187 |
| register fragments (`lib/Settings/register.d/*.json`) | 143 |
| existing MCP code | **none** (`grep -ril mcp lib/` is empty) |

Two constraints dominate the design:

1. **Tool budget is a shared, fleet-wide resource.** Hermiq loads every app's tools into one
   context. A naive shillinq surface (482 slugs x up to 5 verbs) would swamp every other app.
   Specter's research: oversized tool sets cost ~9.5% tool-selection accuracy and 30k+ tokens.
2. **Every shillinq entity is a financial record.** Writes are not a UX question, they are an
   audit and Archiefwet question.

## Goals / Non-Goals

**Goals**
- Expose the smallest read surface that answers the questions an accountant, controller, or
  ZZP-er would actually put to an assistant.
- Leave a durable, categorised record of *why* 470 slugs are OFF, so the surface cannot creep.
- Guarantee every declared `search` filter is a real property (OR's validator hard-fails).

**Non-Goals**
- Any write path. Not deferred — **refused** (D3).
- Any curated `#[McpTool]` service tool (D5).
- Fixing the invoice/VAT slug fragmentation this change surfaced (proposal, Open Questions).

## Decisions

### D1: Declare the dialect in a new last-sorting `register.d` fragment

The 12 curated schemas are defined across at least 8 different files (`Account` alone is
declared in 8; `GLTransaction` in 11). Editing each in place would touch a dozen fragments and
conflict with every concurrent shillinq build.

`SettingsService::deepMergeConfig()` (ADR-037) merges assoc arrays **by key union, recursing
on shared keys**; list arrays concatenate; scalars overwrite. Fragments are `glob()`-ed and
**sorted**, then merged in order. So a fragment containing only

```
components.schemas.<Slug>.configuration["x-openregister-mcp"]
```

unions the dialect onto the fully-merged schema **without restating or clobbering a single
property**. `zzz-mcp-tool-surface.json` sorts after every existing fragment (including
`zz-order-base.json`), so nothing merged later can overwrite it.

*Alternative considered:* edit the 8+ owning fragments in place. Rejected — guaranteed merge
conflicts, and the curation would be scattered across the register instead of readable in one
file.

### D2: The curated ON list — 12 schemas, read-only (2.5% of 482 slugs)

Verbs are `search` + `get` for all twelve. `scope: read`, `readOnlyHint: true`.
Every filter below was cross-checked against the **union of the schema's properties across
every fragment that defines it**.

| # | Schema | Verbs | `search.filters` (all verified real properties) | Why an agent is genuinely asked this |
|---|--------|-------|--------------------------------------------------|--------------------------------------|
| 1 | `ARInvoice` | search, get | `customerId`, `invoiceNumber`, `lifecycleState`, `invoiceType`, `administrationId` | The AR sub-ledger record of account — the only place `amountDue`/`paidAmount`/`dunning` live. "Which invoices are overdue?" is the single most common finance question. |
| 2 | `SupplierInvoice` | search, get | `supplierId`, `invoiceNumber`, `statusCode`, `administrationId` | The AP counterpart. "What do we still owe, and what is stuck unmatched against a PO?" |
| 3 | `CustomerMaster` | search, get | `customerId`, `legalName`, `kvkNumber`, `vatId`, `lifecycleState` | Debtor master. Every invoice question starts with resolving *which customer*. |
| 4 | `Payment` | search, get | `linkedInvoiceId`, `status`, `paymentType`, `paymentDate`, `administrationId` | "Has invoice X been paid?" — the read that closes the loop on #1. |
| 5 | `Account` | search, get | `accountNumber`, `accountType`, `rgsCode`, `lifecycleState`, `administrationId` | The RGS chart of accounts. An agent cannot reason about any posting without resolving the ledger account. |
| 6 | `GLTransaction` | search, get | `transactionNumber`, `state`, `journalType`, `fiscalYearId`, `periodId` | The double-entry posting of record. "What was booked in period 6?" |
| 7 | `UrenRegistratie` | search, get | `personId`, `projectId`, `date`, `taskId`, `administrationId` | Billable hours. "How many hours did I book on project Y this month?" — the top ZZP/consultancy question and the urencriterium input. |
| 8 | `ExpenseClaimEntry` | search, get | `employeeId`, `state`, `claimNumber`, `approvalState` | "Which expense claims are waiting on approval?" Header only — `Receipt` line rows stay OFF. |
| 9 | `Project` | search, get | `customerId`, `state`, `projectNumber`, `code`, `administrationId` | Carries `percentageComplete`, `wipBalance`, `recognisedRevenue` — the RJ 270 / IFRS 15 answers. |
| 10 | `VatReturn` | search, get | `periodYear`, `periodQuarter`, `periodType`, `state`, `administrationId` | "Is the Q2 BTW-aangifte submitted?" Digipoort submission state. |
| 11 | `TrialBalance` | search, get | `fiscalYearId`, `reportDate`, `status`, `administrationId` | "Is the trial balance balanced?" — `isBalanced`, `totalDebits`, `totalCredits`. A closing-check read. |
| 12 | `BankStatement` | search, get | `bankAccountIban`, `statementDate`, `lifecycleState`, `administrationId` | "Did the January statement import, and is it reconciled?" Header only — `BankStatementLine` stays OFF. |

= **24 tools** (12 x 2 reads). **Zero write tools.**

*Deliberately-close calls left OFF:* `JournalEntry` (an authoring construct that materialises
into `GLTransaction` — exposing both would give the agent two near-identical ledger tools and
force a coin-flip), `Receipt` and `BankStatementLine` (line rows reachable via their header),
`Quote` (`Quote`/`SalesOrder`/`Invoice` is a third overlapping document chain; not worth the
budget until the invoice fragmentation below is resolved).

### D3: Every write verb is REFUSED — on every schema

No `create`, `update`, or `delete` is declared anywhere in this change. The refusals, stated
explicitly so a future change has to argue *against* them rather than merely notice a gap:

| Verb x schema | Refused because |
|---|---|
| `create`/`update` on `ARInvoice`, `SupplierInvoice`, `Payment` | Issuing or altering an invoice, or recording a payment, is a **real-money act with a legal counterparty**. An LLM misreading "draft an invoice for Acme" as an instruction to *issue* one produces a document a customer can be dunned against. Not recoverable by "just delete it" — see below. |
| `create`/`update` on `GLTransaction`, `Account` | The ledger is **legally append-only**. `GLTransaction` itself declares `irreversible`, `postingLocked`, `periodLocked`, `retentionUntil`, `integrityVerified` — the schema is telling us the invariant. An agent-authored posting can unbalance the double-entry invariant or land in a locked period, and a mutated chart of accounts silently re-maps every downstream statutory report (RGS → SBR/XBRL → Belastingdienst). |
| `create`/`update` on `VatReturn` | A BTW return is a **statutory filing** (Wet OB 1968 art. 14) submitted to the Belastingdienst via Digipoort. Wrong numbers are a tax offence, not a bug. |
| `create`/`update` on `UrenRegistratie` | Hours drive both billing and the **urencriterium** (the ZZP hour threshold that unlocks fiscal deductions). Fabricated hours are invoice fraud on one side and tax fraud on the other. This is the *least* dangerous financial write in the app and it is still disqualifying. |
| `create`/`update` on `ExpenseClaimEntry`, `Project`, `CustomerMaster`, `BankStatement`, `TrialBalance` | Each feeds the ledger or the statutory reports (expense → GL passthrough; project → revenue recognition; customer → dunning/SEPA; statement → reconciliation; trial balance → close). "Low-stakes master data" is not a category that exists in a bookkeeping system. |
| **`delete` on all 12** | Categorically refused. Financial records are subject to **Archiefwet / 7-year fiscal retention**; `Account` even carries `daysUntilRetention` and `RetentionRule` exists as a schema. Deletion is not an operation an agent may hold, at any confidence level, on any of these entities. |

Two structural arguments reinforce every row:

- **Attribution.** MCP writes are attributed to the **session user**, not the agent principal.
  A bookkeeping audit trail whose entries are indistinguishable from the human's own actions
  is not an audit trail. Reads carry no such requirement, which is exactly why reads are fine
  and writes are not.
- **Undo does not exist here.** In most apps a bad agent write is undone by another write. In
  double-entry bookkeeping the correction is *itself* a posting (a reversal), which is also
  legally significant. There is no clean rollback, so "the agent can fix it" is not available
  as a mitigation.

**What would have to change to revisit this:** agent-principal attribution in the audit trail,
plus a human-in-the-loop approval gate on the write path. Both are OpenRegister/Hermiq
platform capabilities, not shillinq's to build. Until then: read-only.

### D4: Secret- and BSN-bearing schemas are excluded outright, not merely un-curated

App-layer masking (controllers, UI) does **not** protect an OR-derived tool, which reads the
stored object directly. So any schema whose protection lives in a controller must be OFF:

- `WidgetAccessKey` (`apiKeyHash`, `previousApiKeyHash`), `ConfirmationToken` (`tokenString`)
  — credential material. OFF.
- `Employee` / `Werknemer` (`bsn`), `IBAangifte` (`bsn`), `IB47Record` (`ontvangerBSN`) —
  **BSN is special-category PII**; the schemas themselves say "masked in API output" /
  "never logged raw", i.e. protected at the app layer. OFF. This is the load-bearing reason
  the entire payroll/detachering domain is excluded, over and above its low agent value.

**Verified non-issue:** `ARInvoice.cardPan` looked like PCI cardholder data. It is not — the
property exists to assert *absence*: "an Invoice should never include a full card PAN
(EN 16931 BR-51). Must remain absent", and `InvoicingTailChecks.php:256` enforces
`present($o, 'cardPan') === false`. `ARInvoice` is safe to expose.

**Accepted residual:** `CustomerMaster.iban`, `BankStatement.bankAccountIban` and
`Account`-adjacent IBANs are payment-instrument PII, not credentials. They are inherent to the
records an accountant asks about, they remain behind OpenRegister RBAC, and reads are scoped
to the session user's permissions. Accepted and documented rather than silently shipped.

### D5: No `#[McpTool]` service tool

Scanned `lib/Service/` for genuinely non-CRUD behaviour worth curating (`DunningRunService`,
`PayrollService`, `ComplianceService`, `PurchaseOrderService`, `BadoControleprotocolService`,
…). Every candidate is either (a) a **write/posting** action — dunning runs, payment runs,
payroll journal posting — which D3 refuses on exactly the same grounds, or (b) an aggregation
that OpenRegister's declarative calculation/aggregation layer already materialises onto the
read (`Project.percentageComplete`, `Project.wipBalance`, `TrialBalance.isBalanced`), so a
plain `get` already returns it. Adding a service tool would be re-implementing the platform.
**Nothing qualifies. No PHP in this change.**

## The OFF list — 470 unique slugs, grouped

The OFF list is the real deliverable. Grouped by category with the rationale that disqualifies
the whole group, so future additions must argue against a category rather than sneak in a name.
(Counts are approximate groupings over the 482 unique slugs; the 12 ON are excluded.)

| ~Count | Category | Representative slugs | Why the whole category is OFF |
|---|---|---|---|
| ~40 | **Line / child rows of an exposed header** | `GLLine`, `InvoiceLine`, `VATLine`, `SalesOrderLine`, `QuoteLine`, `BankStatementLine`, `GoodsReceiptLine`, `BillableInvoiceLine`, `RepaymentInstallment` | Reachable through the header's `get` (they are the header's own arrays/relations). Exposing them doubles the tool count and gives the agent a second, worse way to ask the same question. |
| ~29 | **Public-sector / BBV / gemeente-GR accounting** | `BBVProgramma`, `Taakveld`, `Paragraaf`, `Begrotingswijziging`, `EconomischeCategorie`, `GRDeelnemer`, `GRVerdeelsleutel`, `WaterschapHeffingPosting`, `ProvincialeFondsPosting`, `Reserve`, `Voorziening` | A different tenant and a different persona (municipal controller under BBV), not the accountant/ZZP-er this surface targets. Enabling it would nearly double the tool count for an audience that is not the one asking. Revisit as a separate, deliberately-scoped change if a BBV tenant ever wants it. |
| ~40 | **Statutory reporting artifacts and their line items** | `XBRLMapping`, `XBRLTaxonomy`, `SBRDocumentType`, `SisaReport`, `SisaRegelingIndicator`, `Iv3Export`, `IcpStatement`, `IcpOpgaaf`, `OssPayment`, `CbCR`/Pillar-2, `BcfClaim`, `Innovatiebox*`, `WBSO*`, `IbAangifteExport` | These are **outputs**, not questions. They are generated from the ledger, filed, and archived. An agent asks "is the BTW filed?" (→ `VatReturn`, exposed) — it does not browse XBRL taxonomy rows. High schema count, near-zero conversational value. |
| ~25 | **Config / reference data / rules / templates / rates** | `VatTariff`, `MileageRate`, `PerDiemRate`, `RateCard`, `RateCardVersion`, `MatchingRule`, `BankingRule`, `AllocationRule`, `RetentionRule`, `ClosingEntryTemplate`, `ChartOfAccountsMapping`, `KorThreshold`, `AccountingFramework` | Administrator configuration, not business records. Nobody asks an assistant "what is the mileage rate row" — and if they did, the answer belongs in docs. Also the highest-leverage *write* target in the register (mutate a `MatchingRule` and you silently re-map reconciliation), so it stays entirely out of the agent's reach. |
| ~28 | **Consolidation / close / period-end machinery** | `ClosingEntry`, `EliminationJournal`, `ConsolidatedReport`, `ConsolidationGroup`, `DepreciationSchedule`, `RetainedEarnings`, `WipBalance`, `FxRate`, `CurrencyBalance`, `Cashflow*` (8 slugs), `IntercompanyJournalEntry` | Internal machinery of the close process. The *answers* it produces are already exposed (`TrialBalance`, `GLTransaction`, `Project.wipBalance`). The machinery itself is bookkeeping-internal plumbing. |
| ~16 | **Payroll / HR / detachering** | `Employee`, `Werknemer`, `LoonStrook`, `LoonPeriode`, `Loonjournaalpost`, `PensionPlan`, `IB47Record` | **BSN special-category PII** whose masking lives in the app layer and would be bypassed by a derived tool (D4). Disqualifying on privacy grounds alone, independent of tool budget. |
| ~16 | **Bookings / appointments sub-app** | `Appointment`, `Booking`, `BookingCancellation`, `Resource`, `DepositPayment`, `WidgetAccessKey`, `ConfirmationToken` | A different product surface bolted into the same register (scheduling, not bookkeeping). Its financial output already reaches the ledger through the exposed invoice/payment path. Also holds credential material (`apiKeyHash`, `tokenString`) — D4. |
| ~13 | **Inventory / SKU / warehouse** | `InventoryStock`, `InventoryStockTransfer`, `InventoryReorderRule`, `Barcode`, `GoodsReceipt`, `InventoryCount` | Operational stock control, not invoicing/bookkeeping. Its value reaches the ledger as postings, which are exposed. |
| ~14 | **Audit / compliance / evidence trails** | `ComplianceAuditTrail`, `AuditTrail`, `AuditDocument`, `AuditFinding`, `AuditSample`, `ManagementLetter`, `AssuranceEngagement`, `RekenkamerAuditPack`, `BadoControleprotocol` | The audit trail is evidence *about* the system. Exposing it to the agent whose actions it records is backwards, and it carries actor/PII detail with no offsetting conversational value. |
| ~9 | **Integration plumbing / batches / logs / retries** | `ImportBatch`, `TimeIntakeBatch`, `TimelineDeadLetter`, `TimelinePublishRetryEntry`, `AdministrationMigration`, `MobileScannerSyncBatch`, `WBSOExportLog`, `AlertLog` | Machine-to-machine transport records. Operator/debug surface, never a business question. |
| ~240 | **Long-tail domain slices** (ESG/CSRD, EU projects & subsidies, treasury & trust ledgers, SEPA direct-debit, dunning, fixed assets, purchase-order 3-way match, multi-currency, VPB/corporate tax, pension IAS-19, provisions/claims, KOR, insolvency, …) | Each is a narrow regulatory or vertical slice added by its own OpenSpec change (143 fragments, one per change). Individually plausible, collectively fatal: enabling even a fraction re-creates the tool explosion this curation exists to prevent. The bar for promotion is not "is this schema real?" but **"is this a question a human would actually put to an assistant, that the 12 exposed schemas cannot already answer?"** For every slug here, today, the answer is no. |

## Risks / Trade-offs

- **[The `ARInvoice` bet is wrong and the tool returns an empty list]** → Live-verify (see
  tasks) that customer invoices materialise in `ARInvoice`. Failure mode is an empty result,
  not corruption, and the fix is a one-line fragment edit.
- **[12 schemas is too few and users hit a wall]** → Deliberate. Growing a curated surface on
  evidence of a real unanswered question is cheap; shrinking a bloated one after Hermiq's
  prompts depend on it is not. Bias to fewer, per ADR-063.
- **[A future change adds the dialect to a schema in its own fragment and quietly re-inflates
  the surface]** → The categorised OFF list above is the countermeasure: it names the bar. The
  spec's REQ-MCP-003 makes read-only + curation normative, so a write verb or a bulk enable is
  a spec violation, not a judgement call.
- **[IBAN/PII in LLM context]** → Accepted (D4), bounded by OR RBAC, documented here.

## Migration Plan

1. Land the fragment. Dialect is **inert** until OR's derived-tool engine is deployed — no
   behaviour change on any existing instance.
2. On deploy, the fragment signature changes the register version → OR's version-gated
   `importFromApp` re-imports → `McpAnnotationValidator` validates the dialect. A bad filter
   **fails the import**, which is the intended hard gate.
3. Verify the 24 derived tools appear and **zero** shillinq write tools do.
4. **Rollback:** delete the fragment, re-import. Purely additive; nothing else is touched.

## Open Questions

1. Which register actually holds customer invoice rows — `Invoice` or `ARInvoice`? (Blocking
   for tool usefulness, not for landing the spec.)
2. `VatReturn` vs `VATReturn` — two case-differing schemas in one register. Modelling defect;
   raise separately.
3. Should OpenRegister offer field-level projection/redaction in the dialect? It would let
   BSN- and IBAN-bearing schemas be exposed safely and would relax D4 considerably. Today the
   dialect has no such key, so exclusion is the only lever.
4. Agent-principal attribution in the audit trail — the precondition for ever reconsidering D3.
</content>
