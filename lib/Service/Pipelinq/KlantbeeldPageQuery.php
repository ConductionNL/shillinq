<?php

/**
 * Klantbeeld Page Query
 *
 * One page request for a contact's klantbeeld: the contact id and the
 * clamped limit and offset, with the request path and the result envelopes
 * that page answers with.
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

use OCA\Shillinq\Kernel\Model\KlantbeeldResult;
use OCA\Shillinq\Kernel\Model\KlantbeeldTransaction;

/**
 * A klantbeeld page window for one contact.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-04-klantbeeld-read/tasks.md
 */
final class KlantbeeldPageQuery {

	/**
	 * Page size, already clamped to 1..100.
	 *
	 * @var int
	 */
	public int $limit = 5;

	/**
	 * Page offset, already clamped to 0 or more.
	 *
	 * @var int
	 */
	public int $offset = 0;

	/**
	 * Constructor.
	 *
	 * @param string $externalId Pipelinq contact id.
	 */
	public function __construct(
		public readonly string $externalId,
	) {

	}//end __construct()

	/**
	 * The klantbeeld path for this page, relative to the endpoint.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-04-klantbeeld-read/tasks.md
	 */
	public function path(): string {
		return sprintf(
			'/api/v1/contacts/%s/klantbeeld?limit=%d&offset=%d',
			rawurlencode($this->externalId),
			$this->limit,
			$this->offset
		);

	}//end path()

	/**
	 * An available page carrying the given rows.
	 *
	 * @param array<int, KlantbeeldTransaction> $transactions Page rows (possibly empty).
	 *
	 * @return KlantbeeldResult
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-04-klantbeeld-read/tasks.md
	 */
	public function available(array $transactions): KlantbeeldResult {
		return KlantbeeldResult::available(transactions: $transactions, limit: $this->limit, offset: $this->offset);

	}//end available()

	/**
	 * The unavailable envelope for this page.
	 *
	 * @return KlantbeeldResult
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-04-klantbeeld-read/tasks.md
	 */
	public function unavailable(): KlantbeeldResult {
		return KlantbeeldResult::unavailable(limit: $this->limit, offset: $this->offset);

	}//end unavailable()
}//end class
