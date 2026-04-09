<?php

/**
 * Shillinq User Preferences Controller
 *
 * Controller for reading and persisting per-user preferences.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for reading and persisting per-user preferences.
 */
class UserPreferencesController extends Controller
{

    /**
     * List of allowed preference keys (whitelist).
     */
    private const ALLOWED_KEYS = ['language', 'dateFormat', 'notificationEmail', 'notificationInApp'];

    /**
     * Constructor for the UserPreferencesController.
     *
     * @param IRequest     $request     The request object
     * @param IConfig      $config      The Nextcloud configuration service
     * @param IUserSession $userSession The current user session
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private IConfig $config,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Return the current user's stored preferences.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse
     */
    public function index(): JSONResponse
    {
        $userId = $this->userSession->getUser()?->getUID();
        if ($userId === null) {
            return new JSONResponse([], 401);
        }

        $prefs = [];
        foreach (self::ALLOWED_KEYS as $key) {
            $value = $this->config->getUserValue($userId, Application::APP_ID, $key, '');
            if ($value !== '') {
                $prefs[$key] = $value;
            }
        }

        return new JSONResponse($prefs);

    }//end index()

    /**
     * Persist the current user's preferences.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse
     */
    public function save(): JSONResponse
    {
        $userId = $this->userSession->getUser()?->getUID();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $body = $this->request->getParams();

        foreach (self::ALLOWED_KEYS as $key) {
            if (isset($body[$key]) === true) {
                $this->config->setUserValue($userId, Application::APP_ID, $key, (string) $body[$key]);
            }
        }

        return new JSONResponse(['status' => 'ok']);

    }//end save()
}//end class
