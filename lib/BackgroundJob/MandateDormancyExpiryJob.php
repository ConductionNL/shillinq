<?php

/**
 * Mandate Dormancy Expiry Job
 *
 * ADR-031 exception-path daily job that expires SEPA mandates dormant beyond
 * the 36-month rulebook threshold (REQ-SDD-008). The decision logic lives in
 * MandateGuard::canExpire (unit-tested); this job only iterates active
 * mandates and applies the transition, deferring to OpenRegister's
 * ScheduledWorkflow primitive once it is stable (proposal Open Question 1).
 *
 * ADR-031 exception reason: OpenRegister does not yet expose a stable
 * scheduled-workflow trigger for time-based lifecycle transitions. When it
 * does, replace this job with a declarative ScheduledWorkflow and delete the
 * file.
 *
 * @category Job
 * @package  OCA\Shillinq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Lifecycle\MandateGuard;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Daily background job expiring dormant SEPA mandates (REQ-SDD-008).
 *
 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
 */
class MandateDormancyExpiryJob extends TimedJob {

	/**
	 * Run interval: once per day (REQ-SDD-008 "the daily expiry job").
	 */
	private const INTERVAL_SECONDS = 86400;

	/**
	 * Construct the job.
	 *
	 * @param ITimeFactory $time Time factory for TimedJob scheduling.
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param MandateGuard $guard Dormancy decision guard.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly MandateGuard $guard,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Iterate active mandates and expire those dormant beyond 36 months.
	 *
	 * Fail-soft: any error is logged and swallowed so a single bad record
	 * never aborts the whole run.
	 *
	 * @param mixed $argument The job argument (unused).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	protected function run($argument): void {
		try {
			$register = $this->resolveRegister();
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

			$mandates = $objectService
				->setRegister($register)
				->setSchema('SepaMandate')
				->findAll(['filters' => ['status' => 'active']]);

			$expired = 0;
			foreach ($mandates as $mandate) {
				if (is_array($mandate) === false) {
					continue;
				}

				$mandateId = (string)($mandate['id'] ?? '');
				if ($this->guard->canExpire(mandateId: $mandateId, object: $mandate) === false) {
					continue;
				}

				$mandate['status'] = 'expired';
				$objectService
					->setRegister($register)
					->setSchema('SepaMandate')
					->saveObject($mandate);
				$expired++;
			}//end foreach

			if ($expired > 0) {
				$this->logger->info(
					'MandateDormancyExpiryJob: expired ' . $expired . ' dormant SEPA mandate(s) (REQ-SDD-008)'
				);
			}
		} catch (\Throwable $e) {
			$this->logger->error(
				'MandateDormancyExpiryJob: run failed',
				['exception' => $e->getMessage()]
			);
		}//end try
	}//end run()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to `shillinq`.
	 *
	 * @return string The register slug.
	 */
	private function resolveRegister(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end resolveRegister()
}//end class
