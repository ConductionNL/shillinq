<?php

/**
 * Characterisation tests for PaymentRunExportService storage, tagging and envelopes.
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
use OCA\Shillinq\PaymentRun\PaymentBlockChecker;
use OCA\Shillinq\PaymentRun\PaymentRunExportService;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
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
 * Pins the stored-file list, the tags per file, the saved fields, the
 * storage-failure, no-generator and no-session envelopes, and the order of
 * the refusals.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PaymentRunExportStepsTest extends TestCase {

	/**
	 * Tag assignments: file id => tag ids.
	 *
	 * @var list<string>
	 */
	private array $tagged = [];

	/**
	 * The saved run.
	 *
	 * @var array<string, mixed>
	 */
	private array $saved = [];

	/**
	 * Warning messages.
	 *
	 * @var list<string>
	 */
	private array $warnings = [];

	/**
	 * Build the service.
	 *
	 * @param bool $withGenerators Whether the generators resolve.
	 * @param bool $withUser       Whether a user session exists.
	 * @param bool $xmlStorable    Whether the XML file can be stored.
	 *
	 * @return PaymentRunExportService The service.
	 */
	private function service(bool $withGenerators = true, bool $withUser = true, bool $xmlStorable = true): PaymentRunExportService {
		$test = $this;
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($withGenerators, $test) {
				$id = ltrim($id, '\\');
				if ($withGenerators === true && $id === ltrim(SepaPain001Generator::class, '\\')) {
					return new SepaPain001Generator();
				}

				if ($withGenerators === true && $id === ltrim(PaymentRunCsvGenerator::class, '\\')) {
					return new PaymentRunCsvGenerator();
				}

				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return new class($test) {
						public function __construct(
							private readonly PaymentRunExportStepsTest $test,
						) {
						}

						public function setRegister(string $register): self {
							return $this;
						}

						public function setSchema(string $schema): self {
							return $this;
						}

						/**
						 * @param array<string, mixed> $object The run.
						 *
						 * @return array<string, mixed>
						 */
						public function saveObject(array $object): array {
							$this->test->recordSave($object);
							return $object + ['savedBy' => 'store'];
						}
					};
				}

				throw new \RuntimeException('unknown service ' . $id);
			}
		);

		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFolder')->willReturnSelf();
		$folder->method('newFile')->willReturnCallback(
			function (string $name) use ($xmlStorable) {
				if ($xmlStorable === false && str_ends_with($name, '.xml') === true) {
					throw new \RuntimeException('disk full');
				}

				$file = $this->createMock(File::class);
				$file->method('getPath')->willReturn('/u/files/Shillinq/PaymentRuns/adm-1/' . $name);
				$file->method('getId')->willReturn(strlen($name));
				return $file;
			}
		);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($folder);

		$tagManager = $this->createMock(ISystemTagManager::class);
		$tagManager->method('getTag')->willReturnCallback(
			function (string $name) {
				$tag = $this->createMock(ISystemTag::class);
				$tag->method('getId')->willReturn($name);
				return $tag;
			}
		);
		$tagMapper = $this->createMock(ISystemTagObjectMapper::class);
		$tagMapper->method('assignTags')->willReturnCallback(
			function (string $objectId, string $type, array $tagIds): void {
				$this->tagged[] = $objectId . ' ' . $type . ' ' . implode(',', $tagIds);
			}
		);

		$session = $this->createMock(IUserSession::class);
		if ($withUser === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('admin');
			$session->method('getUser')->willReturn($user);
		}

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		return new PaymentRunExportService(
			$container,
			$rootFolder,
			$tagManager,
			$tagMapper,
			$session,
			$logger,
			new PaymentBlockChecker(new InMemoryObjectServiceStub(['APTransaction' => [['id' => 'a', 'invoiceNumber' => 'INV-1', 'state' => 'issued']]]), $settings),
		);

	}//end service()

	/**
	 * Record the saved run.
	 *
	 * @param array<string, mixed> $run The saved run.
	 *
	 * @return void
	 */
	public function recordSave(array $run): void {
		$this->saved = $run;

	}//end recordSave()

	/**
	 * An approved run.
	 *
	 * @return array<string, mixed> The run.
	 */
	private static function approvedRun(): array {
		return [
			'id' => 'pr-1',
			'runNumber' => 'PR-1',
			'administrationId' => 'adm-1',
			'executionDate' => '2026-07-01',
			'debtorAccountIban' => 'NL00BANK9999999999',
			'lifecycleState' => 'approved',
			'currency' => 'EUR',
			'paymentLines' => [
				['payeeName' => 'Eneco', 'creditorIban' => 'NL00BANK0123456789', 'amount' => 10.5, 'remittanceInfo' => 'INV-1', 'apTransactionRef' => 'a'],
			],
		];

	}//end approvedRun()

	/**
	 * The success path: files, tags, saved fields and envelope.
	 *
	 * @return void
	 */
	public function testSuccessEnvelope(): void {
		$result = $this->service()->export(self::approvedRun());

		self::assertSame(['exportedFileRef', 'exportedAt', 'lifecycleState', 'files', 'paymentRun'], array_keys($result));
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $result['exportedAt']);
		self::assertSame('exported', $result['lifecycleState']);
		self::assertSame('store', $result['paymentRun']['savedBy']);
		$files = $result['files'];
		self::assertSame(['format', 'fileName', 'filePath', 'fileId'], array_keys($files[0]));
		$xml = array_values(array_filter($files, static fn (array $f): bool => $f['format'] === 'sepa-pain001'))[0];
		self::assertSame($xml['filePath'], $result['exportedFileRef']);
		self::assertSame('/u/files/Shillinq/PaymentRuns/adm-1/' . $xml['fileName'], $xml['filePath']);
		self::assertCount(count($files) * 3, $this->tagged);
		self::assertSame($xml['fileId'] . ' files shillinq-payment-run:PR-1', $this->tagged[array_search($xml, $files, true) * 3]);
		self::assertSame(
			[$result['exportedFileRef'], $result['exportedAt'], 'exported', 'exported'],
			[$this->saved['exportedFileRef'], $this->saved['exportedAt'], $this->saved['lifecycleState'], $this->saved['status']]
		);
		self::assertSame(array_keys(self::approvedRun() + ['exportedFileRef' => 1, 'exportedAt' => 1, 'status' => 1]), array_keys($this->saved));

	}//end testSuccessEnvelope()

	/**
	 * Tags for every file: run, administration, format.
	 *
	 * @return void
	 */
	public function testTagsPerFile(): void {
		$result = $this->service()->export(self::approvedRun());
		$expected = [];
		foreach ($result['files'] as $file) {
			$expected[] = $file['fileId'] . ' files shillinq-payment-run:PR-1';
			$expected[] = $file['fileId'] . ' files shillinq-administration:adm-1';
			$expected[] = $file['fileId'] . ' files shillinq-file-type:' . $file['format'];
		}

		self::assertSame($expected, $this->tagged);

	}//end testTagsPerFile()

	/**
	 * Failure envelopes: storage failed, no session, no generator.
	 *
	 * @return void
	 */
	public function testFailureEnvelopes(): void {
		$result = $this->service(xmlStorable: false)->export(self::approvedRun());
		self::assertSame('storage-failed', $result['error']);
		self::assertSame(['error', 'files'], array_keys($result));
		$xml = array_values(array_filter($result['files'], static fn (array $f): bool => $f['format'] === 'sepa-pain001'))[0];
		self::assertSame([null, null], [$xml['filePath'], $xml['fileId']]);
		self::assertSame([], $this->saved);
		self::assertContains('PaymentRunExportService: XML file storage failed; run stays approved', $this->warnings);

		$this->tagged = [];
		$result = $this->service(withUser: false)->export(self::approvedRun());
		self::assertSame('storage-failed', $result['error']);
		self::assertSame([], $this->tagged);
		self::assertContains('PaymentRunExportService: no user session, cannot store export file', $this->warnings);

		self::assertSame(['error' => 'no-generator'], $this->service(withGenerators: false)->export(self::approvedRun()));

	}//end testFailureEnvelopes()

	/**
	 * Refusals come before rendering, in order: state, IBAN, block.
	 *
	 * @return void
	 */
	public function testRefusalOrder(): void {
		$run = self::approvedRun();
		$run['lifecycleState'] = null;
		$run['status'] = 'draft';
		$run['paymentLines'][0]['creditorIban'] = '';
		self::assertSame(['error' => 'not-approved', 'state' => 'draft'], $this->service(withGenerators: false)->export($run));

		$run['status'] = 'approved';
		$result = $this->service(withGenerators: false)->export($run);
		self::assertSame('missing-creditor-iban', $result['error']);

	}//end testRefusalOrder()
}//end class
