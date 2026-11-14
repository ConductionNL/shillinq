<?php

/**
 * Supplementary return filing unavailable.
 *
 * Thrown when a prepared supplementary return is to be filed while no
 * Digipoort connection is bound: the return is then downloaded and filed by
 * hand (REQ-VPS-002).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Tax
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Tax;

use RuntimeException;

/**
 * No Digipoort connection is bound, so the supplementary return cannot be filed from here.
 *
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.2
 */
class SuppletieFilingUnavailableException extends RuntimeException {
}//end class
