<?php

/**
 * Settles the invoices a confirmed reconciliation match names.
 *
 * A confirmed ReconciliationMatch says "this bank money paid these invoices".
 * The invoices move to paid through their own declared transitions, so their
 * guards and actions run and the transition is audit-trailed like any other:
 * `mark-paid` (issued) or `pay-overdue` (overdue) for an ARInvoice, `matchFull`
 * or, for a partial match, `matchPartial` for an APTransaction. An invoice not
 * in a payable state is left alone and the reason logged, which also makes a
 * repeated confirm event a no-op: the invoice is already paid.
 *
 * ARInvoice has no partially paid state, so a partial match leaves a sales
 * invoice issued; the open amount is read from its matches.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Bank
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/banking-manual-match/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Bank;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Util\ObjectIdentifier;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Moves the invoices of a confirmed match to paid.
 *
 * @spec openspec/changes/banking-manual-match/tasks.md#task-2.1
 */
class InvoiceSettlementService {
	/**
	 * Outcome: the named transition ran.
	 *
	 * @var string
	 */
	public const SETTLED = 'settled';

	/**
	 * Outcome: the invoice was not in a payable state and was left alone.
	 *
	 * @var string
	 */
	public const SKIPPED = 'skipped';

	/**
	 * Outcome: the invoice could not be read or the transition was refused.
	 *
	 * @var string
	 */
	public const FAILED = 'failed';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object surface.
	 * @param ObjectTransitionRunner $transitions   Runs the invoices' declared transitions.
	 * @param SettingsService        $settings      Register slug.
	 * @param LoggerInterface        $logger        Logger for skipped and failed settlements.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ObjectTransitionRunner $transitions,
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Settle every invoice a confirmed match names.
	 *
	 * @param array<string,mixed> $match The confirmed ReconciliationMatch payload.
	 *
	 * @return array<string,array{outcome:string,transition:?string,reason:string}> Per invoice id.
	 *
	 * @spec openspec/changes/banking-manual-match/tasks.md#task-2.1
	 */
	public function settle(array $match): array {
		$type = (string)($match['targetType'] ?? ($match['matchType'] ?? ''));
		$schema = self::schemaForType(type: $type);
		if ($schema === null) {
			return [];
		}

		$partial = (($match['isPartial'] ?? false) === true || ($match['partial'] ?? false) === true);
		$outcomes = [];
		foreach (self::targetIds(match: $match) as $invoiceId) {
			$outcomes[$invoiceId] = $this->settleOne(schema: $schema, invoiceId: $invoiceId, partial: $partial);
		}

		return $outcomes;

	}//end settle()

	/**
	 * The transition that pays an invoice in its current state, or null.
	 *
	 * @param string $schema  `ARInvoice` or `APTransaction`.
	 * @param string $state   The invoice's current lifecycle state.
	 * @param bool   $partial Whether the match covers only part of the invoice.
	 *
	 * @return string|null The transition name, or null when none applies.
	 *
	 * @spec openspec/changes/banking-manual-match/tasks.md#task-2.1
	 */
	public static function transitionFor(string $schema, string $state, bool $partial): ?string {
		if ($schema === 'ARInvoice') {
			if ($partial === true) {
				return null;
			}

			return match ($state) {
				'issued' => 'mark-paid',
				'overdue' => 'pay-overdue',
				default => null,
			};
		}

		if ($partial === true) {
			return in_array($state, ['issued', 'overdue'], true) === true ? 'matchPartial' : null;
		}

		return in_array($state, ['issued', 'overdue', 'partially-paid'], true) === true ? 'matchFull' : null;

	}//end transitionFor()

	/**
	 * Settle one invoice.
	 *
	 * @param string $schema    `ARInvoice` or `APTransaction`.
	 * @param string $invoiceId The invoice uuid.
	 * @param bool   $partial   Whether the match is partial.
	 *
	 * @return array{outcome:string,transition:?string,reason:string}
	 */
	private function settleOne(string $schema, string $invoiceId, bool $partial): array {
		try {
			$invoice = ObjectIdentifier::findOne(
				scoped: $this->objectService->setRegister($this->settings->getRegisterSlug())->setSchema($schema),
				id: $invoiceId
			);
		} catch (Throwable $e) {
			$invoice = null;
		}

		if ($invoice === null) {
			$this->logger->warning('InvoiceSettlementService: invoice not found', ['schema' => $schema, 'invoiceId' => $invoiceId]);
			return ['outcome' => self::FAILED, 'transition' => null, 'reason' => 'not found'];
		}

		$stateField = $schema === 'ARInvoice' ? 'lifecycleState' : 'state';
		$state = (string)($invoice[$stateField] ?? '');
		$transition = self::transitionFor(schema: $schema, state: $state, partial: $partial);
		if ($transition === null) {
			$reason = 'state ' . $state . ' is not payable' . ($partial === true ? ' by a partial match' : '');
			$this->logger->info('InvoiceSettlementService: invoice left unchanged', ['invoiceId' => $invoiceId, 'reason' => $reason]);
			return ['outcome' => self::SKIPPED, 'transition' => null, 'reason' => $reason];
		}

		try {
			$this->transitions->run(objectId: $invoiceId, action: $transition);
		} catch (Throwable $e) {
			$this->logger->error(
				'InvoiceSettlementService: settlement transition refused',
				['invoiceId' => $invoiceId, 'transition' => $transition, 'exception' => $e->getMessage()]
			);
			return ['outcome' => self::FAILED, 'transition' => $transition, 'reason' => 'transition refused'];
		}

		return ['outcome' => self::SETTLED, 'transition' => $transition, 'reason' => ''];

	}//end settleOne()

	/**
	 * The invoice schema for a match target type.
	 *
	 * @param string $type `ar-invoice`, `ap-invoice` or another target type.
	 *
	 * @return string|null The schema slug, or null for a non-invoice target.
	 */
	private static function schemaForType(string $type): ?string {
		return match ($type) {
			'ar-invoice' => 'ARInvoice',
			'ap-invoice' => 'APTransaction',
			default => null,
		};

	}//end schemaForType()

	/**
	 * The invoice ids a match names: `targetRefs`, else `matchedObjectId`.
	 *
	 * @param array<string,mixed> $match The match payload.
	 *
	 * @return array<int,string>
	 */
	private static function targetIds(array $match): array {
		$refs = ($match['targetRefs'] ?? []);
		if (is_array($refs) === true && $refs !== []) {
			return array_values(array_unique(array_filter(array_map('strval', $refs), static fn (string $id): bool => $id !== '')));
		}

		$single = trim((string)($match['matchedObjectId'] ?? ''));
		return $single === '' ? [] : [$single];

	}//end targetIds()
}//end class
