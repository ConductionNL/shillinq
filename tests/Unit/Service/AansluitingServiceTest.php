<?php

/**
 * Unit tests for AansluitingService.
 *
 * Exercises both aansluitingType resolvers (btw-ledger-aangifte and
 * subledger-gl-control), the tolerance -> status decision, and the
 * open -> explained -> resolved -> reopen lifecycle (REQ-AANS-002,
 * REQ-AANS-004, REQ-AANS-005, REQ-AANS-006). Uses an inline fake
 * ObjectService stub so the real OR-API call shape (find / findAll /
 * saveObject) stays honest, matching VatSuppletieDetectionServiceTest's
 * pattern.
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
 * @spec openspec/changes/bookkeeping-aansluitingen/specs/bookkeeping-aansluitingen/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Lifecycle\AansluitingResolutionGuard;
use OCA\Shillinq\Service\AansluitingCalculator;
use OCA\Shillinq\Service\AansluitingService;
use OCA\Shillinq\Service\VATReturnService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests AansluitingService against an inline ObjectService fake.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AansluitingServiceTest extends TestCase {

	/**
	 * Mock container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');

	}//end setUp()

	/**
	 * Build the AansluitingService wired against a fake ObjectService.
	 *
	 * @param object $stub The ObjectService fake.
	 *
	 * @return AansluitingService
	 */
	private function buildService(object $stub): AansluitingService {
		$this->container->method('get')->willReturn($stub);

		$vatReturnService = new VATReturnService(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($stub),
		);

		$guard = new AansluitingResolutionGuard(logger: $this->logger);

		return new AansluitingService(
			appConfig: $this->appConfig,
			logger: $this->logger,
			calculator: new AansluitingCalculator(),
			vatReturnService: $vatReturnService,
			resolutionGuard: $guard,
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * Build the inline ObjectService stub, pre-seeded with the given rows
	 * per schema.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $seed Rows keyed by schema slug.
	 *
	 * @return object
	 */
	private function fakeObjectService(array $seed): object {
		return new class($seed) {
			/**
			 * Records keyed by schema slug.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $data;

			/**
			 * Auto-increment counter for synthetic ids.
			 *
			 * @var integer
			 */
			private int $idCounter = 0;

			/**
			 * Active schema (set via setSchema()).
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string,array<int,array<string,mixed>>> $seed Rows keyed by schema slug.
			 */
			public function __construct(array $seed) {
				$defaults = [
					'Aansluiting' => [],
					'AansluitingResult' => [],
					'Account' => [],
					'GLTransaction' => [],
					'GLLine' => [],
					'BtwAangifte' => [],
					'VATDeclaration' => [],
					'ARInvoice' => [],
					'APTransaction' => [],
					'VatCorrection' => [],
				];
				$this->data = array_merge($defaults, $seed);
			}//end __construct()

			/**
			 * Fluent register setter (no-op).
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter; records the active schema.
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
			 * Return the data set for the active schema, applying simple equality filters.
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
			 * Find a single record by id.
			 *
			 * @param string $id Record id.
			 *
			 * @return array<string,mixed>|null
			 */
			public function find(string $id): ?array {
				foreach (($this->data[$this->schema] ?? []) as $row) {
					if (((string)($row['id'] ?? '')) === $id) {
						return $row;
					}
				}

				return null;
			}//end find()

			/**
			 * Save a record (insert or update) and return the persisted shape.
			 *
			 * @param array<string,mixed> $data Record body.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $data): array {
				if (isset($data['id']) === false || $data['id'] === '') {
					$this->idCounter++;
					$data['id'] = $this->schema . '-' . $this->idCounter;
				}

				foreach (($this->data[$this->schema] ?? []) as $idx => $row) {
					if (((string)($row['id'] ?? '')) === ((string)$data['id'])) {
						$this->data[$this->schema][$idx] = $data;
						return $data;
					}
				}

				$this->data[$this->schema][] = $data;
				return $data;
			}//end saveObject()

			/**
			 * Expose the live data set (for assertions).
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function dump(string $schema): array {
				return ($this->data[$schema] ?? []);
			}//end dump()
		};

	}//end fakeObjectService()

	/**
	 * btw-ledger-aangifte: the ledger's current recompute (4200.0 collected
	 *
	 * @21%) diverges from the as-filed declaration (4450.0) by more than the
	 * EUR 1 tolerance, so compute() persists an `open` AansluitingResult with
	 * a matching TOTAL + rubriek-bucket drill-down (REQ-AANS-002, REQ-AANS-004).
	 *
	 * @return void
	 */
	public function testComputeBtwLedgerAangifteReportsOpenDrift(): void {
		$stub = $this->fakeObjectService(
			[
				'Aansluiting' => [
					[
						'id' => 'aansl-btw-1',
						'reconciliationType' => 'vat-ledger-return',
						'toleranceCents' => 100,
						'expectedRelationship' => 'equal',
						'administrationId' => 'adm-1',
					],
				],
				'Account' => [
					['accountNumber' => '4000', 'accountType' => 'revenue', 'vatApplicable' => true, 'administrationId' => 'adm-1'],
				],
				'GLTransaction' => [
					[
						'id' => 'gltx-1',
						'administrationId' => 'adm-1',
						'transactionDate' => '2026-06-15',
						'lines' => [
							['accountNumber' => '4000', 'taxableAmount' => 20000.0, 'taxRate' => 21.0],
						],
					],
				],
				'BtwAangifte' => [
					[
						'id' => 'vatret-1',
						'administrationId' => 'adm-1',
						'statusCode' => 'submitted',
						'period' => 'quarter',
						'periodYear' => 2026,
						'periodNumber' => 2,
						'startDate' => '2026-04-01',
						'endDate' => '2026-06-30',
					],
				],
				'VATDeclaration' => [
					[
						'id' => 'vd-1',
						'returnId' => 'vatret-1',
						'type' => 'collected',
						'taxRate' => 21.0,
						'totalVATAmount' => 4450.0,
						'totalTaxableAmount' => 21190.48,
						'lineCount' => 1,
					],
				],
			]
		);

		$service = $this->buildService(stub: $stub);
		$result = $service->compute(reconciliationId: 'aansl-btw-1', periodId: '2026-Q2');

		self::assertSame(4200.0, $result['sourceATotal']);
		self::assertSame(4450.0, $result['sourceBTotal']);
		self::assertSame(-25000, $result['differenceCents']);
		self::assertFalse($result['withinTolerance']);
		self::assertSame('open', $result['status']);

		$byKey = [];
		foreach ($result['lineDeltas'] as $row) {
			$byKey[$row['bucketKey']] = $row;
		}

		self::assertArrayHasKey('TOTAL', $byKey);
		self::assertArrayHasKey('collected:21.00', $byKey);
		self::assertSame(4200.0, $byKey['collected:21.00']['sourceAAmount']);
		self::assertSame(4450.0, $byKey['collected:21.00']['sourceBAmount']);

	}//end testComputeBtwLedgerAangifteReportsOpenDrift()

	/**
	 * btw-ledger-aangifte: when a VatCorrection already exists for the same
	 * VATReturn (created by btw-suppletie-detection), the AansluitingResult
	 * cross-references it rather than duplicating the correction workflow
	 * (REQ-AANS-007).
	 *
	 * @return void
	 */
	public function testComputeBtwLedgerAangifteLinksExistingVatCorrection(): void {
		$stub = $this->fakeObjectService(
			[
				'Aansluiting' => [
					[
						'id' => 'aansl-btw-1',
						'reconciliationType' => 'vat-ledger-return',
						'toleranceCents' => 100,
						'expectedRelationship' => 'equal',
						'administrationId' => 'adm-1',
					],
				],
				'Account' => [
					['accountNumber' => '4000', 'accountType' => 'revenue', 'vatApplicable' => true, 'administrationId' => 'adm-1'],
				],
				'GLTransaction' => [
					[
						'id' => 'gltx-1',
						'administrationId' => 'adm-1',
						'transactionDate' => '2026-06-15',
						'lines' => [
							['accountNumber' => '4000', 'taxableAmount' => 20000.0, 'taxRate' => 21.0],
						],
					],
				],
				'BtwAangifte' => [
					[
						'id' => 'vatret-1',
						'administrationId' => 'adm-1',
						'statusCode' => 'submitted',
						'period' => 'quarter',
						'periodYear' => 2026,
						'periodNumber' => 2,
						'startDate' => '2026-04-01',
						'endDate' => '2026-06-30',
					],
				],
				'VATDeclaration' => [
					[
						'id' => 'vd-1',
						'returnId' => 'vatret-1',
						'type' => 'collected',
						'taxRate' => 21.0,
						'totalVATAmount' => 4450.0,
						'totalTaxableAmount' => 21190.48,
						'lineCount' => 1,
					],
				],
				'VatCorrection' => [
					['id' => 'vatcorr-1', 'originalVatReturnId' => 'vatret-1', 'state' => 'draft'],
				],
			]
		);

		$service = $this->buildService(stub: $stub);
		$result = $service->compute(reconciliationId: 'aansl-btw-1', periodId: '2026-Q2');

		self::assertSame('vatcorr-1', $result['relatedVatCorrectionId']);

	}//end testComputeBtwLedgerAangifteLinksExistingVatCorrection()

	/**
	 * subledger-gl-control (AR, 'equal'): the 1300 control account's
	 * cumulative debit balance exactly equals the open ARInvoice total, so
	 * compute() auto-resolves (REQ-AANS-004, REQ-AANS-005) — this is the
	 * comparison PeriodCloseAssistantService::detectOpenSubLedger() never makes.
	 *
	 * @return void
	 */
	public function testComputeSubledgerGlControlArWithinTolerance(): void {
		$stub = $this->fakeObjectService(
			[
				'Aansluiting' => [
					[
						'id' => 'aansl-ar-1',
						'reconciliationType' => 'subledger-gl-control',
						'controlAccountNumber' => '1300',
						'subLedgerType' => 'ar',
						'toleranceCents' => 100,
						'expectedRelationship' => 'equal',
						'administrationId' => 'adm-2',
					],
				],
				'GLTransaction' => [
					['id' => 'gltx-2', 'administrationId' => 'adm-2'],
				],
				'GLLine' => [
					['transactionId' => 'gltx-2', 'accountNumber' => '1300', 'side' => 'debit', 'amount' => 18500.0],
				],
				'ARInvoice' => [
					['id' => 'inv-1', 'administrationId' => 'adm-2', 'lifecycleState' => 'issued', 'grossAmount' => 10000.0],
					['id' => 'inv-2', 'administrationId' => 'adm-2', 'lifecycleState' => 'overdue', 'grossAmount' => 8500.0],
					// Paid invoices must not count toward the open subledger total.
					['id' => 'inv-3', 'administrationId' => 'adm-2', 'lifecycleState' => 'paid', 'grossAmount' => 5000.0],
				],
			]
		);

		$service = $this->buildService(stub: $stub);
		$result = $service->compute(reconciliationId: 'aansl-ar-1', periodId: '2026-Q2');

		self::assertSame(18500.0, $result['sourceATotal']);
		self::assertSame(18500.0, $result['sourceBTotal']);
		self::assertSame(0, $result['differenceCents']);
		self::assertTrue($result['withinTolerance']);
		self::assertSame('resolved', $result['status']);
		self::assertSame('system', $result['resolvedBy']);

	}//end testComputeSubledgerGlControlArWithinTolerance()

	/**
	 * subledger-gl-control (AP, 'equal-with-sign-flip'): a liability control
	 * account nets negative under the debit-positive convention while its
	 * subledger total is positive; a genuine EUR 150 drift is reported open
	 * with an itemized drill-down row for the contributing open transaction.
	 *
	 * @return void
	 */
	public function testComputeSubledgerGlControlApSignFlipReportsOpenDrift(): void {
		$stub = $this->fakeObjectService(
			[
				'Aansluiting' => [
					[
						'id' => 'aansl-ap-1',
						'reconciliationType' => 'subledger-gl-control',
						'controlAccountNumber' => '1600',
						'subLedgerType' => 'ap',
						'toleranceCents' => 100,
						'expectedRelationship' => 'equal-with-sign-flip',
						'administrationId' => 'adm-3',
					],
				],
				'GLTransaction' => [
					['id' => 'gltx-3', 'administrationId' => 'adm-3'],
				],
				'GLLine' => [
					['transactionId' => 'gltx-3', 'accountNumber' => '1600', 'side' => 'credit', 'amount' => 9200.0],
				],
				'APTransaction' => [
					['id' => 'aptx-2026-0088', 'administrationId' => 'adm-3', 'state' => 'received', 'totalAmount' => 9350.0],
				],
			]
		);

		$service = $this->buildService(stub: $stub);
		$result = $service->compute(reconciliationId: 'aansl-ap-1', periodId: '2026-Q2');

		self::assertSame(-9200.0, $result['sourceATotal']);
		self::assertSame(9350.0, $result['sourceBTotal']);
		self::assertSame(15000, $result['differenceCents']);
		self::assertFalse($result['withinTolerance']);
		self::assertSame('open', $result['status']);

		$byKey = [];
		foreach ($result['lineDeltas'] as $row) {
			$byKey[$row['bucketKey']] = $row;
		}

		self::assertArrayHasKey('aptx-2026-0088', $byKey);
		self::assertNull($byKey['aptx-2026-0088']['sourceAAmount']);
		self::assertSame(9350.0, $byKey['aptx-2026-0088']['sourceBAmount']);

	}//end testComputeSubledgerGlControlApSignFlipReportsOpenDrift()

	/**
	 * A recompute of an already-`explained` result is a no-op — the operator's
	 * explanation is never silently clobbered (REQ-AANS-006).
	 *
	 * @return void
	 */
	public function testComputeSkipsRecomputeOfExplainedResult(): void {
		$stub = $this->fakeObjectService(
			[
				'Aansluiting' => [
					[
						'id' => 'aansl-ar-1',
						'reconciliationType' => 'subledger-gl-control',
						'controlAccountNumber' => '1300',
						'subLedgerType' => 'ar',
						'toleranceCents' => 100,
						'expectedRelationship' => 'equal',
						'administrationId' => 'adm-2',
					],
				],
				'AansluitingResult' => [
					[
						'id' => 'aanslres-1',
						'reconciliationId' => 'aansl-ar-1',
						'periodId' => '2026-Q2',
						'status' => 'explained',
						'explanationReasonText' => 'Already investigated.',
						'sourceATotal' => 100.0,
						'sourceBTotal' => 200.0,
					],
				],
				'GLTransaction' => [],
				'GLLine' => [],
				'ARInvoice' => [],
			]
		);

		$service = $this->buildService(stub: $stub);
		$result = $service->compute(reconciliationId: 'aansl-ar-1', periodId: '2026-Q2');

		// Untouched — proves the resolver never even ran (sourceATotal/B would
		// otherwise both come back 0.0 from the empty GL/AR seed).
		self::assertSame(100.0, $result['sourceATotal']);
		self::assertSame(200.0, $result['sourceBTotal']);
		self::assertSame('explained', $result['status']);

	}//end testComputeSkipsRecomputeOfExplainedResult()

	/**
	 * explain() transitions an open result to explained, stamping the actor
	 * + reason (REQ-AANS-006).
	 *
	 * @return void
	 */
	public function testExplainTransitionsOpenToExplained(): void {
		$stub = $this->fakeObjectService(
			[
				'AansluitingResult' => [
					['id' => 'aanslres-1', 'reconciliationId' => 'aansl-1', 'periodId' => '2026-Q2', 'status' => 'open'],
				],
			]
		);

		$service = $this->buildService(stub: $stub);
		$result = $service->explain(
			resultId: 'aanslres-1',
			reasonCode: 'timing',
			reasonText: 'Factuur volgt bij de volgende recompute.',
			actor: 'bookkeeper-1'
		);

		self::assertSame('explained', $result['status']);
		self::assertSame('bookkeeper-1', $result['explainedBy']);
		self::assertSame('timing', $result['explanationReasonCode']);
		self::assertNotEmpty($result['explainedAt']);

	}//end testExplainTransitionsOpenToExplained()

	/**
	 * explain() rejects a blank reason text (REQ-AANS-006).
	 *
	 * @return void
	 */
	public function testExplainRejectsBlankReasonText(): void {
		$service = $this->buildService(stub: $this->fakeObjectService([]));

		$this->expectException(RuntimeException::class);
		$service->explain(resultId: 'aanslres-1', reasonCode: 'timing', reasonText: '   ', actor: 'bookkeeper-1');

	}//end testExplainRejectsBlankReasonText()

	/**
	 * resolve() transitions an explained result to resolved when the guard
	 * is satisfied (REQ-AANS-006).
	 *
	 * @return void
	 */
	public function testResolveTransitionsExplainedToResolved(): void {
		$stub = $this->fakeObjectService(
			[
				'AansluitingResult' => [
					[
						'id' => 'aanslres-1',
						'status' => 'explained',
						'explanationReasonText' => 'Timing difference.',
					],
				],
			]
		);

		$service = $this->buildService(stub: $stub);
		$result = $service->resolve(resultId: 'aanslres-1', actor: 'controller-1');

		self::assertSame('resolved', $result['status']);
		self::assertSame('controller-1', $result['resolvedBy']);
		self::assertNotEmpty($result['resolvedAt']);

	}//end testResolveTransitionsExplainedToResolved()

	/**
	 * resolve() rejects a result that is not in the explained status.
	 *
	 * @return void
	 */
	public function testResolveRejectsNonExplainedResult(): void {
		$stub = $this->fakeObjectService(
			['AansluitingResult' => [['id' => 'aanslres-1', 'status' => 'open']]]
		);

		$service = $this->buildService(stub: $stub);

		$this->expectException(RuntimeException::class);
		$service->resolve(resultId: 'aanslres-1', actor: 'controller-1');

	}//end testResolveRejectsNonExplainedResult()

	/**
	 * reopen() transitions a resolved result back to open, audit-trailing the reason.
	 *
	 * @return void
	 */
	public function testReopenTransitionsResolvedToOpen(): void {
		$stub = $this->fakeObjectService(
			[
				'AansluitingResult' => [
					['id' => 'aanslres-1', 'status' => 'resolved', 'resolvedBy' => 'system', 'resolvedAt' => '2026-07-13T09:00:00+00:00'],
				],
			]
		);

		$service = $this->buildService(stub: $stub);
		$result = $service->reopen(resultId: 'aanslres-1', actor: 'bookkeeper-1', reason: 'Nieuwe factuur ontdekt.');

		self::assertSame('open', $result['status']);
		self::assertNull($result['resolvedBy']);
		self::assertNull($result['resolvedAt']);

	}//end testReopenTransitionsResolvedToOpen()
}//end class
