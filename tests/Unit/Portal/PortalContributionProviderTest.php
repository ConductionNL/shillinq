<?php

/**
 * Unit tests for PortalContributionProvider.
 *
 * Pins shillinq's ADR-046 contribution contract: the dual v2/v1 audience
 * declaration (customer + supplier + accountant), the fail-closed null for
 * non-matching subjects, every declarative manifest's exact shape (verified
 * UUID scopeFields + bare-name scopeClaims, read-only: no actions, no
 * notifications), and the documented exclusions (dunning, goods receipts,
 * cross-audience leakage). The provider is constructed directly — it is a
 * plain dependency-free class by contract (amendment A1), so no mocks and no
 * container are involved.
 *
 * Wave 2 (customer-invoice-portal-wave2) lifts the Wave-1 customer-side
 * exclusion of ARInvoice and PaymentRequest: this suite additionally pins that
 * the customer sees their own AR invoices (scoped by the CustomerMaster object
 * UUID against claims.shillinq.customerMasterId) and their payment requests
 * (reached only through the one-hop reverse `via` join on ARInvoice.customerId)
 * and — the security headline — that neither surface is ever scoped by
 * administrationId or any client-supplied id, so another debtor's invoice is
 * unreachable (IDOR).
 *
 * portal-payment-initiation (REQ-SPPI-006) adds the write leg: this suite
 * additionally pins the exact shape of the `pay` endpoint-forward action (id,
 * type, instance-local relative endpoint, method, minTrust), that both
 * `salesInvoices` and `paymentRequests` reference it as a `rowAction`, that
 * `confirmationSummary` joins the `paymentRequests` field whitelist, and that
 * the `supplier` / `accountant` manifests keep empty `actions`.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Portal
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
 * @spec openspec/changes/portal-contribution/tasks.md#task-3
 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-006)
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Portal;

use OCA\Shillinq\Portal\PortalContributionProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PortalContributionProvider.
 *
 * @spec openspec/changes/portal-contribution/tasks.md#task-3
 */
class PortalContributionProviderTest extends TestCase {

	/**
	 * The provider under test.
	 *
	 * @var PortalContributionProvider
	 */
	private PortalContributionProvider $provider;

	/**
	 * A fully server-derived customer subject, as portaliq's auth edge builds it.
	 *
	 * @var array<string, mixed>
	 */
	private const CUSTOMER_SUBJECT = [
		'subjectRef' => '00000000-0000-0000-0000-000000000000',
		'audience' => 'customer',
		'organisation' => '00000000-0000-0000-0000-000000000000',
		'trust' => 'low',
	];

	/**
	 * A fully server-derived supplier subject.
	 *
	 * @var array<string, mixed>
	 */
	private const SUPPLIER_SUBJECT = [
		'subjectRef' => '00000000-0000-0000-0000-000000000000',
		'audience' => 'supplier',
		'organisation' => '00000000-0000-0000-0000-000000000000',
		'trust' => 'substantial',
	];

	/**
	 * A fully server-derived accountant (external bookkeeper) subject.
	 *
	 * @var array<string, mixed>
	 */
	private const ACCOUNTANT_SUBJECT = [
		'subjectRef' => '00000000-0000-0000-0000-000000000000',
		'audience' => 'accountant',
		'organisation' => '00000000-0000-0000-0000-000000000000',
		'trust' => 'substantial',
	];

	/**
	 * Schemas that must never appear in any manifest (design.md Exclusions).
	 *
	 * ARInvoice and PaymentRequest are NO LONGER excluded from the customer
	 * manifest (Wave 2 surfaces them, UUID-scoped). Dunning-run and
	 * goods-receipt schemas stay excluded (recipient PII / no scalar scope).
	 *
	 * @var array<int, string>
	 */
	private const EXCLUDED_SCHEMAS = [
		'DunningNotice',
		'DunningRecord',
		'DunningRun',
		'GoodsReceipt',
		'GoodsReceiptNote',
	];

