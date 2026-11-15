<?php

/**
 * Golden tests for BbvComplianceGuard: lookups, log lines and fail-closed paths.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-bbv-compliance/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\BbvComplianceGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins the exact queries, log messages, log context and fail-closed answers.
 */
class BbvComplianceGuardGoldenTest extends TestCase {

	/**
	 * Recorded log calls as [level, message, context].
	 *
	 * @var array<int,array{0:string,1:string,2:array<string,mixed>}>
	 */
	private array $logs = [];

	/**
	 * Recorded lookups as [register, schema, params].
	 *
	 * @var array<int,array{0:string,1:string,2:array<string,mixed>}>
	 */
	private array $queries = [];

	/**
	 * Number of container lookups.
	 *
	 * @var int
	 */
	private int $containerCalls = 0;

	/**
	 * Build a guard whose ObjectService answers with $rows (or throws when $rows is a Throwable).
	 *
	 * @param array<int,array<string,mixed>>|\Throwable $rows   Rows to return, or the failure.
	 * @param array<string,mixed>                       $config App config values by key.
	 *
	 * @return BbvComplianceGuard
	 */
	private function guard(array|\Throwable $rows, array $config=[]): BbvComplianceGuard {
		$test      = $this;
		$service   = new class($rows, $test) {
			/**
			 * The register.
			 *
			 * @var string
			 */
			private string $register = '';

			/**
			 * The schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Construct.
			 *
			 * @param array<int,array<string,mixed>>|\Throwable $rows Rows or failure.
			 * @param BbvComplianceGuardGoldenTest              $test The recorder.
			 */
			public function __construct(private array|\Throwable $rows, private BbvComplianceGuardGoldenTest $test) {
			}//end __construct()

			/**
			 * Fluent setter.
			 *
			 * @param string $register Slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				$this->register = $register;
				return $this;
			}//end setRegister()

			/**
			 * Fluent setter.
			 *
			 * @param string $schema Slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Record and answer.
			 *
			 * @param array<string,mixed> $params Query.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params=[]): array {
				$this->test->recordQuery(query: [$this->register, $this->schema, $params]);
				if ($this->rows instanceof \Throwable) {
					throw $this->rows;
				}

				return $this->rows;
			}//end findAll()
		};
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($service): object {
				self::assertSame(expected: 'OCA\OpenRegister\Service\ObjectService', actual: $id);
				$this->containerCalls++;
				return $service;
			}
		);
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default): string => (string)($config[$key] ?? $default)
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default): int => (int)($config[$key] ?? $default)
		);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->logs[] = ['info', $message, $context];
			}
		);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->logs[] = ['error', $message, $context];
			}
		);

		return new BbvComplianceGuard(container: $container, appConfig: $appConfig, logger: $logger);
	}//end guard()

	/**
	 * Record one lookup.
	 *
	 * @param array{0:string,1:string,2:array<string,mixed>} $query The lookup.
	 *
	 * @return void
	 */
	public function recordQuery(array $query): void {
		$this->queries[] = $query;
	}//end recordQuery()

	/**
	 * A jaarrekening without administration or year is refused without a lookup.
	 *
	 * @return void
	 */
	public function testParagrafenWithoutYearRefusedWithoutLookup(): void {
		$guard = $this->guard(rows: []);
		self::assertFalse(condition: $guard->requireParagrafenCompleet(['administrationType' => 'municipality', 'administrationId' => 'g1']));
		self::assertSame(expected: 0, actual: $this->containerCalls);
		self::assertSame(
			expected: [['info', 'BbvComplianceGuard: jaarrekening missing administrationId or boekjaar (REQ-BBV-007)', []]],
			actual: $this->logs
		);
	}//end testParagrafenWithoutYearRefusedWithoutLookup()

	/**
	 * Missing paragrafen are listed in the mandatory order; the query is exact.
	 *
	 * @return void
	 */
	public function testParagrafenMissingLoggedInOrder(): void {
		$guard = $this->guard(
			rows: [['type' => 'financiering'], ['type' => 'lokale-heffingen'], ['type' => 'grondbeleid'], []],
			config: ['register' => 'reg-x']
		);
		$annualAccounts = ['administrationType' => 'province', 'administrationId' => 'p1', 'financialYear' => '2026'];
		self::assertFalse(condition: $guard->requireParagrafenCompleet($annualAccounts));
		self::assertSame(
			expected: [['reg-x', 'Paragraaf', ['filters' => ['administrationId' => 'p1', 'financialYear' => 2026, 'status' => 'determined']]]],
			actual: $this->queries
		);
		self::assertSame(
			expected: [[
				'info',
				'BbvComplianceGuard: jaarrekening mist verplichte paragrafen (REQ-BBV-007, BBV art. 9)',
				[
					'administrationId' => 'p1',
					'financialYear' => 2026,
					'ontbrekend' => ['weerstandsvermogen', 'onderhoud-kapitaalgoederen', 'bedrijfsvoering', 'verbonden-partijen'],
				],
			]],
			actual: $this->logs
		);
	}//end testParagrafenMissingLoggedInOrder()

	/**
	 * All seven present passes silently; an empty register slug falls back to shillinq.
	 *
	 * @return void
	 */
	public function testParagrafenCompletePasses(): void {
		$rows  = [];
		$types = [
			'lokale-heffingen',
			'weerstandsvermogen',
			'onderhoud-kapitaalgoederen',
			'financiering',
			'bedrijfsvoering',
			'verbonden-partijen',
			'grondbeleid',
		];
		foreach ($types as $type) {
			$rows[] = ['type' => $type];
		}

		$guard = $this->guard(rows: $rows, config: ['register' => '']);
		self::assertTrue(condition: $guard->requireParagrafenCompleet(['bbvCompliance' => true, 'administrationId' => 'w1', 'financialYear' => 2025]));
		self::assertSame(expected: 'shillinq', actual: $this->queries[0][0]);
		self::assertSame(expected: [], actual: $this->logs);
	}//end testParagrafenCompletePasses()

	/**
	 * A failed paragraaf lookup refuses and logs the error.
	 *
	 * @return void
	 */
	public function testParagrafenLookupFailureFailsClosed(): void {
		$guard = $this->guard(rows: new \RuntimeException('down'));
		self::assertFalse(condition: $guard->requireParagrafenCompleet(['bbv_compliance' => true, 'administrationId' => 'w1', 'financialYear' => 2025]));
		self::assertSame(
			expected: [['error', 'BbvComplianceGuard: paragraaf lookup failed — denying publish (fail-closed)', ['exception' => 'down']]],
			actual: $this->logs
		);
	}//end testParagrafenLookupFailureFailsClosed()

	/**
	 * The meerjaren query is exact and only the first negative horizon is logged.
	 *
	 * @return void
	 */
	public function testMeerjarenFirstNegativeHorizonLogged(): void {
		$guard = $this->guard(
			rows: [
				['meerjarenHorizon' => 0, 'revenueCents' => 100, 'expensesCents' => 50],
				['meerjarenHorizon' => 2, 'revenueCents' => 10, 'expensesCents' => 50, 'movementReservesCents' => 5],
				['meerjarenHorizon' => 1, 'revenueCents' => 0, 'expensesCents' => 7],
			]
		);
		$programma = ['administrationType' => 'municipality', 'administrationId' => 'g1', 'financialYear' => 2027, 'councilResolutionNumber' => 'RB-1'];
		self::assertFalse(condition: $guard->requireMeerjarenramingSluitend($programma));
		self::assertSame(
			expected: [['shillinq', 'MeerjarenBudget', ['filters' => ['administrationId' => 'g1', 'financialYear' => 2027, 'version' => 'primitief']]]],
			actual: $this->queries
		);
		self::assertSame(
			expected: [[
				'info',
				'BbvComplianceGuard: meerjarenraming not sluitend (REQ-BBV-003)',
				['administrationId' => 'g1', 'horizon' => 2, 'saldoCents' => -35],
			]],
			actual: $this->logs
		);
	}//end testMeerjarenFirstNegativeHorizonLogged()

	/**
	 * A failed meerjaren lookup refuses and logs the error.
	 *
	 * @return void
	 */
	public function testMeerjarenLookupFailureLogged(): void {
		$guard = $this->guard(rows: new \RuntimeException('gone'));
		self::assertFalse(condition: $guard->requireMeerjarenramingSluitend(['administrationType' => 'municipality']));
		self::assertSame(
			expected: [['shillinq', 'MeerjarenBudget', ['filters' => ['administrationId' => '', 'financialYear' => 0, 'version' => 'primitief']]]],
			actual: $this->queries
		);
		self::assertSame(
			expected: [['error', 'BbvComplianceGuard: meerjarenraming lookup failed — denying publish (fail-closed)', ['exception' => 'gone']]],
			actual: $this->logs
		);
	}//end testMeerjarenLookupFailureLogged()

	/**
	 * The account lookup is scoped to the administration and limited to one.
	 *
	 * @return void
	 */
	public function testAccountLookupQueryAndProvisionMismatchLog(): void {
		$guard = $this->guard(rows: [['bbvClassificatie' => 'voorziening', 'taskField' => '7.1'], ['bbvClassificatie' => 'reserve']]);
		$line  = ['administrationType' => 'municipality', 'administrationId' => 'g1', 'accountNumber' => '0601', 'taskField' => '7.2'];
		self::assertFalse(condition: $guard->requireLineClassification($line));
		self::assertSame(
			expected: [['shillinq', 'Account', ['filters' => ['accountNumber' => '0601', 'administrationId' => 'g1'], 'limit' => 1]]],
			actual: $this->queries
		);
		self::assertSame(
			expected: [[
				'info',
				'BbvComplianceGuard: voorziening mutation off gekoppeld taakveld rejected (REQ-BBV-004)',
				['accountNumber' => '0601', 'expected' => '7.1', 'found' => '7.2'],
			]],
			actual: $this->logs
		);
	}//end testAccountLookupQueryAndProvisionMismatchLog()

	/**
	 * A voorziening account without its own taakveld accepts any line.
	 *
	 * @return void
	 */
	public function testProvisionWithoutLinkedTaskFieldPasses(): void {
		$guard = $this->guard(rows: [['bbvClassificatie' => 'voorziening']]);
		self::assertTrue(condition: $guard->requireLineClassification(['administrationType' => 'municipality', 'accountNumber' => '0601']));
		self::assertSame(expected: [['shillinq', 'Account', ['filters' => ['accountNumber' => '0601'], 'limit' => 1]]], actual: $this->queries);
		self::assertSame(expected: [], actual: $this->logs);
	}//end testProvisionWithoutLinkedTaskFieldPasses()

	/**
	 * Investering and balans-overig accounts are exempt; a reserve line logs its taakveld.
	 *
	 * @return void
	 */
	public function testExemptClassesAndReserveLog(): void {
		$line = ['administrationType' => 'municipality', 'accountNumber' => '1'];
		self::assertTrue(condition: $this->guard(rows: [['bbvClassificatie' => 'investering']])->requireLineClassification($line));
		self::assertTrue(condition: $this->guard(rows: [['bbvClassificatie' => 'balans-overig']])->requireLineClassification($line));
		$reserve = ['administrationType' => 'municipality', 'accountNumber' => '2', 'taskField' => 0.1];
		self::assertFalse(condition: $this->guard(rows: [['bbvClassificatie' => 'reserve']])->requireLineClassification($reserve));
		self::assertSame(
			expected: [[
				'info',
				'BbvComplianceGuard: reserve mutation off taakveld 0.10 rejected (REQ-BBV-004)',
				['accountNumber' => '2', 'taskField' => 0.1],
			]],
			actual: $this->logs
		);
	}//end testExemptClassesAndReserveLog()

	/**
	 * A failing account lookup logs the error, then the unresolved account, and refuses.
	 *
	 * @return void
	 */
	public function testAccountLookupFailureLogsBoth(): void {
		$guard = $this->guard(rows: new \RuntimeException('no db'));
		self::assertFalse(condition: $guard->requireLineClassification(['administrationType' => 'municipality', 'accountNumber' => '4310']));
		self::assertSame(
			expected: [
				['error', 'BbvComplianceGuard: account resolution failed', ['exception' => 'no db']],
				['info', 'BbvComplianceGuard: cannot resolve account for GLLine classification (REQ-BBV-002)', ['accountNumber' => '4310']],
			],
			actual: $this->logs
		);
	}//end testAccountLookupFailureLogsBoth()

	/**
	 * A line without account number is refused without a lookup.
	 *
	 * @return void
	 */
	public function testLineWithoutAccountNumberRefusedWithoutLookup(): void {
		$guard = $this->guard(rows: []);
		self::assertFalse(condition: $guard->requireLineClassification(['administrationType' => 'municipality']));
		self::assertSame(expected: 0, actual: $this->containerCalls);
		self::assertSame(
			expected: [['info', 'BbvComplianceGuard: cannot resolve account for GLLine classification (REQ-BBV-002)', ['accountNumber' => 'unknown']]],
			actual: $this->logs
		);
	}//end testLineWithoutAccountNumberRefusedWithoutLookup()

	/**
	 * A posting on or after the install date, or with an unparseable date, is checked.
	 *
	 * @return void
	 */
	public function testHistoricCarveOutBoundaries(): void {
		$exploitatie = [['bbvClassificatie' => 'exploitatie']];
		$line        = ['administrationType' => 'municipality', 'accountNumber' => '4', 'postingDate' => '2026-01-01'];
		self::assertFalse(condition: $this->guard(rows: $exploitatie, config: ['bbv_installation_date' => '2026-01-01'])->requireLineClassification($line));
		self::assertFalse(condition: $this->guard(rows: $exploitatie, config: ['bbv_installation_date' => 'not a date'])->requireLineClassification($line));
		$undated = ['administrationType' => 'municipality', 'accountNumber' => '4'];
		$june    = ['bbv_installation_date' => '2026-06-01'];
		self::assertFalse(condition: $this->guard(rows: $exploitatie, config: $june)->requireLineClassification($undated));
		self::assertTrue(condition: $this->guard(rows: $exploitatie, config: ['bbv_installation_date' => '2026-06-01'])->requireLineClassification($line));
		self::assertCount(expectedCount: 3, haystack: $this->queries);
		self::assertSame(
			expected: ['info', 'BbvComplianceGuard: exploitatie line missing taakveld/economische_categorie (REQ-BBV-002)', ['accountNumber' => '4']],
			actual: $this->logs[0]
		);
	}//end testHistoricCarveOutBoundaries()

	/**
	 * An unparseable ingebruikname date logs and answers null.
	 *
	 * @return void
	 */
	public function testDepreciationStartUnparseableLogged(): void {
		$guard = $this->guard(rows: []);
		self::assertNull(actual: $guard->depreciationStartMonth(['commissioningDate' => 'garbage']));
		self::assertSame(expected: 'info', actual: $this->logs[0][0]);
		self::assertSame(expected: 'BbvComplianceGuard: ingebruiknameDatum unparseable, depreciation start unknown', actual: $this->logs[0][1]);
		self::assertSame(expected: ['raw', 'exception'], actual: array_keys($this->logs[0][2]));
		self::assertSame(expected: 'garbage', actual: $this->logs[0][2]['raw']);
		self::assertSame(expected: '2027-01', actual: $guard->depreciationStartMonth(['commissioningDate' => '2026-12-31']));
	}//end testDepreciationStartUnparseableLogged()

	/**
	 * The activeringsgrens is inclusive, falls back to the default when not positive, and the refusal logs.
	 *
	 * @return void
	 */
	public function testMvaThresholdAndRefusalLog(): void {
		$mva = ['administrationType' => 'municipality', 'mvaCategory' => 'maatschappelijk-nut', 'acquisitionValueCents' => 5000000];
		self::assertTrue(condition: $this->guard(rows: [], config: ['bbv_activeringsgrens_cents' => 0])->requireMvaActivation($mva));
		$activated = $mva + ['depreciationPeriodYears' => 1];
		self::assertTrue(condition: $this->guard(rows: [], config: ['bbv_activeringsgrens_cents' => 100])->requireMvaActivation($activated));
		self::assertFalse(condition: $this->guard(rows: [], config: ['bbv_activeringsgrens_cents' => 100])->requireMvaActivation($mva));
		self::assertSame(
			expected: [[
				'info',
				'BbvComplianceGuard: maatschappelijk-nut investering above grens not activated (REQ-BBV-005)',
				['description' => 'unknown', 'aanschafCents' => 5000000],
			]],
			actual: $this->logs
		);
	}//end testMvaThresholdAndRefusalLog()
}//end class
