<?php

/**
 * Unit tests for PublicSectorRecords::find on a record whose payload has no id:
 * the id asked for is put on it.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\PublicSector
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

namespace OCA\Shillinq\Tests\Unit\Service\PublicSector;


use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\PublicSector\PublicSectorRecords;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use OCA\Shillinq\Tests\Support\LegacyObjectArgs;

/**
 * Tests for PublicSectorRecords::find without a payload id.
 */
#[CoversClass(PublicSectorRecords::class)]
#[UsesClass(ObjectIdentifier::class)]
final class PublicSectorRecordsFindTest extends TestCase {

	/**
	 * A found object whose payload carries no id gets the id it was found by.
	 *
	 * @return void
	 */
	public function testARecordWithoutIdGetsTheIdItWasFoundBy(): void {
		$entity = $this->createStub(ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn(['name' => 'Algemene reserve', 'id' => '']);

		$os = $this->createMock(ObjectServiceInterface::class);
		$os->expects($this->once())->method('setRegister')->with('shillinq')->willReturnSelf();
		$os->expects($this->once())->method('setSchema')->with('Reserve')->willReturnSelf();
		$os->expects($this->once())->method('find')->with(...LegacyObjectArgs::with(method: 'find', expected: [
				'id' => 'r-1',
			]))->willReturn($entity);

		$location = $this->createStub(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');

		$records = new PublicSectorRecords($os, $this->createStub(ObjectTransitionRunner::class), $location);

		self::assertSame(['name' => 'Algemene reserve', 'id' => 'r-1'], $records->find('Reserve', 'r-1'));

	}//end testARecordWithoutIdGetsTheIdItWasFoundBy()
}//end class
