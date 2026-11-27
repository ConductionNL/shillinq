<?php

/**
 * Grant Accountability Controller
 *
 * The grant page's report action: it stores the grant's accountability report
 * and, when the grant's amount requires one, a pending auditor statement
 * (public-sector-grant-accountability REQ-SUBV-012). And the SiSa appendix of
 * an administration's specific grants for a year, as a table or as the CSV for
 * the annual accounts (REQ-SISA-012, REQ-SISA-013). And the EU project
 * page's proposal of a declaration for a quarter (REQ-EUF-012).
 *
 * #[NoAdminRequired]: any member of the administration may use them. The grant
 * is read with OpenRegister's RBAC as the caller, and a grant or appendix of an
 * administration the caller cannot access answers 404, as one that does not
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
 * @spec openspec/changes/archive/2026-11-24-public-sector-grant-accountability/specs/bookkeeping-subsidie-verantwoording/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DateTimeImmutable;
use DomainException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Kernel\Contracts\EuDeclarationBuilderInterface;
use OCA\Shillinq\Kernel\Contracts\GrantSettlementInterface;
use OCA\Shillinq\Kernel\Contracts\SisaAppendixInterface;
use OCA\Shillinq\Support\RegisterSlug;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The grant accountability endpoints.
 *
 * @spec openspec/changes/archive/2026-11-24-public-sector-grant-accountability/specs/bookkeeping-subsidie-verantwoording/spec.md
 */
