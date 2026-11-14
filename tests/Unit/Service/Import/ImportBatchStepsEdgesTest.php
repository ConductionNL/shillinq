<?php

/**
 * ImportBatchSteps at its edges: a batch without an id is refused before
 * mapping, a batch without an idempotency key is posted without looking for
 * an earlier import, and a mapping row that cannot be read is left out.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Import
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

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\Import;

use DomainException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\Import\ImportBatchSteps;
use OCA\Shillinq\Service\Import\ImportPeriod;
use OCA\Shillinq\Service\Import\ImportPipelineService;
use OCA\Shillinq\Service\Import\ImportSourceReader;
use OCA\Shillinq\Service\RegisterLocation;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The id refusal, the keyless post and the unreadable mapping row.
 *
 * @covers \OCA\Shillinq\Service\Import\ImportBatchSteps
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
final class ImportBatchStepsEdgesTest extends TestCase {

	/**
	 * The pipeline double.
	 *
	 * @var ImportPipelineService&MockObject
	 */
	private ImportPipelineService $pipeline;

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objects;

	/**
	 * Steps over a pipeline double and an object service double.
	 *
	 * @return ImportBatchSteps
	 */
	private function steps(): ImportBatchSteps {
		$this->pipeline = $this->createMock(ImportPipelineService::class);
		$this->objects  = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();

		return new ImportBatchSteps(
			pipeline: $this->pipeline,
			reader: $this->createMock(ImportSourceReader::class),
			period: $this->createMock(ImportPeriod::class),
			objectService: $this->objects,
			registerLocation: $this->createConfiguredMock(RegisterLocation::class, ['getRegisterSlug' => 'shillinq'])
		);

	}//end steps()

	/**
	 * A batch without an id is refused before any mapping is resolved.
	 *
	 * @return void
	 */
	public function testABatchWithoutAnIdIsRefusedBeforeMapping(): void {
		$steps = $this->steps();
		$this->pipeline->expects($this->never())->method('resolveBatchMappings');

		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('This import batch has no id.');

		$steps->startMapping(['administrationId' => 'adm-1', '@self' => []]);

	}//end testABatchWithoutAnIdIsRefusedBeforeMapping()

	/**
	 * The id may come from `@self`; mapping then runs under it.
	 *
	 * @return void
	 */
	public function testTheIdIsReadFromSelfWhenTheBatchCarriesNone(): void {
		$steps = $this->steps();
		$this->pipeline->expects($this->once())->method('resolveBatchMappings')
			->with($this->anything(), 'batch-9');

		$steps->startMapping(['@self' => ['id' => 'batch-9']]);

	}//end testTheIdIsReadFromSelfWhenTheBatchCarriesNone()

	/**
	 * Without an idempotency key no earlier import is looked for, and
	 * the post runs over the readable mapping rows only, sorted by code.
	 *
	 * @return void
	 */
	public function testAKeylessPostSkipsTheEarlierImportCheckAndUnreadableRows(): void {
		$steps = $this->steps();
		$this->objects->expects($this->once())->method('findAll')
			->with(['filters' => ['batchReference' => 'batch-1']])
			->willReturn([
				['id' => 'm-2', 'sourceCode' => '2000', 'targetAccount' => '2000', 'mappingSource' => 'manual', 'confirmed' => true],
				'not a row',
				['id' => 'm-1', 'sourceCode' => '1000', 'targetAccount' => null, 'mappingSource' => 'unmapped'],
			]);
		$this->pipeline->expects($this->once())->method('post')
			->with($this->callback(static fn (array $batch): bool => $batch['mappings'] === [
				['sourceCode' => '1000', 'targetAccount' => null, 'mappingSource' => 'unmapped', 'confirmed' => false],
				['sourceCode' => '2000', 'targetAccount' => '2000', 'mappingSource' => 'manual', 'confirmed' => true],
			]))
			->willReturn(['postingRefs' => ['openingJournalId' => 'je-1'], 'findings' => [], 'status' => 'posted']);

		$posted = $steps->post(['id' => 'batch-1', 'status' => 'dry_run_completed']);

		$this->assertSame('posted', $posted['status']);
		$this->assertSame(['openingJournalId' => 'je-1'], $posted['postingRefs']);
		$this->assertSame(['findings' => []], $posted['postingReport']);

	}//end testAKeylessPostSkipsTheEarlierImportCheckAndUnreadableRows()
}//end class
