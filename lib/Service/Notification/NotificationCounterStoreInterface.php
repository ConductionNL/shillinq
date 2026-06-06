<?php

/**
 * Counter / dedupe-cache port used by the booking notification helpers.
 *
 * Both the rate limiter (per-booking-hour, per-organizer-day) and the
 * deduplicator (per-recipient-trigger-bookingId within N minutes) need a
 * narrow incremental counter / lookup. Production deployments back this
 * with NC's IMemcache (APCu) or a small DB table; unit tests use an
 * in-memory fake. Keeping the port narrow keeps the pure-logic gates
 * trivially testable.
 *
 * The store is keyed by a (scope, identifier, window) tuple; the helper
 * provides the well-known key shapes.
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
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-9
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Notification;

/**
 * Counter / dedupe store port.
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-9
 */
interface NotificationCounterStoreInterface
{

    /**
     * Read the current count for the key.
     *
     * @param string $key Opaque key (helper-built).
     *
     * @return int Current count (0 when absent).
     */
    public function get(string $key): int;


    /**
     * Atomically increment the count for the key and return the new value.
     *
     * @param string $key Opaque key (helper-built).
     * @param int    $ttl Optional time-to-live in seconds (0 = caller managed).
     *
     * @return int New count after increment.
     */
    public function increment(string $key, int $ttl=0): int;


    /**
     * Reset the count for the key.
     *
     * Used by the admin monitor "reset rate-limits" action (REQ-BNT-008).
     *
     * @param string $key Opaque key (helper-built).
     *
     * @return void
     */
    public function reset(string $key): void;


}//end interface
