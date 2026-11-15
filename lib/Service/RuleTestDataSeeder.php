<?php

/**
 * Rule Test-Data Seeder
 *
 * Idempotently backfills the local TEST data so it satisfies the enforced
 * machine-checkable rules — every GLTransaction gets a `sourceReference`
 * (Belegfunktion) and at least two balanced GLLines (double-entry completeness).
 * Run after a clean-env reset so a fresh environment starts 100%-compliant in
 * `occ shillinq:rules:audit`.
 *
 * This is a TEST/DEV utility only: it writes with RBAC bypassed and as an admin
 * user to reach seeded objects' folders. No runtime path uses it; it exists so
 * the compliant-test-data state is reproducible rather than a one-off live edit.
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
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, Generic.Files.LineLength, PEAR.Commenting.FunctionComment, Squiz.PHP.DisallowInlineIf
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\RuleTestDataSeederInterface;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Makes the local test data compliant with the enforced rules (idempotent).
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ElseExpression)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * Pre-existing debt (issue #506): this seeder enumerates every rule
 * family's test fixtures; inherent complexity. Deferred to a follow-up.
 */
class RuleTestDataSeeder implements RuleTestDataSeederInterface {
	/**
	 * Construct the rule test-data seeder.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param IUserManager $userManager To resolve an admin user for updates.
	 * @param IGroupManager $groupManager To find an admin user.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {

	}//end __construct()

	/**
	 * Backfill GL transactions to satisfy the enforced ledger rules.
	 *
	 * @return array<string, int> Counts: sourceReferencesAdded, linesAdded, alreadyCompliant.
	 *
	 * @spec openspec/changes/glline-administration-scope/specs/glline-administration-scope/spec.md
	 */
	public function seed(): array {
		$run = new RuleTestDataSeedRun(
			register: $this->register(),
			admin: $this->adminUser(),
			objectService: $this->objectService(),
			logger: $this->logger,
		);
		return $run->run();

	}//end seed()

	/**
	 * Resolve an admin user (needed to update seeded objects' folders), or null.
	 *
	 * @return IUser|null
	 */
	private function adminUser(): ?IUser {
		$admins = $this->groupManager->get('admin');
		if ($admins !== null) {
			foreach ($admins->getUsers() as $user) {
				return $user;
			}
		}

		return $this->userManager->get('admin');
	}//end adminUser()

	/**
	 * Resolve the OpenRegister ObjectService from the DI container.
	 *
	 * @return mixed The OpenRegister ObjectService.
	 */
	private function objectService(): mixed {
		return $this->objectService;
	}//end objectService()

	/**
	 * Return the configured OpenRegister register slug.
	 *
	 * @return string The configured register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		return $register === '' ? 'shillinq' : $register;
	}//end register()
}//end class
