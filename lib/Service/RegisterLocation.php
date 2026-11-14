<?php

/**
 * Shillinq register location
 *
 * Answers where Shillinq's records live: whether OpenRegister is installed,
 * and which register slug the app is configured to use.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\RegisterLocationInterface;
use OCP\App\IAppManager;
use OCP\IAppConfig;

/**
 * Resolves the OpenRegister register Shillinq reads and writes.
 *
 * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md
 */
class RegisterLocation implements RegisterLocationInterface {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig  $appConfig  The app config holding the register slug
	 * @param IAppManager $appManager The app manager that knows whether OpenRegister is installed
	 *
	 * @return void
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private IAppManager $appManager,
	) {
	}//end __construct()

	/**
	 * Check whether OpenRegister is installed and available.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md#task-1
	 */
	public function isOpenRegisterAvailable(): bool {
		return $this->appManager->isInstalled('openregister');
	}//end isOpenRegisterAvailable()

	/**
	 * Return the configured register slug, falling back to 'shillinq' if unset.
	 *
	 * Single source of truth for the register slug — mirrors the dynamic slug
	 * resolution already used by DeepLinkRegistrationListener (L3/C3).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md
	 */
	public function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()
}//end class
