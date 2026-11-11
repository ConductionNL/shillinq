<?php

/**
 * Golden tests for AccountantPortalController::handoverPack: refusals, the membership check, the pack and log lines.
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
 * @spec openspec/specs/accountant-portal/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\AccountantPortalController;
use OCA\Shillinq\Reporting\ReportGenerationService;
use OCA\Shillinq\Service\AccountantDashboardService;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every answer of the handover pack, allowed and refused, and every call it makes.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class AccountantPortalControllerGoldenTest extends TestCase {

	/**
	 * Recorded calls in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Call handoverPack once.
	 *
	 * @param string                                 $id      The path id.
	 * @param array<string,mixed>                    $setup   user, member (bool|Throwable), period, records (per report type), files, generateFails.
	 *
	 * @return Response
	 */
	private function pack(string $id, array $setup=[]): Response {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default=null) use ($setup): mixed {
				$this->calls[] = ['getParam', $key, $default];
				return ($setup['period'] ?? $default);
			}
		);
		$context = $this->createMock(AdministrationContextService::class);
		$user = 'acc-1';
		if (array_key_exists('user', $setup) === true) {
			$user = $setup['user'];
		}

		$context->method('currentUserId')->willReturn($user);
		$context->method('canAccess')->willReturnCallback(
			function (string $administrationId) use ($setup): bool {
				$this->calls[] = ['canAccess', $administrationId];
				$member = ($setup['member'] ?? true);
				if ($member instanceof \Throwable) {
					throw $member;
				}

				return $member;
			}
		);
		$reports = $this->createMock(ReportGenerationService::class);
		$reports->method('generate')->willReturnCallback(
			function (string $reportType, string $period, string $administrationId, string $format) use ($setup): array {
				$this->calls[] = ['generate', $reportType, $period, $administrationId, $format];
				if (isset($setup['generateFails']) === true) {
					throw $setup['generateFails'];
				}

				return ($setup['records'][$reportType] ?? ['id' => 'rec-' . $reportType, 'fileName' => 'f.' . $format]);
			}
		);
		$reports->method('resolveFile')->willReturnCallback(
			function (string $recordId) use ($setup): ?File {
				$this->calls[] = ['resolveFile', $recordId];
				$content = ('bytes:' . $recordId);
				if (array_key_exists($recordId, ($setup['files'] ?? [])) === true) {
					$content = $setup['files'][$recordId];
				}

				if ($content === null) {
					return null;
				}

				$file = $this->createMock(File::class);
				if ($content instanceof \Throwable) {
					$file->method('getContent')->willThrowException($content);
				} else {
					$file->method('getContent')->willReturn($content);
				}

				return $file;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context=[]) use ($level): void {
					$this->calls[] = [$level, $message, $context];
				}
			);
		}

		$controller = new AccountantPortalController($request, $context, $this->createMock(AccountantDashboardService::class), $reports, $logger);

		return $controller->handoverPack($id);
	}//end pack()

	/**
	 * Status and JSON body.
	 *
	 * @param Response $response The response.
	 *
	 * @return array{0:int,1:mixed}
	 */
	private static function answer(Response $response): array {
		self::assertInstanceOf(expected: JSONResponse::class, actual: $response);
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
	 * 401, then 400 for a bad id, both before the membership check.
	 *
	 * @return void
	 */
	public function testAnonymousAndMalformedRefusedFirst(): void {
		self::assertSame(expected: [401, ['error' => 'Not authenticated']], actual: self::answer($this->pack(id: 'adm', setup: ['user' => null])));
		foreach (['', '  ', 'a/b', str_repeat('a', 65)] as $id) {
			self::assertSame(expected: [400, ['error' => 'Invalid administration id']], actual: self::answer($this->pack(id: $id)));
		}

		self::assertSame(expected: [], actual: $this->calls);
	}//end testAnonymousAndMalformedRefusedFirst()

	/**
	 * A non-member gets the masked 404 and nothing is generated; a failing check is a 500.
	 *
	 * @return void
	 */
	public function testMembershipRefusals(): void {
		$response = $this->pack(id: ' adm-2 ', setup: ['member' => false]);
		self::assertSame(expected: [404, ['error' => 'Administration not found']], actual: self::answer($response));
		self::assertSame(expected: [['canAccess', 'adm-2']], actual: $this->calls);
		$this->calls = [];
		$response = $this->pack(id: 'adm', setup: ['member' => new \RuntimeException('db')]);
		self::assertSame(expected: [500, ['error' => 'Failed to resolve handover pack']], actual: self::answer($response));
		self::assertSame(
			expected: [['canAccess', 'adm'], ['error', 'AccountantPortalController: failed to check handover-pack access', ['exception' => 'db']]],
			actual: $this->calls
		);
	}//end testMembershipRefusals()

	/**
	 * A member gets a ZIP with every report, generated in order for the trimmed period.
	 *
	 * @return void
	 */
	public function testAllowedPackContents(): void {
		$response = $this->pack(id: str_repeat('a', 64), setup: ['period' => ' 2025-Q1 ', 'records' => ['vat-return' => ['id' => 'rec-vat']]]);
		self::assertInstanceOf(expected: DataDownloadResponse::class, actual: $response);
		$admin = str_repeat('a', 64);
		self::assertSame(
			expected: [
				['canAccess', $admin],
				['getParam', 'period', gmdate('Y')],
				['generate', 'xaf', '2025-Q1', $admin, 'xml'],
				['resolveFile', 'rec-xaf'],
				['generate', 'trial-balance', '2025-Q1', $admin, 'csv'],
				['resolveFile', 'rec-trial-balance'],
				['generate', 'general-ledger', '2025-Q1', $admin, 'csv'],
				['resolveFile', 'rec-general-ledger'],
				['generate', 'vat-return', '2025-Q1', $admin, 'xml'],
				['resolveFile', 'rec-vat'],
			],
			actual: $this->calls
		);
		self::assertSame(expected: 'attachment; filename=handover-pack-' . $admin . '-2025-Q1.zip', actual: $response->getHeaders()['Content-Disposition']);
		self::assertSame(expected: 'application/zip', actual: $response->getHeaders()['Content-Type']);
		self::assertSame(
			expected: [
				'xaf/f.xml' => 'bytes:rec-xaf',
				'trial-balance/f.csv' => 'bytes:rec-trial-balance',
				'general-ledger/f.csv' => 'bytes:rec-general-ledger',
				'vat-return/vat-return.xml' => 'bytes:rec-vat',
			],
			actual: self::zipEntries(bytes: $response->render())
		);
	}//end testAllowedPackContents()

	/**
	 * Each per-report failure is a warning and skipped; with none left the answer is a 500.
	 *
	 * @return void
	 */
	public function testEveryReportFailing(): void {
		$setup = [
			'records' => ['xaf' => ['error' => 'no-data'], 'trial-balance' => ['fileName' => 'x'], 'general-ledger' => ['id' => 'gl']],
			'files' => ['gl' => null, 'rec-vat-return' => new \RuntimeException('unreadable')],
		];
		// An empty archive is never written, so reading it back raises a PHP warning; record it rather than fail on it.
		$phpWarnings = [];
		set_error_handler(
			function (int $errno, string $message) use (&$phpWarnings): bool {
				$phpWarnings[] = $message;
				return true;
			},
			E_WARNING
		);
		try {
			$response = $this->pack(id: 'adm', setup: $setup);
		} finally {
			restore_error_handler();
		}

		self::assertSame(expected: [500, ['error' => 'No reports could be generated for this administration']], actual: self::answer($response));
		self::assertCount(expectedCount: 1, haystack: $phpWarnings);
		self::assertStringStartsWith(prefix: 'file_get_contents(', string: $phpWarnings[0]);
		self::assertSame(
			expected: [
				[
					'warning',
					'AccountantPortalController: handover-pack report failed',
					['reportType' => 'xaf', 'administrationId' => 'adm', 'error' => 'no-data'],
				],
				[
					'warning',
					'AccountantPortalController: handover-pack report file not resolvable',
					['reportType' => 'trial-balance', 'administrationId' => 'adm'],
				],
				[
					'warning',
					'AccountantPortalController: handover-pack report file not resolvable',
					['reportType' => 'general-ledger', 'administrationId' => 'adm'],
				],
				[
					'warning',
					'AccountantPortalController: handover-pack report content unreadable',
					['reportType' => 'vat-return', 'exception' => 'unreadable'],
				],
			],
			actual: $this->only(kinds: ['warning', 'error'])
		);
		self::assertSame(expected: [['resolveFile', 'gl'], ['resolveFile', 'rec-vat-return']], actual: $this->only(kinds: ['resolveFile']));
	}//end testEveryReportFailing()

	/**
	 * A generator that throws aborts the pack with the error line.
	 *
	 * @return void
	 */
	public function testGeneratorThrowing(): void {
		$response = $this->pack(id: 'adm', setup: ['generateFails' => new \LogicException('boom')]);
		self::assertSame(expected: [500, ['error' => 'Handover pack generation failed']], actual: self::answer($response));
		self::assertSame(
			expected: [[
				'error',
				'AccountantPortalController: handover pack generation failed',
				['administrationId' => 'adm', 'exception' => 'boom'],
			]],
			actual: $this->only(kinds: ['warning', 'error'])
		);
	}//end testGeneratorThrowing()

	/**
	 * The entries of a ZIP archive.
	 *
	 * @param string $bytes The archive.
	 *
	 * @return array<string,string>
	 */
	private static function zipEntries(string $bytes): array {
		$path = tempnam(sys_get_temp_dir(), 'golden-zip-');
		file_put_contents($path, $bytes);
		$zip = new \ZipArchive();
		$zip->open($path);
		$entries = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			$entries[$name] = $zip->getFromName($name);
		}

		$zip->close();
		unlink($path);

		return $entries;
	}//end zipEntries()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
