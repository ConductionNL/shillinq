<?php

/**
 * Unit tests for GuardRegistrar: every lifecycle `requires` tag resolves to a
 * fail-closed adapter over the guard and method it names.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\GuardRegistrar;
use OCA\Shillinq\Lifecycle\RegisterRequiresGuardAdapter;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Shillinq\Guard\GuardRegistrar
 * @uses   \OCA\Shillinq\Lifecycle\RegisterRequiresGuardAdapter
 */
class GuardRegistrarTest extends TestCase {

	/**
	 * Tag => the guard class the container is asked for.
	 */
	private const EXPECTED = [
		'OCA\Shillinq\Guard\Iv3XmlValidationGuard::requireValidXml' => 'OCA\Shillinq\Guard\Iv3XmlValidationGuard',
		'OCA\Shillinq\Guard\Iv3SubmissionGuard::requireApproval' => 'OCA\Shillinq\Guard\Iv3SubmissionGuard',
		'OCA\Shillinq\Guard\KorLockoutGuard::requireLockoutExpired' => 'OCA\Shillinq\Guard\KorLockoutGuard',
		'OCA\Shillinq\Guard\ProjectActivationGuard::requireStartDate' => 'OCA\Shillinq\Guard\ProjectActivationGuard',
		'OCA\Shillinq\Guard\ProjectTransitionGuard::requireReason' => 'OCA\Shillinq\Guard\ProjectTransitionGuard',
		'OCA\Shillinq\Guard\ProjectCloseGuard::requireWipJustificationOrZero' => 'OCA\Shillinq\Guard\ProjectCloseGuard',
		'OCA\Shillinq\Guard\VatSubmissionGuard::requireApproval' => 'OCA\Shillinq\Guard\VatSubmissionGuard',
		'OCA\Shillinq\Guard\BcfSubmissionGuard::requireApproval' => 'OCA\Shillinq\Guard\BcfSubmissionGuard',
		'OCA\Shillinq\Guard\SubsidieRepaymentGuard::requireZeroRepaymentBalance' => 'OCA\Shillinq\Guard\SubsidieRepaymentGuard',
		'OCA\Shillinq\Guard\RateScheduleOverlapGuard::requireNonOverlappingWindow' => 'OCA\Shillinq\Guard\RateScheduleOverlapGuard',
		'OCA\Shillinq\Service\OssPaymentReconciliation::canMarkPaid' => 'OCA\Shillinq\Guard\OssPaymentGuard',
		'OCA\Shillinq\Guard\IntercompanyEliminationGuard::requireReconciledPair' => 'OCA\Shillinq\Guard\IntercompanyEliminationGuard',
	];

	/**
	 * The factories registered, by tag.
	 *
	 * @return array<string, callable>
	 */
	private function factories(): array {
		$factories = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			static function (string $name, callable $factory) use (&$factories): void {
				$factories[$name] = $factory;
			}
		);

		(new GuardRegistrar())->register($context);

		return $factories;
	}//end factories()

	/**
	 * Exactly the expected tags are registered, each once.
	 *
	 * @return void
	 */
	public function testEveryRequiresTagIsRegistered(): void {
		$this->assertSame(array_keys(self::EXPECTED), array_keys($this->factories()));
	}//end testEveryRequiresTagIsRegistered()

	/**
	 * Each factory builds an adapter over the guard class it names that calls
	 * the tag's method, allows when it answers true and denies with a reason
	 * when it answers false.
	 *
	 * @return void
	 */
	public function testEachFactoryWrapsTheNamedGuardAndMethod(): void {
		foreach ($this->factories() as $tag => $factory) {
			$guard = new class {
				/**
				 * Methods called, with the object.
				 *
				 * @var array<int, array{0: string, 1: array}>
				 */
				public array $calls = [];

				/**
				 * The answer to give.
				 *
				 * @var bool
				 */
				public bool $answer = true;

				/**
				 * Record any guard method.
				 *
				 * @param string $name      The method.
				 * @param array  $arguments The arguments.
				 *
				 * @return bool
				 */
				public function __call(string $name, array $arguments): bool {
					$this->calls[] = [$name, $arguments[0]];
					return $this->answer;
				}
			};
			$asked = [];
			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willReturnCallback(
				function (string $id) use ($guard, &$asked): object {
					$asked[] = $id;
					if ($id === LoggerInterface::class) {
						return $this->createMock(LoggerInterface::class);
					}

					return $guard;
				}
			);

			$adapter = $factory($container);

			$this->assertInstanceOf(RegisterRequiresGuardAdapter::class, $adapter, $tag);
			$this->assertSame([self::EXPECTED[$tag], LoggerInterface::class], $asked, $tag);
			$this->assertTrue($adapter->check(['id' => 'o-1'], 'submit', 'alice')->isAllowed(), $tag);

			$guard->answer = false;
			$denied = $adapter->check(['id' => 'o-1'], 'submit', 'alice');
			$this->assertFalse($denied->isAllowed(), $tag);
			$this->assertNotSame('', (string)$denied->getMessage(), $tag);

			$method = substr($tag, (strpos($tag, '::') + 2));
			$this->assertSame([[$method, ['id' => 'o-1']], [$method, ['id' => 'o-1']]], $guard->calls, $tag);
		}//end foreach
	}//end testEachFactoryWrapsTheNamedGuardAndMethod()
}//end class
