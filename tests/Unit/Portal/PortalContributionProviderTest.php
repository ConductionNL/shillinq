<?php

/**
 * Unit tests for PortalContributionProvider.
 *
 * Pins shillinq's Wave-1 ADR-046 contribution contract: the dual v2/v1
 * audience declaration (customer + supplier), the fail-closed null for
 * non-matching subjects, both declarative manifests' exact shape (verified
 * UUID scopeFields + bare-name scopeClaims, read-only: no actions, no
 * notifications), and the documented exclusions (ARInvoice, PaymentRequest,
 * dunning, goods receipts, cross-audience leakage). The provider is
 * constructed directly — it is a plain dependency-free class by contract
 * (amendment A1), so no mocks and no container are involved.
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
class PortalContributionProviderTest extends TestCase
{

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
        'subjectRef'   => '00000000-0000-0000-0000-000000000000',
        'audience'     => 'customer',
        'organisation' => '00000000-0000-0000-0000-000000000000',
        'trust'        => 'low',
    ];

    /**
     * A fully server-derived supplier subject.
     *
     * @var array<string, mixed>
     */
    private const SUPPLIER_SUBJECT = [
        'subjectRef'   => '00000000-0000-0000-0000-000000000000',
        'audience'     => 'supplier',
        'organisation' => '00000000-0000-0000-0000-000000000000',
        'trust'        => 'substantial',
    ];

    /**
     * A fully server-derived accountant (external bookkeeper) subject.
     *
     * @var array<string, mixed>
     */
    private const ACCOUNTANT_SUBJECT = [
        'subjectRef'   => '00000000-0000-0000-0000-000000000000',
        'audience'     => 'accountant',
        'organisation' => '00000000-0000-0000-0000-000000000000',
        'trust'        => 'substantial',
    ];

    /**
     * Schemas that must never appear in any manifest (design.md Exclusions).
     *
     * @var array<int, string>
     */
    private const EXCLUDED_SCHEMAS = [
        'ARInvoice',
        'PaymentRequest',
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
    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new PortalContributionProvider();

    }//end setUp()

    /**
     * The class is plain: no interfaces, no parent, no constructor deps.
     *
     * @return void
     */
    public function testClassIsPlainAndDependencyFree(): void
    {
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
    public function testGetAudiencesReturnsCustomerSupplierAccountant(): void
    {
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
    public function testGetAudienceReturnsCustomer(): void
    {
        $this->assertSame('customer', $this->provider->getAudience());
        $this->assertContains($this->provider->getAudience(), $this->provider->getAudiences());

    }//end testGetAudienceReturnsCustomer()

    /**
     * Non-matching subjects get null — fail-closed audience filtering.
     *
     * @return void
     */
    public function testGetContributionReturnsNullForNonMatchingSubjects(): void
    {
        $clientSubject             = self::CUSTOMER_SUBJECT;
        $clientSubject['audience'] = 'client';
        $this->assertNull($this->provider->getContribution($clientSubject));

        $emptyAudience             = self::CUSTOMER_SUBJECT;
        $emptyAudience['audience'] = '';
        $this->assertNull($this->provider->getContribution($emptyAudience));

        $noAudience = self::CUSTOMER_SUBJECT;
        unset($noAudience['audience']);
        $this->assertNull($this->provider->getContribution($noAudience));

        $this->assertNull($this->provider->getContribution([]));

    }//end testGetContributionReturnsNullForNonMatchingSubjects()

    /**
     * The customer manifest carries exactly the five verified collections.
     *
     * @return void
     */
    public function testCustomerManifestShape(): void
    {
        $manifest = $this->provider->getContribution(self::CUSTOMER_SUBJECT);

        $this->assertIsArray($manifest);
        $this->assertSame('Shillinq', $manifest['label']);
        $this->assertSame([], $manifest['actions']);
        $this->assertSame([], $manifest['notifications']);

        $expected = [
            'invoices'        => ['Invoice', 'customerReference'],
            'projectInvoices' => ['BillableInvoice', 'customerId'],
            'quotes'          => ['Quote', 'customerReference'],
            'salesOrders'     => ['SalesOrder', 'customerReference'],
            'contracts'       => ['Contract', 'customerId'],
        ];

        $this->assertSame(array_keys($expected), array_column($manifest['collections'], 'id'));

        foreach ($manifest['collections'] as $collection) {
            [$schema, $scopeField] = $expected[$collection['id']];
            $this->assertSame('shillinq', $collection['register'], $collection['id']);
            $this->assertSame($schema, $collection['schema'], $collection['id']);
            $this->assertSame($scopeField, $collection['scopeField'], $collection['id']);
            $this->assertSame('customerId', $collection['scopeClaim'], $collection['id']);
            $this->assertTrue($collection['listable'], $collection['id']);
            $this->assertNotSame('', $collection['label'], $collection['id']);
        }

    }//end testCustomerManifestShape()

    /**
     * The supplier manifest carries exactly the two verified collections.
     *
     * @return void
     */
    public function testSupplierManifestShape(): void
    {
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
     * ARInvoice/PaymentRequest (non-UUID customer code), dunning (AP-side /
     * PII), and goods receipts (no scalar supplier ref) are documented
     * exclusions; supplier collections must not appear in the customer
     * manifest and vice versa (other parties' data stays out).
     *
     * @return void
     */
    public function testExclusionsAndNoCrossAudienceLeakage(): void
    {
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
    public function testEveryCollectionDeclaresABareNameScopeClaim(): void
    {
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
    public function testAccountantManifestShape(): void
    {
        $manifest = $this->provider->getContribution(self::ACCOUNTANT_SUBJECT);

        $this->assertIsArray($manifest);
        $this->assertSame('Shillinq', $manifest['label']);
        $this->assertSame([], $manifest['actions']);
        $this->assertSame([], $manifest['notifications']);

        $expected = [
            'salesInvoices'    => 'ARInvoice',
            'purchaseInvoices' => 'SupplierInvoice',
            'journalEntries'   => 'JournalEntry',
            'generalLedger'    => 'GLTransaction',
            'trialBalance'     => 'TrialBalance',
            'vatReturns'       => 'VatReturn',
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
    public function testAccountantManifestIsReadOnlyAndAdministrationScoped(): void
    {
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
     * Adding the accountant audience leaves customer/supplier manifests intact.
     *
     * Byte-for-byte snapshot of the existing manifests (task 3.1): no
     * collection, scopeField or scopeClaim of the Wave-1 surfaces changed.
     *
     * @return void
     *
     * @spec openspec/specs/portal-contribution/spec.md
     */
    public function testExistingManifestsAreUnchanged(): void
    {
        $customer = $this->provider->getContribution(self::CUSTOMER_SUBJECT);
        $supplier = $this->provider->getContribution(self::SUPPLIER_SUBJECT);

        $this->assertSame(
            ['invoices', 'projectInvoices', 'quotes', 'salesOrders', 'contracts'],
            array_column($customer['collections'], 'id')
        );
        $this->assertSame(
            ['purchaseOrders', 'supplierInvoices'],
            array_column($supplier['collections'], 'id')
        );

    }//end testExistingManifestsAreUnchanged()
}//end class
