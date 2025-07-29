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
 * Teacher view renderer for Student Performance Predictor.
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
 * Teacher view class for the teacher dashboard.
 *
 * This class prepares data for the teacher dashboard template.
 */
class teacher_view implements \renderable, \templatable {
    /** @var int Course ID */
    protected $courseid;

    /**
     * Constructor.
     *
     * @param int $courseid Course ID
     */
    public function __construct($courseid) {
        $this->courseid = $courseid;
    }

    /**
     * Export data for template.
     *
     * @param \renderer_base $output The renderer
     * @return \stdClass Data for template
     */
    public function export_for_template(\renderer_base $output) {
        global $CFG;

        $data = new \stdClass();
        $data->heading = get_string('courseperformance', 'block_studentperformancepredictor');
        $data->courseid = $this->courseid;

        // Check if there's an active model - use global namespace function
        $data->hasmodel = \block_studentperformancepredictor_has_active_model($this->courseid);

        if (!$data->hasmodel) {
            $data->nomodeltext = get_string('noactivemodel', 'block_studentperformancepredictor');
            return $data;
        }

        // Get risk statistics - use global namespace function
        $riskStats = \block_studentperformancepredictor_get_course_risk_stats($this->courseid);

        $data->totalstudents = $riskStats->total;
        $data->highrisk = $riskStats->highrisk;
        $data->mediumrisk = $riskStats->mediumrisk;
        $data->lowrisk = $riskStats->lowrisk;

        // Calculate percentages
        if ($data->totalstudents > 0) {
            $data->highriskpercent = round(($data->highrisk / $data->totalstudents) * 100);
            $data->mediumriskpercent = round(($data->mediumrisk / $data->totalstudents) * 100);
            $data->lowriskpercent = round(($data->lowrisk / $data->totalstudents) * 100);
        } else {
            $data->highriskpercent = 0;
            $data->mediumriskpercent = 0;
            $data->lowriskpercent = 0;
        }

        // Create URL to detailed report
        $data->detailreporturl = new \moodle_url('/blocks/studentperformancepredictor/reports.php', 
                                              ['id' => $this->courseid]);

        // Create chart data
        $data->haschart = true;
        $chartData = [
            'labels' => [
                get_string('highrisk_label', 'block_studentperformancepredictor'),
                get_string('mediumrisk_label', 'block_studentperformancepredictor'),
                get_string('lowrisk_label', 'block_studentperformancepredictor')
            ],
            'data' => [$data->highrisk, $data->mediumrisk, $data->lowrisk]
        ];
        $data->chartdata = json_encode($chartData);

        return $data;
    }
}