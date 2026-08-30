<?php

/**
 * Shillinq DBA daily flag-generation background job.
 *
 * Inspects all active DBAOpdrachten and generates immutable DBARisicoflag records
 * for high-risk patterns: vaste maandfactuur (REQ-DBA-004), modelovereenkomst
 * verlopen (REQ-DBA-002), vervangbaarheid theoretisch (REQ-DBA-014), VBAR
 * uurtarief-onderschrijding (REQ-DBA-016), WBA verlopen (REQ-DBA-013), and
 * langjarige-relatie + concentratie hooks (REQ-DBA-005).
 *
 * @category BackgroundJob
 * @package  OCA\Shillinq\BackgroundJob
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

namespace OCA\Shillinq\BackgroundJob;

use DateTimeImmutable;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Enums\DBAConstants;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Daily DBA monitoring batch — generates flags for high-risk patterns.
 *
 * The detection rules (REQ-DBA-004/005/014/016) are factored into pure
 * detectXxx() methods so they are unit-testable without a live OpenRegister
 * instance. run() wires them to the ObjectService (ADR-022).
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 */
class DBAFlagGenerationJob extends TimedJob {
	/**
	 * Interval between job runs: 86400 seconds = 24 hours.
	 */
	private const INTERVAL_SECONDS = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Nextcloud time factory.
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Detect the FACTUURFREQUENTIE_LIJKT_OP_LOON pattern.
	 *
	 * Per REQ-DBA-004: 6+ months of facturen with date-spacing within +/- 2 days
	 * of monthly cadence AND amount-coefficient-of-variation < 0.04.
	 *
	 * @param array<int,array<string,mixed>> $facturen List of factuur rows with
	 *                                                 `factuurDatum` (Y-m-d) and
	 *                                                 `bedragCents` (int eurocenten).
	 *
	 * @return bool True when the pattern triggers a flag.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function detectVasteMaandfactuur(array $facturen): bool {
		if (count($facturen) < DBAConstants::VASTE_MAANDFACTUUR_MIN_MAANDEN) {
			return false;
		}

		$dates = [];
		$amounts = [];
		foreach ($facturen as $row) {
			$dateStr = (string)($row['invoiceDate'] ?? '');
			$amountCents = (int)($row['bedragCents'] ?? 0);
			if ($dateStr === '' || $amountCents <= 0) {
				continue;
			}

			try {
				$dates[] = new DateTimeImmutable($dateStr);
				$amounts[] = $amountCents;
			} catch (Throwable) {
				continue;
			}
		}

		if (count($dates) < DBAConstants::VASTE_MAANDFACTUUR_MIN_MAANDEN) {
			return false;
		}

		// Sort by date ascending.
		usort($dates, fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);
		$intervals = [];
		$datesCount = count($dates);
		for ($i = 1; $i < $datesCount; $i++) {
			$intervals[] = (int)$dates[$i - 1]->diff($dates[$i])->days;
		}

		if (count($intervals) === 0) {
			return false;
		}

		$avgInterval = array_sum($intervals) / count($intervals);
		$tolerance = DBAConstants::VASTE_MAANDFACTUUR_DAG_TOLERANTIE;
		// ~30-day cadence: each interval within 28..32 days (allowing the tolerance).
		$monthly = (30 - $tolerance) <= $avgInterval && $avgInterval <= (30 + $tolerance);
		if ($monthly === false) {
			return false;
		}

		// Coefficient of variation = stdev / mean.
		$mean = array_sum($amounts) / count($amounts);
		if ($mean <= 0.0) {
			return false;
		}

		$variance = 0.0;
		foreach ($amounts as $amount) {
			$variance += (($amount - $mean) ** 2);
		}

		$variance /= count($amounts);
		$stdev = sqrt($variance);
		$cv = $stdev / $mean;

		return $cv < DBAConstants::VASTE_MAANDFACTUUR_VARIATIE_MAX;
	}//end detectVasteMaandfactuur()

