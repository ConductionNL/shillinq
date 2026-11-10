<?php

/**
 * Characterisation test for PaymentRequestLeafRegistrationListener.
 *
 * Pins every leaf the listener registers — in order, with every descriptor
 * field (including the permission the payment-request data leaf requires)
 * and the provider each one is registered with.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Event\RegisterLeafProvidersEvent;
use OCA\Shillinq\Integration\ContractLeafProvider;
use OCA\Shillinq\Integration\FinancialDocumentLeafProvider;
use OCA\Shillinq\Integration\PaymentRequestLeafProvider;
use OCA\Shillinq\Listener\PaymentRequestLeafRegistrationListener;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionObject;

/**
 * Golden of the registered leaves.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PaymentRequestLeafRegistrationListenerTest extends TestCase {

	/**
	 * Every leaf, in registration order, with its provider.
	 *
	 * @return void
	 */
	public function testRegisteredLeaves(): void {
		$provider = (new ReflectionClass(PaymentRequestLeafProvider::class))->newInstanceWithoutConstructor();
		$contracts = (new ReflectionClass(ContractLeafProvider::class))->newInstanceWithoutConstructor();
		$documents = (new ReflectionClass(FinancialDocumentLeafProvider::class))->newInstanceWithoutConstructor();
		$listener = new PaymentRequestLeafRegistrationListener(provider: $provider, contracts: $contracts, documents: $documents);

		$event = new RegisterLeafProvidersEvent();
		$listener->handle(new Event());
		self::assertSame([], $event->getLeaves());
		$listener->handle($event);

		$providers = [spl_object_id($provider) => 'payment-requests', spl_object_id($contracts) => 'contracts', spl_object_id($documents) => 'documents'];
		$leaves = [];
		foreach ($event->getLeaves() as $leaf) {
			$fields = [];
			foreach ((new ReflectionObject($leaf['descriptor']))->getProperties() as $property) {
				$property->setAccessible(true);
				$fields[$property->getName()] = $property->getValue($leaf['descriptor']);
			}

			$fields['provider'] = ($leaf['provider'] === null) ? null : $providers[spl_object_id($leaf['provider'])];
			$leaves[] = $fields;
		}

		$panel = ['kinds' => ['render-surface'], 'requiredApp' => 'shillinq', 'group' => 'Finance', 'surfaces' => ['detail-page', 'single-entity'], 'referenceType' => null, 'requiresPermission' => null, 'renderMode' => 'mount', 'provider' => null];
		$data = ['kinds' => ['data-provider'], 'requiredApp' => 'shillinq', 'group' => 'Finance', 'surfaces' => [], 'referenceType' => null, 'requiresPermission' => null, 'renderMode' => 'component'];
		self::assertSame(
			[
				['id' => PaymentRequestLeafProvider::LEAF_ID, 'label' => 'Payment requests', 'icon' => 'CreditCardOutline'] + array_merge($data, ['requiresPermission' => PaymentRequestLeafProvider::ACTION_REQUEST, 'provider' => 'payment-requests']),
				['id' => 'shillinq-payment-requests-panel', 'label' => 'Payment requests', 'icon' => 'CreditCardOutline'] + $panel,
				['id' => ContractLeafProvider::LEAF_ID, 'label' => 'Contract', 'icon' => 'FileDocumentOutline'] + $data + ['provider' => 'contracts'],
				['id' => FinancialDocumentLeafProvider::LEAF_ID, 'label' => 'Financial documents', 'icon' => 'FileDocumentMultipleOutline'] + $data + ['provider' => 'documents'],
				['id' => 'shillinq-financial-documents-panel', 'label' => 'Financial documents', 'icon' => 'FileDocumentMultipleOutline'] + $panel,
				['id' => 'shillinq-contracts-panel', 'label' => 'Contract', 'icon' => 'FileDocumentOutline'] + $panel,
			],
			$leaves
		);

	}//end testRegisteredLeaves()
}//end class
