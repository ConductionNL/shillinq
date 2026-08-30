<?php

/**
 * Shillinq Portal Contribution Provider
 *
 * Shillinq's Wave-1 contribution to the shared Portaliq external portal
 * (hydra ADR-046 + 2026-07-06 amendment, contribution contract v2). Portaliq
 * — the one shared external portal for people WITHOUT Nextcloud accounts —
 * discovers this class by convention FQCN
 * (`OCA\{App}\Portal\PortalContributionProvider`) and duck-types it via
 * method_exists(), never instanceof. Therefore this class is deliberately
 * PLAIN: no portaliq imports, no `implements` clause, no info.xml dependency,
 * no constructor dependencies. Without portaliq installed it is inert and the
 * app behaves exactly as before (amendment A1).
 *
 * Shillinq contributes to TWO audiences: `customer` (invoices, quotes,
 * orders, contracts) and `supplier` (purchase orders, supplier invoices).
 * Every collection is read-only and scoped by a verified UUID domain
 * reference on the row, matched against a shillinq-namespaced claim
 * (claims.shillinq.customerId / claims.shillinq.supplierId /
 * claims.shillinq.customerMasterId) — never a Nextcloud user id (externals
 * have no NC account by premise). The verified scoping map is documented in
 * the change design.md.
 *
 * Wave 2 (customer-invoice-portal-wave2) lifts the Wave-1 customer-side
 * exclusion of ARInvoice and PaymentRequest: debtors can now see and pay their
 * own AR invoices. AR invoices are scoped by ARInvoice.customerId — the
 * CustomerMaster OBJECT UUID (base schema: `format: uuid`, `$ref:
 * CustomerMaster`, `inversedBy: invoices`) — matched against the new
 * claims.shillinq.customerMasterId claim. Because the CustomerMaster object
 * UUID is globally unique (unlike the per-administration customer CODE the
 * Wave-1 design conservatively read from a stale fragment description), this
 * cannot collide across administrations. PaymentRequest carries no customer
 * property, so it is reached through a one-hop reverse `via` join through
 * ARInvoice.customerId (contract v2.2, `match: 'scopeField'`): a payment
 * request is visible only when its ARInvoice belongs to the subject's
 * CustomerMaster. Dunning is surfaced read-only as the ARInvoice.dunning
 * summary group (no separate DunningRun collection — that carries recipient
 * PII). Still excluded: goods receipts, AP/vendor dunning; see design.md.
 *
 * portal-payment-initiation adds the write leg: a `pay` `endpoint-forward`
 * action (contract v2, A6) forwarded server-to-server to
 * `PortalPaymentInitiationController`, referenced as a `rowAction` on the
 * open-invoice rows of `salesInvoices` / `paymentRequests` so portaliq renders
 * a per-row pay-now control. The action itself is pure data (no I/O) — the
 * imperative ownership + PSP work lives entirely in the receiver
 * (`PortalPaymentSessionService`), keeping this provider plain/dependency-free
 * (ADR-046 A1). `confirmationSummary` (written by `PaymentReconciliationService`
 * on settlement) joins the `paymentRequests` field whitelist so the debtor
 * reads a plain-language receipt through the existing read-only collection.
 *
 * @category Portal
 * @package  OCA\Shillinq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-contribution/tasks.md#task-1
 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-006)
 */

declare(strict_types=1);

namespace OCA\Shillinq\Portal;

