<?php

/**
 * Resolves a trigger's recipient rule list into a concrete recipient list.
 *
 * Walks the configured `recipients` array on a BookingNotificationTrigger,
 * evaluates each rule's condition against the booking payload, and emits
 * one ResolvedRecipient per surviving rule. Each recipient carries the
 * resolved role, the channel priority order (per-rule override else
 * trigger default) and the resolved address (email / phone / chat id /
 * group reference) from the booking + caller-supplied directory map.
 *
 * The resolver is pure: no I/O, no database lookups. Callers supply
 * - the trigger config,
 * - the booking payload (`customer`, `organizer`, …),
 * - an admin-group descriptor (the `admin_group` role expands to a single
 *   logical recipient whose address resolution is delegated to the engine
 *   — group membership is not enumerated by this helper).
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
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Notification;

/**
 * Translates a trigger's recipient rule list into a flat recipient list.
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-4
 */
final class RecipientResolver
{

    /**
     * Constructor.
     *
     * @param RecipientConditionEvaluator $evaluator Condition expression evaluator.
     */
    public function __construct(private readonly RecipientConditionEvaluator $evaluator)
    {

    }//end __construct()

    /**
     * Resolve the recipient list for one trigger fire.
     *
     * @param array<string, mixed> $trigger Trigger config (recipients[], channels[]).
     * @param array<string, mixed> $booking Booking payload (customer, organizer, price, status, duration).
     *
     * @return array<int, array<string, mixed>> Ordered list of recipients:
     *     `[{role, channels:[…], address:string|null, name:string|null, skipReason:string|null}]`.
     *
     * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-4
     */
    public function resolve(array $trigger, array $booking): array
    {
        $rules           = (array) ($trigger['recipients'] ?? []);
        $defaultChannels = (array) ($trigger['channels'] ?? []);
        $variables       = ['booking' => $booking];

        $resolved = [];
        foreach ($rules as $rule) {
            if (is_array($rule) === false) {
                continue;
            }

            $role      = (string) ($rule['role'] ?? '');
            $condition = (isset($rule['condition']) === true ? (string) $rule['condition'] : null);

            $passes = $this->evaluator->evaluate(condition: $condition, variables: $variables);
            if ($passes === false) {
                $resolved[] = [
                    'role'       => $role,
                    'channels'   => [],
                    'address'    => null,
                    'name'       => null,
                    'skipReason' => 'condition-false',
                ];
                continue;
            }

            $channels = (array) ($rule['channels'] ?? $defaultChannels);

            $contact = $this->resolveContact(role: $role, booking: $booking);
            if ($contact === null) {
                $resolved[] = [
                    'role'       => $role,
                    'channels'   => $channels,
                    'address'    => null,
                    'name'       => null,
                    'skipReason' => 'no-recipient-address',
                ];
                continue;
            }

            $resolved[] = [
                'role'       => $role,
                'channels'   => $channels,
                'address'    => ($contact['address'] ?? null),
                'name'       => ($contact['name'] ?? null),
                'skipReason' => null,
            ];
        }//end foreach

        return $resolved;
    }//end resolve()

    /**
     * Resolve the contact details for one role from the booking payload.
     *
     * The `admin_group` role expands to a single logical recipient whose
     * address is the group name; the engine fans the group out at runtime.
     *
     * @param string               $role    Recipient role.
     * @param array<string, mixed> $booking Booking payload.
     *
     * @return array<string, string|null>|null Address + display name, or null on missing data.
     */
    private function resolveContact(string $role, array $booking): ?array
    {
        if ($role === 'customer') {
            $email = ($booking['customerEmail'] ?? ($booking['guestEmail'] ?? null));
            $name  = ($booking['customerName'] ?? ($booking['guestName'] ?? null));
            if ($email === null || $email === '') {
                return null;
            }

            return [
                'address' => (string) $email,
                'name'    => ($name === null ? null : (string) $name),
            ];
        }

        if ($role === 'organizer') {
            $email = ($booking['organizerEmail'] ?? null);
            $name  = ($booking['organizer'] ?? null);
            if ($email === null || $email === '') {
                return null;
            }

            return [
                'address' => (string) $email,
                'name'    => ($name === null ? null : (string) $name),
            ];
        }

        if ($role === 'admin_group') {
            return [
                'address' => 'group:admin',
                'name'    => 'Administrators',
            ];
        }

        if (str_starts_with($role, 'role:') === true) {
            return [
                'address' => $role,
                'name'    => substr($role, 5),
            ];
        }

        return null;
    }//end resolveContact()

}//end class
