<?php

/**
 * Klantbeeld response envelope returned by the pipelinq adapter.
 *
 * Slice 04 of the `bookings-pipelinq-customer-bridge` chain (ADR-032). The
 * envelope wraps the page of transactions with the pagination metadata
 * (the limit and offset that produced this page) AND an "unavailable"
 * marker that the slice-05 controller and slice-06 UI use to distinguish
 * three legitimate outcomes:
 *
 *   1. Available + non-empty — render the rows.
 *   2. Available + empty — render "no previous transactions" (NOT an error).
 *   3. Unavailable — render the profile but hide / replace history.
 *
 * The "unavailable" path is what the spec requires when the Contact fetch
 * succeeded (slice 03) but klantbeeld returns 5xx — we don't want to lose
 * the profile just because the transaction warehouse is down. The
 * `unavailable` factory captures that intent at the type level so callers
 * can `if ($result->isUnavailable())` instead of treating an empty array
 * the same as a backend outage.
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
 * Immutable envelope: page of {@see KlantbeeldTransaction} + meta.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-04-klantbeeld-read/tasks.md
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Pre-existing debt (issue
 *     #506): changing this constructor signature would ripple to callers;
 *     deferred.
 */
final class KlantbeeldResult implements \JsonSerializable {
	/**
	 * Constructor (use the named factories for clarity).
	 *
	 * @param array<int, KlantbeeldTransaction> $transactions Page of transaction rows (possibly empty).
	 * @param int $limit Limit used to produce this page (echoed back for the UI).
	 * @param int $offset Offset used to produce this page (echoed back for the UI).
	 * @param bool $unavailable TRUE when klantbeeld 5xx'd while Contact succeeded — UI hides history.
	 */
	public function __construct(
		public readonly array $transactions,
		public readonly int $limit,
		public readonly int $offset,
		public readonly bool $unavailable = false,
	) {

	}//end __construct()

	/**
	 * Build an available result (possibly empty transactions).
	 *
	 * @param array<int, KlantbeeldTransaction> $transactions Page of rows.
	 * @param int $limit Page limit.
	 * @param int $offset Page offset.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-04-klantbeeld-read/tasks.md
	 */
	public static function available(array $transactions, int $limit, int $offset): self {
		return new self(
			transactions: $transactions,
			limit: $limit,
			offset: $offset,
			unavailable: false
		);

	}//end available()

	/**
	 * Build an "unavailable" marker for the 5xx-while-Contact-succeeded case.
	 *
	 * The UI uses this to render the profile but hide / replace the
	 * history surface. The {@see self::$limit} / {@see self::$offset}
	 * fields are echoed back so the UI can hint at the next retry.
	 *
	 * @param int $limit Page limit the caller requested.
	 * @param int $offset Page offset the caller requested.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-04-klantbeeld-read/tasks.md
	 */
	public static function unavailable(int $limit, int $offset): self {
		return new self(
			transactions: [],
			limit: $limit,
			offset: $offset,
			unavailable: true
		);

	}//end unavailable()

	/**
	 * Was the klantbeeld backend unavailable for this fetch?
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-04-klantbeeld-read/tasks.md
	 */
	public function isUnavailable(): bool {
		return $this->unavailable;
	}//end isUnavailable()

	/**
	 * Did the call succeed but return no transactions?
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-04-klantbeeld-read/tasks.md
	 */
	public function isEmpty(): bool {
		return $this->unavailable === false && $this->transactions === [];
	}//end isEmpty()

	/**
	 * Serialise to the API shape the controller (slice 05) returns.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-04-klantbeeld-read/tasks.md
	 */
	public function jsonSerialize(): array {
		return [
			'transactions' => array_map(
				static fn (KlantbeeldTransaction $t): array => $t->jsonSerialize(),
				$this->transactions
			),
			'limit' => $this->limit,
			'offset' => $this->offset,
			'unavailable' => $this->unavailable,
		];

	}//end jsonSerialize()
}//end class
