<?php

/**
 * Dashboard Range
 *
 * The optional from/to bounds of the financial dashboard endpoints as read
 * from the request.
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
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCP\IRequest;

/**
 * The trimmed from/to bounds and their validation.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 */
final class DashboardRange {
	/**
	 * Accepted shape for the from/to bounds: a `YYYY-MM-DD` day, optionally
	 * followed by a time suffix (the service only reads the first ten
	 * characters, mirroring the client's `.slice(0, 10)`).
	 *
	 * @var string
	 */
	private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}([T ][0-9:.+\-Z]{1,22})?$/';

	/**
	 * The lower bound, '' when not given.
	 *
	 * @var string
	 */
	public readonly string $from;

	/**
	 * The upper bound, '' when not given.
	 *
	 * @var string
	 */
	public readonly string $to;

	/**
	 * Read the bounds from the request.
	 *
	 * @param IRequest $request The request.
	 */
	public function __construct(IRequest $request) {
		$this->from = trim((string)$request->getParam('from', ''));
		$this->to = trim((string)$request->getParam('to', ''));

	}//end __construct()

	/**
	 * The validation error of the bounds, checked in order: both or neither,
	 * then the shape of `from`, then the shape of `to`.
	 *
	 * @return string|null The error text, or null when the range is valid.
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	public function error(): ?string {
		if (($this->from === '') !== ($this->to === '')) {
			return 'from and to must be provided together';
		}

		if (self::malformed(bound: $this->from) === true) {
			return 'from must be an ISO-8601 date';
		}

		if (self::malformed(bound: $this->to) === true) {
			return 'to must be an ISO-8601 date';
		}

		return null;
	}//end error()

	/**
	 * The lower bound for the service: null when not given.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	public function fromBound(): ?string {
		return self::orNull(bound: $this->from);
	}//end fromBound()

	/**
	 * The upper bound for the service: null when not given.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	public function toBound(): ?string {
		return self::orNull(bound: $this->to);
	}//end toBound()

	/**
	 * Whether a given bound does not match the accepted date shape.
	 *
	 * @param string $bound The bound.
	 *
	 * @return bool
	 */
	private static function malformed(string $bound): bool {
		return $bound !== '' && preg_match(self::DATE_PATTERN, $bound) !== 1;
	}//end malformed()

	/**
	 * Null for an empty bound, the bound otherwise.
	 *
	 * @param string $bound The bound.
	 *
	 * @return string|null
	 */
	private static function orNull(string $bound): ?string {
		if ($bound === '') {
			return null;
		}

		return $bound;
	}//end orNull()
}//end class
