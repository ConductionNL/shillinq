<?php

/**
 * WBSO Account Service
 *
 * Server-side helpers for the Account register declared by the
 * bookkeeping-financial-administration spec (REQ-WBSO-001 / REQ-WBSO-006).
 * Wraps OpenRegister's ObjectService with administration-scoped queries and
 * pure helpers that translate a flat account list into a hierarchical tree
 * with depth and cycle validation.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-23
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use InvalidArgumentException;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Account-register helper service (REQ-WBSO-001 / REQ-WBSO-006).
 *
 * The schema is the source of truth for the hierarchy depth and circular-ref
 * constraints; this service mirrors those rules in PHP so the controllers
 * surface a meaningful error message before the OpenRegister write reaches
 * the data layer (defence-in-depth).
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-23
 */
class WbsoAccountService {

	/**
	 * Maximum chart-of-accounts depth (REQ-WBSO-001).
	 *
	 * @var int
	 */
	public const HIERARCHY_MAX_DEPTH = 5;

	/**
	 * Construct the service with lazy DI of OpenRegister's ObjectService.
	 *
	 * @param IAppConfig $appConfig App config (register slug).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Return every Account record for an administration (REQ-WBSO-006).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function getAccountsByAdministration(string $administrationId): array {
		return $this->objectService
			->setRegister($this->register())
			->setSchema('Account')
			->findAll(['filters' => ['administrationId' => $administrationId]]);

	}//end getAccountsByAdministration()

	/**
	 * Return the chart-of-accounts as a hierarchical tree (REQ-WBSO-006).
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<int,array<string,mixed>> Root accounts, each carrying a recursive `children` array.
	 */
	public function getAccountHierarchy(string $administrationId): array {
		$accounts = $this->getAccountsByAdministration(administrationId: $administrationId);

		return $this->buildHierarchy(accounts: $accounts);
	}//end getAccountHierarchy()

	/**
	 * Build a tree from a flat account list. Pure function — no I/O.
	 *
	 * @param array<int,array<string,mixed>> $accounts Flat account list.
	 *
	 * @return array<int,array<string,mixed>> Root nodes with nested children.
	 *
	 * @spec openspec/specs/bookkeeping-chart-of-accounts/spec.md#req-coa-003-the-account-schema-shall-declare-a-self-relation-for-hierarchy-via-x-openregister-relations
	 */
	public function buildHierarchy(array $accounts): array {
		$byNumber = [];
		foreach ($accounts as $account) {
			$number = (string)($account['accountNumber'] ?? '');
			if ($number === '') {
				continue;
			}

			$account['children'] = [];
			$byNumber[$number] = $account;
		}

		$roots = [];
		foreach ($byNumber as $number => $account) {
			$parent = (string)($account['parentAccountNumber'] ?? '');
			if ($parent === '' || isset($byNumber[$parent]) === false) {
				$roots[] = &$byNumber[$number];
				continue;
			}

			$byNumber[$parent]['children'][] = &$byNumber[$number];
		}

		// `$roots` is only ever appended to with `[]=`, so it is already a list.
		return $roots;
	}//end buildHierarchy()

	/**
	 * Return one account with its immediate parent + child links populated.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $accountNumber Account number to resolve.
	 *
	 * @return array<string,mixed>|null The account with `parent` + `children`, or null when missing.
	 */
	public function getAccountByNumber(string $administrationId, string $accountNumber): ?array {
		$accounts = $this->getAccountsByAdministration(administrationId: $administrationId);
		$byNumber = [];
		foreach ($accounts as $account) {
			$number = (string)($account['accountNumber'] ?? '');
			if ($number !== '') {
				$byNumber[$number] = $account;
			}
		}

		if (isset($byNumber[$accountNumber]) === false) {
			return null;
		}

		$target = $byNumber[$accountNumber];
		$target['parent'] = $byNumber[(string)($target['parentAccountNumber'] ?? '')] ?? null;
		$target['children'] = [];
		foreach ($byNumber as $candidate) {
			if (($candidate['parentAccountNumber'] ?? null) === $accountNumber) {
				$target['children'][] = $candidate;
			}
		}

		return $target;
	}//end getAccountByNumber()

	/**
	 * Create an account, enforcing hierarchy + circular-ref rules (REQ-WBSO-001).
	 *
	 * @param string $administrationId Administration scope.
	 * @param array<string,mixed> $payload Account fields (accountNumber, name, accountType, etc.).
	 *
	 * @return array<string,mixed> The persisted record.
	 *
	 * @throws InvalidArgumentException When validation fails.
	 */
	public function createAccount(string $administrationId, array $payload): array {
		$payload['administrationId'] = $administrationId;
		$this->validatePayload(payload: $payload);
		$this->assertHierarchy(
			administrationId: $administrationId,
			accountNumber: (string)$payload['accountNumber'],
			parent: (string)($payload['parentAccountNumber'] ?? ''),
		);


		// ADR-084: saveObject() returns an ObjectEntityInterface, not the array
		// this method declares — returning it raised a TypeError on every call.
		return (array)$this->objectService
			->setRegister($this->register())
			->setSchema('Account')
			->saveObject($payload)
			->jsonSerialize();

	}//end createAccount()

