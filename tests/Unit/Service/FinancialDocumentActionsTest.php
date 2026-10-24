<?php

/**
 * Unit tests for FinancialDocumentActions.
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
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003, REQ-FDO-004)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\FinancialDocumentActionRefusedException;
use OCA\Shillinq\Service\FinancialDocumentActions;
use OCA\Shillinq\Service\FinancialDocumentGuard;
use OCA\Shillinq\Service\ObjectRequestInvoiceService;
use OCA\Shillinq\Service\PaymentActionAuthorizer;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * An in-memory store per schema; a caller read hides the ids in `$hidden`.
 */
final class ActionDocumentStore {
	public string $schema = '';

	/**
	 * Every save, with its schema.
	 *
	 * @var array<int, array{schema: string, object: array<string, mixed>, rbac: bool}>
	 */
	public array $saved = [];

	/**
	 * @param array<string, array<int, array<string, mixed>>> $rows Rows per schema.
	 * @param array<int, string> $hidden Ids the caller may not read.
	 */
	public function __construct(public array $rows, public array $hidden = []) {
	}

	public function setRegister(string|int $register): static {
		return $this;
	}

	public function setSchema(string|int $schema): static {
		$this->schema = (string)$schema;
		return $this;
	}

	/**
	 * @param array<string, mixed> $config The query.
	 * @param bool $_rbac Whether the caller's rights apply.
	 * @param bool $_multitenancy Unused.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$rows = [];
		foreach (($this->rows[$this->schema] ?? []) as $row) {
			if ($_rbac === true && in_array($row['id'], $this->hidden, true) === true) {
				continue;
			}

			foreach (($config['filters'] ?? []) as $key => $value) {
				if (($row[$key] ?? null) !== $value) {
					continue 2;
				}
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * @param array<string, mixed> $object The object.
	 * @param array|null $extend Unused.
	 * @param mixed $register Unused.
	 * @param mixed $schema The schema.
	 * @param string|null $uuid Unused.
	 * @param bool $_rbac Whether RBAC applies.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true): array {
		$id = 'new-' . (count($this->saved) + 1);
		$this->saved[] = ['schema' => (string)($schema ?? $this->schema), 'object' => $object, 'rbac' => $_rbac];

		return $object + ['id' => $id];
	}
}//end class

/**
 * The acts of the financial documents panel.
 */
final class FinancialDocumentActionsTest extends TestCase {
	private const CASE_ID = '7d9a2c4e-1b3f-4a5d-8e6f-0a1b2c3d4e5f';

	private const LINKS = [['app' => 'dossiq', 'type' => 'case', 'register' => 'dossiq', 'schema' => 'case', 'id' => self::CASE_ID]];

	private ?ActionDocumentStore $store = null;

	/**
	 * The service for a caller holding the given actions.
	 *
	 * @param array<int, string> $actions The actions the caller's group carries.
	 * @param ActionDocumentStore $store The store.
	 * @param ?ObjectRequestInvoiceService $invoices The invoice issuer.
	 * @param bool $memberOfAdministration Whether the caller may work in adm-1.
	 *
	 * @return FinancialDocumentActions
	 */
	private function actions(array $actions, ActionDocumentStore $store, ?ObjectRequestInvoiceService $invoices = null, bool $memberOfAdministration = true): FinancialDocumentActions {
		$this->store = $store;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('handler');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturn(true);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($actions): string {
				if ($key === PaymentActionAuthorizer::CONFIG_ACTION_GROUPS) {
					return (string)json_encode(array_fill_keys($actions, ['finance']));
				}

				return ($key === 'register') ? 'shillinq' : $default;
			}
		);
		$administrations = $this->createMock(AdministrationContextService::class);
		$administrations->method('canAccess')->willReturn($memberOfAdministration);
		$administrations->method('defaultAdministrationIdForUser')->willReturn('adm-1');

		$objects = new DuckObjectServiceAdapter(inner: $store);
		$authorizer = new PaymentActionAuthorizer(appConfig: $appConfig, userSession: $session, groupManager: $groups);

		return new FinancialDocumentActions(
			objectService: $objects,
			appConfig: $appConfig,
			invoices: ($invoices ?? $this->createMock(ObjectRequestInvoiceService::class)),
			guard: new FinancialDocumentGuard(objectService: $objects, appConfig: $appConfig, authorizer: $authorizer, administrations: $administrations),
		);
	}//end actions()

	/**
	 * An issued invoice of 120 euros on the case.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private static function invoice(array $overrides = []): array {
		return array_merge(
			['id' => 'i-1', 'invoiceNumber' => 'F-1', 'lifecycleState' => 'issued', 'invoiceType' => 'standard', 'grossAmount' => 120.0, 'amountDue' => 120.0, 'currency' => 'EUR', 'administrationId' => 'adm-1', 'linkedObjects' => self::LINKS],
			$overrides
		);
	}//end invoice()

	/**
	 * The status code of the refusal an act throws.
	 *
	 * @param callable $act The act.
	 *
	 * @return int The status.
	 */
	private static function refusal(callable $act): int {
		try {
			$act();
		} catch (FinancialDocumentActionRefusedException $e) {
			return $e->getStatus();
		}

		self::fail('the act was not refused');
	}//end refusal()

