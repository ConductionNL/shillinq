<?php

/**
 * Duplicate Candidate
 *
 * Which earlier records count as a duplicate of an invoice: the same payee in
 * the record's own supplier field, and not one of the ids to ignore.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Purchasing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-29-purchasing-supplier-invoice-intake/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Purchasing;

/**
 * The payee and ignored ids a duplicate lookup compares records against.
 *
 * @spec openspec/changes/archive/2026-09-29-purchasing-supplier-invoice-intake/tasks.md#task-2.1
 */
final class DuplicateCandidate {

	/**
	 * The payee id.
	 *
	 * @var string
	 */
	private string $supplierId;

	/**
	 * The invoice itself and the AP transaction it was handed to.
	 *
	 * @var list<string>
	 */
	private array $ignoreIds;

	/**
	 * Constructor.
	 *
	 * @param string       $supplierId The payee id.
	 * @param list<string> $ignoreIds  The ids that are never a duplicate.
	 */
	public function __construct(string $supplierId, array $ignoreIds) {
		$this->supplierId = $supplierId;
		$this->ignoreIds  = $ignoreIds;

	}//end __construct()

	/**
	 * Whether a record is a duplicate: the same payee in its supplier field, and not ignored.
	 *
	 * @param array<string, mixed> $record        The record.
	 * @param string               $supplierField The record's supplier field.
	 *
	 * @return bool True for a duplicate.
	 *
	 * @spec openspec/changes/archive/2026-09-29-purchasing-supplier-invoice-intake/tasks.md#task-2.1
	 */
	public function matches(array $record, string $supplierField): bool {
		return (string)($record[$supplierField] ?? '') === $this->supplierId
			&& in_array((string)($record['id'] ?? ''), $this->ignoreIds, true) === false;

	}//end matches()
}//end class
