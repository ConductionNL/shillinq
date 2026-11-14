<?php

/**
 * Golden tests for SupplierInvoiceImportController::import: upload sources, format choice, UBL and CSV paths.
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
 * @spec openspec/specs/shillinq-bill-import-modal/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Controller\SupplierInvoiceImportController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Purchasing\SupplierInvoiceChecks;
use OCA\Shillinq\Service\SupplierInvoiceService;
use OCA\Shillinq\Tests\Unit\Service\Support\ObjectEntityStub;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Serves a fixed body for `php://input` while registered.
 */
final class SupplierImportPhpInput {

	/**
	 * The body served.
	 *
	 * @var string
	 */
	public static string $body = '';

	/**
	 * Stream context, assigned by the stream layer.
	 *
	 * @var resource|null
	 */
	public $context = null;

	/**
	 * Read cursor.
	 *
	 * @var int
	 */
	private int $position = 0;

	/**
	 * Open.
	 *
	 * @param string      $path       Path.
	 * @param string      $mode       Mode.
	 * @param int         $options    Options.
	 * @param string|null $openedPath Opened path.
	 *
	 * @return bool
	 */
	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		$this->position = 0;
		return true;
	}//end stream_open()

	/**
	 * Read.
	 *
	 * @param int $count Bytes.
	 *
	 * @return string
	 */
	public function stream_read(int $count): string {
		$chunk = substr(self::$body, $this->position, $count);
		$this->position += strlen($chunk);
		return $chunk;
	}//end stream_read()

	/**
	 * End of body.
	 *
	 * @return bool
	 */
	public function stream_eof(): bool {
		return $this->position >= strlen(self::$body);
	}//end stream_eof()

	/**
	 * Stat.
	 *
	 * @return array<int|string,int>
	 */
	public function stream_stat(): array {
		return [];
	}//end stream_stat()
}//end class

