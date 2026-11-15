<?php

/**
 * Unit tests for the dormant CBS Bestanden and Iv3 log adapters.
 *
 * Pins the deferred contract both defaults keep until a real outbound
 * connector is bound: what is logged (and that the raw file bytes are not),
 * the tracking id format, and the dormant DEFERRED result.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\External\Cbs
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

namespace OCA\Shillinq\Tests\Unit\Service\External\Cbs;

use OCA\Shillinq\Service\External\Cbs\CbsBestandenAdapterInterface;
use OCA\Shillinq\Service\External\Cbs\CbsIv3AdapterInterface;
use OCA\Shillinq\Service\External\Cbs\CbsSubmissionResult;
use OCA\Shillinq\Service\External\Cbs\LogCbsBestandenAdapter;
use OCA\Shillinq\Service\External\Cbs\LogCbsIv3Adapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Both dormant CBS adapters log a sanitised payload and return a deferred result.
 */
#[CoversClass(LogCbsBestandenAdapter::class)]
#[CoversClass(LogCbsIv3Adapter::class)]
#[CoversClass(CbsSubmissionResult::class)]
final class LogCbsAdaptersTest extends TestCase {

	/**
	 * A logger that records every info call.
	 *
	 * @param array<int, array{string, array<string, mixed>}> $calls The recorded calls.
	 *
	 * @return LoggerInterface The logger.
	 */
	private function recordingLogger(array &$calls): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->willReturnCallback(
			static function (string $message, array $context = []) use (&$calls): void {
				$calls[] = [$message, $context];
			}
		);
		return $logger;
	}//end recordingLogger()

	/**
	 * Bestanden: the file bytes are dropped from the log, the rest is kept, and the result is deferred.
	 *
	 * @return void
	 */
	public function testBestandenSubmitLogsSanitisedPayloadAndDefers(): void {
		$calls   = [];
		$adapter = new LogCbsBestandenAdapter(logger: $this->recordingLogger($calls));
		$payload = ['period' => '2026-Q3', 'checksum' => 'sha256:abc', 'iv3FileBytes' => 'RAW-BYTES'];

		$result = $adapter->submit($payload);

		$this->assertInstanceOf(CbsBestandenAdapterInterface::class, $adapter);
		$this->assertMatchesRegularExpression('/^cbs-log-[0-9a-f]{16}$/', $result->trackingId);
		$this->assertSame('DEFERRED', $result->deliveryStatus);
		$this->assertTrue($result->dormant);
		$this->assertSame('no-outbound-connector-bound', $result->extras['reason']);
		$this->assertStringContainsString('`cbs-bestanden`', $result->extras['note']);
		$this->assertStringContainsString('CbsBestandenAdapterInterface', $result->extras['note']);
		$this->assertSame(
			[
				[
					'Shillinq CBS Bestanden submission deferred (no outbound connector bound)',
					['trackingId' => $result->trackingId, 'payload' => ['period' => '2026-Q3', 'checksum' => 'sha256:abc']],
				],
			],
			$calls
		);
		$this->assertTrue($adapter->isDormant());
	}//end testBestandenSubmitLogsSanitisedPayloadAndDefers()

	/**
	 * Iv3: the reporting XML bytes are dropped from the log, other byte keys are kept, and the result is deferred.
	 *
	 * @return void
	 */
	public function testIv3SubmitLogsSanitisedPayloadAndDefers(): void {
		$calls   = [];
		$adapter = new LogCbsIv3Adapter(logger: $this->recordingLogger($calls));
		$payload = ['period' => '2026-Q3', 'reportingXmlBytes' => '<xml/>', 'iv3FileBytes' => 'kept'];

		$result = $adapter->submit($payload);

		$this->assertInstanceOf(CbsIv3AdapterInterface::class, $adapter);
		$this->assertMatchesRegularExpression('/^iv3-log-[0-9a-f]{16}$/', $result->trackingId);
		$this->assertSame('DEFERRED', $result->deliveryStatus);
		$this->assertTrue($result->dormant);
		$this->assertSame('no-outbound-connector-bound', $result->extras['reason']);
		$this->assertStringContainsString('`cbs-iv3`', $result->extras['note']);
		$this->assertStringContainsString('CbsIv3AdapterInterface', $result->extras['note']);
		$this->assertSame(
			[
				[
					'Shillinq CBS Iv3 submission deferred (no outbound connector bound)',
					['trackingId' => $result->trackingId, 'payload' => ['period' => '2026-Q3', 'iv3FileBytes' => 'kept']],
				],
			],
			$calls
		);
		$this->assertTrue($adapter->isDormant());
	}//end testIv3SubmitLogsSanitisedPayloadAndDefers()

	/**
	 * Every submission gets its own tracking id.
	 *
	 * @return void
	 */
	public function testTrackingIdsAreUniquePerSubmission(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$iv3    = new LogCbsIv3Adapter(logger: $logger);
		$files  = new LogCbsBestandenAdapter(logger: $logger);

		$this->assertNotSame($iv3->submit([])->trackingId, $iv3->submit([])->trackingId);
		$this->assertNotSame($files->submit([])->trackingId, $files->submit([])->trackingId);
	}//end testTrackingIdsAreUniquePerSubmission()

	/**
	 * The result keeps what it is built with and defaults to no extras.
	 *
	 * @return void
	 */
	public function testSubmissionResultDefaults(): void {
		$result = new CbsSubmissionResult(deliveryStatus: 'SENT', trackingId: 't-1', dormant: false);

		$this->assertSame('SENT', $result->deliveryStatus);
		$this->assertSame('t-1', $result->trackingId);
		$this->assertFalse($result->dormant);
		$this->assertSame([], $result->extras);
	}//end testSubmissionResultDefaults()
}//end class
