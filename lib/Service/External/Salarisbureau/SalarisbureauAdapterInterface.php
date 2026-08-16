<?php

/**
 * Salarisbureau (payroll bureau) integration port.
 *
 * Dutch detachering organisations (and most MKB employers) outsource
 * payroll runs to a salarisbureau — ADP, Loket.nl, Visma, Nmbrs,
 * Exact Loon, etc. The bureau owns the loonaangifte (wage-tax filing
 * to the Belastingdienst), the loonstroken (payslips), the
 * jaaropgaaf, and the integration with the pensioenfonds and STIPP.
 * Shillinq materialises the per-employee payroll-run delta from the
 * `EmployeePayrollRecord` / `PayrollRun` / `DetacheringContract`
 * schemas declared by the `bookkeeping-detachering-payroll-administratie`
 * + `bookkeeping-payroll-engine-nl` capabilities and hands the run
 * to this adapter for transport to the salarisbureau.
 *
 * The port is intentionally narrow — one submit call returning a
 * run outcome — so the production binding (per-bureau adapter:
 * ADP RUN-via-REST, Loket OAuth2 REST, Nmbrs SOAP, Visma OAuth2) can
 * be swapped in via `Application::register()` without touching the
 * orchestrator. Until an openconnector-backed binding to source slug
 * `salarisbureau-<vendor>` is configured, the default binding is
 * dormant: it logs the intent without contacting the bureau so the
 * lifecycle stays observable in test + staging environments.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\Salarisbureau
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 * @link https://developers.adp.com/articles/api/run-api
 * @link https://developer.loket.nl/
 * @link https://support.nmbrs.com/hc/nl/articles/360013506839
 *
 * @spec openspec/specs/bookkeeping-detachering-payroll-administratie/spec.md
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\Salarisbureau;

/**
 * Salarisbureau payroll-run dispatch port.
 *
 * Implementations MUST be side-effect-free when the dormant flag is set;
 * a dormant adapter records the intent (logger, audit trail) and returns
 * a synthetic DEFERRED outcome so the surrounding lifecycle can advance
 * into `submitted-to-bureau` without contacting the salarisbureau.
 *
 * Activation steps for a real salarisbureau binding:
 *  1. Decide which bureau-side credential scheme applies (ADP
 *     RUN: OAuth2 + client cert; Loket: OAuth2; Nmbrs: WSSE +
 *     SOAP; Visma: OAuth2).
 *  2. Create an openconnector source with slug
 *     `salarisbureau-<vendor>` per tenant.
 *  3. Override the SalarisbureauAdapterInterface DI binding in
 *     `Application::register()` to the vendor-specific
 *     implementation (e.g. `OpenConnectorAdpSalarisbureauAdapter`).
 *
 * @spec openspec/specs/bookkeeping-detachering-payroll-administratie/spec.md
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 */
interface SalarisbureauAdapterInterface {
	/**
	 * Submit a payroll-run delta to the salarisbureau.
	 *
	 * @param array<string,mixed> $payload The payroll-run envelope —
	 *                                     bureau (`adp`/`loket`/`nmbrs`/`visma`),
	 *                                     employerLoonheffingenNumber,
	 *                                     periodYear, periodMonth,
	 *                                     periodType (`maand`/`4-weken`),
	 *                                     employees[] (BSN, employeeNumber,
	 *                                     contractType, sectorCode,
	 *                                     loonHeffingsKorting, urenWerkelijk,
	 *                                     urenVerlof, bruto, fiscaleVergoeding,
	 *                                     pensioenpremie, stippAfdracht),
	 *                                     mutations[] (in-dienst /
	 *                                     uit-dienst / arbeidsverleden),
	 *                                     correlationId.
	 *
	 * @return SalarisbureauPayrollRunResult The dispatch outcome (status +
	 *                                       bureau run id + payslip URLs).
	 */
	public function submit(array $payload): SalarisbureauPayrollRunResult;

	/**
	 * Whether the adapter is dormant — i.e. wired but not contacting any bureau.
	 *
	 * @return bool TRUE when the adapter is a log-only stub.
	 */
	public function isDormant(): bool;
}//end interface
