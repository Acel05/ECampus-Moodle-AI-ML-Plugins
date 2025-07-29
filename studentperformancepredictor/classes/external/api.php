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
 * External API for Student Performance Predictor.
 *
 * @package    block_studentperformancepredictor
 * @copyright  2023 Your Name <[Email]>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_studentperformancepredictor\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

/**
 * External API class for Student Performance Predictor block.
 */
class api extends \external_api {

    /**
     * Returns description of mark_suggestion_viewed parameters.
     *
     * @return \external_function_parameters
     */
    public static function mark_suggestion_viewed_parameters() {
        return new \external_function_parameters([
            'suggestionid' => new \external_value(PARAM_INT, 'Suggestion ID')
        ]);
    }

    /**
     * Mark a suggestion as viewed.
     *
     * @param int $suggestionid Suggestion ID
     * @return array Operation result
     */
    public static function mark_suggestion_viewed($suggestionid) {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::mark_suggestion_viewed_parameters(), [
            'suggestionid' => $suggestionid
        ]);

        // Get suggestion.
        $suggestion = $DB->get_record('block_spp_suggestions', ['id' => $params['suggestionid']], '*', MUST_EXIST);

        // Security checks.
        $context = \context_course::instance($suggestion->courseid);
        self::validate_context($context);

        // Only the user who received the suggestion can mark it as viewed.
        if ($suggestion->userid != $USER->id) {
            throw new \moodle_exception('nopermission', 'block_studentperformancepredictor');
        }

        // Mark as viewed.
        $success = block_studentperformancepredictor_mark_suggestion_viewed($suggestion->id);

