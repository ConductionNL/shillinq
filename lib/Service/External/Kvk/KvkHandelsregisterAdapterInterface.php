<?php

/**
 * KvK Handelsregister lookup port.
 *
 * The Kamer van Koophandel Handelsregister-API is the canonical
 * source-of-truth for Dutch legal entities. Shillinq consumes it on
 * three lifecycles:
 *  1. Multi-administration onboarding — the
 *     `bookkeeping-multi-administratie` wizard pre-fills an
 *     `Administratie` record from the KvK number (rechtsvorm, statutaire
 *     naam, RSIN, vestigingsadres, sectorindeling SBI) so an accountant
 *     does not retype data already in the register.
 *  2. AR debtor + AP creditor enrichment — when a `DebtorContact` /
 *     `SupplierContact` is created with a KvK number, the engine pulls
 *     the active vestiging's address + bezoekadres, the
 *     uitschrijving-flag (rechtsvorm-end ⇒ block new invoicing) and the
 *     active `bestuurdersinformatie` for the BTW-verleggingscheck on
 *     B2B invoices.
 *  3. Consolidation diagrammen — the
 *     `bookkeeping-consolidation-commercial` cycle walks the KvK
 *     deelnemingen-graaf to detect groepsmaatschappij-relaties that
 *     should be eliminated.
 *
 * The port is intentionally narrow — `lookup($kvkNumber)` returning a
 * structured result — so the production binding (openconnector source
 * slug `kvk-handelsregister`, OAuth2 + per-tenant API key) can be
 * swapped in via `Application::register()` without touching any
 * orchestrator. Until that binding is configured, the default binding
 * is dormant: it logs the intent + returns a synthetic
 * `LOOKUP_DEFERRED` outcome so the surrounding lifecycle stays
 * observable in test + staging environments.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\Kvk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 * @link https://developers.kvk.nl/apis/handelsregister
 *
 * @spec openspec/specs/bookkeeping-multi-administratie/spec.md
 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
 * @spec openspec/changes/bookkeeping-consolidation-commercial/proposal.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\Kvk;

/**
 * KvK Handelsregister lookup port.
 *
 * Implementations MUST be side-effect-free when the dormant flag is set;
 * a dormant adapter records the intent (logger, audit trail) and returns
 * a synthetic LOOKUP_DEFERRED outcome so the surrounding lifecycle can
 * advance into `awaiting-kvk-enrichment` without contacting the KvK.
 *
 * Activation steps for a real KvK Handelsregister binding:
 *  1. Provision a KvK Handelsregister API key (production tier) via the
 *     KvK developer portal.
 *  2. Create an openconnector source with slug
 *     `kvk-handelsregister`, pointing at the Handelsregister-Profile API
 *     v1 endpoint (`api.kvk.nl/api/v1/handelsregister`).
 *  3. Override the KvkHandelsregisterAdapterInterface DI binding in
 *     `Application::register()` to the openconnector-backed
 *     implementation.
 *
 * @spec openspec/specs/bookkeeping-multi-administratie/spec.md
 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
 */
interface KvkHandelsregisterAdapterInterface {
	/**
	 * Look up a legal entity by KvK number.
	 *
	 * @param string $kvkNumber 8-digit KvK number — leading zeros
	 *                          preserved.
	 * @param array<string,mixed> $context Optional context — administrationId,
	 *                                     lookupReason (`onboarding` |
	 *                                     `ar-enrichment` |
	 *                                     `ap-enrichment` |
	 *                                     `consolidation-graph`),
	 *                                     correlationId.
	 *
	 * @return KvkLookupResult The lookup outcome (status + entity envelope
	 *                         + optional vestiging list).
	 */
	public function lookup(string $kvkNumber, array $context = []): KvkLookupResult;

	/**
	 * Whether the adapter is dormant — i.e. wired but not contacting
	 * the KvK Handelsregister.
	 *
	 * @return bool TRUE when the adapter is a log-only stub.
	 */
	public function isDormant(): bool;
}//end interface
