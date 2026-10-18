<?php
/**
 * Register console commands for the eBooks POC app
 */

use OCA\KoreaderCompanion\Command\GenerateBookHashesCommand;

/** @var Symfony\Component\Console\Application $application */
$application->add(\OCP\Server::get(GenerateBookHashesCommand::class));