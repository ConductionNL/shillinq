<?php

/**
 * Unit tests for the expense-reimbursement-or-passthrough register fragment.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/expense-reimbursement-or-passthrough/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the expense-reimbursement-or-passthrough fragment is valid JSON,
 * extends Receipt/ExpenseClaimEntry additively, declares the two new master-data
 * schemas with seed objects, and merges onto the monolith without dropping any
 * pre-existing schema or object (ADR-037).
 */
final class ExpenseReimbursementOrPassthroughFragmentTest extends TestCase
{

    /**
     * Absolute path to the change fragment.
     *
     * @var string
     */
    private string $fragmentPath = __DIR__.'/../../../lib/Settings/register.d/expense-reimbursement-or-passthrough.json';

    /**
     * Absolute path to the monolith register file.
     *
     * @var string
     */
    private string $registerPath = __DIR__.'/../../../lib/Settings/shillinq_register.json';

    /**
     * Invoke the private static SettingsService::deepMergeConfig().
     *
     * @param array<mixed> $base    Base config.
     * @param array<mixed> $overlay Fragment.
     *
     * @return array<mixed> Merged config.
     */
    private function merge(array $base, array $overlay): array
    {
        $m = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
        $m->setAccessible(true);
        return $m->invoke(null, $base, $overlay);

    }//end merge()

    /**
     * Decode the fragment file.
     *
     * @return array<mixed>
     */
    private function fragment(): array
    {
        return json_decode((string) file_get_contents($this->fragmentPath), true);

    }//end fragment()

    /**
     * The fragment file is present and valid JSON with the expected components.
     *
     * @return void
     */
    public function testFragmentIsValidJson(): void
    {
        self::assertFileExists($this->fragmentPath);
        $data = $this->fragment();
        self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
        self::assertIsArray($data);
        self::assertArrayHasKey('schemas', $data['components']);

    }//end testFragmentIsValidJson()

    /**
     * The fragment declares the two new master-data schemas.
     *
     * @return void
     */
    public function testFragmentDeclaresNewSchemas(): void
    {
        $schemas = $this->fragment()['components']['schemas'];
        self::assertArrayHasKey('ReimbursementPolicy', $schemas);
        self::assertArrayHasKey('PassThroughMarkupRule', $schemas);

    }//end testFragmentDeclaresNewSchemas()

    /**
     * The Receipt extension adds the settlement fields without redeclaring the
     * base required set (it carries only new properties + calculations).
     *
     * @return void
     */
    public function testReceiptExtensionAddsSettlementFields(): void
    {
        $receipt = $this->fragment()['components']['schemas']['Receipt'];
        $props   = $receipt['properties'];

        foreach (['settlementMode', 'linkedCustomerId', 'markupRuleId', 'markupRateApplied', 'markupAmountCalculated', 'passthroughDebitAccountCode'] as $field) {
            self::assertArrayHasKey($field, $props, "Receipt must add $field");
        }

        // settlementMode is an enum but NOT forced required (keeps existing seeds valid).
        self::assertSame(['reimbursable', 'pass-through'], $props['settlementMode']['enum']);
        self::assertArrayNotHasKey('required', $receipt, 'Receipt extension must not redeclare required[]');

        // The markup calculation is declared with a guard fallback (ADR-031).
        self::assertArrayHasKey('x-openregister-calculations', $receipt);
        self::assertSame(
            'OCA\\Shillinq\\Lifecycle\\SettlementGuard::computeMarkupAmount',
            $receipt['x-openregister-calculations']['markupAmountCalculated']['guard']
        );

    }//end testReceiptExtensionAddsSettlementFields()

