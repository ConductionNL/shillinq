<?php

/**
 * VAT Return Controller
 *
 * Tier-3 BTW-aangifte HTTP API for the bookkeeping-vat-btw-filing
 * change (issue #127). Exposes the seven endpoints declared in
 * tasks.md:
 *
 *   GET    /api/vat-returns                       — list (paginated, filterable)
 *   GET    /api/vat-returns/{returnId}             — detail
 *   POST   /api/vat-returns                       — create + derive from GL
 *   PUT    /api/vat-returns/{returnId}             — update notes
 *   POST   /api/vat-returns/{returnId}/submit      — submit
 *   POST   /api/vat-returns/{returnId}/rebase      — re-open + re-derive
 *   DELETE /api/vat-returns/{returnId}             — delete (draft only)
 *
 * The endpoints are authenticated (#[NoAdminRequired]); per-object
 * authorisation is delegated to OpenRegister's ObjectService which
 * enforces administration-scoped multitenancy. Future returns are
 * rejected at the controller (REQ-VAT-001); lifecycle status checks
 * live in VATReturnService.
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
 * @spec openspec/changes/bookkeeping-vat-btw-filing/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\VATReturnService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * HTTP API for VAT returns (REQ-VAT-001 .. REQ-VAT-012).
 *
 * @spec openspec/changes/bookkeeping-vat-btw-filing/tasks.md
 */
class VATReturnController extends Controller
{
    private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

    private const PERIOD_VALUES = ['quarter', 'month', 'year'];

    private const REGIME_VALUES = ['standard', 'kor', 'reverse-charge'];

