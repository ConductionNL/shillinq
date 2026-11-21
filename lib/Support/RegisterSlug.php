<?php

/**
 * Shillinq RegisterSlug
 *
 * The slug of the OpenRegister register this app keeps its objects in: the
 * `register` app config value, or `shillinq` when that value is empty.
 *
 * @category Support
 * @package  OCA\Shillinq\Support
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

namespace OCA\Shillinq\Support;

use OCA\Shillinq\Kernel\AppIdentity;
use OCP\IAppConfig;

/**
 * Reads the configured register slug.
 *
 * @spec exclude infrastructure utility with no feature requirement of its own; it is
 *   exercised through the features that call it
 */
final class RegisterSlug {

	/**
	 * The default register slug, used when the app config value is empty.
	 */
	public const DEFAULT_SLUG = 'shillinq';

	/**
	 * The configured register slug, `shillinq` when none is set.
	 *
	 * @param IAppConfig $config The app config.
	 *
	 * @return string
	 *
	 * @spec exclude infrastructure utility with no feature requirement of its own; it is
	 *   exercised through the features that call it
	 */
	public static function of(IAppConfig $config): string {
		$register = $config->getValueString(AppIdentity::APP_ID, 'register', self::DEFAULT_SLUG);
		if ($register === '') {
			return self::DEFAULT_SLUG;
		}

		return $register;
	}//end of()
}//end class
