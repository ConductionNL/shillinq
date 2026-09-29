<?php

/**
 * Shillinq PostingRefusedException
 *
 * A post guard refusing a posting for a reason the bookkeeper must read: the
 * control account a line is on, or the posting restriction a line breaks.
 * RegisterRequiresGuardAdapter turns it into the transition's refusal
 * message instead of the guard's generic one (ledger-booking-rules).
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ledger-booking-rules/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use RuntimeException;

/**
 * A refusal whose message is shown to the bookkeeper as it stands.
 *
 * @spec openspec/changes/ledger-booking-rules/tasks.md#task-2.2
 */
class PostingRefusedException extends RuntimeException {
}//end class
