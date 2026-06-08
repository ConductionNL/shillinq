<?php

/**
 * Twig-style template renderer for booking notifications.
 *
 * Substitutes `{{ booking.* }}`, `{{ recipient.* }}` and `{{ system.* }}`
 * variables in a notification subject or body. Supports nested dot-paths
 * (`booking.startTime`) and a small set of Twig-style filters required by
 * the templates seeded in bookings-email-templates:
 *
 *   - `| date('d-m-Y H:i')` — formats a date value with PHP's date()
 *     syntax (after parsing the input with strtotime()).
 *   - `| upper` / `| lower` — case helpers.
 *
 * Undefined variables render as the empty string instead of raising — the
 * REQ-BNT-002 contract makes broken bookings recoverable (the template
 * stays valid even if a field is missing from the payload). The renderer
 * is intentionally non-recursive (it only substitutes the variables in
 * the input string once) so rendered output cannot itself contain a
 * variable reference and become an injection vector.
 *
 * The pure-logic helper backs the declarative `renderTemplate(...)` expression
 * used in bookings-email-templates and the new
 * BookingNotificationTrigger fragment; OR's notification engine consumes
 * the same contract at runtime.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Notification;

/**
 * Pure-logic Twig-style variable substitution for notification templates.
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-3
 */
final class NotificationTemplateRenderer
{

    /**
     * Variable / filter pattern.
     *
     * Matches `{{ name.path | filter('arg') }}` with optional whitespace,
     * up to one filter, and either single-quoted or unquoted filter args.
     *
     * @var string
     */
    private const PATTERN = '/\{\{\s*([a-zA-Z][a-zA-Z0-9_\.]*)\s*(?:\|\s*([a-zA-Z]+)\s*(?:\(\s*\'([^\']*)\'\s*\))?)?\s*\}\}/';

    /**
     * Render a template string with the given variable map.
     *
     * @param string               $template  Raw template text (subject / body).
     * @param array<string, mixed> $variables Namespaced variable map (e.g. `['booking'=>['organizer'=>'…']]`).
     *
     * @return string Rendered output. Undefined variables become ''.
     *
     * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-3
     */
    public function render(string $template, array $variables): string
    {
        $result = preg_replace_callback(
            self::PATTERN,
            function (array $match) use ($variables): string {
                $path   = $match[1];
                $filter = ($match[2] ?? '');
                $arg    = ($match[3] ?? '');

                $value = $this->resolvePath(path: $path, variables: $variables);
                if ($value === null) {
                    return '';
                }

                if ($filter === '') {
                    return $this->stringify(value: $value);
                }

                return $this->applyFilter(filter: $filter, value: $value, arg: $arg);
            },
            $template
        );
        return ($result ?? '');
    }//end render()

    /**
     * Resolve a dotted path (e.g. `booking.organizer`) against the variable map.
     *
     * @param string               $path      Dot-separated key path.
     * @param array<string, mixed> $variables Namespaced variable map.
     *
     * @return mixed|null Resolved value or null when missing.
     */
    private function resolvePath(string $path, array $variables)
    {
        $segments = explode('.', $path);
        $cursor   = $variables;
        foreach ($segments as $segment) {
            if (is_array($cursor) === true && array_key_exists($segment, $cursor) === true) {
                $cursor = $cursor[$segment];
                continue;
            }

            return null;
        }

        return $cursor;
    }//end resolvePath()

    /**
     * Coerce a resolved value to a flat string for output.
     *
     * @param mixed $value Resolved value (scalar / null).
     *
     * @return string
     */
    private function stringify($value): string
    {
        if (is_bool($value) === true) {
            return ($value === true ? 'true' : 'false');
        }

        if (is_scalar($value) === true) {
            return (string) $value;
        }

        return '';
    }//end stringify()

    /**
     * Apply a Twig-style filter to a resolved value.
     *
     * @param string $filter Filter name (date / upper / lower).
     * @param mixed  $value  Resolved value.
     * @param string $arg    Optional filter argument (single-quoted string).
     *
     * @return string Filtered output.
     */
    private function applyFilter(string $filter, $value, string $arg): string
    {
        $string = $this->stringify(value: $value);
        if ($filter === 'date') {
            if ($string === '') {
                return '';
            }

            $ts = strtotime($string);
            if ($ts === false) {
                return '';
            }

            $format = ($arg !== '' ? $arg : 'd-m-Y H:i');
            return date($format, $ts);
        }

        if ($filter === 'upper') {
            return mb_strtoupper($string);
        }

        if ($filter === 'lower') {
            return mb_strtolower($string);
        }

        // Unknown filter — fail safe to the unfiltered value.
        return $string;
    }//end applyFilter()
}//end class
