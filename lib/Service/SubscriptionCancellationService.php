<?php

/**
 * Subscription Cancellation Service
 *
 * Ends a customer's own subscription when portaliq forwards the
 * `cancel-subscription` action (sales-cancellation REQ-SCX-003).
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-cancellation/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\SubscriptionCancellationServiceInterface;
use OCA\Shillinq\Lifecycle\CancellationReasonGuard;
use OCA\Shillinq\Portal\PortalSubjectResolver;
use OCA\Shillinq\Util\ObjectIdentifier;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * End the customer's own running subscription through its lifecycle.
 *
 * Ownership is checked here, never taken from the request: the subject
 * assertion names the CustomerMaster, and the profile must bill that one. The
 * end runs through the profile's own end transition, as the app with nobody
 * signed in, so CancellationReasonGuard lets the reason be left out and
 * RecordCancellationAction records the customer as the one who cancelled.
 *
 * @spec openspec/changes/sales-cancellation/tasks.md#task-2.1
 */
class SubscriptionCancellationService implements SubscriptionCancellationServiceInterface {

	/**
	 * OpenRegister's object service, read through the container.
	 *
	 * @var string
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * OpenRegister's transition engine, read through the container.
	 *
	 * @var string
	 */
	private const TRANSITION_ENGINE = 'OCA\\OpenRegister\\Service\\Lifecycle\\TransitionEngine';

	/**
	 * The register.
	 *
	 * @var string
	 */
	private const REGISTER = 'shillinq';

	/**
	 * The end transition per state a profile can be ended from.
	 *
	 * @var array<string, string>
	 */
	private const END_TRANSITIONS = [
		'active' => 'endFromActive',
		'paused' => 'endFromPaused',
	];

	/**
	 * The longest note kept.
	 *
	 * @var int
	 */
	private const MAX_TEXT = 1000;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface    $container The container, for OpenRegister's services.
	 * @param LoggerInterface       $logger    The logger.
	 * @param PortalSubjectResolver $subjects  Resolves the subject to its CustomerMaster.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly PortalSubjectResolver $subjects = new PortalSubjectResolver(),
	) {
	}//end __construct()

	/**
	 * End a customer's own subscription at their request.
	 *
	 * @param array<string, mixed> $claims     The verified portal subject claims.
	 * @param string               $profileId  The RecurringInvoiceProfile id.
	 * @param string               $reasonCode The reason the customer chose, or ''.
	 * @param string               $reasonText The customer's note, or ''.
	 *
	 * @return string CANCELLED, FORBIDDEN or DOWNSTREAM_ERROR.
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md#task-2.1
	 */
	public function cancel(array $claims, string $profileId, string $reasonCode = '', string $reasonText = ''): string {
		$profileId = trim($profileId);
		if ((string)($claims['audience'] ?? '') !== 'customer' || $this->subjects->isOpaqueId(target: $profileId) === false) {
			return self::FORBIDDEN;
		}

		try {
			$objectService = $this->container->get(self::OBJECT_SERVICE);
			$customerMasterId = $this->subjects->customerMasterId(
				objectService: $objectService,
				subjectRef: (string)($claims['sub'] ?? ''),
				audience: 'customer',
			);
			if ($customerMasterId === null) {
				return self::FORBIDDEN;
			}

			$profile = $this->ownedProfile(objectService: $objectService, profileId: $profileId, customerMasterId: $customerMasterId);
			if ($profile === null) {
				return self::FORBIDDEN;
			}

			$status = (string)($profile['status'] ?? '');
			if ($status === 'ended') {
				// A double click, or a second tab: the end already stands.
				return self::CANCELLED;
			}

			if (array_key_exists($status, self::END_TRANSITIONS) === false) {
				return self::FORBIDDEN;
			}

			$this->container->get(self::TRANSITION_ENGINE)->transitionAsSystem(
				$profileId,
				self::END_TRANSITIONS[$status],
				AppIdentity::APP_ID,
				self::inputs(reasonCode: $reasonCode, reasonText: $reasonText)
			);
		} catch (Throwable $e) {
			$this->logger->error('Shillinq: ending a subscription from the portal failed', ['exception' => $e->getMessage()]);
			return self::DOWNSTREAM_ERROR;
		}//end try

		return self::CANCELLED;
	}//end cancel()

	/**
	 * The transition inputs: a listed reason or not-given, and the note cut to size.
	 *
	 * @param string $reasonCode The reason the customer chose, or ''.
	 * @param string $reasonText The customer's note, or ''.
	 *
	 * @return array{cancellationReasonCode: string, cancellationReasonText: string|null} The inputs.
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md#task-2.1
	 */
	public static function inputs(string $reasonCode, string $reasonText): array {
		$code = trim($reasonCode);
		if (in_array($code, CancellationReasonGuard::REASON_CODES, true) === false) {
			$code = CancellationReasonGuard::NOT_GIVEN;
		}

		$text = mb_substr(trim($reasonText), 0, self::MAX_TEXT);
		if ($text === '') {
			$text = null;
		}

		return ['cancellationReasonCode' => $code, 'cancellationReasonText' => $text];
	}//end inputs()

	/**
	 * The profile, only when it bills this customer.
	 *
	 * @param object $objectService    OpenRegister's object service.
	 * @param string $profileId        The profile id.
	 * @param string $customerMasterId The subject's CustomerMaster id.
	 *
	 * @return array<string, mixed>|null The profile, or null when it is missing or another customer's.
	 */
	private function ownedProfile(object $objectService, string $profileId, string $customerMasterId): ?array {
		try {
			$found = ObjectIdentifier::findScoped(
				scoped: $objectService->setRegister(self::REGISTER)->setSchema('RecurringInvoiceProfile'),
				id: $profileId,
				named: ['_rbac' => false, '_multitenancy' => false]
			);
		} catch (Throwable $notFound) {
			return null;
		}

		$profile = ObjectIdentifier::recordWithId(candidate: $found);
		if ($profile === null || (string)($profile['customerMasterId'] ?? '') !== $customerMasterId) {
			return null;
		}

		return $profile;
	}//end ownedProfile()
}//end class
