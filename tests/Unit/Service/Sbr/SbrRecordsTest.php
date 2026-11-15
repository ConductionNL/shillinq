<?php

/**
 * Unit tests for SbrRecords: the register the filing records live in.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Sbr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Sbr;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Shillinq\Kernel\Contracts\RegisterLocationInterface;
use OCA\Shillinq\Service\Sbr\SbrRecords;
use OCA\Shillinq\Util\ObjectIdentifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(SbrRecords::class)]
#[UsesClass(ObjectIdentifier::class)]
final class SbrRecordsTest extends TestCase {

	private function location(): RegisterLocationInterface {
		$location = $this->createMock(RegisterLocationInterface::class);
		$location->method('getRegisterSlug')->willReturn('books');
		return $location;
	}

	private function objects(string $schema): ObjectServiceInterface {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->with('books')->willReturnSelf();
		$objects->method('setSchema')->with($schema)->willReturnSelf();
		return $objects;
	}

	public function testFindAllPassesTheFiltersAndKeepsTheRecords(): void {
		$objects = $this->objects('VATDeclaration');
		$objects->expects($this->once())->method('findAll')->with(['filters' => ['returnId' => 'ret-1']])
			->willReturn([['id' => 'd-1', 'returnBox' => '1a'], 'not a record']);

		$this->assertSame([['id' => 'd-1', 'returnBox' => '1a']], (new SbrRecords($objects, $this->location()))->findAll('VATDeclaration', ['returnId' => 'ret-1']));
	}

	public function testSaveReturnsTheRecordWithItsUuid(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('7d6c1a2e-0000-4000-8000-000000000001');
		$objects = $this->objects('XbrlInstance');
		$objects->expects($this->once())->method('saveObject')->with(['state' => 'draft'])->willReturn($entity);

		$this->assertSame(
			['state' => 'draft', 'id' => '7d6c1a2e-0000-4000-8000-000000000001'],
			(new SbrRecords($objects, $this->location()))->save('XbrlInstance', ['state' => 'draft'])
		);
	}

	public function testASaveWithoutAUuidIsAnError(): void {
		$objects = $this->objects('XbrlInstance');
		$objects->method('saveObject')->willReturn(new ObjectEntity());

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The XbrlInstance record could not be saved.');

		(new SbrRecords($objects, $this->location()))->save('XbrlInstance', []);
	}

	public function testAnEmptyIdFindsNothingWithoutAQuery(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->never())->method('setRegister');

		$this->assertNull((new SbrRecords($objects, $this->location()))->find('XbrlInstance', ' '));
	}
	public function testFindReadsOneRecordByItsUuid(): void {
		$uuid = '7d6c1a2e-0000-4000-8000-000000000002';
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject(['state' => 'validated']);
		$objects = $this->objects('XbrlInstance');
		$objects->method('find')->willReturn($entity);

		$record = (new SbrRecords($objects, $this->location()))->find('XbrlInstance', $uuid);

		$this->assertSame('validated', $record['state'] ?? null);
	}
}
