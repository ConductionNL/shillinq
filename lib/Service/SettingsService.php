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

use InvalidArgumentException;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Shillinq\Service\Seed\SeedImport;
use OCA\Shillinq\Service\Seed\SeedImporter;
use OCA\Shillinq\Service\Seed\SeedMessages;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing Shillinq application configuration and settings.
 *
 * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ElseExpression)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * Pre-existing debt (issue #506): this service owns every app-config
 * getter/setter/seed operation the app exposes, so its size scales with
 * the app's configuration surface; splitting is out of scope for a
 * mechanical phpcs/phpmd cleanup. Deferred to a follow-up.
 */
class SettingsService implements SettingsServiceInterface {

	/**
	 * Configuration keys managed by this service.
	 *
	 * @var array<string>
	 */
	private const CONFIG_KEYS = [
		'register',
		'rgs_template',
		'administration_id',
		'legal_region',
		self::PORTAL_REDIRECT_KEY,
	];

	/**
	 * Where the portal checkout sends the payer back to. Read by
	 * PortalPaymentSessionService; empty falls back to the instance root
	 * (REQ-SPPI-010).
	 *
	 * @var string
	 */
	public const PORTAL_REDIRECT_KEY = 'portal_payment_redirect_url';

	/**
	 * The single-schema seed files: file, items key, deduplication key,
	 * schema and log label.
	 *
	 * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
	 */
	private const GENERIC_SEEDS = [
		'rj270' => ['rj-270-stages.json', 'stages', 'stageId', 'RJ270Stage', 'RJ-270 stages'],
		'defaultAdministration' => ['administraties/default.json', 'administrations', 'administrationCode', 'Administration', 'Default administration'],
		'btw' => ['btw-tariffs-2026.json', 'tariffs', 'code', 'VatTariff', 'BTW tariffs'],
		'taakvelden' => ['bbv-task_fields-2024.json', 'taskFields', 'code', 'BbvTaakveld', 'BBV task_fields'],
	];

	/**
	 * Constructor for the SettingsService.
	 *
	 * @param IAppConfig $appConfig The app config interface
	 * @param RegisterLocation $registerLocation Where Shillinq's register lives
	 * @param ContainerInterface $container The container
	 * @param IGroupManager $groupManager The group manager
	 * @param IUserSession $userSession The user session
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private RegisterLocation $registerLocation,
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
	public function isOpenRegisterAvailable(): bool {
		return $this->registerLocation->isOpenRegisterAvailable();
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
	public function getSettings(): array {
		$settings = [];
		foreach (self::CONFIG_KEYS as $key) {
			$settings[$key] = $this->appConfig->getValueString(AppIdentity::APP_ID, $key, '');
		}

		$user = $this->userSession->getUser();
		$isAdmin = ($user !== null && $this->groupManager->isAdmin($user->getUID()));

		return array_merge(
			$settings,
			[
				'openregisters' => $this->isOpenRegisterAvailable(),
				'isAdmin' => $isAdmin,
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
	 * @throws InvalidArgumentException When the portal return address is not an absolute https address.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md#task-1
	 * @spec openspec/changes/portal-pay-row-action-keys/specs/portal-payment-initiation/spec.md (REQ-SPPI-010)
	 */
	public function updateSettings(array $data): array {
		// Checked before anything is written, so a refused save stores nothing.
		if (isset($data[self::PORTAL_REDIRECT_KEY]) === true) {
			$this->assertPortalRedirect(value: (string)$data[self::PORTAL_REDIRECT_KEY]);
		}

		foreach (self::CONFIG_KEYS as $key) {
			if (isset($data[$key]) === true) {
				$this->appConfig->setValueString(AppIdentity::APP_ID, $key, (string)$data[$key]);
			}
		}

		return $this->getSettings();
	}//end updateSettings()

	/**
	 * Refuse a portal return address that is neither empty nor an absolute
	 * https address, so the checkout never sends a payer to plain http, a
	 * script URL or a relative path.
	 *
	 * @param string $value The submitted address.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the address is not acceptable.
	 *
	 * @spec openspec/changes/portal-pay-row-action-keys/specs/portal-payment-initiation/spec.md (REQ-SPPI-010)
	 */
	private function assertPortalRedirect(string $value): void {
		$value = trim($value);
		if ($value === '') {
			return;
		}

		$scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
		$host = (string)parse_url($value, PHP_URL_HOST);
		if (filter_var($value, FILTER_VALIDATE_URL) === false || $scheme !== 'https' || $host === '') {
			throw new InvalidArgumentException('The portal return address must be an absolute https address.');
		}
	}//end assertPortalRedirect()

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
	public function getRegisterSlug(): string {
		return $this->registerLocation->getRegisterSlug();
	}//end getRegisterSlug()

	/**
	 * Seed the chart of accounts from an RGS template file, idempotently.
	 *
	 * Reads the selected template from lib/Settings/seeds/ and imports Account
	 * records via OpenRegister's ObjectService. Already-existing records (matched
	 * by accountNumber + administrationId) are skipped, preserving operator edits.
	 *
	 * @param string $templateVariant Template to seed: 'mkb', 'zzp', or 'bbv'.
	 * @param string $administrationId The administrationId to stamp on seeded records.
	 *                                 Must be a non-empty tenant-specific identifier;
	 *                                 callers must always supply an explicit value (C2).
	 *
	 * @return array<string,mixed> Result with success flag, seeded count, skipped count.
	 *
	 * @spec openspec/changes/spec/tasks.md#task-11
	 */
	public function seedRgsTemplate(string $templateVariant, string $administrationId): array {
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

		return $this->seedImporter()->import(
			import: new SeedImport(
				schema: 'Account',
				rows: $accountsResult['accounts'],
				// Deduplication key (accountNumber, administrationId): accounts seeded under an
				// earlier administrationId stay in OpenRegister and are not matched (M3).
				match: static fn ($account): array => [
					'accountNumber' => $account['accountNumber'],
					'administrationId' => $administrationId,
				],
				administrationId: $administrationId,
			),
			messages: new SeedMessages(
				seededLog: 'Shillinq: RGS template seeded',
				seededMessage: 'RGS template seeded successfully.',
				failedLog: 'Shillinq: RGS template seeding failed',
				logContext: ['variant' => $templateVariant],
			)
		);
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
	private function loadSeedAccounts(string $templateVariant): array {
		$fileMap = [
			'mkb' => 'rgs-3.5-mkb.json',
			'zzp' => 'rgs-3.5-zzp.json',
			'bbv' => 'rgs-bbv.json',
		];

		if (isset($fileMap[$templateVariant]) === false) {
			return ['error' => ['success' => false, 'message' => 'Unknown RGS template variant: ' . $templateVariant]];
		}

		$seedPath = __DIR__ . '/../Settings/seeds/' . $fileMap[$templateVariant];
		if (file_exists($seedPath) === false) {
			$this->logger->error('Shillinq: seed file not found at ' . $seedPath);
			return ['error' => ['success' => false, 'message' => 'Seed file not found: ' . $fileMap[$templateVariant]]];
		}

		$content = file_get_contents($seedPath);
		if ($content === false) {
			return ['error' => ['success' => false, 'message' => 'Failed to read seed file.']];
		}

		$data = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return ['error' => ['success' => false, 'message' => 'Failed to parse seed file: ' . json_last_error_msg()]];
		}

		$accounts = ($data['accounts'] ?? []);
		if (empty($accounts) === true) {
			return ['error' => ['success' => false, 'message' => 'Seed file contains no accounts.']];
		}

		return ['accounts' => $accounts];
	}//end loadSeedAccounts()

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
	public function seedRj270Stages(): array {
		return $this->seedGenericFile(seed: 'rj270');

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
	 * The result carries `administrationCode` (read from the seed file, not
	 * guessed) alongside `success`/`seeded`/`skipped` so callers — notably
	 * SetupController's `init-administration` action — can persist the real
	 * identifier of the created/existing record as `administration_id`
	 * instead of hardcoding it.
	 *
	 * @return array<string,mixed> Result with success flag, seeded count, skipped
	 *                             count, and administrationCode.
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-14
	 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	 */
	public function seedDefaultAdministration(): array {
		$result = $this->seedGenericFile(seed: 'defaultAdministration');

		if (($result['success'] ?? false) === true) {
			$result['administrationCode'] = $this->readDefaultAdministrationCode();
			$result['membership'] = $this->grantDefaultAdministrationMembership(
				administrationCode: $result['administrationCode']
			);
		}

		return $result;
	}//end seedDefaultAdministration()

	/**
	 * Grant the operator running first-time setup a membership on an administration.
	 *
	 * WHY THIS EXISTS (shillinq#569). Seeding an Administration record is not
	 * enough to make it usable: access is granted by an AdministrationMembership
	 * (REQ-MA-003), and NOTHING in the codebase created one. So every fresh
	 * install finished setup with `completed: true` and
	 * `GET /api/administrations/context` returning `administrations: []` — for
	 * every user, on every install — leaving every administration-scoped surface
	 * unreachable with no error anywhere.
	 *
	 * Role `eigenaar` with posting and closing rights: the caller has already
	 * passed `AuthorizedAdminSetting`, and an operator who provisions the default
	 * administration is its owner. Only the CALLING user is granted — this is not
	 * a broadcast to every account.
	 *
	 * ⚠️ The membership is written against the **administrationCode**, NOT the
	 * OpenRegister uuid, even though the uuid is the more "correct" identifier.
	 * `buildContext()` echoes this value back as `activeAdministrationId`, and
	 * consumers downstream already run on the code: the `administration_id`
	 * app-config holds `ADM-001` and ~20 call sites scope on it, and
	 * `tests/e2e/ci-seed.sh` stamps its booking fixtures with
	 * `activeAdministrationId`, falling back to the literal `ADM-001` when the
	 * context is empty. Writing the uuid here would silently switch that value
	 * from a code to a uuid and strand every fixture the seed created.
	 *
	 * `findAdministration()` resolves either id space, so this is a choice about
	 * which one the rest of the system sees — and the answer is the one it
	 * already uses.
	 *
	 * Idempotent: a membership for the same (userId, administrationId) is left
	 * alone, so re-running setup never duplicates or downgrades an existing grant.
	 * Never throws — a failure here is reported in the result and logged, and must
	 * not turn a successful seed into a failed setup action.
	 *
	 * @param string $administrationCode The administrationCode of the seeded administration.
	 *
	 * @return array<string,mixed> `{ granted: bool, reason?: string, administrationId?: string }`.
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-14
	 */
	private function grantDefaultAdministrationMembership(string $administrationCode): array {
		if ($administrationCode === '') {
			return ['granted' => false, 'reason' => 'no administrationCode'];
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return ['granted' => false, 'reason' => 'no authenticated user'];
		}

		$userId = $user->getUID();

		try {
			$refusal = $this->saveDefaultAdministrationMembership(userId: $userId, administrationCode: $administrationCode);
		} catch (\Throwable $e) {
			$this->logger->error(
				'SettingsService: failed to grant default administration membership',
				['userId' => $userId, 'administrationCode' => $administrationCode, 'exception' => $e->getMessage()]
			);
			return ['granted' => false, 'reason' => 'exception: ' . $e->getMessage()];
		}//end try

		if ($refusal !== null) {
			return $refusal;
		}

		$administrationId = $administrationCode;

		$this->logger->info(
			'SettingsService: granted default administration membership',
			['userId' => $userId, 'administrationId' => $administrationId]
		);

		return ['granted' => true, 'administrationId' => $administrationId];
	}//end grantDefaultAdministrationMembership()

	/**
	 * Save the owner membership of the default administration, unless it is missing or already granted.
	 *
	 * @param string $userId             The user to grant the membership to.
	 * @param string $administrationCode The administrationCode of the seeded administration.
	 *
	 * @return array<string,mixed>|null The refusal result, or null when the membership was saved.
	 *
	 * @throws \Throwable When OpenRegister lookups or saves fail.
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-14
	 */
	private function saveDefaultAdministrationMembership(string $userId, string $administrationCode): array|null {
		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		$registerSlug = $this->getRegisterSlug();

		// Confirm the administration exists before granting anything, by the
		// administrationCode — a real JSON property, and therefore filterable.
		// Filtering on `id` here would match nothing (see findAdministration()).
		$administrations = $this->findOneRecord(
			objectService: $objectService,
			registerSlug: $registerSlug,
			schema: 'Administration',
			filters: ['administrationCode' => $administrationCode]
		);

		if (empty($administrations) === true) {
			return ['granted' => false, 'reason' => 'administration not found: ' . $administrationCode];
		}

		// The code is the id space the rest of the system runs on — see the
		// docblock. Deliberately not the uuid.
		$administrationId = $administrationCode;

		$filters = ['userId' => $userId, 'administrationId' => $administrationId];
		$existing = $this->findOneRecord(objectService: $objectService, registerSlug: $registerSlug, schema: 'AdministrationMembership', filters: $filters);

		if (empty($existing) === false) {
			return ['granted' => false, 'reason' => 'already a member', 'administrationId' => $administrationId];
		}

		$this->saveOwnerMembership(objectService: $objectService, registerSlug: $registerSlug, userId: $userId, administrationId: $administrationId);

		return null;
	}//end saveDefaultAdministrationMembership()

	/**
	 * Save an owner (`eigenaar`) AdministrationMembership valid from today.
	 *
	 * @param mixed  $objectService    OpenRegister's ObjectService.
	 * @param string $registerSlug     The register to write to.
	 * @param string $userId           The member, also recorded as the granter.
	 * @param string $administrationId The administration the membership is for.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-14
	 */
	private function saveOwnerMembership(mixed $objectService, string $registerSlug, string $userId, string $administrationId): void {
		$objectService->saveObject(
			object: [
				'userId' => $userId,
				'administrationId' => $administrationId,
				'role' => 'eigenaar',
				'mayPostJournalEntries' => true,
				'mayCloseFiscalYear' => true,
				'validFrom' => date('Y-m-d'),
				'validUntil' => null,
				'grantedBy' => $userId,
			],
			register: $registerSlug,
			schema: 'AdministrationMembership',
		);
	}//end saveOwnerMembership()

	/**
	 * Read the `administrationCode` of the bundled default-administration seed.
	 *
	 * Single source of truth for the identifier `seedDefaultAdministration()`
	 * creates/finds, so callers never need to hardcode it.
	 *
	 * @return string The administrationCode, or '' if the seed file is missing/invalid.
	 */
	private function readDefaultAdministrationCode(): string {
		$seedPath = __DIR__ . '/../Settings/seeds/administraties/default.json';
		if (file_exists($seedPath) === false) {
			return '';
		}

		$content = file_get_contents($seedPath);
		if ($content === false) {
			return '';
		}

		$data = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return '';
		}

		return (string)($data['administrations'][0]['administrationCode'] ?? '');
	}//end readDefaultAdministrationCode()

	/**
	 * Seed statutory BTW tariffs from btw-tariffs-2026.json, idempotently.
	 *
	 * Imports the current Dutch VAT rates into the VatTariff schema. Deduplication
	 * key is code. Idempotent on re-run; operator-added rates are preserved.
	 *
	 * @return array<string,mixed> Result with success flag, seeded count, skipped count.
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	public function seedBtwTariffs(): array {
		return $this->seedGenericFile(seed: 'btw');

	}//end seedBtwTariffs()

	/**
	 * Seed the BBV task_field catalogue from bbv-task_fields-2024.json, idempotently.
	 *
	 * Imports the canonical Besluit BBV bijlage IV task_fields into the BbvTaakveld
	 * schema. Deduplication key is code. Idempotent on re-run.
	 *
	 * @return array<string,mixed> Result with success flag, seeded count, skipped count.
	 *
	 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md
	 */
	public function seedBbvTaakvelden(): array {
		return $this->seedGenericFile(seed: 'taakvelden');

	}//end seedBbvTaakvelden()

	/**
	 * Seed FixedAsset + DepreciationSchedule demo records, idempotently (REQ-FA-001..010).
	 *
	 * Reads `lib/Settings/seeds/fixed-assets-demo.json` and imports four
	 * realistic Dutch SMB scenarios (company vehicle, office building,
	 * computer equipment, retired asset) together with their 2026
	 * DepreciationSchedule records. Each FixedAsset carries both the
	 * canonical fields (assetCategory / usefulLifeMonths / lifecycleState)
	 * and the REQ-FA-002 operator-facing aliases (assetType / usefulLifeYears
	 * / status). Deduplication key for both schemas is `assetNumber` /
	 * `scheduleNumber` + `administrationId`; idempotent on re-run.
	 *
	 * @param string $administrationId The administrationId to stamp on seeded records.
	 *
	 * @return array<string,mixed> Result with success flag, seeded count, skipped count.
	 *
	 * @spec openspec/changes/bookkeeping-fixed-assets-depreciation/tasks.md#task-15
	 */
	public function seedFixedAssetsDemo(string $administrationId): array {
		if ($this->isOpenRegisterAvailable() === false) {
			return ['success' => false, 'message' => 'OpenRegister is not installed or enabled.'];
		}

		if ($administrationId === '') {
			return ['success' => false, 'message' => 'administrationId must not be empty.'];
		}

		$seedResult = $this->loadFixedAssetsDemoSeed();
		if (isset($seedResult['error']) === true) {
			return $seedResult['error'];
		}

		$data = $seedResult['data'];

		$fixedAssets = ($data['fixedAssets'] ?? []);
		$depreciationSchedules = ($data['depreciationSchedules'] ?? []);

		try {
			return $this->importFixedAssetsDemo(administrationId: $administrationId, fixedAssets: $fixedAssets, schedules: $depreciationSchedules);
		} catch (\Throwable $e) {
			$this->logger->error('Shillinq: fixed-assets demo seeding failed', ['exception' => $e->getMessage()]);
			return ['success' => false, 'message' => $e->getMessage()];
		}//end try

	}//end seedFixedAssetsDemo()

	/**
	 * Read and decode `lib/Settings/seeds/fixed-assets-demo.json`.
	 *
	 * Returns `['data' => mixed]` on success, or `['error' => array]` with a
	 * failure result array when the file is missing, unreadable or invalid JSON.
	 *
	 * @return array{data: mixed}|array{error: array<string,mixed>}
	 *
	 * @spec openspec/changes/bookkeeping-fixed-assets-depreciation/tasks.md#task-15
	 */
	private function loadFixedAssetsDemoSeed(): array {
		$seedPath = __DIR__ . '/../Settings/seeds/fixed-assets-demo.json';
		if (file_exists($seedPath) === false) {
			return ['error' => ['success' => false, 'message' => 'Seed file not found: fixed-assets-demo.json']];
		}

		$content = file_get_contents($seedPath);
		if ($content === false) {
			return ['error' => ['success' => false, 'message' => 'Failed to read fixed-assets-demo.json']];
		}

		$data = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return [
				'error' => [
					'success' => false,
					'message' => 'Failed to parse fixed-assets-demo.json: ' . json_last_error_msg(),
				],
			];
		}

		return ['data' => $data];
	}//end loadFixedAssetsDemoSeed()

	/**
	 * Seed the demo FixedAssets and their DepreciationSchedules, then log and report the counts.
	 *
	 * @param string       $administrationId The administrationId to stamp on seeded records.
	 * @param array<mixed> $fixedAssets      FixedAsset rows from the seed file.
	 * @param array<mixed> $schedules        DepreciationSchedule rows from the seed file.
	 *
	 * @return array<string,mixed> Result with success flag and seeded/skipped counts.
	 *
	 * @throws \Throwable When OpenRegister lookups or saves fail.
	 *
	 * @spec openspec/changes/bookkeeping-fixed-assets-depreciation/tasks.md#task-15
	 */
	private function importFixedAssetsDemo(string $administrationId, array $fixedAssets, array $schedules): array {
		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		$registerSlug = $this->getRegisterSlug();
		$assets = $this->seedDemoFixedAssets(
			objectService: $objectService,
			registerSlug: $registerSlug,
			fixedAssets: $fixedAssets,
			administrationId: $administrationId
		);
		$seededSchedules = 0;
		$skippedSchedules = 0;

		foreach ($schedules as $schedule) {
			$schedule['administrationId'] = $administrationId;
			$schedule = $this->linkDemoScheduleToAsset(schedule: $schedule, assetUuidByNumber: $assets['uuids']);

			if ($this->seedDemoSchedule(objectService: $objectService, registerSlug: $registerSlug, schedule: $schedule) === false) {
				$skippedSchedules++;
				continue;
			}

			$seededSchedules++;
		}

		return $this->reportFixedAssetsDemo(
			counts: [
				'seededAssets' => $assets['seeded'],
				'skippedAssets' => $assets['skipped'],
				'seededSchedules' => $seededSchedules,
				'skippedSchedules' => $skippedSchedules,
			]
		);
	}//end importFixedAssetsDemo()

	/**
	 * Seed every demo FixedAsset and collect the asset UUIDs by assetNumber.
	 *
	 * @param mixed        $objectService    OpenRegister's ObjectService.
	 * @param string       $registerSlug     The register to write to.
	 * @param array<mixed> $fixedAssets      FixedAsset rows from the seed file.
	 * @param string       $administrationId The administrationId to stamp on seeded records.
	 *
	 * @return array{seeded: int, skipped: int, uuids: array<string,string>}
	 *
	 * @throws \Throwable When OpenRegister lookups or saves fail.
	 *
	 * @spec openspec/changes/bookkeeping-fixed-assets-depreciation/tasks.md#task-15
	 */
	private function seedDemoFixedAssets(mixed $objectService, string $registerSlug, array $fixedAssets, string $administrationId): array {
		$result = ['seeded' => 0, 'skipped' => 0, 'uuids' => []];

		foreach ($fixedAssets as $asset) {
			$asset['administrationId'] = $administrationId;

			$outcome = $this->seedDemoFixedAsset(objectService: $objectService, registerSlug: $registerSlug, asset: $asset);
			if ($outcome['seeded'] === true) {
				$result['seeded']++;
			} else {
				$result['skipped']++;
			}

			if ($outcome['uuid'] !== '') {
				$result['uuids'][$asset['assetNumber']] = $outcome['uuid'];
			}
		}

		return $result;
	}//end seedDemoFixedAssets()

	/**
	 * Resolve a demo schedule's assetNumber to the assetRef UUID and drop the assetNumber.
	 *
	 * @param array<string,mixed>  $schedule          The schedule row.
	 * @param array<string,string> $assetUuidByNumber Asset UUIDs keyed by assetNumber.
	 *
	 * @return array<string,mixed> The schedule row with assetRef set when resolvable.
	 *
	 * @spec openspec/changes/bookkeeping-fixed-assets-depreciation/tasks.md#task-15
	 */
	private function linkDemoScheduleToAsset(array $schedule, array $assetUuidByNumber): array {
		// Resolve assetNumber → assetRef UUID.
		$assetNumber = (string)($schedule['assetNumber'] ?? '');
		if ($assetNumber !== '' && isset($assetUuidByNumber[$assetNumber]) === true) {
			$schedule['assetRef'] = $assetUuidByNumber[$assetNumber];
		}

		unset($schedule['assetNumber']);

		return $schedule;
	}//end linkDemoScheduleToAsset()

	/**
	 * Log the fixed-assets demo counts and build the success result.
	 *
	 * @param array<string,int> $counts The seeded and skipped counts per schema.
	 *
	 * @return array<string,mixed> Result with success flag and the counts.
	 *
	 * @spec openspec/changes/bookkeeping-fixed-assets-depreciation/tasks.md#task-15
	 */
	private function reportFixedAssetsDemo(array $counts): array {
		$this->logger->info('Shillinq: fixed-assets demo seeded', $counts);

		return array_merge(
			[
				'success' => true,
				'message' => 'FixedAsset + DepreciationSchedule demo records seeded successfully.',
			],
			$counts
		);
	}//end reportFixedAssetsDemo()

	/**
	 * Find the one record of a schema that matches the given filters.
	 *
	 * @param mixed               $objectService OpenRegister's ObjectService.
	 * @param string              $registerSlug  The register to search.
	 * @param string              $schema        The schema to search.
	 * @param array<string,mixed> $filters       The deduplication filters.
	 *
	 * @return mixed The findAll result, at most one row.
	 *
	 * @spec openspec/changes/bookkeeping-fixed-assets-depreciation/tasks.md#task-15
	 */
	private function findOneRecord(mixed $objectService, string $registerSlug, string $schema, array $filters): mixed {
		return $objectService
			->setRegister($registerSlug)
			->setSchema($schema)
			->findAll(['filters' => $filters, 'limit' => 1]);
	}//end findOneRecord()

	/**
	 * Save one demo FixedAsset unless a record with its assetNumber already exists.
	 *
	 * @param mixed               $objectService OpenRegister's ObjectService.
	 * @param string              $registerSlug  The register to write to.
	 * @param array<string,mixed> $asset         The asset row, already stamped with its administrationId.
	 *
	 * @return array{seeded: bool, uuid: string} Whether it was saved, and the UUID of the saved or existing record.
	 *
	 * @spec openspec/changes/bookkeeping-fixed-assets-depreciation/tasks.md#task-15
	 */
	private function seedDemoFixedAsset(mixed $objectService, string $registerSlug, array $asset): array {
		$filters = ['assetNumber' => $asset['assetNumber'], 'administrationId' => $asset['administrationId']];
		$existing = $this->findOneRecord(objectService: $objectService, registerSlug: $registerSlug, schema: 'FixedAsset', filters: $filters);

		if (empty($existing) === false) {
			// Cache the existing UUID for schedule linking.
			$firstRow = $existing[0];
			return ['seeded' => false, 'uuid' => ObjectIdentifier::resolve(saved: $firstRow)];
		}

		$saved = $objectService->saveObject(
			object: $asset,
			register: $registerSlug,
			schema: 'FixedAsset',
		);

		return ['seeded' => true, 'uuid' => ObjectIdentifier::resolve(saved: $saved)];
	}//end seedDemoFixedAsset()

	/**
	 * Save one demo DepreciationSchedule unless a record with its scheduleNumber already exists.
	 *
	 * @param mixed               $objectService OpenRegister's ObjectService.
	 * @param string              $registerSlug  The register to write to.
	 * @param array<string,mixed> $schedule      The schedule row, already stamped with its administrationId.
	 *
	 * @return bool True when the schedule was saved, false when it already existed.
	 *
	 * @spec openspec/changes/bookkeeping-fixed-assets-depreciation/tasks.md#task-15
	 */
	private function seedDemoSchedule(mixed $objectService, string $registerSlug, array $schedule): bool {
		$filters = ['scheduleNumber' => $schedule['scheduleNumber'], 'administrationId' => $schedule['administrationId']];
		$existing = $this->findOneRecord(
			objectService: $objectService,
			registerSlug: $registerSlug,
			schema: 'DepreciationSchedule',
			filters: $filters
		);

		if (empty($existing) === false) {
			return false;
		}

		$objectService->saveObject(
			object: $schedule,
			register: $registerSlug,
			schema: 'DepreciationSchedule',
		);

		return true;
	}//end seedDemoSchedule()

	/**
	 * The per-administration BBV seeds, run with this service's register settings.
	 *
	 * @return BbvAdministrationSeedService
	 *
	 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md (REQ-BBV-006)
	 */
	private function bbvAdministrationSeeds(): BbvAdministrationSeedService {
		return new BbvAdministrationSeedService(
			settings: $this->registerLocation,
			importer: $this->seedImporter(),
			appConfig: $this->appConfig,
			logger: $this->logger,
		);
	}//end bbvAdministrationSeeds()

	/**
	 * Seed the default RGS → BBV account mapping for a municipal administration
	 *
	 * @param string $administrationId The administration id.
	 * @param string $administrationType The administration type.
	 *
	 * @return array<string, mixed> Result with success flag, seeded count, skipped count.
	 *
	 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md (REQ-BBV-006)
	 */
	public function seedBbvAccountMappings(string $administrationId, string $administrationType): array {
		return $this->bbvAdministrationSeeds()->seedBbvAccountMappings(administrationId: $administrationId, administrationType: $administrationType);
	}//end seedBbvAccountMappings()

	/**
	 * Seed default rate-card templates from rate-card-templates.json, idempotently.
	 *
	 * @param string $administrationId The administrationId to stamp on seeded records.
	 *
	 * @return array<string,mixed> Result with success flag, seeded count, skipped count.
	 *
	 * @spec openspec/changes/add-shillinq-consultancy-project-accounting/tasks.md#task-14
	 */
	public function seedRateCardTemplates(string $administrationId): array {
		return $this->templateSeeds()->seedRateCardTemplates(administrationId: $administrationId);
	}//end seedRateCardTemplates()

	/**
	 * Seed a single-schema seed file that needs no administrationId, as the system user.
	 *
	 * @param string $seed Key in GENERIC_SEEDS.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec exclude system-context adoption
	 */
	private function seedGenericFile(string $seed): array {
		[$seedFileName, $itemsKey, $dedupeKey, $schema, $logLabel] = self::GENERIC_SEEDS[$seed];
		if ($this->isOpenRegisterAvailable() === false) {
			return ['success' => false, 'message' => 'OpenRegister is not installed or enabled.'];
		}

		$importer = $this->seedImporter();
		$file = $importer->read(fileName: $seedFileName);
		if (isset($file['error']) === true) {
			return $file['error'];
		}

		$items = ($file['data'][$itemsKey] ?? []);
		if (empty($items) === true) {
			return ['success' => false, 'message' => 'Seed file ' . $seedFileName . ' contains no ' . $itemsKey . '.'];
		}

		return $importer->importAsSystem(
			import: new SeedImport(schema: $schema, rows: $items, match: static fn ($item): array => [$dedupeKey => $item[$dedupeKey]]),
			messages: new SeedMessages(
				seededLog: 'Shillinq: ' . $logLabel . ' seeded',
				seededMessage: $logLabel . ' seeded successfully.',
				failedLog: 'Shillinq: ' . $logLabel . ' seeding failed',
			)
		);
	}//end seedGenericFile()

	/**
	 * Seed retention rules from the Selectielijst Gemeenten 2020 seed file, idempotently.
	 *

	 * @return array<string,mixed> Result with success flag, seeded count, skipped count.
	 *
	 * @spec openspec/changes/add-shillinq-archiefwet-retention/tasks.md#task-11
	 */
	public function seedSelectielijst(): array {
		return $this->templateSeeds()->seedSelectielijst();
	}//end seedSelectielijst()

	/**
	 * Seed example allocation rules from the bundled seed files, idempotently.
	 *
	 * @param string $administrationId The administrationId to stamp on seeded records.
	 *
	 * @return array<string,mixed> Result with success flag, seeded count, skipped count.
	 *
	 * @spec openspec/changes/add-shillinq-cost-centers-dimensions/tasks.md#task-11
	 */
	public function seedAllocationRules(string $administrationId): array {
		return $this->templateSeeds()->seedAllocationRules(administrationId: $administrationId);
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
	public function loadConfiguration(): array {
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
	public function loadConfigurationForced(): array {
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
	private function loadRegisterConfigData(): array {
		$base = $this->readBaseRegisterConfig();
		if (isset($base['success']) === true) {
			return $base;
		}

		$merged = $this->mergeRegisterFragments(configData: $base['data']);
		$configData = $merged['data'];
		$fragmentSig = $merged['signature'];

		// Fold the fragment signature into the version so OpenRegister's
		// version-gated importFromApp re-imports whenever fragments change.
		$version = ($configData['info']['version'] ?? '0.0.0');
		if ($fragmentSig !== '') {
			$version .= '+frag.' . substr(md5($fragmentSig), 0, 8);
		}

		return [
			'data' => $configData,
			'version' => $version,
		];
	}//end loadRegisterConfigData()

	/**
	 * Read and parse the base shillinq_register.json file.
	 *
	 * @return array<string,mixed> Either ['data' => mixed] or ['success' => false, 'message' => string]
	 */
	private function readBaseRegisterConfig(): array {
		$configPath = __DIR__ . '/../Settings/shillinq_register.json';
		if (file_exists($configPath) === false) {
			$this->logger->error('Shillinq: shillinq_register.json not found at ' . $configPath);
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
			$this->logger->error('Shillinq: failed to parse shillinq_register.json: ' . json_last_error_msg());
			return [
				'success' => false,
				'message' => 'Failed to parse configuration file: ' . json_last_error_msg(),
			];
		}

		return ['data' => $configData];
	}//end readBaseRegisterConfig()

	/**
	 * Merge the register fragments from Settings/register.d/*.json over the base configuration.
	 *
	 * @param mixed $configData The parsed base configuration.
	 *
	 * @return array{data: mixed, signature: string} The merged configuration and the fragment signature.
	 */
	private function mergeRegisterFragments(mixed $configData): array {
		// ADR-037: merge modular register fragments from Settings/register.d/*.json.
		// Each OpenSpec change drops its own fragment file instead of editing this
		// monolith, so concurrent builds touch disjoint files (no merge conflicts).
		// OpenAPI `components.schemas` / `paths` are keyed objects, so disjoint
		// fragments union cleanly by key.
		$fragmentDir = __DIR__ . '/../Settings/register.d';
		$fragmentSig = '';
		if (is_dir($fragmentDir) === true) {
			$fragmentFiles = glob($fragmentDir . '/*.json');
			sort($fragmentFiles);
			foreach ($fragmentFiles as $fragmentFile) {
				$fragment = $this->readRegisterFragment(fragmentFile: $fragmentFile);
				if ($fragment === null) {
					continue;
				}

				$configData = self::deepMergeConfig(base: $configData, overlay: $fragment['data']);
				$fragmentSig .= basename($fragmentFile) . ':' . md5($fragment['content']) . ';';
			}
		}

		return ['data' => $configData, 'signature' => $fragmentSig];
	}//end mergeRegisterFragments()

	/**
	 * Read and parse one register fragment file.
	 *
	 * @param string $fragmentFile The fragment file path.
	 *
	 * @return array{data: mixed, content: string}|null The parsed fragment and its raw content, or null when unusable.
	 */
	private function readRegisterFragment(string $fragmentFile): array|null {
		$fragmentContent = file_get_contents($fragmentFile);
		if ($fragmentContent === false) {
			return null;
		}

		$fragmentData = json_decode($fragmentContent, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->logger->warning(
				'Shillinq: skipping malformed register fragment ' . basename($fragmentFile)
				. ': ' . json_last_error_msg()
			);
			return null;
		}

		return ['data' => $fragmentData, 'content' => $fragmentContent];
	}//end readRegisterFragment()

	/**
	 * Deep-merge a register fragment onto the base config (ADR-037).
	 *
	 * Associative arrays (OpenAPI objects like `components.schemas`, `paths`) are
	 * merged by key union (recursing on shared keys); list arrays are concatenated;
	 * scalars in the fragment overwrite the base. Disjoint fragments never collide.
	 *
	 * @param array<mixed> $base The accumulated config.
	 * @param array<mixed> $overlay The fragment to merge in.
	 *
	 * @return array<mixed> The merged config.
	 */
	private static function deepMergeConfig(array $base, array $overlay): array {
		foreach ($overlay as $key => $value) {
			if (is_array($value) === true
				&& isset($base[$key]) === true
				&& is_array($base[$key]) === true
			) {
				$baseIsList = ($base[$key] === [] || array_keys($base[$key]) === range(0, (count($base[$key]) - 1)));
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
	private function runLoadConfiguration(bool $force): array {
		if ($this->isOpenRegisterAvailable() === false) {
			$this->logger->warning('Shillinq: OpenRegister not available, skipping register initialization');
			return [
				'success' => false,
				'message' => 'OpenRegister is not installed or enabled.',
			];
		}

		try {
			return $this->importRegisterConfiguration(force: $force);
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
	 * Import the register configuration through OpenRegister and classify the outcome.
	 *
	 * @param bool $force Force re-import even if already configured.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws \Throwable When loading or importing the configuration fails.
	 */
	private function importRegisterConfiguration(bool $force): array {
		$configLoad = $this->loadRegisterConfigData();
		if (isset($configLoad['success']) === true && $configLoad['success'] === false) {
			return $configLoad;
		}

		$configData = $configLoad['data'];
		$configVersion = $configLoad['version'];

		$configurationService = $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
		$result = $configurationService->importFromApp(
			appId: AppIdentity::APP_ID,
			data: $configData,
			version: $configVersion,
			force: $force
		);

		if (empty($result) === false) {
			return $this->classifyConfigurationImport(result: $result);
		}

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
	}//end importRegisterConfiguration()

	/**
	 * Turn a non-empty importFromApp result into the load result, logging errors, warnings and skipped seeds.
	 *
	 * @param array<string,mixed> $result The importFromApp result.
	 *
	 * @return array<string,mixed>
	 */
	private function classifyConfigurationImport(array $result): array {
		$errors = ($result['errors'] ?? []);
		$warnings = ($result['warnings'] ?? []);

		// A seed object that fails its schema's `required` list is skipped
		// ONE BY ONE by OpenRegister's ImportHandler (per-entity resilience):
		// it increments $result['skipped'] and logs a WARNING, then keeps
		// going. It does NOT raise an error, so every branch below used to
		// report `success: true` over a partially-imported register — and
		// `occ app:enable` exits 0. That is why a broken seed stayed
		// invisible until an endpoint merely LOOKED empty:
		// GET /api/v2/calendars returned 200 {"calendars":[]} and the whole
		// bookings collection failed against calendar code that was never
		// wrong. Surface the count and the names instead of discarding them.
		//
		// Deliberately NOT fatal: flipping success to false here would make
		// `occ app:enable` / `occ upgrade` fail on EXISTING installs that
		// already carry these seeds, which would brick an upgrade for a
		// data defect that is not new. The count is reported, logged at
		// error level and returned to the admin UI; the ratchet in
		// tests/validate-seeds.js is what actually stops new ones landing.
		$skipped = ($result['skipped'] ?? []);
		$skippedSeeds = (int)($skipped['objects'] ?? 0) + (int)($skipped['seedObjects'] ?? 0);

		if (empty($errors) === false) {
			return $this->configurationImportFailed(result: $result);
		}

		if (empty($warnings) === false) {
			$this->logger->warning(
				'Shillinq: register configuration imported with warnings',
				['warnings' => $warnings]
			);
		}

		if ($skippedSeeds > 0) {
			return $this->configurationImportSkippedSeeds(result: $result, skippedSeeds: $skippedSeeds);
		}

		$this->logger->info('Shillinq: register configuration imported successfully');
		return [
			'success' => true,
			'message' => 'Configuration imported successfully.',
			'skippedSeedObjects' => 0,
			'warnings' => $warnings,
			'version' => ($result['version'] ?? 'unknown'),
		];
	}//end classifyConfigurationImport()

	/**
	 * Log and report a configuration import that returned errors.
	 *
	 * @param array<string,mixed> $result The importFromApp result.
	 *
	 * @return array<string,mixed>
	 */
	private function configurationImportFailed(array $result): array {
		$errors = ($result['errors'] ?? []);
		$warnings = ($result['warnings'] ?? []);

		$this->logger->error(
			'Shillinq: register configuration imported with errors',
			['errors' => $errors]
		);
		return [
			'success' => false,
			'message' => 'Configuration import completed with errors.',
			'errors' => $errors,
			'warnings' => $warnings,
			'version' => ($result['version'] ?? 'unknown'),
		];
	}//end configurationImportFailed()

	/**
	 * Log and report a configuration import that skipped seed objects failing their schema.
	 *
	 * @param array<string,mixed> $result       The importFromApp result.
	 * @param int                 $skippedSeeds The number of seed objects that could not be imported.
	 *
	 * @return array<string,mixed>
	 */
	private function configurationImportSkippedSeeds(array $result, int $skippedSeeds): array {
		$skipped = ($result['skipped'] ?? []);
		$warnings = ($result['warnings'] ?? []);

		$this->logger->error(
			'Shillinq: register configuration imported, but ' . $skippedSeeds . ' seed object(s) could NOT be imported '
			. '— they do not satisfy their own schema\'s required list. Affected endpoints will return empty '
			. 'collections that look like a working-but-empty install. Run `node tests/validate-seeds.js` for the '
			. 'per-schema breakdown of which objects failed and which fields they are missing.',
			['skippedSeedObjects' => $skippedSeeds, 'skipped' => $skipped]
		);

		return [
			'success' => true,
			'message' => 'Configuration imported, but ' . $skippedSeeds . ' seed object(s) could not be imported.',
			'skippedSeedObjects' => $skippedSeeds,
			'skipped' => $skipped,
			'warnings' => $warnings,
			'version' => ($result['version'] ?? 'unknown'),
		];
	}//end configurationImportSkippedSeeds()

	/**
	 * The template and reference-rule seeds, run with this service's register settings.
	 *
	 * @return TemplateSeedService
	 *
	 * @spec openspec/changes/add-shillinq-archiefwet-retention/tasks.md#task-11
	 */
	private function templateSeeds(): TemplateSeedService {
		return new TemplateSeedService(registerLocation: $this->registerLocation, importer: $this->seedImporter(), logger: $this->logger);
	}//end templateSeeds()

	/**
	 * The inventory demo seeds, run with this service's register and administration settings.
	 *
	 * @return InventorySeedService
	 *
	 * @spec openspec/changes/inventory-stock-tracking/tasks.md#task-12
	 */
	private function inventorySeeds(): InventorySeedService {
		return new InventorySeedService(settings: $this, importer: $this->seedImporter(), logger: $this->logger);
	}//end inventorySeeds()

	/**
	 * The importer for seed files under lib/Settings/seeds/.
	 *
	 * @return SeedImporter
	 *
	 * @spec openspec/changes/inventory-stock-tracking/tasks.md#task-12
	 */
	private function seedImporter(): SeedImporter {
		return new SeedImporter(settings: $this->registerLocation, container: $this->container, logger: $this->logger);
	}//end seedImporter()

	/**
	 * Seed demo Barcode records from inventory-barcodes-demo.json, idempotently.
	 *
	 * @return array<string, mixed> Result with success flag, seeded count, skipped count, message.
	 *
	 * @spec openspec/changes/inventory-barcode-sku/tasks.md#task-15
	 */
	public function seedInventoryBarcodes(): array {
		return $this->inventorySeeds()->seedInventoryBarcodes();
	}//end seedInventoryBarcodes()

	/**
	 * Seed demo InventoryLot records from inventory-lots-demo.json, idempotently.
	 *
	 * @return array<string, mixed> Result with success flag, seeded count, skipped count, message.
	 *
	 * @spec openspec/changes/inventory-lot-batch-expiry/tasks.md#task-14
	 */
	public function seedInventoryLots(): array {
		return $this->inventorySeeds()->seedInventoryLots();
	}//end seedInventoryLots()

	/**
	 * Seed the demo InventoryValuation records, idempotently per administration.
	 *
	 * @param string $administrationId Administration scope for the seed.
	 *
	 * @return array<string, mixed> Result with success flag, seeded count, skipped count, message.
	 *
	 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-12
	 */
	public function seedInventoryValuationExamples(string $administrationId): array {
		return $this->inventorySeeds()->seedInventoryValuationExamples(administrationId: $administrationId);
	}//end seedInventoryValuationExamples()

	/**
	 * Seed the demo InventoryStock records, idempotently per administration.
	 *
	 * @param string $administrationId Administration scope for the seed.
	 *
	 * @return array<string, mixed> Result with success flag, seeded count, skipped count, message.
	 *
	 * @spec openspec/changes/inventory-stock-tracking/tasks.md#task-12
	 */
	public function seedInventoryStockExamples(string $administrationId): array {
		return $this->inventorySeeds()->seedInventoryStockExamples(administrationId: $administrationId);
	}//end seedInventoryStockExamples()

	/**
	 * Seed the default InventoryGLConfig for an administration, idempotently.
	 *
	 * @param string $administrationId Administration scope for the seed.
	 *
	 * @return array<string, mixed> Result with success flag, seeded count, skipped count, message.
	 *
	 * @spec openspec/changes/inventory-cogs-posting/tasks.md#task-11
	 */
	public function seedInventoryGLConfig(string $administrationId): array {
		return $this->inventorySeeds()->seedInventoryGLConfig(administrationId: $administrationId);
	}//end seedInventoryGLConfig()

	/**
	 * Seed the demo BBVProgramme records from
	 *
	 * @param string $administrationId The administration id.
	 *
	 * @return array<string, mixed> Result with success flag, seeded count, skipped count.
	 *
	 * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-01-config-schemas-seed/tasks.md#seed-data
	 */
	public function seedBbvProgrammes(string $administrationId): array {
		return $this->bbvAdministrationSeeds()->seedBbvProgrammes(administrationId: $administrationId);
	}//end seedBbvProgrammes()

	/**
	 * Seed the demo BudgetBBVMapping records from
	 *
	 * @param string $administrationId The administration id.
	 *
	 * @return array<string, mixed> Result with success flag, seeded count, skipped count.
	 *
	 * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-01-config-schemas-seed/tasks.md#seed-data
	 */
	public function seedBudgetBbvMappings(string $administrationId): array {
		return $this->bbvAdministrationSeeds()->seedBudgetBbvMappings(administrationId: $administrationId);
	}//end seedBudgetBbvMappings()

	/**
	 * Seed the default Archiefwet retention policies, idempotently.
	 *

	 * @return array<string,mixed> Result with success flag, seeded count, skipped count.
	 *
	 * @spec openspec/specs/bookkeeping-archiefwet-retention/spec.md (Task 13, REQ-RET-012)
	 */
	public function seedRetentionPolicies(): array {
		return $this->templateSeeds()->seedRetentionPolicies();
	}//end seedRetentionPolicies()

	/**
	 * Seed mandaat (signing-authority) templates from mandaat-templates.json, idempotently.
	 *
	 * @param string $administrationId The administrationId to stamp on seeded records.
	 *
	 * @return array<string,mixed> Result with success flag, seeded count, skipped count.
	 *
	 * @spec openspec/changes/bookkeeping-verplichtingenadministratie/tasks.md#task-1.7
	 */
	public function seedMandateTemplates(string $administrationId): array {
		return $this->templateSeeds()->seedMandateTemplates(administrationId: $administrationId);
	}//end seedMandateTemplates()

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
	 * @spec openspec/specs/bookkeeping-financial-statements/spec.md
	 */
	public function seedStatementManifests(): array {
		$files = [
			'balance-sheet' => 'rj270-balance-sheet.json',
			'profit-loss' => 'rj270-pl.json',
			'cash-flow' => 'rj270-cash-flow.json',
		];

		$imported = 0;
		$skipped = 0;

		foreach ($files as $type => $fileName) {
			$configKey = 'statement_manifest_' . $type;

			// Preserve operator edits: skip when the manifest is already persisted.
			$existing = $this->appConfig->getValueString(AppIdentity::APP_ID, $configKey, '');
			if ($existing !== '') {
				$skipped++;
				continue;
			}

			$manifestPath = __DIR__ . '/../Settings/statements/' . $fileName;
			if (file_exists($manifestPath) === false) {
				$this->logger->warning('Shillinq: statement manifest not found: ' . $fileName);
				continue;
			}

			$content = file_get_contents($manifestPath);
			if ($content === false) {
				$this->logger->warning('Shillinq: failed to read statement manifest: ' . $fileName);
				continue;
			}

			$decoded = json_decode($content, true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				$this->logger->warning(
					'Shillinq: failed to parse statement manifest ' . $fileName . ': ' . json_last_error_msg()
				);
				continue;
			}

			// Stamp the import timestamp so a future presentation-standard migration
			// (BBV — T3; IFRS full — T5) can tell template-sourced from operator-authored.
			if (isset($decoded['_meta']) === true && is_array($decoded['_meta']) === true) {
				$decoded['_meta']['imported'] = gmdate('Y-m-d\TH:i:s\Z');
			}

			$this->appConfig->setValueString(
				AppIdentity::APP_ID,
				$configKey,
				(string)json_encode($decoded)
			);
			$imported++;
		}//end foreach

		$this->logger->info(
			'Shillinq: statement manifests imported',
			['imported' => $imported, 'skipped' => $skipped]
		);

		return [
			'success' => true,
			'message' => 'Statement manifests imported.',
			'imported' => $imported,
			'skipped' => $skipped,
		];

	}//end seedStatementManifests()

	/**
	 * Seed the WMO (Wet Markt en Overheid) example records, idempotently per administration.
	 *
	 * Reads the four JSON files under `lib/Settings/seeds/commercial-activities/`:
	 *
	 *   - sportaccommodaties-municipality.json      → CommercialActivity records (state=paused)
	 *   - waterschap-slibruimte.json            → CommercialActivity records (state=paused)
	 *   - abb-example-municipality.json             → AlgemeenBelangBesluit records (status=concept)
	 *   - integral-cost-price-example-q1-2026.json → IntegralCostPrice records (status=voorlopig)
	 *
	 * Each record is stamped with the configured administrationId and deduped on its
	 * natural key (`code` for activities, `reference` for ABBs, `(commercialActivityId,period)`
	 * for IKPs) so re-runs preserve operator edits per REQ-WMO-001 / REQ-WMO-002 /
	 * REQ-WMO-005. All seeded records ship in paused / concept / voorlopig lifecycle
	 * states; operators promote them through the workflow when adopting the seed.
	 *
	 * Skipped when administrationId is empty (C2 — prevents "default" contamination
	 * of real tenant data).
	 *
	 * @param string $administrationId The administrationId to stamp on seeded records.
	 *
	 * @return array<string,mixed> Result with success flag and per-schema counts.
	 *
	 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p1-17
	 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p1-18
	 */
	public function seedWmoCommercialActivities(string $administrationId): array {
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

		$seedDir = __DIR__ . '/../Settings/seeds/commercial-activities';
		if (is_dir($seedDir) === false) {
			return [
				'success' => false,
				'message' => 'WMO seed directory not found: ' . $seedDir,
			];
		}

		try {
			return $this->importWmoSeeds(seedDir: $seedDir, administrationId: $administrationId);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Shillinq: WMO seeding failed',
				['exception' => $e->getMessage()]
			);
			return [
				'success' => false,
				'message' => $e->getMessage(),
			];
		}//end try

	}//end seedWmoCommercialActivities()

	/**
	 * Seed the records of every WMO seed file, then log and report the totals.
	 *
	 * @param string $seedDir          The commercial-activities seed directory.
	 * @param string $administrationId The administrationId to stamp on seeded records.
	 *
	 * @return array<string,mixed> Result with success flag and seeded/skipped totals.
	 *
	 * @throws \Throwable When OpenRegister lookups or saves fail.
	 *
	 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p1-17
	 */
	private function importWmoSeeds(string $seedDir, string $administrationId): array {
		$totalSeeded = 0;
		$totalSkipped = 0;
		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		$registerSlug = $this->getRegisterSlug();

		foreach ($this->wmoSeedFiles() as $filename => $spec) {
			$records = $this->readWmoSeedRecords(seedPath: $seedDir . '/' . $filename, filename: $filename, arrayKey: $spec[1]);
			if ($records === null) {
				continue;
			}

			foreach ($records as $record) {
				// Drop _meta-only keys that aren't schema properties.
				unset($record['_meta']);
				$record['administrationId'] = $administrationId;

				if ($this->seedWmoRecord(objectService: $objectService, registerSlug: $registerSlug, spec: $spec, record: $record) === false) {
					$totalSkipped++;
					continue;
				}

				$totalSeeded++;
			}
		}//end foreach

		return $this->reportWmoSeeds(seeded: $totalSeeded, skipped: $totalSkipped);
	}//end importWmoSeeds()

	/**
	 * The WMO seed files, each mapped to [schema, records key, dedupe key].
	 *
	 * @return array<string,array{0: string, 1: string, 2: string}>
	 *
	 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p1-17
	 */
	private function wmoSeedFiles(): array {
		return [
			'sportaccommodaties-municipality.json' => ['CommercialActivity', 'activities', 'code'],
			'waterschap-slibruimte.json' => ['CommercialActivity', 'activities', 'code'],
			'abb-example-municipality.json' => ['AlgemeenBelangBesluit', 'besluiten', 'reference'],
			'integral-cost-price-example-q1-2026.json' => ['IntegralCostPrice', 'ikp', '__ikpKey'],
		];
	}//end wmoSeedFiles()

	/**
	 * Read one WMO seed file and return its records, or null when it cannot be used.
	 *
	 * @param string $seedPath The seed file path.
	 * @param string $filename The seed file name, for the warning log.
	 * @param string $arrayKey The key that holds the records.
	 *
	 * @return mixed The records, or null when the file is missing, unreadable or invalid JSON.
	 *
	 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p1-17
	 */
	private function readWmoSeedRecords(string $seedPath, string $filename, string $arrayKey): mixed {
		if (file_exists($seedPath) === false) {
			return null;
		}

		$content = file_get_contents($seedPath);
		if ($content === false) {
			return null;
		}

		$data = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->logger->warning(
				'Shillinq: failed to parse WMO seed file: ' . $filename,
				['error' => json_last_error_msg()]
			);
			return null;
		}

		return ($data[$arrayKey] ?? []);
	}//end readWmoSeedRecords()

	/**
	 * Save one WMO record unless a record with the same natural key already exists.
	 *
	 * @param mixed                $objectService OpenRegister's ObjectService.
	 * @param string               $registerSlug  The register to write to.
	 * @param array<int,string>    $spec          The seed file's [schema, records key, dedupe key].
	 * @param array<string,mixed>  $record        The record, already stamped with its administrationId.
	 *
	 * @return bool True when the record was saved, false when it already existed.
	 *
	 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p1-17
	 */
	private function seedWmoRecord(mixed $objectService, string $registerSlug, array $spec, array $record): bool {
		$schema = $spec[0];
		$filter = $this->wmoDedupeFilter(record: $record, dedupeKey: $spec[2]);
		$existing = $this->findOneRecord(objectService: $objectService, registerSlug: $registerSlug, schema: $schema, filters: $filter);

		if (empty($existing) === false) {
			return false;
		}

		$objectService->saveObject(
			object: $record,
			register: $registerSlug,
			schema: $schema,
		);

		return true;
	}//end seedWmoRecord()

	/**
	 * Build the dedupe filter for one WMO record.
	 *
	 * @param array<string,mixed> $record    The record, already stamped with its administrationId.
	 * @param string              $dedupeKey The natural key, or `__ikpKey` for (commercialActivityId, period).
	 *
	 * @return array<string,mixed> The findAll filters.
	 *
	 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p1-17
	 */
	private function wmoDedupeFilter(array $record, string $dedupeKey): array {
		$filter = ['administrationId' => $record['administrationId']];
		if ($dedupeKey === '__ikpKey') {
			$filter['commercialActivityId'] = ($record['commercialActivityId'] ?? '');
			$filter['period'] = ($record['period'] ?? '');
		} elseif (isset($record[$dedupeKey]) === true) {
			$filter[$dedupeKey] = $record[$dedupeKey];
		}

		return $filter;
	}//end wmoDedupeFilter()

	/**
	 * Log the WMO seed totals and build the success result.
	 *
	 * @param int $seeded  Records saved.
	 * @param int $skipped Records that already existed.
	 *
	 * @return array<string,mixed> Result with success flag and the totals.
	 *
	 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p1-17
	 */
	private function reportWmoSeeds(int $seeded, int $skipped): array {
		$this->logger->info(
			'Shillinq: WMO commercial activities seeded',
			['seeded' => $seeded, 'skipped' => $skipped]
		);

		return [
			'success' => true,
			'message' => 'WMO commercial activities seeded.',
			'seeded' => $seeded,
			'skipped' => $skipped,
		];
	}//end reportWmoSeeds()
}//end class
