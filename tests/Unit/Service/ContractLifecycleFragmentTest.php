<?php

/**
 * Unit tests for the contract-lifecycle-management register fragment.
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
 * @spec openspec/changes/contract-lifecycle-management/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the contract-lifecycle-management fragment is valid JSON, declares
 * the Contract + ContractObligation schemas with audit trails, the declarative
 * lifecycle (REQ-CLM-002), the renewalDecisionDate calculation (REQ-CLM-001),
 * and the canonical-dialect notification rules (REQ-CLM-004 / ADR-031).
 */
final class ContractLifecycleFragmentTest extends TestCase {

	/**
	 * Absolute path to the change fragment.
	 *
	 * @var string
	 */
	private string $fragmentPath = __DIR__ . '/../../../lib/Settings/register.d/contract-lifecycle-management.json';

	/**
	 * Decode the fragment JSON.
	 *
	 * @return array<mixed> The decoded fragment.
	 */
	private function load(): array {
		$data = json_decode((string)file_get_contents($this->fragmentPath), true);
		self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
		self::assertIsArray($data);
		return $data;
	}//end load()

	/**
	 * The fragment file is present and valid JSON with a schemas block.
	 *
	 * @return void
	 */
	public function testFragmentIsValidJson(): void {
		self::assertFileExists($this->fragmentPath);
		$data = $this->load();
		self::assertArrayHasKey('schemas', $data['components']);
	}//end testFragmentIsValidJson()

	/**
	 * Both schemas are declared with audit-trail enabled (REQ-CLM-001 / REQ-CLM-003).
	 *
	 * @return void
	 */
	public function testBothSchemasPresentWithAuditTrail(): void {
		$schemas = $this->load()['components']['schemas'];

		foreach (['Contract', 'ContractObligation'] as $name) {
			self::assertArrayHasKey($name, $schemas, "Fragment must declare $name");
			self::assertArrayHasKey('x-openregister-audit-trail', $schemas[$name], "$name must enable audit trail");
			self::assertTrue(
				$schemas[$name]['x-openregister-audit-trail']['enabled'],
				"$name audit trail must be enabled"
			);
		}
	}//end testBothSchemasPresentWithAuditTrail()

	/**
	 * The Contract lifecycle declares all states and key transitions (REQ-CLM-002).
	 *
	 * @return void
	 */
	public function testContractLifecycleStatesAndTransitions(): void {
		$contract = $this->load()['components']['schemas']['Contract'];
		$lifecycle = $contract['x-openregister-lifecycle'];

		self::assertSame('status', $lifecycle['field']);
		self::assertSame('draft', $lifecycle['initialState']);

		foreach (['draft', 'active', 'expiring', 'expired', 'renewed', 'terminated'] as $state) {
			self::assertArrayHasKey($state, $lifecycle['states'], "Lifecycle must declare state $state");
		}

		$transitions = $lifecycle['transitions'];
		foreach (['activate', 'markExpiring', 'expire', 'terminate', 'renew'] as $transition) {
			self::assertArrayHasKey($transition, $transitions, "Lifecycle must declare transition $transition");
		}

		// Activation + termination carry fail-closed PHP guards (REQ-CLM-002).
		self::assertStringContainsString(
			'ContractLifecycleGuard::canActivate',
			$transitions['activate']['requires']
		);
		self::assertStringContainsString(
			'ContractLifecycleGuard::requireTerminationReason',
			$transitions['terminate']['requires']
		);
	}//end testContractLifecycleStatesAndTransitions()

	/**
	 * renewalDecisionDate is declared as an x-openregister-calculations field (REQ-CLM-001).
	 *
	 * @return void
	 */
	public function testRenewalDecisionDateCalculation(): void {
		$contract = $this->load()['components']['schemas']['Contract'];

		self::assertArrayHasKey('x-openregister-calculations', $contract);
		self::assertArrayHasKey('renewalDecisionDate', $contract['x-openregister-calculations']);
		self::assertSame('date', $contract['x-openregister-calculations']['renewalDecisionDate']['type']);
		self::assertStringContainsString(
			'noticePeriodDays',
			$contract['x-openregister-calculations']['renewalDecisionDate']['expression']
		);
	}//end testRenewalDecisionDateCalculation()

