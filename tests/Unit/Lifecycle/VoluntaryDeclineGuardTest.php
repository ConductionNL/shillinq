<?php

/**
 * Unit tests for VoluntaryDeclineGuard.
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
 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-014)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\VoluntaryDeclineGuard;
use PHPUnit\Framework\TestCase;

/**
 * The decline transitions admit a voluntary contribution and nothing else.
 */
final class VoluntaryDeclineGuardTest extends TestCase {
	/**
	 * A voluntary contribution may be declined; a compulsory contribution, an
	 * ordinary invoice and a malformed group may not (REQ-SCON-014).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-014)
	 */
	public function testOnlyAVoluntaryContributionMayBeDeclined(): void {
		$guard = new VoluntaryDeclineGuard();

		self::assertTrue($guard->requireVoluntary(['contribution' => ['kind' => 'parental-contribution', 'voluntary' => true]]));
		self::assertFalse($guard->requireVoluntary(['contribution' => ['kind' => 'lunch-supervision', 'voluntary' => false]]));
		self::assertFalse($guard->requireVoluntary(['invoiceNumber' => 'INV-2026-0001']));
		self::assertFalse($guard->requireVoluntary(['contribution' => 'voluntary']));
		self::assertFalse($guard->requireVoluntary(['contribution' => ['voluntary' => 'true']]));
	}//end testOnlyAVoluntaryContributionMayBeDeclined()
}//end class
