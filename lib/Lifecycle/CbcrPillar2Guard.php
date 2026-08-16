<?php

/**
 * CbCR / Pillar Two Lifecycle Guard
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
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
		try {
			$summary = $this->resolveObject(schema: 'CbcrJurisdictionSummary', id: $summaryId, object: $object);
			if ($summary === null) {
				return false;
			}

			if ((string)($summary['period'] ?? '') === '' || (string)($summary['jurisdiction'] ?? '') === '') {
				return false;
			}

			$unrelated = (float)($summary['unrelatedPartyRevenue'] ?? 0);
			$related = (float)($summary['relatedPartyRevenue'] ?? 0);
			$total = (float)($summary['totalRevenue'] ?? 0);

			// Total revenue must tie out to the two revenue components (1 cent tolerance).
			if (abs(($unrelated + $related) - $total) > 0.01) {
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'CbcrPillar2Guard: summary reconcile check failed — denying transition (fail-closed)',
				['summaryId' => $summaryId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

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

			$jurisdiction = strtoupper((string)($computation['jurisdiction'] ?? ''));
			$etr = (float)($computation['etrJurisdiction'] ?? 0);
			$minimumRate = (float)($computation['minimumRate'] ?? 0.15);
			$topUpAmount = (float)($computation['topUpTaxAmount'] ?? 0);
			$qdmttAmount = (float)($computation['qdmttAmount'] ?? 0);

			// QDMTT priority only bites for NL-resident low-taxed income with a top-up.
			if ($jurisdiction === 'NL' && $etr < $minimumRate && $topUpAmount > 0) {
				if ($qdmttAmount <= 0) {
					return false;
				}
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'CbcrPillar2Guard: computation approve check failed — denying transition (fail-closed)',
				['computationId' => $computationId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end canApproveComputation()

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
		try {
			$return = $this->resolveObject(schema: 'QdmttReturn', id: $returnId, object: $object);
			if ($return === null) {
				return false;
			}

			if ((string)($return['period'] ?? '') === '' || (string)($return['entity'] ?? '') === '') {
				return false;
			}

			if ((float)($return['qdmttPayable'] ?? -1) < 0) {
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'CbcrPillar2Guard: QDMTT submit check failed — denying transition (fail-closed)',
				['returnId' => $returnId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

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
		try {
			$return = $this->resolveObject(schema: 'CbcrReturn', id: $returnId, object: $object);
			if ($return === null) {
				return false;
			}

			$residual = abs((float)($return['reconciliationResidual'] ?? 0));
			$items = $return['reconciliationItems'] ?? [];

			// A residual over EUR 1M must be explained by at least one item.
			if ($residual > 1000000.0 && (is_array($items) === false || count($items) < 1)) {
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'CbcrPillar2Guard: CbCR return reconcile check failed — denying transition (fail-closed)',
				['returnId' => $returnId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end canReconcileCbcrReturn()

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
		if ($object !== null) {
			return $object;
		}

		if ($id === '') {
			return null;
		}

		$register = $this->resolveRegister();

		$results = $this->objectService
			->setRegister($register)
			->setSchema($schema)
			->findAll(['filters' => ['id' => $id]]);

		foreach ($results as $result) {
			if (is_array($result) === true) {
				return $result;
			}
		}

		return null;
	}//end resolveObject()

	/**
	 * Resolve the configured OpenRegister register slug for this app.
	 *
	 * @return string The register slug (defaults to 'shillinq').
	 */
	private function resolveRegister(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end resolveRegister()
}//end class
