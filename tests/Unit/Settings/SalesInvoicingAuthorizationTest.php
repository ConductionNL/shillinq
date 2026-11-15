<?php

/**
 * Schema authorization for the sales, customer, time and invoicing domain.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Settings
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

namespace OCA\Shillinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Every sales, receivables, rate-card and time schema must close the
 * OpenRegister object API to callers outside its groups.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SalesInvoicingAuthorizationTest extends TestCase {
	use ShippedRegisterAuthorization;

	public const SCHEMAS = [
		'ARInvoice', 'BillableInvoice', 'BillableInvoiceLine', 'BlanketOrder', 'Contract',
		'ContractObligation', 'CreditHold', 'CreditNote', 'CreditScore', 'CustomerMaster', 'DebtorCredit',
		'Delivery', 'DunningLadder', 'DunningPauseDispute', 'DunningRecord', 'DunningRun', 'engagement',
		'FeeSchedule', 'IncassoKostenBerekening', 'Invoice', 'InvoiceLine', 'KlantLadderOverride',
		'MeterReading', 'OninbaarAfschrijving', 'OrderLine', 'OrderPrimitive', 'PaymentPlan',
		'PaymentPlanInstalment', 'PaymentRequest', 'PricingTier', 'ProjectAssignment', 'ProjectBudget',
		'Quote', 'QuoteLine', 'RateCard', 'RateCardTemplate', 'RateCardVersion', 'RateRecord',
		'RateSchedule', 'RecurringInvoiceProfile', 'RetainerDrawdown', 'RetainerPool', 'RetainerRollover',
		'RetainerSchedule', 'RetainerTrueUp', 'SalesOrder', 'SalesOrderLine', 'ServiceCategoryOverride',
		'TimeIntakeBatch', 'UrenRegistratie', 'UsageRatePlan', 'VolumeDiscount',
	];

	/**
	 * Each schema in the domain ships an enforceable authorization block.
	 *
	 * @return void
	 */
	public function testSalesAndInvoicingSchemasEnforceAuthorization(): void {
		$this->assertSchemasEnforceAuthorization(self::SCHEMAS);
	}//end testSalesAndInvoicingSchemasEnforceAuthorization()

	/**
	 * Issued invoices are never deleted through the object API: corrections
	 * go through credit notes, so only Nextcloud admins hold delete.
	 *
	 * @return void
	 */
	public function testIssuedInvoicesCannotBeDeletedByUsers(): void {
		$this->assertAdminOnly('ARInvoice', ['delete']);
		$this->assertAdminOnly('Invoice', ['delete']);
	}//end testIssuedInvoicesCannotBeDeletedByUsers()
}//end class
