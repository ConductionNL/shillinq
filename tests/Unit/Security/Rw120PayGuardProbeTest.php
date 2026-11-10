<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Security;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Shillinq\Lifecycle\SupplierInvoicePayGuard;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * RW120 probe: SupplierInvoicePayGuard over an OpenRegister-faithful ObjectService double.
 *
 * Faithful to OR 551af4c ObjectService::find(): the register/schema set with setRegister()/setSchema()
 * is NOT used by find(); only the register/schema ARGUMENTS scope it (callFindScope). With neither,
 * MagicMapper::find() searches across all magic tables (findAcrossAllMagicTables) by id, uuid, slug
 * or uri. With a schema, a uuid miss is retried without the schema (mayRetryWithoutSchema).
 * Multitenancy filters on the caller's organisation (org-a).
 *
 * find() answers with an ObjectEntity, as OpenRegister's does; the schema
 * resolver reads the register and schema it carries.
 *
 * @covers \OCA\Shillinq\Lifecycle\SupplierInvoicePayGuard
 */
final class Rw120PayGuardProbeTest extends TestCase {

	public const OWN = '11111111-1111-4111-8111-111111111111';
	public const FOREIGN_ORG = '22222222-2222-4222-8222-222222222222';
	public const OTHER_ADMIN = '33333333-3333-4333-8333-333333333333';
	public const OPEN = '44444444-4444-4444-8444-444444444444';
	public const AR_INVOICE = '55555555-5555-4555-8555-555555555555';
	public const OTHER_SUPPLIER_TX = '66666666-6666-4666-8666-666666666666';
	public const OTHER_APP = '77777777-7777-4777-8777-777777777777';
	public const NO_ADMIN = '88888888-8888-4888-8888-888888888888';
	public const PARTIAL = '99999999-9999-4999-8999-999999999999';
	public const INVOICE = 'aaaaaaaa-0000-4000-8000-00000000000a';
	public const OTHER_INVOICE = 'bbbbbbbb-0000-4000-8000-00000000000b';

	/** @var array<int, array<string, mixed>> */
	public array $calls = [];

	/** @return array<int, array<string, mixed>> rows: uuid, slug, register, schema, org, data */
	private static function rows(): array {
		$rows = [
			['uuid' => self::OWN, 'slug' => null, 'register' => 'shillinq', 'schema' => 'APTransaction', 'org' => 'org-a', 'data' => ['state' => 'paid', 'administrationId' => 'adm-1']],
			['uuid' => self::FOREIGN_ORG, 'slug' => null, 'register' => 'shillinq', 'schema' => 'APTransaction', 'org' => 'org-b', 'data' => ['state' => 'paid', 'administrationId' => 'adm-1']],
			['uuid' => self::OTHER_ADMIN, 'slug' => null, 'register' => 'shillinq', 'schema' => 'APTransaction', 'org' => 'org-a', 'data' => ['state' => 'paid', 'administrationId' => 'adm-2']],
			['uuid' => self::OPEN, 'slug' => null, 'register' => 'shillinq', 'schema' => 'APTransaction', 'org' => 'org-a', 'data' => ['state' => 'issued', 'administrationId' => 'adm-1']],
			['uuid' => self::PARTIAL, 'slug' => null, 'register' => 'shillinq', 'schema' => 'APTransaction', 'org' => 'org-a', 'data' => ['state' => 'partially-paid', 'administrationId' => 'adm-1']],
			// A paid customer (AR) invoice in the same administration: not an AP transaction.
			['uuid' => self::AR_INVOICE, 'slug' => 'inv-2026-001', 'register' => 'shillinq', 'schema' => 'Invoice', 'org' => 'org-a', 'data' => ['state' => 'paid', 'administrationId' => 'adm-1']],
			// A paid AP transaction that belongs to ANOTHER supplier invoice, same administration.
			['uuid' => self::OTHER_SUPPLIER_TX, 'slug' => null, 'register' => 'shillinq', 'schema' => 'APTransaction', 'org' => 'org-a', 'data' => ['state' => 'paid', 'administrationId' => 'adm-1', 'invoiceNumber' => 'OTHER-1']],
			// Another app's register, same organisation.
			['uuid' => self::OTHER_APP, 'slug' => null, 'register' => 'otherapp', 'schema' => 'Thing', 'org' => 'org-a', 'data' => ['state' => 'paid', 'administrationId' => 'adm-1']],
			// A paid object with no administrationId (RepaymentInstallment-like).
			['uuid' => self::NO_ADMIN, 'slug' => null, 'register' => 'shillinq', 'schema' => 'RepaymentInstallment', 'org' => 'org-a', 'data' => ['state' => 'paid']],
		];
		// Every row names the invoice under test, except the other supplier invoice's transaction.
		foreach ($rows as $i => $row) {
			$rows[$i]['data']['supplierInvoiceId'] = $row['uuid'] === self::OTHER_SUPPLIER_TX ? self::OTHER_INVOICE : self::INVOICE;
		}
		return $rows;
	}

