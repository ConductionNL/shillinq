<?php

/**
 * Unit tests for BadoControleprotocolService::organisationIdFor().
 *
 * `Controleprotocol.organisationId` is the ONLY tenant field in the whole BADO
 * bundle — the six child schemas carry none and hang off the protocol's FK — so
 * this accessor is what the ADR-005 membership check in
 * BadoControleprotocolController is made against. It was added by #520 and had
 * no test.
 *
 * The lookup shape is load-bearing and is asserted here: it must address the
 * object by identity through `find(id:, register:, schema:)`. The obvious
 * alternative, `findAll(['filters' => ['id' => $uuid]])`, cannot ever match —
 * `id` is `@self.id`, a bigint column, and Postgres answers with SQLSTATE[22P02]
 * which the service swallows. A guard built on it refuses the legitimate owner
 * as well as the attacker; that was measured live on a two-account rig as
 * owner 200 -> 404 before the shape was corrected.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use JsonSerializable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\BadoControleprotocolCalculator;
use OCA\Shillinq\Service\BadoControleprotocolService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests the BADO tenant accessor.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BadoControleprotocolTenantTest extends TestCase {

	/**
	 * Mock container.
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
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');

	}//end setUp()

	/**
	 * Build the service over a given ObjectService.
	 *
	 * ADR-084 injects the ObjectService through the constructor, so the
	 * subject has to be built AFTER the per-test double exists — it can no
	 * longer be assembled once in setUp() and re-pointed through a container.
	 *
	 * @param ObjectServiceInterface $objectService The object service to inject.
	 *
	 * @return BadoControleprotocolService
	 */
	private function serviceWith(ObjectServiceInterface $objectService): BadoControleprotocolService {
		return new BadoControleprotocolService(
			appConfig: $this->appConfig,
			calculator: $this->createMock(BadoControleprotocolCalculator::class),
			logger: new NullLogger(),
			objectService: $objectService,
		);

	}//end serviceWith()

	/**
	 * Build an ObjectService double that mirrors ObjectService::find().
	 *
	 * The real signature is
	 * `find(int|string $id, ?array $_extend = [], bool $files = false,
	 *       Register|string|int|null $register = null,
	 *       Schema|string|int|null $schema = null, ...): ?ObjectEntity`.
	 * The parameter NAMES are reproduced because the caller passes them by
	 * name; a double that renamed them would accept a call the real service
	 * rejects with "Unknown named parameter".
	 *
	 * @param mixed $result What find() returns.
	 *
	 * @return object
	 */
	private function fakeObjectService(mixed $result): object {
		return new class($result) {
			/**
			 * What find() returns.
			 *
			 * @var mixed
			 */
			private mixed $result;

			/**
			 * The arguments find() was called with.
			 *
			 * @var array<string,mixed>
			 */
			public array $seenFind = [];

			/**
			 * Constructor.
			 *
			 * @param mixed $result What find() returns.
			 */
			public function __construct(mixed $result) {
				$this->result = $result;
			}//end __construct()

			/**
			 * Identity lookup (mirrors ObjectService::find).
			 *
			 * @param int|string $id Object identity.
			 * @param array|null $_extend Properties to extend.
			 * @param bool $files Whether to include files.
			 * @param string|null $register Register slug.
			 * @param string|null $schema Schema slug.
			 *
			 * @return mixed
			 */
			public function find(
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				?string $register = null,
				?string $schema = null,
			): mixed {
				$this->seenFind = [
					'id' => $id,
					'register' => $register,
					'schema' => $schema,
				];

				return $this->result;
			}//end find()

			/**
			 * Present so a regression onto the broken lookup fails loudly
			 * instead of silently returning null, the way it does live.
			 *
			 * @param array<string,mixed> $config Query configuration.
			 *
			 * @return array<int,mixed>
			 */
			public function findAll(array $config = []): array {
				throw new \LogicException(
					'organisationIdFor() must address the protocol by identity: '
					. "findAll(['filters' => ['id' => ...]]) cannot match a bigint @self.id."
				);
			}//end findAll()

			/**
			 * Fluent register setter (mirrors ObjectService::setRegister).
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter (mirrors ObjectService::setSchema).
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()
		};

	}//end fakeObjectService()

	/**
	 * An empty protocol id resolves to null without touching the data layer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-12
	 */
	public function testAnEmptyProtocolIdResolvesToNull(): void {
		// The data layer is the injected ObjectService now that ADR-084
		// removed the container: an empty id must not reach it at all.
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->expects($this->never())->method('find');

		self::assertNull($this->serviceWith($objectService)->organisationIdFor(protocolId: ''));

	}//end testAnEmptyProtocolIdResolvesToNull()

	/**
	 * A protocol that does not exist resolves to null, and the lookup addresses
	 * the object by identity with the configured register and schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-12
	 */
	public function testAnAbsentProtocolResolvesToNull(): void {
		$stub = $this->fakeObjectService(null);

		self::assertNull(
			$this->serviceWith(new DuckObjectServiceAdapter($stub))->organisationIdFor(protocolId: 'proto-1')
		);
		self::assertSame(
			['id' => 'proto-1', 'register' => 'shillinq', 'schema' => 'Controleprotocol'],
			$stub->seenFind
		);

	}//end testAnAbsentProtocolResolvesToNull()

	/**
	 * An ObjectEntity is normalised through jsonSerialize().
	 *
	 * ObjectService::find() is declared `: ?ObjectEntity` and ObjectEntity
	 * implements JsonSerializable, so the entity arm — not the array arm — is
	 * the one production actually takes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-12
	 */
	public function testAnEntityIsNormalisedThroughJsonSerialize(): void {
		$entity = new class implements JsonSerializable {

			/**
			 * Serialise the object body.
			 *
			 * @return array<string,mixed>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'proto-1', 'organisationId' => 'adm-1'];
			}//end jsonSerialize()
		};

		$stub = $this->fakeObjectService($entity);

		self::assertSame(
			'adm-1',
			$this->serviceWith(new DuckObjectServiceAdapter($stub))->organisationIdFor(protocolId: 'proto-1')
		);

	}//end testAnEntityIsNormalisedThroughJsonSerialize()

	/**
	 * A protocol carrying no organisationId resolves to the empty string.
	 *
	 * The caller treats '' as a refusal — AdministrationContextService::canAccess()
	 * fails closed on it — so a tenant-less protocol is inaccessible rather than
	 * accessible to everyone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bookkeeping-bado-controleprotocol/tasks.md#task-12
	 */
	public function testAProtocolWithoutATenantResolvesToTheEmptyString(): void {
		$stub = $this->fakeObjectService(['id' => 'proto-1']);

		self::assertSame(
			'',
			$this->serviceWith(new DuckObjectServiceAdapter($stub))->organisationIdFor(protocolId: 'proto-1')
		);

	}//end testAProtocolWithoutATenantResolvesToTheEmptyString()
}//end class
