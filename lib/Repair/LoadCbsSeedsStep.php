<?php

/**
 * CBS Submissions Seed Repair Step.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Declarative repair step that idempotently seeds the CBSSubmission and
 * CBSLine example records shipped with the bookkeeping-cbs-bestanden-extended
 * change. Per ADR-037 the canonical seeds live in
 * `lib/Settings/register.d/bookkeeping-cbs-bestanden-extended.json` `objects[]`
 * and are auto-loaded by `SettingsService::loadConfigurationForced()` (which
 * `InitializeSettings` already runs). This repair step is an explicit
 * additional safety net: when the main configuration import is skipped because
 * the register fragment version is unchanged but the operator has manually
 * cleared the seed records, this step deduplicates on slug and re-creates the
 * three canonical CBSSubmission + three CBSLine fixtures.
 *
 * The step is non-fatal — a failure here logs a warning but does not abort the
 * upgrade. The post-migration phase is the appropriate hook (we depend on
 * OpenRegister being installed and the shillinq register slug being available).
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
 * @spec openspec/specs/bookkeeping-cbs-bestanden-extended/spec.md
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
 * Idempotently seeds CBSSubmission + CBSLine fixtures from the change fragment.
 *
 * @spec openspec/specs/bookkeeping-cbs-bestanden-extended/spec.md
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Pre-existing debt (issue
 *     #506): inherent branch complexity in this domain logic; deferred
 *     pending a dedicated refactor.
 */
class LoadCbsSeedsStep implements IRepairStep {
	use \OCA\Shillinq\Repair\Support\RunsUnderSystemIdentity;

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
		$this->seedPath = __DIR__ . '/../Settings/register.d/bookkeeping-cbs-bestanden-extended.json';

	}//end __construct()

	/**
	 * Human-readable name of this repair step.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Seed CBS Submissions example records (bookkeeping-cbs-bestanden-extended)';
	}//end getName()

	/**
	 * Run the repair step.
	 *
	 * Reads the canonical fragment, walks the `objects[]` array, and for each
	 * CBSSubmission / CBSLine object checks whether an object with the same
	 * `@self.slug` already exists in OpenRegister; if not, saves it via the
	 * real OR ObjectService API (`saveObject` named-arg form per the OR-API
	 * memory). Non-fatal on any failure.
	 *
	 * @param IOutput $output Progress output interface.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-administration/spec.md
	 */
	public function run(IOutput $output): void {
		$output->info('Seeding CBSSubmission + CBSLine example records...');

		$data = $this->readSeedFragment(output: $output);
		if ($data === null) {
			return;
		}

		$objects = ($data['objects'] ?? []);
		if (is_array($objects) === false || $objects === []) {
			$output->info('CBS seed fragment carries no objects; nothing to seed');
			return;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			$output->warning('OpenRegister ObjectService unavailable; skipping CBS seed: ' . $e->getMessage());
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
	 * Read and decode the CBS seed fragment, or null (reported) when it is missing, unreadable or not a JSON object.
	 *
	 * @param IOutput $output Progress output interface.
	 *
	 * @return array<mixed>|null The decoded fragment.
	 *
	 * @spec openspec/specs/app-administration/spec.md
	 */
	private function readSeedFragment(IOutput $output): array|null {
		if (file_exists($this->seedPath) === false) {
			$output->warning('CBS seed fragment not found at ' . $this->seedPath . ', skipping');
			return null;
		}

		$content = file_get_contents($this->seedPath);
		if ($content === false) {
			$output->warning('Failed to read CBS seed fragment, skipping');
			return null;
		}

		$data = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($data) === false) {
			$output->warning('Failed to parse CBS seed fragment: ' . json_last_error_msg());
			return null;
		}

		return $data;
	}//end readSeedFragment()

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
		$counts = ['seeded' => 0, 'skipped' => 0, 'failed' => 0];

		foreach ($objects as $object) {
			$identity = $this->seedIdentity(object: $object);
			if ($identity === null) {
				continue;
			}

			$outcome = $this->seedObject(objectService: $objectService, registerSlug: $registerSlug, object: $object, identity: $identity);
			$counts[$outcome]++;
		}

		$output->info(
			sprintf(
				'CBS seed: %d created, %d skipped (already exist).',
				$counts['seeded'],
				$counts['skipped'],
			)
		);

	}//end runInner()

	/**
	 * The schema and slug of a seedable CBSSubmission / CBSLine object, or null for anything else.
	 *
	 * @param mixed $object One entry of the fragment's `objects[]`.
	 *
	 * @return array{0: string, 1: string}|null [schema, slug].
	 *
	 * @spec openspec/specs/app-administration/spec.md
	 */
	private function seedIdentity(mixed $object): array|null {
		if (is_array($object) === false) {
			return null;
		}

		$self = (array)($object['@self'] ?? []);
		$schema = (string)($self['schema'] ?? '');
		$slug = (string)($self['slug'] ?? '');
		if ($schema === '' || $slug === '') {
			return null;
		}

		if (in_array($schema, ['CBSSubmission', 'CBSLine'], true) === false) {
			return null;
		}

		return [$schema, $slug];
	}//end seedIdentity()

	/**
	 * Save one seed object unless an object with its slug already exists; failures are logged.
	 *
	 * @param object               $objectService OpenRegister's ObjectService.
	 * @param string               $registerSlug  The register to write to.
	 * @param array<string,mixed>  $object        The seed object.
	 * @param array{0: string, 1: string} $identity      [schema, slug].
	 *
	 * @return string One of `seeded`, `skipped` or `failed`.
	 *
	 * @spec openspec/specs/app-administration/spec.md
	 */
	private function seedObject(object $objectService, string $registerSlug, array $object, array $identity): string {
		[$schema, $slug] = $identity;

		try {
			$existing = $objectService
				->setRegister($registerSlug)
				->setSchema($schema)
				->findAll(
					[
						'filters' => ['slug' => $slug],
						'limit' => 1,
					]
				);

			if (empty($existing) === false) {
				return 'skipped';
			}

			// Drop the @self envelope before persisting — the OR backend
			// honours register + schema named args, not the inline block.
			$payload = $object;
			unset($payload['@self']);
			$payload['slug'] = $slug;

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
			return 'seeded';
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CBS seed: failed to save record',
				['schema' => $schema, 'slug' => $slug, 'exception' => $e->getMessage()]
			);
			return 'failed';
		}//end try
	}//end seedObject()

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
