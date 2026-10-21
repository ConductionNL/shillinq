<?php

/**
 * BBV Allocation Validation Listener
 *
 * Enforces on the save path the three BBV rules the waterschappen variant
 * declared under `x-openregister-validation`, a key OpenRegister never reads:
 * a BBV programme code is unique per administration and fiscal year, a budget
 * mapping points at an account of its administration, and the allocations of
 * one account in one fiscal year add up to at most 100% (with 0.1% rounding
 * room). These rules compare a record with its siblings, which OpenRegister's
 * per-document `x-openregister-validations` conditions cannot express, so they
 * run here, whatever client writes the record.
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
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.7
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refuses duplicate programme codes, unknown accounts and over-allocation.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.7
 */
class BbvAllocationValidationListener implements IEventListener {

	/**
	 * Most an account may be allocated in one year, in percent, with rounding room.
	 *
	 * @var float
	 */
	public const MAX_ALLOCATION = 100.1;

	/**
	 * Refusal for a duplicate programme code.
	 *
	 * @var string
	 */
	public const MESSAGE_DUPLICATE_CODE = 'A BBV programme with this code already exists for the selected fiscal year. '
		. 'Programme codes are unique per administration and fiscal year.';

	/**
	 * Refusal for an unknown ledger account.
	 *
	 * @var string
	 */
	public const MESSAGE_UNKNOWN_ACCOUNT = 'The ledger account does not exist in the chart of accounts of this administration.';

	/**
	 * Refusal for an over-allocated account.
	 *
	 * @var string
	 */
	public const MESSAGE_OVER_ALLOCATED = 'The allocations of this ledger account in this fiscal year would add up to more than 100%. '
		. 'Lower one of them first.';

	/**
	 * Refusal when the siblings could not be read.
	 *
	 * @var string
	 */
	public const MESSAGE_UNCHECKED = 'The BBV rules could not be checked, so the save is refused. Try again.';

	/**
	 * Construct the listener.
	 *
	 * @param ListenerSchemaResolver $schemaResolver  Matches the BBV schemas in shillinq's register.
	 * @param ContainerInterface     $container       DI container for the lazy ObjectService.
	 * @param SettingsService        $settingsService Resolves the register slug.
	 * @param LoggerInterface        $logger          Logger.
	 */
	public function __construct(
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Check a BBV programme or budget mapping before it is saved.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.7
	 */
	public function handle(Event $event): void {
		$entity = null;
		if ($event instanceof ObjectCreatingEvent === true) {
			$entity = $event->getObject();
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$entity = $event->getNewObject();
		}

		if ($entity === null) {
			return;
		}

		$data = $entity->getObject();
		$self = (string)($entity->getUuid() ?? ($data['id'] ?? ''));
		try {
			$error = null;
			if ($this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: 'BBVProgramme') === true) {
				$error = $this->programmeRefusal(data: $data, self: $self);
			} elseif ($this->schemaResolver->matchesSchema(entity: $entity, expectedSlug: 'BudgetBBVMapping') === true) {
				$error = $this->mappingRefusal(data: $data, self: $self);
			}
		} catch (Throwable $e) {
			$this->logger->error('BbvAllocationValidationListener: sibling lookup failed; refusing', ['exception' => $e->getMessage()]);
			$error = self::MESSAGE_UNCHECKED;
		}

		if ($error !== null) {
			$event->setErrors(['message' => $error]);
			$event->stopPropagation();
		}
	}//end handle()

	/**
	 * Why a programme may not be saved, or null.
	 *
	 * @param array<string, mixed> $data The programme as it would be saved.
	 * @param string               $self Its id, empty on create.
	 *
	 * @return string|null
	 */
	private function programmeRefusal(array $data, string $self): ?string {
		$code = trim((string)($data['programmeCode'] ?? ''));
		if ($code === '') {
			return null;
		}

		$siblings = $this->others(
			schema: 'BBVProgramme',
			filters: [
				'administrationId' => (string)($data['administrationId'] ?? ''),
				'fiscalYear' => ($data['fiscalYear'] ?? null),
				'programmeCode' => $code,
			],
			self: $self
		);
		if ($siblings !== []) {
			return self::MESSAGE_DUPLICATE_CODE;
		}

		return null;
	}//end programmeRefusal()

	/**
	 * Why a budget mapping may not be saved, or null.
	 *
	 * @param array<string, mixed> $data The mapping as it would be saved.
	 * @param string               $self Its id, empty on create.
	 *
	 * @return string|null
	 */
	private function mappingRefusal(array $data, string $self): ?string {
		$administrationId = (string)($data['administrationId'] ?? '');
		$account = trim((string)($data['glAccountNumber'] ?? ''));
		if ($account === '') {
			return null;
		}

		$accounts = $this->others(
			schema: 'Account',
			filters: ['administrationId' => $administrationId, 'accountNumber' => $account],
			self: ''
		);
		if ($accounts === []) {
			return self::MESSAGE_UNKNOWN_ACCOUNT;
		}

		$total = (float)($data['allocationPercentage'] ?? 0);
		$siblings = $this->others(
			schema: 'BudgetBBVMapping',
			filters: [
				'administrationId' => $administrationId,
				'glAccountNumber' => $account,
				'fiscalYear' => ($data['fiscalYear'] ?? null),
			],
			self: $self
		);
		foreach ($siblings as $sibling) {
			$total += (float)($sibling['allocationPercentage'] ?? 0);
		}

		if ($total > self::MAX_ALLOCATION) {
			return self::MESSAGE_OVER_ALLOCATED;
		}

		return null;
	}//end mappingRefusal()

	/**
	 * The rows matching the filters, except the record itself.
	 *
	 * Read with RBAC and multitenancy off: the rule is about every sibling, not
	 * only those the caller may read.
	 *
	 * @param string               $schema  Schema slug.
	 * @param array<string, mixed> $filters Equality filters.
	 * @param string               $self    Id to leave out.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function others(string $schema, array $filters, string $self): array {
		$rows = $this->container->get('OCA\OpenRegister\Service\ObjectService')
			->setRegister($this->settingsService->getRegisterSlug())
			->setSchema($schema)
			->findAll(
				['filters' => array_filter($filters, static fn ($v): bool => $v !== null && $v !== ''), 'limit' => 1000],
				false,
				false
			);

		$result = [];
		foreach ((array)$rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === false) {
				continue;
			}

			$id = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
			if ($self !== '' && $id === $self) {
				continue;
			}

			$result[] = $row;
		}

		return $result;
	}//end others()
}//end class
