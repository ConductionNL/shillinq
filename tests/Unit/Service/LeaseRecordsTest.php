<?php

/**
 * Unit tests for LeaseRecords.
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
 * @spec openspec/specs/bookkeeping-lease-disclosures/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\LeaseRecords;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The administration-scoped lease and event reads of the lease services.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(LeaseRecords::class)]
final class LeaseRecordsTest extends TestCase {

	/**
	 * An object service double that records the register, schema and query it
	 * was asked for, and answers $rows or throws $error.
	 *
	 * @param mixed                 $rows  The findAll() answer.
	 * @param \Throwable|null       $error Thrown by findAll() when set.
	 * @param array<string,mixed>   $seen  Filled with the register, schema and query.
	 *
	 * @return object The double.
	 */
	private function objectService(mixed $rows, ?\Throwable $error, array &$seen): object {
		return new class($rows, $error, $seen) {
			/**
			 * @param mixed               $rows  The answer.
			 * @param \Throwable|null     $error The error to throw.
			 * @param array<string,mixed> $seen  The recorded calls.
			 */
			public function __construct(private mixed $rows, private ?\Throwable $error, private array &$seen) {
			}

			/**
			 * @param string $register The register.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				$this->seen['register'] = $register;
				return $this;
			}

			/**
			 * @param string $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->seen['schema'] = $schema;
				return $this;
			}

			/**
			 * @param array<string,mixed> $query The query.
			 *
			 * @return mixed
			 */
			public function findAll(array $query): mixed {
				$this->seen['query'] = $query;
				if ($this->error !== null) {
					throw $this->error;
				}

				return $this->rows;
			}
		};
	}

	/**
	 * A lease is found by id or by slug among the administration's leases.
	 *
	 * @return void
	 */
	public function testALeaseIsMatchedOnItsIdOrSlugWithinTheAdministration(): void {
		$seen = [];
		$rows = ['not a lease', ['id' => 'l-1', '@self' => ['slug' => 'office']], ['@self' => ['id' => 'l-2', 'slug' => 'car']]];
		$records = new LeaseRecords($this->objectService($rows, null, $seen), 'shillinq', $this->createMock(LoggerInterface::class));

		self::assertSame('l-1', $records->lease('office', 'adm-1', 'unused')['id']);
		self::assertSame('car', $records->lease('l-2', 'adm-1', 'unused')['@self']['slug']);
		self::assertNull($records->lease('l-3', 'adm-1', 'unused'));
		self::assertSame('shillinq', $seen['register']);
		self::assertSame('LeaseContract', $seen['schema']);
		self::assertSame(['filters' => ['administrationId' => 'adm-1']], $seen['query']);
	}

	/**
	 * A failing lease query is logged with the owner's message and answers null.
	 *
	 * @return void
	 */
	public function testAFailingLeaseQueryIsLoggedUnderTheOwnersMessageAndReadsAsNoLease(): void {
		$seen = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'Owner: failed to read lease',
			['administrationId' => 'adm-1', 'exception' => 'down'],
		);
		$records = new LeaseRecords($this->objectService([], new RuntimeException('down'), $seen), 'shillinq', $logger);

		self::assertNull($records->lease('l-1', 'adm-1', 'Owner: failed to read lease'));
	}

	/**
	 * Events are read for the source lease within the administration.
	 *
	 * @return void
	 */
	public function testEventsAreQueriedForTheSourceLeaseInTheAdministration(): void {
		$seen = [];
		$rows = [['id' => 'e-1']];
		$records = new LeaseRecords($this->objectService($rows, null, $seen), 'reg', $this->createMock(LoggerInterface::class));

		self::assertSame($rows, $records->events('lease-1', 'adm-1'));
		self::assertSame('reg', $seen['register']);
		self::assertSame('LeaseReassessmentEvent', $seen['schema']);
		self::assertSame(['filters' => ['administrationId' => 'adm-1', 'sourceLease' => 'lease-1']], $seen['query']);
	}

	/**
	 * A failing event query throws to the caller.
	 *
	 * @return void
	 */
	public function testAFailingEventQueryIsLeftToTheCaller(): void {
		$seen = [];
		$records = new LeaseRecords($this->objectService([], new RuntimeException('down'), $seen), 'reg', $this->createMock(LoggerInterface::class));

		$this->expectException(RuntimeException::class);
		$records->events('lease-1', 'adm-1');
	}
}
