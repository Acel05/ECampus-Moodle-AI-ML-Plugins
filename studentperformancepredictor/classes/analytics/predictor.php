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
 * Prediction engine for Student Performance Predictor.
 *
 * @package    block_studentperformancepredictor
 * @copyright  2023 Your Name <[Email]>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_studentperformancepredictor\analytics;

defined('MOODLE_INTERNAL') || die();

// Ensure lib.php is included at the top for all global function definitions
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Prediction engine for student performance.
 *
 * NOTE: As of the unified plugin architecture, all predictions are made by calling
 * the Python backend /predict endpoint. No ML logic is performed in PHP.
 * This class is responsible only for orchestrating backend calls and storing prediction results.
 */
class predictor {
    /** @var int Course ID */
    protected $courseid;

    /** @var object The model record from database */
    protected $model;

    /** @var data_preprocessor Preprocessor instance */
    protected $preprocessor;

    /** @var suggestion_generator Suggestion generator instance */
    protected $suggestiongenerator;

    /**
     * Constructor.
     *
     * @param int $courseid Course ID
     */
    public function __construct($courseid) {
        global $DB;

        $this->courseid = $courseid;

        // Query for active model - course model has priority
        $params = ['courseid' => $courseid, 'active' => 1, 'trainstatus' => 'complete'];
        $this->model = $DB->get_record('block_spp_models', $params);

        // If no course model and global models are enabled, try to get a global model
        if (!$this->model && get_config('block_studentperformancepredictor', 'enableglobalmodel')) {
            $params = ['courseid' => 0, 'active' => 1, 'trainstatus' => 'complete'];
            $this->model = $DB->get_record('block_spp_models', $params);
        }

        if (!$this->model) {
            throw new \moodle_exception('noactivemodel', 'block_studentperformancepredictor');
        }

        $this->preprocessor = new data_preprocessor($courseid);
        $this->suggestiongenerator = new suggestion_generator($courseid);
    }

    /**
     * Generate predictions for a specific student.
     *
     * @param int $userid User ID
     * @return object Prediction result
     */
    public function predict_for_student($userid) {
        global $DB, $CFG;

        // Get comprehensive student data for prediction
        $studentdata = $this->get_comprehensive_student_data($userid);

        if (empty($studentdata)) {
            throw new \moodle_exception('nostudentdata', 'block_studentperformancepredictor');
        }

        // Create feature vector for prediction
        $features = $this->create_feature_vector($studentdata);

        // Make prediction using the backend
        $prediction = $this->make_prediction($features);

        // Determine risk level based on pass probability
        $risklevel = $this->calculate_risk_level($prediction->passprob);

        // Store prediction in database
        $predictionrecord = new \stdClass();
        $predictionrecord->modelid = $this->model->id;
        $predictionrecord->courseid = $this->courseid;
        $predictionrecord->userid = $userid;
        $predictionrecord->passprob = $prediction->passprob;
        $predictionrecord->riskvalue = $risklevel;
        $predictionrecord->predictiondata = json_encode($prediction->details);
        $predictionrecord->timecreated = time();
        $predictionrecord->timemodified = time();

        try {
            $predictionid = $DB->insert_record('block_spp_predictions', $predictionrecord);

            // Generate suggestions based on prediction
            $this->suggestiongenerator->generate_suggestions($predictionid, $userid, $predictionrecord);

            return $DB->get_record('block_spp_predictions', array('id' => $predictionid));
        } catch (\Exception $e) {
            debugging('Error storing prediction: ' . $e->getMessage(), DEBUG_DEVELOPER);
            throw new \moodle_exception('errorpredicting', 'block_studentperformancepredictor', '', $e->getMessage());
        }
    }

