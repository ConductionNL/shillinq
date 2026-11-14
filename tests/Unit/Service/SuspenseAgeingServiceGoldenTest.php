<?php

/**
 * Characterisation tests for SuspenseAgeingService.
 *
 * Pins the aged suspense worklist: which unresolved bank lines count for an
 * administration, how they are aged and summed, their order, and the two
 * failure behaviours (the report degrades to empty, the control path fails
 * closed).
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
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The aged suspense worklist, field by field.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SuspenseAgeingServiceGoldenTest extends TestCase {

	/**
	 * Build the service over the given rows, or over a container that fails.
	 *
	 * @param array<string,array<int,array<string,mixed>>>|null $data   Rows per schema; null makes the lookup throw.
	 * @param LoggerInterface|null                              $logger The logger.
	 *
	 * @return SuspenseAgeingService
	 */
	private function service(?array $data, ?LoggerInterface $logger = null): SuspenseAgeingService {
		$container = $this->createMock(ContainerInterface::class);
		if ($data === null) {
			$container->method('get')->willThrowException(new \RuntimeException('register down'));
		} else {
			$container->method('get')->willReturn(new InMemoryObjectServiceStub(data: $data));
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new SuspenseAgeingService(container: $container, appConfig: $appConfig, logger: $logger ?? $this->createMock(LoggerInterface::class));
	}

	/**
	 * Two administrations' statements and their unresolved, resolved and odd lines.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private static function ledger(): array {
		return [
			'BankStatement' => [
				['id' => 'st-1', 'administrationId' => 'adm-1'],
				['statementId' => 'st-2', 'uuid' => 'st-2-uuid', 'administrationId' => 'adm-1'],
				['id' => 'st-9', 'administrationId' => 'adm-2'],
			],
			'BankStatementLine' => [
				['lineId' => 'l-1', 'statementId' => 'st-1', 'status' => 'unmatched', 'amount' => -12.345, 'valueDate' => '2026-01-01'],
				['id' => 'l-2', 'statementId' => 'st-2', 'status' => 'routed-to-suspense', 'amount' => 5, 'transactionDate' => '2026-01-10'],
				['lineId' => 'l-3', 'statementId' => 'st-2-uuid', 'status' => 'unmatched', 'amount' => '7.5', 'valueDate' => '2026-03-01'],
				['lineId' => 'l-4', 'statementId' => 'st-1', 'status' => 'unmatched', 'amount' => 1, 'valueDate' => 'not a date'],
				['lineId' => 'l-5', 'statementId' => 'st-9', 'status' => 'unmatched', 'amount' => 100, 'valueDate' => '2025-01-01'],
				['lineId' => 'l-6', 'statementId' => 'st-1', 'status' => 'matched', 'amount' => 100, 'valueDate' => '2025-01-01'],
				['lineId' => 'l-7', 'status' => 'unmatched', 'amount' => 3, 'valueDate' => '2025-12-01'],
				['lineId' => 'l-8', 'statementId' => 'st-1', 'status' => 'unmatched', 'amount' => 2],
			],
		];
	}

	/**
	 * Golden: the worklists of one administration, of another, of all, and the empty answers.
	 *
	 * @return void
	 */
	public function testWorklistsMatchTheGolden(): void {
		$service = $this->service(self::ledger());
		$answers = [
			'adm-1' => $service->agedUnmatchedItems('adm-1', '2026-02-01'),
			'adm-2' => $service->agedUnmatchedItems('adm-2', '2026-02-01'),
			'unscoped' => $service->agedUnmatchedItems(' ', '2026-02-01'),
			'unknown administration' => $service->agedUnmatchedItems('adm-x', '2026-02-01'),
			'control adm-1' => $service->hasUnresolvedItems('adm-1', '2026-02-01'),
			'control unknown' => $service->hasUnresolvedItems('adm-x', '2026-02-01'),
		];

		$golden = __DIR__ . '/fixtures/suspense-ageing.golden.json';
		$actual = json_decode(json_encode($answers, JSON_PRESERVE_ZERO_FRACTION), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents($golden, json_encode($actual, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES) . "\n");
		}

		self::assertSame(json_decode((string)file_get_contents($golden), true), $actual);
	}

	/**
	 * The report degrades to an empty worklist and logs; the control path rethrows.
	 *
	 * @return void
	 */
	public function testAFailingLookupDegradesTheReportAndFailsTheControlClosed(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with(
			'SuspenseAgeingService: ageing computation failed',
			['administrationId' => 'adm-1', 'error' => 'register down']
		);
		$service = $this->service(null, $logger);

		self::assertSame(['items' => [], 'count' => 0, 'oldestDaysOutstanding' => 0, 'totalAmountCents' => 0], $service->agedUnmatchedItems('adm-1', '2026-02-01'));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('register down');
		$service->hasUnresolvedItems('adm-1', '2026-02-01');
	}
}
