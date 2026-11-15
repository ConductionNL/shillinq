<?php

/**
 * Archivable Document
 *
 * The id, administration and filing date of one filed Document the
 * archive cron considers.
 *
 * @category Cron
 * @package  OCA\Shillinq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-29
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

/**
 * A filed Document row's identity and filing date.
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-29
 */
final class ArchivableDocument {
	/**
	 * The document id (its id, else its documentNumber).
	 *
	 * @var string
	 */
	public readonly string $documentId;

	/**
	 * The administration.
	 *
	 * @var string
	 */
	public readonly string $administrationId;

	/**
	 * When it was filed (its filedAt, else its documentDate).
	 *
	 * @var string
	 */
	public readonly string $filedAt;

	/**
	 * Read a filed Document row.
	 *
	 * @param mixed $row The row.
	 */
	public function __construct(mixed $row) {
		$this->documentId = (string)($row['id'] ?? ($row['documentNumber'] ?? ''));
		$this->administrationId = (string)($row['administrationId'] ?? '');
		$this->filedAt = (string)($row['filedAt'] ?? ($row['documentDate'] ?? ''));

	}//end __construct()

	/**
	 * Whether the row names its document and its administration.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-29
	 */
	public function isIdentified(): bool {
		return $this->documentId !== '' && $this->administrationId !== '';
	}//end isIdentified()
}//end class
