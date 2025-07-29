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
* Analytics for EIRA AI Academic Assistant
*
* @package    block_chatbot
* @copyright  2023 Your Name
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/
 
namespace block_chatbot;
 
defined('MOODLE_INTERNAL') || die();
 
/**
* Class to handle analytics for the EIRA AI chatbot
*/
class analytics {
 
    /**
     * Log a user interaction with the chatbot
     *
     * @param int $userid User ID
     * @param string $query The user's query
     * @param string $category The category of query
     * @param bool $success Whether the response was successful
     * @return bool Success of logging
     */
    public static function log_interaction($userid, $query, $category, $success = true) {
        global $DB;
 
        $record = new \stdClass();
        $record->userid = $userid;
        $record->query = $query;
        $record->category = $category;
        $record->success = $success ? 1 : 0;
        $record->timecreated = time();
 
        try {
            $DB->insert_record('block_chatbot_interactions', $record);
            return true;
        } catch (\Exception $e) {
            // If the table doesn't exist yet, just ignore the error
            // This will be fixed during the next upgrade
            return false;
        }
    }
 
    /**
     * Analyze common queries to improve future responses
     *
     * @param int $limit Number of queries to analyze
     * @return array Analytics data
     */
    public static function analyze_common_queries($limit = 100) {
        global $DB;
 
        // Check if the table exists
        if (!$DB->get_manager()->table_exists('block_chatbot_interactions')) {
            return [];
        }
 
        // Get the most common queries
        $sql = "SELECT query, category, COUNT(*) as count, 
                SUM(success) as success_count,
                (SUM(success) / COUNT(*)) * 100 as success_rate
                FROM {block_chatbot_interactions}
                GROUP BY query, category
                ORDER BY count DESC
                LIMIT ?";
 
        $records = $DB->get_records_sql($sql, [$limit]);
 
        // Format the results
        $results = [];
        foreach ($records as $record) {
            $results[] = [
                'query' => $record->query,
                'category' => $record->category,
                'count' => $record->count,
                'success_rate' => round($record->success_rate, 2)
            ];
        }
 
        return $results;
    }
 
    /**
     * Get categories that users ask about most frequently
     *
     * @return array Category data
     */
    public static function get_top_categories() {
        global $DB;
 
        // Check if the table exists
        if (!$DB->get_manager()->table_exists('block_chatbot_interactions')) {
            return [];
        }
 
        // Get category statistics
        $sql = "SELECT category, COUNT(*) as count
                FROM {block_chatbot_interactions}
                GROUP BY category
                ORDER BY count DESC";
 
        $records = $DB->get_records_sql($sql);
 
        // Format the results
        $results = [];
        foreach ($records as $record) {
            $results[$record->category] = $record->count;
        }
 
        return $results;
    }
 
    /**
     * Categorize a query based on its content
     *
     * @param string $query The user's query
     * @return string Category
     */
    public static function categorize_query($query) {
        $query_lower = strtolower($query);
 
        $categories = [
            'courses' => ['course', 'class', 'subject', 'enrolled'],
            'assignments' => ['assignment', 'homework', 'deadline', 'due', 'project'],
            'study_tips' => ['study', 'learn', 'remember', 'focus', 'memorize', 'notes'],
            'exams' => ['exam', 'test', 'quiz', 'final', 'midterm', 'prepare'],
            'time_management' => ['time', 'schedule', 'plan', 'organize', 'productivity'],
            'resources' => ['resource', 'material', 'book', 'article', 'video'],
            'career' => ['career', 'job', 'profession', 'future', 'industry'],
            'wellbeing' => ['stress', 'anxiety', 'motivation', 'health', 'balance'],
            'technology' => ['tool', 'software', 'app', 'online', 'digital']
        ];
 
        foreach ($categories as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($query_lower, $keyword) !== false) {
                    return $category;
                }
            }
        }
 
        return 'general';
    }
}