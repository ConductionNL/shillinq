<?php

/**
 * Unit tests for PaymentRequestLeafProvider.
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
 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-003)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Integration;

use InvalidArgumentException;
use OCA\Shillinq\Integration\PaymentRequestLeafProvider;
use OCA\Shillinq\Service\DebtorCreditService;
use OCA\Shillinq\Service\FeeScheduleService;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\ObjectPaymentRequestValidator;
use OCA\Shillinq\Service\PaymentActionAppGrant;
use OCA\Shillinq\Service\PaymentActionAuthorizer;
use OCA\Shillinq\Service\PaymentRevenueAccountResolver;
use OCA\Shillinq\Service\PaymentSettlementService;
use OCA\Shillinq\Tests\Unit\Fixtures\EffectiveRegisterFixture;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;


/**
 * Covers the leaf's scoping, its refusals and the shape it appends
 * (REQ-SOPR-003).
 */
final class PaymentRequestLeafProviderTest extends TestCase {
	/**
	 * Requests saved by the object-service double.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The `_rbac` flag of every save, in order.
	 *
	 * @var array<int, bool>
	 */
	private array $savedWithRbac = [];

	/**
	 * The `_rbac` flag of every read, in order.
	 *
	 * @var array<int, bool>
	 */
	private array $foundWithRbac = [];

	/**
	 * Build an object-service double backed by the given stored requests.
	 *
	 * @param array<int, array<string, mixed>> $stored Stored PaymentRequest rows.
	 *
	 * @return object The double.
	 */
	private function objectServiceDouble(array $stored): object {
		return new class($stored, $this->saved, $this->savedWithRbac, $this->foundWithRbac) {
			/**
			 * @param array<int, array<string, mixed>> $stored Stored rows.
			 * @param array<int, array<string, mixed>> $saved Sink.
			 * @param array<int, bool> $savedWithRbac The _rbac flag of each save.
			 * @param array<int, bool> $foundWithRbac The _rbac flag of each read.
			 */
			public function __construct(
				private array $stored,
				private array &$saved,
				private array &$savedWithRbac,
				private array &$foundWithRbac,
			) {
			}

			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				return $this;
			}

			/**
			 * @param array<string, mixed> $params Query params.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $params = [], bool $_rbac = true): array {
				$this->foundWithRbac[] = $_rbac;
				return $this->stored;
			}

			/**
			 * @param array<string, mixed> $object Object to persist.
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register = '', string $schema = '', bool $_rbac = true): array {
				$this->saved[]         = $object;
				$this->savedWithRbac[] = $_rbac;
				return $object;
			}
		};
	}//end objectServiceDouble()

	/**
	 * Build the provider under test.
	 *
	 * @param array<int, array<string, mixed>> $stored Stored PaymentRequest rows.
	 * @param bool $isAdmin Whether the caller is an administrator.
	 * @param array<int, string> $groups Groups the caller is in.
	 * @param array<string, array<int, string>> $actionGroups The configured action matrix.
	 * @param bool $signedIn Whether there is a session at all.
	 * @param object|null $inner An object-service double to use instead of the default one.
	 * @param array<string, array<int, string>> $appGrants The configured paymentActionApps matrix.
	 * @param array<int, string> $enabledApps The apps that are enabled.
	 *
	 * @return PaymentRequestLeafProvider The provider.
	 */
	private function makeProvider(
		array $stored = [],
		bool $isAdmin = false,
		array $groups = [],
		array $actionGroups = [],
		bool $signedIn = true,
		?object $inner = null,
		array $appGrants = [],
		array $enabledApps = [],
	): PaymentRequestLeafProvider {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($actionGroups, $appGrants): string {
				if ($key === PaymentActionAuthorizer::CONFIG_ACTION_GROUPS) {
					return json_encode($actionGroups, JSON_THROW_ON_ERROR);
				}

				if ($key === PaymentActionAppGrant::CONFIG_ACTION_APPS) {
					return json_encode($appGrants, JSON_THROW_ON_ERROR);
				}

				return ($key === 'register' ? 'shillinq' : $default);
			}
		);

		$session = $this->createMock(IUserSession::class);
		if ($signedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('handler');
			$session->method('getUser')->willReturn($user);
		} else {
			$session->method('getUser')->willReturn(null);
		}

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => in_array($group, $groups, true)
		);

