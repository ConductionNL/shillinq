<?php

/**
 * Unit tests for TimeIntakeService.
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
 * @spec openspec/changes/time-expense-invoice-intake/specs/time-expense-invoice-intake/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Shillinq\Service\InvoiceGenerationService;
use OCA\Shillinq\Service\TimeIntakeService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * In-memory fake standing in for OpenRegister's real ObjectService — supports
 * the fluent setRegister()/setSchema() + find()/findAll()/saveObject() shape
 * TimeIntakeService uses, keeping per-schema rows so writes made mid-test are
 * visible to subsequent reads within the same ingest() call (matches the
 * pattern used by RateCardResolverTest / SupplierInvoiceImportControllerTest).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class FakeIntakeObjectService {
	/**
	 * @var array<string, array<int, array<string,mixed>>>
	 */
	public array $stored;

	private string $schema = '';

	private int $nextId = 1;

	/**
	 * @param array<string, array<int, array<string,mixed>>> $seed Pre-seeded rows keyed by schema.
	 */
	public function __construct(array $seed = []) {
		$this->stored = $seed;

	}//end __construct()

	public function setRegister(string $r): self {
		return $this;
	}//end setRegister()

	public function setSchema(string $s): self {
		$this->schema = $s;
		return $this;
	}//end setSchema()

	/**
	 * Mirrors OpenRegister ObjectService::findAll(array $config) — the filter map
	 * travels inside $config['filters'], never as a top-level `filters:` argument.
	 *
	 * @param array<string,mixed> $config Find configuration (filters, limit, offset, …).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function findAll(array $config = []): array {
		$filters = (array)($config['filters'] ?? []);
		$rows = ($this->stored[$this->schema] ?? []);
		$result = [];
		foreach ($rows as $row) {
			$match = true;
			foreach ($filters as $key => $value) {
				if (($row[$key] ?? null) != $value) {
					$match = false;
					break;
				}
			}

			if ($match === true) {
				$result[] = $row;
			}
		}

		return $result;
	}//end findAll()

	/**
	 * @param string $id Object id.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find(string $id): ?array {
		foreach ($this->stored as $rows) {
			foreach ($rows as $row) {
				if (($row['id'] ?? null) === $id) {
					return $row;
				}
			}
		}

		return null;
	}//end find()

	/**
	 * @param array<string,mixed> $data Object body.
	 *
	 * @return array<string,mixed>
	 */
	public function saveObject(array $data): array {
		if (isset($data['id']) === false) {
			$data['id'] = $this->schema . '-' . ($this->nextId++);
		}

		$this->stored[$this->schema][] = $data;

		return $data;
	}//end saveObject()
}//end class

