<?php

/**
 * Unit tests for PhotoValidator.
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Validation;

use OCA\Shillinq\Validation\PhotoValidator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PhotoValidator per REQ-EC-008.
 *
 * Covers:
 * - JPEG, PNG, PDF accepted
 * - Unsupported file type rejected
 * - File exceeding 10 MB rejected
 * - Non-existent file path rejected
 */
class PhotoValidatorTest extends TestCase {

	/**
	 * The validator under test.
	 *
	 * @var PhotoValidator
	 */
	private PhotoValidator $validator;

	/**
	 * Temporary file path created for tests that need a real file.
	 *
	 * @var string|null
	 */
	private ?string $tmpFile = null;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->validator = new PhotoValidator();

	}//end setUp()

	/**
	 * Clean up temporary files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ($this->tmpFile !== null && file_exists(filename: $this->tmpFile)) {
			unlink(filename: $this->tmpFile);
		}

		parent::tearDown();

	}//end tearDown()

	/**
	 * Create a temporary file of given size for testing.
	 *
	 * @param int $bytes File size in bytes.
	 *
	 * @return string Path to the temporary file.
	 */
	private function makeTmpFile(int $bytes): string {
		$path = sys_get_temp_dir() . '/photo_validator_test_' . uniqid() . '.tmp';
		$this->tmpFile = $path;
		file_put_contents(filename: $path, data: str_repeat(string: 'x', times: $bytes));
		return $path;
	}//end makeTmpFile()

	/**
	 * JPEG MIME type is accepted when size is within limit.
	 *
	 * @return void
	 */
	public function testJpegIsAccepted(): void {
		$path = $this->makeTmpFile(bytes: 1024);
		self::assertTrue(
			condition: $this->validator->validate(filePath: $path, mimeType: 'image/jpeg', fileSize: 1024),
			message: 'image/jpeg within size limit must be accepted'
		);

	}//end testJpegIsAccepted()

	/**
	 * PNG MIME type is accepted when size is within limit.
	 *
	 * @return void
	 */
	public function testPngIsAccepted(): void {
		$path = $this->makeTmpFile(bytes: 2048);
		self::assertTrue(
			condition: $this->validator->validate(filePath: $path, mimeType: 'image/png', fileSize: 2048),
			message: 'image/png within size limit must be accepted'
		);

	}//end testPngIsAccepted()

	/**
	 * PDF MIME type is accepted when size is within limit.
	 *
	 * @return void
	 */
	public function testPdfIsAccepted(): void {
		$path = $this->makeTmpFile(bytes: 512);
		self::assertTrue(
			condition: $this->validator->validate(filePath: $path, mimeType: 'application/pdf', fileSize: 512),
			message: 'application/pdf within size limit must be accepted'
		);

	}//end testPdfIsAccepted()

	/**
	 * Unsupported file type (text/plain) is rejected.
	 *
	 * @return void
	 */
	public function testUnsupportedMimeTypeIsRejected(): void {
		$path = $this->makeTmpFile(bytes: 100);
		self::assertFalse(
			condition: $this->validator->validate(filePath: $path, mimeType: 'text/plain', fileSize: 100),
			message: 'text/plain must be rejected as unsupported file type'
		);

	}//end testUnsupportedMimeTypeIsRejected()

	/**
	 * File exceeding 10 MB is rejected.
	 *
	 * @return void
	 */
	public function testFileTooLargeIsRejected(): void {
		// 10 MB + 1 byte.
		$oversizedBytes = 10 * 1024 * 1024 + 1;
		$path = $this->makeTmpFile(bytes: 1);
		self::assertFalse(
			condition: $this->validator->validate(filePath: $path, mimeType: 'image/jpeg', fileSize: $oversizedBytes),
			message: 'File exceeding 10 MB must be rejected'
		);

	}//end testFileTooLargeIsRejected()

	/**
	 * Non-existent file path is rejected (fail-closed).
	 *
	 * @return void
	 */
	public function testNonExistentFileIsRejected(): void {
		self::assertFalse(
			condition: $this->validator->validate(
				filePath: '/tmp/non_existent_file_' . uniqid() . '.jpg',
				mimeType: 'image/jpeg',
				fileSize: 1024
			),
			message: 'Non-existent file must be rejected (fail-closed)'
		);

	}//end testNonExistentFileIsRejected()

	/**
	 * Exactly 10 MB is accepted (boundary condition).
	 *
	 * @return void
	 */
	public function testExactlyTenMbIsAccepted(): void {
		$exactBytes = 10 * 1024 * 1024;
		$path = $this->makeTmpFile(bytes: 1);
		self::assertTrue(
			condition: $this->validator->validate(filePath: $path, mimeType: 'image/jpeg', fileSize: $exactBytes),
			message: 'Exactly 10 MB must be accepted (boundary)'
		);

	}//end testExactlyTenMbIsAccepted()
}//end class
