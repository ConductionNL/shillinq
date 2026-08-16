<?php

/**
 * Wet Fido & Treasurystatuut Lifecycle Guard
 *
 * ADR-031 exception-path lifecycle guards for the Wet Fido / Treasurystatuut
 * registers (bookkeeping-wet-fido-treasury, T3). The bulk of the Wet Fido
 * measurement model is declarative (schema metadata + x-openregister-lifecycle +
 * x-openregister-aggregations for the kasgeldlimiet rolling-3-month average and
 * the rente-risiconorm 4-year forward projection). A small set of preconditions
 * require cross-field / cross-schema completeness checks that OpenRegister's
 * declarative `requires:` clause cannot yet express; those are referenced from
 * the schema lifecycle transitions and implemented here:
 *
 *  - canRecordLening():     a lening may only be recorded when it sits inside the
 *                           adopted Treasurystatuut signing-mandate matrix (role x
 *                           instrument x amount, REQ-FDO-001 / design D5); when a
 *                           limiet-breach was flagged, a written override-rationale
 *                           is mandatory before the lening may move to
 *                           recorded-with-override (REQ-FDO-008 / design D10).
 *  - canRecordDerivaat():   a derivaat passes RUDDO hedging-only validation only
 *                           when it carries a justification, a hedge-link, a notional
 *                           that does not exceed the hedged exposure, and a
 *                           counterparty rating of at least single-A (REQ-FDO-004 /
 *                           design D6). Speculative derivatives are never recorded.
 *  - canSubmitRapportage(): a quarterly Fido rapportage may only leave draft once it
 *                           carries both the treasurer and the concerncontroller
 *                           sign-off (person + timestamp), REQ-FDO-006 / design D8.
 *
 * ADR-031 exception reason: cross-row matrix lookups and cross-field completeness
 * checks are not yet expressible in the declarative lifecycle DSL. When the engine
 * gains those capabilities, replace these references with declarative conditions and
 * delete this file. ADR-022: object reads use the real OpenRegister ObjectService API
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
 * @spec openspec/specs/bookkeeping-wet-fido-treasury/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle precondition guards for the Wet Fido / Treasurystatuut registers.
 *
 * Referenced from the register.d fragment schema lifecycle transitions (Lening,
 * Derivaat, QuartaalrapportageFido) as
 * OCA\Shillinq\Lifecycle\FidoTreasuryGuard::<method>. Every guard fails closed:
 * any exception or malformed input denies the transition (CWE-863).
 *
 * The aggregate per-class complexity slightly exceeds the default phpmd threshold
 * because the three Wet Fido / RUDDO preconditions (signing-mandate matrix lookup,
 * RUDDO hedging validation, dual sign-off) are kept together as cohesive,
 * single-purpose private methods rather than fragmented across several thin
 * collaborator classes; splitting would hurt readability without lowering the real
 * branch count. The class is suppressed accordingly (mirrors AppointmentGuard).
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/specs/bookkeeping-wet-fido-treasury/spec.md
 */
class FidoTreasuryGuard {

