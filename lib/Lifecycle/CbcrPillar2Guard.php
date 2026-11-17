<?php

/**
 * CbCR / Pillar Two Lifecycle Guard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ADR-031 exception-path lifecycle guards for the CbCR / Pillar 2 (GloBE)
 * registers (bookkeeping-cbcr-pillar2, T3). The bulk of the CbCR / Pillar 2
 * measurement model is declarative (schema metadata + x-openregister-lifecycle +
 * x-openregister-calculations / -aggregations: ETR, SBIE carve-out, top-up tax,
 * the 7-field CbCR roll-up). A small set of preconditions require cross-field or
 * cross-schema completeness checks that OpenRegister's declarative `requires:`
 * clause cannot yet express; those are referenced from the schema lifecycle
 * transitions and implemented here:
 *
 *  - canReconcileSummary():     a CbcrJurisdictionSummary may only be marked
 *                               reconciled once its mandatory CbCR fields are
 *                               present and totalRevenue ties out (REQ-CBC-002).
 *  - canApproveComputation():   a Pillar2JurisdictionComputation may only be
 *                               approved once QDMTT priority is honoured for
 *                               NL-resident low-taxed income — an NL jurisdiction
 *                               with ETR < 15% and a positive top-up must carry a
 *                               positive QDMTT allocation before any IIR is taken
 *                               (REQ-CBC-006).
 *  - canSubmitQdmtt():          a QdmttReturn may only be submitted once it
 *                               carries an NL-resident entity, a period, and a
 *                               non-negative QDMTT payable (REQ-CBC-006).
 *  - canReconcileCbcrReturn():  a CbcrReturn may only be marked reconciled once a
 *                               residual difference greater than EUR 1M is
 *                               explained by reconciliation items (REQ-CBC-010).
 *
 * ADR-031 exception reason: cross-field / cross-schema completeness checks are
 * not yet expressible in the declarative lifecycle DSL. When the engine gains
 * those capabilities, replace these references with declarative conditions and
 * delete this file. ADR-022: object reads use the real OpenRegister ObjectService
 * API (setRegister/setSchema/findAll) only.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-cbcr-pillar2/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\Service\Cbcr\CbcrCheckRunner;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle precondition guards for the CbCR / Pillar 2 registers.
 *
 * Referenced from the register.d fragment schema lifecycle transitions
 * (CbcrJurisdictionSummary, Pillar2JurisdictionComputation, QdmttReturn,
 * CbcrReturn) as OCA\Shillinq\Lifecycle\CbcrPillar2Guard::<method>. Every guard
 * fails closed: any exception or malformed input denies the transition (CWE-863).
 *
 * @spec openspec/specs/bookkeeping-cbcr-pillar2/spec.md
 */
class CbcrPillar2Guard {
	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Returns true iff the CbcrJurisdictionSummary may be marked reconciled.
	 *
	 * REQ-CBC-002: a period and jurisdiction must be present and totalRevenue must
	 * tie out to unrelated + related party revenue before the summary leaves draft.
	 *
	 * @param string $summaryId The CbcrJurisdictionSummary id (call-signature parity).
	 * @param array<string,mixed>|null $object The summary being transitioned.
	 *
	 * @return bool True when the summary may be reconciled.
	 *
	 * @spec openspec/specs/bookkeeping-cbcr-pillar2/spec.md
	 */
	public function canReconcileSummary(string $summaryId, ?array $object = null): bool {
		return $this->failClosed(
			check: fn (): bool => self::summaryReconciles(
				summary: $this->resolveObject(schema: 'CbcrJurisdictionSummary', id: $summaryId, object: $object)
			),
			message: 'CbcrPillar2Guard: summary reconcile check failed — denying transition (fail-closed)',
			context: ['summaryId' => $summaryId]
		);
	}//end canReconcileSummary()

