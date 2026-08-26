<?php

/**
 * Shillinq External Adapters Admin Controller.
 *
 * Read-only admin endpoint over the 15 external-API adapter families that
 * Shillinq ships as dormant log-only stubs (per
 * `Application::register()`). Surfaces, for each adapter family:
 *
 *  - the FQCN of the bound interface + log-only default class,
 *  - the dormancy flag (`isDormant()` on the bound implementation),
 *  - the OpenSpec change slug + REQ id the adapter belongs to,
 *  - the openconnector source slug operators must provision,
 *  - whether that source slug is actually provisioned in openconnector
 *    today (`resolveProvisioning()`, REQ-ICO-003), fail-soft to
 *    `unknown` on any lookup error,
 *  - the feature flag (config key) that flips behaviour,
 *  - the human-readable activation steps the operator must follow.
 *
 * Drives the single `ExternalAdaptersStatus.vue` roster page mounted
 * under the "External Connections" nav node — one row per family,
 * with the activation steps disclosed in place per row. There is no
 * per-family detail page/route any more
 * (openspec/changes/integration-config-to-openconnector, REQ-ICO-002):
 * integration configuration (credentials, endpoints, protocol
 * mapping) belongs to openconnector per ADR-067/ADR-091/ADR-022; this
 * app holds only the source-slug reference.
 *
 * Per ADR-005 / ADR-004 the endpoint is admin-gated via
 * `#[AuthorizedAdminSetting(Application::class)]` — only admins
 * authorised for the shillinq settings section can read the adapter
 * health roll-up. Reading dormancy is non-sensitive in itself (no
 * credentials, no PII), but the activation steps reveal the
 * configuration keys an operator must set, which is admin-only data.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-config-to-openconnector/specs/integration-config-to-openconnector/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\External\Bunq\BunqBankConnectorAdapterInterface;
use OCA\Shillinq\Service\External\Cbs\CbsBestandenAdapterInterface;
use OCA\Shillinq\Service\External\Cbs\CbsIv3AdapterInterface;
use OCA\Shillinq\Service\External\CcmRuleEngine\CcmRuleEngineAdapterInterface;
use OCA\Shillinq\Service\External\CsrdEsrsXbrl\CsrdEsrsXbrlAdapterInterface;
use OCA\Shillinq\Service\External\DepositPayment\DepositPaymentAdapterInterface;
use OCA\Shillinq\Service\External\Digipoort\DigipoortSbrAdapterInterface;
use OCA\Shillinq\Service\External\Ib47\Ib47AdapterInterface;
use OCA\Shillinq\Service\External\Kvk\KvkHandelsregisterAdapterInterface;
use OCA\Shillinq\Service\External\Mollie\MolliePaymentAdapterInterface;
use OCA\Shillinq\Service\External\RvO\RvOAanvraagAdapterInterface;
use OCA\Shillinq\Service\External\Salarisbureau\SalarisbureauAdapterInterface;
use OCA\Shillinq\Service\External\Sisa\BzkSisaUploadAdapterInterface;
use OCA\Shillinq\Service\External\TreasuryRate\TreasuryRateAdapterInterface;
use OCA\Shillinq\Service\External\Uwv\UwvLoonaangifteAdapterInterface;
use OCA\Shillinq\Support\FleetAppId;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only admin endpoint over the 15 external-API adapter families.
 *
 * @spec openspec/changes/integration-config-to-openconnector/specs/integration-config-to-openconnector/spec.md
 */