/**
 * Declares what an external portal subject may see in Shillinq's books.
 *
 * The contribution is a declarative manifest (pure data — no I/O, no
 * callbacks): per audience, the OpenRegister collections portaliq may read on
 * the subject's behalf, each scoped by a UUID domain-reference property
 * (`scopeField`) matched against a shillinq claim (`scopeClaim`, bare name =
 * own app namespace). All subject identity (subjectRef, audience,
 * organisation, trust) is derived server-side by portaliq's auth edge and
 * MUST never be trusted from the client (ADR-005). Rows also carry
 * administrationId — shillinq-internal multi-administration tenancy — which
 * is NOT a portal boundary and is never used as a scopeField here; portaliq's
 * per-row organisation check only applies when rows carry `organisation`,
 * which shillinq rows do not.
 *
 * No create/endpoint actions ship in Wave 1 (read-only manifest); collections
 * stay at default (low) trust until the eHerkenning broker lands, after which
 * the financial collections move to minTrust `substantial` (Wave 2).
 *
 * portal-payment-initiation adds exactly one `endpoint-forward` action (`pay`)
 * on the `customer` manifest, referenced as a `rowAction` on the open-invoice
 * rows of `salesInvoices` / `paymentRequests` (REQ-SPPI-006). `supplier` /
 * `accountant` manifests keep empty `actions` — the write leg is customer-only.
 *
 * @spec openspec/changes/portal-contribution/tasks.md#task-1
 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-006)
 *
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Pre-existing debt (issue
 *     #506): deferred pending a dedicated refactor.
 */
class PortalContributionProvider {
	/**
	 * The audiences this provider contributes to (contract v2, preferred).
	 *
	 * The registry probes for this method first; the audience vocabulary is
	 * an open string set. Shillinq serves the parties on both sides of its
	 * ledgers: customers (AR side) and suppliers (AP side).
	 *
	 * @return array<int, string> The audience identifiers.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function getAudiences(): array {
		return [
			'customer',
			'supplier',
			'accountant',
		];

	}//end getAudiences()

	/**
	 * The single audience this provider contributes to (contract v1 fallback).
	 *
	 * Kept alongside getAudiences() so the provider also works against a v1
	 * registry that predates multi-audience support. A v1 registry serves a
	 * single audience, so the primary (customer) surface is declared; the
	 * supplier surface then only exists on contract-v2 registries.
	 *
	 * @return string The audience identifier.
	 *
	 * @spec openspec/changes/portal-contribution/tasks.md#task-2
	 */
	public function getAudience(): string {
		return 'customer';
	}//end getAudience()

	/**
	 * Build the declarative portal manifest for one resolved subject.
	 *
	 * The subject array is server-derived by portaliq (subjectRef UUID,
	 * audience, organisation, trust level low|substantial|high). Branches on
	 * `$subject['audience']` and returns null for any audience this app does
	 * not serve — fail-closed; the registry already filters by audience, but
	 * a provider must not rely on that. The customer manifest never contains
	 * supplier collections and vice versa (other parties' data stays out).
	 *
	 * @param array<string, mixed> $subject The resolved portal subject.
	 *
	 * @return array<string, mixed>|null The manifest, or null when not contributing.
	 *
	 * @spec openspec/changes/portal-contribution/tasks.md#task-2
	 */
	public function getContribution(array $subject): ?array {
		$audience = $subject['audience'] ?? '';

		if ($audience === 'customer') {
			return $this->customerManifest();
		}

		if ($audience === 'supplier') {
			return $this->supplierManifest();
		}

		if ($audience === 'accountant') {
			return $this->accountantManifest();
		}

		return null;
	}//end getContribution()