class GrantAccountabilityController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                              $request       The request.
	 * @param GrantSettlementInterface              $settlement    Stores the report.
	 * @param SisaAppendixInterface                 $sisa          The SiSa appendix.
	 * @param EuDeclarationBuilderInterface         $declarations  Proposes EU declarations.
	 * @param ObjectServiceInterface                $objectService OpenRegister's object service.
	 * @param AdministrationContextServiceInterface $context       Administration access.
	 * @param IAppConfig                            $appConfig     App config for the register slug.
	 * @param IL10N                                 $l10n          Translations.
	 * @param LoggerInterface                       $logger        Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly GrantSettlementInterface $settlement,
		private readonly SisaAppendixInterface $sisa,
		private readonly EuDeclarationBuilderInterface $declarations,
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
	 * @spec openspec/changes/archive/2026-11-24-public-sector-grant-accountability/specs/bookkeeping-subsidie-verantwoording/spec.md#requirement-the-accountability-report-is-generated-from-the-grant-req-subv-012
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
	 * The SiSa appendix of an administration for a year.
	 *
	 * Query: `administrationId` (default the active administration), `year`
	 * and `format` (`csv` for the export, else a JSON table).
	 *
	 * @return JSONResponse|DataDownloadResponse The rows and columns, or the CSV.
	 *
	 * @spec openspec/changes/archive/2026-11-24-public-sector-grant-accountability/specs/bookkeeping-sisa-reporting/spec.md#requirement-the-appendix-is-exported-for-the-annual-accounts-req-sisa-013
	 */
	#[NoAdminRequired]
	public function sisa(): JSONResponse|DataDownloadResponse {
		if ($this->context->currentUserId() === null) {
			return $this->message(text: 'Not logged in', status: Http::STATUS_UNAUTHORIZED);
		}

		$administrationId = trim((string)$this->request->getParam('administrationId', ''));
		if ($administrationId === '') {
			$administrationId = (string)($this->context->buildContext()['activeAdministrationId'] ?? '');
		}

		if ($administrationId === '' || $this->context->canAccess(administrationId: $administrationId) === false) {
			return $this->message(text: 'Administration not found', status: Http::STATUS_NOT_FOUND);
		}

		$year = trim((string)$this->request->getParam('year', ''));
		if (preg_match('/^\d{4}$/', $year) !== 1) {
			return $this->message(text: 'Choose the year of the appendix, for example 2026.', status: Http::STATUS_BAD_REQUEST);
		}

		try {
			$rows = $this->sisa->forYear(administrationId: $administrationId, fiscalYear: $year);
		} catch (Throwable $e) {
			$this->logger->error('GrantAccountabilityController: SiSa appendix failed', ['exception' => $e->getMessage()]);
			return $this->message(text: 'The SiSa appendix could not be loaded.', status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ((string)$this->request->getParam('format', '') === 'csv') {
			return new DataDownloadResponse(data: $this->sisa->toCsv(rows: $rows), filename: 'sisa-' . $year . '.csv', contentType: 'text/csv');
		}

		return new JSONResponse(data: ['year' => (int)$year, 'administrationId' => $administrationId, 'rows' => $rows]);

	}//end sisa()

	/**
	 * Propose the draft declaration of an EU project for a quarter.
	 *
	 * Body: `period` as a quarter such as `2026-Q3` (default the current
	 * quarter), or `periodFrom` and `periodTo` as dates.
	 *
	 * @param string $id The EU project's id.
	 *
	 * @return JSONResponse The stored draft declaration.
	 *
	 * @spec openspec/changes/archive/2026-11-24-public-sector-grant-accountability/specs/bookkeeping-single-audit-eu-fondsen/spec.md#requirement-a-declaration-bundles-eligible-expenditure-with-evidence-req-euf-012
	 */
	#[NoAdminRequired]
	public function proposeEuDeclaration(string $id): JSONResponse {
		if ($this->context->currentUserId() === null) {
			return $this->message(text: 'Not logged in', status: Http::STATUS_UNAUTHORIZED);
		}

		$project = $this->accessible(schema: 'EuProject', id: $id);
		if ($project === null) {
			return $this->message(text: 'EU project not found', status: Http::STATUS_NOT_FOUND);
		}

		$period = $this->period();
		if ($period === null) {
			return $this->message(text: 'Choose the quarter of the declaration, for example 2026-Q3.', status: Http::STATUS_BAD_REQUEST);
		}

		try {
			$declaration = $this->declarations->propose(project: $project, periodFrom: $period[0], periodTo: $period[1]);
			return new JSONResponse(data: $declaration, statusCode: Http::STATUS_CREATED);
		} catch (DomainException $e) {
			return new JSONResponse(data: ['message' => $this->l10n->t($e->getMessage())], statusCode: Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->error('GrantAccountabilityController: EU declaration failed', ['exception' => $e->getMessage()]);
			return $this->message(text: 'The declaration could not be proposed.', status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end proposeEuDeclaration()

	/**
	 * The requested period: the dates given, else the quarter given, else the current quarter; null when unreadable.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private function period(): ?array {
		$from = trim((string)$this->request->getParam('periodFrom', ''));
		$to = trim((string)$this->request->getParam('periodTo', ''));
		if ($from !== '' || $to !== '') {
			return [$from, $to];
		}

		$quarter = trim((string)$this->request->getParam('period', ''));
		if ($quarter === '') {
			$quarter = gmdate('Y') . '-Q' . (string)intdiv(((int)gmdate('n')) + 2, 3);
		}

		if (preg_match('/^(\d{4})-Q([1-4])$/', $quarter, $match) !== 1) {
			return null;
		}

		$firstMonth = (((int)$match[2] - 1) * 3) + 1;
		$start = sprintf('%s-%02d-01', $match[1], $firstMonth);
		$end = (new DateTimeImmutable($start))->modify('+2 months')->format('Y-m-t');
		return [$start, $end];

	}//end period()

	/**
	 * The grant, read as the caller, when its administration is one the caller can access.
	 *
	 * @param string $id The grant's id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function grant(string $id): ?array {
		return $this->accessible(schema: 'Subsidie', id: $id);

	}//end grant()

	/**
	 * A record of a schema, read as the caller, when its administration is one the caller can access.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The record's id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function accessible(string $schema, string $id): ?array {
		if (trim($id) === '') {
			return null;
		}

		try {
			$scoped = $this->objectService->setRegister(RegisterSlug::of(config: $this->appConfig))->setSchema($schema);
			$record = ObjectIdentifier::findOneStrict(scoped: $scoped, id: $id);
		} catch (Throwable) {
			return null;
		}

		if ($record === null) {
			return null;
		}

		$administrationId = (string)($record['administrationId'] ?? '');
		if ($administrationId === '' || $this->context->canAccess(administrationId: $administrationId) === false) {
			return null;
		}

		$record['id'] = (string)($record['id'] ?? $id);
		return $record;

	}//end accessible()

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
