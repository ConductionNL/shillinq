<?php

/**
 * Shillinq SBR filing status listener
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectTransitionRunnerInterface;
use OCA\Shillinq\Service\Sbr\SbrRecords;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Follows integriq's `nl.conduction.sbr.filing.status` reports on a filing.
 *
 * Integriq saves each CloudEvent as an OpenRegister object (register
 * `integriq`, schema `event`), so this hears ObjectCreatedEvent and matches
 * the type itself, as PeppolDeliveryStatusListener does. The data carries
 * objectUri, deliveryReference, status and errors:
 *
 * - `delivered`: the Digipoort reference is recorded and `deliver` moves the
 *   instance from handed-off to submitted;
 * - `accepted` / `rejected`: the recipient's outcome, `accept` or `reject`,
 *   with the errors in `rejectionReason` (delivered first when that report
 *   never came);
 * - `failed`: integriq could not deliver; `retract` returns the instance to
 *   validated with the reason, so it can be filed again.
 *
 * A VAT return handed off through the instance follows it: submitted (with
 * the reference) on delivery, verified on acceptance, back to draft with the
 * errors in its notes on rejection or a failed delivery. Every transition is declared on the
 * schema and run as the system, its guards still applying. Fail-soft: a
 * report that does not fit is logged and skipped.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/tax-digipoort-filing/specs/bookkeeping-sbr-xbrl-reporting/spec.md#requirement-submission-hands-the-instance-to-integriq-and-follows-its-reports-req-tdf-004
 */
class SbrFilingStatusListener implements IEventListener {

	/**
	 * The CloudEvent type integriq's Digipoort connector reports with.
	 */
	public const CLOUDEVENT_TYPE = 'nl.conduction.sbr.filing.status';

	/**
	 * Constructor.
	 *
	 * @param SbrRecords                      $records        The filings and returns.
	 * @param ObjectTransitionRunnerInterface $transitions    Runs their declared transitions.
	 * @param ListenerSchemaResolverInterface $schemaResolver Tells integriq's event records apart.
	 * @param LoggerInterface                 $logger         Logs what is skipped.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-3.3
	 */
	public function __construct(
		private readonly SbrRecords $records,
		private readonly ObjectTransitionRunnerInterface $transitions,
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle the creation of integriq's filing-status CloudEvent object.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-3.3
	 *
	 * @listener-placement inline cheap-bounded — every object that is not an
	 *   integriq filing-status CloudEvent returns on an in-memory type check
	 *   before any read. For the rare one that is, the work is one filing read
	 *   and save, at most three of its transitions, and the same for its VAT
	 *   return.
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatedEvent === false) {
			return;
		}

