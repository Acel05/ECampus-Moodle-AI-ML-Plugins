<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.
 
/**
* Installation script for block_chatbot
*
* @package    block_chatbot
* @copyright  2023 Your Name
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/
 
defined('MOODLE_INTERNAL') || die();
 
/**
* Installation procedure
*/
function xmldb_block_chatbot_install() {
    global $CFG, $DB;
 
    // Check if BotMan dependencies are installed
    $botmandir = $CFG->dirroot . '/blocks/chatbot/botman';
 
    if (!file_exists($botmandir . '/vendor/autoload.php')) {
        // Log warning
        mtrace('Warning: BotMan dependencies are not installed for the chatbot block.');
        mtrace('Please run: composer install in ' . $botmandir);
    }
 
    // Create cache directory if it doesn't exist
    $cachedir = $botmandir . '/cache';
    if (!is_dir($cachedir)) {
        if (!mkdir($cachedir, 0755, true)) {
            mtrace('Warning: Could not create cache directory for chatbot at ' . $cachedir);
        }
    }
 
    // Create images directory if it doesn't exist
    $imagesdir = $CFG->dirroot . '/blocks/chatbot/images';
    if (!is_dir($imagesdir)) {
        if (!mkdir($imagesdir, 0755, true)) {
            mtrace('Warning: Could not create images directory for chatbot at ' . $imagesdir);
        }
    }
 
    // Check for required image files
    $requiredImages = ['bot_img.jpg', 'loading.gif', 'send.png'];
    foreach ($requiredImages as $image) {
        if (!file_exists($imagesdir . '/' . $image)) {
            mtrace('Warning: Missing required image file: ' . $image);
            mtrace('Please add this file to: ' . $imagesdir);
        }
    }
 
    return true;
}