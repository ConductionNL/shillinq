<?php

/**
 * Unit tests for JournalVoidGuard.
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
 * @spec openspec/changes/bookkeeping-journal-entries/specs/bookkeeping-journal-entries/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\JournalVoidGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for JournalVoidGuard (REQ-JE-010).
 *
 * Covers:
 * - void denied when journal has no glTransactionId
 * - void denied when no reversing GLTransaction exists (storneer eerst)
 * - void permitted when an offsetting reversal exists
 * - fail-closed on exception
 */
class JournalVoidGuardTest extends TestCase {

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
	 * @var JournalVoidGuard
	 */
	private JournalVoidGuard $guard;

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

		$this->guard = new JournalVoidGuard(
			container: $this->container,
			appConfig: $this->appConfig,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Void is denied when the journal carries no glTransactionId.
	 *
	 * @return void
	 */
	public function testVoidDeniedWithoutGlTransactionId(): void {
		$this->container->expects($this->never())->method('get');

		self::assertFalse(
			$this->guard->requireReversedGLTransaction(['journalNumber' => 'M-2026-0001'])
		);

	}//end testVoidDeniedWithoutGlTransactionId()

	/**
	 * Void is denied when no offsetting reversal exists.
	 *
	 * @return void
	 */
	public function testVoidDeniedWhenNoReversalExists(): void {
		$objectService = $this->buildObjectServiceStub(reversals: []);
		$this->container->method('get')->willReturn($objectService);

		self::assertFalse(
			$this->guard->requireReversedGLTransaction(
				[
					'journalNumber' => 'M-2026-0002',
					'glTransactionId' => 'txn-001',
				]
			),
			'Void must be denied until the GL transaction is reversed (storneer eerst)'
		);

	}//end testVoidDeniedWhenNoReversalExists()

	/**
	 * Void is permitted once an offsetting reversal exists.
	 *
	 * @return void
	 */
	public function testVoidPermittedWhenReversalExists(): void {
		$objectService = $this->buildObjectServiceStub(
			reversals: [['id' => 'txn-002', 'reversesTransactionId' => 'txn-001']]
		);
		$this->container->method('get')->willReturn($objectService);

		self::assertTrue(
			$this->guard->requireReversedGLTransaction(
				[
					'journalNumber' => 'M-2026-0003',
					'glTransactionId' => 'txn-001',
				]
			)
		);

	}//end testVoidPermittedWhenReversalExists()

	/**
	 * Fail-closed: an exception during the reversal check denies the void.
	 *
	 * @return void
	 */
	public function testVoidFailClosedOnException(): void {
		$objectService = new class {
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * @param array<string,mixed> $params
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				throw new \RuntimeException('DB error');
			}//end findAll()
		};
		$this->container->method('get')->willReturn($objectService);

		self::assertFalse(
			$this->guard->requireReversedGLTransaction(
				[
					'journalNumber' => 'M-2026-0004',
					'glTransactionId' => 'txn-001',
				]
			)
		);

	}//end testVoidFailClosedOnException()

	/**
	 * Build an anonymous ObjectService stub returning the given reversals for
	 * the GLTransaction findAll() query.
	 *
	 * @param array<mixed> $reversals Reversing GLTransaction records to return.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $reversals): object {
		return new class($reversals) {
			private array $reversals;

			public function __construct(array $reversals) {
				$this->reversals = $reversals;
			}//end __construct()

			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * @param array<string,mixed> $params
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				return $this->reversals;
			}//end findAll()
		};

	}//end buildObjectServiceStub()
}//end class
