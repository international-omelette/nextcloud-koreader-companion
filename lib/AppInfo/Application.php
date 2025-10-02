<?php
namespace OCA\KoreaderCompanion\AppInfo;

use OCA\KoreaderCompanion\Listener\FileDeleteListener;
use OCA\KoreaderCompanion\Service\PdfMetadataExtractor;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeDeletedEvent;
use Psr\Log\LoggerInterface;

class Application extends App implements IBootstrap {

    public const APP_ID = 'koreader_companion';

    public function __construct() {
        parent::__construct(self::APP_ID);
        
        // Load composer autoloader for our dependencies
        $composerAutoload = __DIR__ . '/../../vendor/autoload.php';
        if (file_exists($composerAutoload)) {
            require_once $composerAutoload;
        }
    }

    public function register(IRegistrationContext $context): void {
        // Register services
        $context->registerService(PdfMetadataExtractor::class, function ($c) {
            return new PdfMetadataExtractor(
                $c->get(LoggerInterface::class)
            );
        });
        
        
        // Register the file delete listener for cleanup when files are deleted from filesystem
        $context->registerEventListener(NodeDeletedEvent::class, FileDeleteListener::class);
    }

    public function boot(IBootContext $context): void {
        // Register navigation entry
        $navigationManager = $context->getAppContainer()->get('OCP\INavigationManager');
        $urlGenerator = $context->getAppContainer()->get('OCP\IURLGenerator');
        
        $navigationManager->add(function () use ($urlGenerator) {
            return [
                'id' => 'koreader_companion',
                'order' => 10,
                'href' => $urlGenerator->linkToRoute('koreader_companion.page.index'),
                'icon' => $urlGenerator->imagePath('koreader_companion', 'icon.svg'),
                'name' => 'KOReader',
            ];
        });
    }
}