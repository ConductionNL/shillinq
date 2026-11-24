<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Expense Category Account Listener
 *
 * Expense categories are written straight into OpenRegister from their
 * settings page, so the account rule runs on OpenRegister's pre-save veto:
 * `ObjectCreatingEvent` and `ObjectUpdatingEvent` are stoppable, and a stopped
 * event becomes HTTP 422 with the refusal, the mechanism
 * FeeScheduleValidationListener uses. The rule itself is
 * ExpenseCategoryAccountCheck, called rather than copied.
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
 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Shillinq\Service\ExpenseCategoryAccountCheck;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Refuses an expense category whose account is not an expense account of its
 * administration (REQ-ECM-001).
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-1.1
 */
class ExpenseCategoryAccountListener implements IEventListener {
	/**
	 * The schema this listener is scoped to.
	 *
	 * @var string
	 */
	public const SCHEMA_SLUG = 'ExpenseCategory';

	/**
	 * Constructor.
	 *
	 * @param ExpenseCategoryAccountCheck     $accountCheck   The account rule.
	 * @param ListenerSchemaResolverInterface $schemaResolver Resolves the entity's schema to its slug.
	 * @param LoggerInterface                 $logger         Logger for refusals.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExpenseCategoryAccountCheck $accountCheck,
		private readonly ListenerSchemaResolverInterface $schemaResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Veto an expense category with an account that is not an expense account.
	 *
	 * @param Event $event The OpenRegister pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-1.1
	 */
	public function handle(Event $event): void {
		$write = new PreSaveWrite();
		$entity = $write->incomingEntity(event: $event);
		if ($entity === null || $this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: self::SCHEMA_SLUG) === false) {
			return;
		}

		$category = $write->payload(entity: $entity);
		$reason = $this->accountCheck->refusal(category: $category);
		if ($reason === null) {
			return;
		}

		$this->logger->warning(
			'Shillinq: refused an ExpenseCategory whose account is not an expense account',
			['code' => (string)($category['code'] ?? ''), 'reason' => $reason]
		);

		$event->setErrors(
			[
				'message' => $reason,
				'requirement' => 'REQ-ECM-001',
			]
		);
		$event->stopPropagation();
	}//end handle()
}//end class
