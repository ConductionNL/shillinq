<?php

/**
 * Contract of FluxService
 *
 * The public surface of FluxService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of FluxService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FluxServiceInterface {

	/**
	 * Aggregate run items into a flux narrative ordered by absolute variance (REQ-CLS-007).
	 *
	 * @param array<int,array<string,mixed>> $items Flux items (typically from run()['items']).
	 * @param string $periodId The yyyy-mm period.
	 *
	 * @return array{
	 *   periodId: string,
	 *   itemCount: int,
	 *   explainedCount: int,
	 *   unexplainedCount: int,
	 *   totalAdverseCents: int,
	 *   rows: array<int,array<string,mixed>>
	 * } Narrative structure.
	 *
	 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-21
	 */
	public function buildNarrative(array $items, string $periodId): array;

	/**
	 * Render the flux narrative as Markdown (REQ-CLS-007).
	 *
	 * @param array<string,mixed> $narrative Output of buildNarrative().
	 *
	 * @return string Markdown text.
	 *
	 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-21
	 */
	public function renderNarrativeMarkdown(array $narrative): string;

	/**
	 * Build a 1-page PDF-style plain text rendering of the narrative (REQ-CLS-007).
	 *
	 * We render to plain text rather than binary PDF to keep the implementation
	 * portable; the real PDF is wrapped by a downstream renderer. The contract
	 * is the layout (letterhead + period + signature line).
	 *
	 * @param array<string,mixed> $narrative Output of buildNarrative().
	 *
	 * @return string Plain-text PDF-layout body.
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function renderNarrativePdfBody(array $narrative): string;

	/**
	 * Run a flux analysis for an administratie + period (REQ-CLS-005).
	 *
	 * @param array<string,mixed> $inputs Run inputs:
	 *                                    {
	 *                                    administrationId, periodId, scope, comparisonBasis,
	 *                                    accounts: [{glAccountNumber, accountGroupCode, actualCents, basisCents, attributions}],
	 *                                    materialityPolicy: {...},
	 *                                    runTimestamp?: DateTimeImmutable
	 *                                    }
	 *
	 * @return array{
	 *   fluxRunId: string,
	 *   itemCount: int,
	 *   materialCount: int,
	 *   autoExplainedCount: int,
	 *   escalatedCount: int,
	 *   totalVarianceCents: int,
	 *   items: array<int, array<string, mixed>>
	 * } Run summary.
	 *
	 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-21
	 */
	public function run(array $inputs): array;
}//end interface