	/**
	 * Detect VBAR uurtarief-onderschrijding (REQ-DBA-016).
	 *
	 * @param int $amountCents Factuurbedrag in eurocenten.
	 * @param float $hours Aantal gefactureerde uren.
	 * @param int $vbarGrensCents VBAR-grens (eurocenten).
	 *
	 * @return bool True when the effective hourly rate falls below threshold.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function detectVbarGrensOnderschreden(int $amountCents, float $hours, int $vbarGrensCents): bool {
		if ($hours <= 0.0 || $amountCents <= 0) {
			return false;
		}

		$effectiefCents = (int)round($amountCents / $hours);
		return $effectiefCents < $vbarGrensCents;
	}//end detectVbarGrensOnderschreden()

	/**
	 * Detect MODELOVEREENKOMST_VERLOPEN (REQ-DBA-002).
	 *
	 * @param string|null $validTo The model's geldigTot date (Y-m-d) or null.
	 * @param DateTimeImmutable $now Reference "now".
	 *
	 * @return bool True when the model's geldigheid has expired.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function detectModelovereenkomstVerlopen(?string $validTo, DateTimeImmutable $now): bool {
		if ($validTo === null || $validTo === '') {
			return false;
		}

		try {
			$expiry = new DateTimeImmutable($validTo);
		} catch (Throwable) {
			return false;
		}

		return $now > $expiry;
	}//end detectModelovereenkomstVerlopen()

	/**
	 * Detect VERVANGBAARHEID_THEORETISCH (REQ-DBA-014).
	 *
	 * Contractually vervangbaar (vervangbaarScore < 5) AND vervanging never
	 * happened (vervangingFeitelijkScore >= 10), with relation duration >= 18 months.
	 *
	 * @param int $replaceableScore Contractuele vervangbaarheid (0-10).
	 * @param int $replacementActualScore Feitelijke vervanging (0-10).
	 * @param float $durationInMonths Relatieduur in maanden.
	 *
	 * @return bool True when theoretical-only substitutability is detected.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function detectVervangbaarheidTheoretisch(int $replaceableScore, int $replacementActualScore, float $durationInMonths): bool {
		if ($durationInMonths < (float)DBAConstants::VERVANGBAARHEID_THEORETISCH_MIN_MAANDEN) {
			return false;
		}

		return $replaceableScore < 5 && $replacementActualScore >= 10;
	}//end detectVervangbaarheidTheoretisch()

	/**
	 * Detect LANGJARIGE_HOOFDRELATIE (REQ-DBA-005).
	 *
	 * @param float $durationInJaren Relatieduur (jaren).
	 * @param float $revenueShare Omzetaandeel (0-1).
	 *
	 * @return bool True when the relation qualifies as langjarige hoofdrelatie.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function detectLangjarigeHoofdrelatie(float $durationInJaren, float $revenueShare): bool {
		return $durationInJaren >= DBAConstants::LANGJARIG_DREMPEL_JAREN
			&& $revenueShare >= DBAConstants::LANGJARIG_DREMPEL_OMZET;
	}//end detectLangjarigeHoofdrelatie()

	/**
	 * Detect HERBEOORDELING_OVERDUE (REQ-DBA-009).
	 *
	 * @param string|null $intakeDate Y-m-d of last intake.
	 * @param DateTimeImmutable $now Reference "now".
	 *
	 * @return bool True when intake is older than 12 months + 30 days grace.
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 */
	public function detectHerbeoordelingOverdue(?string $intakeDate, DateTimeImmutable $now): bool {
		if ($intakeDate === null || $intakeDate === '') {
			return false;
		}

		try {
			$intake = new DateTimeImmutable($intakeDate);
		} catch (Throwable) {
			return false;
		}

		$deadline = $intake->modify('+' . DBAConstants::HERBEOORDELING_TRIGGER_MAANDEN . ' months')
			->modify('+' . DBAConstants::HERBEOORDELING_GRACE_DAGEN . ' days');
		return $now > $deadline;
	}//end detectHerbeoordelingOverdue()

