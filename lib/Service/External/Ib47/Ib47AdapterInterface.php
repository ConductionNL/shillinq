<?php

/**
 * Belastingdienst IB47 (opgaaf van uitbetaalde bedragen aan
 * derden) submission port.
 *
 * IB47 is the Belastingdienst's annual reporting obligation for
 * payments made to non-employed third parties (freelance
 * speakers, jury members, volunteers' onkostenvergoeding above
 * threshold, royalties etc.). Since 2026 it also subsumes the
 * post-IB47 / "uitbetalingen aan derden" UBD stream that used to
 * run through the Renseigneringsplicht webform. Shillinq
 * materialises the per-recipient row stream from the
 * `Ib47Recipient` / `Ib47Statement` projections declared by the
 * `bookkeeping-detachering-payroll-administratie` capability —
 * and, for BTW-OSS distance-sales side-payments to
 * EU-resident contributors, also from the
 * `bookkeeping-btw-oss-eu` aggregations — and hands the prepared
 * payload to this adapter for transport to the Belastingdienst
 * Gegevensportaal.
 *
 * The port is intentionally narrow — one submit call returning a
 * dispatch outcome — so the production binding (PKIoverheid
 * Services-server cert + Belastingdienst Gegevensportaal /
 * Digipoort Renseigneringsstroom) can be swapped in via
 * `Application::register()` without touching the orchestrator.
 * Until an openconnector-backed binding to source slug
 * `belastingdienst-ib47` is configured, the default binding is
 * dormant: it logs the intent without contacting the
 * Belastingdienst so the lifecycle stays observable in test +
 * staging environments.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\Ib47
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 * @link https://www.belastingdienst.nl/wps/wcm/connect/bldcontentnl/themaoverstijgend/programmas_en_formulieren/opgaaf_uitbetaalde_bedragen_aan_derden
 *
 * @spec openspec/specs/bookkeeping-detachering-payroll-administratie/spec.md
 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\Ib47;

/**
 * Belastingdienst IB47 submission port.
 *
 * Implementations MUST be side-effect-free when the dormant flag is set;
 * a dormant adapter records the intent (logger, audit trail) and returns
 * a synthetic DEFERRED outcome so the surrounding lifecycle can advance
 * into `submitted-to-belastingdienst` without contacting the
 * Belastingdienst.
 *
 * Activation steps for a real IB47 binding:
 *  1. Provision a PKIoverheid Services-server certificate
 *     (RSA 4096 + OIN) and load it into the openconnector credential
 *     store.
 *  2. Create an openconnector source with slug
 *     `belastingdienst-ib47`, pointing at the Belastingdienst
 *     Gegevensportaal IB47 endpoint
 *     (or the Renseigneringsstroom via Digipoort if the
 *     intermediair route is in scope).
 *  3. Override the Ib47AdapterInterface DI binding in
 *     `Application::register()` to the openconnector-backed
 *     implementation.
 *
 * @spec openspec/specs/bookkeeping-detachering-payroll-administratie/spec.md
 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
 */
interface Ib47AdapterInterface {
	/**
	 * Submit an IB47 / UBD opgaaf to the Belastingdienst.
	 *
	 * @param array<string,mixed> $payload The IB47 submission envelope —
	 *                                     reportingYear, organizationLegalName,
	 *                                     kvkNumber, fiscalNumber,
	 *                                     beconNumber (optional intermediair),
	 *                                     recipients[] (BSN OR rsin, name,
	 *                                     addressLine, postalCode, city,
	 *                                     country, birthDate (natural persons),
	 *                                     paidAmount, paymentDate, natureCode),
	 *                                     totalRecipients,
	 *                                     totalPaidAmount,
	 *                                     correlationId.
	 *
	 * @return Ib47SubmissionResult The dispatch outcome (status + kenmerk).
	 */
	public function submit(array $payload): Ib47SubmissionResult;

	/**
	 * Whether the adapter is dormant — i.e. wired but not contacting
	 * the Belastingdienst.
	 *
	 * @return bool TRUE when the adapter is a log-only stub.
	 */
	public function isDormant(): bool;
}//end interface
