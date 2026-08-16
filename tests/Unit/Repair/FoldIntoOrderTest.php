<?php

/**
 * Unit tests for FoldIntoOrder.
 *
 * Verifies that legacy Subsidie / PurchaseOrder / DBAOpdracht rows are folded
 * into the unified `Order` schema (orderType=subsidie|purchase|engagement)
 * with every source field preserved on the type-namespaced group, that
 * already-folded rows are skipped (idempotency via the migratedFrom marker),
 * that purchase amounts are correctly converted from integer cents to decimal
 * EUR on the shared totalAmount, and that per-row failures are handled
 * fail-softly (REQ-ORD-003).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/abstract-order-primitive/specs/order-primitive/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\FoldIntoOrder;
use OCA\Shillinq\Service\SettingsService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for FoldIntoOrder.
 *
 * Uses a fake, schema-aware ObjectService that stores records per schema and
 * supports simple dot-notation equality filters (as FoldIntoOrder's
 * idempotency check uses `migratedFrom.schema` / `migratedFrom.key`).
 */
class FoldIntoOrderTest extends TestCase {

	/**
	 * Settings service mock.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settingsService;

	/**
	 * Container mock.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Group manager mock (resolves the admin IUser).
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Output mock.
	 *
	 * @var IOutput&MockObject
	 */
	private IOutput&MockObject $output;

