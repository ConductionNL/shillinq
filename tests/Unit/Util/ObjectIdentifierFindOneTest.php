<?php

/**
 * Unit tests for ObjectIdentifier::findOne().
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Util
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

namespace OCA\Shillinq\Tests\Unit\Util;

use OCA\Shillinq\Util\ObjectIdentifier;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The by-uuid and by-property lookups forward the caller's RBAC choice.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ObjectIdentifierFindOneTest extends TestCase {

	/**
	 * A scoped ObjectService double that records the `_rbac` flag per call.
	 *
	 * @param array<string,mixed>|null $byId     What find() answers, or null to throw.
	 * @param array<int,array>         $byFilter What findAll() answers.
	 *
	 * @return object
	 */
	private function scoped(?array $byId, array $byFilter): object {
		return new class($byId, $byFilter) {
			/**
			 * @var array<int,array{0:string,1:bool}>
			 */
			public array $calls = [];

			/**
			 * @param array<string,mixed>|null $byId     find() answer.
			 * @param array<int,array>         $byFilter findAll() answer.
			 */
			public function __construct(
				private ?array $byId,
				private array $byFilter,
			) {
			}//end __construct()

			/**
			 * @param string $id    Identifier.
			 * @param bool   $_rbac Whether OpenRegister RBAC applies.
			 *
			 * @return array<string,mixed>
			 */
			public function find(string $id, bool $_rbac = true): array {
				$this->calls[] = ['find', $_rbac];
				if ($this->byId === null) {
					throw new RuntimeException('not a uuid');
				}

				return $this->byId;
			}//end find()

			/**
			 * @param array<string,mixed> $config Query.
			 * @param bool                $_rbac  Whether OpenRegister RBAC applies.
			 *
			 * @return array<int,array>
			 */
			public function findAll(array $config = [], bool $_rbac = true): array {
				$this->calls[] = ['findAll', $_rbac];
				return $this->byFilter;
			}//end findAll()
		};
	}//end scoped()

	/**
	 * By default both lookups stay subject to OpenRegister RBAC.
	 *
	 * @return void
	 */
	public function testLookupsApplyRbacByDefault(): void {
		$scoped = $this->scoped(null, [['code' => 'ADM-001']]);

		$record = ObjectIdentifier::findOne($scoped, 'ADM-001', 'code');

		self::assertSame(['code' => 'ADM-001'], $record);
		self::assertSame([['find', true], ['findAll', true]], $scoped->calls);
	}//end testLookupsApplyRbacByDefault()

	/**
	 * A key-, token- or signature-authenticated caller asks for both lookups
	 * without RBAC.
	 *
	 * @return void
	 */
	public function testLookupsSkipRbacWhenAsked(): void {
		$scoped = $this->scoped(null, [['code' => 'ADM-001']]);

		ObjectIdentifier::findOne($scoped, 'ADM-001', 'code', false);

		self::assertSame([['find', false], ['findAll', false]], $scoped->calls);
	}//end testLookupsSkipRbacWhenAsked()

	/**
	 * A uuid hit answers without the property fallback.
	 *
	 * @return void
	 */
	public function testUuidHitSkipsFallback(): void {
		$scoped = $this->scoped(['id' => 'u-1', 'name' => 'x'], []);

		$record = ObjectIdentifier::findOne($scoped, 'u-1', 'code', false);

		self::assertSame('x', $record['name']);
		self::assertSame([['find', false]], $scoped->calls);
	}//end testUuidHitSkipsFallback()
}//end class