	/**
	 * Long-term counterparty ratings that satisfy the RUDDO single-A minimum.
	 *
	 * @var array<string>
	 */
	private const RUDDO_ACCEPTED_RATINGS = ['AAA', 'AA', 'A'];

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
	 * Returns true iff the lening may be recorded (with or without override).
	 *
	 * REQ-FDO-001 / design D5: the lening's signer-role + instrument + amount must
	 * be covered by a row of the adopted Treasurystatuut signing-mandate matrix.
	 * REQ-FDO-008 / design D10: when a limiet-breach was flagged on the lening, a
	 * non-empty override-rationale is mandatory before it may be recorded.
	 *
	 * @param string $leningId The Lening id (call-signature parity).
	 * @param array<string,mixed>|null $object The lening being transitioned.
	 *
	 * @return bool True when the lening may be recorded.
	 *
	 * @spec openspec/specs/bookkeeping-wet-fido-treasury/spec.md
	 */
	public function canRecordLening(string $leningId, ?array $object = null): bool {
		try {
			$lening = $this->resolveObject(schema: 'Lening', id: $leningId, object: $object);
			if ($lening === null) {
				return false;
			}

			// REQ-FDO-008 / D10: a flagged limiet-breach demands an override-rationale.
			if ((bool)($lening['limitBreach'] ?? false) === true
				&& trim((string)($lening['overrideRationale'] ?? '')) === ''
			) {
				return false;
			}

			// REQ-FDO-001 / D5: validate against the adopted Treasurystatuut matrix.
			return $this->isWithinSigningMandate(lening: $lening);
		} catch (\Throwable $e) {
			$this->logger->error(
				'FidoTreasuryGuard: lening record check failed — denying transition (fail-closed)',
				['leningId' => $leningId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canRecordLening()

	/**
	 * Returns true iff the derivaat passes RUDDO hedging-only validation.
	 *
	 * REQ-FDO-004 / design D6 (RUDDO Article 2): the derivaat must carry a written
	 * justification, a hedge-link to an underlying exposure, a notional that does not
	 * exceed the hedged exposure, and a counterparty rating of at least single-A.
	 * Speculative derivatives are never recorded.
	 *
	 * @param string $derivaatId The Derivaat id (call-signature parity).
	 * @param array<string,mixed>|null $object The derivaat being transitioned.
	 *
	 * @return bool True when the derivaat may be recorded.
	 *
	 * @spec openspec/specs/bookkeeping-wet-fido-treasury/spec.md
	 */
	public function canRecordDerivaat(string $derivaatId, ?array $object = null): bool {
		try {
			$derivaat = $this->resolveObject(schema: 'Derivaat', id: $derivaatId, object: $object);
			if ($derivaat === null) {
				return false;
			}

			return $this->passesRuddo(derivaat: $derivaat);
		} catch (\Throwable $e) {
			$this->logger->error(
				'FidoTreasuryGuard: derivaat RUDDO check failed — denying transition (fail-closed)',
				['derivaatId' => $derivaatId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canRecordDerivaat()

	/**
	 * Returns true iff a derivaat satisfies the RUDDO hedging-only rule (Article 2).
	 *
	 * Requires a justification narrative, a hedge-link to an underlying exposure, a
	 * notional bounded by the hedged exposure, and a single-A-or-better counterparty.
	 *
	 * @param array<string,mixed> $derivaat The derivaat being validated.
	 *
	 * @return bool True when every RUDDO precondition is met.
	 */
	private function passesRuddo(array $derivaat): bool {
		// A hedging justification narrative and a hedge-link are mandatory.
		if (trim((string)($derivaat['RUDDOJustification'] ?? '')) === ''
			|| trim((string)($derivaat['hedgedExposureId'] ?? '')) === ''
		) {
			return false;
		}

		// The notional may not exceed the hedged exposure (no over-hedging).
		if ($this->notionalWithinHedge(
			notional: ($derivaat['notional'] ?? null),
			hedgedExposure: ($derivaat['hedgedExposureAmount'] ?? null)
		) === false
		) {
			return false;
		}

		// The counterparty must meet the RUDDO single-A minimum.
		$rating = (string)($derivaat['counterpartyRating'] ?? '');
		return in_array($rating, self::RUDDO_ACCEPTED_RATINGS, true);
	}//end passesRuddo()

	/**
	 * Returns true iff a positive notional does not exceed a positive hedged exposure.
	 *
	 * @param mixed $notional The derivaat notional.
	 * @param mixed $hedgedExposure The hedged exposure amount.
	 *
	 * @return bool True when both are positive and notional <= hedgedExposure.
	 */
	private function notionalWithinHedge(mixed $notional, mixed $hedgedExposure): bool {
		$notionalValue = $this->toFloat(value: $notional);
		$hedgedValue = $this->toFloat(value: $hedgedExposure);
		if ($notionalValue === null || $hedgedValue === null) {
			return false;
		}

		if ($notionalValue <= 0.0 || $hedgedValue <= 0.0) {
			return false;
		}

		return $notionalValue <= $hedgedValue;
	}//end notionalWithinHedge()

	/**
	 * Returns true iff the quarterly Fido rapportage may leave draft / be submitted.
	 *
	 * REQ-FDO-006 / design D8: the rapportage must carry both the treasurer and the
	 * concerncontroller sign-off (each a person + timestamp) before it may be signed
	 * and subsequently transmitted to the toezichthouder.
	 *
	 * @param string $reportId The QuartaalrapportageFido id (call-signature parity).
	 * @param array<string,mixed>|null $object The rapportage being transitioned.
	 *
	 * @return bool True when the rapportage may be submitted.
	 *
	 * @spec openspec/specs/bookkeeping-wet-fido-treasury/spec.md
	 */
	public function canSubmitRapportage(string $reportId, ?array $object = null): bool {
		try {
			$report = $this->resolveObject(schema: 'QuartaalrapportageFido', id: $reportId, object: $object);
			if ($report === null) {
				return false;
			}

			return $this->isSignOffComplete(signOff: ($report['signOffTreasurer'] ?? null))
				&& $this->isSignOffComplete(signOff: ($report['signOffGroupController'] ?? null));
		} catch (\Throwable $e) {
			$this->logger->error(
				'FidoTreasuryGuard: rapportage submit check failed — denying transition (fail-closed)',
				['reportId' => $reportId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canSubmitRapportage()

	/**
	 * Returns true iff the lening sits inside the adopted Treasurystatuut
	 * signing-mandate matrix (role x instrument x amount), REQ-FDO-001 / D5.
	 *
	 * A matrix row authorises the lening when it matches the signer role, lists the
	 * lening instrument among its permitted instruments, and its maxAmount is either
	 * null (unbounded, e.g. college besluit) or at least the lening principal.
	 *
	 * @param array<string,mixed> $lening The lening being validated.
	 *
	 * @return bool True when a matrix row authorises the lening.
	 */
	private function isWithinSigningMandate(array $lening): bool {
		$role = (string)($lening['signingMandateRole'] ?? '');
		$instrument = (string)($lening['type'] ?? '');
		$principal = $this->toFloat(value: ($lening['principal'] ?? null));
		if ($this->isLeningSignableShape(role: $role, instrument: $instrument, principal: $principal) === false) {
			return false;
		}

		$statuut = $this->resolveAdoptedStatuut(
			organisationId: (string)($lening['organisationId'] ?? ''),
			statuutId: (string)($lening['treasuryStatuteId'] ?? '')
		);
		if ($statuut === null) {
			return false;
		}

		$mandates = $statuut['signingMandates'] ?? [];
		if (is_array($mandates) === false) {
			return false;
		}

		foreach ($mandates as $mandate) {
			if ($this->mandateAuthorises(mandate: $mandate, role: $role, instrument: $instrument, principal: (float)$principal) === true) {
				return true;
			}
		}

		return false;
	}//end isWithinSigningMandate()

	/**
	 * Returns true iff the lening carries the minimal shape needed to be matched
	 * against the signing-mandate matrix (non-empty role + instrument, non-negative
	 * principal).
	 *
	 * @param string $role The signer role on the lening.
	 * @param string $instrument The lening instrument type.
	 * @param float|null $principal The coerced lening principal.
	 *
	 * @return bool True when the lening is shaped for a mandate match.
	 */
	private function isLeningSignableShape(string $role, string $instrument, ?float $principal): bool {
		if ($role === '' || $instrument === '') {
			return false;
		}

		return $principal !== null && $principal >= 0.0;
	}//end isLeningSignableShape()

	/**
	 * Returns true iff a single signing-mandate row authorises the lening.
	 *
	 * @param mixed $mandate The matrix row to test.
	 * @param string $role The signer role on the lening.
	 * @param string $instrument The lening instrument type.
	 * @param float $principal The lening principal.
	 *
	 * @return bool True when this row authorises the lening.
	 */
	private function mandateAuthorises(mixed $mandate, string $role, string $instrument, float $principal): bool {
		if (is_array($mandate) === false) {
			return false;
		}

		if ((string)($mandate['role'] ?? '') !== $role) {
			return false;
		}

		$instruments = $mandate['instruments'] ?? [];
		if (is_array($instruments) === false || in_array($instrument, $instruments, true) === false) {
			return false;
		}

		// A null maxAmount means unbounded authority (e.g. college besluit).
		if (array_key_exists('maxAmount', $mandate) === false || $mandate['maxAmount'] === null) {
			return true;
		}

		$maxAmount = $this->toFloat(value: $mandate['maxAmount']);
		if ($maxAmount === null) {
			return false;
		}

		return $principal <= $maxAmount;
	}//end mandateAuthorises()

	/**
	 * Returns true iff a sign-off record carries a person (REQ-FDO-006).
	 *
	 * @param mixed $signOff The sign-off sub-object to validate.
	 *
	 * @return bool True when the sign-off names a person.
	 */
	private function isSignOffComplete(mixed $signOff): bool {
		if (is_array($signOff) === false) {
			return false;
		}

		return trim((string)($signOff['person'] ?? '')) !== '';
	}//end isSignOffComplete()

	/**
	 * Resolve the adopted Treasurystatuut for an organisation (ADR-022 real API).
	 *
	 * Prefers an explicit statuut id reference on the lening; otherwise queries the
	 * adopted statuut for the organisation. Only an `adopted` statuut is enforced
	 * (pre-adoption drafts do not bind, design D4).
	 *
	 * @param string $organisationId The organisation whose adopted statuut is sought.
	 * @param string $statuutId Explicit statuut FK from the lening, if present.
	 *
	 * @return array<string,mixed>|null The adopted statuut, or null when unavailable.
	 */
	private function resolveAdoptedStatuut(string $organisationId, string $statuutId): ?array {
		$register = $this->resolveRegister();

		if ($statuutId === '' && $organisationId === '') {
			return null;
		}

		$filters = ['status' => 'adopted'];
		if ($statuutId !== '') {
			$filters['id'] = $statuutId;
		}

		if ($statuutId === '' && $organisationId !== '') {
			$filters['organisationId'] = $organisationId;
		}

		$results = $this->objectService
			->setRegister($register)
			->setSchema('Treasurystatuut')
			->findAll(['filters' => $filters]);

		foreach ($results as $result) {
			if (is_array($result) === true && (string)($result['status'] ?? '') === 'adopted') {
				return $result;
			}
		}

		return null;
	}//end resolveAdoptedStatuut()

	/**
	 * Coerce a value to a float, returning null for non-numeric input.
	 *
	 * @param mixed $value The value to coerce.
	 *
	 * @return float|null The float value, or null when not numeric.
	 */
	private function toFloat(mixed $value): ?float {
		if (is_int($value) === true || is_float($value) === true) {
			return (float)$value;
		}

		if (is_string($value) === true && is_numeric($value) === true) {
			return (float)$value;
		}

		return null;
	}//end toFloat()

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
		// The app id is the literal 'shillinq' constant; referencing it directly
		// (rather than Application::APP_ID) avoids coupling this lifecycle guard to
		// the heavy IBootstrap App class on the autoload path.
		$register = $this->appConfig->getValueString('shillinq', 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end resolveRegister()
}//end class
