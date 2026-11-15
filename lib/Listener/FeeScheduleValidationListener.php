<?php

/**
 * FeeScheduleValidationListener — enforce the fee-schedule rules on the write
 * path, where fee schedules are actually written.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * FeeScheduleService::assertNoOverlap() has existed, with tests, since the
 * leges work landed, and nothing ever called it. Gate 6 (orphan-auth) named
 * it: a validator with a full test suite and no call site reports exactly the
 * same green as one that runs, and enforces exactly as much as no validator at
 * all. A council could publish two fees covering the same day for the same
 * type and channel, and the resolver would return whichever came back first.
 *
 * There is no controller in this app that writes a FeeSchedule. They are
 * written straight into OpenRegister, so the only place the rule can be
 * enforced is OpenRegister's pre-save veto. `ObjectCreatingEvent` and
 * `ObjectUpdatingEvent` are stoppable, and a stopped event becomes HTTP 422
 * with this message, the same mechanism OrderFulfilmentEvidenceListener uses.
 *
 * The check is the SAME method the unit tests exercise, called rather than
 * copied, so the two can never drift.
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use InvalidArgumentException;
use OCA\Shillinq\Kernel\Contracts\FeeScheduleServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Write-path enforcement of the fee-schedule overlap and legal-basis rules.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-006)
 */
class FeeScheduleValidationListener implements IEventListener {

	/**
	 * The schema this listener is scoped to.
	 *
	 * @var string
	 */
	private const SCHEMA_SLUG = 'FeeSchedule';

	/**
	 * Constructor.
	 *
	 * @param FeeScheduleServiceInterface $feeSchedules The rules, called rather than copied.
	 * @param ListenerSchemaResolverInterface $schemaResolver Resolves the entity's schema to its slug.
	 * @param LoggerInterface $logger Logger for refusals.
	 */
	public function __construct(
		private readonly FeeScheduleServiceInterface $feeSchedules,
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Veto a fee schedule that overlaps a published one, names no council
	 * decision, or carries no default amount.
	 *
	 * @param Event $event The OpenRegister pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-006)
	 */
	public function handle(Event $event): void {
		$write = new PreSaveWrite();
		$entity = $write->incomingEntity(event: $event);
		if ($entity === null || $this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: self::SCHEMA_SLUG) === false) {
			return;
		}

		$reason = $this->ruleViolation(schedule: $write->payload(entity: $entity));
		if ($reason === null) {
			return;
		}

		$this->refuse(event: $event, reason: $reason);

	}//end handle()

	/**
	 * Log the refusal and veto the write with the broken rule.
	 *
	 * @param Event  $event  The OpenRegister pre-save event.
	 * @param string $reason The broken rule.
	 *
	 * @return void
	 */
	private function refuse(Event $event, string $reason): void {
		$this->logger->warning(
			'Shillinq: refused a FeeSchedule write that breaks a fee-schedule rule',
			['reason' => $reason]
		);

		$event->setErrors(
			[
				'message' => $reason,
				'requirement' => 'REQ-SOPR-006',
			]
		);
		$event->stopPropagation();

	}//end refuse()

	/**
	 * The fee-schedule rule the schedule breaks, or null when it keeps them all.
	 *
	 * @param array<string, mixed> $schedule The schedule being written.
	 *
	 * @return string|null
	 */
	private function ruleViolation(array $schedule): string|null {
		try {
			$this->feeSchedules->assertNoOverlap(schedule: $schedule);
			return null;
		} catch (InvalidArgumentException $e) {
			return $e->getMessage();
		}

	}//end ruleViolation()
}//end class
