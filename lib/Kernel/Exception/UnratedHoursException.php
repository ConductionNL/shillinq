<?php

/**
 * Unrated Hours Exception
 *
 * Raised when an invoice draft holds an hour that has no billable rate
 * (REQ-BRPH-002). Each unrated hour is named by its person and date, so the
 * controller can refuse the draft with HTTP 422 and a message the project
 * manager can act on, and no invoice is written.
 *
 * @category Service
 * @package  OCA\Shillinq\Kernel\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Exception;

use DateTimeImmutable;

/**
 * The hours of an invoice draft that have no rate.
 *
 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-3.1
 */
class UnratedHoursException extends \RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param array<int, array{person: string, date: string, reason: string}> $hours The unrated hours: person, date (Y-m-d) and the no-rate message.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-3.1
	 */
	public function __construct(private readonly array $hours) {
		parent::__construct(message: self::sentence(hours: $hours));
	}//end __construct()

	/**
	 * The unrated hours.
	 *
	 * @return array<int, array{person: string, date: string, reason: string}> The hours.
	 *
	 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-3.1
	 */
	public function getHours(): array {
		return $this->hours;
	}//end getHours()

	/**
	 * One sentence naming every unrated hour's person and date.
	 *
	 * @param array<int, array{person: string, date: string, reason: string}> $hours The unrated hours.
	 *
	 * @return string The sentence, e.g. "No rate for Dana on 14 October 2026."
	 *
	 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-3.1
	 */
	public static function sentence(array $hours): string {
		$named = [];
		foreach ($hours as $hour) {
			$named[] = sprintf('%s on %s', $hour['person'], self::longDate(date: $hour['date']));
		}

		return 'No rate for ' . implode(', ', array_values(array_unique($named))) . '.';
	}//end sentence()

	/**
	 * A Y-m-d date as "14 October 2026"; any other text as it is.
	 *
	 * @param string $date The date.
	 *
	 * @return string The date in words.
	 */
	private static function longDate(string $date): string {
		$parsed = DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10));
		if ($parsed === false) {
			return $date;
		}

		return $parsed->format('j F Y');
	}//end longDate()
}//end class
