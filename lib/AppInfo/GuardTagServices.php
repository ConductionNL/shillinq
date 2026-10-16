<?php

/**
 * Guard Tag Services
 *
 * One entry point for the groups of lifecycle guard tags registered outside Application.
 *
 * @category AppInfo
 * @package  OCA\Shillinq\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\AppInfo;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers each group of `Class::method` guard tags; a new group is added here, not in Application.
 *
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */
final class GuardTagServices {

	/**
	 * Register every group.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		(new CommitmentGuardServices())->register(context: $context);
		(new ImportBatchServices())->register(context: $context);

	}//end register()
}//end class
