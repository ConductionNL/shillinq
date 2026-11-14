<?php

/**
 * Sales Invoice Approval Guard
 *
 * The four-eyes step before a large sales invoice goes out (REQ-SIIC-003).
 * Above the administration's `salesInvoiceApprovalThreshold` a draft cannot
 * be issued: it is sent for approval, and approval is refused to the user who
 * created the invoice.
 *
 *  - canApprove(): the guard of ARInvoice.approve (LedgerPostingRegistration).
 *  - canIssue():   called by RuleComplianceGuard::validateInvoice, because
 *                  ARInvoice.issue takes one `requires`.
 *
 * A refusal the bookkeeper must act on is thrown as a PostingRefusedException,
 * whose message RegisterRequiresGuardAdapter passes on. Anything that cannot be
 * determined (the stored invoice, the creator, the caller) refuses: fail-closed.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IUserSession;

/**
 * Refuses issuing a large invoice unapproved, and approving one's own invoice.
 *
 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-2.2
 */
class SalesInvoiceApprovalGuard {

	/**
	 * Refusal of issuing a draft above the threshold.
	 *
	 * @var string
	 */
	public const MESSAGE_NEEDS_APPROVAL = 'This invoice is above the approval threshold of its administration. '
		. 'Request approval; it can be issued once someone else has approved it.';

	/**
	 * Refusal of an approval by the invoice's creator.
	 *
	 * @var string
	 */
	public const MESSAGE_CREATOR = 'You created this invoice, so you cannot approve it. Someone else must approve it.';

	/**
	 * Refusal when the approver or the creator cannot be identified.
	 *
	 * @var string
	 */
	public const MESSAGE_UNKNOWN = 'The invoice cannot be approved: who created it, or who is approving it, could not be determined.';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService    OpenRegister's object service.
	 * @param RegisterLocation       $registerLocation The register this app's objects live in.
	 * @param IUserSession           $userSession      The approving user.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocation $registerLocation,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Allow ARInvoice.approve only to someone other than the invoice's creator.
	 *
	 * @param array<string,mixed> $invoice The invoice being approved.
	 *
	 * @return bool True to allow.
	 *
	 * @throws PostingRefusedException When the caller created the invoice, or either party is unknown.
	 *
	 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-2.2
	 */
	public function canApprove(array $invoice): bool {
		$approver = trim((string)($this->userSession->getUser()?->getUID() ?? ''));
		$creator = $this->creatorOf(invoice: $invoice);
		if ($approver === '' || $creator === '') {
			throw new PostingRefusedException(self::MESSAGE_UNKNOWN);
		}

		if ($approver === $creator) {
			throw new PostingRefusedException(self::MESSAGE_CREATOR);
		}

		return true;
	}//end canApprove()

	/**
	 * Allow ARInvoice.issue from draft only at or below the threshold.
	 *
	 * An invoice issued from `approved` passed approval. The state it leaves is
	 * read from the stored invoice: the payload already carries the target state.
	 *
	 * @param array<string,mixed> $invoice The invoice being issued.
	 *
	 * @return bool True to allow.
	 *
	 * @throws PostingRefusedException When a draft is above the threshold.
	 *
	 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-2.2
	 */
	public function canIssue(array $invoice): bool {
		$stored = $this->stored(schema: 'ARInvoice', id: $this->idOf(invoice: $invoice));
		if ($stored !== null && (string)($stored['lifecycleState'] ?? '') === 'approved') {
			return true;
		}

		$threshold = $this->thresholdOf(administrationId: (string)($invoice['administrationId'] ?? ''));
		if ($threshold === null) {
			return true;
		}

		if ((float)($invoice['grossAmount'] ?? 0) > $threshold) {
			throw new PostingRefusedException(self::MESSAGE_NEEDS_APPROVAL);
		}

		return true;
	}//end canIssue()

	/**
	 * The user who created the invoice: the owner of the stored object.
	 *
	 * @param array<string,mixed> $invoice The invoice.
	 *
	 * @return string The uid, '' when unknown.
	 */
	private function creatorOf(array $invoice): string {
		$id = $this->idOf(invoice: $invoice);
		if ($id !== '') {
			$entity = ObjectIdentifier::findScoped(
				scoped: $this->objectService->setRegister($this->registerLocation->getRegisterSlug())->setSchema('ARInvoice'),
				id: $id
			);
			if (is_object($entity) === true && method_exists($entity, 'getOwner') === true) {
				return trim((string)($entity->getOwner() ?? ''));
			}
		}

		return '';
	}//end creatorOf()

	/**
	 * The administration's approval threshold, or null when it needs no approval.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return float|null The threshold.
	 *
	 * @throws PostingRefusedException When the invoice names no administration that can be read.
	 */
	private function thresholdOf(string $administrationId): ?float {
		$administration = $this->stored(schema: 'Administration', id: $administrationId);
		if ($administration === null) {
			throw new PostingRefusedException('The invoice cannot be issued: its administration could not be read to check the approval threshold.');
		}

		$threshold = ($administration['salesInvoiceApprovalThreshold'] ?? null);
		if ($threshold === null || $threshold === '' || is_numeric($threshold) === false) {
			return null;
		}

		return (float)$threshold;
	}//end thresholdOf()

	/**
	 * One stored object as an array, or null.
	 *
	 * @param string $schema The schema.
	 * @param string $id     The id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function stored(string $schema, string $id): ?array {
		if (trim($id) === '') {
			return null;
		}

		$entity = ObjectIdentifier::findScoped(
			scoped: $this->objectService->setRegister($this->registerLocation->getRegisterSlug())->setSchema($schema),
			id: $id
		);

		return ObjectIdentifier::recordWithId(candidate: $entity);
	}//end stored()

	/**
	 * The invoice's id.
	 *
	 * @param array<string,mixed> $invoice The invoice.
	 *
	 * @return string The id, '' when it carries none.
	 */
	private function idOf(array $invoice): string {
		return (string)($invoice['id'] ?? ($invoice['@self']['id'] ?? ''));
	}//end idOf()
}//end class
