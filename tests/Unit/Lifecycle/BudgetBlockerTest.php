<?php

/**
 * Unit tests for BudgetBlocker.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-verplichtingenadministratie/tasks.md#task-1.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\BudgetBlocker;
use OCA\Shillinq\Lifecycle\MandateEnforcer;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BudgetBlocker per REQ-VPL-001.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class BudgetBlockerTest extends TestCase {

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var BudgetBlocker
	 */
	private BudgetBlocker $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$mandate = new MandateEnforcer(
			container: $this->container,
			appConfig: $this->appConfig,
			logger: $this->logger,
		);

		$this->guard = new BudgetBlocker(
			container: $this->container,
			appConfig: $this->appConfig,
			logger: $this->logger,
			mandate: $mandate,
		);

	}//end setUp()

	/**
	 * Build a filter-aware ObjectService stub returning records by schema.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $recordsBySchema Records by schema.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $recordsBySchema): object {
		return new class($recordsBySchema) {
			/**
			 * Map of schema name → record arrays.
			 *
			 * @var array<string, array<int, array<string, mixed>>>
			 */
			private array $recordsBySchema;

			/**
			 * Currently active schema name.
			 *
			 * @var string
			 */
			private string $currentSchema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string, array<int, array<string, mixed>>> $recordsBySchema Records by schema.
			 */
			public function __construct(array $recordsBySchema) {
				$this->recordsBySchema = $recordsBySchema;

			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema name.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->currentSchema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return stubbed records matching the exact-match filters.
			 *
			 * @param array<string, mixed> $params Query parameters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $params = []): array {
				$records = ($this->recordsBySchema[$this->currentSchema] ?? []);
				$filters = ($params['filters'] ?? []);

				return array_values(
					array_filter(
						$records,
						static function (array $record) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($record[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);

			}//end findAll()
		};

	}//end buildObjectServiceStub()

	/**
	 * Stub the container to return the given ObjectService stub.
	 *
	 * @param object $objectService The ObjectService stub.
	 *
	 * @return void
	 */
	private function withObjectService(object $objectService): void {
		$this->container->method('get')->willReturn($objectService);

	}//end withObjectService()

	/**
	 * A budget record for the demo administration / programme / financial_year.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 *
	 * @return array<string,mixed>
	 */
	private function budget(array $overrides = []): array {
		return array_merge(
			[
				'administrationId' => 'adm-1',
				'programmeCode' => '5.1',
				'financialYear' => 2026,
				'authorised_amount' => 50000000,
				'realised_amount' => 20000000,
				'outstanding_commitments' => 0,
			],
			$overrides
		);

	}//end budget()

	/**
	 * A single-line commitment of the given amount on programme 5.1 / 2026.
	 *
	 * @param int $amount Amount in minor units.
	 *
	 * @return array<string,mixed>
	 */
	private function commitment(int $amount): array {
		return [
			'administrationId' => 'adm-1',
			'commitmentNumber' => 'PO-1',
			'kind' => 'purchase_order',
			'total_amount_excl_vat' => $amount,
			'rules' => [
				[
					'programme' => '5.1',
					'financialYear' => 2026,
					'amount_excl_vat' => $amount,
				],
			],
		];

	}//end commitment()

	/**
	 * Free room is geautoriseerd - gerealiseerd - openstaande (pure).
	 *
	 * @return void
	 */
	public function testFreeRoomCalculation(): void {
		$this->assertSame(30000000, $this->guard->freeRoom($this->budget()));
		$this->assertSame(
			5000000,
			$this->guard->freeRoom($this->budget(['outstanding_commitments' => 25000000]))
		);

	}//end testFreeRoomCalculation()

	/**
	 * REQ-VPL-001: a commitment within free room is allowed and blocks budget.
	 *
	 * @return void
	 */
	public function testCommitmentWithinBudgetAllowed(): void {
		$this->withObjectService(
			$this->buildObjectServiceStub(['CommitmentBudget' => [$this->budget()], 'SpendingMandate' => []])
		);

		// Free room is EUR 300.000; a EUR 250.000 commitment fits.
		$this->assertTrue($this->guard->canCommit('PO-1', $this->commitment(25000000)));

	}//end testCommitmentWithinBudgetAllowed()

	/**
	 * REQ-VPL-001: a commitment exceeding free room is rejected without an override.
	 *
	 * @return void
	 */
	public function testCommitmentExceedingBudgetRejected(): void {
		$this->withObjectService(
			$this->buildObjectServiceStub(['CommitmentBudget' => [$this->budget()], 'SpendingMandate' => []])
		);

		// Free room is EUR 300.000; a EUR 350.000 commitment exceeds it.
		$this->assertFalse($this->guard->canCommit('PO-1', $this->commitment(35000000)));

	}//end testCommitmentExceedingBudgetRejected()

	/**
	 * REQ-VPL-001: an override-mandate holder may force-accept a budget-exceeding
	 * commitment.
	 *
	 * @return void
	 */
	public function testOverrideMandateForcesAcceptance(): void {
		$override = [
			'administrationId' => 'adm-1',
			'mandateCode' => 'M-CFO-OVERRIDE',
			'maximumAmount' => 1000000000,
			'kind_commitment' => ['purchase_order'],
			'is_override' => true,
			'valid_from' => '2020-01-01',
			'valid_to' => '2999-12-31',
		];

		$this->withObjectService(
			$this->buildObjectServiceStub(['CommitmentBudget' => [$this->budget()], 'SpendingMandate' => [$override]])
		);

		// EUR 350.000 exceeds free room but the override-mandate forces acceptance.
		$this->assertTrue($this->guard->canCommit('PO-1', $this->commitment(35000000)));

	}//end testOverrideMandateForcesAcceptance()

	/**
	 * REQ-VPL-001 / REQ-VPL-004: each rule is validated against its own
	 * programme + financial_year budget independently.
	 *
	 * @return void
	 */
	public function testMultiYearPerBudgetIsolation(): void {
		$budget2026 = $this->budget(['financialYear' => 2026, 'authorised_amount' => 12000000, 'realised_amount' => 0]);
		$budget2027 = $this->budget(['financialYear' => 2027, 'authorised_amount' => 5000000, 'realised_amount' => 0]);

		$this->withObjectService(
			$this->buildObjectServiceStub(
				['CommitmentBudget' => [$budget2026, $budget2027], 'SpendingMandate' => []]
			)
		);

		$commitment = [
			'administrationId' => 'adm-1',
			'commitmentNumber' => 'RO-1',
			'kind' => 'frameworkAgreement',
			'total_amount_excl_vat' => 20000000,
			'rules' => [
				['programme' => '5.1', 'financialYear' => 2026, 'amount_excl_vat' => 10000000],
				['programme' => '5.1', 'financialYear' => 2027, 'amount_excl_vat' => 10000000],
			],
		];

		// 2026 fits (EUR 100k <= EUR 120k) but 2027 does not (EUR 100k > EUR 50k) → rejected.
		$this->assertFalse($this->guard->canCommit('RO-1', $commitment));

	}//end testMultiYearPerBudgetIsolation()

	/**
	 * A commitment whose rule has no matching budget is rejected (fail-closed:
	 * a missing budget is not free budget).
	 *
	 * @return void
	 */
	public function testMissingBudgetRejected(): void {
		$this->withObjectService(
			$this->buildObjectServiceStub(['CommitmentBudget' => [], 'SpendingMandate' => []])
		);

		$this->assertFalse($this->guard->canCommit('PO-1', $this->commitment(1000000)));

	}//end testMissingBudgetRejected()

	/**
	 * Fail-closed: when the ObjectService throws, the commitment is denied (CWE-863).
	 *
	 * @return void
	 */
	public function testFailClosedOnException(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('boom'));

		$this->assertFalse($this->guard->canCommit('PO-1', $this->commitment(1000000)));

	}//end testFailClosedOnException()

	/**
	 * A rule whose amount cannot be read is DENIED, not treated as zero.
	 *
	 * The shipped code coalesced a missing amount to 0, and `fits()` is
	 * `$amount <= freeRoom()`, so every unreadable rule fitted every budget
	 * that was not already overcommitted — the commitment was approved against
	 * a figure nothing had read (CWE-863).
	 *
	 * The amount here (EUR 5.000.000) is far beyond the free room, so the
	 * ONLY way this can return true is the coalesce-to-zero path. That makes
	 * the assertion unable to pass for an unrelated reason.
	 *
	 * @return void
	 */
	public function testRegelWithUnreadableAmountIsDenied(): void {
		$this->withObjectService(
			$this->buildObjectServiceStub(['CommitmentBudget' => [$this->budget()], 'SpendingMandate' => []])
		);

		$commitment = $this->commitment(500000000);
		// The amount arrives under a key this guard does not read — exactly what
		// a vocabulary drift on either side of the flow produces.
		$commitment['rules'][0]['amount_excl_vat'] = $commitment['rules'][0]['amount_excl_vat'];
		unset($commitment['rules'][0]['amount_excl_vat']);

		$this->assertFalse(
			$this->guard->canCommit('PO-1', $commitment),
			'an unreadable amount must deny, not coalesce to a zero that fits every budget'
		);

	}//end testRegelWithUnreadableAmountIsDenied()

	/**
	 * A non-numeric amount is DENIED for the same reason.
	 *
	 * `array_key_exists()` alone would accept `'onbekend'`, which `(int)` casts
	 * to 0 — the same fail-open through a different door.
	 *
	 * @return void
	 */
	public function testRegelWithNonNumericAmountIsDenied(): void {
		$this->withObjectService(
			$this->buildObjectServiceStub(['CommitmentBudget' => [$this->budget()], 'SpendingMandate' => []])
		);

		$commitment = $this->commitment(500000000);
		$commitment['rules'][0]['amount_excl_vat'] = 'onbekend';

		$this->assertFalse($this->guard->canCommit('PO-1', $commitment));

	}//end testRegelWithNonNumericAmountIsDenied()

	/**
	 * POSITIVE CONTROL: a readable amount within free room is still allowed.
	 *
	 * Without this, the two denials above would also be satisfied by a guard
	 * that had started denying everything — which is precisely how a
	 * fail-closed repair goes wrong.
	 *
	 * @return void
	 */
	public function testReadableAmountWithinBudgetIsStillAllowed(): void {
		$this->withObjectService(
			$this->buildObjectServiceStub(['CommitmentBudget' => [$this->budget()], 'SpendingMandate' => []])
		);

		$this->assertTrue($this->guard->canCommit('PO-1', $this->commitment(10000000)));

	}//end testReadableAmountWithinBudgetIsStillAllowed()
}//end class
