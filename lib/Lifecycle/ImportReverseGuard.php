<?php

/**
 * Import Reverse Guard
 *
 * The lifecycle guard behind the ImportBatch `reverse` transition.
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
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\Shillinq\Service\Import\ImportPeriod;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Allows reversing a posted import only while its migration date's year is open (REQ-AIW-003).
 *
 * OpenRegister hands a guard the object with the transition already applied,
 * so the status reads `reversed` here; the move from `posted` was checked by
 * the transition itself. What is left to judge is the period.
 *
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */
final class ImportReverseGuard implements LifecycleGuardInterface {

	/**
	 * Constructor.
	 *
	 * @param ImportBatchGuard $guard  The posted-and-open rule.
	 * @param ImportPeriod     $period Whether the migration date's year is open.
	 * @param LoggerInterface  $logger Fail-closed diagnostics.
	 */
	public function __construct(
		private readonly ImportBatchGuard $guard,
		private readonly ImportPeriod $period,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Allow or refuse the reversal.
	 *
	 * @param array<string,mixed> $object The batch.
	 * @param string              $action The transition.
	 * @param string              $userId The acting user.
	 *
	 * @return GuardResult
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 *
	 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		try {
			$open = $this->period->isOpen(
				administrationId: (string)($object['administrationId'] ?? ''),
				date: (string)($object['migrationDate'] ?? '')
			);
		} catch (Throwable $e) {
			$this->logger->error('ImportReverseGuard: period lookup failed, reversal refused', ['exception' => $e->getMessage()]);
			$open = false;
		}

		if ($this->guard->canReverse(batch: array_merge($object, ['status' => 'posted']), periodOpen: $open) === true) {
			return GuardResult::allow();
		}

		return GuardResult::deny('The year of the migration date is closed. Correct the import with a journal entry instead.');

	}//end check()
}//end class
