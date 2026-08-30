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
 *   2. The `KnownCostScheduleExpanderInterface` → `KnownCostScheduleExpander`
 *      alias. This was a lazy `class_exists()`-probing factory while the
 *      concrete class was still on an unmerged sibling branch; that class has
 *      landed and now declares the interface, so the probe could no longer
 *      fail and has been replaced by a plain alias (see the call site).
 *
 * Lives in its own class rather than inline in {@see Application::register()}
 * deliberately: that class already sits within PHPMD's `ExcessiveClassLength`
 * threshold (1300), and this change's own registrations were what would have
 * pushed it over. Keeping new registrations in focused registrars, the same
 * pattern {@see OrderFulfilmentGateRegistration} and
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
use OCA\Shillinq\Service\KnownCostScheduleExpander;
use OCA\Shillinq\Service\KnownCostScheduleExpanderInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Log\LoggerInterface;

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
		// KnownCostScheduleExpanderInterface rather than on budget-known-costs's
		// concrete KnownCostScheduleExpander, and this is the binding between them.
		//
		// This used to be a runtime-probing factory. When budget-scenarios was
		// written, the concrete class lived on an unmerged sibling branch (PR #967)
		// and was absent from this checkout, so the factory tested for it at
		// container-resolution time and threw a descriptive RuntimeException when it
		// was missing — deliberately loud, so a missing integration could not read
		// as a passing test.
		//
		// That scaffolding has done its job and is now dead. The class has landed
		// and declares `implements KnownCostScheduleExpanderInterface`, which makes
		// the probe unconditionally true (PHPStan: `function.alreadyNarrowedType`)
		// and the throw unreachable. Keeping a guard that can no longer fail is
		// worse than not having one: it reads like an active safety check while
		// testing nothing.
		//
		// A plain alias is now strictly stronger than the probe ever was. The
		// `implements` clause is checked by PHP at class-load time and by PHPStan
		// at analysis time, so a class that stops satisfying the contract fails
		// earlier and louder than any container-resolution check could manage.
		$context->registerServiceAlias(
			KnownCostScheduleExpanderInterface::class,
			KnownCostScheduleExpander::class
		);

	}//end register()
}//end class
