<?php

/**
 * MCP handlers for expenses: book a receipt onto a claim, decide a claim.
 *
 * @category Mcp
 * @package  OCA\Shillinq\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/mcp-action-tool-surface/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Mcp;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ExtractionPrefillServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectTransitionRunnerInterface;
use OCA\Shillinq\Service\Extraction\ExtractionDraftCommitter;
use OCA\Shillinq\Util\ObjectIdentifier;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * `shillinq.receipt.book` commits a Receipt draft onto an expense claim through
 * the same consume flow as the receipt review screen (the correction is
 * recorded, the draft confirmed, the booking fed back). `shillinq.expense_claim.approve`
 * runs the claim's declared `approve` or `reject` transition through
 * OpenRegister's transition engine as the signed-in user, so the approval
 * workflow's own checks decide who may.
 *
 * @spec openspec/changes/mcp-action-tool-surface/tasks.md
 */
class McpExpenseTools {

	/**
	 * The refusal for an administration the caller cannot reach.
	 */
	public const ADMINISTRATION_NOT_FOUND = 'Administration not found';

	/**
	 * The refusal for a receipt that is missing or in another administration.
	 */
	public const RECEIPT_NOT_FOUND = 'Receipt not found';

	/**
	 * The refusal for a claim that is missing or in another administration.
	 */
	public const CLAIM_NOT_FOUND = 'Expense claim not found';

	/**
	 * The fields of a receipt an agent may set while booking it, besides the claim.
	 */
	public const CORRECTABLE = [
		'amount',
		'currency',
		'receiptDate',
		'category',
		'description',
		'vendorName',
		'costCentreCode',
		'glAccount',
		'receiptNumber',
	];

	/**
	 * What a booked receipt must carry (the Receipt schema's required fields the review screen asks for).
	 */
	public const REQUIRED = ['amount', 'currency', 'receiptDate', 'category'];

	/**
	 * The claim state a receipt may still be added in.
	 */
	public const OPEN_CLAIM_STATE = 'draft';

	/**
	 * The claim state a decision is taken in.
	 */
	public const DECIDABLE_CLAIM_STATE = 'submitted';

	/**
	 * Constructor.
	 *
	 * @param McpAdministrationGate             $gate        The administration gate.
	 * @param ObjectServiceInterface            $objects     Reads the receipt and the claim.
	 * @param ExtractionPrefillServiceInterface $prefill     Records the correction, as the review screen's confirm does.
	 * @param ExtractionDraftCommitter          $committer   Saves the draft and feeds its booking back.
	 * @param ObjectTransitionRunnerInterface   $transitions Runs the claim's declared transition.
	 * @param LoggerInterface                   $logger      The logger.
	 */
	public function __construct(
		private readonly McpAdministrationGate $gate,
		private readonly ObjectServiceInterface $objects,
		private readonly ExtractionPrefillServiceInterface $prefill,
		private readonly ExtractionDraftCommitter $committer,
		private readonly ObjectTransitionRunnerInterface $transitions,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Book a Receipt draft onto a draft expense claim of the same administration.
	 *
	 * @param array<string, mixed> $args The validated arguments: administrationId, receiptId, claimId and corrections.
	 *
	 * @return array<string, mixed> `{receipt}`, or the refusal.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
	 */
	public function bookReceipt(array $args): array {
		$administrationId = (string)$args['administrationId'];
		$refusal = $this->gate->refusal(administrationId: $administrationId, notFound: self::ADMINISTRATION_NOT_FOUND);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$receipt = $this->record(schema: 'Receipt', id: (string)$args['receiptId']);
			$refusal = $this->gate->recordRefusal(administrationId: $administrationId, record: $receipt, notFound: self::RECEIPT_NOT_FOUND);
			if ($refusal !== null) {
				return $refusal;
			}

			$claim = $this->record(schema: 'ExpenseClaimEntry', id: (string)$args['claimId']);
			$refusal = $this->gate->recordRefusal(administrationId: $administrationId, record: $claim, notFound: self::CLAIM_NOT_FOUND);
			if ($refusal !== null) {
				return $refusal;
			}

			$refusal = self::bookingRefusal(receipt: $receipt, claim: $claim);
			if ($refusal !== null) {
				return $refusal;
			}

			$booked = $this->prefill->recordCorrection(existingDraft: $receipt, incomingFields: self::bookingFields(args: $args, claim: $claim));
			$missing = self::missing(receipt: $booked);
			if ($missing !== []) {
				return McpResult::error(code: 'invalid_arguments', message: 'The receipt has no ' . implode(', ', $missing) . '; pass them to book it');
			}

			return ['receipt' => $this->committer->commit(schema: 'Receipt', draft: $booked)];
		} catch (Throwable $e) {
			$this->logger->error(
				'Shillinq MCP: failed to book a receipt',
				['administrationId' => $administrationId, 'receiptId' => $args['receiptId'], 'exception' => $e->getMessage()]
			);
			return McpResult::error(code: 'failed', message: 'Could not book the receipt');
		}//end try
	}//end bookReceipt()

