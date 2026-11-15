<?php

/**
 * AP Guard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Lifecycle preconditions for the APTransaction schema's `receive` and
 * `writeOff` transitions (lib/Settings/register.d/
 * bookkeeping-accounts-payable-core.json, REQ-AP-003 scenario 2, REQ-AP-005).
 * ADR-031 exception-path PHP guard — the vendor-scoped invoice-number
 * uniqueness check is a cross-record lookup the declarative lifecycle DSL
 * cannot express (this is also the concrete AP-duplicate-invoice control
 * originally suspected as a silent OWASP A01 bypass; confirmed instead to be
 * a hard-fail per shillinq#425's investigation — this class fixes both by
 * making the check real).
 *
 * shillinq#425: class did not exist prior to this change; both `receive` and
 * `writeOff` hard-failed (RuntimeException from LifecycleGuardRegistry).
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
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Guards APTransaction `receive` (vendor invoice-number dedupe) and
 * `writeOff` (reason required).
 *
 * Fail-closed: any lookup exception denies `receive`.
 *
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
 */
class APGuard {
	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for register slug resolution.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Precondition for `receive`: the vendor invoice number must be unique
	 * per (administrationId, vendorId) — REQ-AP-003 scenario 2. Excludes the
	 * current record by id so re-saving an already-received invoice does not
	 * self-collide.
	 *
	 * @param array<string, mixed> $transaction The APTransaction object being transitioned.
	 *
	 * @return bool True when no other APTransaction for the same vendor+administration
	 *              already carries this invoice number.
	 *
	 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
	 */
	public function isInvoiceNumberUnique(array $transaction): bool {
		$invoiceNumber = trim((string)($transaction['invoiceNumber'] ?? ''));
		if ($invoiceNumber === '') {
			// No invoice number recorded yet — nothing to dedupe against;
			// the schema's own `required` validation gates emptiness.
			return true;
		}

		try {
			return $this->noDuplicateNumber(transaction: $transaction);
		} catch (\Throwable $e) {
			$this->logger->error(
				'APGuard: isInvoiceNumberUnique check failed — denying receive (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end isInvoiceNumberUnique()

	/**
	 * Whether no other APTransaction carries the vendor's invoice number.
	 *
	 * @param array<string, mixed> $transaction The APTransaction being received.
	 *
	 * @return bool False, with the duplicate logged, when another one does.
	 */
	private function noDuplicateNumber(array $transaction): bool {
		$filters = [
			'invoiceNumber' => trim((string)($transaction['invoiceNumber'] ?? '')),
			'vendorId' => (string)($transaction['vendorId'] ?? ''),
			'administrationId' => (string)($transaction['administrationId'] ?? ''),
		];
		$existing = $this->sameNumbered(filters: $filters);
		if ($this->anyOtherRecord(transaction: $transaction, existing: $existing) === false) {
			return true;
		}

		$this->logger->info(
			'APGuard: duplicate vendor invoice number rejected',
			['vendorId' => $filters['vendorId'], 'administrationId' => $filters['administrationId'], 'invoiceNumber' => $filters['invoiceNumber']]
		);
		return false;

	}//end noDuplicateNumber()

	/**
	 * The APTransactions matching the invoice-number filters; empty on a non-array answer.
	 *
	 * @param array<string, string> $filters Invoice number, vendor and administration.
	 *
	 * @return array<int|string, mixed> The matching transactions.
	 */
	private function sameNumbered(array $filters): array {
		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		$existing = $objectService
			->setRegister($this->register())
			->setSchema('APTransaction')
			->findAll(['filters' => $filters]);

		if (is_array($existing) === false) {
			return [];
		}

		return $existing;

	}//end sameNumbered()

	/**
	 * Whether any matching record is not the transaction itself.
	 *
	 * @param array<string, mixed> $transaction The APTransaction being received.
	 * @param array<int|string, mixed> $existing The matching transactions.
	 *
	 * @return bool True when another record carries the number.
	 */
	private function anyOtherRecord(array $transaction, array $existing): bool {
		$currentId = ($transaction['id'] ?? null);
		foreach ($existing as $candidate) {
			if ($currentId === null || ($candidate['id'] ?? null) !== $currentId) {
				return true;
			}
		}

		return false;

	}//end anyOtherRecord()

	/**
	 * Precondition for `writeOff`: `writeOffReason` must be set.
	 *
	 * @param array<string, mixed> $transaction The APTransaction object being transitioned.
	 *
	 * @return bool True when the write-off may proceed.
	 *
	 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
	 */
	public function requireWriteOffReason(array $transaction): bool {
		return trim((string)($transaction['writeOffReason'] ?? '')) !== '';
	}//end requireWriteOffReason()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
