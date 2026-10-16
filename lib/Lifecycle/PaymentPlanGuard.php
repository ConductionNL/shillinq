<?php

/**
 * Payment Plan Guard
 *
 * The precondition on PaymentPlan.activate: a plan is only agreed when its
 * instalments add up to the plan total to the cent (receivables-payment-plans
 * design.md D3, REQ-RPPL-001). Declared as the transition's `requires` in
 * lib/Settings/register.d/receivables-payment-plans.json and run by
 * OpenRegister's LifecycleValidationListener. Read-only, and fail closed: a plan
 * whose instalments cannot be read is not activated.
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
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\Shillinq\PaymentPlan\PaymentPlanSchedule;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Util\ObjectIdentifier;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refuses to activate a payment plan whose schedule does not add up.
 *
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 */
final class PaymentPlanGuard implements LifecycleGuardInterface {
	/**
	 * Refusal when the instalments differ from the total.
	 *
	 * @var string
	 */
	public const MESSAGE_UNBALANCED = 'The instalments add up to %s, the plan total is %s. Draw up the schedule again before activating.';

	/**
	 * Refusal when the instalments cannot be read.
	 *
	 * @var string
	 */
	public const MESSAGE_INDETERMINATE = 'The instalments of this plan could not be read, so it is not activated.';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object surface.
	 * @param SettingsService        $settings      Register slug.
	 * @param PaymentPlanSchedule    $schedule      The cent-exact sum check.
	 * @param LoggerInterface        $logger        Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly SettingsService $settings,
		private readonly PaymentPlanSchedule $schedule,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Run the guard for a transition.
	 *
	 * @param array<string,mixed> $object The plan as it will be saved.
	 * @param string              $action The transition.
	 * @param string              $userId The acting user.
	 *
	 * @return GuardResult
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-1.1
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($action !== 'activate') {
			return GuardResult::allow();
		}

		return $this->requireBalancedSchedule(plan: $object);

	}//end check()

	/**
	 * Allow only a plan whose instalments add up to its total.
	 *
	 * @param array<string,mixed> $plan The plan.
	 *
	 * @return GuardResult
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-1.1
	 */
	public function requireBalancedSchedule(array $plan): GuardResult {
		$planId = (string)($plan['id'] ?? ($plan['uuid'] ?? ''));
		if ($planId === '') {
			return GuardResult::deny(self::MESSAGE_INDETERMINATE);
		}

		try {
			$rows = $this->objectService
				->setRegister($this->settings->getRegisterSlug())
				->setSchema('PaymentPlanInstalment')
				->findAll(['filters' => ['planId' => $planId], 'limit' => 1000]);
		} catch (Throwable $e) {
			$this->logger->warning('PaymentPlanGuard: instalments could not be read', ['planId' => $planId, 'exception' => $e->getMessage()]);
			return GuardResult::deny(self::MESSAGE_INDETERMINATE);
		}

		$amounts = [];
		foreach ($rows as $row) {
			$instalment = ObjectIdentifier::recordWithId(candidate: $row);
			if ($instalment !== null) {
				$amounts[] = (float)($instalment['amount'] ?? 0);
			}
		}

		$total = (float)($plan['totalAmount'] ?? 0);
		if ($this->schedule->balances(total: $total, amounts: $amounts) === true) {
			return GuardResult::allow();
		}

		return GuardResult::deny(
			sprintf(self::MESSAGE_UNBALANCED, number_format(array_sum($amounts), 2, '.', ','), number_format($total, 2, '.', ','))
		);

	}//end requireBalancedSchedule()
}//end class
