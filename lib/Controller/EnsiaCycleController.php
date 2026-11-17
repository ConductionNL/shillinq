<?php

/**
 * ENSIA Cycle Controller
 *
 * Downloads of an ENSIA cycle: its submission XML
 * (GET /api/v1/public-sector/ensia/{id}/xml, REQ-ENSIA-012) and its college
 * statement (GET /api/v1/public-sector/ensia/{id}/statement). Both are
 * #[NoAdminRequired] with a session guard and a per-administration guard in
 * the method body (ADR-005): another administration's cycle is masked as 404.
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
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-ensia-zelfevaluatie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Kernel\Contracts\EnsiaCycleStepsInterface;
use OCA\Shillinq\PaymentRun\RenderedFile;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Serves the files an ENSIA cycle's transitions stored.
 *
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-ensia-zelfevaluatie/spec.md
 */
class EnsiaCycleController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                              $request          The request.
	 * @param EnsiaCycleStepsInterface              $steps            Reads the cycle's stored files.
	 * @param AdministrationContextServiceInterface $administrations  The caller's administrations (ADR-005).
	 * @param ObjectServiceInterface                $objectService    OpenRegister's object service.
	 * @param RegisterLocation                      $registerLocation Where the register lives.
	 * @param IUserSession                          $session          The user session.
	 * @param LoggerInterface                       $logger           Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly EnsiaCycleStepsInterface $steps,
		private readonly AdministrationContextServiceInterface $administrations,
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocation $registerLocation,
		private readonly IUserSession $session,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Download the submission XML of a submitted cycle.
	 *
	 * 200 with the XML as an attachment; 401 without a user; 404 for a missing
	 * cycle or one of another administration; 409 when the cycle has no XML yet.
	 *
	 * @param string $id The ENSIAJaarcyclus id.
	 *
	 * @return DataDownloadResponse|JSONResponse
	 *
	 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-ensia-zelfevaluatie/spec.md
	 */
	#[NoAdminRequired]
	public function xml(string $id): DataDownloadResponse|JSONResponse {
		return $this->download(id: $id, kind: 'xml');
	}//end xml()

	/**
	 * Download the college statement of an approved cycle.
	 *
	 * 200 with the DOCX as an attachment; 401 without a user; 404 for a missing
	 * cycle or one of another administration; 409 when no statement is rendered yet.
	 *
	 * @param string $id The ENSIAJaarcyclus id.
	 *
	 * @return DataDownloadResponse|JSONResponse
	 *
	 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-ensia-zelfevaluatie/spec.md
	 */
	#[NoAdminRequired]
	public function statement(string $id): DataDownloadResponse|JSONResponse {
		return $this->download(id: $id, kind: 'statement');
	}//end statement()

	/**
	 * One stored file of an accessible cycle.
	 *
	 * @param string $id   The cycle id.
	 * @param string $kind `xml` or `statement`.
	 *
	 * @return DataDownloadResponse|JSONResponse
	 */
	private function download(string $id, string $kind): DataDownloadResponse|JSONResponse {
		if ($this->session->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$cycle = $this->accessibleCycle(id: $id);
		if ($cycle === null) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		$file = $this->fileOf(cycle: $cycle, kind: $kind);
		if ($file === null) {
			return new JSONResponse(['error' => 'not-generated'], Http::STATUS_CONFLICT);
		}

		return new DataDownloadResponse($file->content, $file->fileName, $file->mimeType);
	}//end download()

	/**
	 * The stored file of the given kind.
	 *
	 * @param array<string, mixed> $cycle The cycle.
	 * @param string               $kind  `xml` or `statement`.
	 *
	 * @return RenderedFile|null
	 */
	private function fileOf(array $cycle, string $kind): ?RenderedFile {
		if ($kind === 'xml') {
			return $this->steps->submissionFile(cycle: $cycle);
		}

		return $this->steps->statementFile(cycle: $cycle);
	}//end fileOf()

	/**
	 * The cycle, when it exists and belongs to one of the caller's administrations.
	 *
	 * @param string $id The cycle id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function accessibleCycle(string $id): ?array {
		try {
			$cycle = ObjectIdentifier::findOne(
				scoped: $this->objectService->setRegister($this->registerLocation->getRegisterSlug())->setSchema('ENSIAJaarcyclus'),
				id: trim($id)
			);
		} catch (Throwable $e) {
			$this->logger->warning('EnsiaCycleController: cycle lookup failed', ['exception' => $e->getMessage()]);
			return null;
		}

		if ($cycle === null) {
			return null;
		}

		$administrationId = (string)($cycle['administrationId'] ?? '');
		if ($administrationId === '' || $this->administrations->canAccess(administrationId: $administrationId) === false) {
			return null;
		}

		return $cycle;
	}//end accessibleCycle()
}//end class
