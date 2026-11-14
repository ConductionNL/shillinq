<?php

/**
 * SBR / XBRL Seed Repair Step.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Declarative repair step that idempotently seeds the XBRLTaxonomy,
 * SBRDocumentType and XBRLMapping example records shipped with the
 * bookkeeping-sbr-xbrl-reporting T3 change. Per ADR-037 the canonical
 * seeds live in `lib/Settings/register.d/bookkeeping-sbr-xbrl-reporting.json`
 * `objects[]` and are auto-loaded by `SettingsService::loadConfigurationForced()`
 * (which `InitializeSettings` already runs). This repair step is an
 * explicit additional safety net: when the main configuration import is
 * skipped because the register fragment version is unchanged but the
 * operator has manually cleared the seed records, this step deduplicates
 * on slug and re-creates the seed XBRLTaxonomy, SBRDocumentType and
 * XBRLMapping fixtures (Task 13 / REQ-SBR-002 + REQ-SBR-003 + REQ-SBR-004).
 *
 * The step is non-fatal — a failure here logs a warning but does not abort
 * the upgrade. The post-migration phase is the appropriate hook (we depend
 * on OpenRegister being installed and the shillinq register slug being
 * available).
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-sbr-xbrl-reporting/tasks.md#task-13
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\Shillinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Idempotently seeds XBRLTaxonomy / SBRDocumentType / XBRLMapping fixtures from the change fragment.
 *
 * @spec openspec/changes/bookkeeping-sbr-xbrl-reporting/tasks.md#task-13
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Pre-existing debt (issue
 *     #506): inherent branch complexity in this domain logic; deferred
 *     pending a dedicated refactor.
 */
class LoadSbrXbrlSeedsStep implements IRepairStep {
	use \OCA\Shillinq\Repair\Support\RunsUnderSystemIdentity;

	/**
	 * Schemas this step is responsible for seeding.
	 *
	 * @var array<int, string>
	 */
	private const SCHEMAS = ['XBRLTaxonomy', 'SBRDocumentType', 'XBRLMapping'];

	/**
	 * Absolute path to the canonical seed JSON (the register fragment).
	 *
	 * @var string
	 */
	private string $seedPath;

	/**
	 * Construct the repair step.
	 *
	 * @param ContainerInterface $container DI container — OR's ObjectService is fetched lazily.
	 * @param IAppConfig $appConfig App config for the register slug lookup.
	 * @param LoggerInterface $logger Logger for diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
		$this->seedPath = __DIR__ . '/../Settings/register.d/bookkeeping-sbr-xbrl-reporting.json';

	}//end __construct()

	/**
	 * Human-readable name of this repair step.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/bookkeeping-sbr-xbrl-reporting/tasks.md#task-13
	 */
	public function getName(): string {
		return 'Seed SBR / XBRL example records (bookkeeping-sbr-xbrl-reporting)';
	}//end getName()