    /**
     * Constructor.
     *
     * @param IRequest           $request   The request object.
     * @param VATReturnService   $service   The VAT return service.
     * @param ContainerInterface $container DI container for OR's ObjectService.
     * @param IUserSession       $session   User session (for actor identity).
     * @param LoggerInterface    $logger    Logger.
     */
    public function __construct(
        IRequest $request,
        private readonly VATReturnService $service,
        private readonly ContainerInterface $container,
        private readonly IUserSession $session,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List VAT returns (paginated, filterable by period / regime / status).
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/bookkeeping-vat-btw-filing/tasks.md
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $filters = $this->buildListFilters();
        $page    = max(1, (int) $this->request->getParam('_page', 1));
        $limit   = max(1, min(200, (int) $this->request->getParam('_limit', 25)));

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $all           = $objectService
                ->setRegister(register: 'shillinq')
                ->setSchema(schema: 'VATReturn')
                ->findAll(['filters' => $filters]);
        } catch (\Throwable $e) {
            $this->logger->error(
                'VATReturnController: failed to list VAT returns',
                ['exception' => $e->getMessage()]
            );

            return new JSONResponse(['error' => 'Failed to list VAT returns'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $total  = count($all);
        $pages  = max(1, (int) ceil($total / $limit));
        $offset = (($page - 1) * $limit);
        $slice  = array_slice($all, $offset, $limit);

        return new JSONResponse(
            [
                'data'  => $slice,
                'total' => $total,
                'page'  => $page,
                'pages' => $pages,
                'limit' => $limit,
            ],
            Http::STATUS_OK
        );

    }//end index()

    /**
     * Return one VAT return by id (with declarations + lines).
     *
     * @param string $returnId The VATReturn id.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/bookkeeping-vat-btw-filing/tasks.md
     */
    #[NoAdminRequired]
    public function show(string $returnId): JSONResponse
    {
        if ($this->validId(id: $returnId) === false) {
            return new JSONResponse(['error' => 'returnId is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $vatReturn     = $objectService->setRegister(register: 'shillinq')->setSchema(schema: 'VATReturn')->find($returnId);
            if (is_array($vatReturn) === false) {
                return new JSONResponse(['error' => 'VAT return not found'], Http::STATUS_NOT_FOUND);
            }

            $declarations = $objectService
                ->setRegister(register: 'shillinq')
                ->setSchema(schema: 'VATDeclaration')
                ->findAll(['filters' => ['returnId' => $returnId]]);
            $lines        = $objectService
                ->setRegister(register: 'shillinq')
                ->setSchema(schema: 'VATLine')
                ->findAll(['filters' => ['returnId' => $returnId]]);
        } catch (\Throwable $e) {
            $this->logger->error(
                'VATReturnController: failed to load VAT return',
                ['returnId' => $returnId, 'exception' => $e->getMessage()]
            );

            return new JSONResponse(['error' => 'Failed to load VAT return'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

        return new JSONResponse(
            [
                'data'         => $vatReturn,
                'declarations' => $declarations,
                'lines'        => $lines,
            ],
            Http::STATUS_OK
        );

    }//end show()

    /**
     * Create a new VAT return (REQ-VAT-001).
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/bookkeeping-vat-btw-filing/tasks.md
     */
    #[NoAdminRequired]
    public function create(): JSONResponse
    {
        $administrationId = trim((string) $this->request->getParam('administrationId', ''));
        $period           = trim((string) $this->request->getParam('period', 'quarter'));
        $periodYear       = (int) $this->request->getParam('periodYear', 0);
        $periodNumber     = (int) $this->request->getParam('periodNumber', 0);
        $regime           = trim((string) $this->request->getParam('regime', 'standard'));

        if ($this->validId(id: $administrationId) === false) {
            return new JSONResponse(['error' => 'administrationId is required'], Http::STATUS_BAD_REQUEST);
        }

        if (in_array($period, self::PERIOD_VALUES, true) === false) {
            return new JSONResponse(['error' => 'period must be one of quarter | month | year'], Http::STATUS_BAD_REQUEST);
        }

        if (in_array($regime, self::REGIME_VALUES, true) === false) {
            return new JSONResponse(['error' => 'regime must be one of standard | kor | reverse-charge'], Http::STATUS_BAD_REQUEST);
        }

        if ($periodYear < 2020 || $periodYear > 2099 || $periodNumber < 1) {
            return new JSONResponse(['error' => 'periodYear / periodNumber must be valid'], Http::STATUS_BAD_REQUEST);
        }

        if ($period === 'quarter' && $periodNumber > 4) {
            return new JSONResponse(['error' => 'periodNumber must be 1..4 for quarter'], Http::STATUS_BAD_REQUEST);
        }

        if ($period === 'month' && $periodNumber > 12) {
            return new JSONResponse(['error' => 'periodNumber must be 1..12 for month'], Http::STATUS_BAD_REQUEST);
        }

        if ($this->isPeriodInFuture(periodYear: $periodYear, periodNumber: $periodNumber, period: $period) === true) {
            return new JSONResponse(['error' => 'Cannot create returns for future periods'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $created = $this->service->createReturn(
                administrationId: $administrationId,
                period: $period,
                periodYear: $periodYear,
                periodNumber: $periodNumber,
                regime: $regime
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'VATReturnController: failed to create VAT return',
                ['exception' => $e->getMessage()]
            );

            return new JSONResponse(['error' => 'Failed to create VAT return'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse(['data' => $created], Http::STATUS_CREATED);

    }//end create()

    /**
     * Update notes on a draft VAT return.
     *
     * @param string $returnId The VATReturn id.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/bookkeeping-vat-btw-filing/tasks.md
     */
    #[NoAdminRequired]
    public function update(string $returnId): JSONResponse
    {
        if ($this->validId(id: $returnId) === false) {
            return new JSONResponse(['error' => 'returnId is required'], Http::STATUS_BAD_REQUEST);
        }

        $notes = (string) $this->request->getParam('notes', '');

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $vatReturn     = $objectService->setRegister(register: 'shillinq')->setSchema(schema: 'VATReturn')->find($returnId);
            if (is_array($vatReturn) === false) {
                return new JSONResponse(['error' => 'VAT return not found'], Http::STATUS_NOT_FOUND);
            }

            if ((string) ($vatReturn['statusCode'] ?? '') !== 'draft') {
                return new JSONResponse(['error' => 'Only draft returns can be edited; rebase first'], Http::STATUS_CONFLICT);
            }

            $vatReturn['notes'] = $notes;
            $saved              = $objectService
                ->setRegister(register: 'shillinq')
                ->setSchema(schema: 'VATReturn')
                ->saveObject($vatReturn);
        } catch (\Throwable $e) {
            $this->logger->error(
                'VATReturnController: failed to update VAT return',
                ['returnId' => $returnId, 'exception' => $e->getMessage()]
            );

            return new JSONResponse(['error' => 'Failed to update VAT return'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

        return new JSONResponse(['data' => $saved], Http::STATUS_OK);

    }//end update()

    /**
     * Submit a VAT return (REQ-VAT-005).
     *
     * @param string $returnId The VATReturn id.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/bookkeeping-vat-btw-filing/tasks.md
     */
    #[NoAdminRequired]
    public function submit(string $returnId): JSONResponse
    {
        if ($this->validId(id: $returnId) === false) {
            return new JSONResponse(['error' => 'returnId is required'], Http::STATUS_BAD_REQUEST);
        }

        $userId = $this->resolveUserId();

        try {
            $vatReturn = $this->service->submitReturn(returnId: $returnId, userId: $userId);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
        } catch (\Throwable $e) {
            $this->logger->error(
                'VATReturnController: failed to submit VAT return',
                ['returnId' => $returnId, 'exception' => $e->getMessage()]
            );

            return new JSONResponse(['error' => 'Failed to submit VAT return'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse(['data' => $vatReturn], Http::STATUS_OK);

    }//end submit()

    /**
     * Rebase a submitted VAT return back to draft (REQ-VAT-008).
     *
     * @param string $returnId The VATReturn id.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/bookkeeping-vat-btw-filing/tasks.md
     */
    #[NoAdminRequired]
    public function rebase(string $returnId): JSONResponse
    {
        if ($this->validId(id: $returnId) === false) {
            return new JSONResponse(['error' => 'returnId is required'], Http::STATUS_BAD_REQUEST);
        }

        $userId = $this->resolveUserId();

        try {
            $vatReturn = $this->service->rebaseReturn(returnId: $returnId, userId: $userId);
        } catch (\RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
        } catch (\Throwable $e) {
            $this->logger->error(
                'VATReturnController: failed to rebase VAT return',
                ['returnId' => $returnId, 'exception' => $e->getMessage()]
            );

            return new JSONResponse(['error' => 'Failed to rebase VAT return'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse(['data' => $vatReturn], Http::STATUS_OK);

    }//end rebase()

    /**
     * Delete a VAT return — draft only.
     *
     * @param string $returnId The VATReturn id.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/bookkeeping-vat-btw-filing/tasks.md
     */
    #[NoAdminRequired]
    public function destroy(string $returnId): JSONResponse
    {
        if ($this->validId(id: $returnId) === false) {
            return new JSONResponse(['error' => 'returnId is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $vatReturn     = $objectService->setRegister(register: 'shillinq')->setSchema(schema: 'VATReturn')->find($returnId);
            if (is_array($vatReturn) === false) {
                return new JSONResponse(['error' => 'VAT return not found'], Http::STATUS_NOT_FOUND);
            }

            if ((string) ($vatReturn['statusCode'] ?? '') !== 'draft') {
                return new JSONResponse(['error' => 'Only draft returns can be deleted'], Http::STATUS_CONFLICT);
            }

            $objectService->setRegister(register: 'shillinq')->setSchema(schema: 'VATReturn')->deleteObject($returnId);
        } catch (\Throwable $e) {
            $this->logger->error(
                'VATReturnController: failed to delete VAT return',
                ['returnId' => $returnId, 'exception' => $e->getMessage()]
            );

            return new JSONResponse(['error' => 'Failed to delete VAT return'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try

        return new JSONResponse(['data' => ['id' => $returnId, 'deleted' => true]], Http::STATUS_OK);

    }//end destroy()

    /**
     * Build the list-filter map from query params; only allow whitelisted keys.
     *
     * @return array<string,mixed>
     */
    private function buildListFilters(): array
    {
        $filters = [];

        $period = (string) $this->request->getParam('period', '');
        if (in_array($period, self::PERIOD_VALUES, true) === true) {
            $filters['period'] = $period;
        }

        $regime = (string) $this->request->getParam('regime', '');
        if (in_array($regime, self::REGIME_VALUES, true) === true) {
            $filters['regime'] = $regime;
        }

        $status = (string) $this->request->getParam('status', '');
        if (in_array($status, ['draft', 'submitted', 'verified', 'filed'], true) === true) {
            $filters['statusCode'] = $status;
        }

        $administrationId = (string) $this->request->getParam('administrationId', '');
        if ($administrationId !== '' && $this->validId(id: $administrationId) === true) {
            $filters['administrationId'] = $administrationId;
        }

        return $filters;

    }//end buildListFilters()

    /**
     * Decide whether the requested period is in the future (REQ-VAT-001 validation).
     *
     * @param int    $periodYear   Fiscal year.
     * @param int    $periodNumber Period within year.
     * @param string $period       quarter | month | year.
     *
     * @return bool True when the requested period starts after today.
     */
    private function isPeriodInFuture(int $periodYear, int $periodNumber, string $period): bool
    {
        $today        = gmdate(format: 'Y-m-d');
        $currentYear  = (int) substr($today, 0, 4);
        $currentMonth = (int) substr($today, 5, 2);

        if ($periodYear > $currentYear) {
            return true;
        }

        if ($periodYear < $currentYear) {
            return false;
        }

        // Same year.
        if ($period === 'quarter') {
            $startMonth = (1 + (($periodNumber - 1) * 3));
            return $startMonth > $currentMonth;
        }

        if ($period === 'month') {
            return $periodNumber > $currentMonth;
        }

        // Year period this year is in-progress; allow.
        return false;

    }//end isPeriodInFuture()

    /**
     * Validate an opaque identifier (administration / return / declaration / line).
     *
     * @param string $id Candidate identifier.
     *
     * @return bool True when the identifier is non-empty and well-formed.
     */
    private function validId(string $id): bool
    {
        if ($id === '') {
            return false;
        }

        return (preg_match(pattern: self::ID_PATTERN, subject: $id) === 1);

    }//end validId()

    /**
     * Resolve the actor's user identifier; falls back to 'system'.
     *
     * @return string The user identifier.
     */
    private function resolveUserId(): string
    {
        $user = $this->session->getUser();
        if ($user !== null) {
            return $user->getUID();
        }

        return 'system';

    }//end resolveUserId()
}//end class
