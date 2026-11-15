<?php

/**
 * Shillinq Investment Deduction Tables
 *
 * The year-scoped tables an investment deduction is worked out from: the KIA
 * tiers, the Energielijst and the Milieulijst. Read from the register, and
 * from the tables shipped for that year when the register holds none.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\InvestmentDeduction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-investment-deduction-claims/specs/bookkeeping-investeringsaftrek/spec.md#requirement-a-years-deduction-is-calculated-per-scheme-req-idc-002
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\InvestmentDeduction;

use OCA\Shillinq\Service\RegisterBinding;

/**
 * Year-scoped lookups of the deduction tables.
 *
 * @spec openspec/changes/tax-investment-deduction-claims/specs/bookkeeping-investeringsaftrek/spec.md#requirement-a-years-deduction-is-calculated-per-scheme-req-idc-002
 */
class InvestmentDeductionTables {

	/**
	 * Per table: the schema, the shipped file's name part and its list key.
	 *
	 * @var array<string, array{schema: string, file: string, key: string}>
	 */
	private const TABLES = [
		'kia' => ['schema' => 'KIATier', 'file' => 'kia-tiers', 'key' => 'kiaTiers'],
		'energy' => ['schema' => 'EnergielijstCode', 'file' => 'energielijst', 'key' => 'energielijstCodes'],
		'environment' => ['schema' => 'MilieulijstCode', 'file' => 'milieulijst', 'key' => 'milieulijstCodes'],
	];

	/**
	 * Constructor.
	 *
	 * @param RegisterBinding $binding  The shillinq register.
	 * @param string          $seedsDir Where the shipped tables live.
	 */
	public function __construct(
		private readonly RegisterBinding $binding,
		private readonly string $seedsDir = __DIR__ . '/../../Settings/seeds',
	) {
	}//end __construct()

	/**
	 * The KIA tiers of a year, ordered by tier.
	 *
	 * @param int $year The year.
	 *
	 * @return array<int, array<string, mixed>> Empty when none are loaded.
	 *
	 * @spec openspec/changes/tax-investment-deduction-claims/specs/bookkeeping-investeringsaftrek/spec.md#requirement-a-years-deduction-is-calculated-per-scheme-req-idc-002
	 */
	public function kiaTiers(int $year): array {
		$tiers = $this->rows(table: 'kia', year: $year);
		usort($tiers, static fn (array $left, array $right): int => ((int)($left['tier'] ?? 0) <=> (int)($right['tier'] ?? 0)));

		return $tiers;
	}//end kiaTiers()

	/**
	 * An Energielijst code of a year, or null.
	 *
	 * @param string $code The code.
	 * @param int    $year The year.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/tax-investment-deduction-claims/specs/bookkeeping-investeringsaftrek/spec.md#requirement-a-years-deduction-is-calculated-per-scheme-req-idc-002
	 */
	public function energyCode(string $code, int $year): ?array {
		return $this->code(table: 'energy', code: $code, year: $year);
	}//end energyCode()

	/**
	 * A Milieulijst code of a year, or null.
	 *
	 * @param string $code The code.
	 * @param int    $year The year.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/tax-investment-deduction-claims/specs/bookkeeping-investeringsaftrek/spec.md#requirement-a-years-deduction-is-calculated-per-scheme-req-idc-002
	 */
	public function environmentCode(string $code, int $year): ?array {
		return $this->code(table: 'environment', code: $code, year: $year);
	}//end environmentCode()

	/**
	 * A code of a list table, or null.
	 *
	 * @param string $table The table.
	 * @param string $code  The code.
	 * @param int    $year  The year.
	 *
	 * @return array<string, mixed>|null
	 */
	private function code(string $table, string $code, int $year): ?array {
		$code = trim($code);
		if ($code === '') {
			return null;
		}

		foreach ($this->rows(table: $table, year: $year) as $row) {
			if ((string)($row['code'] ?? '') === $code) {
				return $row;
			}
		}

		return null;
	}//end code()

	/**
	 * A table's rows for a year: the register's, else the shipped ones.
	 *
	 * @param string $table The table.
	 * @param int    $year  The year.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(string $table, int $year): array {
		$config = self::TABLES[$table];
		$rows = [];
		foreach ($this->binding->schema(schema: $config['schema'])->findAll(['filters' => ['yearNumber' => $year]]) as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true && (int)($row['yearNumber'] ?? 0) === $year) {
				$rows[] = $row;
			}
		}

		if ($rows !== []) {
			return $rows;
		}

		return $this->shipped(file: $config['file'], key: $config['key'], year: $year);
	}//end rows()

	/**
	 * The rows of a shipped table for a year, or none.
	 *
	 * @param string $file The file's name part.
	 * @param string $key  The list key in the file.
	 * @param int    $year The year.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function shipped(string $file, string $key, int $year): array {
		$path = $this->seedsDir . '/investeringsaftrek-' . $file . '-' . $year . '.json';
		if (is_file($path) === false) {
			return [];
		}

		$data = json_decode((string)file_get_contents($path), true);
		$rows = [];
		foreach ((array)($data[$key] ?? []) as $row) {
			if (is_array($row) === true && (int)($row['yearNumber'] ?? $year) === $year) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end shipped()
}//end class
