<?php

/**
 * Shillinq Register
 *
 * The OpenRegister register shillinq stores its objects in: the slug comes
 * from app config (`register`), defaulting to `shillinq`, and the object
 * service is scoped to it one schema at a time.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/budget-projection-engine/spec.md#req-bpe-009
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCP\IAppConfig;

/**
 * Scopes the OpenRegister object service to the configured shillinq register.
 *
 * @spec openspec/specs/budget-projection-engine/spec.md#req-bpe-009
 */
final class ShillinqRegister {

	/**
	 * Hold the app config and the object service.
	 *
	 * @param IAppConfig             $appConfig     App config (OpenRegister register slug).
	 * @param ObjectServiceInterface $objectService OpenRegister object service (ADR-083/084).
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * The object service scoped to the register and the given schema. The
	 * register slug is read from app config on every call.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectServiceInterface The scoped object service.
	 *
	 * @spec openspec/specs/budget-projection-engine/spec.md#req-bpe-009
	 */
	public function schema(string $schema): ObjectServiceInterface {
		return $this->objectService
			->setRegister($this->slug())
			->setSchema($schema);
	}//end schema()

	/**
	 * Resolve the OpenRegister register slug from app config.
	 *
	 * @return string The register slug, defaulting to `shillinq`.
	 *
	 * @spec openspec/specs/budget-projection-engine/spec.md#req-bpe-009
	 */
	public function slug(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end slug()
}//end class
