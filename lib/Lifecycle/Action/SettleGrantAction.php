<?php

/**
 * Settle Grant Action
 *
 * The declared action of Subsidie.vaststellen (determine): it sets the
 * reclaimed amount and writes the draft reclaim invoice or payable when more
 * was paid out than determined (public-sector-grant-accountability design
 * D1, REQ-SUBV-011). The work is GrantSettlement's.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-public-sector-grant-accountability/specs/bookkeeping-subsidie-verantwoording/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use DomainException;
use OCA\OpenRegister\Lifecycle\ContextLifecycleActionInterface;
use OCA\OpenRegister\Lifecycle\LifecycleActionContext;
use OCA\Shillinq\Kernel\Contracts\GrantSettlementInterface;

/**
 * The determine transition's executor.
 *
 * @spec openspec/changes/archive/2026-11-24-public-sector-grant-accountability/specs/bookkeeping-subsidie-verantwoording/spec.md
 */
class SettleGrantAction implements ContextLifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param GrantSettlementInterface $settlement Settles a determined grant.
	 */
	public function __construct(
		private readonly GrantSettlementInterface $settlement,
	) {
	}//end __construct()

	/**
	 * Return the grant with its reclaim settled.
	 *
	 * @param LifecycleActionContext $context The transition; its objectData is the grant with the transition applied.
	 *
	 * @return array<string,mixed> The grant to save.
	 *
	 * @throws DomainException When the grant cannot be settled.
	 *
	 * @spec openspec/changes/archive/2026-11-24-public-sector-grant-accountability/specs/bookkeeping-subsidie-verantwoording/spec.md#requirement-determining-a-grant-settles-the-difference-req-subv-011
	 */
	public function execute(LifecycleActionContext $context): array {
		return $this->settlement->settle(grant: $context->objectData);

	}//end execute()
}//end class
