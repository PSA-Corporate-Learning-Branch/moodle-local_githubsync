<?php
// PHPStan bootstrap — loads Moodle core for type information.
define('CLI_SCRIPT', true);
define('ABORT_AFTER_CONFIG_CANCEL', true);

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
