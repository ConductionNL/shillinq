<?php

/**
 * Contract of BadoControleprotocolService
 *
 * The public surface of BadoControleprotocolService that other components call, so they depend
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
 * Contract of BadoControleprotocolService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BadoControleprotocolServiceInterface {

	/**
	 * Compute the full per-topic aggregation + derived opinion for a protocol (REQ-006, REQ-007).
	 *
	 * Server-authoritative: loads the protocol's materialiteit, all
	 * AuditFindings (via their parent AuditSamples) and the ToleranceMatrix
	 * rows, reclassifies each agreed/resolved finding's severity against the
	 * frozen materialiteit ceilings, aggregates per topic and derives the
	 * proposed opinion. The breakdown rows are never trusted from the client.
	 *
	 * @param string $protocolId The Controleprotocol.id to aggregate.
	 *
	 * @return array{protocolId: string, materialityAmount: float, topics: array<int,array<string,mixed>>, proposedOpinion: string}
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-12
	 */
	public function computeAggregation(string $protocolId): array;

	/**
	 * Resolve the tenant (organisation) a Controleprotocol belongs to.
	 *
	 * `Controleprotocol.organisationId` is the tenant scope for the whole BADO
	 * bundle: none of the six child schemas (ToleranceMatrix, Materialiteit,
	 * AuditSample, AuditFinding, VerklaringDraft, SiSaAssurance) carries a tenant
	 * field of its own — they hang off the protocol's FK. So the protocol's
	 * organisationId is the only value an ADR-005 membership check can be made
	 * against, and this accessor exists so the controller can make it: every
	 * loader in this class and in AccountantsdossierExportService was private.
	 *
	 * @param string $protocolId The Controleprotocol.id.
	 *
	 * @return string|null The organisation id, or null when the protocol does not exist.
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-12
	 */
	public function organisationIdFor(string $protocolId): ?string;
}//end interface
