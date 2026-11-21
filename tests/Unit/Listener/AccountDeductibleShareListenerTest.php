<?php

/**
 * Unit tests for AccountDeductibleShareListener.
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
 * @spec openspec/changes/tax-vat-rates-and-deductibility/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Listener\AccountDeductibleShareListener;
use OCA\Shillinq\Listener\PreSaveWrite;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * An account below 100 percent deductible must name a private-share account (REQ-TVRD-003).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(AccountDeductibleShareListener::class)]
#[UsesClass(PreSaveWrite::class)]
final class AccountDeductibleShareListenerTest extends TestCase {

	/**
	 * The listener with the schema check answered.
	 *
	 * @param bool $matches Whether the entity is an Account.
	 *
	 * @return AccountDeductibleShareListener
	 */
	private function listener(bool $matches = true): AccountDeductibleShareListener {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('matchesSchema')->willReturnCallback(
			static fn (object $entity, string $expectedSlug): bool => ($matches === true && $expectedSlug === 'Account')
		);

		return new AccountDeductibleShareListener(schemaResolver: $resolver, logger: new NullLogger());
	}//end listener()

	/**
	 * An entity carrying the payload.
	 *
	 * @param array<string,mixed> $data The account.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($data);
		$entity->setSchema('Account');
		$entity->setRegister('shillinq');
		return $entity;
	}//end entity()

	/**
	 * 70 percent without a private-share account is refused on create, with the requirement.
	 *
	 * @return void
	 */
	public function testSeventyPercentWithoutAPrivateAccountIsRefusedOnCreate(): void {
		$event = new ObjectCreatingEvent(self::entity(['accountNumber' => '4520', 'deductiblePercentage' => 70]));

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(
			['message' => 'Account 4520 is less than 100 percent deductible, so it needs a private-share account.', 'requirement' => 'REQ-TVRD-003'],
			$event->getErrors()
		);
	}//end testSeventyPercentWithoutAPrivateAccountIsRefusedOnCreate()

	/**
	 * The same rule holds on update, on the new version of the account.
	 *
	 * @return void
	 */
	public function testTheRuleHoldsOnUpdate(): void {
		$old = self::entity(['accountNumber' => '4520', 'deductiblePercentage' => 70, 'privateShareAccountNumber' => '1810']);
		$new = self::entity(['accountNumber' => '4520', 'deductiblePercentage' => 70, 'privateShareAccountNumber' => ' ']);
		$event = new ObjectUpdatingEvent($new, $old);

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testTheRuleHoldsOnUpdate()

	/**
	 * The sole trader's car account is written.
	 *
	 * @return void
	 */
	public function testSeventyPercentWithAPrivateAccountIsWritten(): void {
		$event = new ObjectCreatingEvent(self::entity(['accountNumber' => '4520', 'deductiblePercentage' => 70, 'privateShareAccountNumber' => '1810']));

		$this->listener()->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testSeventyPercentWithAPrivateAccountIsWritten()

	/**
	 * The pair rule, case by case.
	 *
	 * @return void
	 */
	public function testTheRuleCaseByCase(): void {
		self::assertNull(AccountDeductibleShareListener::violation(['accountNumber' => '4300']));
		self::assertNull(AccountDeductibleShareListener::violation(['accountNumber' => '4300', 'deductiblePercentage' => 100]));
		self::assertNull(AccountDeductibleShareListener::violation(['accountNumber' => '4300', 'deductiblePercentage' => '']));
		self::assertNull(
			AccountDeductibleShareListener::violation(['accountNumber' => '4520', 'deductiblePercentage' => 0, 'privateShareAccountNumber' => '1810']),
		);
		self::assertSame(
			'Account 4520 cannot be its own private-share account.',
			AccountDeductibleShareListener::violation(['accountNumber' => '4520', 'deductiblePercentage' => 70, 'privateShareAccountNumber' => '4520'])
		);
		self::assertSame(
			'The deductible percentage of account 4520 must be a number from 0 to 100.',
			AccountDeductibleShareListener::violation(['accountNumber' => '4520', 'deductiblePercentage' => 101, 'privateShareAccountNumber' => '1810'])
		);
		self::assertNotNull(AccountDeductibleShareListener::violation(['accountNumber' => '4520', 'deductiblePercentage' => 'half']));
	}//end testTheRuleCaseByCase()

	/**
	 * Another schema's write is untouched.
	 *
	 * @return void
	 */
	public function testOtherSchemasAreUntouched(): void {
		$event = new ObjectCreatingEvent(self::entity(['accountNumber' => '4520', 'deductiblePercentage' => 70]));

		$this->listener(matches: false)->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testOtherSchemasAreUntouched()
}//end class
