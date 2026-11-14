<?php

/**
 * Characterisation tests for GRIRClearingService::createGRIRPosting().
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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-009
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\GRIRClearingService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/InMemoryObjectService.php';

/**
 * Pins the refusals of createGRIRPosting() and the full transaction header
 * and line payloads with and without a ThreeWayMatch id.
 */
// phpcs:disable CustomSniffs.Functions.NamedParameters
final class GRIRClearingPostingTest extends TestCase {

	/**
	 * In-memory object store.
	 *
	 * @var InMemoryObjectService
	 */
	private InMemoryObjectService $os;

	/**
	 * The service under test.
	 *
	 * @var GRIRClearingService
	 */
	private GRIRClearingService $service;

	/**
	 * Set up the service on an in-memory store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->os = new InMemoryObjectService();

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturn(true);

		$this->service = new GRIRClearingService(
			appConfig: $appConfig,
			administrationContext: $context,
			logger: $this->createStub(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($this->os),
		);

	}//end setUp()

	/**
	 * A GRN line without poLineId and an unknown PO line are refused.
	 *
	 * @return void
	 */
	public function testMissingPoLineRefusals(): void {
		try {
			$this->service->createGRIRPosting('adm-1', [], []);
			self::fail('expected refusal');
		} catch (RuntimeException $e) {
			self::assertSame('GR/IR posting requires a poLineId on the GRN line', $e->getMessage());
		}

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Purchase order line not found');
		$this->service->createGRIRPosting('adm-1', [], ['poLineId' => 'pl-404']);

	}//end testMissingPoLineRefusals()

	/**
	 * Header and line payloads; the @self line id and the "GRN" label stand
	 * in when the line id and GRN number are absent, and a match id becomes
	 * the source reference.
	 *
	 * @return void
	 */
	public function testClearingPayloads(): void {
		$this->os->seed(
			'PurchaseOrderLine',
			[['id' => 'pl-1', 'administrationId' => 'adm-1', 'glAccount' => '1200', 'unitPrice' => 250, 'costCenter' => ' CC ', 'projectCode' => 'PRJ']]
		);

		$result = $this->service->createGRIRPosting(
			'adm-1',
			['receivedAt' => '2026-02-03T10:00:00Z'],
			['@self' => ['id' => 'gl-9'], 'poLineId' => 'pl-1', 'quantityAccepted' => 2]
		);

		self::assertSame(500, $result['amountCents']);
		$txn = $this->os->setSchema('GLTransaction')->findAll()[0];
		$description = 'GR/IR clearing — GRN line gl-9 — 500 cents';
		foreach ([
			'transactionNumber' => 'GRIR-gl-9-GL9',
			'journalCode' => 'GRIR',
			'postingDate' => '2026-02-03',
			'periodId' => '2026-Q1',
			'currency' => 'EUR',
			'description' => $description,
			'sourceReference' => 'gl-9',
			'threeWayMatchId' => null,
			'state' => 'draft',
			'administrationId' => 'adm-1',
		] as $key => $value) {
			self::assertSame($value, $txn[$key], $key);
		}

		$lines = $this->os->setSchema('GLLine')->findAll();
		self::assertSame(['1200', '2910'], array_column($lines, 'accountNumber'));
		self::assertSame(['debit', 'credit'], array_column($lines, 'side'));
		self::assertSame([$description, $description], array_column($lines, 'description'));
		self::assertSame(['CC', 'CC'], array_column($lines, 'costCenter'));
		self::assertSame(['PRJ', 'PRJ'], array_column($lines, 'projectCode'));

		$this->service->createGRIRPosting(
			'adm-1',
			['grnNumber' => 'GRN-7'],
			['id' => 'line-2', 'poLineId' => 'pl-1', 'quantityAccepted' => 1],
			'match-5'
		);
		$second = $this->os->setSchema('GLTransaction')->findAll()[1];
		self::assertSame('GRIR-GRN-7-LINE2', $second['transactionNumber']);
		self::assertSame('GR/IR clearing — GRN-7 line line-2 — 250 cents', $second['description']);
		self::assertSame('match-5', $second['sourceReference']);
		self::assertSame('match-5', $second['threeWayMatchId']);

	}//end testClearingPayloads()
}//end class
