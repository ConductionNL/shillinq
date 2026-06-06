<?php

/**
 * In-memory counter store used as the default binding in dev and tests.
 *
 * Keeps counts in process memory only — fine for unit tests and the
 * default container binding; production deployments swap in a Memcache /
 * APCu / DB-backed implementation. The TTL argument on increment is
 * accepted for API parity but ignored — counts live for the process
 * lifetime here.
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
 * Process-local counter store (unit-test default).
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-9
 */
final class InMemoryNotificationCounterStore implements NotificationCounterStoreInterface
{

    /**
     * Counter table keyed by helper-built key.
     *
     * @var array<string, int>
     */
    private array $counts = [];

    /**
     * @inheritDoc
     */
    public function get(string $key): int
    {
        return ($this->counts[$key] ?? 0);
    }//end get()

    /**
     * @inheritDoc
     */
    public function increment(string $key, int $ttl=0): int
    {
        unset($ttl);
        $this->counts[$key] = ($this->get(key: $key) + 1);
        return $this->counts[$key];
    }//end increment()

    /**
     * @inheritDoc
     */
    public function reset(string $key): void
    {
        unset($this->counts[$key]);
    }//end reset()

    /**
     * Reset every counter (test helper).
     *
     * @return void
     */
    public function clear(): void
    {
        $this->counts = [];
    }//end clear()

}//end class
