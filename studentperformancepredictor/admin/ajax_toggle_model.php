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
 * AJAX handler for toggling model active status.
 *
 * @package    block_studentperformancepredictor
 * @copyright  2023 Your Name <[Email]>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once('../../../config.php');
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

// Set up response
$response = array(
    'success' => false,
    'message' => ''
);

// Check if this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = get_string('invalidrequest', 'block_studentperformancepredictor');
    echo json_encode($response);
    die();
}

// Get parameters
$courseid = required_param('courseid', PARAM_INT);
$modelid = required_param('modelid', PARAM_INT);
$active = required_param('active', PARAM_INT);

// Validate session key
require_sesskey();

// Set up context
$course = get_course($courseid);
$context = context_course::instance($courseid);

// Check permissions
require_login($course);
require_capability('block/studentperformancepredictor:managemodels', $context);

// Get the model
$model = $DB->get_record('block_spp_models', array('id' => $modelid), '*', MUST_EXIST);

// Ensure the model belongs to this course
if ($model->courseid != $courseid) {
    $response['message'] = get_string('modelnotincourse', 'block_studentperformancepredictor');
    echo json_encode($response);
    die();
}

// Begin transaction
$transaction = $DB->start_delegated_transaction();
$success = true;
$error_message = '';

// If activating this model, deactivate all other models for this course
if ($active) {
    $DB->set_field('block_spp_models', 'active', 0, array('courseid' => $courseid));
}

// Update this model's active status
$model->active = $active;
$model->timemodified = time();
$model->usermodified = $USER->id;

if (!$DB->update_record('block_spp_models', $model)) {
    $success = false;
    $error_message = get_string('errorupdatingmodel', 'block_studentperformancepredictor');
}

if ($success) {
    // If activating, trigger backend-driven prediction refresh for this course
    if ($active) {
        // This will call the Python backend /predict endpoint for all students using the new active model
        try {
            block_studentperformancepredictor_trigger_prediction_refresh($courseid);
        } catch (Exception $e) {
            // Log but do not fail activation if refresh fails
            if (function_exists('debugging')) {
                debugging('Prediction refresh error after model activation: ' . $e->getMessage(), defined('DEBUG_DEVELOPER') ? DEBUG_DEVELOPER : 0);
            }
        }
    }
    // Commit transaction
    $transaction->allow_commit();
    $response['success'] = true;
    if ($active) {
        $response['message'] = get_string('modelactivated', 'block_studentperformancepredictor');
    } else {
        $response['message'] = get_string('modeldeactivated', 'block_studentperformancepredictor');
    }
} else {
    // Rollback transaction
    $transaction->rollback();
    $response['message'] = $error_message;
    if (function_exists('debugging')) {
        debugging('Model toggle error: ' . $error_message, defined('DEBUG_DEVELOPER') ? DEBUG_DEVELOPER : 0);
    }
}

// Return JSON response
echo json_encode($response);