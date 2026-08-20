<?php

/**
 * Unit tests for PaymentRunExportService.
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
 * @spec openspec/changes/payment-run-sepa-export/specs/payment-run-sepa-export/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\PaymentRun;

use OCA\Shillinq\PaymentRun\Generator\PaymentRunCsvGenerator;
use OCA\Shillinq\PaymentRun\Generator\SepaPain001Generator;
use OCA\Shillinq\PaymentRun\PaymentRunExportService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests REQ-SEPA-001 / REQ-SEPA-004 / REQ-SEPA-005 — validate, store, write-back.
 */
class PaymentRunExportServiceTest extends TestCase {
	/**
	 * The seeded approved PR-2026-001 fixture (SAFE placeholders).
	 *
	 * @return array<string, mixed>
	 */
	private function approvedRun(): array {
		return [
			'id' => 'pr-2026-001',
			'runNumber' => 'PR-2026-001',
			'administrationId' => 'adm-consultancy',
			'executionDate' => '2026-07-01',
			'debtorAccountIban' => 'NL00BANK9999999999',
			'status' => 'approved',
			'lifecycleState' => 'approved',
			'totalAmount' => 1497.50,
			'currency' => 'EUR',
			'paymentLines' => [
				['payeeName' => 'Eneco Energie B.V.', 'creditorIban' => 'NL00BANK0123456789', 'amount' => 892.50, 'remittanceInfo' => 'ENECO-2026-04-0001', 'apTransactionRef' => 'a'],
				['payeeName' => 'Jan de Vries (ZZP)', 'creditorIban' => 'NL00TEST0222222222', 'amount' => 605.00, 'remittanceInfo' => 'JDV-2026-06-0003', 'apTransactionRef' => 'b'],
			],
		];
	}//end approvedRun()

	/**
	 * A fake fluent ObjectService capturing the saved run.
	 *
	 * @param array<string, mixed> $captured Pass-by-reference capture slot.
	 *
	 * @return object
	 */
	private function fakeObjectService(array &$captured): object {
		return new class($captured) {
			/**
			 * @param array<string,mixed> $captured Capture slot.
			 */
			public function __construct(
				private array &$captured,
			) {
			}//end __construct()

			public function setRegister(string $r): self {
				return $this;
			}//end setRegister()

			public function setSchema(string $s): self {
				return $this;
			}//end setSchema()

			/**
			 * @param array<string,mixed> $object Saved object.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				$this->captured = $object;
				return $object;
			}//end saveObject()
		};
	}//end fakeObjectService()

	/**
	 * Build the service with mocked Files/tags and a fake ObjectService.
	 *
	 * @param array<string, mixed> $captured Capture slot for the saved run.
	 *
	 * @return PaymentRunExportService
	 */
	private function service(array &$captured): PaymentRunExportService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use (&$captured) {
				// Discovery resolves with a leading-backslash FQN; normalise.
				$id = ltrim($id, '\\');

				if ($id === ltrim(SepaPain001Generator::class, '\\')) {
					return new SepaPain001Generator();
				}

				if ($id === ltrim(PaymentRunCsvGenerator::class, '\\')) {
					return new PaymentRunCsvGenerator();
				}

				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $this->fakeObjectService($captured);
				}

				throw new \RuntimeException('unknown service ' . $id);
			}
		);

		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/admin/files/Shillinq/PaymentRuns/adm-consultancy/PR-2026-001.pain001.xml');
		$file->method('getId')->willReturn(4242);

		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFolder')->willReturnSelf();
		$folder->method('newFile')->willReturn($file);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($folder);

		$tag = $this->createMock(ISystemTag::class);
		$tag->method('getId')->willReturn('1');
		$tagManager = $this->createMock(ISystemTagManager::class);
		$tagManager->method('getTag')->willReturn($tag);
		$tagMapper = $this->createMock(ISystemTagObjectMapper::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new PaymentRunExportService(
			$container,
			$rootFolder,
			$tagManager,
			$tagMapper,
			$session,
			$this->createMock(LoggerInterface::class),
		);
	}//end service()

	/**
	 * HAPPY: an approved run exports, stores the XML, writes back
	 * exportedFileRef / exportedAt and requests approved → exported.
	 *
	 * @return void
	 */
	public function testApprovedRunExportsAndTransitions(): void {
		$captured = [];
		$service = $this->service($captured);

		$result = $service->export($this->approvedRun());

		$this->assertArrayNotHasKey('error', $result);
		$this->assertNotNull($result['exportedFileRef']);
		$this->assertNotEmpty($result['exportedAt']);
		$this->assertSame('exported', $result['lifecycleState']);

		// Both artefacts (pain.001 + CSV) were rendered.
		$formats = array_column($result['files'], 'format');
		$this->assertContains('sepa-pain001', $formats);
		$this->assertContains('csv', $formats);

		// The write-back drove the transition + set the file ref.
		$this->assertSame('exported', $captured['lifecycleState']);
		$this->assertSame($result['exportedFileRef'], $captured['exportedFileRef']);
		$this->assertNotEmpty($captured['exportedAt']);
	}//end testApprovedRunExportsAndTransitions()

	/**
	 * ERROR: a non-approved (draft) run is rejected without rendering a file.
	 *
	 * @return void
	 */
	public function testDraftRunIsRejected(): void {
		$captured = [];
		$service = $this->service($captured);

		$run = $this->approvedRun();
		$run['lifecycleState'] = 'draft';
		$run['status'] = 'draft';

		$result = $service->export($run);

		$this->assertSame('not-approved', $result['error']);
		$this->assertSame('draft', $result['state']);
		$this->assertSame([], $captured, 'No run should be persisted for a rejected export');
	}//end testDraftRunIsRejected()

	/**
	 * EDGE: an approved run with a line missing creditorIban is rejected
	 * before any file is generated.
	 *
	 * @return void
	 */
	public function testLineMissingCreditorIbanRejected(): void {
		$captured = [];
		$service = $this->service($captured);

		$run = $this->approvedRun();
		$run['paymentLines'][1]['creditorIban'] = '';

		$result = $service->export($run);

		$this->assertSame('missing-creditor-iban', $result['error']);
		$this->assertSame([2], $result['lines']);
		$this->assertSame([], $captured);
	}//end testLineMissingCreditorIbanRejected()
}//end class
