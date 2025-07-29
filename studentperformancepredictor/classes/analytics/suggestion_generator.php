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
 * Suggestion generator for Student Performance Predictor.
 *
 * @package    block_studentperformancepredictor
 * @copyright  2023 Your Name <your.email@example.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_studentperformancepredictor\analytics;

defined('MOODLE_INTERNAL') || die();

use moodle_exception;
use dml_exception;

// Moodle core library includes for required functions/classes/constants
global $CFG;
require_once($CFG->dirroot . '/course/lib.php'); // get_course
require_once($CFG->libdir . '/completionlib.php'); // completion_info
require_once($CFG->libdir . '/gradelib.php'); // grade_get_course_grade
require_once($CFG->libdir . '/moodlelib.php'); // get_string, debugging

if (!defined('COMPLETION_COMPLETE')) {
    define('COMPLETION_COMPLETE', 1);
}

/**
 * Generates personalized suggestions for students.
 *
 * NOTE: In the unified plugin architecture, suggestions are generated based on backend-driven
 * predictions and risk levels. This class operates on the results of backend predictions only.
 * No ML logic is performed in PHP.
 */
class suggestion_generator {
    /** @var int Course ID */
    protected $courseid;

    /** @var object Course object */
    protected $course;

    /**
     * Constructor.
     *
     * @param int $courseid Course ID
     * @throws \moodle_exception If course not found
     */
    public function __construct($courseid) {
        $this->courseid = $courseid;
        $this->course = get_course($courseid);
        if (!$this->course) {
            throw new moodle_exception('invalidcourseid', 'error');
        }
    }

