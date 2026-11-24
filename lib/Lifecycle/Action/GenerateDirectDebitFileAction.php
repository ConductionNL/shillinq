<?php

/**
 * Generate Direct Debit File Action
 *
 * The declared action of the DirectDebitBatch `generate` transition: it
 * writes the batch's pain.008.001.02 file, validated against the XSD, into
 * `pain008Xml`. A file with errors throws, so the transition fails and the
 * batch stays draft with the errors.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-receivables-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md#requirement-the-batch-is-written-as-a-validated-pain008-file-req-sdd-012
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use OCA\OpenRegister\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Kernel\Contracts\DirectDebitFileServiceInterface;

/**
 * Writes the pain.008 file of a batch as it is generated.
 *
 * @spec openspec/changes/archive/2026-11-24-receivables-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md#requirement-the-batch-is-written-as-a-validated-pain008-file-req-sdd-012
 */
class GenerateDirectDebitFileAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param DirectDebitFileServiceInterface $files Renders and validates the file.
	 */
	public function __construct(
		private readonly DirectDebitFileServiceInterface $files,
	) {
	}//end __construct()

	/**
	 * The batch with its validated file, count and control sum.
	 *
	 * @param LifecycleActionContext $context The transition; its objectData is the batch after the transition.
	 *
	 * @return array<string,mixed> The batch to save.
	 *
	 * @throws \RuntimeException When the file has errors.
	 *
	 * @spec openspec/changes/archive/2026-11-24-receivables-sepa-direct-debit/tasks.md#task-1.2
	 */
	public function execute(LifecycleActionContext $context): array {
		$objectData = $context->objectData;

		return $this->files->generate(batch: $objectData);

	}//end execute()
}//end class
