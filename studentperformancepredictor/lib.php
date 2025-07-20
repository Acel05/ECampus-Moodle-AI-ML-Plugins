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
 * Library functions for Student Performance Predictor.
 *
 * @package    block_studentperformancepredictor
 * @copyright  2023 Your Name <[Email]>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Creates and ensures the dataset directory exists for a course.
 *
 * @param int $courseid Course ID
 * @return string Path to dataset directory
 * @throws moodle_exception If directory creation fails
 */
function block_studentperformancepredictor_ensure_dataset_directory($courseid) {
    global $CFG;

    // Create course-specific dataset directory with proper Windows compatibility
    $basedir = $CFG->dataroot . DIRECTORY_SEPARATOR . 'blocks_studentperformancepredictor';
    $coursedir = $basedir . DIRECTORY_SEPARATOR . 'course_' . $courseid;
    $datasetsdir = $coursedir . DIRECTORY_SEPARATOR . 'datasets';

    // Create directories with proper permissions for Windows/XAMPP
    foreach ([$basedir, $coursedir, $datasetsdir] as $dir) {
        if (!file_exists($dir)) {
            if (!mkdir($dir, 0777, true)) {
                throw new \moodle_exception('directorycreateerror', 'block_studentperformancepredictor', '', $dir);
            }
            // Explicitly set permissions for XAMPP
            chmod($dir, 0777);
        }

        // Check if directory is writable
        if (!is_writable($dir)) {
            // Try to make it writable for XAMPP
            chmod($dir, 0777);
            if (!is_writable($dir)) {
                throw new \moodle_exception('directorynotwritable', 'block_studentperformancepredictor', '', $dir);
            }
        }
    }

    return $datasetsdir;
}

/**
 * Creates and ensures the models directory exists for a course.
 *
 * @param int $courseid Course ID
 * @return string Path to models directory
 * @throws moodle_exception If directory creation fails
 */
function block_studentperformancepredictor_ensure_models_directory($courseid) {
    global $CFG;

    // Create course-specific models directory with proper Windows compatibility
    $basedir = $CFG->dataroot . DIRECTORY_SEPARATOR . 'blocks_studentperformancepredictor';
    $coursedir = $basedir . DIRECTORY_SEPARATOR . 'course_' . $courseid;
    $modelsdir = $coursedir . DIRECTORY_SEPARATOR . 'models';

    // Create directories with proper permissions for Windows/XAMPP
    foreach ([$basedir, $coursedir, $modelsdir] as $dir) {
        if (!file_exists($dir)) {
            if (!mkdir($dir, 0777, true)) {
                throw new \moodle_exception('directorycreateerror', 'block_studentperformancepredictor', '', $dir);
            }
            // Explicitly set permissions for XAMPP
            chmod($dir, 0777);
        }

        // Check if directory is writable
        if (!is_writable($dir)) {
            // Try to make it writable for XAMPP
            chmod($dir, 0777);
            if (!is_writable($dir)) {
                throw new \moodle_exception('directorynotwritable', 'block_studentperformancepredictor', '', $dir);
            }
        }
    }

    return $modelsdir;
}

/**
 * Check if the course has an active model.
 *
 * @param int $courseid Course ID
 * @return bool True if there is an active model
 */
function block_studentperformancepredictor_has_active_model($courseid) {
    global $DB;

    // First check for a course-specific model
    if ($DB->record_exists('block_spp_models', array('courseid' => $courseid, 'active' => 1, 'trainstatus' => 'complete'))) {
        return true;
    }

    // If global models are enabled, check for a global model
    if (get_config('block_studentperformancepredictor', 'enableglobalmodel')) {
        return $DB->record_exists('block_spp_models', array('courseid' => 0, 'active' => 1, 'trainstatus' => 'complete'));
    }

    return false;
}

/**
 * Get the student's prediction for a course.
 *
 * @param int $courseid Course ID
 * @param int $userid User ID
 * @return object|bool Prediction object or false if not found
 */
