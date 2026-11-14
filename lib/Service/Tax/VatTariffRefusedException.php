<?php

/**
 * VAT tariff refused
 *
 * A line named a VAT tariff that does not exist or is not valid on the document date.
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
 * @spec openspec/changes/tax-vat-rates-and-deductibility/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Tax;

use InvalidArgumentException;

/**
 * Names the tariff code and the date, so the refusal can be shown as is.
 *
 * @spec openspec/changes/tax-vat-rates-and-deductibility/tasks.md#task-2.1
 */
class VatTariffRefusedException extends InvalidArgumentException {

	/**
	 * Constructor.
	 *
	 * @param string $tariffCode The tariff code as the line named it.
	 * @param string $date The document date, Y-m-d.
	 * @param string $why  `unknown` or `expired`.
	 *
	 * @spec openspec/changes/tax-vat-rates-and-deductibility/tasks.md#task-2.1
	 */
	public function __construct(
		public readonly string $tariffCode,
		public readonly string $date,
		public readonly string $why,
	) {
		$message = sprintf('VAT tariff "%s" does not exist (document date %s).', $tariffCode, $date);
		if ($why === 'expired') {
			$message = sprintf('VAT tariff "%s" is not valid on the document date %s.', $tariffCode, $date);
		}

		parent::__construct(message: $message);

	}//end __construct()
}//end class
