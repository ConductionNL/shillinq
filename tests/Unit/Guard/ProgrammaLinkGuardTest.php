<?php

/**
 * Unit tests for ProgrammaLinkGuard.
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
 * @spec openspec/changes/bookkeeping-provincies-bbv-variant/tasks.md#task-15
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\ProgrammaLinkGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ProgrammaLinkGuard::validateOnSave per REQ-BBL-002/005.
 *
 * Covers:
 * - Unmapped line (no programmaStructure) always passes (REQ-BBL-001).
 * - Valid canonical programme + past/today effective date passes.
 * - Non-canonical programme is denied (REQ-BBL-002).
 * - Future effective date is denied (REQ-BBL-002).
 * - Malformed effective date is denied.
 * - Re-assignment to a DIFFERENT stored programme is denied (REQ-BBL-005).
 * - Idempotent re-save with the SAME programme is permitted.
 * - Assigning a programme to a previously-unmapped stored line is permitted.
 * - New (unsaved, no id) line with a programme passes.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class ProgrammaLinkGuardTest extends TestCase {

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var ProgrammaLinkGuard
	 */
	private ProgrammaLinkGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->guard = new ProgrammaLinkGuard(
			container: $this->container,
			appConfig: $this->appConfig,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Build a fluent ObjectService stub whose find() returns a given stored line.
	 *
	 * @param array<string, mixed>|null $storedLine The persisted GLLine for find(), or null.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(?array $storedLine): object {
		return new class($storedLine) {
			/**
			 * The stored GLLine returned by find(), or null.
			 *
			 * @var array<string, mixed>|null
			 */
			private ?array $storedLine;

			/**
			 * Constructor.
			 *
			 * @param array<string, mixed>|null $storedLine Stored line.
			 */
			public function __construct(?array $storedLine) {
				$this->storedLine = $storedLine;

			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema name.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Return the stubbed stored line for the given id.
			 *
			 * @param string $id GLLine id (unused in stub).
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id): ?array {
				return $this->storedLine;
			}//end find()
		};

	}//end buildObjectServiceStub()

	/**
	 * Wire the container to return the given ObjectService stub.
	 *
	 * @param object $objectService The ObjectService stub.
	 *
	 * @return void
	 */
	private function withObjectService(object $objectService): void {
		$this->container->method('get')->willReturn($objectService);

	}//end withObjectService()

	/**
	 * An unmapped GL line (no programmaStructure) always passes.
	 *
	 * @return void
	 */
	public function testUnmappedLinePasses(): void {
		self::assertTrue(
			$this->guard->validateOnSave(['accountNumber' => '4100', 'side' => 'debit', 'amount' => 100.0])
		);

	}//end testUnmappedLinePasses()

	/**
	 * A new line with a valid canonical programme and a non-future date passes.
	 *
	 * @return void
	 */
	public function testValidNewAssignmentPasses(): void {
		$line = [
			'accountNumber' => '4100',
			'programmeStructure' => 'mobiliteit',
			'programmeAssignedAt' => '2026-05-15',
		];
		self::assertTrue($this->guard->validateOnSave($line));

	}//end testValidNewAssignmentPasses()

	/**
	 * A non-canonical programme value is denied (REQ-BBL-002).
	 *
	 * @return void
	 */
	public function testNonCanonicalProgrammeDenied(): void {
		$line = ['programmeStructure' => 'sport'];
		self::assertFalse($this->guard->validateOnSave($line));

	}//end testNonCanonicalProgrammeDenied()

	/**
	 * A future effective date is denied (REQ-BBL-002).
	 *
	 * @return void
	 */
	public function testFutureEffectiveDateDenied(): void {
		$future = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');
		$line = [
			'programmeStructure' => 'water',
			'programmeAssignedAt' => $future,
		];
		self::assertFalse($this->guard->validateOnSave($line));

	}//end testFutureEffectiveDateDenied()

	/**
	 * A malformed effective date is denied.
	 *
	 * @return void
	 */
	public function testMalformedEffectiveDateDenied(): void {
		$line = [
			'programmeStructure' => 'water',
			'programmeAssignedAt' => 'not-a-date',
		];
		self::assertFalse($this->guard->validateOnSave($line));

	}//end testMalformedEffectiveDateDenied()

	/**
	 * Re-assigning a line already stored under a DIFFERENT programme is denied
	 * (REQ-BBL-005 no double-mapping).
	 *
	 * @return void
	 */
	public function testConflictingReassignmentDenied(): void {
		$this->withObjectService(
			$this->buildObjectServiceStub(['id' => 'gl-1', 'programmeStructure' => 'mobiliteit'])
		);

		$line = [
			'id' => 'gl-1',
			'programmeStructure' => 'water',
		];
		self::assertFalse($this->guard->validateOnSave($line));

	}//end testConflictingReassignmentDenied()

	/**
	 * Re-saving a line with the SAME stored programme is idempotent and permitted.
	 *
	 * @return void
	 */
	public function testIdempotentResavePermitted(): void {
		$this->withObjectService(
			$this->buildObjectServiceStub(['id' => 'gl-1', 'programmeStructure' => 'mobiliteit'])
		);

		$line = [
			'id' => 'gl-1',
			'programmeStructure' => 'mobiliteit',
		];
		self::assertTrue($this->guard->validateOnSave($line));

	}//end testIdempotentResavePermitted()

	/**
	 * Assigning a programme to a previously-unmapped stored line is permitted.
	 *
	 * @return void
	 */
	public function testFirstAssignmentOnStoredUnmappedLinePermitted(): void {
		$this->withObjectService(
			$this->buildObjectServiceStub(['id' => 'gl-1', 'programmeStructure' => null])
		);

		$line = [
			'id' => 'gl-1',
			'programmeStructure' => 'cultuur',
		];
		self::assertTrue($this->guard->validateOnSave($line));

	}//end testFirstAssignmentOnStoredUnmappedLinePermitted()

	/**
	 * A new line (no id) carrying a programme passes without a lookup.
	 *
	 * @return void
	 */
	public function testNewLineWithoutIdPasses(): void {
		$line = ['programmeStructure' => 'bestuur'];
		self::assertTrue($this->guard->validateOnSave($line));

	}//end testNewLineWithoutIdPasses()
}//end class
