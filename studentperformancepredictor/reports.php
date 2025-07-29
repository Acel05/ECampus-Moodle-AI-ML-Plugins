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
 * Detailed prediction reports for Student Performance Predictor.
 *
 * @package    block_studentperformancepredictor
 * @copyright  2023 Your Name <[Email]>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

// Get parameters
$id = required_param('id', PARAM_INT);  // Course ID
$page = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', 20, PARAM_INT);

// Set up page
$course = get_course($id);
$context = context_course::instance($id);

// Check permissions
require_login($course);
require_capability('block/studentperformancepredictor:viewallpredictions', $context);

// Set up page layout
$PAGE->set_url(new moodle_url('/blocks/studentperformancepredictor/reports.php', array('id' => $id)));
$PAGE->set_context($context);
$PAGE->set_title(get_string('detailedreport', 'block_studentperformancepredictor'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('report');

// Add necessary JavaScript
$PAGE->requires->js_call_amd('block_studentperformancepredictor/chart_renderer', 'initTeacherChart');
$PAGE->requires->js_call_amd('block_studentperformancepredictor/prediction_viewer', 'init');

// Output starts here
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('detailedreport', 'block_studentperformancepredictor'));

// Check if there's an active model
if (!block_studentperformancepredictor_has_active_model($id)) {
    echo $OUTPUT->notification(get_string('noactivemodel', 'block_studentperformancepredictor'), 'warning');
    echo html_writer::link(
        new moodle_url('/course/view.php', array('id' => $id)),
        get_string('backtocourse', 'block_studentperformancepredictor'),
        array('class' => 'btn btn-secondary')
    );
    echo $OUTPUT->footer();
    exit;
}

// Get risk statistics
$riskStats = block_studentperformancepredictor_get_course_risk_stats($id);

// Display summary
// All stats below are based on backend-driven predictions (see lib.php orchestration)
echo $OUTPUT->box_start('generalbox spp-summary-box');
echo html_writer::tag('h4', get_string('currentpredictionstats', 'block_studentperformancepredictor'));

echo html_writer::start_div('row');
echo html_writer::start_div('col-md-6');

echo html_writer::start_div('spp-stats');
echo html_writer::div(
    html_writer::span(get_string('totalstudents', 'block_studentperformancepredictor'), 'spp-label') . 
    html_writer::span($riskStats->total, 'spp-value'),
    'spp-stat-total'
);

echo html_writer::start_div('spp-risk-distribution');
echo html_writer::div(
    html_writer::span(get_string('highrisk_label', 'block_studentperformancepredictor'), 'spp-label spp-risk-high') . 
    html_writer::span($riskStats->highrisk . ' (' . round(($riskStats->highrisk / max(1, $riskStats->total)) * 100) . '%)', 'spp-value'),
    'spp-risk-high'
);
echo html_writer::div(
    html_writer::span(get_string('mediumrisk_label', 'block_studentperformancepredictor'), 'spp-label spp-risk-medium') . 
    html_writer::span($riskStats->mediumrisk . ' (' . round(($riskStats->mediumrisk / max(1, $riskStats->total)) * 100) . '%)', 'spp-value'),
    'spp-risk-medium'
);
echo html_writer::div(
    html_writer::span(get_string('lowrisk_label', 'block_studentperformancepredictor'), 'spp-label spp-risk-low') . 
    html_writer::span($riskStats->lowrisk . ' (' . round(($riskStats->lowrisk / max(1, $riskStats->total)) * 100) . '%)', 'spp-value'),
    'spp-risk-low'
);
echo html_writer::end_div(); // End risk distribution
echo html_writer::end_div(); // End stats

echo html_writer::end_div(); // End col-md-6

// Chart
// The chart data is based on backend-calculated risk stats
echo html_writer::start_div('col-md-6');
$chartdata = json_encode([
    'labels' => [
        get_string('highrisk_label', 'block_studentperformancepredictor'),
        get_string('mediumrisk_label', 'block_studentperformancepredictor'),
        get_string('lowrisk_label', 'block_studentperformancepredictor')
    ],
    'data' => [$riskStats->highrisk, $riskStats->mediumrisk, $riskStats->lowrisk]
]);
echo html_writer::div(
    '<canvas id="spp-teacher-chart" data-chartdata="' . $chartdata . '" aria-label="' . get_string('riskdistributionchart', 'block_studentperformancepredictor') . '"></canvas>' .
    '<noscript><div class="alert alert-info mt-2">' . get_string('jsrequired', 'block_studentperformancepredictor') . '</div></noscript>',
    'spp-chart-container'
);
echo html_writer::end_div(); // End col-md-6

echo html_writer::end_div(); // End row

// Refresh button (triggers backend prediction refresh for all students)
echo html_writer::start_div('mt-3');
echo html_writer::tag('button', get_string('refreshpredictions', 'block_studentperformancepredictor'), 
                      array('class' => 'btn btn-secondary spp-refresh-predictions', 
                           'data-course-id' => $id,
                           'type' => 'button',
                           'title' => get_string('refreshpredictionsdesc', 'block_studentperformancepredictor')));
echo html_writer::end_div();

echo $OUTPUT->box_end();

// Get all students with predictions
$students = get_enrolled_users($context, 'moodle/course:isincompletionreports', 0, 'u.*', null, $page*$perpage, $perpage);
$studentscount = count_enrolled_users($context, 'moodle/course:isincompletionreports');

// Start student table
$table = new html_table();
$table->head = array(
    get_string('fullname'),
    get_string('passingchance', 'block_studentperformancepredictor'),
    get_string('risk', 'block_studentperformancepredictor'),
    get_string('lastupdate', 'block_studentperformancepredictor'),
    get_string('actions')
);
$table->attributes['class'] = 'generaltable table-striped spp-student-table';
$table->attributes['aria-label'] = get_string('studentpredictionstable', 'block_studentperformancepredictor');
$table->data = array();

// Populate the table with student data
$missingpredictions = 0;
foreach ($students as $student) {
    $prediction = block_studentperformancepredictor_get_student_prediction($id, $student->id);
    $row = array();
    $row[] = fullname($student);
    if ($prediction) {
        $row[] = isset($prediction->passprob) ? round($prediction->passprob * 100) . '%' : '-';
        $riskclass = block_studentperformancepredictor_get_risk_class($prediction->riskvalue);
        $risktext = block_studentperformancepredictor_get_risk_text($prediction->riskvalue);
        $row[] = html_writer::tag('span', $risktext, array('class' => $riskclass));
        $row[] = isset($prediction->timemodified) ? userdate($prediction->timemodified) : '-';
    } else {
        $row[] = get_string('noprediction', 'block_studentperformancepredictor');
        $row[] = '-';
        $row[] = '-';
        $missingpredictions++;
    }
    // Actions
    $actions = html_writer::link(
        new moodle_url('/blocks/studentperformancepredictor/generate_prediction.php', 
            array('courseid' => $id, 'userid' => $student->id, 'sesskey' => sesskey())),
        get_string('updateprediction', 'block_studentperformancepredictor'),
        array('class' => 'btn btn-sm btn-info')
    );
    $row[] = $actions;
    $table->data[] = $row;
}

// If any students are missing predictions, show a warning and suggest refresh
if ($missingpredictions > 0) {
    echo $OUTPUT->notification(get_string('somepredictionsmissing', 'block_studentperformancepredictor', $missingpredictions), 'warning');
}

echo html_writer::table($table);

// Pagination
echo $OUTPUT->paging_bar($studentscount, $page, $perpage, $PAGE->url);

// Add a "Back to course" button
echo html_writer::div(
    html_writer::link(
        new moodle_url('/course/view.php', array('id' => $id)),
        get_string('backtocourse', 'block_studentperformancepredictor'),
        array('class' => 'btn btn-secondary')
    ),
    'mt-3'
);

// Output footer
echo $OUTPUT->footer();
