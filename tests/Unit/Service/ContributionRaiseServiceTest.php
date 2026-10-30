<?php

/**
 * Unit tests for ContributionRaiseService.
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
 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-001)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Shillinq\Service\ContributionDebtorResolver;
use OCA\Shillinq\Service\ContributionInvoiceBuilder;
use OCA\Shillinq\Service\ContributionRaiseService;
use OCA\Shillinq\Service\ObjectPaymentRequestValidator;
use OCA\Shillinq\Service\PaymentActionAuthorizer;
use OCA\Shillinq\Service\PaymentRequestFinder;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the bulk raise end to end over one in-memory store: the invoices and
 * requests it writes, its idempotency, its failure isolation and its refusals.
 */
final class ContributionRaiseServiceTest extends TestCase {
	/**
	 * The store the raise reads and writes.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * Build the service over a store seeded with the given rows.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $data Schema => rows.
	 * @param bool $allowed Whether the caller carries payment.request.
	 *
	 * @return ContributionRaiseService The service.
	 */
	private function service(array $data = [], bool $allowed = true): ContributionRaiseService {
		$this->store = new InMemoryObjectServiceStub(data: $data, findAllRendersEntities: true);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'register' ? 'shillinq' : $default)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('coordinator');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($allowed);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-09-27'));

		$validator = new ObjectPaymentRequestValidator();

