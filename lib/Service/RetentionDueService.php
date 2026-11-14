<?php

/**
 * Shillinq Retention Due Service
 *
 * Which retention-bound records have reached the end of their retention
 * period. The end date of a record is its own reference date (a posting
 * date, an invoice date) plus the retention period of the rule that binds
 * its schema. A record under a legal hold is never due.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ledger-retention-due-records/tasks.md#1-retention-end-date
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use JsonSerializable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\Relation\RelationRecords;
use Throwable;

/**
 * Lists the records whose retention end date lies before today.
 *
 * @spec openspec/changes/ledger-retention-due-records/specs/bookkeeping-archiefwet-retention/spec.md#requirement-a-list-shows-the-records-whose-retention-period-has-ended-req-ret-015
 */
class RetentionDueService {

	/**
	 * The retention-bound schemas: the field holding each record's reference
	 * date, and the Selectielijst code of the rule that binds it.
	 *
	 * @var array<string, array{dateField: string, ruleCode: string}>
	 */
	public const BINDINGS = [
		'GLTransaction' => ['dateField' => 'postingDate', 'ruleCode' => '5.1.2'],
		'APInvoice'     => ['dateField' => 'invoiceDate', 'ruleCode' => '5.1.2'],
	];

	/**
	 * Dispositions that never make a record due.
	 */
	private const NEVER_DUE = ['keep_indefinite'];

	/**
	 * The most rules read for one Selectielijst code.
	 */
	private const RULE_LIMIT = 200;

	/**
	 * Constructor.
	 *
	 * @param RelationRecords        $records       Administration-scoped reads of the records and holds.
	 * @param ObjectServiceInterface $objectService Reads the rules, which may belong to no administration.
	 * @param RegisterLocation       $settings      Where the register lives.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RelationRecords $records,
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocation $settings,
	) {
	}//end __construct()

	/**
	 * The records of one administration whose retention period has ended, oldest end date first.
	 *
	 * @param string            $administrationId The administration.
	 * @param DateTimeImmutable $today            The day the list is drawn up.
	 *
	 * @return array<int, array{register: string, recordId: string, ruleCode: string, endDate: string, disposition: string}>
	 *
	 * @spec openspec/changes/ledger-retention-due-records/specs/bookkeeping-archiefwet-retention/spec.md#requirement-a-list-shows-the-records-whose-retention-period-has-ended-req-ret-015
	 */
	public function dueFor(string $administrationId, DateTimeImmutable $today): array {
		if ($administrationId === '') {
			return [];
		}

		$held = $this->heldIds(administrationId: $administrationId);

		$due = [];
		foreach (self::BINDINGS as $schema => $binding) {
			$rule = self::ruleFor(rules: $this->rules(code: $binding['ruleCode']), code: $binding['ruleCode'], administrationId: $administrationId);
			if ($rule === null) {
				continue;
			}

			$rows = $this->records->rows(schema: $schema, administrationId: $administrationId);
			$due  = array_merge(
				$due,
				self::due(records: $rows, schema: $schema, dateField: $binding['dateField'], rule: $rule, heldIds: $held, today: $today)
			);
		}

		usort($due, static fn (array $a, array $b): int => [$a['endDate'], $a['register'], $a['recordId']] <=> [$b['endDate'], $b['register'], $b['recordId']]);

		return $due;
	}//end dueFor()

	/**
	 * The records of one schema that are past their end date and not held.
	 *
	 * A record without a readable reference date, or a rule without a whole
	 * number of years, is never listed: an end date that cannot be computed
	 * is not an end date that has passed.
	 *
	 * @param array<int, array<string, mixed>> $records   The records.
	 * @param string                           $schema    Their schema.
	 * @param string                           $dateField The field holding the reference date.
	 * @param array<string, mixed>             $rule      The retention rule.
	 * @param array<int, string>               $heldIds   Ids of records under a legal hold.
	 * @param DateTimeImmutable                $today     The day the list is drawn up.
	 *
	 * @return array<int, array{register: string, recordId: string, ruleCode: string, endDate: string, disposition: string}>
	 *
	 * @spec openspec/changes/ledger-retention-due-records/specs/bookkeeping-archiefwet-retention/spec.md#requirement-a-list-shows-the-records-whose-retention-period-has-ended-req-ret-015
	 */
	public static function due(array $records, string $schema, string $dateField, array $rule, array $heldIds, DateTimeImmutable $today): array {
		$years       = self::years(rule: $rule);
		$disposition = (string) ($rule['disposition'] ?? '');
		if ($years === null || in_array($disposition, self::NEVER_DUE, true) === true) {
			return [];
		}

		$day = $today->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
		$due = [];
		foreach ($records as $record) {
			$id  = (string) ($record['id'] ?? '');
			$end = self::endDate(reference: ($record[$dateField] ?? null), years: $years);
			if ($id === '' || $end === null || $end >= $day) {
				continue;
			}

			if (($record['legalHold'] ?? false) === true || in_array($id, $heldIds, true) === true) {
				continue;
			}

			$due[] = [
				'register'    => $schema,
				'recordId'    => $id,
				'ruleCode'    => (string) ($rule['selectionListCode'] ?? ''),
				'endDate'     => $end,
				'disposition' => $disposition,
			];
		}//end foreach

		return $due;
	}//end due()

