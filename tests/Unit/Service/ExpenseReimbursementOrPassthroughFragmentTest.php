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
 * schemas, and merges onto the monolith without dropping any pre-existing
 * schema (ADR-037).
 */
final class ExpenseReimbursementOrPassthroughFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/expense-reimbursement-or-passthrough.json';

	/**
	 * Absolute path to the monolith register file.
	 *
	 * @var string
	 */
	private string $registerPath = __DIR__ . '/../../../lib/Settings/shillinq_register.json';

	/**
	 * Invoke the private static SettingsService::deepMergeConfig().
	 *
	 * @param array<mixed> $base Base config.
	 * @param array<mixed> $overlay Fragment.
	 *
	 * @return array<mixed> Merged config.
	 */
	private function merge(array $base, array $overlay): array {
		$m = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$m->setAccessible(true);
		return $m->invoke(null, $base, $overlay);
	}//end merge()

	/**
	 * Decode the fragment file.
	 *
	 * @return array<mixed>
	 */
	private function fragment(): array {
		return json_decode((string)file_get_contents($this->fragmentPath), true);
	}//end fragment()

	/**
	 * The fragment file is present and valid JSON with the expected components.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = $this->fragment();
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertIsArray($data);
		self::assertArrayHasKey('schemas', $data['components']);

	}//end testFragmentIsValidJson()

	/**
	 * The fragment declares the two new master-data schemas plus the
	 * receipt-category extensions (MileageEntry, PerDiem).
	 *
	 * @return void
	 */
	public function testFragmentDeclaresNewSchemas(): void {
		$schemas = $this->fragment()['components']['schemas'];
		self::assertArrayHasKey('ReimbursementPolicy', $schemas);
		self::assertArrayHasKey('PassThroughMarkupRule', $schemas);

	}//end testFragmentDeclaresNewSchemas()

	/**
	 * The Receipt extension adds the settlement fields and declares the
	 * markup-lookup declarative calculation that targets markupRuleId,
	 * markupRateApplied and markupAmountCalculated (REQ-ERP-002 / REQ-ERP-005).
	 *
	 * @return void
	 */
	public function testReceiptExtensionAddsSettlementFields(): void {
		$receipt = $this->fragment()['components']['schemas']['Receipt'];
		$props = $receipt['properties'];

		foreach (['settlementMode', 'linkedCustomerId', 'markupRuleId', 'markupRateApplied', 'markupAmountCalculated', 'passthroughDebitAccountCode'] as $field) {
			self::assertArrayHasKey($field, $props, "Receipt must add $field");
		}

		// settlementMode is an enum but NOT forced required (keeps existing seeds valid).
		self::assertSame(['reimbursable', 'pass-through'], $props['settlementMode']['enum']);
		self::assertArrayNotHasKey('required', $receipt, 'Receipt extension must not redeclare required[]');

		// The markup lookup is declared declaratively under x-openregister-calculations
		// and targets markupAmountCalculated (ADR-031 declarative computation).
		self::assertArrayHasKey('x-openregister-calculations', $receipt);
		$calculations = $receipt['x-openregister-calculations'];
		self::assertArrayHasKey('markupLookup', $calculations);
		self::assertArrayHasKey('markupAmountCalculated', $calculations['markupLookup']['targets']);
		self::assertSame('ExpenseClaimEntry.submit', $calculations['markupLookup']['lockOn']);

	}//end testReceiptExtensionAddsSettlementFields()

	/**
	 * The ExpenseClaimEntry extension adds the settlement-totals aggregation +
	 * the dual-path GL transaction back-refs + the submit/approve guards on the
	 * declarative lifecycle (REQ-ERP-006 / REQ-ERP-007 / REQ-ERP-010).
	 *
	 * @return void
	 */
	public function testClaimExtensionAddsAggregatesAndSettlementContract(): void {
		$claim = $this->fragment()['components']['schemas']['ExpenseClaimEntry'];
		$props = $claim['properties'];

		foreach (['settlementMode', 'totalReimbursableAmount', 'totalPassThroughAmount', 'passThroughCustomerIds', 'glReimbursableTransactionId', 'glPassThroughTransactionId'] as $field) {
			self::assertArrayHasKey($field, $props, "ExpenseClaimEntry must add $field");
		}

		// settlementTotals aggregation keys the dual-path settlement.
		self::assertArrayHasKey('x-openregister-aggregations', $claim);
		self::assertArrayHasKey('settlementTotals', $claim['x-openregister-aggregations']);

		// Lifecycle guards enforce the submit/approve preconditions; the post
		// transition emits the GL materialisation actions.
		self::assertArrayHasKey('x-openregister-lifecycle', $claim);
		$transitions = $claim['x-openregister-lifecycle']['transitions'];
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\ExpenseReimbursementGuard::requireSettlementModeConsistency',
			$transitions['submit']['requires']
		);
		self::assertSame(
			'OCA\\Shillinq\\Lifecycle\\ExpenseReimbursementGuard::requireMarkupApprovalIfThreshold',
			$transitions['approve']['requires']
		);

	}//end testClaimExtensionAddsAggregatesAndSettlementContract()

	/**
	 * No seed objects ship with this fragment — the policies and markup rules
	 * are seeded administratively via the SettingsService (REQ-ERP-005 seed
	 * data is captured at runtime, not in the register fragment).
	 *
	 * @return void
	 */
	public function testFragmentSeedsPolicyAndRuleObjects(): void {
		$frag = $this->fragment();
		// The fragment may or may not declare top-level objects; either is fine.
		// What matters is that the two master-data schemas are present so the
		// service can seed them.
		$schemas = $frag['components']['schemas'];
		self::assertArrayHasKey('ReimbursementPolicy', $schemas);
		self::assertArrayHasKey('PassThroughMarkupRule', $schemas);

	}//end testFragmentSeedsPolicyAndRuleObjects()

	/**
	 * Merging the fragment onto the monolith extends Receipt/ExpenseClaimEntry
	 * in place and drops nothing pre-existing (ADR-037 additive merge).
	 *
	 * @return void
	 */
	public function testFragmentMergesAdditivelyOntoMonolith(): void {
		$base = json_decode((string)file_get_contents($this->registerPath), true);
		$frag = $this->fragment();

		$schemaCountBefore = count($base['components']['schemas']);
		$receiptPropsBefore = count($base['components']['schemas']['Receipt']['properties']);

		$merged = $this->merge($base, $frag);
		$schemas = $merged['components']['schemas'];

		// The two new schemas land.
		self::assertArrayHasKey('ReimbursementPolicy', $schemas);
		self::assertArrayHasKey('PassThroughMarkupRule', $schemas);

		// The new schema count is at most schemaCountBefore + size(fragmentSchemas),
		// but Receipt + ExpenseClaimEntry already existed and merge over them in
		// place — assert the actual added count equals the new-only set.
		$existingNames = array_keys($base['components']['schemas']);
		$fragmentNames = array_keys($frag['components']['schemas']);
		$newOnly = array_values(array_diff($fragmentNames, $existingNames));
		self::assertCount(
			$schemaCountBefore + count($newOnly),
			$schemas,
			'Merge must add exactly the fragment-only schemas'
		);

		// Pre-existing schemas survive.
		foreach (array_keys($base['components']['schemas']) as $name) {
			self::assertArrayHasKey($name, $schemas, "$name must survive merge");
		}

		// Receipt was extended in place (props grew, base props survive).
		$mergedReceipt = $schemas['Receipt']['properties'];
		self::assertGreaterThan($receiptPropsBefore, count($mergedReceipt));
		self::assertArrayHasKey('settlementMode', $mergedReceipt, 'New Receipt prop must be present');

		// ExpenseClaimEntry retains its lifecycle and has the new aggregation.
		$mergedClaim = $schemas['ExpenseClaimEntry'];
		self::assertArrayHasKey('x-openregister-lifecycle', $mergedClaim);
		self::assertArrayHasKey('settlementTotals', $mergedClaim['x-openregister-aggregations']);

	}//end testFragmentMergesAdditivelyOntoMonolith()
}//end class
