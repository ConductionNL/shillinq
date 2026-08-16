<?php

/**
 * Intercompany Matching Service
 *
 * Orchestrates the intercompany elimination engine over the real OpenRegister
 * ObjectService API (find/findAll/saveObject only — findObject/createFromArray are
 * not part of the API per ADR-022). Implements detection of intercompany
 * transactions (REQ-ICE-002), per-relation per-period matching (REQ-ICE-003),
 * tolerance-driven match-status determination (REQ-ICE-004), balanced
 * elimination-journal generation (REQ-ICE-006), counterparty-balance refresh
 * (REQ-ICE-007), cross-period roll-forward consistency (REQ-ICE-008) and
 * multi-currency conversion (REQ-ICE-009). Pure arithmetic and classification live
 * in IntercompanyMatchingCalculator; this class performs only I/O orchestration.
 *
 * ADR-031 note: matching and counterparty roll-up are declared as
 * x-openregister-aggregations on their schemas. This service is the bounded
 * exception-path implementation for the parts the declarative aggregation engine
 * cannot yet express (multi-source per-side conditional sums, FX conversion,
 * cross-period carry, scheduled re-match orchestration). When the OpenRegister
 * aggregation engine matures, the per-side sums move to declarative queries.
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
 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-15
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Orchestrating service for the intercompany elimination engine.
 *
 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-15
 */
class IntercompanyMatchingService {
	/**
	 * Construct the service with lazy DI of OpenRegister's ObjectService.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param IntercompanyMatchingCalculator $calculator Pure-logic arithmetic + classification helper.
	 * @param LoggerInterface $logger Logger for diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IntercompanyMatchingCalculator $calculator,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Return the configured register slug, falling back to 'shillinq' if unset.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end register()

	/**
	 * Resolve OpenRegister's ObjectService from the container.
	 *
	 * @return object The ObjectService instance.
	 */
	private function objectService(): object {
		return $this->objectService;
	}//end objectService()

	/**
	 * Detect the detection method + confidence for a source line (REQ-ICE-002).
	 *
	 * Account-based (the line's GL account matches one of the relation's default
	 * IC accounts) yields high confidence. Label-based (the counterparty name
	 * matches a group entity but no IC account) yields medium confidence and is
	 * routed to the review queue. Explicit operator marking yields high confidence.
	 *
	 * @param array<string,mixed> $line The source GL line.
	 * @param array<int,string> $icAccounts Registered IC GL accounts for the group.
	 * @param array<string,string> $entityNames entity name => entity id, for label matching.
	 * @param bool $explicit Whether the operator explicitly marked the line.
	 *
	 * @return array{detectionMethod:string,detectionConfidence:string,counterpartyEntityId:?string}
	 *
	 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-15
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The explicit-mark flag is an
	 * intrinsic detection input (REQ-ICE-002 explicitly-marked path); splitting it
	 * into separate methods would duplicate the shared counterparty resolution.
	 */
	public function classifyDetection(
		array $line,
		array $icAccounts,
		array $entityNames,
		bool $explicit = false,
	): array {
		if ($explicit === true) {
			return [
				'detectionMethod' => 'explicitly-marked',
				'detectionConfidence' => 'high',
				'counterpartyEntityId' => $this->matchCounterpartyByName(line: $line, entityNames: $entityNames),
			];
		}

		$account = (string)($line['glAccount'] ?? '');
		if ($account !== '' && in_array($account, $icAccounts, true) === true) {
			return [
				'detectionMethod' => 'account-based',
				'detectionConfidence' => 'high',
				'counterpartyEntityId' => $this->matchCounterpartyByName(line: $line, entityNames: $entityNames),
			];
		}

		$counterparty = $this->matchCounterpartyByName(line: $line, entityNames: $entityNames);
		if ($counterparty !== null) {
			return [
				'detectionMethod' => 'label-based',
				'detectionConfidence' => 'medium',
				'counterpartyEntityId' => $counterparty,
			];
		}

		return [
			'detectionMethod' => 'label-based',
			'detectionConfidence' => 'low',
			'counterpartyEntityId' => null,
		];

	}//end classifyDetection()

