<?php

/**
 * Unit tests for VoluntaryContributionPolicy.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Dunning
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-008)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Dunning;

use OCA\Shillinq\Service\Dunning\VoluntaryContributionPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Covers the voluntary test, the one-stage cap and the cost stripping.
 */
final class VoluntaryContributionPolicyTest extends TestCase {
	/**
	 * Only an invoice whose contribution group says voluntary is voluntary.
	 *
	 * @return void
	 */
	public function testOnlyAVoluntaryContributionIsVoluntary(): void {
		$policy = new VoluntaryContributionPolicy();

		self::assertTrue($policy->isVoluntary(['contribution' => ['voluntary' => true]]));
		self::assertFalse($policy->isVoluntary(['contribution' => ['voluntary' => false]]));
		self::assertFalse($policy->isVoluntary(['contribution' => ['voluntary' => 'true']]));
		self::assertFalse($policy->isVoluntary(['grossAmount' => 60.0]));
		self::assertFalse($policy->isVoluntary(null));
	}//end testOnlyAVoluntaryContributionIsVoluntary()

	/**
	 * A voluntary invoice may run stage 1 once; every other invoice any stage.
	 *
	 * @return void
	 */
	public function testAVoluntaryInvoiceRunsTheFirstStageOnce(): void {
		$policy = new VoluntaryContributionPolicy();
		$voluntary = ['contribution' => ['voluntary' => true]];

		self::assertTrue($policy->allowsStage($voluntary, 1, 0));
		self::assertFalse($policy->allowsStage($voluntary, 1, 1));
		self::assertFalse($policy->allowsStage($voluntary, 2, 0));
		self::assertTrue($policy->allowsStage(['grossAmount' => 60.0], 4, 3));
	}//end testAVoluntaryInvoiceRunsTheFirstStageOnce()

	/**
	 * Collection costs and interest are removed, everything else stays.
	 *
	 * @return void
	 */
	public function testCostsAreStripped(): void {
		$params = (new VoluntaryContributionPolicy())->stripCosts(
			['invoiceId' => 'inv-1', 'collectionCostAmount' => 40.0, 'interestAmount' => 1.5]
		);

		self::assertSame(['invoiceId' => 'inv-1', 'collectionCostAmount' => null, 'interestAmount' => null], $params);
	}//end testCostsAreStripped()

	/**
	 * A declined invoice is closed; an open one and a missing one are not
	 * (REQ-SCON-014).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-014)
	 */
	public function testADeclinedInvoiceIsRecognised(): void {
		$policy = new VoluntaryContributionPolicy();

		self::assertTrue($policy->isDeclined(['lifecycleState' => 'declined']));
		self::assertFalse($policy->isDeclined(['lifecycleState' => 'overdue']));
		self::assertFalse($policy->isDeclined(null));
	}//end testADeclinedInvoiceIsRecognised()
}//end class
