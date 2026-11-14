<?php

/**
 * Pins how ReportGenerationService::generate() stores and tags a rendered report:
 * the folder path it creates or descends, the non-colliding file name, the tags
 * it resolves, creates and assigns, and the warnings when a step fails.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Reporting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment, Squiz.PHP.DisallowInlineIf
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting;

use OCA\Shillinq\Kernel\Model\GeneratedFile;
use OCA\Shillinq\Reporting\ReportGenerationService;
use OCA\Shillinq\Reporting\ReportGeneratorInterface;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;

/**
 * Stores a balance sheet named by the case and records what the storage did.
 */
final class ReportFileStorageTest extends TestCase {

	/**
	 * Generate a balance sheet for adm-1 into the given tree.
	 *
	 * @param StoredFileTreeFixture $tree     The files, tags and log.
	 * @param string                $fileName The rendered file name.
	 *
	 * @return array<string, mixed> The generate() result.
	 */
	private function generate(StoredFileTreeFixture $tree, string $fileName): array {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(
			new class {
				public function saveObject(array $object, string $register, string $schema): array {
					return $object;
				}
			}
		);
		$service = new ReportGenerationService(
			$container,
			$tree->rootFolder(),
			$tree->tagManager(),
			$tree->tagMapper(),
			$this->createMock(IUserSession::class),
			$tree,
		);
		$generator = new class($fileName) implements ReportGeneratorInterface {
			public function __construct(
				private readonly string $fileName,
			) {
			}

			public static function reportType(): string {
				return 'balance-sheet';
			}

			public static function supportedFormats(): array {
				return ['pdf'];
			}

			public function generate(array $context, string $format): GeneratedFile {
				return new GeneratedFile($this->fileName, 'application/pdf', 'pdf', 'bytes');
			}
		};
		$property = (new ReflectionClass($service))->getProperty('generators');
		$property->setAccessible(true);
		$property->setValue($service, ['balance-sheet' => $generator]);

		return $service->generate('balance-sheet', '2026', 'adm-1', 'pdf', 'alice');

	}//end generate()

	/**
	 * Existing folders are descended, a missing one created; a missing tag is
	 * created and a failing assignment is logged, the others still assigned.
	 *
	 * @return void
	 */
	public function testStoresAndTagsTheReport(): void {
		$tree = new StoredFileTreeFixture(
			$this,
			['Shillinq' => ['Reports' => []]],
			['shillinq-period:2026'],
			['shillinq-administration:adm-1']
		);

		$result = $this->generate($tree, 'balans.pdf');

		self::assertSame(42, $result['fileId']);
		self::assertSame('/home/Shillinq/Reports/adm-1/balans.pdf', $result['filePath']);
		self::assertSame(
			[
				'folder /home/Shillinq/Reports/adm-1',
				'file /home/Shillinq/Reports/adm-1/balans.pdf (bytes)',
				'assign 42 files shillinq-report:balance-sheet',
				'create-tag shillinq-period:2026',
				'assign 42 files shillinq-period:2026',
				'warning ReportGenerationService: failed to apply system tag',
				'assign 42 files shillinq-category:statements',
			],
			$tree->events
		);

	}//end testStoresAndTagsTheReport()

	/**
	 * A taken name gets the first free -N suffix before the extension, or at the end without one.
	 *
	 * @return void
	 */
	public function testATakenNameGetsTheFirstFreeSuffix(): void {
		$tree = new StoredFileTreeFixture($this, ['Shillinq' => ['Reports' => ['adm-1' => ['balans.pdf' => 'file', 'balans-1.pdf' => 'file']]]]);
		self::assertSame('/home/Shillinq/Reports/adm-1/balans-2.pdf', $this->generate($tree, 'balans.pdf')['filePath']);

		$tree = new StoredFileTreeFixture($this, ['Shillinq' => ['Reports' => ['adm-1' => ['balans' => 'file']]]]);
		self::assertSame('/home/Shillinq/Reports/adm-1/balans-1', $this->generate($tree, 'balans')['filePath']);

	}//end testATakenNameGetsTheFirstFreeSuffix()

	/**
	 * A path segment that is a file stops the storage with a warning and no file or tag.
	 *
	 * @return void
	 */
	public function testASegmentThatIsAFileStoresNothing(): void {
		$tree = new StoredFileTreeFixture($this, ['Shillinq' => ['Reports' => 'file']]);

		$result = $this->generate($tree, 'balans.pdf');

		self::assertNull($result['fileId']);
		self::assertNull($result['filePath']);
		self::assertSame(['warning ReportGenerationService: report path segment is not a folder'], $tree->events);

	}//end testASegmentThatIsAFileStoresNothing()
}//end class
