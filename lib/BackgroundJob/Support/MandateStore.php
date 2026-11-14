<?php

/**
 * Mandate Store
 *
 * The OpenRegister object service and register the SEPA mandate jobs read and save through.
 *
 * @category BackgroundJob
 * @package  OCA\Shillinq\BackgroundJob\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob\Support;

/**
 * The resolved ObjectService and register slug, scoped to SepaMandate on every use.
 *
 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
 */
final class MandateStore {
	/**
	 * Hold the store.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $register      The register slug.
	 */
	public function __construct(
		private readonly object $objectService,
		private readonly string $register,
	) {
	}//end __construct()

	/**
	 * The object service scoped to the register and the SepaMandate schema.
	 *
	 * @return object
	 */
	public function scoped(): object {
		return $this->objectService->setRegister($this->register)->setSchema('SepaMandate');
	}//end scoped()
}//end class
