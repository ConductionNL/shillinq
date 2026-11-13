<?php

/**
 * Edge-case unit tests for PhotoValidator.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Validation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/expense-capture-core/tasks.md#task-11
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Validation;

use OCA\Shillinq\Validation\PhotoValidator;
use PHPUnit\Framework\TestCase;

/**
 * Exact MIME matching, size boundaries and check order (REQ-EC-008).
 *
 * @covers \OCA\Shillinq\Validation\PhotoValidator
 */
final class PhotoValidatorEdgeCasesTest extends TestCase {

	/**
	 * The validator under test.
	 *
	 * @var PhotoValidator
	 */
	private PhotoValidator $validator;

	/**
	 * A real temporary file used as the upload.
	 *
	 * @var string
	 */
	private string $tmpFile;

	/**
	 * Set up the validator and a small temporary file.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->validator = new PhotoValidator();
		$this->tmpFile   = (string) tempnam(sys_get_temp_dir(), 'photo_edge_');
		file_put_contents($this->tmpFile, 'x');
	}//end setUp()

	/**
	 * Remove the temporary file.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if (file_exists($this->tmpFile) === true) {
			unlink($this->tmpFile);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * MIME types are matched exactly: case, aliases and padding are rejected.
	 *
	 * @return void
	 */
	public function testMimeTypeMustMatchExactly(): void {
		foreach (['IMAGE/JPEG', 'image/jpg', 'image/png ', 'Application/PDF', 'image/gif', 'image/webp', ''] as $mime) {
			self::assertFalse(
				$this->validator->validate($this->tmpFile, $mime, 1),
				'MIME "' . $mime . '" must be rejected'
			);
		}
	}//end testMimeTypeMustMatchExactly()

	/**
	 * The size limit is 10 MiB (1024-based), not 10 MB (1000-based).
	 *
	 * @return void
	 */
	public function testSizeLimitIsBinaryMegabytes(): void {
		self::assertTrue($this->validator->validate($this->tmpFile, 'image/png', 10_000_001));
		self::assertTrue($this->validator->validate($this->tmpFile, 'image/png', 10_485_760));
		self::assertFalse($this->validator->validate($this->tmpFile, 'image/png', 10_485_761));
	}//end testSizeLimitIsBinaryMegabytes()

	/**
	 * An empty upload of an allowed type is accepted.
	 *
	 * @return void
	 */
	public function testZeroByteSizeIsAccepted(): void {
		self::assertTrue($this->validator->validate($this->tmpFile, 'application/pdf', 0));
	}//end testZeroByteSizeIsAccepted()

	/**
	 * The reported size decides, not the size of the file on disk.
	 *
	 * @return void
	 */
	public function testReportedSizeIsWhatIsChecked(): void {
		// The file on disk is 1 byte; the reported size is over the limit.
		self::assertFalse($this->validator->validate($this->tmpFile, 'image/jpeg', PHP_INT_MAX));
	}//end testReportedSizeIsWhatIsChecked()

	/**
	 * An empty path or a removed file is rejected even when type and size pass.
	 *
	 * @return void
	 */
	public function testMissingFileIsRejected(): void {
		self::assertFalse($this->validator->validate('', 'image/jpeg', 1));

		unlink($this->tmpFile);
		self::assertFalse($this->validator->validate($this->tmpFile, 'image/jpeg', 1));
	}//end testMissingFileIsRejected()

	/**
	 * Every failing condition alone is enough to reject.
	 *
	 * @return void
	 */
	public function testEachConditionRejectsOnItsOwn(): void {
		$missing = $this->tmpFile . '_missing';

		self::assertTrue($this->validator->validate($this->tmpFile, 'image/jpeg', 1));
		self::assertFalse($this->validator->validate($this->tmpFile, 'text/plain', 1));
		self::assertFalse($this->validator->validate($this->tmpFile, 'image/jpeg', 10_485_761));
		self::assertFalse($this->validator->validate($missing, 'image/jpeg', 1));
		self::assertFalse($this->validator->validate($missing, 'text/plain', 10_485_761));
	}//end testEachConditionRejectsOnItsOwn()
}//end class