	/**
	 * The read-only customer (AR-side) manifest.
	 *
	 * The first five collections (Q2C: Invoice / BillableInvoice / Quote /
	 * SalesOrder / Contract) are scoped by a verified UUID domain reference to
	 * the customer record (Nextcloud contact / AR customer master) matched
	 * against claims.shillinq.customerId — unchanged from Wave 1.
	 *
	 * Wave 2 adds the AR sub-ledger surface the Wave-1 slice deferred:
	 *
	 * - `salesInvoices` (schema ARInvoice) — scoped by `customerId`, the
	 *   CustomerMaster OBJECT UUID (base schema declares it `format: uuid`,
	 *   `$ref: CustomerMaster`, `inversedBy: invoices`), matched against the
	 *   new bare-name claim `customerMasterId`. The CustomerMaster object UUID
	 *   is globally unique, so — unlike the per-administration customer CODE —
	 *   it cannot leak across administrations. A `fields` whitelist projects
	 *   the row to the customer-safe subset (invoice header, lines, artefact
	 *   URIs, the dunning summary group) and deliberately drops internal
	 *   accounting fields (glTransactionId, matchedBankLineId, the writeOff
	 *   bad-debt group, administrationId) so a debtor never sees them.
	 * - `paymentRequests` (schema PaymentRequest) — carries no customer
	 *   property, so it is reached through a one-hop reverse `via` join
	 *   through ARInvoice.customerId (contract v2.2, `match: 'scopeField'`):
	 *   the join collects the subject's own ARInvoice ids, then keeps only
	 *   PaymentRequests whose `invoiceReference` is in that set. `confirmationSummary`
	 *   (portal-payment-initiation, REQ-SPPI-005) joins the whitelist so a
	 *   settled request shows the debtor a plain-language receipt. The computed
	 *   `paymentLink` (OpenConnector hosted payment UI, short-lived signed
	 *   token; null unless state=pending) is the pay-now surface — clicking it
	 *   settles the invoice through the existing capture → matchPaid flow.
	 *
	 * Dunning is surfaced read-only via the ARInvoice.dunning summary group
	 * (currentStage / nextDunningDate / incassokosten / rente); the DunningRun
	 * schema itself stays excluded (recipient PII + rendered letters).
	 *
	 * portal-payment-initiation (REQ-SPPI-006) adds the write leg: a single
	 * `pay` `endpoint-forward` action, referenced as a `rowAction` on
	 * `salesInvoices` and `paymentRequests` so portaliq renders a per-row
	 * pay-now control. The action is pure declarative data (no I/O, no state)
	 * exactly like every other key on this manifest; the imperative ownership
	 * + PSP work is the receiver's job (`PortalPaymentInitiationController` /
	 * `PortalPaymentSessionService`).
	 *
	 * @return array<string, mixed> The customer manifest.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	private function customerManifest(): array {
		return [
			'label' => 'Shillinq',
			'collections' => [
				[
					'id' => 'invoices',
					'register' => 'shillinq',
					'schema' => 'Invoice',
					'scopeField' => 'customerReference',
					'scopeClaim' => 'customerId',
					'label' => 'My invoices',
					'listable' => true,
				],
				[
					'id' => 'projectInvoices',
					'register' => 'shillinq',
					'schema' => 'BillableInvoice',
					'scopeField' => 'customerId',
					'scopeClaim' => 'customerId',
					'label' => 'My project invoices',
					'listable' => true,
				],
				[
					'id' => 'quotes',
					'register' => 'shillinq',
					'schema' => 'Quote',
					'scopeField' => 'customerReference',
					'scopeClaim' => 'customerId',
					'label' => 'My quotes',
					'listable' => true,
				],
				[
					'id' => 'salesOrders',
					'register' => 'shillinq',
					'schema' => 'SalesOrder',
					'scopeField' => 'customerReference',
					'scopeClaim' => 'customerId',
					'label' => 'My orders',
					'listable' => true,
				],
				[
					'id' => 'contracts',
					'register' => 'shillinq',
					'schema' => 'RevenueContract',
					'scopeField' => 'customerId',
					'scopeClaim' => 'customerId',
					'label' => 'My contracts',
					'listable' => true,
				],
				[
					'id' => 'salesInvoices',
					'register' => 'shillinq',
					'schema' => 'ARInvoice',
					'scopeField' => 'customerId',
					'scopeClaim' => 'customerMasterId',
					'label' => 'My invoices',
					'listable' => true,
					'rowAction' => 'pay',
					'fields' => [
						'invoiceNumber',
						'invoiceType',
						'invoiceDate',
						'dueDate',
						'currency',
						'totalAmount',
						'taxAmount',
						'lines',
						'state',
						'sourceDocumentUri',
						'ublXml',
						'dunning',
					],
					'columns' => [
						[
							'field' => 'invoiceNumber',
							'label' => 'Invoice',
							'render' => 'text',
						],
						[
							'field' => 'invoiceDate',
							'label' => 'Date',
							'render' => 'date',
						],
						[
							'field' => 'dueDate',
							'label' => 'Due',
							'render' => 'date',
						],
						[
							'field' => 'totalAmount',
							'label' => 'Amount',
							'render' => 'currency',
						],
						[
							'field' => 'state',
							'label' => 'Status',
							'render' => 'badge',
						],
					],
					'detail' => [
						'layout' => 'card',
						'fields' => [
							'invoiceNumber',
							'invoiceType',
							'invoiceDate',
							'dueDate',
							'currency',
							'totalAmount',
							'taxAmount',
							'lines',
							'state',
							'sourceDocumentUri',
							'ublXml',
							'dunning',
						],
					],
					'defaultSort' => [
						'field' => 'invoiceDate',
						'direction' => 'desc',
					],
				],
				[
					'id' => 'paymentRequests',
					'register' => 'shillinq',
					'schema' => 'PaymentRequest',
					'scopeField' => 'invoiceReference',
					'scopeClaim' => 'customerMasterId',
					'via' => [
						'register' => 'shillinq',
						'schema' => 'ARInvoice',
						'scopeField' => 'customerId',
						'targetField' => 'id',
						'match' => 'scopeField',
					],
					'label' => 'Pay my invoices',
					'listable' => true,
					'rowAction' => 'pay',
					'fields' => [
						'invoiceReference',
						'amount',
						'currency',
						'paymentGateway',
						'state',
						'paymentLink',
						'expiresAt',
						'capturedAt',
						'failureReason',
						'confirmationSummary',
					],
					'columns' => [
						[
							'field' => 'invoiceReference',
							'label' => 'Invoice',
							'render' => 'text',
						],
						[
							'field' => 'amount',
							'label' => 'Amount',
							'render' => 'currency',
						],
						[
							'field' => 'state',
							'label' => 'Status',
							'render' => 'badge',
						],
						[
							'field' => 'paymentLink',
							'label' => 'Pay now',
							'render' => 'link',
						],
					],
					'detail' => [
						'layout' => 'card',
						'fields' => [
							'invoiceReference',
							'amount',
							'currency',
							'paymentGateway',
							'state',
							'paymentLink',
							'expiresAt',
							'capturedAt',
							'failureReason',
							'confirmationSummary',
						],
					],
					'defaultSort' => [
						'field' => 'expiresAt',
						'direction' => 'desc',
					],
				],
			],
			// Portal-payment-initiation REQ-SPPI-006: exactly one endpoint-forward
			// action, forwarded server-to-server by portaliq to
			// PortalPaymentInitiationController (route declared in
			// appinfo/routes.php). minTrust tracks the AR surface — salesInvoices /
			// paymentRequests above declare no explicit minTrust (default 'low'), so
			// the action does not gate any tighter than the data it acts on; bump
			// both together if/when the AR surface moves to 'substantial' (Wave 2
			// note above).
			'actions' => [
				[
					'id' => 'pay',
					'label' => 'Pay now',
					'type' => 'endpoint-forward',
					'endpoint' => '/apps/shillinq/api/portal/payments/initiate',
					'method' => 'POST',
					'minTrust' => 'low',
				],
			],
			'notifications' => [],
		];

	}//end customerManifest()

	/**
	 * The read-only supplier (AP-side) manifest.
	 *
	 * Both scopeFields are verified UUID references to the Payee (vendor)
	 * record, matched against claims.shillinq.supplierId. GoodsReceipt is
	 * deliberately absent (it carries no supplier reference at all) and
	 * GoodsReceiptNote is deferred (its only supplier linkage is the poIds
	 * ARRAY of PurchaseOrder FKs — beyond the one-hop scalar via join);
	 * suppliers see match outcomes via SupplierInvoice.statusCode instead.
	 *
	 * @return array<string, mixed> The supplier manifest.
	 *
	 * @spec openspec/changes/portal-contribution/tasks.md#task-2
	 */
	private function supplierManifest(): array {
		return [
			'label' => 'Shillinq',
			'collections' => [
				[
					'id' => 'purchaseOrders',
					'register' => 'shillinq',
					'schema' => 'PurchaseOrder',
					'scopeField' => 'supplierId',
					'scopeClaim' => 'supplierId',
					'label' => 'Purchase orders',
					'listable' => true,
				],
				[
					'id' => 'supplierInvoices',
					'register' => 'shillinq',
					'schema' => 'SupplierInvoice',
					'scopeField' => 'supplierId',
					'scopeClaim' => 'supplierId',
					'label' => 'My invoices',
					'listable' => true,
				],
			],
			'actions' => [],
			'notifications' => [],
		];

	}//end supplierManifest()

