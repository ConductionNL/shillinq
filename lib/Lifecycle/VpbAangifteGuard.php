<?php

/**
 * Vpb-aangifte Guard
 *
 * ADR-031 exception-path lifecycle guards for the Vpb (vennootschapsbelasting)
 * registers of the bookkeeping-vpb-mkb change (T3). The declarative
 * x-openregister-lifecycle metadata on the VpbAangifte, DefinitieveAanslag and
 * FiscaleEenheid schemas references the methods below for the cross-schema
 * preconditions that the declarative DSL cannot yet express:
 *
 *  - canIndienen():            the linked jaarrekening must be vastgesteld, the
 *                              Belastingplichtige must carry eHerkenning EH3+ and a
 *                              Digipoort-certificaat, and any Innovatiebox claim must
 *                              carry an S&O-verklaring reference, before the aangifte
 *                              may be ingediend (REQ-VPB-002, REQ-VPB-004, REQ-VPB-009).
 *  - canAanslagOntvangen():    the gekoppelde VpbAangifte must be in the ingediend
 *                              state before a DefinitieveAanslag may be marked
 *                              ontvangen (REQ-VPB-010).
 *  - canVoegen():              a FiscaleEenheid voeging requires >=95% bezit,
 *                              gelijke boekjaren and vestiging in NL (REQ-VPB-007).
 *
 * The pure fiscal computations — schijftarief (REQ-VPB-003) and voorvoegingsverlies
 * regime + verjaring (REQ-VPB-006) — live in the sibling
 * OCA\Shillinq\Lifecycle\VpbBerekeningGuard so each class stays focused and below
 * the configured class-complexity threshold.
 *
 * ADR-031 exception reason: cross-schema lookups (jaarrekening vastgesteld-state on
 * a sibling AnnualReport; Innovatiebox S&O-verklaring presence) are not yet
 * expressible in the declarative lifecycle DSL. When the engine gains those
 * capabilities, replace these references with declarative conditions and delete
 * this file. No tax-calculation service class is authored (ADR-022/ADR-031): these
 * are thin, fail-closed precondition guards.
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
 * @spec openspec/changes/bookkeeping-vpb-mkb/specs/bookkeeping-vpb-mkb/spec.md
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
 * Lifecycle precondition guards for the Vpb registers.
 *
 * Referenced from the bookkeeping-vpb-mkb register.d fragment schema lifecycle
 * transitions as OCA\Shillinq\Lifecycle\VpbAangifteGuard::<method>. Every guard
 * fails closed: any exception or malformed input denies the transition (CWE-863).
 *
 * @spec openspec/changes/bookkeeping-vpb-mkb/specs/bookkeeping-vpb-mkb/spec.md
 */
class VpbAangifteGuard {
	/**
	 * Valid accountantsverklaring/jaarrekening states that count as vastgesteld.
	 *
	 * @var array<string>
	 */
	private const VASTGESTELDE_STATES = ['determined', 'gedeponeerd'];

