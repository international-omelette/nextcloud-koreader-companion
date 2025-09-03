<?php
namespace OCA\KoreaderCompanion\Http;

use OCP\AppFramework\Http\Response;

class XMLResponse extends Response {
    private $xml;

    public function __construct($xml, $statusCode = 200) {
        parent::__construct();
        $this->addHeader('Content-Type', 'application/xml; charset=utf-8');
        $this->xml = $xml;
        $this->setStatus($statusCode);
    }

    public function render(): string {
        return $this->xml;
    }
}