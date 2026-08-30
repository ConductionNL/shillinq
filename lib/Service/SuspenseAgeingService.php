<?php

/**
 * Suspense / Unmatched-Worklist Ageing Service
 *
 * Ages the bank-reconciliation suspense worklist (payment-control-guards,
 * REQ-PCG-002). A `BankStatementLine` in state `unmatched` or `routed-to-suspense`
 * is an open suspense item — money the bank moved that the ledger has not yet
 * matched. This service computes, per administration, the days each such item has
 * been outstanding (as-of today, or an explicit as-of date), the count, the
 * oldest age, and the total amount. It is a pure read/reporting service over the
 * real OpenRegister ObjectService API (ADR-022) with no side effects.
 *
 * It backs two consumers: the period-close control (PeriodCloseGuard /
 * PeriodCloseService block a close while the worklist is non-empty, REQ-PCG-003)
 * and the close-assistant flag surface (PeriodCloseAssistantService) so the aged
 * worklist is visible to the operator before they attempt to close.
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
 * @spec openspec/specs/payment-control-guards/spec.md
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

/**
 * Computes the aged suspense / unmatched bank worklist for an administration.
 *
 * @spec openspec/specs/payment-control-guards/spec.md
 */
class SuspenseAgeingService {
	/**
	 * BankStatementLine states that count as an open suspense item.
	 *
	 * @var array<string>
	 */
	private const UNRESOLVED_STATES = [
		'unmatched',
		'routed-to-suspense',
	];

	/**
	 * FQCN of OpenRegister's ObjectService.
	 *
	 * @var string
	 */
	private const OBJECT_SERVICE = 'OCA\OpenRegister\Service\ObjectService';

	/**
	 * Construct the service.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger for diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Build the aged suspense worklist for an administration.
	 *
	 * @param string $administrationId The administration scope ('' ages every statement).
	 * @param string $asOf ISO date (YYYY-MM-DD) to age against; '' means today.
	 *
	 * @return array{items:array<int,array<string,mixed>>, count:int, oldestDaysOutstanding:int, totalAmountCents:int}
	 *
	 * @spec openspec/specs/payment-control-guards/spec.md (REQ-PCG-002)
	 */
	public function agedUnmatchedItems(string $administrationId, string $asOf = ''): array {
		try {
			return $this->computeWorklist(administrationId: $administrationId, asOf: $asOf);
		} catch (\Throwable $e) {
			// Reporting path (close assistant): a broken worklist must not crash
			// the assistant, so it degrades to empty. The CONTROL path
			// (hasUnresolvedItems) deliberately does NOT swallow — see below.
			$this->logger->error(
				'SuspenseAgeingService: ageing computation failed',
				['administrationId' => $administrationId, 'error' => $e->getMessage()]
			);
			return [
				'items' => [],
				'count' => 0,
				'oldestDaysOutstanding' => 0,
				'totalAmountCents' => 0,
			];
		}//end try

	}//end agedUnmatchedItems()

	/**
	 * Whether any unmatched / routed-to-suspense item remains for an administration.
	 *
	 * This is the CONTROL path used by the period-close blocker: it does NOT
	 * swallow errors — a failure propagates so the caller can FAIL CLOSED (block
	 * the close) rather than mistake an unreadable worklist for an empty one.
	 *
	 * @param string $administrationId The administration scope.
	 * @param string $asOf ISO date to age against; '' means today.
	 *
	 * @return bool True when the suspense worklist is non-empty.
	 *
	 * @throws \Throwable When the worklist cannot be computed (caller fails closed).
	 *
	 * @spec openspec/specs/payment-control-guards/spec.md (REQ-PCG-003)
	 */
	public function hasUnresolvedItems(string $administrationId, string $asOf = ''): bool {
		return ($this->computeWorklist(administrationId: $administrationId, asOf: $asOf)['count'] > 0);
	}//end hasUnresolvedItems()

