<?php

/**
 * Invoice Line Scope
 *
 * The administration, supplier invoice and invoice line a multi-PO
 * consolidation request is about.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCP\IRequest;

/**
 * The scope parameters of a multi-PO consolidation request, read from the request.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
 */
final class InvoiceLineScope {
	/**
	 * Short-slug identifier pattern shared by every scope/path parameter.
	 *
	 * @var string
	 */
	public const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * The administration, '' when blank or malformed.
	 *
	 * @var string
	 */
	public readonly string $administrationId;

	/**
	 * The supplier invoice, '' when blank or malformed.
	 *
	 * @var string
	 */
	public readonly string $invoiceId;

	/**
	 * The 1-based invoice line number (0 when not given).
	 *
	 * @var int
	 */
	public readonly int $invoiceLineNumber;

	/**
	 * Read the scope parameters.
	 *
	 * @param IRequest $request The request.
	 */
	public function __construct(IRequest $request) {
		$this->administrationId = self::slug(value: $request->getParam('administrationId', ''));
		$this->invoiceId = self::slug(value: $request->getParam('invoiceId', ''));
		$this->invoiceLineNumber = (int)$request->getParam('invoiceLineNumber', 0);

	}//end __construct()

	/**
	 * The administration and invoice as log context, with the failure.
	 *
	 * @param \Throwable $e The failure.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
	 */
	public function invoiceLogContext(\Throwable $e): array {
		return [
			'administrationId' => $this->administrationId,
			'invoiceId' => $this->invoiceId,
			'exception' => $e->getMessage(),
		];
	}//end invoiceLogContext()

	/**
	 * The administration, invoice and line number as log context, with the failure.
	 *
	 * @param \Throwable $e The failure.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
	 */
	public function lineLogContext(\Throwable $e): array {
		return [
			'administrationId' => $this->administrationId,
			'invoiceId' => $this->invoiceId,
			'invoiceLineNumber' => $this->invoiceLineNumber,
			'exception' => $e->getMessage(),
		];
	}//end lineLogContext()

	/**
	 * A trimmed identifier, '' when blank or malformed.
	 *
	 * @param mixed $value The raw parameter.
	 *
	 * @return string
	 */
	private static function slug(mixed $value): string {
		$value = trim((string)$value);
		if ($value === '' || preg_match(self::ID_PATTERN, $value) !== 1) {
			return '';
		}

		return $value;
	}//end slug()
}//end class
