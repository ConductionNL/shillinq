<?php

/**
 * Unit tests for ReportGenerationService's lookups and fail-soft paths.
 *
 * Pins the refusals before a generator runs (unknown type, no generator),
 * the format fallback, what a run without a user or with a failing file
 * store or record store answers, the generator discovery over
 * lib/Reporting/Generator, the filtered listing, the record lookup and
 * the narrowed file resolution (own home first, then only the exact
 * recorded path).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Reporting
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
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting;

use OCA\Shillinq\Reporting\GeneratedFile;
use OCA\Shillinq\Reporting\ReportGenerationService;
use OCA\Shillinq\Reporting\ReportGeneratorInterface;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use ReflectionProperty;

/**
 * A fake OpenRegister ObjectService for the lookups: records every call, answers what it was given.
 */
final class FakeLookupObjectService {

	/**
	 * @var array<int, string>
	 */
	public array $calls = [];

	/**
	 * @param mixed           $found   What find() answers.
	 * @param mixed           $listed  What findAll() answers.
	 * @param mixed           $saved   What saveObject() answers (null: the object with an id).
	 * @param \Throwable|null $failure Thrown by every read and write when set.
	 */
	public function __construct(
		public mixed $found=null,
		public mixed $listed=[],
		public mixed $saved=null,
		public ?\Throwable $failure=null,
	) {

	}//end __construct()

	public function setRegister(string $register): self {
		$this->calls[] = 'register ' . $register;
		return $this;
	}//end setRegister()

	public function setSchema(string $schema): self {
		$this->calls[] = 'schema ' . $schema;
		return $this;
	}//end setSchema()

	/**
	 * @param array<string, mixed> $config The query.
	 *
	 * @return mixed
	 */
	public function findAll(array $config): mixed {
		$this->calls[] = 'findAll ' . json_encode($config);
		if ($this->failure !== null) {
			throw $this->failure;
		}

		return $this->listed;
	}//end findAll()

	public function find(string $id): mixed {
		$this->calls[] = 'find ' . $id;
		if ($this->failure !== null) {
			throw $this->failure;
		}

		return $this->found;
	}//end find()

	/**
	 * @param array<string, mixed> $object The record.
	 *
	 * @return mixed
	 */
	public function saveObject(array $object, string $register, string $schema): mixed {
		$this->calls[] = 'save ' . $register . '/' . $schema . ' ' . (string)($object['status'] ?? '');
		if ($this->failure !== null) {
			throw $this->failure;
		}

		return $this->saved ?? array_merge($object, ['id' => 'rec-1']);
	}//end saveObject()
}//end class

/**
 * A row OpenRegister answers as an entity.
 */
final class FakeLookupEntity implements \JsonSerializable {

	/**
	 * @param array<string, mixed> $data The row.
	 */
	public function __construct(private readonly array $data) {

	}//end __construct()

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return $this->data;
	}//end jsonSerialize()
}//end class

/**
 * A generator that renders a fixed file and remembers the format asked for.
 */
final class FixedTrialBalanceGenerator implements ReportGeneratorInterface {

	/**
	 * @var array<int, string>
	 */
	public array $formats = [];

	public static function reportType(): string {
		return 'trial-balance';
	}//end reportType()

	public static function supportedFormats(): array {
		return ['csv', 'pdf'];
	}//end supportedFormats()

	public function generate(array $context, string $format): GeneratedFile {
		$this->formats[] = $format;
		return new GeneratedFile('saldibalans.' . $format, 'text/csv', $format, 'a;b');
	}//end generate()
}//end class

/**
 * A logger that keeps the warnings.
 */
final class LookupWarnings extends AbstractLogger {

	/**
	 * @var array<int, array{0: string, 1: array<mixed>}>
	 */
	public array $warnings = [];

	public function log($level, string|\Stringable $message, array $context=[]): void {
		$this->warnings[] = [(string)$message, $context];
	}//end log()

	/**
	 * @return array<int, string>
	 */
	public function messages(): array {
		return array_column($this->warnings, 0);
	}//end messages()
}//end class

