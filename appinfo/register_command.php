<?php
/**
 * Register console commands for the eBooks POC app
 */

use OCA\KoreaderCompanion\Command\GenerateBookHashesCommand;
use OCA\KoreaderCompanion\Command\IndexLibraryCommand;

/** @var Symfony\Component\Console\Application $application */
$application->add(\OCP\Server::get(GenerateBookHashesCommand::class));
$application->add(\OCP\Server::get(IndexLibraryCommand::class));