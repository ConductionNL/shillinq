<?php

/**
 * Shillinq route registrations.
 *
 * @category AppInfo
 * @package  OCA\Shillinq\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

return [
    'routes' => [
        // Dashboard + Settings.
        ['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
        ['name' => 'settings#load',  'url' => '/api/settings/load', 'verb' => 'POST'],

        // Generic per-user preferences (used by shared nextcloud-vue widgets, e.g. CnSupportDialog).
        ['name' => 'preferences#getPreference', 'url' => '/api/preferences/{key}', 'verb' => 'GET'],
        ['name' => 'preferences#setPreference', 'url' => '/api/preferences/{key}', 'verb' => 'PUT'],

        // Prometheus metrics endpoint.
        ['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
        // Health check endpoint.
        ['name' => 'health#index', 'url' => '/api/health', 'verb' => 'GET'],

        // Trial balance (Tier 2): read-only per-account period aggregation.
        ['name' => 'trialBalance#index', 'url' => '/api/trial-balance', 'verb' => 'GET'],

        // BADO audit aggregation — per-topic finding roll-up + proposed opinion (REQ-006, REQ-007).
        ['name' => 'badoControleprotocol#aggregation', 'url' => '/api/bado/aggregation', 'verb' => 'GET'],

        // OSS (One-Stop-Shop, Tier 2): destination-country rate resolution + quarterly return generation.
        ['name' => 'oss#resolveRate', 'url' => '/api/oss/rate', 'verb' => 'GET'],
        ['name' => 'oss#generateReturn', 'url' => '/api/oss/return', 'verb' => 'GET'],

        // Multi-administratie (multi-tenant) context, switcher and per-administration export scope.
        // Static segments precede the {id} wildcard so they are matched first.
        ['name' => 'administration#context', 'url' => '/api/administrations/context', 'verb' => 'GET'],
        ['name' => 'administration#switch', 'url' => '/api/administrations/switch', 'verb' => 'POST'],
        ['name' => 'administration#exportScope', 'url' => '/api/administrations/{id}/export-scope', 'verb' => 'GET'],

        // Payroll engine (NL loonadministratie): read-only compute endpoints.
        ['name' => 'payroll#loonstrook', 'url' => '/api/payroll/loonstrook', 'verb' => 'GET'],
        ['name' => 'payroll#lhAfdracht', 'url' => '/api/payroll/lh-afdracht', 'verb' => 'GET'],
        ['name' => 'payroll#journaalpost', 'url' => '/api/payroll/journaalpost', 'verb' => 'GET'],

        // BCF claims (Tier 3): read-only compensable-VAT breakdown for a quarter.
        ['name' => 'bcfClaim#compensation', 'url' => '/api/bcf-claims/compensation', 'verb' => 'GET'],

        // KOR (Tier 2): read-only drempel-bewaking (running omzet, benutting, prognose, alert-schijf).
        ['name' => 'kor#monitor', 'url' => '/api/kor/monitor', 'verb' => 'GET'],

        // Vpb corporate tax (Tier 2): read-only quarterly/annual tax statements
        // and payment reconciliation. Deadline/payment CRUD is served by
        // OpenRegister's generic object API. Specific verb routes precede the
        // catch-all wildcard below.
        ['name' => 'taxReport#quarter', 'url' => '/api/tax-reports/{year}/{quarter}', 'verb' => 'GET'],
        ['name' => 'taxReport#annual', 'url' => '/api/tax-reports/{year}', 'verb' => 'GET'],
        ['name' => 'taxPayment#reconcile', 'url' => '/api/tax-payments/{id}/reconcile', 'verb' => 'POST'],

        // Deposit payment webhook (bookings-deposits). Public but signature-verified
        // (ADR-005): Mollie / Stripe async confirmation routed via OpenConnector.
        // Static/verb route declared before the SPA catch-all (ADR-016).
        ['name' => 'depositWebhook#handle', 'url' => '/api/deposits/webhook/{gateway}', 'verb' => 'POST'],

        // Innovatiebox administratie (Tier 4): read-only Vpb roll-up + nexus scenario + doorsnijdingsverbod.
        ['name' => 'innovatiebox#aggregation', 'url' => '/api/innovatiebox/aggregation', 'verb' => 'GET'],
        ['name' => 'innovatiebox#scenario', 'url' => '/api/innovatiebox/scenario', 'verb' => 'GET'],
        ['name' => 'innovatiebox#doorsnijdingsverbod', 'url' => '/api/innovatiebox/doorsnijdingsverbod', 'verb' => 'GET'],

        // IFRS 15 revenue cut-off (Tier 2): read-only per-contract asset/liability + waterfall.
        ['name' => 'revenue#cutoff', 'url' => '/api/revenue-cutoff', 'verb' => 'GET'],

        // SPA catch-all — same controller as the index route; must use a distinct route name
        // (duplicate names replace the earlier route in Symfony, which breaks GET /).
        ['name' => 'dashboard#catchAll', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
