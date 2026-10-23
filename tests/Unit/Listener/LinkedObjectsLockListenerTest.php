<?php

/**
 * Unit tests for LinkedObjectsLockListener.
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
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-001)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Shillinq\AppInfo\PaymentControlsRegistration;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Listener\LinkedObjectsLockListener;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use PHPUnit\Framework\TestCase;

/**
 * The list of linked objects freezes once a document is issued.
 */
final class LinkedObjectsLockListenerTest extends TestCase {
	private const CASE_1 = ['register' => 'dossiq', 'schema' => 'case', 'id' => 'c-1'];

	private const CASE_2 = ['register' => 'dossiq', 'schema' => 'case', 'id' => 'c-2'];

	/**
	 * A resolver that matches only the given schema.
	 *
	 * @param string $schema The schema the entity is.
	 *
	 * @return ListenerSchemaResolver
	 */
	private function resolver(string $schema): ListenerSchemaResolver {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('matchesSchema')->willReturnCallback(
			static fn (mixed $entity, string $expectedSlug): bool => $expectedSlug === $schema
		);

		return $resolver;
	}//end resolver()

	/**
	 * Run the listener over one update and return the errors it set.
	 *
	 * @param string $schema The schema the object is.
	 * @param array<string, mixed> $old The stored object.
	 * @param array<string, mixed> $new The incoming object.
	 *
	 * @return array<string, mixed> The errors.
	 */
	private function errors(string $schema, array $old, array $new): array {
		$before = new ObjectEntity();
		$before->setObject($old);
		$after = new ObjectEntity();
		$after->setObject($new);
		$event = new ObjectUpdatingEvent($after, $before);

		(new LinkedObjectsLockListener($this->resolver($schema)))->handle($event);

		return $event->getErrors();
	}//end errors()

	public function testAnIssuedInvoiceKeepsItsList(): void {
		$issued = ['lifecycleState' => 'issued', 'linkedObjects' => [self::CASE_1]];
		$errors = $this->errors('ARInvoice', $issued, ['lifecycleState' => 'issued', 'linkedObjects' => [self::CASE_1, self::CASE_2]]);

		self::assertSame(LinkedObjectsLockListener::MESSAGE, $errors['message'] ?? null);
		self::assertSame('linkedObjects', $errors['field'] ?? null);
	}//end testAnIssuedInvoiceKeepsItsList()

	public function testADraftQuoteCanChangeItsList(): void {
		$draft = ['status' => 'draft', 'linkedObjects' => [self::CASE_1]];

		self::assertSame([], $this->errors('Quote', $draft, ['status' => 'draft', 'linkedObjects' => [self::CASE_1, self::CASE_2]]));
	}//end testADraftQuoteCanChangeItsList()

	public function testASentQuoteKeepsItsList(): void {
		$sent = ['status' => 'sent', 'linkedObjects' => [self::CASE_1]];

		self::assertNotSame([], $this->errors('Quote', $sent, ['status' => 'sent', 'linkedObjects' => []]));
	}//end testASentQuoteKeepsItsList()

	public function testAPayoutKeepsItsListFromTheMomentItIsRaised(): void {
		$raised = ['state' => 'requested', 'linkedObjects' => [self::CASE_1]];

		self::assertNotSame([], $this->errors('InboundPayable', $raised, ['state' => 'requested', 'linkedObjects' => [self::CASE_2]]));
	}//end testAPayoutKeepsItsListFromTheMomentItIsRaised()

	public function testOtherFieldsOfAnIssuedInvoiceAreNotThisListenersBusiness(): void {
		$issued = ['lifecycleState' => 'issued', 'linkedObjects' => [self::CASE_1]];

		self::assertSame([], $this->errors('ARInvoice', $issued, ['lifecycleState' => 'paid', 'linkedObjects' => [self::CASE_1]]));
	}//end testOtherFieldsOfAnIssuedInvoiceAreNotThisListenersBusiness()

	public function testAnEmptyListMayTakeTheRequestsOwnSubject(): void {
		$request = ['state' => 'pending', 'subject' => self::CASE_1 + ['app' => 'dossiq']];

		self::assertSame([], $this->errors('PaymentRequest', $request, $request + ['linkedObjects' => [self::CASE_1 + ['app' => 'dossiq']]]));
	}//end testAnEmptyListMayTakeTheRequestsOwnSubject()

	public function testAnEmptyListMayNotTakeAnotherObject(): void {
		$request = ['state' => 'pending', 'subject' => self::CASE_1];

		self::assertNotSame([], $this->errors('PaymentRequest', $request, $request + ['linkedObjects' => [self::CASE_2]]));
	}//end testAnEmptyListMayNotTakeAnotherObject()

	public function testAnItemWithoutAnIdIsRefusedEvenOnADraft(): void {
		$draft = ['lifecycleState' => 'draft'];
		$errors = $this->errors('ARInvoice', $draft, ['lifecycleState' => 'draft', 'linkedObjects' => [['register' => 'dossiq', 'schema' => 'case']]]);

		self::assertSame(LinkedObjectsLockListener::MALFORMED, $errors['message'] ?? null);
	}//end testAnItemWithoutAnIdIsRefusedEvenOnADraft()

	public function testTheListenerIsRegisteredOnTheUpdate(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = [$event, $listener];
			}
		);

		(new PaymentControlsRegistration())->register(context: $context);

		self::assertContains([ObjectUpdatingEvent::class, LinkedObjectsLockListener::class], $registered);
	}//end testTheListenerIsRegisteredOnTheUpdate()

	public function testAnotherSchemaIsLeftAlone(): void {
		$issued = ['lifecycleState' => 'issued', 'linkedObjects' => [self::CASE_1]];

		self::assertSame([], $this->errors('Contract', $issued, ['lifecycleState' => 'issued', 'linkedObjects' => []]));
	}//end testAnotherSchemaIsLeftAlone()
}//end class