	public function testRequestPaymentRaisesAnInvoiceRequestCarryingTheInvoicesObjects(): void {
		$store = new ActionDocumentStore(['ARInvoice' => [self::invoice()], 'PaymentRequest' => []]);

		$created = $this->actions(['payment.request'], $store)->requestPayment(invoiceId: 'i-1');

		self::assertCount(1, $store->saved);
		$request = $store->saved[0]['object'];
		self::assertSame('PaymentRequest', $store->saved[0]['schema']);
		self::assertSame('invoice', $request['subjectKind']);
		self::assertSame('i-1', $request['invoiceReference']);
		self::assertSame(120.0, $request['amount']);
		self::assertSame('pending', $request['state']);
		self::assertSame('adm-1', $request['administrationId']);
		self::assertSame(self::LINKS, $request['linkedObjects']);
		self::assertSame('handler', $request['requestedBy']);
		self::assertSame('new-1', $created['id']);
	}//end testRequestPaymentRaisesAnInvoiceRequestCarryingTheInvoicesObjects()

	public function testRequestPaymentIsRefusedBeforeAnythingIsWritten(): void {
		$cases = [
			'no action' => [[], ['ARInvoice' => [self::invoice()]], [], 403],
			'unreadable invoice' => [['payment.request'], ['ARInvoice' => [self::invoice()]], ['i-1'], 404],
			'unknown invoice' => [['payment.request'], ['ARInvoice' => []], [], 404],
			'draft invoice' => [['payment.request'], ['ARInvoice' => [self::invoice(['lifecycleState' => 'draft'])]], [], 409],
			'paid invoice' => [['payment.request'], ['ARInvoice' => [self::invoice(['lifecycleState' => 'paid'])]], [], 409],
			'credit note' => [['payment.request'], ['ARInvoice' => [self::invoice(['invoiceType' => 'credit-note'])]], [], 409],
			'open request' => [['payment.request'], ['ARInvoice' => [self::invoice()], 'PaymentRequest' => [['id' => 'p-1', 'subjectKind' => 'invoice', 'invoiceReference' => 'i-1', 'state' => 'pending']]], [], 409],
		];
		foreach ($cases as $label => [$actions, $rows, $hidden, $status]) {
			$store = new ActionDocumentStore($rows, $hidden);
			$service = $this->actions($actions, $store);

			self::assertSame($status, self::refusal(static fn () => $service->requestPayment(invoiceId: 'i-1')), $label);
			self::assertSame([], $store->saved, $label . ' wrote something');
		}
	}//end testRequestPaymentIsRefusedBeforeAnythingIsWritten()

	public function testRequestPaymentIsRefusedOutsideTheInvoicesAdministration(): void {
		$store = new ActionDocumentStore(['ARInvoice' => [self::invoice()]]);
		$service = $this->actions(['payment.request'], $store, memberOfAdministration: false);

		self::assertSame(403, self::refusal(static fn () => $service->requestPayment(invoiceId: 'i-1')));
		self::assertSame([], $store->saved);
	}//end testRequestPaymentIsRefusedOutsideTheInvoicesAdministration()

	public function testIssueInvoiceIssuesOneInvoiceLinkedToTheObject(): void {
		$invoices = $this->createMock(ObjectRequestInvoiceService::class);
		$invoices->expects(self::once())->method('issue')
			->with(
				self::callback(static function (array $request): bool {
					return $request['amount'] === 250.0
						&& $request['description'] === 'Leges omgevingsvergunning'
						&& $request['debtor'] === ['name' => 'J. Jansen', 'email' => 'j.jansen@example.nl']
						&& $request['linkedObjects'] === [['type' => 'case', 'register' => 'dossiq', 'schema' => 'case', 'id' => self::CASE_ID]];
				}),
				'adm-1',
				self::matchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/')
			)
			->willReturn('inv-9');

		$issued = $this->actions(['invoice.issue'], new ActionDocumentStore([]), $invoices)->issueInvoice(
			register: 'dossiq',
			schema: 'case',
			objectId: self::CASE_ID,
			payload: ['amount' => 250, 'description' => 'Leges omgevingsvergunning', 'debtor' => ['name' => 'J. Jansen', 'email' => 'j.jansen@example.nl'], 'objectType' => 'case'],
		);

		self::assertSame(['id' => 'inv-9'], $issued);
	}//end testIssueInvoiceIssuesOneInvoiceLinkedToTheObject()

	public function testIssueInvoiceIsRefusedBeforeAnythingIsIssued(): void {
		$cases = [
			'no action' => [[], true, ['amount' => 10, 'debtor' => ['name' => 'X', 'email' => 'x@example.nl']], 403],
			'outside the administration' => [['invoice.issue'], false, ['amount' => 10, 'debtor' => ['name' => 'X', 'email' => 'x@example.nl']], 403],
			'no amount' => [['invoice.issue'], true, ['amount' => 0, 'debtor' => ['name' => 'X', 'email' => 'x@example.nl']], 400],
			'no debtor' => [['invoice.issue'], true, ['amount' => 10], 400],
		];
		foreach ($cases as $label => [$actions, $member, $payload, $status]) {
			$invoices = $this->createMock(ObjectRequestInvoiceService::class);
			$invoices->expects(self::never())->method('issue');
			$service = $this->actions($actions, new ActionDocumentStore([]), $invoices, $member);

			self::assertSame($status, self::refusal(static fn () => $service->issueInvoice(register: 'dossiq', schema: 'case', objectId: self::CASE_ID, payload: $payload)), $label);
		}
	}//end testIssueInvoiceIsRefusedBeforeAnythingIsIssued()
}//end class
