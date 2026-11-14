<?php

/**
 * Unit tests for DunningStageDispatcher: the payload it hands the channel
 * adapter and the delivery outcome it writes back onto the DunningRun record.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Dunning
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

namespace OCA\Shillinq\Tests\Unit\Service\Dunning;

use OCA\Shillinq\Kernel\Model\DunningChannelSendResult;
use OCA\Shillinq\Service\Dunning\DunningChannelAdapterInterface;
use OCA\Shillinq\Service\Dunning\DunningStageDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Shillinq\Service\Dunning\DunningStageDispatcher
 * @uses   \OCA\Shillinq\Kernel\Model\DunningChannelSendResult
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class DunningStageDispatcherTest extends TestCase {

	/**
	 * A rendered stage record as the tick runner hands it over.
	 *
	 * @return array<string, mixed>
	 */
	private function record(): array {
		return [
			'administrationId' => 'adm-1',
			'invoiceId' => 'inv-7',
			'ladderId' => 'ladder-b2c',
			'stageNr' => 2,
			'templateId' => 'tpl-2',
			'channel' => 'POST',
			'recipientEmail' => 'debiteur@example.nl',
			'recipientName' => 'J. de Vries',
			'recipientAddress' => 'Markt 1, 1234 AB Zuiddrecht',
			'renderedSubject' => 'Tweede herinnering',
			'renderedBody' => 'U heeft nog niet betaald.',
			'renderedPdfHash' => 'sha256:abc',
			'postageStatus' => ['requestedAt' => '2026-11-01T09:00:00+00:00'],
		];
	}//end record()

	public function testTheAdapterGetsTheRenderedStageAndTheRecordCarriesTheOutcome(): void {
		$invoice = ['id' => 'inv-7', 'total' => 121.0];
		$adapter = $this->createMock(DunningChannelAdapterInterface::class);
		$adapter->expects($this->once())->method('send')->with(
			'POST',
			[
				'administrationId' => 'adm-1',
				'invoiceId' => 'inv-7',
				'ladderId' => 'ladder-b2c',
				'stageNr' => 2,
				'templateId' => 'tpl-2',
				'recipientEmail' => 'debiteur@example.nl',
				'recipientName' => 'J. de Vries',
				'recipientAddress' => 'Markt 1, 1234 AB Zuiddrecht',
				'subject' => 'Tweede herinnering',
				'body' => 'U heeft nog niet betaald.',
				'renderedPdfHash' => 'sha256:abc',
				'invoice' => $invoice,
			]
		)->willReturn(new DunningChannelSendResult(channel: 'POST', deliveryStatus: 'SENT', extras: ['barcode' => '3SABCD', 'trackingUrl' => 'https://track/3SABCD']));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		$out = (new DunningStageDispatcher($adapter, $logger))->dispatch($this->record(), $invoice);

		$this->assertSame('SENT', $out['deliveryStatus']);
		$this->assertNull($out['deliveryNote']);
		$this->assertSame(
			['requestedAt' => '2026-11-01T09:00:00+00:00', 'barcode' => '3SABCD', 'trackingUrl' => 'https://track/3SABCD'],
			$out['postageStatus'],
			'the tracking facts are merged onto what the record already held'
		);
		$this->assertSame('Tweede herinnering', $out['renderedSubject'], 'the rest of the record is kept');
	}//end testTheAdapterGetsTheRenderedStageAndTheRecordCarriesTheOutcome()

	public function testAnEmptyRecordGoesOutByEmailWithNullFieldsAndKeepsNoPostage(): void {
		$adapter = $this->createMock(DunningChannelAdapterInterface::class);
		$adapter->expects($this->once())->method('send')->with(
			'EMAIL',
			$this->callback(static fn (array $payload): bool => $payload['invoiceId'] === null && $payload['subject'] === null && $payload['invoice'] === [])
		)->willReturn(new DunningChannelSendResult(channel: 'EMAIL', deliveryStatus: 'MANUAL', errorMessage: 'No address on file'));

		$out = (new DunningStageDispatcher($adapter, $this->createMock(LoggerInterface::class)))->dispatch([]);

		$this->assertSame(['deliveryStatus' => 'MANUAL', 'deliveryNote' => 'No address on file'], $out, 'no postage without a barcode or tracking link');
	}//end testAnEmptyRecordGoesOutByEmailWithNullFieldsAndKeepsNoPostage()

	public function testAThrowingAdapterRecordsAFailedRunWithTheReasonAndLogsIt(): void {
		$adapter = $this->createMock(DunningChannelAdapterInterface::class);
		$adapter->method('send')->willThrowException(new RuntimeException('SMTP refused'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Shillinq: dunning stage dispatch failed, the run is recorded as FAILED',
			['invoiceId' => 'inv-7', 'channel' => 'POST', 'exception' => 'SMTP refused']
		);

		$out = (new DunningStageDispatcher($adapter, $logger))->dispatch($this->record());

		$this->assertSame('FAILED', $out['deliveryStatus']);
		$this->assertSame('SMTP refused', $out['deliveryNote']);
		$this->assertSame(['requestedAt' => '2026-11-01T09:00:00+00:00'], $out['postageStatus'], 'a failed send adds no tracking');
	}//end testAThrowingAdapterRecordsAFailedRunWithTheReasonAndLogsIt()
}//end class
