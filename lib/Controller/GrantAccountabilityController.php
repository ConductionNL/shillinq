<?php

/**
 * Grant Accountability Controller
 *
 * The grant page's report action: it stores the grant's accountability report
 * and, when the grant's amount requires one, a pending auditor statement
 * (public-sector-grant-accountability REQ-SUBV-012).
 *
 * #[NoAdminRequired]: any member of the grant's administration may report on
 * it. The grant is read with OpenRegister's RBAC as the caller, and a grant of
 * an administration the caller cannot access answers 404, as one that does not
 * exist.
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
 * @spec openspec/changes/public-sector-grant-accountability/specs/bookkeeping-subsidie-verantwoording/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DomainException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Kernel\Contracts\GrantSettlementInterface;
use OCA\Shillinq\Support\RegisterSlug;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The grant accountability endpoints.
 *
 * @spec openspec/changes/public-sector-grant-accountability/specs/bookkeeping-subsidie-verantwoording/spec.md
 */
class GrantAccountabilityController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                              $request       The request.
	 * @param GrantSettlementInterface              $settlement    Stores the report.
	 * @param ObjectServiceInterface                $objectService OpenRegister's object service.
	 * @param AdministrationContextServiceInterface $context       Administration access.
	 * @param IAppConfig                            $appConfig     App config for the register slug.
	 * @param IL10N                                 $l10n          Translations.
	 * @param LoggerInterface                       $logger        Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly GrantSettlementInterface $settlement,
		private readonly ObjectServiceInterface $objectService,
		private readonly AdministrationContextServiceInterface $context,
		private readonly IAppConfig $appConfig,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Store the accountability report of a grant.
	 *
	 * @param string $id The grant's id.
	 *
	 * @return JSONResponse The stored report's id and whether an auditor statement is required.
	 *
	 * @spec openspec/changes/public-sector-grant-accountability/specs/bookkeeping-subsidie-verantwoording/spec.md#requirement-the-accountability-report-is-generated-from-the-grant-req-subv-012
	 */
	#[NoAdminRequired]
	public function report(string $id): JSONResponse {
		if ($this->context->currentUserId() === null) {
			return $this->message(text: 'Not logged in', status: Http::STATUS_UNAUTHORIZED);
		}

		$grant = $this->grant(id: $id);
		if ($grant === null) {
			return $this->message(text: 'Grant not found', status: Http::STATUS_NOT_FOUND);
		}

		try {
			return new JSONResponse(data: $this->settlement->report(grant: $grant), statusCode: Http::STATUS_CREATED);
		} catch (DomainException $e) {
			return new JSONResponse(data: ['message' => $this->l10n->t($e->getMessage())], statusCode: Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->error('GrantAccountabilityController: report failed', ['exception' => $e->getMessage()]);
			return $this->message(text: 'The accountability report could not be stored.', status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end report()

	/**
	 * The grant, read as the caller, when its administration is one the caller can access.
	 *
	 * @param string $id The grant's id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function grant(string $id): ?array {
		if (trim($id) === '') {
			return null;
		}

		try {
			$scoped = $this->objectService->setRegister(RegisterSlug::of(config: $this->appConfig))->setSchema('Subsidie');
			$grant = ObjectIdentifier::findOneStrict(scoped: $scoped, id: $id);
		} catch (Throwable) {
			return null;
		}

		if ($grant === null) {
			return null;
		}

		$administrationId = (string)($grant['administrationId'] ?? '');
		if ($administrationId === '' || $this->context->canAccess(administrationId: $administrationId) === false) {
			return null;
		}

		$grant['id'] = (string)($grant['id'] ?? $id);
		return $grant;

	}//end grant()

	/**
	 * A translated message response.
	 *
	 * @param string $text   The message.
	 * @param int    $status The HTTP status.
	 *
	 * @return JSONResponse
	 */
	private function message(string $text, int $status): JSONResponse {
		return new JSONResponse(data: ['message' => $this->l10n->t($text)], statusCode: $status);

	}//end message()
}//end class
