<?php

/**
 * DeepLinkRegistration stub mirroring OCA\OpenRegister\Dto\DeepLinkRegistration on openregister main.
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Dto;

/**
 * Stub for OCA\OpenRegister\Dto\DeepLinkRegistration used by shillinq tests.
 */
final class DeepLinkRegistration {
	/**
	 * Constructor.
	 *
	 * @param string      $appId        The app that registers the link.
	 * @param string      $registerSlug The register slug.
	 * @param string      $schemaSlug   The schema slug.
	 * @param string      $urlTemplate  The url template, with `{uuid}`.
	 * @param string      $icon         The icon.
	 * @param string|null $displayName  The display name.
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $registerSlug,
		public readonly string $schemaSlug,
		public readonly string $urlTemplate,
		public readonly string $icon = '',
		public readonly ?string $displayName = null,
	) {
	}//end __construct()
}//end class
