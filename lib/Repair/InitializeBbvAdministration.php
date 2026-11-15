<?php

/**
 * Shillinq BBV Administration Bootstrap Repair Step
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Bootstraps newly-created BBV-tenants (administrationType ∈ {gemeente, provincie,
 * waterschap}) with the minimum mandatory book-keeping primitives:
 *
 *   - One `Reserve` record per administration named "Algemene reserve" with
 *     `soort=algemeen` and `saldoBeginJaar=0` (Task 3.8, REQ-BBV-007 D-4).
 *   - Auto-create the resultaatbestemmingsregel taakveld `0.10` "Mutaties
 *     reserves" if it is missing (REQ-BBV-004 — all reserve mutations must
 *     book on this taakveld).
 *
 * Voorzieningen are explicitly NOT seeded; operators must declare them with the
 * underlying onderbouwingsdocument (BBV art. 44). Idempotent on (administrationId,
 * naam) — re-runs skip existing rows and preserve operator edits.
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
 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Repair\Support\RunsUnderSystemIdentity;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Repair step bootstrapping BBV-administrations with mandatory primitives.
 *
 * Per ADR-031 §"PHP guards remain a legitimate seam", this is a bootstrap-only
 * helper — no domain logic — that wires the declarative engine's prerequisites
 * (algemene reserve, taakveld 0.10) for every BBV-tenant. Skipped for non-BBV
 * administrations so generic SMB/ZZP installs are untouched.
 *
 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md
 */
class InitializeBbvAdministration implements IRepairStep {
	use RunsUnderSystemIdentity;

	private const BBV_ADMINISTRATION_TYPES = ['municipality', 'province', 'waterAuthority'];

	private const RESERVE_TAAKVELD = '0.10';

	private const RESERVE_TAAKVELD_NAAM = 'Mutaties reserves';

