<?php

/**
 * Unit tests for the SeedFragment repair helper.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Repair\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/app-administration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair\Support;

use OCA\Shillinq\Repair\Support\SeedFragment;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Shillinq\Repair\Support\SeedFragment
 *
 * @spec openspec/specs/app-administration/spec.md
 */
final class SeedFragmentTest extends TestCase {

	/**
	 * Info lines written to the output.
	 *
	 * @var array<int,string>
	 */
	private array $info = [];

	/**
	 * Warning lines written to the output.
	 *
	 * @var array<int,string>
	 */
	private array $warnings = [];

	/**
	 * Fragment files created by a test.
	 *
	 * @var array<int,string>
	 */
	private array $files = [];

	/**
	 * Remove the fragment files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->files as $file) {
			@unlink($file);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * An output that records what it is told.
	 *
	 * @return IOutput
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->info[] = $message;
			}
		);
		$output->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);
		return $output;
	}//end recordingOutput()

	/**
	 * A fragment file with the given content.
	 *
	 * @param string $content The file content.
	 *
	 * @return string The path.
	 */
	private function fragment(string $content): string {
		$path = tempnam(sys_get_temp_dir(), 'seed');
		file_put_contents($path, $content);
		$this->files[] = $path;
		return $path;
	}//end fragment()

	/**
	 * The objects of a well-formed fragment come back unchanged and nothing is reported.
	 *
	 * @return void
	 */
	public function testTheObjectsOfAFragmentAreReturned(): void {
		$path = $this->fragment(content: '{"objects":[{"@self":{"slug":"a"}},{"x":1}]}');

		$objects = (new SeedFragment(path: $path, label: 'CBS'))->objects(output: $this->recordingOutput());

		$this->assertSame([['@self' => ['slug' => 'a']], ['x' => 1]], $objects);
		$this->assertSame([], $this->info);
		$this->assertSame([], $this->warnings);
	}//end testTheObjectsOfAFragmentAreReturned()

	/**
	 * A missing file is a labelled warning naming the path.
	 *
	 * @return void
	 */
	public function testAMissingFileIsReported(): void {
		$path = sys_get_temp_dir() . '/no-such-seed-fragment.json';

		$this->assertNull((new SeedFragment(path: $path, label: 'DBA'))->objects(output: $this->recordingOutput()));
		$this->assertSame(['DBA seed fragment not found at ' . $path . ', skipping'], $this->warnings);
	}//end testAMissingFileIsReported()

	/**
	 * Content that is not a JSON object is a labelled parse warning.
	 *
	 * @return void
	 */
	public function testContentThatIsNotAJsonObjectIsReported(): void {
		$path = $this->fragment(content: '"just a string"');

		$this->assertNull((new SeedFragment(path: $path, label: 'SBR/XBRL'))->objects(output: $this->recordingOutput()));
		$this->assertSame(['Failed to parse SBR/XBRL seed fragment: No error'], $this->warnings);
	}//end testContentThatIsNotAJsonObjectIsReported()

	/**
	 * A fragment without objects is a labelled info line, not a warning.
	 *
	 * @return void
	 */
	public function testAFragmentWithoutObjectsIsNothingToSeed(): void {
		$path = $this->fragment(content: '{"objects":[]}');

		$this->assertNull((new SeedFragment(path: $path, label: 'CBS'))->objects(output: $this->recordingOutput()));
		$this->assertSame(['CBS seed fragment carries no objects; nothing to seed'], $this->info);
		$this->assertSame([], $this->warnings);
	}//end testAFragmentWithoutObjectsIsNothingToSeed()
}//end class
