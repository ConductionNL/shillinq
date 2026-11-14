<?php

/**
 * Shillinq JurisdictionLockListener.
 *
 * Refuses a change of an administration's jurisdiction once it has a posted
 * transaction: the existing postings were booked under the old jurisdiction's
 * rules, and changing it would put them under other rules.
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Service\Jurisdiction\AdministrationJurisdiction;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Keeps an administration's jurisdiction fixed once it has postings.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
 */
class JurisdictionLockListener implements IEventListener {

	/**
	 * The message a refused change carries.
	 */
	public const DENY_MESSAGE = 'The jurisdiction cannot change: this administration has posted transactions.';

	/**
	 * Constructor.
	 *
	 * @param AdministrationJurisdiction $jurisdiction   The jurisdiction and postings lookup.
	 * @param ListenerSchemaResolver     $schemaResolver Matches the event's object to the Administration schema.
	 * @param LoggerInterface            $logger         Logger.
	 *
	 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
	 */
	public function __construct(
		private readonly AdministrationJurisdiction $jurisdiction,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse a jurisdiction change of an administration with postings.
	 *
	 * A lookup that fails refuses the change too: the postings cannot be ruled out.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$new = $event->getNewObject();
		$old = $event->getOldObject();
		if ($old === null || $this->schemaResolver->matchesSchema(entity: $new, expectedSlug: 'Administration') === false) {
			return;
		}

		$before = AdministrationJurisdiction::normalise(value: (($old->getObject() ?? [])['jurisdiction'] ?? null));
		$after  = AdministrationJurisdiction::normalise(value: (($new->getObject() ?? [])['jurisdiction'] ?? null));
		if ($before === $after) {
			return;
		}

		// Transactions carry the administrationCode, older ones the uuid; either counts.
		$ids = array_values(
			array_unique(
				array_filter(
					[trim((string)(($old->getObject() ?? [])['administrationCode'] ?? '')), trim((string)($old->getUuid() ?? ''))],
					static fn (string $id): bool => $id !== ''
				)
			)
		);
		$administrationId = implode(',', $ids);
		try {
			$locked = false;
			foreach ($ids as $id) {
				if ($this->jurisdiction->hasPostings(administrationId: $id) === true) {
					$locked = true;
					break;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->error(
				'Shillinq: could not check postings before a jurisdiction change; refusing it',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			$locked = true;
		}

		if ($locked === false) {
			return;
		}

		$this->logger->info(
			'Shillinq: refused a jurisdiction change of an administration with postings',
			['administrationId' => $administrationId, 'from' => $before, 'to' => $after]
		);
		$event->setErrors(['message' => self::DENY_MESSAGE, 'field' => 'jurisdiction']);
		$event->stopPropagation();
	}//end handle()
}//end class
