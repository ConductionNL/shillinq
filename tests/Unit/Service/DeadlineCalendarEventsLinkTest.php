<?php

/**
 * Unit tests for $this->events()->linkFieldsOf().
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-25-integration-leaves-consume/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\DeadlineCalendarEvents;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The link fields of an OpenRegister row.
 */
final class DeadlineCalendarEventsLinkTest extends TestCase {

	/**
	 * A row with its uuid, register and schema in @self links to them.
	 *
	 * @return void
	 */
	public function testARowWithItsUuidAndSchemaLinks(): void {
		$link = $this->events()->linkFieldsOf(row: ['@self' => ['id' => 'u-1', 'slug' => 's', 'register' => 7, 'schema' => 42]]);

		self::assertSame(expected: ['objectUuid' => 'u-1', 'registerId' => '7', 'schemaId' => '42'], actual: $link);

	}//end testARowWithItsUuidAndSchemaLinks()

	/**
	 * Without a register the link still names the object and schema.
	 *
	 * @return void
	 */
	public function testTheRegisterMayBeMissing(): void {
		$link = $this->events()->linkFieldsOf(row: ['@self' => ['id' => 'u-1', 'schema' => '42']]);

		self::assertSame(expected: ['objectUuid' => 'u-1', 'registerId' => '', 'schemaId' => '42'], actual: $link);

	}//end testTheRegisterMayBeMissing()

	/**
	 * A row without its uuid or schema in @self, or without @self, does not link.
	 *
	 * @return void
	 */
	public function testARowWithoutUuidOrSchemaDoesNotLink(): void {
		

		self::assertSame(expected: [], actual: $this->events()->linkFieldsOf(row: ['@self' => ['id' => 'u-1']]));
		self::assertSame(expected: [], actual: $this->events()->linkFieldsOf(row: ['@self' => ['schema' => '42']]));
		self::assertSame(expected: [], actual: $this->events()->linkFieldsOf(row: ['@self' => 'scalar', 'id' => 'u-1']));
		self::assertSame(expected: [], actual: $this->events()->linkFieldsOf(row: ['id' => 'u-1']));

	}//end testARowWithoutUuidOrSchemaDoesNotLink()
	/**
	 * The writer under test.
	 *
	 * @return DeadlineCalendarEvents
	 */
	private function events(): DeadlineCalendarEvents {
		return new DeadlineCalendarEvents(logger: new NullLogger());

	}//end events()
}//end class
