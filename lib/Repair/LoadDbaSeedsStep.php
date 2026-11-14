<?php

/**
 * DBA Modelovereenkomst Seed Repair Step.
 *
 * Idempotently seeds the Belastingdienst-goedgekeurde DBAModelovereenkomst
 * fixtures shipped with the dba-compliance-marker change (REQ-DBA-002, T18).
 * Canonical seed data lives in `lib/Settings/register.d/dba-compliance-marker.json`
 * `objects[]` per ADR-037 and is auto-loaded by SettingsService; this repair
 * step is the explicit safety net for upgrades that skip the configuration
 * import.
 *
 * Non-fatal: failures log a warning but do not abort the upgrade.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\Shillinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Idempotently seeds DBAModelovereenkomst fixtures (REQ-DBA-002).
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Pre-existing debt (issue
 *     #506): inherent branch complexity in this domain logic; deferred
 *     pending a dedicated refactor.
 */
class LoadDbaSeedsStep implements IRepairStep {
	use \OCA\Shillinq\Repair\Support\RunsUnderSystemIdentity;

	/**
	 * The only schema this step seeds.
	 *
	 * @var string
	 */
	private const DBA_SCHEMA = 'DBAModelovereenkomst';

	/**
	 * Absolute path to the canonical seed JSON (the register fragment).
	 *
	 * @var string
	 */
	private string $seedPath;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container — OR's ObjectService is fetched lazily.
	 * @param IAppConfig $appConfig App config for the register slug lookup.
	 * @param LoggerInterface $logger Logger for diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
		$this->seedPath = __DIR__ . '/../Settings/register.d/dba-compliance-marker.json';
	}//end __construct()

	/**
	 * Human-readable name of this repair step.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function getName(): string {
		return 'Seed DBAModelovereenkomst Belastingdienst-templates (dba-compliance-marker)';
	}//end getName()

	/**
	 * Run the repair step (REQ-DBA-002).
	 *
	 * @param IOutput $output Progress output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function run(IOutput $output): void {
		$output->info('Seeding DBAModelovereenkomst Belastingdienst-templates...');

		$objects = $this->seedObjects(output: $output);
		if ($objects === null) {
			return;
		}

		$this->seedUnderSystemIdentity(objects: $objects, output: $output);
	}//end run()

	/**
	 * Read and parse the seed fragment's objects, warning and returning null when there is nothing to seed.
	 *
	 * @param IOutput $output Progress output.
	 *
	 * @return array<int, mixed>|null The seed objects, or null to skip.
	 */
	private function seedObjects(IOutput $output): array|null {
		$data = $this->seedData(output: $output);
		if ($data === null) {
			return null;
		}

		$objects = ($data['objects'] ?? []);
		if (is_array($objects) === false || $objects === []) {
			$output->info('DBA seed fragment carries no objects; nothing to seed');
			return null;
		}

		return $objects;
	}//end seedObjects()

	/**
	 * Read and decode the seed fragment, warning and returning null when it cannot be used.
	 *
	 * @param IOutput $output Progress output.
	 *
	 * @return array<mixed>|null The decoded fragment, or null.
	 */
	private function seedData(IOutput $output): array|null {
		if (file_exists($this->seedPath) === false) {
			$output->warning('DBA seed fragment not found at ' . $this->seedPath . ', skipping');
			return null;
		}

		$content = file_get_contents($this->seedPath);
		if ($content === false) {
			$output->warning('Failed to read DBA seed fragment, skipping');
			return null;
		}

		$data = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($data) === false) {
			$output->warning('Failed to parse DBA seed fragment: ' . json_last_error_msg());
			return null;
		}

		return $data;
	}//end seedData()

	/**
	 * Seed the objects under a system identity: an upgrade has no session, and OpenRegister
	 * refuses `create` for 'Anonymous'. Without it this seed writes nothing
	 * and says so only in a warning, which does not fail an upgrade.
	 *
	 * @param array<int, mixed> $objects The seed objects.
	 * @param IOutput           $output  Progress output.
	 *
	 * @return void
	 */
	private function seedUnderSystemIdentity(array $objects, IOutput $output): void {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$output->warning('OpenRegister ObjectService unavailable; skipping DBA seed: ' . $e->getMessage());
			return;
		}

		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($objectService, $objects, $output): void {
				$this->runInner(objectService: $objectService, objects: $objects, output: $output);
			}
		);
	}//end seedUnderSystemIdentity()

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
		$counts       = ['seeded' => 0, 'skipped' => 0];

		foreach ($objects as $object) {
			$outcome = $this->seedObject(objectService: $objectService, registerSlug: $registerSlug, object: $object);
			if ($outcome !== '') {
				$counts[$outcome]++;
			}
		}

		$output->info(
			sprintf('DBA modelovereenkomst seed: %d created, %d skipped (already exist).', $counts['seeded'], $counts['skipped'])
		);
	}//end runInner()

	/**
	 * Seed one object unless it is not a DBAModelovereenkomst seed or already exists.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $registerSlug  The register slug.
	 * @param mixed  $object        One seed object.
	 *
	 * @return string The outcome: seeded, skipped, or '' when ignored or failed.
	 */
	private function seedObject(object $objectService, string $registerSlug, mixed $object): string {
		$slug = self::dbaSlug(object: $object);
		if ($slug === '') {
			return '';
		}

		try {
			return $this->saveUnlessExists(objectService: $objectService, registerSlug: $registerSlug, object: $object, slug: $slug);
		} catch (Throwable $e) {
			$this->logger->warning(
				'DBA seed: failed to save record',
				['schema' => self::DBA_SCHEMA, 'slug' => $slug, 'exception' => $e->getMessage()]
			);
			return '';
		}
	}//end seedObject()

	/**
	 * The seed object's slug when it is a DBAModelovereenkomst seed with a schema and slug, else ''.
	 *
	 * @param mixed $object One seed object.
	 *
	 * @return string
	 */
	private static function dbaSlug(mixed $object): string {
		if (is_array($object) === false) {
			return '';
		}

		$self   = (array)($object['@self'] ?? []);
		$schema = (string)($self['schema'] ?? '');
		$slug   = (string)($self['slug'] ?? '');
		if ($schema === '' || $slug === '' || $schema !== self::DBA_SCHEMA) {
			return '';
		}

		return $slug;
	}//end dbaSlug()

	/**
	 * Save the seed object unless a record with its slug already exists.
	 *
	 * @param object               $objectService OpenRegister's ObjectService.
	 * @param string               $registerSlug  The register slug.
	 * @param array<string, mixed> $object        The seed object.
	 * @param string               $slug          Its slug.
	 *
	 * @return string The outcome: seeded or skipped.
	 */
	private function saveUnlessExists(object $objectService, string $registerSlug, array $object, string $slug): string {
		$existing = $objectService
			->setRegister($registerSlug)
			->setSchema(self::DBA_SCHEMA)
			->findAll(['filters' => ['slug' => $slug], 'limit' => 1]);
		if (empty($existing) === false) {
			return 'skipped';
		}

		$payload = $object;
		unset($payload['@self']);
		$payload['slug'] = $slug;

		// Runs in the installer/repair context where no web user is
		// authenticated ('Anonymous'). Bypass RBAC + multi-tenancy so the
		// seed persists instead of throwing "User 'Anonymous' does not have
		// permission to 'create'" — mirrors the OR ImportHandler fix.
		$objectService->saveObject(object: $payload, register: $registerSlug, schema: self::DBA_SCHEMA, _rbac: false, _multitenancy: false);
		return 'seeded';
	}//end saveUnlessExists()

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
