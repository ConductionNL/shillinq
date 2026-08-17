<?php

/**
 * Unit tests for ObjectionPeriodGuard.
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
 * @spec openspec/changes/bookkeeping-vpb-mkb/specs/bookkeeping-vpb-mkb/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\ObjectionPeriodGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ObjectionPeriodGuard.
 *
 * Covers REQ-VPB-010 — bezwaar within 6 weeks of the aanslag dagtekening and
 * beroep within 6 weeks of the inspecteur uitspraak.
 */
class ObjectionPeriodGuardTest extends TestCase {

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
	 * @var ObjectionPeriodGuard
	 */
	private ObjectionPeriodGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		// phpcs:enable CustomSniffs.Functions.NamedParameters

		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->guard = new ObjectionPeriodGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($this->buildSchemaStub(recordsBySchema: [])),
		);

	}//end setUp()

	/**
	 * Point the guard at the given duck-typed ObjectService store.
	 *
	 * The store is a constructor dependency since ADR-084, so the guard has to
	 * be rebuilt whenever a test seeds different records.
	 *
	 * @param object $store The in-memory ObjectService double.
	 *
	 * @return void
	 */
	private function wireObjectService(object $store): void {
		$this->container->method('get')->willReturn($store);

		$this->guard = new ObjectionPeriodGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($store),
		);

	}//end wireObjectService()

	/**
	 * Bezwaar is admissible within the 6-week termijn from the aanslag dagtekening (REQ-VPB-010).
	 *
	 * @return void
	 */
	public function testCanBezwaarMakenWithinTermijn(): void {
		$issueDate = (new \DateTimeImmutable('today'))->modify('-1 week')->format('Y-m-d');
		$this->wireObjectService(
			store: $this->buildSchemaStub(recordsBySchema: ['DefinitieveAanslag' => [['taxReturn' => 'aangifte-1', 'issueDate' => $issueDate]]])
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canFileObjection(taxReturnId:'aangifte-1', object: ['id' => 'aangifte-1']));

	}//end testCanBezwaarMakenWithinTermijn()

	/**
	 * Bezwaar is inadmissible once the 6-week termijn has passed (REQ-VPB-010).
	 *
	 * @return void
	 */
	public function testCannotBezwaarMakenAfterTermijn(): void {
		$issueDate = (new \DateTimeImmutable('today'))->modify('-8 weeks')->format('Y-m-d');
		$this->wireObjectService(
			store: $this->buildSchemaStub(recordsBySchema: ['DefinitieveAanslag' => [['taxReturn' => 'aangifte-2', 'issueDate' => $issueDate]]])
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canFileObjection(taxReturnId:'aangifte-2', object: ['id' => 'aangifte-2']));

	}//end testCannotBezwaarMakenAfterTermijn()

	/**
	 * Bezwaar is denied (fail-closed) when no aanslag is linked (REQ-VPB-010).
	 *
	 * @return void
	 */
	public function testCannotBezwaarMakenWithoutAanslag(): void {
		$this->wireObjectService(store: $this->buildSchemaStub(recordsBySchema: ['DefinitieveAanslag' => []]));

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canFileObjection(taxReturnId:'aangifte-3', object: ['id' => 'aangifte-3']));

	}//end testCannotBezwaarMakenWithoutAanslag()

	/**
	 * Beroep is admissible within 6 weeks of the inspecteur uitspraak (REQ-VPB-010).
	 *
	 * @return void
	 */
	public function testCanBeroepInstellenWithinTermijn(): void {
		$ruling = (new \DateTimeImmutable('today'))->modify('-2 weeks')->format('Y-m-d');

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue(
			$this->guard->canFileAppeal(objectionId:'bezwaar-1', object: ['rulingDate' => $ruling])
		);

	}//end testCanBeroepInstellenWithinTermijn()

	/**
	 * Beroep is inadmissible once the 6-week beroepstermijn has passed (REQ-VPB-010).
	 *
	 * @return void
	 */
	public function testCannotBeroepInstellenAfterTermijn(): void {
		$ruling = (new \DateTimeImmutable('today'))->modify('-7 weeks')->format('Y-m-d');

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse(
			$this->guard->canFileAppeal(objectionId:'bezwaar-2', object: ['rulingDate' => $ruling])
		);

	}//end testCannotBeroepInstellenAfterTermijn()

	/**
	 * Beroep is denied (fail-closed) when no uitspraakDatum is recorded (REQ-VPB-010).
	 *
	 * @return void
	 */
	public function testCannotBeroepInstellenWithoutUitspraak(): void {
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse(
			$this->guard->canFileAppeal(objectionId:'bezwaar-3', object: ['rulingDate' => ''])
		);

	}//end testCannotBeroepInstellenWithoutUitspraak()

	/**
	 * An exception in the termijn path fails closed (REQ-VPB-010, CWE-863).
	 *
	 * @return void
	 */
	public function testBezwaarExceptionFailsClosed(): void {
		$this->wireObjectService(store: $this->buildFailingObjectServiceStub());
		$this->logger->expects($this->once())->method('error');

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canFileObjection(taxReturnId:'aangifte-x', object: ['id' => 'aangifte-x']));

	}//end testBezwaarExceptionFailsClosed()

	/**
	 * Build a schema-aware ObjectService stub.
	 *
	 * @param array<string,array<mixed>> $recordsBySchema Map of schema slug => records.
	 *
	 * @return object
	 */
	private function buildSchemaStub(array $recordsBySchema): object {
		return new class($recordsBySchema) {
			/**
			 * Map of schema slug => records.
			 *
			 * @var array<string,array<mixed>>
			 */
			private array $recordsBySchema;

			/**
			 * The currently selected schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string,array<mixed>> $recordsBySchema Map of schema => records.
			 */
			public function __construct(array $recordsBySchema) {
				$this->recordsBySchema = $recordsBySchema;
			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug (unused).
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter — selects the active record set.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return the records for the active schema.
			 *
			 * @param array<string,mixed> $params Query parameters (unused in stub).
			 *
			 * @return array<mixed>
			 */
			public function findAll(array $params = []): array {
				return ($this->recordsBySchema[$this->schema] ?? []);
			}//end findAll()
		};
	}//end buildSchemaStub()

	/**
	 * Build an ObjectService store that refuses every read.
	 *
	 * Since the store is injected rather than pulled from the container, an
	 * unavailable OpenRegister is modelled by a store that throws.
	 *
	 * @return object
	 */
	private function buildFailingObjectServiceStub(): object {
		return new class {

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug (unused).
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema slug (unused).
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Refuse the read, as an unavailable ObjectService would.
			 *
			 * @param array<string,mixed> $params Query parameters (unused in stub).
			 *
			 * @return array<mixed>
			 *
			 * @throws \RuntimeException Always.
			 */
			public function findAll(array $params = []): array {
				throw new \RuntimeException('down');
			}//end findAll()
		};
	}//end buildFailingObjectServiceStub()
}//end class
