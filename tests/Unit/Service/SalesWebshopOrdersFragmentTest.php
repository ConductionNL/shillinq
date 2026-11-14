<?php

/**
 * Unit tests for the sales-webshop-orders register fragment.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @spec openspec/changes/sales-webshop-orders/tasks.md#1-contract
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * REQ-SWO-001 and REQ-SWO-006: the order intake contract integriq writes to,
 * the channel settings, the intake lifecycle with its refused notification,
 * and the ARInvoice.webshop group, read from the fragment and from the
 * register as SettingsService merges it.
 *
 * @coversNothing
 *
 * @spec openspec/changes/sales-webshop-orders/specs/bookkeeping-accounts-receivable-core/spec.md#requirement-a-received-web-shop-order-becomes-one-issued-sales-invoice-req-swo-001
 */
final class SalesWebshopOrdersFragmentTest extends TestCase {

	/**
	 * The settings directory.
	 */
	private const SETTINGS = __DIR__.'/../../../lib/Settings';

	/**
	 * The register with every fragment merged in, the way SettingsService loads it.
	 *
	 * @return array<string, mixed> The merged register.
	 */
	private static function merged(): array {
		$merge  = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		$config = json_decode((string) file_get_contents(self::SETTINGS.'/shillinq_register.json'), true);
		$files  = glob(self::SETTINGS.'/register.d/*.json');
		sort($files);
		foreach ($files as $file) {
			$fragment = json_decode((string) file_get_contents($file), true);
			if (is_array($fragment) === true) {
				$config = $merge->invoke(null, $config, $fragment);
			}
		}

		return $config;
	}//end merged()

	/**
	 * The order carries the fields of the contract, with channel and shop order number as the idempotency key.
	 *
	 * @return void
	 */
	public function testTheOrderIsTheIntakeContract(): void {
		$order = self::merged()['components']['schemas']['WebshopOrder'];

		$contract = [
			'channelId', 'shopOrderId', 'orderedAt', 'buyer', 'currency', 'pricesIncludeVat', 'lines', 'shipping',
			'discountTotal', 'totals', 'payment', 'administrationId', 'intakeState', 'arInvoiceId', 'creditNoteId', 'refusalReason',
		];
		foreach ($contract as $field) {
			$this->assertArrayHasKey($field, $order['properties'], $field);
		}

		$this->assertSame(['channelId', 'shopOrderId', 'orderedAt', 'buyer', 'currency', 'lines', 'totals', 'payment'], $order['required']);
		$this->assertSame(['business', 'consumer'], $order['properties']['buyer']['properties']['type']['enum']);
		$this->assertSame(['paid', 'pending', 'refunded', 'cancelled'], $order['properties']['payment']['properties']['status']['enum']);
		$this->assertSame(['sku', 'description', 'quantity', 'unitPrice', 'vatRate'], array_keys($order['properties']['lines']['items']['properties']));
		$this->assertSame(['net', 'vat', 'gross'], array_keys($order['properties']['totals']['properties']));
		$this->assertTrue($order['x-openregister-audit-trail']['enabled']);
	}//end testTheOrderIsTheIntakeContract()

	/**
	 * The intake state starts at received and moves to invoiced, refused or duplicate, and from invoiced to credited.
	 *
	 * @return void
	 */
	public function testTheIntakeLifecycle(): void {
		$order     = self::merged()['components']['schemas']['WebshopOrder'];
		$lifecycle = $order['x-openregister-lifecycle'];

		$this->assertSame('intakeState', $lifecycle['field']);
		$this->assertSame('received', $lifecycle['initialState']);
		$this->assertSame($order['properties']['intakeState']['enum'], array_keys($lifecycle['states']));
		$moves = array_map(static fn (array $t): array => [$t['from'], $t['to']], $lifecycle['transitions']);
		$this->assertSame(
			[
				'invoice'       => ['received', 'invoiced'],
				'refuse'        => ['received', 'refused'],
				'markDuplicate' => ['received', 'duplicate'],
				'credit'        => ['invoiced', 'credited'],
			],
			$moves
		);
	}//end testTheIntakeLifecycle()

	/**
	 * A refused order notifies the bookkeepers, naming the order and the reason.
	 *
	 * @return void
	 */
	public function testARefusedOrderIsNotified(): void {
		$rule = self::merged()['components']['schemas']['WebshopOrder']['x-openregister-notifications']['onRefused'];

		$this->assertSame(['type' => 'transition', 'action' => 'refuse'], $rule['trigger']);
		$this->assertTrue($rule['enabled']);
		$this->assertStringContainsString('{{refusalReason}}', $rule['subject']['nl']);
		$this->assertStringContainsString('{{shopOrderId}}', $rule['subject']['en']);
	}//end testARefusedOrderIsNotified()

	/**
	 * A channel holds what the shop does not know; the invoice keeps where it came from, next to its own fields.
	 *
	 * @return void
	 */
	public function testTheChannelAndTheInvoiceGroup(): void {
		$schemas = self::merged()['components']['schemas'];

		$this->assertSame(
			['name', 'platform', 'administrationId', 'consumerDebtorId', 'revenueAccount', 'shippingAccount', 'clearingAccount', 'issueMode'],
			array_keys($schemas['WebshopChannel']['properties'])
		);
		$this->assertSame(['auto-issue', 'draft-for-review'], $schemas['WebshopChannel']['properties']['issueMode']['enum']);
		$this->assertTrue($schemas['WebshopChannel']['x-openregister-audit-trail']['enabled']);

		$invoice = $schemas['ARInvoice'];
		$this->assertSame(
			['channelId', 'shopOrderId', 'orderId', 'buyerName', 'paymentReference'],
			array_keys($invoice['properties']['webshop']['properties'])
		);
		$this->assertArrayHasKey('invoiceType', $invoice['properties'], 'the merge keeps ARInvoice\'s own fields');
		$this->assertArrayHasKey('slug', $invoice);
	}//end testTheChannelAndTheInvoiceGroup()
}//end class
