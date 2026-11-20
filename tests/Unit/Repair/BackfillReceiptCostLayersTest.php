<?php

/**
 * Unit tests for BackfillReceiptCostLayers.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-receipt-cost-layers/specs/inventory-valuation-fifo-avg/spec.md#requirement-past-receipts-get-their-layers-once-req-ircl-003
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Kernel\Contracts\ReceiptCostLayerBackfillInterface;
use OCA\Shillinq\Repair\BackfillReceiptCostLayers;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The repair runs the backfill once, logs each miscosted issue, and is not
 * marked done when the backfill fails.
 *
 * @covers \OCA\Shillinq\Repair\BackfillReceiptCostLayers
 */
final class BackfillReceiptCostLayersTest extends TestCase {

	/**
	 * A first run replays, logs the listed issue, reports and marks it done.
	 *
	 * @return void
	 */
	public function testAFirstRunReplaysLogsAndMarksItDone(): void {
		$issue = ['move' => 'i-1', 'movementNumber' => 'I-1', 'postedCogs' => 0.0, 'layerCogs' => 932.5];
		$backfill = $this->createMock(ReceiptCostLayerBackfillInterface::class);
		$backfill->expects($this->once())->method('run')->willReturn(['locations' => 2, 'receipts' => 3, 'undercosted' => [$issue]]);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$config->expects($this->once())->method('setValueString')->with('shillinq', 'receipt_cost_layers_backfilled', '1');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('costed without its receipt layers'), $issue);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Shillinq: cost layers replayed for 3 receipt(s) at 2 location(s); 1 issue(s) costed differently, listed in the log.');

		(new BackfillReceiptCostLayers(backfill: $backfill, appConfig: $config, logger: $logger))->run($output);
	}//end testAFirstRunReplaysLogsAndMarksItDone()

	/**
	 * A later upgrade does nothing.
	 *
	 * @return void
	 */
	public function testALaterRunDoesNothing(): void {
		$backfill = $this->createMock(ReceiptCostLayerBackfillInterface::class);
		$backfill->expects($this->never())->method('run');

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('shillinq', 'receipt_cost_layers_backfilled', '')->willReturn('1');
		$config->expects($this->never())->method('setValueString');

		(new BackfillReceiptCostLayers(backfill: $backfill, appConfig: $config, logger: $this->createMock(LoggerInterface::class)))->run($this->createMock(IOutput::class));
	}//end testALaterRunDoesNothing()

	/**
	 * A failed backfill warns and is tried again on the next upgrade.
	 *
	 * @return void
	 */
	public function testAFailureIsNotMarkedDone(): void {
		$backfill = $this->createMock(ReceiptCostLayerBackfillInterface::class);
		$backfill->method('run')->willThrowException(new RuntimeException('register gone'));

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$config->expects($this->never())->method('setValueString');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with('Shillinq: the receipt cost layer backfill failed: register gone');

		$this->assertSame('Shillinq: give receipts posted before cost layers their layers', (new BackfillReceiptCostLayers(backfill: $backfill, appConfig: $config, logger: $this->createMock(LoggerInterface::class)))->getName());
		(new BackfillReceiptCostLayers(backfill: $backfill, appConfig: $config, logger: $this->createMock(LoggerInterface::class)))->run($output);
	}//end testAFailureIsNotMarkedDone()
}//end class
