<?php

/**
 * Reconciliation Resolution Service
 *
 * T4 bookkeeping-reconciliation-reports — encapsulates the
 * REQ-REC-004 unmatched-item resolution write path used by
 * ReconciliationResolutionController. Pre-checks the parent
 * BankReconciliation is open (not closed/cancelled) and belongs to an
 * administration the caller can access, persists the resolution
 * classification, reason and actor onto the ReconciliationMatch via
 * OpenRegister's ObjectService, and emits an audit-trail event so the
 * resolution is permanently traceable per ADR-022.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DomainException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use OutOfBoundsException;
use Psr\Log\LoggerInterface;

/**
 * Persists REQ-REC-004 resolutions onto ReconciliationMatch records and
 * audit-trails the action against the parent BankReconciliation.
 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md (REQ-REC-004)
 */
class ReconciliationResolutionService {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config for register slug.
	 * @param LoggerInterface $logger Logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's published
	 *                                             object surface (ADR-084),
	 *                                             aliased in Application.php.
	 * @param AdministrationContextService $administrations The caller's administrations.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly AdministrationContextService $administrations,
	) {
	}//end __construct()

	/**
	 * Resolve one ReconciliationMatch by classifying it per REQ-REC-004.
	 *
	 * @param string $reconId The parent BankReconciliation id.
	 * @param string $matchId The ReconciliationMatch id.
	 * @param string $resolutionStatus One of matched/timing/pending/adjustment
	 *                                 (validated by the caller).
	 * @param string $resolutionReason Operator-supplied reason text
	 *                                 (audit-trailed).
	 * @param string $actor Nextcloud UID of the operator.
	 *
	 * @return array<string,mixed> The updated ReconciliationMatch as
	 *                             returned by OR.
	 *
	 * @throws OutOfBoundsException When the match or parent does not exist.
	 * @throws DomainException When the parent reconciliation is
	 *                         closed/cancelled (locked) per REQ-REC-003.
	 * @throws \Throwable On any OR/service error.
	 *
	 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md (REQ-REC-004)
	 */
	public function resolveMatch(
		string $reconId,
		string $matchId,
		string $resolutionStatus,
		string $resolutionReason,
		string $actor,
	): array {
		$register = $this->getRegisterSlug();

		// Load + validate the parent reconciliation.
		try {
			$parent = $this->objectService
				->setRegister($register)
				->setSchema('BankReconciliation')
				->find($reconId);
			$parent = $this->toArray(result: $parent);
		} catch (\Throwable $e) {
			throw new OutOfBoundsException(
				'reconciliation ' . $reconId . ' not found',
				0,
				$e
			);
		}

		if ($parent === null
			|| $this->administrations->canAccess(administrationId: (string)($parent['administrationId'] ?? '')) === false
		) {
			throw new OutOfBoundsException('reconciliation ' . $reconId . ' not found');
		}

		$parentStatus = (string)($parent['reconciliationStatus'] ?? 'draft');
		if (in_array($parentStatus, ['closed', 'cancelled'], true) === true) {
			throw new DomainException(
				'reconciliation is ' . $parentStatus . ' — resolutions are not permitted'
			);
		}

		// Load + validate the match record.
		try {
			$match = $this->objectService
				->setRegister($register)
				->setSchema('ReconciliationMatch')
				->find($matchId);
			$match = $this->toArray(result: $match);
		} catch (\Throwable $e) {
			throw new OutOfBoundsException(
				'match ' . $matchId . ' not found',
				0,
				$e
			);
		}

		if ($match === null) {
			throw new OutOfBoundsException('match ' . $matchId . ' not found');
		}

		// Verify the match belongs to the recon (REQ-REC-004 + IDOR guard).
		$matchReconId = (string)($match['reconId'] ?? '');
		if ($matchReconId !== $reconId) {
			throw new OutOfBoundsException(
				'match ' . $matchId . ' does not belong to reconciliation ' . $reconId
			);
		}

		$updated = $this->objectService
			->setRegister($register)
			->setSchema('ReconciliationMatch')
			// PatchObject merges; updateObject would replace the match with
			// these four keys and erase its line, targets and amount.
			->patchObject(
				$matchId,
				[
					'resolutionStatus' => $resolutionStatus,
					'resolutionReason' => $resolutionReason,
					'resolvedBy' => $actor,
					'matchedAt' => gmdate('Y-m-d\TH:i:s\Z'),
				]
			);

		$this->logger->info(
			'ReconciliationResolutionService: REQ-REC-004 resolution applied',
			[
				'reconId' => $reconId,
				'matchId' => $matchId,
				'resolutionStatus' => $resolutionStatus,
				'actor' => $actor,
			]
		);

		return $this->toArray(result: $updated) ?? [];
	}//end resolveMatch()

	/**
	 * Resolve several matches, each under the reconciliation it belongs to.
	 *
	 * The caller sends match ids only; the reconciliation is read from each
	 * match, so a selection may span reconciliations. A match without a
	 * reconciliation is refused, not adopted.
	 *
	 * @param list<string> $matchIds         The ReconciliationMatch ids.
	 * @param string       $resolutionStatus One of matched/timing/pending/adjustment.
	 * @param string       $resolutionReason The shared reason.
	 * @param string       $actor            Nextcloud UID of the operator.
	 *
	 * @return array{applied: int, failed: array<string, \Throwable>}
	 *
	 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md (REQ-REC-004)
	 */
	public function resolveMatches(array $matchIds, string $resolutionStatus, string $resolutionReason, string $actor): array {
		$applied = 0;
		$failed  = [];
		foreach ($matchIds as $matchId) {
			try {
				$this->resolveMatch(
					reconId: $this->reconIdOf(matchId: $matchId),
					matchId: $matchId,
					resolutionStatus: $resolutionStatus,
					resolutionReason: $resolutionReason,
					actor: $actor,
				);
				$applied++;
			} catch (\Throwable $e) {
				$failed[$matchId] = $e;
			}
		}

		return ['applied' => $applied, 'failed' => $failed];

	}//end resolveMatches()

	/**
	 * Return the reconciliation a match belongs to.
	 *
	 * @param string $matchId The ReconciliationMatch id.
	 *
	 * @return string The BankReconciliation id.
	 *
	 * @throws OutOfBoundsException When the match is missing or has no reconciliation.
	 */
	private function reconIdOf(string $matchId): string {
		try {
			$match = $this->toArray(
				result: $this->objectService
					->setRegister($this->getRegisterSlug())
					->setSchema('ReconciliationMatch')
					->find($matchId)
			);
		} catch (\Throwable $e) {
			throw new OutOfBoundsException('match ' . $matchId . ' not found', 0, $e);
		}

		$reconId = trim((string)($match['reconId'] ?? ''));
		if ($reconId === '') {
			throw new OutOfBoundsException('match ' . $matchId . ' not found');
		}

		return $reconId;

	}//end reconIdOf()

	/**
	 * Return the configured register slug, falling back to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()

	/**
	 * Normalise an OR find/update result to a plain array.
	 *
	 * @param mixed $result The OR return value.
	 *
	 * @return array<string,mixed>|null
	 */
	private function toArray(mixed $result): ?array {
		if (is_array($result) === true) {
			return $result;
		}

		if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
			$serialized = $result->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}

			return null;
		}

		return null;
	}//end toArray()
}//end class
