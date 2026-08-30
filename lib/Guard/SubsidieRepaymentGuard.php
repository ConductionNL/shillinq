<?php

/**
 * Subsidie Repayment Guard
 *
 * Lifecycle precondition for the Subsidie schema's
 * `afhandelenVanuitTeruggevorderd` transition (teruggevorderd -> afgehandeld,
 * lib/Settings/shillinq_register.json, REQ-SUB-004).
 *
 * shillinq#425: the class did not exist AND the register.d `requires` value
 * was shaped as an object (`{"guard": "OCA\\Shillinq\\Guard\\
 * SubsidieRepaymentGuard::requireZeroRepaymentBalance"}`) rather than the
 * plain string OpenRegister's LifecycleValidationListener actually reads
 * (`is_string($spec['requires']) === true` — openregister/lib/Listener/
 * LifecycleValidationListener.php:216). That shape bug meant the whole
 * `requires` clause was silently SKIPPED (not even attempted, let alone
 * hard-failing) — this was a true silent control bypass (any dossier could
 * close with an outstanding repayment balance), unlike the other 12 items in
 * this change which hard-fail loudly. Fixed here by (a) correcting the JSON
 * shape to a plain string and (b) implementing this class for real.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Guards Subsidie `afhandelenVanuitTeruggevorderd` — the dossier may only
 * close once every RepaymentInstallment for it is `paid` (i.e. the
 * outstanding repayment balance is zero).
 *
 * Fail-closed: any lookup exception denies the close.
 *
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
 */
class SubsidieRepaymentGuard {
	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for register slug resolution.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Precondition: sum of all non-`paid` RepaymentInstallment.amount for
	 * this Subsidie must be zero.
	 *
	 * @param array<string, mixed> $subsidy The Subsidie object being transitioned.
	 *
	 * @return bool True when the outstanding repayment balance is zero.
	 *
	 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
	 */
	public function requireZeroRepaymentBalance(array $subsidy): bool {
		$subsidyId = ($subsidy['id'] ?? null);
		if ($subsidyId === null) {
			// No persisted identity to look up installments against.
			return false;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$installments = $objectService
				->setRegister($this->register())
				->setSchema('RepaymentInstallment')
				->findAll(['filters' => ['subsidyId' => $subsidyId]]);

			if (is_array($installments) === false) {
				$installments = [];
			}

			$outstandingCents = 0;
			foreach ($installments as $installment) {
				if ((string)($installment['state'] ?? '') === 'paid') {
					continue;
				}

				$outstandingCents += (int)round(((float)($installment['amount'] ?? 0)) * 100);
			}

			if ($outstandingCents !== 0) {
				$this->logger->info(
					'SubsidieRepaymentGuard: outstanding repayment balance — denying close',
					['subsidyId' => $subsidyId, 'outstandingCents' => $outstandingCents]
				);
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'SubsidieRepaymentGuard: requireZeroRepaymentBalance check failed — denying (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end requireZeroRepaymentBalance()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
