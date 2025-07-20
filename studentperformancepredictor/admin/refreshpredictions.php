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
* Refresh predictions page for Student Performance Predictor.
*
* @package    block_studentperformancepredictor
* @copyright  2023 Your Name <your.email@example.com>
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/

require_once('../../../config.php');
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

// Get parameters.
$courseid = required_param('courseid', PARAM_INT);

// Set up page.
$course = get_course($courseid);
$context = context_course::instance($courseid);

// Check permissions.
require_login($course);
require_capability('block/studentperformancepredictor:viewallpredictions', $context);

// Set up page layout.
$PAGE->set_url(new moodle_url('/blocks/studentperformancepredictor/admin/refreshpredictions.php', array('courseid' => $courseid)));
$PAGE->set_context($context);
$PAGE->set_title(get_string('refreshpredictions', 'block_studentperformancepredictor'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('standard');

// Add required JavaScript
$PAGE->requires->js_call_amd('block_studentperformancepredictor/prediction_viewer', 'init');

// Check if there's an active model.
$hasactivemodel = block_studentperformancepredictor_has_active_model($courseid);

// Output starts here.
echo $OUTPUT->header();

// Print heading.
echo $OUTPUT->heading(get_string('refreshpredictions', 'block_studentperformancepredictor'));

if (!$hasactivemodel) {
    echo $OUTPUT->notification(get_string('noactivemodel', 'block_studentperformancepredictor'), 'warning');
    echo html_writer::link(
        new moodle_url('/blocks/studentperformancepredictor/admin/managemodels.php', array('courseid' => $courseid)), 
        get_string('managemodels', 'block_studentperformancepredictor'),
        array('class' => 'btn btn-primary')
    );
} else {
    // Get statistics on current predictions.
    $stats = block_studentperformancepredictor_get_course_risk_stats($courseid);

    echo html_writer::start_div('spp-prediction-stats mb-4');

    echo html_writer::start_div('card');
    echo html_writer::start_div('card-body');

    echo html_writer::tag('h5', get_string('currentpredictionstats', 'block_studentperformancepredictor'), array('class' => 'card-title'));

    echo html_writer::start_tag('ul', array('class' => 'list-group list-group-flush'));
    echo html_writer::tag('li', get_string('totalstudents', 'block_studentperformancepredictor') . ': ' . $stats->total, array('class' => 'list-group-item'));
    echo html_writer::tag('li', get_string('highrisk_label', 'block_studentperformancepredictor') . ': ' . $stats->highrisk . 
        ' (' . round(($stats->highrisk / max(1, $stats->total)) * 100) . '%)', array('class' => 'list-group-item spp-risk-high'));
    echo html_writer::tag('li', get_string('mediumrisk_label', 'block_studentperformancepredictor') . ': ' . $stats->mediumrisk . 
        ' (' . round(($stats->mediumrisk / max(1, $stats->total)) * 100) . '%)', array('class' => 'list-group-item spp-risk-medium'));
    echo html_writer::tag('li', get_string('lowrisk_label', 'block_studentperformancepredictor') . ': ' . $stats->lowrisk . 
        ' (' . round(($stats->lowrisk / max(1, $stats->total)) * 100) . '%)', array('class' => 'list-group-item spp-risk-low'));
    echo html_writer::end_tag('ul');

    echo html_writer::end_div(); // card-body
    echo html_writer::end_div(); // card

    echo html_writer::end_div(); // spp-prediction-stats

    // Refresh button and explanation.
    echo html_writer::start_div('spp-refresh-container mb-4');

    echo html_writer::tag('p', get_string('refreshexplanation', 'block_studentperformancepredictor'));

    echo html_writer::start_div('spp-refresh-action');
    echo html_writer::tag('button', get_string('refreshallpredictions', 'block_studentperformancepredictor'), 
                        array('class' => 'btn btn-primary spp-refresh-predictions', 
                             'data-course-id' => $courseid));
    echo html_writer::end_div();

    echo html_writer::end_div();

    // Show the last refresh time if available
    $lastrefreshtime = get_config('block_studentperformancepredictor', 'lastrefresh_' . $courseid);
    if (!empty($lastrefreshtime)) {
        echo html_writer::start_div('spp-last-refresh mb-4');
        echo html_writer::tag('p', get_string('lastrefreshtime', 'block_studentperformancepredictor', userdate($lastrefreshtime)), 
            array('class' => 'text-muted'));
        echo html_writer::end_div();
    }

    // Get information about students with no predictions (backend-driven)
    // This count reflects the state after the last backend prediction refresh.
    $sql = "SELECT COUNT(DISTINCT u.id) 
            FROM {user} u
            JOIN {user_enrolments} ue ON ue.userid = u.id
            JOIN {enrol} e ON e.id = ue.enrolid
            LEFT JOIN {block_spp_predictions} p ON p.userid = u.id AND p.courseid = :courseid
            WHERE e.courseid = :courseid2
            AND p.id IS NULL";

    $params = [
        'courseid' => $courseid,
        'courseid2' => $courseid
    ];

    $studentswithoutpredictions = $DB->count_records_sql($sql, $params);

    if ($studentswithoutpredictions > 0) {
        echo html_writer::start_div('spp-missing-predictions');
        echo $OUTPUT->notification(
            get_string('studentswithoutpredictions_backend', 'block_studentperformancepredictor', $studentswithoutpredictions), 
            'info'
        );
        echo html_writer::end_div();
    }

    // Add a link back to the admin menu.
    echo html_writer::div(
        html_writer::link(
            new moodle_url('/blocks/studentperformancepredictor/admin/managemodels.php', array('courseid' => $courseid)), 
            get_string('backtomodels', 'block_studentperformancepredictor'),
            array('class' => 'btn btn-secondary')
        ),
        'mt-3'
    );
}

// Output footer.
echo $OUTPUT->footer();