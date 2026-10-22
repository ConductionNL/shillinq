<?php

/**
 * Administration Membership Service
 *
 * Writes AdministrationMembership rows on behalf of someone who manages the
 * administration. The schema is writable by Nextcloud admins only, so this is
 * the path an owner adds members through.
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
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DomainException;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Adds a member to an administration.
 *
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-12
 */
class AdministrationMembershipService {

	/**
	 * The membership roles, as the AdministrationMembership schema declares them.
	 *
	 * @var array<int,string>
	 */
	public const ROLES = [
		'eigenaar',
		'controller',
		'boekhouder',
		'inkijker',
		'accountant_extern',
		'salarisadministrateur',
		'debiteurenadmin',
		'crediteurenadmin',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 * @param IAppConfig $appConfig App config for the register slug.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Add a member to an administration.
	 *
	 * @param string $administrationId The administration.
	 * @param string $userId The Nextcloud user to add.
	 * @param string $role One of {@see self::ROLES}.
	 * @param string $grantedBy The user who adds the member.
	 * @param array<string,mixed> $options Optional `validFrom`, `validUntil` (Y-m-d),
	 *                                     `mayPostJournalEntries`, `mayCloseFiscalYear` (bool).
	 *
	 * @return array<string,mixed> The saved membership.
	 *
	 * @throws InvalidArgumentException When the role or an option is invalid.
	 * @throws DomainException When the user already holds a membership of the administration.
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-12
	 */
	public function addMember(string $administrationId, string $userId, string $role, string $grantedBy, array $options=[]): array {
		if (in_array($role, self::ROLES, true) === false) {
			throw new InvalidArgumentException('Unknown role.');
		}

		$membership = [
			'userId' => $userId,
			'administrationId' => $administrationId,
			'role' => $role,
			'grantedBy' => $grantedBy,
		];
		foreach (['validFrom', 'validUntil'] as $dateKey) {
			$value = trim((string)($options[$dateKey] ?? ''));
			if ($value === '') {
				continue;
			}

			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
				throw new InvalidArgumentException('Dates are written as YYYY-MM-DD.');
			}

			$membership[$dateKey] = $value;
		}

		foreach (['mayPostJournalEntries', 'mayCloseFiscalYear'] as $flagKey) {
			if (array_key_exists($flagKey, $options) === true && $options[$flagKey] !== null) {
				$membership[$flagKey] = ($options[$flagKey] === true);
			}
		}

		$existing = $this->objectService
			->setRegister($this->register())
			->setSchema('AdministrationMembership')
			->findAll(['filters' => ['userId' => $userId, 'administrationId' => $administrationId], 'limit' => 1]);
		if (empty($existing) === false) {
			throw new DomainException('The user is already a member of this administration.');
		}

		$saved = $this->objectService->saveObject(
			object: $membership,
			register: $this->register(),
			schema: 'AdministrationMembership',
			_rbac: false,
			_multitenancy: false,
		);

		$serialised = $saved->jsonSerialize();
		if (is_array($serialised) === false) {
			return $membership;
		}

		return $serialised;
	}//end addMember()

	/**
	 * The configured register slug.
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
