@block @block_mastermind_assistant @javascript
Feature: The settings page is the primary activation surface for Mastermind Assistant
  In order to connect to Mastermind without hunting for the block
  As an admin
  I should see a Connect button on the plugin settings page

  Background:
    Given I log in as "admin"

  Scenario: Settings page shows Connect CTA when no key is saved
    Given the following config values are set as admin:
      | api_key |  | block_mastermind_assistant |
    When I navigate to "Plugins > Blocks > Mastermind Assistant" in site administration
    Then I should see "Not connected yet"
    And "Connect to Mastermind" "button" should exist
    And I should not see "Dashboard URL"

  Scenario: API key field stays hidden until "Paste it manually" is clicked
    Given the following config values are set as admin:
      | api_key |  | block_mastermind_assistant |
    When I navigate to "Plugins > Blocks > Mastermind Assistant" in site administration
    Then "API Key" "field" should not be visible
    When I click on "Paste it manually" "link"
    Then "API Key" "field" should be visible

  Scenario: Connected state shows status and Disconnect
    Given the following config values are set as admin:
      | api_key | ma_live_abcdef1234567890 | block_mastermind_assistant |
    When I navigate to "Plugins > Blocks > Mastermind Assistant" in site administration
    Then I should see "Connected ✓"
    And "Disconnect" "button" should exist
    And "API Key" "field" should not be visible

  Scenario: Edit / replace key reveals the API Key field
    Given the following config values are set as admin:
      | api_key | ma_live_abcdef1234567890 | block_mastermind_assistant |
    When I navigate to "Plugins > Blocks > Mastermind Assistant" in site administration
    And I click on "Edit / replace key" "button"
    Then "API Key" "field" should be visible
