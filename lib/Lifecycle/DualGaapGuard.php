<?php

/**
 * Dual GAAP Lifecycle Guard
 *
 * ADR-031 exception-path lifecycle guards for the dual GAAP reporting registers
 * (bookkeeping-ifrs-rj-dual-gaap, T3). The bulk of the dual-GAAP measurement
 * model is declarative (schema metadata + x-openregister-lifecycle +
 * x-openregister-aggregations). A small set of preconditions require cross-field
 * completeness checks that OpenRegister's declarative `requires:` clause cannot
 * yet express; those are referenced from the schema lifecycle transitions and
 * implemented here:
 *
 *  - canReconcileTransaction(): a DualTransaction may only be reconciled once it
 *                               carries a divergence reason code AND, for a
 *                               temporary difference, a non-zero deferred-tax
 *                               effect (REQ-DGAAP-003 / REQ-DGAAP-006).
 *  - canActivateElection():     a FrameworkElection may only activate once its
 *                               comply-or-explain motivation and AVA-besluit
 *                               reference are present and the elected variant is
 *                               consistent with the measured size criteria
 *                               (REQ-DGAAP-010).
 *
 * ADR-031 exception reason: cross-field / conditional completeness checks are not
 * yet expressible in the declarative lifecycle DSL. When the engine gains those
 * capabilities, replace these references with declarative conditions and delete
 * this file. ADR-022: object reads use the real OpenRegister ObjectService API
 * (setRegister/setSchema/findAll) only.
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
 * @spec openspec/specs/bookkeeping-ifrs-rj-dual-gaap/spec.md
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
 * Lifecycle precondition guards for the dual GAAP reporting registers.
 *
 * Referenced from the register.d fragment schema lifecycle transitions
 * (DualTransaction.reconcile, FrameworkElection.activate) as
 * OCA\Shillinq\Lifecycle\DualGaapGuard::<method>. Every guard fails closed:
 * any exception or malformed input denies the transition (CWE-863).
 *
 * @spec openspec/specs/bookkeeping-ifrs-rj-dual-gaap/spec.md
 */
class DualGaapGuard {
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
	 * Returns true iff a DualTransaction may transition from classified to reconciled.
	 *
	 * REQ-DGAAP-003 / REQ-DGAAP-006: a transaction may only be reconciled once a
	 * divergence reason code is assigned, and — when the difference is temporary —
	 * a non-zero deferred-tax effect is recorded so the IAS 12 sub-administration
	 * stays in balance. Permanent differences and reclassifications need no tax
	 * effect.
	 *
	 * @param string $transactionId The DualTransaction id (call-signature parity).
	 * @param array<string,mixed>|null $object The transaction being transitioned.
	 *
	 * @return bool True when the transaction may be reconciled.
	 *
	 * @spec openspec/specs/bookkeeping-ifrs-rj-dual-gaap/spec.md
	 */
	public function canReconcileTransaction(string $transactionId, ?array $object = null): bool {
		try {
			$transaction = $this->resolveObject(schema: 'DualTransaction', id: $transactionId, object: $object);
			if ($transaction === null) {
				return false;
			}

			$reasonCode = trim((string)($transaction['divergenceReasonCode'] ?? ''));
			if ($reasonCode === '') {
				return false;
			}

			$classification = (string)($transaction['divergenceClassification'] ?? '');
			if ($classification === 'temporary') {
				$taxEffect = $transaction['deferredTaxEffect'] ?? null;
				if ($taxEffect === null || is_numeric($taxEffect) === false) {
					return false;
				}

				if (abs((float)$taxEffect) < 0.005) {
					return false;
				}
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'DualGaapGuard: reconcile check failed — denying transition (fail-closed)',
				['transactionId' => $transactionId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end canReconcileTransaction()

	/**
	 * Returns true iff a FrameworkElection may transition from draft to active.
	 *
	 * REQ-DGAAP-010: an election may only activate once it carries a comply-or-explain
	 * motivation and an AVA-besluit reference, and the elected RJ variant is consistent
	 * with the measured size criteria. An RJk (small-entity) variant is rejected when
	 * the measured balance-sheet total or net turnover exceeds the small-entity ceilings
	 * (BW2 art 2:396: balanstotaal > €6m or netto-omzet > €12m) — that mismatch must be
	 * resolved (or an explicit larger variant chosen) before publication.
	 *
	 * @param string $electionId The FrameworkElection id (call-signature parity).
	 * @param array<string,mixed>|null $object The election being transitioned.
	 *
	 * @return bool True when the election may be activated.
	 *
	 * @spec openspec/specs/bookkeeping-ifrs-rj-dual-gaap/spec.md
	 */
	public function canActivateElection(string $electionId, ?array $object = null): bool {
		try {
			$election = $this->resolveObject(schema: 'FrameworkElection', id: $electionId, object: $object);
			if ($election === null) {
				return false;
			}

			$motivation = trim((string)($election['complyOrExplainMotivation'] ?? ''));
			$avaRef = trim((string)($election['avaDecisionReference'] ?? ''));
			if ($motivation === '' || $avaRef === '') {
				return false;
			}

			return $this->isVariantConsistentWithSize(election: $election);
		} catch (\Throwable $e) {
			$this->logger->error(
				'DualGaapGuard: activate-election check failed — denying transition (fail-closed)',
				['electionId' => $electionId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end canActivateElection()

	/**
	 * Returns true iff the elected RJ variant is consistent with the measured size
	 * criteria (REQ-DGAAP-010).
	 *
	 * Only the RJk (small-entity) variant carries a hard size ceiling. When either
	 * the balance-sheet total exceeds €6,000,000 or the net turnover exceeds
	 * €12,000,000 (BW2 art 2:396), the small-entity election is inconsistent and the
	 * activation is denied. RJ-onverkort and IFRS-volledig impose no upper ceiling.
	 *
	 * @param array<string,mixed> $election The election being validated.
	 *
	 * @return bool True when the variant is consistent with the measured size.
	 */
	private function isVariantConsistentWithSize(array $election): bool {
		$variant = (string)($election['rjVariant'] ?? '');
		if ($variant !== 'RJk') {
			return true;
		}

		$balanceTotal = $election['sizeCriteriaBalanceSheetTotal'] ?? null;
		$netTurnover = $election['sizeCriteriaNetRevenue'] ?? null;

		if (is_numeric($balanceTotal) === true && (float)$balanceTotal > 6000000.0) {
			return false;
		}

		if (is_numeric($netTurnover) === true && (float)$netTurnover > 12000000.0) {
			return false;
		}

		return true;
	}//end isVariantConsistentWithSize()

	/**
	 * Resolve the object under transition, preferring the supplied in-flight
	 * object and falling back to an ObjectService lookup by id (ADR-022 real API).
	 *
	 * @param string $schema The OpenRegister schema slug to query.
	 * @param string $id The object id to look up if no object given.
	 * @param array<string,mixed>|null $object The in-flight object, if provided by the engine.
	 *
	 * @return array<string,mixed>|null The resolved object, or null when unavailable.
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
	 * Resolve the configured OpenRegister register slug, defaulting to `shillinq`.
	 *
	 * @return string The register slug.
	 */
	private function resolveRegister(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end resolveRegister()
}//end class
