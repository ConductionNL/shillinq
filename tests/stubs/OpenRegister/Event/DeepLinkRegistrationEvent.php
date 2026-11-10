<?php

/**
 * DeepLinkRegistrationEvent stub mirroring openregister main: registrations go to the registry.
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

use OCA\OpenRegister\Dto\DeepLinkRegistration;
use OCA\OpenRegister\Service\DeepLinkRegistryServiceInterface;
use OCP\EventDispatcher\Event;

/**
 * Stub for OCA\OpenRegister\Event\DeepLinkRegistrationEvent mirroring openregister main.
 */
class DeepLinkRegistrationEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param DeepLinkRegistryServiceInterface $registry The registry the links are added to.
	 */
	public function __construct(
		private readonly DeepLinkRegistryServiceInterface $registry,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The registry the links are added to.
	 *
	 * @return DeepLinkRegistryServiceInterface
	 */
	public function getRegistry(): DeepLinkRegistryServiceInterface {
		return $this->registry;
	}//end getRegistry()

	/**
	 * Register one deep link.
	 *
	 * @param string      $appId        The app.
	 * @param string      $registerSlug The register slug.
	 * @param string      $schemaSlug   The schema slug.
	 * @param string      $urlTemplate  The url template.
	 * @param string      $icon         The icon.
	 * @param string|null $displayName  The display name.
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
		$this->registry->add(
			registration: new DeepLinkRegistration(
				appId: $appId,
				registerSlug: $registerSlug,
				schemaSlug: $schemaSlug,
				urlTemplate: $urlTemplate,
				icon: $icon,
				displayName: $displayName
			)
		);
	}//end register()
}//end class
