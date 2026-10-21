<?php

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\StandardsPolicyService;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

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
		$this->service = new StandardsPolicyService($this->createMock(ObjectServiceInterface::class));

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
		$rows = [
			['@self' => ['id' => 'pol-a'], 'administrationId' => 'adm-a', 'frameworks' => []],
			['@self' => ['id' => 'pol-b'], 'administrationId' => 'adm-b', 'frameworks' => []],
		];
		$saved = [];
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('findAll')->willReturnCallback(
			static fn (array $config): array => array_values(
				array_filter($rows, static fn (array $row): bool => $row['administrationId'] === ($config['filters']['administrationId'] ?? null))
			)
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend, $register, $schema, ?string $uuid) use (&$saved): ObjectEntityInterface {
				$saved[] = ['object' => $object, 'uuid' => $uuid];
				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn(['@self' => ['id' => $uuid]] + $object);
				return $entity;
			}
		);
		$service = new StandardsPolicyService($objects);

		$result = $service->savePolicy('adm-b', [['key' => 'ifrs', 'enabled' => true, 'precedence' => 1]]);

		self::assertSame('pol-b', $result['id']);
		self::assertCount(1, $saved);
		self::assertSame('pol-b', $saved[0]['uuid']);
		self::assertSame('adm-b', $saved[0]['object']['administrationId']);
		self::assertSame([['key' => 'ifrs', 'enabled' => true, 'precedence' => 1]], $saved[0]['object']['frameworks']);

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