	/**
	 * The rule with a Selectielijst code: the administration's own override when there is one, else the statutory rule.
	 *
	 * Another administration's override is never used.
	 *
	 * @param array<int, array<string, mixed>> $rules            The rules with that code.
	 * @param string                           $code             The Selectielijst code.
	 * @param string                           $administrationId The administration.
	 *
	 * @return array<string, mixed>|null The rule, or null.
	 *
	 * @spec openspec/changes/ledger-retention-due-records/specs/bookkeeping-archiefwet-retention/spec.md#requirement-a-list-shows-the-records-whose-retention-period-has-ended-req-ret-015
	 */
	public static function ruleFor(array $rules, string $code, string $administrationId): ?array {
		$found = null;
		foreach ($rules as $rule) {
			$owner = (string) ($rule['administrationId'] ?? '');
			if ((string) ($rule['selectionListCode'] ?? '') !== $code || ($owner !== '' && $owner !== $administrationId)) {
				continue;
			}

			if ($found === null || $owner !== '') {
				$found = $rule;
			}
		}

		return $found;
	}//end ruleFor()

	/**
	 * Every rule with a Selectielijst code, statutory and overrides alike.
	 *
	 * @param string $code The Selectielijst code.
	 *
	 * @return array<int, array<string, mixed>> The rules.
	 */
	private function rules(string $code): array {
		$rows = $this->objectService
			->setRegister($this->settings->getRegisterSlug())
			->setSchema('RetentionRule')
			->findAll(['limit' => self::RULE_LIMIT, 'filters' => ['selectionListCode' => $code]]);

		$rules = [];
		foreach ($rows as $row) {
			if ($row instanceof JsonSerializable) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$rules[] = $row;
			}
		}

		return $rules;
	}//end rules()

	/**
	 * The retention period of a rule in years: an operator's longer period, else the statutory one.
	 *
	 * @param array<string, mixed> $rule The rule.
	 *
	 * @return integer|null The years, or null when the rule names no whole number of years.
	 */
	private static function years(array $rule): ?int {
		$statutory = $rule['retentionYears'] ?? null;
		if (is_int($statutory) === false || $statutory < 0) {
			return null;
		}

		$custom = $rule['customRetentionYears'] ?? null;
		if (is_int($custom) === true && $custom > $statutory) {
			return $custom;
		}

		return $statutory;
	}//end years()

	/**
	 * A reference date plus a number of years, as a date.
	 *
	 * @param mixed $reference The reference date.
	 * @param int   $years     The retention period.
	 *
	 * @return string|null The end date (Y-m-d), or null when the reference date cannot be read.
	 */
	private static function endDate(mixed $reference, int $years): ?string {
		if (is_string($reference) === false || trim($reference) === '') {
			return null;
		}

		try {
			$date = new DateTimeImmutable(trim($reference), new DateTimeZone('UTC'));
		} catch (Throwable) {
			return null;
		}

		return $date->modify('+'.$years.' years')->format('Y-m-d');
	}//end endDate()

	/**
	 * The ids of the documents of an administration that carry a legal hold.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<int, string> The document ids.
	 */
	private function heldIds(string $administrationId): array {
		$held = [];
		foreach ($this->records->rows(schema: 'DocumentRetention', administrationId: $administrationId, filters: ['legalHold' => true]) as $row) {
			if (($row['legalHold'] ?? false) === true && (string) ($row['documentId'] ?? '') !== '') {
				$held[] = (string) $row['documentId'];
			}
		}

		return $held;
	}//end heldIds()
}//end class
