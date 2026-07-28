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
 * Pre-generation "Course details" modal for AI course creation.
 *
 * Collects optional instructor context (audience, difficulty, language,
 * outcomes, size, emphasis, tone) and hands it to the caller as a plain
 * object using the dashboard contract keys. Preset chips submit stable
 * English tokens regardless of UI language.
 *
 * @module     block_mastermind_assistant/course_context_form
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/str'], function($, Str) {

    var FREE_TEXT_MAX = 500;

    // Lang strings resolved on open(); indexes documented inline below.
    var STRING_REQUESTS = [
        {key: 'ctx_modal_title', component: 'block_mastermind_assistant'}, // 0
        {key: 'ctx_modal_subtitle', component: 'block_mastermind_assistant'}, // 1
        {key: 'ctx_audience_label', component: 'block_mastermind_assistant'}, // 2
        {key: 'ctx_audience_school', component: 'block_mastermind_assistant'}, // 3
        {key: 'ctx_audience_university', component: 'block_mastermind_assistant'}, // 4
        {key: 'ctx_audience_professionals', component: 'block_mastermind_assistant'}, // 5
        {key: 'ctx_audience_general', component: 'block_mastermind_assistant'}, // 6
        {key: 'ctx_audience_other', component: 'block_mastermind_assistant'}, // 7
        {key: 'ctx_audience_other_placeholder', component: 'block_mastermind_assistant'}, // 8
        {key: 'ctx_difficulty_label', component: 'block_mastermind_assistant'}, // 9
        {key: 'ctx_difficulty_beginner', component: 'block_mastermind_assistant'}, // 10
        {key: 'ctx_difficulty_intermediate', component: 'block_mastermind_assistant'}, // 11
        {key: 'ctx_difficulty_advanced', component: 'block_mastermind_assistant'}, // 12
        {key: 'ctx_difficulty_mixed', component: 'block_mastermind_assistant'}, // 13
        {key: 'ctx_language_label', component: 'block_mastermind_assistant'}, // 14
        {key: 'ctx_language_unspecified', component: 'block_mastermind_assistant'}, // 15
        {key: 'ctx_outcomes_label', component: 'block_mastermind_assistant'}, // 16
        {key: 'ctx_outcomes_placeholder', component: 'block_mastermind_assistant'}, // 17
        {key: 'ctx_size_label', component: 'block_mastermind_assistant'}, // 18
        {key: 'ctx_size_compact', component: 'block_mastermind_assistant'}, // 19
        {key: 'ctx_size_standard', component: 'block_mastermind_assistant'}, // 20
        {key: 'ctx_size_extensive', component: 'block_mastermind_assistant'}, // 21
        {key: 'ctx_emphasis_label', component: 'block_mastermind_assistant'}, // 22
        {key: 'ctx_emphasis_balanced', component: 'block_mastermind_assistant'}, // 23
        {key: 'ctx_emphasis_theory', component: 'block_mastermind_assistant'}, // 24
        {key: 'ctx_emphasis_practice', component: 'block_mastermind_assistant'}, // 25
        {key: 'ctx_emphasis_discussion', component: 'block_mastermind_assistant'}, // 26
        {key: 'ctx_tone_label', component: 'block_mastermind_assistant'}, // 27
        {key: 'ctx_tone_academic', component: 'block_mastermind_assistant'}, // 28
        {key: 'ctx_tone_conversational', component: 'block_mastermind_assistant'}, // 29
        {key: 'ctx_tone_professional', component: 'block_mastermind_assistant'}, // 30
        {key: 'ctx_generate_button', component: 'block_mastermind_assistant'}, // 31
        {key: 'ctx_cancel_button', component: 'block_mastermind_assistant'} // 32
    ];

    /**
     * Escape HTML to prevent XSS. Also escapes double quotes so the result
     * is safe to interpolate inside a double-quoted HTML attribute value
     * (e.g. placeholder="...").
     *
     * @param {string} text Text to escape
     * @returns {string} Escaped, attribute-safe text
     */
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML.replace(/"/g, '&quot;');
    }

    /**
     * Build one chip group's HTML.
     *
     * @param {string} group Context group name (data-group attribute)
     * @param {Array} chips List of {token, label} objects
     * @returns {string} HTML
     */
    function buildChips(group, chips) {
        var html = '<div class="mastermind-ctx-chips">';
        chips.forEach(function(chip) {
            html +=
                '<button type="button" class="mastermind-ctx-chip"' +
                    ' data-group="' + group + '" data-token="' + chip.token + '">' +
                    escapeHtml(chip.label) +
                '</button>';
        });
        html += '</div>';
        return html;
    }

    /**
     * Build the full modal HTML.
     *
     * @param {Array} s Resolved lang strings (see STRING_REQUESTS order)
     * @param {string} heading What is being created (course name / file name)
     * @param {Array} languages List of {label, value} language options
     * @returns {string} HTML
     */
    function buildModalHtml(s, heading, languages) {
        var languageOptions = '<option value="">' + escapeHtml(s[15]) + '</option>';
        (languages || []).forEach(function(lang) {
            languageOptions +=
                '<option value="' + escapeHtml(lang.value) + '">' +
                    escapeHtml(lang.label) +
                '</option>';
        });

        return '<div id="mastermind-ctx-overlay" class="mastermind-preview-overlay">' +
            '<div class="mastermind-preview-modal mastermind-ctx-modal">' +
                '<div class="mastermind-preview-header">' +
                    '<h3 class="mastermind-preview-title">' + escapeHtml(s[0]) + '</h3>' +
                    '<button class="mastermind-close-modal-btn" id="mastermind-ctx-close">&times;</button>' +
                '</div>' +
                '<div class="mastermind-course-preview-body">' +
                    '<h4 class="mastermind-ctx-heading">' + escapeHtml(heading) + '</h4>' +
                    '<p class="mastermind-ctx-subtitle">' + escapeHtml(s[1]) + '</p>' +
                    '<div class="mastermind-ctx-field">' +
                        '<span class="mastermind-ctx-label">' + escapeHtml(s[2]) + '</span>' +
                        buildChips('audience', [
                            {token: 'school_students', label: s[3]},
                            {token: 'university_students', label: s[4]},
                            {token: 'professionals', label: s[5]},
                            {token: 'general_public', label: s[6]},
                            {token: 'other', label: s[7]}
                        ]) +
                        '<input type="text" id="mastermind-ctx-audience-other"' +
                            ' class="mastermind-ctx-other-input" maxlength="' + FREE_TEXT_MAX + '"' +
                            ' placeholder="' + escapeHtml(s[8]) + '">' +
                    '</div>' +
                    '<div class="mastermind-ctx-field">' +
                        '<span class="mastermind-ctx-label">' + escapeHtml(s[9]) + '</span>' +
                        buildChips('difficulty', [
                            {token: 'beginner', label: s[10]},
                            {token: 'intermediate', label: s[11]},
                            {token: 'advanced', label: s[12]},
                            {token: 'mixed', label: s[13]}
                        ]) +
                    '</div>' +
                    '<div class="mastermind-ctx-field">' +
                        '<label class="mastermind-ctx-label" for="mastermind-ctx-language">' +
                            escapeHtml(s[14]) +
                        '</label>' +
                        '<select id="mastermind-ctx-language" class="mastermind-ctx-select">' +
                            languageOptions +
                        '</select>' +
                    '</div>' +
                    '<div class="mastermind-ctx-field">' +
                        '<label class="mastermind-ctx-label" for="mastermind-ctx-outcomes">' +
                            escapeHtml(s[16]) +
                        '</label>' +
                        '<textarea id="mastermind-ctx-outcomes" class="mastermind-ctx-textarea"' +
                            ' rows="2" maxlength="' + FREE_TEXT_MAX + '"' +
                            ' placeholder="' + escapeHtml(s[17]) + '"></textarea>' +
                    '</div>' +
                    '<div class="mastermind-ctx-field">' +
                        '<span class="mastermind-ctx-label">' + escapeHtml(s[18]) + '</span>' +
                        buildChips('size', [
                            {token: 'compact', label: s[19]},
                            {token: 'standard', label: s[20]},
                            {token: 'extensive', label: s[21]}
                        ]) +
                    '</div>' +
                    '<div class="mastermind-ctx-field">' +
                        '<span class="mastermind-ctx-label">' + escapeHtml(s[22]) + '</span>' +
                        buildChips('emphasis', [
                            {token: 'balanced', label: s[23]},
                            {token: 'theory', label: s[24]},
                            {token: 'practice', label: s[25]},
                            {token: 'discussion', label: s[26]}
                        ]) +
                    '</div>' +
                    '<div class="mastermind-ctx-field">' +
                        '<span class="mastermind-ctx-label">' + escapeHtml(s[27]) + '</span>' +
                        buildChips('tone', [
                            {token: 'academic', label: s[28]},
                            {token: 'conversational', label: s[29]},
                            {token: 'professional', label: s[30]}
                        ]) +
                    '</div>' +
                '</div>' +
                '<div class="mastermind-preview-footer">' +
                    '<button class="mastermind-secondary-button mastermind-preview-btn"' +
                        ' id="mastermind-ctx-cancel">' + escapeHtml(s[32]) + '</button>' +
                    '<button class="mastermind-action-button mastermind-preview-btn"' +
                        ' id="mastermind-ctx-generate">&#9889; ' + escapeHtml(s[31]) + '</button>' +
                '</div>' +
            '</div>' +
        '</div>';
    }

    /**
     * Read the selected chip token for a group.
     *
     * @param {jQuery} $overlay Modal overlay element
     * @param {string} group Group name
     * @returns {string|undefined} Selected token
     */
    function selectedToken($overlay, group) {
        return $overlay.find('.mastermind-ctx-chip.selected[data-group="' + group + '"]').data('token');
    }

    /**
     * Collect the context object from the modal's current state.
     *
     * @param {jQuery} $overlay Modal overlay element
     * @returns {object} Context object (contract keys; may be empty)
     */
    function collectContext($overlay) {
        var context = {};

        var audience = selectedToken($overlay, 'audience');
        if (audience === 'other') {
            var other = $.trim($overlay.find('#mastermind-ctx-audience-other').val() || '');
            if (other) {
                context['target_audience'] = other.substring(0, FREE_TEXT_MAX);
            }
        } else if (audience) {
            context['target_audience'] = audience;
        }

        var difficulty = selectedToken($overlay, 'difficulty');
        if (difficulty) {
            context['difficulty'] = difficulty;
        }

        var language = $overlay.find('#mastermind-ctx-language').val();
        if (language) {
            context['language'] = language;
        }

        var outcomes = $.trim($overlay.find('#mastermind-ctx-outcomes').val() || '');
        if (outcomes) {
            context['learning_outcomes'] = outcomes.substring(0, FREE_TEXT_MAX);
        }

        var size = selectedToken($overlay, 'size');
        if (size) {
            context['course_size'] = size;
        }

        var emphasis = selectedToken($overlay, 'emphasis');
        if (emphasis) {
            context['activity_emphasis'] = emphasis;
        }

        var tone = selectedToken($overlay, 'tone');
        if (tone) {
            context['tone'] = tone;
        }

        return context;
    }

    /**
     * Close and remove the modal.
     */
    function close() {
        $('#mastermind-ctx-overlay').remove();
        $(document).off('keydown.ctxForm');
    }

    /**
     * Open the course details modal.
     *
     * @param {object} options {heading, languages, onGenerate}
     */
    function open(options) {
        options = options || {};

        Str.get_strings(STRING_REQUESTS).then(function(s) {
            // Remove any stale instance.
            close();

            $(document.body).append(buildModalHtml(s, options.heading || '', options.languages || []));
            var $overlay = $('#mastermind-ctx-overlay');

            // Chip toggling: single-select per group, click again to deselect.
            $overlay.find('.mastermind-ctx-chip').on('click', function() {
                var $chip = $(this);
                var wasSelected = $chip.hasClass('selected');
                $overlay.find('.mastermind-ctx-chip[data-group="' + $chip.data('group') + '"]')
                    .removeClass('selected');
                if (!wasSelected) {
                    $chip.addClass('selected');
                }
                if ($chip.data('group') === 'audience') {
                    var showOther = !wasSelected && $chip.data('token') === 'other';
                    $overlay.find('#mastermind-ctx-audience-other').toggleClass('visible', showOther);
                }
            });

            // Close without generating.
            $overlay.find('#mastermind-ctx-close, #mastermind-ctx-cancel').on('click', function(e) {
                e.preventDefault();
                close();
            });
            $overlay.on('click', function(e) {
                if ($(e.target).is('#mastermind-ctx-overlay')) {
                    close();
                }
            });
            $(document).on('keydown.ctxForm', function(e) {
                if (e.key === 'Escape') {
                    close();
                }
            });

            // Generate.
            $overlay.find('#mastermind-ctx-generate').on('click', function(e) {
                e.preventDefault();
                var context = collectContext($overlay);
                close();
                if (typeof options.onGenerate === 'function') {
                    options.onGenerate(context);
                }
            });
            return s;
        }).catch(function() {
            // Strings unavailable — skip the form rather than block creation.
            if (typeof options.onGenerate === 'function') {
                options.onGenerate({});
            }
        });
    }

    return {
        open: open
    };
});
