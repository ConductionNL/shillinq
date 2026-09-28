<?php

/**
 * Runs a declared lifecycle transition on an object through OpenRegister.
 *
 * OpenRegister's TransitionEngine is the one path that honours a schema's
 * declared `from` states, guards and actions and then dispatches
 * ObjectTransitionedEvent. Writing the state field directly skips all of it.
 * The engine is resolved lazily so shillinq still boots when OpenRegister is
 * older than the engine.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/banking-manual-match/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Lifecycle;

use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Thin seam over OpenRegister's TransitionEngine.
 *
 * @spec openspec/changes/banking-manual-match/tasks.md#task-2.1
 */
class ObjectTransitionRunner {
	/**
	 * The engine's container id.
	 *
	 * @var string
	 */
	public const ENGINE_CLASS = 'OCA\\OpenRegister\\Service\\Lifecycle\\TransitionEngine';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Server container, for the lazy engine lookup.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {

	}//end __construct()

	/**
	 * Run one declared transition.
	 *
	 * @param string $objectId The object's uuid.
	 * @param string $action   The transition name as the schema declares it.
	 * @param array<string,mixed> $data Declared transition inputs, if any.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the engine is not available.
	 * @throws \Throwable Whatever the engine throws: a refused guard, a state it cannot leave.
	 *
	 * @spec openspec/changes/banking-manual-match/tasks.md#task-2.1
	 */
	public function run(string $objectId, string $action, array $data = []): void {
		if ($this->container->has(self::ENGINE_CLASS) === false) {
			throw new RuntimeException('OpenRegister TransitionEngine is not available');
		}

		$engine = $this->container->get(self::ENGINE_CLASS);
		$engine->transition($objectId, $action, $data);

	}//end run()
}//end class
