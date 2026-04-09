<?php

/**
 * AccountInactiveException
 *
 * Thrown by PermissionGateMiddleware when a deactivated user attempts access.
 *
 * @category  Exception
 * @package   OCA\Shillinq\Exception
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://conduction.nl
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Shillinq\Exception;

/**
 * Thrown when an inactive Shillinq user attempts to access an endpoint.
 */
class AccountInactiveException extends \RuntimeException
{
}//end class