	/**
	 * Set up the provider — direct construction, no dependencies by contract.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->provider = new PortalContributionProvider();

	}//end setUp()

	/**
	 * The class is plain: no interfaces, no parent, no constructor deps.
	 *
	 * @return void
	 */
	public function testClassIsPlainAndDependencyFree(): void {
		$reflection = new \ReflectionClass(PortalContributionProvider::class);

		$this->assertSame([], $reflection->getInterfaceNames());
		$this->assertFalse($reflection->getParentClass());
		$this->assertNull($reflection->getConstructor());

	}//end testClassIsPlainAndDependencyFree()

	/**
	 * getAudiences() (v2) returns exactly ['customer', 'supplier', 'accountant'].
	 *
	 * @return void
	 */
	public function testGetAudiencesReturnsCustomerSupplierAccountant(): void {
		$this->assertSame(
			['customer', 'supplier', 'accountant'],
			$this->provider->getAudiences()
		);

	}//end testGetAudiencesReturnsCustomerSupplierAccountant()

	/**
	 * getAudience() (v1 fallback) returns the primary audience, contained in v2.
	 *
	 * @return void
	 */
	public function testGetAudienceReturnsCustomer(): void {
		$this->assertSame('customer', $this->provider->getAudience());
		$this->assertContains($this->provider->getAudience(), $this->provider->getAudiences());

	}//end testGetAudienceReturnsCustomer()

	/**
	 * Non-matching subjects get null — fail-closed audience filtering.
	 *
	 * @return void
	 */
	public function testGetContributionReturnsNullForNonMatchingSubjects(): void {
		$clientSubject = self::CUSTOMER_SUBJECT;
		$clientSubject['audience'] = 'client';
		$this->assertNull($this->provider->getContribution($clientSubject));

		$emptyAudience = self::CUSTOMER_SUBJECT;
		$emptyAudience['audience'] = '';
		$this->assertNull($this->provider->getContribution($emptyAudience));

		$noAudience = self::CUSTOMER_SUBJECT;
		unset($noAudience['audience']);
		$this->assertNull($this->provider->getContribution($noAudience));

		$this->assertNull($this->provider->getContribution([]));

	}//end testGetContributionReturnsNullForNonMatchingSubjects()

