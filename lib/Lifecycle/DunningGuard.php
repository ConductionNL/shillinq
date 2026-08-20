<?php

/**
 * Credit-Control / Dunning Lifecycle Guard
 *
 * ADR-031 exception-path lifecycle guards for the credit-control & dunning
 * registers (bookkeeping-credit-control-dunning, T2). The bulk of the dunning
 * measurement model is declarative (schema metadata + x-openregister-lifecycle +
 * x-openregister-calculations for the BIK-staffel and wettelijke rente). A small
 * set of preconditions require cross-field completeness / legal-period checks that
 * OpenRegister's declarative `requires:` clause cannot yet express; those are
 * referenced from the schema lifecycle transitions and implemented here:
 *
 *  - canActivateOverride(): a KlantLadderOverride that skips stage 4/5 escalation
 *                           may only activate with a manager/controller approver
 *                           (approvedBy) recorded (REQ-CCD-001 / design D6).
 *  - canExecuteRun():       a B2C stage-3 DunningRun must carry the verbatim
 *                           14-dagen-brief text in its rendered body before it may
 *                           be dispatched (REQ-CCD-006 / art. 6:96 BW); an already
 *                           executed/locked run may never be amended (REQ-CCD-002).
 *  - canResolvePause():     a DunningPauseDispute may only resolve once a pauzeEind
 *                           is recorded (REQ-CCD-004 / design D7).
 *  - canPostWriteOff():     an OninbaarAfschrijving may only post once it carries an
 *                           art. 29 OB-verklaring (REQ-CCD-010).
 *  - blocksB2cIncassokosten(): helper consumed by the IncassoKostenBerekening write
 *                           path to block B2C incassokosten before day 44 (the 14-day
 *                           termijn after a day-30 aanmaning) per art. 6:96 BW
 *                           (REQ-CCD-003 / REQ-CCD-006).
 *
 * ADR-031 exception reason: cross-field completeness, legal-period (day-44) and
 * substring (14-dagen-brief) checks are not yet expressible in the declarative
 * lifecycle DSL. When the engine gains those capabilities, replace these references
 * with declarative conditions and delete this file. ADR-022: object reads use the
 * real OpenRegister ObjectService API (setRegister/setSchema/findAll) only. *
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
 * @spec openspec/changes/bookkeeping-credit-control-dunning/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle precondition guards for the credit-control & dunning registers.
 *
 * Referenced from the register.d fragment schema lifecycle transitions
 * (KlantLadderOverride, DunningRun, DunningPauseDispute, OninbaarAfschrijving) as
 * OCA\Shillinq\Lifecycle\DunningGuard::<method>. Every guard fails closed: any
 * exception or malformed input denies the transition (CWE-863). The verbatim
 * 14-dagen-brief fragment and the B2C day-44 threshold are read from app config so
 * they can be tuned without a code change (ADR-005 — secrets/config from app config).
 *
 * @spec openspec/changes/bookkeeping-credit-control-dunning/spec.md
 */
class DunningGuard {
	/**
	 * Default day after vervaldatum before B2C incassokosten may be calculated:
	 * day-30 aanmaning + 14-dagen termijn (art. 6:96 BW).
	 *
	 * @var int
	 */
	private const DEFAULT_B2C_INCASSOKOSTEN_DAG = 44;

