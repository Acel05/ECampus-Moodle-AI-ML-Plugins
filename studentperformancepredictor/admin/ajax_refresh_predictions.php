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
 * AJAX handler for refreshing predictions.
 *
 * @package    block_studentperformancepredictor
 * @copyright  2023 Your Name <[Email]>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once('../../../config.php');
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

// Set up response
$response = [
    'success' => false,
    'message' => ''
];

// Check if this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = get_string('invalidrequest', 'block_studentperformancepredictor');
    echo json_encode($response);
    die();
}

// Get parameters
$courseid = required_param('courseid', PARAM_INT);

// Validate session key
require_sesskey();

// Set up context
$course = get_course($courseid);
$context = context_course::instance($courseid);

// Check permissions
require_login($course);
require_capability('block/studentperformancepredictor:viewallpredictions', $context);

// Check if there's an active model
if (!block_studentperformancepredictor_has_active_model($courseid)) {
    $response['message'] = get_string('noactivemodel', 'block_studentperformancepredictor');
    echo json_encode($response);
    die();
}

// Trigger prediction refresh: orchestrate backend prediction for all students
try {
    // This function should call the Python backend /predict endpoint for each student in the course
    // using the active model, and update the Moodle DB with the returned predictions/probabilities.
    block_studentperformancepredictor_trigger_prediction_refresh($courseid);
    $response['success'] = true;
    $response['message'] = get_string('predictionsrefreshqueued', 'block_studentperformancepredictor');
} catch (Exception $e) {
    $response['message'] = get_string('predictionsrefresherror', 'block_studentperformancepredictor') . ': ' . $e->getMessage();
    if (function_exists('debugging')) {
        debugging('Prediction refresh error: ' . $e->getMessage(), defined('DEBUG_DEVELOPER') ? DEBUG_DEVELOPER : 0);
    }
}

// Return JSON response (always backend-driven)
echo json_encode($response);