	/**
	 * The customer manifest carries the five Wave-1 collections plus the two
	 * Wave-2 AR-side surfaces (salesInvoices, paymentRequests).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function testCustomerManifestShape(): void {
		$manifest = $this->provider->getContribution(self::CUSTOMER_SUBJECT);

		$this->assertIsArray($manifest);
		$this->assertSame('Shillinq', $manifest['label']);
		$this->assertCount(1, $manifest['actions'], 'exactly one pay action (REQ-SPPI-006)');
		$this->assertSame('pay', $manifest['actions'][0]['id']);
		$this->assertSame([], $manifest['notifications']);

		// [schema, scopeField, scopeClaim] per collection id.
		$expected = [
			'invoices' => ['Invoice', 'customerReference', 'customerId'],
			'projectInvoices' => ['BillableInvoice', 'customerId', 'customerId'],
			'quotes' => ['Quote', 'customerReference', 'customerId'],
			'salesOrders' => ['SalesOrder', 'customerReference', 'customerId'],
			'contracts' => ['RevenueContract', 'customerId', 'customerId'],
			'salesInvoices' => ['ARInvoice', 'customerId', 'customerMasterId'],
			'paymentRequests' => ['PaymentRequest', 'invoiceReference', 'customerMasterId'],
		];

		$this->assertSame(array_keys($expected), array_column($manifest['collections'], 'id'));

		foreach ($manifest['collections'] as $collection) {
			[$schema, $scopeField, $scopeClaim] = $expected[$collection['id']];
			$this->assertSame('shillinq', $collection['register'], $collection['id']);
			$this->assertSame($schema, $collection['schema'], $collection['id']);
			$this->assertSame($scopeField, $collection['scopeField'], $collection['id']);
			$this->assertSame($scopeClaim, $collection['scopeClaim'], $collection['id']);
			$this->assertTrue($collection['listable'], $collection['id']);
			$this->assertNotSame('', $collection['label'], $collection['id']);
		}

	}//end testCustomerManifestShape()

	/**
	 * Wave 2: the ARInvoice + PaymentRequest surfaces are UUID-scoped.
	 *
	 * salesInvoices scopes ARInvoice by `customerId` (the CustomerMaster object
	 * UUID) against the `customerMasterId` claim, with a customer-safe `fields`
	 * projection that hides internal accounting fields. paymentRequests reaches
	 * PaymentRequest ONLY through a one-hop reverse `via` join on
	 * ARInvoice.customerId, and exposes the computed `paymentLink` (pay-now).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function testCustomerManifestArInvoiceAndPaymentRequestWiring(): void {
		$manifest = $this->provider->getContribution(self::CUSTOMER_SUBJECT);
		$collections = [];
		foreach ($manifest['collections'] as $collection) {
			$collections[$collection['id']] = $collection;
		}

		// AR invoices — CustomerMaster-UUID scoped, projected to safe fields.
		$ar = $collections['salesInvoices'];
		$this->assertSame('ARInvoice', $ar['schema']);
		$this->assertSame('customerId', $ar['scopeField']);
		$this->assertSame('customerMasterId', $ar['scopeClaim']);
		$this->assertContains('invoiceNumber', $ar['fields']);
		$this->assertContains('dunning', $ar['fields'], 'dunning summary is surfaced read-only');
		// Internal accounting fields must never be projected to a debtor.
		foreach (['glTransactionId', 'matchedBankLineId', 'writeOff', 'administrationId', 'settlementReference'] as $internal) {
			$this->assertNotContains($internal, $ar['fields'], $internal);
		}

		// Payment requests — reached ONLY via the reverse join through
		// ARInvoice.customerId; the pay-now link is exposed.
		$pr = $collections['paymentRequests'];
		$this->assertSame('PaymentRequest', $pr['schema']);
		$this->assertSame('invoiceReference', $pr['scopeField']);
		$this->assertSame('customerMasterId', $pr['scopeClaim']);
		$this->assertSame(
			[
				'register' => 'shillinq',
				'schema' => 'ARInvoice',
				'scopeField' => 'customerId',
				'targetField' => 'id',
				'match' => 'scopeField',
			],
			$pr['via'],
			'PaymentRequest must be scoped by a one-hop reverse join through ARInvoice.customerId'
		);
		$this->assertContains('paymentLink', $pr['fields'], 'pay-now link is surfaced');
		$this->assertContains('confirmationSummary', $pr['fields'], 'REQ-SPPI-005 settlement receipt is surfaced');

	}//end testCustomerManifestArInvoiceAndPaymentRequestWiring()

	/**
	 * REQ-SPPI-006: the customer manifest declares exactly one `pay`
	 * endpoint-forward action, referenced as a `rowAction` on the open-invoice
	 * rows of both AR-side collections.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-006)
	 */
	public function testCustomerManifestPayActionAndRowAction(): void {
		$manifest = $this->provider->getContribution(self::CUSTOMER_SUBJECT);
		$collections = [];
		foreach ($manifest['collections'] as $collection) {
			$collections[$collection['id']] = $collection;
		}

		$this->assertCount(1, $manifest['actions']);
		$action = $manifest['actions'][0];
		$this->assertSame('pay', $action['id']);
		$this->assertSame('endpoint-forward', $action['type']);
		$this->assertSame('POST', $action['method']);
		$this->assertArrayHasKey('minTrust', $action);
		$this->assertNotSame('', $action['label']);

		// Instance-local relative endpoint under the declared path — no
		// scheme, no host, no `..` (SSRF hardening, REQ-SPPI-006).
		$endpoint = $action['endpoint'];
		$this->assertStringStartsWith('/apps/shillinq/api/portal/payments/', $endpoint);
		$this->assertStringStartsWith('/', $endpoint);
		$this->assertStringNotContainsString('://', $endpoint);
		$this->assertStringNotContainsString('..', $endpoint);

		// Both AR-side collections reference the action as a rowAction.
		$this->assertSame('pay', $collections['salesInvoices']['rowAction']);
		$this->assertSame('pay', $collections['paymentRequests']['rowAction']);

	}//end testCustomerManifestPayActionAndRowAction()

