<?php

/**
 * DBA Compliance Lifecycle Guard
 *
 * ADR-031 exception-path guard for the DBA compliance marker registers
 * (dba-compliance-marker, T2). The bulk of the DBA surface is declarative
 * (six schemas + x-openregister-lifecycle + x-openregister-calculations +
 * x-openregister-aggregations in the register.d fragment). A small set of
 * cross-field rules require completeness/derivation logic that OpenRegister's
 * declarative DSL cannot yet express; those are referenced from the schema
 * lifecycle transitions and implemented here:
 *
 *  - canActivateOpdracht():  a DBAOpdracht may only become actief (and thus
 *                            allow the first factuur) once its intake is
 *                            VOLTOOID (REQ-DBA-001).
 *  - canBeeindigOpdracht():  an opdracht may only be beeindigd once a
 *                            feitelijkeEindDatum is set, which starts the
 *                            7-year AWR retention clock (REQ-DBA-018).
 *  - canCompleteIntake():    an intake may only complete once it carries a
 *                            computed totaalScore and a derived risk band
 *                            (REQ-DBA-003).
 *
 * In addition the guard exposes pure derivation helpers used by the monitoring
 * engine and the calculation layer (no lifecycle binding required):
 *
 *  - deriveRiskBand():       maps a 0-100 score onto the four risk bands
 *                            LAAG/LAAG_MIDDEN/MIDDEN_HOOG/HOOG (REQ-DBA-003).
 *  - computeTotaalScore():   sums the three pijler subtotals + Deliveroo
 *                            subtotal from an intake (REQ-DBA-003).
 *  - computeCompleteness():  the evidence-dossier completeness ratio over the
 *                            required stuk types (REQ-DBA-007).
 *  - effectiveHourlyRateBreach(): true when an invoice's effective hourly rate
 *                            falls below the VBAR uurtarief-grens (REQ-DBA-016).
 *  - isModelExpired():       true when a modelovereenkomst is past geldigTot
 *                            (REQ-DBA-002).
 *
 * ADR-031 exception reason: cross-field completeness checks, band derivation
 * and ratio computation are not yet expressible in the declarative lifecycle /
 * calculation DSL. When the engine gains those capabilities, replace these
 * references with declarative conditions and delete this file. ADR-022: object
 * reads use the real OpenRegister ObjectService API (setRegister/setSchema/
 * findAll) only — findObject/findObjects/createFromArray do not exist.
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
 * @spec openspec/specs/dba-compliance-marker/spec.md
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
 * Lifecycle precondition guards + pure derivation helpers for the DBA
 * compliance marker registers.
 *
 * Referenced from the register.d fragment lifecycle transitions
 * (DBAOpdracht.activeer/beeindig, DBAIntake.complete) as
 * OCA\Shillinq\Lifecycle\DBAComplianceGuard::<method>. Every guard fails
 * closed: any exception or malformed input denies the transition (CWE-863).
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 */
class DBAComplianceGuard {
	/**
	 * VBAR uurtarief-rechtsvermoeden grens in EUR (peil 2024, geindexeerd).
	 *
	 * Below this effective hourly rate the VBAR introduces an automatic
	 * werknemer-rechtsvermoeden (REQ-DBA-016). Baked as a constant; operators
	 * override it via administration settings when the legislation indexes the
	 * grens annually.
	 *
	 * @var float
	 */
	public const VBAR_GRENS_EUR = 33.0;

	/**
	 * The eenmalige-opdracht drempel in EUR below which the verkorte 3-vraag
	 * intake is offered (REQ-DBA-001).
	 *
	 * @var float
	 */
	public const EENMALIG_DREMPEL_EUR = 5000.0;

