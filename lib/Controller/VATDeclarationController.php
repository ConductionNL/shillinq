<?php

/**
 * VAT Declaration Controller
 *
 * Tier-3 BTW-rubriek read-only API for the bookkeeping-vat-btw-filing
 * change (issue #127). Exposes the declarations belonging to one
 * VAT return:
 *
 *   GET /api/vat-returns/{returnId}/declarations
 *
 * The endpoint is authenticated (#[NoAdminRequired]) AND scoped to the
 * caller's administration memberships in this controller
 * (AdministrationContextService::accessibleAdministrationIds(), ADR-005 /
 * REQ-MA-001).
 *
 * ⚠️ This paragraph previously claimed "the per-object multitenancy is
 * enforced by OpenRegister's ObjectService". It was not: the query passed
 * no administration term into OpenRegister at all.
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
 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Read-only API for VAT declarations belonging to a return.
 *
 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
 */
class VATDeclarationController extends Controller {
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param ContainerInterface $container DI container for OR's ObjectService.
	 * @param IUserSession $session User session for the authentication guard.
	 * @param AdministrationContextService $context RBAC guard — resolves the user's administration memberships.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly IUserSession $session,
		private readonly AdministrationContextService $context,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List the VATDeclaration rows belonging to one VATReturn.
	 *
	 * @param string $returnId The VATReturn id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
	 */
	#[NoAdminRequired]
	public function listByReturn(string $returnId): JSONResponse {
		if ($this->session->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		if ($returnId === '' || preg_match(pattern: self::ID_PATTERN, subject: $returnId) !== 1) {
			return new JSONResponse(['error' => 'returnId is required'], Http::STATUS_BAD_REQUEST);
		}

		// ADR-005 / REQ-MA-001. VATDeclaration carries administrationId; the
		// scope is the caller's memberships, one query per administration.
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$declarations = [];
			foreach ($this->context->accessibleAdministrationIds() as $administrationId) {
				$declarations = array_merge(
					$declarations,
					$objectService
						->setRegister(register: 'shillinq')
						->setSchema(schema: 'VATDeclaration')
						->findAll(
							[
								'filters' => [
									'returnId' => $returnId,
									'administrationId' => $administrationId,
								],
							]
						)
				);
			}
		} catch (\Throwable $e) {
			$this->logger->error(
				'VATDeclarationController: failed to list declarations',
				['returnId' => $returnId, 'exception' => $e->getMessage()]
			);

			return new JSONResponse(['error' => 'Failed to list declarations'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

		return new JSONResponse(
			[
				'data' => $declarations,
				'total' => count($declarations),
			],
			Http::STATUS_OK
		);

	}//end listByReturn()
}//end class
