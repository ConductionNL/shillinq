<?php

/**
 * RvO (Rijksdienst voor Ondernemend Nederland) aanvraag port.
 *
 * RvO is the executive agency for Dutch entrepreneurial schemes —
 * it administers WBSO (R&D wage-tax credit), SnO (Speur- en
 * Ontwikkelingswerk), the investment-allowance schemes (KIA / EIA /
 * MIA / Vamil / Subsidie Verduurzaming MKB), and a long tail of
 * grant programmes (DEI+, SDE++, ISDE …). Shillinq materialises a
 * single aanvraag (application or progress report) from the
 * schemas declared by `bookkeeping-wbso-sno-administratie` and
 * `bookkeeping-investeringsaftrek` and hands the prepared
 * documents to this adapter for transport to the RvO
 * subsidieportaal via eHerkenning.
 *
 * The port is intentionally narrow — one submit call returning a
 * dispatch outcome — so the production binding (eHerkenning Level 3
 * / Idensys + RvO Mijn-RvO REST endpoints) can be swapped in via
 * `Application::register()` without touching the orchestrator.
 * Until an openconnector-backed binding to source slug
 * `rvo-aanvraag` is configured, the default binding is dormant:
 * it logs the intent without contacting RvO so the lifecycle stays
 * observable in test + staging environments.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\RvO
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 * @link https://www.rvo.nl/subsidies-financiering/wbso
 * @link https://www.rvo.nl/subsidies-financiering/investeringsregelingen
 *
 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
 * @spec openspec/specs/bookkeeping-wbso-sno-administratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\RvO;

/**
 * RvO subsidy/scheme aanvraag dispatch port.
 *
 * Implementations MUST be side-effect-free when the dormant flag is set;
 * a dormant adapter records the intent (logger, audit trail) and returns
 * a synthetic DEFERRED outcome so the surrounding lifecycle can advance
 * into `submitted-to-rvo` without contacting RvO.
 *
 * Activation steps for a real RvO binding:
 *  1. Provision eHerkenning Level 3 (or higher) for the
 *     tenant org + register the dienst at the
 *     RvO subsidieportaal.
 *  2. Create an openconnector source with slug
 *     `rvo-aanvraag`, pointing at the Mijn-RvO
 *     REST endpoint for the relevant scheme
 *     (WBSO / KIA / EIA / MIA / Vamil).
 *  3. Override the RvOAanvraagAdapterInterface DI binding in
 *     `Application::register()` to the openconnector-backed
 *     implementation.
 *
 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
 * @spec openspec/specs/bookkeeping-wbso-sno-administratie/spec.md
 */
interface RvOAanvraagAdapterInterface {
	/**
	 * Submit an RvO aanvraag (application, progress report, or
	 * mededeling werkelijk gerealiseerde uren / investeringen).
	 *
	 * @param array<string,mixed> $payload The RvO aanvraag envelope —
	 *                                     scheme (`wbso`/`sno`/`kia`/
	 *                                     `eia`/`mia`/`vamil`),
	 *                                     aanvraagType (`aanvraag`/
	 *                                     `voortgangsmelding`/
	 *                                     `mededeling`),
	 *                                     periodYear, periodMonth,
	 *                                     organizationLegalName,
	 *                                     kvkNumber, rsinNumber,
	 *                                     eHerkenningOin (intermediair),
	 *                                     projectsOrInvestments[]
	 *                                     (per-project hours /
	 *                                     per-investment bedragen),
	 *                                     attachmentBytes (binary),
	 *                                     attachmentChecksum,
	 *                                     correlationId.
	 *
	 * @return RvORequestResult The dispatch outcome (status + RvO
	 *                          aanvraagnummer).
	 */
	public function submit(array $payload): RvORequestResult;

	/**
	 * Whether the adapter is dormant — i.e. wired but not contacting RvO.
	 *
	 * @return bool TRUE when the adapter is a log-only stub.
	 */
	public function isDormant(): bool;
}//end interface
