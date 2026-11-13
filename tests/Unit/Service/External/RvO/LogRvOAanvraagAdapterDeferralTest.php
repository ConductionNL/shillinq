<?php

/**
 * Unit tests for what LogRvOAanvraagAdapter returns for a deferred request.
 *
 * A deferred RvO request has a fresh number, names the connector to bind and
 * carries the scheme it was for.
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

use OCA\Shillinq\Service\External\RvO\RvOAanvraagAdapterInterface;
use OCA\Shillinq\Service\External\RvO\LogRvOAanvraagAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for LogRvOAanvraagAdapter's deferral.
 *
 * @covers \OCA\Shillinq\Service\External\RvO\LogRvOAanvraagAdapter
 * @uses \OCA\Shillinq\Service\External\RvO\RvORequestResult
 */
class LogRvOAanvraagAdapterDeferralTest extends TestCase {

	/**
	 * Context of every info line the adapter logged.
	 *
	 * @var array<int,array{0:string,1:array<string,mixed>}>
	 */
	private array $logged = [];

	/**
	 * The adapter, over a logger that records every info line and expects no warnings or errors.
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
		$logger->expects($this->never())->method('warning');
		$logger->expects($this->never())->method('error');

		return new LogRvOAanvraagAdapter(logger: $logger);
	}//end adapter()

	/**
	 * A deferred request names the connector to bind and the scheme it was for.
	 *
	 * @return void
	 */
	public function testARequestExplainsItsDeferral(): void {
		$adapter = $this->adapter();
		$first   = $adapter->submit(['scheme' => 'wbso']);
		$second  = $adapter->submit(['scheme' => 'wbso']);

		$this->assertInstanceOf(RvOAanvraagAdapterInterface::class, $adapter);
		$this->assertSame('DEFERRED', $first->deliveryStatus);
		$this->assertMatchesRegularExpression('/^rvo-log-[0-9a-f]{16}$/', $first->aanvraagnummer);
		$this->assertNotSame($first->aanvraagnummer, $second->aanvraagnummer);
		$this->assertTrue($first->dormant);
		$this->assertSame(['reason', 'note', 'scheme'], array_keys($first->extras));
		$this->assertSame('no-outbound-connector-bound', $first->extras['reason']);
		$this->assertStringContainsString('`rvo-aanvraag`', $first->extras['note']);
		$this->assertSame('wbso', $first->extras['scheme']);
		$this->assertSame('Shillinq RvO aanvraag deferred (no outbound connector bound)', $this->logged[0][0]);
	}//end testARequestExplainsItsDeferral()

	/**
	 * A non-string scheme is carried as text.
	 *
	 * @return void
	 */
	public function testASchemeIsCarriedAsText(): void {
		$result = $this->adapter()->submit(['scheme' => 42]);

		$this->assertSame('42', $result->extras['scheme']);
		$this->assertSame('42', $this->logged[0][1]['scheme']);
		$this->assertSame(['scheme' => 42], $this->logged[0][1]['payload']);
	}//end testASchemeIsCarriedAsText()
}//end class