	/**
	 * SECURITY HEADLINE (mandatory, non-negotiable): another debtor's invoice
	 * is unreachable — no customer collection is ever scoped by
	 * administrationId (cross-tenant) or an unscoped/client-supplied id (IDOR).
	 *
	 * The provider is pure data; portaliq's PortalObjectReader enforces the
	 * scope at runtime (per-row verifyScope + reverse-via membership, tested in
	 * portaliq). This test pins the declaration that FEEDS that enforcement:
	 *  - every customer collection carries a bare-name scopeClaim (server-issued
	 *    value, never client input);
	 *  - the AR surfaces scope by the globally-unique CustomerMaster object UUID
	 *    (`customerId` → `customerMasterId`), NOT the per-administration
	 *    customer CODE and NOT `administrationId` (which would leak every
	 *    debtor in the administration);
	 *  - PaymentRequest — which has no customer field — is reachable ONLY
	 *    through the reverse `via` join whose join scopeField is
	 *    ARInvoice.customerId, so a payment request whose invoice belongs to a
	 *    different CustomerMaster can never enter the result set.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function testCustomerInvoiceIdorBoundary(): void {
		$manifest = $this->provider->getContribution(self::CUSTOMER_SUBJECT);

		foreach ($manifest['collections'] as $collection) {
			// No portal collection may scope by the administration tenancy key —
			// that would surface EVERY debtor's rows in the administration.
			$this->assertNotSame('administrationId', $collection['scopeField'], $collection['id']);
			// Server-issued claim on every collection: the scope value can never
			// be supplied by the client (the classic IDOR vector).
			$this->assertArrayHasKey('scopeClaim', $collection, $collection['id']);
			$this->assertNotSame('', $collection['scopeClaim'], $collection['id']);
		}

		$byId = [];
		foreach ($manifest['collections'] as $collection) {
			$byId[$collection['id']] = $collection;
		}

		// AR invoices: UUID scope, not the guessable per-administration code.
		$this->assertSame('customerId', $byId['salesInvoices']['scopeField']);
		$this->assertSame('customerMasterId', $byId['salesInvoices']['scopeClaim']);
		$this->assertArrayNotHasKey('via', $byId['salesInvoices']);

		// PaymentRequest: NOT directly scoped by administrationId or a raw id —
		// only via the join through the subject's own ARInvoices.
		$pr = $byId['paymentRequests'];
		$this->assertArrayHasKey('via', $pr);
		$this->assertSame('ARInvoice', $pr['via']['schema']);
		$this->assertSame('customerId', $pr['via']['scopeField']);
		$this->assertSame('scopeField', $pr['via']['match']);
		$this->assertNotSame('administrationId', $pr['scopeField']);

	}//end testCustomerInvoiceIdorBoundary()

	/**
	 * The supplier manifest carries exactly the two verified collections.
	 *
	 * @return void
	 */
	public function testSupplierManifestShape(): void {
		$manifest = $this->provider->getContribution(self::SUPPLIER_SUBJECT);

		$this->assertIsArray($manifest);
		$this->assertSame('Shillinq', $manifest['label']);
		$this->assertSame([], $manifest['actions']);
		$this->assertSame([], $manifest['notifications']);

		$this->assertSame(
			['purchaseOrders', 'supplierInvoices'],
			array_column($manifest['collections'], 'id')
		);
		$this->assertSame(
			['PurchaseOrder', 'SupplierInvoice'],
			array_column($manifest['collections'], 'schema')
		);

		foreach ($manifest['collections'] as $collection) {
			$this->assertSame('shillinq', $collection['register'], $collection['id']);
			$this->assertSame('supplierId', $collection['scopeField'], $collection['id']);
			$this->assertSame('supplierId', $collection['scopeClaim'], $collection['id']);
			$this->assertTrue($collection['listable'], $collection['id']);
			$this->assertNotSame('', $collection['label'], $collection['id']);
		}

	}//end testSupplierManifestShape()

