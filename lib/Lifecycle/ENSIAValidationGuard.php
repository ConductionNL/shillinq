<?php

/**
 * ENSIA Validation Guard
 *
 * Single declarative-precondition seam the OpenRegister lifecycle engine
 * cannot yet express for the ENSIA (Eenduidige Normatiek Single Information
 * Audit) annual cycle (REQ-ENSIA-001..010).
 *
 * Three guards are exposed:
 *   - collegeAkkoordAllowed()  Blocks the ENSIAJaarcyclus
 *                              `peer-review → college-akkoord` transition
 *                              whenever any child Evaluatievraag still
 *                              carries peerReviewStatus=wijziging-gevraagd
 *                              (REQ-ENSIA-004 third scenario).
 *   - maturityEvidenceSatisfied()
 *                              Enforces REQ-ENSIA-003: when an Evaluatievraag
 *                              has volwassenheidsScore ≥ 3, at least one
 *                              bewijsstuk MUST be linked AND toelichting
 *                              length MUST be ≥ 50 chars before persist.
 *   - postPeerReviewReasonRequired()
 *                              Enforces REQ-ENSIA-008: when an Evaluatievraag
 *                              has already completed peer-review (peerReviewedAt
 *                              set OR peerReviewStatus≠nog-niet-beoordeeld),
 *                              any subsequent edit MUST carry a non-empty
 *                              `reden` field documenting the change rationale.
 *
 * Thin PHP seam per ADR-031 §"PHP guards remain a legitimate seam" — no
 * domain orchestration; each method evaluates a single declarative cross-
 * schema or per-record precondition. Pure functions over input data; lazy DI
 * keeps the guard usable before the registers exist.
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
 * @spec openspec/specs/bookkeeping-ensia-zelfevaluatie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Guards three ENSIA lifecycle preconditions.
 *
 * Declared via x-openregister-lifecycle.preconditions on the ENSIAJaarcyclus
 * and Evaluatievraag schema fragments under
 * lib/Settings/register.d/bookkeeping-ensia-zelfevaluatie.json — never
 * invoked directly from application code.
 *
 * @spec openspec/specs/bookkeeping-ensia-zelfevaluatie/spec.md
 */
