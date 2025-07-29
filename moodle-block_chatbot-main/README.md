# Moodle Chatbot Block
 
A chatbot plugin for Moodle that helps users find resources, assignments, and more through a conversational interface.
 
## Features
 
- Interactive chat interface for finding resources
- Support for both conversation mode and direct command mode
- Search for files, URLs, and assignments
- Mobile-friendly design
- Dark mode support
- Customizable appearance
 
## Installation
 
1. Download or clone this repository into the `blocks` directory of your Moodle installation as `chatbot`.
2. Install BotMan dependencies:
cd /path/to/moodle/blocks/chatbot/botman composer install
3. Log in to your Moodle site as an administrator.4. Go to Site administration > Notifications to install the plugin.
5. Add the chatbot block to your course or dashboard.

## Requirements

- Moodle 3.11 or higher
- PHP 7.4 or higher
- Composer (for installation)

## Configuration

You can configure the chatbot from the block settings:

1. Go to Site administration > Plugins > Blocks > Chatbot
2. Customize:
- Chat header color
- Chat button color
- Welcome message
- Help message
- Default interaction mode
- Cache lifetime

## Usage

To search for resources, you can use the following commands:

- "Resource [name]" - Search for a resource with a specific name
- "File [name]" - Search specifically for file resources
- "URL [name]" - Search for URL resources
- "Assign [name]" - Search for assignments

Alternatively, you can just type "resource" to start a guided conversation.

## License

This plugin is licensed under the [GNU GPL v3 or later](http://www.gnu.org/copyleft/gpl.html).

## Credits

Developed by [Your Name]