	/**
	 * Set up shared fixtures, including an admin group resolving one IUser.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->output = $this->createMock(IOutput::class);

		$this->settingsService->method('getRegisterSlug')->willReturn('shillinq');

		$admin = $this->createMock(IUser::class);
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$admin]);
		$this->groupManager->method('get')->with('admin')->willReturn($group);

	}//end setUp()

	/**
	 * Build a fake, schema-aware ObjectService.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $recordsBySchema Source records keyed by schema slug.
	 *
	 * @return object
	 */
	private function fakeObjectService(array $recordsBySchema): object {
		return new class($recordsBySchema) {
			/**
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $recordsBySchema;

			private string $currentSchema = '';

			/**
			 * @param array<string,array<int,array<string,mixed>>> $recordsBySchema
			 */
			public function __construct(array $recordsBySchema) {
				$this->recordsBySchema = $recordsBySchema;

			}//end __construct()

			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): static {
				$this->currentSchema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Mirrors OpenRegister's real findAll() paging semantics: `limit` is a
			 * literal SQL LIMIT (so limit=0 returns ZERO rows, NOT "unlimited"),
			 * `offset` skips rows, and both apply AFTER filtering. Modelling this
			 * faithfully is what catches a caller passing limit=0 and silently
			 * reading nothing.
			 *
			 * @param array<string,mixed> $params
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params): array {
				$rows = ($this->recordsBySchema[$this->currentSchema] ?? []);

				$filters = ($params['filters'] ?? []);
				if ($filters !== []) {
					$rows = array_values(
						array_filter(
							$rows,
							function (mixed $row) use ($filters): bool {
								if (is_array($row) === false) {
									return false;
								}

								foreach ($filters as $path => $expected) {
									// OpenRegister does NOT support dot-path filters on
									// nested properties: such a filter matches nothing.
									// Modelled faithfully so a caller relying on one is
									// caught here instead of silently on a live instance.
									if (str_contains((string)$path, '.') === true) {
										return false;
									}

									if ($this->dotGet($row, (string)$path) !== $expected) {
										return false;
									}
								}

								return true;
							}
						)
					);
				}

				$offset = (int)($params['offset'] ?? 0);
				if ($offset > 0) {
					$rows = array_slice($rows, $offset);
				}

				$limit = ($params['limit'] ?? null);
				if ($limit !== null) {
					$rows = array_slice($rows, 0, (int)$limit);
				}

				return array_values($rows);
			}//end findAll()

			/**
			 * @param array<string,mixed> $object
			 */
			public function saveObject(array $object, string $register, string $schema, bool $_rbac, bool $_multitenancy, mixed $currentUser): void {
				$this->recordsBySchema[$schema][] = $object;

			}//end saveObject()

			/**
			 * @return array<int,array<string,mixed>>
			 */
			public function saved(string $schema): array {
				return ($this->recordsBySchema[$schema] ?? []);
			}//end saved()

			/**
			 * @param array<string,mixed> $row
			 */
			private function dotGet(array $row, string $path): mixed {
				$segments = explode('.', $path);
				$cursor = $row;
				foreach ($segments as $segment) {
					if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
						return null;
					}

					$cursor = $cursor[$segment];
				}

				return $cursor;
			}//end dotGet()
		};

	}//end fakeObjectService()

	/**
	 * getName returns a descriptive string.
	 */
	public function testGetName(): void {
		$step = new FoldIntoOrder(
			settingsService: $this->settingsService,
			logger: $this->logger,
			groupManager: $this->groupManager,
			container: $this->container,
		);

		self::assertStringContainsString('Subsidie', $step->getName());
		self::assertStringContainsString('PurchaseOrder', $step->getName());
		self::assertStringContainsString('DBAOpdracht', $step->getName());

	}//end testGetName()

	/**
	 * A Subsidie row is folded into an Order(orderType=subsidie) with every
	 * field preserved on the `subsidie` group and no field dropped.
	 */
	public function testFoldsSubsidieIntoOrderLosslessly(): void {
		$subsidy = [
			'id' => 'sub-1',
			'administrationId' => 'adm-1',
			'direction' => 'outgoing',
			'subsidyNumber' => 'SUB-2026-001',
			'counterpartyName' => 'Stichting Cultuur Almelo',
			'schemeName' => 'Subsidieregeling cultuur 2026',
			'schemeArticle' => 'Art. 3.1',
			'requestDate' => '2026-02-01',
			'decisionDate' => '2026-03-15',
			'determinationDate' => '2026-10-01',
			'requestedAmount' => 25000.0,
			'grantedAmount' => 20000.0,
			'determinedAmount' => 18500.0,
			'paidOutAmount' => 18500.0,
			'reclaimedAmount' => null,
			'decisionUri' => 'docudesk://x/verlening.pdf',
			'determinationUri' => null,
			'prestatieverantwoording' => 'Aangewend zoals beschreven.',
			'rejectionReason' => null,
			'repaymentPlanId' => null,
			'hasRepaymentPlan' => false,
			'state' => 'determined',
			'currency' => 'EUR',
		];

		$fakeOs = $this->fakeObjectService(['Subsidie' => [$subsidy]]);
		$this->container->method('get')->willReturn($fakeOs);

		$step = new FoldIntoOrder(
			settingsService: $this->settingsService,
			logger: $this->logger,
			groupManager: $this->groupManager,
			container: $this->container,
		);
		$step->run($this->output);

		$saved = $fakeOs->saved('OrderPrimitive');
		self::assertCount(1, $saved);

		$order = $saved[0];
		self::assertSame('subsidy', $order['orderType']);
		self::assertSame('outgoing', $order['direction']);
		self::assertSame('SUB-2026-001', $order['orderNumber']);
		self::assertSame('Stichting Cultuur Almelo', $order['counterpartyName']);
		self::assertSame(20000.0, $order['totalAmount']);
		self::assertSame('determined', $order['state']);
		self::assertSame('Subsidie', $order['migratedFrom']['schema']);
		self::assertSame('SUB-2026-001', $order['migratedFrom']['key']);

		// No regulatory field dropped — every Subsidie field lands on the group.
		self::assertSame('Subsidieregeling cultuur 2026', $order['subsidy']['schemeName']);
		self::assertSame('Art. 3.1', $order['subsidy']['schemeArticle']);
		self::assertSame(25000.0, $order['subsidy']['requestedAmount']);
		self::assertSame(20000.0, $order['subsidy']['grantedAmount']);
		self::assertSame(18500.0, $order['subsidy']['determinedAmount']);
		self::assertSame(18500.0, $order['subsidy']['paidOutAmount']);
		self::assertSame('docudesk://x/verlening.pdf', $order['subsidy']['decisionUri']);
		self::assertSame('Aangewend zoals beschreven.', $order['subsidy']['prestatieverantwoording']);

	}//end testFoldsSubsidieIntoOrderLosslessly()

	/**
	 * A PurchaseOrder's integer-cent totalInclVat is converted to decimal EUR
	 * on the shared totalAmount, while the original cent value is preserved
	 * verbatim inside the `purchase` group (ADR-022 money-unit boundary).
	 */
	public function testFoldsPurchaseOrderWithCentToEuroConversion(): void {
		$po = [
			'id' => 'po-1',
			'administrationId' => 'adm-1',
			'poNumber' => 'PO-2026-0001',
			'supplierId' => 'vendor-001',
			'supplierReference' => 'ACK-NW-9914',
			'currency' => 'EUR',
			'totalExclVat' => 4000000,
			'totalVat' => 840000,
			'totalInclVat' => 4840000,
			'costCenter' => 'CC-IT-OPERATIONS',
			'projectCode' => 'PRJ-OFFICE-REFRESH-2026',
			'paymentTerms' => '30 days net',
			'statusCode' => 'approved',
		];

		$fakeOs = $this->fakeObjectService(['PurchaseOrder' => [$po]]);
		$this->container->method('get')->willReturn($fakeOs);

		$step = new FoldIntoOrder(
			settingsService: $this->settingsService,
			logger: $this->logger,
			groupManager: $this->groupManager,
			container: $this->container,
		);
		$step->run($this->output);

		$saved = $fakeOs->saved('OrderPrimitive');
		self::assertCount(1, $saved);

		$order = $saved[0];
		self::assertSame('purchase', $order['orderType']);
		self::assertSame('incoming', $order['direction']);
		self::assertSame('PO-2026-0001', $order['orderNumber']);
		self::assertSame(48400.0, $order['totalAmount'], 'totalAmount must be decimal EUR (cents / 100)');
		self::assertSame('approved', $order['state']);
		self::assertSame('CC-IT-OPERATIONS', $order['costCenter']);
		self::assertSame('PRJ-OFFICE-REFRESH-2026', $order['projectReference']);
		self::assertSame('30 days net', $order['paymentTerms']);

		// Original integer-cent fields preserved verbatim.
		self::assertSame(4000000, $order['purchase']['totalExclVat']);
		self::assertSame(840000, $order['purchase']['totalVat']);
		self::assertSame(4840000, $order['purchase']['totalInclVat']);

	}//end testFoldsPurchaseOrderWithCentToEuroConversion()

	/**
	 * A DBAOpdracht row is folded into an Order(orderType=engagement) with
	 * its intakeStatus lifecycle vocabulary preserved verbatim on state.
	 */
	public function testFoldsDbaOpdrachtIntoEngagementOrder(): void {
		$assignment = [
			'id' => 'dba-opdr-2026-0042',
			'administrationId' => 'adm-1',
			'enterpriseId' => 'ond-nl-001234',
			'customerId' => 'klant-acme-bv',
			'assignmentName' => 'Backend ontwikkeling betaalmodule',
			'startDate' => '2026-03-01',
			'expectedRevenue' => 4800000,
			'intakeStatus' => 'ACTIVE',
			'riskLevel' => 'LOW_MIDDEN',
			'modelAgreementId' => 'modov-bd-2024-tussenkomstvrij-v3',
		];

		$fakeOs = $this->fakeObjectService(['DBAOpdracht' => [$assignment]]);
		$this->container->method('get')->willReturn($fakeOs);

		$step = new FoldIntoOrder(
			settingsService: $this->settingsService,
			logger: $this->logger,
			groupManager: $this->groupManager,
			container: $this->container,
		);
		$step->run($this->output);

		$saved = $fakeOs->saved('OrderPrimitive');
		self::assertCount(1, $saved);

		$order = $saved[0];
		self::assertSame('engagement', $order['orderType']);
		self::assertSame('DBA-dba-opdr-2026-0042', $order['orderNumber']);
		self::assertSame('ACTIVE', $order['state'], 'engagement state vocabulary preserved verbatim');
		self::assertSame(48000.0, $order['totalAmount']);
		self::assertSame('LOW_MIDDEN', $order['engagement']['riskLevel']);
		self::assertSame('modov-bd-2024-tussenkomstvrij-v3', $order['engagement']['modelAgreementId']);
		self::assertSame('DBAOpdracht', $order['migratedFrom']['schema']);

	}//end testFoldsDbaOpdrachtIntoEngagementOrder()

	/**
	 * A row that already has a matching folded Order (migratedFrom marker) is
	 * skipped — idempotency.
	 */
	public function testSkipsAlreadyFoldedRows(): void {
		$subsidy = [
			'id' => 'sub-2',
			'subsidyNumber' => 'SUB-2026-002',
		];
		$alreadyFolded = [
			'orderType' => 'subsidy',
			'orderNumber' => 'SUB-2026-002',
			'migratedFrom' => ['schema' => 'Subsidie', 'key' => 'SUB-2026-002'],
		];

		$fakeOs = $this->fakeObjectService(
			[
				'Subsidie' => [$subsidy],
				'OrderPrimitive' => [$alreadyFolded],
			]
		);
		$this->container->method('get')->willReturn($fakeOs);

		$step = new FoldIntoOrder(
			settingsService: $this->settingsService,
			logger: $this->logger,
			groupManager: $this->groupManager,
			container: $this->container,
		);
		$step->run($this->output);

		self::assertCount(1, $fakeOs->saved('OrderPrimitive'), 'no second Order must be created for an already-folded row');

	}//end testSkipsAlreadyFoldedRows()

	/**
	 * A row without any resolvable id is skipped with a warning, not fatal.
	 */
	public function testSkipsRowWithoutId(): void {
		$fakeOs = $this->fakeObjectService(['Subsidie' => [['someField' => 'x']]]);
		$this->container->method('get')->willReturn($fakeOs);

		$this->output->expects(self::atLeastOnce())->method('warning');

		$step = new FoldIntoOrder(
			settingsService: $this->settingsService,
			logger: $this->logger,
			groupManager: $this->groupManager,
			container: $this->container,
		);
		$step->run($this->output);

		self::assertCount(0, $fakeOs->saved('OrderPrimitive'));

	}//end testSkipsRowWithoutId()

	/**
	 * Every source row is folded, including rows beyond the first read batch.
	 *
	 * Regression guard for the live-verified defect where readRows() passed
	 * `'limit' => 0` meaning "unlimited": OpenRegister forwards it as a literal
	 * SQL LIMIT 0, so the fold read ZERO rows and still reported
	 * "0 migrated, 0 skipped, 0 failed" — a silent, green no-op on every real
	 * instance. Seeding more rows than one batch also proves the offset paging
	 * actually advances instead of re-reading page one forever.
	 */
	public function testFoldsEverySourceRowAcrossReadBatches(): void {
		$total = (FoldIntoOrder::READ_BATCH_SIZE + 5);
		$subsidies = [];
		for ($i = 0; $i < $total; $i++) {
			$subsidies[] = [
				'id' => 'sub-' . $i,
				'administrationId' => 'adm-1',
				'direction' => 'outgoing',
				'subsidyNumber' => sprintf('SUB-2026-%04d', $i),
				'counterpartyName' => 'Stichting ' . $i,
				'requestedAmount' => 1000.0,
				'grantedAmount' => 900.0,
				'state' => 'granted',
				'currency' => 'EUR',
			];
		}

		$fakeOs = $this->fakeObjectService(['Subsidie' => $subsidies]);
		$this->container->method('get')->willReturn($fakeOs);

		$step = new FoldIntoOrder(
			settingsService: $this->settingsService,
			logger: $this->logger,
			groupManager: $this->groupManager,
			container: $this->container,
		);
		$step->run($this->output);

		$saved = $fakeOs->saved('OrderPrimitive');
		self::assertCount($total, $saved, 'every source row must be folded, not just the first batch');

		// The last row must be present — proves paging reached the final page.
		$numbers = array_column($saved, 'orderNumber');
		self::assertContains(sprintf('SUB-2026-%04d', ($total - 1)), $numbers);

	}//end testFoldsEverySourceRowAcrossReadBatches()

	/**
	 * Rows arriving as OpenRegister ObjectEntity instances are folded.
	 *
	 * Regression guard for the live-verified defect where foldRows() did
	 * `(array) $row`. OpenRegister's findAll() returns ObjectEntity objects whose
	 * payload lives in getObject(); casting one to array yields mangled
	 * "\0*\0prop" keys, so every field — including the id — vanished and every
	 * row was rejected as "row without a stable id" while the step still printed
	 * a clean summary.
	 *
	 * The double below mirrors Nextcloud's Entity base by exposing getObject()
	 * and getUuid() through __call(), which is what makes method_exists() return
	 * FALSE for them on the real class.
	 */
	public function testFoldsRowsDeliveredAsObjectEntities(): void {
		$payload = [
			'id' => 'sub-obj-1',
			'administrationId' => 'adm-1',
			'direction' => 'outgoing',
			'subsidyNumber' => 'SUB-2026-777',
			'counterpartyName' => 'Stichting Entity',
			'requestedAmount' => 5000.0,
			'grantedAmount' => 4500.0,
			'state' => 'granted',
			'currency' => 'EUR',
		];

		$entity = new class($payload) {

			/**
			 * @var array<string,mixed>
			 */
			private array $payload;

			/**
			 * @param array<string,mixed> $payload
			 */
			public function __construct(array $payload) {
				$this->payload = $payload;

			}//end __construct()

			/**
			 * Mirrors OCP\AppFramework\Db\Entity: getters resolve via __call, so
			 * method_exists() reports false for them.
			 *
			 * @param array<int,mixed> $arguments
			 */
			public function __call(string $name, array $arguments): mixed {
				if ($name === 'getObject') {
					return $this->payload;
				}

				if ($name === 'getUuid') {
					return 'uuid-obj-1';
				}

				throw new \BadMethodCallException($name);
			}//end __call()
		};

		$fakeOs = $this->fakeObjectService(['Subsidie' => [$entity]]);
		$this->container->method('get')->willReturn($fakeOs);

		$step = new FoldIntoOrder(
			settingsService: $this->settingsService,
			logger: $this->logger,
			groupManager: $this->groupManager,
			container: $this->container,
		);
		$step->run($this->output);

		$saved = $fakeOs->saved('OrderPrimitive');
		self::assertCount(1, $saved, 'an ObjectEntity row must fold, not be skipped as id-less');
		self::assertSame('SUB-2026-777', $saved[0]['orderNumber']);
		self::assertSame('subsidy', $saved[0]['orderType']);
		self::assertSame('Stichting Entity', $saved[0]['counterpartyName']);

	}//end testFoldsRowsDeliveredAsObjectEntities()

	/**
	 * Re-running the step folds nothing again — for EVERY source type.
	 *
	 * Regression guard for the live-verified idempotency defect. The original
	 * check filtered on `migratedFrom.key`, which OpenRegister cannot match
	 * (no dot-path filter support), so every run re-folded every row and each
	 * `occ upgrade` added a duplicate set of financial records. Filtering on the
	 * top-level `orderNumber` instead still duplicated DBAOpdracht, whose
	 * orderNumber is prefixed "DBA-" and therefore never equals its migration
	 * key — which is why the marker set is read up front instead.
	 */
	public function testSecondRunIsIdempotentForEverySourceType(): void {
		$records = [
			'Subsidie' => [
				[
					'id' => 'sub-idem-1',
					'administrationId' => 'adm-1',
					'direction' => 'outgoing',
					'subsidyNumber' => 'SUB-IDEM-1',
					'counterpartyName' => 'Stichting Idem',
					'grantedAmount' => 100.0,
					'state' => 'granted',
					'currency' => 'EUR',
				],
			],
			'DBAOpdracht' => [
				[
					'id' => 'dba-idem-1',
					'enterpriseId' => 'onp-1',
					'customerId' => 'kl-1',
					'assignmentName' => 'Opdracht Idem',
					'startDate' => '2026-02-01',
					'intakeStatus' => 'ACTIVE',
					'riskLevel' => 'LOW',
				],
			],
		];

		$fakeOs = $this->fakeObjectService($records);
		$this->container->method('get')->willReturn($fakeOs);

		$step = new FoldIntoOrder(
			settingsService: $this->settingsService,
			logger: $this->logger,
			groupManager: $this->groupManager,
			container: $this->container,
		);

		$step->run($this->output);
		$afterFirst = count($fakeOs->saved('OrderPrimitive'));
		self::assertSame(2, $afterFirst, 'first run folds one Order per source row');

		$step->run($this->output);
		self::assertCount(
			$afterFirst,
			$fakeOs->saved('OrderPrimitive'),
			'a second run must fold nothing again — no duplicate financial records'
		);

	}//end testSecondRunIsIdempotentForEverySourceType()

	/**
	 * Empty source schemas are handled gracefully (fresh-tenant no-op).
	 */
	public function testEmptySourcesHandledGracefully(): void {
		$fakeOs = $this->fakeObjectService([]);
		$this->container->method('get')->willReturn($fakeOs);

		$step = new FoldIntoOrder(
			settingsService: $this->settingsService,
			logger: $this->logger,
			groupManager: $this->groupManager,
			container: $this->container,
		);
		$step->run($this->output);

		self::assertCount(0, $fakeOs->saved('OrderPrimitive'));

	}//end testEmptySourcesHandledGracefully()

	/**
	 * When no admin user can be resolved, the step warns and does nothing
	 * (never fatal at Nextcloud upgrade time).
	 */
	public function testNoAdminUserSkipsGracefully(): void {
		$emptyGroupManager = $this->createMock(IGroupManager::class);
		$emptyGroupManager->method('get')->with('admin')->willReturn(null);

		$this->output->expects(self::atLeastOnce())->method('warning');

		$step = new FoldIntoOrder(
			settingsService: $this->settingsService,
			logger: $this->logger,
			groupManager: $emptyGroupManager,
			container: $this->container,
		);
		$step->run($this->output);

	}//end testNoAdminUserSkipsGracefully()
}//end class
