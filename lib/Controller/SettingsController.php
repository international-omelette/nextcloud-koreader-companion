<?php
namespace OCA\KoreaderCompanion\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

class SettingsController extends Controller {

    private $config;

    public function __construct(IRequest $request, IConfig $config, $appName) {
        parent::__construct($appName, $request);
        $this->config = $config;
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function setFolder($folder) {
        $this->config->setAppValue($this->appName, 'folder', $folder);
        return new JSONResponse(['status' => 'success']);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function setRestrictUploads($value) {
        $this->config->setAppValue($this->appName, 'restrict_uploads', $value);
        return new JSONResponse(['status' => 'success']);
    }

    /**
     * @NoAdminRequired  
     * @NoCSRFRequired
     */
    public function setAutoCleanup($value) {
        $this->config->setAppValue($this->appName, 'auto_cleanup', $value);
        return new JSONResponse(['status' => 'success']);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function setAutoRename($value) {
        $this->config->setAppValue($this->appName, 'auto_rename', $value);
        return new JSONResponse(['status' => 'success']);
    }
}
