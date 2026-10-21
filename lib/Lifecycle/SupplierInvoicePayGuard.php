<?php

/**
 * Supplier Invoice Pay Guard
 *
 * Guards `SupplierInvoice.pay` (approved -> paid). An invoice is paid when the
 * AP transaction it was handed to is paid; that state is reached by the bank
 * match on the AP side. A supplier invoice marked paid while its AP
 * transaction is open, or without one, is refused.
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
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\Shillinq\Service\SettingsService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lets a supplier invoice become paid only when its AP transaction is paid.
 *
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.5
 */
final class SupplierInvoicePayGuard implements LifecycleGuardInterface {

	/**
	 * Refusal shown to the caller.
	 *
	 * @var string
	 */
	public const MESSAGE = 'The invoice is paid when its accounts-payable transaction is paid. Its transaction is not paid yet.';

	/**
	 * Construct the guard.
	 *
	 * @param ContainerInterface $container       DI container for the lazy ObjectService.
	 * @param SettingsService    $settingsService Resolves the register slug.
	 * @param LoggerInterface    $logger          Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Allow the transition only when the linked AP transaction is paid.
	 *
	 * @param array<string, mixed> $object The invoice as it would be saved.
	 * @param string               $action The transition, `pay`.
	 * @param string               $userId The caller.
	 *
	 * @return GuardResult
	 *
	 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.5
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$apTransactionId = trim((string)($object['apTransactionId'] ?? ''));
		if ($apTransactionId === '') {
			return GuardResult::deny(self::MESSAGE);
		}

		try {
			$rows = $this->container->get('OCA\OpenRegister\Service\ObjectService')
				->setRegister($this->settingsService->getRegisterSlug())
				->setSchema('APTransaction')
				->findAll(['filters' => ['id' => $apTransactionId], 'limit' => 1], false, false);
		} catch (Throwable $e) {
			$this->logger->error('SupplierInvoicePayGuard: AP transaction lookup failed; refusing', ['exception' => $e->getMessage()]);
			return GuardResult::deny(self::MESSAGE);
		}

		foreach ((array)$rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true && (string)($row['state'] ?? '') === 'paid') {
				return GuardResult::allow();
			}
		}

		return GuardResult::deny(self::MESSAGE);
	}//end check()
}//end class
