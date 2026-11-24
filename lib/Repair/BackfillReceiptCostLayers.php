<?php

/**
 * Backfill Receipt Cost Layers
 *
 * Gives the receipts posted before inventory-receipt-cost-layers their
 * layers, once: the stock ledger is replayed into the valuation snapshots
 * without posting anything, and every issue whose posted cost of goods sold
 * differs from the layer-based cost is logged with both amounts, for an
 * accountant's correcting journal.
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-receipt-cost-layers/specs/inventory-valuation-fifo-avg/spec.md#requirement-past-receipts-get-their-layers-once-req-ircl-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\ReceiptCostLayerBackfillInterface;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Replays the stock ledger into the valuation snapshots once.
 *
 * @spec openspec/changes/inventory-receipt-cost-layers/specs/inventory-valuation-fifo-avg/spec.md#requirement-past-receipts-get-their-layers-once-req-ircl-003
 */
class BackfillReceiptCostLayers implements IRepairStep {

	/**
	 * The app config key set once the backfill ran.
	 */
	public const DONE_KEY = 'receipt_cost_layers_backfilled';

	/**
	 * Constructor.
	 *
	 * @param ReceiptCostLayerBackfillInterface $backfill  Replays the ledger.
	 * @param IAppConfig                        $appConfig Holds the done flag.
	 * @param LoggerInterface                   $logger    Logger.
	 */
	public function __construct(
		private readonly ReceiptCostLayerBackfillInterface $backfill,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/inventory-receipt-cost-layers/specs/inventory-valuation-fifo-avg/spec.md#requirement-past-receipts-get-their-layers-once-req-ircl-003
	 */
	public function getName(): string {
		return 'Shillinq: give receipts posted before cost layers their layers';

	}//end getName()

	/**
	 * Run the backfill unless it ran before.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inventory-receipt-cost-layers/specs/inventory-valuation-fifo-avg/spec.md#requirement-past-receipts-get-their-layers-once-req-ircl-003
	 */
	public function run(IOutput $output): void {
		if ($this->appConfig->getValueString(AppIdentity::APP_ID, self::DONE_KEY, '') === '1') {
			return;
		}

		try {
			$result = $this->backfill->run();
		} catch (Throwable $e) {
			// Not marked done: the next upgrade tries again.
			$output->warning('Shillinq: the receipt cost layer backfill failed: ' . $e->getMessage());
			$this->logger->warning('Shillinq: the receipt cost layer backfill failed', ['exception' => $e->getMessage()]);
			return;
		}

		foreach ($result['undercosted'] as $issue) {
			$this->logger->warning('Shillinq: an issue was costed without its receipt layers; correct it with a journal entry', $issue);
		}

		$this->appConfig->setValueString(AppIdentity::APP_ID, self::DONE_KEY, '1');
		$output->info(
			sprintf(
				'Shillinq: cost layers replayed for %d receipt(s) at %d location(s); %d issue(s) costed differently, listed in the log.',
				$result['receipts'],
				$result['locations'],
				count($result['undercosted'])
			)
		);

	}//end run()
}//end class
