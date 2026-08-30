<?php

/**
 * Unit tests for EliminationGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-gr-consolidation/specs/bookkeeping-intercompany-posting.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\EliminationGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for EliminationGuard::canChangeEliminationStatus (REQ-ICP-006).
 *
 * Covers:
 * - Unconsolidated transaction (no consolidatedReportId) → permits.
 * - Transaction whose owning report is still draft → permits.
 * - Transaction whose owning report is final → denies (immutable).
 * - Transaction whose owning report is published → denies (immutable).
 * - Report id references no report → permits (nothing frozen).
 * - Fail-closed on exception → denies.
 */
final class EliminationGuardTest extends TestCase {

	/**
	 * Mock DI container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Guard under test.
	 *
	 * @var EliminationGuard
	 */
	private EliminationGuard $guard;

	/**
	 * Set up the guard with mocked dependencies.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->guard = new EliminationGuard(
			container: $this->container,
			appConfig: $this->appConfig,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * An ObjectService stub returning a fixed findAll result.
	 *
	 * @param array<int,mixed> $findAllReturn Value findAll() returns.
	 *
	 * @return object Fluent stub.
	 */
	private function buildObjectServiceStub(array $findAllReturn): object {
		return new class($findAllReturn) {
			/**
			 * Fixed findAll return.
			 *
			 * @var array<int,mixed>
			 */
			private array $findAllReturn;

			/**
			 * Construct the stub.
			 *
			 * @param array<int,mixed> $findAllReturn Fixed findAll return.
			 */
			public function __construct(array $findAllReturn) {
				$this->findAllReturn = $findAllReturn;
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
			 * Return the fixed findAll result.
			 *
			 * @param array<string,mixed> $params Filter params.
			 *
			 * @return array<int,mixed>
			 */
			public function findAll(array $params = []): array {
				return $this->findAllReturn;
			}//end findAll()
		};
	}//end buildObjectServiceStub()

	/**
	 * A transaction not yet consolidated into any report is always editable.
	 *
	 * @return void
	 */
	public function testPermitsWhenNotConsolidated(): void {
		// No container access should be needed; do not configure the stub.
		$result = $this->guard->canChangeEliminationStatus('ict-1', ['eliminationStatus' => 'pending']);
		self::assertTrue(condition: $result, message: 'Unconsolidated transaction must be editable (REQ-ICP-006)');
	}//end testPermitsWhenNotConsolidated()

	/**
	 * A transaction whose owning report is still a draft is editable.
	 *
	 * @return void
	 */
	public function testPermitsWhenReportDraft(): void {
		$objectService = $this->buildObjectServiceStub(findAllReturn: [['id' => 'cr-1', 'status' => 'draft']]);
		$this->container->method('get')->willReturn($objectService);

		$result = $this->guard->canChangeEliminationStatus(
			'ict-1',
			['consolidatedReportId' => 'cr-1', 'eliminationStatus' => 'pending']
		);
		self::assertTrue(condition: $result, message: 'Draft report must allow elimination status changes');
	}//end testPermitsWhenReportDraft()

	/**
	 * A transaction whose owning report is final is frozen.
	 *
	 * @return void
	 */
	public function testDeniesWhenReportFinal(): void {
		$objectService = $this->buildObjectServiceStub(findAllReturn: [['id' => 'cr-1', 'status' => 'final']]);
		$this->container->method('get')->willReturn($objectService);

		$result = $this->guard->canChangeEliminationStatus(
			'ict-1',
			['consolidatedReportId' => 'cr-1', 'eliminationStatus' => 'eliminated']
		);
		self::assertFalse(condition: $result, message: 'Final report must freeze elimination status (REQ-ICP-006)');
	}//end testDeniesWhenReportFinal()

	/**
	 * A transaction whose owning report is published is frozen.
	 *
	 * @return void
	 */
	public function testDeniesWhenReportPublished(): void {
		$objectService = $this->buildObjectServiceStub(findAllReturn: [['id' => 'cr-1', 'status' => 'published']]);
		$this->container->method('get')->willReturn($objectService);

		$result = $this->guard->canChangeEliminationStatus(
			'ict-1',
			['consolidatedReportId' => 'cr-1', 'eliminationStatus' => 'eliminated']
		);
		self::assertFalse(condition: $result, message: 'Published report must freeze elimination status (REQ-ICP-006)');
	}//end testDeniesWhenReportPublished()

	/**
	 * A dangling report reference (no report found) permits the change.
	 *
	 * @return void
	 */
	public function testPermitsWhenReportNotFound(): void {
		$objectService = $this->buildObjectServiceStub(findAllReturn: []);
		$this->container->method('get')->willReturn($objectService);

		$result = $this->guard->canChangeEliminationStatus(
			'ict-1',
			['consolidatedReportId' => 'missing', 'eliminationStatus' => 'pending']
		);
		self::assertTrue(condition: $result, message: 'A dangling report reference must not freeze the transaction');
	}//end testPermitsWhenReportNotFound()

	/**
	 * Any exception from the container resolution fails closed (denies).
	 *
	 * @return void
	 */
	public function testFailsClosedOnException(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('OR unavailable'));

		$result = $this->guard->canChangeEliminationStatus(
			'ict-1',
			['consolidatedReportId' => 'cr-1', 'eliminationStatus' => 'eliminated']
		);
		self::assertFalse(condition: $result, message: 'Exception must fail closed and deny the change (CWE-863)');
	}//end testFailsClosedOnException()
}//end class
