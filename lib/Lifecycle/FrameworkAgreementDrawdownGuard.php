<?php

/**
 * Framework Agreement Drawdown Guard
 *
 * Blocks a PurchaseOrder call-off that would draw a FrameworkAgreement above
 * its spend ceiling. A generic, jurisdiction-neutral procurement-governance
 * control abstracted from the retired purchaseq `raamovereenkomst-minicompetitie`
 * slug (purchaseq#5): a framework agreement authorises spend up to a ceiling and
 * PurchaseOrder call-offs draw it down; a call-off exceeding the remaining
 * ceiling — or against an inactive / out-of-validity agreement — is blocked
 * (REQ-PG-004).
 *
 * Reads via OpenRegister's ObjectService (ADR-022); re-implements no business
 * logic that lives elsewhere (ADR-031). Fail-closed: any unresolved check
 * denies the call-off (CWE-863).
 *
 * @category Guard
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/procurement-governance/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Precondition for a framework-agreement call-off: does it fit the remaining ceiling?
 *
 * @spec openspec/specs/procurement-governance/spec.md
 */
class FrameworkAgreementDrawdownGuard {
	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param IAppConfig $appConfig App config for register slug resolution.
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
	 * Assert a call-off of $addCents fits the agreement's remaining ceiling, or
	 * throw (REQ-PG-004).
	 *
	 * Fail-closed: throws when the agreement is missing, not `active`, outside its
	 * validity window, or when `drawnAmount + addCents > ceilingAmount`.
	 *
	 * @param string $administrationId Administration (tenant) scope.
	 * @param string $frameworkAgreementId FrameworkAgreement identifier (agreementNumber or object id).
	 * @param int $addCents Call-off amount in integer euro cents.
	 *
	 * @return array<string,mixed> The resolved FrameworkAgreement (for the caller to record the drawdown against).
	 *
	 * @throws RuntimeException When the call-off is not allowed.
	 *
	 * @spec openspec/specs/procurement-governance/spec.md
	 */
	public function assertWithinCeiling(string $administrationId, string $frameworkAgreementId, int $addCents): array {
		try {
			$agreement = $this->resolveAgreement(
				administrationId: $administrationId,
				frameworkAgreementId: $frameworkAgreementId
			);

			if ($agreement === null) {
				throw new RuntimeException('Framework agreement not found.');
			}

			$this->assertUsable(agreement: $agreement);

			$ceiling = (int)($agreement['ceilingAmount'] ?? 0);
			$drawn = (int)($agreement['drawnAmount'] ?? 0);
			if (($drawn + $addCents) > $ceiling) {
				$this->logger->info(
					'FrameworkAgreementDrawdownGuard: call-off exceeds ceiling — blocking purchase order',
					[
						'frameworkAgreementId' => $frameworkAgreementId,
						'ceilingAmount' => $ceiling,
						'drawnAmount' => $drawn,
						'addCents' => $addCents,
					]
				);
				throw new RuntimeException('Call-off exceeds the framework agreement ceiling.');
			}

			return $agreement;
		} catch (RuntimeException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->logger->error(
				'FrameworkAgreementDrawdownGuard: drawdown check failed — denying (fail-closed)',
				['frameworkAgreementId' => $frameworkAgreementId, 'exception' => $e->getMessage()]
			);
			throw new RuntimeException('Framework agreement drawdown check failed.');
		}//end try

	}//end assertWithinCeiling()

	/**
	 * Assert the agreement is active and within its validity window, or throw.
	 *
	 * @param array<string,mixed> $agreement The resolved FrameworkAgreement.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the agreement is not active or out of window.
	 */
	private function assertUsable(array $agreement): void {
		if ((string)($agreement['statusCode'] ?? '') !== 'active') {
			throw new RuntimeException('Framework agreement is not active.');
		}

		$today = date('Y-m-d');
		$validFrom = trim((string)($agreement['validFrom'] ?? ''));
		$validTo = trim((string)($agreement['validUntil'] ?? ''));
		if (($validFrom !== '' && $today < $validFrom) || ($validTo !== '' && $today > $validTo)) {
			throw new RuntimeException('Framework agreement is outside its validity window.');
		}

	}//end assertUsable()

	/**
	 * Resolve an agreement by agreementNumber, then by object id.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $frameworkAgreementId Reference (agreementNumber or id).
	 *
	 * @return array<string,mixed>|null
	 */
	private function resolveAgreement(string $administrationId, string $frameworkAgreementId): ?array {
		$byNumber = $this->findOne(
			schema: 'FrameworkAgreement',
			filters: [
				'administrationId' => $administrationId,
				'agreementNumber' => $frameworkAgreementId,
			]
		);
		if ($byNumber !== null) {
			return $byNumber;
		}

		return $this->findOne(
			schema: 'FrameworkAgreement',
			filters: [
				'administrationId' => $administrationId,
				'id' => $frameworkAgreementId,
			]
		);

	}//end resolveAgreement()

	/**
	 * Fetch one record via the real ObjectService API (findAll then first).
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findOne(string $schema, array $filters): ?array {
		$rows = $this->objectService
			->setRegister($this->register())
			->setSchema($schema)
			->findAll(['filters' => $filters]);

		foreach ($rows as $row) {
			if (is_array($row) === true) {
				return $row;
			}
		}

		return null;
	}//end findOne()

	/**
	 * Resolve the OpenRegister register slug from app config (defaults to "shillinq").
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
