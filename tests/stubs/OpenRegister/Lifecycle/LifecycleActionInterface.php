<?php

/**
 * Minimal test stub mirroring OCA\OpenRegister\Lifecycle\LifecycleActionInterface.
 *
 * OpenRegister is a separate app and is not a composer dependency of shillinq
 * (cross-app classes are loaded dynamically at runtime by Nextcloud's shared
 * app autoloader). Unit tests run outside a full Nextcloud bootstrap, so this
 * stub — registered via tests/bootstrap-unit.php's `OCA\OpenRegister\` PSR-4
 * mapping — lets shillinq's LifecycleActionInterface-implementing handlers
 * type-check and run under PHPUnit/PHPStan/Psalm. Mirrors the real
 * openregister/lib/Lifecycle/LifecycleActionInterface.php contract exactly.
 *
 * Test-only: this file is reachable through the dev autoloader alone and is
 * never loaded by the shipped app, so it cannot shadow the real interface on
 * a live instance.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Lifecycle;

/**
 * Apps implement this to run a declared lifecycle action on a transition.
 *
 * Guards authorise a transition (read-only); actions run its side effects. A
 * self-mutating action MUST return the modified object payload — the executor
 * merges the return value back into the object being saved. A pure
 * side-effect action MUST return the payload it received, unchanged.
 */
interface LifecycleActionInterface {
	/**
	 * Run the action on a transitioning object.
	 *
	 * @param LifecycleActionContext $context The payload after and before the transition, the declared
	 *                                        `actionParameters` block and the declared action name.
	 *
	 * @return array<string, mixed> The object payload, with any self-mutations applied.
	 */
	public function execute(LifecycleActionContext $context): array;
}//end interface