		try {
			$entity     = $event->getObject();
			$cloudEvent = $entity?->getObject();
			if (is_array($cloudEvent) === false || ($cloudEvent['type'] ?? null) !== self::CLOUDEVENT_TYPE) {
				return;
			}

			if ($this->schemaResolver->matchesRegisterAndSchema(
				entity: $entity,
				registerSlug: IntegriqCloudEventListener::REGISTER_SLUG,
				schemaSlug: IntegriqCloudEventListener::SCHEMA_SLUG
			) === false
			) {
				return;
			}

			$this->apply(payload: (array) ($cloudEvent['data'] ?? []));
		} catch (Throwable $e) {
			$this->logger->warning(
				'SbrFilingStatusListener: failed to apply a filing status — fail-soft',
				['exception' => $e->getMessage()]
			);
		}//end try
	}//end handle()

	/**
	 * Apply one filing status to its instance and return.
	 *
	 * @param array<string,mixed> $payload {objectUri, deliveryReference, status, errors}.
	 *
	 * @return string|null The instance's state afterwards, null when nothing was applied.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-3.3
	 */
	public function apply(array $payload): ?string {
		$objectUri = trim((string) ($payload['objectUri'] ?? ''));
		$status    = strtolower(trim((string) ($payload['status'] ?? '')));
		$report    = [
			'reference' => trim((string) ($payload['deliveryReference'] ?? '')),
			'errors'    => self::errors(errors: ($payload['errors'] ?? [])),
		];

		$instance = $this->instance(objectUri: $objectUri);
		if ($instance === null) {
			$this->logger->info('SbrFilingStatusListener: no filing for the report — skipping', ['objectUri' => $objectUri]);
			return null;
		}

		$state = match ($status) {
			'delivered' => $this->delivered(instance: $instance, report: ['outcome' => null] + $report),
			'accepted', 'rejected' => $this->delivered(instance: $instance, report: ['outcome' => $status] + $report),
			'failed' => $this->failed(instance: $instance, errors: $report['errors']),
			default => null,
		};
		if ($state === null) {
			$this->logger->info(
				'SbrFilingStatusListener: a report that does not fit the filing — skipping',
				['objectUri' => $objectUri, 'status' => $status, 'state' => (string) ($instance['state'] ?? '')]
			);
			return null;
		}

		$this->mirrorOnReturn(instance: $instance, report: ['state' => $state] + $report);
		return $state;
	}//end apply()

	/**
	 * Record the delivery, and the outcome when there is one.
	 *
	 * @param array<string,mixed>                                          $instance The XbrlInstance.
	 * @param array{reference: string, errors: string, outcome: string|null} $report   The delivery reference, the
	 *                                                                               recipient's errors, and
	 *                                                                               `accepted`, `rejected` or null
	 *                                                                               for the delivery alone.
	 *
	 * @return string|null The state reached, null when the report does not fit.
	 */
	private function delivered(array $instance, array $report): ?string {
		$state   = (string) ($instance['state'] ?? '');
		$outcome = $report['outcome'];
		if (self::alreadyThere(state: $state, outcome: $outcome) === true) {
			return $state;
		}

		if (self::deliverable(state: $state, reference: $report['reference']) === false) {
			return null;
		}

		$this->records->save(schema: 'XbrlInstance', record: array_merge($instance, self::deliveryChanges(state: $state, report: $report)));
		if ($state === 'handed-off') {
			$this->transitions->runAsSystem(objectId: (string) $instance['id'], action: 'deliver');
		}

		if ($outcome === null) {
			return 'submitted';
		}

		$this->transitions->runAsSystem(objectId: (string) $instance['id'], action: ['accepted' => 'accept', 'rejected' => 'reject'][$outcome]);
		return $outcome;
	}//end delivered()

	/**
	 * Whether the instance already stands where the report would bring it: the
	 * outcome reached, or a delivery alone reported for a submitted filing.
	 *
	 * @param string      $state   The instance's state.
	 * @param string|null $outcome `accepted`, `rejected` or null for the delivery alone.
	 *
	 * @return bool True when nothing is left to record.
	 */
	private static function alreadyThere(string $state, ?string $outcome): bool {
		return ($outcome !== null && $state === $outcome) || ($state === 'submitted' && $outcome === null);
	}//end alreadyThere()

	/**
	 * Whether a delivery can be recorded: on a submitted filing, or on a handed-off one with its reference.
	 *
	 * @param string $state     The instance's state.
	 * @param string $reference The Digipoort delivery reference.
	 *
	 * @return bool True when it can.
	 */
	private static function deliverable(string $state, string $reference): bool {
		return $state === 'submitted' || ($state === 'handed-off' && $reference !== '');
	}//end deliverable()

	/**
	 * The fields a delivery and its outcome set on the instance.
	 *
	 * @param string                                                         $state  The instance's state.
	 * @param array{reference: string, errors: string, outcome: string|null} $report The report.
	 *
	 * @return array<string,string> The changes.
	 */
	private static function deliveryChanges(string $state, array $report): array {
		$changes = [];
		if ($state === 'handed-off') {
			$changes['digipoortReceiptId'] = $report['reference'];
			$changes['submittedAt']        = gmdate('Y-m-d\TH:i:s\Z');
		}

		if ($report['outcome'] === 'accepted') {
			$changes['acceptedAt'] = gmdate('Y-m-d\TH:i:s\Z');
		}

		if ($report['outcome'] === 'rejected') {
			$changes['rejectionReason'] = $report['errors'];
		}

		return $changes;
	}//end deliveryChanges()

	/**
	 * Return a filing integriq could not deliver to validated, with the reason.
	 *
	 * @param array<string,mixed> $instance The XbrlInstance.
	 * @param string              $errors   Why the delivery failed.
	 *
	 * @return string|null `validated`, null when the filing is not handed off.
	 */
	private function failed(array $instance, string $errors): ?string {
		if (($instance['state'] ?? '') !== 'handed-off') {
			return null;
		}

		$this->records->save(schema: 'XbrlInstance', record: array_merge($instance, ['rejectionReason' => $errors]));
		$this->transitions->runAsSystem(objectId: (string) $instance['id'], action: 'retract');
		return 'validated';
	}//end failed()

	/**
	 * Let the VAT return the instance files follow it.
	 *
	 * A return handed off with its instance is delivered with it (the
	 * reference in filingReference), verified when the instance is accepted,
	 * reopened to draft with the errors in its notes when it is rejected, and
	 * retracted to draft when integriq could not deliver. A return in draft
	 * (its instance filed from the SBR filings page) is left alone.
	 *
	 * @param array<string,mixed>                                  $instance The XbrlInstance.
	 * @param array{state: string, reference: string, errors: string} $report   The instance's state now, the
	 *                                                                        Digipoort delivery reference and
	 *                                                                        the recipient's errors.
	 *
	 * @return void
	 */
	private function mirrorOnReturn(array $instance, array $report): void {
		$vatReturn = $this->records->find(schema: 'BtwAangifte', id: (string) ($instance['sourceReturnId'] ?? ''));
		if ($vatReturn === null) {
			return;
		}

		if ($report['reference'] === '') {
			$report['reference'] = (string) ($instance['digipoortReceiptId'] ?? '');
		}

		$status = (string) ($vatReturn['statusCode'] ?? 'draft');
		if ($status === 'handed-off') {
			$this->returnFromHandedOff(vatReturn: $vatReturn, report: $report);
			return;
		}

		if ($status === 'submitted') {
			$this->returnFromSubmitted(vatReturn: $vatReturn, report: $report);
		}
	}//end mirrorOnReturn()

	/**
	 * A handed-off return: retracted when its instance was not delivered, else
	 * delivered with the reference and then settled like a submitted one.
	 *
	 * @param array<string,mixed>                                  $vatReturn The BtwAangifte.
	 * @param array{state: string, reference: string, errors: string} $report    The report.
	 *
	 * @return void
	 */
	private function returnFromHandedOff(array $vatReturn, array $report): void {
		$id = (string) $vatReturn['id'];
		if ($report['state'] === 'validated') {
			$vatReturn['notes'] = self::note(notes: (string) ($vatReturn['notes'] ?? ''), line: 'Not delivered to Digipoort: ' . $report['errors']);
			$this->records->save(schema: 'BtwAangifte', record: $vatReturn);
			$this->transitions->runAsSystem(objectId: $id, action: 'retract');
			return;
		}

		$vatReturn['filingReference'] = $report['reference'];
		$vatReturn['submissionDate']  = gmdate('Y-m-d');
		if ($report['state'] === 'rejected') {
			$vatReturn['notes'] = self::note(notes: (string) ($vatReturn['notes'] ?? ''), line: 'Rejected by the Belastingdienst: ' . $report['errors']);
		}

		$this->records->save(schema: 'BtwAangifte', record: $vatReturn);
		$this->transitions->runAsSystem(objectId: $id, action: 'deliver');
		$this->settleReturn(id: $id, state: $report['state']);
	}//end returnFromHandedOff()

	/**
	 * A submitted return: the rejection noted, then settled.
	 *
	 * @param array<string,mixed>                                  $vatReturn The BtwAangifte.
	 * @param array{state: string, reference: string, errors: string} $report    The report.
	 *
	 * @return void
	 */
	private function returnFromSubmitted(array $vatReturn, array $report): void {
		if ($report['state'] === 'rejected') {
			$vatReturn['notes'] = self::note(notes: (string) ($vatReturn['notes'] ?? ''), line: 'Rejected by the Belastingdienst: ' . $report['errors']);
			$this->records->save(schema: 'BtwAangifte', record: $vatReturn);
		}

		$this->settleReturn(id: (string) $vatReturn['id'], state: $report['state']);
	}//end returnFromSubmitted()

	/**
	 * Verify a submitted return whose instance was accepted, reopen one whose instance was rejected.
	 *
	 * @param string $id    The return's id.
	 * @param string $state The instance's state now.
	 *
	 * @return void
	 */
	private function settleReturn(string $id, string $state): void {
		$action = (['accepted' => 'verify', 'rejected' => 'rebase'][$state] ?? null);
		if ($action !== null) {
			$this->transitions->runAsSystem(objectId: $id, action: $action);
		}
	}//end settleReturn()

	/**
	 * The notes with one line added.
	 *
	 * @param string $notes The notes.
	 * @param string $line  The line.
	 *
	 * @return string The notes.
	 */
	private static function note(string $notes, string $line): string {
		return trim($notes . "\n" . $line);
	}//end note()

	/**
	 * The XbrlInstance an `openregister://<register>/XbrlInstance/<id>` URI points at.
	 *
	 * @param string $objectUri The URI.
	 *
	 * @return array<string,mixed>|null The instance.
	 */
	private function instance(string $objectUri): ?array {
		$parts = explode('/', rtrim($objectUri, '/'));
		$id    = trim((string) array_pop($parts));
		if ($id === '' || array_pop($parts) !== 'XbrlInstance') {
			return null;
		}

		return $this->records->find(schema: 'XbrlInstance', id: $id);
	}//end instance()

	/**
	 * The recipient's errors as one line: `code: text` each, separated by semicolons.
	 *
	 * @param mixed $errors A list of {code, message} or strings, or a string.
	 *
	 * @return string The errors.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-3.3
	 */
	public static function errors(mixed $errors): string {
		if (is_string($errors) === true) {
			return trim($errors);
		}

		$lines = [];
		foreach ((array) $errors as $error) {
			if (is_array($error) === true) {
				$code    = trim((string) ($error['code'] ?? ''));
				$message = trim((string) ($error['message'] ?? ($error['text'] ?? '')));
				$error   = trim($code . ': ' . $message, ': ');
			}

			if (is_string($error) === true && trim($error) !== '') {
				$lines[] = trim($error);
			}
		}

		return implode('; ', $lines);
	}//end errors()
}//end class
