<?php

/**
 * Shillinq Settings Service
 *
 * Service for managing Shillinq application configuration and settings.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
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

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing Shillinq application configuration and settings.
 *
 * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md
 */
class SettingsService
{

    /**
     * Configuration keys managed by this service.
     *
     * @var array<string>
     */
    private const CONFIG_KEYS = [
        'register',
        'rgs_template',
        'administration_id',
    ];

    /**
     * Constructor for the SettingsService.
     *
     * @param IAppConfig         $appConfig    The app config interface
     * @param IAppManager        $appManager   The app manager
     * @param ContainerInterface $container    The container
     * @param IGroupManager      $groupManager The group manager
     * @param IUserSession       $userSession  The user session
     * @param LoggerInterface    $logger       The logger
     *
     * @return void
     */
    public function __construct(
        private IAppConfig $appConfig,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private IGroupManager $groupManager,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Check whether OpenRegister is installed and available.
     *
     * @return bool
     *
     * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md#task-1
     */
    public function isOpenRegisterAvailable(): bool
    {
        return $this->appManager->isInstalled('openregister');
    }//end isOpenRegisterAvailable()

    /**
     * Retrieve all current settings.
     *
     * Returns a flat array containing all app config values plus metadata
     * fields (openregisters, isAdmin) consumed by the frontend.
     *
     * @return array<string,mixed>
     *
     * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md#task-1
     */
    public function getSettings(): array
    {
        $settings = [];
        foreach (self::CONFIG_KEYS as $key) {
            $settings[$key] = $this->appConfig->getValueString(Application::APP_ID, $key, '');
        }

        $user    = $this->userSession->getUser();
        $isAdmin = ($user !== null && $this->groupManager->isAdmin($user->getUID()));

        return array_merge(
            $settings,
            [
                'openregisters' => $this->isOpenRegisterAvailable(),
                'isAdmin'       => $isAdmin,
            ]
        );
    }//end getSettings()

    /**
     * Update settings with the provided data.
     *
     * @param array<string,mixed> $data The data to update
     *
     * @return array<string,mixed> The updated settings
     *
     * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md#task-1
     */
    public function updateSettings(array $data): array
    {
        foreach (self::CONFIG_KEYS as $key) {
            if (isset($data[$key]) === true) {
                $this->appConfig->setValueString(Application::APP_ID, $key, (string) $data[$key]);
            }
        }

        return $this->getSettings();
    }//end updateSettings()

    /**
     * Seed the chart of accounts from an RGS template file, idempotently.
     *
     * Reads the selected template from lib/Settings/seeds/ and imports Account
     * records via OpenRegister's ObjectService. Already-existing records (matched
     * by accountNumber + administrationId) are skipped, preserving operator edits.
     *
     * @param string $templateVariant  Template to seed: 'mkb', 'zzp', or 'bbv'.
     * @param string $administrationId The administrationId to stamp on seeded records.
     *
     * @return array<string,mixed> Result with success flag, seeded count, skipped count.
     *
     * @spec openspec/changes/spec/tasks.md#task-11
     */
    public function seedRgsTemplate(string $templateVariant='mkb', string $administrationId='default'): array
    {
        if ($this->isOpenRegisterAvailable() === false) {
            return [
                'success' => false,
                'message' => 'OpenRegister is not installed or enabled.',
            ];
        }

        $accountsResult = $this->loadSeedAccounts(templateVariant: $templateVariant);
        if (isset($accountsResult['error']) === true) {
            return $accountsResult['error'];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            ['seeded' => $seeded, 'skipped' => $skipped] = $this->importAccounts(
                objectService: $objectService,
                accounts: $accountsResult['accounts'],
                administrationId: $administrationId
            );

            $this->logger->info(
                'Shillinq: RGS template seeded',
                [
                    'variant' => $templateVariant,
                    'seeded'  => $seeded,
                    'skipped' => $skipped,
                ]
            );

            return [
                'success' => true,
                'message' => 'RGS template seeded successfully.',
                'seeded'  => $seeded,
                'skipped' => $skipped,
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: RGS template seeding failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }//end try

    }//end seedRgsTemplate()

    /**
     * Load and validate accounts from a seed file for the given template variant.
     *
     * Returns `['accounts' => array]` on success, or `['error' => array]` with a
     * failure result array on any validation / IO error.
     *
     * @param string $templateVariant Template to load: 'mkb', 'zzp', or 'bbv'.
     *
     * @return array{accounts: array<mixed>}|array{error: array<string,mixed>}
     */
    private function loadSeedAccounts(string $templateVariant): array
    {
        $fileMap = [
            'mkb' => 'rgs-3.5-mkb.json',
            'zzp' => 'rgs-3.5-zzp.json',
            'bbv' => 'rgs-bbv.json',
        ];

        if (isset($fileMap[$templateVariant]) === false) {
            return ['error' => ['success' => false, 'message' => 'Unknown RGS template variant: '.$templateVariant]];
        }

        $seedPath = __DIR__.'/../Settings/seeds/'.$fileMap[$templateVariant];
        if (file_exists($seedPath) === false) {
            $this->logger->error('Shillinq: seed file not found at '.$seedPath);
            return ['error' => ['success' => false, 'message' => 'Seed file not found: '.$fileMap[$templateVariant]]];
        }

        $content = file_get_contents($seedPath);
        if ($content === false) {
            return ['error' => ['success' => false, 'message' => 'Failed to read seed file.']];
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['error' => ['success' => false, 'message' => 'Failed to parse seed file: '.json_last_error_msg()]];
        }

        $accounts = ($data['accounts'] ?? []);
        if (empty($accounts) === true) {
            return ['error' => ['success' => false, 'message' => 'Seed file contains no accounts.']];
        }

        return ['accounts' => $accounts];

    }//end loadSeedAccounts()

    /**
     * Import a list of account records into OpenRegister, skipping existing ones.
     *
     * @param object       $objectService    OpenRegister ObjectService.
     * @param array<mixed> $accounts         Account records to import.
     * @param string       $administrationId The administrationId to stamp.
     *
     * @return array{seeded: int, skipped: int}
     */
    private function importAccounts(object $objectService, array $accounts, string $administrationId): array
    {
        $seeded  = 0;
        $skipped = 0;

        foreach ($accounts as $account) {
            $account['administrationId'] = $administrationId;

            $existing = $objectService->findObjects(
                register: 'shillinq',
                schema: 'Account',
                params: [
                    'accountNumber'    => $account['accountNumber'],
                    'administrationId' => $administrationId,
                    '_limit'           => 1,
                ]
            );

            if (empty($existing) === false) {
                $skipped++;
                continue;
            }

            $objectService->saveObject(
                register: 'shillinq',
                schema: 'Account',
                object: $account
            );
            $seeded++;
        }//end foreach

        return ['seeded' => $seeded, 'skipped' => $skipped];

    }//end importAccounts()

    /**
     * Load configuration from shillinq_register.json via OpenRegister.
     *
     * Skips import when the register is already configured (idempotent).
     * Use {@see self::loadConfigurationForced()} to bypass this guard.
     *
     * @return array<string,mixed> Result with success flag, message, and version.
     *
     * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md#task-2
     */
    public function loadConfiguration(): array
    {
        return $this->runLoadConfiguration(force: false);

    }//end loadConfiguration()

    /**
     * Force re-import of configuration from shillinq_register.json via OpenRegister.
     *
     * Bypasses the already-configured guard and always re-imports.
     * Use {@see self::loadConfiguration()} for the idempotent variant.
     *
     * @return array<string,mixed> Result with success flag, message, and version.
     *
     * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md#task-2
     */
    public function loadConfigurationForced(): array
    {
        return $this->runLoadConfiguration(force: true);

    }//end loadConfigurationForced()

    /**
     * Internal implementation for loadConfiguration / loadConfigurationForced.
     *
     * @param bool $force Force re-import even if already configured.
     *
     * @return array<string,mixed>
     */
    private function runLoadConfiguration(bool $force): array
    {
        if ($this->isOpenRegisterAvailable() === false) {
            $this->logger->warning('Shillinq: OpenRegister not available, skipping register initialization');
            return [
                'success' => false,
                'message' => 'OpenRegister is not installed or enabled.',
            ];
        }

        try {
            $configPath = __DIR__.'/../Settings/shillinq_register.json';
            if (file_exists($configPath) === false) {
                $this->logger->error('Shillinq: shillinq_register.json not found at '.$configPath);
                return [
                    'success' => false,
                    'message' => 'Configuration file shillinq_register.json not found.',
                ];
            }

            $configContent = file_get_contents($configPath);
            if ($configContent === false) {
                $this->logger->error('Shillinq: failed to read shillinq_register.json');
                return [
                    'success' => false,
                    'message' => 'Failed to read configuration file.',
                ];
            }

            $configData = json_decode($configContent, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error('Shillinq: failed to parse shillinq_register.json: '.json_last_error_msg());
                return [
                    'success' => false,
                    'message' => 'Failed to parse configuration file: '.json_last_error_msg(),
                ];
            }

            $configVersion = ($configData['info']['version'] ?? '0.0.0');

            $configurationService = $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
            $result = $configurationService->importFromApp(
                appId: Application::APP_ID,
                data: $configData,
                version: $configVersion,
                force: $force
            );

            if (empty($result) === false) {
                $this->logger->info('Shillinq: register configuration imported successfully');
                return [
                    'success' => true,
                    'message' => 'Configuration imported successfully.',
                    'version' => ($result['version'] ?? 'unknown'),
                ];
            }

            return [
                'success' => false,
                'message' => 'Import returned an empty result.',
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: configuration import failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }//end try
    }//end runLoadConfiguration()
}//end class
