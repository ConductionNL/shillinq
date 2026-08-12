<?php

/**
 * Consolidation Mapping Service
 *
 * Pre-positioned hook service for the future `bookkeeping-consolidatie` spec
 * (REQ-MA-005 / Task 19). The actual consolidation-export rendering belongs to
 * that spec; what ships here is the pure, side-effect-free toolkit the
 * consolidation engine will dispatch through:
 *
 *  - look up the active ConsolidationMapping for a source -> destination
 *    administration pair (real ObjectService.findAll);
 *  - apply the mapping rules to a list of GL accounts (pure rewrite from
 *    `sourceAccount` to `destinationAccount` — unmapped accounts pass through
 *    so missing rules are visible at the consolidation layer, not silently
 *    swallowed);
 *  - detect which IntercompanyJournalEntry lines should be eliminated at
 *    consolidation time (`eliminateOnConsolidation === true`) and resolve the
 *    elimination account from the mapping (`intercompanyEliminationAccount`).
 *
 * The mapping itself is stored declaratively on the ConsolidationMapping
 * register record per ADR-031; this service is a pure read-side helper, not
 * a stateful engine.
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
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-19
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Pre-positioned hooks for the future bookkeeping-consolidatie spec
 * (REQ-MA-005).
 *
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-19
 *
 * @SuppressWarnings(PHPMD.LongVariable) Pre-existing debt (issue #506):
 *     not in the project's calibrated length threshold; deferred pending
 *     a dedicated rename pass.
 */
class ConsolidationMappingService {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container — OR's ObjectService is fetched lazily.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Look up the most-recent applicable ConsolidationMapping for a pair (REQ-MA-005).
	 *
	 * Returns the mapping whose source/destination match and whose `validFrom`
	 * is on or before $asOf. When multiple mappings match, the most recent
	 * `validFrom` wins. Returns null when no mapping is configured — callers
	 * must treat that as "no consolidation possible yet".
	 *
	 * @param string $sourceAdministrationId The dochter administration.
	 * @param string $destinationAdministrationId The moeder administration.
	 * @param DateTimeImmutable|null $asOf Reference date (defaults to now).
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-19
	 */
	public function findActiveMapping(
		string $sourceAdministrationId,
		string $destinationAdministrationId,
		?DateTimeImmutable $asOf = null,
	): ?array {
		if ($sourceAdministrationId === '' || $destinationAdministrationId === '') {
			return null;
		}

		$asOf ??= new DateTimeImmutable();

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$candidates = $objectService
				->setRegister($this->resolveRegister())
				->setSchema('ConsolidationMapping')
				->findAll(
					[
						'filters' => [
							'sourceAdministrationId' => $sourceAdministrationId,
							'destinationAdministrationId' => $destinationAdministrationId,
						],
					]
				);
		} catch (Throwable $e) {
			$this->logger->error(
				'ConsolidationMappingService: failed to lookup mappings',
				[
					'sourceAdministrationId' => $sourceAdministrationId,
					'destinationAdministrationId' => $destinationAdministrationId,
					'exception' => $e->getMessage(),
				]
			);
			return null;
		}//end try

