<?php

/**
 * Unit tests for RgsAccountMapper.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\BbvMigration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-bbv-compliance/specs/bookkeeping-bbv-compliance/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\BbvMigration;

use OCA\Shillinq\Service\BbvMigration\RgsAccountMapper;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Covers the confidence scoring and threshold filtering of RgsAccountMapper.
 *
 * @spec openspec/changes/bookkeeping-bbv-compliance/tasks.md#task-39
 */
class RgsAccountMapperTest extends TestCase {

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
	 * Set up the test fixture.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->appConfig->method('getValueString')
			->willReturnCallback(function (string $appId, string $key, string $default): string {
				if ($key === 'register') {
					return 'shillinq';
				}

				return $default;
			});

	}//end setUp()

	/**
	 * Exact referentienummer wins with confidence 100.
	 *
	 * @return void
	 */
	public function testExactReferentienummerScores100(): void {
		$accounts = [
			['id' => 'a-1', 'code' => '4250', 'name' => 'Subsidies cultuur', 'referentienummer' => 'REF-4250'],
		];

		$rgs = [
			['rgsCode' => 'BLasGS', 'rgsDecentraalCode' => 'D-LASGSU', 'referentienummer' => 'REF-4250', 'omschrijvingKort' => 'Lasten subsidies'],
		];

		$service = $this->buildService(accounts: $accounts, rgs: $rgs);
		$result = $service->suggestMappings(administrationId: 'admin-1');

		self::assertTrue(condition: $result['success']);
		self::assertCount(expectedCount: 1, haystack: $result['suggestions']);
		self::assertSame(expected: 100, actual: $result['suggestions'][0]['confidence']);
		self::assertSame(expected: 'exact-referentienummer', actual: $result['suggestions'][0]['scoringReason']);

	}//end testExactReferentienummerScores100()

	/**
	 * Already-mapped accounts are skipped.
	 *
	 * @return void
	 */
	public function testAlreadyMappedAccountIsSkipped(): void {
		$accounts = [
			['id' => 'a-1', 'code' => '4250', 'name' => 'Subsidies cultuur', 'rgsDecentraalCode' => 'D-EXISTING'],
		];

		$rgs = [
			['rgsCode' => '4250', 'rgsDecentraalCode' => 'D-NEW', 'omschrijvingKort' => 'Lasten subsidies'],
		];

		$service = $this->buildService(accounts: $accounts, rgs: $rgs);
		$result = $service->suggestMappings(administrationId: 'admin-1');

		self::assertTrue(condition: $result['success']);
		self::assertSame(expected: 1, actual: $result['skipped']);
		self::assertEmpty(actual: $result['suggestions']);

	}//end testAlreadyMappedAccountIsSkipped()

	/**
	 * Low-confidence matches below the threshold are dropped.
	 *
	 * @return void
	 */
	public function testLowConfidenceFuzzyMatchIsDropped(): void {
		$accounts = [
			['id' => 'a-1', 'code' => '9999', 'name' => 'Onbekende rekening'],
		];

		$rgs = [
			['rgsCode' => '1', 'rgsDecentraalCode' => 'D-OTHER', 'omschrijvingKort' => 'Volstrekt ander label'],
		];

		$service = $this->buildService(accounts: $accounts, rgs: $rgs);
		$result = $service->suggestMappings(administrationId: 'admin-1');

		self::assertTrue(condition: $result['success']);
		self::assertEmpty(actual: $result['suggestions']);

	}//end testLowConfidenceFuzzyMatchIsDropped()

	/**
	 * Account.code matching rgsDecentraalCode scores 95.
	 *
	 * @return void
	 */
	public function testExactRgsDecentraalCodeScores95(): void {
		$accounts = [
			['id' => 'a-1', 'code' => 'D-100A', 'name' => 'Reservering'],
		];

		$rgs = [
			['rgsCode' => 'B', 'rgsDecentraalCode' => 'D-100A', 'omschrijvingKort' => 'Eigen vermogen reserve'],
		];

		$service = $this->buildService(accounts: $accounts, rgs: $rgs);
		$result = $service->suggestMappings(administrationId: 'admin-1');

		self::assertCount(expectedCount: 1, haystack: $result['suggestions']);
		self::assertSame(expected: 95, actual: $result['suggestions'][0]['confidence']);

	}//end testExactRgsDecentraalCodeScores95()

	/**
	 * Build the service with a fake ObjectService.
	 *
	 * @param array<int,array<string,mixed>> $accounts Account stubs.
	 * @param array<int,array<string,mixed>> $rgs RGS stubs.
	 *
	 * @return RgsAccountMapper
	 */
	private function buildService(array $accounts, array $rgs): RgsAccountMapper {
		$objectService = $this->buildObjectServiceStub(accounts: $accounts, rgs: $rgs);

		$this->container->method('get')->willReturn($objectService);

		return new RgsAccountMapper(
			container: $this->container,
			appConfig: $this->appConfig,
		);

	}//end buildService()

	/**
	 * Build a fluent ObjectService stub.
	 *
	 * @param array<int,array<string,mixed>> $accounts Account stubs.
	 * @param array<int,array<string,mixed>> $rgs RGS stubs.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $accounts, array $rgs): object {
		return new class($accounts, $rgs) {
			/**
			 * The pending schema for the next findAll call.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Account stub rows.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $accounts;

			/**
			 * RGS stub rows.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $rgs;

			/**
			 * Initialise the fluent stub.
			 *
			 * @param array<int,array<string,mixed>> $accounts Account fixtures.
			 * @param array<int,array<string,mixed>> $rgs RGS fixtures.
			 */
			public function __construct(array $accounts, array $rgs) {
				$this->accounts = $accounts;
				$this->rgs = $rgs;
			}//end __construct()

			/**
			 * Fluent setter.
			 *
			 * @param string $register Register slug (ignored).
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Capture the schema for the next findAll call.
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
			 * Return rows by schema.
			 *
			 * @param array<string,mixed> $params Query params (ignored).
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				if ($this->schema === 'Account') {
					return $this->accounts;
				}

				if ($this->schema === 'RgsDecentraalRekening' || $this->schema === 'BbvAccountMapping') {
					return $this->rgs;
				}

				return [];
			}//end findAll()
		};

	}//end buildObjectServiceStub()
}//end class
