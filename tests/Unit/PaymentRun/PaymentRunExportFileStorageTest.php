<?php

/**
 * Pins how PaymentRunExportService stores and tags one rendered export file:
 * the folder path it creates or descends, the non-colliding file name, the tags
 * it resolves, creates and assigns, and the warnings when a step fails.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\PaymentRun
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
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\PaymentRun;

use OCA\Shillinq\PaymentRun\PaymentBlockChecker;
use OCA\Shillinq\PaymentRun\PaymentRunExportRun;
use OCA\Shillinq\PaymentRun\PaymentRunExportService;
use OCA\Shillinq\PaymentRun\RenderedFile;
use OCA\Shillinq\Tests\Unit\Reporting\StoredFileTreeFixture;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionMethod;

/**
 * Stores one CSV export of run PR-1 and records what the storage did.
 */
final class PaymentRunExportFileStorageTest extends TestCase {

	/**
	 * Store a CSV named by the case for run PR-1 of adm-1 into the given tree.
	 *
	 * @param StoredFileTreeFixture $tree     The files, tags and log.
	 * @param string                $fileName The rendered file name.
	 *
	 * @return PaymentRunExportRun The run, with the stored file recorded.
	 */
	private function store(StoredFileTreeFixture $tree, string $fileName): PaymentRunExportRun {
		$service = new PaymentRunExportService(
			$this->createMock(ContainerInterface::class),
			$tree->rootFolder(),
			$tree->tagManager(),
			$tree->tagMapper(),
			$this->createMock(IUserSession::class),
			$tree,
			$this->createMock(PaymentBlockChecker::class),
		);
		$run = new PaymentRunExportRun(['administrationId' => 'adm-1', 'runNumber' => 'PR-1'], 'admin');
		$storeOne = new ReflectionMethod($service, 'storeOne');
		$storeOne->setAccessible(true);
		$storeOne->invoke($service, $run, new RenderedFile($fileName, 'text/csv', 'csv', 'rows'));

		return $run;

	}//end store()

	/**
	 * Existing folders are descended, a missing one created; a missing tag is
	 * created and a failing assignment is logged, the others still assigned.
	 *
	 * @return void
	 */
	public function testStoresAndTagsTheFile(): void {
		$tree = new StoredFileTreeFixture(
			$this,
			['Shillinq' => []],
			['shillinq-administration:adm-1'],
			['shillinq-payment-run:PR-1']
		);

		$run = $this->store($tree, 'PR-1.csv');

		self::assertSame('/home/Shillinq/PaymentRuns/adm-1/PR-1.csv', $run->files[0]['filePath']);
		self::assertSame(42, $run->files[0]['fileId']);
		self::assertSame(
			[
				'folder /home/Shillinq/PaymentRuns',
				'folder /home/Shillinq/PaymentRuns/adm-1',
				'file /home/Shillinq/PaymentRuns/adm-1/PR-1.csv (rows)',
				'warning PaymentRunExportService: failed to apply system tag',
				'create-tag shillinq-administration:adm-1',
				'assign 42 files shillinq-administration:adm-1',
				'assign 42 files shillinq-file-type:csv',
			],
			$tree->events
		);

	}//end testStoresAndTagsTheFile()

	/**
	 * A taken name gets the first free -N suffix before the extension, or at the end without one.
	 *
	 * @return void
	 */
	public function testATakenNameGetsTheFirstFreeSuffix(): void {
		$tree = new StoredFileTreeFixture($this, ['Shillinq' => ['PaymentRuns' => ['adm-1' => ['PR-1.csv' => 'file']]]]);
		self::assertSame('/home/Shillinq/PaymentRuns/adm-1/PR-1-1.csv', $this->store($tree, 'PR-1.csv')->files[0]['filePath']);

		$tree = new StoredFileTreeFixture($this, ['Shillinq' => ['PaymentRuns' => ['adm-1' => ['PR-1' => 'file', 'PR-1-1' => 'file']]]]);
		self::assertSame('/home/Shillinq/PaymentRuns/adm-1/PR-1-2', $this->store($tree, 'PR-1')->files[0]['filePath']);

	}//end testATakenNameGetsTheFirstFreeSuffix()

	/**
	 * A path segment that is a file stops the storage with a warning and no file or tag.
	 *
	 * @return void
	 */
	public function testASegmentThatIsAFileStoresNothing(): void {
		$tree = new StoredFileTreeFixture($this, ['Shillinq' => 'file']);

		$run = $this->store($tree, 'PR-1.csv');

		self::assertNull($run->files[0]['fileId']);
		self::assertSame(['warning PaymentRunExportService: path segment is not a folder'], $tree->events);

	}//end testASegmentThatIsAFileStoresNothing()
}//end class
