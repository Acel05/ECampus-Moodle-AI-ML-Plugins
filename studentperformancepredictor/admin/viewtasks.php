<?php
// This file is part of Moodle - http://moodle.org/
/**
 * View model training tasks for Student Performance Predictor.
 *
 * @package    block_studentperformancepredictor
 * @copyright  2023 Your Name <[Email]>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/tablelib.php'); // For html_table
require_once($CFG->dirroot . '/lib/accesslib.php'); // For require_login, require_capability
require_once($CFG->dirroot . '/lib/moodlelib.php'); // For userdate, s
require_once($CFG->libdir . '/weblib.php'); // For html_writer, s, get_string

require_login();
$courseid = optional_param('courseid', 0, PARAM_INT);

// Default to system context if no course specified
if ($courseid) {
    $context = context_course::instance($courseid);
    $course = get_course($courseid);
    $PAGE->set_course($course);
    $pageheading = format_string($course->fullname);
} else {
    $context = context_system::instance();
    $pageheading = get_string('viewtasks', 'block_studentperformancepredictor');
}

require_capability('block/studentperformancepredictor:managemodels', $context);

// Set up page
$PAGE->set_url(new moodle_url('/blocks/studentperformancepredictor/admin/viewtasks.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('viewtasks', 'block_studentperformancepredictor'));
$PAGE->set_heading($pageheading);
$PAGE->set_pagelayout('admin');

// Get all train_model tasks (adhoc and scheduled)
global $DB;
$table = new html_table();
$table->head = [
    get_string('id', 'moodle'),
    get_string('taskname', 'block_studentperformancepredictor'),
    get_string('status', 'block_studentperformancepredictor'),
    get_string('courseid', 'moodle'),
    get_string('algorithm', 'block_studentperformancepredictor'),
    get_string('timecreated', 'moodle'),
    get_string('nextruntime', 'block_studentperformancepredictor'),
    get_string('lastruntime', 'moodle'),
    get_string('output', 'tool_task'),
];
$table->data = [];

// Filter by course if specified
$tasksql = "component = :component";
$taskparams = ['component' => 'block_studentperformancepredictor'];

if ($courseid) {
    $tasksql .= " AND " . $DB->sql_like('customdata', ':courseid');
    $taskparams['courseid'] = '%"courseid":' . $courseid . '%';
}

// Get all adhoc tasks for this plugin/course
$tasks = $DB->get_records_select('task_adhoc', $tasksql, $taskparams, 'id DESC');

foreach ($tasks as $task) {
    $customdata = json_decode($task->customdata);

    // Task information
    $taskname = explode('\\', $task->classname);
    $taskname = end($taskname);

    // Get model information if available
    $model = null;
    if (isset($customdata->modelid)) {
        $model = $DB->get_record('block_spp_models', ['id' => $customdata->modelid]);
    }

    // Task status
    if ($task->nextruntime && $task->nextruntime > time()) {
        $status = get_string('taskqueued', 'block_studentperformancepredictor');
        $statusclass = 'badge badge-info';
    } else if ($task->timestarted && !$task->timecompleted) {
        $status = get_string('taskrunning', 'block_studentperformancepredictor');
        $statusclass = 'badge badge-warning';
    } else {
        $status = get_string('complete', 'completion');
        $statusclass = 'badge badge-success';
    }

    // Course info
    $thiscourse = isset($customdata->courseid) ? $customdata->courseid : '-';

    // Algorithm info
    $algorithm = isset($customdata->algorithm) ? $customdata->algorithm : 
                 (isset($model->algorithmtype) ? $model->algorithmtype : '-');

    // Time values
    $timecreated = isset($task->timestart) && $task->timestart ? userdate($task->timestart) : 
                  (isset($task->timecreated) ? userdate($task->timecreated) : '-');

    $nextrun = $task->nextruntime ? userdate($task->nextruntime) : '-';
    $lastrun = $task->lastruntime ? userdate($task->lastruntime) : '-';

    // Error message handling
    $errormessage = '-';
    if (isset($model) && $model && !empty($model->errormessage)) {
        $icon = html_writer::span('&#9888;', 'mr-1 text-warning', ['title' => 'Error']); // ⚠️
        $errormessage = html_writer::div(
            $icon . html_writer::tag('strong', 'Error: ') . s($model->errormessage), 
            'text-danger bg-warning-light p-2 rounded small', 
            ['style' => 'border:1px solid #f5c6cb; background-color:#fff3cd;']
        );
    }

    $output = $task->faildelay ? get_string('failed', 'core') : ($task->output ?? '-');

    $table->data[] = [
        $task->id,
        $taskname,
        html_writer::span($status, $statusclass),
        $thiscourse,
        $algorithm,
        $timecreated,
        $nextrun,
        $lastrun,
        $output . ($errormessage !== '-' ? '<br>' . $errormessage : ''),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('viewtasks', 'block_studentperformancepredictor'));

if (empty($table->data)) {
    echo $OUTPUT->notification(get_string('notasks', 'block_studentperformancepredictor'), 'info');
} else {
    echo html_writer::table($table);
}

// Back to models button
if ($courseid) {
    echo html_writer::div(
        html_writer::link(
            new moodle_url('/blocks/studentperformancepredictor/admin/managemodels.php', ['courseid' => $courseid]),
            get_string('backtomodels', 'block_studentperformancepredictor'),
            ['class' => 'btn btn-secondary']
        ),
        'mt-3'
    );
}

echo $OUTPUT->footer();