	/**
	 * Match a line's counterparty name against the group entity names (REQ-ICE-002).
	 *
	 * @param array<string,mixed> $line The source GL line.
	 * @param array<string,string> $entityNames entity name => entity id.
	 *
	 * @return string|null The matched entity id, or null when no name matched.
	 */
	private function matchCounterpartyByName(array $line, array $entityNames): ?string {
		$needle = strtolower(trim((string)($line['counterpartyName'] ?? ($line['description'] ?? ''))));
		if ($needle === '') {
			return null;
		}

		foreach ($entityNames as $name => $entityId) {
			$candidate = strtolower(trim((string)$name));
			if ($candidate !== '' && str_contains($needle, $candidate) === true) {
				return $entityId;
			}
		}

		return null;
	}//end matchCounterpartyByName()

	/**
	 * Match one relation for one period and persist an IntercompanyMatch (REQ-ICE-003).
	 *
	 * Reads the relation, fetches its detected IntercompanyTransaction rows for both
	 * sides and period, aggregates each side (with FX conversion when rates are
	 * supplied), evaluates tolerance, determines the match status, and persists the
	 * match via the real saveObject API. On a within-tolerance or perfect match a
	 * balanced EliminationJournal is generated; otherwise an IntercompanyMismatch is
	 * created in the exception queue.
	 *
	 * @param string $relationId The IntercompanyRelation id.
	 * @param string $periodId The consolidation period id.
	 * @param array<string,float> $rates Optional currency => reporting-currency rates.
	 *
	 * @return array<string,mixed> The persisted match payload.
	 *
	 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-12
	 */
	public function matchRelationPeriod(string $relationId, string $periodId, array $rates = []): array {
		$objectService = $this->objectService();
		$register = $this->register();

		$relations = $objectService
			->setRegister($register)
			->setSchema('IntercompanyRelation')
			->findAll(['filters' => ['relationId' => $relationId]]);
		$relation = ($relations[0] ?? []);

		$administrationId = (string)($relation['administrationId'] ?? '');
		$entityAId = (string)($relation['entityAId'] ?? '');
		$entityBId = (string)($relation['entityBId'] ?? '');

		$transactions = $objectService
			->setRegister($register)
			->setSchema('IntercompanyTransaction')
			->findAll(['filters' => ['relationId' => $relationId]]);

		$split = $this->splitSides(transactions: $transactions, entityAId: $entityAId, entityBId: $entityBId);
		$sideA = $split['sideA'];
		$sideB = $split['sideB'];
		$idsA = $split['idsA'];
		$idsB = $split['idsB'];
		$hasFx = $split['hasFx'];

		$totalACents = $this->calculator->sumSideCents(transactions: $sideA, rates: $rates);
		$totalBCents = $this->calculator->sumSideCents(transactions: $sideB, rates: $rates);
		$mismatchC = $this->calculator->mismatchCents(totalACents: $totalACents, totalBCents: $totalBCents);
		$mismatchPct = $this->calculator->mismatchPercentage(totalACents: $totalACents, totalBCents: $totalBCents);

		$within = $this->calculator->isWithinTolerance(
			mismatchCents: $mismatchC,
			mismatchPercentage: $mismatchPct,
			toleranceAbsolute: (float)($relation['toleranceAbsolute'] ?? 10.0),
			toleranceRelative: (float)($relation['toleranceRelative'] ?? 0.5),
			method: 'max-of-absolute-relative'
		);

		$status = $this->calculator->matchStatus(
			totalACents: $totalACents,
			totalBCents: $totalBCents,
			withinTolerance: $within
		);

		$match = [
			'matchId' => 'match-' . $relationId . '-' . $periodId,
			'periodId' => $periodId,
			'relationId' => $relationId,
			'entityATransactionIds' => $idsA,
			'entityBTransactionIds' => $idsB,
			'totalAmountA' => $this->calculator->fromCents(cents: $totalACents),
			'totalAmountB' => $this->calculator->fromCents(cents: $totalBCents),
			'mismatchAmount' => $this->calculator->fromCents(cents: $mismatchC),
			'mismatchPercentage' => $mismatchPct,
			'matchStatus' => $status,
			'administrationId' => $administrationId,
		];

		// REQ-ICE-008: warn on roll-forward inconsistency before persisting the match.
		$priorPeriodId = '';
		$existingMatches = $objectService
			->setRegister($register)
			->setSchema('IntercompanyMatch')
			->findAll(['filters' => ['relationId' => $relationId]]);
		foreach ($existingMatches as $existingMatch) {
			$candidate = (string)($existingMatch['periodId'] ?? '');
			if ($candidate === $periodId || $candidate === '') {
				continue;
			}

			if ($priorPeriodId === '' || strcmp($candidate, $priorPeriodId) > 0) {
				$priorPeriodId = $candidate;
			}
		}//end foreach

		$rollForwardOk = $priorPeriodId === ''
			|| $this->isRollForwardConsistent(
				relationId: $relationId,
				priorPeriodId: $priorPeriodId,
				currentPeriodId: $periodId
			);
		if ($rollForwardOk === false) {
			$this->logger->warning(
				'IntercompanyMatchingService: roll-forward inconsistency — possible backdated change (REQ-ICE-008)',
				['relationId' => $relationId, 'priorPeriodId' => $priorPeriodId, 'currentPeriodId' => $periodId]
			);
		}

		$persisted = $objectService
			->setRegister($register)
			->setSchema('IntercompanyMatch')
			->saveObject($match);

		if ($status === 'perfect-match' || $status === 'within-tolerance') {
			$this->generateElimination(
				match: $match,
				relation: $relation,
				totalACents: $totalACents,
				totalBCents: $totalBCents
			);
		} elseif ($status === 'outside-tolerance') {
			$this->raiseMismatch(match: $match, hasFx: $hasFx);
		}

		if (is_array($persisted) === true) {
			return $persisted;
		}

		return $match;
	}//end matchRelationPeriod()

