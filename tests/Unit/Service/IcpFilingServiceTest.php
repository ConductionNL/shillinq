<?php

/**
 * Unit tests for IcpFilingService.
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
 * @spec openspec/changes/bookkeeping-icp-opgaaf/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\IcpCalculator;
use OCA\Shillinq\Service\IcpFilingService;
use OCA\Shillinq\Service\IcpService;
use OCA\Shillinq\Service\ViesService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the ICP filing write path: correction filings (REQ-ICP-008), the audit
 * inspection-bundle export (REQ-ICP-010) and the pending-VIES-outage scan that
 * feeds the daily revalidation job (REQ-ICP-009).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class IcpFilingServiceTest extends TestCase {

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
	 * The last ObjectService stub built (for save-capture assertions).
	 *
	 * @var object
	 */
	private object $lastStub;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');

	}//end setUp()

	/**
	 * Build the filing service with an ObjectService stub holding the given data sets.
	 *
	 * @param array<int,array<string,mixed>> $supplies IcpSupply records.
	 * @param array<int,array<string,mixed>> $validations ViesValidation records.
	 * @param array<int,array<string,mixed>> $opgaven IcpOpgaaf records.
	 *
	 * @return IcpFilingService
	 */
	private function buildService(array $supplies, array $validations = [], array $opgaven = []): IcpFilingService {
		$stub = new class($supplies, $validations, $opgaven) {

			/**
			 * Data sets keyed by schema slug.
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
			 * Captured saved objects keyed by schema.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			public array $saved = [];

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $supplies IcpSupply records.
			 * @param array<int,array<string,mixed>> $validations ViesValidation records.
			 * @param array<int,array<string,mixed>> $opgaven IcpOpgaaf records.
			 */
			public function __construct(array $supplies, array $validations, array $opgaven) {
				$this->data = [
					'IcpSupply' => $supplies,
					'ViesValidation' => $validations,
					'IcpOpgaaf' => $opgaven,
				];
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
			 * Capture a saved object, keyed by the schema it was written to.
			 *
			 * The schema may arrive either as an explicit argument or through a
			 * preceding setSchema() call — the real ObjectService honours both,
			 * so the capture falls back to the active schema rather than filing
			 * the row under an empty key.
			 *
			 * @param array<string,mixed> $object The object.
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object, string $register = '', string $schema = ''): array {
				$target = ($schema !== '') ? $schema : $this->schema;
				$this->saved[$target][] = $object;
				return $object;
			}//end saveObject()
		};

		$this->container->method('get')->willReturn($stub);
		$this->lastStub = $stub;

		$calculator = new IcpCalculator();
		$vies = new ViesService(
			appConfig: $this->appConfig,
			clientService: $this->createMock(IClientService::class),
			logger: $this->createMock(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($stub),
		);
		$icp = new IcpService(
			appConfig: $this->appConfig,
			calculator: $calculator,
			objectService: new DuckObjectServiceAdapter($stub),
		);

		return new IcpFilingService(
			appConfig: $this->appConfig,
			calculator: $calculator,
			icp: $icp,
			vies: $vies,
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * A correction filing re-attaches contemporaneous VIES evidence (REQ-ICP-008).
	 *
	 * @return void
	 */
	public function testCreateCorrectionReattachesEvidence(): void {
		$validations = [
			[
				'administrationId' => 'adm-1',
				'vatId' => 'BE0123456789',
				'valid' => true,
				'requestId' => 'WAPIAAAAX3p',
				'validationTimestamp' => '2026-03-10T09:00:00+00:00',
			],
		];

		$service = $this->buildService([], $validations, []);
		$result = $service->createCorrection(
			administrationId: 'adm-1',
			correctsPeriod: '2026-Q1',
			correctiveLines: [
				['buyerVatId' => 'BE0123456789', 'supplyType' => 'S', 'amountExclVat' => 1200.0],
			],
			reason: 'Late service supply discovered'
		);

		self::assertSame('correction', $result['type']);
		self::assertSame('draft', $result['status']);
		self::assertSame('2026-Q1', $result['correctsPeriod']);
		self::assertSame(1200.0, $result['totalServices']);
		self::assertTrue($result['saved']);
		// The original VIES requestId is preserved (no re-query).
		self::assertSame('WAPIAAAAX3p', $result['evidence'][0]['requestId']);
		// The opgaaf was persisted via the real saveObject path.
		self::assertNotEmpty($this->lastStub->saved['IcpOpgaaf']);

	}//end testCreateCorrectionReattachesEvidence()

	/**
	 * The inspection bundle ZIP carries the XBRL, kenmerk, and supplies CSV (REQ-ICP-010).
	 *
	 * @return void
	 */
	public function testExportForInspectionBuildsBundle(): void {
		$supplies = [
			[
				'administrationId' => 'adm-1',
				'supplyDate' => '2026-06-15',
				'buyerVatId' => 'BE0123456789',
				'supplyType' => 'L',
				'amountExclVat' => 25000.0,
				'invoiceId' => 'INV-1',
				'viesValidationId' => 'v1',
			],
		];
		$opgaven = [
			['administrationId' => 'adm-1', 'period' => '2026-Q2', 'xmlPayload' => '<xbrli:xbrl/>', 'taxAuthorityReference' => 'BD-2026-Q2-001'],
		];

		$service = $this->buildService($supplies, [], $opgaven);
		$bundle = $service->exportForInspection(administrationId: 'adm-1', period: '2026-Q2');

		self::assertSame('2026-Q2', $bundle['period']);
		self::assertSame(1, $bundle['supplyCount']);
		self::assertSame('BD-2026-Q2-001', $bundle['reference']);
		self::assertContains('supplies.csv', $bundle['manifest']);
		self::assertContains('kenmerk.txt', $bundle['manifest']);
		self::assertFileExists($bundle['zipPath']);

		$zip = new \ZipArchive();
		self::assertTrue($zip->open($bundle['zipPath']));
		$csv = $zip->getFromName('supplies.csv');
		$zip->close();
		@unlink($bundle['zipPath']);
		self::assertIsString($csv);
		self::assertStringContainsString('BE0123456789', $csv);

	}//end testExportForInspectionBuildsBundle()

	/**
	 * Pending-outage scan flags evidence older than 14 days for escalation (REQ-ICP-009).
	 *
	 * @return void
	 */
	public function testPendingOutagesEscalatesAfterFourteenDays(): void {
		$validations = [
			['administrationId' => 'adm-1', 'vatId' => 'BE0111111111', 'outage' => true, 'validationTimestamp' => '2026-06-01T00:00:00+00:00'],
			['administrationId' => 'adm-1', 'vatId' => 'BE0222222222', 'outage' => true, 'validationTimestamp' => '2026-06-19T00:00:00+00:00'],
		];

		$service = $this->buildService([], $validations, []);
		$pending = $service->pendingOutages(administrationId: 'adm-1', now: '2026-06-20T00:00:00+00:00');

		self::assertCount(2, $pending);
		$byVat = [];
		foreach ($pending as $row) {
			$byVat[$row['vatId']] = $row;
		}

		// 19 days old escalates; 1 day old does not yet.
		self::assertTrue($byVat['BE0111111111']['escalate']);
		self::assertFalse($byVat['BE0222222222']['escalate']);

	}//end testPendingOutagesEscalatesAfterFourteenDays()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
