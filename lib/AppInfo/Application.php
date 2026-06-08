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

use OCA\Shillinq\BackgroundJob\TaxDeadlineReminderJob;
use OCA\Shillinq\Listener\DeepLinkRegistrationListener;
use OCA\Shillinq\Notification\Notifier;
use OCA\Shillinq\Repair\InitializeSettings;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;
use Throwable;

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

        // Initialize register and schemas on install/upgrade.
        $context->registerRepairStep(InitializeSettings::class);

        // The daily SEPA mandate dormancy-expiry job (REQ-SDD-008, ADR-031
        // exception pending OpenRegister ScheduledWorkflow) is registered via
        // appinfo/info.xml <background-jobs> and autowired by the DI container.
        // Register the notifier for Shillinq in-app notifications (REQ-SUBV-010).
        $context->registerNotifierService(Notifier::class);
    }//end register()

    /**
     * Boot the application.
     *
     * Enqueues the daily Vpb deadline reminder job (REQ-VPB-013) idempotently —
     * IJobList::add() is a no-op when the job is already registered. The job's
     * constructor dependencies are auto-wired by the Nextcloud DI container.
     *
     * @param IBootContext $context The boot context
     *
     * @return void
     */
    public function boot(IBootContext $context): void
    {
        try {
            $jobList = $context->getServerContainer()->get(IJobList::class);
            $jobList->add(TaxDeadlineReminderJob::class);
        } catch (Throwable $e) {
            $context->getServerContainer()->get(LoggerInterface::class)->warning(
                'Shillinq: failed to enqueue Vpb deadline reminder job',
                ['exception' => $e->getMessage()]
            );
        }

    }//end boot()
}//end class
