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
	 * The collections a parent sees: their own school contribution invoices
	 * and the payment requests on them (extracurricular-fee-to-shillinq).
	 *
	 * @var array<int, string>
	 */
	private const PARENT_COLLECTIONS = ['salesInvoices', 'paymentRequests'];

	/**
	 * The customer actions a parent keeps: pay. A contribution always has an
	 * invoice, so pay-request (a request without one) is not theirs.
	 *
	 * @var array<int, string>
	 */
	private const PARENT_ACTIONS = ['pay'];

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
			'parent',
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

		$manifest = match ($audience) {
			'customer' => $this->customerManifest(),
			'supplier' => $this->supplierManifest(),
			'accountant' => $this->accountantManifest(),
			'parent' => $this->parentManifest(),
			default => null,
		};
		if ($manifest === null) {
			return null;
		}

		$manifest['pages'] = $this->pagesFor(collections: $manifest['collections'], group: (string)$manifest['label']);

		return $manifest;
	}//end getContribution()

	/**
	 * One page per collection, under the audience's menu group.
	 *
	 * The pages portaliq would make when an app declares none (the list and
	 * the selected row; shillinq declares no create action), so the screens
	 * stay as they were. They are declared because only a declared page
	 * carries a `group`, the heading the site's menu shows above it instead of
	 * the app's name.
	 *
	 * @param array<int, array<string, mixed>> $collections The audience's collections.
	 * @param string                           $group       The menu heading.
	 *
	 * @return array<int, array<string, mixed>> The pages.
	 *
	 * @spec openspec/changes/portal-pages-in-dutch-groups/specs/portal-contribution/spec.md#requirement-every-portal-page-names-its-menu-group-in-dutch
	 */
	private function pagesFor(array $collections, string $group): array {
		$pages = [];
		// Every shillinq collection is listable, so every one gets a page.
		foreach ($collections as $collection) {
			$id = (string)$collection['id'];
			$pages[] = [
				'id' => $id,
				'label' => (string)($collection['label'] ?? $id),
				'group' => $group,
				'blocks' => [
					['type' => 'collection', 'collection' => $id],
					['type' => 'detail', 'collection' => $id],
				],
			];
		}

		return $pages;
	}//end pagesFor()

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
			'label' => 'Bestellingen en facturen',
			'collections' => [
				[
					'id' => 'invoices',
					'register' => 'shillinq',
					'schema' => 'Invoice',
					'scopeField' => 'customerReference',
					'scopeClaim' => 'customerId',
					'label' => 'Mijn facturen',
					'listable' => true,
				],
				[
					'id' => 'projectInvoices',
					'register' => 'shillinq',
					'schema' => 'BillableInvoice',
					'scopeField' => 'customerId',
					'scopeClaim' => 'customerId',
					'label' => 'Mijn projectfacturen',
					'listable' => true,
				],
				[
					'id' => 'quotes',
					'register' => 'shillinq',
					'schema' => 'Quote',
					'scopeField' => 'customerReference',
					'scopeClaim' => 'customerId',
					'label' => 'Mijn offertes',
					'listable' => true,
				],
				[
					'id' => 'salesOrders',
					'register' => 'shillinq',
					'schema' => 'SalesOrder',
					'scopeField' => 'customerReference',
					'scopeClaim' => 'customerId',
					'label' => 'Mijn bestellingen',
					'listable' => true,
				],
				[
					'id' => 'contracts',
					'register' => 'shillinq',
					'schema' => 'RevenueContract',
					'scopeField' => 'customerId',
					'scopeClaim' => 'customerId',
					'label' => 'Mijn contracten',
					'listable' => true,
				],
				[
					'id' => 'salesInvoices',
					'register' => 'shillinq',
					'schema' => 'ARInvoice',
					'scopeField' => 'customerId',
					'scopeClaim' => 'customerMasterId',
					'label' => 'Mijn rekeningen',
					'listable' => true,
					'rowAction' => 'pay',
					// ARInvoice's declared names: the amounts, lines, status and UBL
					// reference were listed as totalAmount, taxAmount, lines, state and
					// ublXml, which carry nothing (REQ-SPPI-007).
					'fields' => [
						'invoiceNumber',
						'invoiceType',
						'invoiceDate',
						'dueDate',
						'currency',
						'grossAmount',
						'vatAmount',
						'invoiceLines',
						'lifecycleState',
						'sourceDocumentUri',
						'ublRef',
						'dunning',
					],
					'columns' => [
						[
							'field' => 'invoiceNumber',
							'label' => 'Factuur',
							'render' => 'text',
						],
						[
							'field' => 'invoiceDate',
							'label' => 'Datum',
							'render' => 'date',
						],
						[
							'field' => 'dueDate',
							'label' => 'Vervaldatum',
							'render' => 'date',
						],
						[
							'field' => 'grossAmount',
							'label' => 'Bedrag',
							'render' => 'currency',
						],
						[
							'field' => 'lifecycleState',
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
							'grossAmount',
							'vatAmount',
							'invoiceLines',
							'lifecycleState',
							'sourceDocumentUri',
							'ublRef',
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
					'label' => 'Mijn facturen betalen',
					'listable' => true,
					// No row action: pay forwards the row id as invoiceId, and a
					// row here is a payment request. Its invoice is paid from
					// salesInvoices (REQ-SPPI-009).
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
							'label' => 'Factuur',
							'render' => 'text',
						],
						[
							'field' => 'amount',
							'label' => 'Bedrag',
							'render' => 'currency',
						],
						[
							'field' => 'state',
							'label' => 'Status',
							'render' => 'badge',
						],
						[
							'field' => 'paymentLink',
							'label' => 'Nu betalen',
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
				// A payment request that stands without an invoice (leges on a
				// case, a dwangsom, a deposit) is scoped by its own customerId,
				// stamped from its debtor, because the invoice join above cannot
				// reach it (REQ-SOPR-005, REQ-SPPI-008). Paid from its row with
				// pay-request, which charges the request's own amount.
				[
					'id' => 'requestPayments',
					'register' => 'shillinq',
					'schema' => 'PaymentRequest',
					'scopeField' => 'customerId',
					'scopeClaim' => 'customerMasterId',
					'label' => 'Mijn betaalverzoeken',
					'listable' => true,
					'rowAction' => 'pay-request',
					'fields' => [
						'description',
						'requestType',
						'amount',
						'currency',
						'state',
						'dueAt',
						'legalBasis',
						'paymentLink',
						'capturedAt',
						'failureReason',
						'confirmationSummary',
					],
					'columns' => [
						[
							'field' => 'description',
							'label' => 'Waarvoor',
							'render' => 'text',
						],
						[
							'field' => 'amount',
							'label' => 'Bedrag',
							'render' => 'currency',
						],
						[
							'field' => 'dueAt',
							'label' => 'Vervaldatum',
							'render' => 'date',
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
							'description',
							'requestType',
							'amount',
							'currency',
							'state',
							'dueAt',
							'legalBasis',
							'paymentLink',
							'capturedAt',
							'failureReason',
							'confirmationSummary',
						],
					],
					'defaultSort' => [
						'field' => 'dueAt',
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
				// The rowField and rowWhen keys make pay a per-row action in
				// portaliq (#805): the portal forwards the proven row id as invoiceId,
				// only for a row whose lifecycleState is one the receiver
				// accepts (PortalPaymentSessionService::PAYABLE_STATES). An
				// ARInvoice row has no `state` (REQ-SPPI-009).
				[
					'id' => 'pay',
					'label' => 'Nu betalen',
					'type' => 'endpoint-forward',
					'endpoint' => '/apps/shillinq/api/portal/payments/initiate',
					'method' => 'POST',
					'minTrust' => 'low',
					'rowField' => 'invoiceId',
					'rowWhen' => [
						'field' => 'lifecycleState',
						'in' => ['issued', 'partially-paid', 'overdue'],
					],
				],
				// The row action of requestPayments: portaliq forwards the
				// proven row id under rowField, only while the request is
				// pending (REQ-SPPI-008).
				[
					'id' => 'pay-request',
					'label' => 'Nu betalen',
					'type' => 'endpoint-forward',
					'endpoint' => '/apps/shillinq/api/portal/payments/initiate',
					'method' => 'POST',
					'minTrust' => 'low',
					'rowField' => 'paymentRequestId',
					'rowWhen' => [
						'field' => 'state',
						'in' => ['pending'],
					],
				],
			],
			'notifications' => [],
		];

	}//end customerManifest()

	/**
	 * The parent manifest: a guardian's own school contribution invoices and
	 * their payment requests, with the `pay` action (REQ-SCON-010).
	 *
	 * Guardians sign in to the portal with audience `parent`, and until this
	 * manifest existed they saw no shillinq invoice at all. It reuses the
	 * customer's AR collections and scoping (the `customerMasterId` claim, which
	 * the contribution raise links) and adds the voluntary notice and the
	 * request's description.
	 * It also carries the `decline` action, "I will not pay", for a voluntary
	 * contribution.
	 *
	 * @return array<string, mixed> The parent manifest.
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-010)
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-013)
	 * @spec openspec/changes/portal-pay-row-action-keys/specs/portal-payment-initiation/spec.md (REQ-SPPI-009)
	 */
	private function parentManifest(): array {
		$manifest = $this->customerManifest();

		$collections = [];
		foreach ($manifest['collections'] as $collection) {
			if (in_array($collection['id'], self::PARENT_COLLECTIONS, true) === true) {
				$collections[] = $this->forParents(collection: $collection);
			}
		}

		$manifest['label'] = 'Schoolbijdragen';
		$manifest['collections'] = $collections;
		$manifest['actions'] = array_values(
			array_filter(
				$manifest['actions'],
				static fn (array $action): bool => in_array($action['id'], self::PARENT_ACTIONS, true)
			)
		);

		// "I will not pay" answers the one reminder of a voluntary contribution
		// and closes it without dunning (REQ-SCON-013). Parents only: the
		// receiver refuses anything but the guardian's own open voluntary
		// contribution, and a business customer has none.
		$manifest['actions'][] = [
			'id' => 'decline',
			// The name the Dutch reminder tells the parent to choose
			// (docudesk-templates.json, the voluntary reminder).
			'label' => 'Ik betaal niet',
			'type' => 'endpoint-forward',
			'endpoint' => '/apps/shillinq/api/portal/contributions/decline',
			'method' => 'POST',
			'fields' => ['invoiceId'],
			'minTrust' => 'low',
		];

		return $manifest;
	}//end parentManifest()

	/**
	 * One customer AR collection, reworded and re-fielded for a parent.
	 *
	 * @param array<string, mixed> $collection The customer collection.
	 *
	 * @return array<string, mixed> The parent collection.
	 */
	private function forParents(array $collection): array {
		$extra = 'description';
		$collection['label'] = 'Mijn bijdragen betalen';
		if ($collection['id'] === 'salesInvoices') {
			$extra = 'invoiceNote';
			$collection['label'] = 'Mijn bijdragen';
			// Portaliq shows this field as a notice on the card and in the
			// confirm step: the voluntary sentence (portaliq #805, REQ-SPPI-009).
			$collection['noticeField'] = 'invoiceNote';
		}

		// The customer collections name ARInvoice's declared fields, so a
		// parent sees the same fields plus one (REQ-SPPI-007).
		$collection['fields'][] = $extra;
		$collection['detail']['fields'][] = $extra;

		return $collection;
	}//end forParents()

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
			'label' => 'Opdrachten en facturen',
			'collections' => [
				[
					'id' => 'purchaseOrders',
					'register' => 'shillinq',
					'schema' => 'PurchaseOrder',
					'scopeField' => 'supplierId',
					'scopeClaim' => 'supplierId',
					'label' => 'Inkooporders',
					'listable' => true,
				],
				[
					'id' => 'supplierInvoices',
					'register' => 'shillinq',
					'schema' => 'SupplierInvoice',
					'scopeField' => 'supplierId',
					'scopeClaim' => 'supplierId',
					'label' => 'Mijn facturen',
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
			'label' => 'Administratie',
			'collections' => [
				[
					'id' => 'salesInvoices',
					'register' => 'shillinq',
					'schema' => 'ARInvoice',
					'scopeField' => 'administrationId',
					'scopeClaim' => 'accountantAdministrationId',
					'label' => 'Verkoopfacturen',
					'listable' => true,
				],
				[
					'id' => 'purchaseInvoices',
					'register' => 'shillinq',
					'schema' => 'SupplierInvoice',
					'scopeField' => 'administrationId',
					'scopeClaim' => 'accountantAdministrationId',
					'label' => 'Inkoopfacturen',
					'listable' => true,
				],
				[
					'id' => 'journalEntries',
					'register' => 'shillinq',
					'schema' => 'JournalEntry',
					'scopeField' => 'administrationId',
					'scopeClaim' => 'accountantAdministrationId',
					'label' => 'Journaalposten',
					'listable' => true,
				],
				[
					'id' => 'generalLedger',
					'register' => 'shillinq',
					'schema' => 'GLTransaction',
					'scopeField' => 'administrationId',
					'scopeClaim' => 'accountantAdministrationId',
					'label' => 'Grootboek',
					'listable' => true,
				],
				[
					'id' => 'trialBalance',
					'register' => 'shillinq',
					'schema' => 'TrialBalance',
					'scopeField' => 'administrationId',
					'scopeClaim' => 'accountantAdministrationId',
					'label' => 'Proefbalans',
					'listable' => true,
				],
				[
					'id' => 'vatReturns',
					'register' => 'shillinq',
					'schema' => 'VatReturn',
					'scopeField' => 'administrationId',
					'scopeClaim' => 'accountantAdministrationId',
					'label' => 'Btw-aangiften',
					'listable' => true,
				],
			],
			'actions' => [],
			'notifications' => [],
		];

	}//end accountantManifest()
}//end class