class ExternalAdaptersAdminController extends Controller {
	/**
	 * Adapter-family registry. Each entry describes one of the 15
	 * dormant external-API ports the app ships and the activation
	 * recipe an operator must follow to bind a real implementation.
	 *
	 * Shape (one entry per family, keyed by stable slug):
	 *  - id:           Stable URL/route slug (lowercase, dash-separated).
	 *  - title:        Human display name.
	 *  - category:     Family grouping (regulatory / bank / payment / registry / xbrl / payroll).
	 *  - interface:    Adapter port interface FQCN.
	 *  - logClass:     Default log-only implementation FQCN.
	 *  - specSlug:     OpenSpec change directory the adapter belongs to.
	 *  - requirements: REQ ids the adapter implements.
	 *  - configKeys:   App-config keys (oc_appconfig) operators must set.
	 *  - featureFlag:  Feature-flag slug that gates real binding (or null when n/a).
	 *  - sourceSlug:   openconnector Source slug operators must provision.
	 *  - description:  One-paragraph operator-facing summary.
	 *  - steps:        Ordered activation-step strings (1 entry per step).
	 *
	 * @var array<string,array{
	 *   id:string,
	 *   title:string,
	 *   category:string,
	 *   interface:class-string,
	 *   logClass:class-string,
	 *   specSlug:string,
	 *   requirements:list<string>,
	 *   configKeys:list<string>,
	 *   featureFlag:?string,
	 *   sourceSlug:string,
	 *   description:string,
	 *   steps:list<string>
	 * }>
	 */
	private const ADAPTERS = [
		'digipoort-sbr' => [
			'id' => 'digipoort-sbr',
			'title' => 'Digipoort / SBR',
			'category' => 'regulatory',
			'interface' => DigipoortSbrAdapterInterface::class,
			'logClass' => 'OCA\\Shillinq\\Service\\External\\Digipoort\\LogDigipoortSbrAdapter',
			'specSlug' => 'bookkeeping-vat-btw-filing',
			'requirements' => ['REQ-VAT-FILING-006', 'REQ-FS-008', 'REQ-SBR-002'],
			'configKeys' => ['digipoort.endpoint', 'digipoort.certificate.alias'],
			'featureFlag' => 'gov-sbr',
			'sourceSlug' => 'digipoort-sbr',
			'description' => 'Submits SBR/XBRL filings (BTW-aangifte, jaarrekening, CSRD pack) to the Belastingdienst Digipoort gateway. '
				. 'Dormant by default; returns SUBMISSION_DEFERRED so the filing lifecycle advances to `submitted` without contacting Digipoort.',
			'steps' => [
				'Provision a PKIoverheid services certificate and store the PFX alias in the Nextcloud cert store.',
				'Set app-config keys `digipoort.endpoint` and `digipoort.certificate.alias`.',
				'Create openconnector Source slug `digipoort-sbr` pointing at the Digipoort WUS endpoint.',
				'Override DigipoortSbrAdapterInterface in a downstream Application::register() to bind the openconnector-backed implementation.',
				'Toggle the `gov-sbr` feature flag to ON.',
			],
		],
		'salarisbureau' => [
			'id' => 'salarisbureau',
			'title' => 'Salarisbureau (payroll)',
			'category' => 'payroll',
			'interface' => SalarisbureauAdapterInterface::class,
			'logClass' => 'OCA\\Shillinq\\Service\\External\\Salarisbureau\\LogSalarisbureauAdapter',
			'specSlug' => 'bookkeeping-detachering-payroll-administratie',
			'requirements' => ['REQ-PAYROLL-004', 'REQ-DETACHERING-007'],
			'configKeys' => ['salarisbureau.endpoint', 'salarisbureau.api.key'],
			'featureFlag' => 'payroll-salarisbureau',
			'sourceSlug' => 'salarisbureau',
			'description' => 'Posts payroll-run results to the external payroll bureau (Nmbrs, Visma, Loket). '
				. 'Dormant by default; returns PAYROLL_DEFERRED so the LoonComponent lifecycle stays observable without contacting the bureau.',
			'steps' => [
				'Sign the data-processing agreement with the payroll bureau.',
				'Provision an API key with `payroll.write` scope.',
				'Set app-config keys `salarisbureau.endpoint` and `salarisbureau.api.key`.',
				'Create openconnector Source slug `salarisbureau`.',
				'Override SalarisbureauAdapterInterface in Application::register().',
				'Toggle the `payroll-salarisbureau` feature flag to ON.',
			],
		],
		'rvo' => [
			'id' => 'rvo',
			'title' => 'RvO (subsidy / WBSO)',
			'category' => 'regulatory',
			'interface' => RvOAanvraagAdapterInterface::class,
			'logClass' => 'OCA\\Shillinq\\Service\\External\\RvO\\LogRvOAanvraagAdapter',
			'specSlug' => 'bookkeeping-wbso-sno-administratie',
			'requirements' => ['REQ-WBSO-008', 'REQ-INVESTERINGSAFTREK-005'],
			'configKeys' => ['rvo.endpoint', 'rvo.client.id', 'rvo.client.secret'],
			'featureFlag' => 'gov-rvo',
			'sourceSlug' => 'rvo-aanvraag',
			'description' => 'Submits WBSO / SNO / investeringsaftrek aanvragen to the Rijksdienst voor Ondernemend Nederland portal. '
				. 'Dormant by default; returns REQUEST_DEFERRED so the Aanvraag register lifecycle stays observable.',
			'steps' => [
				'Register the tenant organisation with RvO and obtain client credentials.',
				'Set app-config keys `rvo.endpoint`, `rvo.client.id`, and `rvo.client.secret`.',
				'Create openconnector Source slug `rvo-aanvraag`.',
				'Override RvOAanvraagAdapterInterface in Application::register().',
				'Toggle the `gov-rvo` feature flag to ON.',
			],
		],
		'ib47' => [
			'id' => 'ib47',
			'title' => 'Belastingdienst IB47',
			'category' => 'regulatory',
			'interface' => Ib47AdapterInterface::class,
			'logClass' => 'OCA\\Shillinq\\Service\\External\\Ib47\\LogIb47Adapter',
			'specSlug' => 'bookkeeping-detachering-payroll-administratie',
			'requirements' => ['REQ-IB47-002'],
			'configKeys' => ['ib47.endpoint', 'ib47.certificate.alias'],
			'featureFlag' => 'gov-ib47',
			'sourceSlug' => 'ib47',
			'description' => 'Submits the annual IB47 opgaaf (uitbetalingen aan derden) to the Belastingdienst. '
				. 'Dormant by default; returns SUBMISSION_DEFERRED so the Ib47Submission lifecycle advances without contacting the Belastingdienst.',
			'steps' => [
				'Provision a PKIoverheid certificate authorised for IB47.',
				'Set app-config keys `ib47.endpoint` and `ib47.certificate.alias`.',
				'Create openconnector Source slug `ib47`.',
				'Override Ib47AdapterInterface in Application::register().',
				'Toggle the `gov-ib47` feature flag to ON.',
			],
		],
		'cbs-bestanden' => [
			'id' => 'cbs-bestanden',
			'title' => 'CBS Bestanden',
			'category' => 'regulatory',
			'interface' => CbsBestandenAdapterInterface::class,
			'logClass' => 'OCA\\Shillinq\\Service\\External\\Cbs\\LogCbsBestandenAdapter',
			'specSlug' => 'bookkeeping-cbs-bestanden-extended',
			'requirements' => ['REQ-CBS-003', 'REQ-CBS-005'],
			'configKeys' => ['cbs.bestanden.endpoint', 'cbs.bestanden.account'],
			'featureFlag' => 'gov-cbs',
			'sourceSlug' => 'cbs-bestanden',
			'description' => 'Uploads CBS statistical bestanden (productie, ICT, R&D, finance enquetes). '
				. 'Dormant by default; returns SUBMISSION_DEFERRED so submissions stay observable without contacting CBS.',
			'steps' => [
				'Request a CBS account and SFTP / API credentials.',
				'Set app-config keys `cbs.bestanden.endpoint` and `cbs.bestanden.account`.',
				'Create openconnector Source slug `cbs-bestanden`.',
				'Override CbsBestandenAdapterInterface in Application::register().',
				'Toggle the `gov-cbs` feature flag to ON.',
			],
		],
		'cbs-iv3' => [
			'id' => 'cbs-iv3',
			'title' => 'CBS Iv3',
			'category' => 'regulatory',
			'interface' => CbsIv3AdapterInterface::class,
			'logClass' => 'OCA\\Shillinq\\Service\\External\\Cbs\\LogCbsIv3Adapter',
			'specSlug' => 'bookkeeping-provincies-bbv-variant',
			'requirements' => ['REQ-IV3-002', 'REQ-IV3-005'],
			'configKeys' => ['cbs.iv3.endpoint', 'cbs.iv3.account'],
			'featureFlag' => 'gov-iv3',
			'sourceSlug' => 'cbs-iv3',
			'description' => 'Submits the quarterly Iv3 (Informatie voor derden) report for gemeenten / provincies / waterschappen / GRs to CBS. '
				. 'Dormant by default; returns SUBMISSION_DEFERRED.',
			'steps' => [
				'Request a CBS Iv3 account.',
				'Set app-config keys `cbs.iv3.endpoint` and `cbs.iv3.account`.',
				'Create openconnector Source slug `cbs-iv3`.',
				'Override CbsIv3AdapterInterface in Application::register().',
				'Toggle the `gov-iv3` feature flag to ON.',
			],
		],
		'bzk-sisa' => [
			'id' => 'bzk-sisa',
			'title' => 'BZK SiSa',
			'category' => 'regulatory',
			'interface' => BzkSisaUploadAdapterInterface::class,
			'logClass' => 'OCA\\Shillinq\\Service\\External\\Sisa\\LogBzkSisaUploadAdapter',
			'specSlug' => 'bookkeeping-sisa-reporting',
			'requirements' => ['REQ-SISA-004'],
			'configKeys' => ['sisa.endpoint', 'sisa.upload.token'],
			'featureFlag' => 'gov-sisa',
			'sourceSlug' => 'bzk-sisa',
			'description' => 'Uploads the SiSa-bijlage (Single information, single audit) to the BZK iv3-Plaza / SiSa portal. '
				. 'Dormant by default; returns SUBMISSION_DEFERRED.',
			'steps' => [
				'Obtain a BZK iv3-Plaza upload token for the tenant.',
				'Set app-config keys `sisa.endpoint` and `sisa.upload.token`.',
				'Create openconnector Source slug `bzk-sisa`.',
				'Override BzkSisaUploadAdapterInterface in Application::register().',
				'Toggle the `gov-sisa` feature flag to ON.',
			],
		],
		'mollie' => [
			'id' => 'mollie',
			'title' => 'Mollie Payments',
			'category' => 'payment',
			'interface' => MolliePaymentAdapterInterface::class,
			'logClass' => 'OCA\\Shillinq\\Service\\External\\Mollie\\LogMolliePaymentAdapter',
			'specSlug' => 'bookings-deposits',
			'requirements' => ['REQ-DP-001', 'REQ-DP-005'],
			'configKeys' => ['mollie.api.key', 'mollie.webhook.secret'],
			'featureFlag' => 'payments-mollie',
			'sourceSlug' => 'mollie-payments',
			'description' => 'Creates deposit-payment intents (iDEAL / Bancontact / SEPA). Inbound webhook callbacks are '
				. 'verified by the fail-closed HMAC gate on the webhook controllers (never this adapter). '
				. 'Dormant by default; returns PAYMENT_DEFERRED so DepositReconciliationService never advances the lifecycle '
				. 'without a live confirmation.',
			'steps' => [
				'Create a Mollie profile and obtain a live API key (test_ keys are accepted for staging).',
				'Set app-config keys `mollie.api.key` and `mollie.webhook.secret`.',
				'Create openconnector Source slug `mollie-payments`.',
				'Override MolliePaymentAdapterInterface in Application::register().',
				'Toggle the `payments-mollie` feature flag to ON.',
			],
		],
		'bunq' => [
			'id' => 'bunq',
			'title' => 'Bunq Bank Connector',
			'category' => 'bank',
			'interface' => BunqBankConnectorAdapterInterface::class,
			'logClass' => 'OCA\\Shillinq\\Service\\External\\Bunq\\LogBunqBankConnectorAdapter',
			'specSlug' => 'bookkeeping-bank-connectors',
			'requirements' => ['REQ-BC-003', 'REQ-BC-005'],
			'configKeys' => ['bunq.api.key', 'bunq.installation.context'],
			'featureFlag' => 'bank-bunq',
			'sourceSlug' => 'bunq-bank',
			'description' => 'Pulls CAMT.053 statements per BankConnection and handles SCA consent-renewal. '
				. 'Dormant by default; returns SYNC_DEFERRED so the BankConnection polling stays observable without contacting Bunq.',
			'steps' => [
				'Create a Bunq sandbox key (or production key with PSD2 licence).',
				'Set app-config keys `bunq.api.key` and `bunq.installation.context`.',
				'Create openconnector Source slug `bunq-bank`.',
				'Override BunqBankConnectorAdapterInterface in Application::register().',
				'Toggle the `bank-bunq` feature flag to ON.',
			],
		],
		'kvk' => [
			'id' => 'kvk',
			'title' => 'KvK Handelsregister',
			'category' => 'registry',
			'interface' => KvkHandelsregisterAdapterInterface::class,
			'logClass' => 'OCA\\Shillinq\\Service\\External\\Kvk\\LogKvkHandelsregisterAdapter',
			'specSlug' => 'bookkeeping-multi-administratie',
			'requirements' => ['REQ-MA-007'],
			'configKeys' => ['kvk.api.key'],
			'featureFlag' => 'registry-kvk',
			'sourceSlug' => 'kvk-handelsregister',
			'description' => 'Looks up KvK Handelsregister entries for debtor/creditor enrichment, multi-administratie onboarding, '
				. 'and deelnemingen-graaf walks. Dormant by default; returns LOOKUP_DEFERRED.',
			'steps' => [
				'Obtain a KvK API subscription key (Handelsregister Dataservice).',
				'Set app-config key `kvk.api.key`.',
				'Create openconnector Source slug `kvk-handelsregister`.',
				'Override KvkHandelsregisterAdapterInterface in Application::register().',
				'Toggle the `registry-kvk` feature flag to ON.',
			],
		],
		'uwv' => [
			'id' => 'uwv',
			'title' => 'UWV Loonaangifte',
			'category' => 'payroll',
			'interface' => UwvLoonaangifteAdapterInterface::class,
			'logClass' => 'OCA\\Shillinq\\Service\\External\\Uwv\\LogUwvLoonaangifteAdapter',
			'specSlug' => 'bookkeeping-payroll-engine-nl',
			'requirements' => ['REQ-PAYROLL-009'],
			'configKeys' => ['uwv.endpoint', 'uwv.certificate.alias'],
			'featureFlag' => 'gov-uwv',
			'sourceSlug' => 'uwv-loonaangifte',
			'description' => 'Pulls LH-afdracht acceptance status + werkgever-setup sectorindeling validation from UWV. '
				. 'Dormant by default; returns STATUS_DEFERRED so the payroll lifecycle stays observable.',
			'steps' => [
				'Provision a PKIoverheid certificate authorised for UWV.',
				'Set app-config keys `uwv.endpoint` and `uwv.certificate.alias`.',
				'Create openconnector Source slug `uwv-loonaangifte`.',
				'Override UwvLoonaangifteAdapterInterface in Application::register().',
				'Toggle the `gov-uwv` feature flag to ON.',
			],
		],
		'treasury-rates' => [
			'id' => 'treasury-rates',
			'title' => 'Treasury Rates (ECB / SDMX)',
			'category' => 'bank',
			'interface' => TreasuryRateAdapterInterface::class,
			'logClass' => 'OCA\\Shillinq\\Service\\External\\TreasuryRate\\LogTreasuryRateAdapter',
			'specSlug' => 'bookkeeping-treasury-ihb',
			'requirements' => ['REQ-IHB-003', 'REQ-IHB-004', 'REQ-IHB-008'],
			'configKeys' => ['treasury.rates.provider'],
			'featureFlag' => 'treasury-rates',
			'sourceSlug' => 'treasury-rates',
			'description' => 'Fetches daily reference rates (EURIBOR-3M / SOFR / SARON / ESTR) + FX spots from ECB SDMX (or Bloomberg / Refinitiv). '
				. 'Dormant by default; returns SNAPSHOT_DEFERRED so the interest-accrual + FX-revaluation aggregations stay observable. '
				. 'Manual IntercompanyLoan.interestRate remains the v1 fallback per REQ-IHB-004.',
			'steps' => [
				'Pick a reference-rate provider (ECB SDMX is free; Bloomberg / Refinitiv require a market-data subscription).',
				'Set app-config key `treasury.rates.provider` to the chosen vendor slug.',
				'Create openconnector Source slug `treasury-rates`.',
				'Override TreasuryRateAdapterInterface in Application::register().',
				'Toggle the `treasury-rates` feature flag to ON.',
			],
		],
		'ccm-rule-engine' => [
			'id' => 'ccm-rule-engine',
			'title' => 'CCM Rule Engine',
			'category' => 'regulatory',
			'interface' => CcmRuleEngineAdapterInterface::class,
			'logClass' => 'OCA\\Shillinq\\Service\\External\\CcmRuleEngine\\LogCcmRuleEngineAdapter',
			'specSlug' => 'bookkeeping-ccm-rule-engine',
			'requirements' => ['REQ-CCM-002'],
			'configKeys' => ['ccm.rule.engine.provider'],
			'featureFlag' => 'ccm-external-engine',
			'sourceSlug' => 'ccm-rule-engine',
			'description' => 'Optional swap-out seam delegating CCM v1 sync/async DSL evaluation to the future OpenRegister native rule engine '
				. 'or a third-party evaluator. '
				. 'Dormant by default; returns DEFERRED + fired=false (fail-soft) so binding never raises a false finding.',
			'steps' => [
				'Pick a target rule engine (OpenRegister native, Drools, OPA, etc.).',
				'Set app-config key `ccm.rule.engine.provider`.',
				'Create openconnector Source slug `ccm-rule-engine`.',
				'Override CcmRuleEngineAdapterInterface in Application::register().',
				'Toggle the `ccm-external-engine` feature flag to ON (leaving it OFF keeps the in-process v1 evaluator).',
			],
		],
		'csrd-esrs-xbrl' => [
			'id' => 'csrd-esrs-xbrl',
			'title' => 'CSRD / ESRS XBRL',
			'category' => 'xbrl',
			'interface' => CsrdEsrsXbrlAdapterInterface::class,
			'logClass' => 'OCA\\Shillinq\\Service\\External\\CsrdEsrsXbrl\\LogCsrdEsrsXbrlAdapter',
			'specSlug' => 'bookkeeping-csrd-esrs',
			'requirements' => ['REQ-CSR-006', 'REQ-CSR-008'],
			'configKeys' => ['csrd.xbrl.taxonomy.version'],
			'featureFlag' => 'csrd-xbrl',
			'sourceSlug' => 'csrd-esrs-xbrl',
			'description' => 'EFRAG ESRS XBRL taxonomy mapping + mandatory-data-point validation + iXBRL instance build. '
				. 'SAFETY-CRITICAL: validateMandatoryDataPoints() always returns VALIDATION_BLOCKED in the dormant default '
				. 'so an unvalidated CSRD report cannot slip past EFRAG IG-3.',
			'steps' => [
				'Pick an EFRAG taxonomy version (2024-01 baseline at v1).',
				'Set app-config key `csrd.xbrl.taxonomy.version`.',
				'Create openconnector Source slug `csrd-esrs-xbrl`.',
				'Override CsrdEsrsXbrlAdapterInterface in Application::register().',
				'Toggle the `csrd-xbrl` feature flag to ON.',
				'Smoke-test against a sandbox iXBRL instance before submitting to KvK / AFM via the DigipoortSbrAdapter '
					. '(filingType: csrd-xbrl-pack).',
			],
		],
		'deposit-payment' => [
			'id' => 'deposit-payment',
			'title' => 'Deposit Payment lifecycle',
			'category' => 'payment',
			'interface' => DepositPaymentAdapterInterface::class,
			'logClass' => 'OCA\\Shillinq\\Service\\External\\DepositPayment\\LogDepositPaymentAdapter',
			'specSlug' => 'bookings-deposits',
			'requirements' => ['REQ-DP-001', 'REQ-DP-005', 'REQ-DP-007', 'REQ-DP-008'],
			'configKeys' => ['deposit.payment.provider'],
			'featureFlag' => 'payments-deposit',
			'sourceSlug' => 'deposit-payment',
			'description' => 'Lifecycle adapter sitting ONE LAYER ABOVE the Mollie adapter: the lifecycle code never sees '
				. 'a Mollie vs. Stripe branch, only the projected DepositPayment state. '
				. 'Dormant default returns pending / PAYMENT_DEFERRED with dormant=true so '
				. 'DepositReconciliationService::pollPending() must inspect the dormant flag before advancing.',
			'steps' => [
				'Bind the Mollie adapter first (see the mollie family entry).',
				'Set app-config key `deposit.payment.provider`.',
				'Create openconnector Source slug `deposit-payment` (delegates to mollie-payments under the hood).',
				'Override DepositPaymentAdapterInterface in Application::register() to bind MollieDepositPaymentAdapter (or another vendor mapper).',
				'Toggle the `payments-deposit` feature flag to ON.',
			],
		],
	];

