<?php
namespace OCA\KoreaderCompanion\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {

    private $config;

    public function __construct(IConfig $config) {
        $this->config = $config;
    }

    public function getForm() {
        $folder = $this->config->getAppValue('koreader_companion', 'folder', 'eBooks');
        $restrictUploads = $this->config->getAppValue('koreader_companion', 'restrict_uploads', 'no');
        $autoCleanup = $this->config->getAppValue('koreader_companion', 'auto_cleanup', 'no');
        $autoRename = $this->config->getAppValue('koreader_companion', 'auto_rename', 'no');
        
        return new TemplateResponse('koreader_companion', 'admin-settings', [
            'folder' => $folder,
            'restrict_uploads' => $restrictUploads,
            'auto_cleanup' => $autoCleanup,
            'auto_rename' => $autoRename
        ], 'admin');
    }

    public function getSection() {
        return 'additional';
    }

    public function getPriority() {
        return 50;
    }
}