/**
 * Pins what the import reads and calls, in order, and every answer.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class SupplierInvoiceImportControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public array $calls = [];

	/**
	 * Run import once.
	 *
	 * @param array<string,mixed> $setup body, upload (array), format, member, context, payees, resolved, duplicates, parse, ingest, user.
	 *
	 * @return JSONResponse
	 */
	private function import(array $setup): JSONResponse {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default=null) use ($setup): mixed {
				$this->calls[] = ['getParam', $key];
				return ($setup['format'] ?? $default);
			}
		);
		$request->method('getUploadedFile')->willReturnCallback(
			function (string $key) use ($setup): mixed {
				$this->calls[] = ['getUploadedFile', $key];
				return ($setup['upload'] ?? null);
			}
		);
		$request->method('getParams')->willReturn(($setup['params'] ?? []));
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if (($setup['user'] ?? true) === true) {
			$user = $this->createMock(IUser::class);
		}

		$session->method('getUser')->willReturn($user);
		$admin = $this->createMock(AdministrationContextService::class);
		$admin->method('buildContext')->willReturnCallback(
			function () use ($setup): array {
				$context = ($setup['context'] ?? ['activeAdministrationId' => 'adm-1']);
				if ($context instanceof \Throwable) {
					throw $context;
				}

				return $context;
			}
		);
		$admin->method('canAccess')->willReturnCallback(
			function (string $administrationId) use ($setup): bool {
				$this->calls[] = ['canAccess', $administrationId];
				return ($setup['member'] ?? true);
			}
		);
		$service = $this->createMock(SupplierInvoiceService::class);
		$service->method('parseUblInvoice')->willReturnCallback(
			function (string $ublXml) use ($setup): array {
				$this->calls[] = ['parseUblInvoice', $ublXml];
				$parse = ($setup['parse'] ?? ['invoiceNumber' => 'INV-1']);
				if ($parse instanceof \Throwable) {
					throw $parse;
				}

				return $parse;
			}
		);
		$service->method('resolveSupplier')->willReturnCallback(
			function (string $administrationId, array $parsed): array {
				$this->calls[] = ['resolveSupplier', $administrationId, $parsed];
				return $parsed + ['supplierId' => 'sup-1'];
			}
		);
		$service->method('ingestUBLInvoice')->willReturnCallback(
			function (string $administrationId, string $ublXml) use ($setup): array {
				$this->calls[] = ['ingestUBLInvoice', $administrationId, $ublXml];
				$ingest = ($setup['ingest'] ?? ['id' => 'si-1']);
				if ($ingest instanceof \Throwable) {
					throw $ingest;
				}

				return $ingest;
			}
		);
		$checks = $this->createMock(SupplierInvoiceChecks::class);
		$checks->method('payee')->willReturnCallback(
			function (string $supplierId) use ($setup): ?array {
				$this->calls[] = ['payee', $supplierId];
				return ($setup['payees'][$supplierId] ?? null);
			}
		);
		$checks->method('resolvePayee')->willReturnCallback(
			function (string $administrationId, string $kvkNumber, string $vatNumber) use ($setup): ?array {
				$this->calls[] = ['resolvePayee', $administrationId, $kvkNumber, $vatNumber];
				return ($setup['resolved'][$kvkNumber] ?? null);
			}
		);
		$checks->method('duplicateOf')->willReturnCallback(
			function (string $administrationId, string $supplierId, string $invoiceNumber) use ($setup): ?string {
				$this->calls[] = ['duplicateOf', $administrationId, $supplierId, $invoiceNumber];
				return ($setup['duplicates'][$invoiceNumber] ?? null);
			}
		);
		$checks->method('duplicateOfUnresolved')->willReturnCallback(
			function (string $administrationId, string $supplierIdentifier, string $invoiceNumber) use ($setup): ?string {
				$this->calls[] = ['duplicateOfUnresolved', $administrationId, $supplierIdentifier, $invoiceNumber];
				return ($setup['duplicates'][$invoiceNumber] ?? null);
			}
		);
		$store = $this->createMock(ObjectServiceInterface::class);
		$store->method('setRegister')->willReturnCallback(
			function (string $register) use (&$store): ObjectServiceInterface {
				$this->calls[] = ['setRegister', $register];
				return $store;
			}
		);
		$store->method('setSchema')->willReturnCallback(
			function (string $schema) use (&$store): ObjectServiceInterface {
				$this->calls[] = ['setSchema', $schema];
				return $store;
			}
		);
		$store->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntityInterface {
				$createdAt = $object['createdAt'];
				self::assertMatchesRegularExpression(pattern: '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', string: $createdAt);
				$object['createdAt'] = 'C';
				$this->calls[] = ['saveObject', $object];
				return new ObjectEntityStub(payload: $object + ['id' => 'new-' . $object['invoiceNumber']]);
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->calls[] = ['error', $message];
			}
		);

		$controller = new SupplierInvoiceImportController($request, $service, $admin, $session, $logger, $store, $checks);
		SupplierImportPhpInput::$body = ($setup['body'] ?? '');
		stream_wrapper_unregister('php');
		stream_wrapper_register('php', SupplierImportPhpInput::class);
		try {
			return $controller->import();
		} finally {
			stream_wrapper_restore('php');
		}
	}//end import()

	/**
	 * Status and body.
	 *
	 * @param JSONResponse $response The response.
	 *
	 * @return array{0:int,1:mixed}
	 */
	private static function answer(JSONResponse $response): array {
		return [$response->getStatus(), $response->getData()];
	}//end answer()

	/**
	 * The calls of the given kinds.
	 *
	 * @param array<int,string> $kinds Call names.
	 *
	 * @return array<int,array<int,mixed>>
	 */
	private function only(array $kinds): array {
		return array_values(array_filter($this->calls, fn (array $call): bool => in_array($call[0], $kinds, true)));
	}//end only()

	/**
	 * Anonymous is a 401 before anything is read; empty contents a 422; pdf deferred; unknown unsupported.
	 *
	 * @return void
	 */
	public function testSimpleAnswers(): void {
		self::assertSame(expected: [401, ['error' => 'Not logged in']], actual: self::answer($this->import(setup: ['user' => false])));
		self::assertSame(expected: [], actual: $this->calls);
		$actual = self::answer($this->import(setup: ['body' => '{"format":"ubl"}']));
		self::assertSame(expected: [422, ['error' => 'No file contents were uploaded']], actual: $actual);
		self::assertSame(expected: [['getParam', 'format'], ['getUploadedFile', 'file']], actual: $this->calls);
		$pdf = [422, ['error' => 'PDF OCR extraction is not yet available. Please upload a UBL/e-invoice XML or CSV.', 'deferred' => 'pdf-ocr']];
		self::assertSame(expected: $pdf, actual: self::answer($this->import(setup: ['body' => '{"contents":"%PDF-1.7 x"}'])));
		$unsupported = [422, ['error' => 'Unsupported import format. Use ubl, csv or pdf.']];
		self::assertSame(expected: $unsupported, actual: self::answer($this->import(setup: ['body' => '{"contents":"plain"}'])));
		self::assertSame(expected: $unsupported, actual: self::answer($this->import(setup: ['body' => '{"contents":"<x/>","format":"XLS"}'])));
	}//end testSimpleAnswers()

	/**
	 * Format precedence: explicit param, then body format, then file name, then a content sniff.
	 *
	 * @return void
	 */
	public function testFormatChoice(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'golden-upload-');
		file_put_contents($tmp, '<Invoice/>');
		$cases = [
			[['upload' => ['tmp_name' => $tmp, 'name' => 'a.XML']], 'ubl'],
			[['upload' => ['tmp_name' => $tmp, 'name' => 'a.ubl']], 'ubl'],
			[['upload' => ['tmp_name' => $tmp, 'name' => 'a.pdf']], 'pdf'],
			[['upload' => ['tmp_name' => $tmp, 'name' => 'noext']], 'ubl'],
			[['upload' => ['tmp_name' => $tmp, 'name' => 'a.xml'], 'format' => ' PDF '], 'pdf'],
			[['upload' => ['tmp_name' => ''], 'body' => '{"contents":"x,y","filename":"b.txt"}'], 'csv-empty'],
			[['body' => '{"contents":"<a/>","format":" CSV ","filename":"b.xml"}'], 'csv-empty'],
			[['body' => '{"contents":"x","filename":"b.csv"}'], 'csv-empty'],
			[['body' => '{"contents":"  %PDF"}'], 'pdf'],
		];
		foreach ($cases as [$setup, $expected]) {
			$response = $this->import(setup: $setup);
			$data     = $response->getData();
			$actual   = match (true) {
				isset($data['deferred']) => 'pdf',
				isset($data['imported']) => 'ubl',
				($data['error'] ?? '') === 'CSV did not contain any data rows' => 'csv-empty',
				default => 'other:' . json_encode($data),
			};
			self::assertSame(expected: $expected, actual: $actual, message: json_encode($setup));
		}

		unlink($tmp);
	}//end testFormatChoice()

	/**
	 * UBL: parse, resolve, duplicate check, ingest — and the exception mapping.
	 *
	 * @return void
	 */
	public function testUblPath(): void {
		$body = '{"contents":"<Invoice/>"}';
		$actual = self::answer($this->import(setup: ['body' => $body]));
		self::assertSame(expected: [200, ['imported' => 1, 'record' => ['id' => 'si-1']]], actual: $actual);
		self::assertSame(
			expected: [
				['parseUblInvoice', '<Invoice/>'],
				['resolveSupplier', 'adm-1', ['invoiceNumber' => 'INV-1']],
				['duplicateOf', 'adm-1', 'sup-1', 'INV-1'],
				['ingestUBLInvoice', 'adm-1', '<Invoice/>'],
			],
			actual: $this->only(kinds: ['parseUblInvoice', 'resolveSupplier', 'duplicateOf', 'duplicateOfUnresolved', 'ingestUBLInvoice', 'canAccess'])
		);
		$conflict = [409, ['error' => 'This invoice number already exists for this supplier']];
		self::assertSame(expected: $conflict, actual: self::answer($this->import(setup: ['body' => $body, 'duplicates' => ['INV-1' => 'old']])));
		$actual = self::answer($this->import(setup: ['body' => $body, 'ingest' => new \RuntimeException('Invoice ALREADY EXISTS')]));
		self::assertSame(expected: $conflict, actual: $actual);
		$actual = self::answer($this->import(setup: ['body' => $body, 'parse' => new \RuntimeException('Administration not found: x')]));
		self::assertSame(expected: [403, ['error' => 'Administration not found: x']], actual: $actual);
		$actual = self::answer($this->import(setup: ['body' => $body, 'parse' => new \UnexpectedValueException('bad xml')]));
		self::assertSame(expected: [422, ['error' => 'bad xml']], actual: $actual);
		$this->calls = [];
		$setup  = ['body' => $body, 'context' => ['activeAdministrationId' => ''], 'ingest' => new \LogicException('boom')];
		$actual = self::answer($this->import(setup: $setup));
		self::assertSame(expected: [500, ['error' => 'Internal error']], actual: $actual);
		self::assertSame(expected: ['resolveSupplier', 'default', ['invoiceNumber' => 'INV-1']], actual: $this->only(kinds: ['resolveSupplier'])[0]);
		self::assertSame(expected: [['error', 'SupplierInvoiceImportController.import failed: boom']], actual: $this->only(kinds: ['error']));
	}//end testUblPath()

	/**
	 * CSV: membership first, then rows skipped as incomplete or duplicate, the rest saved with their fields.
	 *
	 * @return void
	 */
	public function testCsvPath(): void {
		$setup  = ['body' => '{"contents":"a,b\n1,2","format":"csv"}', 'member' => false, 'context' => new \RuntimeException('x')];
		$actual = self::answer($this->import(setup: $setup));
		self::assertSame(expected: [403, ['error' => 'Administration not found']], actual: $actual);
		self::assertSame(expected: [['canAccess', 'default']], actual: $this->only(kinds: ['canAccess', 'payee', 'saveObject']));
		$this->calls = [];
		$csv = "supplier, invoiceNumber ,invoiceDate,amount,,vatAmount\r\n"
			. "S1,N1, 2026-01-02 ,\"12,345\",ignored,2.10\n"
			. "\n"
			. "S2,,2026-01-03,1,,0\r"
			. ",N3,2026-01-04,1,,0\n"
			. "S4,N4,2026-01-05,abc,,\n"
			. "S5,N5,2026-01-06,1,,0\n"
			. "S6,N6";
		$setup = [
			'body' => json_encode(['contents' => $csv, 'format' => 'csv']),
			'payees' => ['S1' => ['id' => 'p-1'], 'S5' => ['id' => '']],
			'resolved' => ['S4' => ['id' => 'p-4']],
			'duplicates' => ['N5' => 'old-5'],
		];
		$response = $this->import(setup: $setup);
		$record   = [
			'administrationId' => 'adm-1',
			'currency' => 'EUR',
			'sourceFormat' => 'csv',
			'statusCode' => SupplierInvoiceService::STATUS_RECEIVED,
			'createdAt' => 'C',
		];
		$saved = [
			[
				'supplierId' => 'p-1',
				'supplierIdentifier' => 'S1',
				'administrationId' => 'adm-1',
				'invoiceNumber' => 'N1',
				'invoiceDate' => '2026-01-02',
				'totalInclVat' => 1235,
				'totalVat' => 210,
			] + $record,
			[
				'supplierId' => 'p-4',
				'supplierIdentifier' => 'S4',
				'administrationId' => 'adm-1',
				'invoiceNumber' => 'N4',
				'invoiceDate' => '2026-01-05',
				'totalInclVat' => null,
				'totalVat' => null,
			] + $record,
			[
				'supplierIdentifier' => 'S6',
				'administrationId' => 'adm-1',
				'invoiceNumber' => 'N6',
				'invoiceDate' => '',
				'totalInclVat' => null,
				'totalVat' => null,
			] + $record,
		];
		self::assertSame(expected: array_map(fn (array $s): array => ['saveObject', $s], $saved), actual: $this->only(kinds: ['saveObject']));
		$data = $response->getData();
		self::assertSame(expected: 200, actual: $response->getStatus());
		self::assertSame(expected: ['imported', 'skipped', 'skippedRows', 'records'], actual: array_keys($data));
		self::assertSame(expected: 3, actual: $data['imported']);
		self::assertSame(expected: 3, actual: $data['skipped']);
		self::assertSame(
			expected: [
				['invoiceNumber' => '', 'reason' => 'incomplete'],
				['invoiceNumber' => 'N3', 'reason' => 'incomplete'],
				['invoiceNumber' => 'N5', 'reason' => 'duplicate', 'duplicateOfId' => 'old-5'],
			],
			actual: $data['skippedRows']
		);
		self::assertSame(expected: ['new-N1', 'new-N4', 'new-N6'], actual: array_column($data['records'], 'id'));
		self::assertSame(
			expected: [
				['payee', 'S1'],
				['duplicateOf', 'adm-1', 'p-1', 'N1'],
				['payee', 'S2'],
				['resolvePayee', 'adm-1', 'S2', 'S2'],
				['payee', 'S4'],
				['resolvePayee', 'adm-1', 'S4', 'S4'],
				['duplicateOf', 'adm-1', 'p-4', 'N4'],
				['payee', 'S5'],
				['duplicateOfUnresolved', 'adm-1', 'S5', 'N5'],
				['payee', 'S6'],
				['resolvePayee', 'adm-1', 'S6', 'S6'],
				['duplicateOfUnresolved', 'adm-1', 'S6', 'N6'],
			],
			actual: $this->only(kinds: ['payee', 'resolvePayee', 'duplicateOf', 'duplicateOfUnresolved'])
		);
		$actual = array_slice($this->only(kinds: ['setRegister', 'setSchema']), 0, 2);
		self::assertSame(expected: [['setRegister', 'shillinq'], ['setSchema', 'SupplierInvoice']], actual: $actual);
		$actual = self::answer($this->import(setup: ['body' => '{"contents":"  a,b  ","format":"csv"}']));
		self::assertSame(expected: [422, ['error' => 'CSV did not contain any data rows']], actual: $actual);
	}//end testCsvPath()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
