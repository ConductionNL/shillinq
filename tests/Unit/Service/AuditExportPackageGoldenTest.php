<?php

/**
 * Golden test for the 3-way-match audit package.
 *
 * Generates one package over a fixed register and compares the envelope, the
 * reads made and every ZIP entry with a recorded golden; pins the access
 * refusals (no administration, an administration the user may not access, a
 * missing invoice) and that a refused call reads nothing. The generation
 * timestamp and the package id derived from it are masked.
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
 * Golden and access characterisation of AuditExportService::generateAuditPackage().
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AuditExportPackageGoldenTest extends TestCase {

	/**
	 * Path of the recorded golden.
	 *
	 * @var string
	 */
	private const GOLDEN = __DIR__ . '/fixtures/audit-export-package-golden.json';

	/**
	 * Every read: schema + filters.
	 *
	 * @var list<string>
	 */
	public array $reads = [];

	/**
	 * The register rows.
	 *
	 * @return array<string, list<array<string, mixed>>> Schema => rows.
	 */
	private static function data(): array {
		return [
			'SupplierInvoice' => [
				['id' => 'inv-1', 'administrationId' => 'adm-1', 'invoiceNumber' => 'INV/2026 #1', 'supplierId' => 'sup-<9>', 'peppolReceivedAt' => '2026-03-01T09:00:00+00:00', 'statusCode' => 'matched', 'totalInclVat' => 121000, 'currency' => 'EUR', 'ublFileId' => 'ubl-1'],
			],
			'ThreeWayMatch' => [
				['id' => 'twm-1', 'administrationId' => 'adm-1', 'invoiceId' => 'inv-1', 'createdAt' => '2026-03-01T09:05:00+00:00', 'matchStatus' => 'auto_approved', 'matchedPoIds' => ['po-1', ' po-2 ', 'po-1', ''], 'matchedGrnIds' => ['grn-1']],
				['id' => 'twm-2', 'administrationId' => 'adm-1', 'invoiceId' => 'inv-1', 'createdAt' => '2026-03-03T09:05:00+00:00', 'matchStatus' => 'exception', 'matchedPoIds' => 'po-3', 'matchedGrnIds' => ['grn-2', 'grn-1', 'grn-missing'], 'resolvedBy' => 'carol', 'resolvedAt' => '2026-03-04T10:00:00+00:00', 'resolutionAction' => 'accepted'],
				['id' => 'twm-x', 'administrationId' => 'adm-2', 'invoiceId' => 'inv-1', 'matchedPoIds' => ['po-9']],
			],
			'PurchaseOrder' => [
				['id' => 'po-1', 'administrationId' => 'adm-1', 'poNumber' => 'PO-1', 'requesterId' => 'alice', 'createdAt' => '2026-02-01T10:00:00+00:00', 'approvalChain' => [['userId' => 'bob', 'decision' => 'approved', 'decidedAt' => '2026-02-02T11:00:00+00:00']]],
				['id' => 'po-2', 'administrationId' => 'adm-1', 'poNumber' => 'PO-2', 'createdAt' => '2026-02-05T10:00:00+00:00'],
				['id' => 'po-3', 'administrationId' => 'adm-2', 'poNumber' => 'PO-3'],
			],
			'GoodsReceiptNote' => [
				['id' => 'grn-1', 'administrationId' => 'adm-1', 'grnNumber' => 'GRN-1', 'receivedBy' => 'dave', 'receivedAt' => '2026-02-20T13:00:00+00:00', 'statusCode' => 'accepted', 'photos' => ['file-1', 'file-2']],
				['id' => 'grn-2', 'administrationId' => 'adm-1', 'grnNumber' => 'GRN-2', 'receivedAt' => '2026-02-21T13:00:00+00:00'],
			],
		];

	}//end data()

	/**
	 * Build the service.
	 *
	 * @param list<string> $accessible Administrations the user may access.
	 *
	 * @return AuditExportService The service.
	 */
	private function service(array $accessible): AuditExportService {
		$data = self::data();
		$test = $this;
		$objectService = new class($data, $test) {
			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<string, list<array<string, mixed>>> $data The rows.
			 * @param AuditExportPackageGoldenTest              $test The recorder.
			 */
			public function __construct(
				private readonly array $data,
				private readonly AuditExportPackageGoldenTest $test,
			) {
			}

			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array<string, mixed> $params The query.
			 *
			 * @return list<mixed>
			 */
			public function findAll(array $params = []): array {
				$filters = ($params['filters'] ?? []);
				$this->test->reads[] = $this->schema . ' ' . json_encode($filters);
				$rows = array_filter(
					$this->data[$this->schema] ?? [],
					static function (array $row) use ($filters): bool {
						foreach ($filters as $key => $value) {
							if (($row[$key] ?? null) !== $value) {
								return false;
							}
						}

						return true;
					}
				);

				return array_merge(['junk'], array_values($rows));
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('auditor');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(
			static fn (string $administrationId): bool => in_array($administrationId, $accessible, true)
		);

		return new AuditExportService(
			container: $container,
			appConfig: $appConfig,
			administrationContext: $context,
			userSession: $userSession,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end service()

	/**
	 * The package equals the golden.
	 *
	 * @return void
	 */
	public function testPackageMatchesGolden(): void {
		$envelope = $this->service(['adm-1'])->generateAuditPackage('adm-1', 'inv-1');

		$zip = new ZipArchive();
		self::assertTrue($zip->open($envelope['zipPath']));
		$entries = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = (string)$zip->getNameIndex($i);
			$entries[$name] = (string)$zip->getFromIndex($i);
		}

		$zip->close();
		@unlink($envelope['zipPath']);
		$envelope['zipPath'] = basename(dirname($envelope['zipPath'])) === basename(sys_get_temp_dir()) ? '<tmp>' : 'elsewhere';

		$actual = ['envelope' => $envelope, 'entries' => $entries, 'reads' => $this->reads];
		$masked = str_replace(
			[$envelope['packageId'], $envelope['generatedAt']],
			['<packageId>', '<generatedAt>'],
			(string)json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
		);

		if (getenv('SHILLINQ_RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode(json_decode($masked, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		self::assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode($masked, true));

	}//end testPackageMatchesGolden()

	/**
	 * Refusals, with their messages; a refused administration reads nothing.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$cases = [
			['', 'inv-1', 'administrationId is required', []],
			['adm-2', 'inv-1', 'Administration not found', []],
			['adm-1', '', 'Supplier invoice not found', []],
			['adm-1', 'inv-9', 'Supplier invoice not found', ['SupplierInvoice {"id":"inv-9","administrationId":"adm-1"}']],
		];
		foreach ($cases as [$administrationId, $invoiceId, $message, $reads]) {
			$this->reads = [];
			try {
				$this->service(['adm-1'])->generateAuditPackage($administrationId, $invoiceId);
				self::fail('Expected a refusal for ' . $administrationId . '/' . $invoiceId);
			} catch (RuntimeException $e) {
				self::assertSame($message, $e->getMessage());
			}

			self::assertSame($reads, $this->reads);
		}

	}//end testRefusals()
}//end class