	private const ALGEMENE_RESERVE_NAAM = 'Algemene reserve';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container — ObjectService is fetched lazily.
	 * @param IAppConfig $appConfig App config used to resolve the register slug.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Repair step name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Bootstrap BBV-administraties with algemene reserve and taakveld 0.10';
	}//end getName()

	/**
	 * Resolve the configured register slug (falls back to 'shillinq').
	 *
	 * @return string
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()

	/**
	 * Run the repair step.
	 *
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-administration/spec.md
	 */
	public function run(IOutput $output): void {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			$output->info('Shillinq: ObjectService unavailable, skipping BBV-administration bootstrap');
			return;
		}

		// Under a system identity: an upgrade has no session, and OpenRegister
		// refuses `create` for 'Anonymous'. Without it this bootstrap writes
		// nothing and says so only in a warning, which does not fail an upgrade.
		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($objectService, $output): void {
				$this->runInner(objectService: $objectService, output: $output);
			}
		);
	}//end run()

	/**
	 * The bootstrap itself.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param IOutput $output The output interface for progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-administration/spec.md
	 */
	private function runInner(object $objectService, IOutput $output): void {

		$registerSlug = $this->getRegisterSlug();

		$administrations = $this->administrationsOrSkip(objectService: $objectService, registerSlug: $registerSlug, output: $output);
		if ($administrations === null) {
			return;
		}

		$totals = ['reserves' => 0, 'skippedReserves' => 0, 'taskFields' => 0];
		foreach ($administrations as $administration) {
			$totals = $this->bootstrapAdministration(
				objectService: $objectService,
				registerSlug: $registerSlug,
				administration: $administration,
				totals: $totals
			);
		}

		$message = 'BBV-administration bootstrap: %d algemene reserve(s) created, %d skipped; %d reserve-taakveld(en) created.';
		$output->info(sprintf($message, $totals['reserves'], $totals['skippedReserves'], $totals['taskFields']));

	}//end runInner()

	/**
	 * The administrations to bootstrap, or null (with a note) when the register has none or no Administration schema.
	 *
	 * @param object  $objectService OpenRegister's ObjectService.
	 * @param string  $registerSlug  The register slug.
	 * @param IOutput $output        Progress output.
	 *
	 * @return array<int, mixed>|null
	 */
	private function administrationsOrSkip(object $objectService, string $registerSlug, IOutput $output): array|null {
		try {
			$administrations = $objectService
				->setRegister($registerSlug)
				->setSchema('Administration')
				->findAll(['limit' => 500]);
		} catch (\Throwable $e) {
			$output->info('Shillinq: Administration register not yet present, skipping BBV-administration bootstrap');
			return null;
		}

		if (empty($administrations) === true) {
			$output->info('Shillinq: no administrations found, skipping BBV-administration bootstrap');
			return null;
		}

		return $administrations;
	}//end administrationsOrSkip()

	/**
	 * Seed the algemene reserve and the reserve task field of one BBV administration, and add to the totals.
	 *
	 * @param object             $objectService  OpenRegister's ObjectService.
	 * @param string             $registerSlug   The register slug.
	 * @param mixed              $administration One Administration row.
	 * @param array<string, int> $totals         The totals so far.
	 *
	 * @return array<string, int>
	 */
	private function bootstrapAdministration(object $objectService, string $registerSlug, mixed $administration, array $totals): array {
		$row  = $this->toArray(object: $administration);
		$type = ($row['administrationType'] ?? null);

		$administrationId = ($row['id'] ?? $row['uuid'] ?? null);
		if (in_array($type, self::BBV_ADMINISTRATION_TYPES, true) === false || $administrationId === null) {
			return $totals;
		}

		$reserveResult = $this->ensureAlgemeneReserve(
			objectService: $objectService,
			registerSlug: $registerSlug,
			administrationId: (string)$administrationId
		);
		$totals['reserves']        += $reserveResult['seeded'];
		$totals['skippedReserves'] += $reserveResult['skipped'];

		$taskFieldResult = $this->ensureReserveTaskField(objectService: $objectService, registerSlug: $registerSlug, overheidslaag: (string)$type);
		$totals['taskFields'] += $taskFieldResult['seeded'];

		return $totals;
	}//end bootstrapAdministration()

	/**
	 * Ensure an Algemene reserve exists for the given administration.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param string $registerSlug Register slug.
	 * @param string $administrationId Administration identifier.
	 *
	 * @return array{seeded:int,skipped:int}
	 */
	private function ensureAlgemeneReserve(object $objectService, string $registerSlug, string $administrationId): array {
		try {
			$existing = $objectService
				->setRegister($registerSlug)
				->setSchema('Reserve')
				->findAll(
					[
						'filters' => [
							'administrationId' => $administrationId,
							'name' => self::ALGEMENE_RESERVE_NAAM,
						],
						'limit' => 1,
					]
				);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Shillinq: Reserve register unavailable, skipping algemene reserve bootstrap',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);

			return ['seeded' => 0, 'skipped' => 0];
		}//end try

		if (empty($existing) === false) {
			return ['seeded' => 0, 'skipped' => 1];
		}

		$payload = [
			'administrationId' => $administrationId,
			'name' => self::ALGEMENE_RESERVE_NAAM,
			'kind' => 'algemeen',
			'saldoBeginJaar' => 0,
			'rentetoerekening' => false,
			'_meta' => [
				'source' => 'bootstrap',
				'createdBy' => 'InitializeBbvAdministration',
			],
		];

		try {
			$objectService->saveObject(object: $payload, register: $registerSlug, schema: 'Reserve');
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Shillinq: failed to seed algemene reserve',
				['administrationId' => $administrationId, 'exception' => $e->getMessage()]
			);
			return ['seeded' => 0, 'skipped' => 0];
		}

		return ['seeded' => 1, 'skipped' => 0];
	}//end ensureAlgemeneReserve()

	/**
	 * Ensure taakveld 0.10 "Mutaties reserves" exists for the overheidslaag.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param string $registerSlug Register slug.
	 * @param string $overheidslaag Either gemeente, provincie or waterschap.
	 *
	 * @return array{seeded:int,skipped:int}
	 */
	private function ensureReserveTaskField(object $objectService, string $registerSlug, string $overheidslaag): array {
		try {
			$existing = $objectService
				->setRegister($registerSlug)
				->setSchema('Taakveld')
				->findAll(
					[
						'filters' => [
							'code' => self::RESERVE_TAAKVELD,
							'overheidslaag' => $overheidslaag,
						],
						'limit' => 1,
					]
				);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Shillinq: Taakveld register unavailable, skipping reserve-taakveld bootstrap',
				['overheidslaag' => $overheidslaag, 'exception' => $e->getMessage()]
			);

			return ['seeded' => 0, 'skipped' => 0];
		}//end try

		if (empty($existing) === false) {
			return ['seeded' => 0, 'skipped' => 1];
		}

		$payload = [
			'code' => self::RESERVE_TAAKVELD,
			'name' => self::RESERVE_TAAKVELD_NAAM,
			'mainFunction' => 0,
			'mainFunctionName' => 'Bestuur en ondersteuning',
			'descriptionIv3' => 'Resultaatbestemming: dotaties en onttrekkingen aan reserves (bootstrap).',
			'overheidslaag' => $overheidslaag,
			'validFrom' => '2025-01-01',
			'_meta' => [
				'source' => 'bootstrap',
				'createdBy' => 'InitializeBbvAdministration',
			],
		];

		try {
			$objectService->saveObject(object: $payload, register: $registerSlug, schema: 'Taakveld');
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Shillinq: failed to seed reserve-taakveld',
				['overheidslaag' => $overheidslaag, 'exception' => $e->getMessage()]
			);
			return ['seeded' => 0, 'skipped' => 0];
		}

		return ['seeded' => 1, 'skipped' => 0];
	}//end ensureReserveTaakveld()

	/**
	 * Normalise a heterogeneous OR object into an associative array.
	 *
	 * @param mixed $object Object or array returned by OR ObjectService.
	 *
	 * @return array<string,mixed>
	 */
	private function toArray($object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true) {
			if (method_exists($object, 'jsonSerialize') === true) {
				$payload = $object->jsonSerialize();
				if (is_array($payload) === true) {
					return $payload;
				}
			}

			return (array)$object;
		}

		return [];
	}//end toArray()
}//end class
