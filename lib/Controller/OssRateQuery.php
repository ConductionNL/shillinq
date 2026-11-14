<?php

/**
 * Oss Rate Query
 *
 * The country, rate category and invoice date one OSS rate lookup is asked for.
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
 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCP\IRequest;

/**
 * The trimmed rate-lookup parameters, read in order: country, category, date.
 *
 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
 */
final class OssRateQuery {
	/**
	 * Hold the values.
	 *
	 * @param array{country: string, category: string, date: string} $values The trimmed parameters.
	 */
	private function __construct(private readonly array $values) {
	}//end __construct()

	/**
	 * Read the parameters: the country upper-cased, the category defaulting to `standard`.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return self
	 */
	public static function fromRequest(IRequest $request): self {
		$country = strtoupper(trim((string)$request->getParam('country', '')));
		$category = trim((string)$request->getParam('category', 'standard'));
		$date = trim((string)$request->getParam('date', ''));

		return new self(values: ['country' => $country, 'category' => $category, 'date' => $date]);
	}//end fromRequest()

	/**
	 * The ISO 3166-1 alpha-2 country.
	 *
	 * @return string
	 */
	public function country(): string {
		return $this->values['country'];
	}//end country()

	/**
	 * The rate category.
	 *
	 * @return string
	 */
	public function category(): string {
		return $this->values['category'];
	}//end category()

	/**
	 * The invoice date.
	 *
	 * @return string
	 */
	public function date(): string {
		return $this->values['date'];
	}//end date()

	/**
	 * The message for a malformed country, then for a malformed date.
	 *
	 * @return string|null Null when both are well formed.
	 */
	public function formatError(): ?string {
		if (preg_match('/^[A-Z]{2}$/', $this->country()) !== 1) {
			return 'country must be an ISO 3166-1 alpha-2 code';
		}

		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date()) !== 1) {
			return 'date must be YYYY-MM-DD';
		}

		return null;
	}//end formatError()
}//end class
