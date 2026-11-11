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

use OCA\Shillinq\AppInfo\AppIdentity;
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
		$slug = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
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
			AppIdentity::APP_ID,
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
			return self::failure(error: $e);
		}

		$reads = new RgsRegisterReads(objectService: $objectService, registerSlug: $this->getRegisterSlug());
		$threshold = $this->getConfidenceThreshold();

		try {
			$accounts = $reads->accounts(administrationId: $administrationId);
			$rgsRekeningen = $reads->candidates();
		} catch (\Throwable $e) {
			return self::failure(error: $e);
		}

		$rgsRows = array_map(fn ($row) => $this->toArray(object: $row), $rgsRekeningen);

		return $this->suggest(accounts: $accounts, matcher: new RgsCandidateMatcher(rgsRows: $rgsRows, threshold: $threshold));
	}//end suggestMappings()

	/**
	 * One suggestion per unmapped Account whose best candidate reaches the
	 * threshold; already-mapped Accounts are counted as skipped.
	 *
	 * @param array<int, mixed>   $accounts The Account rows.
	 * @param RgsCandidateMatcher $matcher  The candidate matcher.
	 *
	 * @return array{success: bool, suggestions: array<int, array<string, mixed>>, skipped: int} The result.
	 */
	private function suggest(array $accounts, RgsCandidateMatcher $matcher): array {
		$suggestions = [];
		$skipped = 0;

		foreach ($accounts as $account) {
			$accountRow = $this->toArray(object: $account);
			if (empty($accountRow['rgsDecentraalCode']) === false) {
				$skipped++;
				continue;
			}

			$candidate = $matcher->best(accountRow: $accountRow);
			if ($candidate !== null) {
				$suggestions[] = $this->suggestion(accountRow: $accountRow, candidate: $candidate);
			}
		}

		return ['success' => true, 'suggestions' => $suggestions, 'skipped' => $skipped];
	}//end suggest()

	/**
	 * Project one Account and its candidate to a suggestion row.
	 *
	 * @param array<string, mixed>                                           $accountRow The Account.
	 * @param array{row: array<string,mixed>, confidence:int, reason:string} $candidate  Its best candidate.
	 *
	 * @return array<string, mixed> The suggestion row.
	 */
	private function suggestion(array $accountRow, array $candidate): array {
		return [
			'accountId' => (string)($accountRow['id'] ?? $accountRow['uuid'] ?? ''),
			'accountNumber' => (string)($accountRow['code'] ?? $accountRow['accountNumber'] ?? ''),
			'accountName' => (string)($accountRow['name'] ?? ''),
			'candidate' => $candidate['row'],
			'confidence' => $candidate['confidence'],
			'scoringReason' => $candidate['reason'],
		];
	}//end suggestion()

	/**
	 * The result of a failed read.
	 *
	 * @param \Throwable $error The failure.
	 *
	 * @return array{success: bool, suggestions: array<int, mixed>, skipped: int, message: string} The failure result.
	 */
	private static function failure(\Throwable $error): array {
		return ['success' => false, 'suggestions' => [], 'skipped' => 0, 'message' => $error->getMessage()];
	}//end failure()

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
