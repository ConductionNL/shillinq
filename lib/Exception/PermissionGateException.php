<?php

/**
 * Shillinq Permission Gate Exception
 *
 * Thrown by PermissionGateMiddleware when a request must be blocked. Handled
 * in afterException() to return a well-formed JSON error response.
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
 * Exception thrown by PermissionGateMiddleware to block a request.
 *
 * Using a dedicated typed exception ensures that only explicit gate denials are
 * caught in afterException() — generic exceptions from controllers are still
 * propagated normally.
 */
class PermissionGateException extends \RuntimeException
{
    /**
     * Create a new gate exception.
     *
     * @param string $message    The error message returned to the client
     * @param int    $statusCode HTTP status code for the JSON response
     */
    public function __construct(string $message, int $statusCode=403)
    {
        parent::__construct(message: $message, code: $statusCode);
    }//end __construct()
}//end class
