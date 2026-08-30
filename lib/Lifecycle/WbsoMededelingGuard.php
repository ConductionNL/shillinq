<?php

/**
 * WBSO Mededeling Guard
 *
 * ADR-031 exception-path lifecycle guard for the WbsoMededeling register
 * (bookkeeping-wbso-sno-administratie, T1). The submit / resubmit transition
 * precondition is referenced from the WbsoMededeling schema's
 * x-openregister-lifecycle because it requires a cross-schema lookup against
 * the covering WbsoBeschikking that OpenRegister's declarative `requires:`
 * clause cannot yet express:
 *
 *  - canSubmit(): the realised S&O hours reported on the mededeling MUST NOT
 *                 exceed the grantedSoHours ceiling of the WBSO-beschikking it
 *                 reports on, and that beschikking MUST still be in the
 *                 `granted` lifecycle state (REQ-WBSO-005). An expired or
 *                 withdrawn beschikking may not receive a new realisatie
 *                 filing.
 *
 * ADR-031 exception reason: the cross-schema ceiling comparison (mededeling
 * realisedSoHours vs. beschikking grantedSoHours, plus the beschikking state
 * gate) is not expressible in the declarative lifecycle DSL. When the engine
 * gains cross-schema relation conditions, replace this reference with a
 * declarative condition and delete this file.
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
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/specs.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle precondition guard for the WbsoMededeling submit / resubmit transitions.
 *
 * Referenced from the WbsoMededeling schema (register.d fragment)
 * x-openregister-lifecycle transitions.{submit,resubmit}.requires as
 * OCA\Shillinq\Lifecycle\WbsoMededelingGuard::canSubmit.
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/specs.md
 */
class WbsoMededelingGuard {
	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Returns true iff the mededeling may be submitted to RVO.
	 *
	 * REQ-WBSO-005: the realised S&O hours reported MUST NOT exceed the
	 * grantedSoHours ceiling of the WBSO-beschikking the mededeling reports on,
	 * and that beschikking MUST still be in the `granted` lifecycle state. The
	 * beschikking is resolved by its unique beschikkingNumber within the same
	 * register.
	 *
	 * Fail-closed: returns false on any exception, missing object, missing
	 * beschikkingNumber, an unresolvable / non-granted beschikking, or a
	 * realisatie that exceeds the granted ceiling (REQ-WBSO-005 / CWE-863).
	 *
	 * @param string $mededelingId The WbsoMededeling.id (unused;
	 *                             present for the lifecycle-engine
	 *                             call-signature parity).
	 * @param array<string,mixed>|null $object The WbsoMededeling object being transitioned.
	 *
	 * @return bool True when the mededeling may be submitted.
	 *
	 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/specs.md
	 */
	public function canSubmit(string $mededelingId, ?array $object = null): bool {
		try {
			if ($object === null) {
				return false;
			}

			$decisionNumber = (string)($object['decisionNumber'] ?? '');
			$administrationId = (string)($object['administrationId'] ?? '');
			if ($decisionNumber === '' || $administrationId === '') {
				return false;
			}

			// Use integer-cent arithmetic on the hour figures to avoid IEEE-754
			// equality issues at the ceiling boundary (realised == granted).
			$realisedCenti = (int)round((float)($object['realisedSoHours'] ?? -1) * 100);
			if ($realisedCenti < 0) {
				return false;
			}

			$decision = $this->resolveDecision(
				administrationId: $administrationId,
				decisionNumber: $decisionNumber
			);
			if ($decision === null) {
				return false;
			}

			// The beschikking must still be granted; an expired or withdrawn
			// beschikking may not receive a new realisatie filing.
			if (($decision['state'] ?? '') !== 'granted') {
				return false;
			}

			$grantedCenti = (int)round((float)($decision['grantedSoHours'] ?? 0) * 100);

			return $realisedCenti <= $grantedCenti;
		} catch (\Throwable $e) {
			$this->logger->error(
				'WbsoMededelingGuard: submit check failed — denying submit transition (fail-closed)',
				['mededelingId' => $mededelingId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canSubmit()

	/**
	 * Resolve the covering WbsoBeschikking by its unique beschikkingNumber,
	 * scoped to the administration so no cross-tenant beschikking can be
	 * referenced (REQ-WBSO-004).
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $decisionNumber The WbsoBeschikking.beschikkingNumber to look up.
	 *
	 * @return array<string,mixed>|null The beschikking object, or null when not found.
	 */
	private function resolveDecision(string $administrationId, string $decisionNumber): ?array {
		$register = $this->resolveRegister();

		$beschikkingen = $this->objectService
			->setRegister($register)
			->setSchema('WbsoBeschikking')
			->findAll(
				[
					'filters' => [
						'administrationId' => $administrationId,
						'decisionNumber' => $decisionNumber,
					],
				]
			);

		foreach ($beschikkingen as $decision) {
			if (is_array($decision) === true
				&& (string)($decision['decisionNumber'] ?? '') === $decisionNumber
				&& (string)($decision['administrationId'] ?? '') === $administrationId
			) {
				return $decision;
			}
		}

		return null;
	}//end resolveBeschikking()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to `shillinq`.
	 *
	 * @return string The register slug.
	 */
	private function resolveRegister(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end resolveRegister()
}//end class
