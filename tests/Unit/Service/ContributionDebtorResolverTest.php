<?php

/**
 * Unit tests for ContributionDebtorResolver.
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
 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-005)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Shillinq\Service\ContributionDebtorResolver;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A stand-in for portaliq's claim event: same constructor, same result slot.
 */
final class FakePortalAccountClaimRequestedEvent extends Event {
	/**
	 * The answer portaliq's listener writes.
	 *
	 * @var string
	 */
	private string $result = '';

	/**
	 * Constructor, in portaliq's argument order.
	 *
	 * @param string $appId The dispatching app.
	 * @param string $subjectRef The account.
	 * @param string $claimName The claim.
	 * @param string $value The value.
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $subjectRef,
		public readonly string $claimName,
		public readonly string $value,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Write the answer.
	 *
	 * @param string $result 'ok' or 'refused'.
	 *
	 * @return void
	 */
	public function answer(string $result): void {
		$this->result = $result;
	}//end answer()

	/**
	 * Read the answer.
	 *
	 * @return string The answer.
	 */
	public function getResult(): string {
		return $this->result;
	}//end getResult()
}//end class

/**
 * Covers the lookup order, the create and the portal claim (REQ-SCON-005).
 */
final class ContributionDebtorResolverTest extends TestCase {
	/**
	 * Claim events the dispatcher received.
	 *
	 * @var array<int, FakePortalAccountClaimRequestedEvent>
	 */
	private array $dispatched = [];

	/**
	 * Build the resolver over an in-memory store.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $data Schema => rows.
	 * @param string $claimEventClass The claim event class to dispatch.
	 * @param InMemoryObjectServiceStub|null $store The store, returned for assertions.
	 *
	 * @return ContributionDebtorResolver The resolver.
	 */
	private function resolver(
		array $data,
		string $claimEventClass = FakePortalAccountClaimRequestedEvent::class,
		?InMemoryObjectServiceStub &$store = null,
	): ContributionDebtorResolver {
		$store = new InMemoryObjectServiceStub(data: $data, findAllRendersEntities: true);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				if ($event instanceof FakePortalAccountClaimRequestedEvent) {
					$this->dispatched[] = $event;
					$event->answer('ok');
				}
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new ContributionDebtorResolver(
			objectService: $store,
			dispatcher: $dispatcher,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
			claimEventClass: $claimEventClass,
		);
	}//end resolver()

	/**
	 * A guardian nobody has billed: one customer is created from the name and
	 * the email, and the portal account is asked to point at it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-005)
	 */
	public function testCreatesACustomerAndLinksThePortalAccount(): void {
		$store = null;
		$resolver = $this->resolver(
			['portalAccount' => [['id' => 'acc-1', 'subjectRef' => 'subj-1', 'audience' => 'parent', 'claims' => []]]],
			FakePortalAccountClaimRequestedEvent::class,
			$store
		);

		$result = $resolver->resolve(
			['portalSubjectRef' => 'subj-1', 'name' => 'J. de Vries', 'email' => 'J.deVries@Example.nl'],
			'adm-school-1'
		);

		self::assertTrue($result['created']);
		self::assertTrue($result['portalLinked']);
		self::assertCount(1, $store->saved);
		self::assertSame('CustomerMaster', $store->saved[0]['schema']);
		self::assertSame('j.devries@example.nl', $store->saved[0]['object']['email']);
		self::assertSame('J. de Vries', $store->saved[0]['object']['legalName']);
		self::assertSame('active', $store->saved[0]['object']['lifecycleState']);
		self::assertSame($store->saved[0]['object']['id'], $result['customerMasterId']);

		self::assertCount(1, $this->dispatched);
		self::assertSame('shillinq', $this->dispatched[0]->appId);
		self::assertSame('subj-1', $this->dispatched[0]->subjectRef);
		self::assertSame('customerMasterId', $this->dispatched[0]->claimName);
		self::assertSame($result['customerMasterId'], $this->dispatched[0]->value);
	}//end testCreatesACustomerAndLinksThePortalAccount()