	/**
	 * Approve or reject a submitted expense claim through its declared transition.
	 *
	 * @param array<string, mixed> $args The validated arguments: administrationId, claimId, decision.
	 *
	 * @return array<string, mixed> `{claim}`, or the refusal.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/specs/shillinq-mcp-tool-surface/spec.md
	 */
	public function decideClaim(array $args): array {
		$administrationId = (string)$args['administrationId'];
		$claimId = (string)$args['claimId'];
		$action = 'reject';
		if ($args['decision'] === 'approved') {
			$action = 'approve';
		}

		$refusal = $this->gate->refusal(administrationId: $administrationId, notFound: self::ADMINISTRATION_NOT_FOUND);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$claim = $this->record(schema: 'ExpenseClaimEntry', id: $claimId);
			$refusal = $this->gate->recordRefusal(administrationId: $administrationId, record: $claim, notFound: self::CLAIM_NOT_FOUND);
			if ($refusal !== null) {
				return $refusal;
			}

			$state = (string)($claim['state'] ?? '');
			if ($state !== self::DECIDABLE_CLAIM_STATE) {
				return McpResult::error(
					code: 'conflict',
					message: "Only a submitted claim can be approved or rejected; this claim is '{$state}'"
				);
			}

			$this->transitions->run(objectId: (string)($claim['id'] ?? $claimId), action: $action);

			return ['claim' => ($this->record(schema: 'ExpenseClaimEntry', id: (string)($claim['id'] ?? $claimId)) ?? $claim)];
		} catch (RuntimeException $e) {
			return McpResult::error(code: 'conflict', message: 'The claim could not be ' . $args['decision'] . ': ' . $e->getMessage());
		} catch (Throwable $e) {
			if (str_ends_with(get_class($e), 'NotAuthorizedException') === true) {
				return McpResult::error(code: 'forbidden', message: 'You may not decide this claim');
			}

			$this->logger->error(
				'Shillinq MCP: failed to decide an expense claim',
				['administrationId' => $administrationId, 'claimId' => $claimId, 'exception' => $e->getMessage()]
			);
			return McpResult::error(code: 'failed', message: 'Could not decide the claim');
		}//end try
	}//end decideClaim()

	/**
	 * Why this receipt cannot go onto this claim, or null when it can.
	 *
	 * @param array<string, mixed> $receipt The receipt.
	 * @param array<string, mixed> $claim   The claim.
	 *
	 * @return array<string, mixed>|null The refusal, or null.
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/tasks.md
	 */
	private static function bookingRefusal(array $receipt, array $claim): ?array {
		$state = (string)($claim['state'] ?? '');
		if ($state !== self::OPEN_CLAIM_STATE) {
			return McpResult::error(
				code: 'conflict',
				message: "A receipt can only be added to a draft claim; this claim is '{$state}'"
			);
		}

		$linked = (string)($receipt['claimId'] ?? '');
		if ($linked !== '' && in_array($linked, self::identifiers(record: $claim), true) === false) {
			return McpResult::error(code: 'conflict', message: 'This receipt is already booked on another claim');
		}

		return null;
	}//end bookingRefusal()

	/**
	 * The fields the booking writes: the corrections passed, and the claim.
	 *
	 * @param array<string, mixed> $args  The arguments.
	 * @param array<string, mixed> $claim The claim.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/tasks.md
	 */
	private static function bookingFields(array $args, array $claim): array {
		$fields = array_intersect_key($args, array_flip(self::CORRECTABLE));
		$fields['claimId'] = (string)($claim['id'] ?? $args['claimId']);

		return $fields;
	}//end bookingFields()

	/**
	 * The required fields a receipt still lacks.
	 *
	 * @param array<string, mixed> $receipt The receipt.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/tasks.md
	 */
	private static function missing(array $receipt): array {
		$missing = [];
		foreach (self::REQUIRED as $field) {
			$value = ($receipt[$field] ?? null);
			if ($field === 'amount') {
				if (is_numeric($value) === false || (float)$value < 0) {
					$missing[] = $field;
				}

				continue;
			}

			if (trim((string)$value) === '') {
				$missing[] = $field;
			}
		}

		return $missing;
	}//end missing()

	/**
	 * The ids a record answers to.
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/tasks.md
	 */
	private static function identifiers(array $record): array {
		return array_values(array_filter(
			[(string)($record['id'] ?? ''), (string)($record['uuid'] ?? '')],
			static fn (string $id): bool => $id !== ''
		));
	}//end identifiers()

	/**
	 * One record of the shillinq register by id.
	 *
	 * @param string $schema The schema.
	 * @param string $id     The id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/mcp-action-tool-surface/tasks.md
	 */
	private function record(string $schema, string $id): ?array {
		return ObjectIdentifier::findOne(scoped: $this->objects->setRegister('shillinq')->setSchema($schema), id: $id);
	}//end record()
}//end class
