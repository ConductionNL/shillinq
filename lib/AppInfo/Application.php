<?php

/**
 * Shillinq Application
 *
 * Main application class for the Shillinq Nextcloud app.
 *
 * @category AppInfo
 * @package  OCA\Shillinq\AppInfo
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\AppInfo;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Middleware\ApiContractMiddleware;
use OCA\Shillinq\Notification\DeadlineReminderNotifier;
use OCA\Shillinq\Notification\EInvoiceNotifier;
use OCA\Shillinq\Notification\PosStockUnmatchedLineNotifier;
use OCA\Shillinq\Notification\RoleFallbackResolver;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\Util;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\Shillinq\Guard\GuardRegistrar;
use OCA\Shillinq\Lifecycle\LedgerPostingRegistration;
use OCA\Shillinq\Lifecycle\LifecycleRegistrar;
use OCA\Shillinq\Repair\RepairRegistrar;
use OCA\Shillinq\Service\ServiceRegistrar;
use OCA\Shillinq\Listener\Registration\ListenerRegistrar;

/**
 * Main application class for the Shillinq Nextcloud app.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * Pre-existing debt (issue #506): register() wires every service/listener/
 * guard the app defines, so its length and the class's coupling count scale
 * with the app's feature surface; splitting is out of scope for a mechanical
 * phpcs/phpmd cleanup. Deferred to a follow-up.
 */
class Application extends App implements IBootstrap {
	public const APP_ID = AppIdentity::APP_ID;

