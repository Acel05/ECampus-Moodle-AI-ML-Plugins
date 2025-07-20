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
 * External services definition for Student Performance Predictor.
 *
 * @package    block_studentperformancepredictor
 * @copyright  2023 Your Name <[Email]>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    // Mark a suggestion as viewed by the student.
    'block_studentperformancepredictor_mark_suggestion_viewed' => [
        'classname' => 'block_studentperformancepredictor\external\api',
        'methodname' => 'mark_suggestion_viewed',
        'description' => 'Mark a suggestion as viewed by the student.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'block/studentperformancepredictor:view',
    ],
    // Mark a suggestion as completed by the student.
    'block_studentperformancepredictor_mark_suggestion_completed' => [
        'classname' => 'block_studentperformancepredictor\external\api',
        'methodname' => 'mark_suggestion_completed',
        'description' => 'Mark a suggestion as completed by the student.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'block/studentperformancepredictor:view',
    ],
    // Get predictions for a student in a course.
    'block_studentperformancepredictor_get_student_predictions' => [
        'classname' => 'block_studentperformancepredictor\external\api',
        'methodname' => 'get_student_predictions',
        'description' => 'Get predictions for a student in a course.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'block/studentperformancepredictor:view',
    ],
    // Trigger model training for a course.
    'block_studentperformancepredictor_trigger_model_training' => [
        'classname' => 'block_studentperformancepredictor\external\api',
        'methodname' => 'trigger_model_training',
        'description' => 'Trigger model training for a course.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'block/studentperformancepredictor:managemodels',
    ],
    // Refresh predictions for a course.
    'block_studentperformancepredictor_refresh_predictions' => [
        'classname' => 'block_studentperformancepredictor\external\api',
        'methodname' => 'refresh_predictions',
        'description' => 'Refresh predictions for a course.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'block/studentperformancepredictor:viewallpredictions',
    ],
];

$services = [
    'Student Performance Predictor' => [
        'functions' => [
            'block_studentperformancepredictor_mark_suggestion_viewed',
            'block_studentperformancepredictor_mark_suggestion_completed',
            'block_studentperformancepredictor_get_student_predictions',
            'block_studentperformancepredictor_trigger_model_training',
            'block_studentperformancepredictor_refresh_predictions',
        ],
        'restrictedusers' => 0,
        'enabled' => 1,
        'shortname' => 'studentperformancepredictor',
        'downloadfiles' => 0,
    ],
];