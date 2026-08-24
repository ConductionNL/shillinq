<?php

/**
 * Register-`requires` Guard Adapter
 *
 * Shillinq#425 root-cause fix, part 2 of 2. Part 1 was the 15 missing guard
 * classes themselves (lib/Guard/*, lib/Lifecycle/*); this is the second,
 * previously-undiscovered half of the same bug class.
 *
 * OpenRegister's `LifecycleGuardRegistry::resolve($tag)` (openregister/lib/
 * Service/Lifecycle/LifecycleGuardRegistry.php) treats the ENTIRE `requires`
 * string — including any `::method` suffix — as a single DI container tag,
 * then calls `->check($object, $action, $userId)` on whatever it resolves.
 * Nextcloud's container (`\OC\AppFramework\Utility\SimpleContainer::query()`)
 * only recognises two shapes: (a) a tag explicitly registered via
 * `registerService()`, or (b) a literal, instantiable class name it can
 * autowire via `ReflectionClass`. A string containing `::` is neither —
 * `new ReflectionClass('OCA\Shillinq\Guard\Foo::bar')` throws
 * `ReflectionException` unconditionally, confirmed empirically against the
 * live container (see shillinq#425 investigation notes) — so ANY
 * `"Class::method"`-shaped `requires` value in this app's register.d /
 * shillinq_register.json can NEVER resolve unless the app explicitly
 * registers that exact literal string as a service alias. Before this
 * change, shillinq registered none of them — not even for guards whose
 * class already existed (MandateEnforcer, BudgetBlocker, PeriodCloseGuard,
 * InventoryPostingGuard, KorThresholdGuard, ...): every one of those
 * transitions ALSO hard-fails today. That pre-existing, fleet-wide gap is
 * filed separately (see shillinq#433) — fixing it for the dozens of
 * already-shipped guards is out of scope for shillinq#425, which is limited
 * to the 17 named classes + PeriodCloseGuard::trialBalanceVerifies.
 *
 * This adapter is the reusable glue: it wraps a plain guard object's single
 * boolean precondition method (the existing, fleet-wide convention — see
 * MandateEnforcer::requiresApproval(), PeriodCloseGuard::periodOpen(), etc.)
 * into a real `LifecycleGuardInterface::check()` implementation, so
 * Application.php can register the EXACT literal `requires` tag string for
 * each of the 16 guards this change fixes and have it genuinely resolve and
 * enforce at runtime — not just exist as an unreachable PHP class.
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
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use Psr\Log\LoggerInterface;

/**
 * Adapts a `bool <method>(array $object): bool` guard method to
 * `LifecycleGuardInterface::check()`.
 *
 * Fail-closed: any exception thrown by the wrapped method denies the
 * transition rather than letting it silently proceed (CWE-863 / OWASP
 * A01:2021), matching the fail-closed convention every guard in this app
 * already follows internally.
 *
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-1
 */
final class RegisterRequiresGuardAdapter implements LifecycleGuardInterface {
	/**
	 * Construct the adapter with the guard instance and method it wraps.
	 *
	 * @param object $guard The guard instance owning the precondition method.
	 * @param string $method Public method name on $guard, signature `(array $object): bool`.
	 * @param string $denyMessage User-facing message returned when the precondition fails.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly object $guard,
		private readonly string $method,
		private readonly string $denyMessage,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Authorise (or deny) a transition by delegating to the wrapped guard's
	 * boolean precondition method.
	 *
	 * @param array<string, mixed> $object The loaded object payload at its current state.
	 * @param string $action The transition action being applied.
	 * @param string $userId The uid of the caller.
	 *
	 * @return GuardResult Allow or deny + optional message.
	 *
	 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-1
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $userId is required by
	 *     LifecycleGuardInterface::check()'s signature; this adapter does
	 *     not discriminate by caller.
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		try {
			$allowed = ($this->guard->{$this->method}($object) === true);
		} catch (\Throwable $e) {
			$this->logger->error(
				'RegisterRequiresGuardAdapter: guard method threw — denying transition (fail-closed)',
				[
					'guard' => get_class($this->guard),
					'method' => $this->method,
					'action' => $action,
					'error' => $e->getMessage(),
				]
			);
			return GuardResult::deny($this->denyMessage);
		}

		if ($allowed === true) {
			return GuardResult::allow();
		}

		return GuardResult::deny($this->denyMessage);
	}//end check()
}//end class
