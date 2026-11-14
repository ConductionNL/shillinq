<?php

/**
 * Unit tests for LogUwvLoonaangifteAdapter.
 *
 * Without an outbound connector a loonaangifte status pull and a sector lookup
 * are deferred, each under its own reference.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\External\Uwv
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\External\Uwv;

use OCA\Shillinq\Service\External\Uwv\LogUwvLoonaangifteAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Shillinq\Service\External\Uwv\LogUwvLoonaangifteAdapter
 * @uses \OCA\Shillinq\Service\External\Uwv\UwvStatusResult
 */
final class LogUwvLoonaangifteAdapterTest extends TestCase {

	/**
	 * The info lines the adapter logged, as [message, context] pairs.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * The adapter, over a logger that records every info line.
	 *
	 * @return LogUwvLoonaangifteAdapter The adapter.
	 */
	private function adapter(): LogUwvLoonaangifteAdapter {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->logged[] = [$message, $context];
			}
		);

		return new LogUwvLoonaangifteAdapter(logger: $logger);
	}//end adapter()

	/**
	 * A status pull is deferred under a pull reference.
	 *
	 * @return void
	 */
	public function testAStatusPullIsDeferred(): void {
		$result = $this->adapter()->pullStatus(['berichtId' => 'b-1']);

		$this->assertSame('STATUS_DEFERRED', $result->outcome);
		$this->assertMatchesRegularExpression('/^uwv-pull-log-[0-9a-f]{16}$/', $result->reference);
		$this->assertTrue($result->dormant);
		$this->assertSame('no-outbound-connector-bound', $result->extras['reason']);
		$this->assertSame(['reference' => $result->reference, 'payload' => ['berichtId' => 'b-1']], $this->logged[0][1]);
	}//end testAStatusPullIsDeferred()

	/**
	 * A sector lookup is deferred, logging the sector, year and context.
	 *
	 * @return void
	 */
	public function testASectorLookupIsDeferred(): void {
		$result = $this->adapter()->lookupSector('45', 2026, ['werkgever' => 'w-1']);

		$this->assertSame('SECTOR_DEFERRED', $result->outcome);
		$this->assertMatchesRegularExpression('/^uwv-sector-log-[0-9a-f]{12}$/', $result->reference);
		$this->assertSame(
			['reference' => $result->reference, 'sectorCode' => '45', 'peiljaar' => 2026, 'context' => ['werkgever' => 'w-1']],
			$this->logged[0][1]
		);
	}//end testASectorLookupIsDeferred()


	/**
	 * The adapter reports itself dormant.
	 *
	 * @return void
	 */
	public function testTheAdapterIsDormant(): void {
		$this->assertTrue($this->adapter()->isDormant());
		$this->assertSame([], $this->logged);
	}//end testTheAdapterIsDormant()
}//end class