	/**
	 * Aangifte states from which a DefinitieveAanslag may be marked ontvangen.
	 *
	 * @var array<string>
	 */
	private const AANSLAG_TOEGESTANE_STATES = ['submitted', 'aanslag-ontvangen', 'bezwaar', 'beroep', 'onherroepelijk'];

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
	 * Returns true iff the aangifte may transition concept -> ingediend.
	 *
	 * REQ-VPB-002 / REQ-VPB-004 / REQ-VPB-009: indiening is only permitted when
	 *  - the linked jaarrekening (commercieleWinst FK) is vastgesteld,
	 *  - the Belastingplichtige has eHerkenning EH3+ and a Digipoort-certificaat,
	 *  - every Innovatiebox claim on the aangifte carries an S&O-verklaring.
	 *
	 * Fail-closed: returns false on any exception or unresolvable dependency.
	 *
	 * @param string $taxReturnId The VpbAangifte id (call-signature parity).
	 * @param array<string,mixed>|null $object The aangifte being transitioned.
	 *
	 * @return bool True when the aangifte may be ingediend.
	 *
	 * @spec openspec/changes/bookkeeping-vpb-mkb/specs/bookkeeping-vpb-mkb/spec.md
	 */
	public function canIndienen(string $taxReturnId, ?array $object = null): bool {
		try {
			$taxReturn = $object;
			if ($taxReturn === null || isset($taxReturn['taxpayer']) === false) {
				$taxReturn = $this->resolveObject(schema: 'VpbAangifte', id: $taxReturnId);
			}

			if ($taxReturn === null) {
				return false;
			}

			if ($this->annualAccountsDetermined(taxReturn: $taxReturn) === false) {
				return false;
			}

			if ($this->taxpayerDigipoortReady(taxpayerId: (string)($taxReturn['taxpayer'] ?? '')) === false) {
				return false;
			}

			return $this->innovatieboxClaimsHaveSO(taxReturnId: (string)($taxReturn['id'] ?? $taxReturnId));
		} catch (\Throwable $e) {
			$this->logger->error(
				'VpbAangifteGuard: canIndienen check failed — denying indienen transition (fail-closed)',
				['taxReturnId' => $taxReturnId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canIndienen()

	/**
	 * Returns true iff a DefinitieveAanslag may be marked ontvangen.
	 *
	 * REQ-VPB-010: the gekoppelde VpbAangifte must already be in the ingediend
	 * (or later) state. Fail-closed on any exception or missing aangifte.
	 *
	 * @param string $assessmentId The DefinitieveAanslag id (call-signature parity).
	 * @param array<string,mixed>|null $object The aanslag being transitioned.
	 *
	 * @return bool True when the aanslag may be marked ontvangen.
	 *
	 * @spec openspec/changes/bookkeeping-vpb-mkb/specs/bookkeeping-vpb-mkb/spec.md
	 */
	public function canAanslagOntvangen(string $assessmentId, ?array $object = null): bool {
		try {
			$assessment = $object;
			if ($assessment === null || isset($assessment['taxReturn']) === false) {
				$assessment = $this->resolveObject(schema: 'DefinitieveAanslag', id: $assessmentId);
			}

			if ($assessment === null) {
				return false;
			}

			$taxReturn = $this->resolveObject(schema: 'VpbAangifte', id: (string)($assessment['taxReturn'] ?? ''));
			if ($taxReturn === null) {
				return false;
			}

			$status = (string)($taxReturn['status'] ?? '');

			return in_array($status, self::AANSLAG_TOEGESTANE_STATES, true);
		} catch (\Throwable $e) {
			$this->logger->error(
				'VpbAangifteGuard: canAanslagOntvangen check failed — denying transition (fail-closed)',
				['aanslagId' => $assessmentId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canAanslagOntvangen()

	/**
	 * Returns true iff a FiscaleEenheid voeging satisfies article 15 conditions.
	 *
	 * REQ-VPB-007: voeging requires bezitPercentage >= 95, gelijke boekjaren and
	 * vestiging in NL. Fail-closed on any exception.
	 *
	 * @param string $unitId The FiscaleEenheid id (call-signature parity).
	 * @param array<string,mixed>|null $object The eenheid being created/voegen.
	 *
	 * @return bool True when the voeging is permitted.
	 *
	 * @spec openspec/changes/bookkeeping-vpb-mkb/specs/bookkeeping-vpb-mkb/spec.md
	 */
	public function canVoegen(string $unitId, ?array $object = null): bool {
		try {
			$unit = $object;
			if ($unit === null || isset($unit['holdingPercentage']) === false) {
				$unit = $this->resolveObject(schema: 'FiscaleEenheid', id: $unitId);
			}

			if ($unit === null) {
				return false;
			}

			$bezit = (float)($unit['holdingPercentage'] ?? 0);
			$equalFinancialYear = ($unit['equalFinancialYears'] ?? false) === true;
			$establishmentNl = ($unit['establishmentNetherlands'] ?? false) === true;

			return $bezit >= 95.0 && $equalFinancialYear === true && $establishmentNl === true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'VpbAangifteGuard: canVoegen check failed — denying voeging (fail-closed)',
				['eenheidId' => $unitId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canVoegen()

	/**
	 * Returns true iff the aangifte's linked jaarrekening is vastgesteld.
	 *
	 * Looks up the AnnualReport referenced by commercieleWinst and checks its
	 * status against the vastgestelde states. A missing FK denies indiening.
	 *
	 * @param array<string,mixed> $taxReturn The aangifte being transitioned.
	 *
	 * @return bool True when the jaarrekening is vastgesteld.
	 */
	private function annualAccountsDetermined(array $taxReturn): bool {
		$reportId = (string)($taxReturn['commercialProfit'] ?? '');
		if ($reportId === '') {
			return false;
		}

		$report = $this->resolveObject(schema: 'AnnualReport', id: $reportId);
		if ($report === null) {
			return false;
		}

		$status = (string)($report['status'] ?? '');

		return in_array($status, self::VASTGESTELDE_STATES, true);
	}//end jaarrekeningVastgesteld()

	/**
	 * Returns true iff the Belastingplichtige is Digipoort-ready.
	 *
	 * Requires eHerkenningsNiveau EH3+ and a non-empty digipoortCertificaat FK.
	 *
	 * @param string $taxpayerId The Belastingplichtige id.
	 *
	 * @return bool True when eHerkenning EH3+ and a Digipoort cert are present.
	 */
	private function taxpayerDigipoortReady(string $taxpayerId): bool {
		if ($taxpayerId === '') {
			return false;
		}

		$taxpayer = $this->resolveObject(schema: 'Belastingplichtige', id: $taxpayerId);
		if ($taxpayer === null) {
			return false;
		}

		$level = (string)($taxpayer['eRecognitionLevel'] ?? '');
		$cert = (string)($taxpayer['digipoortCertificate'] ?? '');

		return in_array($level, ['EH3', 'EH4'], true) && $cert !== '';
	}//end belastingplichtigeDigipoortReady()

	/**
	 * Returns true iff every Innovatiebox claim on the aangifte carries an S&O ref.
	 *
	 * REQ-VPB-004: an innovatiebox claim is invalid without a soVerklaringReferentie.
	 * An aangifte with zero innovatiebox claims trivially passes.
	 *
	 * @param string $taxReturnId The VpbAangifte id whose claims to check.
	 *
	 * @return bool True when all (or no) innovatiebox claims carry an S&O reference.
	 */
	private function innovatieboxClaimsHaveSO(string $taxReturnId): bool {
		if ($taxReturnId === '') {
			return false;
		}


		$claims = $this->objectService
			->setRegister($this->resolveRegister())
			->setSchema('Innovatiebox')
			->findAll(['filters' => ['taxReturn' => $taxReturnId]]);

		foreach ($claims as $claim) {
			if (is_array($claim) === false) {
				continue;
			}

			if ((string)($claim['soDeclarationReference'] ?? '') === '') {
				return false;
			}
		}

		return true;
	}//end innovatieboxClaimsHaveSO()

	/**
	 * Resolve an object of the given schema by id via ObjectService.
	 *
	 * @param string $schema The OpenRegister schema slug.
	 * @param string $id The object id.
	 *
	 * @return array<string,mixed>|null The object, or null when not found.
	 */
	private function resolveObject(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}


		$records = $this->objectService
			->setRegister($this->resolveRegister())
			->setSchema($schema)
			->findAll(['filters' => ['id' => $id]]);

		foreach ($records as $record) {
			if (is_array($record) === true) {
				return $record;
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
