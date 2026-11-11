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
use OCA\Shillinq\AppInfo\AppIdentity;
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
	public function findActiveMapping(string $sourceAdministrationId, string $destinationAdministrationId, ?DateTimeImmutable $asOf = null): ?array {
		if (in_array('', [$sourceAdministrationId, $destinationAdministrationId], true) === true) {
			return null;
		}

		$asOf ??= new DateTimeImmutable();
		try {
			$candidates = $this->candidates(sourceAdministrationId: $sourceAdministrationId, destinationAdministrationId: $destinationAdministrationId);
		} catch (Throwable $e) {
			$context = ['sourceAdministrationId' => $sourceAdministrationId, 'destinationAdministrationId' => $destinationAdministrationId];
			$this->logger->error('ConsolidationMappingService: failed to lookup mappings', $context + ['exception' => $e->getMessage()]);
			return null;
		}

		return $this->pickMostRecent(candidates: $candidates, asOf: $asOf);
	}//end findActiveMapping()

	/**
	 * The ConsolidationMappings stored for one source/destination pair.
	 *
	 * @param string $sourceAdministrationId      The dochter administration.
	 * @param string $destinationAdministrationId The moeder administration.
	 *
	 * @return mixed The findAll() result.
	 */
	private function candidates(string $sourceAdministrationId, string $destinationAdministrationId): mixed {
		$filters = ['sourceAdministrationId' => $sourceAdministrationId, 'destinationAdministrationId' => $destinationAdministrationId];

		return $this->container->get('OCA\OpenRegister\Service\ObjectService')
			->setRegister($this->resolveRegister())
			->setSchema('ConsolidationMapping')
			->findAll(['filters' => $filters]);
	}//end candidates()

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

		$destination = $this->ruleDestination(rules: $rules, sourceAccount: $sourceAccount);
		if ($destination === null || $destination === '') {
			return $sourceAccount;
		}

		return $destination;
	}//end applyAccountRule()

	/**
	 * The destination of the first rule for a source account, or null when no
	 * rule names it.
	 *
	 * @param array<mixed> $rules         The mapping's rules.
	 * @param string       $sourceAccount The source GL account number.
	 *
	 * @return string|null
	 */
	private function ruleDestination(array $rules, string $sourceAccount): ?string {
		foreach ($rules as $rule) {
			if (is_array($rule) === true && (string)($rule['sourceAccount'] ?? '') === $sourceAccount) {
				return (string)($rule['destinationAccount'] ?? '');
			}
		}

		return null;
	}//end ruleDestination()

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
		foreach ($sourceAccounts as $sourceAccount) {
			$sourceAccount = (string)$sourceAccount;
			if ($sourceAccount !== '') {
				$mapped[] = ['source' => $sourceAccount, 'destination' => $this->applyAccountRule(mapping: $mapping, sourceAccount: $sourceAccount)];
			}
		}

		$passedThrough = array_filter($mapped, static fn (array $pair): bool => $pair['destination'] === $pair['source']);

		return ['mapped' => $mapped, 'unmapped' => array_column($passedThrough, 'source')];
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
		$pick = new MappingPick();
		$pick->asOf = $asOf;
		foreach ($candidates as $candidate) {
			$record = $this->extractArray(candidate: $candidate);
			if ($record !== null) {
				$this->consider(pick: $pick, record: $record);
			}
		}

		return $pick->best;
	}//end pickMostRecent()

	/**
	 * Let one mapping take the pick: an undated mapping only when nothing is
	 * picked yet, a dated one when it applies and is strictly more recent.
	 *
	 * @param MappingPick         $pick   The running choice.
	 * @param array<string,mixed> $record One candidate mapping.
	 *
	 * @return void
	 */
	private function consider(MappingPick $pick, array $record): void {
		$validFrom = trim((string)($record['validFrom'] ?? ''));
		if ($validFrom === '') {
			$pick->best ??= $record;
			return;
		}

		$stamp = $this->applicableStamp(validFrom: $validFrom, asOf: $pick->asOf);
		if ($stamp !== null && ($pick->bestStamp === null || $stamp > $pick->bestStamp)) {
			$pick->best = $record;
			$pick->bestStamp = $stamp;
		}
	}//end consider()

	/**
	 * A mapping's validFrom when it parses and is on or before the reference
	 * date, otherwise null.
	 *
	 * @param string            $validFrom The trimmed, non-empty validFrom.
	 * @param DateTimeImmutable $asOf      Reference date.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function applicableStamp(string $validFrom, DateTimeImmutable $asOf): ?DateTimeImmutable {
		try {
			$stamp = new DateTimeImmutable($validFrom);
		} catch (Throwable) {
			return null;
		}

		if ($stamp > $asOf) {
			return null;
		}

		return $stamp;
	}//end applicableStamp()

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

		$data = $this->payloadOf(candidate: $candidate);
		if (is_array($data) === true) {
			return $data;
		}

		return null;
	}//end extractArray()

	/**
	 * The payload of a wrapped record, or null when it wraps nothing.
	 *
	 * @param mixed $candidate The candidate.
	 *
	 * @return mixed
	 */
	private function payloadOf(mixed $candidate): mixed {
		if (is_object($candidate) === true && method_exists($candidate, 'getObject') === true) {
			return $candidate->getObject();
		}

		return null;
	}//end payloadOf()

	/**
	 * Resolve the configured register slug, defaulting to `shillinq`.
	 *
	 * @return string
	 */
	private function resolveRegister(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end resolveRegister()
}//end class