	/**
	 * Excluded schemas never surface and audiences never cross-leak.
	 *
	 * Dunning-run (AP-side / recipient PII) and goods receipts (no scalar
	 * supplier ref) remain documented exclusions in both audiences; supplier
	 * collections must not appear in the customer manifest and vice versa
	 * (other parties' data stays out). ARInvoice/PaymentRequest are now
	 * customer-legitimate (Wave 2) so they left the exclusion set.
	 *
	 * @return void
	 */
	public function testExclusionsAndNoCrossAudienceLeakage(): void {
		$customer = $this->provider->getContribution(self::CUSTOMER_SUBJECT);
		$supplier = $this->provider->getContribution(self::SUPPLIER_SUBJECT);

		$customerSchemas = array_column($customer['collections'], 'schema');
		$supplierSchemas = array_column($supplier['collections'], 'schema');

		foreach (self::EXCLUDED_SCHEMAS as $schema) {
			$this->assertNotContains($schema, $customerSchemas, $schema);
			$this->assertNotContains($schema, $supplierSchemas, $schema);
		}

		$this->assertSame([], array_intersect($customerSchemas, $supplierSchemas));
		$this->assertNotContains('PurchaseOrder', $customerSchemas);
		$this->assertNotContains('Invoice', $supplierSchemas);

	}//end testExclusionsAndNoCrossAudienceLeakage()

	/**
	 * Every collection scopes by a claim, never by subjectRef default alone.
	 *
	 * Portal subjects are people, not ledger parties: subjectRef is the
	 * person's own UUID, so each collection must declare a bare-name
	 * scopeClaim resolving in shillinq's claim namespace.
	 *
	 * @return void
	 */
	public function testEveryCollectionDeclaresABareNameScopeClaim(): void {
		foreach ([self::CUSTOMER_SUBJECT, self::SUPPLIER_SUBJECT] as $subject) {
			$manifest = $this->provider->getContribution($subject);
			foreach ($manifest['collections'] as $collection) {
				$this->assertArrayHasKey('scopeClaim', $collection, $collection['id']);
				$this->assertStringNotContainsString('.', $collection['scopeClaim'], $collection['id']);
			}
		}

	}//end testEveryCollectionDeclaresABareNameScopeClaim()

