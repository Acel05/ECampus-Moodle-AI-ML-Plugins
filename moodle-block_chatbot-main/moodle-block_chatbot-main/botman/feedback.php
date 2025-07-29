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
* Feedback handler for EIRA AI
*
* @package    block_chatbot
* @copyright  2023 Your Name
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/
 
define('AJAX_SCRIPT', true);
require_once('../../../config.php');
 
// Security: Require login
require_login(null, true);
 
// Get the feedback data
$helpful = optional_param('helpful', 1, PARAM_INT);
$token = optional_param('token', '', PARAM_RAW);
 
// Verify token
$storedtoken = get_user_preference('block_chatbot_token', '');
if (empty($token) || $token !== $storedtoken) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid token']);
    die();
}
 
// Get the last interaction from session
if (isset($_SESSION['eira_conversation_context']) && 
    isset($_SESSION['eira_conversation_context']['last_interaction_id'])) {
 
    $interactionId = $_SESSION['eira_conversation_context']['last_interaction_id'];
 
    // Update the interaction record
    try {
        $DB->set_field('block_chatbot_interactions', 'success', $helpful, ['id' => $interactionId]);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'No interaction found']);
}