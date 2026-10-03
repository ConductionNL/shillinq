<?php

/**
 * Contract Cost Rollup Job
 *
 * Adds up nightly what the objects raised under each contract have cost, and
 * stamps the total with the time it was computed.
 *
 * WHY NIGHTLY AND NOT ON READ
 * ---------------------------
 * The roll-up crosses registers: it asks a cost service about objects that live
 * in another app. Computing it on read would make a list of forty contracts a
 * list of forty cross-register reads, and the contract overview is exactly the
 * screen somebody opens to scan. So it is computed once a night and stored, and
 * the stamp is what tells a reader how old the number is. A total with no
 * timestamp cannot be told apart from a total that stopped updating.
 *
 * A contract whose roll-up throws is logged and skipped. One unreadable subject
 * must not stop the other contracts from being computed, and the stamp that
 * stops moving is the visible tell.
 *
 * @category BackgroundJob
 * @package  OCA\Shillinq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-005)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\ContractCostRollupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Recomputes every contract's incurred cost once a day.
 *
 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-005)
 */
class ContractCostRollupJob extends TimedJob {
	/**
	 * Daily interval in seconds.
	 *
	 * @var int
	 */
	public const INTERVAL_SECONDS = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Nextcloud's clock.
	 * @param ContractCostRollupService $rollup The roll-up itself.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param IAppConfig $appConfig App config, for the register slug.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ContractCostRollupService $rollup,
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()

	/**
	 * Roll up every contract that has links.
	 *
	 * @param mixed $argument Job argument (unused).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-005)
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is required by
	 * TimedJob's run() signature; this job takes no argument.
	 */
	protected function run($argument): void {
		$register = $this->appConfig->getValueString('shillinq', 'register', 'shillinq');

		try {
			$contracts = $this->objectService
				->setRegister($register)
				->setSchema(ContractCostRollupService::SCHEMA)
				->findAll(['limit' => 1000]);
		} catch (\Throwable $e) {
			$this->logger->error('Shillinq: the contract cost roll-up could not read the contracts', ['exception' => $e->getMessage()]);
			return;
		}

		$done = 0;
		foreach ($contracts as $contract) {
			if (is_array($contract) === false || is_array($contract['linkedObjects'] ?? null) === false) {
				continue;
			}

			if ($contract['linkedObjects'] === []) {
				continue;
			}

			try {
				$this->rollup->rollUp(contract: $contract);
				$done++;
			} catch (\Throwable $e) {
				// One contract that cannot be computed must not stop the rest.
				$this->logger->warning(
					'Shillinq: one contract could not be rolled up',
					['contract' => (string)($contract['id'] ?? ''), 'exception' => $e->getMessage()]
				);
			}
		}

		$this->logger->info('Shillinq: contract cost roll-up finished', ['contracts' => $done]);
	}//end run()
}//end class
