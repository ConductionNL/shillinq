<?php

/**
 * One fixed-asset disposal posting, as FixedAssetDisposalService works it.
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
 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * The tenant, the normalised asset and its disposal terms, and what the
 * posting has written so far. Built and read by FixedAssetDisposalService only.
 *
 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
 */
final class AssetDisposalRun {

	/**
	 * Tenant scope.
	 *
	 * @var string
	 */
	public string $administrationId = '';

	/**
	 * The normalised FixedAsset record.
	 *
	 * @var array<string,mixed>
	 */
	public array $asset = [];

	/**
	 * Disposal date, accounting treatment and proceeds.
	 *
	 * @var array<string,mixed>
	 */
	public array $disposal = [];

	/**
	 * Whether the gain goes to a reinvestment reserve.
	 *
	 * @var bool
	 */
	public bool $toReserve = false;

	/**
	 * The posting date of the journal (Y-m-d).
	 *
	 * @var string
	 */
	public string $postingDate = '';

	/**
	 * The fiscal period id (YYYY-MM).
	 *
	 * @var string
	 */
	public string $periodId = '';

	/**
	 * The saved GLTransaction.
	 *
	 * @var array<string,mixed>
	 */
	public array $transaction = [];

	/**
	 * The saved GLLines.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $lines = [];
}//end class
