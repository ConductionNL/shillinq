<?php

/**
 * Revenue Task Field Lookup
 *
 * Reads the BBV task field (taakveld) of a revenue account from its Account
 * record (Q-shillinq-2), so a fee schedule can carry it as a column.
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
 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md#req-sopr-011
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Account number to task field.
 *
 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md#req-sopr-011
 */
class RevenueTaskFieldLookup {

	/**
	 * Construct the lookup.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 * @param IAppConfig $appConfig App config (register slug).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The task field of one account. Null when the account is unknown, carries
	 * none, or cannot be read: nothing is guessed, and a failed read never
	 * blocks the write that asked.
	 *
	 * @param string $accountNumber The revenue account number.
	 *
	 * @return string|null The task field, e.g. `8.3`, or null.
	 *
	 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md#req-sopr-011
	 */
	public function forAccount(string $accountNumber): ?string {
		if ($accountNumber === '') {
			return null;
		}

		try {
			$rows = $this->objectService
				->setRegister($this->registerSlug())
				->setSchema('Account')
				->findAll(['filters' => ['accountNumber' => $accountNumber], 'limit' => 50]);
		} catch (Throwable $e) {
			$this->logger->warning('Shillinq: could not read the revenue account task field', ['exception' => $e->getMessage()]);
			return null;
		}

		foreach ($rows as $row) {
			if (is_object($row) === true && method_exists($row, 'getObject') === true) {
				$row = $row->getObject();
			}

			if (is_array($row) === false || (string)($row['accountNumber'] ?? '') !== $accountNumber) {
				continue;
			}

			$taskField = trim((string)($row['taskField'] ?? ''));
			if ($taskField !== '') {
				return $taskField;
			}
		}

		return null;
	}//end forAccount()

	/**
	 * The register slug holding shillinq's own objects.
	 *
	 * @return string The slug, `shillinq` when blank.
	 */
	private function registerSlug(): string {
		$register = $this->appConfig->getValueString('shillinq', 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end registerSlug()
}//end class
