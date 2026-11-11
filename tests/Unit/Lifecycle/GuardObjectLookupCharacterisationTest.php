<?php

/**
 * Characterisation tests for the object lookup nine lifecycle guards share.
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
 * @spec openspec/changes/bookkeeping-voorzieningen-claims/specs/bookkeeping-voorzieningen-claims/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\CbcrPillar2Guard;
use OCA\Shillinq\Lifecycle\CcmFindingGuard;
use OCA\Shillinq\Lifecycle\ConsolidationGuard;
use OCA\Shillinq\Lifecycle\CsrdEsrsGuard;
use OCA\Shillinq\Lifecycle\DBAComplianceGuard;
use OCA\Shillinq\Lifecycle\DualGaapGuard;
use OCA\Shillinq\Lifecycle\DunningGuard;
use OCA\Shillinq\Lifecycle\FidoTreasuryGuard;
use OCA\Shillinq\Lifecycle\PensionIas19Guard;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Pins the private `resolveObject()` of the nine guards that carry the same
 * copy: the in-flight object wins, an empty id resolves nothing, and a lookup
 * reads the configured register (default `shillinq`) with an `id` property
 * filter and answers the first array row.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class GuardObjectLookupCharacterisationTest extends TestCase {

	/**
	 * The guards.
	 *
	 * @return array<string, array{0: class-string}> Cases.
	 */
	public static function guards(): array {
		$cases = [];
		foreach ([CbcrPillar2Guard::class, CcmFindingGuard::class, ConsolidationGuard::class, CsrdEsrsGuard::class, DBAComplianceGuard::class, DualGaapGuard::class, DunningGuard::class, FidoTreasuryGuard::class, PensionIas19Guard::class] as $class) {
			$cases[substr($class, strrpos($class, '\\') + 1)] = [$class];
		}

		return $cases;

	}//end guards()

	/**
	 * Resolve through the guard's private method, recording the reads.
	 *
	 * @param class-string      $class The guard class.
	 * @param string            $slug  The configured register slug.
	 * @param list<mixed>       $rows  What findAll() answers.
	 * @param array<int, mixed> $args  schema, id, object.
	 * @param list<string>      $reads Receives the reads.
	 *
	 * @return mixed The resolved object.
	 */
	private function resolve(string $class, string $slug, array $rows, array $args, array &$reads): mixed {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnCallback(
			static function (string $register) use ($objectService, &$reads) {
				$reads[] = 'register ' . $register;
				return $objectService;
			}
		);
		$objectService->method('setSchema')->willReturnCallback(
			static function (string $schema) use ($objectService, &$reads) {
				$reads[] = 'schema ' . $schema;
				return $objectService;
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config) use ($rows, &$reads): array {
				$reads[] = 'findAll ' . json_encode($config);
				return $rows;
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->with('shillinq', 'register', 'shillinq')->willReturn($slug);

		$guard = new $class(appConfig: $appConfig, logger: $this->createMock(LoggerInterface::class), objectService: $objectService);
		$method = new ReflectionMethod($guard, 'resolveObject');
		$method->setAccessible(true);

		return $method->invokeArgs($guard, $args);

	}//end resolve()

	/**
	 * Every branch of the shared lookup.
	 *
	 * @param class-string $class The guard class.
	 *
	 * @return void
	 */
	#[DataProvider('guards')]
	public function testLookup(string $class): void {
		$reads = [];
		self::assertSame(['inflight' => true], $this->resolve($class, 'x', [['id' => 'stored']], ['S', 'id-1', ['inflight' => true]], $reads));
		self::assertSame([], $this->resolve($class, 'x', [['id' => 'stored']], ['S', 'id-1', []], $reads));
		self::assertNull($this->resolve($class, 'x', [['id' => 'stored']], ['S', '', null], $reads));
		self::assertSame([], $reads);

		self::assertSame(['id' => 'a'], $this->resolve($class, 'books', ['junk', ['id' => 'a'], ['id' => 'b']], ['Schema1', 'id-2', null], $reads));
		self::assertSame(['register books', 'schema Schema1', 'findAll {"filters":{"id":"id-2"}}'], $reads);

		$reads = [];
		self::assertNull($this->resolve($class, '', ['junk', 7], ['Schema2', 'id-3', null], $reads));
		self::assertSame(['register shillinq', 'schema Schema2', 'findAll {"filters":{"id":"id-3"}}'], $reads);

	}//end testLookup()
}//end class
