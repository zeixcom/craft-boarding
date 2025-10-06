# Onboarding Plugin for Craft CMS

A comprehensive plugin that makes Onboarding tours available in the Craft Control Panel with full multi-site support and customizable settings.

## Requirements

This plugin requires Craft CMS 5.0.0 or later and PHP 8.0.2 or later.

## Editions

Boarding is available in two editions: **Lite** (free) and **Pro** (paid).

### Lite Edition
- Create and manage up to **3 tours**
- Interactive tour steps with customizable content and placement
- User group-specific tours
- Track which users have completed each tour
- Configurable button position (Header, Sidebar, or Hidden)
- Behavior settings (Auto-start or manual tour initiation)
- Single site installations

### Pro Edition
All Lite features, plus:
- **Unlimited tours** - Create as many tours as you need
- **Multi-Site Support** - Configure different settings for each site
- **Site-specific settings** - Different behavior, button positions, labels, and button texts per site
- **Internationalization** - Customize button texts (Next, Back, Done) for each site/language
- **Multi-language tours** - Translate tour content for different sites
- **Import/Export** - Migrate tours between projects or create backups
- **Advanced configuration** - Per-site tour visibility and management

## Installation

You can install this plugin from the Plugin Store or with Composer.

### From the Plugin Store

Go to the Plugin Store in your project's Control Panel and search for "Boarding". Then click on the "Install" button.

### With Composer

Open your terminal and run the following commands:

```bash
# Go to the project directory
cd /path/to/your-project

# Tell Composer to load the plugin
composer require zeix/craft-boarding
```

## Usage

### Creating a Tour

1. Go to the Control Panel
2. Click on "Boarding" in the main navigation
3. Click "New Tour"
4. Fill in the tour details:
   - **Name**: The name of your tour
   - **Description**: A brief description of what the tour covers
   - **User Group**: The user groups the tour should be available to
   - **Steps**: Add one or more steps to your tour
     - **Title**: Step title
     - **Content**: Step content
     - **Target Element**: CSS selector for the element to highlight (e.g., `#main-content`, `.btn.submit`, `[data-attribute="value"]`)
     - **Placement**: Where to show the step relative to the target (`top`, `bottom`, `left`, `right`, `center`)

### Multi-Language Tours and Translations

The Boarding Pro edition provides comprehensive support for multi-site and multi-language tours, allowing you to create localized experiences for users across different sites.

#### Creating Multi-Language Tours

For multi-site installations, follow this recommended workflow to create tours that work across different languages:

#### Recommended Workflow
1. **Create the Base Tour**:
   - Navigate to **Boarding > New Tour**
   - Fill in the basic tour information in your primary language
   - Add all the tour steps with complete content
   - **Important**: Do not enable translations yet - save the tour first
   - Click **Save**

2. **Enable Translations**:
   - After saving, edit the tour again
   - Toggle the "Translatable Tour" Lightswitch
   - Click **Save** again

3. **Translate Content**:
   - Use the site switcher in the top-right corner of the tour edit page
   - Select the site/language you want to translate to
   - Translate the tour content:
     - **Tour Name**: Translate to the target language
     - **Tour Description**: Translate to the target language
     - **Step Titles**: Translate each step title
     - **Step Content**: Translate the step content while keeping the same structure
     - **Target Elements**: Keep the same CSS selectors (these should remain consistent across sites)
     - **Placement**: Keep the same positioning
   - Click **Save**
   - Repeat for each additional language/site

#### Important Translation Notes

- **Keep step count consistent**: All language versions have the same amount of steps. Steps can only be deleted in the Primary Site Tour. When steps are deleted in non primary site, it will automatically take the step from the Primary Language Version.
- **Test each language**: Preview tours in each language to ensure proper targeting and content flow

#### Import/Export with Translations

When importing or exporting tours, the system handles multi-language content:

**Export Includes**:
- **Multi-site translations**: All language versions of tour content
- **Site-specific settings**: Localized button texts and configurations

**Import Behavior**:
- **Translation Preservation**: Multi-site translations are imported and mapped to corresponding sites
- **Site Mapping**: Map imported sites to sites in your current project

**Best Practices for Multi-Language Import/Export**:
1. Export all tours from your source project
2. Set up your destination project with matching site structure (same site handles if possible)
3. Import tours and test each language version to ensure proper targeting

