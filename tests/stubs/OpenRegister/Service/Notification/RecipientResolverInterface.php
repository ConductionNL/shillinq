<?php

/**
 * Minimal RecipientResolverInterface stub for unit tests that implement
 * OpenRegister's `expression` recipient-resolver contract without depending
 * on the OpenRegister app being autoloaded. Mirrors
 * openregister/lib/Service/Notification/RecipientResolverInterface.php.
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Stub for OCA\OpenRegister\Service\Notification\RecipientResolverInterface
 * used by shillinq tests.
 */
interface RecipientResolverInterface {
	/**
	 * Resolve the recipient uids for a notification dispatch.
	 *
	 * @param ObjectEntity $object The object the event happened on.
	 * @param array<string, mixed> $context Trigger-specific extras.
	 *
	 * @return array<int, string> List of Nextcloud uids.
	 */
	public function resolve(ObjectEntity $object, array $context): array;
}//end interface
