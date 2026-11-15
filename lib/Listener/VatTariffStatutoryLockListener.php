<?php

/**
 * VatTariffStatutoryLockListener — keep the statutory VAT tariffs read-only.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The seeded tariffs (high, low, zero, exempt, reverse-charge) carry
 * `statutory: true` and are the rates the law sets; an administrator may add
 * a tariff with its own code and dates, but not change a statutory one, nor
 * mint a record that claims to be statutory (REQ-TVRD-001, design D1). The
 * tariffs are written straight into OpenRegister, so the rule runs on its
 * stoppable pre-save events. A save without a user (the seeder, a repair
 * step) passes: that is how the statutory records get there.
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-vat-rates-and-deductibility/tasks.md#task-1.3
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;

/**
 * Refuse a person's write to a statutory VAT tariff.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
 */
class VatTariffStatutoryLockListener implements IEventListener {

	/**
	 * The schema this listener is scoped to.
	 *
	 * @var string
	 */
	private const SCHEMA_SLUG = 'VatTariff';

	/**
	 * The requirement a refusal names.
	 *
	 * @var string
	 */
	public const REQUIREMENT = 'REQ-TVRD-001';

	/**
	 * Refusal when a statutory tariff is changed.
	 *
	 * @var string
	 */
	public const MESSAGE_CHANGE = 'Tariff %s is a statutory VAT rate and cannot be changed. Add a tariff with its own code and dates instead.';

	/**
	 * Refusal when a person creates a tariff marked statutory.
	 *
	 * @var string
	 */
	public const MESSAGE_CREATE = 'Only the seeded VAT rates are statutory. Add tariff %s without the statutory mark.';

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolverInterface $schemaResolver Resolves the entity's schema to its slug.
	 * @param IUserSession                    $userSession    The caller; a save without a user passes.
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Veto a person's change to a statutory tariff, or a tariff they mark statutory.
	 *
	 * @param Event $event The OpenRegister pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-rates-and-deductibility/tasks.md#task-1.3
	 */
	public function handle(Event $event): void {
		if ($this->userSession->getUser() === null) {
			return;
		}

		$reason = null;
		if ($event instanceof ObjectCreatingEvent === true) {
			$reason = $this->createRefusal(entity: $event->getObject());
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$reason = $this->updateRefusal(new: $event->getNewObject(), old: $event->getOldObject());
		}

		if ($reason !== null) {
			$event->setErrors(['message' => $reason, 'requirement' => self::REQUIREMENT]);
			$event->stopPropagation();
		}
	}//end handle()

	/**
	 * The refusal of a create that marks a tariff statutory, or null.
	 *
	 * @param object|null $entity The entity being created.
	 *
	 * @return string|null
	 */
	private function createRefusal(?object $entity): ?string {
		if ($this->isTariff(entity: $entity) === false) {
			return null;
		}

		$data = (new PreSaveWrite())->payload(entity: $entity);
		if (($data['statutory'] ?? false) !== true) {
			return null;
		}

		return sprintf(self::MESSAGE_CREATE, (string)($data['code'] ?? ''));
	}//end createRefusal()

	/**
	 * The refusal of an update to a statutory tariff, or null.
	 *
	 * @param object|null $new The new version.
	 * @param object|null $old The stored version.
	 *
	 * @return string|null
	 */
	private function updateRefusal(?object $new, ?object $old): ?string {
		if ($old === null || $this->isTariff(entity: $new) === false) {
			return null;
		}

		$before = (new PreSaveWrite())->payload(entity: $old);
		if (($before['statutory'] ?? false) !== true) {
			return null;
		}

		return sprintf(self::MESSAGE_CHANGE, (string)($before['code'] ?? ''));
	}//end updateRefusal()

	/**
	 * Whether the entity is a VatTariff.
	 *
	 * @param object|null $entity The entity.
	 *
	 * @return bool
	 */
	private function isTariff(?object $entity): bool {
		return $entity !== null && $this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: self::SCHEMA_SLUG) === true;
	}//end isTariff()
}//end class
