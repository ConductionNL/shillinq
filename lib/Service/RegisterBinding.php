<?php

/**
 * Register binding
 *
 * Points OpenRegister's object service at the app's configured register and a
 * schema, resolving the register slug from app config on every use exactly as
 * the services did inline.
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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
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
 * The object service bound to the app's register.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
 */
final class RegisterBinding {
	/**
	 * Bind the object service to the app config that names the register.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param IAppConfig             $appConfig     App config for the register slug.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The object service set to the app's register and the given schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectServiceInterface The object service, ready for one call.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
	 */
	public function schema(string $schema): ObjectServiceInterface {
		return $this->objectService
			->setRegister($this->register())
			->setSchema($schema);
	}//end schema()

	/**
	 * Resolve the OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
	 */
	public function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
