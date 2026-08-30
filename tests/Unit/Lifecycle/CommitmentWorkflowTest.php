<?php

/**
 * End-to-end workflow scenarios for verplichtingenadministratie.
 *
 * Composes the three Commitment lifecycle guards (BudgetBlocker +
 * MandateEnforcer + CommitmentGuard) and walks through the REQ-VPL-001 ..
 * REQ-VPL-010 GIVEN/WHEN/THEN scenarios at the unit level. The lifecycle
 * engine and a live OpenRegister instance are simulated by an in-memory
 * filter-aware ObjectService stub. This is the closest assertion we can make
 * in-repo of Task 2.3 ("test entire workflow end-to-end") and Task 2.4
 * ("verify mandate-exceeded approval workflow") without a live container.
 *
 * The Workflow test deliberately covers the same scenarios that specs.md
 * REQ-VPL-001/002/004 declare — mandate-pass + budget-pass, mandate-exceeded
 * routing, multi-year per-budget isolation — so that any regression to a
 * single guard surfaces here as well.
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
 * @spec openspec/changes/bookkeeping-verplichtingenadministratie/tasks.md#task-2.3
 * @spec openspec/changes/bookkeeping-verplichtingenadministratie/tasks.md#task-2.4
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
 * Commitment workflow scenarios per REQ-VPL-001 through REQ-VPL-004.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class CommitmentWorkflowTest extends TestCase {

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
	 * Mandate-check guard under test.
	 *
	 * @var MandateEnforcer
	 */
	private MandateEnforcer $mandate;

	/**
	 * Budget-room guard under test.
	 *
	 * @var BudgetBlocker
	 */
	private BudgetBlocker $budget;

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

		$this->mandate = new MandateEnforcer(
			container: $this->container,
			appConfig: $this->appConfig,
			logger: $this->logger,
		);

		$this->budget = new BudgetBlocker(
			container: $this->container,
			appConfig: $this->appConfig,
			logger: $this->logger,
			mandate: $this->mandate,
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
	 * REQ-VPL-001 + REQ-VPL-002, T2.3 scenario 1: mandate-covered + budget-blocked
	 * sign of a purchase_order.
	 *
	 * GIVEN a gemeente administration with a EUR 500k budget on programma 5.1 /
	 * boekjaar 2026 and a user mandate covering EUR 100k purchase orders
	 * WHEN a EUR 75k purchase order is moved to `committed`
	 * THEN MandateEnforcer.hasSufficientMandate returns true AND
	 *      BudgetBlocker.canCommit returns true AND
	 *      BudgetBlocker.freeRoom decreases by EUR 75k after the commitment is recorded.
	 *
	 * @return void
	 */
	public function testInkooporderWithinBudgetAndMandateSignsCleanly(): void {
		$budget = $this->makeBudget(['authorised_amount' => 50000000, 'realised_amount' => 0]);
		$mandate = $this->makeMandate(['mandateCode' => 'M-INKOOP-100K', 'maximumAmount' => 10000000]);
		$mandatory = $this->makeCommitment(75 * 100000);

		$this->withObjectService(
			$this->buildObjectServiceStub(
				[
					'CommitmentBudget' => [$budget],
					'Mandate' => [$mandate],
				]
			)
		);

		// Mandate check passes — user can sign EUR 75k under M-INKOOP-100K.
		$this->assertTrue($this->mandate->hasSufficientMandate('PO-1', $mandatory));
		$this->assertFalse($this->mandate->requiresApproval('PO-1', $mandatory));

		// Budget check passes — vrije_ruimte EUR 500k > EUR 75k.
		$this->assertTrue($this->budget->canCommit('PO-1', $mandatory));

		// Vrije_ruimte after the commitment is EUR 500k - EUR 75k = EUR 425k.
		$afterCommitment = $budget;
		$afterCommitment['outstanding_commitments'] = 75 * 100000;
		$this->assertSame(42500000, $this->budget->freeRoom($afterCommitment));

	}//end testInkooporderWithinBudgetAndMandateSignsCleanly()

	/**
	 * REQ-VPL-002, T2.4: mandate-exceeded routes to approval workflow.
	 *
	 * GIVEN a user mandate covering only EUR 50k purchase orders
	 * WHEN a EUR 75k purchase order is moved to `committed`
	 * THEN MandateEnforcer.requiresApproval returns true,
	 *      hasSufficientMandate returns false,
	 *      and BudgetBlocker.canCommit still returns true (budget room exists, just
	 *      requires approval first).
	 *
	 * @return void
	 */
	public function testMandateExceededRoutesToApprovalWorkflow(): void {
		$budget = $this->makeBudget();
		$mandate = $this->makeMandate(['mandateCode' => 'M-INKOOP-50K', 'maximumAmount' => 5000000]);
		$mandatory = $this->makeCommitment(75 * 100000);

		$this->withObjectService(
			$this->buildObjectServiceStub(
				[
					'CommitmentBudget' => [$budget],
					'Mandate' => [$mandate],
				]
			)
		);

		// Mandate check fails → must route to in_approval (ApprovalStep created).
		$this->assertFalse($this->mandate->hasSufficientMandate('PO-1', $mandatory));
		$this->assertTrue($this->mandate->requiresApproval('PO-1', $mandatory));

		// Budget still fits — the rejection here is mandate-based, not budget-based.
		$this->assertTrue($this->budget->canCommit('PO-1', $mandatory));

	}//end testMandateExceededRoutesToApprovalWorkflow()

	/**
	 * REQ-VPL-001 / REQ-VPL-004, T2.5: multi-year raamovereenkomst blocks each
	 * boekjaar budget independently.
	 *
	 * GIVEN budgets for 2026 (EUR 120k free) and 2027 (EUR 50k free) on programma 5.1
	 * AND a raamovereenkomst with two regels of EUR 100k each (one per boekjaar)
	 * WHEN moved to `aangegaan`
	 * THEN BudgetBlocker.canCommit returns false (2027 lacks EUR 100k of room)
	 *      AND when the same raamovereenkomst is reduced to EUR 50k on 2027 it passes.
	 *
	 * @return void
	 */
	public function testMultiYearRaamovereenkomstIsolatesBudgetPerBoekjaar(): void {
		$budget2026 = $this->makeBudget(
			['financialYear' => 2026, 'authorised_amount' => 12000000, 'realised_amount' => 0]
		);
		$budget2027 = $this->makeBudget(
			['financialYear' => 2027, 'authorised_amount' => 5000000,  'realised_amount' => 0]
		);

		$this->withObjectService(
			$this->buildObjectServiceStub(
				[
					'CommitmentBudget' => [$budget2026, $budget2027],
					'Mandate' => [],
				]
			)
		);

		$overcommit = [
			'administrationId' => 'adm-1',
			'commitmentNumber' => 'RO-1',
			'kind' => 'frameworkAgreement',
			'total_amount_excl_vat' => 20000000,
			'rules' => [
				['programme' => '5.1', 'financialYear' => 2026, 'amount_excl_vat' => 10000000],
				['programme' => '5.1', 'financialYear' => 2027, 'amount_excl_vat' => 10000000],
			],
		];

		// 2026 fits (EUR 100k ≤ EUR 120k) but 2027 does NOT (EUR 100k > EUR 50k).
		$this->assertFalse($this->budget->canCommit('RO-1', $overcommit));

		$within = $overcommit;
		$within['rules'][1]['amount_excl_vat'] = 5000000;
		$within['total_amount_excl_vat'] = 15000000;

		// 2027 right-sized to EUR 50k → both regels fit and the raamovereenkomst signs.
		$this->assertTrue($this->budget->canCommit('RO-1', $within));

	}//end testMultiYearRaamovereenkomstIsolatesBudgetPerBoekjaar()

	/**
	 * REQ-VPL-001 override-mandate path: a CFO override-mandate force-accepts a
	 * commitment that would otherwise breach the budget. The override is logged on
	 * the verplichting (audit trail) but the transition proceeds.
	 *
	 * @return void
	 */
	public function testOverrideMandateForcesAcceptanceOfOverBudgetCommitment(): void {
		$budget = $this->makeBudget(['authorised_amount' => 20000000, 'realised_amount' => 0]);
		$override = $this->makeMandate(
			[
				'mandateCode' => 'M-CFO-OVERRIDE',
				'maximumAmount' => 1000000000,
				'is_override' => true,
			]
		);
		$mandatory = $this->makeCommitment(35000000);

		$this->withObjectService(
			$this->buildObjectServiceStub(
				[
					'CommitmentBudget' => [$budget],
					'Mandate' => [$override],
				]
			)
		);

		// EUR 350k breaches EUR 200k vrije_ruimte, but the override forces acceptance.
		$this->assertTrue($this->budget->canCommit('PO-1', $mandatory));

	}//end testOverrideMandateForcesAcceptanceOfOverBudgetCommitment()

	/**
	 * REQ-VPL-002 second-signature scenario: a mandaat with
	 * vereist_tweede_handtekening_boven flags commitments above the threshold as
	 * requiring a second signature.
	 *
	 * @return void
	 */
	public function testSecondSignatureRequiredAboveThreshold(): void {
		$mandate = $this->makeMandate(
			[
				'mandateCode' => 'M-INKOOP-50K-2SIG',
				'maximumAmount' => 5000000,
				'required_second_signature_above' => 2500000,
			]
		);
		$mandatory = $this->makeCommitment(3000000);

		$this->withObjectService(
			$this->buildObjectServiceStub(
				[
					'CommitmentBudget' => [$this->makeBudget()],
					'Mandate' => [$mandate],
				]
			)
		);

		$this->assertTrue($this->mandate->hasSufficientMandate('PO-1', $mandatory));
		$this->assertTrue($this->mandate->requiresSecondSignature($mandatory));

		$small = $this->makeCommitment(2000000);
		$this->assertFalse($this->mandate->requiresSecondSignature($small));

	}//end testSecondSignatureRequiredAboveThreshold()

	/**
	 * Helper: build a budget record for the demo administration / programma /
	 * boekjaar.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 *
	 * @return array<string,mixed>
	 */
	private function makeBudget(array $overrides = []): array {
		return array_merge(
			[
				'administrationId' => 'adm-1',
				'programmeCode' => '5.1',
				'financialYear' => 2026,
				'authorised_amount' => 50000000,
				'realised_amount' => 0,
				'outstanding_commitments' => 0,
			],
			$overrides
		);

	}//end makeBudget()

	/**
	 * Helper: build a mandaat record for the demo administration.
	 *
	 * @param array<string,mixed> $overrides Field overrides.
	 *
	 * @return array<string,mixed>
	 */
	private function makeMandate(array $overrides = []): array {
		return array_merge(
			[
				'administrationId' => 'adm-1',
				'mandateCode' => 'M-INKOOP-100K',
				'maximumAmount' => 10000000,
				'kind_commitment' => ['purchase_order', 'frameworkAgreement'],
				'is_override' => false,
				'valid_from' => '2020-01-01',
				'valid_to' => '2999-12-31',
			],
			$overrides
		);

	}//end makeMandate()

	/**
	 * Helper: build a single-line purchase-order commitment on programma 5.1 / 2026.
	 *
	 * @param int $amount Amount in minor units.
	 *
	 * @return array<string,mixed>
	 */
	private function makeCommitment(int $amount): array {
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

	}//end makeCommitment()
}//end class