	private function guard(): SupplierInvoicePayGuard {
		$objectService = new class($this, self::rows()) {
			public ?string $currentRegister = null;
			public ?string $currentSchema = null;
			public function __construct(private Rw120PayGuardProbeTest $test, private array $rows) {
			}
			public function setRegister($register): self {
				$this->currentRegister = (string)$register;
				return $this;
			}
			public function setSchema($schema): self {
				$this->currentSchema = (string)$schema;
				return $this;
			}
			private function match(array $row, $id, ?string $register, ?string $schema, bool $multi): bool {
				if ($register !== null && $row['register'] !== $register) {
					return false;
				}
				if ($schema !== null && $row['schema'] !== $schema) {
					return false;
				}
				if ($multi === true && $row['org'] !== 'org-a') {
					return false;
				}
				return $row['uuid'] === $id || ($row['slug'] !== null && $row['slug'] === $id);
			}
			private function lookup($id, ?string $register, ?string $schema, bool $multi): ?array {
				foreach ($this->rows as $row) {
					if ($this->match($row, $id, $register, $schema, $multi) === true) {
						return $row;
					}
				}
				return null;
			}
			public function find(
				int|string $id,
				?\OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions $options = null,
				bool $_rbac = true,
				bool $_multitenancy = true
			): ObjectEntity {
				$register = $options?->register;
				$schema = $options?->schema;
				$_audit = ($options?->audit ?? true);
				$this->test->calls[] = ['find', $id, $register, $schema, $_rbac, $_multitenancy, $_audit];
				// find() scopes only by its arguments (OR callFindScope), never the setters' state.
				$hit = $this->lookup($id, $register, $schema, $_multitenancy);
				if ($hit === null && $schema !== null && is_string($id) && preg_match('/^[0-9a-f-]{36}$/', $id) === 1) {
					$hit = $this->lookup($id, $register, null, $_multitenancy);
				}
				if ($hit === null) {
					throw new RuntimeException('DoesNotExist');
				}
				if ($_rbac === true) {
					// The caller (procurement officer) holds no APTransaction read grant.
					throw new RuntimeException('Forbidden');
				}
				$entity = new ObjectEntity();
				$entity->setUuid($hit['uuid']);
				$entity->setRegister($hit['register']);
				$entity->setSchema($hit['schema']);
				$entity->setObject($hit['data']);
				return $entity;
			}
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->test->calls[] = ['findAll', $config, $_rbac, $_multitenancy];
				return [];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$schemas = $this->createMock(ListenerSchemaResolver::class);
		$schemas->method('matchesRegisterAndSchema')->willReturnCallback(
			static fn (?object $entity, string $registerSlug, string $schemaSlug): bool => $entity->getRegister() === $registerSlug && $entity->getSchema() === $schemaSlug
		);
		return new SupplierInvoicePayGuard($container, $settings, $this->createMock(LoggerInterface::class), $schemas);
	}

	private function allowed(array $invoice): bool {
		return $this->guard()->check($invoice + ['id' => self::INVOICE], 'pay', 'officer')->isAllowed();
	}

	public function testLegitimatePaidTransactionAllows(): void {
		self::assertTrue($this->allowed(['apTransactionId' => self::OWN, 'administrationId' => 'adm-1']));
	}

	public function testLegitimateWithWhitespaceIdAllows(): void {
		self::assertTrue($this->allowed(['apTransactionId' => ' ' . self::OWN . ' ', 'administrationId' => 'adm-1']));
	}

	public function testOtherOrganisationRefuses(): void {
		self::assertFalse($this->allowed(['apTransactionId' => self::FOREIGN_ORG, 'administrationId' => 'adm-1']));
	}

	public function testOtherAdministrationRefuses(): void {
		self::assertFalse($this->allowed(['apTransactionId' => self::OTHER_ADMIN, 'administrationId' => 'adm-1']));
	}

	public function testOpenAndPartialRefuse(): void {
		self::assertFalse($this->allowed(['apTransactionId' => self::OPEN, 'administrationId' => 'adm-1']));
		self::assertFalse($this->allowed(['apTransactionId' => self::PARTIAL, 'administrationId' => 'adm-1']));
	}

	public function testMissingOrUnknownRefuses(): void {
		self::assertFalse($this->allowed(['administrationId' => 'adm-1']));
		self::assertFalse($this->allowed(['apTransactionId' => '   ', 'administrationId' => 'adm-1']));
		self::assertFalse($this->allowed(['apTransactionId' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'administrationId' => 'adm-1']));
	}

	public function testCallerForgingAdministrationToMatchStillNeedsSameOrg(): void {
		self::assertFalse($this->allowed(['apTransactionId' => self::FOREIGN_ORG, 'administrationId' => 'adm-1']));
	}

	public function testLookupFlags(): void {
		$this->calls = [];
		$this->allowed(['apTransactionId' => self::OWN, 'administrationId' => 'adm-1']);
		self::assertSame([['find', self::OWN, 'shillinq', 'APTransaction', false, true, true]], $this->calls);
	}

	// ---- Forgery probes: each SHOULD refuse. A failure here is a finding. ----

	public function testForgedIdOfPaidArInvoiceRefuses(): void {
		self::assertFalse($this->allowed(['apTransactionId' => self::AR_INVOICE, 'administrationId' => 'adm-1']), 'FINDING F1: paid AR Invoice uuid satisfies the AP pay guard');
	}

	public function testForgedSlugOfPaidArInvoiceRefuses(): void {
		self::assertFalse($this->allowed(['apTransactionId' => 'inv-2026-001', 'administrationId' => 'adm-1']), 'FINDING F1b: slug of a paid AR Invoice satisfies the guard');
	}

	public function testForgedIdInOtherAppRegisterRefuses(): void {
		self::assertFalse($this->allowed(['apTransactionId' => self::OTHER_APP, 'administrationId' => 'adm-1']), 'FINDING F1c: paid object in another register satisfies the guard');
	}

	public function testForgedIdOfAnotherSuppliersPaidTransactionRefuses(): void {
		self::assertFalse($this->allowed(['apTransactionId' => self::OTHER_SUPPLIER_TX, 'administrationId' => 'adm-1', 'invoiceNumber' => 'MINE-1']), 'FINDING F2: another supplier invoice\'s paid AP transaction satisfies the guard');
	}

	public function testBlankAdministrationWithAdminlessPaidObjectRefuses(): void {
		self::assertFalse($this->allowed(['apTransactionId' => self::NO_ADMIN, 'administrationId' => '']), 'FINDING F3: blank administrationId matches a paid object without one');
		self::assertFalse($this->allowed(['apTransactionId' => self::NO_ADMIN]), 'FINDING F3b: missing administrationId matches a paid object without one');
	}
}
