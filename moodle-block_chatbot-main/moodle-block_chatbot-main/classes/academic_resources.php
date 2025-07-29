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
* Academic resources handler
*
* @package    block_chatbot
* @copyright  2023 Your Name
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/
 
namespace block_chatbot;
 
defined('MOODLE_INTERNAL') || die();
 
/**
* Class to handle academic resources for the chatbot
*/
class academic_resources {
 
    /**
     * Get institution-specific resources based on category
     *
     * @param string $category The resource category
     * @return array Resource information
     */
    public static function get_resources($category) {
        $config = get_config('block_chatbot');
 
        switch ($category) {
            case 'study':
                return [
                    'title' => 'Study Resources',
                    'description' => 'Access study guides, tutorials, and academic support materials',
                    'link' => !empty($config->studyresourceslink) ? $config->studyresourceslink : '',
                    'suggestions' => [
                        'How to access the library',
                        'Finding research papers',
                        'Academic writing guides'
                    ]
                ];
 
            case 'career':
                return [
                    'title' => 'Career Services',
                    'description' => 'Explore career opportunities, internships, and job preparation resources',
                    'link' => !empty($config->careerserviceslink) ? $config->careerserviceslink : '',
                    'suggestions' => [
                        'Resume building help',
                        'Internship opportunities',
                        'Career counseling appointments'
                    ]
                ];
 
            case 'support':
                return [
                    'title' => 'Academic Support',
                    'description' => 'Get help with coursework, tutoring, and academic advising',
                    'link' => !empty($config->academicsupportlink) ? $config->academicsupportlink : '',
                    'suggestions' => [
                        'Tutoring services',
                        'Academic advising',
                        'Study skills workshops'
                    ]
                ];
 
            default:
                return [
                    'title' => 'Institution Resources',
                    'description' => 'Explore the resources available at your institution',
                    'link' => '',
                    'suggestions' => [
                        'Study resources',
                        'Career services',
                        'Academic support'
                    ]
                ];
        }
    }
 
    /**
     * Format resources as a message
     *
     * @param string $category The resource category
     * @return string Formatted message
     */
    public static function format_as_message($category) {
        $resources = self::get_resources($category);
 
        $message = "**{$resources['title']}**\n\n";
        $message .= "{$resources['description']}\n\n";
 
        if (!empty($resources['link'])) {
            $message .= "**Access Now**: [{$resources['title']}]({$resources['link']})\n\n";
        }
 
        $message .= "You might be interested in:\n";
        foreach ($resources['suggestions'] as $suggestion) {
            $message .= "• {$suggestion}\n";
        }
 
        return $message;
    }
}