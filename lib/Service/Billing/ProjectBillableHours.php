<?php

/**
 * Project Billable Hours
 *
 * What one month of a project's hours is worth (sales-billable-rate-per-hour
 * D5, REQ-BRPH-003): each hour from the hours source with its person, date,
 * hours, the rate it resolves to, the tier that rate came from, its value and
 * whether it is billed, and the totals of unbilled hours, unbilled value and
 * hours without a rate. An hour is billed when an invoice of the
 * administration that is not cancelled lists it.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Billing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-4.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Billing;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\BillableRateService;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;

/**
 * Rates a project's hours of one month.
 *
 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-4.1
 */
class ProjectBillableHours {
	/**
	 * The project schema.
	 *
	 * @var string
	 */
	public const PROJECT_SCHEMA = 'engagement';

	/**
	 * Rows read per lookup.
	 *
	 * @var int
	 */
	private const READ_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param BillableHoursSource    $hours            Where the project's hours are kept.
	 * @param BillableRateService    $rates            The rate resolver.
	 * @param ObjectServiceInterface $objectService    OpenRegister's object service.
	 * @param RegisterLocation       $registerLocation The register this app's objects live in.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly BillableHoursSource $hours,
		private readonly BillableRateService $rates,
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocation $registerLocation,
	) {
	}//end __construct()

	/**
	 * The project of an administration, or null when it is not one of its projects.
	 *
	 * @param string $projectId        The project.
	 * @param string $administrationId The administration.
	 *
	 * @return array<string, mixed>|null The project.
	 *
	 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-4.1
	 */
	public function project(string $projectId, string $administrationId): ?array {
		if ($projectId === '' || $administrationId === '') {
			return null;
		}

		foreach ($this->rows(schema: self::PROJECT_SCHEMA, administrationId: $administrationId) as $project) {
			if ((string)($project['id'] ?? '') === $projectId) {
				return $project;
			}
		}

		return null;
	}//end project()

	/**
	 * The rated hours of one month of a project.
	 *
	 * @param array<string, mixed> $project The project, as {@see project()} found it.
	 * @param string               $month   The month, `YYYY-MM`.
	 *
	 * @return array<string, mixed> `available`, `reason`, `month`, `from`, `to`, `rows` and `totals`
	 *                              (`hours`, `unbilledHours`, `unbilledValue`, `unratedHours`).
	 *
	 * @throws InvalidArgumentException When the month is not `YYYY-MM`.
	 *
	 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-4.1
	 */
	public function forMonth(array $project, string $month): array {
		[$from, $to] = self::monthWindow(month: $month);
		$projectId = (string)($project['id'] ?? '');
		$administrationId = (string)($project['administrationId'] ?? '');
		$answer = ['available' => false, 'reason' => '', 'month' => $month, 'from' => $from, 'to' => $to, 'rows' => [], 'totals' => self::totals(rows: [])];

		$source = $this->hours->forProject(projectId: $projectId, from: $from, to: $to);
		if (($source['available'] ?? false) !== true) {
			$answer['reason'] = (string)($source['reason'] ?? '');
			return $answer;
		}

		$billed = $this->billedOn(administrationId: $administrationId);
		$rows = [];
		foreach ((array)($source['hours'] ?? []) as $hour) {
			if (is_array($hour) === true) {
				$rows[] = $this->row(hour: $hour, project: $project, billed: $billed);
			}
		}

		usort($rows, static fn (array $a, array $b): int => [$a['date'], $a['person']] <=> [$b['date'], $b['person']]);

		$answer['available'] = true;
		$answer['rows'] = $rows;
		$answer['totals'] = self::totals(rows: $rows);

		return $answer;
	}//end forMonth()

	/**
	 * The first and last day of a `YYYY-MM` month.
	 *
	 * @param string $month The month.
	 *
	 * @return array{0: string, 1: string} The first and the last day, `Y-m-d`.
	 *
	 * @throws InvalidArgumentException When the month is not `YYYY-MM`.
	 *
	 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-4.1
	 */
	public static function monthWindow(string $month): array {
		if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
			throw new InvalidArgumentException('month must be YYYY-MM.');
		}

		$first = new DateTimeImmutable($month . '-01');

		return [$first->format('Y-m-d'), $first->format('Y-m-t')];
	}//end monthWindow()

	/**
	 * One hour with its rate, value and billed state.
	 *
	 * @param array<string, mixed>  $hour    The hour from the source.
	 * @param array<string, mixed>  $project The project.
	 * @param array<string, string> $billed  Invoice number per billed hour id.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row(array $hour, array $project, array $billed): array {
		$id = (string)($hour['id'] ?? '');
		$hours = (float)($hour['hours'] ?? 0);
		$answer = $this->rates->preview(
			hour: [
				'personId' => (string)($hour['person'] ?? ''),
				'personName' => (string)($hour['personName'] ?? ''),
				'projectId' => (string)($project['id'] ?? ''),
				'date' => (string)($hour['date'] ?? ''),
				'hours' => $hours,
				'administrationId' => (string)($project['administrationId'] ?? ''),
				'clientId' => (string)($project['customerId'] ?? ''),
			]
		);
		$row = [
			'id' => $id,
			'person' => (string)($hour['person'] ?? ''),
			'date' => (string)($hour['date'] ?? ''),
			'hours' => $hours,
			'description' => (string)($hour['description'] ?? ''),
			'status' => BillableRateService::STATUS_NO_RATE,
			'rate' => null,
			'unit' => null,
			'tier' => null,
			'value' => null,
			'reason' => (string)($answer['reason'] ?? ''),
			'message' => (string)($answer['message'] ?? ''),
			'billed' => isset($billed[$id]),
			'invoiceNumber' => ($billed[$id] ?? null),
		];
		if (($answer['status'] ?? '') !== BillableRateService::STATUS_RATED) {
			return $row;
		}

		return array_merge(
			$row,
			[
				'status' => BillableRateService::STATUS_RATED,
				'rate' => (float)$answer['rate'],
				'unit' => (string)$answer['unit'],
				'tier' => (string)$answer['tier'],
				'value' => (float)$answer['value'],
				'reason' => null,
				'message' => null,
			]
		);
	}//end row()

	/**
	 * The totals of the rows: all hours, the rated unbilled hours and their
	 * value, and the hours without a rate.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return array{hours: float, unbilledHours: float, unbilledValue: float, unratedHours: float} The totals.
	 */
	private static function totals(array $rows): array {
		$totals = ['hours' => 0.0, 'unbilledHours' => 0.0, 'unbilledValue' => 0.0, 'unratedHours' => 0.0];
		foreach ($rows as $row) {
			$totals['hours'] += (float)$row['hours'];
			if ($row['status'] !== BillableRateService::STATUS_RATED) {
				$totals['unratedHours'] += (float)$row['hours'];
				continue;
			}

			if ($row['billed'] === false) {
				$totals['unbilledHours'] += (float)$row['hours'];
				$totals['unbilledValue'] += (float)$row['value'];
			}
		}

		return array_map(static fn (float $total): float => round($total, 2), $totals);
	}//end totals()

	/**
	 * The invoice number each billed hour is on, per hour id.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<string, string> Invoice number per hour id.
	 */
	private function billedOn(string $administrationId): array {
		$billed = [];
		foreach ($this->rows(schema: 'BillableInvoice', administrationId: $administrationId) as $invoice) {
			if ((string)($invoice['status'] ?? '') === 'cancelled') {
				continue;
			}

			$number = (string)($invoice['invoiceNumber'] ?? ($invoice['id'] ?? ''));
			foreach ((array)($invoice['timeEntryIds'] ?? []) as $hourId) {
				if (is_scalar($hourId) === true && isset($billed[(string)$hourId]) === false) {
					$billed[(string)$hourId] = $number;
				}
			}
		}

		return $billed;
	}//end billedOn()

	/**
	 * The rows of a schema in one administration, as records with their id.
	 *
	 * @param string $schema           The schema slug.
	 * @param string $administrationId The administration.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rows(string $schema, string $administrationId): array {
		$rows = $this->objectService->setRegister($this->registerLocation->getRegisterSlug())->setSchema($schema)->findAll(
			['filters' => ['administrationId' => $administrationId], 'limit' => self::READ_LIMIT]
		);

		$records = [];
		foreach ($rows as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			// A row of another administration never shows, even when the
			// store ignored the filter.
			if ($record !== null && (string)($record['administrationId'] ?? '') === $administrationId) {
				$records[] = $record;
			}
		}

		return $records;
	}//end rows()
}//end class
