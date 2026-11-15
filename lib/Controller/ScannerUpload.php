<?php

/**
 * Scanner Upload
 *
 * One accepted batch of mobile-scanner operations and the administration it is for.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

/**
 * A size-checked operations batch, scoped to the caller's active administration.
 *
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */
final class ScannerUpload {
	/**
	 * Hold the batch.
	 *
	 * @param array<mixed> $operations       The operations, at most MAX_BATCH_SIZE.
	 * @param string       $administrationId The administration in scope.
	 */
	public function __construct(
		public readonly array $operations,
		public readonly string $administrationId,
	) {
	}//end __construct()
}//end class
