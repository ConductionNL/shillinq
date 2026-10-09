<?php

/**
 * Unit tests for BackfillPaymentRequestCustomer.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/arinvoice-lines-and-portal-amounts/specs/portal-payment-initiation/spec.md (REQ-SPPI-008)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\BackfillPaymentRequestCustomer;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A PaymentRequest store with OpenRegister's paging and JSON-property filters.
 *
 * @SuppressWarnings(PHPMD.CamelCaseParameterName) -- _rbac/_multitenancy mirror OR's API.
 */
final class BackfillRequestStore {
	/**
	 * Rows by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $rows = [];

	/**
	 * Every save.
	 *
	 * @var array<int, array{object: array<string, mixed>, uuid: string|null, rbac: bool}>
	 */
	public array $saved = [];

	/**
	 * Make reads throw.
	 *
	 * @var bool
	 */
	public bool $down = false;

	public function setRegister(string|int $register): static {
		return $this;
	}//end setRegister()

	public function setSchema(string|int $schema): static {
		return $this;
	}//end setSchema()

	/**
	 * @param array<string, mixed> $config Filters, limit and offset.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(array $config = []): array {
		if ($this->down === true) {
			throw new RuntimeException('PaymentRequest schema is not imported yet');
		}

		$matches = [];
		foreach ($this->rows as $uuid => $row) {
			foreach (($config['filters'] ?? []) as $key => $value) {
				if (($row[$key] ?? null) !== $value) {
					continue 2;
				}
			}

			$matches[] = $row + ['id' => $uuid];
		}

		return array_slice($matches, (int)($config['offset'] ?? 0), (int)($config['limit'] ?? count($matches)));
	}//end findAll()

	/**
	 * @param array<string, mixed> $object The object.
	 * @param array|null $extend Unused.
	 * @param mixed $register Unused.
	 * @param mixed $schema Unused.
	 * @param string|null $uuid The uuid to update.
	 * @param bool $_rbac Whether RBAC applies.
	 * @param bool $_multitenancy Unused.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
		$this->saved[] = ['object' => $object, 'uuid' => $uuid, 'rbac' => $_rbac];
		$this->rows[(string)$uuid] = $object;

		return $object + ['id' => $uuid];
	}//end saveObject()
}//end class

/**
 * Requests raised before the portal scope existed get it once.
 *
 * @spec openspec/changes/arinvoice-lines-and-portal-amounts/specs/portal-payment-initiation/spec.md (REQ-SPPI-008)
 */
final class BackfillPaymentRequestCustomerTest extends TestCase {
	private const CUSTOMER = '20000000-0000-4000-8000-000000000002';

	/**
	 * The step over this store.
	 *
	 * @param BackfillRequestStore $store The store.
	 *
	 * @return BackfillPaymentRequestCustomer
	 */
	private function step(BackfillRequestStore $store): BackfillPaymentRequestCustomer {
		$settings = $this->createStub(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		return new BackfillPaymentRequestCustomer(
			settingsService: $settings,
			logger: new NullLogger(),
			objectService: new DuckObjectServiceAdapter($store),
		);
	}//end step()

	/**
	 * A store with one request of each kind.
	 *
	 * @return BackfillRequestStore
	 */
	private function store(): BackfillRequestStore {
		$store = new BackfillRequestStore();
		$store->rows = [
			'pr-leges' => ['subjectKind' => 'object', 'requestType' => 'leges', 'amount' => 125.0, 'state' => 'pending', 'debtor' => ['customerMasterId' => self::CUSTOMER]],
			'pr-contribution' => ['subjectKind' => 'object', 'requestType' => 'contribution', 'invoiceReference' => 'inv-1', 'amount' => 60.0, 'state' => 'pending', 'debtor' => ['customerMasterId' => self::CUSTOMER]],
			'pr-by-mail' => ['subjectKind' => 'object', 'requestType' => 'dwangsom', 'amount' => 250.0, 'state' => 'pending', 'debtor' => ['name' => 'J. Jansen', 'email' => 'j.jansen@example.nl']],
			'pr-invoice' => ['subjectKind' => 'invoice', 'invoiceReference' => 'inv-2', 'amount' => 90.0, 'state' => 'pending'],
		];

		return $store;
	}//end store()

	/**
	 * The request without an invoice gets its customer once, unscoped by RBAC;
	 * the others are untouched; a second run saves nothing (REQ-SPPI-008).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/arinvoice-lines-and-portal-amounts/specs/portal-payment-initiation/spec.md (REQ-SPPI-008)
	 */
	public function testARequestWithoutAnInvoiceIsStampedOnce(): void {
		$store = $this->store();
		$output = $this->createMock(IOutput::class);
		$output->expects(self::exactly(2))->method('info')->with(self::stringContains('stamped'));

		$this->step(store: $store)->run(output: $output);

		self::assertCount(1, $store->saved);
		self::assertSame('pr-leges', $store->saved[0]['uuid']);
		self::assertFalse($store->saved[0]['rbac']);
		self::assertSame(self::CUSTOMER, $store->saved[0]['object']['customerId']);
		self::assertArrayNotHasKey('id', $store->saved[0]['object']);
		self::assertArrayNotHasKey('customerId', $store->rows['pr-contribution']);
		self::assertArrayNotHasKey('customerId', $store->rows['pr-by-mail']);

		$this->step(store: $store)->run(output: $output);
		self::assertCount(1, $store->saved, 'a second run wrote again');
	}//end testARequestWithoutAnInvoiceIsStampedOnce()

	/**
	 * A failing read never blocks the upgrade: the step warns and returns
	 * (REQ-SPPI-008).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/arinvoice-lines-and-portal-amounts/specs/portal-payment-initiation/spec.md (REQ-SPPI-008)
	 */
	public function testAFailingReadWarnsInsteadOfBlockingTheUpgrade(): void {
		$store = $this->store();
		$store->down = true;
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning')->with(self::stringContains('not imported yet'));

		$this->step(store: $store)->run(output: $output);

		self::assertSame([], $store->saved);
	}//end testAFailingReadWarnsInsteadOfBlockingTheUpgrade()

	/**
	 * The step names itself for the upgrade log.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/arinvoice-lines-and-portal-amounts/specs/portal-payment-initiation/spec.md (REQ-SPPI-008)
	 */
	public function testTheStepNamesWhatItDoes(): void {
		$name = $this->step(store: new BackfillRequestStore())->getName();

		self::assertStringContainsString('Shillinq', $name);
		self::assertStringContainsString('payment requests', $name);
	}//end testTheStepNamesWhatItDoes()
}//end class
