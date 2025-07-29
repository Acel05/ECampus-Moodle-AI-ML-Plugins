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
 * Generate a new prediction for a student.
 *
 * @package    block_studentperformancepredictor
 * @copyright  2023 Your Name <[Email]>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/blocks/studentperformancepredictor/lib.php');

// Get parameters
$courseid = required_param('courseid', PARAM_INT);
$userid = required_param('userid', PARAM_INT);
$redirect = optional_param('redirect', 1, PARAM_INT); // Whether to redirect back (default: yes)

// Set up page
$course = get_course($courseid);
$context = context_course::instance($courseid);

// Security check
require_login($course);
require_sesskey();

// If user is viewing their own prediction
if ($USER->id == $userid) {
    require_capability('block/studentperformancepredictor:view', $context);
} else {
    // If teacher is viewing a student's prediction
    require_capability('block/studentperformancepredictor:viewallpredictions', $context);
}

// Generate a new prediction
try {
    $prediction = block_studentperformancepredictor_generate_new_prediction($courseid, $userid);

    if ($prediction) {
        if ($redirect) {
            \core\notification::success(get_string('predictiongenerated', 'block_studentperformancepredictor'));

            // Redirect back to course page or my dashboard
            if ($userid == $USER->id) {
                // For students viewing their own predictions
                if ($courseid == SITEID) {
                    redirect(new moodle_url('/my/'));
                } else {
                    redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
                }
            } else {
                // For teachers viewing student predictions
                redirect(new moodle_url('/blocks/studentperformancepredictor/reports.php', ['id' => $courseid]));
            }
        } else {
            // API-like response for AJAX calls
            $response = [
                'success' => true,
                'predictionid' => $prediction->id,
                'passprob' => round($prediction->passprob * 100),
                'riskvalue' => $prediction->riskvalue,
                'risktext' => block_studentperformancepredictor_get_risk_text($prediction->riskvalue),
                'timemodified' => userdate($prediction->timemodified)
            ];

            header('Content-Type: application/json');
            echo json_encode($response);
            die;
        }
    } else {
        throw new moodle_exception('predictionerror', 'block_studentperformancepredictor');
    }
} catch (Exception $e) {
    if ($redirect) {
        \core\notification::error($e->getMessage());

        // Redirect back to course page or my dashboard
        if ($userid == $USER->id) {
            if ($courseid == SITEID) {
                redirect(new moodle_url('/my/'));
            } else {
                redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
            }
        } else {
            redirect(new moodle_url('/blocks/studentperformancepredictor/reports.php', ['id' => $courseid]));
        }
    } else {
        // API-like response for AJAX calls
        $response = [
            'success' => false,
            'error' => $e->getMessage()
        ];

        header('Content-Type: application/json');
        echo json_encode($response);
        die;
    }
}
