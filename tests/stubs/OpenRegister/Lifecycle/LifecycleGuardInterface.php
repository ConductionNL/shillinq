<?php

/**
 * Minimal test stub mirroring OCA\OpenRegister\Lifecycle\LifecycleGuardInterface.
 *
 * OpenRegister is a separate app and is not a composer dependency of shillinq
 * (cross-app classes are loaded dynamically at runtime by Nextcloud's shared
 * app autoloader). Unit tests run outside a full Nextcloud bootstrap, so this
 * stub — registered via tests/bootstrap-unit.php's `OCA\OpenRegister\` PSR-4
 * mapping — lets shillinq's LifecycleGuardInterface-implementing adapter
 * type-check and run under PHPUnit/PHPStan/Psalm. Mirrors the real
 * openregister/lib/Lifecycle/LifecycleGuardInterface.php contract exactly.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Lifecycle;

/**
 * Apps implement this interface to authorise a lifecycle transition.
 */
interface LifecycleGuardInterface {
	/**
	 * Authorise (or deny) a transition.
	 *
	 * @param array<string, mixed> $object The loaded object payload at its current state.
	 * @param string $action The transition action being applied.
	 * @param string $userId The uid of the caller.
	 *
	 * @return GuardResult Allow or deny + optional message.
	 */
	public function check(array $object, string $action, string $userId): GuardResult;
}//end interface
