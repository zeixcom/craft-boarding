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
- **Import** - Import tours between projects

## Installation

You can install this plugin from the Plugin Store or with Composer / DDEV.

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

### With DDEV

```bash
# Go to the project directory
ddev composer require "zeix/craft-boarding:^1.0.12" -w && ddev craft plugin/install boarding
```

## Usage

### Creating a Tour

1. Go to the Control Panel
2. Click on "Boarding" in the main navigation
3. Click "New Tour"
4. Fill in the tour details:
   - **Name**: The name of your tour
   - **Description**: A brief description of what the tour covers
   - **Progress Indicator Position**: Where the progress indicator should be positioned
   - **Autoplay**: Whether the tour should autoplay or not
   - **User Group**: The user groups the tour should be available to
   - **Steps**: Add one or more steps to your tour
     - **Title**: Step title
     - **Content**: Step content
     - **Target Element**: CSS selector for the element to highlight (e.g., `#main-content`, `.btn.submit`, `[data-attribute="value"]`)
     - **Placement**: Where to show the step relative to the target (`top`, `bottom`, `left`, `right`, `center`)

### Multi-Site Tours and Propagation Methods

The Boarding Pro edition provides comprehensive support for multi-site installations with flexible propagation methods, allowing you to control how tour content is distributed across different sites.

#### Tour Propagation Methods

When creating or editing a tour, you can choose how it propagates across your sites:

**None (Single Site Only)**
- Tour exists only on the site where it was created
- No automatic propagation to other sites
- Ideal for site-specific onboarding content

**All Sites**
- Tour content is identical across all sites
- Changes to tour content on any site update all sites
- Perfect for tours that don't require localization

**Site Group**
- Tour propagates to all sites within the same site group
- Each site can have unique content
- Useful for region-specific or brand-specific tours

**Language**
- Tour propagates to all sites with the same language
- Content remains identical across sites with matching language settings
- Changes made on any site with the same language update all matching sites
- Ideal for multi-domain setups with shared language content

#### Editing Multi-Site Tours

**Content Behavior by Propagation Method**

For **All Sites** and **Language** propagation:
- Tour name, description, and steps remain synchronized
- Editing on any applicable site updates all sites
- No per-site variations allowed

For **Site Group** propagation:
- Each site can have unique tour content
- Edit tour independently on each site in the group
- Changes only affect the current site

For **None** propagation:
- Tour only exists on the creation site

#### Import/Export with Translations

When importing or exporting tours, the system handles multi-language content:

**Export Includes**:
- **Multi-site translations**: All language versions of tour content

**Import Behavior**:
- **Translation Preservation**: Multi-site translations are imported and mapped to corresponding sites
- **Site Mapping**: Map imported sites to sites in your current project


#### Duplicating Tours

To create a copy of an existing tour:

1. Navigate to **Boarding** in the Control Panel
2. Find the tour you want to duplicate in the tours list
3. Click the **gear icon** next to the tour
4. Select **"Duplicate"** from the dropdown menu
5. A new tour will be created 
6. Edit the duplicated tour to customize it for your needs

**Note**: When duplicating tours in multi-site installations, all translations are also duplicated.

### Importing

Boarding Pro includes powerful import functionality to help you migrate tours between projects, create backups, or share tour templates.

#### Importing Tours

1. Navigate to **Boarding** in the Control Panel
2. Click **"Import Tours"** button
3. Choose your import method:

Upload File**
- Click **"Choose File"** and select your exported file
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
   - In Pro Edition, allows configuration of site-specific settings

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

## Support

If you have any issues or feature requests, please create an issue on GitHub.

## License

This plugin is licensed under the Craft Licence.