<?php

/**
 * Unit tests for LogKvkHandelsregisterAdapter.
 *
 * Without an outbound connector every Handelsregister lookup is deferred: a
 * dormant result for the asked number with no entity, and one info line.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\External\Kvk
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

namespace OCA\Shillinq\Tests\Unit\Service\External\Kvk;

use OCA\Shillinq\Service\External\Kvk\KvkHandelsregisterAdapterInterface;
use OCA\Shillinq\Service\External\Kvk\LogKvkHandelsregisterAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for LogKvkHandelsregisterAdapter.
 *
 * @covers \OCA\Shillinq\Service\External\Kvk\LogKvkHandelsregisterAdapter
 * @uses \OCA\Shillinq\Service\External\Kvk\KvkLookupResult
 */
class LogKvkHandelsregisterAdapterTest extends TestCase {

	/**
	 * Context of every info line the adapter logged.
	 *
	 * @var array<int,array{0:string,1:array<string,mixed>}>
	 */
	private array $logged = [];

	/**
	 * The adapter, over a logger that records every info line and expects no warnings or errors.
	 *
	 * @return LogKvkHandelsregisterAdapter The adapter.
	 */
	private function adapter(): LogKvkHandelsregisterAdapter {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->logged[] = [$message, $context];
			}
		);
		$logger->expects($this->never())->method('warning');
		$logger->expects($this->never())->method('error');

		return new LogKvkHandelsregisterAdapter(logger: $logger);
	}//end adapter()

	/**
	 * A lookup is deferred for the asked number, with no entity and the connector to bind.
	 *
	 * @return void
	 */
	public function testALookupIsDeferred(): void {
		$result = $this->adapter()->lookup('69599084', ['administrationId' => 'adm-1']);

		$this->assertSame('LOOKUP_DEFERRED', $result->lookupStatus);
		$this->assertSame('69599084', $result->kvkNumber);
		$this->assertSame([], $result->entity);
		$this->assertTrue($result->dormant);
		$this->assertSame('no-outbound-connector-bound', $result->extras['reason']);
		$this->assertStringContainsString('`kvk-handelsregister`', $result->extras['note']);
		$this->assertSame(
			[['Shillinq KvK Handelsregister lookup deferred (no outbound connector bound)', ['kvkNumber' => '69599084', 'context' => ['administrationId' => 'adm-1']]]],
			$this->logged
		);
	}//end testALookupIsDeferred()

	/**
	 * A lookup without context logs an empty context.
	 *
	 * @return void
	 */
	public function testALookupWithoutContextLogsNone(): void {
		$this->adapter()->lookup('12345678');

		$this->assertSame(['kvkNumber' => '12345678', 'context' => []], $this->logged[0][1]);
	}//end testALookupWithoutContextLogsNone()

	/**
	 * The adapter is a dormant KvK adapter and logs nothing for saying so.
	 *
	 * @return void
	 */
	public function testTheAdapterIsDormant(): void {
		$adapter = $this->adapter();

		$this->assertInstanceOf(KvkHandelsregisterAdapterInterface::class, $adapter);
		$this->assertTrue($adapter->isDormant());
		$this->assertSame([], $this->logged);
	}//end testTheAdapterIsDormant()
}//end class
