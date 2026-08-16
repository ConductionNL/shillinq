<?php

/**
 * Shillinq Role Fallback Recipient Resolver
 *
 * Notification recipient resolver (`kind: expression`, ADR-031 canonical
 * declared recipients) that resolves a configured PRIMARY Nextcloud group
 * to its members, falling back to a configured FALLBACK group when the
 * primary group has no members. Preserves the intent of the legacy
 * `"recipient": {"resolver": "<role>", "fallback": "<role>"}` shape used
 * across shillinq's register.d fragments before the migration to the
 * canonical `recipients: [{"kind": "expression", "resolver": "..."}]`
 * dialect (migrate-legacy-notification-dialect).
 *
 * Multiple role pairs share this single class; each pair is registered
 * under its own DI service alias in lib/AppInfo/Application.php (e.g.
 * `OCA\Shillinq\Notification\RoleFallbackResolver::financeOfficer`), each
 * alias resolving to a distinctly-configured instance.
 *
 * @category Notification
 * @package  OCA\Shillinq\Notification
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
 * @spec openspec/changes/migrate-legacy-notification-dialect/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Notification;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Notification\RecipientResolverInterface;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolve a role's Nextcloud group to notification uids, with a fallback
 * group when the primary is empty.
 *
 * @spec openspec/changes/migrate-legacy-notification-dialect/tasks.md#task-1.1
 */
class RoleFallbackResolver implements RecipientResolverInterface {
	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager Group-member expansion.
	 * @param LoggerInterface $logger Logger for resolution diagnostics.
	 * @param string $primaryGroup Nextcloud group id checked first.
	 * @param string $fallbackGroup Nextcloud group id used when the primary is empty
	 *                              (empty string disables the fallback).
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
		private readonly string $primaryGroup,
		private readonly string $fallbackGroup = '',
	) {
	}//end __construct()

	/**
	 * Resolve the recipient uids for a notification dispatch.
	 *
	 * @param ObjectEntity $object The object the event happened on (unused; role is scope-invariant).
	 * @param array<string, mixed> $context Trigger-specific extras (unused).
	 *
	 * @return array<int, string> Primary group member uids, or fallback group member
	 *                            uids when the primary is empty, or `[]` when both are
	 *                            empty/unresolvable (fail-closed, same as the legacy
	 *                            resolver+fallback behaviour it replaces).
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $object and $context are part of the resolver contract.
	 *
	 * @spec openspec/changes/migrate-legacy-notification-dialect/tasks.md#task-1.1
	 */
	public function resolve(ObjectEntity $object, array $context): array {
		$uids = $this->membersOf(groupId: $this->primaryGroup);
		if ($uids !== []) {
			return $uids;
		}

		if ($this->fallbackGroup === '') {
			return [];
		}

		return $this->membersOf(groupId: $this->fallbackGroup);
	}//end resolve()

	/**
	 * Resolve a single group's member uids, fail-safe on any error.
	 *
	 * @param string $groupId The Nextcloud group id.
	 *
	 * @return array<int, string>
	 */
	private function membersOf(string $groupId): array {
		if ($groupId === '') {
			return [];
		}

		try {
			$group = $this->groupManager->get($groupId);
			if ($group === null) {
				return [];
			}

			$uids = [];
			foreach ($group->getUsers() as $user) {
				$uids[] = $user->getUID();
			}

			return array_values(array_unique($uids));
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[RoleFallbackResolver] group resolution failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'group' => $groupId]
			);
			return [];
		}

	}//end membersOf()
}//end class
