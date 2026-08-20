<?php

/**
 * Rechtmatigheid Guard
 *
 * ADR-031 exception-path lifecycle guards for the Rechtmatigheidsverantwoording
 * registers (bookkeeping-rechtmatigheidsverantwoording, BBV artikel 17a, T2).
 * Referenced from the register-fragment x-openregister-lifecycle transitions
 * because they require cross-field / cross-schema preconditions that the
 * declarative `requires:` clause cannot yet express:
 *
 *  - canFinaliseToets():       a toets may only move in_behandeling -> getoetst
 *                              when, for a voldoet_niet/onzeker uitkomst, the
 *                              onderbouwing is >= 50 characters and a linked
 *                              rechtmatigheidsbevinding FK is present (REQ-RV-002).
 *  - canResolveBevinding():    a bevinding may only move to opgelost when a
 *                              correctieboeking_id FK is set (REQ-RV-010).
 *  - canVaststellenParagraaf(): a paragraaf may only move concept ->
 *                              vastgesteld_college when, if binnen_tolerantie is
 *                              false, a portefeuillehouder-toelichting
 *                              (verklaring_college) has been authored (REQ-RV-005).
 *  - canExportParagraaf():     gating check used by the jaarrekening-export
 *                              integration — export is only permitted for a
 *                              paragraaf in status definitief (REQ-RV-006).
 *
 * ADR-031 exception reason: conditional cross-field requirements (uitkomst-driven
 * onderbouwing length + FK presence, tolerance-driven toelichting requirement) and
 * FK-presence gates are not yet expressible in the declarative lifecycle DSL. When
 * the engine gains conditional `requires:` predicates, replace these references
 * with declarative conditions and delete this file.
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
 * @spec openspec/specs/bookkeeping-rechtmatigheidsverantwoording/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use Psr\Log\LoggerInterface;

/**
 * Lifecycle precondition guards for the Rechtmatigheidsverantwoording registers.
 *
 * Referenced from lib/Settings/register.d/bookkeeping-rechtmatigheidsverantwoording.json:
 * - Rechtmatigheidstoets.lifecycle.afronden.requires       -> canFinaliseToets
 * - Rechtmatigheidsbevinding.lifecycle.oplossen.requires   -> canResolveBevinding
 * - Rechtmatigheidsparagraaf.lifecycle.vaststellen_college.requires -> canVaststellenParagraaf
 *
 * @spec openspec/specs/bookkeeping-rechtmatigheidsverantwoording/spec.md
 */
class RechtmatigheidGuard {
	/**
	 * Minimum onderbouwing length required for a negative toets uitkomst (REQ-RV-002).
	 */
	private const MIN_ONDERBOUWING_LENGTH = 50;

