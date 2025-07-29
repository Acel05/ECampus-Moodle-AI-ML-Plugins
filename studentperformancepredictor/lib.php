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
 * Call the Python backend API with improved error handling.
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

    // If using Railway, ensure the URL is correct
    if (strpos($apiurl, 'railway.app') !== false && strpos($apiurl, 'http') === false) {
        $apiurl = 'https://' . $apiurl;
    }

    // Ensure URL ends with the endpoint
    if ($endpoint[0] == '/') {
        $endpoint = substr($endpoint, 1);
    }
    $apiurl = rtrim($apiurl, '/') . '/' . $endpoint;

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

    $debug = get_config('block_studentperformancepredictor', 'enabledebug');

    // Log request
    error_log("[SPP] API Request to: $apiurl");
    error_log("[SPP] API Data: " . json_encode($data));

    if ($debug) {
        debugging('Calling backend API: ' . $apiurl . ' with data: ' . json_encode($data), DEBUG_DEVELOPER);
    }

    try {
        $start_time = microtime(true);

        // Make the request
        $response = $curl->post($apiurl, json_encode($data), $options);

        $end_time = microtime(true);
        $duration = round($end_time - $start_time, 2);

        $httpcode = $curl->get_info()['http_code'] ?? 0;

        error_log("[SPP] API Response time: {$duration}s, Status code: $httpcode");

        if ($debug) {
            debugging('Backend API response code: ' . $httpcode, DEBUG_DEVELOPER);
            debugging('Backend API response time: ' . $duration . 's', DEBUG_DEVELOPER);

            // Log a truncated response for debugging
            $log_response = (strlen($response) > 1000) 
                ? substr($response, 0, 1000) . '...' 
                : $response;
            debugging('Backend API response: ' . $log_response, DEBUG_DEVELOPER);
        }

        if ($httpcode !== 200) {
            if ($debug) {
                debugging('Backend API error: HTTP ' . $httpcode . ' - ' . $response, DEBUG_DEVELOPER);
            }

            // Try to extract error details from response
            $error_details = '';
            try {
                $json_response = json_decode($response, true);
                if (isset($json_response['detail'])) {
                    $error_details = $json_response['detail'];
                }
            } catch (\Exception $e) {
                // Just use the raw response if JSON parsing fails
                $error_details = $response;
            }

            error_log("[SPP] Backend API error: HTTP $httpcode - $error_details");
            return false;
        }

        $responseData = json_decode($response, true);
        if (!is_array($responseData)) {
            if ($debug) {
                debugging('Invalid response format from backend: ' . substr($response, 0, 200), DEBUG_DEVELOPER);
            }
            error_log("[SPP] Invalid backend response format: " . substr($response, 0, 200));
            return false;
        }

        // Log successful response
        error_log("[SPP] API call successful, response contains " . count($responseData) . " elements");

        return $responseData;
    } catch (\Exception $e) {
        if ($debug) {
            debugging('Backend API error: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        error_log("[SPP] Backend API exception: " . $e->getMessage());
        error_log("[SPP] " . $e->getTraceAsString());
        return false;
    }
}

/**
 * Handle backend API errors consistently.
 * 
 * @param string $error Error message
 * @param string $endpoint API endpoint that failed
 * @param array $data Request data (will be sanitized)
 * @return string Formatted error message
 */
function block_studentperformancepredictor_handle_api_error($error, $endpoint, $data = null) {
    global $CFG;

    // Log the error
    $debug = get_config('block_studentperformancepredictor', 'enabledebug');
    $apiurl = get_config('block_studentperformancepredictor', 'python_api_url');

    // Build a detailed error message for admins/debugging
    $detailed_msg = "ML Backend API Error: $error\n";
    $detailed_msg .= "Endpoint: " . ($apiurl ? rtrim($apiurl, '/') . '/' . ltrim($endpoint, '/') : $endpoint) . "\n";

    if ($data && $debug) {
        // Sanitize sensitive data
        $sanitized_data = $data;
        if (isset($sanitized_data['api_key'])) {
            $sanitized_data['api_key'] = '***hidden***';
        }
        $detailed_msg .= "Data: " . json_encode($sanitized_data) . "\n";
    }

    // Add troubleshooting tips
    $detailed_msg .= "\nTroubleshooting:\n";
    $detailed_msg .= "1. Check if the ML backend is running and accessible\n";
    $detailed_msg .= "2. Verify the API URL is correct in plugin settings\n";
    $detailed_msg .= "3. Ensure the API keys match between Moodle and the ML backend\n";
    $detailed_msg .= "4. Check the ML backend logs for more details\n";

    // Add link to test backend page
    $detailed_msg .= "\nTest your backend connection: " . 
        $CFG->wwwroot . '/blocks/studentperformancepredictor/admin/testbackend.php';

    // Log detailed message for debugging
    debugging($detailed_msg, DEBUG_DEVELOPER);

    // Return a user-friendly message
    return get_string('backendconnectionerror', 'block_studentperformancepredictor');
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
    global $DB, $USER, $CFG;

    // Add detailed logging
    error_log("[SPP] Starting training model via backend for course $courseid, dataset $datasetid, algorithm $algorithm");

    try {
        // Get dataset information
        $dataset = $DB->get_record('block_spp_datasets', ['id' => $datasetid], '*', MUST_EXIST);

        // Normalize file path for backend
        $dataset_filepath = str_replace('\\', '/', $dataset->filepath);
        error_log("[SPP] Original dataset filepath: $dataset_filepath");

        // Bitnami path adjustments for mounted volumes
        if (strpos($dataset_filepath, '/bitnami/moodle') !== false) {
            // Convert Bitnami path to container path
            $dataset_filepath = str_replace('/bitnami/moodle', $CFG->dirroot, $dataset_filepath);
            error_log("[SPP] Adjusted dataset filepath for Bitnami: $dataset_filepath");
        }

        // Double check file exists
        if (!file_exists($dataset_filepath)) {
            error_log("[SPP] Warning: Dataset file not found at: $dataset_filepath");

            // Try to find the file in a different location
            $basename = basename($dataset_filepath);
            $alt_path = $CFG->dataroot . '/blocks_studentperformancepredictor/course_' . $courseid . '/datasets/' . $basename;

            if (file_exists($alt_path)) {
                error_log("[SPP] Found dataset at alternate path: $alt_path");
                $dataset_filepath = $alt_path;
            } else {
                error_log("[SPP] Could not find dataset file in alternate location");
            }
        } else {
            error_log("[SPP] Dataset file exists at: $dataset_filepath");
        }

        // Prepare request payload
        $payload = [
            'courseid' => $courseid,
            'dataset_filepath' => $dataset_filepath,
            'algorithm' => $algorithm ?: 'randomforest',
            'userid' => $USER->id
        ];

        error_log("[SPP] Calling backend API with payload: " . json_encode($payload));

        // Call backend API
        $debug = get_config('block_studentperformancepredictor', 'enabledebug');
        if ($debug) {
            debugging('Training model via backend with payload: ' . json_encode($payload), DEBUG_DEVELOPER);
        }

        $response = block_studentperformancepredictor_call_backend_api('train', $payload);

        if (!$response || !isset($response['model_id'])) {
            $error_msg = isset($response['detail']) ? $response['detail'] : 'Invalid response from backend';
            error_log("[SPP] Error response from backend: " . json_encode($response));
            error_log("[SPP] Error message: $error_msg");
            throw new \moodle_exception('trainingfailed', 'block_studentperformancepredictor', '', $error_msg);
        }

        error_log("[SPP] Successful response from backend: " . json_encode($response));

        // Create a record in the database
        $model = new \stdClass();
        $model->courseid = $courseid;
        $model->datasetid = $datasetid;
        $model->modelname = ucfirst($response['algorithm']) . ' Model - ' . date('Y-m-d H:i');
        $model->modelid = $response['model_id'];
        $model->modelpath = $response['model_path'] ?? null;
        $model->algorithmtype = $response['algorithm'];
        $model->featureslist = isset($response['feature_names']) ? json_encode($response['feature_names']) : '[]';
        $model->accuracy = $response['metrics']['accuracy'] ?? 0;
        $model->metrics = isset($response['metrics']) ? json_encode($response['metrics']) : null;
        $model->active = 0; // Not active by default
        $model->trainstatus = 'complete';
        $model->timecreated = time();
        $model->timemodified = time();
        $model->usermodified = $USER->id;

        // Check if we're updating an existing model or creating a new one
        $existing_model = $DB->get_record('block_spp_models', [
            'courseid' => $courseid,
            'datasetid' => $datasetid,
            'trainstatus' => 'training',
            'algorithmtype' => $response['algorithm']
        ], '*', IGNORE_MULTIPLE);

        if ($existing_model) {
            error_log("[SPP] Updating existing model {$existing_model->id}");

            // Update the existing model
            $model->id = $existing_model->id;
            if ($DB->update_record('block_spp_models', $model)) {
                error_log("[SPP] Successfully updated existing model");
                $model_db_id = $existing_model->id;
            } else {
                error_log("[SPP] Failed to update existing model, creating new record");
                // Fall back to creating a new record
                $model_db_id = $DB->insert_record('block_spp_models', $model);
            }
        } else {
            error_log("[SPP] Creating new model record");
            $model_db_id = $DB->insert_record('block_spp_models', $model);
        }

        if ($debug) {
            debugging("Model training completed successfully. Model ID: {$model_db_id}", DEBUG_DEVELOPER);
        }

        return $model_db_id;

    } catch (\Exception $e) {
        error_log("[SPP] Error training model: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        debugging('Error training model: ' . $e->getMessage(), DEBUG_DEVELOPER);

        // If we have a model info in the DB but training failed, mark it as failed
        if (isset($payload) && isset($courseid) && isset($datasetid)) {
            // Look for pending model
            $pending_model = $DB->get_record('block_spp_models', [
                'courseid' => $courseid,
                'datasetid' => $datasetid,
                'trainstatus' => 'pending'
            ], '*', IGNORE_MULTIPLE);

            if ($pending_model) {
                $pending_model->trainstatus = 'failed';
                $pending_model->errormessage = $e->getMessage();
                $pending_model->timemodified = time();
                $DB->update_record('block_spp_models', $pending_model);
                error_log("[SPP] Updated model {$pending_model->id} status to failed");
            }

            // Also look for models in training status
            $training_model = $DB->get_record('block_spp_models', [
                'courseid' => $courseid,
                'datasetid' => $datasetid,
                'trainstatus' => 'training'
            ], '*', IGNORE_MULTIPLE);

            if ($training_model) {
                $training_model->trainstatus = 'failed';
                $training_model->errormessage = $e->getMessage();
                $training_model->timemodified = time();
                $DB->update_record('block_spp_models', $training_model);
                error_log("[SPP] Updated model {$training_model->id} status to failed");
            }
        }

        return false;
    }
}

/**
 * Generate a prediction using the backend API.
 *
 * @param int $courseid Course ID
 * @param int $userid User ID
 * @return object|bool Prediction object or false
 */
function block_studentperformancepredictor_generate_prediction_via_backend($courseid, $userid) {
    global $DB, $CFG, $USER;

    try {
        // Get active model for this course
        $model = $DB->get_record('block_spp_models', ['courseid' => $courseid, 'active' => 1, 'trainstatus' => 'complete']);

        // If no course model and global models are enabled, try to get global model
        if (!$model && get_config('block_studentperformancepredictor', 'enableglobalmodel')) {
            $model = $DB->get_record('block_spp_models', ['courseid' => 0, 'active' => 1, 'trainstatus' => 'complete']);
        }

        if (!$model) {
            throw new \moodle_exception('noactivemodel', 'block_studentperformancepredictor');
        }

        // Get student data
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/lib/gradelib.php');
        require_once($CFG->dirroot . '/lib/grade/grade_grade.php');

        // Gather student features from Moodle data
        $features = [];

        // Basic user data
        $user = $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname, email, lastaccess, firstaccess');
        $features['user_id'] = $userid;
        $features['days_since_last_access'] = (time() - max(1, $user->lastaccess)) / 86400; // Convert to days
        $features['days_since_first_access'] = (time() - max(1, $user->firstaccess)) / 86400;

        // Activity level - count of logs in the past week
        $sql = "SELECT COUNT(*) FROM {logstore_standard_log} 
                WHERE userid = :userid AND courseid = :courseid 
                AND timecreated > :weekago";
        $features['activity_level'] = $DB->count_records_sql($sql, [
            'userid' => $userid,
            'courseid' => $courseid,
            'weekago' => time() - 7*24*60*60
        ]);

        // Assignment submission count
        $sql = "SELECT COUNT(*) FROM {assign_submission} sub
                JOIN {assign} a ON sub.assignment = a.id
                WHERE sub.userid = :userid AND a.course = :courseid AND sub.status = 'submitted'";
        $features['submission_count'] = $DB->count_records_sql($sql, [
            'userid' => $userid, 
            'courseid' => $courseid
        ]);

        // Course module access count
        $sql = "SELECT COUNT(DISTINCT contextinstanceid) FROM {logstore_standard_log}
                WHERE userid = :userid AND courseid = :courseid AND action = 'viewed' AND target = 'course_module'";
        $features['modules_accessed'] = $DB->count_records_sql($sql, [
            'userid' => $userid,
            'courseid' => $courseid
        ]);

        // Forum activity
        $sql = "SELECT COUNT(*) FROM {forum_posts} fp
                JOIN {forum_discussions} fd ON fp.discussion = fd.id
                JOIN {forum} f ON fd.forum = f.id
                WHERE fp.userid = :userid AND f.course = :courseid";
        $features['forum_posts'] = $DB->count_records_sql($sql, [
            'userid' => $userid,
            'courseid' => $courseid
        ]);

        // Current grade
        if (function_exists('grade_get_course_grade')) {
            try {
                $grade = grade_get_course_grade($userid, $courseid);
                if ($grade && isset($grade->grade)) {
                    $features['current_grade'] = $grade->grade;
                    if (isset($grade->grade_item->grademax) && $grade->grade_item->grademax > 0) {
                        $features['current_grade_percentage'] = ($grade->grade / $grade->grade_item->grademax) * 100;
                    } else {
                        $features['current_grade_percentage'] = 0;
                    }
                } else {
                    $features['current_grade'] = 0;
                    $features['current_grade_percentage'] = 0;
                }
            } catch (\Exception $e) {
                $features['current_grade'] = 0;
                $features['current_grade_percentage'] = 0;
            }
        } else {
            debugging('grade_get_course_grade() is not available in this context. Setting grade to 0.', DEBUG_DEVELOPER);
            $features['current_grade'] = 0;
            $features['current_grade_percentage'] = 0;
        }

        // Call the backend API for prediction
        $payload = [
            'model_id' => $model->modelid,
            'features' => $features
        ];

        $response = block_studentperformancepredictor_call_backend_api('predict', $payload);

        if (!$response || !isset($response['prediction']) || !isset($response['probability'])) {
            throw new \moodle_exception('predictionfailed', 'block_studentperformancepredictor');
        }

        // Determine risk level based on pass probability
        $passprob = $response['probability'];

        // Get risk thresholds from settings
        $lowrisk = get_config('block_studentperformancepredictor', 'lowrisk');
        if (empty($lowrisk) || !is_numeric($lowrisk)) {
            $lowrisk = 0.7; // Default
        }

        $mediumrisk = get_config('block_studentperformancepredictor', 'mediumrisk');
        if (empty($mediumrisk) || !is_numeric($mediumrisk)) {
            $mediumrisk = 0.4; // Default
        }

        if ($passprob >= $lowrisk) {
            $riskvalue = 1; // Low risk
        } else if ($passprob >= $mediumrisk) {
            $riskvalue = 2; // Medium risk
        } else {
            $riskvalue = 3; // High risk
        }

        // Save prediction to database
        $prediction = new \stdClass();
        $prediction->modelid = $model->id;
        $prediction->courseid = $courseid;
        $prediction->userid = $userid;
        $prediction->passprob = $passprob;
        $prediction->riskvalue = $riskvalue;
        $prediction->predictiondata = json_encode($response);
        $prediction->timecreated = time();
        $prediction->timemodified = time();

        $predictionid = $DB->insert_record('block_spp_predictions', $prediction);

        // Get the inserted prediction
        $prediction = $DB->get_record('block_spp_predictions', ['id' => $predictionid]);

        // Generate suggestions based on the prediction
        block_studentperformancepredictor_generate_suggestions($prediction);

        return $prediction;

    } catch (\Exception $e) {
        debugging('Error generating prediction: ' . $e->getMessage(), DEBUG_DEVELOPER);
        return false;
    }
}

/**
 * Generate a prediction for a student.
 *
 * @param int $courseid Course ID
 * @param int $userid User ID
 * @return int|bool Prediction ID or false on failure
 */
function block_studentperformancepredictor_generate_prediction($courseid, $userid) {
    // Use the backend-driven prediction system
    $prediction = block_studentperformancepredictor_generate_prediction_via_backend($courseid, $userid);

    if ($prediction) {
        return $prediction->id;
    }

    return false;
}

/**
 * Generate a new prediction for a student on demand.
 * This function is called when a student clicks the prediction button.
 *
 * @param int $courseid Course ID
 * @param int $userid User ID
 * @return object|bool Prediction object or false on failure
 */
function block_studentperformancepredictor_generate_new_prediction($courseid, $userid) {
    global $DB;

    try {
        // First check if there is an active model
        if (!block_studentperformancepredictor_has_active_model($courseid)) {
            throw new \moodle_exception('noactivemodel', 'block_studentperformancepredictor');
        }

        // Generate the prediction using the backend
        $prediction = block_studentperformancepredictor_generate_prediction_via_backend($courseid, $userid);

        if (!$prediction) {
            throw new \moodle_exception('predictionfailed', 'block_studentperformancepredictor');
        }

        // Log the action
        $event = \core\event\notification_viewed::create([
            'contextid' => context_course::instance($courseid)->id,
            'objectid' => $prediction->id,
            'other' => [
                'type' => 'new_prediction',
                'courseid' => $courseid
            ]
        ]);
        $event->trigger();

        return $prediction;
    } catch (Exception $e) {
        debugging('Error generating new prediction: ' . $e->getMessage(), DEBUG_DEVELOPER);
        return false;
    }
}

/**
 * Generate suggestions for a student based on their prediction.
 *
 * @param object $prediction Prediction object
 * @return array Array of suggestion IDs
 */
function block_studentperformancepredictor_generate_suggestions($prediction) {
    global $DB;

    // Get course context
    $context = \context_course::instance($prediction->courseid);

    // Get available course modules
    $modinfo = get_fast_modinfo($prediction->courseid);
    $cms = $modinfo->get_cms();

    // Get completion info
    $completion = new \completion_info(get_course($prediction->courseid));

    // Generate suggestions based on risk level
    $suggestions = [];

    // Get activities based on risk level
    $risk_level = $prediction->riskvalue;
    $priority_base = 5;

    // Activities to suggest based on risk level
    $activities_by_risk = [
        // Low risk (1)
        1 => ['forum' => 3, 'resource' => 2],
        // Medium risk (2)
        2 => ['quiz' => 7, 'forum' => 5, 'assign' => 6, 'resource' => 4],
        // High risk (3)
        3 => ['quiz' => 9, 'forum' => 7, 'assign' => 10, 'resource' => 8, 'workshop' => 6]
    ];

    $activities = $activities_by_risk[$risk_level] ?? [];

    // Add suggestions for incomplete activities
    foreach ($cms as $cm) {
        // Skip invisible modules
        if (!$cm->uservisible) {
            continue;
        }

        // Skip labels
        if ($cm->modname == 'label') {
            continue;
        }

        // Check if activity is already completed
        $completion_data = $completion->get_data($cm, false, $prediction->userid);
        $is_completed = isset($completion_data->completionstate) && 
                        $completion_data->completionstate == COMPLETION_COMPLETE;

        // If not completed and this activity type is in our list
        if (!$is_completed && isset($activities[$cm->modname])) {
            $suggestion = new \stdClass();
            $suggestion->predictionid = $prediction->id;
            $suggestion->courseid = $prediction->courseid;
            $suggestion->userid = $prediction->userid;
            $suggestion->cmid = $cm->id;
            $suggestion->resourcetype = $cm->modname;
            $suggestion->resourceid = $cm->instance;
            $suggestion->priority = $activities[$cm->modname];

            // Get reason string based on risk level and activity type
            $reason_key = 'suggestion_' . $cm->modname . '_' . 
                          ($risk_level == 1 ? 'low' : ($risk_level == 2 ? 'medium' : 'high'));
            $suggestion->reason = get_string($reason_key, 'block_studentperformancepredictor');

            $suggestion->timecreated = time();
            $suggestion->viewed = 0;
            $suggestion->completed = 0;

            $suggestions[] = $DB->insert_record('block_spp_suggestions', $suggestion);
        }
    }

    // Add general suggestions based on risk level
    $general_suggestions = [];

    // Always add time management suggestion
    $general_suggestions[] = [
        'reason' => get_string('suggestion_time_management', 'block_studentperformancepredictor'),
        'priority' => 3 + $risk_level
    ];

    // Medium and high risk get engagement suggestion
    if ($risk_level >= 2) {
        $general_suggestions[] = [
            'reason' => get_string('suggestion_engagement', 'block_studentperformancepredictor'),
            'priority' => 4 + $risk_level
        ];
    }

    // High risk gets study group and instructor help suggestions
    if ($risk_level == 3) {
        $general_suggestions[] = [
            'reason' => get_string('suggestion_study_group', 'block_studentperformancepredictor'),
            'priority' => 8
        ];

        $general_suggestions[] = [
            'reason' => get_string('suggestion_instructor_help', 'block_studentperformancepredictor'),
            'priority' => 9
        ];
    }

    // Add general suggestions
    foreach ($general_suggestions as $gen_suggestion) {
        $suggestion = new \stdClass();
        $suggestion->predictionid = $prediction->id;
        $suggestion->courseid = $prediction->courseid;
        $suggestion->userid = $prediction->userid;
        $suggestion->cmid = 0; // No specific module
        $suggestion->resourcetype = 'general';
        $suggestion->resourceid = 0;
        $suggestion->priority = $gen_suggestion['priority'];
        $suggestion->reason = $gen_suggestion['reason'];
        $suggestion->timecreated = time();
        $suggestion->viewed = 0;
        $suggestion->completed = 0;

        $suggestions[] = $DB->insert_record('block_spp_suggestions', $suggestion);
    }

    return $suggestions;
}

/**
 * Refresh predictions for a course via the backend.
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

/**
 * Clean up any pending models without corresponding tasks.
 *
 * @param int $courseid Course ID (0 for all courses)
 * @return int Number of models fixed
 */
function block_studentperformancepredictor_cleanup_pending_models($courseid = 0) {
    global $DB;

    $count = 0;
    $conditions = [];
    $params = [];

    // Set conditions based on courseid
    if ($courseid > 0) {
        $conditions[] = "courseid = :courseid";
        $params['courseid'] = $courseid;
    }

    // Add status conditions
    $conditions[] = "trainstatus IN ('pending', 'training')";

    // Build the WHERE clause
    $where = implode(' AND ', $conditions);

    // Get all pending/training models
    $models = $DB->get_records_select('block_spp_models', $where, $params);

    foreach ($models as $model) {
        // Check if there's a corresponding adhoc task
        $sql = "SELECT COUNT(*) FROM {task_adhoc}
                WHERE classname = ?
                AND " . $DB->sql_like('customdata', '?');

        $classname = '\\block_studentperformancepredictor\\task\\adhoc_train_model';
        $customdata = '%"courseid":' . $model->courseid . '%';
        $task_count = $DB->count_records_sql($sql, [$classname, $customdata]);

        // If no task found, mark model as failed
        if ($task_count == 0) {
            $model->trainstatus = 'failed';
            $model->errormessage = 'Task missing - state fixed automatically';
            $model->timemodified = time();
            $DB->update_record('block_spp_models', $model);
            $count++;
        }
    }

    return $count;
}