	/**
	 * The accountant manifest carries the administration-scoped review surfaces.
	 *
	 * Every collection scopes by the row's administrationId tenancy key against
	 * the accountantAdministrationId claim, register shillinq, listable. The
	 * spec-listed financialStatements is intentionally omitted because no
	 * FinancialStatement schema declares administrationId (no-dead-scope rule,
	 * REQ-SPC-011 / task 2.3).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function testAccountantManifestShape(): void {
		$manifest = $this->provider->getContribution(self::ACCOUNTANT_SUBJECT);

		$this->assertIsArray($manifest);
		$this->assertSame('Shillinq', $manifest['label']);
		$this->assertSame([], $manifest['actions']);
		$this->assertSame([], $manifest['notifications']);

		$expected = [
			'salesInvoices' => 'ARInvoice',
			'purchaseInvoices' => 'SupplierInvoice',
			'journalEntries' => 'JournalEntry',
			'generalLedger' => 'GLTransaction',
			'trialBalance' => 'TrialBalance',
			'vatReturns' => 'VatReturn',
		];

		$this->assertSame(array_keys($expected), array_column($manifest['collections'], 'id'));

		foreach ($manifest['collections'] as $collection) {
			$this->assertSame($expected[$collection['id']], $collection['schema'], $collection['id']);
			$this->assertSame('shillinq', $collection['register'], $collection['id']);
			$this->assertSame('administrationId', $collection['scopeField'], $collection['id']);
			$this->assertSame('accountantAdministrationId', $collection['scopeClaim'], $collection['id']);
			$this->assertTrue($collection['listable'], $collection['id']);
			$this->assertNotSame('', $collection['label'], $collection['id']);
		}

	}//end testAccountantManifestShape()

	/**
	 * The accountant surface is read-only and never scopes by a party ref.
	 *
	 * actions/notifications stay empty this wave, and no collection may scope
	 * by customerReference / customerId / supplierId (party scopes), which
	 * would leak across administrations.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function testAccountantManifestIsReadOnlyAndAdministrationScoped(): void {
		$manifest = $this->provider->getContribution(self::ACCOUNTANT_SUBJECT);

		$this->assertSame([], $manifest['actions']);
		$this->assertSame([], $manifest['notifications']);

		foreach ($manifest['collections'] as $collection) {
			$this->assertNotContains(
				$collection['scopeField'],
				['customerReference', 'customerId', 'supplierId'],
				$collection['id']
			);
		}

		// FinancialStatement is omitted (no administrationId) — no dead scope.
		$this->assertNotContains(
			'FinancialStatement',
			array_column($manifest['collections'], 'schema')
		);

	}//end testAccountantManifestIsReadOnlyAndAdministrationScoped()

	/**
	 * The Wave-1 collections are untouched; Wave 2 only appends.
	 *
	 * The five original customer collections keep their exact ids/scope wiring
	 * and stay first (Wave 2 appends salesInvoices + paymentRequests after
	 * them); the supplier and accountant manifests are byte-for-byte unchanged.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function testExistingManifestsAreUnchanged(): void {
		$customer = $this->provider->getContribution(self::CUSTOMER_SUBJECT);
		$supplier = $this->provider->getContribution(self::SUPPLIER_SUBJECT);

		// Wave-1 customer collections stay first and in order; the two Wave-2
		// AR surfaces are appended after them.
		$this->assertSame(
			['invoices', 'projectInvoices', 'quotes', 'salesOrders', 'contracts', 'salesInvoices', 'paymentRequests'],
			array_column($customer['collections'], 'id')
		);

		foreach (array_slice($customer['collections'], 0, 5) as $collection) {
			$this->assertSame('customerId', $collection['scopeClaim'], $collection['id']);
		}

		$this->assertSame(
			['purchaseOrders', 'supplierInvoices'],
			array_column($supplier['collections'], 'id')
		);

	}//end testExistingManifestsAreUnchanged()

	/**
	 * The EFFECTIVE register config — monolith + every register.d fragment,
	 * as `SettingsService::loadRegisterConfigData()` assembles it.
	 *
	 * @return array<string,mixed> The merged config.
	 */
	private function effectiveRegister(): array {
		$decode = static function (string $path): array {
			$data = json_decode((string)file_get_contents($path), true);
			return is_array($data) === true ? $data : [];
		};

		$deepMerge = static function (array $base, array $overlay) use (&$deepMerge): array {
			if (array_is_list($base) === true && array_is_list($overlay) === true) {
				return array_merge($base, $overlay);
			}

			foreach ($overlay as $key => $value) {
				if (isset($base[$key]) === true && is_array($base[$key]) === true && is_array($value) === true) {
					$base[$key] = $deepMerge($base[$key], $value);
					continue;
				}

				$base[$key] = $value;
			}

			return $base;
		};

		$merged = $decode(__DIR__ . '/../../../lib/Settings/shillinq_register.json');
		$fragments = glob(__DIR__ . '/../../../lib/Settings/register.d/*.json');
		$this->assertNotEmpty($fragments, 'No register.d fragments — the merge would be a no-op.');
		sort($fragments);

		foreach ($fragments as $fragment) {
			$merged = $deepMerge($merged, $decode($fragment));
		}

		return $merged;
	}//end effectiveRegister()