    /**
     * Get comprehensive student data for prediction.
     *
     * @param int $userid User ID
     * @return array Student data for prediction
     */
    protected function get_comprehensive_student_data($userid) {
        global $DB, $CFG;

        $data = array();

        // Get all the student's courses
        $courses = enrol_get_all_users_courses($userid, true);

        // Basic user data
        $user = $DB->get_record('user', array('id' => $userid), 'id, lastname, email, country, timezone, lastaccess, firstaccess');
        $data['user_id'] = $userid;
        $data['days_since_last_access'] = (time() - max(1, $user->lastaccess)) / 86400;
        $data['days_since_first_access'] = (time() - max(1, $user->firstaccess)) / 86400;

        // Initialize all potential data points to 0/default to prevent "Undefined index" notices
        $data['total_courses'] = count($courses);
        $data['activity_level'] = 0;
        $data['submission_count'] = 0;
        $data['grade_average'] = 0;
        $data['grade_count'] = 0;
        $data['total_course_modules_accessed'] = 0;
        $data['current_course_modules_accessed'] = 0;
        $data['total_forum_posts'] = 0;
        $data['current_course_forum_posts'] = 0;
        $data['total_assignment_submissions'] = 0;
        $data['current_course_assignment_submissions'] = 0;
        $data['total_quiz_attempts'] = 0;
        $data['current_course_quiz_attempts'] = 0;
        $data['current_course_grade'] = 0;
        $data['current_course_grade_max'] = 100;
        $data['current_course_grade_percentage'] = 0;
        $data['engagement_score'] = 0;
        $data['historical_performance'] = 0;

        // Calculate 'activity_level' (using logs count for last week for better relevance)
        $sql_activity = "SELECT COUNT(*) FROM {logstore_standard_log}
                         WHERE userid = :userid AND courseid = :courseid
                         AND timecreated > :oneweekago";
        $data['activity_level'] = $DB->count_records_sql($sql_activity, [
            'userid' => $userid,
            'courseid' => $this->courseid,
            'oneweekago' => time() - (7 * 24 * 3600) // Last 7 days
        ]);

        // Calculate 'submission_count'
        $sql_submissions = "SELECT COUNT(*) FROM {assign_submission} sub
                            JOIN {assign} a ON sub.assignment = a.id
                            WHERE sub.userid = :userid AND a.course = :courseid AND sub.status = :status";
        $data['submission_count'] = $DB->count_records_sql($sql_submissions, [
            'userid' => $userid,
            'courseid' => $this->courseid,
            'status' => 'submitted'
        ]);

        // Calculate 'grade_average' and 'grade_count'
        $gradesum = 0;
        $gradecount = 0;
        $sql_grades = "SELECT gg.finalgrade, gi.grademax
                       FROM {grade_items} gi
                       JOIN {grade_grades} gg ON gg.itemid = gi.id
                       WHERE gi.courseid = :courseid AND gg.userid = :userid AND gg.finalgrade IS NOT NULL
                       AND gi.itemtype != 'course'"; // Exclude course total itself
        $grades = $DB->get_records_sql($sql_grades, [
            'courseid' => $this->courseid,
            'userid' => $userid
        ]);

        foreach ($grades as $grade) {
            if ($grade->grademax > 0) {
                $gradesum += ($grade->finalgrade / $grade->grademax);
                $gradecount++;
            }
        }
        $data['grade_average'] = $gradecount > 0 ? $gradesum / $gradecount : 0;
        $data['grade_count'] = $gradecount;

        // Remaining comprehensive data points
        // (These might be used by suggestion_generator or for other comprehensive reporting,
        // but the core features for ML prediction are the 'activity_level', 'submission_count', etc.)

        // Get log counts for course modules accessed - safer query
        $sql = "SELECT COUNT(DISTINCT cmid) FROM {logstore_standard_log}
                WHERE userid = ? AND action = ? AND target = ?";
        $data['total_course_modules_accessed'] = $DB->count_records_sql($sql, [$userid, 'viewed', 'course_module']);

        // Get log counts for current course - safer query
        $sql = "SELECT COUNT(DISTINCT cmid) FROM {logstore_standard_log}
                WHERE userid = ? AND courseid = ? AND action = ? AND target = ?";
        $data['current_course_modules_accessed'] = $DB->count_records_sql($sql, [$userid, $this->courseid, 'viewed', 'course_module']);

        // Forum posts counts - fixed query
        $sql = "SELECT COUNT(*) FROM {forum_posts} fp
                JOIN {forum_discussions} fd ON fp.discussion = fd.id
                WHERE fp.userid = ?";
        $data['total_forum_posts'] = $DB->count_records_sql($sql, [$userid]);

        // Forum posts in current course - fixed query
        $sql = "SELECT COUNT(*) FROM {forum_posts} fp
                JOIN {forum_discussions} fd ON fp.discussion = fd.id
                JOIN {forum} f ON fd.forum = f.id
                WHERE fp.userid = ? AND f.course = ?";
        $data['current_course_forum_posts'] = $DB->count_records_sql($sql, [$userid, $this->courseid]);

        // Assignment submissions
        $params_assign = ['userid' => $userid, 'status' => 'submitted'];
        $data['total_assignment_submissions'] = $DB->count_records('assign_submission', $params_assign);

        // Assignment submissions in current course
        $sql_current_assign_submissions = "SELECT COUNT(*) FROM {assign_submission} sub
                                           JOIN {assign} a ON sub.assignment = a.id
                                           WHERE sub.userid = :userid AND a.course = :courseid AND sub.status = :status";
        $data['current_course_assignment_submissions'] = $DB->count_records_sql($sql_current_assign_submissions, ['userid' => $userid, 'courseid' => $this->courseid, 'status' => 'submitted']);

        // Quiz attempts
        $params_quiz = ['userid' => $userid, 'state' => 'finished'];
        $data['total_quiz_attempts'] = $DB->count_records('quiz_attempts', $params_quiz);

        // Quiz attempts in current course
        $sql_current_quiz_attempts = "SELECT COUNT(*) FROM {quiz_attempts} qa
                                      JOIN {quiz} q ON qa.quiz = q.id
                                      WHERE qa.userid = :userid AND q.course = :courseid AND qa.state = :state";
        $data['current_course_quiz_attempts'] = $DB->count_records_sql($sql_current_quiz_attempts, ['userid' => $userid, 'courseid' => $this->courseid, 'state' => 'finished']);

        // Current course grade
        try {
            $grade = grade_get_course_grade($userid, $this->courseid);
            if ($grade && $grade->grade !== null) {
                $data['current_course_grade'] = $grade->grade;
                $data['current_course_grade_max'] = $grade->grade_item->grademax;
                $data['current_course_grade_percentage'] = ($grade->grade / $grade->grade_item->grademax) * 100;
            } else {
                $data['current_course_grade'] = 0;
                $data['current_course_grade_max'] = 100;
                $data['current_course_grade_percentage'] = 0;
            }
        } catch (\Exception $e) {
            debugging('Error getting course grade: ' . $e->getMessage(), DEBUG_DEVELOPER);
            $data['current_course_grade'] = 0;
            $data['current_course_grade_max'] = 100;
            $data['current_course_grade_percentage'] = 0;
        }

        // Calculate overall engagement score
        $data['engagement_score'] = $this->calculate_engagement_score($data);

        // Calculate historical performance
        $data['historical_performance'] = $this->calculate_historical_performance($userid);

        return $data;
    }

