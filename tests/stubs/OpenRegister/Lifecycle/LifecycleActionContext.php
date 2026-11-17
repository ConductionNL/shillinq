<?php

/**
 * Minimal test stub mirroring OCA\OpenRegister\Lifecycle\LifecycleActionContext.
 *
 * What a lifecycle action runs on: the object payload after and before the
 * transition, the declared action parameters and the declared action name.
 * Registered via tests/bootstrap-unit.php's `OCA\OpenRegister\` PSR-4 mapping,
 * like the LifecycleActionInterface stub next to it. Mirrors the real
 * openregister/lib/Lifecycle/LifecycleActionContext.php.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Lifecycle;

/**
 * Read-only input of LifecycleActionInterface::execute().
 */
final class LifecycleActionContext {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $objectData   The object payload after the lifecycle field moved to its target value.
	 * @param array<string, mixed> $previousData The object payload before the transition.
	 * @param array<string, mixed> $parameters   The declared `actionParameters` block (empty array when absent).
	 * @param string               $actionName   The declared `action` name that resolved to the handler.
	 */
	public function __construct(
		public readonly array $objectData,
		public readonly array $previousData,
		public readonly array $parameters,
		public readonly string $actionName,
	) {
	}//end __construct()
}//end class
