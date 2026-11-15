<?php

/**
 * Contract of DepreciationScheduleService
 *
 * The public surface of DepreciationScheduleService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of DepreciationScheduleService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DepreciationScheduleServiceInterface {

	public const ASSET = 'FixedAsset';

	/**
	 * The planned lines of an asset whose period ended before a month: the depreciation the run did not post.
	 *
	 * @param array<string,mixed> $asset     The FixedAsset record.
	 * @param string              $beforeMonth The first month not counted, YYYY-MM.
	 *
	 * @return list<array{period: string, amount: float, lineId: string}> The missed periods, oldest first.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function missed(array $asset, string $beforeMonth): array;

	/**
	 * Post the missed periods of an asset, one journal entry per period.
	 *
	 * @param array<string,mixed> $asset       The FixedAsset record.
	 * @param string              $beforeMonth The first month not counted, YYYY-MM.
	 *
	 * @return list<array{period: string, amount: float, lineId: string}> What was posted.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function postMissed(array $asset, string $beforeMonth): array;
}//end interface
