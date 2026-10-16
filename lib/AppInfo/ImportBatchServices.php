<?php

/**
 * Import Batch Services
 *
 * The container registrations of the ImportBatch lifecycle guard tags.
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

use OCA\Shillinq\Lifecycle\ImportBatchGuard;
use OCA\Shillinq\Lifecycle\ImportReverseGuard;
use OCA\Shillinq\Lifecycle\RegisterRequiresGuardAdapter;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Log\LoggerInterface;

/**
 * Registers the `requires` tags the ImportBatch lifecycle declares.
 *
 * OpenRegister resolves the whole `Class::method` string as one container
 * tag, which can never autowire; unregistered, every parse and every
 * reversal failed with "Lifecycle guard ... is not registered".
 *
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */
final class ImportBatchServices {

	/**
	 * Register the parse and reverse guard tags.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerService(
			'OCA\Shillinq\Lifecycle\ImportBatchGuard::canParse',
			static function ($c): RegisterRequiresGuardAdapter {
				return new RegisterRequiresGuardAdapter(
					guard: $c->get(ImportBatchGuard::class),
					method: 'canParse',
					denyMessage: 'Choose the auditfile, the administration, the migration date and at least one part to import.',
					logger: $c->get(LoggerInterface::class),
				);
			}
		);
		$context->registerService(
			'OCA\Shillinq\Lifecycle\ImportBatchGuard::canReverse',
			static fn ($c): ImportReverseGuard => $c->get(ImportReverseGuard::class)
		);

	}//end register()
}//end class
