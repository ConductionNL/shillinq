<?php

/**
 * Segregated Ledger Guard
 *
 * ADR-031 exception-path lifecycle guard for the SegregatedLedger register
 * (bookkeeping-single-audit-eu-fondsen, T3 regulatory + compliance):
 *
 *  - canClose(): a gesegregeerde EU-administratie may only be closed once the
 *                reguliere GL and the EU-administratie reconcile — i.e.
 *                reconciliationVariance is zero (REQ-EUF-002, art 61 CPR
 *                separate-accounting integrity).
 *
 * ADR-031 exception reason: the zero-variance precondition compares two
 * balance fields, which the declarative `requires:` clause cannot yet express.
 * When the engine gains field-comparison conditions, replace this with a
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/bookkeeping-single-audit-eu-fondsen/specs/bookkeeping-single-audit-eu-fondsen/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Lifecycle precondition guard for the SegregatedLedger close transition.
 *
 * Referenced from the SegregatedLedger schema (register.d fragment)
 * x-openregister-lifecycle transitions.close.requires as
 * OCA\Shillinq\Lifecycle\SegregatedLedgerGuard::canClose.
 *
 * @spec openspec/changes/bookkeeping-single-audit-eu-fondsen/specs/bookkeeping-single-audit-eu-fondsen/spec.md
 */
class SegregatedLedgerGuard {
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
	 * Returns true iff the segregated ledger reconciles (variance == 0).
	 *
	 * REQ-EUF-002: both administraties must be sluitend and reconcilieerbaar
	 * before the ledger may close. Variance is computed in integer cents to
	 * avoid IEEE-754 equality issues, preferring an explicit
	 * reconciliationVariance and otherwise the difference between the two
	 * balance fields.
	 *
	 * Fail-closed: returns false on any exception (REQ-EUF-002 / CWE-863).
	 *
	 * @param string $segregatedLedgerId The SegregatedLedger.id (call-signature parity).
	 * @param array<string,mixed>|null $object The SegregatedLedger object being transitioned.
	 *
	 * @return bool True when the ledger reconciles and may close.
	 *
	 * @spec openspec/changes/bookkeeping-single-audit-eu-fondsen/specs/bookkeeping-single-audit-eu-fondsen/spec.md
	 */
	public function canClose(string $segregatedLedgerId, ?array $object = null): bool {
		try {
			$ledger = $this->resolveLedger(segregatedLedgerId: $segregatedLedgerId, object: $object);
			if ($ledger === null) {
				return false;
			}

			if (array_key_exists('reconciliationVariance', $ledger) === true
				&& $ledger['reconciliationVariance'] !== null
			) {
				$varianceCents = (int)round((float)$ledger['reconciliationVariance'] * 100);
				return $varianceCents === 0;
			}

			$regularCents = (int)round((float)($ledger['regularGlBalanceEur'] ?? 0.0) * 100);
			$euCents = (int)round((float)($ledger['euAdministrationBalanceEur'] ?? 0.0) * 100);
			return $regularCents === $euCents;
		} catch (\Throwable $e) {
			$this->logger->error(
				'SegregatedLedgerGuard: close check failed — denying close transition (fail-closed)',
				['segregatedLedgerId' => $segregatedLedgerId, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canClose()

	/**
	 * Resolve the SegregatedLedger object, preferring the supplied object and
	 * falling back to an ObjectService lookup by id.
	 *
	 * @param string $segregatedLedgerId The SegregatedLedger.id.
	 * @param array<string,mixed>|null $object The in-flight object, if provided.
	 *
	 * @return array<string,mixed>|null The ledger, or null when unresolved.
	 */
	private function resolveLedger(string $segregatedLedgerId, ?array $object): ?array {
		if ($object !== null) {
			return $object;
		}

		if ($segregatedLedgerId === '') {
			return null;
		}

		$ledgers = $this->objectService
			->setRegister($this->resolveRegister())
			->setSchema('SegregatedLedger')
			->findAll(['filters' => ['id' => $segregatedLedgerId], 'limit' => 1]);

		foreach ($ledgers as $ledger) {
			if (is_array($ledger) === true) {
				return $ledger;
			}
		}

		return null;
	}//end resolveLedger()

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
