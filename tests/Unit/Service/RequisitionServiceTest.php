<?php

/**
 * Unit tests for RequisitionService.
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
 * @spec openspec/specs/purchase-requisition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Lifecycle\BudgetBlocker;
use OCA\Shillinq\Lifecycle\MandateEnforcer;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\RequisitionService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the OpenRegister-backed requisition service, exercising the REAL
 * (unmodified) BudgetBlocker so the budget-availability gate on approve() is
 * proven end-to-end, not mocked away (ADR-009).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class RequisitionServiceTest extends TestCase {

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
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Build an in-memory ObjectService stub honouring equality filters.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 * @param array<int,array<string,mixed>> $saved Captured saves (by reference).
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $data, array &$saved): object {
		return new class($data, $saved) {
			/**
			 * Schema => rows.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $data;

			/**
			 * Captured saves (mutable ref).
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $saved;

			/**
			 * Active schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Auto-increment id counter for saved objects.
			 *
			 * @var integer
			 */
			private int $idCounter = 0;

			/**
			 * Constructor.
			 *
			 * @param array<string,array<int,array<string,mixed>>> $data Schema rows.
			 * @param array<int,array<string,mixed>> $saved Capture ref.
			 */
			public function __construct(array $data, array &$saved) {
				$this->data = $data;
				$this->saved = &$saved;
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
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return rows for the active schema, applying equality filters.
			 *
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$rows = ($this->data[$this->schema] ?? []);
				$filters = ($params['filters'] ?? []);
				if ($filters === []) {
					return $rows;
				}

				return array_values(
					array_filter(
						$rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}//end findAll()

			/**
			 * Capture a saved object; stamp an id when absent.
			 *
			 * @param array<string,mixed> $object Object payload.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				if (isset($object['id']) === false || $object['id'] === '') {
					$this->idCounter++;
					$object['id'] = 'req-obj-' . $this->idCounter;
				} else {
					// Update in place when id already present (approve/reject/submit).
					foreach (($this->data[$this->schema] ?? []) as $index => $row) {
						if (($row['id'] ?? null) === $object['id']) {
							$this->data[$this->schema][$index] = $object;
							$this->saved[] = ['schema' => $this->schema, 'object' => $object];
							return $object;
						}
					}
				}

				$this->data[$this->schema][] = $object;
				$this->saved[] = ['schema' => $this->schema, 'object' => $object];
				return $object;
			}//end saveObject()
		};

	}//end buildObjectServiceStub()

	/**
	 * Build the service over an in-memory ObjectService stub, with a REAL
	 * BudgetBlocker/MandateEnforcer wired to the same stub.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 * @param array<int,array<string,mixed>> $saved Captured saves (by reference).
	 * @param string $userId Authenticated uid.
	 * @param array<int,string> $accessibleAdministrations Tenants canAccess returns true for.
	 *
	 * @return RequisitionService
	 */
	private function buildService(
		array $data,
		array &$saved,
		string $userId,
		array $accessibleAdministrations,
	): RequisitionService {
		$stub = $this->buildObjectServiceStub($data, $saved);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('currentUserId')->willReturn($userId);
		$administrationContext->method('canAccess')->willReturnCallback(
			static function (string $administrationId) use ($accessibleAdministrations): bool {
				return in_array($administrationId, $accessibleAdministrations, true);
			}
		);

		$mandate = new MandateEnforcer(container: $container, appConfig: $this->appConfig, logger: $this->logger);
		$budget = new BudgetBlocker(container: $container, appConfig: $this->appConfig, logger: $this->logger, mandate: $mandate);

		return new RequisitionService(
			appConfig: $this->appConfig,
			administrationContext: $administrationContext,
			budgetBlocker: $budget,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * Verifies createRequisition() computes totaalbedrag_excl_btw as the sum
	 * of its lines and persists each RequisitionLine.
	 *
	 * @return void
	 */
	public function testCreateRequisitionComputesTotalAndPersistsLines(): void {
		$saved = [];
		$service = $this->buildService(data: [], saved: $saved, userId: 'employee-1', accessibleAdministrations: ['adm-1']);

		$requisition = $service->createRequisition(
			administrationId: 'adm-1',
			payload: [
				'programme' => '5.1',
				'financialYear' => 2026,
				'neededByDate' => '2026-08-15',
				'justification' => 'Onboarding laptops',
				'kind' => 'inkoop',
				'lines' => [
					['description' => 'Laptop', 'quantity' => 2, 'unitPrice' => 1000.00, 'glAccountSuggestion' => '4400'],
					['description' => 'Docking station', 'quantity' => 2, 'unitPrice' => 150.00, 'glAccountSuggestion' => '4400'],
				],
			]
		);

		self::assertSame(230000, $requisition['total_amount_excl_vat']);
		self::assertSame('draft', $requisition['statusCode']);
		self::assertSame('employee-1', $requisition['requester']);

		$lineSaves = array_filter($saved, static fn ($s) => $s['schema'] === 'RequisitionLine');
		self::assertCount(2, $lineSaves);

	}//end testCreateRequisitionComputesTotalAndPersistsLines()

	/**
	 * Verifies createRequisition() refuses a caller with no access to the
	 * administration.
	 *
	 * @return void
	 */
	public function testCreateRequisitionDeniesCrossTenantAccess(): void {
		$saved = [];
		$service = $this->buildService(data: [], saved: $saved, userId: 'employee-1', accessibleAdministrations: ['adm-2']);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Administration not found');

		$service->createRequisition(
			administrationId: 'adm-1',
			payload: [
				'programme' => '5.1',
				'financialYear' => 2026,
				'neededByDate' => '2026-08-15',
				'justification' => 'x',
				'kind' => 'inkoop',
				'lines' => [
					['description' => 'x', 'quantity' => 1, 'unitPrice' => 1, 'glAccountSuggestion' => '4400'],
				],
			]
		);

	}//end testCreateRequisitionDeniesCrossTenantAccess()

	/**
	 * Verifies submitRequisition() moves draft -> submitted when a positive
	 * total exists.
	 *
	 * @return void
	 */
	public function testSubmitRequisitionMovesFromDraftToSubmitted(): void {
		$saved = [];
		$data = [
			'Requisition' => [
				[
					'id' => 'req-1',
					'administrationId' => 'adm-1',
					'statusCode' => 'draft',
					'total_amount_excl_vat' => 50000,
					'programme' => '5.1',
					'financialYear' => 2026,
					'kind' => 'inkoop',
				],
			],
		];
		$service = $this->buildService(data: $data, saved: $saved, userId: 'employee-1', accessibleAdministrations: ['adm-1']);

		$updated = $service->submitRequisition(administrationId: 'adm-1', requisitionId: 'req-1');

		self::assertSame('submitted', $updated['statusCode']);

	}//end testSubmitRequisitionMovesFromDraftToSubmitted()

	/**
	 * Verifies submitRequisition() refuses a requisition that is not in
	 * draft.
	 *
	 * @return void
	 */
	public function testSubmitRequisitionRejectsWhenNotDraft(): void {
		$saved = [];
		$data = [
			'Requisition' => [
				['id' => 'req-1', 'administrationId' => 'adm-1', 'statusCode' => 'submitted', 'total_amount_excl_vat' => 50000],
			],
		];
		$service = $this->buildService(data: $data, saved: $saved, userId: 'employee-1', accessibleAdministrations: ['adm-1']);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Requisition can only be submitted from draft');

		$service->submitRequisition(administrationId: 'adm-1', requisitionId: 'req-1');

	}//end testSubmitRequisitionRejectsWhenNotDraft()

	/**
	 * Verifies approveRequisition() approves when the requisition fits the
	 * seeded Budget's free room (REQ-REQ-003 — reused, unmodified
	 * BudgetBlocker).
	 *
	 * @return void
	 */
	public function testApproveRequisitionWithinBudgetApproves(): void {
		$saved = [];
		$data = [
			'Requisition' => [
				[
					'id' => 'req-1',
					'administrationId' => 'adm-1',
					'statusCode' => 'submitted',
					'programme' => '5.1',
					'financialYear' => 2026,
					'kind' => 'inkoop',
					'total_amount_excl_vat' => 500000,
				],
			],
			'CommitmentBudget' => [
				[
					'administrationId' => 'adm-1',
					'programmeCode' => '5.1',
					'financialYear' => 2026,
					'authorised_amount' => 1000000,
					'realised_amount' => 0,
					'outstanding_commitments' => 0,
				],
			],
		];
		$service = $this->buildService(data: $data, saved: $saved, userId: 'controller-1', accessibleAdministrations: ['adm-1']);

		$updated = $service->approveRequisition(administrationId: 'adm-1', requisitionId: 'req-1', approverId: 'controller-1');

		self::assertSame('approved', $updated['statusCode']);
		self::assertSame('controller-1', $updated['approvedBy']);
		self::assertNotEmpty($updated['approvedAt']);

	}//end testApproveRequisitionWithinBudgetApproves()

	/**
	 * Verifies approveRequisition() is BLOCKED when the requisition total
	 * exceeds the matching Budget's free room — the core acceptance scenario
	 * for
	 * "reuse the existing budget infra" (REQ-REQ-003).
	 *
	 * @return void
	 */
	public function testApproveRequisitionOverBudgetIsBlocked(): void {
		$saved = [];
		$data = [
			'Requisition' => [
				[
					'id' => 'req-1',
					'administrationId' => 'adm-1',
					'statusCode' => 'submitted',
					'programme' => '5.1',
					'financialYear' => 2026,
					'kind' => 'inkoop',
					// 20,000.00 EUR requested against a 10,000.00 EUR free room.
					'total_amount_excl_vat' => 2000000,
				],
			],
			'CommitmentBudget' => [
				[
					'administrationId' => 'adm-1',
					'programmeCode' => '5.1',
					'financialYear' => 2026,
					'authorised_amount' => 1000000,
					'realised_amount' => 0,
					'outstanding_commitments' => 0,
				],
			],
		];
		$service = $this->buildService(data: $data, saved: $saved, userId: 'controller-1', accessibleAdministrations: ['adm-1']);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Requisition exceeds available budget');

		try {
			$service->approveRequisition(administrationId: 'adm-1', requisitionId: 'req-1', approverId: 'controller-1');
		} finally {
			// Status must remain 'submitted' — the requisition is never
			// silently approved when the budget check fails (fail-closed).
			$stillSubmitted = $data['Requisition'][0];
			foreach ($saved as $entry) {
				if ($entry['schema'] === 'Requisition' && ($entry['object']['id'] ?? '') === 'req-1') {
					$stillSubmitted = $entry['object'];
				}
			}

			self::assertSame('submitted', $stillSubmitted['statusCode']);
		}//end try

	}//end testApproveRequisitionOverBudgetIsBlocked()

	/**
	 * Verifies approveRequisition() refuses a requisition that is not
	 * submitted.
	 *
	 * @return void
	 */
	public function testApproveRequisitionRejectsWhenNotSubmitted(): void {
		$saved = [];
		$data = [
			'Requisition' => [
				['id' => 'req-1', 'administrationId' => 'adm-1', 'statusCode' => 'draft', 'total_amount_excl_vat' => 50000],
			],
		];
		$service = $this->buildService(data: $data, saved: $saved, userId: 'controller-1', accessibleAdministrations: ['adm-1']);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Requisition can only be approved from submitted');

		$service->approveRequisition(administrationId: 'adm-1', requisitionId: 'req-1', approverId: 'controller-1');

	}//end testApproveRequisitionRejectsWhenNotSubmitted()

	/**
	 * Verifies rejectRequisition() requires a non-blank reason.
	 *
	 * @return void
	 */
	public function testRejectRequisitionRequiresReason(): void {
		$saved = [];
		$data = [
			'Requisition' => [
				['id' => 'req-1', 'administrationId' => 'adm-1', 'statusCode' => 'submitted', 'total_amount_excl_vat' => 50000],
			],
		];
		$service = $this->buildService(data: $data, saved: $saved, userId: 'controller-1', accessibleAdministrations: ['adm-1']);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('rejectionReason is required');

		$service->rejectRequisition(administrationId: 'adm-1', requisitionId: 'req-1', rejectorId: 'controller-1', reason: '   ');

	}//end testRejectRequisitionRequiresReason()

	/**
	 * Verifies rejectRequisition() sets statusCode=rejected with the reason
	 * and actor.
	 *
	 * @return void
	 */
	public function testRejectRequisitionSetsRejected(): void {
		$saved = [];
		$data = [
			'Requisition' => [
				['id' => 'req-1', 'administrationId' => 'adm-1', 'statusCode' => 'submitted', 'total_amount_excl_vat' => 50000],
			],
		];
		$service = $this->buildService(data: $data, saved: $saved, userId: 'controller-1', accessibleAdministrations: ['adm-1']);

		$updated = $service->rejectRequisition(
			administrationId: 'adm-1',
			requisitionId: 'req-1',
			rejectorId: 'controller-1',
			reason: 'Not urgent this quarter'
		);

		self::assertSame('rejected', $updated['statusCode']);
		self::assertSame('controller-1', $updated['rejectedBy']);
		self::assertSame('Not urgent this quarter', $updated['rejectionReason']);

	}//end testRejectRequisitionSetsRejected()
}//end class
