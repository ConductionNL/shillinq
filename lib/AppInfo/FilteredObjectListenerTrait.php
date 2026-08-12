<?php

/**
 * Registration helper for filtered object-event subscription.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\AppInfo;

use OCP\EventDispatcher\IEventDispatcher;

/**
 * Registers object-lifecycle listeners that declare their interest up front.
 *
 * Lives beside {@see Application} rather than inside it purely so the
 * registration plumbing does not push that already-long class past its PHPMD
 * length budget.
 *
 * @spec exclude Performance-only registration plumbing — narrows which
 *       dispatches reach an existing listener without changing any behaviour
 *       a spec describes.
 */
trait FilteredObjectListenerTrait {
	/**
	 * Register one listener for BOTH the create and the update event.
	 *
	 * A listener that has to react to "the object was written" needs the pair,
	 * and registering them separately is six lines of near-identical named
	 * arguments twice over — repeated verbatim for several listeners in
	 * Application::boot(), which sits against PHPMD's class-length budget.
	 * Same filtering semantics and same fallback as
	 * {@see registerFilteredObjectListener()}; the event classes are named as
	 * strings for the same reason (they exist only once OpenRegister does).
	 *
	 * @param IEventDispatcher $dispatcher The live event dispatcher.
	 * @param string $listener Listener class name.
	 * @param array<int,string>|null $registers Register slugs the listener reacts to, or null for all.
	 * @param array<int,string>|null $schemas Schema slugs the listener reacts to, or null for all.
	 *
	 * @return void
	 *
	 * @spec exclude Performance-only registration plumbing — narrows which
	 *       dispatches reach an existing listener without changing any
	 *       behaviour a spec describes.
	 */
	private function registerFilteredObjectWriteListener(
		IEventDispatcher $dispatcher,
		string $listener,
		?array $registers = null,
		?array $schemas = null,
	): void {
		// NO leading backslash. `Foo::class` never produces one and
		// `get_class($event)` never returns one, so a listener registered under
		// `\OCA\...\ObjectCreatedEvent` would be keyed under a string the
		// dispatcher never looks up — a registration that exists and never
		// fires. Same leading-backslash trap the gate helpers document.
		$events = [
			'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
			'OCA\\OpenRegister\\Event\\ObjectUpdatedEvent',
		];
		foreach ($events as $event) {
			$this->registerFilteredObjectListener(
				dispatcher: $dispatcher,
				event: $event,
				listener: $listener,
				registers: $registers,
				schemas: $schemas
			);
		}

	}//end registerFilteredObjectWriteListener()

	/**
	 * Register an object-lifecycle listener that declares its interest up front.
	 *
	 * OpenRegister's `ObjectEventSubscription` records the register/schema slugs
	 * a listener reacts to and routes dispatches through a single shared proxy,
	 * so an uninterested listener is neither constructed nor invoked. Registered
	 * globally, shillinq's listeners were constructed on EVERY object write on
	 * the instance — a larpingapp character create reached the GL compliance
	 * cache listener and bailed at its own `isWatched()` guard.
	 *
	 * The class is referenced as a STRING, never as a compile-time symbol: it
	 * only exists once OpenRegister is installed, and shillinq carries no hard
	 * dependency on it. When it is absent this degrades to the plain global
	 * registration it replaced, which is exactly the behaviour every listener
	 * had before. Passing null for either filter also means "all".
	 *
	 * The per-handler self-filter guards are deliberately left in place: the
	 * declaration narrows *dispatch*, the guard still decides *work*.
	 *
	 * This MUST be called from boot(), never from register(). Nextcloud enables
	 * each app's autoloader immediately before calling THAT app's own
	 * register(), so from register() the `class_exists()` guard below is
	 * boot-order dependent: OpenRegister's classes are only autoloadable to apps
	 * that happen to register after it, and every earlier app silently took the
	 * unfiltered fallback branch. boot() runs only after every app's register()
	 * has completed, so the guard resolves regardless of this app's position.
	 *
	 * @param IEventDispatcher $dispatcher The live event dispatcher.
	 * @param string $event OpenRegister event class name.
	 * @param string $listener Listener class name.
	 * @param array<int,string>|null $registers Register slugs the listener reacts to, or null for all.
	 * @param array<int,string>|null $schemas Schema slugs the listener reacts to, or null for all.
	 *
	 * @return void
	 *
	 * @spec exclude Performance-only registration plumbing — narrows which
	 *       dispatches reach an existing listener without changing any
	 *       behaviour a spec describes.
	 */
	private function registerFilteredObjectListener(
		IEventDispatcher $dispatcher,
		string $event,
		string $listener,
		?array $registers = null,
		?array $schemas = null,
	): void {
		$subscription = '\\OCA\\OpenRegister\\Event\\ObjectEventSubscription';
		if (class_exists($subscription) === true) {
			$subscription::subscribe(
				dispatcher: $dispatcher,
				event: $event,
				listener: $listener,
				registers: $registers,
				schemas: $schemas
			);
			return;
		}

		// Loud on purpose. This fallback is correct but UNFILTERED, and while it
		// was silent it was indistinguishable from a working narrowing.
		\OCP\Server::get(\Psr\Log\LoggerInterface::class)->warning(
			'OpenRegister ObjectEventSubscription unavailable: ' . $listener
			. ' fell back to an UNFILTERED registration for ' . $event
			. ' and will be invoked on every object write instance-wide.',
			['app' => 'shillinq']
		);

		$dispatcher->addServiceListener($event, $listener);

	}//end registerFilteredObjectListener()
}//end trait
