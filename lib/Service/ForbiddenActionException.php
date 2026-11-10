<?php

/**
 * Forbidden Action Exception
 *
 * Thrown by a service when the caller may see a record but may not perform
 * the act on it. Controllers answer it with 403 and its message.
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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use RuntimeException;

/**
 * The caller may not perform this act.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md
 */
class ForbiddenActionException extends RuntimeException {
}//end class
