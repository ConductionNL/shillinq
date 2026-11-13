<?php

/**
 * Edge-case unit tests for PaymentRunExportService: a failing generator, the
 * shapes a saved run comes back in, and a stored file that cannot be removed.
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\PaymentRun;

use OCA\Shillinq\PaymentRun\Generator\PaymentRunCsvGenerator;
use OCA\Shillinq\PaymentRun\Generator\PaymentRunGeneratorInterface;
use OCA\Shillinq\PaymentRun\Generator\SepaPain001Generator;
use OCA\Shillinq\PaymentRun\PaymentBlockChecker;
use OCA\Shillinq\PaymentRun\PaymentRunExportService;
use OCA\Shillinq\PaymentRun\RenderedFile;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the export paths outside the happy flow.
 *
 * @covers \OCA\Shillinq\PaymentRun\PaymentRunExportService
 * @uses   \OCA\Shillinq\PaymentRun\Generator\Pain001Document
 * @uses   \OCA\Shillinq\PaymentRun\Generator\PaymentRunCsvGenerator
 * @uses   \OCA\Shillinq\PaymentRun\Generator\SepaPain001Generator
 * @uses   \OCA\Shillinq\PaymentRun\PaymentBlockChecker
 * @uses   \OCA\Shillinq\PaymentRun\PaymentRunExportRun
 * @uses   \OCA\Shillinq\PaymentRun\RenderedFile
 * @uses   \OCA\Shillinq\Reporting\SystemTagAssigner
 * @uses   \OCA\Shillinq\Reporting\TaggedFileStore
 * @uses   \OCA\Shillinq\Reporting\TaggedFileStoreLog
 * @uses   \OCA\Shillinq\Reporting\TaggedFileStoreMessages
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
class PaymentRunExportServiceEdgesTest extends TestCase {

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * What the fake ObjectService returns from saveObject, or throws.
	 *
	 * @var mixed
	 */
	private mixed $saveOutcome = null;

	/**
	 * The run the fake ObjectService was asked to save.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $savedRun = null;

	/**
	 * Whether the sepa-pain001 generator fails to render.
	 *
	 * @var boolean
	 */
	private bool $sepaFails = false;

	/**
	 * Whether deleting a stored file throws.
	 *
	 * @var boolean
	 */
	private bool $deleteFails = false;

	/**
	 * An approved run with two payable lines.
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
	 * A sepa-pain001 generator whose render always fails.
	 *
	 * @return PaymentRunGeneratorInterface
	 */
	private function failingSepaGenerator(): PaymentRunGeneratorInterface {
		return new class implements PaymentRunGeneratorInterface {
			/**
			 * The format this generator claims.
			 *
			 * @return string
			 */
			public static function format(): string {
				return 'sepa-pain001';
			}//end format()

			/**
			 * Always fails.
			 *
			 * @param array<string, mixed> $paymentRun The run.
			 *
			 * @return RenderedFile
			 */
			public function render(array $paymentRun): RenderedFile {
				throw new RuntimeException('no debtor BIC');
			}//end render()
		};
	}//end failingSepaGenerator()

	/**
	 * A fluent ObjectService fake whose saveObject returns or throws $saveOutcome.
	 *
	 * @return object
	 */
	private function objectService(): object {
		$test = $this;
		return new class($test) {
			/**
			 * @param PaymentRunExportServiceEdgesTest $test The test, holding the outcome.
			 */
			public function __construct(private PaymentRunExportServiceEdgesTest $test) {
			}//end __construct()

			public function setRegister(string $r): self {
				return $this;
			}//end setRegister()

			public function setSchema(string $s): self {
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $object The run to save.
			 *
			 * @return mixed
			 */
			public function saveObject(array $object): mixed {
				return $this->test->recordSave($object);
			}//end saveObject()
		};
	}//end objectService()

	/**
	 * Record a save and answer with the configured outcome.
	 *
	 * @param array<string, mixed> $object The run to save.
	 *
	 * @return mixed
	 */
	public function recordSave(array $object): mixed {
		$this->savedRun = $object;
		if ($this->saveOutcome instanceof \Throwable) {
			throw $this->saveOutcome;
		}

		return $this->saveOutcome;
	}//end recordSave()

	/**
	 * Build the service with mocked Files, tags, session and container.
	 *
	 * @return PaymentRunExportService
	 */
	private function service(): PaymentRunExportService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				$id = ltrim($id, '\\');
				if ($id === SepaPain001Generator::class) {
					return ($this->sepaFails === true) ? $this->failingSepaGenerator() : new SepaPain001Generator();
				}

				if ($id === PaymentRunCsvGenerator::class) {
					return new PaymentRunCsvGenerator();
				}

				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $this->objectService();
				}

				throw new RuntimeException('unknown service ' . $id);
			}
		);

		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/admin/files/Shillinq/PaymentRuns/adm-consultancy/PR-2026-001.pain001.xml');
		$file->method('getId')->willReturn(4242);
		if ($this->deleteFails === true) {
			$file->method('delete')->willThrowException(new RuntimeException('locked'));
		}

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

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$settings = $this->createMock(RegisterLocation::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$records = [
			'APTransaction' => [
				['id' => 'a', 'invoiceNumber' => 'ENECO-2026-04-0001', 'state' => 'issued'],
				['id' => 'b', 'invoiceNumber' => 'JDV-2026-06-0003', 'state' => 'issued'],
			],
		];

		$this->logger = $this->createMock(LoggerInterface::class);

		return new PaymentRunExportService(
			$container,
			$rootFolder,
			$tagManager,
			$this->createMock(ISystemTagObjectMapper::class),
			$session,
			$this->logger,
			new PaymentBlockChecker(new InMemoryObjectServiceStub($records), $settings),
		);
	}//end service()

	/**
	 * A generator that fails is logged and left out; the others still render.
	 *
	 * @return void
	 */
	public function testAFailingGeneratorIsLoggedAndSkipped(): void {
		$this->sepaFails = true;
		$service = $this->service();
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'PaymentRunExportService: generator failed',
				['format' => 'sepa-pain001', 'exception' => 'no debtor BIC']
			);

		$files = $service->renderArtefacts($this->approvedRun());

		$this->assertSame(['csv'], array_map(static fn (RenderedFile $file): string => $file->format, $files));
	}//end testAFailingGeneratorIsLoggedAndSkipped()

	/**
	 * Without a stored pain.001 the run is not written back and stays approved.
	 *
	 * @return void
	 */
	public function testWithoutTheXmlFileTheRunStaysApproved(): void {
		$this->sepaFails = true;
		$service = $this->service();

		$result = $service->export($this->approvedRun());

		$this->assertSame('storage-failed', $result['error']);
		$this->assertSame(['csv'], array_column($result['files'], 'format'));
		$this->assertNull($this->savedRun);
	}//end testWithoutTheXmlFileTheRunStaysApproved()

	/**
	 * A saved entity is reported through its JSON form, including its own state.
	 *
	 * @return void
	 */
	public function testASavedEntityIsReportedThroughItsJson(): void {
		$this->saveOutcome = new class implements \JsonSerializable {
			/**
			 * The entity as JSON.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'pr-2026-001', 'lifecycleState' => 'exported-pending'];
			}//end jsonSerialize()
		};
		$service = $this->service();

		$result = $service->export($this->approvedRun());

		$this->assertSame('exported-pending', $result['lifecycleState']);
		$this->assertSame(['id' => 'pr-2026-001', 'lifecycleState' => 'exported-pending'], $result['paymentRun']);
		$this->assertSame('exported', $this->savedRun['lifecycleState']);
		$this->assertSame('exported', $this->savedRun['status']);
		$this->assertSame($result['exportedFileRef'], $this->savedRun['exportedFileRef']);
	}//end testASavedEntityIsReportedThroughItsJson()

	/**
	 * A save that answers with neither an entity nor an array reports the run as sent.
	 *
	 * @return void
	 */
	public function testAnUnshapedSaveAnswerReportsTheRunAsSent(): void {
		$this->saveOutcome = true;
		$service = $this->service();

		$result = $service->export($this->approvedRun());

		$this->assertSame('exported', $result['lifecycleState']);
		$this->assertSame($this->savedRun, $result['paymentRun']);
		$this->assertSame('/admin/files/Shillinq/PaymentRuns/adm-consultancy/PR-2026-001.pain001.xml', $result['exportedFileRef']);
	}//end testAnUnshapedSaveAnswerReportsTheRunAsSent()

	/**
	 * A plain save failure is save-failed, and a file that cannot be removed
	 * again is only logged.
	 *
	 * @return void
	 */
	public function testAFileThatCannotBeRemovedIsOnlyLogged(): void {
		$this->saveOutcome = new RuntimeException('database gone');
		$this->deleteFails = true;
		$service = $this->service();
		$messages = [];
		$this->logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []) use (&$messages): void {
				$messages[] = [$message, $context];
			}
		);

		$result = $service->export($this->approvedRun());

		$this->assertSame(['error' => 'save-failed', 'reason' => 'database gone'], $result);
		$this->assertSame(
			[
				['PaymentRunExportService: failed to save PaymentRun', ['exception' => 'database gone']],
				['PaymentRunExportService: could not remove the file of an unexported run', ['exception' => 'locked']],
				['PaymentRunExportService: could not remove the file of an unexported run', ['exception' => 'locked']],
			],
			$messages
		);
	}//end testAFileThatCannotBeRemovedIsOnlyLogged()
}//end class
