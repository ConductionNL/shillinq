<?php

/**
 * What the consumer collection-cost hold looks at for one dunning stage.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Dunning
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-2.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Dunning;

use DateTimeImmutable;

/**
 * The run's params, the debtor, the ladder and the invoice's earlier runs,
 * at one moment.
 */
final class CostHoldRequest {

	/**
	 * The run's params.
	 *
	 * @var array<string,mixed>
	 */
	public array $params = [];

	/**
	 * The CustomerMaster, or [] when unknown.
	 *
	 * @var array<string,mixed>
	 */
	public array $customer = [];

	/**
	 * The invoice.
	 *
	 * @var array<string,mixed>
	 */
	public array $invoice = [];

	/**
	 * The ladder's stages.
	 *
	 * @var array<int,mixed>
	 */
	public array $stages = [];

	/**
	 * The invoice's earlier DunningRuns.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $runs = [];

	/**
	 * Now.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $now;
}//end class
