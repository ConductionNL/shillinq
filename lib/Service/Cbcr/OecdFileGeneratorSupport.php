<?php

/**
 * Shillinq OECD XML file generator support
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Cbcr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Cbcr;

use DateTimeImmutable;
use DateTimeZone;

/**
 * What the country-by-country and the GloBE information return generators share:
 * the failing blocking checks as lines, the first schema errors as one line,
 * the time in UTC and the fiscal year of a return.
 *
 * The using class has an `IL10N $l10n` property; the check statements are
 * translated through it.
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
 */
trait OecdFileGeneratorSupport {

	/**
	 * "id: statement" for every mandatory check that failed.
	 *
	 * @param array<int,array{id:string,severity:string,passed:bool,statement:string}> $results The check results.
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-5.1
	 */
	private function failingBlockingChecks(array $results): array {
		$failing = [];
		foreach ($results as $result) {
			if ($result['passed'] === false && $result['severity'] === 'mandatory') {
				$failing[] = $result['id'] . ': ' . $this->l10n->t($result['statement']);
			}
		}

		return $failing;
	}//end failingBlockingChecks()

	/**
	 * The first three schema errors, each with its line, as one line.
	 *
	 * @param array<int,array{line:int,element:string,message:string}> $errors The errors.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	private static function firstSchemaErrors(array $errors): string {
		$lines = [];
		foreach (array_slice($errors, 0, 3) as $error) {
			$line = $error['message'];
			if ($error['line'] > 0) {
				$line = 'Line ' . $error['line'] . ': ' . $line;
			}

			$lines[] = $line;
		}

		return implode(' ', $lines);
	}//end firstSchemaErrors()

	/**
	 * Now, in UTC.
	 *
	 * @return DateTimeImmutable
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	private static function utcNow(): DateTimeImmutable {
		return new DateTimeImmutable('now', new DateTimeZone('UTC'));
	}//end utcNow()

	/**
	 * The fiscal year in a return's period, such as FY2026.
	 *
	 * @param array<string,mixed> $return The return.
	 *
	 * @return string The four digits, 0000 when the period names none.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	private static function fiscalYearOf(array $return): string {
		if (preg_match('/(\d{4})/', (string)($return['period'] ?? ''), $match) === 1) {
			return $match[1];
		}

		return '0000';
	}//end fiscalYearOf()
}//end trait
