<?php

/**
 * App identity
 *
 * The app id on its own, so classes that only need the id do not depend on
 * the bootstrap class.
 *
 * @category AppInfo
 * @package  OCA\Shillinq\AppInfo
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

namespace OCA\Shillinq\AppInfo;

/**
 * Holds the app id.
 *
 * @spec exclude Infrastructure: the app id constant, no behaviour.
 */
final class AppIdentity {

	/**
	 * The app id, as appinfo/info.xml declares it.
	 *
	 * @var string
	 */
	public const APP_ID = 'shillinq';
}//end class
