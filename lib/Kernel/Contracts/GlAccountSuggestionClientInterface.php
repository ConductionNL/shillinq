<?php

/**
 * Contract of GlAccountSuggestionClient
 *
 * The public surface of GlAccountSuggestionClient that other components call, so they depend
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
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of GlAccountSuggestionClient.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GlAccountSuggestionClientInterface {

	/**
	 * Post the operator's committed GL-account booking back to docudesk as a
	 * correction, so the ranker's booking history reflects it (REQ-GAC-005).
	 * Posted whether or not the booked code matches the prior suggestion —
	 * docudesk's own `HistoryRanker` counts frequency over ALL past bookings
	 * (design.md Decision D3).
	 *
	 * @param string $extractionId The docudesk `financialExtraction` object id.
	 * @param string $accountCode The operator's committed GL-account code.
	 * @param string|null $accountLabel Optional account label.
	 *
	 * @return array{success: bool, statusCode: int, error: string|null} Outcome; never throws —
	 *                                                                   best-effort, MUST NOT block or undo the already-committed local booking.
	 *
	 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
	 */
	public function postCorrection(string $extractionId, string $accountCode, ?string $accountLabel): array;

	/**
	 * Request a GL-account suggestion for a prior docudesk financial
	 * extraction (REQ-GAC-003).
	 *
	 * Returns an outcome envelope and never throws. `suggestion` is docudesk's
	 * decoded response body, or null on any failure (REQ-GAC-006 graceful
	 * degradation).
	 *
	 * @param string $extractionId The docudesk `financialExtraction`
	 *                             object id.
	 * @param array<int, array<string,string>> $candidateAccounts Candidate accounts (each
	 *                                                            `{code, label}`), supplied by
	 *                                                            shillinq's own chart of accounts
	 *                                                            (REQ-GAC-002).
	 *
	 * @return array{success: bool, statusCode: int, error: string|null, suggestion: array<string,mixed>|null}
	 *
	 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
	 */
	public function requestSuggestion(string $extractionId, array $candidateAccounts): array;
}//end interface
