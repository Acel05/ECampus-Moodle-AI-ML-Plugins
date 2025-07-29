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
 * Student view renderer for Student Performance Predictor.
 *
 * @package    block_studentperformancepredictor
 * @copyright  2023 Your Name <[Email]>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_studentperformancepredictor\output;

defined('MOODLE_INTERNAL') || die();

// Add this line to include lib.php which contains the function definitions
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

/**
 * Student view class for the student dashboard.
 *
 * This class prepares data for the student dashboard template.
 */
class student_view implements \renderable, \templatable {
    /** @var int Course ID */
    protected $courseid;

    /** @var int User ID */
    protected $userid;

    /** @var bool Whether to show course selector */
    protected $showcourseselector;

    /** @var string Course selector HTML */
    protected $courseselector;

    /**
     * Constructor.
     *
     * @param int $courseid Course ID
     * @param int $userid User ID
     * @param bool $showcourseselector Whether to show course selector
     * @param string $courseselector Course selector HTML
     */
    public function __construct($courseid, $userid, $showcourseselector = false, $courseselector = '') {
        $this->courseid = $courseid;
        $this->userid = $userid;
        $this->showcourseselector = $showcourseselector;
        $this->courseselector = $courseselector;
    }

    /**
     * Export data for template.
     *
     * @param \renderer_base $output The renderer
     * @return \stdClass Data for template
     */
    public function export_for_template(\renderer_base $output) {
        global $DB, $CFG, $PAGE;

        $data = new \stdClass();
        $data->heading = get_string('studentperformance', 'block_studentperformancepredictor');
        $data->courseid = $this->courseid;
        $data->userid = $this->userid;
        $data->showcourseselector = $this->showcourseselector;
        $data->courseselector = $this->courseselector;

        // Check if there's an active model - use global namespace function
        $data->hasmodel = \block_studentperformancepredictor_has_active_model($this->courseid);

        if (!$data->hasmodel) {
            $data->nomodeltext = get_string('noactivemodel', 'block_studentperformancepredictor');
            return $data;
        }

        // Get student prediction - use global namespace function
        $prediction = \block_studentperformancepredictor_get_student_prediction($this->courseid, $this->userid);

        // Add ability to generate new prediction
        $data->can_generate_prediction = true;
        $data->generate_prediction_url = new \moodle_url('/blocks/studentperformancepredictor/generate_prediction.php', 
            ['courseid' => $this->courseid, 'userid' => $this->userid, 'sesskey' => sesskey()]);

        if (!$prediction) {
            $data->hasprediction = false;
            $data->nopredictiontext = get_string('noprediction', 'block_studentperformancepredictor');
            return $data;
        }

        $data->hasprediction = true;

        // Prediction information
        $data->passprob = round($prediction->passprob * 100);
        $data->riskvalue = $prediction->riskvalue;
        $data->risktext = \block_studentperformancepredictor_get_risk_text($prediction->riskvalue);
        $data->riskclass = \block_studentperformancepredictor_get_risk_class($prediction->riskvalue);
        $data->lastupdate = userdate($prediction->timemodified);
        $data->predictionid = $prediction->id;

        // Check if this is from a global model
        $model = $DB->get_record('block_spp_models', ['id' => $prediction->modelid]);
        $data->isglobalmodel = ($model && $model->courseid == 0);

        // Get suggestions - use global namespace function
        $suggestions = \block_studentperformancepredictor_get_suggestions($prediction->id);

        $data->hassuggestions = !empty($suggestions);
        $data->suggestions = [];

        foreach ($suggestions as $suggestion) {
            $suggestionData = new \stdClass();
            $suggestionData->id = $suggestion->id;
            $suggestionData->reason = $suggestion->reason;

            // Create URL to the activity
            if (!empty($suggestion->cmid)) {
                $suggestionData->hasurl = true;
                $modulename = !empty($suggestion->modulename) ? $suggestion->modulename : '';
                $cmname = !empty($suggestion->cmname) ? $suggestion->cmname : '';
                $suggestionData->url = new \moodle_url('/mod/' . $modulename . '/view.php', 
                                                    ['id' => $suggestion->cmid]);
                $suggestionData->name = $cmname;
            } else {
                $suggestionData->hasurl = false;
                $suggestionData->name = get_string('generalstudy', 'block_studentperformancepredictor');
            }

            $suggestionData->viewed = $suggestion->viewed;
            $suggestionData->completed = $suggestion->completed;

            $data->suggestions[] = $suggestionData;
        }

        // Create chart data
        $data->haschart = true;
        $chartData = [
            'passprob' => $data->passprob,
            'failprob' => 100 - $data->passprob
        ];
        $data->chartdata = json_encode($chartData);

        // Add performance improvement tracking
        $data->showimprovements = true;

        // Get historical predictions for this student in this course
        $sql = "SELECT p.*, m.algorithmtype 
                FROM {block_spp_predictions} p
                JOIN {block_spp_models} m ON p.modelid = m.id
                WHERE p.courseid = :courseid 
                AND p.userid = :userid
                ORDER BY p.timemodified DESC
                LIMIT 5";

        $historical = $DB->get_records_sql($sql, [
            'courseid' => $this->courseid,
            'userid' => $this->userid
        ]);

        if (count($historical) > 1) {
            $data->has_historical = true;
            $data->historical = [];

            foreach ($historical as $pred) {
                $item = new \stdClass();
                $item->date = userdate($pred->timemodified, get_string('strftimedateshort', 'langconfig'));
                $item->passprob = round($pred->passprob * 100);
                $item->risktext = \block_studentperformancepredictor_get_risk_text($pred->riskvalue);
                $item->riskclass = \block_studentperformancepredictor_get_risk_class($pred->riskvalue);
                $data->historical[] = $item;
            }
        } else {
            $data->has_historical = false;
        }

        return $data;
    }
}