	/**
	 * A stable substring of the wettelijke 14-dagen-brief text (REQ-CCD-006). The
	 * full sentence is rendered by docudesk; this fragment proves it is present.
	 *
	 * @var string
	 */
	private const DEFAULT_FOURTEEN_DAY_FRAGMENT = '14 dagen';

	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param IAppConfig $appConfig App config for the register slug + tunables.
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
	 * Returns true iff a KlantLadderOverride may activate.
	 *
	 * REQ-CCD-001 / design D6: an override that drops a stage 4 or stage 5 from the
	 * base ladder is a privileged escalation-skip and requires a recorded approver
	 * (approvedBy). Overrides that keep all five stages need no approver.
	 *
	 * @param string $overrideId The KlantLadderOverride id (call-signature parity).
	 * @param array<string,mixed>|null $object The override being transitioned.
	 *
	 * @return bool True when the override may be activated.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/spec.md
	 */
	public function canActivateOverride(string $overrideId, ?array $object = null): bool {
		try {
			$override = $this->resolveObject(schema: 'KlantLadderOverride', id: $overrideId, object: $object);
			if ($override === null) {
				return false;
			}

			if ($this->overrideSkipsLateStages(override: $override) === false) {
				return true;
			}

			return trim((string)($override['approvedBy'] ?? '')) !== '';
		} catch (\Throwable $e) {
			$this->logger->error(
				'DunningGuard: override activate check failed — denying transition (fail-closed)',
				['overrideId' => $overrideId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canActivateOverride()

	/**
	 * Returns true iff a DunningRun may be executed (dispatched).
	 *
	 * REQ-CCD-006 / art. 6:96 BW: a B2C stage-3 run must carry the verbatim
	 * 14-dagen-brief text in its rendered body before dispatch. REQ-CCD-002: a run
	 * already in the executed/locked state is immutable and may not be re-executed.
	 *
	 * @param string $runId The DunningRun id (call-signature parity).
	 * @param array<string,mixed>|null $object The run being transitioned.
	 *
	 * @return bool True when the run may be executed.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/spec.md
	 */
	public function canExecuteRun(string $runId, ?array $object = null): bool {
		try {
			$run = $this->resolveObject(schema: 'DunningRun', id: $runId, object: $object);
			if ($run === null) {
				return false;
			}

			// REQ-CCD-002: an executed/locked run is immutable.
			$state = (string)($run['state'] ?? 'draft');
			if ($state === 'executed' || $state === 'locked') {
				return false;
			}

			// REQ-CCD-006: B2C stage 3 must carry the 14-dagen-brief text.
			if ($this->requiresFourteenDayLetter(run: $run) === true
				&& $this->bodyContainsFourteenDayLetter(run: $run) === false
			) {
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'DunningGuard: run execute check failed — denying transition (fail-closed)',
				['runId' => $runId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canExecuteRun()

	/**
	 * Returns true iff a DunningPauseDispute may resolve.
	 *
	 * REQ-CCD-004 / design D7: a pause may only be marked resolved once a pauzeEind
	 * timestamp is recorded, so the ladder knows from when to resume rente accrual.
	 *
	 * @param string $pauseId The DunningPauseDispute id (call-signature parity).
	 * @param array<string,mixed>|null $object The pause being transitioned.
	 *
	 * @return bool True when the pause may be resolved.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/spec.md
	 */
	public function canResolvePause(string $pauseId, ?array $object = null): bool {
		try {
			$pause = $this->resolveObject(schema: 'DunningPauseDispute', id: $pauseId, object: $object);
			if ($pause === null) {
				return false;
			}

			return trim((string)($pause['pauseEnd'] ?? '')) !== '';
		} catch (\Throwable $e) {
			$this->logger->error(
				'DunningGuard: pause resolve check failed — denying transition (fail-closed)',
				['pauseId' => $pauseId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canResolvePause()

	/**
	 * Returns true iff an OninbaarAfschrijving may post.
	 *
	 * REQ-CCD-010: a write-off may only post once it carries an art. 29 OB-verklaring
	 * (the legal ground for the BTW-teruggaaf) and a positive afgeschreven hoofdsom.
	 *
	 * @param string $writeOffId The OninbaarAfschrijving id (call-signature parity).
	 * @param array<string,mixed>|null $object The write-off being transitioned.
	 *
	 * @return bool True when the write-off may post.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/spec.md
	 */
	public function canPostWriteOff(string $writeOffId, ?array $object = null): bool {
		try {
			$writeOff = $this->resolveObject(schema: 'OninbaarAfschrijving', id: $writeOffId, object: $object);
			if ($writeOff === null) {
				return false;
			}

			if (trim((string)($writeOff['art29OBDeclaration'] ?? '')) === '') {
				return false;
			}

			return (float)($writeOff['principalDepreciated'] ?? 0) > 0.0;
		} catch (\Throwable $e) {
			$this->logger->error(
				'DunningGuard: write-off post check failed — denying transition (fail-closed)',
				['writeOffId' => $writeOffId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canPostWriteOff()

	/**
	 * Returns true iff B2C incassokosten must be blocked for the given verzuim day.
	 *
	 * REQ-CCD-003 / REQ-CCD-006 / art. 6:96 BW: for a B2C party the BIK-staffel may
	 * not be calculated before day 44 (the 14-day termijn after the day-30 aanmaning).
	 * B2B incassokosten apply from verzuim van rechtswege and are never blocked.
	 *
	 * @param string $partyType The party type (B2B / B2C).
	 * @param int $daysAfterExpiryDate Days since the invoice vervaldatum.
	 *
	 * @return bool True when the calculation must be blocked.
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/spec.md
	 */
	public function blocksB2cIncassokosten(string $partyType, int $daysAfterExpiryDate): bool {
		if (strtoupper($partyType) !== 'B2C') {
			return false;
		}

		$threshold = $this->appConfig->getValueInt(
			Application::APP_ID,
			'dunning.b2c_incassokosten_dag',
			self::DEFAULT_B2C_INCASSOKOSTEN_DAG
		);

		return $daysAfterExpiryDate < $threshold;
	}//end blocksB2cIncassokosten()

	/**
	 * Returns true iff the override drops a stage numbered 4 or higher from the base
	 * five-stage ladder (an escalation-skip requiring approval).
	 *
	 * @param array<string,mixed> $override The override object.
	 *
	 * @return bool True when a late stage is skipped.
	 */
	private function overrideSkipsLateStages(array $override): bool {
		$stages = $override['overrides']['stages'] ?? null;
		if (is_array($stages) === false) {
			// No explicit stage list — treat as a non-skipping timing override.
			return false;
		}

		$maxStage = 0;
		foreach ($stages as $stage) {
			if (is_array($stage) === true) {
				$maxStage = max($maxStage, (int)($stage['nr'] ?? 0));
			}
		}

		// The default ladder has five stages; a top stage below 5 means 5 (and
		// possibly 4) were dropped — an escalation-skip.
		return $maxStage < 5;
	}//end overrideSkipsLateStages()

	/**
	 * Returns true iff the run is a B2C stage-3 aanmaning that legally requires the
	 * 14-dagen-brief (REQ-CCD-006). The party type is read from the run or, failing
	 * that, the linked IncassoKostenBerekening.
	 *
	 * @param array<string,mixed> $run The DunningRun object.
	 *
	 * @return bool True when the 14-dagen-brief is required.
	 */
	private function requiresFourteenDayLetter(array $run): bool {
		if ((int)($run['stageNr'] ?? 0) !== 3) {
			return false;
		}

		$partyType = strtoupper((string)($run['partyType'] ?? ''));
		if ($partyType === '') {
			$partyType = strtoupper($this->lookupPartyType(invoiceId: (string)($run['invoiceId'] ?? '')));
		}

		return $partyType === 'B2C';
	}//end requiresFourteenDayLetter()

	/**
	 * Returns true iff the run's rendered body contains the verbatim 14-dagen-brief
	 * fragment (REQ-CCD-006). The required fragment is configurable.
	 *
	 * @param array<string,mixed> $run The DunningRun object.
	 *
	 * @return bool True when the body carries the mandatory text.
	 */
	private function bodyContainsFourteenDayLetter(array $run): bool {
		$fragment = $this->appConfig->getValueString(
			Application::APP_ID,
			'dunning.fourteen_day_letter_fragment',
			self::DEFAULT_FOURTEEN_DAY_FRAGMENT
		);

		$body = (string)($run['renderedBody'] ?? '');

		return mb_stripos($body, $fragment) !== false;
	}//end bodyContainsFourteenDayLetter()

	/**
	 * Look up the partyType for an invoice via its IncassoKostenBerekening record
	 * (ADR-022 real ObjectService API). Returns an empty string when unknown.
	 *
	 * @param string $invoiceId The invoice id.
	 *
	 * @return string The party type, or an empty string.
	 */
	private function lookupPartyType(string $invoiceId): string {
		if ($invoiceId === '') {
			return '';
		}

		$register = $this->resolveRegister();

		$results = $this->objectService
			->setRegister($register)
			->setSchema('IncassoKostenBerekening')
			->findAll(['filters' => ['invoiceId' => $invoiceId]]);

		foreach ($results as $result) {
			if (is_array($result) === true && isset($result['partyType']) === true) {
				return (string)$result['partyType'];
			}
		}

		return '';
	}//end lookupPartyType()

	/**
	 * Resolve the object under transition, preferring the supplied in-flight object
	 * and falling back to an ObjectService lookup by id (ADR-022 real API).
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