    /**
     * Calculate overall engagement score (0-1).
     *
     * @param array $data Student data
     * @return float Engagement score
     */
    protected function calculate_engagement_score($data) {
        $score = 0;
        $factors = 0;

        // Module access factor
        if (isset($data['current_course_modules_accessed']) && $data['current_course_modules_accessed'] > 0) {
            $score += min(1, $data['current_course_modules_accessed'] / 10); // Scale up to 10 modules
            $factors++;
        }

        // Forum participation factor
        if (isset($data['current_course_forum_posts']) && $data['current_course_forum_posts'] > 0) {
            $score += min(1, $data['current_course_forum_posts'] / 5); // Scale up to 5 posts
            $factors++;
        }

        // Assignment submission factor
        if (isset($data['current_course_assignment_submissions']) && $data['current_course_assignment_submissions'] > 0) {
            $score += min(1, $data['current_course_assignment_submissions'] / 3); // Scale up to 3 submissions
            $factors++;
        }

        // Quiz attempt factor
        if (isset($data['current_course_quiz_attempts']) && $data['current_course_quiz_attempts'] > 0) {
            $score += min(1, $data['current_course_quiz_attempts'] / 3); // Scale up to 3 attempts
            $factors++;
        }

        // Last access factor (more recent = higher score)
        if (isset($data['days_since_last_access']) && $data['days_since_last_access'] < 30) { // Within last month
            $score += max(0, 1 - ($data['days_since_last_access'] / 30));
            $factors++;
        }

        // Return average score, default to 0.5 if no factors available
        return $factors > 0 ? $score / $factors : 0.5;
    }

