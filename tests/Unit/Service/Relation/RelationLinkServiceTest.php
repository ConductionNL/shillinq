<?php

/**
 * Tests for RelationLinkService (reporting-relation-both-sides REQ-RRBS-001).
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Relation;

use DomainException;
use OCA\Shillinq\Service\Relation\RelationLinkService;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use PHPUnit\Framework\TestCase;

/**
 * Suggest by number, link only on confirmation, one to one, never again once dismissed.
 */
class RelationLinkServiceTest extends TestCase {

	/**
	 * A KvK match and a VAT match typed differently are both suggested; a linked pair and another administration are not.
	 *
	 * @return void
	 */
	public function testPairsWithAnEqualKvkOrVatNumberAreSuggested(): void {
		$service = new RelationLinkService(RelationFixture::records($this));

		$this->assertSame(
			[
				[
					'customerId' => 'c-noord',
					'customerName' => 'Transport Noord B.V.',
					'payeeId' => 'p-noord',
					'payeeName' => 'Transport Noord B.V.',
					'matchedOn' => 'vat',
					'number' => 'NL812345678B01',
				],
			],
			$service->suggestions(RelationFixture::ADM)
		);
	}

	/**
	 * Scenario "A suggested pair is confirmed": the link names the match and who confirmed it, and validates against the register.
	 *
	 * @return void
	 */
	public function testAConfirmedSuggestionBecomesALink(): void {
		$saved = [];
		$service = new RelationLinkService(RelationFixture::records($this, null, $saved));

		$service->link(RelationFixture::ADM, 'c-noord', 'p-noord', 'petra', 'vat');

		$customer = end($saved)['object'];
		$this->assertSame('p-noord', $customer['payeeId']);
		$this->assertSame('vat', $customer['payeeLink']['matchedOn']);
		$this->assertSame('petra', $customer['payeeLink']['confirmedBy']);
		unset($customer['id']);
		$this->assertSame([], RegisterSchema::errors('CustomerMaster', $customer));
		$this->assertSame([], $service->suggestions(RelationFixture::ADM), 'a linked pair is not suggested again');
	}

	/**
	 * A supplier linked to one customer cannot be linked to a second.
	 *
	 * @return void
	 */
	public function testASecondCustomerCannotLinkTheSameSupplier(): void {
		$service = new RelationLinkService(RelationFixture::records($this));

		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('already linked to another customer');
		$service->link(RelationFixture::ADM, 'c-oost', 'p-zuid', 'petra', 'manual');
	}

	/**
	 * A record of another administration is not found.
	 *
	 * @return void
	 */
	public function testARecordOfAnotherAdministrationIsNotFound(): void {
		$service = new RelationLinkService(RelationFixture::records($this));

		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('Not found');
		$service->link(RelationFixture::ADM, 'c-elsewhere', 'p-west', 'petra', 'manual');
	}

	/**
	 * A dismissed pair is not suggested again.
	 *
	 * @return void
	 */
	public function testADismissedPairIsNotSuggestedAgain(): void {
		$service = new RelationLinkService(RelationFixture::records($this));

		$service->dismiss(RelationFixture::ADM, 'c-noord', 'p-noord');

		$this->assertSame([], $service->suggestions(RelationFixture::ADM));
	}

	/**
	 * Unlinking clears the link.
	 *
	 * @return void
	 */
	public function testUnlinkClearsTheLink(): void {
		$saved = [];
		$service = new RelationLinkService(RelationFixture::records($this, null, $saved));

		$service->unlink(RelationFixture::ADM, 'c-zuid');

		$this->assertNull(end($saved)['object']['payeeId']);
	}
}
