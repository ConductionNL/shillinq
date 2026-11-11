<?php

/**
 * The source a Commitment is materialised from.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Commitment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Commitment;

/**
 * One approved PurchaseOrder or activated Contract, as the materialisation
 * reads it (REQ-VPL-010). Built and read by CommitmentMaterialisationService.
 */
final class CommitmentSource {

	/**
	 * Source PO/contract business key, also the idempotency key.
	 *
	 * @var string
	 */
	public string $sourceReference = '';

	/**
	 * Commitment.soort enum value.
	 *
	 * @var string
	 */
	public string $kind = '';

	/**
	 * Owning administration.
	 *
	 * @var string
	 */
	public string $administrationId = '';

	/**
	 * Regel inputs built by the source-specific builder.
	 *
	 * @var array<int, array<string,mixed>>
	 */
	public array $ruleInputs = [];

	/**
	 * Embedded counterparty reference.
	 *
	 * @var array<string, mixed>
	 */
	public array $counterparty = [];

	/**
	 * Whether a budget denial throws (PO) or is logged (Contract).
	 *
	 * @var bool
	 */
	public bool $failClosed = false;
}//end class