    /**
     * Generate suggestions based on prediction (backend-driven orchestration).
     *
     * This method uses the risk value from the backend prediction to generate suggestions.
     * No ML logic is performed in PHP.
     *
     * @param int $predictionid Prediction ID
     * @param int $userid User ID
     * @param object $prediction Prediction data (must have property riskvalue)
     * @return array Generated suggestions (IDs of inserted records)
     * @throws \dml_exception
     */
    public function generate_suggestions($predictionid, $userid, $prediction) {
        global $DB;

        $suggestions = array();

        // Get course modules for current course
        $modinfo = get_fast_modinfo($this->courseid);
        $cms = $modinfo->get_cms();

        // Get completion info for current course
        $completion = new completion_info($this->course);

        // Get activity type suggestions based on risk level
        $activitySuggestions = $this->get_activity_suggestions_by_risk($prediction->riskvalue);

        // Get activity completions for current course
        $completions = array();
        foreach ($cms as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            $completiondata = $completion->get_data($cm, false, $userid);
            $completions[$cm->id] = isset($completiondata->completionstate) ? $completiondata->completionstate : null;
        }

        // Identify weak areas based on grades
        $weakAreas = $this->identify_weak_areas($userid);

        // Generate personalized suggestions
        $allSuggestions = array();

        // 1. Add course-specific suggestions based on incomplete activities
        foreach ($cms as $cm) {
            if (!$cm->uservisible || $cm->modname == 'label') {
                continue;
            }

            // Check if activity is completed
            $isCompleted = isset($completions[$cm->id]) &&
                $completions[$cm->id] == COMPLETION_COMPLETE;

            // If not completed and this activity type is in our suggestions
            if (!$isCompleted && isset($activitySuggestions[$cm->modname])) {
                $suggestionRecord = new \stdClass();
                $suggestionRecord->predictionid = $predictionid;
                $suggestionRecord->courseid = $this->courseid;
                $suggestionRecord->userid = $userid;
                $suggestionRecord->cmid = $cm->id;
                $suggestionRecord->resourcetype = $cm->modname;
                $suggestionRecord->resourceid = $cm->instance;
                $suggestionRecord->priority = $activitySuggestions[$cm->modname]['priority'];

                // Customize reason based on weak areas
                $reason = $activitySuggestions[$cm->modname]['reason'];
                foreach ($weakAreas as $area) {
                    if (stripos($cm->name, $area) !== false) {
                        $reason .= ' ' . get_string('suggestion_targeted_area', 'block_studentperformancepredictor',
                                                      array('area' => $area));
                        $suggestionRecord->priority += 2; // Increase priority for targeted suggestions
                        break;
                    }
                }

                $suggestionRecord->reason = $reason;
                $suggestionRecord->timecreated = time();
                $suggestionRecord->viewed = 0;
                $suggestionRecord->completed = 0;

                $allSuggestions[] = $suggestionRecord;
            }
        }

        // 2. Add general study skill suggestions based on overall performance
        $generalSuggestions = $this->get_general_study_suggestions($prediction->riskvalue, $weakAreas);
        foreach ($generalSuggestions as $suggestion) {
            $suggestionRecord = new \stdClass();
            $suggestionRecord->predictionid = $predictionid;
            $suggestionRecord->courseid = $this->courseid;
            $suggestionRecord->userid = $userid;
            $suggestionRecord->cmid = 0; // No specific course module
            $suggestionRecord->resourcetype = 'general';
            $suggestionRecord->resourceid = 0;
            $suggestionRecord->priority = $suggestion['priority'];
            $suggestionRecord->reason = $suggestion['reason'];
            $suggestionRecord->timecreated = time();
            $suggestionRecord->viewed = 0;
            $suggestionRecord->completed = 0;

            $allSuggestions[] = $suggestionRecord;
        }

        // Sort suggestions by priority (highest first)
        usort($allSuggestions, function($a, $b) {
            return $b->priority - $a->priority;
        });

        // Save top 5 suggestions to database
        $count = 0;
        foreach ($allSuggestions as $suggestion) {
            try {
                $suggestions[] = $DB->insert_record('block_spp_suggestions', $suggestion);
            } catch (dml_exception $e) {
                // Log error but continue
                debugging('Failed to insert suggestion: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
            $count++;
            if ($count >= 5) break; // Limit to top 5 suggestions
        }

        return $suggestions;
    }

    /**
     * Get activity suggestions based on risk level.
     *
     * @param int $risklevel Risk level (1-3)
     * @return array Activity suggestions
     */
    protected function get_activity_suggestions_by_risk($risklevel) {
        $suggestions = array();

        // Low risk suggestions
        if ($risklevel == 1) {
            $suggestions['forum'] = array(
                'priority' => 3,
                'reason' => get_string('suggestion_forum_low', 'block_studentperformancepredictor')
            );

            $suggestions['resource'] = array(
                'priority' => 2,
                'reason' => get_string('suggestion_resource_low', 'block_studentperformancepredictor')
            );
        }

        // Medium risk suggestions
        else if ($risklevel == 2) {
            $suggestions['quiz'] = array(
                'priority' => 7,
                'reason' => get_string('suggestion_quiz_medium', 'block_studentperformancepredictor')
            );

            $suggestions['forum'] = array(
                'priority' => 5,
                'reason' => get_string('suggestion_forum_medium', 'block_studentperformancepredictor')
            );

            $suggestions['assign'] = array(
                'priority' => 6,
                'reason' => get_string('suggestion_assign_medium', 'block_studentperformancepredictor')
            );

            $suggestions['resource'] = array(
                'priority' => 4,
                'reason' => get_string('suggestion_resource_medium', 'block_studentperformancepredictor')
            );
        }

        // High risk suggestions
        else if ($risklevel == 3) {
            $suggestions['quiz'] = array(
                'priority' => 9,
                'reason' => get_string('suggestion_quiz_high', 'block_studentperformancepredictor')
            );

            $suggestions['forum'] = array(
                'priority' => 7,
                'reason' => get_string('suggestion_forum_high', 'block_studentperformancepredictor')
            );

            $suggestions['assign'] = array(
                'priority' => 10,
                'reason' => get_string('suggestion_assign_high', 'block_studentperformancepredictor')
            );

            $suggestions['resource'] = array(
                'priority' => 8,
                'reason' => get_string('suggestion_resource_high', 'block_studentperformancepredictor')
            );

            $suggestions['workshop'] = array(
                'priority' => 6,
                'reason' => get_string('suggestion_workshop_high', 'block_studentperformancepredictor')
            );
        }

        return $suggestions;
    }

    /**
     * Get general study skill suggestions based on risk level and weak areas.
     *
     * @param int $risklevel Risk level (1-3)
     * @param array $weakAreas Array of weak subject areas
     * @return array General study suggestions
     */
    protected function get_general_study_suggestions($risklevel, $weakAreas) {
        $suggestions = array();

        // Add time management suggestion for all risk levels
        $suggestions[] = array(
            'priority' => 3 + $risklevel,
            'reason' => get_string('suggestion_time_management', 'block_studentperformancepredictor')
        );

        // Add engagement suggestion for medium and high risk
        if ($risklevel >= 2) {
            $suggestions[] = array(
                'priority' => 4 + $risklevel,
                'reason' => get_string('suggestion_engagement', 'block_studentperformancepredictor')
            );
        }

        // Add study group suggestion for high risk
        if ($risklevel == 3) {
            $suggestions[] = array(
                'priority' => 8,
                'reason' => get_string('suggestion_study_group', 'block_studentperformancepredictor')
            );

            $suggestions[] = array(
                'priority' => 9,
                'reason' => get_string('suggestion_instructor_help', 'block_studentperformancepredictor')
            );
        }

        // Add weak area specific suggestions
        if (!empty($weakAreas)) {
            foreach ($weakAreas as $index => $area) {
                if ($index < 2) { // Limit to top 2 weak areas
                    $suggestions[] = array(
                        'priority' => 7 + $risklevel,
                        'reason' => get_string('suggestion_weak_area', 'block_studentperformancepredictor',
                                                array('area' => $area))
                    );
                }
            }
        }

        return $suggestions;
    }

    /**
     * Identify weak areas based on grades.
     *
     * @param int $userid User ID
     * @return array List of weak subject areas
     */
    protected function identify_weak_areas($userid) {
        global $DB;

        $weakAreas = array();

        // Get all grade items for the current course
        $sql = "SELECT gi.id, gi.itemname, gi.itemtype, gi.itemmodule, gi.iteminstance, \
                       gg.finalgrade, gi.grademax \
                FROM {grade_items} gi\n                LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :userid\n                WHERE gi.courseid = :courseid \n                AND gi.itemtype != 'course'\n                AND gg.finalgrade IS NOT NULL";

        $params = [
            'userid' => $userid,
            'courseid' => $this->courseid
        ];

        $gradeItems = $DB->get_records_sql($sql, $params);

        // Group items by module/category
        $modulePerformance = [];

        foreach ($gradeItems as $item) {
            // Skip items with no max grade
            if (empty($item->grademax) || $item->grademax == 0) {
                continue;
            }

            $percentage = ($item->finalgrade / $item->grademax) * 100;

            // Use module name or item name as category
            $category = !empty($item->itemmodule) ? $item->itemmodule : $item->itemname;

            if (!isset($modulePerformance[$category])) {
                $modulePerformance[$category] = [
                    'sum' => 0,
                    'count' => 0
                ];
            }

            $modulePerformance[$category]['sum'] += $percentage;
            $modulePerformance[$category]['count']++;
        }

        // Find weak areas (below 70%)
        foreach ($modulePerformance as $module => $data) {
            if ($data['count'] > 0) {
                $average = $data['sum'] / $data['count'];
                if ($average < 70) {
                    $weakAreas[] = $module;
                }
            }
        }

        // If no specific weak areas found, add a general area
        if (empty($weakAreas)) {
            // Get overall course grade
            $grade = grade_get_course_grade($userid, $this->courseid);
            if ($grade && isset($grade->grade) && $grade->grade !== null && isset($grade->grade_item->grademax) && $grade->grade_item->grademax > 0) {
                $percentage = ($grade->grade / $grade->grade_item->grademax) * 100;

                if ($percentage < 70) {
                    $weakAreas[] = 'Course Content';
                } else {
                    // Even if overall grade is good, suggest general improvement
                    $weakAreas[] = 'Study Skills';
                }
            } else {
                // If no grades available
                $weakAreas[] = 'Course Engagement';
            }
        }

        return $weakAreas;
    }
}