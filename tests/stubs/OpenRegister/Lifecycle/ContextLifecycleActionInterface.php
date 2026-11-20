<?php

/**
 * Minimal test stub mirroring OCA\OpenRegister\Lifecycle\ContextLifecycleActionInterface.
 *
 * OpenRegister main splits the action contract: the base
 * LifecycleActionInterface declares no execute(), and a handler written for
 * the one-context signature implements this interface. The base stub here
 * still declares execute(LifecycleActionContext) for the handlers written
 * before the split; the signature is the same, so a handler of either kind
 * type-checks. Test-only, like the other stubs in this directory.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Lifecycle;

/**
 * A lifecycle action that takes one LifecycleActionContext.
 */
interface ContextLifecycleActionInterface extends LifecycleActionInterface {

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