	/**
	 * The five notification rules use the canonical dialect (REQ-CLM-004 / ADR-031).
	 *
	 * Asserts trigger.type in {scheduled, updated}, a recipients array, and both
	 * nl + en subjects on each of the five rules across both schemas.
	 *
	 * @return void
	 */
	public function testNotificationRulesUseCanonicalDialect(): void {
		$schemas = $this->load()['components']['schemas'];

		$rules = array_merge(
			$schemas['Contract']['x-openregister-notifications'],
			$schemas['ContractObligation']['x-openregister-notifications']
		);

		$expected = [
			'renewalDecisionWindow',
			'expiredWithoutRenewal',
			'terminated',
			'obligationDeadline',
			'obligationOverdue',
		];
		foreach ($expected as $name) {
			self::assertArrayHasKey($name, $rules, "Notification rule $name must be declared");
		}

		foreach ($rules as $name => $rule) {
			self::assertArrayHasKey('trigger', $rule, "$name must declare a trigger");
			self::assertContains(
				$rule['trigger']['type'],
				['scheduled', 'updated'],
				"$name trigger.type must be scheduled or updated"
			);
			self::assertIsArray($rule['recipients'], "$name must declare a recipients array");
			self::assertNotEmpty($rule['recipients'], "$name recipients must be non-empty");
			self::assertArrayHasKey('subject', $rule, "$name must declare a subject");
			self::assertArrayHasKey('nl', $rule['subject'], "$name subject must have nl");
			self::assertArrayHasKey('en', $rule['subject'], "$name subject must have en");
		}
	}//end testNotificationRulesUseCanonicalDialect()

	/**
	 * No legacy notification dialect (object.create / title / message) appears (gate-18).
	 *
	 * @return void
	 */
	public function testNoLegacyNotificationDialect(): void {
		$raw = (string)file_get_contents($this->fragmentPath);

		self::assertStringNotContainsString('object.create', $raw, 'Legacy object.create dialect must not appear');
		self::assertStringNotContainsString('object.update', $raw, 'Legacy object.update dialect must not appear');

		$schemas = $this->load()['components']['schemas'];
		$rules = array_merge(
			$schemas['Contract']['x-openregister-notifications'],
			$schemas['ContractObligation']['x-openregister-notifications']
		);
		foreach ($rules as $name => $rule) {
			self::assertArrayNotHasKey('title', $rule, "$name must not use the legacy title key");
			self::assertArrayNotHasKey('message', $rule, "$name must not use the legacy message key");
		}
	}//end testNoLegacyNotificationDialect()

	/**
	 * The spend rollup is honestly chained — no active aggregation rules target
	 * absent schemas; only a descriptive x-chained-aggregations note exists
	 * (REQ-CLM-006).
	 *
	 * @return void
	 */
	public function testSpendRollupIsHonestlyChained(): void {
		$contract = $this->load()['components']['schemas']['Contract'];

		self::assertArrayNotHasKey(
			'x-openregister-aggregations',
			$contract,
			'No active aggregation rules may target the unmerged PurchaseOrder/APInvoice/ARInvoice schemas'
		);
		self::assertArrayHasKey('x-chained-aggregations', $contract);
		self::assertArrayHasKey('committedSpend', $contract['x-chained-aggregations']);
		self::assertArrayHasKey('invoicedSpend', $contract['x-chained-aggregations']);
	}//end testSpendRollupIsHonestlyChained()

	/**
	 * No Party / Customer / Supplier schema is invented; counterparty is a field (REQ-CLM-001).
	 *
	 * @return void
	 */
	public function testNoCounterpartySchemaInvented(): void {
		$schemas = $this->load()['components']['schemas'];

		foreach (['Party', 'Customer', 'Supplier', 'Counterparty'] as $forbidden) {
			self::assertArrayNotHasKey($forbidden, $schemas, "No $forbidden schema may be declared");
		}

		self::assertArrayHasKey(
			'counterpartyReference',
			$schemas['Contract']['properties'],
			'Counterparty must be a contact-reference field'
		);
	}//end testNoCounterpartySchemaInvented()
}//end class
