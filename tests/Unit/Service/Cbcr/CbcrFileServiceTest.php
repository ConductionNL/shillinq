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

use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Kernel\Contracts\RegisterLocationInterface;
use OCA\Shillinq\Kernel\Contracts\ReportGenerationServiceInterface;
use OCA\Shillinq\Service\Cbcr\CbcrFileService;
use OCA\Shillinq\Service\Sbr\SbrRecords;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Generate XML stores the file through the report pipeline and links it on the return,
 * for members of the group's administration only.
 */
#[CoversClass(CbcrFileService::class)]
#[UsesClass(ObjectIdentifier::class)]
#[UsesClass(SbrRecords::class)]
final class CbcrFileServiceTest extends TestCase {

	private const ENTITIES = [
		['id' => 'ge-holding', 'administrationId' => 'adm-holding'],
		['id' => 'ge-productie', 'administrationId' => 'adm-productie', 'ultimateParentEntity' => 'ge-holding'],
		['id' => 'ge-other', 'administrationId' => 'adm-other'],
	];

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
	 * @param array<string,mixed>            $answer  What generateForRecord() answers.
	 * @param array<int,array<string,mixed>> $returns The stored returns.
	 *
	 * @return CbcrFileService
	 */
	private function service(array $answer, array $returns): CbcrFileService {
		$this->store = new InMemoryObjectServiceStub(['CbcrReturn' => $returns, 'GroupEntityRegistry' => self::ENTITIES]);
		$reports     = $this->createMock(ReportGenerationServiceInterface::class);
		$reports->method('generateForRecord')->willReturnCallback(
			function (mixed ...$arguments) use ($answer): array {
				$this->calls[] = $arguments;
				return $answer;
			}
		);
		$location = $this->createMock(RegisterLocationInterface::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');
		$context = $this->createMock(AdministrationContextServiceInterface::class);
		$context->method('canAccess')->willReturnCallback(static fn (string $administrationId): bool => $administrationId === 'adm-holding');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new CbcrFileService($reports, new SbrRecords($this->store, $location), $context, $l10n);
	}//end service()

	/**
	 * A stored file is linked on the return, filed under the ultimate parent's administration.
	 *
	 * @return void
	 */
	public function testAStoredFileIsLinkedOnTheReturn(): void {
		// The generator wrote the validation before the file was stored.
		$return = [
			'id' => 'cbc-2026', 'period' => 'FY2026', 'reportingEntity' => 'ge-productie', 'state' => 'reconciled',
			'lastValidation' => ['valid' => true, 'fingerprint' => 'fp'],
		];

		$result = $this->service(['id' => 'report-7', 'reportType' => 'cbc-report'], [$return])->generateCbc('cbc-2026');

		self::assertSame(['cbc-report', 'FY2026', 'adm-holding', ['returnId' => 'cbc-2026']], $this->calls[0]);
		self::assertSame('report-7', $result['report']['id']);
		self::assertSame('report-7', $result['return']['cbcrXmlSubmission']);
		self::assertSame(['valid' => true, 'fingerprint' => 'fp'], $result['return']['lastValidation'], 'the validation the generator wrote is kept');
		self::assertSame('report-7', $this->store->saved[0]['object']['cbcrXmlSubmission']);
	}//end testAStoredFileIsLinkedOnTheReturn()

	/**
	 * Another group's report, an unknown one, one without an entity or a malformed id is not there for the caller.
	 *
	 * @return void
	 */
	public function testOtherReportsAreNotThere(): void {
		$returns = [
			['id' => 'cbc-other', 'reportingEntity' => 'ge-other', 'period' => 'FY2026'],
			['id' => 'cbc-orphan', 'period' => 'FY2026'],
		];
		$service = $this->service(['id' => 'report-7'], $returns);
		foreach (['cbc-other', 'cbc-orphan', 'cbc-missing', '../etc', ''] as $returnId) {
			self::assertNull($service->generateCbc($returnId), $returnId);
		}

		self::assertSame([], $this->calls);
		self::assertSame([], $this->store->saved);
	}//end testOtherReportsAreNotThere()

	/**
	 * A refused file clears an older link and answers the refusal.
	 *
	 * @return void
	 */
	public function testARefusalClearsTheOldLink(): void {
		$return = ['id' => 'cbc-2026', 'period' => 'FY2026', 'reportingEntity' => 'ge-holding', 'cbcrXmlSubmission' => 'report-old'];
		$answer = ['error' => 'generation-failed', 'message' => 'The country-by-country file is not made while a blocking check fails. cbc-entity-tin'];

		try {
			$this->service($answer, [$return])->generateCbc('cbc-2026');
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
		$return  = ['id' => 'cbc-2026', 'period' => 'FY2026', 'reportingEntity' => 'ge-holding'];
		$service = $this->service(['reportType' => 'cbc-report'], [$return]);

		try {
			$service->generateCbc('cbc-2026');
			self::fail('A file without an id was linked.');
		} catch (RuntimeException $refused) {
			self::assertSame('The country-by-country file could not be stored.', $refused->getMessage());
		}

		self::assertSame([], $this->store->saved);
	}//end testARefusalWithoutALinkWritesNothing()

	/**
	 * A filed report keeps its file; a correction carries changed figures.
	 *
	 * @return void
	 */
	public function testAFiledReportKeepsItsFile(): void {
		foreach (['submitted', 'locked'] as $state) {
			$return = ['id' => 'cbc-2026', 'period' => 'FY2026', 'reportingEntity' => 'ge-holding', 'state' => $state];
			try {
				$this->service(['id' => 'report-8'], [$return])->generateCbc('cbc-2026');
				self::fail('A ' . $state . ' report got a new file.');
			} catch (RuntimeException $refused) {
				self::assertStringContainsString('Create a correction', $refused->getMessage());
			}
		}

		self::assertSame([], $this->calls);
	}//end testAFiledReportKeepsItsFile()
}//end class
