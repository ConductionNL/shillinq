<?php

/**
 * BBV Compliance Guard
 *
 * Single declarative precondition the OpenRegister lifecycle engine cannot yet
 * express: for `gemeente`/`provincie`/`waterschap` administrations, every line
 * of a GLTransaction being transitioned `draft → posted` MUST have a non-
 * archived BbvAccountMapping for the same administration. The check is
 * forward-only by `postingDate` against the BBV-installation date so existing
 * historic postings on instances that retro-adopt the gate are not rejected
 * (REQ-BBV-003 / design.md D3).
 *
 * Thin PHP seam per ADR-031 §"PHP guards remain a legitimate seam" — no
 * domain orchestration; the method evaluates a cross-schema FK presence with
 * administration-type scope.
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
 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md (REQ-BBV-003)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Guards the GLTransaction.post lifecycle transition with the BBV-mapping
 * precondition. Declared via x-openregister-lifecycle.preconditions on the
 * GLTransaction schema fragment under lib/Settings/register.d/add-shillinq-
 * bbv-compliance.json — never invoked directly from application code.
 *
 * Failure mode: the precondition returns `false` (blocking the transition)
 * when a municipal administration has at least one GLLine referencing an
 * accountNumber that is not mapped in BbvAccountMapping for that
 * administration AND the GLTransaction.postingDate is on or after the BBV
 * gate's installation date. Non-municipal administrations and historic
 * postings short-circuit to `true`.
 *
 * @spec openspec/changes/add-shillinq-bbv-compliance/tasks.md#task-7
 */
class BbvComplianceGuard {
	/**
	 * Administration types subject to the BBV-mapping precondition. The
	 * check fires only for these municipal types — every other
	 * administration (mkb, foundation, ZZP, ...) bypasses (REQ-BBV-003
	 * scenario "A non-municipal posting bypasses the check").
	 *
	 * @var array<string>
	 */
	private const MUNICIPAL_TYPES = [
		'municipality',
		'province',
		'waterAuthority',
	];

	/**
	 * App-config key under which the BBV gate installation date is recorded.
	 * Set by lib/Repair/InitializeSettings.php on first BBV seed import; the
	 * gate is forward-only from this date (REQ-BBV-003 forward-only scope).
	 */
	private const APP_CONFIG_GATE_INSTALL_DATE = 'bbv_gate_install_date';

	/**
	 * Construct the guard with lazy DI of OR's ObjectService.
	 *
	 * @param ContainerInterface $container DI container — OR's ObjectService is
	 *                                      fetched lazily so the guard stays
	 *                                      usable before registers exist.
	 * @param IAppConfig $appConfig App config — stores the gate
	 *                              installation date that scopes the
	 *                              forward-only postingDate filter.
	 * @param LoggerInterface $logger Nextcloud logger for fail-closed
	 *                                diagnostics; the guard logs the
	 *                                first unmapped account it finds.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the configured OpenRegister register slug for shillinq.
	 *
	 * @return string The register slug, defaulting to 'shillinq'.
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()

	/**
	 * Read the BBV gate's installation date from app config. The gate is
	 * forward-only by postingDate ≥ install date per REQ-BBV-003. When the
	 * install date is unset (e.g. before the BBV seed has ever run) the gate
	 * is permissive: the precondition returns true for every posting.
	 *
	 * @return string|null ISO-8601 date string, or null when never installed.
	 */
	private function getGateInstallDate(): ?string {
		$value = $this->appConfig->getValueString(
			Application::APP_ID,
			self::APP_CONFIG_GATE_INSTALL_DATE,
			''
		);
		if ($value === '') {
			return null;
		}

		return $value;
	}//end getGateInstallDate()

	/**
	 * Load the administration record by id; returns null when the record is
	 * not resolvable (schema absent, id missing, or OR unavailable). A
	 * permissive null short-circuits the precondition because we cannot
	 * classify the administration type and falsely blocking would break
	 * non-municipal tenants.
	 *
	 * @param string $administrationId The administration's id (or
	 *                                 administrationCode) to resolve.
	 *
	 * @return array<string,mixed>|null Administration record or null when
	 *                                  not resolvable.
	 */
	private function findAdministration(string $administrationId): ?array {
		if ($administrationId === '') {
			return null;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$matches = $objectService
				->setRegister($this->getRegisterSlug())
				->setSchema('Administration')
				->findAll(
					[
						'filters' => [
							'administrationCode' => $administrationId,
						],
						'limit' => 1,
					]
				);

			if (empty($matches) === false) {
				return $matches[0];
			}

			// Fall back to id-based lookup for installs that key on
			// OpenRegister's internal id rather than the business code.
			$byId = $objectService
				->setRegister($this->getRegisterSlug())
				->setSchema('Administration')
				->findAll(
					[
						'filters' => [
							'id' => $administrationId,
						],
						'limit' => 1,
					]
				);

			if (empty($byId) === false) {
				return $byId[0];
			}

			return null;
		} catch (\Throwable $e) {
			$this->logger->debug(
				'BbvComplianceGuard: administration lookup failed; bypassing gate',
				['exception' => $e->getMessage()]
			);
			return null;
		}//end try

	}//end findAdministration()