function block_studentperformancepredictor_get_student_prediction($courseid, $userid) {
    global $DB;

    // First try to get course-specific prediction
    $prediction = $DB->get_record_sql(
        "SELECT p.* 
         FROM {block_spp_predictions} p 
         JOIN {block_spp_models} m ON p.modelid = m.id 
         WHERE p.courseid = ? 
         AND p.userid = ? 
         AND m.active = 1 
         AND m.courseid = ?
         ORDER BY p.timemodified DESC 
         LIMIT 1",
        array($courseid, $userid, $courseid)
    );

    /// If no course-specific prediction and global models are enabled, try global model
    if (!$prediction && get_config('block_studentperformancepredictor', 'enableglobalmodel')) {
        $prediction = $DB->get_record_sql(
            "SELECT p.* 
             FROM {block_spp_predictions} p 
             JOIN {block_spp_models} m ON p.modelid = m.id 
             WHERE p.courseid = ? 
             AND p.userid = ? 
             AND m.active = 1 
             AND m.courseid = 0
             ORDER BY p.timemodified DESC 
             LIMIT 1",
            array($courseid, $userid)
        );
    }

    return $prediction;
}

/**
 * Get risk statistics for the course.
 *
 * @param int $courseid Course ID
 * @return object Risk statistics
 */
function block_studentperformancepredictor_get_course_risk_stats($courseid) {
    global $DB;

    $stats = new stdClass();
    $stats->total = 0;
    $stats->highrisk = 0;
    $stats->mediumrisk = 0;
    $stats->lowrisk = 0;

    // Get risk distribution from course-specific predictions first
    $sql = "SELECT p.riskvalue, COUNT(DISTINCT p.userid) as count
            FROM {block_spp_predictions} p
            JOIN {block_spp_models} m ON p.modelid = m.id
            WHERE p.courseid = :courseid
            AND m.active = 1
            AND (m.courseid = :courseid2 OR m.courseid = 0)
            GROUP BY p.riskvalue";

    $results = $DB->get_records_sql($sql, array('courseid' => $courseid, 'courseid2' => $courseid));

    // Convert risk values to readable form
    foreach ($results as $result) {
        $stats->total += $result->count;
        if ($result->riskvalue == 1) {
            $stats->lowrisk = $result->count;
        } else if ($result->riskvalue == 2) {
            $stats->mediumrisk = $result->count;
        } else if ($result->riskvalue == 3) {
            $stats->highrisk = $result->count;
        }
    }

    // If no predictions found, get total enrolled students
    if ($stats->total == 0) {
        $context = context_course::instance($courseid);
        $stats->total = count_enrolled_users($context, 'moodle/course:isincompletionreports');
    }

    return $stats;
}

/**
 * Get suggestions for a prediction.
 *
 * @param int $predictionid Prediction ID
 * @return array Suggestions
 */
function block_studentperformancepredictor_get_suggestions($predictionid) {
    global $DB;

    // Get suggestions for this prediction
    $sql = "SELECT s.*, cm.id as cmid, cm.name as cmname, m.name as modulename
            FROM {block_spp_suggestions} s
            LEFT JOIN {course_modules} cm ON s.cmid = cm.id
            LEFT JOIN {modules} m ON cm.module = m.id
            WHERE s.predictionid = :predictionid
            ORDER BY s.priority DESC";

    return $DB->get_records_sql($sql, array('predictionid' => $predictionid));
}

/**
 * Get risk text based on risk value.
 *
 * @param int $riskvalue Risk value (1-3)
 * @return string Risk text
 */
function block_studentperformancepredictor_get_risk_text($riskvalue) {
    switch ($riskvalue) {
        case 1:
            return get_string('lowrisk_label', 'block_studentperformancepredictor');
        case 2:
            return get_string('mediumrisk_label', 'block_studentperformancepredictor');
        case 3:
            return get_string('highrisk_label', 'block_studentperformancepredictor');
        default:
            return get_string('unknownrisk', 'block_studentperformancepredictor');
    }
}

