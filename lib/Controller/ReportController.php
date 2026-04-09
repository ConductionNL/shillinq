<?php

/**
 * Shillinq Report Controller
 *
 * OCS controller for generating access rights reports.
 *
 * @category  Controller
 * @package   OCA\Shillinq\Controller
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://conduction.nl
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.6
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for generating user access rights reports in CSV or JSON.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.6
 */
class ReportController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest           $request   The request object
     * @param ContainerInterface $container The DI container
     * @param LoggerInterface    $logger    The logger
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Generate the access rights report.
     *
     * Fetches all active access rights in a single query and groups them by
     * userId in PHP to avoid an N+1 pattern.
     *
     * @return JSONResponse|DataDownloadResponse
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-5.6
     */
    public function accessRights(): JSONResponse|DataDownloadResponse
    {
        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );

            $format = ($this->request->getParam('format') ?? 'html');

            $users = $objectService->findObjects(
                register: Application::APP_ID,
                schema: 'user',
                filters: ['isActive' => true],
            );

            // Fetch all active access rights in ONE query — avoids N+1.
            $allActiveRights = $objectService->findObjects(
                register: Application::APP_ID,
                schema: 'accessRight',
                filters: ['isActive' => true],
            );

            // Fetch all roles in ONE query for name lookup.
            $allRoles = $objectService->findObjects(
                register: Application::APP_ID,
                schema: 'role',
                filters: [],
            );
            $roleMap  = [];
            foreach ($allRoles as $role) {
                if (empty($role['id']) === false) {
                    $roleMap[$role['id']] = ($role['name'] ?? $role['id']);
                }
            }

            // Group access rights by userId.
            $rightsByUser = [];
            foreach ($allActiveRights as $right) {
                $uid = ($right['userId'] ?? '');
                if (empty($uid) === false) {
                    $rightsByUser[$uid][] = $right;
                }
            }

            $rows = [];
            foreach ($users as $user) {
                $userId     = ($user['username'] ?? ($user['id'] ?? ''));
                $userRights = ($rightsByUser[$userId] ?? []);

                $roleNames = [];
                foreach ($userRights as $right) {
                    $roleId = ($right['roleId'] ?? '');
                    if (empty($roleId) === false && isset($roleMap[$roleId]) === true) {
                        $roleNames[] = $roleMap[$roleId];
                    }
                }

                // Resolve role names from active delegations.
                $roleIds = array_filter(
                    array_unique(
                        array_map(
                            static fn($d) => ($d['roleId'] ?? ''),
                            $delegations,
                        )
                    )
                );

                $roleNames = [];
                foreach ($roleIds as $roleId) {
                    try {
                        $role        = $objectService->getObject(
                            register: Application::APP_ID,
                            schema: 'role',
                            id: $roleId,
                        );
                        $roleNames[] = ($role['name'] ?? $roleId);
                    } catch (\Throwable $e) {
                        $roleNames[] = $roleId;
                    }
                }

                // Resolve team memberships.
                $teamMemberships = $objectService->findObjects(
                    register: Application::APP_ID,
                    schema: 'team',
                    filters: ['memberIds' => ($user['id'] ?? '')],
                );

                $teamNames = array_map(
                    static fn($t) => ($t['name'] ?? ''),
                    $teamMemberships,
                );

                $rows[] = [
                    'username'          => ($user['username'] ?? ''),
                    'displayName'       => ($user['displayName'] ?? ''),
                    'roles'             => implode(', ', array_unique($roleNames)),
                    'teams'             => '',
                    'lastLogin'         => ($user['lastLogin'] ?? ''),
                    'branch'            => ($user['branch'] ?? ''),
                    'delegationsActive' => count($userRights),
                ];
            }//end foreach

            if ($format === 'csv') {
                return $this->buildCsvResponse(rows: $rows);
            }

            return new JSONResponse(data: ['results' => $rows]);
        } catch (\Throwable $e) {
            $this->logger->error('Shillinq: report accessRights failed', ['exception' => $e]);
            return new JSONResponse(
                data: ['error' => 'An internal error occurred'],
                statusCode: 500,
            );
        }//end try
    }//end accessRights()

    /**
     * Build a CSV download response from the report rows.
     *
     * @param array $rows The report rows
     *
     * @return DataDownloadResponse
     */
    private function buildCsvResponse(array $rows): DataDownloadResponse
    {
        $headers = [
            'username',
            'displayName',
            'roles',
            'teams',
            'lastLogin',
            'branch',
            'delegationsActive',
        ];

        $csv = implode(',', $headers)."\n";
        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $value  = (string) ($row[$header] ?? '');
                $line[] = '"'.str_replace('"', '""', $value).'"';
            }

            $csv .= implode(',', $line)."\n";
        }

        return new DataDownloadResponse(
            data: $csv,
            filename: 'access-rights-report.csv',
            contentType: 'text/csv',
        );
    }//end buildCsvResponse()
}//end class
