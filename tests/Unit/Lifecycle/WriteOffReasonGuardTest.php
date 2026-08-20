<?php

/**
 * Unit tests for WriteOffReasonGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\WriteOffReasonGuard;
use PHPUnit\Framework\TestCase;

final class WriteOffReasonGuardTest extends TestCase {
	private WriteOffReasonGuard $guard;

	protected function setUp(): void {
		parent::setUp();
		$this->guard = new WriteOffReasonGuard();
	}//end setUp()

	public function testWriteOffAllowedWithReason(): void {
		self::assertTrue($this->guard->requireReason(['writeOffReason' => 'Customer declared bankrupt']));
	}//end testWriteOffAllowedWithReason()

	public function testWriteOffDeniedWithoutReason(): void {
		self::assertFalse($this->guard->requireReason(['writeOffReason' => '']));
	}//end testWriteOffDeniedWithoutReason()
}//end class
