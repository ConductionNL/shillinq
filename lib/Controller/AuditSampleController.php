<?php

/**
 * Audit Sample Controller
 *
 * Draws an audit sample for a protocol from its posted ledger lines
 * (POST /api/bado/controleprotocol/samples, REQ-011, REQ-012).
 * #[NoAdminRequired] with a session guard and the protocol's administration
 * checked in the method body (ADR-005): another administration's protocol
 * is masked as 404.
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
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-bado-controleprotocol/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DomainException;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Kernel\Contracts\BadoControleprotocolServiceInterface;
use OCA\Shillinq\Service\PublicSector\LedgerSampling;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The draw action of the audit protocol page.
 *
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-bado-controleprotocol/spec.md
 */
class AuditSampleController extends Controller {

	/**
	 * The protocol id shape.
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\-]{1,64}$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest                              $request   The request.
	 * @param LedgerSampling                        $sampling  Draws the sample.
	 * @param BadoControleprotocolServiceInterface  $protocols Resolves a protocol's administration.
	 * @param AdministrationContextServiceInterface $context   The caller's administrations (ADR-005).
	 * @param IUserSession                          $session   The user session.
	 * @param LoggerInterface                       $logger    Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly LedgerSampling $sampling,
		private readonly BadoControleprotocolServiceInterface $protocols,
		private readonly AdministrationContextServiceInterface $context,
		private readonly IUserSession $session,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Draw a sample.
	 *
	 * Request: `protocol_id`, `size`, optional `seed` (a positive integer;
	 * the same seed over the same population draws the same lines) and
	 * `account_prefix`. 201 with `{id, sampledLineIds, reproducibleSeed,
	 * populationSize}`; 400 on a malformed request; 401 without a user; 404
	 * for a protocol of another administration; 422 when the size does not
	 * fit the population.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-bado-controleprotocol/spec.md
	 */
	#[NoAdminRequired]
	public function draw(): JSONResponse {
		if ($this->session->getUser() === null) {
			return new JSONResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
		}

		$protocolId = trim((string)$this->request->getParam('protocol_id', ''));
		if (preg_match(self::ID_PATTERN, $protocolId) !== 1) {
			return new JSONResponse(['error' => 'protocol_id is required'], Http::STATUS_BAD_REQUEST);
		}

		$draw = self::drawRequest(
			size: (string)$this->request->getParam('size', ''),
			seed: (string)$this->request->getParam('seed', '')
		);
		if ($draw === null) {
			return new JSONResponse(['error' => 'size and seed are positive whole numbers'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->accessible(protocolId: $protocolId) === false) {
			return new JSONResponse(['error' => 'Controleprotocol not found'], Http::STATUS_NOT_FOUND);
		}

		$draw['accountPrefix'] = trim((string)$this->request->getParam('account_prefix', ''));
		try {
			$sample = $this->sampling->draw(protocolId: $protocolId, request: $draw);
		} catch (DomainException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->error('AuditSampleController: draw failed', ['protocolId' => $protocolId, 'exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'The sample could not be drawn.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($sample, Http::STATUS_CREATED);
	}//end draw()

	/**
	 * The size and seed of a draw, or null when either is malformed.
	 *
	 * @param string $size The size as sent.
	 * @param string $seed The seed as sent, '' for a new one.
	 *
	 * @return array{size: int, seed: int|null}|null
	 */
	private static function drawRequest(string $size, string $seed): ?array {
		$size = trim($size);
		$seed = trim($seed);
		if (preg_match('/^[1-9]\d{0,5}$/', $size) !== 1 || ($seed !== '' && preg_match('/^[1-9]\d{0,9}$/', $seed) !== 1)) {
			return null;
		}

		if ($seed !== '' && (int)$seed > 2147483647) {
			return null;
		}

		return ['size' => (int)$size, 'seed' => ($seed === '' ? null : (int)$seed)];
	}//end drawRequest()

	/**
	 * Whether the protocol belongs to one of the caller's administrations.
	 *
	 * @param string $protocolId The protocol id.
	 *
	 * @return bool
	 */
	private function accessible(string $protocolId): bool {
		try {
			$administrationId = $this->protocols->organisationIdFor(protocolId: $protocolId);
		} catch (Throwable $e) {
			$this->logger->warning('AuditSampleController: protocol lookup failed', ['exception' => $e->getMessage()]);
			return false;
		}

		return $administrationId !== null && $this->context->canAccess(administrationId: $administrationId) === true;
	}//end accessible()
}//end class