		return $this->pickMostRecent(candidates: $candidates, asOf: $asOf);
	}//end findActiveMapping()

	/**
	 * Apply a mapping's rules to a single source GL account (REQ-MA-005).
	 *
	 * Returns the destination account when a rule matches; returns the source
	 * account unchanged when no rule applies (unmapped accounts pass through so
	 * the consolidation layer can surface the gap, not silently lose lines).
	 *
	 * @param array<string,mixed> $mapping The ConsolidationMapping record.
	 * @param string $sourceAccount The source GL account number.
	 *
	 * @return string The destination GL account number.
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-19
	 */
	public function applyAccountRule(array $mapping, string $sourceAccount): string {
		if ($sourceAccount === '') {
			return '';
		}

		$rules = $mapping['rules'] ?? [];
		if (is_array($rules) === false) {
			return $sourceAccount;
		}

		foreach ($rules as $rule) {
			if (is_array($rule) === false) {
				continue;
			}

			if ((string)($rule['sourceAccount'] ?? '') === $sourceAccount) {
				$destination = (string)($rule['destinationAccount'] ?? '');
				if ($destination === '') {
					return $sourceAccount;
				}

				return $destination;
			}
		}

		return $sourceAccount;
	}//end applyAccountRule()

	/**
	 * Apply a mapping to a list of source GL accounts and report unmapped ones (REQ-MA-005).
	 *
	 * Returns [mapped, unmapped]: `mapped` is the rewritten source->destination
	 * pair list, `unmapped` is the subset whose accounts passed through (no rule
	 * matched). The consolidation engine uses `unmapped` to render a gap warning
	 * at export time.
	 *
	 * @param array<string,mixed> $mapping The ConsolidationMapping record.
	 * @param array<int,string> $sourceAccounts List of source GL account numbers.
	 *
	 * @return array{mapped:array<int,array{source:string,destination:string}>,unmapped:array<int,string>}
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-19
	 */
	public function applyMapping(array $mapping, array $sourceAccounts): array {
		$mapped = [];
		$unmapped = [];

		foreach ($sourceAccounts as $sourceAccount) {
			$sourceAccount = (string)$sourceAccount;
			if ($sourceAccount === '') {
				continue;
			}

			$destination = $this->applyAccountRule(
				mapping: $mapping,
				sourceAccount: $sourceAccount
			);

			$mapped[] = ['source' => $sourceAccount, 'destination' => $destination];

			if ($destination === $sourceAccount) {
				$unmapped[] = $sourceAccount;
			}
		}

		return ['mapped' => $mapped, 'unmapped' => $unmapped];
	}//end applyMapping()

	/**
	 * Whether an IntercompanyJournalEntry should be eliminated at consolidation (REQ-MA-005).
	 *
	 * Pure check on the entry's `eliminateOnConsolidation` flag and lifecycle
	 * status (only `bevestigd_beide` or `eliminatie_geboekt` pairs are eligible
	 * — a `concept` pair is not yet a balanced booking).
	 *
	 * @param array<string,mixed> $intercompanyEntry The IntercompanyJournalEntry record.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-19
	 */
	public function shouldEliminate(array $intercompanyEntry): bool {
		if ((bool)($intercompanyEntry['eliminateOnConsolidation'] ?? false) === false) {
			return false;
		}

		$status = (string)($intercompanyEntry['status'] ?? '');
		return in_array(
			needle: $status,
			haystack: ['bevestigd_beide', 'eliminatie_geboekt'],
			strict: true
		);

	}//end shouldEliminate()

	/**
	 * Resolve the elimination account for an intercompany entry (REQ-MA-005).
	 *
	 * Order of preference (decision sticky enough that the consolidation spec
	 * doesn't have to re-derive it):
	 *   1. the IntercompanyJournalEntry's explicit `eliminationAccount`;
	 *   2. the ConsolidationMapping's `intercompanyEliminationAccount`;
	 *   3. null when no account is configured (caller surfaces a gap).
	 *
	 * @param array<string,mixed> $intercompanyEntry The IntercompanyJournalEntry record.
	 * @param array<string,mixed>|null $mapping The applicable ConsolidationMapping, or null.
	 *
	 * @return string|null The elimination GL account number, or null when unconfigured.
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-19
	 */
	public function resolveEliminationAccount(array $intercompanyEntry, ?array $mapping): ?string {
		$explicit = (string)($intercompanyEntry['eliminationAccount'] ?? '');
		if ($explicit !== '') {
			return $explicit;
		}

		if ($mapping === null) {
			return null;
		}

		$fromMapping = (string)($mapping['intercompanyEliminationAccount'] ?? '');
		if ($fromMapping === '') {
			return null;
		}

		return $fromMapping;
	}//end resolveEliminationAccount()

	/**
	 * Pick the most-recent applicable mapping from a candidate list.
	 *
	 * Pure helper exposed for unit-testing. A mapping is applicable when its
	 * `validFrom` is on or before $asOf (an empty validFrom means "always
	 * valid"). The candidate with the latest validFrom wins; ties prefer the
	 * first encountered (stable order).
	 *
	 * @param iterable<int,array<string,mixed>|object> $candidates Candidate mappings.
	 * @param DateTimeImmutable $asOf Reference date.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-19
	 */
	public function pickMostRecent(iterable $candidates, DateTimeImmutable $asOf): ?array {
		$best = null;
		$bestStamp = null;

		foreach ($candidates as $candidate) {
			$record = $this->extractArray(candidate: $candidate);
			if ($record === null) {
				continue;
			}

			$validFrom = trim((string)($record['validFrom'] ?? ''));
			if ($validFrom === '') {
				if ($best === null) {
					$best = $record;
				}

				continue;
			}

			try {
				$stamp = new DateTimeImmutable($validFrom);
			} catch (Throwable) {
				continue;
			}

			if ($stamp > $asOf) {
				continue;
			}

			if ($bestStamp === null || $stamp > $bestStamp) {
				$best = $record;
				$bestStamp = $stamp;
			}
		}//end foreach

		return $best;
	}//end pickMostRecent()

	/**
	 * Coerce a candidate (array or object exposing getObject()) to an array.
	 *
	 * @param mixed $candidate The candidate.
	 *
	 * @return array<string,mixed>|null
	 */
	private function extractArray(mixed $candidate): ?array {
		if (is_array($candidate) === true) {
			return $candidate;
		}

		if (is_object($candidate) === true && method_exists($candidate, 'getObject') === true) {
			$data = $candidate->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return null;
	}//end extractArray()

	/**
	 * Resolve the configured register slug, defaulting to `shillinq`.
	 *
	 * @return string
	 */
	private function resolveRegister(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end resolveRegister()
}//end class
