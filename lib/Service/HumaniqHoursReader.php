<?php

/**
 * Humaniq hours reader
 *
 * Reads the hours humaniq holds for one administration and period, in one
 * batched read, for the ledger paths that book or count them.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://shillinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/hours-to-humaniq/tasks.md#3-move-the-hour
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The hour lives in humaniq; shillinq reads it per run, not per hour.
 *
 * Shape 1 of design.md: shillinq derives a cost line per humaniq `TimeEntry`
 * and keeps on its own booking only what the ledger needs. A booking run
 * (an invoice run, a WBSO export, the yearly urencriterium count) covers one
 * administration over one period, so the read is one query over that scope,
 * keyed by the entry uuid the booking references.
 *
 * ABSENT IS NOT ZERO (REQ-H2H-002). The read answers null when humaniq does
 * not answer, and an empty list when humaniq answers with no hours. A caller
 * that collapses the two reproduces the dossiq tile that reported 0 hours on
 * every case. The register slug comes from {@see HrmqCostRateAdapter}, the one
 * place that knows where humaniq lives.
 *
 * @spec openspec/changes/hours-to-humaniq/specs/hours-to-humaniq/spec.md#requirement-req-h2h-001--hours-on-a-domain-object-live-in-humaniq
 */
class HumaniqHoursReader {

	/**
	 * Humaniq's hour schema.
	 *
	 * @var string
	 */
	private const HOURS_SCHEMA = 'TimeEntry';

	/**
	 * Wire collaborators.
	 *
	 * @param ContainerInterface  $container Container, for the lazy ObjectService resolve.
	 * @param HrmqCostRateAdapter $humaniq   The one place that knows humaniq's register slug.
	 * @param LoggerInterface     $logger    PSR logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hours-to-humaniq/tasks.md#3-move-the-hour
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly HrmqCostRateAdapter $humaniq,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The hours of one administration over a period, keyed by entry uuid.
	 *
	 * @param string $administrationId The administration the run books for.
	 * @param string $from             First day, `YYYY-MM-DD`, inclusive.
	 * @param string $until            Last day, `YYYY-MM-DD`, inclusive.
	 *
	 * @return array<string, array{uuid: string, personId: string, date: string, hours: float|null,
	 *     billable: bool, approvalState: string, projectId: string, domainObjectType: string,
	 *     domainObjectRef: string, administrationId: string}>|null
	 *     The entries, [] when humaniq holds none, null when humaniq does not answer.
	 *
	 * @spec openspec/changes/hours-to-humaniq/specs/hours-to-humaniq/spec.md#requirement-req-h2h-002--an-hours-surface-never-reports-zero-for-a-missing-app
	 */
	public function entriesInPeriod(string $administrationId, string $from, string $until): ?array {
		$administrationId = trim($administrationId);
		if ($administrationId === '' || trim($from) === '' || trim($until) === '') {
			// An unscoped read would hand one administration's hours to another.
			return [];
		}

		try {
			$rows = $this->container->get('OCA\OpenRegister\Service\ObjectService')
				->setRegister($this->humaniq->registerSlug())
				->setSchema(self::HOURS_SCHEMA)
				->findAll(
					[
						'filters' => [
							'administrationId' => $administrationId,
							'date' => ['gte' => trim($from), 'lte' => trim($until)],
						],
					]
				);
		} catch (Throwable $e) {
			$this->logger->debug('HumaniqHoursReader: humaniq hours not readable: ' . $e->getMessage());
			return null;
		}

		if (is_array($rows) === false) {
			return null;
		}

		return $this->keyedEntries(rows: $rows, administrationId: $administrationId);
	}//end entriesInPeriod()

	/**
	 * Normalise the rows and key them by uuid, dropping rows of another administration.
	 *
	 * The register filter already scopes the read; the check here holds when a
	 * register ignores a filter, because a row outside the administration must
	 * never reach its ledger.
	 *
	 * @param array<int|string, mixed> $rows             The rows as stored.
	 * @param string                   $administrationId The administration asked for.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/changes/hours-to-humaniq/tasks.md#3-move-the-hour
	 */
	private function keyedEntries(array $rows, string $administrationId): array {
		$entries = [];
		foreach ($rows as $row) {
			$entry = $this->normalised(row: $this->toArray(row: $row));
			if ($entry !== null && $entry['administrationId'] === $administrationId) {
				$entries[$entry['uuid']] = $entry;
			}
		}

		return $entries;
	}//end keyedEntries()

	/**
	 * One stored row as the ledger reads it, or null when it has no uuid.
	 *
	 * Hours stay null when the row carries none: a missing number is not zero
	 * hours, and the consumer decides how to refuse it.
	 *
	 * @param array<string, mixed>|null $row The row.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/hours-to-humaniq/tasks.md#3-move-the-hour
	 */
	private function normalised(?array $row): ?array {
		if ($row === null) {
			return null;
		}

		$uuid = trim((string)($row['uuid'] ?? ($row['id'] ?? ($row['@self']['id'] ?? ''))));
		if ($uuid === '') {
			return null;
		}

		$hours = null;
		if (is_numeric($row['hours'] ?? null) === true) {
			$hours = (float)$row['hours'];
		}

		return [
			'uuid' => $uuid,
			'personId' => trim((string)($row['employeeId'] ?? '')),
			'date' => trim((string)($row['date'] ?? '')),
			'hours' => $hours,
			'billable' => (($row['billable'] ?? false) === true),
			'approvalState' => trim((string)($row['approvalState'] ?? '')),
			'projectId' => trim((string)($row['projectId'] ?? '')),
			'domainObjectType' => trim((string)($row['domainObjectType'] ?? '')),
			'domainObjectRef' => trim((string)($row['domainObjectRef'] ?? '')),
			'administrationId' => trim((string)($row['administrationId'] ?? '')),
		];
	}//end normalised()

	/**
	 * Normalise an ObjectService row to an array: jsonSerialize(), then getObject().
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/hours-to-humaniq/tasks.md#3-move-the-hour
	 */
	private function toArray(mixed $row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		foreach (['jsonSerialize', 'getObject'] as $method) {
			if (is_object($row) === true && method_exists($row, $method) === true) {
				$out = $row->$method();
				if (is_array($out) === true) {
					return $out;
				}
			}
		}

		return null;
	}//end toArray()
}//end class