	/**
	 * Returns true iff the Pillar2JurisdictionComputation may be approved.
	 *
	 * REQ-CBC-006: QDMTT priority over IIR. An NL-resident jurisdiction with an
	 * ETR below the 15% minimum and a positive top-up tax must carry a positive
	 * QDMTT allocation before any IIR may be taken — the Dutch QDMTT is levied
	 * first. Non-NL jurisdictions and jurisdictions with no top-up tax pass.
	 *
	 * @param string $computationId The Pillar2JurisdictionComputation id (call-signature parity).
	 * @param array<string,mixed>|null $object The computation being transitioned.
	 *
	 * @return bool True when the computation may be approved.
	 *
	 * @spec openspec/specs/bookkeeping-cbcr-pillar2/spec.md
	 */
	public function canApproveComputation(string $computationId, ?array $object = null): bool {
		try {
			$computation = $this->resolveObject(schema: 'Pillar2JurisdictionComputation', id: $computationId, object: $object);
			if ($computation === null) {
				return false;
			}

			return self::qdmttPriorityMet(computation: $computation);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CbcrPillar2Guard: computation approve check failed — denying transition (fail-closed)',
				['computationId' => $computationId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end canApproveComputation()

	/**
	 * QDMTT priority only bites for NL-resident low-taxed income with a top-up: then a QDMTT amount is required.
	 *
	 * @param array<string, mixed> $computation The Pillar2JurisdictionComputation.
	 *
	 * @return bool
	 */
	private static function qdmttPriorityMet(array $computation): bool {
		$jurisdiction = strtoupper((string)($computation['jurisdiction'] ?? ''));
		$etr          = (float)($computation['etrJurisdiction'] ?? 0);
		$minimumRate  = (float)($computation['minimumRate'] ?? 0.15);
		$topUpAmount  = (float)($computation['topUpTaxAmount'] ?? 0);
		$qdmttAmount  = (float)($computation['qdmttAmount'] ?? 0);

		if ($jurisdiction === 'NL' && $etr < $minimumRate && $topUpAmount > 0) {
			if ($qdmttAmount <= 0) {
				return false;
			}
		}

		return true;
	}//end qdmttPriorityMet()

	/**
	 * Returns true iff the QdmttReturn may be submitted.
	 *
	 * REQ-CBC-006: a QDMTT return must reference an NL-resident entity, a period,
	 * and a non-negative QDMTT payable before submission.
	 *
	 * @param string $returnId The QdmttReturn id (call-signature parity).
	 * @param array<string,mixed>|null $object The return being transitioned.
	 *
	 * @return bool True when the return may be submitted.
	 *
	 * @spec openspec/specs/bookkeeping-cbcr-pillar2/spec.md
	 */
	public function canSubmitQdmtt(string $returnId, ?array $object = null): bool {
		return $this->failClosed(
			check: fn (): bool => self::qdmttSubmittable(return: $this->resolveObject(schema: 'QdmttReturn', id: $returnId, object: $object)),
			message: 'CbcrPillar2Guard: QDMTT submit check failed — denying transition (fail-closed)',
			context: ['returnId' => $returnId]
		);
	}//end canSubmitQdmtt()

	/**
	 * Returns true iff the CbcrReturn may be marked reconciled.
	 *
	 * REQ-CBC-010: a residual difference greater than EUR 1M between the CbCR
	 * totals and the consolidated financial statements must be explained by at
	 * least one reconciliation item before the return is marked reconciled.
	 *
	 * @param string $returnId The CbcrReturn id (call-signature parity).
	 * @param array<string,mixed>|null $object The return being transitioned.
	 *
	 * @return bool True when the return may be reconciled.
	 *
	 * @spec openspec/specs/bookkeeping-cbcr-pillar2/spec.md
	 */
	public function canReconcileCbcrReturn(string $returnId, ?array $object = null): bool {
		return $this->failClosed(
			check: fn (): bool => self::cbcrReturnReconciles(return: $this->resolveObject(schema: 'CbcrReturn', id: $returnId, object: $object)),
			message: 'CbcrPillar2Guard: CbCR return reconcile check failed — denying transition (fail-closed)',
			context: ['returnId' => $returnId]
		);
	}//end canReconcileCbcrReturn()

	/**
	 * Returns true iff the CbcrReturn may be marked submitted.
	 *
	 * REQ-CBC-012: only a return with a stored, schema-valid file made from
	 * the records as they are now, and no failing mandatory check, may be
	 * submitted. A file whose fingerprint differs from the current records is
	 * stale and denies, as does any error (fail-closed).
	 *
	 * @param string                   $returnId The CbcrReturn id.
	 * @param array<string,mixed>|null $object   The return being transitioned.
	 *
	 * @return bool True when the return may be submitted.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-5.2
	 */
	public function canSubmitCbcrReturn(string $returnId, ?array $object = null): bool {
		return $this->failClosed(
			check: fn (): bool => $this->fileIsCurrent(
				return: $this->resolveObject(schema: 'CbcrReturn', id: $returnId, object: $object),
				kind: 'cbc'
			),
			message: 'CbcrPillar2Guard: CbCR return submit check failed — denying transition (fail-closed)',
			context: ['returnId' => $returnId]
		);
	}//end canSubmitCbcrReturn()

	/**
	 * Returns true iff the GlobeInformationReturn may be approved.
	 *
	 * REQ-CBC-012: only a return with a stored, schema-valid file made from
	 * the safe harbour records and computations as they are now, no missing
	 * element and no failing mandatory check, may be approved (fail-closed).
	 *
	 * @param string                   $returnId The GlobeInformationReturn id.
	 * @param array<string,mixed>|null $object   The return being transitioned.
	 *
	 * @return bool True when the return may be approved.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-5.2
	 */
	public function canApproveGir(string $returnId, ?array $object = null): bool {
		return $this->failClosed(
			check: fn (): bool => $this->fileIsCurrent(
				return: $this->resolveObject(schema: 'GlobeInformationReturn', id: $returnId, object: $object),
				kind: 'gir'
			),
			message: 'CbcrPillar2Guard: GIR approve check failed — denying transition (fail-closed)',
			context: ['returnId' => $returnId]
		);
	}//end canApproveGir()

	/**
	 * Run a check, denying (false) and logging when it throws: every guard fails closed.
	 *
	 * @param callable(): bool    $check   The check.
	 * @param string              $message The error to log when it throws.
	 * @param array<string,mixed> $context The log context; the exception message is added.
	 *
	 * @return bool
	 */
	private function failClosed(callable $check, string $message, array $context): bool {
		try {
			return $check();
		} catch (\Throwable $e) {
			$this->logger->error($message, $context + ['exception' => $e->getMessage()]);
			return false;
		}
	}//end failClosed()

	/**
	 * REQ-CBC-002: a summary with a period and jurisdiction whose total revenue ties out
	 * to unrelated + related party revenue (1 cent tolerance).
	 *
	 * @param array<string,mixed>|null $summary The summary, or null when not found.
	 *
	 * @return bool
	 */
	private static function summaryReconciles(?array $summary): bool {
		if ($summary === null || (string)($summary['period'] ?? '') === '' || (string)($summary['jurisdiction'] ?? '') === '') {
			return false;
		}

		$unrelated = (float)($summary['unrelatedPartyRevenue'] ?? 0);
		$related   = (float)($summary['relatedPartyRevenue'] ?? 0);
		$total     = (float)($summary['totalRevenue'] ?? 0);

		return (abs(($unrelated + $related) - $total) > 0.01) === false;
	}//end summaryReconciles()

	/**
	 * REQ-CBC-006: a QDMTT return with a period, an entity and a non-negative QDMTT payable.
	 *
	 * @param array<string,mixed>|null $return The return, or null when not found.
	 *
	 * @return bool
	 */
	private static function qdmttSubmittable(?array $return): bool {
		if ($return === null || (string)($return['period'] ?? '') === '' || (string)($return['entity'] ?? '') === '') {
			return false;
		}

		return ((float)($return['qdmttPayable'] ?? -1) < 0) === false;
	}//end qdmttSubmittable()

	/**
	 * REQ-CBC-010: a residual over EUR 1M must be explained by at least one reconciliation item.
	 *
	 * @param array<string,mixed>|null $return The CbcrReturn, or null when not found.
	 *
	 * @return bool
	 */
	private static function cbcrReturnReconciles(?array $return): bool {
		if ($return === null) {
			return false;
		}

		$residual = abs((float)($return['reconciliationResidual'] ?? 0));
		$items    = $return['reconciliationItems'] ?? [];

		return ($residual > 1000000.0 && (is_array($items) === false || count($items) < 1)) === false;
	}//end cbcrReturnReconciles()

	/**
	 * REQ-CBC-012: a return with a stored file that was valid and made from the records as they
	 * are now, and no failing mandatory check.
	 *
	 * @param array<string,mixed>|null $return The CbcrReturn (kind cbc) or GlobeInformationReturn (kind gir).
	 * @param string                   $kind   cbc or gir.
	 *
	 * @return bool
	 */
	private function fileIsCurrent(?array $return, string $kind): bool {
		$field = ['cbc' => 'cbcrXmlSubmission', 'gir' => 'globeXmlSubmission'][$kind];
		if ($return === null || trim((string)($return[$field] ?? '')) === '') {
			return false;
		}

		$runner = new CbcrCheckRunner(objectService: $this->objectService, appConfig: $this->appConfig);
		if ($kind === 'cbc') {
			return self::fileMatches(validation: $return['lastValidation'] ?? null, fingerprint: $runner->cbcFingerprint(return: $return))
				&& CbcrCheckRunner::blockingFailures(results: $runner->runCbc(return: $return)) === [];
		}

		return self::fileMatches(validation: $return['lastValidation'] ?? null, fingerprint: $runner->girFingerprint(return: $return))
			&& CbcrCheckRunner::blockingFailures(results: $runner->runGir(return: $return)) === [];
	}//end fileIsCurrent()

	/**
	 * Whether the last validation says the file was valid and made from these records.
	 *
	 * @param mixed  $validation  The return's lastValidation.
	 * @param string $fingerprint The fingerprint of the records now.
	 *
	 * @return bool
	 */
	private static function fileMatches(mixed $validation, string $fingerprint): bool {
		return is_array($validation) === true
			&& ($validation['valid'] ?? null) === true
			&& is_string($validation['fingerprint'] ?? null) === true
			&& hash_equals($validation['fingerprint'], $fingerprint) === true;
	}//end fileMatches()

	/**
	 * Resolve a register object, preferring the in-flight object payload and
	 * falling back to an ObjectService lookup by id (ADR-022 real API).
	 *
	 * @param string $schema The schema slug.
	 * @param string $id The object id.
	 * @param array<string,mixed>|null $object The in-flight object payload, if any.
	 *
	 * @return array<string,mixed>|null The resolved object, or null if not found.
	 */
	private function resolveObject(string $schema, string $id, ?array $object): ?array {
		$lookup = new GuardObjectLookup(appConfig: $this->appConfig, objectService: $this->objectService);

		return $lookup->resolve(subject: new GuardSubject(schema: $schema, id: $id), object: $object);
	}//end resolveObject()
}//end class