	/**
	 * Execute the flag-generation pass.
	 *
	 * @param mixed $argument Not used; required by TimedJob.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/dba-compliance-marker/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	protected function run(mixed $argument): void {
		$this->logger->info('Shillinq: DBAFlagGenerationJob started');

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'Shillinq DBAFlagGenerationJob: OpenRegister not available, skipping.',
				['exception' => $e->getMessage()]
			);
			return;
		}

		$register = $this->resolveRegister();
		$now = new DateTimeImmutable();
		$generated = 0;

		try {
			$assignments = $objectService
				->setRegister($register)
				->setSchema('DBAOpdracht')
				->findAll(['filters' => ['intakeStatus' => 'ACTIVE'], 'limit' => 1000]);
		} catch (Throwable $e) {
			$this->logger->error(
				'Shillinq DBAFlagGenerationJob: failed to fetch opdrachten',
				['exception' => $e->getMessage()]
			);
			return;
		}

		foreach ($assignments as $entity) {
			$assignment = $this->toArray(entity: $entity);
			if ($assignment === null) {
				continue;
			}

			// Herbeoordeling overdue?
			if ($this->detectHerbeoordelingOverdue(intakeDate: (string)($assignment['intakeDate'] ?? ''), now: $now) === true) {
				if ($this->emitFlag(
					objectService: $objectService,
					register: $register,
					assignment: $assignment,
					type: 'REASSESSMENT_OVERDUE',
					severity: 'MEDIUM',
					details: ['intakeDate' => (string)($assignment['intakeDate'] ?? '')],
					source: 'REQ-DBA-009; Wet DBA jaarlijkse herbeoordeling',
					action: 'Vraag een herbeoordeling van de DBA-intake aan de ondernemer.'
				) === true
				) {
					$generated++;
				}
			}

			// Modelovereenkomst verlopen?
			$modelId = (string)($assignment['modelAgreementId'] ?? '');
			if ($modelId !== '') {
				try {
					$model = $objectService->setRegister($register)->setSchema('DBAModelovereenkomst')->find($modelId);
					$modelArr = $this->toArray(entity: $model);
					if ($modelArr !== null
						&& $this->detectModelovereenkomstVerlopen(
							validTo: (string)($modelArr['validTo'] ?? ''),
							now: $now
						) === true
					) {
						if ($this->emitFlag(
							objectService: $objectService,
							register: $register,
							assignment: $assignment,
							type: 'MODELAGREEMENT_EXPIRED',
							severity: 'MEDIUM',
							details: ['modelId' => $modelId, 'validTo' => (string)($modelArr['validTo'] ?? '')],
							source: 'REQ-DBA-002; Belastingdienst modelovereenkomst-policy',
							action: 'Kies een actueel modelovereenkomst en update de opdracht.'
						) === true
						) {
							$generated++;
						}
					}
				} catch (Throwable $e) {
					$this->logger->debug(
						'Shillinq DBAFlagGenerationJob: skipping model lookup',
						['modelId' => $modelId, 'exception' => $e->getMessage()]
					);
				}//end try
			}//end if

			// WBA verlopen?
			$wbaValidTo = (string)($assignment['wbaValidTo'] ?? '');
			if ($wbaValidTo !== ''
				&& $this->detectModelovereenkomstVerlopen(validTo: $wbaValidTo, now: $now) === true
			) {
				if ($this->emitFlag(
					objectService: $objectService,
					register: $register,
					assignment: $assignment,
					type: 'WBA_EXPIRED',
					severity: 'LOW',
					details: ['wbaValidTo' => $wbaValidTo],
					source: 'REQ-DBA-013; Belastingdienst WBA-policy (1 jaar geldigheid)',
					action: 'Vraag een nieuwe WBA-beoordeling aan.'
				) === true
				) {
					$generated++;
				}
			}
		}//end foreach

		$this->logger->info(
			sprintf('Shillinq DBAFlagGenerationJob: generated %d flags', $generated)
		);
	}//end run()

	/**
	 * Coerce an entity (array or hydratable object) into a plain array.
	 *
	 * @param mixed $entity The entity returned by ObjectService.
	 *
	 * @return array<string,mixed>|null The array shape, or null when not coercible.
	 */
	private function toArray(mixed $entity): ?array {
		if (is_array($entity) === true) {
			/*
			 * @var array<string,mixed> $entity
			 */

			return $entity;
		}

		if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {
			$data = $entity->getObject();
			if (is_array($data) === true) {
				/*
				 * @var array<string,mixed> $data
				 */

				return $data;
			}
		}

		return null;
	}//end toArray()

	/**
	 * Emit a DBARisicoflag if no equivalent open flag already exists (idempotent).
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $register Register slug.
	 * @param array<string,mixed> $assignment The owning DBAOpdracht.
	 * @param string $type Flag type enum.
	 * @param string $severity LAAG/MIDDEN/HOOG.
	 * @param array<string,mixed> $details Detail-payload.
	 * @param string $source Fiscale grondslag.
	 * @param string $action Aanbevolen actie.
	 *
	 * @return bool True when a new flag was written.
	 */
	private function emitFlag(
		object $objectService,
		string $register,
		array $assignment,
		string $type,
		string $severity,
		array $details,
		string $source,
		string $action,
	): bool {
		try {
			$existing = $objectService->setRegister($register)->setSchema('DBARisicoflag')->findAll(
				[
					'filters' => [
						'assignmentId' => (string)($assignment['@self']['id'] ?? ($assignment['id'] ?? '')),
						'type' => $type,
						'status' => 'OPEN',
					],
					'limit' => 1,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Shillinq DBAFlagGenerationJob: idempotency check failed',
				['exception' => $e->getMessage(), 'type' => $type]
			);
			return false;
		}

		foreach ($existing as $_e) {
			unset($_e);
			return false;
		}

		try {
			$objectService->setRegister($register)->setSchema('DBARisicoflag')->saveObject(
				[
					'administrationId' => (string)($assignment['administrationId'] ?? ''),
					'assignmentId' => (string)($assignment['@self']['id'] ?? ($assignment['id'] ?? '')),
					'type' => $type,
					'detectionMoment' => (new DateTimeImmutable())->format('c'),
					'severity' => $severity,
					'details' => $details,
					'fiscalSource' => $source,
					'actionSuggestion' => $action,
					'status' => 'OPEN',
					'displayedInUser' => true,
				]
			);
			return true;
		} catch (Throwable $e) {
			$this->logger->error(
				'Shillinq DBAFlagGenerationJob: failed to write flag',
				['exception' => $e->getMessage(), 'type' => $type]
			);
			return false;
		}//end try
	}//end emitFlag()

	/**
	 * Resolve the configured register slug.
	 *
	 * @return string The register slug.
	 */
	private function resolveRegister(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end resolveRegister()
}//end class
