<?php

/**
 * Unit tests for LogRvOAanvraagAdapter.
 *
 * Without an outbound connector an RvO request is deferred, and its attachment
 * bytes are dropped before the payload is logged.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\External\RvO
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

namespace OCA\Shillinq\Tests\Unit\Service\External\RvO;

use OCA\Shillinq\Service\External\RvO\LogRvOAanvraagAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Shillinq\Service\External\RvO\LogRvOAanvraagAdapter
 * @uses \OCA\Shillinq\Service\External\RvO\RvORequestResult
 */
final class LogRvOAanvraagAdapterTest extends TestCase {

	/**
	 * The info lines the adapter logged, as [message, context] pairs.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * The adapter, over a logger that records every info line.
	 *
	 * @return LogRvOAanvraagAdapter The adapter.
	 */
	private function adapter(): LogRvOAanvraagAdapter {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->logged[] = [$message, $context];
			}
		);

		return new LogRvOAanvraagAdapter(logger: $logger);
	}//end adapter()

	/**
	 * The attachment bytes are dropped from the logged payload.
	 *
	 * @return void
	 */
	public function testAttachmentBytesAreNotLogged(): void {
		$result = $this->adapter()->submit(['scheme' => 'WBSO', 'attachmentBytes' => 'JVBERi0xLjQ=', 'amount' => 5000]);

		$this->assertSame('DEFERRED', $result->deliveryStatus);
		$this->assertMatchesRegularExpression('/^rvo-log-[0-9a-f]{16}$/', $result->aanvraagnummer);
		$this->assertSame('WBSO', $result->extras['scheme']);
		$this->assertTrue($result->dormant);
		$this->assertSame(
			['aanvraagnummer' => $result->aanvraagnummer, 'scheme' => 'WBSO', 'payload' => ['scheme' => 'WBSO', 'amount' => 5000]],
			$this->logged[0][1]
		);
	}//end testAttachmentBytesAreNotLogged()

	/**
	 * A request naming no scheme is logged under "unknown".
	 *
	 * @return void
	 */
	public function testAnUnnamedSchemeIsUnknown(): void {
		$result = $this->adapter()->submit([]);

		$this->assertSame('unknown', $result->extras['scheme']);
		$this->assertSame('unknown', $this->logged[0][1]['scheme']);
	}//end testAnUnnamedSchemeIsUnknown()


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
