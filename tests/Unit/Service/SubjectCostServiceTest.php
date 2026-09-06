<?php

/**
 * SubjectCostService Unit Tests
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://shillinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\HrmqCostRateAdapter;
use OCA\Shillinq\Service\SubjectCostAggregator;
use OCA\Shillinq\Service\SubjectCostService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The composition step that was missing: hours in, cost out.
 *
 * The aggregation policy itself is covered by SubjectCostAggregatorTest and
 * the wage half by HrmqCostRateAdapterTest. What is asserted here is the part
 * neither of those could reach: that an hour set is read for a subject at all,
 * and that the caller's administration scope is applied to it.
 *
 * @covers \OCA\Shillinq\Service\SubjectCostService
 *
 * The aggregator is REAL here, not a double: the composition is only worth
 * asserting against the policy it actually delegates to. Declared with @uses
 * because the configs set beStrictAboutCoverageMetadata, which makes an
 * undeclared execution a RISKY test, and failOnRisky turns that into a failed
 * job. It bites only when coverage is enabled, so a --no-coverage run cannot
 * show it.
 *
 * @uses \OCA\Shillinq\Service\SubjectCostAggregator
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class SubjectCostServiceTest extends TestCase {
	/**
	 * The filters the last findAll() received, for asserting the read is
	 * narrowed to the subject rather than scanning the whole ledger.
	 *
	 * @var array<string, mixed>
	 */
	private array $lastFilters = [];

	/**
	 * Build a service over a stub ObjectService returning the given rows.
	 *
	 * @param array<int, mixed> $rows Rows findAll() answers with.
	 * @param array<string, int> $rates personId => cents per hour.
	 * @param bool $readThrows Whether the read blows up.
	 *
	 * @return SubjectCostService The service.
	 */
	private function service(array $rows, array $rates = [], bool $readThrows = false): SubjectCostService {
		$test = $this;
		$objectService = new class($rows, $readThrows, $test) {
			/**
			 * @param array<int, mixed> $rows Rows to answer with.
			 * @param bool $readThrows Whether to blow up.
			 * @param SubjectCostServiceTest $test The test, for filter capture.
			 */
			public function __construct(
				private array $rows,
				private bool $readThrows,
				private SubjectCostServiceTest $test,
			) {
			}

			/**
			 * @param string $register The register slug.
			 *
			 * @return self Fluent.
			 */
			public function setRegister(string $register): self {
				return $this;
			}

			/**
			 * @param string $schema The schema slug.
			 *
			 * @return self Fluent.
			 */
			public function setSchema(string $schema): self {
				return $this;
			}

			/**
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<int, mixed> The rows.
			 */
			public function findAll(array $query): array {
				if ($this->readThrows === true) {
					throw new RuntimeException('register unreachable');
				}

				$this->test->captureFilters(($query['filters'] ?? []));
				return $this->rows;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$adapter = $this->createMock(HrmqCostRateAdapter::class);
		$adapter->method('ratesFor')->willReturn($rates);

		return new SubjectCostService(
			$container,
			$appConfig,
			$adapter,
			new SubjectCostAggregator(new NullLogger()),
			new NullLogger()
		);
	}

	/**
	 * Record the filters the stub was queried with.
	 *
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return void
	 */
	public function captureFilters(array $filters): void {
		$this->lastFilters = $filters;
	}

	/**
	 * Hours in scope are summed and priced.
	 *
	 * @return void
	 */
	public function testInScopeHoursAreSummedAndPriced(): void {
		$service = $this->service(
			[
				['personId' => 'p1', 'hours' => 2.0, 'administrationId' => 'adm-1'],
				['personId' => 'p1', 'hours' => 1.5, 'administrationId' => 'adm-1'],
			],
			['p1' => 5000]
		);

		$result = $service->costFor('dossiq', 'case-1', ['adm-1']);

		self::assertSame(3.5, $result['hours']);
		self::assertTrue($result['complete']);
		self::assertSame(17500, $result['costCents']);
		self::assertSame('dossiq', $result['subjectApp']);
		self::assertSame('case-1', $result['subjectId']);
	}

	/**
	 * The read is narrowed to the subject, not scanned and matched in PHP.
	 *
	 * @return void
	 */
	public function testTheReadIsFilteredToTheSubject(): void {
		$this->service([])->costFor('dossiq', 'case-1', ['adm-1']);

		self::assertSame(
			['subjectApp' => 'dossiq', 'subjectId' => 'case-1'],
			$this->lastFilters,
			'the hour read must be narrowed by subject, or it grows with the ledger'
		);
	}

	/**
	 * A row in another administration is not the caller's to see.
	 *
	 * @return void
	 */
	public function testRowsOutsideTheScopeAreExcluded(): void {
		$service = $this->service(
			[
				['personId' => 'p1', 'hours' => 2.0, 'administrationId' => 'adm-1'],
				['personId' => 'p2', 'hours' => 8.0, 'administrationId' => 'adm-2'],
			],
			['p1' => 1000, 'p2' => 1000]
		);

		$result = $service->costFor('dossiq', 'case-1', ['adm-1']);

		self::assertSame(2.0, $result['hours'], 'the other tenant\'s eight hours must not appear');
		self::assertSame(2000, $result['costCents']);
	}

	/**
	 * A row with no administration is excluded, and the caller is told.
	 *
	 * @return void
	 */
	public function testUnattributableRowsAreExcludedAndCounted(): void {
		$service = $this->service(
			[
				['personId' => 'p1', 'hours' => 2.0, 'administrationId' => 'adm-1'],
				['personId' => 'p1', 'hours' => 4.0],
				['personId' => 'p1', 'hours' => 1.0, 'administrationId' => ''],
			],
			['p1' => 1000]
		);

		$result = $service->costFor('dossiq', 'case-1', ['adm-1']);

		self::assertSame(2.0, $result['hours']);
		self::assertSame(2, $result['unscopedRowsExcluded']);
	}

	/**
	 * An unpriced person withholds the total, as the aggregator requires,
	 * and the composition must not paper over it.
	 *
	 * @return void
	 */
	public function testAnUnpricedPersonWithholdsTheTotal(): void {
		$service = $this->service(
			[
				['personId' => 'p1', 'hours' => 2.0, 'administrationId' => 'adm-1'],
				['personId' => 'p2', 'hours' => 3.0, 'administrationId' => 'adm-1'],
			],
			['p1' => 1000]
		);

		$result = $service->costFor('dossiq', 'case-1', ['adm-1']);

		self::assertFalse($result['complete']);
		self::assertNull($result['costCents']);
		self::assertContains('p2', $result['unpricedPersonIds']);
		self::assertSame(5.0, $result['hours'], 'hours are effort and are reported regardless');
	}

	/**
	 * An ObjectEntity-shaped row is normalised rather than fatally indexed.
	 *
	 * @return void
	 */
	public function testObjectRowsAreNormalised(): void {
		$row = new class {
			/**
			 * @return array<string, mixed> The serialised row.
			 */
			public function jsonSerialize(): array {
				return ['personId' => 'p1', 'hours' => 2.0, 'administrationId' => 'adm-1'];
			}
		};

		$result = $this->service([$row], ['p1' => 1000])->costFor('dossiq', 'case-1', ['adm-1']);

		self::assertSame(2.0, $result['hours']);
	}

	/**
	 * An unreadable register yields an empty aggregate, not an exception.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterYieldsAnEmptyAggregate(): void {
		$result = $this->service([], [], true)->costFor('dossiq', 'case-1', ['adm-1']);

		self::assertSame(0.0, $result['hours']);
		self::assertSame(0, $result['unscopedRowsExcluded']);
	}

	/**
	 * A null scope is unrestricted, for the Nextcloud-admin read.
	 *
	 * Unrestricted still excludes a row with no administration: unattributable
	 * is not the same as everyone's.
	 *
	 * @return void
	 */
	public function testANullScopeReadsEveryAdministration(): void {
		$service = $this->service(
			[
				['personId' => 'p1', 'hours' => 2.0, 'administrationId' => 'adm-1'],
				['personId' => 'p1', 'hours' => 8.0, 'administrationId' => 'adm-2'],
				['personId' => 'p1', 'hours' => 1.0],
			],
			['p1' => 1000]
		);

		$result = $service->costFor('dossiq', 'case-1', null);

		self::assertSame(10.0, $result['hours']);
		self::assertSame(1, $result['unscopedRowsExcluded']);
	}
}//end class
