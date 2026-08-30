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

}//end class
