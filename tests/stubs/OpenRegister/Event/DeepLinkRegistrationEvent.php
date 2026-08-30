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
class DeepLinkRegistrationEvent extends Event {

	/**
	 * Registered deep links keyed by schema / register tuple.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $registrations = [];

	/**
	 * Register a deep link.
	 *
	 * Mirrors the real `OCA\OpenRegister\Event\DeepLinkRegistrationEvent::register()`
	 * signature (named parameters), so listener code under test can be
	 * exercised unchanged against this stub.
	 *
	 * @param string $appId The consuming app ID.
	 * @param string $registerSlug The register slug.
	 * @param string $schemaSlug The schema slug.
	 * @param string $urlTemplate URL template with placeholders (e.g. "{uuid}").
	 * @param string $icon Optional icon identifier.
	 * @param string|null $displayName Optional human-readable label.
	 *
	 * @return void
	 */
	public function register(
		string $appId,
		string $registerSlug,
		string $schemaSlug,
		string $urlTemplate,
		string $icon = '',
		?string $displayName = null,
	): void {
		$this->registrations[] = [
			'appId' => $appId,
			'registerSlug' => $registerSlug,
			'schemaSlug' => $schemaSlug,
			'urlTemplate' => $urlTemplate,
			'icon' => $icon,
			'displayName' => $displayName,
		];

	}//end register()

	/**
	 * Return all registered deep links.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function getRegistrations(): array {
		return $this->registrations;
	}//end getRegistrations()
}//end class
