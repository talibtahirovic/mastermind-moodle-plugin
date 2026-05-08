# Mastermind Assistant for Moodle

[![moodle-plugin-ci](https://github.com/talibtahirovic/moodle-block_mastermind_assistant/actions/workflows/moodle-ci.yml/badge.svg)](https://github.com/talibtahirovic/moodle-block_mastermind_assistant/actions/workflows/moodle-ci.yml)

Mastermind Assistant is a Moodle block plugin that brings AI-powered course creation, content generation, and learning analytics directly into your Moodle LMS.

## Features

- **AI Course Creation** - Generate complete course structures from a text prompt or by uploading a syllabus document (PDF, DOCX, TXT).
- **Activity Content Generation** - Draft content for Pages, Quizzes, Assignments, Forums, Lessons, Glossaries, Books, and URL resources with a single click.
- **Course Insights** - View completion rates, average grades, drop-off sections, forum activity, and learner satisfaction at a glance.
- **Course Search & Copy** - Quickly find existing courses and duplicate them with updated dates.
- **AI Recommendations** - Get actionable suggestions to improve course design and learner engagement.

## Requirements

- Moodle 4.5 or later
- A Mastermind Assistant API key (register at https://mastermindassistant.ai)

## Installation

1. Download the plugin and extract it into `blocks/mastermind_assistant` inside your Moodle installation directory.
2. Log in as a site administrator and visit **Site administration > Notifications** to complete the installation.
3. Go to **Site administration > Plugins > Blocks > Mastermind Assistant** to configure your Dashboard URL and API key.

## Configuration

| Setting | Description |
|---|---|
| Dashboard URL | The URL of your Mastermind Dashboard instance |
| API Key | Your Mastermind API key (starts with `ma_live_`) |

After saving, click **Test Connection** to verify your credentials.

## Usage

Add the **Mastermind Assistant** block to any course page or the site dashboard. The block provides:

- Course insights and analytics on course pages
- AI content generation when editing activities
- Course search, copy, and AI-assisted course creation from the dashboard

## License

This plugin is licensed under the [GNU GPL v3 or later](http://www.gnu.org/copyleft/gpl.html).

## Support

- Register: https://mastermindassistant.ai
- Contact: info@mastermindassistant.ai
