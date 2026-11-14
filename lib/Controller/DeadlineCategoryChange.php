<?php

/**
 * Deadline Category Change
 *
 * One known deadline-calendar category's submitted toggle and reminder lead
 * time, as read from a settings update.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/compliance-deadline-calendar/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\Contracts\ComplianceDeadlineCalendarServiceInterface;

/**
 * What a settings update asks for one known category.
 *
 * @spec openspec/specs/compliance-deadline-calendar/spec.md
 */
final class DeadlineCategoryChange {
	/**
	 * The category.
	 *
	 * @var string
	 */
	public readonly string $category;

	/**
	 * The submitted toggle, null when not submitted.
	 *
	 * @var boolean|null
	 */
	public readonly ?bool $enabled;

	/**
	 * The submitted lead time in days, null when not submitted.
	 *
	 * @var integer|null
	 */
	public readonly ?int $leadDays;

	/**
	 * Read one category's submitted settings.
	 *
	 * @param string              $category The known category.
	 * @param array<string,mixed> $settings The submitted settings.
	 */
	private function __construct(string $category, array $settings) {
		$enabled = null;
		if (array_key_exists('enabled', $settings) === true) {
			$enabled = (bool)$settings['enabled'];
		}

		$leadDays = null;
		if (array_key_exists('leadDays', $settings) === true) {
			$leadDays = (int)$settings['leadDays'];
		}

		$this->category = $category;
		$this->enabled = $enabled;
		$this->leadDays = $leadDays;

	}//end __construct()

	/**
	 * The change for one submitted entry, or null when the entry is malformed
	 * (a non-string key or non-object settings) or names an unknown category.
	 *
	 * @param mixed $category The submitted key.
	 * @param mixed $settings The submitted settings.
	 *
	 * @return self|null
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public static function fromEntry(mixed $category, mixed $settings): ?self {
		if (is_string($category) === false || is_array($settings) === false) {
			return null;
		}

		if (in_array($category, ComplianceDeadlineCalendarServiceInterface::CATEGORIES, true) === false) {
			return null;
		}

		return new self(category: $category, settings: $settings);
	}//end fromEntry()
}//end class
