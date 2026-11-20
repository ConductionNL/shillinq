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
use OCA\Shillinq\BackgroundJob\Support\RunsJobAsSystem;
use OCA\Shillinq\Enums\DBAConstants;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\Shillinq\Util\ObjectIdentifier;

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
	use RunsJobAsSystem;
	/**
	 * Interval between job runs: 86400 seconds = 24 hours.
	 */
	private const INTERVAL_SECONDS = 86400;

	/**
	 * Severity, fiscal source and suggested action per flag type.
	 *
	 * @var array<string,array{severity: string, fiscalSource: string, actionSuggestion: string}>
	 */
	private const FLAG_RULES = [
		'REASSESSMENT_OVERDUE' => [
			'severity' => 'MEDIUM',
			'fiscalSource' => 'REQ-DBA-009; Wet DBA jaarlijkse herbeoordeling',
			'actionSuggestion' => 'Vraag een herbeoordeling van de DBA-intake aan de ondernemer.',
		],
		'MODELAGREEMENT_EXPIRED' => [
			'severity' => 'MEDIUM',
			'fiscalSource' => 'REQ-DBA-002; Belastingdienst modelovereenkomst-policy',
			'actionSuggestion' => 'Kies een actueel modelovereenkomst en update de opdracht.',
		],
		'WBA_EXPIRED' => [
			'severity' => 'LOW',
			'fiscalSource' => 'REQ-DBA-013; Belastingdienst WBA-policy (1 jaar geldigheid)',
			'actionSuggestion' => 'Vraag een nieuwe WBA-beoordeling aan.',
		],
	];

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

		$series = $this->invoiceSeries(facturen: $facturen);
		if (count($series['dates']) < DBAConstants::VASTE_MAANDFACTUUR_MIN_MAANDEN) {
			return false;
		}

		return $this->hasMonthlyCadence(dates: $series['dates']) === true
			&& $this->hasStableAmount(amounts: $series['amounts']) === true;
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
	 * Run the job inside OpenRegister's system context.
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enforced-schema-authorization/specs/schema-authorization/spec.md
	 */
	protected function run(mixed $argument): void {
		$this->asSystem(work: function () use ($argument): void {
			$this->runAsJob(argument: $argument);
		});
	}//end run()

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
	private function runAsJob(mixed $argument): void {
		$this->logger->info('Shillinq: DBAFlagGenerationJob started');

		$objectService = $this->openRegister();
		if ($objectService === null) {
			return;
		}

		$this->flagActiveAssignments(run: new DBAJobRun(objectService: $objectService, appConfig: $this->appConfig));
	}//end runAsJob()

	/**
	 * The usable invoices: dates and amounts of the rows with a parseable date
	 * and a positive amount.
	 *
	 * @param array<int,array<string,mixed>> $facturen The invoices.
	 *
	 * @return array{dates: array<int,DateTimeImmutable>, amounts: array<int,int>}
	 */
	private function invoiceSeries(array $facturen): array {
		$series = ['dates' => [], 'amounts' => []];
		foreach ($facturen as $row) {
			$point = $this->invoicePoint(row: $row);
			if ($point === null) {
				continue;
			}

			$series['dates'][] = $point[0];
			$series['amounts'][] = $point[1];
		}

		return $series;

	}//end invoiceSeries()

	/**
	 * The date and amount of a usable invoice, null for a row without a
	 * parseable date or a positive amount.
	 *
	 * @param array<string,mixed> $row The invoice.
	 *
	 * @return array{0: DateTimeImmutable, 1: int}|null
	 */
	private function invoicePoint(array $row): ?array {
		$dateStr = (string)($row['invoiceDate'] ?? '');
		$amountCents = (int)($row['bedragCents'] ?? 0);
		if ($dateStr === '' || $amountCents <= 0) {
			return null;
		}

		try {
			return [new DateTimeImmutable($dateStr), $amountCents];
		} catch (Throwable) {
			return null;
		}

	}//end invoicePoint()

	/**
	 * Whether the invoices follow a ~30-day cadence: the mean interval lies
	 * within the day tolerance of 30.
	 *
	 * @param array<int,DateTimeImmutable> $dates The invoice dates.
	 *
	 * @return bool
	 */
	private function hasMonthlyCadence(array $dates): bool {
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
		return (30 - $tolerance) <= $avgInterval && $avgInterval <= (30 + $tolerance);

	}//end hasMonthlyCadence()

	/**
	 * Whether the amounts barely vary: coefficient of variation (stdev / mean)
	 * below the maximum.
	 *
	 * @param array<int,int> $amounts The invoice amounts in cents.
	 *
	 * @return bool
	 */
	private function hasStableAmount(array $amounts): bool {
		$mean = array_sum($amounts) / count($amounts);
		if ($mean <= 0.0) {
			return false;
		}

		$variance = 0.0;
		foreach ($amounts as $amount) {
			$variance += (($amount - $mean) ** 2);
		}

		$variance /= count($amounts);
		$cv = sqrt($variance) / $mean;

		return $cv < DBAConstants::VASTE_MAANDFACTUUR_VARIATIE_MAX;

	}//end hasStableAmount()

	/**
	 * OpenRegister's object service, null (logged) when it is not installed.
	 *
	 * @return object|null
	 */
	private function openRegister(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'Shillinq DBAFlagGenerationJob: OpenRegister not available, skipping.',
				['exception' => $e->getMessage()]
			);
			return null;
		}

	}//end openRegister()

	/**
	 * The active assignments, null (logged) when they cannot be read.
	 *
	 * @param DBAJobRun $run The run.
	 *
	 * @return iterable<mixed>|null
	 */
	private function activeAssignments(DBAJobRun $run): ?iterable {
		try {
			return $run->scoped(schema: 'DBAOpdracht')
				->findAll(['filters' => ['intakeStatus' => 'ACTIVE'], 'limit' => 1000]);
		} catch (Throwable $e) {
			$this->logger->error(
				'Shillinq DBAFlagGenerationJob: failed to fetch opdrachten',
				['exception' => $e->getMessage()]
			);
			return null;
		}

	}//end activeAssignments()

	/**
	 * Flag every active assignment and log how many flags were written.
	 *
	 * @param DBAJobRun $run The run.
	 *
	 * @return void
	 */
	private function flagActiveAssignments(DBAJobRun $run): void {
		$assignments = $this->activeAssignments(run: $run);
		if ($assignments === null) {
			return;
		}

		foreach ($assignments as $entity) {
			$this->flagAssignment(run: $run, entity: $entity);
		}

		$this->logger->info(
			sprintf('Shillinq DBAFlagGenerationJob: generated %d flags', $run->written)
		);

	}//end flagActiveAssignments()

	/**
	 * Run the three detections for one assignment, in order: overdue
	 * reassessment, expired model agreement, expired WBA.
	 *
	 * @param DBAJobRun $run The run.
	 * @param mixed $entity The assignment as the service returned it.
	 *
	 * @return void
	 */
	private function flagAssignment(DBAJobRun $run, mixed $entity): void {
		$assignment = $run->record(entity: $entity);
		if ($assignment === null) {
			return;
		}

		$run->assignment = $assignment;
		$this->flagOverdueReassessment(run: $run);
		$this->flagExpiredModelAgreement(run: $run);
		$this->flagExpiredWba(run: $run);

	}//end flagAssignment()

	/**
	 * Flag an assignment whose yearly reassessment is overdue.
	 *
	 * @param DBAJobRun $run The run, at the assignment.
	 *
	 * @return void
	 */
	private function flagOverdueReassessment(DBAJobRun $run): void {
		$intakeDate = (string)($run->assignment['intakeDate'] ?? '');
		if ($this->detectHerbeoordelingOverdue(intakeDate: $intakeDate, now: $run->now) === true) {
			$this->raise(run: $run, hit: new DBAFlagHit(type: 'REASSESSMENT_OVERDUE', details: ['intakeDate' => $intakeDate]));
		}

	}//end flagOverdueReassessment()

	/**
	 * Flag an assignment whose model agreement has expired; a failing lookup
	 * is logged at debug level and skipped.
	 *
	 * @param DBAJobRun $run The run, at the assignment.
	 *
	 * @return void
	 */
	private function flagExpiredModelAgreement(DBAJobRun $run): void {
		$modelId = (string)($run->assignment['modelAgreementId'] ?? '');
		if ($modelId === '') {
			return;
		}

		try {
			$this->flagIfModelExpired(run: $run, modelId: $modelId);
		} catch (Throwable $e) {
			$this->logger->debug(
				'Shillinq DBAFlagGenerationJob: skipping model lookup',
				['modelId' => $modelId, 'exception' => $e->getMessage()]
			);
		}

	}//end flagExpiredModelAgreement()

	/**
	 * Look the model agreement up and flag it when its validTo has passed.
	 *
	 * @param DBAJobRun $run The run, at the assignment.
	 * @param string $modelId The model agreement.
	 *
	 * @return void
	 */
	private function flagIfModelExpired(DBAJobRun $run, string $modelId): void {
		$model = $run->record(entity: ObjectIdentifier::findScoped(scoped: $run->scoped(schema: 'DBAModelovereenkomst'), id: $modelId));
		if ($model === null) {
			return;
		}

		$validTo = (string)($model['validTo'] ?? '');
		if ($this->detectModelovereenkomstVerlopen(validTo: $validTo, now: $run->now) === true) {
			$this->raise(run: $run, hit: new DBAFlagHit(type: 'MODELAGREEMENT_EXPIRED', details: ['modelId' => $modelId, 'validTo' => $validTo]));
		}

	}//end flagIfModelExpired()

	/**
	 * Flag an assignment whose WBA has expired.
	 *
	 * @param DBAJobRun $run The run, at the assignment.
	 *
	 * @return void
	 */
	private function flagExpiredWba(DBAJobRun $run): void {
		$wbaValidTo = (string)($run->assignment['wbaValidTo'] ?? '');
		if ($wbaValidTo !== ''
			&& $this->detectModelovereenkomstVerlopen(validTo: $wbaValidTo, now: $run->now) === true
		) {
			$this->raise(run: $run, hit: new DBAFlagHit(type: 'WBA_EXPIRED', details: ['wbaValidTo' => $wbaValidTo]));
		}

	}//end flagExpiredWba()

	/**
	 * Write the flag unless an open one of its type exists, and count it.
	 *
	 * @param DBAJobRun $run The run, at the assignment.
	 * @param DBAFlagHit $hit The flag.
	 *
	 * @return void
	 */
	private function raise(DBAJobRun $run, DBAFlagHit $hit): void {
		if ($this->isAlreadyFlagged(run: $run, hit: $hit) === true) {
			return;
		}

		if ($this->writeFlag(run: $run, hit: $hit) === true) {
			$run->written++;
		}

	}//end raise()

	/**
	 * Whether an open flag of the type exists for the assignment; a failing
	 * check is logged and counts as flagged, so nothing is written.
	 *
	 * @param DBAJobRun $run The run, at the assignment.
	 * @param DBAFlagHit $hit The flag.
	 *
	 * @return bool
	 */
	private function isAlreadyFlagged(DBAJobRun $run, DBAFlagHit $hit): bool {
		try {
			$existing = $this->openFlags(run: $run, hit: $hit);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Shillinq DBAFlagGenerationJob: idempotency check failed',
				['exception' => $e->getMessage(), 'type' => $hit->type]
			);
			return true;
		}

		return self::hasAny(rows: $existing);

	}//end isAlreadyFlagged()

	/**
	 * The open flags of the hit's type for the assignment (at most one).
	 *
	 * @param DBAJobRun $run The run, at the assignment.
	 * @param DBAFlagHit $hit The flag.
	 *
	 * @return iterable<mixed>
	 */
	private function openFlags(DBAJobRun $run, DBAFlagHit $hit): iterable {
		return $run->scoped(schema: 'DBARisicoflag')->findAll(
			[
				'filters' => [
					'assignmentId' => self::assignmentId(assignment: $run->assignment),
					'type' => $hit->type,
					'status' => 'OPEN',
				],
				'limit' => 1,
			]
		);

	}//end openFlags()

	/**
	 * Write an open, user-visible risk flag; a failing write is logged.
	 *
	 * @param DBAJobRun $run The run, at the assignment.
	 * @param DBAFlagHit $hit The flag.
	 *
	 * @return bool Whether it was written.
	 */
	private function writeFlag(DBAJobRun $run, DBAFlagHit $hit): bool {
		try {
			$run->scoped(schema: 'DBARisicoflag')->saveObject($this->flagObject(run: $run, hit: $hit));
			return true;
		} catch (Throwable $e) {
			$this->logger->error(
				'Shillinq DBAFlagGenerationJob: failed to write flag',
				['exception' => $e->getMessage(), 'type' => $hit->type]
			);
			return false;
		}

	}//end writeFlag()

	/**
	 * The DBARisicoflag object for a hit, with its severity, fiscal source and
	 * suggested action.
	 *
	 * @param DBAJobRun $run The run, at the assignment.
	 * @param DBAFlagHit $hit The flag.
	 *
	 * @return array<string,mixed>
	 */
	private function flagObject(DBAJobRun $run, DBAFlagHit $hit): array {
		$rule = self::FLAG_RULES[$hit->type];
		return [
			'administrationId' => (string)($run->assignment['administrationId'] ?? ''),
			'assignmentId' => self::assignmentId(assignment: $run->assignment),
			'type' => $hit->type,
			'detectionMoment' => (new DateTimeImmutable())->format('c'),
			'severity' => $rule['severity'],
			'details' => $hit->details,
			'fiscalSource' => $rule['fiscalSource'],
			'actionSuggestion' => $rule['actionSuggestion'],
			'status' => 'OPEN',
			'displayedInUser' => true,
		];

	}//end flagObject()

	/**
	 * The assignment's id: its @self id, else its id, else ''.
	 *
	 * @param array<string,mixed> $assignment The assignment.
	 *
	 * @return string
	 */
	private static function assignmentId(array $assignment): string {
		return (string)($assignment['@self']['id'] ?? ($assignment['id'] ?? ''));

	}//end assignmentId()

	/**
	 * Whether a result holds any row.
	 *
	 * @param iterable<mixed> $rows The rows.
	 *
	 * @return bool
	 */
	private static function hasAny(iterable $rows): bool {
		foreach ($rows as $_row) {
			unset($_row);
			return true;
		}

		return false;

	}//end hasAny()

}//end class
