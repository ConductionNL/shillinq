<?php

/**
 * Shillinq LedgerSealController
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ledger-sealed-entries/specs/bookkeeping-audit-trail/spec.md#requirement-a-controller-checks-the-seal-chain-req-lse-002
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Service\Ledger\LedgerSealService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The Check ledger seal action of the general ledger page: walks the seal chain
 * of one administration and names the first transaction that no longer matches.
 * Only a Nextcloud admin or a controller of that administration may run it (ADR-005).
 *
 * @spec openspec/changes/ledger-sealed-entries/specs/bookkeeping-audit-trail/spec.md#requirement-a-controller-checks-the-seal-chain-req-lse-002
 */
class LedgerSealController extends Controller {

	/**
	 * The administration role that may check the seal chain.
	 */
	public const CHECK_ROLE = 'controller';

	/**
	 * Constructor.
	 *
	 * @param IRequest                              $request  The request.
	 * @param LedgerSealService                     $seals    Walks the seal chain.
	 * @param AdministrationContextServiceInterface $context  Administration access and roles.
	 * @param IUserSession                          $session  The signed-in user.
	 * @param IGroupManager                         $groups   Decides whether the user is a Nextcloud admin.
	 * @param IL10N                                 $l10n     Translations.
	 * @param LoggerInterface                       $logger   Records a failed check.
	 *
	 * @spec openspec/changes/ledger-sealed-entries/tasks.md#task-2.1
	 */
	public function __construct(
		IRequest $request,
		private readonly LedgerSealService $seals,
		private readonly AdministrationContextServiceInterface $context,
		private readonly IUserSession $session,
		private readonly IGroupManager $groups,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Check the seal chain of the administration the request names.
	 *
	 * @return JSONResponse `{intact, entries, transactionNumber, transactionId, reason, message}`, or 401 / 403 / 404.
	 *
	 * @spec openspec/changes/ledger-sealed-entries/specs/bookkeeping-audit-trail/spec.md#requirement-a-controller-checks-the-seal-chain-req-lse-002
	 */
	#[NoAdminRequired]
	public function verify(): JSONResponse {
		$refused = $this->refuse();
		if ($refused !== null) {
			return $refused;
		}

		try {
			$result = $this->seals->verify(administrationId: $this->administrationId());
		} catch (Throwable $e) {
			$this->logger->error('LedgerSealController: the seal check failed', ['exception' => $e->getMessage()]);
			return $this->message(text: $this->l10n->t('The ledger seal could not be checked.'), status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(array_merge($result, ['message' => $this->sentence(result: $result)]));
	}//end verify()

	/**
	 * The refusal for an anonymous caller, an administration the user cannot
	 * reach, or a user who is neither admin nor its controller; null otherwise.
	 *
	 * @return JSONResponse|null
	 */
	private function refuse(): ?JSONResponse {
		$user = $this->session->getUser();
		if ($user === null) {
			return $this->message(text: $this->l10n->t('Not logged in'), status: Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = $this->administrationId();
		if ($administrationId === '' || $this->context->canAccess(administrationId: $administrationId) === false) {
			return $this->message(text: $this->l10n->t('Administration not found'), status: Http::STATUS_NOT_FOUND);
		}

		if ($this->groups->isAdmin($user->getUID()) === true || $this->callerRole(administrationId: $administrationId) === self::CHECK_ROLE) {
			return null;
		}

		return $this->message(text: $this->l10n->t('Only a controller can check the ledger seal.'), status: Http::STATUS_FORBIDDEN);
	}//end refuse()

	/**
	 * The caller's role in the administration ('' without one); the last matching entry wins.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return string
	 */
	private function callerRole(string $administrationId): string {
		$role = '';
		foreach ((array)($this->context->buildContext()['administrations'] ?? []) as $administration) {
			if (is_array($administration) === true && (string)($administration['administrationId'] ?? '') === $administrationId) {
				$role = (string)($administration['role'] ?? '');
			}
		}

		return $role;
	}//end callerRole()

	/**
	 * The sentence the page shows for a check result.
	 *
	 * @param array<string, mixed> $result The result of LedgerSealService::verify().
	 *
	 * @return string
	 */
	private function sentence(array $result): string {
		if (($result['intact'] ?? false) === true) {
			return $this->l10n->t('The ledger seal chain is intact over %1$d entries.', [(int)($result['entries'] ?? 0)]);
		}

		$name = (string)($result['transactionNumber'] ?? '');
		if ($name === '') {
			$name = (string)($result['transactionId'] ?? '');
		}

		return $this->l10n->t('Transaction %1$s is the first entry that no longer matches its seal.', [$name]);
	}//end sentence()

	/**
	 * The administration the request names.
	 *
	 * @return string
	 */
	private function administrationId(): string {
		return trim((string)$this->request->getParam('administrationId', ''));
	}//end administrationId()

	/**
	 * A message response.
	 *
	 * @param string $text   The translated sentence.
	 * @param int    $status The HTTP status.
	 *
	 * @return JSONResponse
	 */
	private function message(string $text, int $status): JSONResponse {
		return new JSONResponse(['message' => $text], $status);
	}//end message()
}//end class
