<?php

/**
 * Characterisation tests for the DBA flag generation and portfolio
 * aggregation jobs.
 *
 * Pins the detection and aggregation rules with explicit dates, and each
 * job's run: the queries it issues in order, the objects it writes, and what
 * it logs when OpenRegister, a lookup or a write fails.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Shillinq\BackgroundJob\DBAFlagGenerationJob;
use OCA\Shillinq\BackgroundJob\DBAPortfolioAggregationJob;
use OCA\Shillinq\Tests\Unit\BackgroundJob\Support\RecordingObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Pins the DBA jobs.
 */
final class DBAJobsCharacterisationTest extends TestCase {

	/**
	 * Log lines as level|message|context.
	 *
	 * @var array<int,string>
	 */
	private array $logs = [];

	/**
	 * The recording object service.
	 *
	 * @var RecordingObjectService
	 */
	private RecordingObjectService $objects;

	/**
	 * Whether OpenRegister is installed.
	 *
	 * @var boolean
	 */
	private bool $openRegister = true;

	/**
	 * The configured register slug.
	 *
	 * @var string
	 */
	private string $register = 'books';

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = new RecordingObjectService();

	}//end setUp()

	/**
	 * Constructor arguments shared by both jobs.
	 *
	 * @return array{0:ITimeFactory,1:ContainerInterface,2:IAppConfig,3:LoggerInterface}
	 */
	private function collaborators(): array {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				if ($this->openRegister === false) {
					throw new \RuntimeException('no OpenRegister');
				}

				return $this->objects;
			}
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (): string => $this->register);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = $level . '|' . $message . '|' . json_encode($context);
				}
			);
		}

		return [$this->createMock(ITimeFactory::class), $container, $config, $logger];

	}//end collaborators()

	/**
	 * Build the flag job.
	 *
	 * @return DBAFlagGenerationJob
	 */
	private function flagJob(): DBAFlagGenerationJob {
		[$time, $container, $config, $logger] = $this->collaborators();
		return new DBAFlagGenerationJob($time, $container, $config, $logger);

	}//end flagJob()

	/**
	 * Build the portfolio job.
	 *
	 * @return DBAPortfolioAggregationJob
	 */
	private function portfolioJob(): DBAPortfolioAggregationJob {
		[$time, $container, $config, $logger] = $this->collaborators();
		return new DBAPortfolioAggregationJob($time, $container, $config, $logger);

	}//end portfolioJob()

	/**
	 * Run a job's protected run().
	 *
	 * @param object $job The job.
	 *
	 * @return void
	 */
	private static function runJob(object $job): void {
		$run = new ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, null);

	}//end runJob()

	/**
	 * Monthly invoices from a start date.
	 *
	 * @param int $count Number of invoices.
	 * @param int $everyDays Days between invoices.
	 * @param array<int,int> $amounts Amount per invoice (cycled).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function invoices(int $count, int $everyDays, array $amounts): array {
		$rows = [];
		$date = new DateTimeImmutable('2025-01-01');
		for ($i = 0; $i < $count; $i++) {
			$rows[] = ['invoiceDate' => $date->format('Y-m-d'), 'bedragCents' => $amounts[$i % count($amounts)]];
			$date = $date->modify('+' . $everyDays . ' days');
		}

		return $rows;

	}//end invoices()

	/**
	 * Invoice series and whether they are a fixed monthly invoice.
	 *
	 * @return array<string,array{0:array<int,array<string,mixed>>,1:bool}>
	 */
	public static function invoiceSeries(): array {
		$six = self::invoices(6, 30, [100000]);
		$withJunk = array_merge(self::invoices(6, 30, [100000]), [['invoiceDate' => '', 'bedragCents' => 5], ['invoiceDate' => '2025-09-01', 'bedragCents' => 0], ['invoiceDate' => 'not a date', 'bedragCents' => 100000]]);
		return [
			'six monthly equal' => [$six, true],
			'five monthly equal' => [self::invoices(5, 30, [100000]), false],
			'six rows, five usable' => [array_merge(self::invoices(5, 30, [100000]), [['invoiceDate' => 'x', 'bedragCents' => 100000]]), false],
			'junk rows are skipped' => [$withJunk, true],
			'every 32 days' => [self::invoices(6, 32, [100000]), true],
			'every 33 days' => [self::invoices(6, 33, [100000]), false],
			'every 28 days' => [self::invoices(6, 28, [100000]), true],
			'every 27 days' => [self::invoices(6, 27, [100000]), false],
			'small variation' => [self::invoices(6, 30, [100000, 103000]), true],
			'large variation' => [self::invoices(6, 30, [100000, 110000]), false],
			'unsorted' => [array_reverse($six), true],
			'same day' => [self::invoices(6, 0, [100000]), false],
		];

	}//end invoiceSeries()

	/**
	 * The fixed monthly invoice rule.
	 *
	 * @param array<int,array<string,mixed>> $rows The invoices.
	 * @param bool $expected Whether they are a fixed monthly invoice.
	 *
	 * @return void
	 *
	 * @dataProvider invoiceSeries
	 */
	public function testFixedMonthlyInvoice(array $rows, bool $expected): void {
		self::assertSame($expected, $this->flagJob()->detectVasteMaandfactuur($rows));

	}//end testFixedMonthlyInvoice()

	/**
	 * The concentration figures.
	 *
	 * @return void
	 */
	public function testConcentration(): void {
		$job = $this->portfolioJob();
		$safe = ['largestCustomer' => null, 'revenueShare12m' => 0.0, 'thresholdHigh' => 0.70, 'status' => 'VEILIG'];
		self::assertSame($safe, $job->computeConcentratie([]));
		self::assertSame($safe, $job->computeConcentratie([['customerId' => '', 'realisedRevenue' => 10], ['customerId' => 'c', 'realisedRevenue' => 0]]));
		self::assertSame(
			['largestCustomer' => 'a', 'revenueShare12m' => 0.8, 'thresholdHigh' => 0.70, 'status' => 'WARNING'],
			$job->computeConcentratie([['customerId' => 'a', 'realisedRevenue' => 100], ['customerId' => 'b', 'realisedRevenue' => 50], ['customerId' => 'a', 'realisedRevenue' => 100]])
		);
		self::assertSame('WARNING', $job->computeConcentratie([['customerId' => 'a', 'realisedRevenue' => 70], ['customerId' => 'b', 'realisedRevenue' => 30]])['status']);
		self::assertSame('CRITICAL', $job->computeConcentratie([['customerId' => 'a', 'realisedRevenue' => 85], ['customerId' => 'b', 'realisedRevenue' => 15]])['status']);
		self::assertSame('a', $job->computeConcentratie([['customerId' => 'a', 'realisedRevenue' => 50], ['customerId' => 'b', 'realisedRevenue' => 50]])['largestCustomer']);
		self::assertSame('7', $job->computeConcentratie([['customerId' => '7', 'realisedRevenue' => 50]])['largestCustomer']);

	}//end testConcentration()

	/**
	 * The multi-year relationships, with an explicit now.
	 *
	 * @return void
	 */
	public function testMultiYearRelationships(): void {
		$job = $this->portfolioJob();
		$now = new DateTimeImmutable('2026-01-01');
		self::assertSame([], $job->computeLangjarigeRelaties([['customerId' => 'a', 'startDate' => '2020-01-01', 'realisedRevenue' => 0]], $now));
		$rows = [
			['customerId' => 'a', 'startDate' => '2022-01-01', 'realisedRevenue' => 60],
			['customerId' => 'a', 'startDate' => '2021-01-01', 'realisedRevenue' => 10],
			['customerId' => 'a', 'startDate' => '2023-01-01', 'realisedRevenue' => 5],
			['customerId' => 'b', 'startDate' => '2025-06-01', 'realisedRevenue' => 20],
			['customerId' => '', 'startDate' => '2020-01-01', 'realisedRevenue' => 5],
			['customerId' => 'c', 'startDate' => '', 'realisedRevenue' => 0],
			['customerId' => 'd', 'startDate' => 'nonsense', 'realisedRevenue' => 0],
		];
		self::assertSame(
			[],
			$job->computeLangjarigeRelaties($rows, $now),
			'a: the older 2021 start resets its revenue to 10, plus 5 later: 15 of 120, below the share threshold'
		);
		$rows[1]['realisedRevenue'] = 120;
		self::assertSame(
			[['customerId' => 'a', 'startDate' => '2021-01-01', 'durationYears' => 5.0, 'revenueShare' => 0.5952]],
			$job->computeLangjarigeRelaties($rows, $now)
		);
		self::assertSame(
			[['customerId' => '9', 'startDate' => '2023-12-01', 'durationYears' => 2.09, 'revenueShare' => 1.0]],
			$job->computeLangjarigeRelaties([['customerId' => '9', 'startDate' => '2023-12-01', 'realisedRevenue' => 3]], $now)
		);

	}//end testMultiYearRelationships()

	/**
	 * Without OpenRegister both jobs log and stop.
	 *
	 * @return void
	 */
	public function testJobsSkipWithoutOpenRegister(): void {
		$this->openRegister = false;
		self::runJob($this->flagJob());
		self::runJob($this->portfolioJob());

		self::assertSame(
			[
				'info|Shillinq: DBAFlagGenerationJob started|[]',
				'warning|Shillinq DBAFlagGenerationJob: OpenRegister not available, skipping.|{"exception":"no OpenRegister"}',
				'info|Shillinq: DBAPortfolioAggregationJob started|[]',
				'warning|Shillinq DBAPortfolioAggregationJob: OpenRegister not available, skipping.|{"exception":"no OpenRegister"}',
			],
			$this->logs
		);

	}//end testJobsSkipWithoutOpenRegister()

	/**
	 * A failing listing is logged and ends the run.
	 *
	 * @return void
	 */
	public function testJobsStopWhenTheListingFails(): void {
		$this->register = '';
		$this->objects->findAllAnswers['DBAOpdracht'] = [new \RuntimeException('db'), new \RuntimeException('db2')];
		self::runJob($this->flagJob());
		self::runJob($this->portfolioJob());

		self::assertSame(
			[
				'register:shillinq',
				'schema:DBAOpdracht',
				'findAll:DBAOpdracht:{"filters":{"intakeStatus":"ACTIVE"},"limit":1000}',
				'register:shillinq',
				'schema:DBAOpdracht',
				'findAll:DBAOpdracht:{"limit":5000}',
			],
			$this->objects->calls
		);
		self::assertSame(
			[
				'info|Shillinq: DBAFlagGenerationJob started|[]',
				'error|Shillinq DBAFlagGenerationJob: failed to fetch opdrachten|{"exception":"db"}',
				'info|Shillinq: DBAPortfolioAggregationJob started|[]',
				'error|Shillinq DBAPortfolioAggregationJob: failed to fetch opdrachten|{"exception":"db2"}',
			],
			$this->logs
		);

	}//end testJobsStopWhenTheListingFails()

	/**
	 * The flag job's run over assignments covering every branch.
	 *
	 * @return void
	 */
	public function testFlagRun(): void {
		$entity = new class {
			/**
			 * The object data.
			 *
			 * @return array<string,mixed>
			 */
			public function getObject(): array {
				return ['@self' => ['id' => 'as-2'], 'id' => 'ignored', 'administrationId' => 'adm-2', 'intakeDate' => '2000-01-01'];
			}
		};
		$this->objects->findAllAnswers['DBAOpdracht'] = [[
			'not a record',
			['id' => 'as-1', 'administrationId' => 'adm-1', 'intakeDate' => '2001-02-03', 'modelAgreementId' => 'm-1', 'wbaValidTo' => '2002-01-01'],
			$entity,
			['id' => 'as-3', 'intakeDate' => '2999-01-01', 'modelAgreementId' => 'm-missing', 'wbaValidTo' => '2999-01-01'],
			['id' => 'as-4', 'modelAgreementId' => 'm-boom'],
		]];
		$this->objects->findAnswers = ['m-1' => ['validTo' => '2003-04-05'], 'm-boom' => new \RuntimeException('lookup')];
		$this->objects->findAllAnswers['DBARisicoflag'] = [
			[],
			new \RuntimeException('index down'),
			[],
			[['already' => 'open']],
		];
		$this->objects->saveFailures['DBARisicoflag'] = [null, new \RuntimeException('disk')];

		self::runJob($this->flagJob());

		$flagQuery = static fn (string $id, string $type): string => 'findAll:DBARisicoflag:' . json_encode(['filters' => ['assignmentId' => $id, 'type' => $type, 'status' => 'OPEN'], 'limit' => 1]);
		self::assertSame(
			[
				'register:books', 'schema:DBAOpdracht', 'findAll:DBAOpdracht:{"filters":{"intakeStatus":"ACTIVE"},"limit":1000}',
				'register:books', 'schema:DBARisicoflag', $flagQuery('as-1', 'REASSESSMENT_OVERDUE'),
				'register:books', 'schema:DBARisicoflag', 'save:DBARisicoflag',
				'register:books', 'schema:DBAModelovereenkomst', 'find:DBAModelovereenkomst:m-1',
				'register:books', 'schema:DBARisicoflag', $flagQuery('as-1', 'MODELAGREEMENT_EXPIRED'),
				'register:books', 'schema:DBARisicoflag', $flagQuery('as-1', 'WBA_EXPIRED'),
				'register:books', 'schema:DBARisicoflag', 'save:DBARisicoflag',
				'register:books', 'schema:DBARisicoflag', $flagQuery('as-2', 'REASSESSMENT_OVERDUE'),
				'register:books', 'schema:DBAModelovereenkomst', 'find:DBAModelovereenkomst:m-missing',
				'register:books', 'schema:DBAModelovereenkomst', 'find:DBAModelovereenkomst:m-boom',
			],
			$this->objects->calls
		);
		self::assertCount(1, $this->objects->saved);
		[$schema, $flag] = $this->objects->saved[0];
		self::assertSame('DBARisicoflag', $schema);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $flag['detectionMoment']);
		unset($flag['detectionMoment']);
		self::assertSame(
			[
				'administrationId' => 'adm-1',
				'assignmentId' => 'as-1',
				'type' => 'REASSESSMENT_OVERDUE',
				'severity' => 'MEDIUM',
				'details' => ['intakeDate' => '2001-02-03'],
				'fiscalSource' => 'REQ-DBA-009; Wet DBA jaarlijkse herbeoordeling',
				'actionSuggestion' => 'Vraag een herbeoordeling van de DBA-intake aan de ondernemer.',
				'status' => 'OPEN',
				'displayedInUser' => true,
			],
			$flag
		);
		self::assertSame(
			[
				'info|Shillinq: DBAFlagGenerationJob started|[]',
				'warning|Shillinq DBAFlagGenerationJob: idempotency check failed|{"exception":"index down","type":"MODELAGREEMENT_EXPIRED"}',
				'error|Shillinq DBAFlagGenerationJob: failed to write flag|{"exception":"disk","type":"WBA_EXPIRED"}',
				'debug|Shillinq DBAFlagGenerationJob: skipping model lookup|{"modelId":"m-boom","exception":"lookup"}',
				'info|Shillinq DBAFlagGenerationJob: generated 1 flags|[]',
			],
			$this->logs
		);

	}//end testFlagRun()

	/**
	 * The flag job writes the model agreement and WBA flags with their details.
	 *
	 * @return void
	 */
	public function testFlagDetailsOfModelAndWba(): void {
		$this->objects->findAllAnswers['DBAOpdracht'] = [[
			['id' => 'as-1', 'modelAgreementId' => 'm-1', 'wbaValidTo' => '2002-01-01'],
		]];
		$this->objects->findAnswers = ['m-1' => ['validTo' => '2003-04-05']];

		self::runJob($this->flagJob());

		$flags = array_map(
			static fn (array $saved): array => [$saved[1]['type'], $saved[1]['severity'], $saved[1]['details'], $saved[1]['fiscalSource'], $saved[1]['actionSuggestion'], $saved[1]['administrationId']],
			$this->objects->saved
		);
		self::assertSame(
			[
				['MODELAGREEMENT_EXPIRED', 'MEDIUM', ['modelId' => 'm-1', 'validTo' => '2003-04-05'], 'REQ-DBA-002; Belastingdienst modelovereenkomst-policy', 'Kies een actueel modelovereenkomst en update de opdracht.', ''],
				['WBA_EXPIRED', 'LOW', ['wbaValidTo' => '2002-01-01'], 'REQ-DBA-013; Belastingdienst WBA-policy (1 jaar geldigheid)', 'Vraag een nieuwe WBA-beoordeling aan.', ''],
			],
			$flags
		);
		self::assertSame('info|Shillinq DBAFlagGenerationJob: generated 2 flags|[]', end($this->logs));

	}//end testFlagDetailsOfModelAndWba()

	/**
	 * The portfolio job groups by enterprise and writes one portfolio each.
	 *
	 * @return void
	 */
	public function testPortfolioRun(): void {
		$this->objects->findAllAnswers['DBAOpdracht'] = [[
			'skip me',
			['enterpriseId' => '', 'customerId' => 'x', 'realisedRevenue' => 5],
			['enterpriseId' => 'e-1', 'administrationId' => 'adm-1', 'customerId' => 'a', 'startDate' => '2000-01-01', 'realisedRevenue' => 99],
			['enterpriseId' => 'e-2', 'administrationId' => 'adm-2', 'customerId' => 'b', 'realisedRevenue' => 10],
			['enterpriseId' => 'e-1', 'administrationId' => 'adm-x', 'customerId' => 'c', 'realisedRevenue' => 1],
			['enterpriseId' => '7', 'customerId' => 'd', 'realisedRevenue' => 0],
		]];
		$this->objects->saveFailures['DBAPortfolioRisico'] = [null, new \RuntimeException('disk')];

		self::runJob($this->portfolioJob());

		self::assertSame(
			[
				'register:books', 'schema:DBAOpdracht', 'findAll:DBAOpdracht:{"limit":5000}',
				'register:books', 'schema:DBAPortfolioRisico', 'save:DBAPortfolioRisico',
				'register:books', 'schema:DBAPortfolioRisico', 'save:DBAPortfolioRisico',
				'register:books', 'schema:DBAPortfolioRisico', 'save:DBAPortfolioRisico',
			],
			$this->objects->calls
		);
		self::assertCount(2, $this->objects->saved);
		$first = $this->objects->saved[0][1];
		self::assertSame((new DateTimeImmutable())->format('Y-m-d'), $first['levelDate']);
		self::assertGreaterThan(25.0, $first['multiYearRelationships'][0]['durationYears']);
		unset($first['levelDate'], $first['multiYearRelationships'][0]['durationYears']);
		self::assertSame(
			[
				'administrationId' => 'adm-1',
				'enterpriseId' => 'e-1',
				'activeAssignments' => 2,
				'concentration' => ['largestCustomer' => 'a', 'revenueShare12m' => 0.99, 'thresholdHigh' => 0.70, 'status' => 'CRITICAL'],
				'multiYearRelationships' => [['customerId' => 'a', 'startDate' => '2000-01-01', 'revenueShare' => 0.99]],
				'exclusiveRelationships' => 1,
				'overallRisk' => 'HIGH',
			],
			$first
		);
		$third = $this->objects->saved[1][1];
		unset($third['levelDate']);
		self::assertSame(
			[
				'administrationId' => '',
				'enterpriseId' => '7',
				'activeAssignments' => 1,
				'concentration' => ['largestCustomer' => null, 'revenueShare12m' => 0.0, 'thresholdHigh' => 0.70, 'status' => 'VEILIG'],
				'multiYearRelationships' => [],
				'exclusiveRelationships' => 0,
				'overallRisk' => 'LOW',
			],
			$third
		);
		self::assertSame(
			[
				'info|Shillinq: DBAPortfolioAggregationJob started|[]',
				'error|Shillinq DBAPortfolioAggregationJob: failed to write portfolio|{"enterpriseId":"e-2","exception":"disk"}',
				'info|Shillinq DBAPortfolioAggregationJob: wrote 2 portfolio records|[]',
			],
			$this->logs
		);

	}//end testPortfolioRun()

	/**
	 * The exclusive count and overall risk of a portfolio.
	 *
	 * @return void
	 */
	public function testExclusiveCountAndOverallRisk(): void {
		$job = $this->portfolioJob();
		$exclusive = new ReflectionMethod($job, 'countExclusief');
		$exclusive->setAccessible(true);
		self::assertSame(0, $exclusive->invoke($job, []));
		self::assertSame(1, $exclusive->invoke($job, [['customerId' => 'a', 'realisedRevenue' => 990], ['customerId' => 'b', 'realisedRevenue' => 10]]));
		self::assertSame(0, $exclusive->invoke($job, [['customerId' => 'a', 'realisedRevenue' => 989], ['customerId' => 'b', 'realisedRevenue' => 11]]));

		self::assertSame('HIGH', $job->computeOverallRisico(['status' => 'VEILIG'], [[], []]));
		self::assertSame('MEDIUM', $job->computeOverallRisico(['status' => 'WARNING'], []));
		self::assertSame('MEDIUM', $job->computeOverallRisico([], [[]]));
		self::assertSame('LOW', $job->computeOverallRisico([], []));

	}//end testExclusiveCountAndOverallRisk()
}//end class
