<?php

/**
 * LinkRecurringProfilesToCustomers unit tests.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/sales-cancellation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\LinkRecurringProfilesToCustomers;
use OCA\Shillinq\Service\ContactCustomerResolver;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\Contacts\IManager as IContactsManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Rows per schema, with OpenRegister's paging; remembers every save.
 *
 * @SuppressWarnings(PHPMD.CamelCaseParameterName) -- _rbac/_multitenancy mirror OR's API.
 */
final class ProfileCustomerStore {
	/**
	 * Rows by schema, then uuid.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $rows = [];

	/**
	 * Every save.
	 *
	 * @var array<int, array{object: array<string, mixed>, uuid: string|null, schema: mixed, rbac: bool}>
	 */
	public array $saved = [];

	/**
	 * Make reads throw.
	 *
	 * @var bool
	 */
	public bool $down = false;

	private string $schema = '';

	public function setRegister(string|int $register): static {
		return $this;
	}//end setRegister()

	public function setSchema(string|int $schema): static {
		$this->schema = (string)$schema;
		return $this;
	}//end setSchema()

	/**
	 * @param array<string, mixed> $config Limit and offset.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(array $config = []): array {
		if ($this->down === true) {
			throw new RuntimeException('RecurringInvoiceProfile schema is not imported yet');
		}

		$matches = [];
		foreach (($this->rows[$this->schema] ?? []) as $uuid => $row) {
			$matches[] = $row + ['id' => $uuid];
		}

		return array_slice($matches, (int)($config['offset'] ?? 0), (int)($config['limit'] ?? count($matches)));
	}//end findAll()

	/**
	 * @param array<string, mixed> $object        The object.
	 * @param array|null           $extend        Unused.
	 * @param mixed                $register      Unused.
	 * @param mixed                $schema        The schema.
	 * @param string|null          $uuid          The uuid to update.
	 * @param bool                 $_rbac         Whether RBAC applies.
	 * @param bool                 $_multitenancy Unused.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
		$this->saved[] = ['object' => $object, 'uuid' => $uuid, 'schema' => $schema, 'rbac' => $_rbac];
		$this->rows[(string)$schema][(string)$uuid] = $object;

		return $object + ['id' => $uuid];
	}//end saveObject()
}//end class

/**
 * Profiles made before customerMasterId get the customer their contact resolves to; the rest are named.
 *
 * @covers \OCA\Shillinq\Repair\LinkRecurringProfilesToCustomers
 * @uses   \OCA\Shillinq\Service\ContactCustomerResolver
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
final class LinkRecurringProfilesToCustomersTest extends TestCase {

	/**
	 * The step over this store, with contacts that know linde's address.
	 *
	 * @param ProfileCustomerStore $store The store.
	 *
	 * @return LinkRecurringProfilesToCustomers
	 */
	private function step(ProfileCustomerStore $store): LinkRecurringProfilesToCustomers {
		$settings = $this->createStub(RegisterLocation::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$contacts = $this->createStub(IContactsManager::class);
		$contacts->method('search')->willReturnCallback(
			static fn (string $pattern): array => ($pattern === 'linde' ? [['UID' => 'linde', 'EMAIL' => 'info@linde.example']] : [])
		);

		return new LinkRecurringProfilesToCustomers(
			settingsService: $settings,
			logger: new NullLogger(),
			objectService: new DuckObjectServiceAdapter($store),
			resolver: new ContactCustomerResolver(contacts: $contacts),
		);
	}//end step()

	/**
	 * Customers and four profiles: one by reference, one by e-mail, one unknown, one already linked.
	 *
	 * @return ProfileCustomerStore
	 */
	private function store(): ProfileCustomerStore {
		$store = new ProfileCustomerStore();
		$store->rows['CustomerMaster'] = [
			'cm-1' => ['name' => 'Acme', 'externalReference' => 'contact:acme'],
			'cm-2' => ['name' => 'Linde', 'email' => 'info@linde.example'],
		];
		$store->rows['RecurringInvoiceProfile'] = [
			'rp-acme' => ['name' => 'Hosting Acme', 'customerReference' => 'contact:acme', 'status' => 'active'],
			'rp-linde' => ['name' => 'Kracht Basis', 'customerReference' => 'contact:linde', 'status' => 'active'],
			'rp-ghost' => ['name' => 'Personal training', 'customerReference' => 'contact:ghost', 'status' => 'ended'],
			'rp-done' => ['name' => 'Linked', 'customerReference' => 'contact:acme', 'customerMasterId' => 'cm-9'],
		];

		return $store;
	}//end store()

	/**
	 * Resolved profiles are linked once, unscoped; the unresolved one is named; a rerun saves nothing.
	 *
	 * @return void
	 */
	public function testResolvedProfilesAreLinkedAndTheRestNamed(): void {
		$store = $this->store();
		$output = $this->createMock(IOutput::class);
		$output->expects(self::exactly(2))->method('info')->willReturnCallback(
			static function (string $message): void {
				self::assertMatchesRegularExpression('/\d recurring profile\(s\) linked to their customer, 1 could not be resolved\./', $message);
			}
		);
		$output->expects(self::exactly(2))->method('warning')->with('Shillinq: link these recurring profiles to their customer by hand: Personal training (rp-ghost)');

		$this->step(store: $store)->run(output: $output);

		self::assertSame(['rp-acme', 'rp-linde'], array_column($store->saved, 'uuid'));
		self::assertSame(['RecurringInvoiceProfile', 'RecurringInvoiceProfile'], array_column($store->saved, 'schema'));
		self::assertSame([false, false], array_column($store->saved, 'rbac'));
		self::assertSame(['name' => 'Hosting Acme', 'customerReference' => 'contact:acme', 'status' => 'active', 'customerMasterId' => 'cm-1'], $store->saved[0]['object']);
		self::assertSame('cm-2', $store->saved[1]['object']['customerMasterId']);
		self::assertSame('cm-9', $store->rows['RecurringInvoiceProfile']['rp-done']['customerMasterId']);

		$this->step(store: $store)->run(output: $output);
		self::assertCount(2, $store->saved, 'a second run wrote again');
	}//end testResolvedProfilesAreLinkedAndTheRestNamed()

	/**
	 * A failing read never blocks the upgrade: the step warns and returns.
	 *
	 * @return void
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
	 */
	public function testTheStepNamesWhatItDoes(): void {
		self::assertStringContainsString('recurring profiles', $this->step(store: new ProfileCustomerStore())->getName());
	}//end testTheStepNamesWhatItDoes()
}//end class