	/**
	 * THE ISOLATION INVARIANT: every customer-manifest scopeField must resolve
	 * to a schema property that is DECLARED as an object reference — never to
	 * a per-administration natural key.
	 *
	 * This is the link that was missing, and its absence is why a real defect
	 * survived. `testCustomerInvoiceIdorBoundary` asserts the manifest NAMES
	 * `customerId`, and its comment claims "UUID scope, not the guessable
	 * per-administration code" — but a manifest is only a declaration of which
	 * field to scope on. What the field actually IS lives in the register, and
	 * no test read the register. For months `ARInvoice.customerId` carried no
	 * `format`, no `$ref`, and a description pointing at
	 * `CustomerMaster.customerId` (the customer CODE, e.g. "DEB-0001"), while
	 * PortalContributionProvider's cross-administration isolation argument
	 * rested on the value being the globally-unique CustomerMaster OBJECT
	 * UUID. Both halves looked fine in isolation; nothing joined them.
	 *
	 * A code-shaped scope value is not merely weaker — `CustomerMaster.customerId`
	 * is unique only WITHIN an administration, so two administrations can each
	 * hold a "DEB-0001". Scoping a debtor's invoices on that field is a
	 * cross-tenant collision by construction.
	 *
	 * NOT ASSERTED HERE, AND DELIBERATELY NAMED RATHER THAN PASSED OVER:
	 * `PaymentRequest.invoiceReference` — the OUTER field of the
	 * paymentRequests join — is declared `{"type": "string"}` with the
	 * description "FK to the ARInvoice (UUID or slug)" and a slug-shaped
	 * example, carrying no `format` and no `$ref`. The join collects ARInvoice
	 * object IDS (`targetField: 'id'`, asserted below), so a value holding a
	 * slug does not compare equal to an id. Narrowing that field to a
	 * `$ref: ARInvoice` object reference is a data-model decision with seed
	 * impact and is filed separately — asserting it here would make this test
	 * red for a defect it did not find and cannot fix.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function testEveryCustomerScopeFieldIsADeclaredObjectReference(): void {
		$schemas = $this->effectiveRegister()['components']['schemas'];
		$manifest = $this->provider->getContribution(self::CUSTOMER_SUBJECT);

		$checked = 0;
		foreach ($manifest['collections'] as $collection) {
			// The isolation hinge is the field the SUBJECT'S IDENTITY is
			// compared against. For a directly-scoped collection that is its
			// own scopeField; for one reached through a reverse `via` join it
			// is the join's scopeField on the hopped-through schema (the
			// collection's own scopeField is then a join key between two
			// rows, not a subject identity — see the note below).
			if (isset($collection['via']) === true) {
				$targets = [[$collection['via']['schema'], $collection['via']['scopeField']]];
			} else {
				$targets = [[$collection['schema'], $collection['scopeField']]];
			}

			foreach ($targets as [$schemaName, $scopeField]) {
				$this->assertArrayHasKey($schemaName, $schemas, 'Manifest names an undeclared schema: ' . $schemaName);
				$properties = ($schemas[$schemaName]['properties'] ?? []);

				// A scopeField that is not a property at all cannot be
				// filtered on: the scope would silently match nothing, or
				// everything, depending on the consumer.
				$this->assertArrayHasKey(
					$scopeField,
					$properties,
					$schemaName . '.' . $scopeField . ' is the portal scope field but is not a declared property.'
				);

				$property = $properties[$scopeField];

				// Only the customerMasterId-claimed collections carry the
				// object-reference guarantee; the Wave-1 collections scope on
				// a verified contact reference under a different claim.
				$claim = ($collection['scopeClaim'] ?? '');
				if ($claim !== 'customerMasterId') {
					continue;
				}

				$this->assertSame(
					'uuid',
					($property['format'] ?? null),
					$schemaName . '.' . $scopeField . ' scopes a portal collection on the customerMasterId claim, '
					. 'so it must be declared format:uuid — a per-administration code is not globally unique.'
				);
				$this->assertSame(
					'CustomerMaster',
					($property['$ref'] ?? null),
					$schemaName . '.' . $scopeField . ' must $ref CustomerMaster so the scope value is the object UUID, '
					. 'not CustomerMaster.customerId (the per-administration customer code).'
				);

				$checked++;
			}
		}

		// Guard against the assertion silently covering nothing if the
		// manifest is refactored — the failure mode this whole test exists
		// to catch.
		$this->assertGreaterThanOrEqual(
			2,
			$checked,
			'Expected the salesInvoices scope and the paymentRequests via-join scope to be checked.'
		);

	}//end testEveryCustomerScopeFieldIsADeclaredObjectReference()

	/**
	 * The reverse `via` join must land on the object identity, not on a
	 * schema property — otherwise a PaymentRequest could be matched to an
	 * invoice by a value that repeats across administrations.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function testViaJoinMatchesOnObjectIdentity(): void {
		$manifest = $this->provider->getContribution(self::CUSTOMER_SUBJECT);

		$joins = 0;
		foreach ($manifest['collections'] as $collection) {
			if (isset($collection['via']) === false) {
				continue;
			}

			$this->assertSame(
				'id',
				($collection['via']['targetField'] ?? null),
				$collection['id'] . ': the via join must collect object ids, so the outer scope compares identities.'
			);
			$joins++;
		}

		$this->assertSame(1, $joins, 'Expected exactly the paymentRequests via join.');

	}//end testViaJoinMatchesOnObjectIdentity()
}//end class
