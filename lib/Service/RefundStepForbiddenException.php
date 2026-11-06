<?php

/**
 * Refund Step Forbidden Exception
 *
 * The signed-in user may not take this refund step: they are outside the
 * request's administration, or they approved the refund they now try to pay.
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
 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DomainException;

/**
 * A refund step the signed-in user may not take.
 *
 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md
 */
class RefundStepForbiddenException extends DomainException {
}//end class