	/**
	 * A guardian billed before is found by email; no second customer appears.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-005)
	 */
	public function testFindsTheCustomerByEmailBeforeCreatingOne(): void {
		$store = null;
		$resolver = $this->resolver(
			['CustomerMaster' => [['id' => 'cm-7', 'email' => 'ouder@example.nl', 'administrationId' => 'adm-school-1']]],
			FakePortalAccountClaimRequestedEvent::class,
			$store
		);

		$result = $resolver->resolve(['name' => 'Ouder', 'email' => 'Ouder@example.nl'], 'adm-school-1');

		self::assertSame('cm-7', $result['customerMasterId']);
		self::assertFalse($result['created']);
		self::assertFalse($result['portalLinked']);
		self::assertSame([], $store->saved);
		self::assertSame([], $this->dispatched);
	}//end testFindsTheCustomerByEmailBeforeCreatingOne()

	/**
	 * The customer a guardian's account already points at wins, and no claim is
	 * written again.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/extracurricular-fee-to-shillinq/specs/school-contributions/spec.md (REQ-SCON-005)
	 */
	public function testTheClaimedCustomerWinsAndIsNotClaimedAgain(): void {
		$resolver = $this->resolver(
			[
				'portalAccount' => [['id' => 'acc-1', 'subjectRef' => 'subj-1', 'claims' => ['shillinq' => ['customerMasterId' => 'cm-9']]]],
				'CustomerMaster' => [
					['id' => 'cm-9', 'email' => 'old@example.nl', 'administrationId' => 'adm-school-1'],
					['id' => 'cm-10', 'email' => 'new@example.nl', 'administrationId' => 'adm-school-1'],
				],
			]
		);

		$result = $resolver->resolve(['portalSubjectRef' => 'subj-1', 'email' => 'new@example.nl', 'name' => 'X'], 'adm-school-1');

		self::assertSame('cm-9', $result['customerMasterId']);
		self::assertTrue($result['portalLinked']);
		self::assertSame([], $this->dispatched);
	}//end testTheClaimedCustomerWinsAndIsNotClaimedAgain()

	/**
	 * A named customer that does not exist fails that recipient, by name.
	 *
	 * @return void
	 */
	public function testAMissingNamedCustomerIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('cm-404');

		$this->resolver([])->resolve(['customerMasterId' => 'cm-404'], 'adm-school-1');
	}//end testAMissingNamedCustomerIsRefused()

	/**
	 * A customer named by its code resolves to its uuid, which is what the
	 * portal scopes invoices by.
	 *
	 * @return void
	 */
	public function testACustomerCodeResolvesToItsUuid(): void {
		$result = $this->resolver(
			['CustomerMaster' => [['id' => 'cm-uuid-1', 'customerId' => 'DEB-0001']]]
		)->resolve(['customerMasterId' => 'DEB-0001'], 'adm-school-1');

		self::assertSame('cm-uuid-1', $result['customerMasterId']);
	}//end testACustomerCodeResolvesToItsUuid()

	/**
	 * Without portaliq the guardian is still billed, and portalLinked says the
	 * account was not linked.
	 *
	 * @return void
	 */
	public function testWithoutPortaliqTheGuardianIsStillBilled(): void {
		$result = $this->resolver([], 'OCA\\Portaliq\\Event\\ThisClassDoesNotExist')
			->resolve(['portalSubjectRef' => 'subj-1', 'name' => 'Ouder', 'email' => 'ouder@example.nl'], 'adm-school-1');

		self::assertTrue($result['created']);
		self::assertFalse($result['portalLinked']);
		self::assertSame([], $this->dispatched);
	}//end testWithoutPortaliqTheGuardianIsStillBilled()

	/**
	 * A new debtor needs a valid email and a name.
	 *
	 * @return void
	 */
	public function testANewDebtorNeedsAnEmailAndAName(): void {
		try {
			$this->resolver([])->resolve(['name' => 'Ouder'], 'adm-school-1');
			self::fail('a debtor without an email was accepted');
		} catch (InvalidArgumentException $e) {
			self::assertStringContainsString('email', $e->getMessage());
		}

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('name');
		$this->resolver([])->resolve(['email' => 'ouder@example.nl'], 'adm-school-1');
	}//end testANewDebtorNeedsAnEmailAndAName()
}//end class
