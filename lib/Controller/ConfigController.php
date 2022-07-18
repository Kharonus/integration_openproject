<?php
/**
 * Nextcloud - openproject
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2021
 */

namespace OCA\OpenProject\Controller;

use OCP\IURLGenerator;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Controller;

use OCA\OpenProject\Service\OauthService;
use OCA\OpenProject\Service\OpenProjectAPIService;
use OCA\OpenProject\AppInfo\Application;
use Psr\Log\LoggerInterface;

class ConfigController extends Controller {

	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var IURLGenerator
	 */
	private $urlGenerator;
	/**
	 * @var IUserManager
	 */
	private $userManager;
	/**
	 * @var IL10N
	 */
	private $l;
	/**
	 * @var OpenProjectAPIService
	 */
	private $openprojectAPIService;

	/**
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * @var string|null
	 */
	private $userId;
	/**
	 * @var OauthService
	 */
	private $oauthService;

	public function __construct(string $appName,
								IRequest $request,
								IConfig $config,
								IURLGenerator $urlGenerator,
								IUserManager $userManager,
								IL10N $l,
								OpenProjectAPIService $openprojectAPIService,
								LoggerInterface $logger,
								OauthService $oauthService,
								?string $userId) {
		parent::__construct($appName, $request);
		$this->config = $config;
		$this->urlGenerator = $urlGenerator;
		$this->userManager = $userManager;
		$this->l = $l;
		$this->openprojectAPIService = $openprojectAPIService;
		$this->logger = $logger;
		$this->userId = $userId;
		$this->oauthService = $oauthService;
	}

	/**
	 * @param string|null $userId
	 * @return void
	 */
	public function clearUserInfo(string $userId = null) {
		if ($userId === null) {
			$userId = $this->userId;
		}
		$this->config->deleteUserValue($userId, Application::APP_ID, 'token');
		$this->config->deleteUserValue($userId, Application::APP_ID, 'login');
		$this->config->deleteUserValue($userId, Application::APP_ID, 'user_id');
		$this->config->deleteUserValue($userId, Application::APP_ID, 'user_name');
		$this->config->deleteUserValue($userId, Application::APP_ID, 'refresh_token');
		$this->config->deleteUserValue($userId, Application::APP_ID, 'last_notification_check');
	}