/**
 * Covers REQ scenarios from spec.md: happy path (one draft invoice + N
 * UrenRegistratie rows), idempotent replay, 409 payload-conflict, 422
 * unresolvable rateRef, 422 unresolvable organisationRef, 400 malformed
 * body, 422 non-t_and_m model, 422 cross-batch externalId dedup, 422
 * non-positive minutes.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class TimeIntakeServiceTest extends TestCase {
	/**
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * @var InvoiceGenerationService&MockObject
	 */
	private InvoiceGenerationService&MockObject $invoiceGenerationService;

	/**
	 * @var FakeIntakeObjectService
	 */
	private FakeIntakeObjectService $fake;

	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->invoiceGenerationService = $this->createMock(InvoiceGenerationService::class);
		$this->invoiceGenerationService->method('draftInvoice')->willReturn(
			['id' => 'inv-1', 'invoiceNumber' => 'BIL-2026-0001']
		);

		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->fake = new FakeIntakeObjectService(
			[
				'CustomerMaster' => [
					['id' => 'cm-1', 'customerId' => 'cust-5432', 'administrationId' => 'adm-1', 'legalName' => 'Acme'],
				],
			]
		);
		$this->container->method('get')->willReturn($this->fake);

	}//end setUp()

	/**
	 * @return TimeIntakeService
	 */
	private function svc(): TimeIntakeService {
		return new TimeIntakeService(
			$this->appConfig,
			$this->logger,
			$this->invoiceGenerationService,
			objectService: new DuckObjectServiceAdapter($this->fake),
		);

	}//end svc()

	/**
	 * @param string $batchId BatchId.
	 *
	 * @return array<string,mixed>
	 */
	private function validBody(string $batchId = 'B1'): array {
		return [
			'batchId' => $batchId,
			'organisationRef' => 'cust-5432',
			'currency' => 'EUR',
			'billingModel' => 't_and_m',
			'period' => ['start' => '2026-06-01', 'end' => '2026-06-30'],
			'rateCardId' => null,
			'projectRef' => 'proj-widget-api',
			'notes' => 'June overflow',
			'entries' => [
				[
					'externalId' => 'pl-time-1001',
					'date' => '2026-06-03',
					'minutes' => 120,
					'description' => 'API design review',
					'hourlyRate' => 150.0,
					'rateRef' => null,
					'projectRef' => null,
				],
				[
					'externalId' => 'pl-time-1002',
					'date' => '2026-06-05',
					'minutes' => 90,
					'description' => 'Integration spike',
					'hourlyRate' => 100.0,
					'rateRef' => null,
					'projectRef' => null,
				],
			],
			'expenses' => [],
		];

	}//end validBody()

	/**
	 * Happy path: one draft invoice, N UrenRegistratie rows with provenance,
	 * one TimeIntakeBatch ledger row, correct response shape.
	 *
	 * @return void
	 */
	public function testNewBatchDraftsOneInvoiceAndMaterialisesEntries(): void {
		$result = $this->svc()->ingest('adm-1', 'alice', $this->validBody());

		self::assertSame('inv-1', $result['invoiceId']);
		self::assertSame('BIL-2026-0001', $result['invoiceNumber']);
		self::assertSame('draft', $result['status']);
		self::assertSame(2, $result['lines']);
		self::assertFalse($result['duplicated']);

		$hours = ($this->fake->stored['UrenRegistratie'] ?? []);
		self::assertCount(2, $hours);
		self::assertSame('pl-time-1001', $hours[0]['externalId']);
		self::assertSame('pipelinq', $hours[0]['sourceApp']);
		self::assertSame('B1', $hours[0]['sourceBatchId']);
		self::assertSame(2.0, $hours[0]['hours']);
		self::assertSame(150.0, $hours[0]['recognisedRate']);
		self::assertSame(1.5, $hours[1]['hours']);
		self::assertSame(100.0, $hours[1]['recognisedRate']);

		$batches = ($this->fake->stored['TimeIntakeBatch'] ?? []);
		self::assertCount(1, $batches);
		self::assertSame('invoiced', $batches[0]['status']);
		self::assertSame('inv-1', $batches[0]['invoiceId']);
		self::assertSame(2, $batches[0]['entryCount']);

		// No request-level rateCardId -> one RateCard auto-materialised from
		// the first entry's resolved rate.
		$cards = ($this->fake->stored['RateCard'] ?? []);
		self::assertCount(1, $cards);
		self::assertSame(150.0, $cards[0]['hourlyRate']);

	}//end testNewBatchDraftsOneInvoiceAndMaterialisesEntries()

	/**
	 * Replaying the exact same batchId + payload short-circuits to the
	 * stored invoice with duplicated:true and creates nothing new.
	 *
	 * @return void
	 */
	public function testReplayedBatchReturnsDuplicatedTrue(): void {
		$svc = $this->svc();
		$body = $this->validBody();

		$first = $svc->ingest('adm-1', 'alice', $body);
		self::assertFalse($first['duplicated']);
		self::assertCount(2, $this->fake->stored['UrenRegistratie']);
		self::assertCount(1, $this->fake->stored['TimeIntakeBatch']);

		$second = $svc->ingest('adm-1', 'alice', $body);
		self::assertTrue($second['duplicated']);
		self::assertSame('inv-1', $second['invoiceId']);
		self::assertSame(2, $second['lines']);

		// No new writes on replay.
		self::assertCount(2, $this->fake->stored['UrenRegistratie']);
		self::assertCount(1, $this->fake->stored['TimeIntakeBatch']);

	}//end testReplayedBatchReturnsDuplicatedTrue()

	/**
	 * Reusing a batchId with a materially different payload is a 409
	 * conflict (RuntimeException message prefixed "Conflict:").
	 *
	 * @return void
	 */
	public function testReusedBatchIdWithDifferentPayloadIsConflict(): void {
		$svc = $this->svc();
		$svc->ingest('adm-1', 'alice', $this->validBody());

		$different = $this->validBody();
		$different['entries'][0]['minutes'] = 999;

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/^Conflict:/');
		$svc->ingest('adm-1', 'alice', $different);

	}//end testReusedBatchIdWithDifferentPayloadIsConflict()

	/**
	 * An entry with no inline hourlyRate and an unresolvable rateRef
	 * hard-fails the whole batch with 422 (RuntimeException, no "Conflict:"
	 * prefix), and nothing is written.
	 *
	 * @return void
	 */
	public function testUnresolvableRateRefWithNoHourlyRateIs422(): void {
		$body = $this->validBody();
		$body['entries'][0]['hourlyRate'] = null;
		$body['entries'][0]['rateRef'] = 'no-such-rate-card';

		$svc = $this->svc();

		try {
			$svc->ingest('adm-1', 'alice', $body);
			self::fail('Expected a RuntimeException.');
		} catch (RuntimeException $e) {
			self::assertStringNotContainsString('Conflict:', $e->getMessage());
			self::assertStringContainsString('pl-time-1001', $e->getMessage());
		}

		self::assertArrayNotHasKey('UrenRegistratie', $this->fake->stored);
		self::assertArrayNotHasKey('TimeIntakeBatch', $this->fake->stored);

	}//end testUnresolvableRateRefWithNoHourlyRateIs422()

	/**
	 * An organisationRef that does not resolve to a CustomerMaster row for
	 * the administration is rejected 422.
	 *
	 * @return void
	 */
	public function testUnresolvableOrganisationRefIs422(): void {
		$body = $this->validBody();
		$body['organisationRef'] = 'cust-unknown';

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/organisationRef/');
		$this->svc()->ingest('adm-1', 'alice', $body);

	}//end testUnresolvableOrganisationRefIs422()

	/**
	 * A missing batchId is a structural 400 (InvalidArgumentException).
	 *
	 * @return void
	 */
	public function testMissingBatchIdIs400(): void {
		$body = $this->validBody();
		unset($body['batchId']);

		$this->expectException(InvalidArgumentException::class);
		$this->svc()->ingest('adm-1', 'alice', $body);

	}//end testMissingBatchIdIs400()

	/**
	 * An empty entries array is a structural 400.
	 *
	 * @return void
	 */
	public function testEmptyEntriesIs400(): void {
		$body = $this->validBody();
		$body['entries'] = [];

		$this->expectException(InvalidArgumentException::class);
		$this->svc()->ingest('adm-1', 'alice', $body);

	}//end testEmptyEntriesIs400()

	/**
	 * A non-t_and_m billingModel is rejected 422 and nothing is created.
	 *
	 * @return void
	 */
	public function testNonTAndMBillingModelIs422(): void {
		$body = $this->validBody();
		$body['billingModel'] = 'fixed_fee';

		try {
			$this->svc()->ingest('adm-1', 'alice', $body);
			self::fail('Expected a RuntimeException.');
		} catch (RuntimeException $e) {
			self::assertStringNotContainsString('Conflict:', $e->getMessage());
		}

		self::assertArrayNotHasKey('UrenRegistratie', $this->fake->stored);

	}//end testNonTAndMBillingModelIs422()

	/**
	 * An externalId already materialised under a different batchId blocks
	 * the new batch with 422 (Risk 1 / cross-batch double-bill protection).
	 *
	 * @return void
	 */
	public function testCrossBatchDuplicateExternalIdIs422(): void {
		$this->fake->stored['UrenRegistratie'] = [
			[
				'id' => 'uren-existing',
				'administrationId' => 'adm-1',
				'externalId' => 'pl-time-1001',
				'sourceBatchId' => 'OTHER-BATCH',
			],
		];

		try {
			$this->svc()->ingest('adm-1', 'alice', $this->validBody());
			self::fail('Expected a RuntimeException.');
		} catch (RuntimeException $e) {
			self::assertStringNotContainsString('Conflict:', $e->getMessage());
			self::assertStringContainsString('pl-time-1001', $e->getMessage());
		}

		// No TimeIntakeBatch created; no NEW UrenRegistratie row appended.
		self::assertArrayNotHasKey('TimeIntakeBatch', $this->fake->stored);
		self::assertCount(1, $this->fake->stored['UrenRegistratie']);

	}//end testCrossBatchDuplicateExternalIdIs422()

	/**
	 * A non-positive minutes value on an entry is rejected 422.
	 *
	 * @return void
	 */
	public function testNonPositiveMinutesIs422(): void {
		$body = $this->validBody();
		$body['entries'][1]['minutes'] = 0;

		try {
			$this->svc()->ingest('adm-1', 'alice', $body);
			self::fail('Expected a RuntimeException.');
		} catch (RuntimeException $e) {
			self::assertStringNotContainsString('Conflict:', $e->getMessage());
			self::assertStringContainsString('pl-time-1002', $e->getMessage());
		}

	}//end testNonPositiveMinutesIs422()

	/**
	 * A same-batchId retry after a prior partial materialisation (Risk 3)
	 * reuses the already-written UrenRegistratie row rather than duplicating
	 * it, and still proceeds to draft the invoice.
	 *
	 * @return void
	 */
	public function testSameBatchRetryReusesPartiallyMaterialisedRows(): void {
		$this->fake->stored['UrenRegistratie'] = [
			[
				'id' => 'uren-partial-1',
				'administrationId' => 'adm-1',
				'externalId' => 'pl-time-1001',
				'sourceBatchId' => 'B1',
			],
		];

		$result = $this->svc()->ingest('adm-1', 'alice', $this->validBody());

		self::assertFalse($result['duplicated']);
		self::assertSame(2, $result['lines']);

		// The first row was reused (still exactly 1 pre-existing + 1 new = 2 total).
		self::assertCount(2, $this->fake->stored['UrenRegistratie']);

	}//end testSameBatchRetryReusesPartiallyMaterialisedRows()

	/**
	 * A request-level rateCardId that does not resolve to an existing
	 * RateCard for the administration is rejected 422.
	 *
	 * @return void
	 */
	public function testUnresolvableRequestLevelRateCardIdIs422(): void {
		$body = $this->validBody();
		$body['rateCardId'] = 'no-such-card';

		$this->expectException(RuntimeException::class);
		$this->svc()->ingest('adm-1', 'alice', $body);

	}//end testUnresolvableRequestLevelRateCardIdIs422()
}//end class
