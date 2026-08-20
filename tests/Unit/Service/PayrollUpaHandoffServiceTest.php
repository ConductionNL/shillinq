<?php

/**
 * Unit tests for the PayrollUpaHandoffService.
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
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/specs/req-pay-001-bruto-netto-berekening.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\PayrollUpaHandoffService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Asserts UPA submission grouping by pensioenuitvoerder.
 *
 * Werknemers are grouped per pensioenRegeling slug, and werknemers without a
 * regeling or with zero pensioenpremie are excluded; each group reports its
 * totals and a per-werknemer breakdown. The administrationId scope is forced
 * by the service — no cross-administration leak.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PayrollUpaHandoffServiceTest extends TestCase {

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
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Build the service over an in-memory ObjectService stub.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 *
	 * @return PayrollUpaHandoffService
	 */
	private function buildService(array $data): PayrollUpaHandoffService {
		$stub = new class($data) {

			/**
			 * Schema => rows.
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
			 * @param array<string,array<int,array<string,mixed>>> $data Data.
			 */
			public function __construct(array $data) {
				$this->data = $data;
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
			 * Filtered findAll.
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
		};

		$this->container->method('get')->willReturn($stub);

		return new PayrollUpaHandoffService(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * A worked dataset: 3 werknemers across 2 pensioenregelingen + one without.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function dataset(): array {
		return [
			'Werknemer' => [
				['id' => 'wn-1', 'administrationId' => 'adm-1', 'pensionScheme' => 'PME_DC'],
				['id' => 'wn-2', 'administrationId' => 'adm-1', 'pensionScheme' => 'PME_DC'],
				['id' => 'wn-3', 'administrationId' => 'adm-1', 'pensionScheme' => 'PFZW'],
				['id' => 'wn-4', 'administrationId' => 'adm-1', 'pensionScheme' => ''],
				['id' => 'wn-5', 'administrationId' => 'adm-2', 'pensionScheme' => 'PME_DC'],
			],
			'LoonStrook' => [
				['employeeId' => 'wn-1', 'periodId' => 'lp-1', 'administrationId' => 'adm-1', 'pension' => ['premie_wn_aandeel' => 100.0, 'premie_wg_aandeel' => 200.0]],
				['employeeId' => 'wn-2', 'periodId' => 'lp-1', 'administrationId' => 'adm-1', 'pension' => ['premie_wn_aandeel' => 50.0, 'premie_wg_aandeel' => 150.0]],
				['employeeId' => 'wn-3', 'periodId' => 'lp-1', 'administrationId' => 'adm-1', 'pension' => ['premie_wn_aandeel' => 80.0, 'premie_wg_aandeel' => 220.0]],
				// No regeling -> excluded.
				['employeeId' => 'wn-4', 'periodId' => 'lp-1', 'administrationId' => 'adm-1', 'pension' => ['premie_wn_aandeel' => 10.0, 'premie_wg_aandeel' => 20.0]],
				// Different admin -> excluded by scope.
				['employeeId' => 'wn-5', 'periodId' => 'lp-1', 'administrationId' => 'adm-2', 'pension' => ['premie_wn_aandeel' => 999.0, 'premie_wg_aandeel' => 999.0]],
				// Zero pensioen -> excluded.
				['employeeId' => 'wn-1', 'periodId' => 'lp-2', 'administrationId' => 'adm-1', 'pension' => ['premie_wn_aandeel' => 0.0, 'premie_wg_aandeel' => 0.0]],
			],
		];

	}//end dataset()

	/**
	 * Groups payloads per pensioenuitvoerder with correct totals.
	 *
	 * @return void
	 */
	public function testGroupsByUitvoerderAndSumsCorrectly(): void {
		$svc = $this->buildService(data: $this->dataset());

		$payloads = $svc->toUpaSubmissionPayloads(administrationId: 'adm-1', periodId: 'lp-1');

		$this->assertCount(2, $payloads);

		$byRegeling = [];
		foreach ($payloads as $p) {
			$byRegeling[$p['pensionScheme']] = $p;
		}

		$this->assertArrayHasKey('PME_DC', $byRegeling);
		$this->assertArrayHasKey('PFZW', $byRegeling);

		$pme = $byRegeling['PME_DC'];
		$this->assertSame(2, $pme['totaalWerknemers']);
		$this->assertEqualsWithDelta(500.0, $pme['totaalPremie'], 0.005);
		$this->assertSame('lp-1', $pme['periodId']);
		$this->assertSame('adm-1', $pme['administrationId']);
		$this->assertCount(2, $pme['rules']);

		$pfzw = $byRegeling['PFZW'];
		$this->assertSame(1, $pfzw['totaalWerknemers']);
		$this->assertEqualsWithDelta(300.0, $pfzw['totaalPremie'], 0.005);

	}//end testGroupsByUitvoerderAndSumsCorrectly()

	/**
	 * Returns an empty array when there are no loonstroken for the period.
	 *
	 * @return void
	 */
	public function testReturnsEmptyWhenNoStroken(): void {
		$svc = $this->buildService(data: ['Werknemer' => [], 'LoonStrook' => []]);

		$payloads = $svc->toUpaSubmissionPayloads(administrationId: 'adm-1', periodId: 'lp-1');

		$this->assertSame([], $payloads);

	}//end testReturnsEmptyWhenNoStroken()

	/**
	 * Does not leak cross-administration loonstroken.
	 *
	 * @return void
	 */
	public function testDoesNotLeakCrossAdministration(): void {
		$svc = $this->buildService(data: $this->dataset());

		$payloads = $svc->toUpaSubmissionPayloads(administrationId: 'adm-2', periodId: 'lp-1');

		// adm-2 has wn-5 with PME_DC but we never see adm-1's wn-1/wn-2 grouped in.
		$this->assertCount(1, $payloads);
		$this->assertSame('PME_DC', $payloads[0]['pensionScheme']);
		$this->assertSame('adm-2', $payloads[0]['administrationId']);
		$this->assertSame(1, $payloads[0]['totaalWerknemers']);

	}//end testDoesNotLeakCrossAdministration()
}//end class
