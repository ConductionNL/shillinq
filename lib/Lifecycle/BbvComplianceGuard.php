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

use OCA\Shillinq\Kernel\AppIdentity;
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
		$slug = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
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
			AppIdentity::APP_ID,
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
	private function findAdministration(string $administrationId): array|null {
		if ($administrationId === '') {
			return null;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			// Fall back to id-based lookup for installs that key on
			// OpenRegister's internal id rather than the business code.
			return $this->firstAdministration(objectService: $objectService, filters: ['administrationCode' => $administrationId])
				?? $this->firstAdministration(objectService: $objectService, filters: ['id' => $administrationId]);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'BbvComplianceGuard: administration lookup failed; bypassing gate',
				['exception' => $e->getMessage()]
			);
			return null;
		}//end try

	}//end findAdministration()

	/**
	 * The first Administration matching the filters, or null.
	 *
	 * @param object               $objectService OpenRegister's ObjectService.
	 * @param array<string,string> $filters       The lookup filters.
	 *
	 * @return array<string,mixed>|null
	 */
	private function firstAdministration(object $objectService, array $filters): array|null {
		$matches = $objectService
			->setRegister($this->getRegisterSlug())
			->setSchema('Administration')
			->findAll(['filters' => $filters, 'limit' => 1]);

		if (empty($matches) === false) {
			return $matches[0];
		}

		return null;

	}//end firstAdministration()

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
			return $this->linesOf(transactionId: $transactionId);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'BbvComplianceGuard: GLLine lookup failed; bypassing gate',
				['transactionId' => $transactionId, 'exception' => $e->getMessage()]
			);
			return [];
		}//end try

	}//end findTransactionLines()

	/**
	 * The GLLine rows of one transaction.
	 *
	 * @param string $transactionId The parent transaction id.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function linesOf(string $transactionId): array {
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

	}//end linesOf()

	/**
	 * Look up the BbvAccountMapping for (administrationId, accountNumber).
	 * Returns null when no mapping exists or the schema is unavailable.
	 *
	 * @param string $administrationId The owning administration id.
	 * @param string $accountNumber The RGS account number.
	 *
	 * @return array<string,mixed>|null The mapping or null when missing.
	 */
	private function findMapping(string $administrationId, string $accountNumber): array|null {
		if ($administrationId === '' || $accountNumber === '') {
			return null;
		}

		try {
			return $this->firstMapping(administrationId: $administrationId, accountNumber: $accountNumber);
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
	 * The first BbvAccountMapping of (administrationId, accountNumber), or null.
	 *
	 * @param string $administrationId The owning administration id.
	 * @param string $accountNumber    The RGS account number.
	 *
	 * @return array<string,mixed>|null
	 */
	private function firstMapping(string $administrationId, string $accountNumber): array|null {
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

	}//end firstMapping()

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
		$administrationType = $this->municipalType(administrationId: (string)($transaction['administrationId'] ?? ''));
		if ($administrationType === null || $this->gateApplies(transaction: $transaction) === false) {
			return true;
		}

		$context = $this->unmappedLine(transaction: $transaction, administrationType: $administrationType);
		if ($context === null) {
			return true;
		}

		$this->logger->info(
			'BbvComplianceGuard: blocking GLTransaction.post — unmapped account on municipal administration',
			$context
		);
		return false;
	}//end allLinesMappedForMunicipalAdmin()

	/**
	 * The municipal administration type the gate applies to, or null when
	 * the administration cannot be classified (no id — BalanceGuard handles
	 * malformed headers; not yet in OR on bootstrap) or is not municipal
	 * (REQ-BBV-003: the gate does not apply).
	 *
	 * @param string $administrationId The transaction's administration.
	 *
	 * @return string|null
	 */
	private function municipalType(string $administrationId): string|null {
		$administration = $this->findAdministration(administrationId: $administrationId);
		if ($administration === null) {
			return null;
		}

		$administrationType = (string)($administration['administrationType'] ?? '');
		if (in_array($administrationType, self::MUNICIPAL_TYPES, true) === false) {
			return null;
		}

		return $administrationType;
	}//end municipalType()

	/**
	 * Whether the gate is active (the BBV seed ran) and the posting is not a
	 * historic one dated before its installation (REQ-BBV-003 forward-only).
	 *
	 * @param array<string,mixed> $transaction The GLTransaction record.
	 *
	 * @return bool
	 */
	private function gateApplies(array $transaction): bool {
		$gateInstallDate = $this->getGateInstallDate();
		if ($gateInstallDate === null) {
			return false;
		}

		$postingDate = (string)($transaction['postingDate'] ?? '');
		return $postingDate === '' || strcmp($postingDate, $gateInstallDate) >= 0;
	}//end gateApplies()

	/**
	 * The log context of the first line whose account has no mapping for the
	 * administration, or null when every line is mapped. Lines without an
	 * account, and a transaction without lines, are BalanceGuard's business.
	 *
	 * @param array<string,mixed> $transaction        The GLTransaction record.
	 * @param string              $administrationType The municipal administration type.
	 *
	 * @return array<string,string>|null
	 */
	private function unmappedLine(array $transaction, string $administrationType): array|null {
		$administrationId = (string)($transaction['administrationId'] ?? '');
		$transactionId = (string)($transaction['id'] ?? $transaction['uuid'] ?? '');
		$accountNumber = $this->firstUnmappedAccount(
			administrationId: $administrationId,
			lines: $this->findTransactionLines(transactionId: $transactionId)
		);
		if ($accountNumber === '') {
			return null;
		}

		return [
			'administrationId' => $administrationId,
			'administrationType' => $administrationType,
			'accountNumber' => $accountNumber,
			'transactionId' => $transactionId,
		];
	}//end unmappedLine()

	/**
	 * The first line account without a mapping for the administration, or ''.
	 *
	 * @param string                         $administrationId The owning administration id.
	 * @param array<int,array<string,mixed>> $lines            The transaction's lines.
	 *
	 * @return string
	 */
	private function firstUnmappedAccount(string $administrationId, array $lines): string {
		foreach ($lines as $line) {
			$accountNumber = (string)($line['accountNumber'] ?? '');
			if ($accountNumber !== '' && $this->findMapping(administrationId: $administrationId, accountNumber: $accountNumber) === null) {
				return $accountNumber;
			}
		}

		return '';
	}//end firstUnmappedAccount()
}//end class
