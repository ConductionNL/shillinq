<?php

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\StandardsPolicyService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Unit tests for the pure ranking logic of StandardsPolicyService (REQ-ASP-003).
 *
 * @covers \OCA\Shillinq\Service\StandardsPolicyService
 */
class StandardsPolicyServiceTest extends TestCase {

	/**
	 * Subject under test (the container is never touched by resolveFromPolicy).
	 *
	 * @var StandardsPolicyService
	 */
	private StandardsPolicyService $service;

	/**
	 * Build the service with a mock container.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new StandardsPolicyService($this->createMock(ContainerInterface::class));

	}//end setUp()

	/**
	 * The lowest-precedence enabled framework wins.
	 *
	 * @return void
	 */
	public function testHighestPrecedenceEnabledWins(): void {
		$frameworks = [
			['key' => 'ifrs', 'enabled' => true, 'precedence' => 1],
			['key' => 'dutch-gaap', 'enabled' => true, 'precedence' => 2],
		];

		$this->assertSame('ifrs', $this->service->resolveFromPolicy($frameworks, 'leases'));

	}//end testHighestPrecedenceEnabledWins()

	/**
	 * Reordering the precedence changes the winner.
	 *
	 * @return void
	 */
	public function testReorderingChangesWinner(): void {
		$frameworks = [
			['key' => 'dutch-gaap', 'enabled' => true, 'precedence' => 1],
			['key' => 'ifrs', 'enabled' => true, 'precedence' => 2],
		];

		$this->assertSame('dutch-gaap', $this->service->resolveFromPolicy($frameworks, 'leases'));

	}//end testReorderingChangesWinner()

	/**
	 * Disabled frameworks are skipped even at a lower precedence.
	 *
	 * @return void
	 */
	public function testDisabledFrameworksAreIgnored(): void {
		$frameworks = [
			['key' => 'ifrs', 'enabled' => false, 'precedence' => 1],
			['key' => 'dutch-gaap', 'enabled' => true, 'precedence' => 2],
		];

		$this->assertSame('dutch-gaap', $this->service->resolveFromPolicy($frameworks, 'revenue'));

	}//end testDisabledFrameworksAreIgnored()

	/**
	 * Precedence — not array order — decides the winner.
	 *
	 * @return void
	 */
	public function testPrecedenceBeatsArrayOrder(): void {
		$frameworks = [
			['key' => 'dutch-gaap', 'enabled' => true, 'precedence' => 5],
			['key' => 'ifrs', 'enabled' => true, 'precedence' => 2],
		];

		$this->assertSame('ifrs', $this->service->resolveFromPolicy($frameworks, null));

	}//end testPrecedenceBeatsArrayOrder()

	/**
	 * No enabled framework resolves to null.
	 *
	 * @return void
	 */
	public function testEmptyPolicyResolvesToNull(): void {
		$this->assertNull($this->service->resolveFromPolicy([], 'leases'));
		$this->assertNull(
			$this->service->resolveFromPolicy(
				[['key' => 'ifrs', 'enabled' => false, 'precedence' => 1]],
				'leases'
			)
		);

	}//end testEmptyPolicyResolvesToNull()

	/**
	 * Saving writes only the named administration's policy, never another's.
	 *
	 * @return void
	 */
	public function testSaveUpdatesOnlyTheNamedAdministrationsPolicy(): void {
		$store = new class {

			/**
			 * Saved calls.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public array $saved = [];

			/**
			 * Filters of the last findAll.
			 *
			 * @var array<string,mixed>
			 */
			public array $filters = [];

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}

			/**
			 * Two administrations' policies; filtered on administrationId.
			 *
			 * @param array<string,mixed> $params Query.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params): array {
				$this->filters = ($params['filters'] ?? []);
				$rows = [
					['@self' => ['id' => 'pol-a'], 'administrationId' => 'adm-a', 'frameworks' => []],
					['@self' => ['id' => 'pol-b'], 'administrationId' => 'adm-b', 'frameworks' => []],
				];

				return array_values(array_filter($rows, fn (array $row): bool => $row['administrationId'] === ($this->filters['administrationId'] ?? null)));
			}

			/**
			 * Record the save.
			 *
			 * @param mixed ...$args Named arguments.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(mixed ...$args): array {
				$this->saved[] = $args;
				return ['@self' => ['id' => $args['uuid'] ?? 'new']] + $args['object'];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$service = new StandardsPolicyService($container);

		$result = $service->savePolicy('adm-b', [['key' => 'ifrs', 'enabled' => true, 'precedence' => 1]]);

		self::assertSame('pol-b', $result['id']);
		self::assertCount(1, $store->saved);
		self::assertSame('pol-b', $store->saved[0]['uuid']);
		self::assertSame('adm-b', $store->saved[0]['object']['administrationId']);
		self::assertSame([['key' => 'ifrs', 'enabled' => true, 'precedence' => 1]], $store->saved[0]['object']['frameworks']);

	}//end testSaveUpdatesOnlyTheNamedAdministrationsPolicy()

	/**
	 * A malformed or repeated framework is refused before anything is written.
	 *
	 * @return void
	 */
	public function testSaveRefusesMalformedFrameworks(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->service->savePolicy(
			'adm-a',
			[
				['key' => 'ifrs', 'enabled' => true, 'precedence' => 1],
				['key' => 'ifrs', 'enabled' => false, 'precedence' => 2],
			]
		);

	}//end testSaveRefusesMalformedFrameworks()

}//end class
