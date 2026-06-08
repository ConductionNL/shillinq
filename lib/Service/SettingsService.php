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
     * Seed the default Administration on a fresh install, idempotently.
     *
     * Single-administratie installs need exactly one valid Administration record so
     * every financial entity has a valid administrationId FK target (REQ-MA-001).
     * The seed (lib/Settings/seeds/administraties/default.json) is matched on
     * administrationCode, so re-runs create no duplicates and operator edits to the
     * default administration are preserved (REQ-MA-007).
     *
     * @return array<string,mixed> Result with success flag, seeded count, skipped count.
     *
     * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-14
     */
    public function seedDefaultAdministration(): array
    {
        return $this->seedGenericFile(
            seedFileName: 'administraties/default.json',
            itemsKey: 'administrations',
            dedupeKey: 'administrationCode',
            schema: 'Administration',
            logLabel: 'Default administration'
        );

    }//end seedDefaultAdministration()

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
     * Seed demo Barcode records from inventory-barcodes-demo.json, idempotently.
     *
     * Reads `lib/Settings/seeds/inventory-barcodes-demo.json` and imports
     * Barcode records via OpenRegister's ObjectService. Deduplication key is
     * `(barcode, uomCode)` per REQ-SKU-011 — re-running the repair step never
     * creates duplicates, preserving operator edits. The seeded barcodes
     * reference inventory-product-catalog demo SKUs (DV-KAT-SENIOR-2KG,
     * VIT-C-1000MG-100CT); when those Products are absent the barcodes still
     * load and become discoverable once the products land.
     *
     * @return array<string, mixed> Result with success flag, seeded count, skipped count, message.
     *
     * @spec openspec/changes/inventory-barcode-sku/tasks.md#task-15
     */
    public function seedInventoryBarcodes(): array
    {
        if ($this->isOpenRegisterAvailable() === false) {
            return [
                'success' => false,
                'message' => 'OpenRegister is not installed or enabled.',
            ];
        }

        $seedPath = __DIR__.'/../Settings/seeds/inventory-barcodes-demo.json';
        if (file_exists($seedPath) === false) {
            return [
                'success' => false,
                'message' => 'Seed file not found: inventory-barcodes-demo.json',
            ];
        }

        $content = file_get_contents($seedPath);
        if ($content === false) {
            return [
                'success' => false,
                'message' => 'Failed to read inventory-barcodes-demo.json',
            ];
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'message' => 'Failed to parse inventory-barcodes-demo.json: '.json_last_error_msg(),
            ];
        }

        $barcodes = ($data['barcodes'] ?? []);
        if (empty($barcodes) === true) {
            return [
                'success' => true,
                'message' => 'Seed file contains no barcodes, nothing to import.',
                'seeded'  => 0,
                'skipped' => 0,
            ];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $registerSlug  = $this->getRegisterSlug();
            $seeded        = 0;
            $skipped       = 0;

            foreach ($barcodes as $barcode) {
                // Deduplication key (barcode, uomCode) — idempotent re-runs per REQ-SKU-011.
                // ADR-022: use the real ObjectService fluent API (setRegister/setSchema/findAll).
                $existing = $objectService
                    ->setRegister($registerSlug)
                    ->setSchema('Barcode')
                    ->findAll(
                        [
                            'filters' => [
                                'barcode' => ($barcode['barcode'] ?? ''),
                                'uomCode' => ($barcode['uomCode'] ?? ''),
                            ],
                            'limit'   => 1,
                        ]
                    );

                if (empty($existing) === false) {
                    $skipped++;
                    continue;
                }

                $objectService->saveObject(
                    object: $barcode,
                    register: $registerSlug,
                    schema: 'Barcode',
                );
                $seeded++;
            }//end foreach

            $this->logger->info(
                'Shillinq: inventory barcodes seeded',
                ['seeded' => $seeded, 'skipped' => $skipped]
            );

            return [
                'success' => true,
                'message' => 'Inventory barcodes seeded successfully.',
                'seeded'  => $seeded,
                'skipped' => $skipped,
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: inventory barcode seeding failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }//end try

    }//end seedInventoryBarcodes()

    /**
     * Seed demo InventoryLot records from inventory-lots-demo.json, idempotently.
     *
     * Reads `lib/Settings/seeds/inventory-lots-demo.json` and imports
     * InventoryLot records via OpenRegister's ObjectService. Deduplication key
     * is `(administrationId, lotNumber)` per REQ-LOT-002 — re-running the
     * repair step never creates duplicates, preserving operator edits. The
     * seeded lots reference inventory-product-catalog demo SKUs; when those
     * Products are absent the lots still load and become discoverable once
     * the products land. Skips silently when no default administration is
     * configured (C2 — prevents "default" contamination).
     *
     * @return array<string, mixed> Result with success flag, seeded count, skipped count, message.
     *
     * @spec openspec/changes/inventory-lot-batch-expiry/tasks.md#task-14
     */
    public function seedInventoryLots(): array
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
                'message' => 'Failed to read inventory-lots-demo.json',
            ];
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'message' => 'Failed to parse inventory-lots-demo.json: '.json_last_error_msg(),
            ];
        }

        $lots = ($data['lots'] ?? []);
        if (empty($lots) === true) {
            return [
                'success' => true,
                'message' => 'Seed file contains no lots, nothing to import.',
                'seeded'  => 0,
                'skipped' => 0,
            ];
        }

        $settings         = $this->getSettings();
        $administrationId = ($settings['administration_id'] ?? '');
        if ($administrationId === '') {
            return [
                'success' => true,
                'message' => 'No default administration configured, skipping lot seed.',
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
                // Deduplication key (administrationId, lotNumber) — idempotent re-runs per REQ-LOT-002.
                $existing = $objectService
                    ->setRegister($registerSlug)
                    ->setSchema('InventoryLot')
                    ->findAll(
                        [
                            'filters' => [
                                'administrationId' => $administrationId,
                                'lotNumber'        => ($lot['lotNumber'] ?? ''),
                            ],
                            'limit'   => 1,
                        ]
                    );

                if (empty($existing) === false) {
                    $skipped++;
                    continue;
                }

                $lot['administrationId'] = $administrationId;
                $objectService->saveObject(
                    object: $lot,
                    register: $registerSlug,
                    schema: 'InventoryLot',
                );
                $seeded++;
            }//end foreach

            $this->logger->info(
                'Shillinq: inventory lots seeded',
                ['seeded' => $seeded, 'skipped' => $skipped]
            );

            return [
                'success' => true,
                'message' => 'Inventory lots seeded successfully.',
                'seeded'  => $seeded,
                'skipped' => $skipped,
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: inventory lot seeding failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }//end try

    }//end seedInventoryLots()

    /**
     * Seed example InventoryValuation records from
     * `inventory-valuation-examples.json`, idempotently per administration.
     *
     * Deduplication key per REQ-INV-005 is
     * `(productId, warehouse, status=active, administrationId)`. When an
     * active snapshot for the same tuple already exists the seed entry is
     * skipped, preserving operator edits and preventing duplicate active
     * snapshots from triggering the uniqueness validation.
     *
     * @param string $administrationId Administration scope for the seed.
     *
     * @return array<string,mixed> Result with success/seeded/skipped/message.
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-12
     */
    public function seedInventoryValuationExamples(string $administrationId): array
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
                'message' => 'administrationId is required for InventoryValuation seed.',
            ];
        }

        $seedPath = __DIR__.'/../Settings/seeds/inventory-valuation-examples.json';
        if (file_exists($seedPath) === false) {
            return [
                'success' => false,
                'message' => 'Seed file not found: inventory-valuation-examples.json',
            ];
        }

        $content = file_get_contents($seedPath);
        if ($content === false) {
            return [
                'success' => false,
                'message' => 'Failed to read inventory-valuation-examples.json',
            ];
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'message' => 'Failed to parse inventory-valuation-examples.json: '.json_last_error_msg(),
            ];
        }

        $valuations = ($data['valuations'] ?? []);
        if (empty($valuations) === true) {
            return [
                'success' => true,
                'message' => 'Seed file contains no valuations, nothing to import.',
                'seeded'  => 0,
                'skipped' => 0,
            ];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $registerSlug  = $this->getRegisterSlug();
            $seeded        = 0;
            $skipped       = 0;

            foreach ($valuations as $valuation) {
                $productId = (string) ($valuation['productId'] ?? '');
                $warehouse = (string) ($valuation['warehouse'] ?? '');
                if ($productId === '' || $warehouse === '') {
                    continue;
                }

                // Dedup key (productId, warehouse, status=active, administrationId) per REQ-INV-005.
                $existing = $objectService
                    ->setRegister($registerSlug)
                    ->setSchema('InventoryValuation')
                    ->findAll(
                        [
                            'filters' => [
                                'productId'        => $productId,
                                'warehouse'        => $warehouse,
                                'status'           => 'active',
                                'administrationId' => $administrationId,
                            ],
                            'limit'   => 1,
                        ]
                    );

                if (empty($existing) === false) {
                    $skipped++;
                    continue;
                }

                $valuation['administrationId'] = $administrationId;
                $objectService->saveObject(
                    object: $valuation,
                    register: $registerSlug,
                    schema: 'InventoryValuation',
                );
                $seeded++;
            }//end foreach

            $this->logger->info(
                'Shillinq: inventory valuation examples seeded',
                ['seeded' => $seeded, 'skipped' => $skipped]
            );

            return [
                'success' => true,
                'message' => 'Inventory valuation examples seeded successfully.',
                'seeded'  => $seeded,
                'skipped' => $skipped,
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: inventory valuation example seeding failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }//end try

    }//end seedInventoryValuationExamples()

    /**
     * Seed demo InventoryStock records from the three location seed files,
     * idempotently per administration.
     *
     * Reads `lib/Settings/seeds/stock-amsterdam.json`,
     * `lib/Settings/seeds/stock-rotterdam.json` and
     * `lib/Settings/seeds/stock-utrecht.json` and imports InventoryStock rows
     * via OpenRegister's ObjectService. Deduplication key is
     * `(administrationId, productSku, locationCode)` per REQ-IST-002 +
     * REQ-IST-009 — re-running the repair step never creates duplicates and
     * operator edits to quantities persist across upgrades. Skips silently
     * when no default administration is configured (C2 — prevents "default"
     * contamination of real tenant data).
     *
     * @param string $administrationId Administration scope for the seed.
     *
     * @return array<string, mixed> Result with success flag, seeded count, skipped count, message.
     *
     * @spec openspec/changes/inventory-stock-tracking/tasks.md#task-12
     */
    public function seedInventoryStockExamples(string $administrationId): array
    {
        if ($this->isOpenRegisterAvailable() === false) {
            return [
                'success' => false,
                'message' => 'OpenRegister is not installed or enabled.',
            ];
        }

        if ($administrationId === '') {
            return [
                'success' => true,
                'message' => 'No default administration configured, skipping stock seed.',
                'seeded'  => 0,
                'skipped' => 0,
            ];
        }

        $seedFiles = [
            __DIR__.'/../Settings/seeds/stock-amsterdam.json',
            __DIR__.'/../Settings/seeds/stock-rotterdam.json',
            __DIR__.'/../Settings/seeds/stock-utrecht.json',
        ];

        $allRows = [];
        foreach ($seedFiles as $seedPath) {
            if (file_exists($seedPath) === false) {
                $this->logger->warning(
                    'Shillinq: InventoryStock seed file missing, skipping',
                    ['file' => basename($seedPath)]
                );
                continue;
            }

            $content = file_get_contents($seedPath);
            if ($content === false) {
                $this->logger->warning(
                    'Shillinq: failed to read InventoryStock seed file',
                    ['file' => basename($seedPath)]
                );
                continue;
            }

            $data = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->warning(
                    'Shillinq: failed to parse InventoryStock seed file',
                    [
                        'file'  => basename($seedPath),
                        'error' => json_last_error_msg(),
                    ]
                );
                continue;
            }

            $rows = ($data['stock'] ?? []);
            foreach ($rows as $row) {
                $allRows[] = $row;
            }
        }//end foreach

        if (empty($allRows) === true) {
            return [
                'success' => true,
                'message' => 'No InventoryStock seed rows found.',
                'seeded'  => 0,
                'skipped' => 0,
            ];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $registerSlug  = $this->getRegisterSlug();
            $seeded        = 0;
            $skipped       = 0;

            foreach ($allRows as $row) {
                $productSku   = ($row['productSku'] ?? '');
                $locationCode = ($row['locationCode'] ?? '');
                if ($productSku === '' || $locationCode === '') {
                    continue;
                }

                // Deduplication key (administrationId, productSku, locationCode) — REQ-IST-002.
                $existing = $objectService
                    ->setRegister($registerSlug)
                    ->setSchema('InventoryStock')
                    ->findAll(
                        [
                            'filters' => [
                                'administrationId' => $administrationId,
                                'productSku'       => $productSku,
                                'locationCode'     => $locationCode,
                            ],
                            'limit'   => 1,
                        ]
                    );

                if (empty($existing) === false) {
                    $skipped++;
                    continue;
                }

                $row['administrationId'] = $administrationId;
                $objectService->saveObject(
                    object: $row,
                    register: $registerSlug,
                    schema: 'InventoryStock',
                );
                $seeded++;
            }//end foreach

            $this->logger->info(
                'Shillinq: InventoryStock seeded',
                ['seeded' => $seeded, 'skipped' => $skipped]
            );

            return [
                'success' => true,
                'message' => 'InventoryStock seeded successfully.',
                'seeded'  => $seeded,
                'skipped' => $skipped,
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: InventoryStock seeding failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }//end try

    }//end seedInventoryStockExamples()

    /**
     * Seed the default `InventoryGLConfig` record for an administration
     * (inventory-cogs-posting REQ-CG-001 + Task 11).
     *
     * Loads the RGS 3.5 MKB defaults (7000 Kostprijs omzet / 1400
     * Voorraden / 1800 GR/IR clearing / 7100 Voorraadmutaties) from
     * `seeds/inventory-gl-config-2026.json` and writes one row per
     * administration via ObjectService. Idempotent: deduplicates on
     * administrationId so re-runs preserve operator overrides (the
     * config is administration-scoped — one active row per tenant).
     * Skipped when administrationId is empty (C2 contamination guard).
     *
     * @param string $administrationId Administration scope for the seed.
     *
     * @return array<string,mixed> Result with success flag, seeded + skipped counts, message.
     *
     * @spec openspec/changes/inventory-cogs-posting/tasks.md#task-11
     */
    public function seedInventoryGLConfig(string $administrationId): array
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

        $seedPath = __DIR__.'/../Settings/seeds/inventory-gl-config-2026.json';
        if (file_exists($seedPath) === false) {
            return [
                'success' => false,
                'message' => 'Seed file not found: '.$seedPath,
            ];
        }

        $content = file_get_contents($seedPath);
        if ($content === false) {
            return [
                'success' => false,
                'message' => 'Failed to read seed file.',
            ];
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'message' => 'Failed to parse seed file: '.json_last_error_msg(),
            ];
        }

        $configs = ($data['configs'] ?? []);
        if (empty($configs) === true) {
            return [
                'success' => true,
                'message' => 'No InventoryGLConfig entries in seed file.',
                'seeded'  => 0,
                'skipped' => 0,
            ];
        }

        $seeded  = 0;
        $skipped = 0;

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $registerSlug  = $this->getRegisterSlug();

            foreach ($configs as $config) {
                $existing = $objectService
                    ->setRegister($registerSlug)
                    ->setSchema('InventoryGLConfig')
                    ->findAll(
                        [
                            'filters' => ['administrationId' => $administrationId],
                            'limit'   => 1,
                        ]
                    );

                if (empty($existing) === false) {
                    $skipped++;
                    continue;
                }

                $config['administrationId'] = $administrationId;

                $objectService->saveObject(
                    object: $config,
                    register: $registerSlug,
                    schema: 'InventoryGLConfig',
                );
                $seeded++;
            }//end foreach

            $this->logger->info(
                'Shillinq: InventoryGLConfig seeded',
                [
                    'administrationId' => $administrationId,
                    'seeded'           => $seeded,
                    'skipped'          => $skipped,
                ]
            );

            return [
                'success' => true,
                'message' => 'InventoryGLConfig seeded successfully.',
                'seeded'  => $seeded,
                'skipped' => $skipped,
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: InventoryGLConfig seeding failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }//end try

    }//end seedInventoryGLConfig()

    /**
     * Seed the demo BBVProgramme records from
     * bbv-waterschappen-programmes-2026-demo.json, idempotently per administration.
     *
     * Loads the 5 demo programmes (1.1.1, 1.2.1, 2.3.2, 2.4.1, 3.1.0) for fiscal
     * year 2026 documented in the bookkeeping-waterschappen-bbv-variant-01-config
     * -schemas-seed design and writes them to the BBVProgramme schema via
     * ObjectService. Deduplication key is
     * (administrationId, programmeCode, fiscalYear) per REQ-BBVW-001 so re-runs
     * never create duplicates and operator edits persist across upgrades.
     * Skipped when administration_id is not configured (C2 — prevents "default"
     * contamination of real tenant data).
     *
     * @param string $administrationId Administration scope for the seed.
     *
     * @return array<string, mixed> Result with success flag, seeded + skipped counts, message.
     *
     * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-01-config-schemas-seed/tasks.md#seed-data
     */
    public function seedBbvProgrammes(string $administrationId): array
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
                'message' => 'administrationId is required for BBVProgramme seed.',
            ];
        }

        $seedPath = __DIR__.'/../Settings/seeds/bbv-waterschappen-programmes-2026-demo.json';
        if (file_exists($seedPath) === false) {
            return [
                'success' => false,
                'message' => 'Seed file not found: bbv-waterschappen-programmes-2026-demo.json',
            ];
        }

        $content = file_get_contents($seedPath);
        if ($content === false) {
            return [
                'success' => false,
                'message' => 'Failed to read bbv-waterschappen-programmes-2026-demo.json',
            ];
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'message' => 'Failed to parse bbv-waterschappen-programmes-2026-demo.json: '.json_last_error_msg(),
            ];
        }

        $programmes = ($data['programmes'] ?? []);
        if (empty($programmes) === true) {
            return [
                'success' => true,
                'message' => 'Seed file contains no programmes, nothing to import.',
                'seeded'  => 0,
                'skipped' => 0,
            ];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $registerSlug  = $this->getRegisterSlug();
            $seeded        = 0;
            $skipped       = 0;

            foreach ($programmes as $programme) {
                $programmeCode = (string) ($programme['programmeCode'] ?? '');
                $fiscalYear    = ($programme['fiscalYear'] ?? null);
                if ($programmeCode === '' || $fiscalYear === null) {
                    continue;
                }

                // Dedup key (administrationId, programmeCode, fiscalYear) per REQ-BBVW-001.
                $existing = $objectService
                    ->setRegister($registerSlug)
                    ->setSchema('BBVProgramme')
                    ->findAll(
                        [
                            'filters' => [
                                'administrationId' => $administrationId,
                                'programmeCode'    => $programmeCode,
                                'fiscalYear'       => $fiscalYear,
                            ],
                            'limit'   => 1,
                        ]
                    );

                if (empty($existing) === false) {
                    $skipped++;
                    continue;
                }

                $programme['administrationId'] = $administrationId;
                $objectService->saveObject(
                    object: $programme,
                    register: $registerSlug,
                    schema: 'BBVProgramme',
                );
                $seeded++;
            }//end foreach

            $this->logger->info(
                'Shillinq: BBVProgramme demo records seeded',
                ['seeded' => $seeded, 'skipped' => $skipped]
            );

            return [
                'success' => true,
                'message' => 'BBVProgramme demo records seeded successfully.',
                'seeded'  => $seeded,
                'skipped' => $skipped,
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: BBVProgramme demo seeding failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }//end try

    }//end seedBbvProgrammes()

    /**
     * Seed the demo BudgetBBVMapping records from
     * bbv-waterschappen-programmes-2026-demo.json, idempotently per administration.
     *
     * Loads the demo allocation split documented in the design: GL 4100 →
     * 50/30/20 across programmes 1.1.1/1.2.1/2.4.1 and GL 5000 → 25/75 across
     * 2.3.2/2.4.1, all effective for fiscal 2026. Deduplication key is
     * (administrationId, glAccountNumber, programmeCode, effectiveFrom) per
     * REQ-BBVW-002 so re-runs never create duplicates and operator edits
     * persist across upgrades. Skipped when administration_id is not configured
     * (C2 — prevents "default" contamination of real tenant data).
     *
     * @param string $administrationId Administration scope for the seed.
     *
     * @return array<string, mixed> Result with success flag, seeded + skipped counts, message.
     *
     * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-01-config-schemas-seed/tasks.md#seed-data
     */
    public function seedBudgetBbvMappings(string $administrationId): array
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
                'message' => 'administrationId is required for BudgetBBVMapping seed.',
            ];
        }

        $seedPath = __DIR__.'/../Settings/seeds/bbv-waterschappen-programmes-2026-demo.json';
        if (file_exists($seedPath) === false) {
            return [
                'success' => false,
                'message' => 'Seed file not found: bbv-waterschappen-programmes-2026-demo.json',
            ];
        }

        $content = file_get_contents($seedPath);
        if ($content === false) {
            return [
                'success' => false,
                'message' => 'Failed to read bbv-waterschappen-programmes-2026-demo.json',
            ];
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'message' => 'Failed to parse bbv-waterschappen-programmes-2026-demo.json: '.json_last_error_msg(),
            ];
        }

        $mappings = ($data['mappings'] ?? []);
        if (empty($mappings) === true) {
            return [
                'success' => true,
                'message' => 'Seed file contains no mappings, nothing to import.',
                'seeded'  => 0,
                'skipped' => 0,
            ];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $registerSlug  = $this->getRegisterSlug();
            $seeded        = 0;
            $skipped       = 0;

            foreach ($mappings as $mapping) {
                $glAccountNumber = (string) ($mapping['glAccountNumber'] ?? '');
                $programmeCode   = (string) ($mapping['programmeCode'] ?? '');
                $effectiveFrom   = (string) ($mapping['effectiveFrom'] ?? '');
                if ($glAccountNumber === '' || $programmeCode === '' || $effectiveFrom === '') {
                    continue;
                }

                // Dedup key (administrationId, glAccountNumber, programmeCode, effectiveFrom) per REQ-BBVW-002.
                $existing = $objectService
                    ->setRegister($registerSlug)
                    ->setSchema('BudgetBBVMapping')
                    ->findAll(
                        [
                            'filters' => [
                                'administrationId' => $administrationId,
                                'glAccountNumber'  => $glAccountNumber,
                                'programmeCode'    => $programmeCode,
                                'effectiveFrom'    => $effectiveFrom,
                            ],
                            'limit'   => 1,
                        ]
                    );

                if (empty($existing) === false) {
                    $skipped++;
                    continue;
                }

                $mapping['administrationId'] = $administrationId;
                $objectService->saveObject(
                    object: $mapping,
                    register: $registerSlug,
                    schema: 'BudgetBBVMapping',
                );
                $seeded++;
            }//end foreach

            $this->logger->info(
                'Shillinq: BudgetBBVMapping demo records seeded',
                ['seeded' => $seeded, 'skipped' => $skipped]
            );

            return [
                'success' => true,
                'message' => 'BudgetBBVMapping demo records seeded successfully.',
                'seeded'  => $seeded,
                'skipped' => $skipped,
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: BudgetBBVMapping demo seeding failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }//end try

    }//end seedBudgetBbvMappings()

    /**
     * Seed the default Archiefwet retention policies, idempotently.
     *
     * Reads lib/Settings/seeds/retention-policies.json and imports the three
     * organization-wide default RetentionPolicy records (financial 5yr, tax 7yr,
     * general 3yr) per REQ-RET-012. Records are matched by `slug` and skipped when
     * already present, preserving operator edits across upgrades. Unlike the chart
     * of accounts these policies are organization-wide (no administrationId), so no
     * tenant identifier is required.
     *
     * @return array<string,mixed> Result with success flag, seeded count, skipped count.
     *
     * @spec openspec/changes/bookkeeping-archiefwet-retention/tasks.md (Task 13, REQ-RET-012)
     */
    public function seedRetentionPolicies(): array
    {
        if ($this->isOpenRegisterAvailable() === false) {
            return [
                'success' => false,
                'message' => 'OpenRegister is not installed or enabled.',
            ];
        }

        $seedPath = __DIR__.'/../Settings/seeds/retention-policies.json';
        if (file_exists($seedPath) === false) {
            return ['success' => false, 'message' => 'Retention policy seed file not found.'];
        }

        $content = file_get_contents($seedPath);
        if ($content === false) {
            return ['success' => false, 'message' => 'Failed to read retention policy seed file.'];
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'message' => 'Failed to parse retention policy seed file: '.json_last_error_msg()];
        }

        $policies = ($data['policies'] ?? []);
        if (empty($policies) === true) {
            return ['success' => false, 'message' => 'Retention policy seed file contains no policies.'];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $registerSlug  = $this->getRegisterSlug();
            $seeded        = 0;
            $skipped       = 0;

            foreach ($policies as $policy) {
                $slug     = ($policy['slug'] ?? '');
                $existing = $objectService
                    ->setRegister($registerSlug)
                    ->setSchema('RetentionPolicy')
                    ->findAll(
                        [
                            'filters' => ['slug' => $slug],
                            'limit'   => 1,
                        ]
                    );

                if (empty($existing) === false) {
                    $skipped++;
                    continue;
                }

                $objectService->saveObject(
                    object: $policy,
                    register: $registerSlug,
                    schema: 'RetentionPolicy',
                );
                $seeded++;
            }//end foreach

            $this->logger->info(
                'Shillinq: retention policies seeded',
                ['seeded' => $seeded, 'skipped' => $skipped]
            );

            return [
                'success' => true,
                'message' => 'Retention policies seeded successfully.',
                'seeded'  => $seeded,
                'skipped' => $skipped,
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: retention policy seeding failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }//end try

    }//end seedRetentionPolicies()

    /**
     * Seed mandaat (signing-authority) templates from mandaat-templates.json, idempotently.
     *
     * Reads lib/Settings/seeds/mandaat-templates.json and imports Mandaat records
     * via OpenRegister's ObjectService, stamping the tenant administrationId.
     * Already-existing records (matched by mandaatcode + administrationId) are
     * skipped, preserving operator edits. Per REQ-VPL-002. Requires a non-empty
     * administrationId (C2).
     *
     * @param string $administrationId The administrationId to stamp on seeded records.
     *
     * @return array<string,mixed> Result with success flag, seeded count, skipped count.
     *
     * @spec openspec/changes/bookkeeping-verplichtingenadministratie/tasks.md#task-1.7
     */
    public function seedMandaatTemplates(string $administrationId): array
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

        $seedPath = __DIR__.'/../Settings/seeds/mandaat-templates.json';
        if (file_exists($seedPath) === false) {
            return ['success' => false, 'message' => 'Seed file not found: mandaat-templates.json'];
        }

        $content = file_get_contents($seedPath);
        if ($content === false) {
            return ['success' => false, 'message' => 'Failed to read mandaat-templates.json'];
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'message' => 'Failed to parse mandaat-templates.json: '.json_last_error_msg()];
        }

        $templates = ($data['mandaatTemplates'] ?? []);
        if (empty($templates) === true) {
            return ['success' => false, 'message' => 'Seed file contains no mandaatTemplates.'];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $registerSlug  = $this->getRegisterSlug();
            $seeded        = 0;
            $skipped       = 0;

            foreach ($templates as $template) {
                // _meta-only fields that are not Mandaat properties are dropped.
                unset($template['doelgroep']);
                $template['administrationId'] = $administrationId;

                $existing = $objectService
                    ->setRegister($registerSlug)
                    ->setSchema('Mandaat')
                    ->findAll(
                        [
                            'filters' => [
                                'mandaatcode'      => $template['mandaatcode'],
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
                    object: $template,
                    register: $registerSlug,
                    schema: 'Mandaat',
                );
                $seeded++;
            }//end foreach

            $this->logger->info(
                'Shillinq: mandaat templates seeded',
                [
                    'seeded'  => $seeded,
                    'skipped' => $skipped,
                ]
            );

            return [
                'success' => true,
                'message' => 'Mandaat templates seeded successfully.',
                'seeded'  => $seeded,
                'skipped' => $skipped,
            ];
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: mandaat template seeding failed',
                ['exception' => $e->getMessage()]
            );
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }//end try

    }//end seedMandaatTemplates()

    /**
     * Import the three RJ 270 statement presentation manifests, idempotently.
     *
     * Reads rj270-balance-sheet.json, rj270-pl.json, and rj270-cash-flow.json from
     * lib/Settings/statements/ and persists each into app config under a per-statement
     * key (statement_manifest_<type>) only when the key is not already set. This makes
     * the import idempotent and preserves operator edits across repair re-runs per
     * REQ-FS-002: a manifest that the operator has customised is never re-overwritten.
     *
     * Financial-statement assembly itself is declarative (trial-balance aggregations
     * composed against these manifests per ADR-031); this method ships only the
     * presentation templates, no report-builder logic.
     *
     * @return array<string,mixed> Result with success flag, imported count, skipped count.
     *
     * @spec openspec/changes/add-shillinq-bookkeeping-compliance/tasks.md#task-3.4
     */
    public function seedStatementManifests(): array
    {
        $files = [
            'balance-sheet' => 'rj270-balance-sheet.json',
            'profit-loss'   => 'rj270-pl.json',
            'cash-flow'     => 'rj270-cash-flow.json',
        ];

        $imported = 0;
        $skipped  = 0;

        foreach ($files as $type => $fileName) {
            $configKey = 'statement_manifest_'.$type;

            // Preserve operator edits: skip when the manifest is already persisted.
            $existing = $this->appConfig->getValueString(Application::APP_ID, $configKey, '');
            if ($existing !== '') {
                $skipped++;
                continue;
            }

            $manifestPath = __DIR__.'/../Settings/statements/'.$fileName;
            if (file_exists($manifestPath) === false) {
                $this->logger->warning('Shillinq: statement manifest not found: '.$fileName);
                continue;
            }

            $content = file_get_contents($manifestPath);
            if ($content === false) {
                $this->logger->warning('Shillinq: failed to read statement manifest: '.$fileName);
                continue;
            }

            $decoded = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->warning(
                    'Shillinq: failed to parse statement manifest '.$fileName.': '.json_last_error_msg()
                );
                continue;
            }

            // Stamp the import timestamp so a future presentation-standard migration
            // (BBV — T3; IFRS full — T5) can tell template-sourced from operator-authored.
            if (isset($decoded['_meta']) === true && is_array($decoded['_meta']) === true) {
                $decoded['_meta']['imported'] = gmdate('Y-m-d\TH:i:s\Z');
            }

            $this->appConfig->setValueString(
                Application::APP_ID,
                $configKey,
                (string) json_encode($decoded)
            );
            $imported++;
        }//end foreach

        $this->logger->info(
            'Shillinq: statement manifests imported',
            ['imported' => $imported, 'skipped' => $skipped]
        );

        return [
            'success'  => true,
            'message'  => 'Statement manifests imported.',
            'imported' => $imported,
            'skipped'  => $skipped,
        ];

    }//end seedStatementManifests()
}//end class