		$objectService = new DuckObjectServiceAdapter(inner: ($inner ?? $this->objectServiceDouble($stored)));

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForAnyone')->willReturnCallback(
			static fn (string $appId): bool => in_array($appId, $enabledApps, true)
		);

		return new PaymentRequestLeafProvider(
			objectService: $objectService,
			validator: new ObjectPaymentRequestValidator(),
			appConfig: $appConfig,
			authorizer: new PaymentActionAuthorizer(
				appConfig: $appConfig,
				userSession: $session,
				groupManager: $groupManager,
			),
			feeSchedules: new FeeScheduleService(
				objectService: $objectService,
				appConfig: $appConfig,
				logger: $this->createMock(LoggerInterface::class),
			),
			settlements: new PaymentSettlementService(),
			appGrant: new PaymentActionAppGrant(appConfig: $appConfig, appManager: $appManager),
		);
	}//end makeProvider()

	/**
	 * One stored request on a given case.
	 *
	 * @param string $id The request id.
	 * @param string $caseId The case id.
	 * @param string $state The request state.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function storedRequest(string $id, string $caseId, string $state = 'pending'): array {
		return [
			'id' => $id,
			'subjectKind' => 'object',
			'subject' => ['type' => 'case', 'register' => 'dossiq', 'schema' => 'Zaak', 'id' => $caseId],
			'requestType' => 'dwangsom',
			'amount' => 250.0,
			'currency' => 'EUR',
			'state' => $state,
			'paymentLink' => 'https://pay.example/' . $id,
		];
	}//end storedRequest()

	/**
	 * The descriptor half the JS registration is paired against (REQ-SOPR-004).
	 *
	 * @return void
	 */
	public function testLeafIdentifiesItselfAsAnAppLocalDataProvider(): void {
		$provider = $this->makeProvider();

		self::assertSame('shillinq-payment-requests', $provider->getId());
		self::assertSame('app-local', $provider->getStorageStrategy());
		self::assertSame('payment.request', $provider->requiresPermission());
	}//end testLeafIdentifiesItselfAsAnAppLocalDataProvider()

	/**
	 * `list` answers only the host object's own requests. A leaf that returned
	 * every object request would leak one case's money into another's panel.
	 *
	 * @return void
	 */
	public function testListIsScopedToTheHostObject(): void {
		$provider = $this->makeProvider(
			[$this->storedRequest('pr-1', 'zaak-7'), $this->storedRequest('pr-2', 'zaak-9')]
		);

		$listed = $provider->list('dossiq', 'Zaak', 'zaak-7');

		self::assertCount(1, $listed['items']);
		self::assertSame(1, $listed['total']);
		self::assertSame('pr-1', $listed['items'][0]['id']);
		self::assertSame('https://pay.example/pr-1', $listed['items'][0]['paymentLink']);
		self::assertArrayHasKey('fee', $listed);
	}//end testListIsScopedToTheHostObject()

	/**
	 * A caller whose groups do not carry `payment.request` is refused, and NOTHING
	 * is written. This is the least privileged principal that should be refused:
	 * an ordinary signed-in user with no mapping (REQ-SOPR-003).
	 *
	 * @return void
	 */
	public function testCallerWithoutTheActionIsRefusedAndNothingIsWritten(): void {
		$provider = $this->makeProvider(actionGroups: ['payment.request' => ['finance']], groups: ['staff']);

		try {
			$provider->create('dossiq', 'Zaak', 'zaak-7', ['requestType' => 'dwangsom', 'amount' => 250.0]);
			self::fail('The provider accepted a caller without the payment.request action.');
		} catch (RuntimeException $e) {
			self::assertStringContainsString('403', $e->getMessage());
		}

		self::assertSame([], $this->saved);
	}//end testCallerWithoutTheActionIsRefusedAndNothingIsWritten()

	/**
	 * With no session there is no caller to check, so the leaf fails closed.
	 *
	 * @return void
	 */
	public function testAnonymousCallerIsRefused(): void {
		$provider = $this->makeProvider(actionGroups: ['payment.request' => ['finance']], signedIn: false);

		$this->expectException(RuntimeException::class);

		$provider->create('dossiq', 'Zaak', 'zaak-7', ['requestType' => 'dwangsom', 'amount' => 250.0]);
	}//end testAnonymousCallerIsRefused()

	/**
	 * A caller in a mapped group appends one pending request carrying the host
	 * object as its subject, with the caller recorded (REQ-SOPR-003).
	 *
	 * @return void
	 */
	public function testMappedCallerAppendsAPendingRequestOnTheHostObject(): void {
		$provider = $this->makeProvider(actionGroups: ['payment.request' => ['finance']], groups: ['finance']);

		$created = $provider->create(
			'dossiq',
			'Zaak',
			'zaak-7',
			['requestType' => 'dwangsom', 'amount' => 250.0, 'subjectType' => 'case', 'description' => 'Dwangsom zaak 7']
		);

		self::assertSame('pending', $created['state']);
		self::assertSame('object', $created['subjectKind']);
		self::assertSame(
			['type' => 'case', 'register' => 'dossiq', 'schema' => 'Zaak', 'id' => 'zaak-7'],
			$created['subject']
		);
		self::assertSame('handler', $created['requestedBy']);
		self::assertCount(1, $this->saved);
	}//end testMappedCallerAppendsAPendingRequestOnTheHostObject()

	/**
	 * Every key the leaf writes is a declared PaymentRequest property, the
	 * requester included; before PaymentRequest 0.6.0 OpenRegister dropped
	 * `requestedBy` (REQ-SOPR-009).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/billing-inherited-defects/specs/object-payment-requests/spec.md (REQ-SOPR-009)
	 */
	public function testEveryWrittenKeyIsADeclaredPaymentRequestProperty(): void {
		$provider = $this->makeProvider(actionGroups: ['payment.request' => ['finance']], groups: ['finance']);

		$created = $provider->create(
			'dossiq',
			'Zaak',
			'zaak-7',
			['requestType' => 'dwangsom', 'amount' => 250.0, 'subjectType' => 'case', 'description' => 'Dwangsom zaak 7']
		);

		$declared = EffectiveRegisterFixture::properties(schema: 'PaymentRequest');
		self::assertSame([], array_values(array_diff(array_keys($created), $declared, ['id'])), 'Undeclared PaymentRequest keys');
		self::assertSame('handler', $created['requestedBy']);
	}//end testEveryWrittenKeyIsADeclaredPaymentRequestProperty()

	/**
	 * The leaf keeps the caller's transfer reference and invoice flag, the
	 * request it writes fits the real PaymentRequest schema, and a reference
	 * an open request elsewhere already carries is refused (REQ-ORS-002).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-002)
	 */
	public function testCreateKeepsReferenceAndInvoiceFlag(): void {
		$provider = $this->makeProvider(actionGroups: ['payment.request' => ['finance']], groups: ['finance']);

		$created = $provider->create(
			'larpinq',
			'Registration',
			'reg-42',
			[
				'requestType' => 'event-fee',
				'amount' => 85.0,
				'subjectType' => 'registration',
				'description' => 'WC26-0042 Winter Camp 2026',
				'paymentReference' => ' WC26-0042 ',
				'invoiceRequested' => false,
				'debtor' => ['name' => 'Anna Jansen', 'email' => 'anna@example.nl'],
			]
		);

		self::assertSame('WC26-0042', $created['paymentReference']);
		self::assertFalse($created['invoiceRequested']);
		self::assertSame([], RegisterSchema::errors('PaymentRequest', $created));
		$declared = EffectiveRegisterFixture::properties(schema: 'PaymentRequest');
		self::assertSame([], array_values(array_diff(array_keys($created), $declared, ['id'])), 'Undeclared PaymentRequest keys');

		$held = array_merge($this->storedRequest('pr-9', 'zaak-9'), ['paymentReference' => 'wc26-0042']);
		$this->saved = [];
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('already on an open request');
		try {
			$this->makeProvider(stored: [$held], actionGroups: ['payment.request' => ['finance']], groups: ['finance'])->create(
				'larpinq',
				'Registration',
				'reg-43',
				['requestType' => 'event-fee', 'amount' => 85.0, 'paymentReference' => 'WC26-0042']
			);
		} finally {
			self::assertSame([], $this->saved, 'A refused reference wrote a request');
		}
	}//end testCreateKeepsReferenceAndInvoiceFlag()

	/**
	 * A request raised through the leaf for a known customer carries that
	 * customer as its portal scope; one for a name and an email does not
	 * (REQ-SPPI-008).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/arinvoice-lines-and-portal-amounts/specs/portal-payment-initiation/spec.md (REQ-SPPI-008)
	 */
	public function testARequestForACustomerCarriesItsPortalScope(): void {
		$provider = $this->makeProvider(actionGroups: ['payment.request' => ['finance']], groups: ['finance']);

		$created = $provider->create(
			'dossiq',
			'Zaak',
			'zaak-8',
			['requestType' => 'leges', 'amount' => 125.0, 'debtor' => ['customerMasterId' => '20000000-0000-4000-8000-000000000002']]
		);
		self::assertSame('20000000-0000-4000-8000-000000000002', $created['customerId']);

		$byMail = $provider->create(
			'dossiq',
			'Zaak',
			'zaak-9',
			['requestType' => 'leges', 'amount' => 125.0, 'debtor' => ['name' => 'J. Jansen', 'email' => 'j.jansen@example.nl']]
		);
		self::assertArrayNotHasKey('customerId', $byMail);
	}//end testARequestForACustomerCarriesItsPortalScope()

	/**
	 * An administrator carries every action without a mapping, which is what keeps
	 * a fresh install usable.
	 *
	 * @return void
	 */
	public function testAdministratorMayRaiseARequestWithoutAMapping(): void {
		$provider = $this->makeProvider(isAdmin: true);

		$provider->create('dossiq', 'Zaak', 'zaak-7', ['requestType' => 'leges', 'amount' => 45.0]);

		self::assertCount(1, $this->saved);
	}//end testAdministratorMayRaiseARequestWithoutAMapping()

	/**
	 * The uniqueness invariant reaches the leaf: a second pending dwangsom on the
	 * same case is refused before anything is written (REQ-SOPR-001).
	 *
	 * @return void
	 */
	public function testSecondPendingRequestOfTheSameTypeIsRefusedThroughTheLeaf(): void {
		$provider = $this->makeProvider([$this->storedRequest('pr-1', 'zaak-7')], isAdmin: true);

		try {
			$provider->create('dossiq', 'Zaak', 'zaak-7', ['requestType' => 'dwangsom', 'amount' => 250.0]);
			self::fail('The provider appended a second pending dwangsom on the same case.');
		} catch (InvalidArgumentException $e) {
			self::assertStringContainsString('pr-1', $e->getMessage());
		}

		self::assertSame([], $this->saved);
	}//end testSecondPendingRequestOfTheSameTypeIsRefusedThroughTheLeaf()

	/**
	 * The leaf appends and reads; it never rewrites or deletes payment evidence
	 * (ADR-066 decision 2).
	 *
	 * @return void
	 */
	public function testTheLeafRefusesToUpdateOrDelete(): void {
		$provider = $this->makeProvider(isAdmin: true);

		$this->expectException(RuntimeException::class);

		$provider->update('dossiq', 'Zaak', 'zaak-7', 'pr-1', ['state' => 'captured']);
	}//end testTheLeafRefusesToUpdateOrDelete()

	/**
	 * `get` cannot reach a request standing on another object, even by id.
	 *
	 * @return void
	 */
	public function testGetCannotReachARequestOnAnotherObject(): void {
		$provider = $this->makeProvider([$this->storedRequest('pr-2', 'zaak-9')]);

		$this->expectException(RuntimeException::class);

		$provider->get('dossiq', 'Zaak', 'zaak-7', 'pr-2');
	}//end testGetCannotReachARequestOnAnotherObject()

	/**
	 * A fee item carrying 250 contribution requests, behind 10 on another
	 * subject, lists all 250 with the fields the owning app reads. One page of
	 * 200 was read before, so 50 children's payments read as absent
	 * (REQ-SCON-004).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-004)
	 */
	public function testListReadsEveryPageOfRequests(): void {
		$rows = [];
		for ($i = 0; $i < 10; $i++) {
			$rows[] = $this->storedRequest('pr-case-' . $i, 'zaak-' . $i);
		}

		for ($i = 0; $i < 250; $i++) {
			$rows[] = [
				'id' => 'pr-ctb-' . $i,
				'subjectKind' => 'object',
				'subject' => ['app' => 'learniq', 'type' => 'fee-item', 'register' => 'learniq', 'schema' => 'FeeItem', 'id' => 'fee-1'],
				'beneficiary' => ['type' => 'learner', 'id' => 'child-' . $i],
				'requestType' => 'contribution',
				'invoiceReference' => 'inv-' . $i,
				'voluntary' => true,
				'amount' => 60.0,
				'state' => ($i === 0 ? 'captured' : 'pending'),
				'settledAt' => ($i === 0 ? '2026-10-03T09:12:00Z' : null),
				'settledVia' => ($i === 0 ? 'provider' : null),
			];
		}

		$paging = new class($rows) {
			/**
			 * @param array<int, array<string, mixed>> $rows Stored rows.
			 */
			public function __construct(private array $rows) {
			}

			public function setRegister(string $register): static {
				return $this;
			}

			public function setSchema(string $schema): static {
				return $this;
			}

			/**
			 * @param array<string, mixed> $params Query params, with limit and offset honoured.
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $params = []): array {
				return array_slice($this->rows, (int)($params['offset'] ?? 0), (int)($params['limit'] ?? 20));
			}
		};

		$listed = $this->makeProvider(inner: $paging)->list('learniq', 'FeeItem', 'fee-1');

		self::assertSame(250, $listed['total']);
		self::assertCount(250, $listed['items']);
		$first = $listed['items'][0];
		self::assertSame('child-0', $first['beneficiary']['id']);
		self::assertSame('inv-0', $first['invoiceReference']);
		self::assertTrue($first['voluntary']);
		self::assertSame('2026-10-03T09:12:00Z', $first['settledAt']);
		self::assertSame('provider', $first['settledVia']);
		self::assertSame('', $listed['items'][249]['settledAt']);
	}//end testListReadsEveryPageOfRequests()

	/**
	 * Build the provider with app grants, for the createAsApp path (REQ-SOPR-010).
	 *
	 * @param array<string, array<int, string>> $appGrants The configured paymentActionApps matrix.
	 * @param array<int, string> $enabledApps The apps that are enabled.
	 * @param array<int, array<string, mixed>> $stored Stored PaymentRequest rows.
	 * @param bool $signedIn Whether anyone is signed in.
	 * @param bool $isAdmin Whether the signed-in user is an administrator.
	 *
	 * @return PaymentRequestLeafProvider The provider.
	 */
	private function makeAppProvider(
		array $appGrants,
		array $enabledApps,
		array $stored = [],
		bool $signedIn = false,
		bool $isAdmin = false,
	): PaymentRequestLeafProvider {
		return $this->makeProvider(
			stored: $stored,
			isAdmin: $isAdmin,
			signedIn: $signedIn,
			appGrants: $appGrants,
			enabledApps: $enabledApps,
		);
	}//end makeAppProvider()

	/**
	 * Larpinq's daily job moves a waitlisted registration up with nobody signed
	 * in. An app granted payment.request raises the fee: one pending request on
	 * the registration, requested by the app, written as the system because
	 * there is no user OpenRegister could check (REQ-SOPR-010).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payment-request-app-caller/specs/object-payment-requests/spec.md (REQ-SOPR-010)
	 */
	public function testAGrantedAppRaisesARequestWithNobodySignedIn(): void {
		$provider = $this->makeAppProvider(appGrants: ['payment.request' => ['larpinq']], enabledApps: ['larpinq']);

		$created = $provider->createAsApp(
			'larpinq',
			'larpinq',
			'Registration',
			'reg-42',
			['requestType' => 'other', 'amount' => 45.0, 'description' => 'WC26-0042 event fee', 'subjectType' => 'registration']
		);

		self::assertSame('pending', $created['state']);
		self::assertSame(
			['type' => 'registration', 'register' => 'larpinq', 'schema' => 'Registration', 'id' => 'reg-42'],
			$created['subject']
		);
		self::assertSame('app:larpinq', $created['requestedBy']);
		self::assertCount(1, $this->saved);
		self::assertSame([false], $this->savedWithRbac, 'An app request with no user is written as the system.');

		$declared = EffectiveRegisterFixture::properties(schema: 'PaymentRequest');
		self::assertSame([], array_values(array_diff(array_keys($created), $declared, ['id'])), 'Undeclared PaymentRequest keys');
	}//end testAGrantedAppRaisesARequestWithNobodySignedIn()

	/**
	 * The callers that must be refused: an app the mapping does not name, an
	 * app named only for another action, a granted app that is not enabled, and
	 * ids that are no app at all. Each answers 403 and writes nothing
	 * (REQ-SOPR-010).
	 *
	 * @return array<string, array{0: string, 1: array<string, array<int, string>>, 2: array<int, string>}>
	 */
	public static function refusedAppCallers(): array {
		return [
			'an app without the grant' => ['larpinq', ['payment.request' => ['dossiq']], ['larpinq', 'dossiq']],
			'an app granted another action' => ['larpinq', ['payment.administer' => ['larpinq']], ['larpinq']],
			'no mapping at all' => ['larpinq', [], ['larpinq']],
			'a granted app that is not enabled' => ['larpinq', ['payment.request' => ['larpinq']], []],
			'an unknown app' => ['ghostapp', ['payment.request' => ['larpinq']], ['larpinq']],
			'an empty id' => ['', ['payment.request' => ['']], ['']],
			'a user-shaped id' => ['app:larpinq', ['payment.request' => ['app:larpinq']], ['app:larpinq']],
		];
	}//end refusedAppCallers()

	/**
	 * See refusedAppCallers().
	 *
	 * @param string $appId The calling app.
	 * @param array<string, array<int, string>> $appGrants The configured grants.
	 * @param array<int, string> $enabledApps The enabled apps.
	 *
	 * @return void
	 *
	 * @dataProvider refusedAppCallers
	 *
	 * @spec openspec/changes/payment-request-app-caller/specs/object-payment-requests/spec.md (REQ-SOPR-010)
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('refusedAppCallers')]
	public function testAnAppThatIsNotGrantedIsRefusedAndNothingIsWritten(string $appId, array $appGrants, array $enabledApps): void {
		$provider = $this->makeAppProvider(appGrants: $appGrants, enabledApps: $enabledApps);

		try {
			$provider->createAsApp($appId, 'larpinq', 'Registration', 'reg-42', ['requestType' => 'other', 'amount' => 45.0]);
			self::fail('The leaf raised a request for an app that is not granted payment.request.');
		} catch (RuntimeException $e) {
			self::assertStringStartsWith('403', $e->getMessage());
		}

		self::assertSame([], $this->saved);
	}//end testAnAppThatIsNotGrantedIsRefusedAndNothingIsWritten()

	/**
	 * An administrator carries every USER action; that does not reach the app
	 * path. A signed-in administrator does not open createAsApp for an app
	 * that is not granted (REQ-SOPR-010).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payment-request-app-caller/specs/object-payment-requests/spec.md (REQ-SOPR-010)
	 */
	public function testASignedInAdministratorDoesNotGrantTheApp(): void {
		$provider = $this->makeAppProvider(appGrants: [], enabledApps: ['larpinq'], signedIn: true, isAdmin: true);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('403');

		$provider->createAsApp('larpinq', 'larpinq', 'Registration', 'reg-42', ['requestType' => 'other', 'amount' => 45.0]);
	}//end testASignedInAdministratorDoesNotGrantTheApp()

	/**
	 * The HTTP route reaches create(), whose payload is the request body. A body
	 * naming a granted app must not open it: an anonymous caller is still
	 * refused, and a signed-in clerk is still the requester (REQ-SOPR-010).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payment-request-app-caller/specs/object-payment-requests/spec.md (REQ-SOPR-010)
	 */
	public function testAPayloadCannotClaimAnApp(): void {
		$claim = ['requestType' => 'other', 'amount' => 45.0, 'callerApp' => 'larpinq', 'appId' => 'larpinq', 'requestedBy' => 'app:larpinq'];

		$anonymous = $this->makeAppProvider(appGrants: ['payment.request' => ['larpinq']], enabledApps: ['larpinq']);
		try {
			$anonymous->create('larpinq', 'Registration', 'reg-42', $claim);
			self::fail('An anonymous create naming a granted app was accepted.');
		} catch (RuntimeException $e) {
			self::assertStringContainsString('403', $e->getMessage());
		}

		self::assertSame([], $this->saved);

		$clerk = $this->makeProvider(
			actionGroups: ['payment.request' => ['finance']],
			groups: ['finance'],
			appGrants: ['payment.request' => ['larpinq']],
			enabledApps: ['larpinq'],
		);
		$created = $clerk->create('larpinq', 'Registration', 'reg-42', $claim);
		self::assertSame('handler', $created['requestedBy']);
		self::assertSame([true], $this->savedWithRbac, 'A user request is written under the user\'s own rights.');
	}//end testAPayloadCannotClaimAnApp()

	/**
	 * The app path keeps the leaf's invariant: a second open request of the
	 * same type on the same registration is refused, read as the system so an
	 * existing request is not missed for want of a user (REQ-SOPR-010).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/payment-request-app-caller/specs/object-payment-requests/spec.md (REQ-SOPR-010)
	 */
	public function testAGrantedAppIsHeldToOneOpenRequestPerType(): void {
		$existing = [
			'id' => 'pr-1',
			'subjectKind' => 'object',
			'subject' => ['type' => 'registration', 'register' => 'larpinq', 'schema' => 'Registration', 'id' => 'reg-42'],
			'requestType' => 'other',
			'amount' => 45.0,
			'currency' => 'EUR',
			'state' => 'pending',
		];
		$provider = $this->makeAppProvider(appGrants: ['payment.request' => ['larpinq']], enabledApps: ['larpinq'], stored: [$existing]);

		try {
			$provider->createAsApp('larpinq', 'larpinq', 'Registration', 'reg-42', ['requestType' => 'other', 'amount' => 45.0]);
			self::fail('A second open request of the same type was raised.');
		} catch (InvalidArgumentException $e) {
			self::assertNotSame('', $e->getMessage());
		}

		self::assertSame([], $this->saved);
		self::assertContains(false, $this->foundWithRbac, 'The existing requests are read as the system.');
	}//end testAGrantedAppIsHeldToOneOpenRequestPerType()

	/**
	 * The leaf with the real credit service over one in-memory store (REQ-ORC-004).
	 *
	 * @param array<int, array<string, mixed>> $credits Stored DebtorCredit rows.
	 * @param array<int, array{0: string, 1: string}> $runs Receives every journal transition run.
	 * @param string $creditAccount The paymentCreditAccount setting.
	 *
	 * @return array{0: PaymentRequestLeafProvider, 1: InMemoryObjectServiceStub} The provider and its store.
	 */
	private function leafWithCredit(array $credits, array &$runs, string $creditAccount = '1850'): array {
		$store = new InMemoryObjectServiceStub(data: ['DebtorCredit' => $credits]);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($creditAccount): string {
				return match ($key) {
					'register' => 'shillinq',
					DebtorCreditService::CONFIG_KEY => $creditAccount,
					PaymentRevenueAccountResolver::CONFIG_KEY => '{"event-fee":"8050"}',
					default => $default,
				};
			}
		);

		$transitions = $this->createMock(ObjectTransitionRunner::class);
		$transitions->method('run')->willReturnCallback(
			static function (string $objectId, string $action) use (&$runs): void {
				$runs[] = [$objectId, $action];
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('handler');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);

		$leaf = new PaymentRequestLeafProvider(
			objectService: $store,
			validator: new ObjectPaymentRequestValidator(),
			appConfig: $appConfig,
			authorizer: new PaymentActionAuthorizer(appConfig: $appConfig, userSession: $session, groupManager: $groups),
			feeSchedules: new FeeScheduleService(objectService: $store, appConfig: $appConfig, logger: $this->createMock(LoggerInterface::class)),
			settlements: new PaymentSettlementService(),
			appGrant: new PaymentActionAppGrant(appConfig: $appConfig, appManager: $this->createMock(IAppManager::class)),
			credits: new DebtorCreditService(
				objectService: $store,
				appConfig: $appConfig,
				transitions: $transitions,
				revenueAccounts: new PaymentRevenueAccountResolver(appConfig: $appConfig),
			),
		);

		return [$leaf, $store];
	}//end leafWithCredit()

	/**
	 * One open credit row for Joris.
	 *
	 * @param string $id The credit id.
	 * @param float $remaining What is left of it.
	 * @param string $created When OpenRegister created it.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function openCredit(string $id, float $remaining, string $created): array {
		return [
			'id' => $id,
			'debtorKey' => 'joris@example.nl',
			'amount' => $remaining,
			'remaining' => $remaining,
			'currency' => 'EUR',
			'sourcePaymentRequestId' => 'pr-old-' . $id,
			'administrationId' => 'adm-larp-1',
			'state' => 'open',
			'@self' => ['created' => $created],
		];
	}//end openCredit()

	/**
	 * A new request for Joris, as larpinq sends it.
	 *
	 * @param float $amount The amount.
	 *
	 * @return array<string, mixed> The leaf payload.
	 */
	private function jorisRequest(float $amount): array {
		return [
			'requestType' => 'event-fee',
			'amount' => $amount,
			'subjectType' => 'registration',
			'description' => 'Spring event 2027',
			'administrationId' => 'adm-larp-1',
			'debtor' => ['name' => 'Joris Bakker', 'email' => 'Joris@Example.nl'],
		];
	}//end jorisRequest()

	/**
	 * The last saved version of each object of a schema, by id.
	 *
	 * @param InMemoryObjectServiceStub $store The store.
	 * @param string $schema The schema.
	 *
	 * @return array<string, array<string, mixed>> Id => object.
	 */
	private function lastSaved(InMemoryObjectServiceStub $store, string $schema): array {
		$byId = [];
		foreach ($store->saved as $save) {
			if ($save['schema'] === $schema) {
				$byId[(string)($save['object']['id'] ?? count($byId))] = $save['object'];
			}
		}

		return $byId;
	}//end lastSaved()

	/**
	 * Joris's 85.00 credit pays a 60.00 request whole, and 25.00 stays open (REQ-ORC-004).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-004)
	 */
	public function testCreditCoveringTheWholeAmountSettlesAtOnce(): void {
		$runs = [];
		[$leaf, $store] = $this->leafWithCredit(credits: [$this->openCredit(id: 'dc-1', remaining: 85.0, created: '2026-09-01T10:00:00Z')], runs: $runs);

		$created = $leaf->create('larpinq', 'Registration', 'reg-77', $this->jorisRequest(amount: 60.0));

		self::assertSame('credit', $created['settledVia'] ?? null);
		self::assertNotSame('', (string)($created['settledAt'] ?? ''));
		self::assertCount(1, $created['settlements']);
		self::assertSame('credit', $created['settlements'][0]['method']);
		self::assertSame(60.0, $created['settlements'][0]['amount']);
		self::assertSame('paid', (new PaymentSettlementService())->report($created)['state']);

		$credit = $this->lastSaved(store: $store, schema: 'DebtorCredit')['dc-1'];
		self::assertSame(25.0, $credit['remaining']);
		self::assertSame('open', $credit['state']);

		$journals = array_values($this->lastSaved(store: $store, schema: 'JournalEntry'));
		self::assertCount(1, $journals);
		self::assertSame(['1850', 'debit', 60.0], [$journals[0]['lines'][0]['accountNumber'], $journals[0]['lines'][0]['side'], $journals[0]['lines'][0]['amount']]);
		self::assertSame(['8050', 'credit', 60.0], [$journals[0]['lines'][1]['accountNumber'], $journals[0]['lines'][1]['side'], $journals[0]['lines'][1]['amount']]);
		self::assertSame([[$journals[0]['id'], 'postDirect']], $runs);

		foreach (['PaymentRequest' => $created, 'DebtorCredit' => $credit, 'JournalEntry' => $journals[0]] as $schema => $object) {
			unset($object['id']);
			self::assertSame([], RegisterSchema::errors(slug: $schema, object: $object), $schema . ' payload refused by the merged register');
		}
	}//end testCreditCoveringTheWholeAmountSettlesAtOnce()

	/**
	 * Credit pays the next request first, oldest credit first, and what it cannot cover stays due.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-004)
	 */
	public function testCreditPaysTheNextRequestFirst(): void {
		$runs = [];
		[$leaf, $store] = $this->leafWithCredit(
			credits: [
				$this->openCredit(id: 'dc-new', remaining: 30.0, created: '2026-09-20T10:00:00Z'),
				$this->openCredit(id: 'dc-old', remaining: 20.0, created: '2026-08-01T10:00:00Z'),
			],
			runs: $runs
		);

		$created = $leaf->create('larpinq', 'Registration', 'reg-78', $this->jorisRequest(amount: 100.0));

		$credits = $this->lastSaved(store: $store, schema: 'DebtorCredit');
		self::assertSame(0.0, $credits['dc-old']['remaining']);
		self::assertSame('used', $credits['dc-old']['state']);
		self::assertSame(0.0, $credits['dc-new']['remaining']);
		self::assertSame('used', $credits['dc-new']['state']);
		self::assertArrayNotHasKey('@self', $credits['dc-old'], 'OpenRegister metadata is not written back');

		self::assertSame(50.0, array_sum(array_column($created['settlements'], 'amount')));
		self::assertSame('partly-paid', (new PaymentSettlementService())->report($created)['state']);
		self::assertSame('', (string)($created['settledAt'] ?? ''), 'a request credit does not cover whole is not settled');
		self::assertCount(2, $runs);
		self::assertSame([], RegisterSchema::errors(slug: 'PaymentRequest', object: $created));
	}//end testCreditPaysTheNextRequestFirst()

	/**
	 * Another debtor's credit, or credit in another administration, is not touched.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-004)
	 */
	public function testOnlyTheSameDebtorsCreditInTheSameAdministrationIsUsed(): void {
		$runs = [];
		$other = array_merge($this->openCredit(id: 'dc-2', remaining: 50.0, created: '2026-08-01T10:00:00Z'), ['debtorKey' => 'mila@example.nl']);
		$elsewhere = array_merge($this->openCredit(id: 'dc-3', remaining: 50.0, created: '2026-08-01T10:00:00Z'), ['administrationId' => 'adm-other']);
		$used = array_merge($this->openCredit(id: 'dc-4', remaining: 0.0, created: '2026-08-01T10:00:00Z'), ['state' => 'used']);
		[$leaf, $store] = $this->leafWithCredit(credits: [$other, $elsewhere, $used], runs: $runs);

		$created = $leaf->create('larpinq', 'Registration', 'reg-79', $this->jorisRequest(amount: 60.0));

		self::assertArrayNotHasKey('settlements', $created);
		self::assertSame([], $this->lastSaved(store: $store, schema: 'DebtorCredit'));
		self::assertSame([], $runs);
	}//end testOnlyTheSameDebtorsCreditInTheSameAdministrationIsUsed()

	/**
	 * Without a customer credit account the credit stays open and the request stays payable.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-004)
	 */
	public function testCreditStaysOpenWhenItCannotBeBooked(): void {
		$runs = [];
		[$leaf, $store] = $this->leafWithCredit(credits: [$this->openCredit(id: 'dc-1', remaining: 85.0, created: '2026-09-01T10:00:00Z')], runs: $runs, creditAccount: '');

		$created = $leaf->create('larpinq', 'Registration', 'reg-80', $this->jorisRequest(amount: 60.0));

		self::assertArrayNotHasKey('settlements', $created);
		self::assertSame([], $this->lastSaved(store: $store, schema: 'DebtorCredit'));
		self::assertSame([], $runs);
	}//end testCreditStaysOpenWhenItCannotBeBooked()
}//end class
