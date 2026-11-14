<?php

/**
 * ICP statement controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Prepare, download and file the ICP statement of a period (REQ-ICR-002,
 * REQ-ICR-003). Every endpoint is #[NoAdminRequired] and refuses an
 * administration the caller is not a member of with 404 before anything is
 * read or written (REQ-ICP-001).
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
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DomainException;
use InvalidArgumentException;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Service\Icp\IcpStatementFiledException;
use OCA\Shillinq\Service\Icp\IcpStatementFiler;
use OCA\Shillinq\Service\Icp\IcpStatementPreparer;
use OCA\Shillinq\Service\Jurisdiction\JurisdictionRefusedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The ICP page's prepare, download and file endpoints.
 *
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.1
 */
class IcpStatementController extends Controller {

	/**
	 * Identifier validation pattern (short slugs only).
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * Construct the controller.
	 *
	 * @param IRequest                              $request         The request.
	 * @param IcpStatementPreparer                  $preparer        Prepares the statement.
	 * @param IcpStatementFiler                     $filer           Composes and files the instance.
	 * @param AdministrationContextServiceInterface $administrations The caller's memberships.
	 * @param IUserSession                          $userSession     The session.
	 * @param LoggerInterface                       $logger          Logger (no traces to the client).
	 */
	public function __construct(
		IRequest $request,
		private readonly IcpStatementPreparer $preparer,
		private readonly IcpStatementFiler $filer,
		private readonly AdministrationContextServiceInterface $administrations,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Prepare the period's statement from the books and write its draft.
	 *
	 * Body: `period` (YYYY-Qn or YYYY-MM) and `administration_id`.
	 *
	 * @return JSONResponse 200 with { statement, reconciliation, replaced }; 409
	 *                      when the period's statement was filed; 400 / 401 / 404 / 422 / 500.
	 *
	 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.1
	 */
	#[NoAdminRequired]
	public function prepare(): JSONResponse {
		$scope = $this->scope();
		if ($scope instanceof JSONResponse) {
			return $scope;
		}

		try {
			return new JSONResponse(
				$this->preparer->prepare(administrationId: $scope['administrationId'], period: $scope['period']),
				Http::STATUS_OK
			);
		} catch (IcpStatementFiledException $e) {
			return new JSONResponse(['error' => 'statement-filed', 'message' => $e->getMessage()], Http::STATUS_CONFLICT);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (JurisdictionRefusedException $e) {
			return self::jurisdictionRefusal(failure: $e);
		} catch (\Throwable $e) {
			return $this->failure(action: 'prepare the ICP statement', failure: $e, scope: $scope);
		}
	}//end prepare()

	/**
	 * Download the XBRL instance of the period's statement.
	 *
	 * Query: `period` and `administration_id`.
	 *
	 * @return DataDownloadResponse|JSONResponse The XML, or 404 when nothing was prepared.
	 *
	 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.3
	 */
	#[NoAdminRequired]
	public function xml(): DataDownloadResponse|JSONResponse {
		$scope = $this->scope();
		if ($scope instanceof JSONResponse) {
			return $scope;
		}

		try {
			$xml = $this->filer->instanceXml(administrationId: $scope['administrationId'], period: $scope['period']);
		} catch (DomainException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			return $this->failure(action: 'compose the ICP instance', failure: $e, scope: $scope);
		}

		return new DataDownloadResponse($xml, 'icp-opgaaf-' . $scope['period'] . '.xml', 'application/xml');
	}//end xml()

	/**
	 * Whether "File" is offered: filing through Digipoort is available.
	 *
	 * @return JSONResponse 200 with { available }; 401 when not logged in.
	 *
	 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.4
	 */
	#[NoAdminRequired]
	public function filing(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(['available' => $this->filer->isAvailable()], Http::STATUS_OK);
	}//end filing()

	/**
	 * File the period's finalised statement through Digipoort.
	 *
	 * Body: `period` and `administration_id`.
	 *
	 * @return JSONResponse 200 with { status, deliveryStatus, reference }; 409
	 *                      when it cannot be filed (and why); 400 / 401 / 404 / 500.
	 *
	 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#task-2.4
	 */
	#[NoAdminRequired]
	public function file(): JSONResponse {
		$scope = $this->scope();
		if ($scope instanceof JSONResponse) {
			return $scope;
		}

		try {
			return new JSONResponse(
				$this->filer->file(administrationId: $scope['administrationId'], period: $scope['period']),
				Http::STATUS_OK
			);
		} catch (DomainException $e) {
			return new JSONResponse(['error' => 'not-filed', 'message' => $e->getMessage()], Http::STATUS_CONFLICT);
		} catch (\Throwable $e) {
			return $this->failure(action: 'file the ICP statement', failure: $e, scope: $scope);
		}
	}//end file()

	/**
	 * The logged-in caller's period and administration, or the refusal.
	 *
	 * @return array{period:string,administrationId:string}|JSONResponse
	 */
	private function scope(): array|JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		$period = trim((string)$this->request->getParam('period', $this->request->getParam('period_id', '')));
		$administrationId = trim((string)$this->request->getParam('administration_id', $this->request->getParam('administrationId', '')));
		foreach (['period' => $period, 'administration_id' => $administrationId] as $label => $value) {
			if ($value === '') {
				return new JSONResponse(['error' => $label . ' is required'], Http::STATUS_BAD_REQUEST);
			}

			if (preg_match(self::ID_PATTERN, $value) !== 1) {
				return new JSONResponse(['error' => $label . ' must be a valid identifier'], Http::STATUS_BAD_REQUEST);
			}
		}

		if ($this->administrations->canAccess(administrationId: $administrationId) === false) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		return ['period' => $period, 'administrationId' => $administrationId];
	}//end scope()

	/**
	 * A 422 for an administration not kept under Dutch rules.
	 *
	 * @param JurisdictionRefusedException $failure The refusal.
	 *
	 * @return JSONResponse
	 */
	private static function jurisdictionRefusal(JurisdictionRefusedException $failure): JSONResponse {
		return new JSONResponse(
			['error' => 'jurisdiction-refused', 'message' => $failure->getMessage(), 'jurisdiction' => $failure->getJurisdiction()],
			Http::STATUS_UNPROCESSABLE_ENTITY
		);
	}//end jurisdictionRefusal()

	/**
	 * Log a failure and answer a generic 500.
	 *
	 * @param string                $action  What failed.
	 * @param \Throwable            $failure The failure.
	 * @param array<string,string>  $scope   Administration and period.
	 *
	 * @return JSONResponse
	 */
	private function failure(string $action, \Throwable $failure, array $scope): JSONResponse {
		$this->logger->error('IcpStatementController: failed to ' . $action, ($scope + ['exception' => $failure->getMessage()]));

		return new JSONResponse(['error' => 'Failed to ' . $action], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end failure()
}//end class
