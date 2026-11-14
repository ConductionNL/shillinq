<?php

/**
 * Unit tests for XbrlDeliveryGuard: submitted only with the Digipoort reference.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-3.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\XbrlDeliveryGuard;
use OCA\Shillinq\Lifecycle\PostingRefusedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(XbrlDeliveryGuard::class)]
#[UsesClass(PostingRefusedException::class)]
final class XbrlDeliveryGuardTest extends TestCase {

	public function testAFilingWithItsDeliveryReferenceMayBeSubmitted(): void {
		$this->assertTrue((new XbrlDeliveryGuard())->requireDeliveryReference(['digipoortReceiptId' => 'KN-123']));
	}

	public function testWithoutTheReferenceItIsRefused(): void {
		$this->expectException(PostingRefusedException::class);
		$this->expectExceptionMessage('The filing has no Digipoort delivery reference yet.');

		(new XbrlDeliveryGuard())->requireDeliveryReference(['digipoortReceiptId' => '  ', 'state' => 'handed-off']);
	}
	public function testAVatReturnCountsAsSubmittedOnlyWithItsFilingReference(): void {
		$guard = new XbrlDeliveryGuard();
		$this->assertTrue($guard->requireFilingReference(['filingReference' => 'KN-123']));

		$this->expectException(PostingRefusedException::class);
		$this->expectExceptionMessage('The VAT return has no Digipoort delivery reference yet.');

		$guard->requireFilingReference(['filingReference' => null]);
	}
}
