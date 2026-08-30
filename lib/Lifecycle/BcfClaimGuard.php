<?php

/**
 * BCF Claim Guard
 *
 * ADR-031 exception-path lifecycle guard for the BcfClaim register
 * (bookkeeping-bcf-vat-compensation, T3). One precondition is referenced from
 * the BcfClaim schema's x-openregister-lifecycle transition because it requires
 * cross-schema lookups / arithmetic that OpenRegister's declarative `requires:`
 * clause cannot yet express:
 *
 *  - canSubmit(): a draft claim may only transition to submitted when the
 *                 computed totalCompensableAmount is strictly positive (a
 *                 non-empty claim) AND the claim quarter's fiscal period is
 *                 closed (period-lock from T2 period-close), so no claim is
 *                 filed for an open quarter (REQ-BCF-003). The compensable
 *                 total is recomputed server-side from the GL, never trusted
 *                 from the client-supplied object.
 *
 * ADR-031 exception reason: the submit precondition combines a cross-schema
 * GL -> BbvAccountMapping weighted aggregation (compensable total) with a
 * FiscalPeriod close-state lookup, neither of which is expressible in the
 * declarative lifecycle DSL. When the engine gains those capabilities, replace
 * this reference with declarative conditions and delete this file.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-bcf-vat-compensation/specs.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\BcfClaimService;
use OCA\Shillinq\Service\BcfCompensationCalculator;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle precondition guard for the BcfClaim draft -> submitted transition.
 *
 * Referenced from the BcfClaim schema (register.d fragment)
 * x-openregister-lifecycle transitions.submit.requires as
 * OCA\Shillinq\Lifecycle\BcfClaimGuard::canSubmit.
 *
 * @spec openspec/changes/bookkeeping-bcf-vat-compensation/specs.md
 */
class BcfClaimGuard {
	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param BcfClaimService $claimService Server-authoritative claim computation.
	 * @param BcfCompensationCalculator $calculator Pure-logic submit-precondition helper.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly BcfClaimService $claimService,
		private readonly BcfCompensationCalculator $calculator,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Returns true iff the claim is non-empty and its quarter is closed (REQ-BCF-003).
	 *
	 * The compensable total is recomputed from the GL for the claim's
	 * administration + quarter (never trusted from the client object), and the
	 * quarter's FiscalPeriod must be closed. Fail-closed: returns false on any
	 * exception, a missing administration/quarter, an empty claim, or an open
	 * quarter (CWE-863).
	 *
	 * @param string $bcfClaimId The BcfClaim.id being transitioned.
	 * @param array<string,mixed>|null $object The BcfClaim object being transitioned.
	 *
	 * @return bool True when the claim may transition to submitted.
	 *
	 * @spec openspec/changes/bookkeeping-bcf-vat-compensation/specs.md
	 */
	public function canSubmit(string $bcfClaimId, ?array $object = null): bool {
		try {
			$claim = $object;
			if ($claim === null || isset($claim['claimQuarter']) === false) {
				$claim = $this->resolveClaim(bcfClaimId: $bcfClaimId);
			}

			if ($claim === null) {
				return false;
			}

			$administrationId = (string)($claim['administrationId'] ?? '');
			$claimQuarter = (string)($claim['claimQuarter'] ?? '');
			if ($administrationId === '' || $claimQuarter === '') {
				return false;
			}

			// Server-authoritative recomputation — never trust the client total.
			$computed = $this->claimService->computeClaim(
				administrationId: $administrationId,
				claimQuarter: $claimQuarter
			);

			$quarterClosed = $this->isQuarterClosed(
				administrationId: $administrationId,
				claimQuarter: $claimQuarter
			);

			return $this->calculator->canSubmit(
				compensableTotal: $computed['totalCompensableAmount'],
				quarterClosed: $quarterClosed
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'BcfClaimGuard: submit precondition check failed — denying submit transition (fail-closed)',
				['bcfClaimId' => $bcfClaimId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end canSubmit()

	/**
	 * Resolve the BcfClaim object by id when it was not supplied to the guard.
	 *
	 * @param string $bcfClaimId The BcfClaim.id to resolve.
	 *
	 * @return array<string,mixed>|null The claim object, or null when not found.
	 */
	private function resolveClaim(string $bcfClaimId): ?array {
		if ($bcfClaimId === '') {
			return null;
		}

		$claims = $this->objectService
			->setRegister($this->register())
			->setSchema('BcfClaim')
			->findAll(['filters' => ['id' => $bcfClaimId]]);

		foreach ($claims as $claim) {
			$id = (string)($claim['id'] ?? ($claim['@self']['id'] ?? ''));
			if ($id === $bcfClaimId || isset($claim['claimQuarter']) === true) {
				return $claim;
			}
		}

		return null;
	}//end resolveClaim()

	/**
	 * Determine whether the claim quarter's fiscal period is closed (REQ-BCF-003).
	 *
	 * Looks up the administration's FiscalPeriod for the claim quarter; the
	 * quarter is closed when a matching period exists with a closed/locked
	 * status. A missing period or an open status fails closed (the quarter is
	 * treated as not closed), preventing a mid-quarter submission.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $claimQuarter Quarter identifier (FiscalPeriod.periodId).
	 *
	 * @return bool True when the quarter's period is closed.
	 */
	private function isQuarterClosed(string $administrationId, string $claimQuarter): bool {
		$periods = $this->objectService
			->setRegister($this->register())
			->setSchema('FiscalPeriod')
			->findAll(
				['filters' => ['administrationId' => $administrationId, 'periodId' => $claimQuarter]]
			);

		$closedStatuses = ['closed', 'locked', 'gesloten', 'vergrendeld'];
		foreach ($periods as $period) {
			$status = strtolower((string)($period['status'] ?? ($period['state'] ?? '')));
			if (in_array($status, $closedStatuses, true) === true) {
				return true;
			}

			if (($period['closed'] ?? false) === true || ($period['isClosed'] ?? false) === true) {
				return true;
			}
		}

		return false;
	}//end isQuarterClosed()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
