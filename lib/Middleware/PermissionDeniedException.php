<?php

/**
 * Shillinq Permission Denied Exception
 *
 * Exception thrown when a user lacks the required permissions.
 *
 * @category Middleware
 * @package  OCA\Shillinq\Middleware
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Shillinq\Middleware;

/**
 * Exception thrown when permission checks fail.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-3
 */
class PermissionDeniedException extends \Exception
{
}//end class