	/**
	 * The read-only accountant (external bookkeeper) review manifest.
	 *
	 * Unlike the customer/supplier surfaces — scoped by a party UUID on the
	 * row — an external accountant is authorised over a whole administration,
	 * so every collection scopes by the row's `administrationId` tenancy key
	 * matched against claims.shillinq.accountantAdministrationId (a multi-value
	 * claim: an accountant authorised for two client administrations carries
	 * both UUIDs, and portaliq's claim matching returns only those rows).
	 *
	 * The collections are the financial-review surfaces an external boekhouder
	 * opens to review and file the books: sales invoices (AR), purchase
	 * invoices (AP), the journal, the general ledger, the trial balance and
	 * the VAT returns. Every schema below was verified to declare an
	 * `administrationId` property so the scope resolves to a real field.
	 *
	 * DEVIATION (task 2.3 / REQ-SPC-011 no-dead-scope rule): the spec lists
	 * `financialStatements` (schema FinancialStatement) as a candidate
	 * collection, but no FinancialStatement definition in lib/Settings declares
	 * an `administrationId` property (its three fragments —
	 * checks-national-reporting{,-tail}.json, checks-ifrsusgaap.json — carry
	 * only reporting fields). Emitting it would be a dead/fail-open scope and a
	 * cross-administration-leakage risk, which REQ-SPC-011 forbids, so it is
	 * intentionally omitted until FinancialStatement carries administrationId.
	 * Adding it back is then pure manifest data (no contract change).
	 *
	 * Read-only this ADR-046 Wave: actions and notifications are empty. Write
	 * accountant collaboration (posting adjustments, correction requests) is a
	 * deliberately deferred later wave.
	 *
	 * @return array<string, mixed> The accountant manifest.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	private function accountantManifest(): array {
		return [
			'label' => 'Shillinq',
			'collections' => [
				[
					'id' => 'salesInvoices',
					'register' => 'shillinq',
					'schema' => 'ARInvoice',
					'scopeField' => 'administrationId',
					'scopeClaim' => 'accountantAdministrationId',
					'label' => 'Sales invoices',
					'listable' => true,
				],
				[
					'id' => 'purchaseInvoices',
					'register' => 'shillinq',
					'schema' => 'SupplierInvoice',
					'scopeField' => 'administrationId',
					'scopeClaim' => 'accountantAdministrationId',
					'label' => 'Purchase invoices',
					'listable' => true,
				],
				[
					'id' => 'journalEntries',
					'register' => 'shillinq',
					'schema' => 'JournalEntry',
					'scopeField' => 'administrationId',
					'scopeClaim' => 'accountantAdministrationId',
					'label' => 'Journal entries',
					'listable' => true,
				],
				[
					'id' => 'generalLedger',
					'register' => 'shillinq',
					'schema' => 'GLTransaction',
					'scopeField' => 'administrationId',
					'scopeClaim' => 'accountantAdministrationId',
					'label' => 'General ledger',
					'listable' => true,
				],
				[
					'id' => 'trialBalance',
					'register' => 'shillinq',
					'schema' => 'TrialBalance',
					'scopeField' => 'administrationId',
					'scopeClaim' => 'accountantAdministrationId',
					'label' => 'Trial balance',
					'listable' => true,
				],
				[
					'id' => 'vatReturns',
					'register' => 'shillinq',
					'schema' => 'VatReturn',
					'scopeField' => 'administrationId',
					'scopeClaim' => 'accountantAdministrationId',
					'label' => 'VAT returns',
					'listable' => true,
				],
			],
			'actions' => [],
			'notifications' => [],
		];

	}//end accountantManifest()
}//end class
