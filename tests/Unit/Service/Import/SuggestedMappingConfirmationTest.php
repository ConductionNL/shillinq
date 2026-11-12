<?php

/**
 * Tests for SuggestedMappingConfirmation.
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
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Import;

use DomainException;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Import\ImportBatchNotFoundException;
use OCA\Shillinq\Service\Import\SuggestedMappingConfirmation;
use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Suggested mappings are confirmed on the server, for a member, while mapping.
 *
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */
final class SuggestedMappingConfirmationTest extends TestCase {

	/**
	 * Saved mapping rows.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * Only rows with a target and not yet confirmed are confirmed.
	 *
	 * @return void
	 */
	public function testConfirmsEveryUnconfirmedRowWithATarget(): void {
		$count = $this->service(batch: ['id' => 'b-1', 'administrationId' => 'adm-1', 'status' => 'mapping'])->confirm('b-1');

		self::assertSame(1, $count);
		self::assertCount(1, $this->saved);
		self::assertSame('m-1', $this->saved[0]['uuid']);
		self::assertTrue($this->saved[0]['object']['confirmed']);
		self::assertSame('1000', $this->saved[0]['object']['targetAccount']);

	}//end testConfirmsEveryUnconfirmedRowWithATarget()

	/**
	 * Another administration's batch reads as not found and nothing is written.
	 *
	 * @return void
	 */
	public function testAnotherAdministrationsBatchIsNotFound(): void {
		$this->expectException(ImportBatchNotFoundException::class);
		try {
			$this->service(batch: ['id' => 'b-1', 'administrationId' => 'adm-other', 'status' => 'mapping'])->confirm('b-1');
		} finally {
			self::assertSame([], $this->saved);
		}

	}//end testAnotherAdministrationsBatchIsNotFound()

	/**
	 * Outside the mapping state the batch is refused.
	 *
	 * @return void
	 */
	public function testOutsideMappingIsRefused(): void {
		$this->expectException(DomainException::class);

		$this->service(batch: ['id' => 'b-1', 'administrationId' => 'adm-1', 'status' => 'posted'])->confirm('b-1');

	}//end testOutsideMappingIsRefused()

	/**
	 * Build the service over a batch and three mapping rows.
	 *
	 * @param array<string,mixed> $batch The batch.
	 *
	 * @return SuggestedMappingConfirmation
	 */
	private function service(array $batch): SuggestedMappingConfirmation {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($batch);
		$entity->method('getObject')->willReturn($batch);
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturn($entity);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('findAll')->willReturn(
			[
				['id' => 'm-1', 'sourceCode' => '0100', 'targetAccount' => '1000', 'confirmed' => false],
				['id' => 'm-2', 'sourceCode' => '0200', 'targetAccount' => '2000', 'confirmed' => true],
				['id' => 'm-3', 'sourceCode' => '0300', 'targetAccount' => null, 'confirmed' => false],
			]
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend, $register, $schema, ?string $uuid): ObjectEntityInterface {
				$this->saved[] = ['object' => $object, 'uuid' => $uuid];
				return $this->createMock(ObjectEntityInterface::class);
			}
		);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturnCallback(static fn (string $id): bool => $id === 'adm-1');

		return new SuggestedMappingConfirmation(objectService: $objects, settings: $settings, administrationContext: $context);

	}//end service()
}//end class