	/**
	 * set config values
	 * @NoAdminRequired
	 *
	 * @param array<string, string> $values
	 * @return DataResponse
	 */
	public function setConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			$this->config->setUserValue($this->userId, Application::APP_ID, $key, trim($value));
		}
		$result = [];

		if (isset($values['token'])) {
			if ($values['token'] && $values['token'] !== '') {
				$result = $this->storeUserInfo();
			} else {
				$this->clearUserInfo();
				$result = [
					'user_name' => '',
				];
			}
		}
		if (isset($result['error'])) {
			return new DataResponse($result, Http::STATUS_UNAUTHORIZED);
		} else {
			return new DataResponse($result);
		}
	}

	/**
	 * set admin config values
	 *
	 * @param array<string, string> $values
	 * @return DataResponse
	 */
	public function setAdminConfig(array $values): DataResponse {
		$oldOpenProjectOauthUrl = $this->config->getAppValue(
			Application::APP_ID, 'oauth_instance_url', ''
		);
		$oldClientId = $this->config->getAppValue(
			Application::APP_ID, 'client_id', ''
		);
		$oldClientSecret = $this->config->getAppValue(
			Application::APP_ID, 'client_secret', ''
		);

		foreach ($values as $key => $value) {
			$this->config->setAppValue(Application::APP_ID, $key, trim($value));
		}
		if (isset($values['oauth_instance_url']) && $oldOpenProjectOauthUrl !== $values['oauth_instance_url']) {
			$oauthClientInternalId = $this->config->getAppValue(Application::APP_ID, 'nc_oauth_client_id', '');
			$this->oauthService->setClientRedirectUri((int) $oauthClientInternalId, $values['oauth_instance_url']);
		}
		if ((isset($values['client_id']) && $values['client_id'] !== $oldClientId) ||
			(isset($values['client_secret']) && $values['client_secret'] !== $oldClientSecret)) {
			$this->userManager->callForAllUsers(function (IUser $user) {
				$this->clearUserInfo($user->getUID());
			});
		}

		return new DataResponse([
			"status" => OpenProjectAPIService::isAdminConfigOk($this->config)
		]);
	}

	/**
	 * receive oauth code and get oauth access token
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $code
	 * @param string $state
	 * @return RedirectResponse
	 */
	public function oauthRedirect(string $code = '', string $state = ''): RedirectResponse {
		$configState = $this->config->getUserValue($this->userId, Application::APP_ID, 'oauth_state');
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id');
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret');
		$codeVerifier = $this->config->getUserValue(
			$this->userId, Application::APP_ID, 'code_verifier', false
		);
		$oauthJourneyStartingPage = $this->config->getUserValue(
			$this->userId, Application::APP_ID, 'oauth_journey_starting_page'
		);

		try {
			$oauthJourneyStartingPageDecoded = \Safe\json_decode($oauthJourneyStartingPage);

			if ($oauthJourneyStartingPageDecoded->page === 'dashboard') {
				$newUrl = $this->urlGenerator->linkToRoute('dashboard.dashboard.index');
			} elseif ($oauthJourneyStartingPageDecoded->page === 'settings') {
				$newUrl = $this->urlGenerator->linkToRoute(
					'settings.PersonalSettings.index', ['section' => 'connected-accounts']
				);
			} elseif ($oauthJourneyStartingPageDecoded->page === 'files') {
				$newUrl = $this->urlGenerator->linkToRoute('files.view.index', [
					'dir' => $oauthJourneyStartingPageDecoded->file->dir,
					'scrollto' => $oauthJourneyStartingPageDecoded->file->name
				]);
			} else {
				$this->logger->error(
					'could not determine where the OAuth journey ' .
					'to connect to OpenProject started'
				);
				throw new \Exception();
			}
		} catch (\Exception $e) {
			$newUrl = $this->urlGenerator->linkToRoute('files.view.index');
		}

		// anyway, reset state
		$this->config->deleteUserValue($this->userId, Application::APP_ID, 'oauth_state');

		$validCodeVerifier = false;
		if (is_string($codeVerifier)) {
			$validCodeVerifier = (\Safe\preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $codeVerifier) === 1);
		}

		$validClientSecret = false;
		if (is_string($clientSecret)) {
			$validClientSecret = (\Safe\preg_match('/^.{10,}$/', $clientSecret) === 1);
		}

		if ($clientID && $validClientSecret && $validCodeVerifier && $configState !== '' && $configState === $state) {
			$redirect_uri = $this->urlGenerator->linkToRouteAbsolute(
				Application::APP_ID . '.config.oauthRedirect'
			);
			$openprojectUrl = $this->config->getAppValue(Application::APP_ID, 'oauth_instance_url');
			$result = $this->openprojectAPIService->requestOAuthAccessToken($openprojectUrl, [
				'client_id' => $clientID,
				'client_secret' => $clientSecret,
				'code' => $code,
				'redirect_uri' => $redirect_uri,
				'grant_type' => 'authorization_code',
				'code_verifier' => $codeVerifier
			], 'POST');
			if (isset($result['access_token']) && isset($result['refresh_token'])) {
				$accessToken = $result['access_token'];
				$this->config->setUserValue($this->userId, Application::APP_ID, 'token', $accessToken);
				$refreshToken = $result['refresh_token'];
				$this->config->setUserValue($this->userId, Application::APP_ID, 'refresh_token', $refreshToken);
				// get user info
				// ToDo check response for errors
				$this->storeUserInfo();
				$this->config->setUserValue(
					$this->userId, Application::APP_ID, 'oauth_connection_result', 'success'
				);
				return new RedirectResponse($newUrl);
			}
			$error = '';
			if (!isset($result['access_token'])) {
				$error = $this->l->t('Error getting OAuth access token');
			} elseif (!isset($result['refresh_token'])) {
				$error = $this->l->t('Error getting OAuth refresh token');
			}
			if (isset($result['error'])) {
				$error = $error . '. ' . $result['error'];
			}
			$result = $error;
		} else {
			if (!$validCodeVerifier) {
				$this->logger->error('invalid OAuth code verifier', ['app' => $this->appName]);
			}
			if (!$validClientSecret) {
				$this->logger->error('invalid OAuth client secret', ['app' => $this->appName]);
			}
			$result = $this->l->t('Error during OAuth exchanges');
		}
		$this->config->setUserValue(
			$this->userId, Application::APP_ID, 'oauth_connection_result', 'error'
		);
		$this->config->setUserValue(
			$this->userId, Application::APP_ID, 'oauth_connection_error_message', $result
		);
		return new RedirectResponse($newUrl);
	}

	/**
	 * @return array{error?: string, user_name?: string, statusCode?: int}
	 */
	private function storeUserInfo(): array {
		$info = $this->openprojectAPIService->request($this->userId, 'users/me');
		if (isset($info['lastName'], $info['firstName'], $info['id'])) {
			$fullName = $info['firstName'] . ' ' . $info['lastName'];
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', $info['id']);
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', $fullName);
			return ['user_name' => $fullName];
		} else {
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_id');
			$this->config->deleteUserValue($this->userId, Application::APP_ID, 'user_name');
			if (isset($info['statusCode']) && $info['statusCode'] === 404) {
				$info['error'] = 'Not found';
			} else {
				if (!isset($info['error'])) {
					$info['error'] = 'Invalid token';
				}
			}
			return $info;
		}
	}

	/**
	 * @return DataResponse
	 */
	public function autoOauthCreation(): DataResponse {
		$this->cleanupOauthClientAndSettings();
		$opUrl = $this->config->getAppValue(Application::APP_ID, 'oauth_instance_url', '');
		$clientInfo = $this->oauthService->createNcOauthClient('OpenProject client', rtrim($opUrl, '/') .'/oauth_clients/%s/callback');
		$this->config->setAppValue(Application::APP_ID, 'nc_oauth_client_id', $clientInfo['id']);
		return new DataResponse($clientInfo);
	}

	/**
	 * @return DataResponse
	 */
	public function deleteOauthClient(): DataResponse {
		$this->cleanupOauthClientAndSettings();
		return new DataResponse();
	}

	private function cleanupOauthClientAndSettings(): void {
		$oauthClientInternalId = $this->config->getAppValue(
			Application::APP_ID, 'nc_oauth_client_id', ''
		);
		if ($oauthClientInternalId !== '') {
			$id = (int) $oauthClientInternalId;
			$this->oauthService->deleteClient($id);
			$this->config->deleteAppValue(Application::APP_ID, 'nc_oauth_client_id');
		}
	}
}