	/**
	 * Update an account's mutable properties (REQ-WBSO-001).
	 *
	 * Allowed fields: name, description, status (active/blocked), vatApplicable,
	 * and parentAccountNumber subject to hierarchy/circular-ref checks. The
	 * accountNumber is immutable once created.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $accountNumber Account to update.
	 * @param array<string,mixed> $payload Update payload.
	 *
	 * @return array<string,mixed> The merged + persisted record.
	 *
	 * @throws InvalidArgumentException When validation fails.
	 */
	public function updateAccount(string $administrationId, string $accountNumber, array $payload): array {
		$existing = $this->getAccountByNumber(
			administrationId: $administrationId,
			accountNumber: $accountNumber,
		);
		if ($existing === null) {
			throw new InvalidArgumentException('Account not found');
		}

		$merged = array_merge($existing, $payload);
		unset($merged['parent'], $merged['children']);
		$merged['accountNumber'] = $accountNumber;
		$merged['administrationId'] = $administrationId;

		$this->validatePayload(payload: $merged);
		$this->assertHierarchy(
			administrationId: $administrationId,
			accountNumber: $accountNumber,
			parent: (string)($merged['parentAccountNumber'] ?? ''),
		);


		// ADR-084: see createAccount() — the contract returns an entity.
		return (array)$this->objectService
			->setRegister($this->register())
			->setSchema('Account')
			->saveObject($merged)
			->jsonSerialize();

	}//end updateAccount()

	/**
	 * Validate the minimum required payload fields. Pure function.
	 *
	 * @param array<string,mixed> $payload Account fields to validate.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When validation fails.
	 */
	public function validatePayload(array $payload): void {
		$required = ['accountNumber', 'name', 'accountType', 'administrationId'];
		foreach ($required as $field) {
			if (isset($payload[$field]) === false || $payload[$field] === '') {
				throw new InvalidArgumentException(sprintf('%s is required', $field));
			}
		}

		$allowedTypes = ['assets', 'liabilities', 'equity', 'revenue', 'expenses'];
		if (in_array((string)$payload['accountType'], $allowedTypes, true) === false) {
			throw new InvalidArgumentException('accountType must be one of: ' . implode(', ', $allowedTypes));
		}

		$allowedStatus = ['active', 'blocked', 'archived'];
		$status = (string)($payload['status'] ?? 'active');
		if (in_array($status, $allowedStatus, true) === false) {
			throw new InvalidArgumentException('status must be one of: ' . implode(', ', $allowedStatus));
		}

		$currency = (string)($payload['currency'] ?? 'EUR');
		if ($currency !== 'EUR') {
			throw new InvalidArgumentException('currency must be EUR in phase 1');
		}

	}//end validatePayload()

	/**
	 * Hierarchy + circular-ref validation, mirroring x-openregister-constraint.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $accountNumber Account about to be saved.
	 * @param string $parent Parent accountNumber ('' means root).
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When validation fails.
	 *
	 * @spec openspec/specs/bookkeeping-chart-of-accounts/spec.md#req-coa-003-the-account-schema-shall-declare-a-self-relation-for-hierarchy-via-x-openregister-relations
	 */
	public function assertHierarchy(string $administrationId, string $accountNumber, string $parent): void {
		if ($parent === '') {
			return;
		}

		if ($parent === $accountNumber) {
			throw new InvalidArgumentException('parentAccountNumber must not equal the account itself');
		}

		$accounts = $this->getAccountsByAdministration(administrationId: $administrationId);
		$byNumber = [];
		foreach ($accounts as $account) {
			$number = (string)($account['accountNumber'] ?? '');
			if ($number !== '') {
				$byNumber[$number] = $account;
			}
		}

		if (isset($byNumber[$parent]) === false) {
			throw new InvalidArgumentException('parentAccountNumber does not exist in this administration');
		}

		if ((string)($byNumber[$parent]['status'] ?? '') !== 'active') {
			throw new InvalidArgumentException('parentAccountNumber refers to an account that is not active');
		}

		// Walk ancestors. If we encounter $accountNumber, we have a cycle.
		$cursor = $parent;
		$depth = 1;
		$seen = [$accountNumber => true];
		// `$cursor` starts non-empty and is only ever reassigned from a `$next`
		// the loop has already refused to accept when empty, so the
		// `$cursor !== ''` condition this replaces could never end the loop —
		// the `break` below is the only exit besides the throws.
		while (true) {
			if (isset($seen[$cursor]) === true) {
				throw new InvalidArgumentException('Circular parent reference detected');
			}

			$seen[$cursor] = true;
			$depth++;
			if ($depth > self::HIERARCHY_MAX_DEPTH) {
				throw new InvalidArgumentException(
					sprintf(
						'Hierarchy depth must not exceed %d levels',
						self::HIERARCHY_MAX_DEPTH
					)
				);
			}

			$next = (string)($byNumber[$cursor]['parentAccountNumber'] ?? '');
			if ($next === '' || isset($byNumber[$next]) === false) {
				break;
			}

			$cursor = $next;
		}//end while

	}//end assertHierarchy()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string Register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
