<?php

/**
 * NRV Write-down Request
 *
 * The input of POST /api/inventory/nrv-write-down as read from the request.
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
 * @spec openspec/specs/inventory-accounting-correctness/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCP\IRequest;

/**
 * The period and net realisable value per SKU of one write-down run.
 *
 * @spec openspec/specs/inventory-accounting-correctness/spec.md
 */
final class NrvWriteDownRequest {
	/**
	 * Allowed shape of an administration or SKU.
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-:\\/ ]{1,128}$/';

	/**
	 * The administration, trimmed.
	 *
	 * @var string
	 */
	public readonly string $administrationId;

	/**
	 * The period, trimmed.
	 *
	 * @var string
	 */
	public readonly string $periodId;

	/**
	 * The nrv_by_sku parameter as sent.
	 *
	 * @var mixed
	 */
	public readonly mixed $nrvBySkuRaw;

	/**
	 * Read the run from the request.
	 *
	 * @param IRequest $request The request.
	 */
	public function __construct(IRequest $request) {
		$this->administrationId = trim((string)$request->getParam('administration_id', ''));
		$this->periodId = trim((string)$request->getParam('period_id', ''));
		$this->nrvBySkuRaw = $request->getParam('nrv_by_sku', []);

	}//end __construct()

	/**
	 * Whether the administration, the period and a non-empty map are given.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/inventory-accounting-correctness/spec.md
	 */
	public function isComplete(): bool {
		return $this->administrationId !== ''
			&& $this->periodId !== ''
			&& is_array($this->nrvBySkuRaw) === true
			&& $this->nrvBySkuRaw !== [];

	}//end isComplete()

	/**
	 * Whether the administration and the period have their shape.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/inventory-accounting-correctness/spec.md
	 */
	public function isWellFormed(): bool {
		return preg_match(self::ID_PATTERN, $this->administrationId) === 1
			&& preg_match('/^[A-Za-z0-9_.\\-]{1,32}$/', $this->periodId) === 1;

	}//end isWellFormed()

	/**
	 * The NRV per unit by trimmed SKU; null when an entry has an invalid SKU
	 * or a non-numeric value. Only call on a complete request.
	 *
	 * @return array<string,float>|null
	 *
	 * @spec openspec/specs/inventory-accounting-correctness/spec.md
	 */
	public function nrvBySku(): ?array {
		$nrvBySku = [];
		foreach ($this->nrvBySkuRaw as $sku => $nrv) {
			$skuKey = trim((string)$sku);
			if (self::isEntry(sku: $skuKey, nrv: $nrv) === false) {
				return null;
			}

			$nrvBySku[$skuKey] = (float)$nrv;
		}

		return $nrvBySku;

	}//end nrvBySku()

	/**
	 * Whether an entry maps a valid SKU to a numeric value.
	 *
	 * @param string $sku The trimmed SKU.
	 * @param mixed $nrv The value.
	 *
	 * @return bool
	 */
	private static function isEntry(string $sku, mixed $nrv): bool {
		return $sku !== '' && preg_match(self::ID_PATTERN, $sku) === 1 && is_numeric($nrv) === true;

	}//end isEntry()
}//end class
