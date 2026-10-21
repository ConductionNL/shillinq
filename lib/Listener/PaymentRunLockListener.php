<?php

/**
 * Payment Run Lock Listener
 *
 * Pre-save veto on PaymentRun writes. A run is created as a draft, leaves
 * draft only by approval, and once approved its payees, amounts, accounts and
 * dates cannot change. On approval it stamps the digest of the approved lines
 * and the approver, which the export checks again before it writes a bank file.
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
 * @spec openspec/specs/payment-run-four-eyes/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\PaymentRun\PaymentRunApprovalLock;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Refuses PaymentRun writes that would change an approved run.
 *
 * @spec openspec/specs/payment-run-four-eyes/spec.md
 *
 * @template-implements IEventListener<Event>
 */
class PaymentRunLockListener implements IEventListener {

	private const SCHEMA_SLUG = 'PaymentRun';

	public const MESSAGE_LOCKED = 'This payment run is approved, so its payees, amounts, accounts and dates can no longer change.';

	public const MESSAGE_NOT_DRAFT_ON_CREATE = 'A new payment run starts as a draft and is approved separately.';

	public const MESSAGE_SKIPS_APPROVAL = 'A payment run leaves draft only by being approved.';

	public const MESSAGE_BACK_TO_DRAFT = 'An approved payment run cannot return to draft.';

	/**
	 * Construct the listener.
	 *
	 * @param PaymentRunApprovalLock $lock           The lock rules.
	 * @param ListenerSchemaResolver $schemaResolver Matches the written schema.
	 * @param IUserSession           $userSession    The acting user.
	 * @param LoggerInterface        $logger         Logger.
	 */
	public function __construct(
		private readonly PaymentRunApprovalLock $lock,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Veto or stamp a PaymentRun write.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payment-run-four-eyes/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === true) {
			$this->handleCreate(event: $event);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->handleUpdate(event: $event);
		}

	}//end handle()

	/**
	 * A run is created as a draft.
	 *
	 * @param ObjectCreatingEvent $event The pre-save event.
	 *
	 * @return void
	 */
	private function handleCreate(ObjectCreatingEvent $event): void {
		$entity = $event->getObject();
		if ($this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: self::SCHEMA_SLUG) === false) {
			return;
		}

		$state = $this->lock->state(run: $this->data(entity: $entity));
		if ($state === '' || $state === PaymentRunApprovalLock::DRAFT) {
			return;
		}

		$this->refuse(event: $event, message: self::MESSAGE_NOT_DRAFT_ON_CREATE, fields: ['lifecycleState']);

	}//end handleCreate()

	/**
	 * Freeze an approved run, and stamp the approval digest when a draft is approved.
	 *
	 * @param ObjectUpdatingEvent $event The pre-save event.
	 *
	 * @return void
	 */
	private function handleUpdate(ObjectUpdatingEvent $event): void {
		$entity = $event->getNewObject();
		if ($this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: self::SCHEMA_SLUG) === false) {
			return;
		}

		$new      = $this->data(entity: $entity);
		$old      = $this->data(entity: $event->getOldObject());
		$oldState = $this->lock->state(run: $old);
		$newState = $this->lock->state(run: $new);

		if ($oldState === '' || $oldState === PaymentRunApprovalLock::DRAFT) {
			if ($newState === PaymentRunApprovalLock::APPROVED) {
				$event->setModifiedData(
					[
						'approvedLinesDigest' => $this->lock->digest(run: $new),
						'approvedBy' => ($this->userSession->getUser()?->getUID() ?? ''),
						'approvedAt' => gmdate('Y-m-d\TH:i:s\Z'),
					]
				);
				return;
			}

			if ($newState !== '' && $newState !== PaymentRunApprovalLock::DRAFT) {
				$this->refuse(event: $event, message: self::MESSAGE_SKIPS_APPROVAL, fields: ['lifecycleState']);
			}

			return;
		}//end if

		if ($newState === PaymentRunApprovalLock::DRAFT) {
			$this->refuse(event: $event, message: self::MESSAGE_BACK_TO_DRAFT, fields: ['lifecycleState']);
			return;
		}

		$changed = $this->lock->changedLockedFields(old: $old, new: $new);
		if ($changed === []) {
			return;
		}

		$this->refuse(event: $event, message: self::MESSAGE_LOCKED, fields: $changed);

	}//end handleUpdate()

	/**
	 * Stop the write with a reason.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event   The pre-save event.
	 * @param string                                  $message The reason.
	 * @param list<string>                            $fields  The fields the refusal is about.
	 *
	 * @return void
	 */
	private function refuse(ObjectCreatingEvent|ObjectUpdatingEvent $event, string $message, array $fields): void {
		$this->logger->warning(
			'Shillinq: refused a PaymentRun write',
			['reason' => $message, 'fields' => $fields]
		);

		$event->setErrors(
			[
				'message' => $message,
				'field' => ($fields[0] ?? null),
				'fields' => $fields,
			]
		);
		$event->stopPropagation();

	}//end refuse()

	/**
	 * Return the payload of an object entity.
	 *
	 * @param object|null $entity The entity.
	 *
	 * @return array<string,mixed> The payload; empty when there is none.
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
