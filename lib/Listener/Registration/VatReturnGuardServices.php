<?php

/**
 * VAT Return Guard Services
 *
 * The container registration of the BtwAangifte `submit` guard tag.
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener\Registration
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

namespace OCA\Shillinq\Listener\Registration;

use OCA\Shillinq\Lifecycle\VatReturnChecksGuard;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds the `requires` tag of BtwAangifte.submit to VatReturnChecksGuard.
 *
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.2
 */
final class VatReturnGuardServices {

	/**
	 * The tag the register's `submit` transition names in `requires`.
	 *
	 * @var string
	 */
	public const SUBMIT_TAG = 'OCA\Shillinq\Lifecycle\VatReturnChecksGuard::check';

	/**
	 * Register the guard under its tag.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.2
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerService(
			self::SUBMIT_TAG,
			static fn ($c): VatReturnChecksGuard => $c->get(VatReturnChecksGuard::class)
		);

	}//end register()
}//end class
