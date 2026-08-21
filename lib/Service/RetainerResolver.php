<?php

/**
 * Retainer Resolver
 *
 * Reads the active RetainerSchedule version for a given invoice month and
 * returns the applicable monthly amount + overage configuration (Task 25,
 * issue #111). Mirrors RateCardResolver in style — pure lookup, no GL
 * posting, no schema mutation.
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
 * @spec openspec/specs/invoice-from-time-and-expense/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolve RetainerSchedule entries from the OR engine.
 *
 * @spec openspec/specs/invoice-from-time-and-expense/spec.md#requirement-invoice-generation-service
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class RetainerResolver {
	/**
	 * Construct the retainer resolver.
	 *
	 * @param ContainerInterface $container DI container.
	 * @param IAppConfig $appConfig App config.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the monthly retainer + overage amounts for a given invoice month.
	 *
	 * Tenant scope (REQ-001, ADR-005 Rule 3): $scheduleId is client-supplied,
	 * so the lookup compounds the caller's server-resolved $administrationId
	 * into the query itself (an equality filter pair on findAll(), mirroring
	 * InvoiceGenerationService::findScoped() / GoodsReceiptNoteService::findOne())
	 * rather than fetching by id and then checking. A RetainerSchedule
	 * belonging to another administration therefore can never resolve — it is
	 * indistinguishable from an unknown id and yields the same zeroed
	 * schedule, this file's pre-existing convention for an unresolvable
	 * reference.
	 *
	 * @param string $scheduleId FK to RetainerSchedule.
	 * @param string $invoiceMonth ISO date — any day within the target month.
	 * @param string $administrationId Caller's server-resolved administration scope.
	 *
	 * @return array{monthlyAmountCents:int,overageHoursThreshold:?float,overageHourlyRateCents:?int,effectiveDate:string,label:string}
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	public function resolveRetainerAmount(string $scheduleId, string $invoiceMonth, string $administrationId): array {
		// Fail closed on an absent scope: with no administration to scope to
		// there is no query that can be proven tenant-safe, so no register is
		// read at all (mirrors AdministrationContextService::canAccess(),
		// which fails closed on '').
		if ($administrationId === '') {
			$this->logger->warning(
				sprintf(
					'RetainerResolver: refusing unscoped lookup for %s on %s (no administrationId)',
					$scheduleId,
					$invoiceMonth
				)
			);

			return $this->zeroSchedule(invoiceMonth: $invoiceMonth);
		}

		$best = $this->pickBestSchedule(
			records: $this->findAll(
				schema: 'RetainerSchedule',
				filters: [
					'scheduleId' => $scheduleId,
					'administrationId' => $administrationId,
				]
			),
			invoiceMonth: $invoiceMonth
		);

		if ($best === null) {
			$this->logger->warning(sprintf('RetainerResolver: no active schedule for %s on %s', $scheduleId, $invoiceMonth));
			return $this->zeroSchedule(invoiceMonth: $invoiceMonth);
		}

		if (isset($best['overageHoursThreshold']) === true) {
			$overageHoursThreshold = (float)$best['overageHoursThreshold'];
		} else {
			$overageHoursThreshold = null;
		}

		if (isset($best['overageHourlyRate']) === true) {
			$overageHourlyRateCents = $this->toCents(value: $best['overageHourlyRate']);
		} else {
			$overageHourlyRateCents = null;
		}

		return [
			'monthlyAmountCents' => $this->toCents(value: $best['monthlyAmount'] ?? 0),
			'overageHoursThreshold' => $overageHoursThreshold,
			'overageHourlyRateCents' => $overageHourlyRateCents,
			'effectiveDate' => (string)($best['effectiveDate'] ?? $invoiceMonth),
			'label' => (string)($best['label'] ?? 'Retainer'),
		];

	}//end resolveRetainerAmount()

	/**
	 * Pick the latest-effective RetainerSchedule version that brackets the
	 * invoice month.
	 *
	 * Versions with a future effectiveDate, or an endDate already past, are
	 * skipped; among the remainder the highest effectiveDate wins.
	 *
	 * @param array<int,array<string,mixed>> $records Candidate schedule versions.
	 * @param string $invoiceMonth ISO date — any day within the target month.
	 *
	 * @return array<string,mixed>|null The winning version, or null when none apply.
	 */
	private function pickBestSchedule(array $records, string $invoiceMonth): ?array {
		$best = null;
		foreach ($records as $record) {
			$effective = (string)($record['effectiveDate'] ?? '');
			$end = (string)($record['endDate'] ?? '');

			if ($effective !== '' && $effective > $invoiceMonth) {
				continue;
			}

			if ($end !== '' && $end < $invoiceMonth) {
				continue;
			}

			if ($best === null || (string)($best['effectiveDate'] ?? '') < $effective) {
				$best = $record;
			}
		}//end foreach

		return $best;
	}//end pickBestSchedule()

	/**
	 * The zeroed retainer shape returned when no schedule resolves.
	 *
	 * Shared by the "no active schedule" path and the "no administration
	 * scope" fail-closed path so a cross-tenant / unscoped id is
	 * indistinguishable from an unknown one.
	 *
	 * @param string $invoiceMonth ISO date — any day within the target month.
	 *
	 * @return array{monthlyAmountCents:int,overageHoursThreshold:?float,overageHourlyRateCents:?int,effectiveDate:string,label:string}
	 */
	private function zeroSchedule(string $invoiceMonth): array {
		return [
			'monthlyAmountCents' => 0,
			'overageHoursThreshold' => null,
			'overageHourlyRateCents' => null,
			'effectiveDate' => $invoiceMonth,
			'label' => 'Retainer',
		];
	}//end zeroSchedule()

	/**
	 * Convert a stored money value to integer cents.
	 *
	 * @param mixed $value Stored value.
	 *
	 * @return int Cents.
	 */
	private function toCents(mixed $value): int {
		if (is_int($value) === true) {
			return $value;
		}

		return (int)round((float)$value * 100);
	}//end toCents()

	/**
	 * Find all matching records via the real OR ObjectService API.
	 *
	 * @param string $schema Schema slug.
	 * @param array<string,mixed> $filters Filter map.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findAll(string $schema, array $filters): array {
		try {
			$svc = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$rs = $svc
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['filters' => $filters]);

			if (is_array($rs) === true) {
				return $rs;
			}

			return [];
		} catch (\Throwable $e) {
			$this->logger->error('RetainerResolver findAll failed: ' . $e->getMessage());
			return [];
		}

	}//end findAll()

	/**
	 * Resolve the OpenRegister register slug.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
