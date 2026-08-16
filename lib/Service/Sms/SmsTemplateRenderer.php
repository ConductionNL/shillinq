<?php

/**
 * SMS template renderer.
 *
 * Pure-logic helper that renders a {{variable}} message template against a
 * variable map, applying the channel's truncation rules and reporting the SMS
 * segment count. The same rendering contract is declared on the schema as the
 * x-openregister-calculations.renderedPreview field (ADR-031); this helper is
 * the unit-tested reference implementation the dispatcher uses at send time,
 * which the calculation DSL string cannot express or test on its own.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Sms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-19
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Sms;

/**
 * Side-effect-free {{variable}} substitution with truncation and segmentation.
 *
 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-19
 */
final class SmsTemplateRenderer {

	/**
	 * The variables a booking SMS template may reference.
	 *
	 * @var array<int, string>
	 */
	public const ALLOWED_VARIABLES = [
		'customerName',
		'bookingRef',
		'bookingDate',
		'bookingTime',
		'bookingLocation',
		'organizationName',
		'bookingUrl',
	];

	/**
	 * Maximum characters in a single (GSM-7) SMS segment.
	 */
	public const SINGLE_SEGMENT_LENGTH = 160;

	/**
	 * Characters per segment when a message is concatenated (UDH overhead).
	 */
	public const CONCAT_SEGMENT_LENGTH = 153;

	/**
	 * Per-variable maximum length before truncation with an ellipsis.
	 *
	 * @var array<string, int>
	 */
	private const TRUNCATE_LIMITS = [
		'bookingLocation' => 30,
		'organizationName' => 20,
	];

	/**
	 * Render a template by substituting {{variable}} placeholders.
	 *
	 * Unknown/undefined variables render as an empty string. Values for
	 * bookingLocation and organizationName are truncated with an ellipsis
	 * when they exceed their per-variable limit (REQ-SMS-010).
	 *
	 * @param string $template The message template.
	 * @param array<string, scalar> $variables Variable name => value map.
	 *
	 * @return string The rendered message body.
	 *
	 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-19
	 */
	public function render(string $template, array $variables): string {
		return (string)preg_replace_callback(
			'/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
			function (array $match) use ($variables): string {
				$name = $match[1];
				if (array_key_exists($name, $variables) === false) {
					return '';
				}

				$value = (string)$variables[$name];
				if (isset(self::TRUNCATE_LIMITS[$name]) === true) {
					$value = $this->truncate(value: $value, max: self::TRUNCATE_LIMITS[$name]);
				}

				return $value;
			},
			$template
		);

	}//end render()

	/**
	 * Truncate a value to a maximum length, appending an ellipsis ("…")
	 * when it is shortened. The ellipsis counts toward the limit.
	 *
	 * @param string $value The value to truncate.
	 * @param int $max Maximum length including the ellipsis.
	 *
	 * @return string The (possibly) truncated value.
	 *
	 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-20
	 */
	public function truncate(string $value, int $max): string {
		if (mb_strlen($value) <= $max) {
			return $value;
		}

		if ($max <= 1) {
			return mb_substr($value, 0, $max);
		}

		return mb_substr($value, 0, ($max - 1)) . '…';
	}//end truncate()

	/**
	 * Number of SMS segments a rendered body requires.
	 *
	 * ≤160 chars = 1 segment; longer messages are split into 153-char
	 * concatenated segments (REQ-SMS-003).
	 *
	 * @param string $body The rendered message body.
	 *
	 * @return int Segment count (minimum 1).
	 *
	 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-13
	 */
	public function segmentCount(string $body): int {
		$len = mb_strlen($body);
		if ($len <= self::SINGLE_SEGMENT_LENGTH) {
			return 1;
		}

		return (int)ceil($len / self::CONCAT_SEGMENT_LENGTH);
	}//end segmentCount()

	/**
	 * Whether a body fits in a single SMS segment.
	 *
	 * @param string $body The rendered message body.
	 *
	 * @return bool True when the body is ≤160 characters.
	 *
	 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-13
	 */
	public function fitsSingleSegment(string $body): bool {
		return mb_strlen($body) <= self::SINGLE_SEGMENT_LENGTH;
	}//end fitsSingleSegment()

	/**
	 * The set of {{variables}} referenced by a template that are NOT in the
	 * allowed set (REQ-SMS-009). An empty result means the template is valid.
	 *
	 * @param string $template The message template.
	 *
	 * @return array<int, string> Disallowed variable names (deduplicated).
	 *
	 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-14
	 */
	public function unknownVariables(string $template): array {
		preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $template, $matches);
		$used = array_unique($matches[1]);
		$unknown = array_values(array_diff($used, self::ALLOWED_VARIABLES));

		return $unknown;
	}//end unknownVariables()
}//end class
