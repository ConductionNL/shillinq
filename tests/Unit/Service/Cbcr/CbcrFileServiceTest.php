<?php

/**
 * Unit tests for CbcrFileService.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Service\Cbcr
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
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\Cbcr;

use OCA\Shillinq\Kernel\Contracts\ReportGenerationServiceInterface;
use OCA\Shillinq\Service\Cbcr\CbcrFileService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Generate XML stores the file through the report pipeline and links it on the return.
 */
#[CoversClass(CbcrFileService::class)]
#[UsesClass(ObjectIdentifier::class)]
final class CbcrFileServiceTest extends TestCase {

	/**
	 * The store.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * The arguments the report pipeline was called with.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * A service whose report pipeline answers the given envelope.
	 *
	 * @param array<string,mixed>            $answer  What generate() answers.
	 * @param array<int,array<string,mixed>> $returns The stored returns.
	 *
	 * @return CbcrFileService
	 */
	private function service(array $answer, array $returns): CbcrFileService {
		$this->store = new InMemoryObjectServiceStub(['CbcrReturn' => $returns]);
		$reports     = $this->createMock(ReportGenerationServiceInterface::class);
		$reports->method('generate')->willReturnCallback(
			function (mixed ...$arguments) use ($answer): array {
				$this->calls[] = $arguments;
				return $answer;
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new CbcrFileService($reports, $this->store, $appConfig, $l10n);
	}//end service()

	/**
	 * A stored file is linked on the return as its CbC submission.
	 *
	 * @return void
	 */
	public function testAStoredFileIsLinkedOnTheReturn(): void {
		$return = ['id' => 'cbc-2026', 'period' => 'FY2026', 'reportingEntity' => 'ge-holding', 'state' => 'reconciled'];
		// The generator wrote the validation before the file was stored.
		$validated = $return + ['lastValidation' => ['valid' => true, 'fingerprint' => 'fp']];

		$result = $this->service(['id' => 'report-7', 'reportType' => 'cbc-report'], [$validated])->generateCbc($return, 'adm-holding');

		self::assertSame(['cbc-report', 'FY2026', 'adm-holding', 'xml', null, ['returnId' => 'cbc-2026']], $this->calls[0]);
		self::assertSame('report-7', $result['report']['id']);
		self::assertSame('report-7', $result['return']['cbcrXmlSubmission']);
		self::assertSame(['valid' => true, 'fingerprint' => 'fp'], $result['return']['lastValidation'], 'the validation the generator wrote is kept');
		self::assertSame('report-7', $this->store->saved[0]['object']['cbcrXmlSubmission']);
	}//end testAStoredFileIsLinkedOnTheReturn()

	/**
	 * A refused file clears an older link and answers the refusal.
	 *
	 * @return void
	 */
	public function testARefusalClearsTheOldLink(): void {
		$return = ['id' => 'cbc-2026', 'period' => 'FY2026', 'cbcrXmlSubmission' => 'report-old'];
		$answer = ['error' => 'generation-failed', 'message' => 'The country-by-country file is not made while a blocking check fails. cbc-entity-tin'];

		try {
			$this->service($answer, [$return])->generateCbc($return, 'adm-holding');
			self::fail('A refused file was linked.');
		} catch (RuntimeException $refused) {
			self::assertSame($answer['message'], $refused->getMessage());
		}

		self::assertSame('', $this->store->saved[0]['object']['cbcrXmlSubmission']);
	}//end testARefusalClearsTheOldLink()

	/**
	 * A refusal on a return without a link writes nothing.
	 *
	 * @return void
	 */
	public function testARefusalWithoutALinkWritesNothing(): void {
		$return = ['id' => 'cbc-2026', 'period' => 'FY2026'];

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The country-by-country file could not be stored.');
		try {
			$this->service(['reportType' => 'cbc-report'], [$return])->generateCbc($return, 'adm-holding');
		} finally {
			self::assertSame([], $this->store->saved);
		}
	}//end testARefusalWithoutALinkWritesNothing()

	/**
	 * A filed report keeps its file; a correction carries changed figures.
	 *
	 * @return void
	 */
	public function testAFiledReportKeepsItsFile(): void {
		foreach (['submitted', 'locked'] as $state) {
			$return = ['id' => 'cbc-2026', 'period' => 'FY2026', 'state' => $state];
			try {
				$this->service(['id' => 'report-8'], [$return])->generateCbc($return, 'adm-holding');
				self::fail('A ' . $state . ' report got a new file.');
			} catch (RuntimeException $refused) {
				self::assertStringContainsString('Create a correction', $refused->getMessage());
			}
		}

		self::assertSame([], $this->calls);
	}//end testAFiledReportKeepsItsFile()
}//end class
