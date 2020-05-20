<?php

namespace Imageshare\Controllers;

require_once imageshare_php_file('classes/class.logger.php');
require_once imageshare_php_file('classes/views/class.plugin_settings.php');
require_once imageshare_php_file('classes/models/class.resource.php');
require_once imageshare_php_file('classes/models/class.resource_file.php');

use Imageshare\Logger;
use ImageShare\Views\PluginSettings as View;
use ImageShare\Models\Resource as ResourceModel;
use ImageShare\Models\ResourceFile as ResourceFileModel;

class PluginSettings {
    const i18n_ns    = 'imageshare';
    const capability = 'manage_options';
    const page_slug  = 'imageshare_settings';

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }

    public function add_admin_menu() {
        $page_title = __('Imageshare settings', self::i18n_ns);
        $menu_title = __('Imageshare', self::i18n_ns);

        add_submenu_page(
            'options-general.php',
            $page_title,
            $menu_title,
            self::capability,
            self::page_slug,
            array($this, 'render_settings_page')
        );
    }

    private function handle_form_submit() {
        if (isset($_FILES['record_file'])) {
            $json_data = file_get_contents($_FILES['record_file']['tmp_name']);
            $records = json_decode($json_data);

            if ($records === null) {
                return new \WP_Error('imageshare', __('Unable to load records from supplied file.', 'imageshare'));
            }

            // TODO schema validation

            return $this->create_resources($records);
        }
    }

    private function create_resources($records) {
        $result = [
            'resources' => [],
            'errors'    => []
        ];

        foreach ($records as $key => $value) {
            Logger::log("Creating resource for \"{$key}\"");

            try {
                $resource = $this->create_resource($value);
                array_push($result['resources'], $resource);
            } catch (\Exception $error) {
                Logger::log("Error creating resource: " . $error->getMessage());
                array_push($result['errors'], [$value, $error->getMessage()]);        
            }
        }

        return $result;
    }

    private function create_resource($record) {
        if (is_array($record->tags)) {
            $tags = $record->tags;
        } else {
            $tags = explode(',', $record->tags);
            $tags = array_map(function ($_) { return trim($_); }, $tags);
        }

        $resource_id = ResourceModel::create([
            'title'         => $record->unique_name,
            'thumbnail_uri' => $record->featured_image_URI,
            'thumbnail_alt' => $record->featured_image_alt,
            'source'        => $record->source,
            'description'   => $record->description,
            'subject'       => $record->subject,
            'tags'          => $tags
        ]);

        foreach ($record->files as $file) {
            $accommodations = [];
            // TODO parse accommodations

            try {
                $file_id = ResourceFileModel::create([
                    'title'             => $file->display_name,
                    'uri'               => $file->URI,
                    'type'              => $file->type,
                    'format'            => $file->format,
                    'license'           => $file->license,
                    'accommodations'    => $accommodations,
                    'language'          => $file->language,
                    'length_minutes'    => $file->length_minutes
                ]);

                ResourceModel::associate_resource_file($resource_id, $file_id);
            } catch (Exception $error) {
                // log the error
            }
        }

        return $resource_id;
    }

    public function render_settings_page() {
        if (isset($_POST['is_update'])) {
            $parse_result = $this->handle_form_submit();
        }

        $rendered = View::render();
        echo $rendered;
    }
}
