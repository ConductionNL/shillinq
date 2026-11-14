<?php

/**
 * Unit tests for LogCsrdEsrsXbrlAdapter.
 *
 * Without an outbound XBRL connector taxonomy mapping and instance building are
 * deferred, and mandatory-data-point validation answers a blocking sentinel so
 * an unvalidated report cannot be published.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\External\CsrdEsrsXbrl
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

namespace OCA\Shillinq\Tests\Unit\Service\External\CsrdEsrsXbrl;

use OCA\Shillinq\Service\External\CsrdEsrsXbrl\LogCsrdEsrsXbrlAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Shillinq\Service\External\CsrdEsrsXbrl\LogCsrdEsrsXbrlAdapter
 * @uses \OCA\Shillinq\Service\External\CsrdEsrsXbrl\CsrdEsrsXbrlResult
 */
final class LogCsrdEsrsXbrlAdapterTest extends TestCase {

	/**
	 * The info lines the adapter logged, as [message, context] pairs.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * The adapter, over a logger that records every info line.
	 *
	 * @return LogCsrdEsrsXbrlAdapter The adapter.
	 */
	private function adapter(): LogCsrdEsrsXbrlAdapter {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->logged[] = [$message, $context];
			}
		);

		return new LogCsrdEsrsXbrlAdapter(logger: $logger);
	}//end adapter()

	/**
	 * Mapping is deferred under the requested taxonomy, logging the data point count.
	 *
	 * @return void
	 */
	public function testMappingIsDeferred(): void {
		$result = $this->adapter()->mapTaxonomy('ESRS-2024', ['E1-1', 'E1-6']);

		$this->assertSame('DEFERRED', $result->status);
		$this->assertMatchesRegularExpression('/^esrs_map_log_[0-9a-f]{14}$/', $result->documentId);
		$this->assertSame('ESRS-2024', $result->taxonomyVersion);
		$this->assertSame('application/json', $result->contentType);
		$this->assertSame([], $result->missingMandatory);
		$this->assertTrue($result->dormant);
		$this->assertSame(
			['documentId' => $result->documentId, 'taxonomyVersion' => 'ESRS-2024', 'dataPointIds' => ['E1-1', 'E1-6'], 'dataPointCount' => 2],
			$this->logged[0][1]
		);
	}//end testMappingIsDeferred()

	/**
	 * Validation blocks publishing with the LOG_DEFERRED sentinel.
	 *
	 * @return void
	 */
	public function testValidationBlocksPublishing(): void {
		$result = $this->adapter()->validateMandatoryDataPoints('2026');

		$this->assertSame('VALIDATION_BLOCKED', $result->status);
		$this->assertSame(['LOG_DEFERRED'], $result->missingMandatory);
		$this->assertSame('unknown', $result->taxonomyVersion);
		$this->assertMatchesRegularExpression('/^esrs_val_log_[0-9a-f]{14}$/', $result->documentId);
		$this->assertSame(['documentId' => $result->documentId, 'reportingPeriod' => '2026'], $this->logged[0][1]);
	}//end testValidationBlocksPublishing()

	/**
	 * Building an instance is deferred as an XBRL document.
	 *
	 * @return void
	 */
	public function testBuildingAnInstanceIsDeferred(): void {
		$result = $this->adapter()->buildInstance('2026', ['E1-6' => 120]);

		$this->assertSame('DEFERRED', $result->status);
		$this->assertSame('application/xbrl+xml', $result->contentType);
		$this->assertSame('', $result->payloadRef);
		$this->assertMatchesRegularExpression('/^esrs_ixbrl_log_[0-9a-f]{14}$/', $result->documentId);
		$this->assertSame(['documentId' => $result->documentId, 'reportingPeriod' => '2026', 'payload' => ['E1-6' => 120]], $this->logged[0][1]);
	}//end testBuildingAnInstanceIsDeferred()


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
