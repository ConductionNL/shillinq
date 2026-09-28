<?php

/**
 * Takes integriq's bank feed pulls into shillinq.
 *
 * Integriq saves each CloudEvent as an object in its `integriq` register,
 * `event` schema, so the only thing shillinq can hear is OpenRegister's
 * ObjectCreatedEvent for that object (see IntegriqCloudEventListener). This
 * listener acts on type `nl.conduction.bankfeed.transactions.synced` and hands
 * the event's data to BankfeedIntakeService. Fail-soft: a failure is logged and
 * never bubbles into integriq's write.
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
 * @spec openspec/changes/banking-connected-accounts/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Shillinq\Service\Bank\BankfeedIntakeService;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `nl.conduction.bankfeed.transactions.synced` to BankfeedIntakeService.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/banking-connected-accounts/tasks.md#task-2.2
 */
class BankfeedSyncedListener implements IEventListener {
	/**
	 * The CloudEvent type integriq's BankfeedSyncService emits.
	 *
	 * @var string
	 */
	public const TYPE_SYNCED = 'nl.conduction.bankfeed.transactions.synced';

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolver $schemaResolver Resolves register and schema ids to slugs.
	 * @param BankfeedIntakeService  $intake         Writes the batch as a statement.
	 * @param LoggerInterface        $logger         Logger.
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly BankfeedIntakeService $intake,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle an object creation; act only on integriq's synced CloudEvents.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/banking-connected-accounts/tasks.md#task-2.2
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatedEvent === false) {
			return;
		}

		try {
			$entity = $event->getObject();
			$cloudEvent = $entity?->getObject();
			if (is_array($cloudEvent) === false || ($cloudEvent['type'] ?? null) !== self::TYPE_SYNCED) {
				return;
			}

			if ($this->schemaResolver->matchesRegisterAndSchema(entity: $entity, registerSlug: 'integriq', schemaSlug: 'event') === false) {
				return;
			}

			$data = $cloudEvent['data'] ?? null;
			if (is_array($data) === false) {
				return;
			}

			$outcome = $this->intake->ingestSynced(data: $data);
			$this->logger->info('BankfeedSyncedListener: bank feed batch taken in', $outcome);
		} catch (Throwable $e) {
			$this->logger->warning('BankfeedSyncedListener: bank feed batch skipped', ['exception' => $e->getMessage()]);
		}//end try

	}//end handle()
}//end class