	/**
	 * Run the repair step.
	 *
	 * Reads the canonical fragment, walks the `objects[]` array, and for each
	 * XBRLTaxonomy / SBRDocumentType / XBRLMapping object checks whether an
	 * object with the same `@self.slug` already exists in OpenRegister; if not,
	 * saves it via the real OR ObjectService API (`saveObject` named-arg form
	 * per the OR-API memory). Non-fatal on any failure.
	 *
	 * @param IOutput $output Progress output interface.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-sbr-xbrl-reporting/tasks.md#task-13
	 */
	public function run(IOutput $output): void {
		$output->info('Seeding XBRLTaxonomy / SBRDocumentType / XBRLMapping example records...');

		$objects = $this->seedObjects(output: $output);
		if ($objects === null) {
			return;
		}

		$objectService = $this->objectService(output: $output);
		if ($objectService === null) {
			return;
		}

		// Under a system identity: an upgrade has no session, and OpenRegister
		// refuses `create` for 'Anonymous'. Without it this seed writes nothing
		// and says so only in a warning, which does not fail an upgrade.
		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($objectService, $objects, $output): void {
				$this->runInner(objectService: $objectService, objects: $objects, output: $output);
			}
		);
	}//end run()

	/**
	 * Read and decode the seed fragment.
	 *
	 * @param IOutput $output Progress output interface.
	 *
	 * @return array<mixed>|null The decoded fragment, or null (warned) when it cannot be read.
	 */
	private function readSeed(IOutput $output): array|null {
		if (file_exists($this->seedPath) === false) {
			$output->warning('SBR/XBRL seed fragment not found at ' . $this->seedPath . ', skipping');
			return null;
		}

		$content = file_get_contents($this->seedPath);
		if ($content === false) {
			$output->warning('Failed to read SBR/XBRL seed fragment, skipping');
			return null;
		}

		$data = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($data) === false) {
			$output->warning('Failed to parse SBR/XBRL seed fragment: ' . json_last_error_msg());
			return null;
		}

		return $data;
	}//end readSeed()

	/**
	 * The seed fragment's objects, or null when there is nothing to seed.
	 *
	 * @param IOutput $output Progress output interface.
	 *
	 * @return array<int, mixed>|null
	 */
	private function seedObjects(IOutput $output): array|null {
		$data = $this->readSeed(output: $output);
		if ($data === null) {
			return null;
		}

		$objects = ($data['objects'] ?? []);
		if (is_array($objects) === false || $objects === []) {
			$output->info('SBR/XBRL seed fragment carries no objects; nothing to seed');
			return null;
		}

		return $objects;
	}//end seedObjects()

	/**
	 * OpenRegister's ObjectService, or null (warned) when it is unavailable.
	 *
	 * @param IOutput $output Progress output interface.
	 *
	 * @return object|null
	 */
	private function objectService(IOutput $output): object|null {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			$output->warning('OpenRegister ObjectService unavailable; skipping SBR/XBRL seed: ' . $e->getMessage());
			return null;
		}

		return $objectService;
	}//end objectService()

	/**
	 * The seed itself.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param array<int, mixed> $objects The seed objects.
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-administration/spec.md
	 */
	private function runInner(object $objectService, array $objects, IOutput $output): void {
		$registerSlug = $this->register();
		$counts = ['seeded' => 0, 'skipped' => 0, 'ignored' => 0];

		foreach ($objects as $object) {
			$counts[$this->seedOne(objectService: $objectService, registerSlug: $registerSlug, object: $object)]++;
		}

		$output->info(
			sprintf(
				'SBR/XBRL seed: %d created, %d skipped (already exist).',
				$counts['seeded'],
				$counts['skipped'],
			)
		);

	}//end runInner()

	/**
	 * Seed one object of a known schema that carries a slug.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $registerSlug  The register slug.
	 * @param mixed  $object        The seed object.
	 *
	 * @return string The tally it counts under: 'seeded', 'skipped' (exists) or 'ignored'.
	 *
	 * @spec openspec/specs/app-administration/spec.md
	 */
	private function seedOne(object $objectService, string $registerSlug, mixed $object): string {
		if (is_array($object) === false) {
			return 'ignored';
		}

		$self = (array)($object['@self'] ?? []);
		$schema = (string)($self['schema'] ?? '');
		$slug = (string)($self['slug'] ?? '');
		if ($schema === '' || $slug === '') {
			return 'ignored';
		}

		if (in_array($schema, self::SCHEMAS, true) === false) {
			return 'ignored';
		}

		return $this->saveSeed(objectService: $objectService, registerSlug: $registerSlug, object: $object);
	}//end seedOne()

	/**
	 * Save one seed object unless an object with its slug already exists.
	 *
	 * @param object              $objectService OpenRegister's ObjectService.
	 * @param string              $registerSlug  The register slug.
	 * @param array<string,mixed> $object        The seed object (known schema, has a slug).
	 *
	 * @return string The tally it counts under: 'seeded', 'skipped' (exists) or 'ignored' (failed).
	 *
	 * @spec openspec/specs/app-administration/spec.md
	 */
	private function saveSeed(object $objectService, string $registerSlug, array $object): string {
		$self = (array)($object['@self'] ?? []);
		$schema = (string)($self['schema'] ?? '');
		$slug = (string)($self['slug'] ?? '');

		try {
			if ($this->slugExists(objectService: $objectService, registerSlug: $registerSlug, schema: $schema, slug: $slug) === true) {
				return 'skipped';
			}

			$payload = $this->seedPayload(object: $object, slug: $slug);
			$this->persistSeed(objectService: $objectService, registerSlug: $registerSlug, schema: $schema, payload: $payload);
			return 'seeded';
		} catch (\Throwable $e) {
			$this->logger->warning('SBR/XBRL seed: failed to save record', ['schema' => $schema, 'slug' => $slug, 'exception' => $e->getMessage()]);
			return 'ignored';
		}
	}//end saveSeed()

	/**
	 * Persist one seed object.
	 *
	 * @param object              $objectService OpenRegister's ObjectService.
	 * @param string              $registerSlug  The register slug.
	 * @param string              $schema        The schema slug.
	 * @param array<string,mixed> $payload       The object to persist.
	 *
	 * @return void
	 *
	 * @throws \Throwable When the save fails.
	 */
	private function persistSeed(object $objectService, string $registerSlug, string $schema, array $payload): void {
		// Runs in the installer/repair context where no web user is
		// authenticated ('Anonymous'). Bypass RBAC + multi-tenancy so the
		// seed persists instead of throwing "User 'Anonymous' does not have
		// permission to 'create'" — mirrors the OR ImportHandler fix.
		$objectService->saveObject(
			object: $payload,
			register: $registerSlug,
			schema: $schema,
			_rbac: false,
			_multitenancy: false,
		);
	}//end persistSeed()

	/**
	 * Whether an object with the slug already exists in the schema.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $registerSlug  The register slug.
	 * @param string $schema        The schema slug.
	 * @param string $slug          The object slug.
	 *
	 * @return bool
	 *
	 * @throws \Throwable When the lookup fails.
	 */
	private function slugExists(object $objectService, string $registerSlug, string $schema, string $slug): bool {
		$existing = $objectService
			->setRegister($registerSlug)
			->setSchema($schema)
			->findAll(
				[
					'filters' => ['slug' => $slug],
					'limit' => 1,
				]
			);

		return empty($existing) === false;
	}//end slugExists()

	/**
	 * The object to persist: the @self envelope dropped, the slug kept.
	 *
	 * @param array<string,mixed> $object The seed object.
	 * @param string              $slug   The object slug.
	 *
	 * @return array<string,mixed>
	 */
	private function seedPayload(array $object, string $slug): array {
		// Drop the @self envelope before persisting — the OR backend
		// honours register + schema named args, not the inline block.
		$payload = $object;
		unset($payload['@self']);
		$payload['slug'] = $slug;

		return $payload;
	}//end seedPayload()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
