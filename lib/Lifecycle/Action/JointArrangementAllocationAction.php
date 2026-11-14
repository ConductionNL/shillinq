<?php

/**
 * Joint Arrangement Allocation Action
 *
 * The executor of the `propose`, `confirm` and `invoice` transitions a
 * GRAllocationRun declares (public-sector-task-field-allocation, REQ-GRC-007
 * and REQ-GRC-008). The declared `step` parameter names which: propose fills
 * the run's lines and total, confirm refuses an empty run and a second run for
 * the same key and period, invoice writes the participants' draft
 * contribution invoices. A refusal aborts the transition.
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
 * @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-gr-consolidation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use DomainException;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Kernel\Contracts\JointArrangementAllocationInterface;

/**
 * Proposes, confirms or invoices an allocation run.
 *
 * @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-gr-consolidation/spec.md
 */
class JointArrangementAllocationAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param JointArrangementAllocationInterface $allocation Proposes, confirms and invoices the run.
	 */
	public function __construct(
		private readonly JointArrangementAllocationInterface $allocation,
	) {
	}//end __construct()

	/**
	 * Run the declared step on the run.
	 *
	 * @param array<string,mixed> $objectData   The run after the transition.
	 * @param array<string,mixed> $previousData The run before it.
	 * @param array<string,mixed> $parameters   The declared actionParameters, with `step`.
	 * @param string              $actionName   The declared action name.
	 *
	 * @return array<string,mixed> The run, proposed, confirmed or invoiced.
	 *
	 * @throws DomainException When the step is unknown or refuses the run.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleActionInterface's.
	 *
	 * @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-gr-consolidation/spec.md
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		return match ((string)($parameters['step'] ?? '')) {
			'propose' => $this->allocation->propose(run: $objectData),
			'confirm' => $this->allocation->confirm(run: $objectData),
			'invoice' => $this->allocation->invoice(run: $objectData),
			default   => throw new DomainException('The allocation run step is not known.'),
		};

	}//end execute()
}//end class
