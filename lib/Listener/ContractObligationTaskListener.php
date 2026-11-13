<?php

/**
 * Contract Obligation Task Listener.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Supplies the missing trigger for {@see \OCA\Shillinq\Service\ObligationTaskBridge::createTaskForObligation()}.
 *
 * REQ-CDC-005 ({@see openspec/specs/compliance-deadline-calendar/spec.md})
 * requires that "the existing VTODO task creation MUST continue to work" and
 * that the bridge publishes its VTODO + deadline VEVENT "when the obligation
 * is created/updated". The bridge itself shipped and is unit-tested, but no
 * create/update hook was ever wired: `ContractObligation` carries no
 * `x-openregister-lifecycle` block, there was no listener, and no
 * ContractObligationService/Controller exists. So every ContractObligation
 * created through OpenRegister's generic CRUD surface left `taskUri` and
 * `taskLinkStatus` permanently null and no task was ever raised for a
 * contract deadline.
 *
 * The trigger is the create/update event OR's MagicMapper dispatches on the
 * generic save path — the same seam {@see LeaseActivationListener} uses for
 * LeaseContract, and for the same reason (OR has no lifecycle-action executor
 * for this schema).
 *
 * IDEMPOTENCY IS LOAD-BEARING HERE, TWICE OVER. The listener writes the bridge
 * result back onto the obligation, and that write itself dispatches an
 * ObjectUpdatedEvent — so without a guard this listener would re-enter
 * indefinitely and mint a fresh VTODO on every pass. Skipping obligations that
 * already carry `taskLinkStatus = linked` closes both the loop and the
 * duplicate-task hole: after the write-back the re-entrant event returns
 * early. A `failed` status is deliberately NOT treated as terminal, so a
 * later edit can retry a link that failed because no CalDAV backend was
 * reachable at creation time.
 *
 * Fail-soft: the obligation is already persisted by the time this fires, and
 * the bridge itself never throws (it degrades fail-closed and reports
 * `taskLinkStatus = failed`). A backend outage must not bubble into the
 * operator's CRUD request.
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
 * @spec openspec/specs/compliance-deadline-calendar/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Shillinq\Kernel\Contracts\ObligationTaskBridgeInterface;
use OCA\Shillinq\Service\RegisterLocation;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Raises the NC Tasks VTODO + deadline VEVENT for a ContractObligation
 * (REQ-CDC-005).
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/compliance-deadline-calendar/spec.md
 */
class ContractObligationTaskListener implements IEventListener {

	/**
	 * The schema this listener reacts to.
	 *
	 * @var string
	 */
	private const SCHEMA = 'ContractObligation';

	/**
	 * Link status that means the VTODO already exists — re-entry is a no-op.
	 *
	 * @var string
	 */
	private const STATUS_LINKED = 'linked';

	/**
	 * Constructor.
	 *
	 * @param RegisterLocation $registerLocation Where the register lives (slug + OpenRegister availability).
	 * @param ObligationTaskBridgeInterface $bridge NC Tasks / Deck glue.
	 * @param ListenerSchemaResolverInterface $schemaResolver Resolves the entity's schema id to its slug.
	 * @param LoggerInterface $logger Logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object
	 *                                              surface (ADR-084), aliased in
	 *                                              Application.php.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegisterLocation $registerLocation,
		private readonly ObligationTaskBridgeInterface $bridge,
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Handle an OR ObjectCreatedEvent / ObjectUpdatedEvent on ContractObligation.
	 *
	 * @param Event $event OR object lifecycle event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false
			&& ($event instanceof ObjectUpdatedEvent) === false
		) {
			return;
		}

		try {
			$obligation = $this->unlinkedObligation(event: $event);
			if ($obligation !== null) {
				$this->linkTask(obligation: $obligation);
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'ContractObligationTaskListener: task creation failed (fail-soft)',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end handle()

	/**
	 * The event's ContractObligation, id-stamped, when it has no linked task yet.
	 *
	 * @param Event $event OR object lifecycle event.
	 *
	 * @return array<string,mixed>|null The obligation, or null when it needs no task.
	 */
	private function unlinkedObligation(Event $event): array|null {
		$entity = $this->resolveEntity(event: $event);
		if ($entity === null || $this->isObligationSchema(schema: $this->schemaResolver->schemaSlug(entity: $entity)) === false) {
			return null;
		}

		$obligation = $entity->getObject();
		// Re-entry guard — see the class docblock. This is what stops the
		// write-back below from re-triggering this listener forever.
		if (is_array($obligation) === false || (string)($obligation['taskLinkStatus'] ?? '') === self::STATUS_LINKED) {
			return null;
		}

		$objectId = (string)($obligation['id'] ?? ($entity->getId() ?? ''));
		if ($objectId === '') {
			return null;
		}

		$obligation['id'] = $objectId;
		return $obligation;

	}//end unlinkedObligation()

	/**
	 * Create the obligation's task, write the link back and log the attempt.
	 *
	 * @param array<string,mixed> $obligation The id-stamped obligation.
	 *
	 * @return void
	 */
	private function linkTask(array $obligation): void {
		$result = $this->bridge->createTaskForObligation(obligation: $obligation);

		$this->persist(id: $obligation['id'], result: $result);

		$this->logger->info(
			'ContractObligationTaskListener: obligation task link attempted',
			[
				'obligationId' => $obligation['id'],
				'taskLinkStatus' => ($result['taskLinkStatus'] ?? 'unknown'),
			]
		);

	}//end linkTask()

	/**
	 * Pull the object entity off either supported event shape.
	 *
	 * @param Event $event OR event.
	 *
	 * @return object|null
	 */
	private function resolveEntity(Event $event): ?object {
		if (method_exists($event, 'getObject') === false) {
			return null;
		}

		$entity = $event->getObject();
		if (is_object($entity) === false) {
			return null;
		}

		return $entity;
	}//end resolveEntity()

	/**
	 * Check whether the schema slug is ContractObligation.
	 *
	 * @param string $schema Schema slug from the event.
	 *
	 * @return bool
	 */
	private function isObligationSchema(string $schema): bool {
		$normalised = strtolower(trim($schema));

		return ($normalised === 'contractobligation'
			|| str_ends_with(haystack: $normalised, needle: 'contractobligation'));

	}//end isObligationSchema()

	/**
	 * Persist the bridge's link result back onto the obligation.
	 *
	 * Only the two fields the ContractObligation schema declares are written;
	 * the bridge's eventUri / eventLinkStatus keys belong to the calendar
	 * surface, which ComplianceDeadlineCalendarService owns.
	 *
	 * @param string $id The obligation object id.
	 * @param array<string,mixed> $result The bridge result.
	 *
	 * @return void
	 */
	private function persist(string $id, array $result): void {
		$updates = ['taskLinkStatus' => (string)($result['taskLinkStatus'] ?? 'failed')];
		if (($result['taskUri'] ?? null) !== null) {
			$updates['taskUri'] = (string)$result['taskUri'];
		}

		$this->objectService
			->setRegister($this->registerLocation->getRegisterSlug())
			->setSchema(self::SCHEMA)
			->updateObject($id, $updates);

	}//end persist()
}//end class
