<?php

/**
 * Unit tests for LogBzkSisaUploadAdapter: the dormant SiSa upload logs a
 * sanitised payload and answers a deferred result.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\External\Sisa
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\External\Sisa;

use OCA\Shillinq\Service\External\Sisa\BzkSisaUploadAdapterInterface;
use OCA\Shillinq\Service\External\Sisa\BzkSisaUploadResult;
use OCA\Shillinq\Service\External\Sisa\LogBzkSisaUploadAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Shillinq\Service\External\Sisa\LogBzkSisaUploadAdapter
 * @covers \OCA\Shillinq\Service\External\Sisa\BzkSisaUploadResult
 */
class LogBzkSisaUploadAdapterTest extends TestCase {

	/**
	 * The adapter is the dormant implementation of the upload contract.
	 *
	 * @return void
	 */
	public function testItIsTheDormantUploadAdapter(): void {
		$adapter = new LogBzkSisaUploadAdapter($this->createMock(LoggerInterface::class));

		$this->assertInstanceOf(BzkSisaUploadAdapterInterface::class, $adapter);
		$this->assertTrue($adapter->isDormant());
	}//end testItIsTheDormantUploadAdapter()

	/**
	 * An upload logs the payload without the report and signed PDF bytes, under the tracking id it answers.
	 *
	 * @return void
	 */
	public function testAnUploadIsLoggedWithoutTheDocumentBytes(): void {
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('info')
			->with('Shillinq BZK SiSa upload deferred (no outbound connector bound)', $this->anything())
			->willReturnCallback(
				static function (string $message, array $context) use (&$logged): void {
					$logged = $context;
				}
			);

		$result = (new LogBzkSisaUploadAdapter($logger))->upload(
			payload: [
				'administrationId' => 'adm-1',
				'fiscalYear' => 2026,
				'reportXmlBytes' => '<sisa>secret</sisa>',
				'signedPdfBytes' => '%PDF-1.7',
			]
		);

		$this->assertInstanceOf(BzkSisaUploadResult::class, $result);
		$this->assertSame(['administrationId' => 'adm-1', 'fiscalYear' => 2026], $logged['payload']);
		$this->assertSame($result->trackingId, $logged['trackingId']);
		$this->assertMatchesRegularExpression('/^sisa-log-[0-9a-f]{16}$/', $result->trackingId);
		$this->assertSame('DEFERRED', $result->deliveryStatus);
		$this->assertTrue($result->dormant);
		$this->assertSame('no-outbound-connector-bound', $result->extras['reason']);
		$this->assertStringContainsString('bzk-sisa', $result->extras['note']);
	}//end testAnUploadIsLoggedWithoutTheDocumentBytes()

	/**
	 * Every upload gets its own tracking id.
	 *
	 * @return void
	 */
	public function testEachUploadHasItsOwnTrackingId(): void {
		$adapter = new LogBzkSisaUploadAdapter($this->createMock(LoggerInterface::class));

		$this->assertNotSame($adapter->upload(payload: [])->trackingId, $adapter->upload(payload: [])->trackingId);
	}//end testEachUploadHasItsOwnTrackingId()
}//end class
