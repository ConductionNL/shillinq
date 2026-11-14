<?php

/**
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Icp
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

namespace OCA\Shillinq\Tests\Unit\Service\Icp;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * An OpenRegister object service over in-memory rows per schema, recording
 * every save.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
trait IcpObjectStore {

	/**
	 * The saves, in order: { object, register, schema, uuid }.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saves = [];

	/**
	 * An object service answering findAll() from the rows, filtered on
	 * every filter key, and recording saveObject().
	 *
	 * @param array<string,array<int,array<string,mixed>>> $rows Rows per schema.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectStore(array $rows): ObjectServiceInterface {
		/** @var TestCase $this */
		$service = $this->createMock(ObjectServiceInterface::class);
		$schema = '';
		$service->method('setRegister')->willReturnSelf();
		$service->method('setSchema')->willReturnCallback(
			static function (string|int $name) use (&$schema, $service): ObjectServiceInterface {
				$schema = (string)$name;
				return $service;
			}
		);
		$service->method('findAll')->willReturnCallback(
			static function (array $config) use (&$schema, $rows): array {
				$filters = ($config['filters'] ?? []);
				return array_values(
					array_filter(
						($rows[$schema] ?? []),
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
			}
		);
		$service->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend=[], string|int|null $register=null, string|int|null $schema=null, ?string $uuid=null): ObjectEntityInterface {
				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($object + ['@self' => ['id' => ($uuid ?? 'new-id')]]);
				return $entity;
			}
		);

		return $service;
	}//end objectStore()
}//end trait
