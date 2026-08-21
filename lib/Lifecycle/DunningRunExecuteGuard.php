<?php

/**
 * Dunning Run Execute Guard
 *
 * ADR-031 exception-path lifecycle guard for DunningRun.execute. Refuses the
 * transition when:
 *   - the linked invoice has an active DunningPauseDispute (REQ-CCD-004), or
 *   - the run targets stage 3 for a B2C invoice but the renderedBody is missing
 *     the verbatim 14-day grace text mandated by art. 6:96 lid 6 BW (REQ-CCD-006).
 *
 * Fail-closed: any exception returns false. The DunningRun therefore stays in
 * lifecycleState=draft and the operator must remedy the underlying issue
 * before re-attempting execution.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-15
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-16
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle precondition guard for DunningRun.execute.
 *
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-16
 */
class DunningRunExecuteGuard {
	/**
	 * Mandatory wettelijke 14-day grace text per art. 6:96 lid 6 BW.
	 *
	 * Matched as a case-insensitive substring; the legally required phrasing
	 * is the operative "14 dagen om de factuur alsnog te voldoen" clause that
	 * the RJ Guidance documents as the minimum disclosure.
	 */
	public const B2C_14D_FRAGMENT = '14 dagen om de factuur alsnog te voldoen';

	/**
	 * Construct the guard.
	 *
	 * @param IAppConfig $appConfig App config.
	 * @param LoggerInterface $logger Logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Whether the DunningRun is permitted to transition draft → executed.
	 *
	 * @param string $runId The DunningRun.id.
	 *
	 * @return bool True when execution is allowed.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-16
	 */
	public function canExecute(string $runId): bool {
		try {
			$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
			if ($register === '') {
				$register = 'shillinq';
			}

			$runs = $this->objectService
				->setRegister($register)
				->setSchema('DunningRun')
				->findAll(['filters' => ['id' => $runId]]);
			if ($runs === []) {
				return false;
			}

			$run = $runs[0];

			$invoiceId = (string)($run['invoiceId'] ?? '');
			if ($invoiceId === '') {
				return false;
			}

			$pauses = $this->objectService
				->setRegister($register)
				->setSchema('DunningPauseDispute')
				->findAll(
					[
						'filters' => [
							'invoiceId' => $invoiceId,
							'lifecycleState' => 'active',
						],
					]
				);
			if ($pauses !== []) {
				$this->logger->info('Shillinq: DunningRun ' . $runId . ' blocked by active pause.');
				return false;
			}

			// REQ-CCD-006: B2C stage 3 MUST include the 14-day grace text.
			$stageNr = (int)($run['stageNr'] ?? 0);
			if ($stageNr === 3) {
				$body = (string)($run['renderedBody'] ?? '');
				// The partyType lookup would normally resolve through the
				// invoice record; absent an integrated AR core, treat the
				// run's presence-of-fragment as the authoritative signal.
				if (mb_stripos($body, self::B2C_14D_FRAGMENT) === false) {
					$this->logger->info(
						'Shillinq: DunningRun ' . $runId . ' stage-3 missing 14-day grace fragment; blocked.'
					);
					return false;
				}
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('Shillinq: DunningRunExecuteGuard failed: ' . $e->getMessage());
			return false;
		}//end try

	}//end canExecute()
}//end class
