<?php

/**
 * Minimal HookStoppedException stub for unit tests.
 *
 * Same constructor and getErrors() as OpenRegister's class: MagicMapper throws
 * it when a pre-save listener (a lifecycle guard such as
 * PaymentRunDuplicateGuard) stops the save.
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use Exception;
use Throwable;

/**
 * Stub for OCA\OpenRegister\Exception\HookStoppedException.
 */
class HookStoppedException extends Exception {

	/**
	 * Validation errors from the hook.
	 *
	 * @var array<string, mixed>
	 */
	private readonly array $errors;

	/**
	 * Constructor.
	 *
	 * @param string               $message  Error message.
	 * @param array<string, mixed> $errors   Hook validation errors.
	 * @param int                  $code     Error code.
	 * @param Throwable|null       $previous Previous exception.
	 */
	public function __construct(
		string $message = 'Operation blocked by schema hook',
		array $errors = [],
		int $code = 0,
		?Throwable $previous = null,
	) {
		$this->errors = $errors;
		parent::__construct(message: $message, code: $code, previous: $previous);
	}//end __construct()

	/**
	 * Get the hook validation errors.
	 *
	 * @return array<string, mixed>
	 */
	public function getErrors(): array {
		return $this->errors;
	}//end getErrors()
}//end class