    /**
     * Calculate historical performance score (0-1).
     *
     * @param int $userid User ID
     * @return float Historical performance score
     */
    protected function calculate_historical_performance($userid) {
        global $DB;

        // Get average course grade percentage for completed courses
        $sql = "SELECT AVG(gg.finalgrade/gi.grademax) as avggrade
                FROM {grade_grades} gg
                JOIN {grade_items} gi ON gg.itemid = gi.id
                WHERE gg.userid = ? AND gi.itemtype = ? AND gg.finalgrade IS NOT NULL";

        $result = $DB->get_record_sql($sql, [$userid, 'course']);

        if ($result && $result->avggrade !== null) {
            return (float)$result->avggrade;
        }

        return 0.5; // Default to neutral if no history
    }

    /**
     * Create feature vector from student data for prediction.
     *
     * @param array $studentdata Student data
     * @return array Feature vector
     */
    protected function create_feature_vector($studentdata) {
        // Return the data as an associative array for the API
        return $studentdata;
    }

    /**
     * Make prediction using the model (backend-driven orchestration).
     *
     * This method calls the Python backend /predict endpoint and uses the returned prediction/probabilities.
     * No ML logic is performed in PHP.
     *
     * @param array $features Feature vector
     * @return object Prediction result
     */
    protected function make_prediction($features) {
        global $CFG;
        $result = new \stdClass();
        $result->details = array();

        // Call Python backend for prediction
        $apiurl = get_config('block_studentperformancepredictor', 'python_api_url');
        if (empty($apiurl)) {
            $apiurl = 'http://localhost:5000/predict';
        } else {
            // Ensure API URL ends with the predict endpoint
            if (substr($apiurl, -8) !== '/predict') {
                $apiurl = rtrim($apiurl, '/') . '/predict';
            }
        }
        $apikey = get_config('block_studentperformancepredictor', 'python_api_key');
        if (empty($apikey)) {
            $apikey = 'changeme';
        }

        // Initialize curl
        $curl = new \curl();
        $payload = [
            'model_id' => $this->model->modelid,
            'features' => $features
        ];
        $options = [
            'CURLOPT_TIMEOUT' => 60,
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_HTTPHEADER' => [
                'Content-Type: application/json',
                'X-API-Key: ' . $apikey
            ],
            // Add these for Windows/XAMPP compatibility
            'CURLOPT_SSL_VERIFYHOST' => 0,
            'CURLOPT_SSL_VERIFYPEER' => 0
        ];

        // Log the request for debugging
        $debug = get_config('block_studentperformancepredictor', 'enabledebug');
        if ($debug) {
            debugging('Prediction request to ' . $apiurl . ': ' . json_encode($payload), DEBUG_DEVELOPER);
        }

