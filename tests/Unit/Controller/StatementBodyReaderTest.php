<?php

/**
 * Unit tests for StatementBodyReader.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
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

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\StatementBodyReader;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests how a bank statement's contents are taken from a request: only a
 * real upload is read, a CLI request has no JSON body, and a JSON body's
 * contents are base64-decoded only when it says so and decodes cleanly.
 *
 * @covers \OCA\Shillinq\Controller\StatementBodyReader
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class StatementBodyReaderTest extends TestCase {

	/**
	 * A reader over a request whose `file` upload is the given value.
	 *
	 * @param mixed $uploaded What getUploadedFile('file') answers.
	 *
	 * @return StatementBodyReader The reader.
	 */
	private function reader(mixed $uploaded = null): StatementBodyReader {
		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->with('file')->willReturn($uploaded);

		return new StatementBodyReader($request);
	}//end reader()

	/**
	 * No upload, a malformed one, and a path that was not uploaded are all "no upload".
	 *
	 * @return void
	 */
	public function testOnlyARealUploadIsRead(): void {
		$local = tempnam(sys_get_temp_dir(), 'stmt');
		file_put_contents($local, 'not uploaded');

		try {
			$this->assertNull($this->reader(null)->uploadedContents());
			$this->assertNull($this->reader(['name' => 'x.940'])->uploadedContents());
			$this->assertNull($this->reader(['tmp_name' => $local])->uploadedContents(), 'a local file is not an upload');
		} finally {
			unlink($local);
		}
	}//end testOnlyARealUploadIsRead()

	/**
	 * A request with no body has no JSON body.
	 *
	 * @return void
	 */
	public function testAnEmptyBodyIsNoJson(): void {
		$this->assertNull($this->reader()->jsonBody());
	}//end testAnEmptyBodyIsNoJson()

	/**
	 * Plain contents are returned as they are.
	 *
	 * @return void
	 */
	public function testPlainContentsAreReturnedAsIs(): void {
		$reader = $this->reader();

		$this->assertSame(':20:STATEMENT', $reader->bodyContents(['contents' => ':20:STATEMENT']));
		$this->assertSame('', $reader->bodyContents([]));
		$this->assertSame('', $reader->bodyContents(['contents' => '', 'encoding' => 'base64']));
		$this->assertSame('OjIwOg==', $reader->bodyContents(['contents' => 'OjIwOg==', 'encoding' => 'utf-8']));
	}//end testPlainContentsAreReturnedAsIs()

	/**
	 * Base64 contents are decoded; contents that are not valid base64 are kept.
	 *
	 * @return void
	 */
	public function testBase64ContentsAreDecodedWhenValid(): void {
		$reader = $this->reader();

		$this->assertSame(':20:STATEMENT', $reader->bodyContents(['contents' => base64_encode(':20:STATEMENT'), 'encoding' => 'base64']));
		$this->assertSame('not*base64!', $reader->bodyContents(['contents' => 'not*base64!', 'encoding' => 'base64']));
	}//end testBase64ContentsAreDecodedWhenValid()
}//end class