	/**
	 * Constructor for the Application class.
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct(appName: self::APP_ID);
	}//end __construct()

	/**
	 * Register event listeners and services.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function register(IRegistrationContext $context): void {
		// The API contract (version header, deprecation of unversioned paths,
		// problem details). Registered first: Nextcloud runs afterController in
		// reverse order, so it sees the final response of every other middleware.
		$context->registerMiddleware(class: ApiContractMiddleware::class);

		$this->registerOpenRegisterContracts(context: $context);
		// OpenRegister AppHost adoption (adopt-apphost) — the mechanical
		// controllers/settings that are byte-for-byte the fleet skeleton are
		// re-pointed at the engine's generics, and observability is served by
		// the engine from the `observability` block in src/manifest.json.
		//
		// Every alias below is registered through a closure that references the
		// OCA\OpenRegister\AppHost\… classes only by STRING (no top-level use /
		// ::class), so a disabled OpenRegister never fatals NC bootstrap — the
		// closure only runs when a route is dispatched, and a missing generic
		// surfaces as a 5xx (the correct DEGRADED behaviour; health then
		// reports orAvailable: failed).
		//
		// Bespoke plumbing that is NOT mechanical is deliberately KEPT
		// (SettingsController/SettingsService — fragment-merge config loading;
		// PreferencesController — no generic ships yet; InitializeSettings —
		// 13-phase domain seeding; DeepLinkRegistrationListener — dynamic
		// register-slug resolution). Bootstrap::register() is intentionally not
		// used: it would alias those onto generics that do not match shillinq's
		// behaviour. See openspec/changes/adopt-apphost.
		$this->registerGenericControllers(context: $context);
		(new AppHostRegistration())->register(context: $context);

		(new ListenerRegistrar())->register(context: $context);

		(new RepairRegistrar())->register(context: $context);

		(new ServiceRegistrar())->register(context: $context);

		// Migrate-legacy-notification-dialect (task 1.3) — register one
		// RoleFallbackResolver instance per (primary role, fallback role)
		// pair used by the canonical `recipients: [{kind: "expression",
		// resolver: "..."}]` rules migrated off the legacy singular
		// `"recipient": {resolver, fallback}` shape. Each alias is a DI
		// service id (not a literal FQCN::method call — the OR dispatcher
		// resolves it via IServerContainer::get($resolverTag)), resolved by
		// Nextcloud's container the same way any other registered service
		// is. Group ids follow the `shillinq_<role>` convention established
		// by WbsoRbacResolver::GROUP_TO_ROLE.
		$context->registerService(
			RoleFallbackResolver::class . '::financeOfficer',
			static function ($c): RoleFallbackResolver {
				return new RoleFallbackResolver(
					groupManager: $c->get(IGroupManager::class),
					logger: $c->get(LoggerInterface::class),
					primaryGroup: 'shillinq_finance_officer',
					fallbackGroup: 'shillinq_subsidie_coordinator',
				);
			}
		);
		$context->registerService(
			RoleFallbackResolver::class . '::subsidieCoordinator',
			static function ($c): RoleFallbackResolver {
				return new RoleFallbackResolver(
					groupManager: $c->get(IGroupManager::class),
					logger: $c->get(LoggerInterface::class),
					primaryGroup: 'shillinq_subsidie_coordinator',
					fallbackGroup: 'shillinq_administration_treasurer',
				);
			}
		);
		$context->registerService(
			RoleFallbackResolver::class . '::payrollOfficer',
			static function ($c): RoleFallbackResolver {
				return new RoleFallbackResolver(
					groupManager: $c->get(IGroupManager::class),
					logger: $c->get(LoggerInterface::class),
					primaryGroup: 'shillinq_payroll_officer',
					fallbackGroup: 'shillinq_administration_treasurer',
				);
			}
		);
		// #516/#1103: the ledger posting guards; then the reporting listeners.
		(new LedgerPostingRegistration())->register(context: $context);

		// Compliance-deadline-calendar REQ-CDC-007 — render the
		// `deadline_reminder` notifications raised by
		// ComplianceDeadlineCalendarService / DeadlineReminderJob in the
		// notification centre. Without a registered INotifier the raised
		// notifications would be discarded at display time.
		$context->registerNotifierService(DeadlineReminderNotifier::class);

		// REQ-EINV-005 (#1111) — render the rejection notice
		// PeppolDeliveryStatusListener raises when the Peppol network refuses an
		// e-invoice. Without a registered INotifier it is discarded at display time.
		$context->registerNotifierService(EInvoiceNotifier::class);

		// Change inventory-pos-decrement (shillinq#504) — render the
		// `pos_stock_unmatched_line` notifications PosStockDecrementListener
		// raises for a POS-sale line that could not be matched to a shillinq
		// inventory item. Without a registered INotifier those notifications
		// would be discarded at display time — the reconciliation/audit
		// surface would be silently unreachable.
		$context->registerNotifierService(PosStockUnmatchedLineNotifier::class);

		(new GuardRegistrar())->register(context: $context);

		(new LifecycleRegistrar())->register(context: $context);

		// Budget-scenarios REQ-BSC-004/REQ-BSC-006 — the BudgetScenarioModifier
		// guard alias and the KnownCostScheduleExpanderInterface binding,
		// registered as one unit. See BudgetScenarioRegistration's own
		// docblock for why this lives in its own class.
		(new BudgetScenarioRegistration())->register(context: $context);

	}//end register()

	/**
	 * Bind the OpenRegister interfaces this app's services type-hint to their implementations.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerOpenRegisterContracts(IRegistrationContext $context): void {
		// ADR-084: services type-hint OpenRegister's PUBLISHED interface, never its
		// concrete class, so this app's unit tests can mock a type they are able to
		// load. Nextcloud autowires concrete classes across apps but not interfaces,
		// so the binding has to be stated — and the composition root is where this
		// app says how it is wired.
		//
		// An ALIAS, not a factory: it resolves when something actually asks for the
		// interface, so an instance without OpenRegister fails at the route that
		// needed the data rather than at registration. Both names are strings and
		// neither triggers an autoload, which is what keeps ADR-083 rule 3's promise
		// that the start screen still boots.
		$context->registerServiceAlias(
			ObjectServiceInterface::class,
			'OCA\OpenRegister\Service\ObjectService'
		);

		// The register-slug resolver, bound the same way and for the same reason.
		//
		// Register slugs live in `openregister_registers`, and nine fleet apps ship
		// a repair step that renames theirs. The step is per instance, so both
		// slugs are live across the estate at once and a literal is wrong on half
		// of it. The old-slug case is the quiet one: OpenRegister finds no
		// register, matches no rows, and returns an empty set that is byte for byte
		// what a healthy empty register returns. No exception, no 404, no log line.
		// This app once read Integriq's connector register that way, from the
		// external-adapters roster that adopt-connection-registry removed.
		//
		// Verified against this container, not assumed: OpenRegister registers the
		// resolver in its OWN container, so nothing of that registration reaches
		// here. What reaches here is the alias stated here plus autowiring of the
		// concrete class, whose only dependencies are `RegisterMapper` and
		// `LoggerInterface`. Both resolve from a leaf app's DIContainer, and the
		// interface then answers with a live resolution. Resolved live in this
		// app's own DIContainer on the running instance before this was written.
		//
		// The one thing lost is OpenRegister's shared-instance registration: a leaf
		// container autowires a fresh resolver per injection point, so the
		// request-scoped memo is per consumer rather than per request. That costs
		// one indexed read per consumer and changes no answer.
		$context->registerServiceAlias(
			RegisterSlugResolverInterface::class,
			'OCA\OpenRegister\Service\RegisterSlugResolver'
		);
	}//end registerOpenRegisterContracts()

	/**
	 * Point the dashboard, health and metrics controllers at the AppHost generics.
	 *
	 * These stay in the composition root: their factories look OpenRegister
	 * classes up by name, which only the composition root may do unguarded
	 * (ADR-083).
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private function registerGenericControllers(IRegistrationContext $context): void {
		$appId = self::APP_ID;

		$context->registerService(
			'OCA\\Shillinq\\Controller\\DashboardController',
			static function (ContainerInterface $c) use ($appId) {
				$class = 'OCA\\OpenRegister\\AppHost\\Controller\\GenericDashboardController';
				return new $class(
					appName: $appId,
					request: $c->get('OCP\\IRequest')
				);
			}
		);

		$context->registerService(
			'OCA\\Shillinq\\Controller\\HealthController',
			static function (ContainerInterface $c) use ($appId) {
				$class = 'OCA\\OpenRegister\\AppHost\\Controller\\GenericHealthController';
				return new $class(
					appName: $appId,
					request: $c->get('OCP\\IRequest'),
					manifestLoader: $c->get('OCA\\OpenRegister\\AppHost\\Observability\\ManifestLoader'),
					executor: $c->get('OCA\\OpenRegister\\AppHost\\Observability\\HealthCheckExecutor')
				);
			}
		);

		$context->registerService(
			'OCA\\Shillinq\\Controller\\MetricsController',
			static function (ContainerInterface $c) use ($appId) {
				$class = 'OCA\\OpenRegister\\AppHost\\Controller\\GenericMetricsController';
				return new $class(
					appName: $appId,
					request: $c->get('OCP\\IRequest'),
					manifestLoader: $c->get('OCA\\OpenRegister\\AppHost\\Observability\\ManifestLoader'),
					engine: $c->get('OCA\\OpenRegister\\AppHost\\Observability\\MetricsEngine')
				);
			}
		);

	}//end registerGenericControllers()

	/**
	 * Boot the application.
	 *
	 * Every object-event listener that declares a schema interest is subscribed
	 * here rather than in register(): OpenRegister's `ObjectEventSubscription`
	 * is only guaranteed autoloadable once every app's register() has run. See
	 * {@see FilteredObjectListenerTrait::registerFilteredObjectListener()}.
	 *
	 * @param IBootContext $context The boot context
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) OCP\Util exposes script registration
	 * (addInitScript) as a static method only. Nextcloud ships no injectable
	 * service for it, and boot() is the only place an app-wide init script can
	 * be registered, so a seam class would relocate the identical static call
	 * rather than remove it. Verified against nextcloud lib/public/Util.php.
	 */
	public function boot(IBootContext $context): void {
		$dispatcher = $context->getServerContainer()->get(IEventDispatcher::class);

		// Put shillinq's two finance panels on every page, not just shillinq's
		// own (ADR-019 / ADR-066, REQ-SOPR-004 and REQ-FPCR-006). The leaves
		// are meant to be read on ANOTHER app's object: a case, a record, a
		// contract party. Nextcloud loads an app's bundle only on that app's
		// routes, so a leaf registered from src/main.js alone never reaches the
		// page it exists for. This tiny entry registers the two descriptors and
		// nothing else; the panels load their data only once mounted.
		Util::addInitScript(self::APP_ID, self::APP_ID.'-integration-init');

		(new ListenerRegistrar())->subscribe(dispatcher: $dispatcher);
	}//end boot()
}//end class
