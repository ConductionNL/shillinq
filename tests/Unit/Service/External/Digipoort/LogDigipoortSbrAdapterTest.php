<?php

/**
 * Unit tests for LogDigipoortSbrAdapter.
 *
 * Without an outbound connector every SBR submission is deferred: a dormant
 * result with a fresh reference, and one info line without the XBRL bytes.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\External\Digipoort
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

namespace OCA\Shillinq\Tests\Unit\Service\External\Digipoort;

use OCA\Shillinq\Service\External\Digipoort\DigipoortSbrAdapterInterface;
use OCA\Shillinq\Service\External\Digipoort\LogDigipoortSbrAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for LogDigipoortSbrAdapter.
 *
 * @covers \OCA\Shillinq\Service\External\Digipoort\LogDigipoortSbrAdapter
 * @uses \OCA\Shillinq\Service\External\Digipoort\DigipoortSubmissionResult
 */
class LogDigipoortSbrAdapterTest extends TestCase {

	/**
	 * Context of every info line the adapter logged.
	 *
	 * @var array<int,array{0:string,1:array<string,mixed>}>
	 */
	private array $logged = [];

	/**
	 * The adapter, over a logger that records every info line and expects no warnings or errors.
	 *
	 * @return LogDigipoortSbrAdapter The adapter.
	 */
	private function adapter(): LogDigipoortSbrAdapter {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->logged[] = [$message, $context];
			}
		);
		$logger->expects($this->never())->method('warning');
		$logger->expects($this->never())->method('error');

		return new LogDigipoortSbrAdapter(logger: $logger);
	}//end adapter()

	/**
	 * A submission is deferred with a fresh reference and the connector to bind.
	 *
	 * @return void
	 */
	public function testASubmissionIsDeferred(): void {
		$adapter = $this->adapter();
		$first   = $adapter->submit(['kind' => 'VPB', 'period' => '2025']);
		$second  = $adapter->submit(['kind' => 'VPB', 'period' => '2025']);

		$this->assertSame('DEFERRED', $first->deliveryStatus);
		$this->assertMatchesRegularExpression('/^digipoort-log-[0-9a-f]{16}$/', $first->reference);
		$this->assertNotSame($first->reference, $second->reference);
		$this->assertTrue($first->dormant);
		$this->assertSame('no-outbound-connector-bound', $first->extras['reason']);
		$this->assertStringContainsString('`digipoort-sbr`', $first->extras['note']);
		$this->assertCount(2, $this->logged);
		$this->assertSame('Shillinq Digipoort/SBR submission deferred (no outbound connector bound)', $this->logged[0][0]);
	}//end testASubmissionIsDeferred()

	/**
	 * The XBRL instance bytes are left out of the log; everything else, the checksum included, is kept.
	 *
	 * @return void
	 */
	public function testTheXbrlBytesAreNotLogged(): void {
		$result = $this->adapter()->submit(['kind' => 'OB', 'xbrlInstanceBytes' => '<xbrl>secret</xbrl>', 'checksum' => 'sha256:ab']);

		$this->assertSame(
			['reference' => $result->reference, 'payload' => ['kind' => 'OB', 'checksum' => 'sha256:ab']],
			$this->logged[0][1]
		);
		$this->assertStringNotContainsString('secret', json_encode($this->logged));
	}//end testTheXbrlBytesAreNotLogged()

	/**
	 * The adapter is a dormant Digipoort adapter and logs nothing for saying so.
	 *
	 * @return void
	 */
	public function testTheAdapterIsDormant(): void {
		$adapter = $this->adapter();

		$this->assertInstanceOf(DigipoortSbrAdapterInterface::class, $adapter);
		$this->assertTrue($adapter->isDormant());
		$this->assertSame([], $this->logged);
	}//end testTheAdapterIsDormant()
}//end class
