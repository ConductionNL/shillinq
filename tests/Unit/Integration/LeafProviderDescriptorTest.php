<?php

/**
 * Unit tests for how the three shillinq integration leaves describe
 * themselves to OpenRegister's integration registry, report health, and
 * refuse the writes they do not offer.
 *
 * The descriptor, health and refusals read no collaborator, so the leaves
 * are built without their (final) services.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Integration
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

namespace OCA\Shillinq\Tests\Unit\Integration;

use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\Shillinq\Integration\ContractLeafProvider;
use OCA\Shillinq\Integration\FinancialDocumentLeafProvider;
use OCA\Shillinq\Integration\PaymentRequestLeafProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Tests for the leaves' descriptors and refusals.
 */
#[CoversClass(ContractLeafProvider::class)]
#[CoversClass(FinancialDocumentLeafProvider::class)]
#[CoversClass(PaymentRequestLeafProvider::class)]
final class LeafProviderDescriptorTest extends TestCase {

	/**
	 * A leaf without its collaborators.
	 *
	 * @param class-string<IntegrationProvider> $class The leaf class.
	 *
	 * @return IntegrationProvider
	 */
	private function leaf(string $class): IntegrationProvider {
		return (new ReflectionClass($class))->newInstanceWithoutConstructor();
	}//end leaf()

	/**
	 * Each leaf and the descriptor it registers with.
	 *
	 * @return array<string, array{0: class-string<IntegrationProvider>, 1: array<string, mixed>}>
	 */
	public static function descriptors(): array {
		return [
			'contracts'           => [
				ContractLeafProvider::class,
				[
					'id' => 'shillinq-contracts', 'label' => 'Contract', 'icon' => 'FileDocumentOutline',
					'group' => 'Finance', 'app' => 'shillinq', 'storage' => 'app-local', 'source' => null,
					'enabled' => true, 'permission' => null, 'auth' => [],
				],
			],
			'financial documents' => [
				FinancialDocumentLeafProvider::class,
				[
					'id' => 'shillinq-financial-documents', 'label' => 'Financial documents',
					'icon' => 'FileDocumentMultipleOutline', 'group' => 'Finance', 'app' => 'shillinq',
					'storage' => 'app-local', 'source' => null, 'enabled' => true, 'permission' => null, 'auth' => [],
				],
			],
			'payment requests'    => [
				PaymentRequestLeafProvider::class,
				[
					'id' => 'shillinq-payment-requests', 'label' => 'Payment requests', 'icon' => 'CreditCardOutline',
					'group' => 'Finance', 'app' => 'shillinq', 'storage' => 'app-local', 'source' => null,
					'enabled' => true, 'permission' => 'payment.request', 'auth' => [],
				],
			],
		];
	}//end descriptors()

	/**
	 * A leaf registers under its id, label, icon, group, app and strategy.
	 *
	 * @param class-string<IntegrationProvider> $class    The leaf class.
	 * @param array<string, mixed>              $expected The descriptor.
	 *
	 * @return void
	 */
	#[DataProvider('descriptors')]
	public function testTheLeafDescribesItself(string $class, array $expected): void {
		$leaf = $this->leaf($class);

		$this->assertSame(
			$expected,
			[
				'id'         => $leaf->getId(),
				'label'      => $leaf->getLabel(),
				'icon'       => $leaf->getIcon(),
				'group'      => $leaf->getGroup(),
				'app'        => $leaf->getRequiredApp(),
				'storage'    => $leaf->getStorageStrategy(),
				'source'     => $leaf->getOpenConnectorSource(),
				'enabled'    => $leaf->isEnabled(),
				'permission' => $leaf->requiresPermission(),
				'auth'       => $leaf->authRequirements(),
			]
		);
		$this->assertSame(['status' => 'ok', 'leaf' => $expected['id']], $leaf->health());
	}//end testTheLeafDescribesItself()

	/**
	 * The writes each leaf refuses, with the reason it gives.
	 *
	 * @return array<string, array{0: class-string<IntegrationProvider>, 1: string, 2: string}>
	 */
	public static function refusals(): array {
		return [
			'contract create'           => [ContractLeafProvider::class, 'create', 'A contract is administered in shillinq; the leaf reads it and does not create one.'],
			'contract update'           => [ContractLeafProvider::class, 'update', 'A contract is edited in shillinq, where its lifecycle and its audit trail are.'],
			'contract delete'           => [ContractLeafProvider::class, 'delete', 'A contract is an agreement and is terminated, never deleted from a case.'],
			'financial document create' => [FinancialDocumentLeafProvider::class, 'create', '400 Name the act: issueInvoice, requestPayment, createQuote, creditInvoice or raisePayout.'],
			'financial document update' => [FinancialDocumentLeafProvider::class, 'update', 'A financial document is changed in shillinq, where its lifecycle and its audit trail are.'],
			'financial document delete' => [FinancialDocumentLeafProvider::class, 'delete', 'A financial document is credited or voided, never deleted from an object.'],
			'payment request update'    => [PaymentRequestLeafProvider::class, 'update', 'A payment request changes state through its lifecycle, not through the leaf.'],
			'payment request delete'    => [PaymentRequestLeafProvider::class, 'delete', 'A payment request is payment evidence and is voided, never deleted.'],
		];
	}//end refusals()

	/**
	 * A write the leaf does not offer is refused with its reason.
	 *
	 * @param class-string<IntegrationProvider> $class   The leaf class.
	 * @param string                            $write   create, update or delete.
	 * @param string                            $message The refusal.
	 *
	 * @return void
	 */
	#[DataProvider('refusals')]
	public function testTheLeafRefusesWritesItDoesNotOffer(string $class, string $write, string $message): void {
		$leaf = $this->leaf($class);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage($message);

		match ($write) {
			'create' => $leaf->create('zaken', 'zaak', 'zaak-7', ['title' => 'x']),
			'update' => $leaf->update('zaken', 'zaak', 'zaak-7', 'e-1', ['title' => 'x']),
			'delete' => $leaf->delete('zaken', 'zaak', 'zaak-7', 'e-1'),
		};
	}//end testTheLeafRefusesWritesItDoesNotOffer()
}//end class
