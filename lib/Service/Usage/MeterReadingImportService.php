<?php

/**
 * Meter Reading Import Service
 *
 * Turns the rows of a meter reading file into unrated MeterReading records in
 * one administration. A row that cannot become a valid reading is refused
 * with its row number and the reason, and the other rows still land
 * (sales-usage-billing, REQ-USB-001).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Usage
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-usage-billing/specs/usage-metered-billing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Usage;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IL10N;
use Throwable;

/**
 * Imports meter readings row by row.
 *
 * @spec openspec/changes/sales-usage-billing/specs/usage-metered-billing/spec.md
 */
class MeterReadingImportService {

	/**
	 * The text columns a row may carry, besides quantity and the dates.
	 *
	 * @var array<int,string>
	 */
	private const TEXT_COLUMNS = ['meterId', 'customerId', 'resourceType', 'unit', 'ratePlanId', 'description'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param SettingsService        $settings      Supplies the register slug.
	 * @param IL10N                  $l10n          Translates the refusal reasons.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly SettingsService $settings,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Create an unrated reading for every valid row; refuse the others by row number.
	 *
	 * @param string                          $administrationId The administration the readings belong to.
	 * @param array<int,array<string,mixed>> $rows             The rows, first row is row 1.
	 *
	 * @return array{created:list<string>,refused:list<array{row:int,reason:string}>}
	 *
	 * @spec openspec/changes/sales-usage-billing/specs/usage-metered-billing/spec.md
	 */
	public function import(string $administrationId, array $rows): array {
		$created = [];
		$refused = [];
		$plans   = [];
		foreach (array_values($rows) as $index => $row) {
			$number  = ($index + 1);
			$reading = $this->reading(administrationId: $administrationId, row: (array)$row);
			if (is_string($reading) === true) {
				$refused[] = ['row' => $number, 'reason' => $reading];
				continue;
			}

			if (isset($reading['ratePlanId']) === false) {
				$type = (string)$reading['resourceType'];
				if (array_key_exists($type, $plans) === false) {
					$plans[$type] = $this->planIdFor(administrationId: $administrationId, resourceType: $type);
				}

				if ($plans[$type] !== null) {
					$reading['ratePlanId'] = $plans[$type];
				}
			}

			try {
				$saved = ObjectIdentifier::recordWithId(candidate: $this->scoped(schema: 'MeterReading')->saveObject($reading));
			} catch (Throwable $e) {
				$refused[] = ['row' => $number, 'reason' => $this->l10n->t('The register refused the row: %s', [$e->getMessage()])];
				continue;
			}

			$created[] = (string)($saved['id'] ?? '');
		}//end foreach

		return ['created' => $created, 'refused' => $refused];

	}//end import()

	/**
	 * The reading a row describes, or the reason it cannot be one.
	 *
	 * @param string              $administrationId The administration.
	 * @param array<string,mixed> $row              The row.
	 *
	 * @return array<string,mixed>|string The reading, or the refusal reason.
	 */
	private function reading(string $administrationId, array $row): array|string {
		$reading = ['administrationId' => $administrationId, 'status' => 'unrated'];
		foreach (self::TEXT_COLUMNS as $column) {
			$value = trim((string)($row[$column] ?? ''));
			if ($value !== '') {
				$reading[$column] = $value;
			}
		}

		$missing = $this->missing(reading: $reading);
		if ($missing !== null) {
			return $missing;
		}

		$quantity = str_replace(',', '.', trim((string)($row['quantity'] ?? '')));
		if (is_numeric($quantity) === false) {
			return $this->l10n->t('The quantity is not a number.');
		}

		if ((float)$quantity < 0) {
			return $this->l10n->t('The quantity is negative.');
		}

		$reading['quantity'] = (float)$quantity;

		return $this->withPeriod(reading: $reading, row: $row);

	}//end reading()

	/**
	 * The reason a required text column is empty, or null.
	 *
	 * @param array<string,mixed> $reading The reading so far.
	 *
	 * @return string|null The reason, or null.
	 */
	private function missing(array $reading): ?string {
		if (isset($reading['customerId']) === false) {
			return $this->l10n->t('The customer is missing.');
		}

		if (isset($reading['resourceType']) === false) {
			return $this->l10n->t('The resource type is missing.');
		}

		return null;

	}//end missing()

	/**
	 * The reading with its period, or the reason the period is unusable.
	 *
	 * @param array<string,mixed> $reading The reading so far.
	 * @param array<string,mixed> $row     The row.
	 *
	 * @return array<string,mixed>|string The reading, or the refusal reason.
	 */
	private function withPeriod(array $reading, array $row): array|string {
		$start = trim((string)($row['periodStart'] ?? ''));
		$end   = trim((string)($row['periodEnd'] ?? ''));
		if ($this->isDate(value: $start) === false) {
			return $this->l10n->t('The period start is not a date (YYYY-MM-DD).');
		}

		if ($this->isDate(value: $end) === false) {
			return $this->l10n->t('The period end is not a date (YYYY-MM-DD).');
		}

		if ($end < $start) {
			return $this->l10n->t('The period ends before it starts.');
		}

		$reading['periodStart'] = $start;
		$reading['periodEnd']   = $end;

		return $reading;

	}//end withPeriod()

	/**
	 * Whether a value is a real calendar date written YYYY-MM-DD.
	 *
	 * @param string $value The value.
	 *
	 * @return bool
	 */
	private function isDate(string $value): bool {
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
			return false;
		}

		return checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]);

	}//end isDate()

	/**
	 * The id of the one rate plan for a resource type in the administration, or null.
	 *
	 * @param string $administrationId The administration.
	 * @param string $resourceType     The metered resource.
	 *
	 * @return string|null The plan id, or null when there is none or more than one.
	 */
	private function planIdFor(string $administrationId, string $resourceType): ?string {
		$plans = [];
		foreach ($this->scoped(schema: 'UsageRatePlan')->findAll(['filters' => ['administrationId' => $administrationId, 'resourceType' => $resourceType], 'limit' => 2]) as $row) {
			$plan = ObjectIdentifier::recordWithId(candidate: $row);
			if ($plan !== null) {
				$plans[] = (string)($plan['id'] ?? '');
			}
		}

		if (count($plans) !== 1 || $plans[0] === '') {
			return null;
		}

		return $plans[0];

	}//end planIdFor()

	/**
	 * The object service on one schema of shillinq's register.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectServiceInterface
	 */
	private function scoped(string $schema): ObjectServiceInterface {
		return $this->objectService->setRegister($this->settings->getRegisterSlug())->setSchema($schema);

	}//end scoped()
}//end class