/**
 * Get risk CSS class based on risk value.
 *
 * @param int $riskvalue Risk value (1-3)
 * @return string CSS class
 */
function block_studentperformancepredictor_get_risk_class($riskvalue) {
    switch ($riskvalue) {
        case 1:
            return 'spp-risk-low';
        case 2:
            return 'spp-risk-medium';
        case 3:
            return 'spp-risk-high';
        default:
            return 'spp-risk-unknown';
    }
}

/**
 * Mark a suggestion as viewed.
 *
 * @param int $suggestionid Suggestion ID
 * @return bool Success
 */
function block_studentperformancepredictor_mark_suggestion_viewed($suggestionid) {
    global $DB;

    $suggestion = $DB->get_record('block_spp_suggestions', array('id' => $suggestionid));
    if (!$suggestion) {
        return false;
    }

    $suggestion->viewed = 1;
    return $DB->update_record('block_spp_suggestions', $suggestion);
}

/**
 * Mark a suggestion as completed.
 *
 * @param int $suggestionid Suggestion ID
 * @return bool Success
 */
function block_studentperformancepredictor_mark_suggestion_completed($suggestionid) {
    global $DB;

    $suggestion = $DB->get_record('block_spp_suggestions', array('id' => $suggestionid));
    if (!$suggestion) {
        return false;
    }

    $suggestion->completed = 1;
    $suggestion->viewed = 1; // Also mark as viewed
    return $DB->update_record('block_spp_suggestions', $suggestion);
}

/**
 * Trigger prediction refresh for a course.
 *
 * @param int $courseid Course ID
 * @param int $userid User ID requesting the refresh (optional)
 * @return bool Success
 */
function block_studentperformancepredictor_trigger_prediction_refresh($courseid, $userid = null) {
    global $USER;

    // Use current user if not specified
    if ($userid === null) {
        $userid = $USER->id;
    }

    // Create adhoc task
    $task = new \block_studentperformancepredictor\task\refresh_predictions();
    $task->set_custom_data([
        'courseid' => $courseid,
        'userid' => $userid
    ]);

    // Queue task with high priority
    return \core\task\manager::queue_adhoc_task($task, true);
}

/**
 * Generate a prediction for a student.
 *
 * @param int $courseid Course ID
 * @param int $userid User ID
 * @return int|bool Prediction ID or false on failure
 */
function block_studentperformancepredictor_generate_prediction($courseid, $userid) {
    try {
        $predictor = new \block_studentperformancepredictor\analytics\predictor($courseid);
        $prediction = $predictor->predict_for_student($userid);
        return $prediction->id;
    } catch (Exception $e) {
        debugging('Error generating prediction: ' . $e->getMessage(), DEBUG_DEVELOPER);
        return false;
    }
}

/**
 * Train a model via the Python backend.
 *
 * @param int $courseid Course ID (0 for global model)
 * @param int $datasetid Dataset ID
 * @param string $algorithm Algorithm to use (optional)
 * @return int|bool Model ID or false on failure
 */
function block_studentperformancepredictor_train_model_via_backend($courseid, $datasetid, $algorithm = null) {
    try {
        $trainer = new \block_studentperformancepredictor\analytics\model_trainer($courseid, $datasetid);
        return $trainer->train_model($algorithm);
    } catch (Exception $e) {
        debugging('Error training model: ' . $e->getMessage(), DEBUG_DEVELOPER);

        // Update model status to failed if we have a model ID
        $data = ['error' => $e->getMessage()];
        if (isset($e->modelid)) {
            \block_studentperformancepredictor\analytics\training_manager::update_model_status($e->modelid, 'failed', $data);
        }

        return false;
    }
}