		return new ContributionRaiseService(
			authorizer: new PaymentActionAuthorizer(appConfig: $appConfig, userSession: $session, groupManager: $groups),
			builder: new ContributionInvoiceBuilder(l10nFactory: $factory),
			debtors: new ContributionDebtorResolver(
				objectService: $this->store,
				dispatcher: $this->createMock(IEventDispatcher::class),
				appConfig: $appConfig,
				logger: $this->createMock(LoggerInterface::class),
			),
			validator: $validator,
			finder: new PaymentRequestFinder(objectService: $this->store, validator: $validator, appConfig: $appConfig),
			objectService: $this->store,
			appConfig: $appConfig,
			timeFactory: $time,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end service()

	/**
	 * Three guardians with a customer each.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The seed.
	 */
	private function customers(): array {
		return [
			'CustomerMaster' => [
				['id' => 'cm-1', 'customerId' => 'G-1', 'email' => 'een@example.nl', 'administrationId' => 'adm-school-1'],
				['id' => 'cm-2', 'customerId' => 'G-2', 'email' => 'twee@example.nl', 'administrationId' => 'adm-school-1'],
				['id' => 'cm-3', 'customerId' => 'G-3', 'email' => 'drie@example.nl', 'administrationId' => 'adm-school-1'],
			],
		];
	}//end customers()

	/**
	 * A raise call for the ouderbijdrage.
	 *
	 * @param array<int, array<string, mixed>> $recipients The recipients.
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The call.
	 */
	private function call(array $recipients, array $overrides = []): array {
		return array_merge(
			[
				'chargeable' => ['app' => 'learniq', 'type' => 'fee-item', 'register' => 'learniq', 'schema' => 'FeeItem', 'id' => 'fee-1'],
				'kind' => 'parental-contribution',
				'description' => 'Ouderbijdrage 2026-2027',
				'amount' => 60,
				'voluntary' => true,
				'administrationId' => 'adm-school-1',
				'recipients' => $recipients,
			],
			$overrides
		);
	}//end call()

	/**
	 * One recipient: a customer and a child.
	 *
	 * @param string $customer The customer id.
	 * @param string $child The child id.
	 *
	 * @return array<string, mixed> The recipient.
	 */
	private function recipient(string $customer, string $child): array {
		return [
			'debtor' => ['customerMasterId' => $customer],
			'beneficiary' => ['type' => 'learner', 'register' => 'learniq', 'schema' => 'LearnerProfile', 'id' => $child],
		];
	}//end recipient()

	/**
	 * The saves the store recorded for one schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return array<int, array<string, mixed>> The saved objects.
	 */
	private function savedIn(string $schema): array {
		return array_values(
			array_map(
				static fn (array $save): array => $save['object'],
				array_filter($this->store->saved, static fn (array $save): bool => $save['schema'] === $schema)
			)
		);
	}//end savedIn()

	/**
	 * Three guardians become three issued invoices and three requests that
	 * stand on the fee item and on their own invoice (REQ-SCON-001).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-001)
	 */
	public function testRaisesOneInvoiceAndOneRequestPerRecipient(): void {
		$result = $this->service($this->customers())->raise(
			$this->call(
				[
					$this->recipient('cm-1', 'child-a'),
					$this->recipient('cm-2', 'child-b'),
					$this->recipient('cm-3', 'child-c'),
				]
			)
		);

		self::assertSame(3, $result['raised']);
		self::assertSame(0, $result['skipped']);
		self::assertSame(0, $result['failed']);
		self::assertMatchesRegularExpression('/^ctb-20260927-[0-9a-f]{8}$/', $result['batchId']);

		$invoices = $this->savedIn('ARInvoice');
		$requests = $this->savedIn('PaymentRequest');
		self::assertCount(3, $invoices);
		self::assertCount(3, $requests);

		foreach ($result['results'] as $i => $row) {
			self::assertSame('raised', $row['status']);
			self::assertSame($i, $row['index']);
			self::assertSame($invoices[$i]['id'], $row['invoiceId']);
			self::assertSame($requests[$i]['id'], $row['paymentRequestId']);
			self::assertSame('issued', $invoices[$i]['lifecycleState']);
			self::assertSame('fee-1', $invoices[$i]['contribution']['chargeable']['id']);
			self::assertSame($result['batchId'], $invoices[$i]['contribution']['raiseBatchId']);
			self::assertSame('object', $requests[$i]['subjectKind']);
			self::assertSame('contribution', $requests[$i]['requestType']);
			self::assertSame($invoices[$i]['id'], $requests[$i]['invoiceReference']);
			self::assertSame($row['customerMasterId'], $invoices[$i]['customerId']);
		}

		self::assertSame('child-b', $requests[1]['beneficiary']['id']);
		self::assertStringEndsWith('-0003', $invoices[2]['invoiceNumber']);
	}//end testRaisesOneInvoiceAndOneRequestPerRecipient()

	/**
	 * A child already billed on this fee item is skipped, naming the standing
	 * request; a duplicate inside the call is skipped too; a voided request
	 * does not count (REQ-SCON-003).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-003)
	 */
	public function testSkipsARecipientWhoseChildIsAlreadyBilled(): void {
		$standing = static fn (string $id, string $child, string $state): array => [
			'id' => $id,
			'subjectKind' => 'object',
			'subject' => ['app' => 'learniq', 'type' => 'fee-item', 'register' => 'learniq', 'schema' => 'FeeItem', 'id' => 'fee-1'],
			'requestType' => 'contribution',
			'beneficiary' => ['type' => 'learner', 'register' => 'learniq', 'schema' => 'LearnerProfile', 'id' => $child],
			'amount' => 60.0,
			'state' => $state,
		];
		$seed = $this->customers() + [
			'PaymentRequest' => [
				$standing('pr-old-a', 'child-a', 'captured'),
				$standing('pr-old-c', 'child-c', 'voided'),
			],
		];

		$result = $this->service($seed)->raise(
			$this->call(
				[
					$this->recipient('cm-1', 'child-a'),
					$this->recipient('cm-2', 'child-b'),
					$this->recipient('cm-2', 'child-b'),
					$this->recipient('cm-3', 'child-c'),
				]
			)
		);

		self::assertSame(['skipped', 'raised', 'skipped', 'raised'], array_column($result['results'], 'status'));
		self::assertSame('already-raised', $result['results'][0]['reason']);
		self::assertSame('pr-old-a', $result['results'][0]['paymentRequestId']);
		self::assertSame($result['results'][1]['paymentRequestId'], $result['results'][2]['paymentRequestId']);
		self::assertCount(2, $this->savedIn('ARInvoice'));
	}//end testSkipsARecipientWhoseChildIsAlreadyBilled()

	/**
	 * The same call twice writes nothing the second time (REQ-SCON-003).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-003)
	 */
	public function testARepeatedCallBillsNobodyTwice(): void {
		$service = $this->service($this->customers());
		$call = $this->call([$this->recipient('cm-1', 'child-a'), $this->recipient('cm-2', 'child-b')]);

		$first = $service->raise($call);
		$savesAfterFirst = count($this->store->saved);
		$second = $service->raise($call);

		self::assertSame(2, $first['raised']);
		self::assertSame(0, $second['raised']);
		self::assertSame(2, $second['skipped']);
		self::assertSame($savesAfterFirst, count($this->store->saved));
		self::assertSame($first['results'][0]['paymentRequestId'], $second['results'][0]['paymentRequestId']);
	}//end testARepeatedCallBillsNobodyTwice()

	/**
	 * A school billing per household passes no child: the customer becomes the
	 * beneficiary, and one household is billed once.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-003)
	 */
	public function testAHouseholdIsBilledOnceWithoutAChild(): void {
		$result = $this->service($this->customers())->raise(
			$this->call(
				[
					['debtor' => ['customerMasterId' => 'cm-1']],
					['debtor' => ['customerMasterId' => 'cm-1']],
					['debtor' => ['customerMasterId' => 'cm-2']],
				]
			)
		);

		self::assertSame(['raised', 'skipped', 'raised'], array_column($result['results'], 'status'));
		self::assertSame(
			['type' => 'customer', 'register' => 'shillinq', 'schema' => 'CustomerMaster', 'id' => 'cm-1'],
			$this->savedIn('PaymentRequest')[0]['beneficiary']
		);
	}//end testAHouseholdIsBilledOnceWithoutAChild()

	/**
	 * A recipient naming a customer that does not exist fails by name, and the
	 * others are still billed (REQ-SCON-001).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-001)
	 */
	public function testOneFailingRecipientDoesNotStopTheOthers(): void {
		$result = $this->service($this->customers())->raise(
			$this->call(
				[
					$this->recipient('cm-1', 'child-a'),
					$this->recipient('cm-404', 'child-b'),
					$this->recipient('cm-3', 'child-c'),
				]
			)
		);

		self::assertSame(['raised', 'failed', 'raised'], array_column($result['results'], 'status'));
		self::assertStringContainsString('cm-404', $result['results'][1]['reason']);
		self::assertArrayNotHasKey('invoiceId', $result['results'][1]);
		self::assertSame(1, $result['failed']);
		self::assertCount(2, $this->savedIn('ARInvoice'));
	}//end testOneFailingRecipientDoesNotStopTheOthers()

	/**
	 * A malformed call writes nothing (REQ-SCON-001).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-001)
	 */
	public function testRefusesAMalformedCall(): void {
		$service = $this->service($this->customers());

		try {
			$service->raise($this->call([$this->recipient('cm-1', 'child-a')], ['amount' => 0]));
			self::fail('an amount of zero was raised');
		} catch (InvalidArgumentException $e) {
			self::assertStringContainsString('amount', $e->getMessage());
		}

		self::assertSame([], $this->store->saved);
	}//end testRefusesAMalformedCall()

	/**
	 * A caller without payment.request is refused before anything is read or
	 * written (REQ-SCON-002).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-002)
	 */
	public function testRefusesACallerWithoutTheAction(): void {
		$service = $this->service($this->customers(), false);

		try {
			$service->raise($this->call([$this->recipient('cm-1', 'child-a')]));
			self::fail('a caller without payment.request raised a contribution');
		} catch (RuntimeException $e) {
			self::assertStringStartsWith('403', $e->getMessage());
		}

		self::assertSame([], $this->store->saved);
	}//end testRefusesACallerWithoutTheAction()
}//end class