	/**
	 * Uitkomst values that require a substantiation + linked bevinding (REQ-RV-002).
	 *
	 * @var array<int, string>
	 */
	private const NEGATIVE_UITKOMSTEN = ['voldoet_niet', 'onzeker'];

	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Precondition for the Rechtmatigheidstoets afronden transition (REQ-RV-002).
	 *
	 * When uitkomst is voldoet_niet or onzeker, the toets may only be finalised
	 * when the onderbouwing is at least 50 characters and a rechtmatigheidsbevinding
	 * FK is linked. A voldoet / niet_van_toepassing uitkomst has no such requirement.
	 *
	 * Fail-closed: returns false on any exception (denies transition) per CWE-863.
	 *
	 * @param array<string, mixed> $toets Rechtmatigheidstoets object array loaded by OR.
	 *
	 * @return bool True when the toets may be finalised.
	 *
	 * @spec openspec/specs/bookkeeping-rechtmatigheidsverantwoording/spec.md
	 */
	public function canFinaliseToets(array $toets): bool {
		try {
			$outcome = (string)($toets['outcome'] ?? '');

			if (in_array($outcome, self::NEGATIVE_UITKOMSTEN, true) === false) {
				// Voldoet / niet_van_toepassing: no substantiation gate.
				return true;
			}

			$substantiation = trim((string)($toets['substantiation'] ?? ''));
			if (mb_strlen($substantiation) < self::MIN_ONDERBOUWING_LENGTH) {
				$this->logger->info(
					'RechtmatigheidGuard: onderbouwing too short for negative uitkomst — denying afronden',
					[
						'toetsId' => ($toets['id'] ?? 'unknown'),
						'outcome' => $outcome,
						'length' => mb_strlen($substantiation),
					]
				);
				return false;
			}

			$bevinding = trim((string)($toets['lawfulnessFinding'] ?? ''));
			if ($bevinding === '') {
				$this->logger->info(
					'RechtmatigheidGuard: negative uitkomst without linked bevinding — denying afronden',
					['toetsId' => ($toets['id'] ?? 'unknown'), 'outcome' => $outcome]
				);
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'RechtmatigheidGuard: canFinaliseToets failed — denying afronden (fail-closed)',
				['toetsId' => ($toets['id'] ?? 'unknown'), 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end canFinaliseToets()

	/**
	 * Precondition for the Rechtmatigheidsbevinding oplossen transition (REQ-RV-010).
	 *
	 * A bevinding may only move to opgelost when a correctieboeking_id FK is set,
	 * preserving the audit-trail link between the original fout and its correction.
	 *
	 * Fail-closed: returns false on any exception.
	 *
	 * @param array<string, mixed> $bevinding Rechtmatigheidsbevinding object array.
	 *
	 * @return bool True when the bevinding may be resolved.
	 *
	 * @spec openspec/specs/bookkeeping-rechtmatigheidsverantwoording/spec.md
	 */
	public function canResolveBevinding(array $bevinding): bool {
		try {
			$correction = trim((string)($bevinding['correction_entry_id'] ?? ''));
			if ($correction === '') {
				$this->logger->info(
					'RechtmatigheidGuard: bevinding without correctieboeking_id — denying oplossen',
					['findingNumber' => ($bevinding['findingNumber'] ?? 'unknown')]
				);
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'RechtmatigheidGuard: canResolveBevinding failed — denying oplossen (fail-closed)',
				['findingNumber' => ($bevinding['findingNumber'] ?? 'unknown'), 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end canResolveBevinding()

	/**
	 * Precondition for the Rechtmatigheidsparagraaf vaststellen_college transition (REQ-RV-005).
	 *
	 * When the paragraaf is binnen_tolerantie, college vaststelling needs no extra
	 * toelichting. When NOT binnen_tolerantie, the portefeuillehouder Financiën must
	 * have authored a verklaring_college (toelichting) citing the overages before the
	 * paragraaf may advance.
	 *
	 * Fail-closed: returns false on any exception.
	 *
	 * @param array<string, mixed> $paragraph Rechtmatigheidsparagraaf object array.
	 *
	 * @return bool True when the paragraaf may be vastgesteld by college.
	 *
	 * @spec openspec/specs/bookkeeping-rechtmatigheidsverantwoording/spec.md
	 */
	public function canVaststellenParagraaf(array $paragraph): bool {
		try {
			$binnenTolerance = (bool)($paragraph['within_tolerance'] ?? true);
			if ($binnenTolerance === true) {
				return true;
			}

			$declaration = trim((string)($paragraph['declaration_college'] ?? ''));
			if ($declaration === '') {
				$this->logger->info(
					'RechtmatigheidGuard: paragraaf buiten tolerantie zonder toelichting — denying vaststellen',
					['financialYear' => ($paragraph['financialYear'] ?? 'unknown')]
				);
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'RechtmatigheidGuard: canVaststellenParagraaf failed — denying vaststellen (fail-closed)',
				['financialYear' => ($paragraph['financialYear'] ?? 'unknown'), 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end canVaststellenParagraaf()

	/**
	 * Gating check for jaarrekening-export of a paragraaf (REQ-RV-006).
	 *
	 * Consumed by the bookkeeping-financial-statements export integration: a
	 * paragraaf may only be exported when its status is definitief. A concept /
	 * vastgesteld_college / behandeld_raad paragraaf blocks export.
	 *
	 * Fail-closed: returns false on any exception.
	 *
	 * @param array<string, mixed> $paragraph Rechtmatigheidsparagraaf object array.
	 *
	 * @return bool True when the paragraaf may be exported.
	 *
	 * @spec openspec/specs/bookkeeping-rechtmatigheidsverantwoording/spec.md
	 */
	public function canExportParagraaf(array $paragraph): bool {
		try {
			$status = (string)($paragraph['status'] ?? '');
			if ($status !== 'final') {
				$this->logger->info(
					'RechtmatigheidGuard: paragraaf not definitief — denying jaarrekening-export',
					['financialYear' => ($paragraph['financialYear'] ?? 'unknown'), 'status' => $status]
				);
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'RechtmatigheidGuard: canExportParagraaf failed — denying export (fail-closed)',
				['financialYear' => ($paragraph['financialYear'] ?? 'unknown'), 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end canExportParagraaf()
}//end class
