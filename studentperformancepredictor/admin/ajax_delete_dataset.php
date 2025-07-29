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
 * AJAX handler for deleting datasets.
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
$datasetid = required_param('datasetid', PARAM_INT);

// Validate session key
require_sesskey();

// Set up context
$course = get_course($courseid);
$context = context_course::instance($courseid);

// Check permissions
require_login($course);
require_capability('block/studentperformancepredictor:managemodels', $context);

// Get the dataset
$dataset = $DB->get_record('block_spp_datasets', array('id' => $datasetid), '*', MUST_EXIST);

// Ensure the dataset belongs to this course
if ($dataset->courseid != $courseid) {
    $response['message'] = get_string('datasetnotincourse', 'block_studentperformancepredictor');
    echo json_encode($response);
    die();
}

// Begin transaction
$transaction = $DB->start_delegated_transaction();
$success = true;
$error_message = '';

// Delete the dataset file
if (!empty($dataset->filepath) && file_exists($dataset->filepath)) {
    if (!unlink($dataset->filepath)) {
        $success = false;
        $error_message = get_string('filedeleteerror', 'block_studentperformancepredictor');
    }
}

// Delete the database record
if ($success) {
    if (!$DB->delete_records('block_spp_datasets', array('id' => $datasetid))) {
        $success = false;
        $error_message = get_string('databasedeleteerror', 'block_studentperformancepredictor');
    }
}

// Also delete all models trained from this dataset (for this course)
if ($success) {
    $models = $DB->get_records('block_spp_models', array('datasetid' => $datasetid, 'courseid' => $courseid));
    foreach ($models as $model) {
        // Optionally, delete model files from disk if stored
        if (!empty($model->modelpath) && file_exists($model->modelpath)) {
            @unlink($model->modelpath);
        }
        $DB->delete_records('block_spp_models', array('id' => $model->id));
    }
}

if ($success) {
    // Commit transaction
    $transaction->allow_commit();
    $response['success'] = true;
    $response['message'] = get_string('datasetdeleted', 'block_studentperformancepredictor');
} else {
    // Rollback transaction
    $transaction->rollback();
    $response['message'] = $error_message;
    debugging('Dataset deletion error: ' . $error_message, DEBUG_DEVELOPER);
}

// Return JSON response
echo json_encode($response);