	/**
	 * Evidence stuk types that count towards the dossier completeness ratio
	 * (REQ-DBA-007).
	 *
	 * @var array<string>
	 */
	public const REQUIRED_STUK_TYPES = [
		'SIGNED_AGREEMENT',
		'INVOICE_FIRST',
		'TIMESHEET_QUARTER',
	];

	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param IAppConfig $appConfig App config for the register slug + VBAR override.
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
	 * Returns true iff a DBAOpdracht may transition from draft to actief.
	 *
	 * REQ-DBA-001: the DBA intake is verplicht before the first factuur. An
	 * opdracht only becomes actief — the state in which the first factuur is
	 * permitted — once its intake is VOLTOOID. A VERKORT_LAGE_DREMPEL intake on
	 * an eenmalige opdracht under the drempel also satisfies the requirement.
	 *
	 * @param string $assignmentId The DBAOpdracht id (call-signature parity).
	 * @param array<string,mixed>|null $object The opdracht being transitioned.
	 *
	 * @return bool True when the opdracht may be activated.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function canActivateOpdracht(string $assignmentId, ?array $object = null): bool {
		try {
			$assignment = $this->resolveObject(schema: 'DBAOpdracht', id: $assignmentId, object: $object);
			if ($assignment === null) {
				return false;
			}

			$intakeStatus = (string)($assignment['intakeStatus'] ?? 'NONE');

			return $intakeStatus === 'VOLTOOID';
		} catch (\Throwable $e) {
			$this->logger->error(
				'DBAComplianceGuard: activate-opdracht check failed — denying transition (fail-closed)',
				['assignmentId' => $assignmentId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end canActivateOpdracht()

	/**
	 * Returns true iff a DBAOpdracht may transition from actief to beeindigd.
	 *
	 * REQ-DBA-018: beeindiging closes the evidence-trail and starts the 7-year
	 * AWR (art. 52) retention clock. The transition requires a feitelijkeEindDatum
	 * so the retention-period end can be computed (eindDatum + 7 years).
	 *
	 * @param string $assignmentId The DBAOpdracht id (call-signature parity).
	 * @param array<string,mixed>|null $object The opdracht being transitioned.
	 *
	 * @return bool True when the opdracht may be beeindigd.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function canBeeindigOpdracht(string $assignmentId, ?array $object = null): bool {
		try {
			$assignment = $this->resolveObject(schema: 'DBAOpdracht', id: $assignmentId, object: $object);
			if ($assignment === null) {
				return false;
			}

			$endDate = trim((string)($assignment['actualEndDate'] ?? ''));

			return $endDate !== '';
		} catch (\Throwable $e) {
			$this->logger->error(
				'DBAComplianceGuard: beeindig-opdracht check failed — denying transition (fail-closed)',
				['assignmentId' => $assignmentId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end canBeeindigOpdracht()

	/**
	 * Returns true iff a DBAIntake may transition from submitted to completed.
	 *
	 * REQ-DBA-003: an intake may only complete once it carries a computed
	 * totaalScore consistent with the four subtotals and a non-empty derived
	 * risk band. The recomputed score must equal the stored totaalScore so a
	 * tampered or stale score cannot be completed.
	 *
	 * @param string $intakeId The DBAIntake id (call-signature parity).
	 * @param array<string,mixed>|null $object The intake being transitioned.
	 *
	 * @return bool True when the intake may be completed.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function canCompleteIntake(string $intakeId, ?array $object = null): bool {
		try {
			$intake = $this->resolveObject(schema: 'DBAIntake', id: $intakeId, object: $object);
			if ($intake === null) {
				return false;
			}

			// A verkorte intake on an eenmalige opdracht is always completable —
			// it carries the VERKORT_LAGE_DREMPEL band, not a full score.
			if (($intake['verkort'] ?? false) === true) {
				return true;
			}

			$stored = (int)($intake['totalScore'] ?? -1);
			$recomputed = $this->computeTotaalScore(intake: $intake);
			if ($stored !== $recomputed) {
				return false;
			}

			return $this->deriveRiskBand(score: $recomputed) !== '';
		} catch (\Throwable $e) {
			$this->logger->error(
				'DBAComplianceGuard: complete-intake check failed — denying transition (fail-closed)',
				['intakeId' => $intakeId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end canCompleteIntake()

	/**
	 * Sum the three Wet-DBA pijler subtotals + the Deliveroo subtotal into the
	 * total risk score (REQ-DBA-003).
	 *
	 * TotaalScore = gezagSubtotaal + arbeidSubtotaal + financieelSubtotaal
	 * + deliverooSubtotaal, clamped to 0-100.
	 *
	 * @param array<string,mixed> $intake The intake answers.
	 *
	 * @return int The total risk score (0-100).
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function computeTotaalScore(array $intake): int {
		$gezag = (int)($intake['gezagSubtotaal'] ?? 0);
		$arbeid = (int)($intake['arbeidSubtotaal'] ?? 0);
		$financieel = (int)($intake['financieelSubtotaal'] ?? 0);
		$deliveroo = (int)($intake['deliverooSubtotaal'] ?? 0);

		$total = ($gezag + $arbeid + $financieel + $deliveroo);

		return max(0, min(100, $total));
	}//end computeTotaalScore()

	/**
	 * Derive the risk band from a 0-100 score (REQ-DBA-003).
	 *
	 * Bands: LAAG (0-24), LAAG_MIDDEN (25-49), MIDDEN_HOOG (50-74),
	 * HOOG (75-100). Scores outside 0-100 are clamped before banding.
	 *
	 * @param int $score The total risk score.
	 *
	 * @return string The risk band, or '' when the score is not derivable.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function deriveRiskBand(int $score): string {
		$clamped = max(0, min(100, $score));

		if ($clamped >= 75) {
			return 'HIGH';
		}

		if ($clamped >= 50) {
			return 'MIDDEN_HIGH';
		}

		if ($clamped >= 25) {
			return 'LOW_MIDDEN';
		}

		return 'LOW';
	}//end deriveRiskBand()

	/**
	 * Compute the evidence-dossier completeness ratio (REQ-DBA-007).
	 *
	 * The compleetheidScore is count(distinct present required stuk types)
	 * divided by count(REQUIRED_STUK_TYPES). The set of still-missing required
	 * types is returned alongside the ratio so the UI can list them.
	 *
	 * @param array<int,mixed> $documents The dossier stukken (each item is a JSON-decoded stuk).
	 *
	 * @return array{score: float, missing: array<string>} Completeness result.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function computeCompleteness(array $documents): array {
		$presentTypes = [];
		foreach ($documents as $stuk) {
			if (is_array($stuk) === false) {
				continue;
			}

			$type = (string)($stuk['type'] ?? '');
			if ($type !== '') {
				$presentTypes[$type] = true;
			}
		}

		$missing = [];
		$present = 0;
		foreach (self::REQUIRED_STUK_TYPES as $required) {
			if (isset($presentTypes[$required]) === true) {
				$present++;
				continue;
			}

			$missing[] = $required;
		}

		// REQUIRED_STUK_TYPES is a non-empty constant, so the denominator is
		// always positive — no zero-division guard needed. Cast to float so the
		// ratio is always a float (PHP returns int on an exact division).
		$score = ((float)$present / (float)count(self::REQUIRED_STUK_TYPES));

		return [
			'score' => $score,
			'missing' => $missing,
		];

	}//end computeCompleteness()

	/**
	 * Determine whether an invoice's effective hourly rate breaches the VBAR
	 * uurtarief-grens (REQ-DBA-016).
	 *
	 * The effective rate = bedrag / uren. A breach (rate < grens) raises the
	 * VBAR_GRENS_ONDERSCHREDEN flag (and blocks in hard-mode). Non-positive uren
	 * or bedrag yields no breach (cannot compute a rate). The grens is the
	 * administration override when configured, else VBAR_GRENS_EUR.
	 *
	 * @param float $amount The invoice amount (EUR).
	 * @param float $hours The hours billed.
	 *
	 * @return array{breach: bool, rate: float, grens: float} VBAR check result.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function effectiveHourlyRateBreach(float $amount, float $hours): array {
		$grens = $this->resolveVbarGrens();

		if ($hours <= 0.0 || $amount <= 0.0) {
			return [
				'breach' => false,
				'rate' => 0.0,
				'grens' => $grens,
			];
		}

		$rate = ($amount / $hours);

		return [
			'breach' => ($rate < $grens),
			'rate' => $rate,
			'grens' => $grens,
		];

	}//end effectiveHourlyRateBreach()

	/**
	 * Determine whether a modelovereenkomst is expired on the given reference
	 * date (REQ-DBA-002).
	 *
	 * A model is expired when geldigTot is set and strictly before the
	 * reference date — monitoring then raises MODELOVEREENKOMST_VERLOPEN. A
	 * model with no geldigTot never expires.
	 *
	 * @param array<string,mixed> $model The modelovereenkomst.
	 * @param string $referenceYmd The reference date (Y-m-d); defaults to today.
	 *
	 * @return bool True when the model is expired.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function isModelExpired(array $model, string $referenceYmd = ''): bool {
		$validTo = trim((string)($model['validTo'] ?? ''));
		if ($validTo === '') {
			return false;
		}

		if ($referenceYmd === '') {
			$referenceYmd = date('Y-m-d');
		}

		return strcmp($validTo, $referenceYmd) < 0;
	}//end isModelExpired()

	/**
	 * Resolve the effective VBAR grens, preferring an administration override
	 * (app config key 'dba_vbar_grens') and falling back to VBAR_GRENS_EUR.
	 *
	 * @return float The effective VBAR grens in EUR.
	 */
	private function resolveVbarGrens(): float {
		$override = $this->appConfig->getValueString(Application::APP_ID, 'dba_vbar_grens', '');
		if ($override !== '' && is_numeric($override) === true) {
			$value = (float)$override;
			if ($value > 0.0) {
				return $value;
			}
		}

		return self::VBAR_GRENS_EUR;
	}//end resolveVbarGrens()

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
