<?php

/**
 * OrderFulfilment bewijsstuk-gate registrations (REQ-004)
 *
 * Wires both halves of the bewijsstuk-required completion gate into the
 * Nextcloud DI/event container:
 *
 *   1. {@see \OCA\Shillinq\Listener\OrderFulfilmentEvidenceListener} on
 *      OpenRegister's pre-save events, which covers the plain write path
 *      (`POST`/`PUT` on `/apps/openregister/api/objects/…/OrderFulfilment`).
 *   2. The DI alias for the `voltooien` transition's declared `requires` tag,
 *      `OCA\Shillinq\Lifecycle\OrderFulfilmentGuard::canVoltooien`, which
 *      covers OpenRegister's transition endpoint. The tag was declared in the
 *      schema but never registered, so LifecycleGuardRegistry::resolve() threw
 *      rather than evaluating the gate.
 *
 * Lives in its own class rather than inline in {@see Application::register()}
 * deliberately: that class already sits within ~10 lines of PHPMD's
 * `ExcessiveClassLength` threshold (1300), so any inline addition turns
 * `composer check:strict` red for reasons unrelated to the change. Keeping new
 * registrations in focused registrars stops Application growing further, and
 * keeps this gate's wiring next to its own documentation.
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
 * @spec openspec/specs/bookkeeping-tenderned-integratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\AppInfo;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Lifecycle\OrderFulfilmentGuard;
use OCA\Shillinq\Lifecycle\RegisterRequiresGuardAdapter;
use OCA\Shillinq\Listener\OrderFulfilmentEvidenceListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Log\LoggerInterface;

/**
 * Registers the REQ-004 bewijsstuk gate on both the write path and the
 * transition path.
 *
 * @spec openspec/specs/bookkeeping-tenderned-integratie/spec.md
 */
final class OrderFulfilmentGateRegistration {

	/**
	 * The `requires` tag the OrderFulfilment `voltooien` transition declares.
	 *
	 * @var string
	 */
	private const VOLTOOIEN_GUARD_TAG = 'OCA\Shillinq\Lifecycle\OrderFulfilmentGuard::canVoltooien';

	/**
	 * Register the listener and the transition-guard alias.
	 *
	 * @param IRegistrationContext $context The app registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-tenderned-integratie/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		// A direct create/update never triggers a lifecycle transition, so the
		// declarative `requires` guard never sees it. Measured on a live
		// instance: POSTing an OrderFulfilment with `status: completed` and
		// `bewijsstukken: []` returned 201 Created.
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $preSaveEvent) {
			$context->registerEventListener(
				event: $preSaveEvent,
				listener: OrderFulfilmentEvidenceListener::class
			);
		}

		// The transition endpoint's half. Denies with the SAME wording as the
		// listener so a caller cannot tell the two enforcement points apart.
		$context->registerService(
			self::VOLTOOIEN_GUARD_TAG,
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(OrderFulfilmentGuard::class),
					method: 'canVoltooien',
					denyMessage: OrderFulfilmentEvidenceListener::DENY_MESSAGE,
					logger: $c->get(LoggerInterface::class),
				);
			}
		);

	}//end register()
}//end class
