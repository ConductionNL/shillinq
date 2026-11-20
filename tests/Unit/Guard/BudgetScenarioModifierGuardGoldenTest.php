<?php

/**
 * Golden tests for BudgetScenarioModifierGuard: refusal messages, the sibling query and conflicts.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\BudgetScenarioModifierGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every answer, lookup and log line of the save precondition.
 */
class BudgetScenarioModifierGuardGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public array $calls = [];

	/**
	 * Run the precondition once.
	 *
	 * @param array<string,mixed>         $modifier The modifier.
	 * @param array<int,mixed>|\Throwable $siblings findAll()'s answer, or its failure.
	 *
	 * @return bool
	 */
	private function validate(array $modifier, array|\Throwable $siblings=[]): bool {
		$store     = new class($this, $siblings) {
			/**
			 * Construct.
			 *
			 * @param BudgetScenarioModifierGuardGoldenTest $test     Recorder.
			 * @param array<int,mixed>|\Throwable           $siblings Answer.
			 */
			public function __construct(private BudgetScenarioModifierGuardGoldenTest $test, private array|\Throwable $siblings) {
			}//end __construct()

			/**
			 * Record.
			 *
			 * @param string $register Slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				$this->test->calls[] = ['setRegister', $register];
				return $this;
			}//end setRegister()

			/**
			 * Record.
			 *
			 * @param string $schema Slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->test->calls[] = ['setSchema', $schema];
				return $this;
			}//end setSchema()

			/**
			 * Record and answer.
			 *
			 * @param array<string,mixed> $params Query.
			 *
			 * @return array<int,mixed>
			 */
			public function findAll(array $params=[]): array {
				$this->test->calls[] = ['findAll', $params];
				if ($this->siblings instanceof \Throwable) {
					throw $this->siblings;
				}

				return $this->siblings;
			}//end findAll()
		};
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['info', $message, $context];
			}
		);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->calls[] = ['error', $message, $context];
			}
		);
		$guard = new BudgetScenarioModifierGuard(appConfig: $appConfig, logger: $logger, objectService: new DuckObjectServiceAdapter(inner: $store));

		return $guard->validateOnSave($modifier);
	}//end validate()

	/**
	 * Each missing field is refused with its own message, checked in order, without a lookup.
	 *
	 * @return void
	 */
	public function testMissingFieldMessages(): void {
		$cases = [
			[
				['modifierType' => 'RECURRING_AMOUNT_CHANGE', 'scenarioId' => 's1'],
				'BudgetScenarioModifierGuard: RECURRING_* modifier missing targetRecurId — denying save',
			],
			[
				['modifierType' => 'RECURRING_END', 'targetRecurId' => 7],
				'BudgetScenarioModifierGuard: RECURRING_* modifier missing targetRecurId — denying save',
			],
			[
				['modifierType' => 'RECURRING_AMOUNT_CHANGE', 'targetRecurId' => 'r', 'newStandardAmount' => 'x', 'scenarioId' => 's2'],
				'BudgetScenarioModifierGuard: RECURRING_AMOUNT_CHANGE missing newStandardAmount — denying save',
			],
			[
				['modifierType' => 'LEDGER_AMOUNT_DELTA', 'amountDeltaCents' => 'y'],
				'BudgetScenarioModifierGuard: LEDGER_AMOUNT_DELTA missing targetLedgerGroupId — denying save',
			],
			[
				['modifierType' => 'LEDGER_AMOUNT_DELTA', 'targetLedgerGroupId' => 'g', 'scenarioId' => 's3'],
				'BudgetScenarioModifierGuard: LEDGER_AMOUNT_DELTA missing amountDeltaCents — denying save',
			],
		];
		$expected = [];
		foreach ($cases as [$modifier, $message]) {
			self::assertFalse(condition: $this->validate(modifier: $modifier));
			$expected[] = ['info', $message, ['scenarioId' => ($modifier['scenarioId'] ?? 'unknown')]];
		}

		self::assertSame(expected: $expected, actual: $this->calls);
	}//end testMissingFieldMessages()

	/**
	 * Complete non-recurring modifiers, and recurring ones without a scenario, pass without a lookup.
	 *
	 * @return void
	 */
	public function testPassesWithoutLookup(): void {
		$subject = ['modifierType' => 'LEDGER_AMOUNT_DELTA', 'targetLedgerGroupId' => 'g', 'amountDeltaCents' => '12'];
		self::assertTrue(condition: $this->validate(modifier: $subject));
		self::assertTrue(condition: $this->validate(modifier: ['modifierType' => 'OTHER']));
		self::assertTrue(condition: $this->validate(modifier: ['modifierType' => 'RECURRING_END', 'targetRecurId' => 'r1']));
		$subject = ['modifierType' => 'RECURRING_AMOUNT_CHANGE', 'targetRecurId' => 'r1', 'newStandardAmount' => 3, 'scenarioId' => ''];
		self::assertTrue(condition: $this->validate(modifier: $subject));
		self::assertSame(expected: [], actual: $this->calls);
	}//end testPassesWithoutLookup()

	/**
	 * The sibling query is exact; itself (by id or @self id) and non-recurring siblings are no conflict.
	 *
	 * @return void
	 */
	public function testSiblingQueryAndSelfSkip(): void {
		$siblings = [
			['modifierType' => 'LEDGER_AMOUNT_DELTA', 'id' => 'other'],
			['modifierType' => 'RECURRING_END', '@self' => ['id' => 'me']],
		];
		$subject = ['modifierType' => 'RECURRING_END', 'targetRecurId' => 'r1', 'scenarioId' => 's1', '@self' => ['id' => 'me']];
		self::assertTrue(condition: $this->validate(modifier: $subject, siblings: $siblings));
		self::assertSame(
			expected: [
				['setRegister', 'shillinq'],
				['setSchema', 'BudgetScenarioModifier'],
				['findAll', ['filters' => ['scenarioId' => 's1', 'targetRecurId' => 'r1']]],
			],
			actual: $this->calls
		);
	}//end testSiblingQueryAndSelfSkip()

	/**
	 * The first recurring sibling that is not the record itself is the logged conflict.
	 *
	 * @return void
	 */
	public function testConflictLogged(): void {
		$siblings = [
			['modifierType' => 'RECURRING_END', 'id' => 'me'],
			['modifierType' => 'RECURRING_AMOUNT_CHANGE', '@self' => ['id' => 'c1']],
			['modifierType' => 'RECURRING_END', 'id' => 'c2'],
		];
		$subject = ['modifierType' => 'RECURRING_END', 'targetRecurId' => 'r1', 'scenarioId' => 's1', 'id' => 'me'];
		self::assertFalse(condition: $this->validate(modifier: $subject, siblings: $siblings));
		self::assertSame(
			expected: [
				'info',
				'BudgetScenarioModifierGuard: another RECURRING_* modifier already targets this recurId in this scenario — denying save',
				['scenarioId' => 's1', 'targetRecurId' => 'r1', 'conflictingId' => 'c1'],
			],
			actual: end($this->calls)
		);
	}//end testConflictLogged()

	/**
	 * A new record conflicts with any recurring sibling, even one without an id.
	 *
	 * @return void
	 */
	public function testNewRecordConflictsWithIdlessSibling(): void {
		$subject = ['modifierType' => 'RECURRING_END', 'targetRecurId' => 'r1', 'scenarioId' => 's1'];
		self::assertFalse(condition: $this->validate(modifier: $subject, siblings: [['modifierType' => 'RECURRING_END']]));
		self::assertNull(actual: end($this->calls)[2]['conflictingId']);
	}//end testNewRecordConflictsWithIdlessSibling()

	/**
	 * A failing lookup denies the save and logs the error.
	 *
	 * @return void
	 */
	public function testLookupFailureFailsClosed(): void {
		$subject = ['modifierType' => 'RECURRING_END', 'targetRecurId' => 'r1', 'scenarioId' => 's9'];
		self::assertFalse(condition: $this->validate(modifier: $subject, siblings: new \RuntimeException('down')));
		self::assertSame(
			expected: [
				'error',
				'BudgetScenarioModifierGuard: validateOnSave failed — denying save (fail-closed)',
				['scenarioId' => 's9', 'exception' => 'down'],
			],
			actual: end($this->calls)
		);
	}//end testLookupFailureFailsClosed()
}//end class
