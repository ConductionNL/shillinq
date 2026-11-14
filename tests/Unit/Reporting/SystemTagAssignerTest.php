<?php

/**
 * Unit tests for SystemTagAssigner: resolve-or-create a collaborative tag and attach it to a file.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Reporting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters, PEAR.Commenting.FunctionComment
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting;

use OCA\Shillinq\Reporting\SystemTagAssigner;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Shillinq\Reporting\SystemTagAssigner
 */
final class SystemTagAssignerTest extends TestCase {

	private function tag(string $id): ISystemTag {
		$tag = $this->createMock(ISystemTag::class);
		$tag->method('getId')->willReturn($id);
		return $tag;
	}

	public function testAnExistingVisibleAssignableTagIsAttachedToTheFile(): void {
		$manager = $this->createMock(ISystemTagManager::class);
		$manager->expects(self::once())->method('getTag')->with('Finance', true, true)->willReturn($this->tag('4'));
		$manager->expects(self::never())->method('createTag');

		$mapper = $this->createMock(ISystemTagObjectMapper::class);
		$mapper->expects(self::once())->method('assignTags')->with('123', 'files', ['4']);

		(new SystemTagAssigner($manager, $mapper))->assign(123, 'Finance');
	}

	public function testAMissingTagIsCreatedVisibleAndAssignableThenAttached(): void {
		$manager = $this->createMock(ISystemTagManager::class);
		$manager->method('getTag')->willThrowException(new TagNotFoundException('none'));
		$manager->expects(self::once())->method('createTag')->with('Q4', true, true)->willReturn($this->tag('12'));

		$mapper = $this->createMock(ISystemTagObjectMapper::class);
		$mapper->expects(self::once())->method('assignTags')->with('9', 'files', ['12']);

		(new SystemTagAssigner($manager, $mapper))->assign(9, 'Q4');
	}

	public function testAnyOtherLookupFailurePropagatesWithoutCreatingOrAssigning(): void {
		$manager = $this->createMock(ISystemTagManager::class);
		$manager->method('getTag')->willThrowException(new RuntimeException('down'));
		$manager->expects(self::never())->method('createTag');

		$mapper = $this->createMock(ISystemTagObjectMapper::class);
		$mapper->expects(self::never())->method('assignTags');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('down');

		(new SystemTagAssigner($manager, $mapper))->assign(1, 'X');
	}
}
