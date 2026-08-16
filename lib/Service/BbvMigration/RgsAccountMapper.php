<?php

/**
 * RGS-decentraal Account Mapping Backfill Service
 *
 * Backfills pre-existing `Account` records of a BBV-tenant with RGS-decentraal
 * codes by scoring candidate matches and surfacing them for operator approval
 * (Task 3.9, REQ-BBV-001/002/006). The service is read-only with respect to
 * Account records: it produces suggestion rows that the manifest "RGS-mapping
 * approval" page consumes; the operator's decision is what actually mutates
 * the Account.rgsDecentraalCode + Account.taakveld + Account.economischeCategorie
 * fields via the regular OR PUT.
 *
 * Scoring inputs (descending weight):
 *
 *   1. Exact `referentienummer` equality between Account and RgsDecentraalRekening
 *      (RGS reference number → 100% confidence).
 *   2. Exact `code` equality between Account.code and RgsDecentraalRekening.rgsDecentraalCode
 *      (operator pre-loaded the decentraal code directly → 95%).
 *   3. Account.code matches RgsDecentraalRekening.rgsCode (legacy direct → 80%).
 *   4. Levenshtein distance between Account.name and omschrijvingKort
 *      (fuzzy → max 70% scaled by similarity).
 *
 * No HTTP calls; pure in-process matching against the OR ObjectService. Confidence
 * threshold defaults to 70 (configurable via shillinq's app config key
 * `bbv_rgs_mapper_confidence_threshold`).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\BbvMigration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\BbvMigration;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;

/**
 * Produces RGS-decentraal mapping suggestions for unmapped Account records.
 *
 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Pre-existing debt (issue
 *     #506): inherent branch complexity in this domain logic; deferred
 *     pending a dedicated refactor.
 */
class RgsAccountMapper {
	/**
	 * Default confidence threshold (percentage) below which a suggestion is
	 * dropped from the operator-review queue.
	 *
	 * @var int
	 */
	private const DEFAULT_CONFIDENCE_THRESHOLD = 70;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container — ObjectService fetched lazily.
	 * @param IAppConfig $appConfig App config for register slug + threshold override.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Resolve the configured register slug (falls back to 'shillinq').
	 *
	 * @return string
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()

	/**
	 * Resolve the confidence threshold for surfacing a suggestion.
	 *
	 * @return int
	 */
	private function getConfidenceThreshold(): int {
		$value = $this->appConfig->getValueString(
			Application::APP_ID,
			'bbv_rgs_mapper_confidence_threshold',
			(string)self::DEFAULT_CONFIDENCE_THRESHOLD
		);

		$threshold = (int)$value;
		if ($threshold < 0 || $threshold > 100) {
			return self::DEFAULT_CONFIDENCE_THRESHOLD;
		}

		return $threshold;
	}//end getConfidenceThreshold()

