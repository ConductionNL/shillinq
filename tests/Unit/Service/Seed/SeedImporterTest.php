<?php

/**
 * Unit tests for SeedImporter.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Seed
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

namespace OCA\Shillinq\Tests\Unit\Service\Seed;

use OCA\Shillinq\Service\Seed\SeedImport;
use OCA\Shillinq\Service\Seed\SeedImporter;
use OCA\Shillinq\Service\Seed\SeedMessages;
use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for reading seed files and leaving out rows without a match.
 *
 * @covers \OCA\Shillinq\Service\Seed\SeedImporter
 * @uses   \OCA\Shillinq\Service\Seed\SeedImport
 * @uses   \OCA\Shillinq\Service\Seed\SeedMessages
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SeedImporterTest extends TestCase {

	private const FIXTURES = '../../../tests/Unit/Service/Seed/fixtures/';

	/**
	 * Build an importer whose container hands out $objectService.
	 *
	 * @param object $objectService The object-service double.
	 *
	 * @return SeedImporter
	 */
	private function importer(object $objectService): SeedImporter {
		$settings = $this->createStub(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('books');
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		return new SeedImporter(
			settings: $settings,
			container: $container,
			logger: $this->createStub(LoggerInterface::class),
		);

	}//end importer()

	/**
	 * A missing seed file is named in the failure, by default and with own messages.
	 *
	 * @return void
	 */
	public function testAMissingFileIsNamedInTheFailure(): void {
		$importer = $this->importer(new \stdClass());

		self::assertSame(
			['error' => ['success' => false, 'message' => 'Seed file not found: no-such-seed.json']],
			$importer->read(fileName: 'no-such-seed.json')
		);
		self::assertSame(
			['error' => ['success' => false, 'message' => 'gone']],
			$importer->read(fileName: 'no-such-seed.json', messages: ['missing' => 'gone', 'unreadable' => 'x', 'invalid' => 'y'])
		);

	}//end testAMissingFileIsNamedInTheFailure()

	/**
	 * A file that is not valid JSON fails with the JSON error.
	 *
	 * @return void
	 */
	public function testInvalidJsonFailsWithTheJsonError(): void {
		$importer = $this->importer(new \stdClass());
		$fileName = self::FIXTURES . 'invalid-seed.txt';

		self::assertSame(
			['error' => ['success' => false, 'message' => 'Failed to parse ' . $fileName . ': Syntax error']],
			$importer->read(fileName: $fileName)
		);

	}//end testInvalidJsonFailsWithTheJsonError()

	/**
	 * A row whose match returns null is neither probed nor saved.
	 *
	 * @return void
	 */
	public function testARowWithoutAMatchIsLeftOut(): void {
		$objects = new class {

			/**
			 * Recorded calls.
			 *
			 * @var array<int, string>
			 */
			public array $calls = [];

			/**
			 * @param mixed $register The register.
			 *
			 * @return self
			 */
			public function setRegister(mixed $register): self {
				return $this;
			}

			/**
			 * @param mixed $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(mixed $schema): self {
				return $this;
			}

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config): array {
				$this->calls[] = 'findAll ' . $config['filters']['code'];
				return [];
			}

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param mixed                $register The register.
			 * @param mixed                $schema   The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, mixed $register, mixed $schema): array {
				$this->calls[] = 'save ' . $object['code'];
				return $object;
			}
		};

		$match = static function ($row): ?array {
			if ($row['code'] === '') {
				return null;
			}

			return ['code' => $row['code']];
		};

		$result = $this->importer($objects)->import(
			import: new SeedImport(
				schema: 'Thing',
				rows: [['code' => 'a'], ['code' => ''], ['code' => 'c']],
				match: $match,
			),
			messages: new SeedMessages(seededLog: 'seeded', seededMessage: 'done', failedLog: 'failed'),
		);

		self::assertSame(['success' => true, 'message' => 'done', 'seeded' => 2, 'skipped' => 0], $result);
		self::assertSame(['findAll a', 'save a', 'findAll c', 'save c'], $objects->calls);

	}//end testARowWithoutAMatchIsLeftOut()
}//end class