        try {
            $response = $curl->post($apiurl, json_encode($payload), $options);
            $httpcode = $curl->get_info()['http_code'] ?? 0;

            if ($debug) {
                debugging('Prediction response code: ' . $httpcode, DEBUG_DEVELOPER);
            }

            if ($httpcode === 200) {
                $data = json_decode($response, true);
                if (is_array($data) && isset($data['prediction'])) {
                    // Get the pass probability from response
                    if (isset($data['probability'])) {
                        $result->passprob = $data['probability'];
                    } else if (isset($data['probabilities']) && is_array($data['probabilities']) && count($data['probabilities']) >= 2) {
                        // Use the probability for class 1 (passing)
                        $result->passprob = $data['probabilities'][1];
                    } else if (isset($data['probabilities']) && is_array($data['probabilities'])) {
                        // Use the highest probability if class is unclear
                        $result->passprob = max($data['probabilities']);
                    } else if ($data['prediction'] == 1) {
                        // If only binary prediction available
                        $result->passprob = 0.75; // Default high probability for positive prediction
                    } else {
                        $result->passprob = 0.25; // Default low probability for negative prediction
                    }
                    $result->details['backend'] = $data;
                } else {
                    if ($debug) {
                        debugging('Invalid prediction response format: ' . $response, DEBUG_DEVELOPER);
                    }
                    $result->passprob = 0.5;
                    $result->details['backend_error'] = 'Invalid response format: ' . substr($response, 0, 200);
                }
            } else {
                if ($debug) {
                    debugging('Backend API error: HTTP ' . $httpcode . ' - ' . $response, DEBUG_DEVELOPER);
                }
                $result->passprob = 0.5;
                $result->details['backend_error'] = 'HTTP error ' . $httpcode . ': ' . substr($response, 0, 200);
            }
        } catch (\Exception $e) {
            if ($debug) {
                debugging('Exception during prediction API call: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
            $result->passprob = 0.5;
            $result->details['backend_error'] = 'Exception: ' . $e->getMessage();
        }

        // Ensure passprob is within valid range
        $result->passprob = max(0, min(1, $result->passprob));
        return $result;
    }

    /**
     * Calculate risk level based on pass probability.
     *
     * @param float $passprob Pass probability
     * @return int Risk level (1=low, 2=medium, 3=high)
     */
    protected function calculate_risk_level($passprob) {
        // Get risk thresholds from settings with defaults
        $lowrisk = get_config('block_studentperformancepredictor', 'lowrisk');
        if (empty($lowrisk) || !is_numeric($lowrisk)) {
            $lowrisk = 0.7; // Default
        }

        $mediumrisk = get_config('block_studentperformancepredictor', 'mediumrisk');
        if (empty($mediumrisk) || !is_numeric($mediumrisk)) {
            $mediumrisk = 0.4; // Default
        }

        if ($passprob >= $lowrisk) {
            return 1; // Low risk
        } else if ($passprob >= $mediumrisk) {
            return 2; // Medium risk
        } else {
            return 3; // High risk
        }
    }

    /**
     * Generate predictions for all students in the course.
     *
     * @return array Array of prediction records
     */
    public function predict_for_all_students() {
        global $DB;

        $context = \context_course::instance($this->courseid);
        $students = get_enrolled_users($context, 'moodle/course:isincompletionreports');

        $predictions = array();
        $errors = array();

        foreach ($students as $student) {
            try {
                $prediction = $this->predict_for_student($student->id);
                $predictions[] = $prediction;
            } catch (\Exception $e) {
                // Log error and continue with next student
                debugging('Error predicting for student ' . $student->id . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
                $errors[$student->id] = $e->getMessage();
            }
        }

        // Log summary
        debugging('Predictions generated for ' . count($predictions) . ' students with ' .
                 count($errors) . ' errors', DEBUG_DEVELOPER);

        return $predictions;
    }
}
