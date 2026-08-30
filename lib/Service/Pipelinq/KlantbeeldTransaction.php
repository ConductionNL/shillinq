<?php

/**
 * Klantbeeld transaction row returned by the pipelinq adapter.
 *
 * Slice 04 of the `bookings-pipelinq-customer-bridge` chain (ADR-032). A
 * single row of the customer-360 transaction history surface: the date the
 * transaction was booked, a human-readable description, the amount and
 * currency, and a status string (one of `paid`, `pending`, `failed`,
 * `refunded` per the pipelinq API spec — but the adapter passes the value
 * through verbatim so the UI can render whatever the backend sends).
 *
 * The shape mirrors the pipelinq klantbeeld response payload one-to-one so
 * the slice-05 controller and slice-06 UI can render without extra mapping.
 * Immutable by design (every field is `readonly`); construction is via
 * {@see self::fromArray()} which tolerates missing keys with sane defaults
 * so a partially-degraded backend response never blows up the detail view.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Pipelinq
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-04-klantbeeld-read/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Pipelinq;

/**
 * Immutable value object: one row of klantbeeld history.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-04-klantbeeld-read/tasks.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
final class KlantbeeldTransaction implements \JsonSerializable {
	/**
	 * Constructor.
	 *
	 * @param string $date ISO-8601 date string (YYYY-MM-DD or RFC3339) when the transaction was booked.
	 * @param string $description Short human-readable description (e.g. invoice number, line summary).
	 * @param float $amount Signed amount (positive = receivable, negative = payable) in the given currency.
	 * @param string $currency ISO-4217 currency code (e.g. EUR).
	 * @param string $status Backend-defined status string (paid/pending/failed/refunded/…).
	 */
	public function __construct(
		public readonly string $date,
		public readonly string $description,
		public readonly float $amount,
		public readonly string $currency,
		public readonly string $status,
	) {

	}//end __construct()

	/**
	 * Build a transaction from the pipelinq JSON-decoded shape.
	 *
	 * Tolerates missing or null fields by falling back to empty strings /
	 * zero so a single malformed row never aborts the entire klantbeeld
	 * render. Unknown fields are ignored.
	 *
	 * @param array<string, mixed> $row One element of the `transactions` array as decoded by `json_decode`.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-04-klantbeeld-read/tasks.md
	 */
	public static function fromArray(array $row): self {
		$date = self::asString(value: ($row['date'] ?? null));
		$description = self::asString(value: ($row['description'] ?? null));
		$currency = self::asString(value: ($row['currency'] ?? null));
		$status = self::asString(value: ($row['status'] ?? null));

		$rawAmount = ($row['amount'] ?? 0);
		if (is_int($rawAmount) === true || is_float($rawAmount) === true) {
			$amount = (float)$rawAmount;
		} elseif (is_string($rawAmount) === true && is_numeric($rawAmount) === true) {
			$amount = (float)$rawAmount;
		} else {
			$amount = 0.0;
		}

		return new self(
			date: $date,
			description: $description,
			amount: $amount,
			currency: $currency,
			status: $status
		);

	}//end fromArray()

	/**
	 * Coerce a `mixed` slot to a string, falling back to empty.
	 *
	 * @param mixed $value Raw JSON-decoded value.
	 *
	 * @return string
	 */
	private static function asString(mixed $value): string {
		if (is_string($value) === true) {
			return $value;
		}

		return '';
	}//end asString()

	/**
	 * Serialise back to the pipelinq JSON shape for the API surface.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-04-klantbeeld-read/tasks.md
	 */
	public function jsonSerialize(): array {
		return [
			'date' => $this->date,
			'description' => $this->description,
			'amount' => $this->amount,
			'currency' => $this->currency,
			'status' => $this->status,
		];

	}//end jsonSerialize()
}//end class