	/**
	 * Compute the aged worklist; throws on any lookup/parse failure.
	 *
	 * @param string $administrationId The administration scope ('' ages every statement).
	 * @param string $asOf ISO date to age against; '' means today.
	 *
	 * @return array{items:array<int,array<string,mixed>>, count:int, oldestDaysOutstanding:int, totalAmountCents:int}
	 */
	private function computeWorklist(string $administrationId, string $asOf): array {
		$asOfDate = $this->asOfDate(asOf: $asOf);
		$scopeStatement = $this->statementScope(administrationId: $administrationId);

		$items = [];
		$oldest = 0;
		$totalCents = 0;
		foreach ($this->unresolvedLines() as $line) {
			if (is_array($line) === false) {
				continue;
			}

			$statementId = trim((string)($line['statementId'] ?? ''));
			if ($scopeStatement !== null && ($statementId === '' || isset($scopeStatement[$statementId]) === false)) {
				// Line belongs to another administration's statement.
				continue;
			}

			$days = $this->daysOutstanding(line: $line, asOfDate: $asOfDate);
			$amountCents = (int)round(((float)($line['amount'] ?? 0)) * 100);

			$items[] = [
				'lineId' => trim((string)($line['lineId'] ?? ($line['id'] ?? ''))),
				'statementId' => $statementId,
				'status' => (string)($line['status'] ?? ''),
				'amount' => (float)($line['amount'] ?? 0),
				'valueDate' => (string)($line['valueDate'] ?? ($line['transactionDate'] ?? '')),
				'daysOutstanding' => $days,
			];
			$oldest = max($oldest, $days);
			$totalCents += abs($amountCents);
		}//end foreach

		// Oldest first — the worklist prioritises the most-aged item.
		usort(
			$items,
			static function (array $a, array $b): int {
				return ($b['daysOutstanding'] <=> $a['daysOutstanding']);
			}
		);

		return [
			'items' => $items,
			'count' => count($items),
			'oldestDaysOutstanding' => $oldest,
			'totalAmountCents' => $totalCents,
		];

	}//end computeWorklist()

	/**
	 * Resolve the as-of date used to age items.
	 *
	 * @param string $asOf The ISO date string, or '' for today.
	 *
	 * @return DateTimeImmutable The as-of date at midnight.
	 */
	private function asOfDate(string $asOf): DateTimeImmutable {
		if (trim($asOf) === '') {
			return new DateTimeImmutable('today');
		}

		return new DateTimeImmutable($asOf);
	}//end asOfDate()

	/**
	 * Days a line has been outstanding, floored at zero (future dates → 0).
	 *
	 * @param array<string,mixed> $line The BankStatementLine record.
	 * @param DateTimeImmutable $asOfDate The as-of reference date.
	 *
	 * @return int The whole days outstanding.
	 */
	private function daysOutstanding(array $line, DateTimeImmutable $asOfDate): int {
		$raw = trim((string)($line['valueDate'] ?? ($line['transactionDate'] ?? '')));
		if ($raw === '') {
			return 0;
		}

		try {
			$valueDate = new DateTimeImmutable($raw);
		} catch (\Throwable $e) {
			return 0;
		}

		$seconds = ($asOfDate->getTimestamp() - $valueDate->getTimestamp());
		if ($seconds <= 0) {
			return 0;
		}

		return (int)floor($seconds / 86400);
	}//end daysOutstanding()

	/**
	 * Build the set of BankStatement ids belonging to an administration.
	 *
	 * @param string $administrationId The administration scope.
	 *
	 * @return array<string,true>|null The id set, or null when scoping is disabled ('').
	 */
	private function statementScope(string $administrationId): ?array {
		if (trim($administrationId) === '') {
			return null;
		}

		$statements = $this->container->get(self::OBJECT_SERVICE)
			->setRegister($this->register())
			->setSchema('BankStatement')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

		$scope = [];
		if (is_array($statements) === true) {
			foreach ($statements as $statement) {
				if (is_array($statement) === false) {
					continue;
				}

				foreach (['statementId', 'id', 'uuid', 'slug'] as $key) {
					$value = trim((string)($statement[$key] ?? ''));
					if ($value !== '') {
						$scope[$value] = true;
					}
				}
			}
		}

		return $scope;
	}//end statementScope()

	/**
	 * Fetch every unmatched / routed-to-suspense BankStatementLine.
	 *
	 * OpenRegister filters cannot express an `IN` set, so each unresolved status
	 * is queried in turn and the results concatenated.
	 *
	 * @return array<int,mixed> The unresolved line records.
	 */
	private function unresolvedLines(): array {
		$objectService = $this->container->get(self::OBJECT_SERVICE);

		$lines = [];
		foreach (self::UNRESOLVED_STATES as $state) {
			$batch = $objectService
				->setRegister($this->register())
				->setSchema('BankStatementLine')
				->findAll(['filters' => ['status' => $state]]);
			if (is_array($batch) === true) {
				$lines = array_merge($lines, $batch);
			}
		}

		return $lines;
	}//end unresolvedLines()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
