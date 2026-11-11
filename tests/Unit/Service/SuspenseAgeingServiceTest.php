<?php

/**
 * Unit tests for SuspenseAgeingService.
 *
 * Proves the suspense / unmatched-worklist ageing (payment-control-guards,
 * REQ-PCG-002): unmatched and routed-to-suspense BankStatementLine items are
 * aged (days outstanding), scoped to an administration, sorted oldest-first,
 * and summarised (count, oldest age, total); and the control-path
 * hasUnresolvedItems() reflects whether the worklist is non-empty.
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
 * @spec openspec/specs/payment-control-guards/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SuspenseAgeingService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for SuspenseAgeingService.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SuspenseAgeingServiceTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var SuspenseAgeingService
	 */
	private SuspenseAgeingService $service;

	/**
	 * Set up fixtures with a schema- and status-aware ObjectService stub.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$statements = [
			['statementId' => 'stmt-1', 'administrationId' => 'adm-1'],
			['statementId' => 'stmt-2', 'administrationId' => 'adm-2'],
		];
		$lines = [
			['lineId' => 'l1', 'statementId' => 'stmt-1', 'status' => 'unmatched', 'amount' => -250.00, 'valueDate' => '2026-01-13'],
			['lineId' => 'l2', 'statementId' => 'stmt-1', 'status' => 'routed-to-suspense', 'amount' => 100.00, 'valueDate' => '2026-02-15'],
			['lineId' => 'l3', 'statementId' => 'stmt-2', 'status' => 'unmatched', 'amount' => 50.00, 'valueDate' => '2026-01-01'],
		];

		$objectService = new class($statements, $lines) {

			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<int,array<string,mixed>> $statements Statements.
			 * @param array<int,array<string,mixed>> $lines Lines.
			 */
			public function __construct(
				private array $statements,
				private array $lines,
			) {
			}

			/**
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}

			/**
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array<string,mixed> $params Query params carrying the filter.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$filters = ($params['filters'] ?? []);
				if ($this->schema === 'BankStatement') {
					$admin = ($filters['administrationId'] ?? null);
					return array_values(
						array_filter(
							$this->statements,
							static fn (array $s): bool => ($admin === null || ($s['administrationId'] ?? null) === $admin)
						)
					);
				}

				if ($this->schema === 'BankStatementLine') {
					$status = ($filters['status'] ?? null);
					return array_values(
						array_filter(
							$this->lines,
							static fn (array $l): bool => ($status === null || ($l['status'] ?? null) === $status)
						)
					);
				}

				return [];
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$this->service = new SuspenseAgeingService(
			container: $container,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end setUp()

	/**
	 * Items are aged, scoped to the administration, and sorted oldest-first.
	 *
	 * @return void
	 */
	public function testAgesScopesAndSorts(): void {
		$result = $this->service->agedUnmatchedItems('adm-1', '2026-03-01');

		// adm-2's line (l3) is excluded; l1 (unmatched) + l2 (routed) remain.
		self::assertSame(2, $result['count']);
		self::assertSame(47, $result['oldestDaysOutstanding']);
		// 250.00 + 100.00 in cents.
		self::assertSame(35000, $result['totalAmountCents']);
		// Oldest first: l1 (47 days) before l2 (14 days).
		self::assertSame('l1', $result['items'][0]['lineId']);
		self::assertSame(47, $result['items'][0]['daysOutstanding']);
		self::assertSame('l2', $result['items'][1]['lineId']);
		self::assertSame(14, $result['items'][1]['daysOutstanding']);

	}//end testAgesScopesAndSorts()

	/**
	 * hasUnresolvedItems() is true when the scoped worklist is non-empty.
	 *
	 * @return void
	 */
	public function testHasUnresolvedItemsTrueForNonEmptyScope(): void {
		self::assertTrue($this->service->hasUnresolvedItems('adm-1', '2026-03-01'));

	}//end testHasUnresolvedItemsTrueForNonEmptyScope()

	/**
	 * An administration with no unmatched items reports an empty, non-blocking worklist.
	 *
	 * @return void
	 */
	public function testEmptyForAdministrationWithoutItems(): void {
		// adm-3 owns no statements, so no lines are in scope.
		$result = $this->service->agedUnmatchedItems('adm-3', '2026-03-01');

		self::assertSame(0, $result['count']);
		self::assertFalse($this->service->hasUnresolvedItems('adm-3', '2026-03-01'));

	}//end testEmptyForAdministrationWithoutItems()
}//end class
