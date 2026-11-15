<?php

/**
 * Behaviour tests for RenameCollidingSchemaSlugs when the database fails.
 *
 * The sibling RenameCollidingSchemaSlugsTest pins the rename decisions. This
 * file pins the two failure paths: an unreadable schema table leaves the slug
 * alone, and a failing rename is logged loudly instead of thrown.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\RenameCollidingSchemaSlugs;
use OCP\DB\Exception;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins RenameCollidingSchemaSlugs' failure paths.
 *
 * @covers \OCA\Shillinq\Repair\RenameCollidingSchemaSlugs
 */
final class RenameCollidingSchemaSlugsFailuresTest extends TestCase {

	/**
	 * An unreadable schema table leaves the slug alone and runs no UPDATE.
	 *
	 * @return void
	 */
	public function testAnUnreadableTableLeavesTheSlugAlone(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willThrowException(new Exception('no such table'));
		$db->expects($this->never())->method('executeStatement');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning')
			->with(
				'RenameCollidingSchemaSlugs: could not read the schema table; skipping.',
				$this->callback(fn (array $context): bool => $context['exception'] === 'no such table')
			);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')
			->with('RenameCollidingSchemaSlugs: schema table unreadable; leaving `Employee` alone.');

		(new RenameCollidingSchemaSlugs(db: $db, logger: $logger))->run($output);
	}//end testAnUnreadableTableLeavesTheSlugAlone()

	/**
	 * A failing UPDATE is logged as an error and reported, never thrown.
	 *
	 * @return void
	 */
	public function testAFailingRenameIsReported(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params): IResult {
				$result = $this->createMock(IResult::class);
				$ids = [];
				if ($params[0] === 'Employee') {
					$ids = [41];
				}

				$result->method('fetchAll')->willReturn($ids);
				return $result;
			}
		);
		$db->expects($this->once())->method('executeStatement')
			->with('UPDATE `*PREFIX*openregister_schemas` SET slug = ? WHERE id = ?', ['payrollEmployee', 41])
			->willThrowException(new Exception('deadlock'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'RenameCollidingSchemaSlugs: slug rename failed; the import will create a second schema.',
			['from' => 'Employee', 'id' => 41, 'exception' => 'deadlock']
		);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('info');
		$output->expects($this->once())->method('warning')
			->with('RenameCollidingSchemaSlugs: renaming `Employee` failed; see the log.');

		$step = new RenameCollidingSchemaSlugs(db: $db, logger: $logger);
		self::assertSame('Namespace the shillinq schema slugs that collided with another app', $step->getName());
		$step->run($output);
	}//end testAFailingRenameIsReported()
}//end class
