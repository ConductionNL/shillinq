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
use OCA\Shillinq\Service\ObjectPaymentRequestValidator;
use OCA\Shillinq\Service\PaymentActionAuthorizer;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
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
	 * Build an object-service double backed by the given stored requests.
	 *
	 * @param array<int, array<string, mixed>> $stored Stored PaymentRequest rows.
	 *
	 * @return object The double.
	 */
	private function objectServiceDouble(array $stored): object {
		return new class($stored, $this->saved) {
			/**
			 * @param array<int, array<string, mixed>> $stored Stored rows.
			 * @param array<int, array<string, mixed>> $saved Sink.
			 */
			public function __construct(
				private array $stored,
				private array &$saved,
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
			public function findAll(array $params = []): array {
				return $this->stored;
			}

			/**
			 * @param array<string, mixed> $object Object to persist.
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register = '', string $schema = ''): array {
				$this->saved[] = $object;
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
	 *
	 * @return PaymentRequestLeafProvider The provider.
	 */
	private function makeProvider(
		array $stored = [],
		bool $isAdmin = false,
		array $groups = [],
		array $actionGroups = [],
		bool $signedIn = true,
	): PaymentRequestLeafProvider {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($actionGroups): string {
				if ($key === PaymentActionAuthorizer::CONFIG_ACTION_GROUPS) {
					return json_encode($actionGroups, JSON_THROW_ON_ERROR);
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

		return new PaymentRequestLeafProvider(
			objectService: new DuckObjectServiceAdapter(inner: $this->objectServiceDouble($stored)),
			validator: new ObjectPaymentRequestValidator(),
			appConfig: $appConfig,
			authorizer: new PaymentActionAuthorizer(
				appConfig: $appConfig,
				userSession: $session,
				groupManager: $groupManager,
			),
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

		$rows = $provider->list('dossiq', 'Zaak', 'zaak-7');

		self::assertCount(1, $rows);
		self::assertSame('pr-1', $rows[0]['id']);
		self::assertSame('https://pay.example/pr-1', $rows[0]['paymentLink']);
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
}//end class
