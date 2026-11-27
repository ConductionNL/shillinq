<?php

/**
 * Submit EU Declaration Action
 *
 * The declared action of EuDeclaration.submit: it submits every expenditure
 * the declaration holds, all or none (public-sector-grant-accountability
 * REQ-EUF-013). The work is EuDeclarationBuilder's.
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
 * @spec openspec/changes/archive/2026-11-24-public-sector-grant-accountability/specs/bookkeeping-single-audit-eu-fondsen/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use DomainException;
use OCA\OpenRegister\Lifecycle\ContextLifecycleActionInterface;
use OCA\OpenRegister\Lifecycle\LifecycleActionContext;
use OCA\Shillinq\Kernel\Contracts\EuDeclarationBuilderInterface;

/**
 * The EU declaration's submit transition executor.
 *
 * @spec openspec/changes/archive/2026-11-24-public-sector-grant-accountability/specs/bookkeeping-single-audit-eu-fondsen/spec.md
 */
class SubmitEuDeclarationAction implements ContextLifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param EuDeclarationBuilderInterface $declarations Submits a declaration's expenditure.
	 */
	public function __construct(
		private readonly EuDeclarationBuilderInterface $declarations,
	) {
	}//end __construct()

	/**
	 * Return the declaration after submitting its expenditure.
	 *
	 * @param LifecycleActionContext $context The transition; its objectData is the declaration with the transition applied.
	 *
	 * @return array<string,mixed> The declaration to save.
	 *
	 * @throws DomainException When an expenditure can no longer be submitted.
	 *
	 * @spec openspec/changes/archive/2026-11-24-public-sector-grant-accountability/specs/bookkeeping-single-audit-eu-fondsen/spec.md#requirement-submitting-a-declaration-submits-its-expenditures-req-euf-013
	 */
	public function execute(LifecycleActionContext $context): array {
		return $this->declarations->submit(declaration: $context->objectData);

	}//end execute()
}//end class