	/**
	 * Split a relation's transactions into A-side and B-side buckets (REQ-ICE-003).
	 *
	 * Transactions whose sourceAdministrationId equals entity A land on side A, those
	 * matching entity B land on side B; ids are collected per side and a currency flag
	 * is raised when any transaction is non-EUR (for downstream FX classification).
	 *
	 * @param array<int,array<string,mixed>> $transactions The relation's transactions.
	 * @param string $entityAId Entity A administration id.
	 * @param string $entityBId Entity B administration id.
	 *
	 * @return array{sideA:array<int,array<string,mixed>>,sideB:array<int,array<string,mixed>>,idsA:array<int,string>,idsB:array<int,string>,hasFx:bool}
	 *
	 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-15
	 */
	private function splitSides(array $transactions, string $entityAId, string $entityBId): array {
		$sideA = [];
		$sideB = [];
		$idsA = [];
		$idsB = [];
		$hasFx = false;
		foreach ($transactions as $transaction) {
			$source = (string)($transaction['sourceAdministrationId'] ?? '');
			$id = (string)($transaction['id'] ?? ($transaction['@self']['id'] ?? ''));
			if ((string)($transaction['currency'] ?? 'EUR') !== 'EUR') {
				$hasFx = true;
			}

			if ($source === $entityAId) {
				$sideA[] = $transaction;
				$idsA[] = $id;
				continue;
			}

			if ($source === $entityBId) {
				$sideB[] = $transaction;
				$idsB[] = $id;
			}
		}//end foreach

		return [
			'sideA' => $sideA,
			'sideB' => $sideB,
			'idsA' => $idsA,
			'idsB' => $idsB,
			'hasFx' => $hasFx,
		];

	}//end splitSides()

	/**
	 * Generate and persist a balanced EliminationJournal for a match (REQ-ICE-006).
	 *
	 * @param array<string,mixed> $match The persisted match payload.
	 * @param array<string,mixed> $relation The IntercompanyRelation.
	 * @param int $totalACents Side A net total in cents.
	 * @param int $totalBCents Side B net total in cents.
	 *
	 * @return array<string,mixed> The persisted elimination payload.
	 *
	 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-16
	 */
	private function generateElimination(array $match, array $relation, int $totalACents, int $totalBCents): array {
		$built = $this->calculator->buildEliminationLines(
			totalACents: $totalACents,
			totalBCents: $totalBCents,
			accountA: (string)($relation['defaultAccountA'] ?? ''),
			accountB: (string)($relation['defaultAccountB'] ?? ''),
			description: 'IC elimination ' . (string)($match['relationId'] ?? '')
		);

		$description = 'IC elimination';
		if ($built !== []) {
			$description = 'IC elimination ' . (string)($match['relationId'] ?? '');
		}

		$elimination = [
			'eliminationId' => 'elim-' . (string)($match['matchId'] ?? ''),
			'consolidationPeriodId' => (string)($match['periodId'] ?? ''),
			'matchId' => (string)($match['matchId'] ?? ''),
			'bookingDate' => date('Y-m-d'),
			'description' => $description,
			'lines' => $built['lines'],
			'totalDebit' => $built['totalDebit'],
			'totalCredit' => $built['totalCredit'],
			'generatedBy' => 'system',
			'administrationId' => (string)($match['administrationId'] ?? ''),
		];

		$persisted = $this->objectService()
			->setRegister($this->register())
			->setSchema('EliminationJournal')
			->saveObject($elimination);

		if (is_array($persisted) === true) {
			return $persisted;
		}

		return $elimination;
	}//end generateElimination()

