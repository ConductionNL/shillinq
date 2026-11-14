<?php

/**
 * Payment run export run
 *
 * One export of a PaymentRun: the administration, run number and user the
 * files are stored for, and what storing them produced — the stored-file
 * list, the stored nodes (removed again when the run cannot be exported) and
 * the pain.001 file reference.
 *
 * @category PaymentRun
 * @package  OCA\Shillinq\PaymentRun
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/payment-run-sepa-export/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\PaymentRun;

/**
 * Progress of one PaymentRunExportService::export() call; never leaves the
 * service.
 *
 * @spec openspec/specs/payment-run-sepa-export/spec.md
 */
final class PaymentRunExportRun {

	/**
	 * The administration the files are filed under.
	 *
	 * @var string
	 */
	public readonly string $administrationId;

	/**
	 * The run number the files are tagged with.
	 *
	 * @var string
	 */
	public readonly string $runNumber;

	/**
	 * One entry per rendered file: format, fileName, filePath, fileId.
	 *
	 * @var list<array{format: string, fileName: string, filePath: ?string, fileId: ?int}>
	 */
	public array $files = [];

	/**
	 * The stored file nodes.
	 *
	 * @var list<object>
	 */
	public array $nodes = [];

	/**
	 * Path of the stored pain.001 file, or null while it is not stored.
	 *
	 * @var string|null
	 */
	public ?string $xmlFileRef = null;

	/**
	 * Export timestamp (ISO 8601 UTC), once written back.
	 *
	 * @var string
	 */
	public string $exportedAt = '';

	/**
	 * Read the filing scope from the run.
	 *
	 * @param array<string, mixed> $paymentRun The PaymentRun object array.
	 * @param string               $userId     The user the files are stored for ('' when none).
	 */
	public function __construct(
		array $paymentRun,
		public readonly string $userId,
	) {
		$this->administrationId = (string)($paymentRun['administrationId'] ?? '');
		$this->runNumber = (string)($paymentRun['runNumber'] ?? '');
	}//end __construct()
}//end class
