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

use OCA\Shillinq\Kernel\AppIdentity;
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
			$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
			if ($register === '') {
				$register = 'shillinq';
			}

			$check = new DunningRunCheck(runId: $runId, register: $register);
			$runs = $this->objectService
				->setRegister($register)
				->setSchema('DunningRun')
				->findAll(['filters' => ['id' => $runId]]);
			if ($runs === []) {
				return false;
			}

			return $this->runExecutable(check: $check, run: $runs[0]);
		} catch (\Throwable $e) {
			$this->logger->warning('Shillinq: DunningRunExecuteGuard failed: ' . $e->getMessage());
			return false;
		}//end try

	}//end canExecute()

	/**
	 * The run has an invoice, no active pause and, at stage 3, the grace text.
	 *
	 * @param DunningRunCheck $check The run being executed.
	 * @param mixed $run The DunningRun record.
	 *
	 * @return bool True when execution is allowed.
	 */
	private function runExecutable(DunningRunCheck $check, mixed $run): bool {
		$invoiceId = (string)($run['invoiceId'] ?? '');
		if ($invoiceId === '') {
			return false;
		}

		if ($this->activePauseExists(check: $check, invoiceId: $invoiceId) === true) {
			$this->logger->info('Shillinq: DunningRun ' . $check->runId . ' blocked by active pause.');
			return false;
		}

		// REQ-CCD-006: B2C stage 3 MUST include the 14-day grace text.
		if ($this->graceTextMissing(run: $run) === true) {
			$this->logger->info(
				'Shillinq: DunningRun ' . $check->runId . ' stage-3 missing 14-day grace fragment; blocked.'
			);
			return false;
		}

		return true;

	}//end runExecutable()

	/**
	 * Whether an active DunningPauseDispute holds the invoice.
	 *
	 * @param DunningRunCheck $check The run being executed.
	 * @param string $invoiceId The run's invoice id.
	 *
	 * @return bool True when the invoice is paused.
	 */
	private function activePauseExists(DunningRunCheck $check, string $invoiceId): bool {
		$pauses = $this->objectService
			->setRegister($check->register)
			->setSchema('DunningPauseDispute')
			->findAll(
				[
					'filters' => [
						'invoiceId' => $invoiceId,
						'lifecycleState' => 'active',
					],
				]
			);

		return $pauses !== [];

	}//end activePauseExists()

	/**
	 * Whether a stage-3 run's body lacks the mandatory 14-day grace fragment.
	 *
	 * The partyType lookup would normally resolve through the invoice record;
	 * absent an integrated AR core, the run's presence-of-fragment is the
	 * authoritative signal.
	 *
	 * @param mixed $run The DunningRun record.
	 *
	 * @return bool True when the run is at stage 3 without the fragment.
	 */
	private function graceTextMissing(mixed $run): bool {
		if ((int)($run['stageNr'] ?? 0) !== 3) {
			return false;
		}

		return mb_stripos((string)($run['renderedBody'] ?? ''), self::B2C_14D_FRAGMENT) === false;

	}//end graceTextMissing()
}//end class