	/**
	 * Construct the External Adapters admin controller.
	 *
	 * @param IRequest $request Nextcloud request.
	 * @param ContainerInterface $container DI container for adapter resolution.
	 * @param LoggerInterface $logger Structured logger.
	 * @param IAppManager $appManager App manager, used to resolve the integriq
	 *                                app id across the fleet rename.
	 */
	public function __construct(
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly IAppManager $appManager,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List every external adapter family with live dormancy +
	 * provisioning state.
	 *
	 * Shape:
	 *  - adapters: list of family entries, each carrying every static
	 *    field from the ADAPTERS registry plus a live `dormant: bool`
	 *    flag resolved via the DI container, and a `provisioning: {
	 *    status, openconnectorObjectId?, deepLink }` block resolved
	 *    per REQ-ICO-003.
	 *  - summary: { total: int, dormant: int, live: int } roll-up.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/integration-config-to-openconnector/specs/integration-config-to-openconnector/spec.md
	 */
	#[AuthorizedAdminSetting(Application::class)]
	public function index(): JSONResponse {
		$entries = [];
		$dormantCount = 0;

		foreach (self::ADAPTERS as $entry) {
			$dormant = $this->resolveDormancy(interfaceFqcn: $entry['interface']);
			$provisioning = $this->resolveProvisioning(sourceSlug: $entry['sourceSlug']);
			$entries[] = ($entry + ['dormant' => $dormant, 'provisioning' => $provisioning]);
			if ($dormant === true) {
				$dormantCount++;
			}
		}

		$total = count($entries);

		return new JSONResponse(
			[
				'adapters' => $entries,
				'summary' => [
					'total' => $total,
					'dormant' => $dormantCount,
					'live' => ($total - $dormantCount),
				],
			]
		);
	}//end index()

	/**
	 * Resolve the bound adapter implementation and call `isDormant()`.
	 *
	 * Defensive: any DI / Throwable returns `false` (the safe assumption
	 * for "we don't know" is to display the adapter as LIVE so an
	 * operator never silently relies on a dormant-default for a port
	 * that is actually mis-wired). The header strip on the Vue side
	 * separately surfaces an error message when the index call fails.
	 *
	 * @param class-string $interfaceFqcn Adapter port FQCN.
	 *
	 * @return bool TRUE when the bound implementation reports dormant.
	 */
	private function resolveDormancy(string $interfaceFqcn): bool {
		try {
			$impl = $this->container->get($interfaceFqcn);
			if (method_exists($impl, 'isDormant') === true) {
				return (bool)$impl->isDormant();
			}

			return false;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Shillinq ExternalAdaptersAdminController: dormancy lookup threw',
				['interface' => $interfaceFqcn, 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end resolveDormancy()

	/**
	 * Resolve whether a family's declared openconnector source slug is
	 * actually provisioned, per REQ-ICO-003.
	 *
	 * Read-only, existence-only: queries OpenRegister's generic object
	 * API (via DI, the sanctioned ADR-022 abstraction — see
	 * openspec/changes/integration-config-to-openconnector/design.md §4)
	 * for `register: 'openconnector', schema: 'source'` filtered by
	 * `slug`. Never reads or returns `configuration.headers`/credentials
	 * from the resolved object — only its id.
	 *
	 * Defensive, mirroring {@see resolveDormancy()}'s shape exactly:
	 * any Throwable (OpenRegister unavailable, openconnector not
	 * installed, DI resolution failure) is caught, logged at
	 * `warning`, and resolves to `unknown` rather than failing the
	 * whole `#index` response.
	 *
	 * @param string $sourceSlug The family's declared openconnector
	 *                           source slug.
	 *
	 * @return array{status:string,openconnectorObjectId?:string,deepLink:string}
	 */
	private function resolveProvisioning(string $sourceSlug): array {
		// The deep link is a ROUTING KEY: NC mounts routes under the registered
		// app id, which is `integriq` on development and `openconnector` on
		// beta/main. Resolve it rather than hardcoding either, or half the fleet
		// gets a 404 from this link.
		//
		// NOTE: the register slug passed to setRegister() below stays
		// 'openconnector' deliberately. OpenRegister register slugs are frozen
		// across the rename — they are literals already written into stored
		// data, so changing one orphans the records it names.
		$deepLink = (FleetAppId::appPath($this->appManager, 'integriq', 'sources') ?? '/apps/openconnector/sources');

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');

			$records = $objectService
				->setRegister('openconnector')
				->setSchema('source')
				->findAll(['filters' => ['slug' => $sourceSlug], 'limit' => 1]);

			foreach ($records as $record) {
				$objectId = null;
				if (is_object($record) === true && method_exists($record, 'getId') === true) {
					$objectId = $record->getId();
				} elseif (is_array($record) === true && isset($record['id']) === true) {
					$objectId = $record['id'];
				}

				return [
					'status' => 'provisioned',
					'openconnectorObjectId' => $objectId,
					'deepLink' => $deepLink,
				];
			}

			return [
				'status' => 'declared-not-provisioned',
				'deepLink' => $deepLink,
			];
		} catch (Throwable $e) {
			$this->logger->warning(
				'Shillinq ExternalAdaptersAdminController: provisioning lookup threw',
				['sourceSlug' => $sourceSlug, 'exception' => $e->getMessage()]
			);
			return [
				'status' => 'unknown',
				'deepLink' => $deepLink,
			];
		}
	}//end resolveProvisioning()
}//end class
