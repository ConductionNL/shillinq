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
 */

declare(strict_types=1);

namespace OCA\Shillinq\AppInfo;

use OCA\Shillinq\Listener\AppointmentCreatedListener;
use OCA\Shillinq\Listener\DeepLinkRegistrationListener;
use OCA\Shillinq\Listener\StockMoveTransitionedListener;
use OCA\Shillinq\Repair\InitializeSettings;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Main application class for the Shillinq Nextcloud app.
 */
class Application extends App implements IBootstrap
{
    public const APP_ID = 'shillinq';

    /**
     * Constructor for the Application class.
     *
     * @return void
     */
    public function __construct()
    {
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
    public function register(IRegistrationContext $context): void
    {
        // Register deep link patterns with OpenRegister's unified search provider.
        // Only fires when OpenRegister is installed and dispatches the event.
        $context->registerEventListener(
            event: DeepLinkRegistrationEvent::class,
            listener: DeepLinkRegistrationListener::class
        );

        // bookings-confirm-flow REQ-BCF-001/010 — issue a ConfirmationToken
        // + dispatch the confirmation email when a new Appointment record is
        // created with status `pending_confirmation` (customer self-service).
        // Admin-created bookings start `confirmed` and the listener ignores
        // them. Idempotent: OR fires the event once per saveObject.
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: AppointmentCreatedListener::class
        );

        // Inventory-valuation-fifo-avg REQ-INV-003 / REQ-INV-004 / REQ-INV-007
        // — dispatch posted StockMove records into the valuation engine
        // (FIFO or moving-average per the InventoryValuation.valuationMethod)
        // and post a balanced COGS GLTransaction on outbound moves.
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: StockMoveTransitionedListener::class
        );

        // Initialize register and schemas on install/upgrade.
        $context->registerRepairStep(InitializeSettings::class);

    }//end register()

    /**
     * Boot the application.
     *
     * @param IBootContext $context The boot context
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function boot(IBootContext $context): void
    {
    }//end boot()
}//end class
