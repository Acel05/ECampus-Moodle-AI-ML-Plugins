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
 * Renderer for Student Performance Predictor.
 *
 * @package    block_studentperformancepredictor
 * @copyright  2023 Your Name <[Email]>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_studentperformancepredictor\output;

defined('MOODLE_INTERNAL') || die();

use plugin_renderer_base;

/**
 * Renderer for Student Performance Predictor block.
 */
class renderer extends plugin_renderer_base {
    /**
     * Renders student_view.
     *
     * @param student_view $studentview The student view object
     * @return string HTML
     */
    public function render_student_view(student_view $studentview) {
        $data = $studentview->export_for_template($this);
        return $this->render_from_template('block_studentperformancepredictor/student_dashboard', $data);
    }

    /**
     * Renders teacher_view.
     *
     * @param teacher_view $teacherview The teacher view object
     * @return string HTML
     */
    public function render_teacher_view(teacher_view $teacherview) {
        $data = $teacherview->export_for_template($this);
        return $this->render_from_template('block_studentperformancepredictor/teacher_dashboard', $data);
    }

    /**
     * Renders admin_view.
     *
     * @param admin_view $adminview The admin view object
     * @return string HTML
     */
    public function render_admin_view(admin_view $adminview) {
        $data = $adminview->export_for_template($this);
        return $this->render_from_template('block_studentperformancepredictor/admin_settings', $data);
    }
}