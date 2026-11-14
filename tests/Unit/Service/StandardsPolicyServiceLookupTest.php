<?php

/**
 * Tests for StandardsPolicyService's stored-policy lookups: resolve() and policyFor().
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\StandardsPolicyService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins how the stored policy is read: the filters asked for, the row shapes
 * accepted (array, serialisable entity, plain object), and the fallbacks.
 */
// phpcs:disable CustomSniffs.Functions.NamedParameters
// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
#[CoversClass(StandardsPolicyService::class)]
final class StandardsPolicyServiceLookupTest extends TestCase {

	/**
	 * An object service answering findAll() with the given rows, recording the config asked for.
	 *
	 * @param array<int, mixed>         $rows     The rows to answer.
	 * @param array<string, mixed>|null $captured Receives the findAll config.
	 *
	 * @return ObjectServiceInterface&MockObject
	 */
	private function objects(array $rows, ?array &$captured = null): ObjectServiceInterface&MockObject {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->with('shillinq')->willReturnSelf();
		$objects->method('setSchema')->with('StandardsPolicy')->willReturnSelf();
		$objects->method('findAll')->willReturnCallback(
			static function (array $config) use ($rows, &$captured): array {
				$captured = $config;
				return $rows;
			}
		);

		return $objects;
	}//end objects()

	/**
	 * Two frameworks; ifrs has the lower (winning) precedence.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function frameworks(): array {
		return [
			['key' => 'nl-gaap', 'enabled' => true, 'precedence' => 2],
			['key' => 'ifrs', 'enabled' => true, 'precedence' => 1],
		];
	}//end frameworks()

	public function testResolveReadsTheAdministrationsPolicyAndPicksTheWinner(): void {
		$captured = null;
		$service = new StandardsPolicyService($this->objects([['frameworks' => $this->frameworks()]], $captured));

		$this->assertSame('ifrs', $service->resolve('revenue', 'adm-1'));
		$this->assertSame(['filters' => ['administrationId' => 'adm-1'], 'limit' => 1], $captured);
	}//end testResolveReadsTheAdministrationsPolicyAndPicksTheWinner()

	public function testResolveWithoutAnAdministrationAsksWithoutAFilter(): void {
		$captured = null;
		$service = new StandardsPolicyService($this->objects([['frameworks' => $this->frameworks()]], $captured));

		$service->resolve();

		$this->assertSame(['filters' => [], 'limit' => 1], $captured);
	}//end testResolveWithoutAnAdministrationAsksWithoutAFilter()

	public function testResolveReadsASerialisableEntityRow(): void {
		$entity = new class($this->frameworks()) implements \JsonSerializable {
			/**
			 * @param array<int, array<string, mixed>> $frameworks The frameworks.
			 */
			public function __construct(private array $frameworks) {
			}

			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['frameworks' => $this->frameworks];
			}
		};

		$this->assertSame('ifrs', (new StandardsPolicyService($this->objects([$entity])))->resolve(null, 'adm-1'));
	}//end testResolveReadsASerialisableEntityRow()

	public function testResolveReadsAPlainObjectRow(): void {
		$row = new \stdClass();
		$row->frameworks = $this->frameworks();

		$this->assertSame('ifrs', (new StandardsPolicyService($this->objects([$row])))->resolve(null, 'adm-1'));
	}//end testResolveReadsAPlainObjectRow()

	public function testResolveIsNullWithoutAPolicyARowOrReadableFrameworks(): void {
		$this->assertNull((new StandardsPolicyService($this->objects([])))->resolve(null, 'adm-1'));
		$this->assertNull((new StandardsPolicyService($this->objects(['not a row'])))->resolve(null, 'adm-1'));
		$this->assertNull((new StandardsPolicyService($this->objects([['frameworks' => 'ifrs']])))->resolve(null, 'adm-1'));
	}//end testResolveIsNullWithoutAPolicyARowOrReadableFrameworks()

	public function testResolveIsNullWhenTheStoreFails(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willThrowException(new RuntimeException('store down'));

		$this->assertNull((new StandardsPolicyService($objects))->resolve(null, 'adm-1'));
	}//end testResolveIsNullWhenTheStoreFails()

	public function testPolicyForReturnsTheStoredIdAndFrameworks(): void {
		$captured = null;
		$service = new StandardsPolicyService(
			$this->objects([['@self' => ['id' => 'pol-1'], 'frameworks' => ['a' => $this->frameworks()[0]]]], $captured)
		);

		$this->assertSame(['id' => 'pol-1', 'frameworks' => [$this->frameworks()[0]]], $service->policyFor('adm-1'));
		$this->assertSame(['filters' => ['administrationId' => 'adm-1'], 'limit' => 1], $captured);
	}//end testPolicyForReturnsTheStoredIdAndFrameworks()

	public function testPolicyForFallsBackToTheTopLevelIdAndDropsNonArrayFrameworks(): void {
		$service = new StandardsPolicyService($this->objects([['id' => 42, 'frameworks' => 'ifrs']]));

		$this->assertSame(['id' => '42', 'frameworks' => []], $service->policyFor('adm-1'));
	}//end testPolicyForFallsBackToTheTopLevelIdAndDropsNonArrayFrameworks()

	public function testPolicyForSkipsUnreadableRowsAndAnswersEmptyWithoutAPolicy(): void {
		$service = new StandardsPolicyService($this->objects([42]));

		$this->assertSame(['id' => null, 'frameworks' => []], $service->policyFor('adm-1'));
	}//end testPolicyForSkipsUnreadableRowsAndAnswersEmptyWithoutAPolicy()

	public function testPolicyForAnEmptyAdministrationNeverAsksTheStore(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->never())->method('findAll');

		$this->assertSame(['id' => null, 'frameworks' => []], (new StandardsPolicyService($objects))->policyFor(''));
	}//end testPolicyForAnEmptyAdministrationNeverAsksTheStore()
}//end class
