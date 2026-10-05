<?php

/**
 * Stamp VAT Return Boxes (repair step)
 *
 * Stamps the VAT tariff, return box and amount kind on posted ledger lines
 * that do not carry them yet, so a VAT return prepared from the books
 * (REQ-VBTW-004) includes the history (tax-vat-return-from-books, design D1
 * and Migration Plan). Runs after InitializeSettings, which imports the
 * fields and seeds the VAT tariffs the boxes come from. Idempotent: a line
 * whose stamps already match is not written again. A failure is reported
 * and never blocks the upgrade.
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
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-1.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\Shillinq\Service\Vat\VatLineBackfill;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps the VAT return box on already posted ledger lines once.
 *
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-1.3
 */
class StampVatReturnBoxes implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param VatLineBackfill $backfill Stamps the lines.
	 * @param LoggerInterface $logger   Logger.
	 */
	public function __construct(
		private readonly VatLineBackfill $backfill,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-1.3
	 */
	public function getName(): string {
		return 'Shillinq: stamp the VAT tariff and return box on posted ledger lines';
	}//end getName()

	/**
	 * Stamp the lines of every posted and reversed transaction.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-1.3
	 */
	public function run(IOutput $output): void {
		try {
			$result = $this->backfill->run();
			$output->info(
				sprintf(
					'Shillinq: %d ledger line(s) stamped with their VAT return box; %d VAT line(s) could not be resolved (see the log).',
					$result['stamped'],
					$result['unresolved']
				)
			);
		} catch (Throwable $e) {
			$output->warning('Shillinq: stamping the VAT return boxes failed: ' . $e->getMessage());
			$this->logger->warning('Shillinq: stamping the VAT return boxes failed', ['exception' => $e->getMessage()]);
		}
	}//end run()
}//end class