/**
 * Tests ReportGenerationService's lookups and fail-soft paths.
 *
 * @covers \OCA\Shillinq\Reporting\ReportGenerationService
 * @uses   \OCA\Shillinq\Reporting\GeneratedFile
 * @uses   \OCA\Shillinq\Reporting\ReportCatalogue
 * @uses   \OCA\Shillinq\Reporting\SystemTagAssigner
 * @uses   \OCA\Shillinq\Reporting\TaggedFileStore
 * @uses   \OCA\Shillinq\Reporting\TaggedFileStoreLog
 * @uses   \OCA\Shillinq\Reporting\TaggedFileStoreMessages
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
final class ReportGenerationServiceLookupTest extends TestCase {

	/**
	 * The warnings logged by the service under test.
	 *
	 * @var LookupWarnings
	 */
	private LookupWarnings $logger;

	protected function setUp(): void {
		$this->logger = new LookupWarnings();
	}//end setUp()

	/**
	 * A container answering the ObjectService (or failing for it) and generator classes from a map.
	 *
	 * @param object|null                      $objectService The ObjectService, null for none.
	 * @param array<string, object|\Throwable> $classes       Answers by class name; others throw.
	 * @param array<int, string>               $asked         Every id asked for.
	 */
	private function container(?object $objectService, array $classes=[], array &$asked=[]): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService, $classes, &$asked): mixed {
				$asked[] = $id;
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService' && $objectService !== null) {
					return $objectService;
				}

				$answer = ($classes[$id] ?? new \RuntimeException('not in this container: ' . $id));
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);
		return $container;
	}//end container()

	/**
	 * A session for alice, or for nobody.
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * A service; the generator index is seeded when generators are given.
	 *
	 * @param array<string, ReportGeneratorInterface>|null $generators The index, null to discover.
	 */
	private function service(
		ContainerInterface $container,
		?array $generators=null,
		?IRootFolder $root=null,
		?string $uid='alice',
	): ReportGenerationService {
		$service = new ReportGenerationService(
			$container,
			($root ?? $this->createMock(IRootFolder::class)),
			$this->createMock(ISystemTagManager::class),
			$this->createMock(ISystemTagObjectMapper::class),
			$this->session($uid),
			$this->logger,
		);
		if ($generators !== null) {
			(new ReflectionProperty($service, 'generators'))->setValue($service, $generators);
		}

		return $service;
	}//end service()

	/**
	 * A file mock at a path.
	 */
	private function file(string $path): File {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn($path);
		return $file;
	}//end file()

	public function testAnUnknownTypeOrOneWithoutAGeneratorIsRefused(): void {
		$store   = new FakeLookupObjectService();
		$service = $this->service($this->container($store), []);

		$this->assertSame(['error' => 'unknown-report-type', 'reportType' => 'nope'], $service->generate('nope', '2026', 'adm-1', 'pdf'));
		$this->assertSame(['error' => 'no-generator', 'reportType' => 'saft'], $service->generate('saft', '2026', 'adm-1', 'xml'));
		$this->assertSame(
			['ReportGenerationService: unknown report type', 'ReportGenerationService: no generator for report type'],
			$this->logger->messages()
		);
		$this->assertSame([], $store->calls);
	}//end testAnUnknownTypeOrOneWithoutAGeneratorIsRefused()

	public function testAnUnsupportedFormatFallsBackAndNoUserStoresNoFile(): void {
		$generator = new FixedTrialBalanceGenerator();
		$store     = new FakeLookupObjectService(saved: new FakeLookupEntity(['id' => 'rec-9', 'status' => 'ready']));
		$root      = $this->createMock(IRootFolder::class);
		$root->expects($this->never())->method('getUserFolder');
		$service = $this->service($this->container($store), ['trial-balance' => $generator], $root, null);

		$result = $service->generate('trial-balance', '2026', 'adm-1', 'xbrl');

		$this->assertSame(['csv'], $generator->formats);
		$this->assertSame(['id' => 'rec-9', 'status' => 'ready', 'downloadPath' => '/index.php/apps/shillinq/api/reporting/download/rec-9'], $result);
		$this->assertSame(['save shillinq/GeneratedReport ready'], $store->calls);
		$this->assertSame(['ReportGenerationService: no user session, cannot store report file'], $this->logger->messages());
	}//end testAnUnsupportedFormatFallsBackAndNoUserStoresNoFile()

	public function testAFailingFileStoreAndRecordStoreStillAnswerTheRecord(): void {
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willThrowException(new \RuntimeException('home gone'));
		$store   = new FakeLookupObjectService(failure: new \RuntimeException('register gone'));
		$service = $this->service($this->container($store), ['trial-balance' => new FixedTrialBalanceGenerator()], $root, 'alice');

		$result = $service->generate('trial-balance', '2026-Q1', 'adm-1', 'pdf');

		$this->assertSame('pdf', $result['format']);
		$this->assertSame('saldibalans.pdf', $result['fileName']);
		$this->assertNull($result['filePath']);
		$this->assertNull($result['fileId']);
		$this->assertSame(3, $result['sizeBytes']);
		$this->assertSame('alice', $result['generatedBy']);
		$this->assertSame('ready', $result['status']);
		$this->assertSame(
			['shillinq-report:trial-balance', 'shillinq-period:2026-Q1', 'shillinq-administration:adm-1', 'shillinq-category:ledger'],
			$result['tags']
		);
		$this->assertSame('/index.php/apps/shillinq/api/reporting/download/', $result['downloadPath']);
		$this->assertSame(
			['ReportGenerationService: failed to store report file', 'ReportGenerationService: failed to record GeneratedReport'],
			$this->logger->messages()
		);
	}//end testAFailingFileStoreAndRecordStoreStillAnswerTheRecord()

	public function testWithoutAnObjectServiceTheRecordIsAnsweredUnsaved(): void {
		$service = $this->service($this->container(null), ['trial-balance' => new FixedTrialBalanceGenerator()], null, null);

		$result = $service->generate('trial-balance', '2026', 'adm-1', 'csv', 'scheduler');

		$this->assertSame('scheduler', $result['generatedBy']);
		$this->assertArrayNotHasKey('id', $result);
		$this->assertSame([], $service->listGenerated([]));
		$this->assertNull($service->findRecord('rec-1'));
		$this->assertContains('ReportGenerationService: ObjectService unavailable', $this->logger->messages());
	}//end testWithoutAnObjectServiceTheRecordIsAnsweredUnsaved()

	public function testTheGeneratorsAreDiscoveredOnceThroughTheContainer(): void {
		$generator = new FixedTrialBalanceGenerator();
		$asked     = [];
		$classes   = ['\\OCA\\Shillinq\\Reporting\\Generator\\TrialBalanceReportGenerator' => $generator];
		$service   = $this->service($this->container(new FakeLookupObjectService(), $classes, $asked), null, null, null);

		$service->generate('trial-balance', '2026', 'adm-1', 'csv');
		$service->generate('trial-balance', '2027', 'adm-1', 'csv');

		$this->assertSame(['csv', 'csv'], $generator->formats);
		$generators = array_values(array_filter($asked, static fn (string $id): bool => str_contains($id, '\\Generator\\')));
		$this->assertContains('\\OCA\\Shillinq\\Reporting\\Generator\\VatReturnReportGenerator', $generators);
		$this->assertContains('\\OCA\\Shillinq\\Reporting\\Generator\\XafAuditfileGenerator', $generators);
		$this->assertNotContains('\\OCA\\Shillinq\\Reporting\\Generator\\AbstractDocumentReportGenerator', $generators);
		$this->assertNotContains('\\OCA\\Shillinq\\Reporting\\Generator\\AbstractStatementReportGenerator', $generators);
		$this->assertNotContains('\\OCA\\Shillinq\\Reporting\\Generator\\AccountBucket', $generators);
		$this->assertSame(count(array_unique($generators)), count($generators));
		$this->assertContains('ReportGenerationService: failed to instantiate generator', $this->logger->messages());
	}//end testTheGeneratorsAreDiscoveredOnceThroughTheContainer()

	public function testTheListingKeepsOnlyKnownNonEmptyFilters(): void {
		$store   = new FakeLookupObjectService(listed: [['id' => 'rec-1']]);
		$service = $this->service($this->container($store), []);

		$listed = $service->listGenerated(
			['reportType' => ' vat-return ', 'period' => '', 'administrationId' => 7, 'category' => 'tax', 'other' => 'x']
		);

		$this->assertSame([['id' => 'rec-1']], $listed);
		$this->assertSame(
			['register shillinq', 'schema GeneratedReport', 'findAll {"filters":{"reportType":"vat-return","category":"tax"}}'],
			$store->calls
		);
	}//end testTheListingKeepsOnlyKnownNonEmptyFilters()

	public function testAListingThatFailsOrIsNotAListAnswersNothing(): void {
		$this->assertSame([], $this->service($this->container(new FakeLookupObjectService(listed: 'oops')), [])->listGenerated([]));

		$failing = new FakeLookupObjectService(failure: new \RuntimeException('down'));
		$this->assertSame([], $this->service($this->container($failing), [])->listGenerated([]));
		$this->assertSame(['ReportGenerationService: listGenerated failed'], $this->logger->messages());
	}//end testAListingThatFailsOrIsNotAListAnswersNothing()

	public function testARecordIsFoundAsAnArray(): void {
		$entity = $this->service($this->container(new FakeLookupObjectService(found: new FakeLookupEntity(['id' => 'rec-1', 'fileId' => 5]))), []);
		$this->assertSame(['id' => 'rec-1', 'fileId' => 5], $entity->findRecord('rec-1'));

		$array = $this->service($this->container(new FakeLookupObjectService(found: ['id' => 'rec-2'])), []);
		$this->assertSame(['id' => 'rec-2'], $array->findRecord('rec-2'));

		$missing = new FakeLookupObjectService();
		$this->assertNull($this->service($this->container($missing), [])->findRecord('rec-3'));
		$this->assertSame(['register shillinq', 'schema GeneratedReport', 'find rec-3'], $missing->calls);

		$blank = new FakeLookupObjectService();
		$this->assertNull($this->service($this->container($blank), [])->findRecord('  '));
		$this->assertSame([], $blank->calls);

		$this->assertNull($this->service($this->container(new FakeLookupObjectService(failure: new \RuntimeException('x'))), [])->findRecord('rec-4'));
		$this->assertSame(['ReportGenerationService: findRecord failed'], $this->logger->messages());
	}//end testARecordIsFoundAsAnArray()

	public function testTheFileIsFoundInTheOwnHomeFirst(): void {
		$own  = $this->file('/alice/files/Shillinq/Reports/adm-1/a.csv');
		$home = $this->createMock(Folder::class);
		$home->method('getById')->with(5)->willReturn([$this->createMock(Folder::class), $own]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with('alice')->willReturn($home);
		$root->expects($this->never())->method('getById');

		$this->assertSame($own, $this->service($this->container(null), [], $root)->resolveRecordFile(['fileId' => '5', 'filePath' => '/other']));
	}//end testTheFileIsFoundInTheOwnHomeFirst()

	public function testElsewhereOnlyTheExactRecordedPathResolves(): void {
		$path = '/bob/files/Shillinq/Reports/adm-1/a.csv';
		$home = $this->createMock(Folder::class);
		$home->method('getById')->willThrowException(new \RuntimeException('not in this home'));
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($home);
		$root->method('getById')->with(5)->willReturn([$this->file('/mallory/files/a.csv'), $this->file($path)]);
		$service = $this->service($this->container(null), [], $root);

		$this->assertSame($path, $service->resolveRecordFile(['fileId' => 5, 'filePath' => $path])?->getPath());
		$this->assertNull($service->resolveRecordFile(['fileId' => 5, 'filePath' => '/eve/files/a.csv']));
		$this->assertSame(
			[
				'ReportGenerationService: report file not resolvable in the user home',
				'ReportGenerationService: report file not resolvable in the user home',
				'ReportGenerationService: stored report file missing',
			],
			$this->logger->messages()
		);
	}//end testElsewhereOnlyTheExactRecordedPathResolves()

	public function testARecordWithoutAFileOrPathResolvesNothing(): void {
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->never())->method('getById');
		$root->expects($this->never())->method('getUserFolder');

		$this->assertNull($this->service($this->container(null), [], $root, null)->resolveRecordFile(['fileId' => 5, 'filePath' => '']));
		$this->assertNull($this->service($this->container(null), [], $root)->resolveRecordFile(['fileId' => null, 'filePath' => '/a']));
		$this->assertSame([], $this->logger->messages());
	}//end testARecordWithoutAFileOrPathResolvesNothing()

	public function testResolvingByIdLoadsTheRecordFirst(): void {
		$path = '/alice/files/a.csv';
		$home = $this->createMock(Folder::class);
		$home->method('getById')->willReturn([$this->file($path)]);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($home);

		$found = new FakeLookupObjectService(found: ['id' => 'rec-1', 'fileId' => 5, 'filePath' => $path]);
		$this->assertSame($path, $this->service($this->container($found), [], $root)->resolveFile('rec-1')?->getPath());
		$this->assertNull($this->service($this->container(new FakeLookupObjectService()), [], $root)->resolveFile('rec-2'));
	}//end testResolvingByIdLoadsTheRecordFirst()
}//end class
