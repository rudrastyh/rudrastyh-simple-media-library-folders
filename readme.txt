=== Simple Media Library Folders ===
Contributors: rudrastyh
Tags: folders, media folder, media library folders, WordPress media library folders
Requires at least: 5.0
Tested up to: 7.1
Stable tag: 1.8
Requires PHP: 7.3
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Organize your WordPress media library with folders. Beautifully.

== Description ==

Simple Media Library Folders allows you to create folders in the WordPress media library. This is a free version of the plugin, which is also packed with all necessary features and provides a seamless workflow.

= Features =

✅ An unlimited number of folders
✅ Nested folders
✅ Download any folder as a ZIP archive in one click
✅ Instantly search for a folder using the search feature
✅ Sorting folders in a custom order using drag and drop
✅ No annoying notifications
✅ The plugin does not affect your site performance in any way
✅ One-click import from other plugins (currently available are: Filebird, HappyFiles, Real Media Library, Wicked Folders, WP Media Folder by Joomunited, and Folders by Premio)

= Pro features =

⚡️ Add folders for CPT (Custom Post Types)
⚡️ Remove all media files together with a folder feature
⚡️ Custom default folder (previously selected folder is also supported)
⚡️ Configurable folder management capabilities for each user role
⚡️ Show folders in a list view
⚡️ Show folder tree in media modals (instead of dropdown filter)
⚡️ Individual folder permissions (by a username or by a user role)
⚡️ [Multisite Shared Media Library](https://rudrastyh.com/plugins/multisite-shared-media-library) support

🚀 [Upgrade to Pro](https://rudrastyh.com/plugins/wordpress-media-library-folders)

== Installation ==

= Automatic Install =

1. Log into your WordPress dashboard and go to Plugins > Add New
2. Search for "Simple Media Library Folders"
3. Click "Install Now" under the Simple Media Library Folders plugin
4. Click "Activate Now"

= Manual Install =

1. Download the plugin from the download button on this page
2. Unzip the file, and upload the resulting `rudrastyh-simple-media-library-folders` folder to your `/wp-content/plugins` directory
3. Log into your WordPress dashboard and go to Plugins
4. Click "Activate" under the Simple Media Library Folders plugin

== Screenshots ==
1. Plugin settings
2. The Import tab is only displayed in settings if data from other media folder plugins is found in the database
3. Simple Media Library Folders

== Frequently Asked Questions ==

= Is there a folder limit in the Lite version? =
No, there is not. Feel free to create as many folder as you'd like.

= Is it possible to migrate my existing folders, which I created using another plugin? =
Yes. The one-click import is supported from the following media folder plugins: Filebird, HappyFiles, Real Media Library, Wicked Folders, WP Media Folder by Joomunited, and Folders by Premio.

= How many nested levels are supported? =
Both Lite and Pro versions of the plugin support nested folders up to 5 levels deep.

= Will using folders change the URL of my images/posts/pages? =
No, it will not happen. Permalink structure remains untouched.

= What actually happens if I delete a folder? =
By default, the plugin only removes the folder itself. The files remain untouched. However, the Pro version of the plugin allows you to delete all files along with the folder (the appropriate checkbox is available in the plugin settings).

= If I delete a folder, will the pages (posts, custom post types) in the folder be deleted? =
No, only the folder itself will be deleted.

= Can I hide the folder pane at any time? =
Yes, you can hide or show it anytime without a page refresh using "Screen Options" in the top right part of the screen.

= Is this plugin compatible with WordPress Multisite? =
Yes, but if you want to use it together with [Multisite Shared Media Library](https://rudrastyh.com/plugins/multisite-shared-media-library), you will need the PRO version.

= Can I organize WooCommerce products with folders? =
Yes, the plugin allows you to do that, but this option is only available in the Pro version of the plugin.

= Will the plugin settings and folders remain unchanged if I upgrade to the PRO version? =
Yes, the plugin configuration and the folders you created using the Lite version of the plugin will remain unchanged.

= How to activate the Pro version of the plugin? =
You need to deactivate the Lite version of the plugin, then to install and activate the Pro version (as a separate plugin).

= Is the plugin GDPR compliant? =
Yes, the Simple Media Library Folders plugin is GDPR compliant. The plugin neither collects or sends data from your website anywhere.

== Changelog ==

= 1.8 =
* Added: Sorting folders in a custom order using drag and drop
* Fixed: An issue with incorrect folder order in dropdown lists (when adding images to posts)
* Minor UI fixes

= 1.7.3 =
* Fixed: Color mismatch of the resize handle when using other than standard admin color schemes
* Fixed: Minor issue when emptying the search field

= 1.7.2 =
* Added: Style and usability improvements for the Edit Folder modal window
* Added: UX Builder (by Flatsome) compatibility

= 1.7.1 =
* Fixed: A console error when editing galleries in posts

= 1.7 =
* Simplified and more convenient interface for adding new folders

= 1.5.5 =
* Fixed: A database error PHP notice which was related to importing folders from other plugins
* Performance improvements

= 1.5.4 = 
* Added: Sticky folder sidebar which can be helpful in cases when you have a lot of media in the WordPress media library

= 1.5.3 = 
* Fixed: An unnecessary media library refresh when editing folders via the media modal

= 1.5.2 = 
* Fixed: An issue with parent folders when creating new folders via the media modal

= 1.5.1 = 
* Fixed: An issue where the parent folder selection was not updated after adding a new folder
* Fixed: An issue where a page refresh was sometimes required after adding a new folder
* Fixed: Minor CSS issues in WordPress 7.1+

= 1.5 =
* Added: A more convenient way to manage attachment folders when editing it via the modal
* Added: Editing a folder or adding a new one now works without a page refresh
* Changed default capabilities for folders
* Fixed: An issue with the media library not properly refreshing when moving a media file between folders
* CSS styles are optimized for WordPress 7.1+

= 1.4.4 =
* Fixed: An error in the browser console when adding new folders

= 1.4.3 =
* Bug fixes

= 1.4.2 =
* Some improvements in the plugin settings pages

= 1.4.1 =
* Minor UI changes

= 1.4 =
* Added: It is now possible to select multiple folders using checkboxes

= 1.3 =
* Added: A button, which allows you to download files from any folder as a ZIP archive
* Some CSS fixes for the upcoming WordPress 7.0 version

= 1.2 =
* Added: A tool that allows you to import media library folders from other plugins

= 1.1 =
* Added: It is now possible to hide or show folders sidebar via the Screen Options
* Fixed: An issue with SVG arrow icons not being properly displayed for nested folders
* Fixed: PHP Warning inside pre_get_posts action hook
* Fixed: A folder selection when editing media in the modal window is properly displayed as a dropdown list now
* Fixed: Capability issues: the plugin now uses two capabilities for folder management: upload_files and edit_files

= 1.0 =
* Initial release
