<?php

/**
 * Unit tests for CcmRuleEngine.
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
 * @spec openspec/changes/bookkeeping-ccm-rule-engine/specs/bookkeeping-ccm-rule-engine/index.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\CcmRuleEngine;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the DSL validator, AST compiler/cache, and every operator family of the
 * deterministic CcmRuleEngine (REQ-CCM-002). The engine never executes dynamic
 * code; every operator is asserted with both a firing and a non-firing scenario.
 */
class CcmRuleEngineTest extends TestCase {
	// phpcs:disable CustomSniffs.Functions.NamedParameters

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The engine under test.
	 *
	 * @var CcmRuleEngine
	 */
	private CcmRuleEngine $engine;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->engine = new CcmRuleEngine($this->logger);

	}//end setUp()

	/**
	 * Evaluate a DSL expression against a context, returning the fired boolean.
	 *
	 * @param array<string,mixed> $dsl The ruleLogic.
	 * @param array<string,mixed> $context The transaction context.
	 *
	 * @return bool Whether the rule fired.
	 */
	private function fire(array $dsl, array $context): bool {
		$result = $this->engine->evaluateRule(['ruleCode' => 'T-01', 'ruleLogic' => $dsl], $context);
		return $result['fired'];
	}//end fire()

	/**
	 * A valid DSL passes validation.
	 *
	 * @return void
	 */
	public function testValidDslValidates(): void {
		self::assertTrue($this->engine->validateDsl(['field-equals' => ['amount', '100']]));
		self::assertTrue($this->engine->validateDsl(['all-of' => [['event-matches' => 'x'], ['field-greater-than' => ['amount', 1]]]]));

	}//end testValidDslValidates()

	/**
	 * An unknown operator fails validation (REQ-CCM-002 registration guard).
	 *
	 * @return void
	 */
	public function testUnknownOperatorFailsValidation(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->engine->validateDsl(['not-a-real-operator' => 'x']);

	}//end testUnknownOperatorFailsValidation()

	/**
	 * A node with more than one operator key fails validation.
	 *
	 * @return void
	 */
	public function testMultiKeyNodeFailsValidation(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->engine->validateDsl(['field-equals' => ['a', 'b'], 'event-matches' => 'x']);

	}//end testMultiKeyNodeFailsValidation()

	/**
	 * Compilation caches the AST and reuses it on a second call (REQ-CCM-002).
	 *
	 * @return void
	 */
	public function testAstIsCachedAndReused(): void {
		$rule = [
			'@self' => ['id' => 'rule-1', 'version' => '1'],
			'ruleCode' => 'SoD-01',
			'ruleLogic' => ['field-equals' => ['amount', '100']],
		];
		$first = $this->engine->compileRule($rule);
		$second = $this->engine->compileRule($rule);
		self::assertSame($first, $second);
		self::assertStringStartsWith('rule-1@1:', $first['cacheKey']);

	}//end testAstIsCachedAndReused()

	/**
	 * An empty DSL never fires (custom-rule slot no-op).
	 *
	 * @return void
	 */
	public function testEmptyDslNeverFires(): void {
		self::assertFalse($this->fire([], ['fields' => []]));
		self::assertFalse($this->fire(['all-of' => []], ['fields' => []]));

	}//end testEmptyDslNeverFires()

	/**
	 * The event-matches operator fires only on the matching event.
	 *
	 * @return void
	 */
	public function testEventMatches(): void {
		self::assertTrue($this->fire(['event-matches' => 'journal-entry-posted'], ['event' => 'journal-entry-posted']));
		self::assertFalse($this->fire(['event-matches' => 'journal-entry-posted'], ['event' => 'vendor-created']));

	}//end testEventMatches()

	/**
	 * The field-equals / field-in-set / field-greater-than / field-between operators.
	 *
	 * @return void
	 */
	public function testFieldOperators(): void {
		$ctx = ['fields' => ['posting_channel' => 'manual-by-user', 'amount' => 24950, 'account_code' => '8000']];

		self::assertTrue($this->fire(['field-equals' => ['posting_channel', 'manual-by-user']], $ctx));
		self::assertFalse($this->fire(['field-equals' => ['posting_channel', 'system-generated']], $ctx));

		self::assertTrue($this->fire(['field-in-set' => ['account_code', ['8000', '8100']]], $ctx));
		self::assertFalse($this->fire(['field-in-set' => ['account_code', ['4100']]], $ctx));

		self::assertTrue($this->fire(['field-greater-than' => ['amount', 20000]], $ctx));
		self::assertFalse($this->fire(['field-greater-than' => ['amount', 30000]], $ctx));

		self::assertTrue($this->fire(['field-between' => ['amount', 24000, 24999]], $ctx));
		self::assertFalse($this->fire(['field-between' => ['amount', 25000, 26000]], $ctx));

	}//end testFieldOperators()

	/**
	 * The field-matches-regex operator fires on a pattern match and a malformed pattern fails
	 * closed (no exception escapes).
	 *
	 * @return void
	 */
	public function testRegexOperator(): void {
		$ctx = ['fields' => ['narrative' => 'correctie']];
		self::assertTrue($this->fire(['field-matches-regex' => ['narrative', '^(aanpassing|correctie)$']], $ctx));
		self::assertFalse($this->fire(['field-matches-regex' => ['narrative', '^adjustment$']], $ctx));
		// Malformed pattern denies the match (fail-closed).
		self::assertFalse($this->fire(['field-matches-regex' => ['narrative', '([']], $ctx));

	}//end testRegexOperator()

	/**
	 * The time-is-weekend and time-is-outside-business-hours operators.
	 *
	 * @return void
	 */
	public function testTimeOperators(): void {
		// 2026-03-28 is a Saturday; 2026-03-30 is a Monday.
		self::assertTrue($this->fire(['time-is-weekend' => 'posting_date'], ['fields' => ['posting_date' => '2026-03-28T10:00:00+01:00']]));
		self::assertFalse($this->fire(['time-is-weekend' => 'posting_date'], ['fields' => ['posting_date' => '2026-03-30T10:00:00+01:00']]));

		$night = ['fields' => ['posting_date' => '2026-03-30T22:30:00+01:00']];
		$day = ['fields' => ['posting_date' => '2026-03-30T12:30:00+01:00']];
		self::assertTrue($this->fire(['time-is-outside-business-hours' => ['posting_date', '08:00', '20:00']], $night));
		self::assertFalse($this->fire(['time-is-outside-business-hours' => ['posting_date', '08:00', '20:00']], $day));

	}//end testTimeOperators()

	/**
	 * The user-has-function / user-also-has-function / user-is-in-role operators resolve the
	 * SoD function-code and role maps from the context (REQ-CCM-005).
	 *
	 * @return void
	 */
	public function testUserFunctionAndRoleOperators(): void {
		$ctx = [
			'fields' => ['posting_user' => 'alice'],
			'userFunctions' => ['alice' => ['VENDOR-CREATE', 'PAYMENT-RELEASE']],
			'userRoles' => ['alice' => ['cfo']],
		];

		self::assertTrue($this->fire(['user-has-function' => ['posting_user', 'VENDOR-CREATE']], $ctx));
		self::assertFalse($this->fire(['user-has-function' => ['posting_user', 'BANK-RECONCILIATION']], $ctx));

		self::assertTrue($this->fire(['user-also-has-function' => ['posting_user', 'PAYMENT-RELEASE', 'VENDOR-CREATE']], $ctx));
		self::assertFalse($this->fire(['user-also-has-function' => ['posting_user', 'PAYMENT-RELEASE', 'INVOICE-POST']], $ctx));

		self::assertTrue($this->fire(['user-is-in-role' => ['posting_user', 'cfo']], $ctx));
		self::assertFalse($this->fire(['user-is-in-role' => ['posting_user', 'auditor']], $ctx));

	}//end testUserFunctionAndRoleOperators()

	/**
	 * The value-deviates-from-baseline operator computes a z-score against the materialised
	 * baseline and exposes diagnostics (REQ-CCM-002 diagnostic scenario).
	 *
	 * @return void
	 */
	public function testBaselineDeviationWithDiagnostics(): void {
		$ctx = [
			'fields' => ['amount' => 9200],
			'baselines' => ['vendor' => ['mean' => 5000.0, 'stddev' => 1200.0]],
		];

		$result = $this->engine->evaluateRule(
			['ruleCode' => 'AMT-04', 'ruleLogic' => ['value-deviates-from-baseline' => ['amount', 'vendor', 3.0, '12-month']]],
			$ctx
		);
		self::assertTrue($result['fired']);
		self::assertSame(3.5, $result['diagnostics']['z_score']);
		self::assertSame(5000.0, $result['diagnostics']['baseline_mean']);
		self::assertSame(1200.0, $result['diagnostics']['baseline_stddev']);

		// Within threshold does not fire.
		$ctx['fields']['amount'] = 6000;
		$within = $this->engine->evaluateRule(
			['ruleCode' => 'AMT-04', 'ruleLogic' => ['value-deviates-from-baseline' => ['amount', 'vendor', 3.0, '12-month']]],
			$ctx
		);
		self::assertFalse($within['fired']);

	}//end testBaselineDeviationWithDiagnostics()

	/**
	 * The same-user-as operator compares two user fields (self-approval detection).
	 *
	 * @return void
	 */
	public function testSameUserAs(): void {
		$dsl = ['same-user-as' => ['approver', 'requester', 'approval']];
		self::assertTrue($this->fire($dsl, ['fields' => ['approver' => 'bob', 'requester' => 'bob']]));
		self::assertFalse($this->fire($dsl, ['fields' => ['approver' => 'bob', 'requester' => 'carol']]));
		// Two empty fields do not count as "same".
		self::assertFalse($this->fire($dsl, ['fields' => []]));

	}//end testSameUserAs()

	/**
	 * Context-flag operators (duplicate / count / master-data / approval-bypass /
	 * benford / period-closing) read pre-resolved booleans from the context.
	 *
	 * @return void
	 */
	public function testContextFlagOperators(): void {
		$dup = ['duplicate-of-existing' => ['vendor', 'amount', '7-days', 1.0]];
		self::assertTrue($this->fire($dup, ['fields' => ['duplicate_match' => true]]));
		self::assertFalse($this->fire($dup, ['fields' => ['duplicate_match' => false]]));

		$cnt = ['count-of-similar-in-period-exceeds' => ['vendor', 'amount', 5, '30-days']];
		self::assertTrue($this->fire($cnt, ['fields' => ['similar_count' => 6]]));
		self::assertFalse($this->fire($cnt, ['fields' => ['similar_count' => 2]]));

		$md = ['master-data-changed-within' => ['vendor', '14-days', 'bank_account']];
		self::assertTrue($this->fire($md, ['fields' => ['master_data_changed' => true]]));
		$bypass = ['approval-chain-bypassed' => ['amount_threshold', 'policy']];
		self::assertTrue($this->fire($bypass, ['fields' => ['approval_chain_bypassed' => true]]));
		self::assertTrue($this->fire(['value-violates-benford' => ['amount', 'leading-digit', 0.05]], ['fields' => ['benford_violation' => true]]));
		self::assertTrue($this->fire(['posted-while-period-closing' => ['posting_date', '3-days']], ['fields' => ['period_closing' => true]]));

	}//end testContextFlagOperators()

	/**
	 * Compound operators all-of / any-of / none-of / not.
	 *
	 * @return void
	 */
	public function testCompoundOperators(): void {
		$ctx = ['event' => 'journal-entry-posted', 'fields' => ['posting_channel' => 'manual-by-user', 'amount' => 60000]];

		self::assertTrue(
			$this->fire(
				[
					'all-of' => [
						['event-matches' => 'journal-entry-posted'],
						['field-equals' => ['posting_channel', 'manual-by-user']],
						['field-greater-than' => ['amount', 50000]],
					],
				],
				$ctx
			)
		);

		self::assertFalse(
			$this->fire(
				[
					'all-of' => [
						['event-matches' => 'journal-entry-posted'],
						['field-greater-than' => ['amount', 100000]],
					],
				],
				$ctx
			)
		);

		self::assertTrue(
			$this->fire(
				[
					'any-of' => [
						['field-greater-than' => ['amount', 100000]],
						['field-equals' => ['posting_channel', 'manual-by-user']],
					],
				],
				$ctx
			)
		);

		self::assertTrue(
			$this->fire(
				[
					'none-of' => [
						['field-equals' => ['posting_channel', 'system-generated']],
					],
				],
				$ctx
			)
		);

		self::assertFalse(
			$this->fire(
				[
					'none-of' => [
						['field-equals' => ['posting_channel', 'manual-by-user']],
					],
				],
				$ctx
			)
		);

		self::assertTrue($this->fire(['not' => ['field-equals' => ['posting_channel', 'system-generated']]], $ctx));

	}//end testCompoundOperators()

	/**
	 * The seed SoD-01 rule fires when one user holds both VENDOR-CREATE and
	 * PAYMENT-RELEASE on a payment-instruction event (REQ-CCM-005 / REQ-CCM-007).
	 *
	 * @return void
	 */
	public function testSeedSoD01FiresOnConflict(): void {
		$logic = [
			'all-of' => [
				['event-matches' => 'payment-instruction-created'],
				['user-has-function' => ['posting_user', 'VENDOR-CREATE']],
				['user-also-has-function' => ['posting_user', 'PAYMENT-RELEASE', 'VENDOR-CREATE']],
			],
		];
		$conflict = [
			'event' => 'payment-instruction-created',
			'fields' => ['posting_user' => 'alice'],
			'userFunctions' => ['alice' => ['VENDOR-CREATE', 'PAYMENT-RELEASE']],
		];
		$clean = [
			'event' => 'payment-instruction-created',
			'fields' => ['posting_user' => 'bob'],
			'userFunctions' => ['bob' => ['VENDOR-CREATE']],
		];
		self::assertTrue($this->fire($logic, $conflict));
		self::assertFalse($this->fire($logic, $clean));

	}//end testSeedSoD01FiresOnConflict()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