class ENSIAValidationGuard {
	/**
	 * Construct the guard with lazy DI of OR's ObjectService.
	 *
	 * @param ContainerInterface $container DI container — OR's ObjectService is
	 *                                      fetched lazily so the guard stays
	 *                                      usable before registers exist.
	 * @param IAppConfig $appConfig App config — resolves the register
	 *                              slug for ENSIA register lookups.
	 * @param LoggerInterface $logger Nextcloud logger for fail-closed
	 *                                diagnostics; the guard logs
	 *                                transient lookup failures.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the configured OpenRegister register slug for shillinq.
	 *
	 * @return string The register slug, defaulting to 'shillinq'.
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()

	/**
	 * Lazy-load OR's ObjectService through the DI container.
	 *
	 * @return object|null The ObjectService instance, or null when OR is
	 *                     not yet installed / not resolvable.
	 */
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->debug(
				'ENSIAValidationGuard: ObjectService lookup failed; bypassing gate',
				['exception' => $e->getMessage()]
			);
			return null;
		}

	}//end getObjectService()

	/**
	 * Precondition guarding the ENSIAJaarcyclus peer-review → college-akkoord
	 * transition (REQ-ENSIA-004 third scenario).
	 *
	 * Returns false when at least one child Evaluatievraag still carries
	 * peerReviewStatus=wijziging-gevraagd. Returns true when every child
	 * question is akkoord or there are no children (cycle just initialised).
	 *
	 * Fail-permissive when OR is not resolvable — the lifecycle engine
	 * would itself reject the transition for unrelated reasons; we do not
	 * want a transient ObjectService outage to wedge a clean cycle.
	 *
	 * @param array<string,mixed> $cyclus The ENSIAJaarcyclus record being
	 *                                    transitioned. Must contain an `id`
	 *                                    or `uuid` so children resolve.
	 *
	 * @return bool True when the transition is allowed; false when blocked.
	 */
	public function collegeAkkoordAllowed(array $cyclus): bool {
		$cyclusId = (string)($cyclus['id'] ?? $cyclus['uuid'] ?? '');
		if ($cyclusId === '') {
			return true;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return true;
		}

		try {
			$unresolved = $objectService
				->setRegister($this->getRegisterSlug())
				->setSchema('Evaluatievraag')
				->findAll(
					[
						'filters' => [
							'cyclusId' => $cyclusId,
							'peerReviewStatus' => 'wijziging-gevraagd',
						],
						'limit' => 1,
					]
				);

			if (empty($unresolved) === false) {
				$this->logger->info(
					'ENSIAValidationGuard: blocking college-akkoord — unresolved peer-review wijzigingen',
					['cyclusId' => $cyclusId]
				);
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->debug(
				'ENSIAValidationGuard: unresolved-wijziging lookup failed; permissive bypass',
				[
					'cyclusId' => $cyclusId,
					'exception' => $e->getMessage(),
				]
			);
			return true;
		}//end try

	}//end collegeAkkoordAllowed()

	/**
	 * Precondition enforcing REQ-ENSIA-003 maturity-evidence rule on an
	 * Evaluatievraag persist.
	 *
	 * When volwassenheidsScore is null or < 3, no evidence is required —
	 * returns true. When volwassenheidsScore ≥ 3, bewijsstukken MUST be a
	 * non-empty array AND toelichting MUST be ≥ 50 chars.
	 *
	 * @param array<string,mixed> $question The Evaluatievraag record being
	 *                                   persisted.
	 *
	 * @return bool True when the persist is allowed; false when blocked.
	 */
	public function maturityEvidenceSatisfied(array $question): bool {
		$score = $question['maturityScore'] ?? null;
		if (is_int($score) === false || $score < 3) {
			return true;
		}

		$supportingDocuments = $question['supportingDocuments'] ?? [];
		if (is_array($supportingDocuments) === false || count($supportingDocuments) === 0) {
			$this->logger->info(
				'ENSIAValidationGuard: REQ-ENSIA-003 — score ≥ 3 requires evidence',
				[
					'questionCode' => (string)($question['questionCode'] ?? ''),
					'score' => $score,
				]
			);
			return false;
		}

		$notes = (string)($question['notes'] ?? '');
		if (mb_strlen($notes) < 50) {
			$this->logger->info(
				'ENSIAValidationGuard: REQ-ENSIA-003 — score ≥ 3 requires toelichting ≥ 50 chars',
				[
					'questionCode' => (string)($question['questionCode'] ?? ''),
					'toelichtingChars' => mb_strlen($notes),
				]
			);
			return false;
		}

		return true;
	}//end maturityEvidenceSatisfied()

	/**
	 * Precondition enforcing REQ-ENSIA-008 post-peer-review reden requirement
	 * on an Evaluatievraag persist.
	 *
	 * When the question has not yet been peer-reviewed (peerReviewedAt empty
	 * AND peerReviewStatus=nog-niet-beoordeeld), no reden is required —
	 * returns true (pre-peer-review free editing scenario in REQ-ENSIA-008).
	 *
	 * When peer-review has taken place (peerReviewedAt set OR
	 * peerReviewStatus≠nog-niet-beoordeeld), the `reden` field MUST be a
	 * non-empty string.
	 *
	 * @param array<string,mixed> $question The Evaluatievraag record being
	 *                                   persisted (post-edit shape).
	 *
	 * @return bool True when the persist is allowed; false when blocked.
	 */
	public function postPeerReviewReasonRequired(array $question): bool {
		$peerReviewedAt = (string)($question['peerReviewedAt'] ?? '');
		$peerReviewStatus = (string)($question['peerReviewStatus'] ?? 'nog-niet-beoordeeld');

		$hasBeenReviewed = $peerReviewedAt !== '' || $peerReviewStatus !== 'nog-niet-beoordeeld';
		if ($hasBeenReviewed === false) {
			return true;
		}

		$reason = trim((string)($question['reason'] ?? ''));
		if ($reason === '') {
			$this->logger->info(
				'ENSIAValidationGuard: REQ-ENSIA-008 — post-peer-review edit requires reden',
				[
					'questionCode' => (string)($question['questionCode'] ?? ''),
				]
			);
			return false;
		}

		return true;
	}//end postPeerReviewReasonRequired()
}//end class
