<?php

/**
 * Unit tests for GLReversalGuard.
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
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-3
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
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class GLReversalGuardTest extends TestCase {
	/**
	 * @param array<int,array<string,mixed>> $transactions GLTransaction rows.
	 *
	 * @return GLReversalGuard
	 */
	private function buildGuard(array $transactions): GLReversalGuard {
		$stub = new class($transactions) {
			/**
			 * @var array<int,array<string,mixed>>
			 */
			private array $transactions;

			/**
			 * @param array<int,array<string,mixed>> $transactions GLTransaction rows.
			 */
			public function __construct(array $transactions) {
				$this->transactions = $transactions;
			}//end __construct()

			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$filters = ($params['filters'] ?? []);
				return array_values(
					array_filter(
						$this->transactions,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}//end findAll()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new GLReversalGuard(
			container: $container,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end buildGuard()

	/**
	 * Good path: linked GLTransaction is already reversed.
	 *
	 * @return void
	 */
	public function testVoidAllowedWhenLinkedTransactionReversed(): void {
		$guard = $this->buildGuard([['id' => 'gl-1', 'state' => 'reversed']]);

		$allowed = $guard->isReversed(['glTransactionId' => 'gl-1']);
		self::assertTrue($allowed);

	}//end testVoidAllowedWhenLinkedTransactionReversed()

	/**
	 * Bad path: linked GLTransaction still posted — deny void.
	 *
	 * @return void
	 */
	public function testVoidDeniedWhenLinkedTransactionNotReversed(): void {
		$guard = $this->buildGuard([['id' => 'gl-1', 'state' => 'posted']]);

		$allowed = $guard->isReversed(['glTransactionId' => 'gl-1']);
		self::assertFalse($allowed);

	}//end testVoidDeniedWhenLinkedTransactionNotReversed()

	/**
	 * Bad path: no glTransactionId at all — fail closed.
	 *
	 * @return void
	 */
	public function testVoidDeniedWithoutGlTransactionId(): void {
		$guard = $this->buildGuard([]);

		$allowed = $guard->isReversed(['glTransactionId' => '']);
		self::assertFalse($allowed);

	}//end testVoidDeniedWithoutGlTransactionId()
}//end class
