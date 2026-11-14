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
 * @spec openspec/changes/receivables-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md#requirement-the-batch-is-written-as-a-validated-pain008-file-req-sdd-012
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Service\Receivables\DirectDebitFileService;

/**
 * Writes the pain.008 file of a batch as it is generated.
 *
 * @spec openspec/changes/receivables-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md#requirement-the-batch-is-written-as-a-validated-pain008-file-req-sdd-012
 */
class GenerateDirectDebitFileAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param DirectDebitFileService $files Renders and validates the file.
	 */
	public function __construct(
		private readonly DirectDebitFileService $files,
	) {
	}//end __construct()

	/**
	 * The batch with its validated file, count and control sum.
	 *
	 * @param array<string,mixed> $objectData   The batch after the transition.
	 * @param array<string,mixed> $previousData The batch before it.
	 * @param array<string,mixed> $parameters   The declared actionParameters.
	 * @param string              $actionName   The declared action name.
	 *
	 * @return array<string,mixed> The batch to save.
	 *
	 * @throws \RuntimeException When the file has errors.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleActionInterface's.
	 *
	 * @spec openspec/changes/receivables-sepa-direct-debit/tasks.md#task-1.2
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		return $this->files->generate(batch: $objectData);

	}//end execute()
}//end class
