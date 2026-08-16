<?php

/**
 * Reconciliation Match → Reconciliation Reports Listener.
 *
 * T4 bookkeeping-reconciliation-reports — REQ-REC-010. Listens for
 * T2 `bookkeeping-bank-reconciliation` events (T2 emits an OR
 * `ObjectTransitionedEvent` on every confirm of a `ReconciliationMatch`
 * per REQ-BR-006) and stamps the T4-side fields on the same match
 * record so the T4 BankReconciliation session sees the outcome:
 *
 *   - reconId          (looked up from the BankStatementLine's parent
 *                       statement, then mapped to the open
 *                       BankReconciliation for that bank account + period)
 *   - matchAlgorithm   ("exact" when T2's confidence=="auto",
 *                       "manual" when T2's confidence=="manual")
 *   - confidenceScoreT4 (copied from T2's confidenceScore)
 *   - matchedAt        (UTC now)
 *   - glTransactionId / arInvoiceId / apTransactionId (derived from
 *                       T2's matchType + matchedObjectId)
 *
 * REQ-REC-010 contract: "T4 MUST create a ReconciliationMatch record ...
 * within 1 second of the event". This listener updates the existing
 * T2 record in-place (deep-merged schema fragment per ADR-037) rather
 * than creating a parallel record — there is one `ReconciliationMatch`
 * register, with T2 owning matching semantics and T4 owning session +
 * resolution semantics.
 *
 * Fail-soft: a stamping failure logs but never bubbles back into the
 * T2 confirm write path.
 *
 * **T4-vs-T2 deduplication note** (Task 19, openspec change
 * `bookkeeping-reconciliation-reports`): the matching DECISION is owned
 * by T2 — its MatchingRule register, the `x-openregister-aggregations`
 * candidate emission on `ReconciliationMatch`, and the operator-confirm
 * lifecycle (REQ-BR-005..010). T4 is the OUTCOME RECORDER, not a
 * matcher: this listener does not compute matches, only stamps the
 * T4-side session linkage (`reconId`) and the resolution-workflow
 * fields (`resolutionStatus`, `resolutionReason`, `matchAlgorithm`,
 * `matchedAt`, polymorphic FK shortcuts) on the SAME register record.
 * There is intentionally no parallel match table and no `T4MatchingService`.
 * See `openspec/changes/bookkeeping-reconciliation-reports/design.md` §D2.
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
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stamp T4 fields (reconId, matchAlgorithm, matchedAt, etc.) on a
 * ReconciliationMatch when T2 transitions it to `confirmed`.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md (REQ-REC-010)
 */
final class ReconciliationMatchToReportListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config for register slug.
	 * @param LoggerInterface $logger Logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object
	 *                                              surface (ADR-084), aliased in
	 *                                              Application.php.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Handle the T2 transition event and stamp T4 fields when the
	 * subject is a ReconciliationMatch confirm.
	 *
	 * Fail-soft: any error logs and returns; never bubbles back into the
	 * T2 confirm write path per REQ-REC-010 + ADR-022 "events are
	 * advisory, never blocking".
	 *
	 * @param Event $event The dispatched OR event.
	 *
	 * @return void
	 */
	public function handle(Event $event): void {
		try {
			$payload = $this->extractEventPayload(event: $event);
			if ($payload === null) {
				return;
			}

			if ($this->shouldStamp(payload: $payload) === false) {
				return;
			}

			$this->stampT4Fields(match: $payload['object']);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ReconciliationMatchToReportListener: stamping failed (fail-soft)',
				['exception' => $e->getMessage()]
			);
		}

	}//end handle()

	/**
	 * Extract the schema + object payload from an OR event. OR events
	 * expose `getObject()` (post-write snapshot) and the schema slug via
	 * either `getSchema()` or the snapshot's `@self.schema`.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array{schema:string,object:array<string,mixed>}|null
	 */
	private function extractEventPayload(Event $event): ?array {
		if (method_exists($event, 'getObject') === false) {
			return null;
		}

		$object = $event->getObject();
		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				$object = $serialized;
			}
		}

		if (is_array($object) === false) {
			return null;
		}

		$schema = '';
		if (method_exists($event, 'getSchema') === true) {
			$rawSchema = $event->getSchema();
			if (is_string($rawSchema) === true) {
				$schema = $rawSchema;
			} elseif (is_object($rawSchema) === true && method_exists($rawSchema, 'getSlug') === true) {
				$schema = (string)$rawSchema->getSlug();
			}
		}

		if ($schema === '' && isset($object['@self']['schema']) === true) {
			$schema = (string)$object['@self']['schema'];
		}

		return ['schema' => $schema, 'object' => $object];
	}//end extractEventPayload()

	/**
	 * Decide whether this event should trigger T4-field stamping.
	 *
	 * @param array{schema:string,object:array<string,mixed>} $payload Extracted event payload.
	 *
	 * @return bool
	 */
	private function shouldStamp(array $payload): bool {
		if (strcasecmp($payload['schema'], 'ReconciliationMatch') !== 0) {
			return false;
		}

		$status = (string)($payload['object']['status'] ?? '');
		if ($status !== 'confirmed') {
			// Only confirmed matches drive T4 ReconciliationMatch records.
			return false;
		}

		// Idempotency: don't re-stamp records that already carry the T4
		// matchAlgorithm field.
		$algo = (string)($payload['object']['matchAlgorithm'] ?? '');
		if ($algo !== '') {
			return false;
		}

		return true;
	}//end shouldStamp()

	/**
	 * Update the ReconciliationMatch in-place with T4 fields.
	 *
	 * @param array<string,mixed> $match The T2-side match snapshot.
	 *
	 * @return void
	 */
	private function stampT4Fields(array $match): void {
		$matchId = (string)($match['id'] ?? $match['matchId'] ?? '');
		if ($matchId === '') {
			return;
		}

		$reconId = $this->resolveReconId(match: $match);
		if ($reconId === '') {
			// No open BankReconciliation session for this account/period yet —
			// leave reconId empty and let the operator wire it up later. The
			// ReconciliationMatch is still a valid T2 record.
			$this->logger->info(
				'ReconciliationMatchToReportListener: no open BankReconciliation session — match stamped without reconId',
				['matchId' => $matchId]
			);
		}

		$updates = [
			'matchAlgorithm' => $this->algorithmFromConfidence(confidence: (string)($match['confidence'] ?? 'auto')),
			'matchedAt' => gmdate('Y-m-d\TH:i:s\Z'),
			'manualOverride' => ((string)($match['confidence'] ?? '') === 'manual'),
			'confidenceScoreT4' => (float)($match['confidenceScore'] ?? 1.0),
		];

		if ($reconId !== '') {
			$updates['reconId'] = $reconId;
		}

		$matchType = (string)($match['matchType'] ?? '');
		$matchedObject = (string)($match['matchedObjectId'] ?? '');
		if ($matchedObject !== '') {
			switch ($matchType) {
				case 'ar-invoice':
					$updates['arInvoiceId'] = $matchedObject;
					break;
				case 'ap-invoice':
					$updates['apTransactionId'] = $matchedObject;
					break;
				case 'gl-transaction':
				case 'journal':
					$updates['glTransactionId'] = $matchedObject;
					break;
				default:
					// Suspense / unknown — leave as polymorphic matchedObjectId only.
					break;
			}
		}

		$bankLineId = (string)($match['bankStatementLineId'] ?? '');
		if ($bankLineId !== '') {
			$updates['bankLineId'] = $bankLineId;
		}

		$this->objectService
			->setRegister($this->getRegisterSlug())
			->setSchema('ReconciliationMatch')
			->updateObject($matchId, $updates);

		$this->logger->info(
			'ReconciliationMatchToReportListener: T4 fields stamped',
			['matchId' => $matchId, 'reconId' => $reconId, 'algo' => $updates['matchAlgorithm']]
		);

	}//end stampT4Fields()

	/**
	 * Convert the T2 `confidence` enum (auto/manual) into the T4
	 * `matchAlgorithm` enum (exact/manual). REQ-REC-005: T4 supports
	 * `exact` + `manual` only — `fuzzy` is reserved for T5.
	 *
	 * @param string $confidence The T2 confidence value.
	 *
	 * @return string The T4 matchAlgorithm value.
	 */
	private function algorithmFromConfidence(string $confidence): string {
		if ($confidence === 'manual') {
			return 'manual';
		}

		return 'exact';
	}//end algorithmFromConfidence()

	/**
	 * Resolve the open BankReconciliation session that owns this match.
	 * Walks BankStatementLine → BankStatement to find (bankAccountIban,
	 * periodEnd) and looks up an in-progress reconciliation with the same
	 * (bankAccountId, statementPeriodEnd).
	 *
	 * @param array<string,mixed> $match The T2 match.
	 *
	 * @return string The recon id, or '' when no session is open.
	 */
	private function resolveReconId(array $match): string {
		try {
			$lineId = (string)($match['bankStatementLineId'] ?? '');
			if ($lineId === '') {
				return '';
			}

			$line = $this->objectService
				->setRegister($this->getRegisterSlug())
				->setSchema('BankStatementLine')
				->find($lineId);
			$line = $this->toArray(result: $line);
			if ($line === null) {
				return '';
			}

			$statementId = (string)($line['statementId'] ?? '');
			if ($statementId === '') {
				return '';
			}

			$statement = $this->objectService
				->setRegister($this->getRegisterSlug())
				->setSchema('BankStatement')
				->find($statementId);
			$statement = $this->toArray(result: $statement);
			if ($statement === null) {
				return '';
			}

			$iban = (string)($statement['bankAccountIban'] ?? '');
			$periodEnd = (string)($statement['periodEnd'] ?? '');
			if ($iban === '' || $periodEnd === '') {
				return '';
			}

			$sessions = $this->objectService
				->setRegister($this->getRegisterSlug())
				->setSchema('BankReconciliation')
				->findAll(
					[
						'filters' => [
							'bankAccountId' => $iban,
							'statementPeriodEnd' => $periodEnd,
							'reconciliationStatus' => 'in-progress',
						],
						'limit' => 1,
					]
				);
			if ($sessions === [] || isset($sessions[0]) === false) {
				return '';
			}

			return (string)($sessions[0]['id'] ?? '');
		} catch (Throwable $e) {
			$this->logger->info(
				'ReconciliationMatchToReportListener: reconId lookup failed (continuing without)',
				['exception' => $e->getMessage()]
			);
			return '';
		}//end try

	}//end resolveReconId()

	/**
	 * Normalise an OR find result to a plain array.
	 *
	 * @param mixed $result OR return value.
	 *
	 * @return array<string,mixed>|null
	 */
	private function toArray(mixed $result): ?array {
		if (is_array($result) === true) {
			return $result;
		}

		if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
			$serialized = $result->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}

			return null;
		}

		return null;
	}//end toArray()

	/**
	 * Return the configured register slug, falling back to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()
}//end class
