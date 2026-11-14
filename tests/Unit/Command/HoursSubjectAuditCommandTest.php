<?php

/**
 * Shillinq Hours Subject Audit Command Test
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://shillinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/hours-to-humaniq/specs/hours-to-humaniq/spec.md#requirement-req-h2h-001-hours-on-a-domain-object-live-in-humaniq
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Command;

use OCA\Shillinq\Command\HoursSubjectAuditCommand;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The count that must read zero on every install before the subject overlay is retired.
 *
 * @covers \OCA\Shillinq\Command\HoursSubjectAuditCommand
 */
class HoursSubjectAuditCommandTest extends TestCase {

	/**
	 * Every read the fake object service received.
	 *
	 * @var list<array{register: string, schema: string, config: array<string, mixed>}>
	 */
	public array $reads = [];

	/**
	 * A tester over a fake ObjectService answering the rows in pages.
	 *
	 * @param list<mixed> $rows The UrenRegistratie rows.
	 *
	 * @return CommandTester
	 */
	private function tester(array $rows): CommandTester {
		$test = $this;
		$objectService = new class($rows, $test) {
			private string $register = '';

			private string $schema = '';

			/**
			 * @param list<mixed>                  $rows The rows.
			 * @param HoursSubjectAuditCommandTest $test The test, for read capture.
			 */
			public function __construct(
				private array $rows,
				private HoursSubjectAuditCommandTest $test,
			) {
			}

			/**
			 * @param string $register The register slug.
			 *
			 * @return self Fluent.
			 */
			public function setRegister(string $register): self {
				$this->register = $register;
				return $this;
			}

			/**
			 * @param string $schema The schema slug.
			 *
			 * @return self Fluent.
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return list<mixed> One page.
			 */
			public function findAll(array $config): array {
				$this->test->reads[] = ['register' => $this->register, 'schema' => $this->schema, 'config' => $config];
				return array_slice($this->rows, (int)$config['offset'], (int)$config['limit']);
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objectService);

		return new CommandTester(new HoursSubjectAuditCommand(container: $container));
	}//end tester()

	public function testTheCommandIsNamed(): void {
		$command = new HoursSubjectAuditCommand(container: $this->createMock(ContainerInterface::class));

		self::assertSame('shillinq:hours:subject-audit', $command->getName());
	}//end testTheCommandIsNamed()

	public function testNoEntryWithASubjectMeansNothingToMigrate(): void {
		$tester = $this->tester([
			['administrationId' => 'adm-1', 'hours' => 2],
			['administrationId' => 'adm-1', 'hours' => 3, 'subjectApp' => null, 'subjectId' => null],
		]);

		self::assertSame(0, $tester->execute([]));
		self::assertSame(
			"adm-1: 2 entries, 0 with subjectApp, 0 with subjectId\n"
			. "Total: 2 entries, 0 with subjectApp, 0 with subjectId\n"
			. "Nothing to migrate.\n",
			$tester->getDisplay()
		);
		self::assertSame('shillinq', $this->reads[0]['register']);
		self::assertSame('UrenRegistratie', $this->reads[0]['schema']);
		self::assertFalse($this->reads[0]['config']['_rbac'], 'the count sees every entry, not the caller\'s');
		self::assertFalse($this->reads[0]['config']['_multitenancy']);
	}//end testNoEntryWithASubjectMeansNothingToMigrate()

	public function testEntriesWithASubjectAreCountedPerAdministration(): void {
		$entity = new class {
			/**
			 * @return array<string, mixed> The payload.
			 */
			public function getObject(): array {
				return ['administrationId' => 'adm-1', 'subjectApp' => 'dossiq', 'subjectId' => 'case-1'];
			}
		};
		$tester = $this->tester([
			['administrationId' => 'adm-2', 'subjectApp' => '', 'subjectId' => null],
			$entity,
			['administrationId' => 'adm-1'],
			['administrationId' => 'adm-2', 'subjectId' => 'case-9'],
			['hours' => 1, 'subjectApp' => 'procest'],
			'not a row',
		]);

		self::assertSame(1, $tester->execute([]));
		self::assertSame(
			"(none): 1 entries, 1 with subjectApp, 0 with subjectId\n"
			. "adm-1: 2 entries, 1 with subjectApp, 1 with subjectId\n"
			. "adm-2: 2 entries, 1 with subjectApp, 1 with subjectId\n"
			. "Total: 5 entries, 3 with subjectApp, 2 with subjectId\n"
			. "Entries carry a domain subject: migrate them before the fields are retired.\n",
			$tester->getDisplay()
		);
	}//end testEntriesWithASubjectAreCountedPerAdministration()

	public function testASubjectIdAloneStillNeedsMigrating(): void {
		$tester = $this->tester([['administrationId' => 'adm-1', 'subjectId' => 'case-1']]);

		self::assertSame(1, $tester->execute([]));
	}//end testASubjectIdAloneStillNeedsMigrating()

	public function testEveryPageIsRead(): void {
		$rows = array_fill(0, 450, ['administrationId' => 'adm-1']);
		$rows[449] = ['administrationId' => 'adm-1', 'subjectApp' => 'dossiq'];

		$tester = $this->tester($rows);

		self::assertSame(1, $tester->execute([]));
		self::assertSame([0, 200, 400], array_map(static fn (array $read): int => $read['config']['offset'], $this->reads));
		self::assertStringContainsString('Total: 450 entries, 1 with subjectApp, 0 with subjectId', $tester->getDisplay());
	}//end testEveryPageIsRead()

	public function testAnUnreadableRegisterFailsAndCountsNothing(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('OpenRegister is not installed'));
		$tester = new CommandTester(new HoursSubjectAuditCommand(container: $container));

		self::assertSame(1, $tester->execute([]));
		self::assertSame("could not read UrenRegistratie: OpenRegister is not installed\n", $tester->getDisplay());
	}//end testAnUnreadableRegisterFailsAndCountsNothing()
}//end class
