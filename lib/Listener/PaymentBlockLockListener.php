<?php

/**
 * Payment Block Lock Listener
 *
 * Pre-save veto on APTransaction and Payee updates: the payment block fields
 * change only through PaymentBlockService, which checks the payment action,
 * the administration, the reason and four-eyes. A write that leaves them out
 * keeps the stored values. A record may still be created with a block on,
 * which is the safe direction.
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
 * @spec openspec/specs/payment-control-guards/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\PaymentRun\PaymentBlockService;
use OCA\Shillinq\PaymentRun\PaymentBlockWriteScope;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Refuses a payment block change that does not come from the block endpoint.
 *
 * @spec openspec/specs/payment-control-guards/spec.md
 *
 * @template-implements IEventListener<Event>
 */
class PaymentBlockLockListener implements IEventListener {

	public const MESSAGE = 'A payment block is set or released through Block payment or Release payment, with a reason.';

	/**
	 * Construct the listener.
	 *
	 * @param PaymentBlockWriteScope $scope          Open while the block service writes.
	 * @param ListenerSchemaResolver $schemaResolver Matches the written schema.
	 * @param LoggerInterface        $logger         Logger.
	 */
	public function __construct(
		private readonly PaymentBlockWriteScope $scope,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Veto a block change made outside the block service.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payment-control-guards/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent === false || $this->scope->isOpen() === true) {
			return;
		}

		$entity = $event->getNewObject();
		if ($this->isBlockable(entity: $entity) === false) {
			return;
		}

		$new     = $this->data(entity: $entity);
		$old     = $this->data(entity: $event->getOldObject());
		$changed = [];
		$kept    = [];
		foreach (PaymentBlockService::FIELDS as $field) {
			if (array_key_exists($field, $new) === false) {
				if (array_key_exists($field, $old) === true) {
					$kept[$field] = $old[$field];
				}

				continue;
			}

			if ($this->normalise(value: ($old[$field] ?? null)) !== $this->normalise(value: $new[$field])) {
				$changed[] = $field;
			}
		}

		if ($changed === []) {
			if ($kept !== []) {
				$event->setModifiedData(array_merge($event->getModifiedData(), $kept));
			}

			return;
		}

		$this->logger->warning('Shillinq: refused a payment block change outside the block endpoint', ['fields' => $changed]);
		$event->setErrors(['message' => self::MESSAGE, 'field' => $changed[0], 'fields' => $changed]);
		$event->stopPropagation();

	}//end handle()

	/**
	 * Whether the entity is an APTransaction or a Payee.
	 *
	 * @param object|null $entity The entity.
	 *
	 * @return bool
	 */
	private function isBlockable(?object $entity): bool {
		foreach (PaymentBlockService::SCHEMAS as $slug) {
			if ($this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: $slug) === true) {
				return true;
			}
		}

		return false;

	}//end isBlockable()

	/**
	 * Treat a missing flag as false and an empty string as null.
	 *
	 * @param mixed $value The stored or written value.
	 *
	 * @return mixed
	 */
	private function normalise(mixed $value): mixed {
		if ($value === '' || $value === false) {
			return null;
		}

		return $value;

	}//end normalise()

	/**
	 * Return the payload of an object entity.
	 *
	 * @param object|null $entity The entity.
	 *
	 * @return array<string,mixed>
	 */
	private function data(?object $entity): array {
		if ($entity === null || method_exists($entity, 'getObject') === false) {
			return [];
		}

		$data = $entity->getObject();
		if (is_array($data) === false) {
			return [];
		}

		return $data;

	}//end data()
}//end class