/**
 * Call the Python backend API.
 *
 * @param string $endpoint API endpoint
 * @param array $data Data to send
 * @return array|bool Response data or false on failure
 */
function block_studentperformancepredictor_call_backend_api($endpoint, $data) {
    // Get API settings
    $apiurl = get_config('block_studentperformancepredictor', 'python_api_url');
    if (empty($apiurl)) {
        $apiurl = 'http://localhost:5000';
    }

    // Ensure URL ends with the endpoint
    if (substr($apiurl, -strlen($endpoint)) !== $endpoint) {
        $apiurl = rtrim($apiurl, '/') . '/' . ltrim($endpoint, '/');
    }

    $apikey = get_config('block_studentperformancepredictor', 'python_api_key');
    if (empty($apikey)) {
        $apikey = 'changeme';
    }

    // Initialize curl
    $curl = new \curl();
    $options = [
        'CURLOPT_TIMEOUT' => 300,
        'CURLOPT_RETURNTRANSFER' => true,
        'CURLOPT_HTTPHEADER' => [
            'Content-Type: application/json',
            'X-API-Key: ' . $apikey
        ],
        // Add these for Windows XAMPP compatibility
        'CURLOPT_SSL_VERIFYHOST' => 0,
        'CURLOPT_SSL_VERIFYPEER' => 0
    ];

    try {
        $response = $curl->post($apiurl, json_encode($data), $options);
        $httpcode = $curl->get_info()['http_code'] ?? 0;

        if ($httpcode !== 200) {
            debugging('Backend API error: HTTP ' . $httpcode . ' - ' . $response, DEBUG_DEVELOPER);
            return false;
        }

        $responseData = json_decode($response, true);
        if (!is_array($responseData)) {
            debugging('Invalid response format from backend: ' . substr($response, 0, 200), DEBUG_DEVELOPER);
            return false;
        }

        return $responseData;
    } catch (\Exception $e) {
        debugging('Backend API error: ' . $e->getMessage(), DEBUG_DEVELOPER);
        return false;
    }
}

/**
 * Refresh predictions for a course via the Python backend.
 *
 * @param int $courseid Course ID
 * @return array Result statistics
 */
function block_studentperformancepredictor_refresh_predictions_via_backend($courseid) {
    global $DB;

    $context = \context_course::instance($courseid);
    $students = get_enrolled_users($context, 'moodle/course:isincompletionreports');

    $result = [
        'success' => 0,
        'errors' => 0,
        'total' => count($students)
    ];

    // Get active model - course model first, then global if enabled
    $model = $DB->get_record('block_spp_models', ['courseid' => $courseid, 'active' => 1, 'trainstatus' => 'complete']);
    if (!$model && get_config('block_studentperformancepredictor', 'enableglobalmodel')) {
        $model = $DB->get_record('block_spp_models', ['courseid' => 0, 'active' => 1, 'trainstatus' => 'complete']);
    }

    if (!$model) {
        return $result;
    }

    // Call backend for each student
    foreach ($students as $student) {
        try {
            $predictionid = block_studentperformancepredictor_generate_prediction($courseid, $student->id);
            if ($predictionid) {
                $result['success']++;
            } else {
                $result['errors']++;
            }
        } catch (Exception $e) {
            debugging('Error refreshing prediction for student ' . $student->id . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            $result['errors']++;
        }
    }

    // Update last refresh time
    set_config('lastrefresh_' . $courseid, time(), 'block_studentperformancepredictor');

    return $result;
}

/**
 * Check if global models are enabled and available.
 *
 * @return bool True if a global model is available
 */
function block_studentperformancepredictor_has_global_model() {
    global $DB;

    if (!get_config('block_studentperformancepredictor', 'enableglobalmodel')) {
        return false;
    }

    return $DB->record_exists('block_spp_models', ['courseid' => 0, 'active' => 1, 'trainstatus' => 'complete']);
}