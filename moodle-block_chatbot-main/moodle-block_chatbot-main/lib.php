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
* Library functions for block_chatbot
*
* @package    block_chatbot
* @copyright  2023 Your Name
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/
 
defined('MOODLE_INTERNAL') || die();
 
/**
* Gets chatbot settings with defaults
* 
* @return object
*/
function block_chatbot_get_settings() {
    $config = get_config('block_chatbot');
 
    // Set defaults if not configured
    if (!isset($config->headercolor)) {
        $config->headercolor = '#1177d1';
    }
 
    if (!isset($config->buttoncolor)) {
        $config->buttoncolor = '#1177d1';
    }
 
    if (!isset($config->welcomemessage)) {
        $config->welcomemessage = get_string('fullwelcome1', 'block_chatbot');
    }
 
    if (!isset($config->helpmessage)) {
        $config->helpmessage = get_string('fullwelcome2', 'block_chatbot');
    }
 
    if (!isset($config->defaultmode)) {
        $config->defaultmode = 'conversation';
    }
 
    if (!isset($config->cachelifetime)) {
        $config->cachelifetime = 86400; // 24 hours
    }
 
    return $config;
}
 
/**
* Function to check if the user can use the chatbot
* 
* @param int $userid User ID
* @return bool
*/
function block_chatbot_can_use($userid = null) {
    global $USER;
 
    if ($userid === null) {
        $userid = $USER->id;
    }
 
    $systemcontext = \context_system::instance();
    return has_capability('block/chatbot:usechatbot', $systemcontext, $userid);
}
 
/**
* Clean up chatbot cache
*/
function block_chatbot_cleanup_cache() {
    global $CFG;
 
    $cachedir = $CFG->dirroot . '/blocks/chatbot/botman/cache';
    $config = block_chatbot_get_settings();
 
    // If cache directory exists
    if (is_dir($cachedir)) {
        // Get current time
        $now = time();
 
        // Get files in cache directory
        $files = glob($cachedir . '/*');
 
        foreach ($files as $file) {
            // Skip directories
            if (is_dir($file)) {
                continue;
            }
 
            // Check if file is older than cache lifetime
            if ($now - filemtime($file) > $config->cachelifetime) {
                unlink($file);
            }
        }
    }
}
 
/**
* Serve the files from the PLUGINFILE file system
*
* @param stdClass $course course object
* @param stdClass $cm course module
* @param stdClass $context context object
* @param string $filearea file area
* @param array $args extra arguments
* @param bool $forcedownload whether or not force download
* @param array $options additional options affecting the file serving
* @return bool false if file not found, does not return if found - just send the file
*/
function block_chatbot_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = array()) {
    // Only serve files from the 'images' filearea
    if ($filearea !== 'images') {
        return false;
    }
 
    // Check the contextlevel is as expected
    if ($context->contextlevel != CONTEXT_SYSTEM) {
        return false;
    }
 
    // Make sure the user is logged in
    require_login();
 
    $fs = get_file_storage();
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';
 
    // Get the file
    $file = $fs->get_file($context->id, 'block_chatbot', $filearea, 0, $filepath, $filename);
    if (!$file) {
        return false;
    }
 
    // Send the file
    send_stored_file($file, 0, 0, $forcedownload, $options);
}