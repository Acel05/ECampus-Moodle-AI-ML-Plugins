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
 * Upload dataset handler for Student Performance Predictor.
 *
 * @package    block_studentperformancepredictor
 * @copyright  2023 Your Name <[Email]>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once('../../../config.php');
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');
require_once($CFG->libdir . '/moodlelib.php'); // For get_string, required_param, optional_param, require_sesskey, clean_filename
require_once($CFG->libdir . '/accesslib.php'); // For get_course, context_course, require_capability
require_once($CFG->libdir . '/enrollib.php'); // For require_login
require_once($CFG->libdir . '/filelib.php'); // For file handling
require_once($CFG->libdir . '/adminlib.php'); // For admin functions
require_once($CFG->libdir . '/weblib.php'); // For web utilities

// Set up response array
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
$datasetname = required_param('dataset_name', PARAM_TEXT);
$datasetformat = required_param('dataset_format', PARAM_ALPHA);
$datasetdesc = optional_param('dataset_description', '', PARAM_TEXT);

// Validate session key
require_sesskey();

// Set up context
$course = get_course($courseid);
$context = context_course::instance($courseid);

// Check permissions
require_login($course);
require_capability('block/studentperformancepredictor:managemodels', $context);

// Verify file upload
if (!isset($_FILES['dataset_file']) || empty($_FILES['dataset_file']['name'])) {
    $response['message'] = get_string('nofileuploaded', 'block_studentperformancepredictor');
    echo json_encode($response);
    die();
}

$file = $_FILES['dataset_file'];

// Check for upload errors
if ($file['error'] !== UPLOAD_ERR_OK) {
    $errormessage = get_string('fileuploaderror', 'block_studentperformancepredictor');
    switch ($file['error']) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            $errormessage = get_string('filetoolarge', 'block_studentperformancepredictor');
            break;
        case UPLOAD_ERR_PARTIAL:
            $errormessage = get_string('filepartialuploaded', 'block_studentperformancepredictor');
            break;
        case UPLOAD_ERR_NO_FILE:
            $errormessage = get_string('nofileuploaded', 'block_studentperformancepredictor');
            break;
    }
    $response['message'] = $errormessage;
    echo json_encode($response);
    die();
}

// Check file extension
$filename = $file['name'];
$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

if ($datasetformat === 'csv' && $extension !== 'csv') {
    $response['message'] = get_string('invalidfileextension', 'block_studentperformancepredictor');
    echo json_encode($response);
    die();
} else if ($datasetformat === 'json' && $extension !== 'json') {
    $response['message'] = get_string('invalidfileextension', 'block_studentperformancepredictor');
    echo json_encode($response);
    die();
}

// Create dataset directory
try {
    $datasetdir = block_studentperformancepredictor_ensure_dataset_directory($courseid);
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    echo json_encode($response);
    die();
}

// Store the file with a unique name
$newfilename = $courseid . '_' . time() . '_' . clean_filename($filename);
$filepath = $datasetdir . '/' . $newfilename;

// Make sure directory has proper permissions for Railway deployment
if (is_dir($datasetdir)) {
    chmod($datasetdir, 0777); // Set directory permissions
}

if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    $response['message'] = get_string('fileuploadfailed', 'block_studentperformancepredictor');
    echo json_encode($response);
    die();
}

// Make sure the uploaded file has the right permissions
chmod($filepath, 0666); // Set file permissions for Railway compatibility

// Extract column headers
$columns = array();

if ($datasetformat === 'csv') {
    $handle = fopen($filepath, 'r');
    if ($handle !== false) {
        $headers = fgetcsv($handle);
        foreach ($headers as $header) {
            $columns[] = $header;
        }
        fclose($handle);
    }
} else if ($datasetformat === 'json') {
    $content = file_get_contents($filepath);
    $jsonData = json_decode($content, true);
    if (is_array($jsonData) && !empty($jsonData)) {
        $firstRow = reset($jsonData);
        $columns = array_keys($firstRow);
    }
}

// Save dataset record to database
$dataset = new stdClass();
$dataset->courseid = $courseid;
$dataset->name = $datasetname;
$dataset->description = $datasetdesc;
$dataset->filepath = $filepath;
$dataset->fileformat = $datasetformat;
$dataset->columns = json_encode($columns);
$dataset->timecreated = time();
$dataset->timemodified = time();
$dataset->usermodified = $USER->id;

try {
    $datasetid = $DB->insert_record('block_spp_datasets', $dataset);
    $response['success'] = true;
    $response['message'] = get_string('datasetsaved_backend', 'block_studentperformancepredictor');
    $response['datasetid'] = $datasetid;
} catch (Exception $e) {
    $response['message'] = get_string('datasetsaveerror', 'block_studentperformancepredictor') . ': ' . $e->getMessage();
    if (function_exists('debugging')) {
        debugging('Dataset upload error: ' . $e->getMessage(), defined('DEBUG_DEVELOPER') ? DEBUG_DEVELOPER : 0);
    }
}

// Return JSON response
// All orchestration is backend-driven
echo json_encode($response);
