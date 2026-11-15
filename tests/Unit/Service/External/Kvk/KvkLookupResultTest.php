<?php

/**
 * Unit tests for KvkLookupResult.
 *
 * The result an adapter returns keeps each member exactly as given, has no
 * extras unless they are passed, and cannot be changed afterwards.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\External\Kvk
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

namespace OCA\Shillinq\Tests\Unit\Service\External\Kvk;

use Error;
use OCA\Shillinq\Service\External\Kvk\KvkLookupResult;
use PHPUnit\Framework\TestCase;

/**
 * Tests for KvkLookupResult.
 *
 * @covers \OCA\Shillinq\Service\External\Kvk\KvkLookupResult
 */
class KvkLookupResultTest extends TestCase {

	/**
	 * Every member is kept as given.
	 *
	 * @return void
	 */
	public function testItKeepsEveryMember(): void {
		$result = new KvkLookupResult(
			lookupStatus: 'FOUND',
			kvkNumber: '69599084',
			entity: ['name' => 'Conduction B.V.'],
			dormant: false,
			extras: ['trace' => 'x-1'],
		);

		$this->assertSame('FOUND', $result->lookupStatus);
		$this->assertSame('69599084', $result->kvkNumber);
		$this->assertSame(['name' => 'Conduction B.V.'], $result->entity);
		$this->assertFalse($result->dormant);
		$this->assertSame(['trace' => 'x-1'], $result->extras);
	}//end testItKeepsEveryMember()

	/**
	 * Without extras the result carries none.
	 *
	 * @return void
	 */
	public function testExtrasDefaultToEmpty(): void {
		$result = new KvkLookupResult('FOUND', '69599084', ['name' => 'Conduction B.V.'], true);

		$this->assertSame([], $result->extras);
		$this->assertTrue($result->dormant);
	}//end testExtrasDefaultToEmpty()

	/**
	 * A result cannot be changed once made.
	 *
	 * @return void
	 */
	public function testItIsReadOnly(): void {
		$result = new KvkLookupResult('FOUND', '69599084', ['name' => 'Conduction B.V.'], true);

		$this->expectException(Error::class);
		$this->expectExceptionMessage('Cannot modify readonly property');
		$result->dormant = false;
	}//end testItIsReadOnly()
}//end class
