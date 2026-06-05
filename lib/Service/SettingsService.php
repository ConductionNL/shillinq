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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
     * Return the configured register slug, falling back to 'shillinq' if unset.
     *
     * Single source of truth for the register slug — mirrors the dynamic slug
     * resolution already used by DeepLinkRegistrationListener (L3/C3).
     *
     * @return string
     *
     * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md
     */
    public function getRegisterSlug(): string
    {
        $slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
        if ($slug === '') {
            return 'shillinq';
        }

        return $slug;

    }//end getRegisterSlug()

    /**
     * Seed the chart of accounts from an RGS template file, idempotently.
     *
     * Reads the selected template from lib/Settings/seeds/ and imports Account
     * records via OpenRegister's ObjectService. Already-existing records (matched
     * by accountNumber + administrationId) are skipped, preserving operator edits.
     *
     * @param string $templateVariant  Template to seed: 'mkb', 'zzp', or 'bbv'.
     * @param string $administrationId The administrationId to stamp on seeded records.
     *                                 Must be a non-empty tenant-specific identifier;
     *                                 callers must always supply an explicit value (C2).
     *
     * @return array<string,mixed> Result with success flag, seeded count, skipped count.
     *
     * @spec openspec/changes/spec/tasks.md#task-11
     */
    public function seedRgsTemplate(string $templateVariant, string $administrationId): array
    {
        if ($this->isOpenRegisterAvailable() === false) {
            return [
                'success' => false,
                'message' => 'OpenRegister is not installed or enabled.',
            ];
        }

        // C2: reject empty administrationId — writing under '' or a hardcoded
        // "default" would contaminate real tenant data on later reconfiguration.
        if ($administrationId === '') {
            return [
                'success' => false,
                'message' => 'administrationId must not be empty.',
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
     * Deduplication key is (accountNumber, administrationId). This means that if
     * an admin changes the administrationId app config after an initial seed, the
     * previously seeded accounts (under the old id) will be orphaned — they remain
     * in OpenRegister but are no longer matched on re-seed. To clean up, an admin
     * must manually delete accounts with the stale administrationId via the
     * OpenRegister UI before re-seeding under the new id (M3).
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

            $registerSlug = $this->getRegisterSlug();
            $existing     = $objectService
                ->setRegister($registerSlug)
                ->setSchema('Account')
                ->findAll(
                        [
                            'filters' => [
                                'accountNumber'    => $account['accountNumber'],
                                'administrationId' => $administrationId,
                            ],
                            'limit'   => 1,
                        ]
                        );

            if (empty($existing) === false) {
                $skipped++;
                continue;
            }

            $objectService->saveObject(
                object: $account,
                register: $registerSlug,
                schema: 'Account',
            );
            $seeded++;
        }//end foreach

        return ['seeded' => $seeded, 'skipped' => $skipped];

    }//end importAccounts()

    /**
     * Seed RJ-270 stages from the rj-270-stages.json seed file, idempotently.
     *
     * Imports the 4 canonical percentage-of-completion stage definitions.
     * Deduplication key is stageId. Idempotent on re-run.
     *
     * @return array<string,mixed> Result with success flag, seeded count, skipped count.
     *
     * @spec openspec/changes/add-shillinq-consultancy-project-accounting/tasks.md#task-14
     */
    public function seedRj270Stages(): array
    {
        return $this->seedGenericFile(
            seedFileName: 'rj-270-stages.json',
            itemsKey: 'stages',
            dedupeKey: 'stageId',
            schema: 'RJ270Stage',
            logLabel: 'RJ-270 stages'
        );

    }//end seedRj270Stages()

    /**
     * Seed statutory BTW tariffs from btw-tariffs-2026.json, idempotently.
     *
     * Imports the current Dutch VAT rates into the VatTariff schema. Deduplication
     * key is code. Idempotent on re-run; operator-added rates are preserved.
     *
     * @return array<string,mixed> Result with success flag, seeded count, skipped count.
     *
     * @spec openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-vat-btw-filing/spec.md
     */
    public function seedBtwTariffs(): array
    {
        return $this->seedGenericFile(
            seedFileName: 'btw-tariffs-2026.json',
            itemsKey: 'tariffs',
            dedupeKey: 'code',
            schema: 'VatTariff',
            logLabel: 'BTW tariffs'
        );

    }//end seedBtwTariffs()

    /**
     * Seed the BBV taakveld catalogue from bbv-taakvelden-2024.json, idempotently.
     *
     * Imports the canonical Besluit BBV bijlage IV taakvelden into the BbvTaakveld
     * schema. Deduplication key is code. Idempotent on re-run.
     *
     * @return array<string,mixed> Result with success flag, seeded count, skipped count.
     *
     * @spec openspec/changes/add-shillinq-bookkeeping-operations/specs/bookkeeping-bbv-compliance/spec.md
     */
    public function seedBbvTaakvelden(): array
    {
        return $this->seedGenericFile(
            seedFileName: 'bbv-taakvelden-2024.json',
            itemsKey: 'taakvelden',
            dedupeKey: 'code',
            schema: 'BbvTaakveld',
            logLabel: 'BBV taakvelden'
        );

    }//end seedBbvTaakvelden()

    /**
     * Seed default rate-card templates from rate-card-templates.json, idempotently.
     *
     * Requires a non-empty administrationId; seeding is skipped otherwise (C2).
     * Deduplication key is level + effectiveFrom + administrationId.
     *
     * @param string $administrationId The administrationId to stamp on seeded records.
     *
     * @return array<string,mixed> Result with success flag, seeded count, skipped count.
     *
     * @spec openspec/changes/add-shillinq-consultancy-project-accounting/tasks.md#task-14
     */
    public function seedRateCardTemplates(string $administrationId): array
    {
        if ($this->isOpenRegisterAvailable() === false) {
            return [
                'success' => false,
                'message' => 'OpenRegister is not installed or enabled.',
            ];
        }

        if ($administrationId === '') {
            return [
                'success' => false,
                'message' => 'administrationId must not be empty.',
            ];
        }

        $seedPath = __DIR__.'/../Settings/seeds/rate-card-templates.json';
        if (file_exists($seedPath) === false) {
            return ['success' => false, 'message' => 'Seed file not found: rate-card-templates.json'];
        }

        $content = file_get_contents($seedPath);
        if ($content === false) {
            return ['success' => false, 'message' => 'Failed to read rate-card-templates.json'];
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'message' => 'Failed to parse rate-card-templates.json: '.json_last_error_msg()];
        }

        $rateCards = ($data['rateCards'] ?? []);
        if (empty($rateCards) === true) {
            return ['success' => false, 'message' => 'Seed file contains no rateCards.'];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $registerSlug  = $this->getRegisterSlug();
            $seeded        = 0;
            $skipped       = 0;

            foreach ($rateCards as $rateCard) {
                $rateCard['administrationId'] = $administrationId;

                $existing = $objectService
                    ->setRegister($registerSlug)
                    ->setSchema('RateCard')
                    ->findAll(
                            [
                                'filters' => [
                                    'level'            => $rateCard['level'],
                                    'effectiveFrom'    => $rateCard['effectiveFrom'],
                                    'administrationId' => $administrationId,
                                ],
                                'limit'   => 1,
                            ]
                            );

                if (empty($existing) === false) {
                    $skipped++;
                    continue;
                }

                $objectService->saveObject(
                    object: $rateCard,
                    register: $registerSlug,
                    schema: 'RateCard',
                );
                $seeded++;
            }//end foreach

            $this->logger->info(
                'Shillinq: rate-card templates seeded',
                [
                    'seeded'  => $seeded,
                    'skipped' => $skipped,
                ]
            );

            return [
                'success' => true,
                'message' => 'Rate-card templates seeded successfully.',
                'seeded'  => $seeded,
                'skipped' => $skipped,
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: rate-card templates seeding failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }//end try

    }//end seedRateCardTemplates()

    /**
     * Generic seed helper for single-schema seed files that don't require an administrationId.
     *
     * @param string $seedFileName Name of the seed file under lib/Settings/seeds/.
     * @param string $itemsKey     Key in the JSON holding the items array.
     * @param string $dedupeKey    Field used as deduplication key.
     * @param string $schema       OpenRegister schema slug to import into.
     * @param string $logLabel     Label for log messages.
     *
     * @return array<string,mixed>
     */
    private function seedGenericFile(
        string $seedFileName,
        string $itemsKey,
        string $dedupeKey,
        string $schema,
        string $logLabel
    ): array {
        if ($this->isOpenRegisterAvailable() === false) {
            return ['success' => false, 'message' => 'OpenRegister is not installed or enabled.'];
        }

        $seedPath = __DIR__.'/../Settings/seeds/'.$seedFileName;
        if (file_exists($seedPath) === false) {
            return ['success' => false, 'message' => 'Seed file not found: '.$seedFileName];
        }

        $content = file_get_contents($seedPath);
        if ($content === false) {
            return ['success' => false, 'message' => 'Failed to read '.$seedFileName];
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'message' => 'Failed to parse '.$seedFileName.': '.json_last_error_msg()];
        }

        $items = ($data[$itemsKey] ?? []);
        if (empty($items) === true) {
            return ['success' => false, 'message' => 'Seed file '.$seedFileName.' contains no '.$itemsKey.'.'];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $registerSlug  = $this->getRegisterSlug();
            $seeded        = 0;
            $skipped       = 0;

            foreach ($items as $item) {
                $existing = $objectService
                    ->setRegister($registerSlug)
                    ->setSchema($schema)
                    ->findAll(
                            [
                                'filters' => [$dedupeKey => $item[$dedupeKey]],
                                'limit'   => 1,
                            ]
                            );

                if (empty($existing) === false) {
                    $skipped++;
                    continue;
                }

                $objectService->saveObject(
                    object: $item,
                    register: $registerSlug,
                    schema: $schema,
                );
                $seeded++;
            }//end foreach

            $this->logger->info(
                'Shillinq: '.$logLabel.' seeded',
                ['seeded' => $seeded, 'skipped' => $skipped]
            );

            return [
                'success' => true,
                'message' => $logLabel.' seeded successfully.',
                'seeded'  => $seeded,
                'skipped' => $skipped,
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: '.$logLabel.' seeding failed',
                ['exception' => $e->getMessage()]
            );
            return ['success' => false, 'message' => $e->getMessage()];
        }//end try

    }//end seedGenericFile()

    /**
     * Seed retention rules from the Selectielijst Gemeenten 2020 seed file, idempotently.
     *
     * Reads lib/Settings/seeds/selectielijst-gemeenten-2020.json and imports
     * RetentionRule records via OpenRegister's ObjectService. Already-existing
     * records (matched by selectielijstCode + null administrationId) are skipped,
     * preserving operator-authored overrides. Per REQ-ARC-002.
     *
     * @return array<string,mixed> Result with success flag, seeded count, skipped count.
     *
     * @spec openspec/changes/add-shillinq-archiefwet-retention/tasks.md#task-11
     */
    public function seedSelectielijst(): array
    {
        if ($this->isOpenRegisterAvailable() === false) {
            return [
                'success' => false,
                'message' => 'OpenRegister is not installed or enabled.',
            ];
        }

        $seedPath = __DIR__.'/../Settings/seeds/selectielijst-gemeenten-2020.json';
        if (file_exists($seedPath) === false) {
            $this->logger->error('Shillinq: selectielijst-gemeenten-2020.json not found at '.$seedPath);
            return [
                'success' => false,
                'message' => 'Seed file selectielijst-gemeenten-2020.json not found.',
            ];
        }

        $content = file_get_contents($seedPath);
        if ($content === false) {
            return ['success' => false, 'message' => 'Failed to read selectielijst seed file.'];
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'message' => 'Failed to parse selectielijst seed file: '.json_last_error_msg()];
        }

        $rules = ($data['retentionRules'] ?? []);
        if (empty($rules) === true) {
            return ['success' => false, 'message' => 'Seed file contains no retention rules.'];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $registerSlug  = $this->getRegisterSlug();
            $seeded        = 0;
            $skipped       = 0;

            foreach ($rules as $rule) {
                $code     = ($rule['selectielijstCode'] ?? '');
                $existing = $objectService
                    ->setRegister($registerSlug)
                    ->setSchema('RetentionRule')
                    ->findAll(
                        [
                            'filters' => [
                                'selectielijstCode' => $code,
                                'administrationId'  => null,
                            ],
                            'limit'   => 1,
                        ]
                    );

                if (empty($existing) === false) {
                    $skipped++;
                    continue;
                }

                $objectService->saveObject(
                    object: $rule,
                    register: $registerSlug,
                    schema: 'RetentionRule',
                );
                $seeded++;
            }//end foreach

            $this->logger->info(
                'Shillinq: Selectielijst retention rules seeded',
                [
                    'seeded'  => $seeded,
                    'skipped' => $skipped,
                ]
            );

            return [
                'success' => true,
                'message' => 'Selectielijst retention rules seeded.',
                'seeded'  => $seeded,
                'skipped' => $skipped,
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: Selectielijst seeding failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }//end try

    }//end seedSelectielijst()

    /**
     * Seed example allocation rules from the bundled seed files, idempotently.
     *
     * Reads all three default seed files from lib/Settings/seeds/allocation-rules/
     * and imports AllocationRule records via OpenRegister's ObjectService. Records
     * are matched by (name, administrationId) — already-existing records are skipped.
     * Seeds ship in lifecycleState: paused for operator review per REQ-CC-004.
     *
     * @param string $administrationId The administrationId to stamp on seeded records.
     *                                 Must be a non-empty tenant-specific identifier (C2).
     *
     * @return array<string,mixed> Result with success flag, seeded count, skipped count.
     *
     * @spec openspec/changes/add-shillinq-cost-centers-dimensions/tasks.md#task-11
     */
    public function seedAllocationRules(string $administrationId): array
    {
        if ($this->isOpenRegisterAvailable() === false) {
            return [
                'success' => false,
                'message' => 'OpenRegister is not installed or enabled.',
            ];
        }

        if ($administrationId === '') {
            return [
                'success' => false,
                'message' => 'administrationId must not be empty.',
            ];
        }

        $seedFiles = [
            'overhead-by-headcount.json',
            'it-by-volume.json',
            'facility-by-fixed-percentage.json',
        ];

        $seeded  = 0;
        $skipped = 0;

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            foreach ($seedFiles as $seedFile) {
                $seedPath = __DIR__.'/../Settings/seeds/allocation-rules/'.$seedFile;

                if (file_exists($seedPath) === false) {
                    $this->logger->warning('Shillinq: allocation rule seed file not found at '.$seedPath);
                    continue;
                }

                $content = file_get_contents($seedPath);
                if ($content === false) {
                    $this->logger->warning('Shillinq: failed to read allocation rule seed file: '.$seedFile);
                    continue;
                }

                $data = json_decode($content, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->logger->warning('Shillinq: failed to parse allocation rule seed file: '.$seedFile);
                    continue;
                }

                $rules = ($data['allocationRules'] ?? []);

                foreach ($rules as $rule) {
                    $rule['administrationId'] = $administrationId;

                    $registerSlug = $this->getRegisterSlug();
                    $existing     = $objectService
                        ->setRegister($registerSlug)
                        ->setSchema('AllocationRule')
                        ->findAll(
                            [
                                'filters' => [
                                    'name'             => $rule['name'],
                                    'administrationId' => $administrationId,
                                ],
                                'limit'   => 1,
                            ]
                        );

                    if (empty($existing) === false) {
                        $skipped++;
                        continue;
                    }

                    $objectService->saveObject(
                        object: $rule,
                        register: $registerSlug,
                        schema: 'AllocationRule',
                    );
                    $seeded++;
                }//end foreach
            }//end foreach

            $this->logger->info(
                'Shillinq: allocation rule seeds imported',
                [
                    'seeded'  => $seeded,
                    'skipped' => $skipped,
                ]
            );

            return [
                'success' => true,
                'message' => 'Allocation rule seeds imported successfully.',
                'seeded'  => $seeded,
                'skipped' => $skipped,
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: allocation rule seeding failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }//end try

    }//end seedAllocationRules()

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
     * Load and parse the shillinq_register.json configuration file.
     *
     * Returns the parsed data array on success, or an error result array on
     * failure (same shape as the public load methods so callers can return it).
     *
     * @return array<string,mixed> Either ['data' => array, 'version' => string]
     *                             or ['success' => false, 'message' => string]
     */
    private function loadRegisterConfigData(): array
    {
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

        // ADR-037: merge modular register fragments from Settings/register.d/*.json.
        // Each OpenSpec change drops its own fragment file instead of editing this
        // monolith, so concurrent builds touch disjoint files (no merge conflicts).
        // OpenAPI `components.schemas` / `paths` are keyed objects, so disjoint
        // fragments union cleanly by key.
        $fragmentDir = __DIR__.'/../Settings/register.d';
        $fragmentSig = '';
        if (is_dir($fragmentDir) === true) {
            $fragmentFiles = glob($fragmentDir.'/*.json');
            sort($fragmentFiles);
            foreach ($fragmentFiles as $fragmentFile) {
                $fragmentContent = file_get_contents($fragmentFile);
                if ($fragmentContent === false) {
                    continue;
                }

                $fragmentData = json_decode($fragmentContent, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->logger->warning(
                        'Shillinq: skipping malformed register fragment '.basename($fragmentFile)
                        .': '.json_last_error_msg()
                    );
                    continue;
                }

                $configData   = self::deepMergeConfig(base: $configData, overlay: $fragmentData);
                $fragmentSig .= basename($fragmentFile).':'.md5($fragmentContent).';';
            }
        }//end if

        // Fold the fragment signature into the version so OpenRegister's
        // version-gated importFromApp re-imports whenever fragments change.
        $version = ($configData['info']['version'] ?? '0.0.0');
        if ($fragmentSig !== '') {
            $version .= '+frag.'.substr(md5($fragmentSig), 0, 8);
        }

        return [
            'data'    => $configData,
            'version' => $version,
        ];

    }//end loadRegisterConfigData()

    /**
     * Deep-merge a register fragment onto the base config (ADR-037).
     *
     * Associative arrays (OpenAPI objects like `components.schemas`, `paths`) are
     * merged by key union (recursing on shared keys); list arrays are concatenated;
     * scalars in the fragment overwrite the base. Disjoint fragments never collide.
     *
     * @param array<mixed> $base    The accumulated config.
     * @param array<mixed> $overlay The fragment to merge in.
     *
     * @return array<mixed> The merged config.
     */
    private static function deepMergeConfig(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            if (is_array($value) === true
                && isset($base[$key]) === true
                && is_array($base[$key]) === true
            ) {
                $baseIsList    = ($base[$key] === [] || array_keys($base[$key]) === range(0, (count($base[$key]) - 1)));
                $overlayIsList = ($value === [] || array_keys($value) === range(0, (count($value) - 1)));
                if ($baseIsList === true && $overlayIsList === true) {
                    $base[$key] = array_merge($base[$key], $value);
                } else {
                    $base[$key] = self::deepMergeConfig(base: $base[$key], overlay: $value);
                }
            } else {
                $base[$key] = $value;
            }
        }

        return $base;

    }//end deepMergeConfig()

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
            $configLoad = $this->loadRegisterConfigData();
            if (isset($configLoad['success']) === true && $configLoad['success'] === false) {
                return $configLoad;
            }

            $configData    = $configLoad['data'];
            $configVersion = $configLoad['version'];

            $configurationService = $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
            $result = $configurationService->importFromApp(
                appId: Application::APP_ID,
                data: $configData,
                version: $configVersion,
                force: $force
            );

            if (empty($result) === false) {
                $errors   = ($result['errors'] ?? []);
                $warnings = ($result['warnings'] ?? []);

                if (empty($errors) === false) {
                    $this->logger->error(
                        'Shillinq: register configuration imported with errors',
                        ['errors' => $errors]
                    );
                    return [
                        'success'  => false,
                        'message'  => 'Configuration import completed with errors.',
                        'errors'   => $errors,
                        'warnings' => $warnings,
                        'version'  => ($result['version'] ?? 'unknown'),
                    ];
                }

                if (empty($warnings) === false) {
                    $this->logger->warning(
                        'Shillinq: register configuration imported with warnings',
                        ['warnings' => $warnings]
                    );
                }

                $this->logger->info('Shillinq: register configuration imported successfully');
                return [
                    'success'  => true,
                    'message'  => 'Configuration imported successfully.',
                    'warnings' => $warnings,
                    'version'  => ($result['version'] ?? 'unknown'),
                ];
            }//end if

            // C8: OR's importFromApp returns an all-empty result on the version-unchanged
            // idempotent-skip path (force=false, same version as previously imported).
            // This is not a failure — it means the register is already up-to-date.
            // Classifying it as failure caused every routine `occ upgrade` to log
            // "schema import failed, skipping account seed" even when nothing was wrong.
            $this->logger->info('Shillinq: register configuration already up-to-date (version-unchanged skip)');
            return [
                'success' => true,
                'skipped' => true,
                'message' => 'Configuration already up-to-date (version-unchanged skip).',
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

    /**
     * Seed allocation-rule example objects from the default seed files, idempotently.
     *
     * Reads the three example seed files from lib/Settings/seeds/allocation-rules/
     * and imports AllocationRule records via OpenRegister's ObjectService.
     * Already-existing rules (matched by name + administrationId) are skipped,
     * preserving operator edits. Seeds ship in lifecycleState: paused so operators
     * can review and activate per REQ-CC-004.
     *
     * @param string $administrationId The administrationId to stamp on seeded rules.
     *
     * @return array<string,mixed> Result with success flag, seeded count, skipped count.
     *
     * @spec openspec/changes/add-shillinq-cost-centers-dimensions/tasks.md#task-11
     */
    public function seedAllocationRuleExamples(string $administrationId): array
    {
        if ($this->isOpenRegisterAvailable() === false) {
            return [
                'success' => false,
                'message' => 'OpenRegister is not installed or enabled.',
            ];
        }

        if ($administrationId === '') {
            return [
                'success' => false,
                'message' => 'administrationId must not be empty.',
            ];
        }

        $seedFiles = [
            'overhead-by-headcount.json',
            'it-by-volume.json',
            'facility-by-fixed-percentage.json',
        ];

        $seeded  = 0;
        $skipped = 0;

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $registerSlug  = $this->getRegisterSlug();

            foreach ($seedFiles as $fileName) {
                $seedPath = __DIR__.'/../Settings/seeds/allocation-rules/'.$fileName;
                if (file_exists($seedPath) === false) {
                    $this->logger->warning('Shillinq: allocation rule seed file not found: '.$seedPath);
                    continue;
                }

                $content = file_get_contents($seedPath);
                if ($content === false) {
                    $this->logger->warning('Shillinq: failed to read allocation rule seed file: '.$seedPath);
                    continue;
                }

                $data = json_decode($content, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->logger->warning(
                        'Shillinq: failed to parse allocation rule seed file: '.$fileName.': '.json_last_error_msg()
                    );
                    continue;
                }

                $rules = ($data['allocationRules'] ?? []);
                foreach ($rules as $rule) {
                    $rule['administrationId'] = $administrationId;

                    $existing = $objectService
                        ->setRegister($registerSlug)
                        ->setSchema('AllocationRule')
                        ->findAll(
                            [
                                'filters' => [
                                    'name'             => $rule['name'],
                                    'administrationId' => $administrationId,
                                ],
                                'limit'   => 1,
                            ]
                        );

                    if (empty($existing) === false) {
                        $skipped++;
                        continue;
                    }

                    $objectService->saveObject(
                        object: $rule,
                        register: $registerSlug,
                        schema: 'AllocationRule',
                    );
                    $seeded++;
                }//end foreach
            }//end foreach

            $this->logger->info(
                'Shillinq: allocation rule examples seeded',
                [
                    'seeded'  => $seeded,
                    'skipped' => $skipped,
                ]
            );

            return [
                'success' => true,
                'message' => 'Allocation rule examples seeded successfully.',
                'seeded'  => $seeded,
                'skipped' => $skipped,
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: allocation rule seeding failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }//end try

    }//end seedAllocationRuleExamples()

    /**
     * Seed ProductAttribute templates from a category seed file, idempotently.
     *
     * Reads the specified seed file from lib/Settings/seeds/ and imports
     * ProductAttribute records via OpenRegister's ObjectService. Already-existing
     * records (matched by name + applicableToCategories) are skipped, preserving
     * operator edits across repair re-runs per REQ-IPC-007.
     *
     * @param string $category Seed category key: office, it_hardware, logistics, food_beverage, clothing.
     *
     * @return array<string,mixed> Result with success flag, seeded count, skipped count.
     *
     * @spec openspec/changes/inventory-product-catalog/tasks.md#task-13
     */
    public function seedProductAttributes(string $category): array
    {
        if ($this->isOpenRegisterAvailable() === false) {
            return [
                'success' => false,
                'message' => 'OpenRegister is not installed or enabled.',
            ];
        }

        // Category keys use underscores (it_hardware, food_beverage); filenames use hyphens.
        $filename = 'product-attributes-'.str_replace('_', '-', $category).'.json';
        $seedPath = __DIR__.'/../Settings/seeds/'.$filename;

        if (file_exists($seedPath) === false) {
            return [
                'success' => false,
                'message' => 'Seed file not found: '.$filename,
            ];
        }

        $content = file_get_contents($seedPath);
        if ($content === false) {
            return [
                'success' => false,
                'message' => 'Failed to read seed file: '.$filename,
            ];
        }

        $data = json_decode($content, associative: true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'message' => 'Failed to parse seed file '.$filename.': '.json_last_error_msg(),
            ];
        }

        $attributes = ($data['productAttributes'] ?? []);
        if (empty($attributes) === true) {
            return [
                'success' => true,
                'message' => 'Seed file contains no productAttributes, nothing to import.',
                'seeded'  => 0,
                'skipped' => 0,
            ];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            ['seeded' => $seeded, 'skipped' => $skipped] = $this->importProductAttributes(
                objectService: $objectService,
                attributes: $attributes
            );

            $this->logger->info(
                'Shillinq: ProductAttribute seed imported',
                [
                    'category' => $category,
                    'seeded'   => $seeded,
                    'skipped'  => $skipped,
                ]
            );

            return [
                'success' => true,
                'message' => 'ProductAttribute seed imported successfully.',
                'seeded'  => $seeded,
                'skipped' => $skipped,
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: ProductAttribute seeding failed',
                [
                    'category'  => $category,
                    'exception' => $e->getMessage(),
                ]
            );
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }//end try

    }//end seedProductAttributes()

    /**
     * Import ProductAttribute records into OpenRegister, skipping existing ones.
     *
     * Deduplication key is (name, applicableToCategories), preserving operator
     * edits across repair re-runs per REQ-IPC-007. Records missing either key
     * field are skipped silently.
     *
     * @param object       $objectService OpenRegister ObjectService.
     * @param array<mixed> $attributes    ProductAttribute records to import.
     *
     * @return array{seeded: int, skipped: int}
     */
    private function importProductAttributes(object $objectService, array $attributes): array
    {
        $registerSlug = $this->getRegisterSlug();
        $seeded       = 0;
        $skipped      = 0;

        foreach ($attributes as $attribute) {
            $name       = ($attribute['name'] ?? null);
            $categories = ($attribute['applicableToCategories'] ?? null);

            if ($name === null || $categories === null) {
                continue;
            }

            // Deduplication key: name + applicableToCategories preserves operator edits.
            // ADR-022: use the real ObjectService fluent API (setRegister/setSchema/findAll);
            // findObjects() does not exist on OpenRegister's ObjectService.
            $existing = $objectService
                ->setRegister($registerSlug)
                ->setSchema('ProductAttribute')
                ->findAll(
                    [
                        'filters' => [
                            'name'                   => $name,
                            'applicableToCategories' => $categories,
                        ],
                        'limit'   => 1,
                    ]
                );

            if (empty($existing) === false) {
                $skipped++;
                continue;
            }

            $objectService->saveObject(
                object: $attribute,
                register: $registerSlug,
                schema: 'ProductAttribute',
            );
            $seeded++;
        }//end foreach

        return ['seeded' => $seeded, 'skipped' => $skipped];

    }//end importProductAttributes()

    /**
     * Seed demo InventoryLot records from inventory-lots-demo.json, idempotently.
     *
     * Reads lib/Settings/seeds/inventory-lots-demo.json and imports InventoryLot
     * records via OpenRegister's ObjectService. Deduplication key is lotNumber —
     * already-existing lots are skipped so re-runs are safe.
     * Only called when APP_ENV=development per REQ-LOT design.md seed data section.
     *
     * @return array<string,mixed> Result with success flag, seeded count, skipped count.
     *
     * @spec openspec/changes/inventory-lot-batch-expiry/tasks.md#task-14
     */
    public function seedInventoryLotsDemoData(): array
    {
        if ($this->isOpenRegisterAvailable() === false) {
            return [
                'success' => false,
                'message' => 'OpenRegister is not installed or enabled.',
            ];
        }

        $seedPath = __DIR__.'/../Settings/seeds/inventory-lots-demo.json';

        if (file_exists($seedPath) === false) {
            return [
                'success' => false,
                'message' => 'Seed file not found: inventory-lots-demo.json',
            ];
        }

        $content = file_get_contents($seedPath);
        if ($content === false) {
            return [
                'success' => false,
                'message' => 'Failed to read seed file: inventory-lots-demo.json',
            ];
        }

        $data = json_decode($content, associative: true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'message' => 'Failed to parse inventory-lots-demo.json: '.json_last_error_msg(),
            ];
        }

        $lots = ($data['inventoryLots'] ?? []);
        if (empty($lots) === true) {
            return [
                'success' => true,
                'message' => 'Seed file contains no inventoryLots, nothing to import.',
                'seeded'  => 0,
                'skipped' => 0,
            ];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $registerSlug  = $this->getRegisterSlug();
            $seeded        = 0;
            $skipped       = 0;

            foreach ($lots as $lot) {
                $lotNumber = ($lot['lotNumber'] ?? '');
                if ($lotNumber === '') {
                    $skipped++;
                    continue;
                }

                $existing = $objectService
                    ->setRegister($registerSlug)
                    ->setSchema('InventoryLot')
                    ->findAll(
                        [
                            'filters' => ['lotNumber' => $lotNumber],
                            'limit'   => 1,
                        ]
                    );

                if (empty($existing) === false) {
                    $skipped++;
                    continue;
                }

                $objectService->saveObject(
                    object: $lot,
                    register: $registerSlug,
                    schema: 'InventoryLot',
                );
                $seeded++;
            }//end foreach

            $this->logger->info(
                'Shillinq: InventoryLot demo seed imported',
                ['seeded' => $seeded, 'skipped' => $skipped]
            );

            return [
                'success' => true,
                'message' => 'InventoryLot demo seed imported successfully.',
                'seeded'  => $seeded,
                'skipped' => $skipped,
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: InventoryLot demo seeding failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }//end try

    }//end seedInventoryLotsDemoData()
}//end class
