<?php

/**
 * Unit tests for AuditExportService (slice 11 of
 * bookkeeping-purchase-order-3way).
 *
 * Covers REQ-PO3W-010:
 *  - buildLedger() returns a strictly time-ordered list of events
 *    (po_created, po_approval_decision, po_sent_to_supplier,
 *    grn_received, grn_lifecycle_state, invoice_received,
 *    invoice_lifecycle_state, match_evaluated, match_resolved);
 *  - each event records actor + timestamp from the source record;
 *  - the resolved match emits a match_resolved event keyed on the
 *    resolvedBy + resolutionAction stamped by slice 08;
 *  - generateAuditPackage() writes an immutable ZIP to a temp file
 *    that contains manifest.json + ledger.json + summary.pdf.html +
 *    attachments for every lifecycle object;
 *  - the manifest carries a SHA-256 over the ledger so the auditor
 *    can verify the package was not tampered with;
 *  - retentionYears = 7 (BW2 art 2:10);
 *  - cross-tenant access masks as a not-found RuntimeException;
 *  - missing invoice is rejected.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\AuditExportService;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use ZipArchive;

/**
 * Tests the OR-backed audit package export service.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AuditExportServiceTest extends TestCase {
	/**
	 * The pure ledger assembly returns events sorted by timestamp.
	 *
	 * @return void
	 */
	public function testBuildLedgerReturnsTimeOrderedEvents(): void {
		$service = $this->buildService(
			data: [],
			accessibleAdministrations: []
		);

		$bundle = [
			'invoice' => [
				'id' => 'inv-1',
				'invoiceNumber' => 'INV-2026-0001',
				'administrationId' => 'admin-1',
				'supplierId' => 'sup-9',
				'peppolReceivedAt' => '2026-03-01T09:00:00+00:00',
				'statusCode' => 'matched',
				'totalInclVat' => 121000,
				'currency' => 'EUR',
			],
			'purchaseOrders' => [
				[
					'id' => 'po-1',
					'poNumber' => 'PO-001',
					'requesterId' => 'alice',
					'createdAt' => '2026-02-01T10:00:00+00:00',
					'supplierId' => 'sup-9',
					'totalAmount' => 1210.00,
					'currency' => 'EUR',
					'approvalChain' => [
						['userId' => 'bob', 'decision' => 'approved', 'decidedAt' => '2026-02-02T11:00:00+00:00'],
					],
					'peppolSentAt' => '2026-02-03T08:00:00+00:00',
				],
			],
			'goodsReceiptNotes' => [
				[
					'id' => 'grn-1',
					'grnNumber' => 'GRN-001',
					'receivedBy' => 'dave',
					'receivedAt' => '2026-02-20T13:00:00+00:00',
					'statusCode' => 'accepted',
					'photos' => ['file-1', 'file-2'],
				],
			],
			'threeWayMatches' => [
				[
					'id' => 'twm-1',
					'createdAt' => '2026-03-01T09:05:00+00:00',
					'matchStatus' => 'exception_price',
					'matchedPoIds' => ['po-1'],
					'matchedGrnIds' => ['grn-1'],
					'divergenceDetails' => [['field' => 'price', 'delta' => 500]],
					'resolvedBy' => 'carol',
					'resolvedAt' => '2026-03-02T15:00:00+00:00',
					'resolutionAction' => 'accepted',
					'resolutionNotes' => 'Within ToleranceProfile-2 override',
				],
			],
		];

		$ledger = $service->buildLedger(bundle: $bundle);
		$events = $ledger['events'];

		// Strict event ordering by timestamp.
		$lastTimestamp = '';
		foreach ($events as $event) {
			self::assertGreaterThanOrEqual($lastTimestamp, (string)$event['timestamp']);
			$lastTimestamp = (string)$event['timestamp'];
		}

		// Expected events are all present.
		$kinds = array_column($events, 'event');
		self::assertContains('po_created', $kinds);
		self::assertContains('po_approval_decision', $kinds);
		self::assertContains('po_sent_to_supplier', $kinds);
		self::assertContains('grn_received', $kinds);
		self::assertContains('grn_lifecycle_state', $kinds);
		self::assertContains('invoice_received', $kinds);
		self::assertContains('invoice_lifecycle_state', $kinds);
		self::assertContains('match_evaluated', $kinds);
		self::assertContains('match_resolved', $kinds);

		// Each event has an actor and an object reference.
		foreach ($events as $event) {
			self::assertArrayHasKey('actor', $event);
			self::assertArrayHasKey('objectType', $event);
			self::assertArrayHasKey('objectId', $event);
			self::assertArrayHasKey('details', $event);
		}

		// Summary has the invoice header.
		self::assertSame('INV-2026-0001', $ledger['summary']['invoiceNumber']);
		self::assertSame('sup-9', $ledger['summary']['supplierId']);
		self::assertSame(121000, $ledger['summary']['totalInclVat']);

	}//end testBuildLedgerReturnsTimeOrderedEvents()

	/**
	 * Resolved match carries the resolver + action + notes in its event.
	 *
	 * @return void
	 */
	public function testResolvedMatchEmitsResolverActionAndNotes(): void {
		$service = $this->buildService(
			data: [],
			accessibleAdministrations: []
		);

		$bundle = $this->emptyBundle();
		$bundle['threeWayMatches'] = [
			[
				'id' => 'twm-x',
				'createdAt' => '2026-04-01T00:00:00+00:00',
				'matchStatus' => 'exception_price',
				'resolvedAt' => '2026-04-02T00:00:00+00:00',
				'resolvedBy' => 'carol',
				'resolutionAction' => 'rejected',
				'resolutionNotes' => 'Wrong supplier',
			],
		];

		$ledger = $service->buildLedger(bundle: $bundle);
		$resolution = null;
		foreach ($ledger['events'] as $event) {
			if ($event['event'] === 'match_resolved') {
				$resolution = $event;
				break;
			}
		}

		self::assertNotNull($resolution);
		self::assertSame('carol', $resolution['actor']);
		self::assertSame('rejected', $resolution['details']['resolutionAction']);
		self::assertSame('Wrong supplier', $resolution['details']['resolutionNotes']);
		self::assertSame('2026-04-02T00:00:00+00:00', $resolution['timestamp']);

	}//end testResolvedMatchEmitsResolverActionAndNotes()

	/**
	 * Generate the ZIP package and inspect its contents.
	 *
	 * @return void
	 */
	public function testGenerateAuditPackageWritesImmutableZip(): void {
		$data = [
			'SupplierInvoice' => [
				[
					'id' => 'inv-1',
					'administrationId' => 'admin-1',
					'invoiceNumber' => 'INV-2026-0042',
					'supplierId' => 'sup-9',
					'peppolReceivedAt' => '2026-03-01T09:00:00+00:00',
					'statusCode' => 'matched',
					'totalInclVat' => 121000,
					'currency' => 'EUR',
				],
			],
			'ThreeWayMatch' => [
				[
					'id' => 'twm-1',
					'administrationId' => 'admin-1',
					'invoiceId' => 'inv-1',
					'createdAt' => '2026-03-01T09:05:00+00:00',
					'matchStatus' => 'auto_approved',
					'matchedPoIds' => ['po-1'],
					'matchedGrnIds' => ['grn-1'],
					'resolvedBy' => 'carol',
					'resolvedAt' => '2026-03-02T15:00:00+00:00',
					'resolutionAction' => 'accepted',
				],
			],
			'PurchaseOrder' => [
				[
					'id' => 'po-1',
					'administrationId' => 'admin-1',
					'poNumber' => 'PO-001',
					'requesterId' => 'alice',
					'createdAt' => '2026-02-01T10:00:00+00:00',
					'supplierId' => 'sup-9',
					'totalAmount' => 1210.00,
					'currency' => 'EUR',
					'approvalChain' => [
						['userId' => 'bob', 'decision' => 'approved', 'decidedAt' => '2026-02-02T11:00:00+00:00'],
					],
				],
			],
			'GoodsReceiptNote' => [
				[
					'id' => 'grn-1',
					'administrationId' => 'admin-1',
					'grnNumber' => 'GRN-001',
					'receivedBy' => 'dave',
					'receivedAt' => '2026-02-20T13:00:00+00:00',
					'statusCode' => 'accepted',
					'photos' => ['file-1', 'file-2'],
				],
			],
		];

		$service = $this->buildService(
			data: $data,
			accessibleAdministrations: ['admin-1'],
			userId: 'auditor'
		);

		$envelope = $service->generateAuditPackage(
			administrationId: 'admin-1',
			invoiceId: 'inv-1'
		);

		self::assertSame('inv-1', $envelope['invoiceId']);
		self::assertSame('INV-2026-0042', $envelope['invoiceNumber']);
		self::assertSame('auditor', $envelope['generatedBy']);
		self::assertNotEmpty($envelope['sha256']);
		self::assertSame(AuditExportService::RETENTION_YEARS, $envelope['retentionYears']);
		self::assertSame(7, $envelope['retentionYears']);
		self::assertGreaterThan(0, $envelope['eventCount']);
		self::assertGreaterThan(0, $envelope['attachmentCount']);
		self::assertFileExists($envelope['zipPath']);

		$zip = new ZipArchive();
		self::assertTrue($zip->open($envelope['zipPath']));

		$packageBase = $envelope['packageId'] . '/';
		$expectedFiles = [
			$packageBase . 'manifest.json',
			$packageBase . 'ledger.json',
			$packageBase . 'summary.pdf.html',
			$packageBase . 'attachments/supplier-invoice/invoice.json',
			$packageBase . 'attachments/purchase-orders/po-1.json',
			$packageBase . 'attachments/goods-receipts/grn-1.json',
			$packageBase . 'attachments/three-way-match/twm-1.json',
		];
		foreach ($expectedFiles as $expected) {
			self::assertNotFalse($zip->locateName($expected), 'Expected ZIP entry ' . $expected);
		}

		$manifestJson = $zip->getFromName($packageBase . 'manifest.json');
		$manifest = json_decode($manifestJson, true);
		self::assertIsArray($manifest);
		self::assertSame($envelope['sha256'], $manifest['sha256']);
		self::assertSame(7, $manifest['retentionYears']);
		self::assertSame('inv-1', $manifest['invoiceId']);

		$ledgerJson = $zip->getFromName($packageBase . 'ledger.json');
		// The SHA-256 must match the ledger bytes in the ZIP.
		self::assertSame(hash('sha256', $ledgerJson), $envelope['sha256']);

		$zip->close();

		// Cleanup so tests don't pollute /tmp across runs.
		@unlink($envelope['zipPath']);

	}//end testGenerateAuditPackageWritesImmutableZip()

	/**
	 * Cross-tenant access masks as not-found.
	 *
	 * @return void
	 */
	public function testCrossTenantAccessIsMaskedAsNotFound(): void {
		$data = [
			'SupplierInvoice' => [
				[
					'id' => 'inv-other',
					'administrationId' => 'admin-other',
				],
			],
		];

		$service = $this->buildService(
			data: $data,
			accessibleAdministrations: ['admin-1'],
			userId: 'mallory'
		);

		$this->expectException(RuntimeException::class);
		$service->generateAuditPackage(
			administrationId: 'admin-other',
			invoiceId: 'inv-other'
		);

	}//end testCrossTenantAccessIsMaskedAsNotFound()

	/**
	 * Missing invoice surfaces a not-found RuntimeException.
	 *
	 * @return void
	 */
	public function testMissingInvoiceIsRejected(): void {
		$service = $this->buildService(
			data: ['SupplierInvoice' => []],
			accessibleAdministrations: ['admin-1'],
			userId: 'auditor'
		);

		$this->expectException(RuntimeException::class);
		$service->generateAuditPackage(
			administrationId: 'admin-1',
			invoiceId: 'inv-missing'
		);

	}//end testMissingInvoiceIsRejected()

	/**
	 * Build the service over an in-memory OR stub.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 * @param array<int,string> $accessibleAdministrations Tenants canAccess returns true for.
	 * @param string $userId UID returned by the session.
	 *
	 * @return AuditExportService
	 */
	private function buildService(
		array $data,
		array $accessibleAdministrations,
		string $userId = 'auditor',
	): AuditExportService {
		$stub = $this->objectServiceStub(data: $data);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('canAccess')->willReturnCallback(
			static function (string $administrationId) use ($accessibleAdministrations): bool {
				return in_array($administrationId, $accessibleAdministrations, true);
			}
		);

		return new AuditExportService(
			container: $container,
			appConfig: $appConfig,
			administrationContext: $administrationContext,
			userSession: $userSession,
			logger: $logger,
		);

	}//end buildService()

	/**
	 * Empty bundle helper for ledger-only tests.
	 *
	 * @return array{
	 *     invoice:array<string,mixed>,
	 *     purchaseOrders:array<int,array<string,mixed>>,
	 *     goodsReceiptNotes:array<int,array<string,mixed>>,
	 *     threeWayMatches:array<int,array<string,mixed>>
	 * }
	 */
	private function emptyBundle(): array {
		return [
			'invoice' => [
				'id' => 'inv-x',
				'invoiceNumber' => 'INV-X',
				'supplierId' => 'sup-x',
				'totalInclVat' => 0,
				'currency' => 'EUR',
			],
			'purchaseOrders' => [],
			'goodsReceiptNotes' => [],
			'threeWayMatches' => [],
		];

	}//end emptyBundle()

	/**
	 * In-memory OR stub (read-only — generateAuditPackage doesn't save).
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema rows.
	 *
	 * @return object
	 */
	private function objectServiceStub(array $data): object {
		return new class($data) {
			/**
			 * Schema rows.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $data;

			/**
			 * Active schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string,array<int,array<string,mixed>>> $data Rows.
			 */
			public function __construct(array $data) {
				$this->data = $data;
			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug (ignored).
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
			 * Apply equality filters to the active schema rows.
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
			 * Stub saveObject (no writes in the export path).
			 *
			 * @param array<string,mixed> $object Object body.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				return $object;
			}//end saveObject()
		};

	}//end objectServiceStub()
}//end class
