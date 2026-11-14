<?php

/**
 * GLReversalGuard: how the linked transaction is looked up, and the fail-closed paths.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\GLReversalGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The guard reads the linked GLTransaction from the configured register,
 * and denies a void when it is missing or the lookup fails.
 *
 * @covers \OCA\Shillinq\Lifecycle\GLReversalGuard
 */
final class GLReversalGuardLookupTest extends TestCase {

	/**
	 * The calls the object-service stub received.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * A guard over an object service that answers with $rows.
	 *
	 * @param mixed           $rows     What findAll returns.
	 * @param string          $register The configured register.
	 * @param LoggerInterface $logger   The logger.
	 *
	 * @return GLReversalGuard
	 */
	private function guard(mixed $rows, string $register, LoggerInterface $logger): GLReversalGuard {
		$calls   = &$this->calls;
		$service = new class($rows, $calls) {
			/**
			 * @param mixed                         $rows  The answer.
			 * @param array<int, array<int, mixed>> $calls The call log.
			 */
			public function __construct(private mixed $rows, private array &$calls) {
			}

			public function setRegister(string $register): static {
				$this->calls[] = ['setRegister', $register];
				return $this;
			}

			public function setSchema(string $schema): static {
				$this->calls[] = ['setSchema', $schema];
				return $this;
			}

			public function findAll(array $params = []): mixed {
				$this->calls[] = ['findAll', $params];
				return $this->rows;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($service);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->with('shillinq', 'register', 'shillinq')->willReturn($register);

		return new GLReversalGuard(container: $container, appConfig: $appConfig, logger: $logger);

	}//end guard()

	/**
	 * The lookup asks the configured register for exactly the linked id.
	 *
	 * @return void
	 */
	public function testItLooksUpTheLinkedTransactionInTheConfiguredRegister(): void {
		$guard = $this->guard([['id' => 'gl-7', 'state' => 'reversed']], 'finance', $this->createMock(LoggerInterface::class));

		$this->assertTrue($guard->isReversed(['glTransactionId' => ' gl-7 ']));
		$this->assertSame(
			[
				['setRegister', 'finance'],
				['setSchema', 'GLTransaction'],
				['findAll', ['filters' => ['id' => 'gl-7'], 'limit' => 1]],
			],
			$this->calls
		);

	}//end testItLooksUpTheLinkedTransactionInTheConfiguredRegister()

	/**
	 * An empty register setting falls back to the app register.
	 *
	 * @return void
	 */
	public function testAnEmptyRegisterSettingFallsBackToShillinq(): void {
		$guard = $this->guard([(object)['state' => 'posted']], '', $this->createMock(LoggerInterface::class));

		$this->assertFalse($guard->isReversed(['glTransactionId' => 'gl-1']));
		$this->assertSame(['setRegister', 'shillinq'], $this->calls[0]);

	}//end testAnEmptyRegisterSettingFallsBackToShillinq()

	/**
	 * A missing transaction denies the void and says which one.
	 *
	 * @return void
	 */
	public function testAMissingTransactionDeniesAndLogs(): void {
		foreach ([[], null] as $answer) {
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($this->once())->method('info')->with(
				'GLReversalGuard: linked GLTransaction not found — denying void',
				['glTransactionId' => 'gl-404']
			);
			$this->assertFalse($this->guard($answer, 'shillinq', $logger)->isReversed(['glTransactionId' => 'gl-404']));
		}

	}//end testAMissingTransactionDeniesAndLogs()

	/**
	 * A failing lookup denies the void and logs the error.
	 *
	 * @return void
	 */
	public function testAFailingLookupDenies(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('OpenRegister not installed'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'GLReversalGuard: isReversed check failed — denying void (fail-closed)',
			['exception' => 'OpenRegister not installed']
		);
		$guard = new GLReversalGuard(container: $container, appConfig: $this->createMock(IAppConfig::class), logger: $logger);

		$this->assertFalse($guard->isReversed(['glTransactionId' => 'gl-1']));

	}//end testAFailingLookupDenies()

	/**
	 * Without a link nothing is looked up.
	 *
	 * @return void
	 */
	public function testNoLinkDeniesWithoutALookup(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$guard = new GLReversalGuard(container: $container, appConfig: $this->createMock(IAppConfig::class), logger: $this->createMock(LoggerInterface::class));

		$this->assertFalse($guard->isReversed(['glTransactionId' => '  ']));
		$this->assertFalse($guard->isReversed([]));

	}//end testNoLinkDeniesWithoutALookup()
}//end class
