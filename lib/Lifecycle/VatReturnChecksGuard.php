<?php

/**
 * VAT Return Checks Guard
 *
 * The lifecycle guard behind the BtwAangifte `submit` transition.
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
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\Shillinq\Standards\Checks\VatReturnChecks;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refuses to submit a VAT return while one of its blocking checks fails, naming the check.
 *
 * A warning does not refuse. A check run that throws refuses: a return is
 * never submitted on checks that did not run.
 *
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.2
 */
final class VatReturnChecksGuard implements LifecycleGuardInterface {

	/**
	 * Constructor.
	 *
	 * @param VatReturnChecks $checks The checks.
	 * @param IL10N           $l10n   The refusal message.
	 * @param LoggerInterface $logger Records a check run that failed.
	 */
	public function __construct(
		private readonly VatReturnChecks $checks,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Allow the transition when no blocking check fails.
	 *
	 * @param array<string, mixed> $object The BtwAangifte.
	 * @param string               $action The transition.
	 * @param string               $userId The user asking.
	 *
	 * @return GuardResult
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.2
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		try {
			$failed = $this->firstBlockingFailure(results: $this->checks->run(return: $object));
		} catch (Throwable $e) {
			$this->logger->error('VatReturnChecksGuard: checks did not run, submit refused', ['exception' => $e->getMessage()]);
			return GuardResult::deny($this->l10n->t('The checks of this return could not run, so it cannot be submitted.'));
		}

		if ($failed === null) {
			return GuardResult::allow();
		}

		return GuardResult::deny($this->l10n->t('The check "%1$s" failed: %2$s', [$failed['id'], $failed['message']]));
	}//end check()

	/**
	 * The first failing blocking check, or null when none fails.
	 *
	 * @param list<array{id: string, severity: string, passed: bool, message: string}> $results The run.
	 *
	 * @return array{id: string, severity: string, passed: bool, message: string}|null
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.2
	 */
	private function firstBlockingFailure(array $results): ?array {
		foreach ($results as $result) {
			if ($result['severity'] === VatReturnChecks::BLOCKING && $result['passed'] === false) {
				return $result;
			}
		}

		return null;
	}//end firstBlockingFailure()
}//end class
