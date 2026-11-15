<?php

/**
 * Period closed drift listener.
 *
 * When a fiscal period is closed, the filed VAT returns of its fiscal year are
 * checked against the books, so a late booking into a filed quarter shows on
 * the corrections page without anyone running a check (REQ-VPS-003).
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
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Shillinq\Service\Tax\FiledReturnDriftCheck;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Checks the fiscal year's filed returns for drift once a period is closed.
 *
 * Only the change into `closed` counts: saving an already closed period again,
 * or locking it for audit, checks nothing. A failed check never fails the
 * close, which is already saved.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.3
 */
class PeriodClosedDriftListener implements IEventListener {

	/**
	 * The schema a fiscal period is stored in.
	 *
	 * @var string
	 */
	public const PERIOD_SCHEMA = 'FiscalPeriod';

	/**
	 * Constructor.
	 *
	 * @param FiledReturnDriftCheck           $driftCheck     Checks a fiscal year's filed returns.
	 * @param ListenerSchemaResolverInterface $schemaResolver Resolves an entity's schema slug.
	 * @param LoggerInterface                 $logger         Logger.
	 */
	public function __construct(
		private readonly FiledReturnDriftCheck $driftCheck,
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an updated object.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.3
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		try {
			$entity = $event->getNewObject();
			if ($this->schemaResolver->schemaSlug(entity: $entity) !== self::PERIOD_SCHEMA) {
				return;
			}

			$period = ($entity->getObject() ?? []);
			$old = ($event->getOldObject()?->getObject() ?? []);
			if ((string)($period['state'] ?? '') !== 'closed' || (string)($old['state'] ?? '') === 'closed') {
				return;
			}

			$this->driftCheck->checkFiscalYear(
				administrationId: (string)($period['administrationId'] ?? ''),
				fiscalYear: $this->fiscalYear(period: $period)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'PeriodClosedDriftListener: the filed returns were not checked for drift',
				['exception' => $e->getMessage()]
			);
		}//end try
	}//end handle()

	/**
	 * The period's fiscal year: its own field, else the year of its start date.
	 *
	 * @param array<string,mixed> $period The fiscal period.
	 *
	 * @return int The year, 0 when neither is set.
	 */
	private function fiscalYear(array $period): int {
		$year = (int)($period['fiscalYear'] ?? 0);
		if ($year > 0) {
			return $year;
		}

		$start = (string)($period['startDate'] ?? '');
		if (preg_match('/^(\d{4})-/', $start, $match) === 1) {
			return (int)$match[1];
		}

		return 0;
	}//end fiscalYear()
}//end class