	/**
	 * Create an IntercompanyMismatch exception for an outside-tolerance match (REQ-ICE-005).
	 *
	 * @param array<string,mixed> $match The persisted match payload.
	 * @param bool $hasFx Whether the match involved a currency difference.
	 *
	 * @return array<string,mixed> The persisted mismatch payload.
	 *
	 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-17
	 */
	private function raiseMismatch(array $match, bool $hasFx): array {
		$mismatch = [
			'mismatchId' => 'mismatch-' . (string)($match['matchId'] ?? ''),
			'periodId' => (string)($match['periodId'] ?? ''),
			'relationId' => (string)($match['relationId'] ?? ''),
			'matchId' => (string)($match['matchId'] ?? ''),
			'causeClassification' => $this->calculator->defaultCauseClassification(currencyDiff: $hasFx),
			'amount' => (float)($match['mismatchAmount'] ?? 0.0),
			'currency' => 'EUR',
			'description' => 'Mismatch outside tolerance for relation ' . (string)($match['relationId'] ?? ''),
			'status' => 'open',
			'administrationId' => (string)($match['administrationId'] ?? ''),
		];

		$persisted = $this->objectService()
			->setRegister($this->register())
			->setSchema('IntercompanyMismatch')
			->saveObject($mismatch);

		if (is_array($persisted) === true) {
			return $persisted;
		}

		return $mismatch;
	}//end raiseMismatch()

	/**
	 * Verify cross-period roll-forward consistency for a relation (REQ-ICE-008).
	 *
	 * Compares the prior period's closing net position for the relation against the
	 * current period's opening net position. Returns true when they agree (within a
	 * cent) and false when a backdated discrepancy is detected, in which case the
	 * caller blocks further matching and offers the cascade-impact wizard. Fail-open
	 * is NOT used: an exception denies consistency (fail-closed) so a silent backdated
	 * change can never pass unnoticed.
	 *
	 * @param string $relationId The IntercompanyRelation id.
	 * @param string $priorPeriodId The prior consolidation period id.
	 * @param string $currentPeriodId The current consolidation period id.
	 *
	 * @return bool True when the roll-forward is consistent.
	 *
	 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-19
	 */
	public function isRollForwardConsistent(string $relationId, string $priorPeriodId, string $currentPeriodId): bool {
		try {
			$objectService = $this->objectService();
			$register = $this->register();

			$priorMatches = $objectService
				->setRegister($register)
				->setSchema('IntercompanyMatch')
				->findAll(['filters' => ['relationId' => $relationId, 'periodId' => $priorPeriodId]]);

			$currentMatches = $objectService
				->setRegister($register)
				->setSchema('IntercompanyMatch')
				->findAll(['filters' => ['relationId' => $relationId, 'periodId' => $currentPeriodId]]);

			// No prior period to roll forward from — trivially consistent.
			if ($priorMatches === []) {
				return true;
			}

			$priorClosing = $this->netPositionCents(matches: $priorMatches);
			$currentOpen = $this->netPositionCents(matches: $currentMatches);

			return $priorClosing === $currentOpen;
		} catch (\Throwable $e) {
			$this->logger->error(
				'IntercompanyMatchingService: roll-forward check failed — denying consistency (fail-closed)',
				['relationId' => $relationId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end isRollForwardConsistent()

	/**
	 * Compute the net position (A minus B) in cents across a set of matches.
	 *
	 * @param array<int,array<string,mixed>> $matches The match rows.
	 *
	 * @return int The net position in cents.
	 */
	private function netPositionCents(array $matches): int {
		$cents = 0;
		foreach ($matches as $match) {
			$aCents = $this->calculator->toCents(amount: (float)($match['totalAmountA'] ?? 0.0));
			$bCents = $this->calculator->toCents(amount: (float)($match['totalAmountB'] ?? 0.0));
			$cents += ($aCents - $bCents);
		}

		return $cents;
	}//end netPositionCents()
}//end class
