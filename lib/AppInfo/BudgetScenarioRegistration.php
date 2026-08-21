<?php

/**
 * Budget-scenarios (REQ-BSC-004, REQ-BSC-006) DI registrations
 *
 * Wires both container bindings the budget-scenarios change needs:
 *
 *   1. The `BudgetScenarioModifier` schema's
 *      `x-openregister-lifecycle.preconditions.save` tag
 *      (`OCA\Shillinq\Guard\BudgetScenarioModifierGuard::validateOnSave`), a
 *      `Class::method`-shaped string, same shillinq#425/#433 resolution class
 *      every other guard on {@see Application::register()}'s list already
 *      works around.
 *   2. The `KnownCostScheduleExpanderInterface` → concrete-class binding that
 *      resolves lazily via `class_exists()` at container-resolution time, so
 *      it starts serving the real implementation the moment budget-known-costs
 *      (PR #967, a sibling branch not in this base) lands — with no further
 *      code change here — and fails loudly rather than substituting a no-op
 *      until then.
 *
 * Lives in its own class rather than inline in {@see Application::register()}
 * deliberately: that class already sits within PHPMD's `ExcessiveClassLength`
 * threshold (1300), and this change's own registrations were what would have
 * pushed it over. Keeping new registrations in focused registrars, the same
 * pattern {@see OpdrachtUitvoeringGateRegistration} and
 * {@see SigningDelegationRegistration} already established, stops Application
 * growing further and keeps this change's wiring next to its own
 * documentation.
 *
 * @category AppInfo
 * @package  OCA\Shillinq\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-004
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-006
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\AppInfo;

use OCA\Shillinq\Guard\BudgetScenarioModifierGuard;
use OCA\Shillinq\Lifecycle\RegisterRequiresGuardAdapter;
use OCA\Shillinq\Service\KnownCostScheduleExpanderInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Registers the BudgetScenarioModifier guard alias and the
 * KnownCostScheduleExpanderInterface binding as one unit.
 *
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-004
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-006
 */
final class BudgetScenarioRegistration {

	/**
	 * The `preconditions.save` tag the BudgetScenarioModifier schema declares.
	 *
	 * @var string
	 */
	private const MODIFIER_GUARD_TAG = 'OCA\Shillinq\Guard\BudgetScenarioModifierGuard::validateOnSave';

	/**
	 * The budget-known-costs (PR #967) concrete class this binding resolves
	 * to once that sibling branch lands.
	 *
	 * @var string
	 */
	private const KNOWN_COST_EXPANDER_CLASS = 'OCA\\Shillinq\\Service\\KnownCostScheduleExpander';

	/**
	 * Register the modifier guard alias and the known-cost expander binding.
	 *
	 * @param IRegistrationContext $context The app registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-004
	 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-006
	 */
	public function register(IRegistrationContext $context): void {
		// REQ-BSC-004 — registered (unlike the still-unregistered, pre-existing
		// CashflowRecurringGuard/ProgrammaLinkGuard `preconditions.save` tags,
		// shillinq#433's own fleet-wide gap, out of this change's scope) so
		// this guard actually enforces the one-unresolvable-conflict rule at
		// runtime.
		$context->registerService(
			self::MODIFIER_GUARD_TAG,
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(BudgetScenarioModifierGuard::class),
					method: 'validateOnSave',
					denyMessage: 'This modifier conflicts with an existing modifier in the same scenario, or is missing a required field for its type.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

		// REQ-BSC-006 — BudgetScenarioEvaluator depends on
		// KnownCostScheduleExpanderInterface, not budget-known-costs's own
		// concrete KnownCostScheduleExpander class directly, because that
		// class lives on the sibling branch feat/budget-known-costs (PR #967)
		// and does not exist in this checkout's dependency tree yet (see the
		// interface's own docblock, lib/Service/
		// KnownCostScheduleExpanderInterface.php). This binding resolves the
		// interface to the real class via class_exists() AT RESOLUTION TIME
		// (evaluated fresh on every container ->get(), never cached as a
		// permanent stub) — once budget-known-costs lands and that class
		// declares `implements KnownCostScheduleExpanderInterface`, this
		// starts resolving to the real implementation with NO further code
		// change here. Deliberately fails LOUD (throws) rather than silently
		// substituting a no-op when the concrete class is still absent, so a
		// missing integration reads as a clear error, not a passing test for
		// the wrong reason.
		$context->registerService(
			KnownCostScheduleExpanderInterface::class,
			static function ($c): KnownCostScheduleExpanderInterface {
				// The probe is `is_a(..., allow_string: true)`, NOT class_exists().
				//
				// The concrete class HAS since landed in this checkout, so
				// class_exists() is now true — but it does not yet declare
				// `implements KnownCostScheduleExpanderInterface`. Returning it
				// from a factory typed to the interface is a TypeError at the
				// return, i.e. the "fail loud" intent below was already being
				// bypassed by a probe that asked the wrong question. PHPStan 2
				// reports it as `should return
				// KnownCostScheduleExpander&KnownCostScheduleExpanderInterface`.
				//
				// Completing the integration belongs to budget-known-costs
				// (PR #967), which owns that class; this binding only has to
				// refuse anything that does not satisfy the contract.
				if (is_a(self::KNOWN_COST_EXPANDER_CLASS, KnownCostScheduleExpanderInterface::class, true) === true) {
					$expander = $c->get(self::KNOWN_COST_EXPANDER_CLASS);
					if (($expander instanceof KnownCostScheduleExpanderInterface) === true) {
						return $expander;
					}
				}

				throw new RuntimeException(
					'KnownCostScheduleExpander does not satisfy KnownCostScheduleExpanderInterface in '
					. 'this checkout — BudgetScenarioEvaluator cannot evaluate RECURRING_* modifiers '
					. 'until the class declares `implements KnownCostScheduleExpanderInterface` '
					. '(budget-known-costs, PR #967). This is the stated integration point '
					. '(budget-scenarios design.md §6a, lib/Service/KnownCostScheduleExpanderInterface.php).'
				);
			}
		);

	}//end register()
}//end class
