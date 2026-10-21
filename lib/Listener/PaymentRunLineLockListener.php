<?php

/**
 * Payment Run Line Lock Listener
 *
 * Once a payment run leaves `draft` its lines and amounts are what the
 * approver approved and what the bank file pays. FourEyesPaymentRunGuard holds
 * at approval only, so without this listener an approved run could be edited
 * through the object API (a creditor IBAN, an amount) and exported as edited.
 * Any change to a locked field of a run that is not a draft is refused.
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
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Refuses changes to an approved, exported or reconciled run's lines and amounts.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.1
 */
class PaymentRunLineLockListener implements IEventListener {

	/**
	 * Fields that are fixed once the run is approved.
	 *
	 * @var array<int, string>
	 */
	public const LOCKED_FIELDS = ['paymentLines', 'totalAmount', 'currency', 'debtorAccountIban', 'executionDate', 'runNumber'];

	/**
	 * Refusal shown to the caller.
	 *
	 * @var string
	 */
	public const MESSAGE = 'An approved payment run cannot be changed. Its lines and amounts are what was approved and what the bank file pays.';

	/**
	 * Construct the listener.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Matches PaymentRun in shillinq's register.
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
	) {
	}//end __construct()

	/**
	 * Refuse a change to a locked field of a run past `draft`.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.1
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$old = $event->getOldObject();
		$new = $event->getNewObject();
		if ($old === null || $new === null) {
			return;
		}

		$before = $old->getObject();
		$state  = (string)($before['lifecycleState'] ?? ($before['status'] ?? 'draft'));
		if ($state === 'draft' || $state === '') {
			return;
		}

		if ($this->schemaResolver->matchesSchema(entity: $new, expectedSlug: 'PaymentRun') === false) {
			return;
		}

		$after = $new->getObject();
		foreach (self::LOCKED_FIELDS as $field) {
			if (self::normalise(value: ($before[$field] ?? null)) !== self::normalise(value: ($after[$field] ?? null))) {
				$event->setErrors(['message' => self::MESSAGE, 'field' => $field]);
				$event->stopPropagation();
				return;
			}
		}
	}//end handle()

	/**
	 * A value with every number as a float, so 100 and 100.0 compare equal.
	 *
	 * @param mixed $value A stored or incoming field value.
	 *
	 * @return mixed
	 */
	private static function normalise(mixed $value): mixed {
		if (is_int($value) === true || is_float($value) === true) {
			return (float)$value;
		}

		if (is_array($value) === true) {
			return array_map(static fn (mixed $item): mixed => self::normalise(value: $item), $value);
		}

		return $value;
	}//end normalise()
}//end class