    /**
     * The ExpenseClaimEntry extension adds aggregates + the dual-path settlement
     * contract keyed under a new x-extension (so the post action is not duplicated).
     *
     * @return void
     */
    public function testClaimExtensionAddsAggregatesAndSettlementContract(): void
    {
        $claim = $this->fragment()['components']['schemas']['ExpenseClaimEntry'];
        $props = $claim['properties'];

        foreach (['settlementMode', 'totalReimbursableAmount', 'totalPassThroughAmount', 'passThroughCustomerIds', 'glReimbursableTransactionId', 'glPassThroughTransactionId'] as $field) {
            self::assertArrayHasKey($field, $props, "ExpenseClaimEntry must add $field");
        }

        self::assertArrayHasKey('x-openregister-settlement', $claim);
        $settlement = $claim['x-openregister-settlement'];
        self::assertSame('settlementMode', $settlement['modeField']);
        self::assertSame(
            'OCA\\Shillinq\\Lifecycle\\SettlementGuard::requireSettlementClassification',
            $settlement['submitGuard']
        );
        self::assertSame('ExpenseClaimReimbursementNotification', $settlement['reimbursable']['notification']['event']);
        self::assertTrue($settlement['passThrough']['perCustomer']);

        // The fragment must NOT redeclare the lifecycle (would duplicate the post action list on merge).
        self::assertArrayNotHasKey('x-openregister-lifecycle', $claim);

    }//end testClaimExtensionAddsAggregatesAndSettlementContract()

    /**
     * Seed objects: two policies and four markup rules.
     *
     * @return void
     */
    public function testFragmentSeedsPolicyAndRuleObjects(): void
    {
        $objects = $this->fragment()['objects'];
        self::assertCount(6, $objects);

        $policies = array_filter($objects, static fn(array $o): bool => $o['@self']['schema'] === 'ReimbursementPolicy');
        $rules    = array_filter($objects, static fn(array $o): bool => $o['@self']['schema'] === 'PassThroughMarkupRule');
        self::assertCount(2, $policies);
        self::assertCount(4, $rules);

    }//end testFragmentSeedsPolicyAndRuleObjects()

    /**
     * Merging the fragment onto the monolith adds exactly the two new schemas,
     * extends Receipt/ExpenseClaimEntry in place, appends six seed objects, and
     * drops nothing pre-existing (ADR-037).
     *
     * @return void
     */
    public function testFragmentMergesAdditivelyOntoMonolith(): void
    {
        $base = json_decode((string) file_get_contents($this->registerPath), true);
        $frag = $this->fragment();

        $schemaCountBefore  = count($base['components']['schemas']);
        $objectCountBefore  = count($base['objects']);
        $receiptPropsBefore = count($base['components']['schemas']['Receipt']['properties']);

        $merged  = $this->merge($base, $frag);
        $schemas = $merged['components']['schemas'];

        // Exactly two NEW schemas (Receipt + ExpenseClaimEntry already existed).
        self::assertCount($schemaCountBefore + 2, $schemas, 'Exactly two schemas must be added');
        self::assertArrayHasKey('ReimbursementPolicy', $schemas);
        self::assertArrayHasKey('PassThroughMarkupRule', $schemas);

        // Pre-existing schemas survive.
        foreach (array_keys($base['components']['schemas']) as $name) {
            self::assertArrayHasKey($name, $schemas, "$name must survive merge");
        }

        // Receipt was extended in place (props grew, base props survive).
        $mergedReceipt = $schemas['Receipt']['properties'];
        self::assertGreaterThan($receiptPropsBefore, count($mergedReceipt));
        self::assertArrayHasKey('receiptNumber', $mergedReceipt, 'Base Receipt prop must survive');
        self::assertArrayHasKey('settlementMode', $mergedReceipt, 'New Receipt prop must be present');

        // ExpenseClaimEntry keeps its original lifecycle (single post action).
        $mergedClaim = $schemas['ExpenseClaimEntry'];
        self::assertArrayHasKey('x-openregister-lifecycle', $mergedClaim);
        $postActions = $mergedClaim['x-openregister-lifecycle']['transitions']['post']['actions'];
        self::assertCount(1, $postActions, 'Post action list must not be duplicated by the merge');

        // Six seed objects appended.
        self::assertCount($objectCountBefore + 6, $merged['objects'], 'Six seed objects must be appended');

    }//end testFragmentMergesAdditivelyOntoMonolith()
}//end class
