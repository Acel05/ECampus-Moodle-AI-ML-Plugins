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
 * Model trainer for Student Performance Predictor.
 *
 * @package    block_studentperformancepredictor
 * @copyright  2023 Your Name <[Email]>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_studentperformancepredictor\analytics;

defined('MOODLE_INTERNAL') || die();

/**
 * Model trainer for student performance prediction.
 *
 * This class is responsible for orchestrating the model training process
 * by calling the Python backend API. No ML logic is performed in PHP.
 */
class model_trainer {
    /** @var int Course ID */
    protected $courseid;

    /** @var int Dataset ID */
    protected $datasetid;

    /** @var data_preprocessor Preprocessor instance */
    protected $preprocessor;

    /**
     * Constructor.
     *
     * @param int $courseid Course ID
     * @param int $datasetid Dataset ID
     */
    public function __construct($courseid, $datasetid) {
        global $CFG;

        $this->courseid = $courseid;
        $this->datasetid = $datasetid;
        $this->preprocessor = new data_preprocessor($courseid);
    }

    /**
     * Train a new model by calling the Python backend API.
     *
     * @param string|null $algorithm Optional algorithm to use
     * @param array $options Training options
     * @return int ID of the newly created model
     * @throws \moodle_exception on error
     */
    public function train_model($algorithm = null, $options = array()) {
        global $DB, $USER, $CFG;

        if (function_exists('mtrace')) {
            mtrace("Starting model training process via backend API...");
        }

        // Validate inputs
        if (empty($this->datasetid)) {
            throw new \moodle_exception('invalidinput', 'block_studentperformancepredictor', '',
                'Dataset ID is missing');
        }

        // Verify dataset exists and belongs to the course if course-specific
        if ($this->courseid > 0) {
            $dataset = $DB->get_record('block_spp_datasets',
                array('id' => $this->datasetid, 'courseid' => $this->courseid));
        } else {
            // For global models (courseid=0), just check if dataset exists
            $dataset = $DB->get_record('block_spp_datasets', array('id' => $this->datasetid));
        }

        if (!$dataset) {
            throw new \moodle_exception('invaliddataset', 'block_studentperformancepredictor');
        }
        $dataset_filepath = $dataset->filepath; // Get the full path

        // If no algorithm specified, use the default from settings
        if (empty($algorithm)) {
            $algorithm = get_config('block_studentperformancepredictor', 'defaultalgorithm');
            if (empty($algorithm)) {
                $algorithm = 'randomforest'; // Default fallback
            }
        }

        // Call Python backend for training
        $apiurl = get_config('block_studentperformancepredictor', 'python_api_url');
        if (empty($apiurl)) {
            $apiurl = 'http://localhost:5000/train';
        } else {
            // Ensure API URL ends with the train endpoint
            if (substr($apiurl, -6) !== '/train') {
                $apiurl = rtrim($apiurl, '/') . '/train';
            }
        }

        $apikey = get_config('block_studentperformancepredictor', 'python_api_key');
        if (empty($apikey)) {
            $apikey = 'changeme';
        }

        // Normalize filepath for Windows compatibility
        $dataset_filepath = str_replace('\\', '/', $dataset_filepath);

        $payload = [
            'courseid' => $this->courseid,
            'dataset_filepath' => $dataset_filepath, // Send the full filepath as expected by Python backend
            'algorithm' => $algorithm,
            'userid' => $USER->id
        ];

        // Add any custom options to the payload
        if (!empty($options)) {
            $payload = array_merge($payload, $options);
        }

        // Enable debug output for training
        $debug = get_config('block_studentperformancepredictor', 'enabledebug');

        $curl = new \curl();
        $curl_options = [
            'CURLOPT_TIMEOUT' => 300, // 5 minutes timeout for training
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_HTTPHEADER' => [
                'Content-Type: application/json',
                'X-API-Key: ' . $apikey
            ],
            // Add these for Windows XAMPP compatibility
            'CURLOPT_SSL_VERIFYHOST' => 0,
            'CURLOPT_SSL_VERIFYPEER' => 0
        ];

        if ($debug && function_exists('mtrace')) {
            mtrace("Sending request to backend API: " . json_encode($payload));
        }

        try {
            $response = $curl->post($apiurl, json_encode($payload), $curl_options);
            $httpcode = $curl->get_info()['http_code'] ?? 0;

            if ($debug && function_exists('mtrace')) {
                mtrace("Prediction response code: " . $httpcode);
                mtrace("Response: " . $response);
            }

            if ($httpcode === 200) {
                $data = json_decode($response, true);
                if (!is_array($data) || !isset($data['model_id'])) {
                    throw new \moodle_exception('trainingfailed', 'block_studentperformancepredictor', '',
                        'Invalid response from backend: ' . $response);
                }

                if (function_exists('mtrace')) {
                    mtrace("Received successful response from backend. Model ID: " . $data['model_id']);
                }

                // Extract model details from response
                $model_id = $data['model_id'];
                $model_path = isset($data['model_path']) ? $data['model_path'] : null;
                $algorithm_used = isset($data['algorithm']) ? $data['algorithm'] : $algorithm;
                $accuracy = isset($data['metrics']['accuracy']) ? $data['metrics']['accuracy'] : null;
                $metrics = isset($data['metrics']) ? json_encode($data['metrics']) : null;

                // Create model record
                $model = new \stdClass();
                $model->courseid = $this->courseid;
                $model->datasetid = $this->datasetid;
                $model->modelname = ucfirst($algorithm_used) . ' Model - ' . date('Y-m-d H:i');
                $model->modelid = $model_id;
                $model->modelpath = $model_path;
                $model->algorithmtype = $algorithm_used;
                $model->featureslist = isset($data['feature_names']) ? json_encode($data['feature_names']) : '[]';
                $model->accuracy = $accuracy;
                $model->metrics = $metrics;
                $model->active = 0; // Not active by default
                $model->trainstatus = 'complete';
                $model->timecreated = time();
                $model->timemodified = time();
                $model->usermodified = $USER->id;

                $model_db_id = $DB->insert_record('block_spp_models', $model);

                if (function_exists('mtrace')) {
                    mtrace("Model training completed successfully. Moodle Model ID: $model_db_id");
                }

                // Trigger event for model trained
                $context = \context_course::instance($this->courseid > 0 ? $this->courseid : SITEID);
                $event = \block_studentperformancepredictor\event\model_trained::create([
                    'context' => $context,
                    'objectid' => $model_db_id,
                    'other' => [
                        'courseid' => $this->courseid,
                        'datasetid' => $this->datasetid,
                        'algorithm' => $algorithm_used
                    ]
                ]);
                $event->trigger();

                return $model_db_id;
            } else {
                $error = "HTTP $httpcode: " . $response;
                if (function_exists('mtrace')) {
                    mtrace("Backend API error: $error");
                }

                // Error handling for HTTP errors
                if ($httpcode >= 400 && $httpcode < 500) {
                    throw new \moodle_exception('trainingfailed', 'block_studentperformancepredictor', '',
                        'Client error: ' . $error);
                } else if ($httpcode >= 500) {
                    throw new \moodle_exception('trainingfailed', 'block_studentperformancepredictor', '',
                        'Server error: ' . $error);
                } else {
                    throw new \moodle_exception('trainingfailed', 'block_studentperformancepredictor', '', $error);
                }
            }
        } catch (\Exception $e) {
            if (function_exists('mtrace')) {
                mtrace("Exception during model training: " . $e->getMessage());
            }
            throw new \moodle_exception('trainingfailed', 'block_studentperformancepredictor', '', $e->getMessage());
        }
    }
}
