<?php

/**
 * Minimal DeepLinkRegistrationEvent stub for unit tests.
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;

/**
 * Stub for OCA\OpenRegister\Event\DeepLinkRegistrationEvent.
 */
class DeepLinkRegistrationEvent extends Event
{

    /**
     * Registered deep links keyed by schema / register tuple.
     *
     * @var array<int,array<string,mixed>>
     */
    private array $registrations = [];

    /**
     * Register a deep link.
     *
     * @param array<string,mixed> $registration The registration payload.
     *
     * @return void
     */
    public function register(array $registration): void
    {
        $this->registrations[] = $registration;

    }//end register()

    /**
     * Return all registered deep links.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getRegistrations(): array
    {
        return $this->registrations;

    }//end getRegistrations()
}//end class
