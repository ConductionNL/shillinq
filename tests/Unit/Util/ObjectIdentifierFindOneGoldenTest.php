<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Util;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Shillinq\Util\ObjectIdentifier;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Golden record of findOne() over object-service doubles that do not report
 * their register and schema: every call with its arguments, and the result,
 * for uuid hits, misses, fallbacks, empty answers, entities and failures.
 *
 * @covers \OCA\Shillinq\Util\ObjectIdentifier
 */
final class ObjectIdentifierFindOneGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/../../fixtures/golden/object-identifier-find-one.json';

	/**
	 * A double whose find() and findAll() answer as told and record their arguments.
	 *
	 * @param string $find    'hit', 'entity', 'empty', 'emptyEntity' or 'throw'.
	 * @param string $findAll 'hit', 'entity', 'empty', 'junk' or 'throw'.
	 */
	private static function scoped(string $find, string $findAll): object {
		return new class($find, $findAll) {
			/** @var array<int, array<int|string, mixed>> */
			public array $calls = [];
			public function __construct(private string $find, private string $findAll) {
			}
			public function find(string $id, mixed ...$named): mixed {
				$this->calls[] = ['find', $id, $named];
				return match ($this->find) {
					'hit' => ['id' => $id, 'name' => 'by uuid'],
					'entity' => (new ObjectEntity())->setObject(['name' => 'entity by uuid']),
					'empty' => [],
					'emptyEntity' => (new ObjectEntity())->setObject([]),
					'throw' => throw new RuntimeException('DoesNotExist'),
				};
			}
			public function findAll(array $config = [], mixed ...$named): array {
				$this->calls[] = ['findAll', $config, $named];
				return match ($this->findAll) {
					'hit' => [[], ['code' => 'by property']],
					'entity' => [(new ObjectEntity())->setObject(['code' => 'entity by property'])],
					'empty' => [],
					'junk' => ['x', 3, null],
					'throw' => throw new RuntimeException('down'),
				};
			}
		};
	}

	public function testFindOneMatchesTheRecording(): void {
		$actual = [];
		foreach (['hit', 'entity', 'empty', 'emptyEntity', 'throw'] as $find) {
			foreach (['hit', 'entity', 'empty', 'junk', 'throw'] as $findAll) {
				foreach ([null, 'code'] as $fallback) {
					foreach ([[], ['_rbac' => false]] as $scope) {
						foreach (['u-1', ''] as $id) {
							$scoped = self::scoped($find, $findAll);
							$result = ObjectIdentifier::findOne($scoped, $id, $fallback, $scope);
							$actual[] = [
								'case' => implode(' ', [$find, $findAll, (string)$fallback, json_encode($scope), $id]),
								'result' => $result,
								'calls' => $scoped->calls,
							];
						}
					}
				}
			}
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}
		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		self::assertCount(count($expected), $actual);
		foreach ($expected as $i => $record) {
			self::assertSame($record, json_decode((string)json_encode($actual[$i]), true), $record['case']);
		}
	}
}