#### Duplicating Tours

To create a copy of an existing tour:

1. Navigate to **Boarding** in the Control Panel
2. Find the tour you want to duplicate in the tours list
3. Click the **gear icon** next to the tour
4. Select **"Duplicate Tour"** from the dropdown menu
5. A new tour will be created with "(Copy)" appended to the name
6. Edit the duplicated tour to customize it for your needs

**Note**: When duplicating tours in multi-site installations, all translations are also duplicated.

### Importing and Exporting Tours

Boarding Pro includes powerful import/export functionality to help you migrate tours between projects, create backups, or share tour templates.

#### Exporting Tours

**Export Single Tour**:
1. Navigate to **Boarding** in the Control Panel
2. Find the tour you want to export in the tours list
3. Click the **gear icon** next to the tour
4. Select **"Export Tour"** from the dropdown menu
5. The tour will download as a JSON file containing all tour data, steps, and translations

**Export All Tours**:
1. Go to **Boarding** in the Control Panel
2. Click the **"Export All Tours"** button at the top of the tours list
3. All tours will be exported as a single JSON file
4. The export includes all tour content, steps, translations, and metadata

#### What's Included in Exports
Tour exports contain:
- **Tour metadata**: Name, description, handle, and settings
- **All tour steps**: Titles, content, target elements, and placement settings
- **Multi-site translations**: All language versions of tour content (see [Translation section](#multi-language-tours-and-translations) for details)
- **User group assignments**: Which user groups can access the tour
- **Tour configuration**: Enable/disable status and other settings

#### Importing Tours

**Import Tours**:
1. Navigate to **Boarding** in the Control Panel
2. Click **"Import Tours"** button
3. Choose your import method:

Upload JSON File**
- Click **"Choose File"** and select your exported JSON file
- Click **"Import Tours"** to begin the process
- Review the import summary showing which tours will be created/updated

#### Import Behavior
- **New Tours**: Tours not existing in the target project are created
- **Translation Preservation**: Multi-site translations are imported and mapped to corresponding sites
- **User Group Mapping**: User groups are matched by name

### Managing Tour Access and Permissions

Boarding provides granular permission control to manage who can view, create, edit, and manage tours in your Control Panel.

#### Permission Types

The plugin includes the following permissions:

1. **Access Boarding Plugin**
   - Required for any interaction with the plugin
   - Allows users to see the Boarding section in the Control Panel
   - Without this permission, users cannot access any Boarding features

2. **Create Tours** 
   - Allows users to create new tours
   - Requires "Access Boarding Plugin" permission
   - Users can create tours but may not be able to edit or delete them without additional permissions

3. **Edit Tours**
   - Allows users to modify existing tours
   - Requires "Access Boarding Plugin" permission
   - Enables editing of tour content, steps, translations, and settings

4. **Delete Tours** 
   - Allows users to permanently remove tours
   - Requires "Access Boarding Plugin" permission
   - **Important**: Deleting a tour removes all translations and completion tracking

5. **Manage Tour Settings** 
   - Allows access to the plugin settings page
   - Requires "Access Boarding Plugin" permission
   - Enables configuration of button positions, default behavior, and button text
   - In Pro edition, allows configuration of site-specific settings

**Important**: User group assignment is about tour visibility, while permissions control what users can do with tours (create, edit, delete).

#### Interface Localization

Configure site-specific interface elements through **Boarding > Settings**:

##### Button Text Localization
- **Back Button Text**: Text for the previous step button
- **Next Button Text**: Text for the next step button  
- **Done Button Text**: Text for the completion button

##### Menu and Label Customization
- **Menu Label**: Custom text for the tours button (e.g., "Available Tours", "Tours d'aide", "Hilfe-Touren")
- **Menu Position**: Configure where tours appear (Header, Sidebar, or Hidden) per site

#### General Settings
- **Default Behavior**: 
  - `Auto`: Tours automatically start for new users or users that have not completed the tour yet
  - `Manual`: Users must manually start tours
- **Menu Position**:
  - `Header`: Show tours button in the Control Panel header
  - `Sidebar`: Show tours button in the Control Panel sidebar
  - `Hide`: Don't show the tours button


## Support

If you have any issues or feature requests, please create an issue on GitHub.

## License

This plugin is licensed under the Craft Licence.