<?php

/**
 * Compute BCF Claim Action
 *
 * The `compute` transition of BcfClaim: computes the compensable VAT for the
 * claim's administration and quarter from the posted GL lines and keeps the
 * total and the per-account breakdown on the claim (REQ-BCF-010).
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
 * @spec openspec/changes/public-sector-quarterly-returns/specs/bookkeeping-bcf-vat-compensation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use InvalidArgumentException;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Service\BcfClaimService;

/**
 * The `compute` transition's executor.
 *
 * @spec openspec/changes/public-sector-quarterly-returns/specs/bookkeeping-bcf-vat-compensation/spec.md
 */
class ComputeBcfClaimAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param BcfClaimService $claims Computes the compensable VAT of a quarter.
	 */
	public function __construct(
		private readonly BcfClaimService $claims,
	) {
	}//end __construct()

	/**
	 * Return the claim with its computed total and breakdown.
	 *
	 * @param array<string,mixed> $objectData   The claim with the transition applied.
	 * @param array<string,mixed> $previousData The claim before.
	 * @param array<string,mixed> $parameters   Declared parameters (none).
	 * @param string              $actionName   The action name.
	 *
	 * @return array<string,mixed> The claim to save.
	 *
	 * @throws InvalidArgumentException When the claim names no administration or quarter.
	 *
	 * @spec openspec/changes/public-sector-quarterly-returns/specs/bookkeeping-bcf-vat-compensation/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleActionInterface's.
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		$administrationId = trim((string)($objectData['administrationId'] ?? ''));
		$claimQuarter = trim((string)($objectData['claimQuarter'] ?? ''));
		if ($administrationId === '' || $claimQuarter === '') {
			throw new InvalidArgumentException('A BCF claim needs an administration and a claim quarter before it can be computed.');
		}

		$computed = $this->claims->computeClaim(administrationId: $administrationId, claimQuarter: $claimQuarter);
		$objectData['totalCompensableAmount'] = $computed['totalCompensableAmount'];
		$objectData['breakdown'] = $computed['breakdown'];

		return $objectData;

	}//end execute()
}//end class
