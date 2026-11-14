<?php

/**
 * Failure paths of the references audit: no OpenRegister, an unreadable
 * satellite schema, a refused write, and owner rows that cannot be indexed.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Command
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

namespace OCA\Shillinq\Tests\Unit\Command;

use OCA\Shillinq\Command\ReferencesAuditCommand;
use OCA\Shillinq\Service\HrmqCostRateAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OCA\Shillinq\Command\ReferencesAuditCommand
 */
class ReferencesAuditCommandFailureTest extends TestCase {

	/**
	 * A tester over an object service with the given rows and failures.
	 *
	 * @param array<int, mixed>  $satellites     payrollEmployee rows.
	 * @param array<int, mixed>  $owners         Employee rows.
	 * @param string|null        $satelliteError Thrown when payrollEmployee is read.
	 * @param string|null        $writeError     Thrown by patchObject.
	 *
	 * @return CommandTester
	 */
	private function tester(array $satellites, array $owners, ?string $satelliteError = null, ?string $writeError = null): CommandTester {
		$objectService = new class($satellites, $owners, $satelliteError, $writeError) {
			/**
			 * The schema being read.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array       $satellites     payrollEmployee rows.
			 * @param array       $owners         Employee rows.
			 * @param string|null $satelliteError Read failure.
			 * @param string|null $writeError     Write failure.
			 */
			public function __construct(
				private array $satellites,
				private array $owners,
				private ?string $satelliteError,
				private ?string $writeError,
			) {
			}

			/**
			 * Set the register.
			 *
			 * @param string $register The register.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}

			/**
			 * Set the schema.
			 *
			 * @param string $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * One page of rows.
			 *
			 * @param array $config The query.
			 *
			 * @return array
			 */
			public function findAll(array $config): array {
				if (($config['offset'] ?? 0) > 0) {
					return [];
				}

				if ($this->schema === 'Employee') {
					return $this->owners;
				}

				if ($this->satelliteError !== null) {
					throw new RuntimeException($this->satelliteError);
				}

				return $this->satellites;
			}

			/**
			 * Patch one object.
			 *
			 * @param string      $objectId      The id.
			 * @param array       $data          The fields.
			 * @param string|null $register      The register.
			 * @param string|null $schema        The schema.
			 * @param bool        $_rbac         RBAC.
			 * @param bool        $_multitenancy Multitenancy.
			 *
			 * @return array
			 */
			public function patchObject(string $objectId, array $data, ?string $register = null, ?string $schema = null, bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->writeError !== null) {
					throw new RuntimeException($this->writeError);
				}

				return $data;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$humaniq = $this->createMock(HrmqCostRateAdapter::class);
		$humaniq->method('registerSlug')->willReturn('humaniq');

		return new CommandTester(new ReferencesAuditCommand($container, $humaniq));
	}//end tester()

	/**
	 * Without OpenRegister the command says so and fails.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheCommandFails(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no such service'));
		$humaniq = $this->createMock(HrmqCostRateAdapter::class);
		$humaniq->expects($this->never())->method('registerSlug');
		$tester = new CommandTester(new ReferencesAuditCommand($container, $humaniq));

		$tester->execute([]);

		self::assertSame(1, $tester->getStatusCode());
		self::assertSame("OpenRegister is not available: no such service\n", $tester->getDisplay());
	}//end testWithoutOpenRegisterTheCommandFails()

	/**
	 * An unreadable satellite schema says which and fails.
	 *
	 * @return void
	 */
	public function testAnUnreadableSatelliteSchemaFails(): void {
		$tester = $this->tester([], [], satelliteError: 'schema missing');

		$tester->execute([]);

		self::assertSame(1, $tester->getStatusCode());
		self::assertSame("could not read payrollEmployee: schema missing\n", $tester->getDisplay());
	}//end testAnUnreadableSatelliteSchemaFails()

	/**
	 * A refused write is reported per row and not counted as written.
	 *
	 * @return void
	 */
	public function testARefusedWriteIsReportedAndNotCounted(): void {
		$tester = $this->tester(
			[['id' => 'p1', 'bsn' => '123', 'employee' => '']],
			[['id' => 'e1', 'bsn' => '123']],
			writeError: 'read-only register'
		);

		$tester->execute(['--write' => true]);

		self::assertStringContainsString('  write failed p1: read-only register', $tester->getDisplay());
		self::assertStringContainsString('0 written', $tester->getDisplay());
	}//end testARefusedWriteIsReportedAndNotCounted()

	/**
	 * Owner rows without a payload or without an identity key are not indexed,
	 * so they cannot make a match ambiguous.
	 *
	 * @return void
	 */
	public function testUnindexableOwnerRowsAreSkipped(): void {
		$tester = $this->tester(
			[['id' => 'p1', 'bsn' => '123', 'employee' => '']],
			[42, ['id' => 'e0', 'bsn' => '  '], ['id' => 'e1', 'bsn' => '123']]
		);

		$tester->execute([]);

		self::assertSame(0, $tester->getStatusCode());
		self::assertStringContainsString('1 backfillable', $tester->getDisplay());
		self::assertStringNotContainsString('ambiguous  p1', $tester->getDisplay());
	}//end testUnindexableOwnerRowsAreSkipped()
}//end class