	/**
	 * Resolve every GLLine row belonging to the candidate GLTransaction.
	 * Returns an empty array when the schema is unavailable; the caller
	 * treats an empty line set as permissive.
	 *
	 * @param string $transactionId The parent transaction id.
	 *
	 * @return array<int,array<string,mixed>> The lines (possibly empty).
	 */
	private function findTransactionLines(string $transactionId): array {
		if ($transactionId === '') {
			return [];
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			return $objectService
				->setRegister($this->getRegisterSlug())
				->setSchema('GLLine')
				->findAll(
					[
						'filters' => [
							'transactionId' => $transactionId,
						],
						'limit' => 1000,
					]
				);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'BbvComplianceGuard: GLLine lookup failed; bypassing gate',
				['transactionId' => $transactionId, 'exception' => $e->getMessage()]
			);
			return [];
		}//end try

	}//end findTransactionLines()

	/**
	 * Look up the BbvAccountMapping for (administrationId, accountNumber).
	 * Returns null when no mapping exists or the schema is unavailable.
	 *
	 * @param string $administrationId The owning administration id.
	 * @param string $accountNumber The RGS account number.
	 *
	 * @return array<string,mixed>|null The mapping or null when missing.
	 */
	private function findMapping(string $administrationId, string $accountNumber): ?array {
		if ($administrationId === '' || $accountNumber === '') {
			return null;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$matches = $objectService
				->setRegister($this->getRegisterSlug())
				->setSchema('BbvAccountMapping')
				->findAll(
					[
						'filters' => [
							'administrationId' => $administrationId,
							'accountNumber' => $accountNumber,
						],
						'limit' => 1,
					]
				);

			if (empty($matches) === true) {
				return null;
			}

			return $matches[0];
		} catch (\Throwable $e) {
			$this->logger->debug(
				'BbvComplianceGuard: BbvAccountMapping lookup failed; treating as missing',
				[
					'administrationId' => $administrationId,
					'accountNumber' => $accountNumber,
					'exception' => $e->getMessage(),
				]
			);
			return null;
		}//end try

	}//end findMapping()

	/**
	 * Precondition: every GLLine on this transaction MUST have a non-archived
	 * BbvAccountMapping for the transaction's administration, but ONLY when
	 * the administration is municipal (gemeente / provincie / waterschap)
	 * AND the postingDate is on or after the BBV gate's installation date.
	 *
	 * Return-value semantics (lifecycle precondition contract): `true`
	 * permits the transition; `false` blocks it.
	 *
	 * Permissive short-circuits (return true):
	 *   - Administration not resolvable (schema absent on bootstrap).
	 *   - Administration type not in MUNICIPAL_TYPES (REQ-BBV-003 scenario:
	 *     "A non-municipal posting bypasses the check").
	 *   - Gate installation date unset (BBV seed never ran on this instance).
	 *   - postingDate strictly before the gate installation date (REQ-BBV-003
	 *     forward-only — historic postings on retro-install must not be
	 *     rejected; backfill is an operator workflow per proposal Open
	 *     Question 2).
	 *   - No GLLine rows on the transaction (the BalanceGuard precondition
	 *     handles malformed transactions; we do not duplicate its job).
	 *
	 * Blocking case (return false):
	 *   - At least one GLLine references an accountNumber for which no
	 *     BbvAccountMapping row exists for the same administrationId.
	 *
	 * @param array<string,mixed> $transaction The GLTransaction record being
	 *                                         transitioned draft → posted.
	 *
	 * @return bool True when posting is permitted; false to block.
	 *
	 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md (REQ-BBV-003)
	 */
	public function allLinesMappedForMunicipalAdmin(array $transaction): bool {
		$administrationId = (string)($transaction['administrationId'] ?? '');
		if ($administrationId === '') {
			// Cannot classify — BalanceGuard handles malformed headers.
			return true;
		}

		$administration = $this->findAdministration(administrationId: $administrationId);
		if ($administration === null) {
			// Administration not yet present in OR (bootstrap) — permissive.
			return true;
		}

		$administrationType = (string)($administration['administrationType'] ?? '');
		if (in_array($administrationType, self::MUNICIPAL_TYPES, true) === false) {
			// Non-municipal — gate not applicable (REQ-BBV-003).
			return true;
		}

		$gateInstallDate = $this->getGateInstallDate();
		if ($gateInstallDate === null) {
			// BBV seed has never run; gate not yet active.
			return true;
		}

		$postingDate = (string)($transaction['postingDate'] ?? '');
		if ($postingDate !== '' && strcmp($postingDate, $gateInstallDate) < 0) {
			// Historic posting (REQ-BBV-003 forward-only).
			return true;
		}

		$transactionId = (string)($transaction['id'] ?? $transaction['uuid'] ?? '');
		$lines = $this->findTransactionLines(transactionId: $transactionId);
		if (empty($lines) === true) {
			// BalanceGuard handles transactions without lines.
			return true;
		}

		foreach ($lines as $line) {
			$accountNumber = (string)($line['accountNumber'] ?? '');
			if ($accountNumber === '') {
				// BalanceGuard handles malformed lines.
				continue;
			}

			$mapping = $this->findMapping(administrationId: $administrationId, accountNumber: $accountNumber);
			if ($mapping === null) {
				$this->logger->info(
					'BbvComplianceGuard: blocking GLTransaction.post — unmapped account on municipal administration',
					[
						'administrationId' => $administrationId,
						'administrationType' => $administrationType,
						'accountNumber' => $accountNumber,
						'transactionId' => $transactionId,
					]
				);
				return false;
			}
		}//end foreach

		return true;
	}//end allLinesMappedForMunicipalAdmin()
}//end class
