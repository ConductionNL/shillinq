<?php

/**
 * POS Stock Unmatched-Line Notifier.
 *
 * Renders the `pos_stock_unmatched_line` notifications raised by
 * {@see \OCA\Shillinq\Listener\PosStockDecrementListener} for the Nextcloud
 * notification centre. Without a registered INotifier a raised notification
 * is silently discarded at display time, so this class is part of the
 * change's reconciliation/audit surface, not optional plumbing — mirrors
 * {@see \OCA\Shillinq\Notification\DeadlineReminderNotifier}.
 *
 * Subject parameters: posTxnId, productRef (may be empty), quantity, reason
 * ('no_product_ref' | 'no_inventory_stock' | 'unsellable_lot'). i18n keys are
 * English (ADR-005); Dutch translations live in l10n/nl.json.
 *
 * @category Notification
 * @package  OCA\Shillinq\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-pos-decrement/specs/pos-stock-decrement/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Notification;

use OCA\Shillinq\Listener\PosStockDecrementListener;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;

/**
 * Prepares `pos_stock_unmatched_line` notifications for display.
 *
 * @spec openspec/changes/inventory-pos-decrement/specs/pos-stock-decrement/spec.md
 */
class PosStockUnmatchedLineNotifier extends AppNotifier {
	/**
	 * The notification subject this notifier renders.
	 *
	 * @var string
	 */
	protected const SUBJECT = PosStockDecrementListener::NOTIFICATION_SUBJECT_UNMATCHED;

	/**
	 * Prepare a `pos_stock_unmatched_line` notification for display.
	 *
	 * @param INotification $notification The raw notification.
	 * @param string $languageCode The language to render in.
	 *
	 * @return INotification The prepared notification.
	 *
	 * @throws UnknownNotificationException When the notification is not ours.
	 *
	 * @spec openspec/changes/inventory-pos-decrement/specs/pos-stock-decrement/spec.md
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		$l = $this->ownedL10n(notification: $notification, languageCode: $languageCode);
		$parameters = $notification->getSubjectParameters();
		$productRef = (string)($parameters['productRef'] ?? '');
		$posTxnId = (string)($parameters['posTxnId'] ?? '');

		$subject = $l->t('POS sale %1$s: a sold line has no matching product reference', [$posTxnId]);
		if ($productRef !== '') {
			$subject = $l->t('POS sale %1$s: product %2$s could not be matched to inventory', [$posTxnId, $productRef]);
		}

		$notification->setParsedSubject($subject);

		$notification->setParsedMessage(
			$l->t('Stock was not decremented for this line. Review it in Shillinq inventory reconciliation.')
		);

		return $notification;
	}//end prepare()
}//end class
