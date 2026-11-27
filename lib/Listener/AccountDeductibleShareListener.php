<?php

/**
 * AccountDeductibleShareListener — enforce the deductible-share pair rule on
 * the write path of a ledger account.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An Account carries a `deductiblePercentage` from 0 to 100 and a
 * `privateShareAccountNumber`. Below 100 the posting mappers book the private
 * share of every cost on that second account, so a percentage below 100 must
 * not be saved without one (REQ-TVRD-003). Accounts are written straight into
 * OpenRegister, so the rule runs on its stoppable pre-save events; a stopped
 * event becomes HTTP 422 with this message.
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-tax-vat-rates-and-deductibility/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Veto an Account below 100 percent deductible that names no private-share account.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/bookkeeping-chart-of-accounts/spec.md
 */
class AccountDeductibleShareListener implements IEventListener {

	/**
	 * The schema this listener is scoped to.
	 *
	 * @var string
	 */
	private const SCHEMA_SLUG = 'Account';

	/**
	 * The requirement a refusal names.
	 *
	 * @var string
	 */
	public const REQUIREMENT = 'REQ-TVRD-003';

	/**
	 * Constructor.
	 *
	 * @param ListenerSchemaResolverInterface $schemaResolver Resolves the entity's schema to its slug.
	 * @param LoggerInterface                 $logger         Logger for refusals.
	 */
	public function __construct(
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Veto an account write that breaks the pair rule.
	 *
	 * @param Event $event The OpenRegister pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-24-tax-vat-rates-and-deductibility/tasks.md#task-3.1
	 */
	public function handle(Event $event): void {
		$reason = $this->violationOf(event: $event);
		if ($reason === null) {
			return;
		}

		$this->logger->warning('Shillinq: refused an Account write that breaks the deductible-share rule', ['reason' => $reason]);
		$event->setErrors(['message' => $reason, 'requirement' => self::REQUIREMENT]);
		$event->stopPropagation();
	}//end handle()

	/**
	 * The pair rule an Account save breaks, or null for a save that keeps it
	 * or is not an Account.
	 *
	 * @param Event $event The OpenRegister pre-save event.
	 *
	 * @return string|null
	 */
	private function violationOf(Event $event): ?string {
		$write   = new PreSaveWrite();
		$account = $write->incomingEntity(event: $event);
		if ($account === null || $this->schemaResolver->matchesSchema(entity: $account, expectedSlug: self::SCHEMA_SLUG) === false) {
			return null;
		}

		return self::violation(account: $write->payload(entity: $account));
	}//end violationOf()

	/**
	 * The pair rule the account breaks, or null when it keeps it.
	 *
	 * Absent or 100 needs nothing; a number from 0 to below 100 needs a
	 * private-share account other than the account itself; anything else is
	 * refused as out of range.
	 *
	 * @param array<string, mixed> $account The account being written.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/archive/2026-11-24-tax-vat-rates-and-deductibility/tasks.md#task-3.1
	 */
	public static function violation(array $account): string|null {
		$percentage = ($account['deductiblePercentage'] ?? null);
		if ($percentage === null || $percentage === '') {
			return null;
		}

		$number = (string)($account['accountNumber'] ?? '');
		if (is_numeric($percentage) === false || (float)$percentage < 0 || (float)$percentage > 100) {
			return sprintf('The deductible percentage of account %s must be a number from 0 to 100.', $number);
		}

		if ((float)$percentage === 100.0) {
			return null;
		}

		$private = trim((string)($account['privateShareAccountNumber'] ?? ''));
		if ($private === '') {
			return sprintf('Account %s is less than 100 percent deductible, so it needs a private-share account.', $number);
		}

		if ($private === $number) {
			return sprintf('Account %s cannot be its own private-share account.', $number);
		}

		return null;
	}//end violation()
}//end class
