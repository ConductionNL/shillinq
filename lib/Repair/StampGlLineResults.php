<?php

/**
 * Stamp GL Line Results (repair step)
 *
 * Stamps accountClass and countsInResult on the lines of every transaction
 * that was posted or reversed before GLLineResultStampListener existed, so the
 * segment results include the history (reporting-segment-results, design
 * Migration Plan). Idempotent: a line whose stamp is already right is not
 * written again. A failure is reported and never blocks the upgrade.
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
 * @spec openspec/specs/bookkeeping-cost-centers-dimensions/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Repair\Support\ReadsSourceRowsInBatches;
use OCA\Shillinq\Service\Ledger\GlLineResultStamps;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamps the lines of already posted and reversed transactions once.
 */
class StampGlLineResults implements IRepairStep {
	use ReadsSourceRowsInBatches;

	/**
	 * Constructor.
	 *
	 * @param GlLineResultStamps     $stamps          Writes the stamps.
	 * @param ObjectServiceInterface $objectService   OpenRegister object service.
	 * @param SettingsService        $settingsService Resolves the register slug.
	 * @param LoggerInterface        $logger          Logger.
	 */
	public function __construct(
		private readonly GlLineResultStamps $stamps,
		private readonly ObjectServiceInterface $objectService,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 */
	public function getName(): string {
		return 'Shillinq: stamp the account class and result inclusion on posted ledger lines';

	}//end getName()

	/**
	 * Stamp the lines of every posted and reversed transaction.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-cost-centers-dimensions/spec.md
	 */
	public function run(IOutput $output): void {
		try {
			$transactions = 0;
			$lines = 0;
			foreach (['posted', 'reversed'] as $state) {
				$rows = $this->readAllRows(
					objectService: $this->objectService,
					registerSlug: $this->settingsService->getRegisterSlug(),
					schema: 'GLTransaction',
					filters: ['state' => $state]
				);
				foreach ($rows as $row) {
					$lines += $this->stamps->stampTransaction(transactionId: ObjectIdentifier::resolve(saved: $row));
					$transactions++;
				}
			}

			$output->info(sprintf('Shillinq: %d ledger line(s) stamped over %d posted or reversed transaction(s).', $lines, $transactions));
		} catch (Throwable $e) {
			$output->warning('Shillinq: stamping the ledger lines failed: ' . $e->getMessage());
			$this->logger->warning('Shillinq: stamping the ledger lines failed', ['exception' => $e->getMessage()]);
		}//end try

	}//end run()
}//end class
