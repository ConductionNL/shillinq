<?php

/**
 * Bunq Bank Connector adapter port.
 *
 * Bunq is a Dutch challenger bank with a first-class public API
 * (sandbox + production). Within shillinq it is one of the
 * PSD2-aggregator-bypass options the
 * `bookkeeping-bank-connectors` spec lists alongside Tink, Klarna
 * Kosma, Plaid EU, Yapily, and manual CAMT.053 — Bunq's own API
 * is preferred over a generic aggregator when the customer
 * already banks with Bunq because:
 *  - the SCA consent expiry is 365 days (vs the PSD2 default 90)
 *  - the per-monetary-account stream lands as proper CAMT.053
 *    natively (no per-aggregator JSON normalisation pipeline)
 *  - the per-transaction `description` is the rich Bunq social
 *    annotation, useful for the bank-reconciliation matcher.
 *
 * The port is intentionally narrow — `pullTransactions()` +
 * `renewConsent()` returning structured results — so the
 * production binding (openconnector source slug
 * `bunq-bank-connector`, per-tenant API key + installation token
 * + device-server registration) can be swapped in via
 * `Application::register()` without touching the Bank Connector
 * orchestrator or the consent-renewal flow.
 *
 * Until that binding is configured, the default binding is
 * dormant: it logs the intent and returns a synthetic
 * SYNC_DEFERRED outcome so the surrounding lifecycle stays
 * observable in test + staging environments. Per the
 * `bookkeeping-bank-connectors` D1 decision NO credentials live
 * in shillinq — the adapter only ever sees a non-credential
 * `connectionReference` that maps back to the openconnector
 * Source row.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\Bunq
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 * @link https://doc.bunq.com/
 *
 * @spec openspec/specs/bookkeeping-bank-connectors/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\Bunq;

/**
 * Bunq Bank Connector adapter port.
 *
 * Implementations MUST be side-effect-free when the dormant flag is set;
 * a dormant adapter records the intent (logger, audit trail) and
 * returns a synthetic SYNC_DEFERRED outcome so the surrounding
 * lifecycle can advance into `pending-bunq-sync` without contacting
 * Bunq.
 *
 * Activation steps for a real Bunq binding:
 *  1. Provision a Bunq API key (production tier) via the tenant's
 *     Bunq business account; run the one-off installation +
 *     device-server registration so the tenant has an
 *     installation token + signed-by-Bunq client cert.
 *  2. Create an openconnector source with slug
 *     `bunq-bank-connector`, pointing at the Bunq API public
 *     endpoint (`api.bunq.com/v1`) and storing the API key,
 *     installation token + device-server identifier — none of
 *     which ever leave openconnector.
 *  3. Override the BunqBankConnectorAdapterInterface DI binding
 *     in `Application::register()` to the
 *     openconnector-backed implementation.
 *
 * @spec openspec/specs/bookkeeping-bank-connectors/spec.md
 */
interface BunqBankConnectorAdapterInterface {
	/**
	 * Pull Bunq monetary-account transactions for a connection.
	 *
	 * @param string $connectionReference Non-credential
	 *                                    BankConnection id
	 *                                    — maps to an
	 *                                    openconnector
	 *                                    Source row.
	 * @param array<string,mixed> $context Optional context —
	 *                                     sinceTransactionId,
	 *                                     maxTransactions,
	 *                                     administrationId,
	 *                                     correlationId.
	 *
	 * @return BunqSyncResult The dispatch outcome (status +
	 *                        transaction count + native CAMT.053 URI).
	 */
	public function pullTransactions(string $connectionReference, array $context = []): BunqSyncResult;

	/**
	 * Renew the SCA consent for a Bunq connection by handing off
	 * to the openconnector SCA endpoint.
	 *
	 * @param string $connectionReference Non-credential
	 *                                    BankConnection id.
	 * @param array<string,mixed> $context Optional context —
	 *                                     administrationId,
	 *                                     redirectAfterScaUrl.
	 *
	 * @return BunqSyncResult The dispatch outcome — `scaUrl` extra
	 *                        carries the URL the operator should
	 *                        follow to complete SCA in the Bunq
	 *                        app / Bunq web UI.
	 */
	public function renewConsent(string $connectionReference, array $context = []): BunqSyncResult;

	/**
	 * Whether the adapter is dormant — i.e. wired but not contacting
	 * Bunq.
	 *
	 * @return bool TRUE when the adapter is a log-only stub.
	 */
	public function isDormant(): bool;
}//end interface