	/**
	 * Produce mapping suggestions for every unmapped Account in the given administration.
	 *
	 * Account is considered "unmapped" when `rgsDecentraalCode` is empty / missing.
	 *
	 * @param string $administrationId Administration identifier to backfill.
	 *
	 * @return array{
	 *     success: bool,
	 *     suggestions: array<int, array{
	 *         accountId: string,
	 *         accountNumber: string,
	 *         accountName: string,
	 *         candidate: array<string, mixed>|null,
	 *         confidence: int,
	 *         scoringReason: string,
	 *     }>,
	 *     skipped: int,
	 *     message?: string,
	 * }
	 */
	public function suggestMappings(string $administrationId): array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			return ['success' => false, 'suggestions' => [], 'skipped' => 0, 'message' => $e->getMessage()];
		}

		$registerSlug = $this->getRegisterSlug();
		$threshold = $this->getConfidenceThreshold();

		try {
			$accounts = $objectService
				->setRegister($registerSlug)
				->setSchema('Account')
				->findAll(
					[
						'filters' => ['administrationId' => $administrationId],
						'limit' => 5000,
					]
				);
		} catch (\Throwable $e) {
			return ['success' => false, 'suggestions' => [], 'skipped' => 0, 'message' => $e->getMessage()];
		}

		try {
			$rgsRekeningen = $objectService
				->setRegister($registerSlug)
				->setSchema('RgsDecentraalRekening')
				->findAll(['limit' => 5000]);
		} catch (\Throwable $e) {
			// Fall back to the BbvAccountMapping register if RgsDecentraalRekening is not yet declared.
			try {
				$rgsRekeningen = $objectService
					->setRegister($registerSlug)
					->setSchema('BbvAccountMapping')
					->findAll(['limit' => 5000]);
			} catch (\Throwable $f) {
				return ['success' => false, 'suggestions' => [], 'skipped' => 0, 'message' => $f->getMessage()];
			}
		}

		$rgsRows = array_map(fn ($row) => $this->toArray(object: $row), $rgsRekeningen);

		$suggestions = [];
		$skipped = 0;

		foreach ($accounts as $account) {
			$accountRow = $this->toArray(object: $account);

			if (empty($accountRow['rgsDecentraalCode']) === false) {
				$skipped++;
				continue;
			}

			$candidate = $this->bestCandidate(accountRow: $accountRow, rgsRows: $rgsRows);

			if ($candidate === null || $candidate['confidence'] < $threshold) {
				continue;
			}

			$suggestions[] = [
				'accountId' => (string)($accountRow['id'] ?? $accountRow['uuid'] ?? ''),
				'accountNumber' => (string)($accountRow['code'] ?? $accountRow['accountNumber'] ?? ''),
				'accountName' => (string)($accountRow['name'] ?? ''),
				'candidate' => $candidate['row'],
				'confidence' => $candidate['confidence'],
				'scoringReason' => $candidate['reason'],
			];
		}//end foreach

		return ['success' => true, 'suggestions' => $suggestions, 'skipped' => $skipped];
	}//end suggestMappings()

	/**
	 * Pick the highest-scoring RGS-decentraal candidate for an Account.
	 *
	 * @param array<string,mixed> $accountRow Account associative array.
	 * @param array<int, array<string, mixed>> $rgsRows RGS-decentraal candidates.
	 *
	 * @return array{row: array<string,mixed>, confidence:int, reason:string}|null
	 */
	private function bestCandidate(array $accountRow, array $rgsRows): ?array {
		$accountReference = (string)($accountRow['referentienummer'] ?? '');
		$accountCode = (string)($accountRow['code'] ?? $accountRow['accountNumber'] ?? '');
		$accountName = mb_strtolower((string)($accountRow['name'] ?? ''));

		$best = null;

		foreach ($rgsRows as $rgsRow) {
			$confidence = 0;
			$reason = '';

			if ($accountReference !== '' && (string)($rgsRow['referentienummer'] ?? '') === $accountReference) {
				$confidence = 100;
				$reason = 'exact-referentienummer';
			} elseif ($accountCode !== '' && (string)($rgsRow['rgsDecentraalCode'] ?? '') === $accountCode) {
				$confidence = 95;
				$reason = 'exact-rgsDecentraalCode';
			} elseif ($accountCode !== '' && (string)($rgsRow['rgsCode'] ?? '') === $accountCode) {
				$confidence = 80;
				$reason = 'exact-rgsCode';
			} elseif ($accountName !== '') {
				$candidateName = mb_strtolower((string)($rgsRow['omschrijvingKort'] ?? $rgsRow['name'] ?? ''));
				if ($candidateName !== '') {
					$similarity = $this->similarityPercent(left: $accountName, right: $candidateName);
					if ($similarity >= 50) {
						$confidence = (int)round($similarity * 0.70);
						$reason = 'fuzzy-name-match-' . ((int)$similarity) . '%';
					}
				}
			}

			if ($confidence === 0) {
				continue;
			}

			if ($best === null || $confidence > $best['confidence']) {
				$best = ['row' => $rgsRow, 'confidence' => $confidence, 'reason' => $reason];
				if ($confidence >= 100) {
					break;
				}
			}
		}//end foreach

		return $best;
	}//end bestCandidate()

	/**
	 * Compute name similarity as a percentage 0-100 using PHP's similar_text.
	 *
	 * @param string $left Left string.
	 * @param string $right Right string.
	 *
	 * @return float Percentage similarity.
	 */
	private function similarityPercent(string $left, string $right): float {
		$percent = 0.0;
		similar_text($left, $right, $percent);

		return $percent;
	}//end similarityPercent()

	/**
	 * Normalise a heterogeneous OR object into an associative array.
	 *
	 * @param mixed $object Object or array returned by OR ObjectService.
	 *
	 * @return array<string,mixed>
	 */
	private function toArray($object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true) {
			if (method_exists($object, 'jsonSerialize') === true) {
				$payload = $object->jsonSerialize();
				if (is_array($payload) === true) {
					return $payload;
				}
			}

			return (array)$object;
		}

		return [];
	}//end toArray()
}//end class
