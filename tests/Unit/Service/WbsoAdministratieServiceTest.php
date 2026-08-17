<?php

/**
 * Unit tests for WbsoAdministratieService.
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
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/specs.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\WbsoAdministratieService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests the on-demand WBSO realisatie summary service (REQ-WBSO-010).
 *
 * Covers the confirmed+locked-only hour roll-up, draft exclusion, the exceeded
 * flag, administration scoping (REQ-WBSO-004) and the remaining-headroom maths.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class WbsoAdministratieServiceTest extends TestCase {

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
	 * Build the service with an ObjectService stub returning the given data sets.
	 *
	 * @param array<int,array<string,mixed>> $beschikkingen WbsoBeschikking records.
	 * @param array<int,array<string,mixed>> $hours SoUurregistratie records.
	 *
	 * @return WbsoAdministratieService
	 */
	private function buildService(array $beschikkingen, array $hours): WbsoAdministratieService {
		$stub = new class($beschikkingen, $hours) {

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
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $beschikkingen WbsoBeschikking records.
			 * @param array<int,array<string,mixed>> $hours SoUurregistratie records.
			 */
			public function __construct(array $beschikkingen, array $hours) {
				$this->data = [
					'WbsoBeschikking' => $beschikkingen,
					'SoUurregistratie' => $hours,
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
			 * Return the data set for the active schema, applying equality filters.
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

		return new WbsoAdministratieService(
			appConfig: $this->appConfig,
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * Build a beschikking row.
	 *
	 * @param string $number beschikkingNumber.
	 * @param float $granted grantedSoHours.
	 * @param string $admin administrationId.
	 *
	 * @return array<string,mixed>
	 */
	private function decision(string $number, float $granted, string $admin): array {
		return [
			'decisionNumber' => $number,
			'rvoReference' => 'S&O ' . $number,
			'projectNumber' => 'PRJ-' . $number,
			'grantedSoHours' => $granted,
			'state' => 'granted',
			'administrationId' => $admin,
		];

	}//end beschikking()

	/**
	 * Build an hour entry row.
	 *
	 * @param string $number beschikkingNumber FK.
	 * @param float $hours hours.
	 * @param string $state entry state.
	 * @param string $admin administrationId.
	 *
	 * @return array<string,mixed>
	 */
	private function uur(string $number, float $hours, string $state, string $admin): array {
		return [
			'decisionNumber' => $number,
			'hours' => $hours,
			'state' => $state,
			'administrationId' => $admin,
		];

	}//end uur()

	/**
	 * Confirmed + locked hours roll up; draft hours are excluded (REQ-WBSO-008).
	 *
	 * @return void
	 */
	public function testRollsUpConfirmedAndLockedHoursOnly(): void {
		$service = $this->buildService(
			[$this->decision('WBSO-1', 1200.0, 'adm-a')],
			[
				$this->uur('WBSO-1', 6.5, 'confirmed', 'adm-a'),
				$this->uur('WBSO-1', 7.0, 'locked', 'adm-a'),
				$this->uur('WBSO-1', 8.0, 'draft', 'adm-a'),
			]
		);

		$result = $service->realisatieSummary('adm-a');
		self::assertSame(1, $result['total']);
		$row = $result['data'][0];
		// 6.5 + 7.0 = 13.5; draft 8.0 excluded.
		self::assertSame(13.5, $row['realisedSoHours']);
		self::assertSame(1200.0, $row['grantedSoHours']);
		self::assertSame((1200.0 - 13.5), $row['remainingSoHours']);
		self::assertFalse($row['exceeded']);

	}//end testRollsUpConfirmedAndLockedHoursOnly()

	/**
	 * The exceeded flag is set when realised hours pass the granted ceiling.
	 *
	 * @return void
	 */
	public function testExceededFlagSet(): void {
		$service = $this->buildService(
			[$this->decision('WBSO-1', 10.0, 'adm-a')],
			[
				$this->uur('WBSO-1', 8.0, 'locked', 'adm-a'),
				$this->uur('WBSO-1', 5.0, 'confirmed', 'adm-a'),
			]
		);

		$row = $service->realisatieSummary('adm-a')['data'][0];
		self::assertSame(13.0, $row['realisedSoHours']);
		self::assertTrue($row['exceeded']);
		self::assertSame((10.0 - 13.0), $row['remainingSoHours']);

	}//end testExceededFlagSet()

	/**
	 * Reads are scoped to the administration; other tenants' data never appears (REQ-WBSO-004).
	 *
	 * @return void
	 */
	public function testScopesToAdministration(): void {
		$service = $this->buildService(
			[
				$this->decision('WBSO-1', 1200.0, 'adm-a'),
				$this->decision('WBSO-9', 999.0, 'adm-other'),
			],
			[
				$this->uur('WBSO-1', 6.5, 'confirmed', 'adm-a'),
				$this->uur('WBSO-9', 50.0, 'locked', 'adm-other'),
			]
		);

		$result = $service->realisatieSummary('adm-a');
		self::assertSame(1, $result['total']);
		self::assertSame('WBSO-1', $result['data'][0]['decisionNumber']);

	}//end testScopesToAdministration()

	/**
	 * A beschikking with no hour entries reports zero realised and full headroom.
	 *
	 * @return void
	 */
	public function testZeroRealisationForEmptyBeschikking(): void {
		$service = $this->buildService(
			[$this->decision('WBSO-1', 100.0, 'adm-a')],
			[]
		);

		$row = $service->realisatieSummary('adm-a')['data'][0];
		self::assertSame(0.0, $row['realisedSoHours']);
		self::assertSame(100.0, $row['remainingSoHours']);
		self::assertFalse($row['exceeded']);

	}//end testZeroRealisationForEmptyBeschikking()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
