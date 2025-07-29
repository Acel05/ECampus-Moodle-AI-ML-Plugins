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
 * View dataset details for Student Performance Predictor.
 *
 * @package    block_studentperformancepredictor
 * @copyright  2023 Your Name <[Email]>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

// Get parameters
$id = required_param('id', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);

// Set up page
$course = get_course($courseid);
$context = context_course::instance($courseid);

// Check permissions
require_login($course);
require_capability('block/studentperformancepredictor:managemodels', $context);

// Get dataset
$dataset = $DB->get_record('block_spp_datasets', array('id' => $id, 'courseid' => $courseid), '*', MUST_EXIST);

// Set up page layout
$PAGE->set_url(new moodle_url('/blocks/studentperformancepredictor/admin/viewdataset.php', array('id' => $id, 'courseid' => $courseid)));
$PAGE->set_context($context);
$PAGE->set_title(get_string('datasetname', 'block_studentperformancepredictor') . ': ' . $dataset->name);
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('standard');

// Output starts here
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('datasetname', 'block_studentperformancepredictor') . ': ' . format_string($dataset->name));

// Display dataset details
$table = new html_table();
$table->attributes['class'] = 'generaltable';
$table->head = array(get_string('property', 'moodle'), get_string('value', 'moodle'));
$table->data = array();

$table->data[] = array(get_string('datasetname', 'block_studentperformancepredictor'), format_string($dataset->name));
$table->data[] = array(get_string('datasetdescription', 'block_studentperformancepredictor'), $dataset->description ? format_text($dataset->description) : '');
$table->data[] = array(get_string('datasetformat', 'block_studentperformancepredictor'), $dataset->fileformat);
$table->data[] = array(get_string('uploaded', 'block_studentperformancepredictor'), userdate($dataset->timecreated));

// Display columns
$columns = json_decode($dataset->columns);
if ($columns && is_array($columns) && count($columns) > 0) {
    $columnlist = html_writer::start_tag('ul');
    foreach ($columns as $column) {
        $columnlist .= html_writer::tag('li', s($column));
    }
    $columnlist .= html_writer::end_tag('ul');
    $table->data[] = array(get_string('columns', 'block_studentperformancepredictor'), $columnlist);
}

echo html_writer::table($table);

// Add a "Train model" button (backend-driven orchestration)
echo html_writer::start_div('mt-4');
echo html_writer::div(
    get_string('trainmodel_backenddesc', 'block_studentperformancepredictor'),
    'mb-2 text-muted'
);
echo html_writer::link(
    new moodle_url('/blocks/studentperformancepredictor/admin/train_model.php', 
        array('courseid' => $courseid, 'datasetid' => $dataset->id)),
    get_string('trainmodel', 'block_studentperformancepredictor'),
    array('class' => 'btn btn-primary')
);

// Add a "Back" button
echo ' ';
echo html_writer::link(
    new moodle_url('/blocks/studentperformancepredictor/admin/managedatasets.php', 
        array('courseid' => $courseid)),
    get_string('back', 'core'),
    array('class' => 'btn btn-secondary')
);
echo html_writer::end_div();

// Output footer
echo $OUTPUT->footer();