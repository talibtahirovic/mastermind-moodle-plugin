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
 * AMD module for getting course recommendations.
 *
 * @module     block_mastermind_assistant/recommendations
 * @copyright  2026 The Namers <info@mastermindassistant.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/notification', 'core/str', 'block_mastermind_assistant/ai_policy'],
function(Ajax, Notification, Str, AiPolicy) {
    /**
     * Get course data for recommendations
     * @param {int} courseid The course ID
     */
    function getMastermindData(courseid) {
        var btn = document.getElementById('get-recommendations-btn');
        var originalText = btn.innerHTML;

        // Show loading state
        Str.get_string('loading', 'core').then(function(loadingText) {
            btn.innerHTML = loadingText;
            btn.disabled = true;
            return loadingText;
        }).catch(function() {
            btn.innerHTML = 'Loading...';
            btn.disabled = true;
        });

        // Make AJAX call using Moodle's method
        var request = {
            methodname: 'block_mastermind_assistant_get_course_data',
            args: {
                courseid: courseid
            }
        };

        Ajax.call([request])[0]
            .then(function(response) {
                if (response.success) {
                    // Now send to OpenAI for recommendations
                    return getAIRecommendations(courseid, response.data, btn, originalText);
                } else {
                    // Parse error from response
                    var errorData = JSON.parse(response.data);

                    // Show error notification
                    Notification.addNotification({
                        message: errorData.error || errorData.message || 'error',
                        type: 'error'
                    });

                    // Reset button
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }

                return response;
            })
            .catch(function(error) {
                // Show error notification
                Notification.addNotification({
                    message: 'Error fetching course data: ' + (error.message || 'Connection error'),
                    type: 'error'
                });

                // Reset button
                btn.innerHTML = originalText;
                btn.disabled = false;
            });
    }

    /**
     * Single-call AI analysis: recommendations + updated structure in one request.
     * The dashboard handles both steps internally so it counts as 1 API call.
     *
     * @param {int} courseid The course ID
     * @param {string} coursedata JSON string of course data
     * @param {HTMLElement} btn The button element
     * @param {string} originalText Original button text
     */
    function getAIRecommendations(courseid, coursedata, btn, originalText) {
        // Update button and show progress
        Str.get_string('processing', 'block_mastermind_assistant').then(function(s) {
            btn.innerHTML = s;
            return null;
        }).catch(function() {
            // Fallback handled below.
        });
        btn.disabled = true;
        showProgress();

        // Log action consumption at generation time (action is consumed even if result is not applied).
        Ajax.call([{
            methodname: 'block_mastermind_assistant_log_generation_feedback',
            args: {
                courseid: courseid,
                action: 'generate',
                moduletype: 'course',
                activityname: '',
                coursename: ''
            }
        }])[0].catch(function() {
            // Silent — feedback logging is non-critical.
        });

        Ajax.call([{
            methodname: 'block_mastermind_assistant_get_full_analysis',
            args: {
                courseid: courseid,
                coursedata: coursedata
            },
            timeout: 600000 // 10 minutes — single call does both steps
        }])[0]
            .then(function(response) {
                if (!response.success) {
                    Notification.addNotification({
                        message: 'AI Analysis Error: ' + (response.error || 'Analysis failed'),
                        type: 'error'
                    });
                    hideProgress();
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                    return response;
                }

                // Update progress through all steps
                updateProgress('step1', true);
                updateProgress('step2', true);
                updateProgress('step3', false);

                // Show complete results
                showCompleteResults(response.recommendations, response.structure);

                // Final progress update
                updateProgress('step3', true);
                setTimeout(hideProgress, 3000);

                // Reset button
                btn.innerHTML = originalText;
                btn.disabled = false;

                return response;
            })
            .catch(function(error) {
                Notification.addNotification({
                    message: 'AI Analysis Error: ' + (error.message || 'Unknown error occurred'),
                    type: 'error'
                });
                hideProgress();

                btn.innerHTML = originalText;
                btn.disabled = false;
            });
    }

    /**
     * Show progress tracking in the insights block
     */
    function showProgress() {
        var progressContainer = document.getElementById('ai-progress-container');
        if (progressContainer) {
            progressContainer.style.display = 'block';
            // Reset all steps
            updateProgress('step1', false);
            document.getElementById('step2-progress').style.display = 'none';
            document.getElementById('step3-progress').style.display = 'none';
        }
    }

    /**
     * Hide progress tracking
     */
    function hideProgress() {
        var progressContainer = document.getElementById('ai-progress-container');
        if (progressContainer) {
            progressContainer.style.display = 'none';
        }
    }

    /**
     * Update progress step
     * @param {string} stepId Step identifier (step1, step2, step3)
     * @param {boolean} completed Whether the step is completed
     */
    function updateProgress(stepId, completed) {
        var stepElement = document.getElementById(stepId + '-progress');
        if (stepElement) {
            stepElement.style.display = 'block';
            var icon = stepElement.querySelector('.step-icon');
            if (completed && icon) {
                icon.textContent = '✅';
            }
        }
    }

    /**
     * Format recommendations text as a styled checklist
     * @param {string} text Raw recommendations text
     * @return {string} HTML formatted checklist
     */
    function formatRecommendationsAsChecklist(text) {
        var html;

        // Try to parse as JSON first (structured recommendations)
        try {
            var jsonData = JSON.parse(text);
            if (jsonData && Array.isArray(jsonData.course_structure_improvements)) {
                html = '<ul class="recommendation-checklist">';

                jsonData.course_structure_improvements.forEach(function(item) {
                    html += '<li class="recommendation-item">' +
                        '<span class="recommendation-icon">✅</span>' +
                        '<span class="recommendation-text">' + escapeHtml(item) + '</span>' +
                        '</li>';
                });

                html += '</ul>';
                return html;
            }
        } catch (e) {
            // Not JSON, continue with text parsing
        }

        // Parse as plain text with bullet points
        var lines = text.split('\n');
        html = '<ul class="recommendation-checklist">';
        var hasItems = false;

        lines.forEach(function(line) {
            line = line.trim();
            if (!line) {
                return;
            }

            // Check if line is a bullet point or numbered item
            var bulletMatch = line.match(/^[-•*]\s*(.+)$/);
            var numberMatch = line.match(/^\d+[.)]\s*(.+)$/);

            if (bulletMatch) {
                html += '<li class="recommendation-item">' +
                    '<span class="recommendation-icon">✅</span>' +
                    '<span class="recommendation-text">' + escapeHtml(bulletMatch[1]) + '</span>' +
                    '</li>';
                hasItems = true;
            } else if (numberMatch) {
                html += '<li class="recommendation-item">' +
                    '<span class="recommendation-icon">✅</span>' +
                    '<span class="recommendation-text">' + escapeHtml(numberMatch[1]) + '</span>' +
                    '</li>';
                hasItems = true;
            } else if (line.length > 30) {
                // Treat longer lines as recommendations even without bullets
                html += '<li class="recommendation-item">' +
                    '<span class="recommendation-icon">💡</span>' +
                    '<span class="recommendation-text">' + escapeHtml(line) + '</span>' +
                    '</li>';
                hasItems = true;
            }
        });

        html += '</ul>';

        // If no items were found, return the original text formatted simply
        if (!hasItems) {
            return '<div style="line-height: 1.6; padding: 4px 0;">' +
                escapeHtml(text) +
                '</div>';
        }

        return html;
    }

    /**
     * Escape HTML to prevent XSS
     * @param {string} text Text to escape
     * @return {string} Escaped text
     */
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Show complete results: compact summary in block + full-width modal.
     *
     * @param {string} recommendations Analysis and recommendations
     * @param {string} structure Updated course structure
     */
    function showCompleteResults(recommendations, structure) {
        // Open the full-screen modal directly with all results.
        showResultsModal(recommendations, structure);
    }

    /**
     * Show a full-width modal with recommendations and updated structure.
     *
     * @param {string} recommendations Raw recommendations text
     * @param {string} structure Raw updated structure text
     */
    function showResultsModal(recommendations, structure) {
        // Remove any existing modal
        var existing = document.getElementById('ai-results-modal');
        if (existing) {
            existing.remove();
        }

        var sections = parseAIResponse(recommendations);
        var formattedRecommendations = formatRecommendationsAsChecklist(sections.mainRecommendations);

        var modalHTML = '<div class="ai-results-modal" id="ai-results-modal">' +
            '<div class="ai-results-content">' +
            '<div class="ai-results-header">' +
            '<h2 class="ai-results-title">AI Course Analysis</h2>' +
            '<button class="close-modal-btn" id="close-ai-results">&times;</button>' +
            '</div>' +
            '<div class="ai-results-body">' +
            '<div class="ai-results-recommendations">' +
            '<h3 class="ai-results-section-title">Recommendations</h3>' +
            '<div class="ai-results-recommendations-content">' +
            formattedRecommendations +
            '</div>' +
            '</div>' +
            '<div class="ai-results-structure">' +
            '<h3 class="ai-results-section-title">Updated Course Structure</h3>' +
            '<div id="ai-results-structure-content"></div>' +
            '</div>' +
            '</div>' +
            '</div>' +
            '</div>';

        document.body.insertAdjacentHTML('beforeend', modalHTML);

        // Render the structure tree into the modal's container
        if (structure && structure.trim()) {
            var modalStructureContainer = document.getElementById('ai-results-structure-content');
            if (modalStructureContainer) {
                renderUpdatedCourseStructure(structure, modalStructureContainer);
            }
        }

        var modal = document.getElementById('ai-results-modal');

        // Close button
        var closeBtn = document.getElementById('close-ai-results');
        if (closeBtn) {
            closeBtn.addEventListener('click', function() {
                modal.remove();
            });
        }

        // Close on background click
        modal.addEventListener('click', function(e) {
            if (e.target.id === 'ai-results-modal') {
                modal.remove();
            }
        });

        // Close on Escape key
        document.addEventListener('keydown', function escapeHandler(e) {
            if (e.key === 'Escape') {
                var m = document.getElementById('ai-results-modal');
                if (m) {
                    m.remove();
                }
                document.removeEventListener('keydown', escapeHandler);
            }
        });
    }

    /**
     * Parse AI response into different sections
     * @param {string} response The full AI response
     * @return {Object} Object with different sections
     */
    function parseAIResponse(response) {
        var result = {
            mainRecommendations: response,
            improvements: '',
            updatedStructure: ''
        };

        // First try to parse as JSON
        try {
            var jsonData = JSON.parse(response);
            if (jsonData && jsonData.course_structure_improvements) {
                // Format improvements as bullet points
                if (Array.isArray(jsonData.course_structure_improvements)) {
                    result.improvements = jsonData.course_structure_improvements.map(function(item) {
                        return '• ' + item;
                    }).join('\n');
                } else {
                    result.improvements = jsonData.course_structure_improvements;
                }

                // Use analysis summary as main recommendations
                result.mainRecommendations = jsonData.analysis_summary || 'Course analysis completed.';

                return result;
            }
        } catch (e) {
            // Not JSON, continue with text parsing
        }

        // Fallback to text parsing for non-JSON responses
        // Find Course Structure Improvements section
        var improvementsMatch = response.match(/(Course Structure Improvements?:[\s\S]*?)(?=Updated Course Structure:|$)/i);
        if (improvementsMatch) {
            result.improvements = improvementsMatch[1].trim();
        }

        // Find Updated Course Structure section
        var structureMatch = response.match(/Updated Course Structure:([\s\S]*)$/i);
        if (structureMatch) {
            result.updatedStructure = structureMatch[1].trim();
        }

        // Remove the improvements and structure sections from main recommendations
        var cleanedRecommendations = response;
        if (improvementsMatch) {
            cleanedRecommendations = cleanedRecommendations.replace(improvementsMatch[1], '');
        }
        if (structureMatch) {
            cleanedRecommendations = cleanedRecommendations.replace(/Updated Course Structure:[\s\S]*$/i, '');
        }

        result.mainRecommendations = cleanedRecommendations.trim();

        return result;
    }

    /**
     * Parse and render updated course structure with status indicators.
     *
     * @param {string} structureText Raw updated structure text
     * @param {HTMLElement} targetContainer Target element to render into
     */
    function renderUpdatedCourseStructure(structureText, targetContainer) {
        var container = targetContainer || document.getElementById('ai-structure-content');
        if (!container) {
            return;
        }

        // Parse updated structure text and create interactive elements
        var sections = parseUpdatedCourseStructure(structureText);
        var html = '<div class="updated-enhanced-structure">';

        sections.forEach(function(section, index) {
            var statusClass = section.status.toLowerCase();
            html += '<div class="updated-course-section">';
            html += '<div class="updated-section-header ' + statusClass + '" data-toggle-section="' + index + '">';
            html += '<span>' + section.title + '</span>';
            html += '<div>';
            html += '<span class="status-badge ' + statusClass + '">' + section.status + '</span>';
            html += '<span class="toggle-icon-updated" id="toggle-updated-' + index + '">▶</span>';
            html += '</div>';
            html += '</div>';
            html += '<div class="updated-section-content" id="content-updated-' + index + '">';

            // Add section description
            if (section.description && section.description.trim()) {
                html += '<div class="updated-section-description">';
                html += '<strong>📖 Section Overview</strong>';
                html += '<span>' + section.description + '</span>';
                html += '</div>';
            }

            // Add activities
            if (section.activities.length > 0) {
                html += '<div class="updated-activities-container">';
                html += '<strong>📝 Activities</strong>';
                html += '<div class="updated-activity-grid">';
                        section.activities.forEach(function(activity) {
                            var activityStatus = activity.status.toLowerCase();
                            var activityLabel = '<div>' + activity.name + '</div>';
                            if (activity.type) {
                                activityLabel += '<small>[' + activity.type + ']</small>';
                            }
                            html += '<div class="updated-activity-box ' + activityStatus + '">' +
                                   activityLabel + '</div>';
                        });
                html += '</div>';
                html += '</div>';
            }

            // Add learning objectives
            if (section.objectives.length > 0) {
                html += '<div class="updated-learning-objectives">';
                html += '<h5>🎯 Learning Objectives:</h5>';
                html += '<ul>';
                section.objectives.forEach(function(objective) {
                    html += '<li>' + objective + '</li>';
                });
                html += '</ul>';
                html += '</div>';
            }

            html += '</div>';
            html += '</div>';
        });

                html += '</div>';

        // Add Apply button
        html += '<div class="apply-structure-container">';
        html += '<h6>Ready to Transform Your Course?</h6>';
        html += '<p>Click below to apply these AI recommendations to your actual course structure.</p>';
        html += '<button id="apply-course-structure" class="apply-structure-btn">';
        html += 'Apply Course Structure Changes';
        html += '</button>';
        html += '</div>';

        html += '</div>';

        container.innerHTML = html;

        // Event delegation for section toggle headers
        container.addEventListener('click', function(e) {
            var header = e.target.closest('[data-toggle-section]');
            if (header) {
                var idx = header.getAttribute('data-toggle-section');
                var content = document.getElementById('content-updated-' + idx);
                var icon = document.getElementById('toggle-updated-' + idx);
                if (content) {
                    content.classList.toggle('expanded');
                }
                if (icon) {
                    icon.classList.toggle('expanded');
                }
            }
        });

        // Event listener for Apply button
        var applyButton = document.getElementById('apply-course-structure');
        if (applyButton) {
            applyButton.addEventListener('click', function() {
                applyCourseStructureChanges(structureText);
            });
        }
    }

            /**
             * Parse updated course structure text into structured data with status indicators
             * @param {string} text Raw updated structure text
             * @return {Array} Array of section objects with status
             */
            function parseUpdatedCourseStructure(text) {
                // First try to extract JSON from markdown code blocks
                var cleanJsonText = text;

                // Remove markdown code blocks if present
                if (text.includes('```json')) {
                    var match = text.match(/```json\s*(\{[\s\S]*?\})\s*```/);
                    if (match && match[1]) {
                        cleanJsonText = match[1];
                    }
                } else if (text.includes('```')) {
                    // Handle generic code blocks
                    var genericMatch = text.match(/```\s*(\{[\s\S]*?\})\s*```/);
                    if (genericMatch && genericMatch[1]) {
                        cleanJsonText = genericMatch[1];
                    }
                }

                // Try to parse as JSON
                try {
                    var jsonData = JSON.parse(cleanJsonText);
                    if (jsonData && jsonData.sections && Array.isArray(jsonData.sections)) {
                        return jsonData.sections.map(function(section) {
                            var activities = (section.activities || []).map(function(activity) {
                                return {
                                    name: activity.activity_name || 'Untitled Activity',
                                    status: activity.status || 'NEW',
                                    type: activity.moodle_type || 'page'
                                };
                            });

                            // Fix status: if section is UNCHANGED but has NEW activities, mark as MODIFIED.
                            var sectionStatus = section.status || 'NEW';
                            if (sectionStatus === 'UNCHANGED') {
                                var hasNew = activities.some(function(a) {
 return a.status === 'NEW';
});
                                if (hasNew) {
                                    sectionStatus = 'MODIFIED';
                                }
                            }

                            return {
                                title: section.section_name || 'Untitled Section',
                                status: sectionStatus,
                                description: section.description || 'No description provided.',
                                activities: activities,
                                objectives: section.learning_objectives || []
                            };
                        });
                    }
                } catch (e) {
                    // JSON parsing failed, try more extraction methods

                    // Try to find JSON object in the text
                    var jsonMatch = text.match(/\{[\s\S]*\}/);
                    if (jsonMatch) {
                        try {
                            var extractedJson = JSON.parse(jsonMatch[0]);
                            if (extractedJson && extractedJson.sections && Array.isArray(extractedJson.sections)) {
                                return extractedJson.sections.map(function(section) {
                                    var acts = (section.activities || []).map(function(activity) {
                                        return {
                                            name: activity.activity_name || 'Untitled Activity',
                                            status: activity.status || 'NEW',
                                            type: activity.moodle_type || 'page'
                                        };
                                    });
                                    var secStatus = section.status || 'NEW';
                                    if (secStatus === 'UNCHANGED' && acts.some(function(a) {
 return a.status === 'NEW';
})) {
                                        secStatus = 'MODIFIED';
                                    }
                                    return {
                                        title: section.section_name || 'Untitled Section',
                                        status: secStatus,
                                        description: section.description || 'No description provided.',
                                        activities: acts,
                                        objectives: section.learning_objectives || []
                                    };
                                });
                            }
                        } catch (extractError) {
                            // Advanced extraction also failed, fall through to markdown parsing
                        }
                    }
                }

                // Fallback: parse as markdown text
                var sections = [];
                var lines = text.split('\n');
                var currentSection = null;
                var inActivities = false;
                var inObjectives = false;

                lines.forEach(function(line) {
                    line = line.trim();
                    if (!line) {
                        return;
                    }

                    if (line.match(/^Section\s+\d+:/i)) {
                        // Section headers.
                        if (currentSection) {
                            sections.push(currentSection);
                        }
                        var title = line.replace(/^Section\s+\d+:\s*/i, '').trim();
                        currentSection = {
                            title: title,
                            status: 'NEW', // Default status
                            description: '',
                            activities: [],
                            objectives: []
                        };
                        inActivities = false;
                        inObjectives = false;
                    } else if (line.match(/^Status:/i)) {
                        // Status line.
                        if (currentSection) {
                            var status = line.replace(/^Status:\s*/i, '').trim();
                            currentSection.status = status;
                        }
                    } else if (line.match(/^Description:/i)) {
                        // Description line.
                        if (currentSection) {
                            var description = line.replace(/^Description:\s*/i, '').trim();
                            currentSection.description = description;
                        }
                        inActivities = false;
                        inObjectives = false;
                    } else if (line.match(/^Activities:/i)) {
                        // Activities section.
                        inActivities = true;
                        inObjectives = false;
                    } else if (line.match(/^Learning\s+Objectives:/i)) {
                        // Learning objectives section.
                        inObjectives = true;
                        inActivities = false;
                    } else if (line.match(/^[-*•]\s*/)) {
                        // Activity or objective items.
                        var item = line.replace(/^[-*•]\s*/, '').trim();
                        if (currentSection) {
                            if (inActivities) {
                                // Parse activity with enhanced status format: "Name (KEEP - type)" or "Name (NEW - type)"
                                var enhancedMatch = item.match(/^(.+?)\s+\((KEEP|NEW|MODIFIED)\s*-\s*([^)]+)\)$/);
                                if (enhancedMatch) {
                                    currentSection.activities.push({
                                        name: enhancedMatch[1].trim(),
                                        status: enhancedMatch[2].trim(),
                                        type: enhancedMatch[3].trim()
                                    });
                                } else {
                                    // Fallback for simple format: "Name (STATUS)"
                                    var simpleMatch = item.match(/^(.+?)\s+\(([^)]+)\)$/);
                                    // eslint-disable-next-line max-depth
                                    if (simpleMatch) {
                                        currentSection.activities.push({
                                            name: simpleMatch[1].trim(),
                                            status: simpleMatch[2].trim(),
                                            type: ''
                                        });
                                    } else {
                                        currentSection.activities.push({
                                            name: item,
                                            status: 'NEW',
                                            type: ''
                                        });
                                    }
                                }
                            } else if (inObjectives) {
                                currentSection.objectives.push(item);
                            }
                        }
                    }
                });

                // Add the last section
                if (currentSection) {
                    sections.push(currentSection);
                }

                // If no sections were parsed, create a default structure
                if (sections.length === 0 && text.trim()) {
                    sections.push({
                        title: 'Updated Course Structure',
                        status: 'NEW',
                        description: 'Review the AI-generated recommendations and implement the suggested ' +
                            'improvements to enhance your course structure and student engagement.',
                        activities: [{name: 'Review the recommendations above', status: 'NEW', type: ''}],
                        objectives: ['Implement suggested improvements', 'Enhance student engagement']
                    });
                }

                return sections;
            }

            /**
             * Apply the AI-recommended course structure changes to the actual course
             * @param {string} structureText Raw updated structure text
             */
            function applyCourseStructureChanges(structureText) {
                // Get course ID from the current context
                var courseid = getCourseId();

                if (!courseid || isNaN(courseid)) {
                    Notification.addNotification({
                        message: 'Error: Could not determine course ID (courseid=' + courseid + ')',
                        type: 'error'
                    });
                    return;
                }

                var confirmMessage = 'Are you sure you want to apply these changes to your course structure? '
                    + 'This will modify your actual course and cannot be easily undone.';

                Notification.saveCancel(
                    'Apply Course Structure Changes',
                    confirmMessage,
                    'Apply',
                    function() {
                        performApplyCourseStructure(structureText, courseid);
                    }
                ).catch(Notification.exception);
            }

            /**
             * Prompt the user to reload the page after a successful apply.
             */
            function promptReloadAfterApply() {
                Notification.saveCancel(
                    'Reload Page',
                    'Would you like to reload the page to see the updated course structure?',
                    'Reload',
                    function() {
                        window.location.reload();
                    }
                ).catch(Notification.exception);
            }

            /**
             * Execute the apply-course-structure web service call after the user confirms.
             * @param {string} structureText Raw updated structure text.
             * @param {number} courseid Target course id.
             */
            function performApplyCourseStructure(structureText, courseid) {
                var applyButton = document.getElementById('apply-course-structure');
                if (applyButton) {
                    applyButton.disabled = true;
                    applyButton.innerHTML = '⏳ Applying Changes... (This may take up to 2 minutes)';
                }

                Notification.addNotification({
                    message: 'Started applying course structure changes. This may take some time...',
                    type: 'info'
                });

                var applyRequest = {
                    methodname: 'block_mastermind_assistant_apply_course_structure',
                    args: {
                        courseid: courseid,
                        structuretext: structureText
                    }
                };

                Ajax.call([applyRequest])[0]
                    .then(function(response) {
                        if (!response.success) {
                            Notification.addNotification({
                                message: 'Error applying changes: '
                                    + (response.error || 'Unknown error occurred while applying changes'),
                                type: 'error'
                            });
                            if (applyButton) {
                                applyButton.disabled = false;
                                applyButton.innerHTML = '✨ Apply Course Structure Changes';
                            }
                            return response;
                        }

                        Notification.addNotification({
                            message: '✅ Course structure updated successfully! ' + response.message,
                            type: 'success'
                        });

                        // Optionally reload the page to see changes
                        setTimeout(promptReloadAfterApply, 2000);

                        // Re-enable button on success
                        if (applyButton) {
                            applyButton.disabled = false;
                            applyButton.innerHTML = '✨ Apply Course Structure Changes';
                        }
                        return response;
                    })
                    .catch(function(error) {
                        var errorMessage = 'Error applying changes: ';
                        if (error && error.message) {
                            errorMessage += error.message;
                        } else if (typeof error === 'string') {
                            errorMessage += error;
                        } else {
                            errorMessage += 'Unknown error occurred. Please check the browser console and Moodle logs for details.';
                        }

                        Notification.addNotification({
                            message: errorMessage,
                            type: 'error'
                        });

                        // Re-enable button on error
                        if (applyButton) {
                            applyButton.disabled = false;
                            applyButton.innerHTML = '✨ Apply Course Structure Changes';
                        }
                    });

                // Safety timeout to ensure button is never stuck permanently
                setTimeout(function() {
                    if (applyButton && applyButton.disabled) {
                        applyButton.disabled = false;
                        applyButton.innerHTML = '✨ Apply Course Structure Changes';

                        Notification.addNotification({
                            message: 'The apply operation may have timed out. ' +
                                'Please check your course to see if changes were applied.',
                            type: 'warning'
                        });
                    }
                }, 150000); // 2.5 minutes safety timeout
            }

    /**
     * Helper to reliably obtain the course ID on any course page.
     * @return {number} Course ID, or 0 if not detectable.
     */
    function getCourseId() {
        // 1) Moodle global (set on every course page)
        if (window.M && M.cfg && M.cfg.courseId && !isNaN(parseInt(M.cfg.courseId))) {
            return parseInt(M.cfg.courseId);
        }
        // 2) data-courseid attribute added by Moodle templates
        var bodyAttr = document.body.getAttribute('data-courseid');
        if (bodyAttr && !isNaN(parseInt(bodyAttr))) {
            return parseInt(bodyAttr);
        }
        // 3) URL parameters
        var params = new URLSearchParams(window.location.search);
        var cid = params.get('courseid');
        if (cid && !isNaN(parseInt(cid))) {
            return parseInt(cid);
        }
        // Fallback – id param may be a course or a section id
        var idParam = params.get('id');
        if (idParam && !isNaN(parseInt(idParam))) {
            return parseInt(idParam); // Backend will auto-correct if this is a section id
        }
        return null;
    }

    /**
     * Show detailed metrics modal
     * @param {int} courseid The course ID
     */
    function showDetailedMetrics(courseid) {
        // Fetch detailed metrics
        var request = {
            methodname: 'block_mastermind_assistant_get_detailed_metrics',
            args: {
                courseid: courseid
            }
        };

        Ajax.call([request])[0]
            .then(function(response) {
                if (response.success) {
                    var metrics = JSON.parse(response.metrics);
                    renderDetailedMetricsModal(metrics);
                } else {
                    Notification.addNotification({
                        message: 'Failed to load detailed metrics',
                        type: 'error'
                    });
                }
                return null;
            })
            .catch(function(error) {
                Notification.addNotification({
                    message: 'Error loading detailed metrics: ' + (error.message || 'Unknown error'),
                    type: 'error'
                });
            });
    }

    /**
     * Render detailed metrics modal
     * @param {Object} metrics The detailed metrics data
     */
    function renderDetailedMetricsModal(metrics) {
        // Build quiz performance table if available.
        var quizPerfHTML = '';
        if (metrics.quiz_performance && metrics.quiz_performance.length > 0) {
            quizPerfHTML = '<div class="metric-item" style="grid-column: 1 / -1;">' +
                '<div class="metric-label">Per-Quiz Breakdown</div>' +
                '<table style="width:100%; margin-top:8px; font-size:13px; color:#e2e8f0;">' +
                '<tr style="opacity:0.6;"><th style="text-align:left; padding:4px;">Quiz</th>' +
                '<th style="text-align:right; padding:4px;">Avg Score</th>' +
                '<th style="text-align:right; padding:4px;">Attempts</th></tr>';
            metrics.quiz_performance.forEach(function(q) {
                quizPerfHTML += '<tr><td style="padding:4px;">' + escapeHtml(q.name) + '</td>' +
                    '<td style="text-align:right; padding:4px;">' + q.avg_score + '%</td>' +
                    '<td style="text-align:right; padding:4px;">' + q.attempts + '</td></tr>';
            });
            quizPerfHTML += '</table></div>';
        }

        // Build assignment submissions table if available.
        var assignHTML = '';
        if (metrics.assignment_submissions && metrics.assignment_submissions.length > 0) {
            assignHTML = '<div class="metric-item" style="grid-column: 1 / -1;">' +
                '<div class="metric-label">Assignment Submission Rates</div>' +
                '<table style="width:100%; margin-top:8px; font-size:13px; color:#e2e8f0;">' +
                '<tr style="opacity:0.6;"><th style="text-align:left; padding:4px;">Assignment</th>' +
                '<th style="text-align:right; padding:4px;">Submitted</th>' +
                '<th style="text-align:right; padding:4px;">Rate</th></tr>';
            metrics.assignment_submissions.forEach(function(a) {
                assignHTML += '<tr><td style="padding:4px;">' + escapeHtml(a.name) + '</td>' +
                    '<td style="text-align:right; padding:4px;">' + a.submitted + '/' + a.enrolled + '</td>' +
                    '<td style="text-align:right; padding:4px;">' + a.rate + '%</td></tr>';
            });
            assignHTML += '</table></div>';
        }

        var modalHTML = '<div class="detailed-metrics-modal" id="detailed-metrics-modal">' +
            '<div class="detailed-metrics-content">' +
            '<div class="detailed-metrics-header">' +
            '<h2 class="detailed-metrics-title">Comprehensive Course Analytics</h2>' +
            '<button class="close-modal-btn" id="close-detailed-metrics">&times;</button>' +
            '</div>' +
            '<div class="detailed-metrics-body">' +

            // Category 1: Engagement & Participation.
            '<div class="metrics-category">' +
            '<div class="category-header">' +
            '<div class="category-icon" style="background:rgba(96,165,250,0.15);color:#60a5fa;">&#x1F465;</div>' +
            '<h3 class="category-title">Engagement & Participation</h3></div>' +
            '<div class="metrics-grid">' +
            '<div class="metric-item"><div class="metric-label">Enrollment Count</div>' +
            '<div class="metric-value">' + metrics.enrollment_count + '</div></div>' +
            '<div class="metric-item"><div class="metric-label">Active Users (30 Days)</div>' +
            '<div class="metric-value">' + metrics.active_users + '</div></div>' +
            '<div class="metric-item"><div class="metric-label">Avg Login Frequency</div>' +
            '<div class="metric-value">' + metrics.avg_login_frequency +
                ' <span style="font-size:14px;">sessions</span></div></div>' +
            '<div class="metric-item"><div class="metric-label">Primary Dropout Point</div>' +
            '<div class="metric-value text">' + escapeHtml(metrics.dropout_point) + '</div></div>' +
            '</div></div>' +

            // Category 2: Progress & Completion.
            '<div class="metrics-category">' +
            '<div class="category-header">' +
            '<div class="category-icon" style="background:rgba(52,211,153,0.15);color:#34d399;">&#x2705;</div>' +
            '<h3 class="category-title">Progress & Completion</h3></div>' +
            '<div class="metrics-grid">' +
            '<div class="metric-item"><div class="metric-label">Completion Rate</div>' +
            '<div class="metric-value">' + metrics.completion_rate + '%</div></div>' +
            '<div class="metric-item"><div class="metric-label">Avg Time to Completion</div>' +
            '<div class="metric-value text">' + escapeHtml(metrics.time_to_completion) + '</div></div>' +
            '<div class="metric-item"><div class="metric-label">Most Completed Section</div>' +
            '<div class="metric-value text">' + escapeHtml(metrics.most_completed_section) + '</div></div>' +
            '<div class="metric-item"><div class="metric-label">Least Completed Section</div>' +
            '<div class="metric-value text">' + escapeHtml(metrics.least_completed_section) + '</div></div>' +
            '<div class="metric-item"><div class="metric-label">Most Incomplete Activity</div>' +
            '<div class="metric-value text">' + escapeHtml(metrics.most_incomplete_activity) + '</div></div>' +
            assignHTML +
            '</div></div>' +

            // Category 3: Assessment & Learning Outcomes.
            '<div class="metrics-category">' +
            '<div class="category-header">' +
            '<div class="category-icon" style="background:rgba(251,191,36,0.15);color:#fbbf24;">&#x1F4DD;</div>' +
            '<h3 class="category-title">Assessment & Learning Outcomes</h3></div>' +
            '<div class="metrics-grid">' +
            '<div class="metric-item"><div class="metric-label">Average Quiz Score</div>' +
            '<div class="metric-value">' + metrics.avg_quiz_score + '%</div></div>' +
            '<div class="metric-item"><div class="metric-label">Average Quiz Attempts</div>' +
            '<div class="metric-value">' + metrics.avg_quiz_attempts + '</div></div>' +
            '<div class="metric-item"><div class="metric-label">Most Failed Question</div>' +
            '<div class="metric-value text">' + escapeHtml(metrics.most_failed_question) + '</div></div>' +
            quizPerfHTML +
            '</div></div>' +

            // Category 4: Satisfaction & Feedback.
            '<div class="metrics-category">' +
            '<div class="category-header">' +
            '<div class="category-icon" style="background:rgba(139,92,246,0.15);color:#8b5cf6;">&#x2B50;</div>' +
            '<h3 class="category-title">Satisfaction & Feedback</h3></div>' +
            '<div class="metrics-grid">' +
            '<div class="metric-item"><div class="metric-label">Satisfaction Score</div>' +
            '<div class="metric-value text">' + escapeHtml(metrics.satisfaction_score) + '</div></div>' +
            '</div></div>' +

            // Category 5: Retention & Dropout.
            '<div class="metrics-category">' +
            '<div class="category-header">' +
            '<div class="category-icon" style="background:rgba(248,113,113,0.15);color:#f87171;">&#x26A0;&#xFE0F;</div>' +
            '<h3 class="category-title">Retention & Dropout</h3></div>' +
            '<div class="metrics-grid">' +
            '<div class="metric-item"><div class="metric-label">Dropout Rate</div>' +
            '<div class="metric-value">' + metrics.dropout_rate + '%</div></div>' +
            '<div class="metric-item"><div class="metric-label">Dropout Timing</div>' +
            '<div class="metric-value text">' + escapeHtml(metrics.dropout_timing) + '</div></div>' +
            '</div></div>' +

            // Category 6: Interaction & Collaboration.
            '<div class="metrics-category">' +
            '<div class="category-header">' +
            '<div class="category-icon" style="background:rgba(192,132,252,0.15);color:#c084fc;">&#x1F4AC;</div>' +
            '<h3 class="category-title">Interaction & Collaboration</h3></div>' +
            '<div class="metrics-grid">' +
            '<div class="metric-item"><div class="metric-label">Total Forum Posts</div>' +
            '<div class="metric-value">' + metrics.forum_posts_total + '</div></div>' +
            '<div class="metric-item"><div class="metric-label">Peer Interactions</div>' +
            '<div class="metric-value">' + metrics.peer_interactions + '</div></div>' +
            '<div class="metric-item"><div class="metric-label">Teacher Posts</div>' +
            '<div class="metric-value">' + metrics.teacher_student_interactions + '</div></div>' +
            '</div></div>' +

            '</div></div></div>';

        // Append modal to body
        document.body.insertAdjacentHTML('beforeend', modalHTML);

        var modal = document.getElementById('detailed-metrics-modal');

        // Close button
        var closeBtn = document.getElementById('close-detailed-metrics');
        if (closeBtn) {
            closeBtn.addEventListener('click', function() {
                modal.remove();
            });
        }

        // Close on background click
        modal.addEventListener('click', function(e) {
            if (e.target.id === 'detailed-metrics-modal') {
                modal.remove();
            }
        });

        // Close on Escape key
        document.addEventListener('keydown', function escapeHandler(e) {
            if (e.key === 'Escape') {
                var m = document.getElementById('detailed-metrics-modal');
                if (m) {
                    m.remove();
                }
                document.removeEventListener('keydown', escapeHandler);
            }
        });
    }

    /**
     * Generate a short text summary from detailed metrics.
     *
     * @param {Object} m The metrics object
     * @return {string} HTML summary text
     */
    function generateInsightsSummary(m) {
        var parts = [];

        if (m.enrollment_count > 0) {
            var activePercent = Math.round((m.active_users / m.enrollment_count) * 100);
            parts.push(m.enrollment_count + ' enrolled, ' + activePercent + '% active in last 30 days.');
        }

        if (m.completion_rate > 0) {
            var descriptor = 'low';
            if (m.completion_rate >= 70) {
                descriptor = 'strong';
            } else if (m.completion_rate >= 40) {
                descriptor = 'moderate';
            }
            parts.push(descriptor.charAt(0).toUpperCase() + descriptor.slice(1) + ' completion at ' + m.completion_rate + '%.');
        }

        if (m.avg_quiz_score > 0) {
            parts.push('Average quiz score: ' + m.avg_quiz_score + '%.');
        }

        if (m.forum_posts_total !== undefined && m.enrollment_count > 0) {
            var postsPerUser = (m.forum_posts_total / m.enrollment_count).toFixed(1);
            if (parseFloat(postsPerUser) < 1) {
                parts.push('Low forum engagement (' + postsPerUser + ' posts/learner).');
            }
        }

        if (m.dropout_rate > 30) {
            parts.push('High dropout rate (' + m.dropout_rate + '%) — consider engagement strategies.');
        }

        if (m.least_completed_section && m.least_completed_section !== 'N/A') {
            parts.push('Weakest section: ' + m.least_completed_section + '.');
        }

        return parts.length > 0 ? '<p>' + parts.join(' ') + '</p>' : '';
    }

    return {
        init: function(courseid) {
            var button = document.getElementById('get-recommendations-btn');
            if (button) {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    AiPolicy.checkAndProceed(function() {
                        getMastermindData(courseid);
                    });
                });
            }

            var detailedMetricsBtn = document.getElementById('show-detailed-metrics-btn');
            if (detailedMetricsBtn) {
                detailedMetricsBtn.addEventListener('click', function() {
                    showDetailedMetrics(courseid);
                });
            }

            // Auto-generate insights summary from detailed metrics.
            Ajax.call([{
                methodname: 'block_mastermind_assistant_get_detailed_metrics',
                args: {courseid: courseid}
            }])[0].then(function(response) {
                if (response.success) {
                    var metrics = JSON.parse(response.metrics);
                    var summary = generateInsightsSummary(metrics);
                    var el = document.getElementById('mastermind-summary-text');
                    if (el && summary) {
                        el.innerHTML = summary;
                        var container = document.getElementById('mastermind-insights-summary');
                        if (container) {
                            container.style.display = 'block';
                        }
                    }
                }
                return response;
            }).catch(function() {
                // Silently fail — summary is optional.
            });
        }
    };
});
