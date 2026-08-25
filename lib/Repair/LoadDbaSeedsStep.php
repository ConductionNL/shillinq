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

use OCA\Shillinq\AppInfo\Application;
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

		if (file_exists($this->seedPath) === false) {
			$output->warning('DBA seed fragment not found at ' . $this->seedPath . ', skipping');
			return;
		}

		$content = file_get_contents($this->seedPath);
		if ($content === false) {
			$output->warning('Failed to read DBA seed fragment, skipping');
			return;
		}

		$data = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($data) === false) {
			$output->warning('Failed to parse DBA seed fragment: ' . json_last_error_msg());
			return;
		}

		$objects = ($data['objects'] ?? []);
		if (is_array($objects) === false || $objects === []) {
			$output->info('DBA seed fragment carries no objects; nothing to seed');
			return;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$output->warning('OpenRegister ObjectService unavailable; skipping DBA seed: ' . $e->getMessage());
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
		$seeded = 0;
		$skipped = 0;

		foreach ($objects as $object) {
			if (is_array($object) === false) {
				continue;
			}

			$self = (array)($object['@self'] ?? []);
			$schema = (string)($self['schema'] ?? '');
			$slug = (string)($self['slug'] ?? '');
			if ($schema === '' || $slug === '') {
				continue;
			}

			if ($schema !== 'DBAModelovereenkomst') {
				continue;
			}

			try {
				$existing = $objectService
					->setRegister($registerSlug)
					->setSchema($schema)
					->findAll(['filters' => ['slug' => $slug], 'limit' => 1]);
				if (empty($existing) === false) {
					$skipped++;
					continue;
				}

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
				$seeded++;
			} catch (Throwable $e) {
				$this->logger->warning(
					'DBA seed: failed to save record',
					['schema' => $schema, 'slug' => $slug, 'exception' => $e->getMessage()]
				);
			}//end try
		}//end foreach

		$output->info(
			sprintf(
				'DBA modelovereenkomst seed: %d created, %d skipped (already exist).',
				$seeded,
				$skipped,
			)
		);
	}//end runInner()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
