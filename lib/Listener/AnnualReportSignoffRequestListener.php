<?php

/**
 * AnnualReport Sign-off Request Listener.
 *
 * Change shillinq-delegation-via-events (REQ-SIGN-005) — the REAL production
 * trigger for {@see \OCA\Shillinq\Service\Signing\SignoffDecisionService::requestSignoff}.
 * Without this listener the decidesk Decision dispatch was an orphaned
 * capability: fully implemented and unit-tested, but never invoked from any
 * production code path.
 *
 * Reacts to {@see \OCA\OpenRegister\Event\ObjectTransitionedEvent} on the
 * AnnualReport schema (bookkeeping-titel-9-jaarrekening) transitioning into
 * `opgemaakt` (bestuur-signed) — the single state both the `vaststellen`
 * (after accountant review) and `vaststellenZonderReview` (klein/micro,
 * skips review) transitions share before the algemene vergadering (AV) can
 * vote. That AV vote is modelled as a decidesk Decision
 * (`decisionType = 'adoption'`); this listener raises it as soon as the
 * report becomes `opgemaakt`, so `decisionOutcome` is `pending` by the time
 * an operator attempts `vaststellen(ZonderReview)`.
 *
 * Idempotent: only requests once per adoption cycle — skipped when the
 * object already carries a non-empty `decisionOutcome` (already requested,
 * approved, or rejected). A `reviewAnnuleren` → re-`opgemaakt` cycle therefore
 * does not raise a duplicate decidesk Decision.
 *
 * Fail-soft at the listener boundary: the `opgemaakt` transition has already
 * committed by the time this fires, so a failure here cannot roll it back.
 * `SignoffDecisionService::requestSignoff()` itself still fails CLOSED
 * (throws, never auto-approves) when decidesk is absent or does not handle
 * the request; this listener catches that Throwable, logs it, and leaves
 * `decisionOutcome` unset so the gap is visible and the request can be
 * retried, instead of ever marking the report approved.
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
 * @spec openspec/changes/shillinq-delegation-via-events/specs/shillinq-delegate-signing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\Signing\SignoffDecisionService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Raise the decidesk adoption Decision the moment an AnnualReport becomes
 * `opgemaakt` (bestuur-signed), so the AV vote is already in flight before
 * `vaststellen`/`vaststellenZonderReview` is attempted.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/shillinq-delegation-via-events/specs/shillinq-delegate-signing/spec.md (REQ-SIGN-005)
 */
final class AnnualReportSignoffRequestListener implements IEventListener {

	/**
	 * The finance schema this listener requests a sign-off decision for.
	 *
	 * @var string
	 */
	private const TARGET_SCHEMA = 'AnnualReport';

	/**
	 * The lifecycle state that raises the adoption decision request.
	 *
	 * @var string
	 */
	private const TARGET_STATE = 'opgemaakt';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Shillinq settings (register slug).
	 * @param SignoffDecisionService $signoffService The sign-off request service.
	 * @param LoggerInterface $logger Logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object
	 *                                              surface (ADR-084), aliased in
	 *                                              Application.php.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly SignoffDecisionService $signoffService,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Handle an OpenRegister ObjectTransitionedEvent.
	 *
	 * Fail-soft: any error logs and returns; the sign-off request can be
	 * retried, and `decisionOutcome` is never set to `approved` on a failure
	 * path (that guarantee lives in
	 * {@see SignoffDecisionService::requestSignoff()} itself).
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/shillinq-delegation-via-events/specs/shillinq-delegate-signing/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false) {
			return;
		}

		try {
			if ($event->getTo() !== self::TARGET_STATE) {
				return;
			}

			if ($this->isAnnualReportSchema(schema: $event->getSchema()) === false) {
				return;
			}

			$entity = $event->getObject();
			$object = $entity->getObject();
			if (is_array($object) === false) {
				return;
			}

			$id = (string)($object['id'] ?? $entity->getUuid() ?? $entity->getId() ?? '');
			if ($id === '') {
				$this->logger->info('AnnualReportSignoffRequestListener: no object id on opgemaakt transition (skipping)');
				return;
			}

			// Idempotent: only request once per adoption cycle.
			$currentOutcome = (string)($object['decisionOutcome'] ?? '');
			if ($currentOutcome !== '') {
				return;
			}

			$object['id'] = $id;

			$updated = $this->signoffService->requestSignoff(
				financeObject: $object,
				subjectSchema: self::TARGET_SCHEMA,
				decisionType: 'adoption',
			);

			$this->persist(
				id: $id,
				updates: [
					'decisionRef' => $updated['decisionRef'] ?? null,
					'decisionOutcome' => $updated['decisionOutcome'] ?? 'pending',
				]
			);

			$this->logger->info(
				'AnnualReportSignoffRequestListener: adoption decision requested',
				[
					'id' => $id,
					'decisionRef' => $updated['decisionRef'] ?? null,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'AnnualReportSignoffRequestListener: adoption request failed (fail-soft; never auto-approves)',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end handle()

	/**
	 * Check whether the schema slug is AnnualReport.
	 *
	 * @param string $schema Schema slug.
	 *
	 * @return bool
	 */
	private function isAnnualReportSchema(string $schema): bool {
		$normalised = strtolower(trim($schema));
		return ($normalised === strtolower(self::TARGET_SCHEMA)
			|| str_ends_with(haystack: $normalised, needle: strtolower(self::TARGET_SCHEMA)));

	}//end isAnnualReportSchema()

	/**
	 * Persist the decisionRef/decisionOutcome mirror onto the AnnualReport via OR.
	 *
	 * @param string $id The AnnualReport id.
	 * @param array<string,mixed> $updates The fields to write.
	 *
	 * @return void
	 */
	private function persist(string $id, array $updates): void {
		$this->objectService
			->setRegister($this->settingsService->getRegisterSlug())
			->setSchema(self::TARGET_SCHEMA)
			->updateObject($id, $updates);

	}//end persist()
}//end class
