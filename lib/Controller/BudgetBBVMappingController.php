<?php

/**
 * Shillinq Budget BBV Mapping Controller
 *
 * Thin page controller for the Budget Mapping index + detail pages.
 *
 * Slice 04 of the bookkeeping-waterschappen-bbv-variant chain
 * (ADR-032). Returns minimal view envelopes so the manifest pages
 * declared in `bookkeeping-waterschappen-bbv-variant-04-manifest-routes`
 * are reachable end-to-end and so hydra-gate-route-auth sees explicit
 * #[NoAdminRequired] attributes on every endpoint. The mapping CRUD
 * itself is mediated by OpenRegister's object endpoints (admin-write
 * per slice 01 register permissions); slice 06/07 build the bespoke
 * index + detail UI that calls those endpoints.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-04-manifest-routes/tasks.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Thin page controller for the Budget BBV Mapping index + detail pages.
 */
class BudgetBBVMappingController extends Controller
{
    /**
     * Constructor for BudgetBBVMappingController.
     *
     * @param IRequest $request The current request.
     *
     * @return void
     */
    public function __construct(IRequest $request)
    {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return the Budget Mapping index view envelope.
     *
     * Slice 04 only registers the page route + attribute; the index
     * UI itself is built in slice 06 (bookkeeping-waterschappen-bbv-
     * variant-06-mapping-index) and pulls the data from the
     * OpenRegister BudgetBBVMapping schema. The envelope shape
     * (schema, register) is fixed here so the future Vue page can
     * resolve the manifest route deterministically.
     *
     * @return JSONResponse {register: string, schema: string, detailRoute: string}
     *
     * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-04-manifest-routes/specs/bookkeeping-waterschappen-bbv-variant/spec.md#requirement-bbv-page-routes
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        return new JSONResponse(
            [
                'register'    => 'shillinq',
                'schema'      => 'BudgetBBVMapping',
                'detailRoute' => 'BudgetBBVMappingDetail',
            ]
        );
    }//end index()

    /**
     * Return the Budget Mapping detail view envelope.
     *
     * Slice 04 only registers the page route + attribute; the bespoke
     * detail page + relation pickers are built in slice 07
     * (bookkeeping-waterschappen-bbv-variant-07-mapping-detail) and
     * write through OpenRegister's object endpoints (which apply the
     * admin-write register permission from slice 01). This skeleton
     * returns the id passed in the URL so the route is end-to-end
     * reachable; no per-object IDOR surface is introduced because no
     * data is read from storage.
     *
     * @param string $id The BudgetBBVMapping object id from the URL.
     *
     * @return JSONResponse {id: string, register: string, schema: string, indexRoute: string}
     *
     * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-04-manifest-routes/specs/bookkeeping-waterschappen-bbv-variant/spec.md#requirement-bbv-page-routes
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        return new JSONResponse(
            [
                'id'         => $id,
                'register'   => 'shillinq',
                'schema'     => 'BudgetBBVMapping',
                'indexRoute' => 'BudgetBBVMappings',
            ]
        );
    }//end show()
}//end class