        return [
            'status' => $success,
            'message' => $success ? get_string('suggestion_marked_viewed', 'block_studentperformancepredictor') 
                                 : get_string('suggestion_marked_viewed_error', 'block_studentperformancepredictor')
        ];
    }

    /**
     * Returns description of mark_suggestion_viewed returns.
     *
     * @return \external_single_structure
     */
    public static function mark_suggestion_viewed_returns() {
        return new \external_single_structure([
            'status' => new \external_value(PARAM_BOOL, 'Operation success status'),
            'message' => new \external_value(PARAM_TEXT, 'Operation message')
        ]);
    }

    /**
     * Returns description of mark_suggestion_completed parameters.
     *
     * @return \external_function_parameters
     */
    public static function mark_suggestion_completed_parameters() {
        return new \external_function_parameters([
            'suggestionid' => new \external_value(PARAM_INT, 'Suggestion ID')
        ]);
    }

    /**
     * Mark a suggestion as completed.
     *
     * @param int $suggestionid Suggestion ID
     * @return array Operation result
     */
    public static function mark_suggestion_completed($suggestionid) {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::mark_suggestion_completed_parameters(), [
            'suggestionid' => $suggestionid
        ]);

        // Get suggestion.
        $suggestion = $DB->get_record('block_spp_suggestions', ['id' => $params['suggestionid']], '*', MUST_EXIST);

        // Security checks.
        $context = \context_course::instance($suggestion->courseid);
        self::validate_context($context);

        // Only the user who received the suggestion can mark it as completed.
        if ($suggestion->userid != $USER->id) {
            throw new \moodle_exception('nopermission', 'block_studentperformancepredictor');
        }

        // Mark as completed.
        $success = block_studentperformancepredictor_mark_suggestion_completed($suggestion->id);

        return [
            'status' => $success,
            'message' => $success ? get_string('suggestion_marked_completed', 'block_studentperformancepredictor') 
                                 : get_string('suggestion_marked_completed_error', 'block_studentperformancepredictor')
        ];
    }

    /**
     * Returns description of mark_suggestion_completed returns.
     *
     * @return \external_single_structure
     */
    public static function mark_suggestion_completed_returns() {
        return new \external_single_structure([
            'status' => new \external_value(PARAM_BOOL, 'Operation success status'),
            'message' => new \external_value(PARAM_TEXT, 'Operation message')
        ]);
    }

    /**
     * Returns description of get_student_predictions parameters.
     *
     * @return \external_function_parameters
     */
    public static function get_student_predictions_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
            'userid' => new \external_value(PARAM_INT, 'User ID', VALUE_DEFAULT, 0)
        ]);
    }

    /**
     * Get predictions for a student.
     *
     * @param int $courseid Course ID
     * @param int $userid User ID (0 for current user)
     * @return array Prediction data
     */
    public static function get_student_predictions($courseid, $userid = 0) {
        global $USER, $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::get_student_predictions_parameters(), [
            'courseid' => $courseid,
            'userid' => $userid
        ]);

        // Security checks.
        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);

        // If no user ID specified, use current user.
        if (empty($params['userid'])) {
            $params['userid'] = $USER->id;
        }

        // Check permission if viewing other user's predictions.
        if ($params['userid'] != $USER->id && !has_capability('block/studentperformancepredictor:viewallpredictions', $context)) {
            throw new \moodle_exception('nopermission', 'block_studentperformancepredictor');
        }

        // Get prediction.
        $prediction = block_studentperformancepredictor_get_student_prediction($params['courseid'], $params['userid']);

        if (!$prediction) {
            return [
                'has_prediction' => false,
                'message' => get_string('noprediction', 'block_studentperformancepredictor')
            ];
        }

        // Get suggestions.
        $suggestions = block_studentperformancepredictor_get_suggestions($prediction->id);

        $suggestiondata = [];
        foreach ($suggestions as $suggestion) {
            $suggestiondata[] = [
                'id' => $suggestion->id,
                'reason' => $suggestion->reason,
                'resource_type' => $suggestion->resourcetype,
                'viewed' => (bool)$suggestion->viewed,
                'completed' => (bool)$suggestion->completed,
                'cmid' => $suggestion->cmid,
                'cmname' => $suggestion->cmname ?? '',
                'modulename' => $suggestion->modulename ?? ''
            ];
        }

        return [
            'has_prediction' => true,
            'prediction' => [
                'id' => $prediction->id,
                'pass_probability' => round($prediction->passprob * 100),
                'risk_level' => $prediction->riskvalue,
                'risk_text' => block_studentperformancepredictor_get_risk_text($prediction->riskvalue),
                'time_created' => $prediction->timecreated,
                'time_modified' => $prediction->timemodified
            ],
            'suggestions' => $suggestiondata
        ];
    }

    /**
     * Returns description of get_student_predictions returns.
     *
     * @return \external_single_structure
     */
    public static function get_student_predictions_returns() {
        return new \external_single_structure([
            'has_prediction' => new \external_value(PARAM_BOOL, 'Whether a prediction exists'),
            'prediction' => new \external_single_structure([
                'id' => new \external_value(PARAM_INT, 'Prediction ID'),
                'pass_probability' => new \external_value(PARAM_INT, 'Pass probability percentage'),
                'risk_level' => new \external_value(PARAM_INT, 'Risk level (1-3)'),
                'risk_text' => new \external_value(PARAM_TEXT, 'Risk level text'),
                'time_created' => new \external_value(PARAM_INT, 'Time created timestamp'),
                'time_modified' => new \external_value(PARAM_INT, 'Time modified timestamp')
            ], 'Prediction data', VALUE_OPTIONAL),
            'suggestions' => new \external_multiple_structure(
                new \external_single_structure([
                    'id' => new \external_value(PARAM_INT, 'Suggestion ID'),
                    'reason' => new \external_value(PARAM_TEXT, 'Suggestion reason'),
                    'resource_type' => new \external_value(PARAM_TEXT, 'Resource type'),
                    'viewed' => new \external_value(PARAM_BOOL, 'Whether suggestion was viewed'),
                    'completed' => new \external_value(PARAM_BOOL, 'Whether suggestion was completed'),
                    'cmid' => new \external_value(PARAM_INT, 'Course module ID', VALUE_OPTIONAL),
                    'cmname' => new \external_value(PARAM_TEXT, 'Course module name', VALUE_OPTIONAL),
                    'modulename' => new \external_value(PARAM_TEXT, 'Module name', VALUE_OPTIONAL)
                ]),
                'Suggestions',
                VALUE_OPTIONAL
            ),
            'message' => new \external_value(PARAM_TEXT, 'Message if no prediction', VALUE_OPTIONAL)
        ]);
    }

    /**
     * Returns description of trigger_model_training parameters.
     *
     * @return \external_function_parameters
     */
    public static function trigger_model_training_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
            'datasetid' => new \external_value(PARAM_INT, 'Dataset ID'),
            'algorithm' => new \external_value(PARAM_TEXT, 'Algorithm to use', VALUE_DEFAULT, '')
        ]);
    }

    /**
     * Trigger model training.
     *
     * @param int $courseid Course ID
     * @param int $datasetid Dataset ID
     * @param string $algorithm Algorithm to use
     * @return array Operation result
     */
    public static function trigger_model_training($courseid, $datasetid, $algorithm = '') {
        global $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::trigger_model_training_parameters(), [
            'courseid' => $courseid,
            'datasetid' => $datasetid,
            'algorithm' => $algorithm
        ]);

        // Security checks.
        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);

        // Check permission.
        require_capability('block/studentperformancepredictor:managemodels', $context);

        // Queue the training task.
        $task = new \block_studentperformancepredictor\task\train_model();
        $task->set_custom_data([
            'courseid' => $params['courseid'],
            'datasetid' => $params['datasetid'],
            'algorithm' => $params['algorithm'],
            'userid' => $USER->id
        ]);

        // Queue the task to run as soon as possible.
        \core\task\manager::queue_adhoc_task($task, true);

        return [
            'status' => true,
            'message' => get_string('model_training_queued', 'block_studentperformancepredictor')
        ];
    }

    /**
     * Returns description of trigger_model_training returns.
     *
     * @return \external_single_structure
     */
    public static function trigger_model_training_returns() {
        return new \external_single_structure([
            'status' => new \external_value(PARAM_BOOL, 'Operation success status'),
            'message' => new \external_value(PARAM_TEXT, 'Operation message')
        ]);
    }

    /**
     * Returns description of refresh_predictions parameters.
     *
     * @return \external_function_parameters
     */
    public static function refresh_predictions_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID')
        ]);
    }

    /**
     * Refresh predictions for a course.
     *
     * @param int $courseid Course ID
     * @return array Operation result
     */
    public static function refresh_predictions($courseid) {
        global $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::refresh_predictions_parameters(), [
            'courseid' => $courseid
        ]);

        // Security checks.
        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);

        // Check permission.
        require_capability('block/studentperformancepredictor:viewallpredictions', $context);

        // Trigger refresh.
        $success = block_studentperformancepredictor_trigger_prediction_refresh($params['courseid'], $USER->id);

        return [
            'status' => $success,
            'message' => $success ? get_string('predictionsrefreshqueued', 'block_studentperformancepredictor') 
                                 : get_string('predictionsrefresherror', 'block_studentperformancepredictor')
        ];
    }

    /**
     * Returns description of refresh_predictions returns.
     *
     * @return \external_single_structure
     */
    public static function refresh_predictions_returns() {
        return new \external_single_structure([
            'status' => new \external_value(PARAM_BOOL, 'Operation success status'),
            'message' => new \external_value(PARAM_TEXT, 'Operation message')
        ]);
    }
}