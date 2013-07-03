<?php
/*
Plugin Name: Media Crawler
Plugin URI: https://github.com/atp/mediacrawler
Version: 0.8
Description: The Media Crawler is an plugin which will crawl a given URI, extract media files and metadatas. This plugin stores the media files as Post or Media Resource in the wordpress, and saves the metadatas as postmeta records.
Author: geiser
Author URI: https://github.com/atp
*/

/*
GNU General Public License version 3

Copyright (c) 2011-2013 Marcel Bokhorst

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

class MediaCrawler {
    
    const glue = ' :: ';
    
    private $tab = 'new';
    private $new_id;
    
    public static $info = array('shortname'=>'mediacrawler', 'page'=>'media-crawler_');
    
    public static function install() {
        global $wpdb;
        $table_name = $wpdb->prefix.'mediacrawler_resources';
        $sql =  "CREATE TABLE {$table_name} (
                    tab VARCHAR(50) NOT NULL,
                    url VARCHAR(250) NOT NULL,
                    post_id bigint(20) NOT NULL,
                    UNIQUE (tab, url)
                 );";
        require_once(ABSPATH.'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        add_option('mediacrawler_db_version', '0.9'); 
        
        wp_schedule_event(time(), 'hourly', 'mediacrawlerhourlyeventhook');
        wp_schedule_event(time(), 'twicedaily', 'mediacrawlertwicedailyeventhook');
        wp_schedule_event(time(), 'daily', 'mediacrawlerdailyeventhook');
    }
    
    public static function uninstall() {
        global $wpdb;
        $table_name = $wpdb->prefix.'mediacrawler_resources';
        $wpdb->query('DROP TABLE '.$table_name);
        
        // main settings
        unregister_setting('mediacrawler_options', 'mediacrawler_name');
        unregister_setting('mediacrawler_options', 'mediacrawler_base_url');
        unregister_setting('mediacrawler_options', 'mediacrawler_follow_mode');
        unregister_setting('mediacrawler_options', 'mediacrawler_content_types');
        unregister_setting('mediacrawler_options', 'mediacrawler_exclude_files');
        unregister_setting('mediacrawler_options', 'mediacrawler_scheduled_interval');
        unregister_setting('mediacrawler_options', 'mediacrawler_update_metadata');
        
        // auth settings
        unregister_setting('mediacrawler_options', 'mediacrawler_auth_user');
        unregister_setting('mediacrawler_options', 'mediacrawler_auth_pass');
        
        // metadata settings
        unregister_setting('mediacrawler_options', 'mediacrawler_meta_file');
        unregister_setting('mediacrawler_options', 'mediacrawler_meta_path');
        unregister_setting('mediacrawler_options', 'mediacrawler_meta_url');
        unregister_setting('mediacrawler_options', 'mediacrawler_meta_content_type');
        unregister_setting('mediacrawler_options', 'mediacrawler_meta_content_length');
        unregister_setting('mediacrawler_options', 'mediacrawler_meta_referer_url');
        unregister_setting('mediacrawler_options', 'mediacrawler_meta_refering_linktext');
        unregister_setting('mediacrawler_options', 'mediacrawler_meta_xpath_paths');
        unregister_setting('mediacrawler_options', 'mediacrawler_meta_xpath_metadatas');
        unregister_setting('mediacrawler_options', 'mediacrawler_meta_xpath_counts');
        unregister_setting('mediacrawler_options', 'mediacrawler_meta_const_values');
        unregister_setting('mediacrawler_options', 'mediacrawler_meta_const_metadatas');
        unregister_setting('mediacrawler_options', 'mediacrawler_meta_const_counts');
        
        // relation settings
        unregister_setting('mediacrawler_options', 'mediacrawler_relation_type');
        unregister_setting('mediacrawler_options', 'mediacrawler_relation_files');
        
        // post settings
        unregister_setting('mediacrawler_options', 'mediacrawler_post_replace');
        unregister_setting('mediacrawler_options', 'mediacrawler_post_type');
        unregister_setting('mediacrawler_options', 'mediacrawler_post_status');
        unregister_setting('mediacrawler_options', 'mediacrawler_post_title');
        unregister_setting('mediacrawler_options', 'mediacrawler_post_content');
        
        // schedule events
        wp_clear_scheduled_hook('mediacrawlerhourlyeventhook');
        wp_clear_scheduled_hook('mediacrawlertwicedailyeventhook');
        wp_clear_scheduled_hook('mediacrawlerdailyeventhook');
    }
    
    public static function init() {
        add_action('deleted_post', array('MediaCrawler', 'delete_post'), 10, 1);
        add_action('mediacrawlerhourlyeventhook', array('MediaCrawler', 'update_hourly'), 10);
        add_action('mediacrawlertwicedailyeventhook', array('MediaCrawler', 'update_twicedaily'), 10);
        add_action('mediacrawlerdailyeventhook', array('MediaCrawler', 'update_daily'), 10);
        return new MediaCrawler();
    }
    
    public function __construct() {
        $this->new_id = uniqid();
        
        if (isset($_POST['remove']) && isset($_POST['mediacrawler_remove'])) {
            $this->tab = trim($_POST['mediacrawler_remove']);
            self::remove_options($this->tab);
            wp_safe_redirect('options-general.php?page='.self::$info['page'].'new');
            exit;
        } else if (isset($_POST['add_xpath']) && isset($_POST['mediacrawler_tab'])) {
            $this->tab = trim($_POST['mediacrawler_tab']);
            $this->add_xpath_options();
        } else if (isset($_POST['remove_xpath']) && isset($_POST['mediacrawler_tab']) && isset($_POST['mediacrawler_index'])) {
            $i = trim($_POST['mediacrawler_index']);
            $this->tab = trim($_POST['mediacrawler_tab']);
            self::remove_xpath_options($this->tab, $i);
            wp_safe_redirect('options-general.php?page='.self::$info['page'].$this->tab);
            exit;
        } else if (isset($_POST['add_const']) && isset($_POST['mediacrawler_tab'])) {
            $this->tab = trim($_POST['mediacrawler_tab']);
            $this->add_const_options();
        } else if (isset($_POST['remove_const']) && isset($_POST['mediacrawler_tab']) && isset($_POST['mediacrawler_index'])) {
            $i = trim($_POST['mediacrawler_index']);
            $this->tab = trim($_POST['mediacrawler_tab']);
            self::remove_const_options($this->tab, $i);
            wp_safe_redirect('options-general.php?page='.self::$info['page'].$this->tab);
            exit;
        } else if (isset($_POST['execute']) && isset($_POST['mediacrawler_execute'])) {
            $this->tab = trim($_POST['mediacrawler_execute']);
            self::execute($this->tab);
        } else if (isset($_GET['page']) && !strncmp($_GET['page'], self::$info['page'], strlen(self::$info['page']))) {
            $this->tab = str_replace(self::$info['page'], '', $_GET['page']);
        } else if (isset($_POST['action']) && $_POST['action'] == 'update' && isset($_POST['_mediacrawler_tab'])) {
            $this->tab = trim($_POST['_mediacrawler_tab']);
        }
        
        add_action('admin_init', array($this, 'admin_init'));
        add_action('admin_menu', array($this, 'admin_menu'));
    }
    
    public static function remove_options($tab) {
        // main settings
        $option = get_option('mediacrawler_name', array());
        unset($option[$tab]);
        update_option('mediacrawler_name', $option);
        
        $option = get_option('mediacrawler_base_url', array());
        unset($option[$tab]);
        update_option('mediacrawler_base_url', $option);
        
        $option = get_option('mediacrawler_follow_mode', array());
        unset($option[$tab]);
        update_option('mediacrawler_follow_mode', $option);
        
        $option = get_option('mediacrawler_content_types', array());
        unset($option[$tab]);
        update_option('mediacrawler_content_types', $option);
 
        $option = get_option('mediacrawler_exclude_files', array());
        unset($option[$tab]);
        update_option('mediacrawler_exclude_files', $option);
               
        $option = get_option('mediacrawler_scheduled_interval', array());
        unset($option[$tab]);
        update_option('mediacrawler_scheduled_interval', $option);
        
        $option = get_option('mediacrawler_update_metadata', array());
        unset($option[$tab]);
        update_option('mediacrawler_update_metadata', $option);
        
        // auth settings
        $option = get_option('mediacrawler_auth_user', array());
        unset($option[$tab]);
        update_option('mediacrawler_auth_user', $option);

        $option = get_option('mediacrawler_auth_pass', array());
        unset($option[$tab]);
        update_option('mediacrawler_auth_pass', $option);
        
        // metadata settings
        $option = get_option('mediacrawler_meta_file', array());
        unset($option[$tab]);
        update_option('mediacrawler_meta_file', $option);
        
        $option = get_option('mediacrawler_meta_path', array());
        unset($option[$tab]);
        update_option('mediacrawler_meta_path', $option);
        
        $option = get_option('mediacrawler_meta_url', array());
        unset($option[$tab]);
        update_option('mediacrawler_meta_url', $option);
        
        $option = get_option('mediacrawler_meta_content_type', array());
        unset($option[$tab]);
        update_option('mediacrawler_meta_content_type', $option);
        
        $option = get_option('mediacrawler_meta_content_length', array());
        unset($option[$tab]);
        update_option('mediacrawler_meta_content_length', $option);
        
        $option = get_option('mediacrawler_referer_url', array());
        unset($option[$tab]);
        update_option('mediacrawler_meta_referer_url', $option);
        
        $option = get_option('mediacrawler_meta_refering_linktext', array());
        unset($option[$tab]);
        update_option('mediacrawler_meta_refering_linktext', $option);
        
        $option = get_option('mediacrawler_meta_xpath_paths', array());
        unset($option[$tab]);
        update_option('mediacrawler_meta_xpath_paths', $option);
        
        $option = get_option('mediacrawler_meta_xpath_metadatas', array());
        unset($option[$tab]);
        update_option('mediacrawler_meta_xpath_metadatas', $option);
        
        $option = get_option('mediacrawler_meta_xpath_counts', array());
        unset($option[$tab]);
        update_option('mediacrawler_meta_xpath_counts', $option);
        
        $option = get_option('mediacrawler_meta_const_values', array());
        unset($option[$tab]);
        update_option('mediacrawler_meta_const_values', $option);
        
        $option = get_option('mediacrawler_meta_const_metadatas', array());
        unset($option[$tab]);
        update_option('mediacrawler_meta_const_metadatas', $option);
        
        $option = get_option('mediacrawler_meta_const_counts', array());
        unset($option[$tab]);
        update_option('mediacrawler_meta_const_counts', $option);
        
        // relation settings
        $option = get_option('mediacrawler_relation_type', array());
        unset($option[$tab]);
        update_option('mediacrawler_relation_type', $option);
        
        $option = get_option('mediacrawler_relation_files', array());
        unset($option[$tab]);
        update_option('mediacrawler_relation_files', $option);
        
        // post settings
        $option = get_option('mediacrawler_post_replace', array());
        unset($option[$tab]);
        update_option('mediacrawler_post_replace', $option);
        
        $option = get_option('mediacrawler_post_type', array());
        unset($option[$tab]);
        update_option('mediacrawler_post_type', $option);
        
        $option = get_option('mediacrawler_post_status', array());
        unset($option[$tab]);
        update_option('mediacrawler_post_status', $option);
        
        $option = get_option('mediacrawler_post_title', array());
        unset($option[$tab]);
        update_option('mediacrawler_post_title', $option);
        
        $option = get_option('mediacrawler_post_content', array());
        unset($option[$tab]);
        update_option('mediacrawler_post_content', $option);
    }
    
    public function add_xpath_options() {
        $option = get_option('mediacrawler_meta_xpath_counts', array($this->tab=>0));
        $option[$this->tab] = $option[$this->tab] + 1;
        update_option('mediacrawler_meta_xpath_counts', $option);
    }
    
    public static function remove_xpath_options($tab, $index) {
        $counts = get_option('mediacrawler_meta_xpath_counts', array($tab=>0));
        $paths = get_option('mediacrawler_meta_xpath_paths', array($tab=>''));
        $metadatas = get_option('mediacrawler_meta_xpath_metadatas', array($tab=>''));
        
        $path = explode(self::glue, $paths[$tab]);
        $metadata = explode(self::glue, $metadatas[$tab]);
        for ($i = $index; $i < ($counts[$tab] - 1); $i++) {
            $path[$i] = array_key_exists($i + 1, $path) ? $path[$i + 1] : '';
            $metadata[$i] = array_key_exists($i + 1, $metadata) ? $metadata[$i + 1] : '';
        }
        if (array_key_exists($counts[$tab] - 1, $path)) { unset($path[$counts[$tab] - 1]); }
        if (array_key_exists($counts[$tab] - 1, $metadata)) { unset($metadata[$counts[$tab] - 1]); }
        $metadatas[$tab] = implode(self::glue, $metadata);
        $paths[$tab] = implode(self::glue, $path);
        $counts[$tab] = $counts[$tab] - 1;
        
        update_option('mediacrawler_meta_xpath_paths', $paths);
        update_option('mediacrawler_meta_xpath_metadatas', $metadatas);
        update_option('mediacrawler_meta_xpath_counts', $counts);
    }
    
    public function add_const_options() {
        $option = get_option('mediacrawler_meta_const_counts', array($this->tab=>0));
        $option[$this->tab] = $option[$this->tab] + 1;
        update_option('mediacrawler_meta_const_counts', $option);
    }
    
    public static function remove_const_options($tab, $index) {
        $counts = get_option('mediacrawler_meta_const_counts', array($tab=>0));
        $values = get_option('mediacrawler_meta_const_values', array($tab=>''));
        $metadatas = get_option('mediacrawler_meta_const_metadatas', array($tab=>''));
        
        $value = explode(self::glue, $values[$tab]);
        $metadata = explode(self::glue, $metadatas[$tab]);
        for ($i = $index; $i < ($counts[$tab] - 1); $i++) {
            $value[$i] = array_key_exists($i + 1, $value) ? $value[$i + 1] : '';
            $metadata[$i] = array_key_exists($i + 1, $metadata) ? $metadata[$i + 1] : '';
        }
        if (array_key_exists($counts[$tab] - 1, $value)) { unset($value[$counts[$tab] - 1]); }
        if (array_key_exists($counts[$tab] - 1, $metadata)) { unset($metadata[$counts[$tab] - 1]); }
        $metadatas[$tab] = implode(self::glue, $metadata);
        $values[$tab] = implode(self::glue, $value);
        $counts[$tab] = $counts[$tab] - 1;
        
        update_option('mediacrawler_meta_const_values', $values);
        update_option('mediacrawler_meta_const_metadatas', $metadatas);
        update_option('mediacrawler_meta_const_counts', $counts);
    }
    
    /**
     * Funtion that add fields for options
     */
    public function admin_init() {
        // register settings sections
        add_settings_section('mediacrawler_main_settings_section', 'Main settings',
            array($this, 'main_settings_text'), 'mediacrawler_main_settings_section');
        add_settings_section('mediacrawler_auth_settings_section', 'Auth settings',
            array($this, 'auth_settings_text'), 'mediacrawler_auth_settings_section');       
        add_settings_section('mediacrawler_meta_settings_section', 'Basic metadata settings',
            array($this, 'metadata_settings_text'), 'mediacrawler_meta_settings_section');
        add_settings_section('mediacrawler_relation_settings_section', 'Relation metadata settings',
            array($this, 'relation_settings_text'), 'mediacrawler_relation_settings_section');
        add_settings_section('mediacrawler_post_settings_section', 'Post settings',
            array($this, 'post_settings_text'), 'mediacrawler_post_settings_section');
        
        // add fields and validate inputs
        register_setting('mediacrawler_options', 'mediacrawler_name', array($this, 'name_validate'));
        add_settings_field('mediacrawler_name_field', 'Crawler name', array($this, 'name_field'),
            'mediacrawler_main_settings_section', 'mediacrawler_main_settings_section');
        register_setting('mediacrawler_options', 'mediacrawler_base_url', array($this, 'base_url_validate'));
        add_settings_field('mediacrawler_base_url_field', 'Base URL', array($this, 'base_url_field'),
            'mediacrawler_main_settings_section', 'mediacrawler_main_settings_section');
        register_setting('mediacrawler_options', 'mediacrawler_follow_mode', array($this, 'follow_mode_validate'));
        add_settings_field('mediacrawler_follow_mode_field', 'Follow mode', array($this, 'follow_mode_field'),
            'mediacrawler_main_settings_section', 'mediacrawler_main_settings_section');
        register_setting('mediacrawler_options', 'mediacrawler_content_types', array($this, 'content_types_validate'));
        add_settings_field('mediacrawler_content_types_field', 'Content types', array($this, 'content_types_field'),
            'mediacrawler_main_settings_section', 'mediacrawler_main_settings_section');
        register_setting('mediacrawler_options', 'mediacrawler_exclude_files', array($this, 'exclude_files_validate'));
        add_settings_field('mediacrawler_exclude_files_field', 'Exclude files', array($this, 'exclude_files_field'),
            'mediacrawler_main_settings_section', 'mediacrawler_main_settings_section');
        register_setting('mediacrawler_options', 'mediacrawler_scheduled_interval', array($this, 'scheduled_interval_validate'));
        add_settings_field('mediacrawler_scheduled_interval_field', 'Scheduled interval', array($this, 'scheduled_interval_field'),
            'mediacrawler_main_settings_section', 'mediacrawler_main_settings_section');
        register_setting('mediacrawler_options', 'mediacrawler_update_metadata', array($this, 'update_metadata_validate'));
        add_settings_field('mediacrawler_update_metadata_field', 'When update metadata?', array($this, 'update_metadata_field'),
            'mediacrawler_main_settings_section', 'mediacrawler_main_settings_section');
        
        register_setting('mediacrawler_options', 'mediacrawler_auth_user', array($this, 'auth_user_validate'));
        add_settings_field('mediacrawler_auth_user_field', 'Auth user', array($this, 'auth_user_field'),
            'mediacrawler_auth_settings_section', 'mediacrawler_auth_settings_section');
        register_setting('mediacrawler_options', 'mediacrawler_auth_pass', array($this, 'auth_pass_validate'));
        add_settings_field('mediacrawler_auth_pass_field', 'Auth password', array($this, 'auth_pass_field'),
            'mediacrawler_auth_settings_section', 'mediacrawler_auth_settings_section');
       
        register_setting('mediacrawler_options', 'mediacrawler_meta_file', array($this, 'metadata_file_validate'));
        add_settings_field('mediacrawler_meta_file_field', 'info-&gt;file', array($this, 'metadata_file_field'),
            'mediacrawler_meta_settings_section', 'mediacrawler_meta_settings_section');
        register_setting('mediacrawler_options', 'mediacrawler_meta_path', array($this, 'metadata_path_validate'));
        add_settings_field('mediacrawler_meta_path_field', 'info-&gt;path', array($this, 'metadata_path_field'),
            'mediacrawler_meta_settings_section', 'mediacrawler_meta_settings_section');
        register_setting('mediacrawler_options', 'mediacrawler_meta_url', array($this, 'metadata_url_validate'));
        add_settings_field('mediacrawler_meta_url_field', 'info-&gt;url', array($this, 'metadata_url_field'),
            'mediacrawler_meta_settings_section', 'mediacrawler_meta_settings_section');
        register_setting('mediacrawler_options', 'mediacrawler_meta_content_type', array($this, 'metadata_content_type_validate'));
        add_settings_field('mediacrawler_meta_content_type_field', 'info-&gt;content_type', array($this, 'metadata_content_type_field'),
            'mediacrawler_meta_settings_section', 'mediacrawler_meta_settings_section');
        register_setting('mediacrawler_options', 'mediacrawler_meta_content_length', array($this, 'metadata_content_length_validate'));
        add_settings_field('mediacrawler_meta_content_length_field', 'info-&gt;content_length', array($this, 'metadata_content_length_field'),
            'mediacrawler_meta_settings_section', 'mediacrawler_meta_settings_section');
        register_setting('mediacrawler_options', 'mediacrawler_meta_referer_url', array($this, 'metadata_referer_url_validate'));
        add_settings_field('mediacrawler_meta_referer_url_field', 'info-&gt;referer_url', array($this, 'metadata_referer_url_field'),
            'mediacrawler_meta_settings_section', 'mediacrawler_meta_settings_section');
        register_setting('mediacrawler_options', 'mediacrawler_meta_refering_linktext', array($this, 'metadata_refering_linktext_validate'));
        add_settings_field('mediacrawler_meta_refering_linktext_field', 'info-&gt;refering_linktext', array($this, 'metadata_refering_linktext_field'),
            'mediacrawler_meta_settings_section', 'mediacrawler_meta_settings_section');
        // xpath fields
        register_setting('mediacrawler_options', 'mediacrawler_meta_xpath_paths', array($this, 'metadata_xpath_path_validate'));
        register_setting('mediacrawler_options', 'mediacrawler_meta_xpath_metadatas', array($this, 'metadata_xpath_metadata_validate'));
        $counts = get_option('mediacrawler_meta_xpath_counts', array($this->tab=>0));
        for ($i = 0; $i < $counts[$this->tab]; $i++) {
            add_settings_field('mediacrawler_meta_xpath_field_'.$i, 'info (XPath '.($i + 1).')', array($this, 'metadata_xpath_field'),
                'mediacrawler_meta_settings_section', 'mediacrawler_meta_settings_section', $i);
        }
        // count fields
        register_setting('mediacrawler_options', 'mediacrawler_meta_const_values', array($this, 'metadata_const_value_validate'));
        register_setting('mediacrawler_options', 'mediacrawler_meta_const_metadatas', array($this, 'metadata_const_metadata_validate'));
        $count = get_option('mediacrawler_meta_const_counts', array($this->tab=>0));
        for ($i = 0; $i < $count[$this->tab]; $i++) {
            add_settings_field('mediacrawler_meta_const_field_'.$i, 'info (Const '.($i + 1).')', array($this, 'metadata_const_field'),
                'mediacrawler_meta_settings_section', 'mediacrawler_meta_settings_section', $i);
        }
        
        register_setting('mediacrawler_options', 'mediacrawler_relation_type', array($this, 'relation_type_validate'));
        add_settings_field('mediacrawler_relation_type_field', 'Relation type', array($this, 'relation_type_field'),
            'mediacrawler_relation_settings_section', 'mediacrawler_relation_settings_section');
        register_setting('mediacrawler_options', 'mediacrawler_relation_files', array($this, 'relation_files_validate'));
        add_settings_field('mediacrawler_relation_files_field', 'info-&gt;file (without extension)', array($this, 'relation_files_field'),
            'mediacrawler_relation_settings_section', 'mediacrawler_relation_settings_section');

        register_setting('mediacrawler_options', 'mediacrawler_post_replace', array($this, 'post_replace_validate'));
        add_settings_field('mediacrawler_post_replace_field', 'Replace metadata by string', array($this, 'post_replace_field'),
            'mediacrawler_post_settings_section', 'mediacrawler_post_settings_section');       
        register_setting('mediacrawler_options', 'mediacrawler_post_type', array($this, 'post_type_validate'));
        add_settings_field('mediacrawler_post_type_field', 'Post type', array($this, 'post_type_field'),
            'mediacrawler_post_settings_section', 'mediacrawler_post_settings_section');
        register_setting('mediacrawler_options', 'mediacrawler_post_status', array($this, 'post_status_validate'));
        add_settings_field('mediacrawler_post_status_field', 'Post status', array($this, 'post_status_field'),
            'mediacrawler_post_settings_section', 'mediacrawler_post_settings_section');
        register_setting('mediacrawler_options', 'mediacrawler_post_title', array($this, 'post_title_validate'));
        add_settings_field('mediacrawler_post_title_field', 'Post title', array($this, 'post_title_field'),
            'mediacrawler_post_settings_section', 'mediacrawler_post_settings_section');
        register_setting('mediacrawler_options', 'mediacrawler_post_content', array($this, 'post_content_validate'));
        add_settings_field('mediacrawler_post_content_field', 'Post content template', array($this, 'post_content_field'),
            'mediacrawler_post_settings_section', 'mediacrawler_post_settings_section');
    }
    
    /**
     * Print text in the main setting section
     */
    public function main_settings_text() {
        echo '<p>Main settings</p>';
    }
    
    public function name_validate($input) {
        $option = get_option('mediacrawler_name', array());
        if (!is_array($option)) { $option = array(); }
        if (array_key_exists('new', $input)) {
            $input[$this->new_id] = $input['new'];
            unset($input['new']);
        }
        return array_merge($option, $input);
    }
    
    public function name_field() {
        $name = '';
        if ($this->tab != 'new') {
            $option = get_option('mediacrawler_name', array());
            if (array_key_exists($this->tab, $option)) $name = $option[$this->tab];
        }
        echo '<input id="mediacrawler_name_field" name="mediacrawler_name['.$this->tab.']"';
        echo ' size="20" type="text" value="'.$name.'" />';
    }
    
    public function base_url_validate($input) {
        $option = get_option('mediacrawler_base_url', array());
        if (!is_array($option)) { $option = array(); }
        if (array_key_exists('new', $input)) {
            $input[$this->new_id] = $input['new'];
            unset($input['new']);
        }
        return array_merge($option, $input);
    }
    
    public function base_url_field() {
        $base_url = '';
        if ($this->tab != 'new') {
            $option = get_option('mediacrawler_base_url', array());
            if (!empty($option) && array_key_exists($this->tab, $option)) $base_url = $option[$this->tab];
        }
        echo '<input id="mediacrawler_base_url" name="mediacrawler_base_url['.$this->tab.']"';
        echo ' size="60" type="text" value="'.$base_url.'" />';
    }
    
    public function follow_mode_validate($input) {
        $option = get_option('mediacrawler_follow_mode', array());
        if (!is_array($option)) { $option = array(); }
        if (array_key_exists('new', $input)) {
            $input[$this->new_id] = $input['new'];
            unset($input['new']);
        }
        return array_merge($option, $input);
    }
    
    public function follow_mode_field() {
        $follow_mode = 2;
        if ($this->tab != 'new') {
            $option = get_option('mediacrawler_follow_mode', array());
            if (!empty($option) && array_key_exists($this->tab, $option)) $follow_mode = $option[$this->tab];
        }
        
        if ($follow_mode == 0) { $checked = 'checked = "checked"'; } else { $checked = ''; }
        echo '<input id="mediacrawler_follow_mode_0" name="mediacrawler_follow_mode['.$this->tab.']"';
        echo ' type="radio" value="0" '.$checked.'/>';
        echo '<label for="mediacrawler_follow_mode_0">every link</label> ';
        
        if ($follow_mode == 1) { $checked = 'checked = "checked"'; } else { $checked = ''; }
        echo '<input id="mediacrawler_follow_mode_1" name="mediacrawler_follow_mode['.$this->tab.']"';
        echo ' type="radio" value="1" '.$checked.'/>';
        echo '<label for="mediacrawler_follow_mode_1">same domain</label> ';
        
        if ($follow_mode == 2) { $checked = 'checked = "checked"'; } else { $checked = ''; }
        echo '<input id="mediacrawler_follow_mode_2" name="mediacrawler_follow_mode['.$this->tab.']"';
        echo ' type="radio" value="2" '.$checked.'/>';
        echo '<label for="mediacrawler_follow_mode_2">same host</label> ';
        
        if ($follow_mode == 3) { $checked = 'checked = "checked"'; } else { $checked = ''; }
        echo '<input id="mediacrawler_follow_mode_3" name="mediacrawler_follow_mode['.$this->tab.']"';
        echo ' type="radio" value="3" '.$checked.'/>';
        echo '<label for="mediacrawler_follow_mode_3">same path</label> ';
    }
    
    public function content_types_validate($input) {
        $option = get_option('mediacrawler_content_types', array());
        if (!is_array($option)) { $option = array(); }
        if (array_key_exists('new', $input)) {
            $input[$this->new_id] = $input['new'];
            unset($input['new']);
        }
        return array_merge($option, $input);
    }
    
    public function content_types_field() {
        $content_types = '';
        if ($this->tab != 'new') {
            $option = get_option('mediacrawler_content_types', array());
            if (!empty($option) && array_key_exists($this->tab, $option)) {
                $content_types = $option[$this->tab];
            }
        }
        echo '<input id="mediacrawler_content_types" name="mediacrawler_content_types['.$this->tab.']"';
        echo ' size="60" type="text" value="'.$content_types.'" />';
    }
    
    public function exclude_files_validate($input) {
        $option = get_option('mediacrawler_exclude_files', array());
        if (!is_array($option)) { $option = array(); }
        if (array_key_exists('new', $input)) {
            $input[$this->new_id] = $input['new'];
            unset($input['new']);
        }
        return array_merge($option, $input);
    }
    
    public function exclude_files_field() {
        $exclude_files = '';
        if ($this->tab != 'new') {
            $option = get_option('mediacrawler_exclude_files', array());
            if (!empty($option) && array_key_exists($this->tab, $option)) {
                $exclude_files = $option[$this->tab];
            }
        }
        echo '<input id="mediacrawler_exclude_files" name="mediacrawler_exclude_files['.$this->tab.']"';
        echo ' size="60" type="text" value="'.$exclude_files.'" />';
    }
    
    public function scheduled_interval_validate($input) {
        $option = get_option('mediacrawler_scheduled_interval', array());
        if (!is_array($option)) { $option = array(); }
        if (array_key_exists('new', $input)) {
            $input[$this->new_id] = $input['new'];
            unset($input['new']);
        }
        return array_merge($option, $input);
    }
    
    public function scheduled_interval_field() {
        $scheduled_interval = 'hourly';
        if ($this->tab != 'new') {
            $option = get_option('mediacrawler_scheduled_interval', array());
            if (!empty($option) && array_key_exists($this->tab, $option)) $scheduled_interval = $option[$this->tab];
        }
        
        if ($scheduled_interval == 'hourly') { $checked = 'checked = "checked"'; } else { $checked = ''; }
        echo '<input id="mediacrawler_scheduled_interval_hourly" name="mediacrawler_scheduled_interval['.$this->tab.']"';
        echo ' type="radio" value="hourly" '.$checked.'/>';
        echo '<label for="mediacrawler_scheduled_interval_hourly">hourly</label> ';
        
        if ($scheduled_interval == 'twicedaily') { $checked = 'checked = "checked"'; } else { $checked = ''; }
        echo '<input id="mediacrawler_scheduled_interval_twicedaily" name="mediacrawler_scheduled_interval['.$this->tab.']"';
        echo ' type="radio" value="twicedaily" '.$checked.'/>';
        echo '<label for="mediacrawler_scheduled_interval_twicedaily">twicedaily</label> ';
        
        if ($scheduled_interval == 'daily') { $checked = 'checked = "checked"'; } else { $checked = ''; }
        echo '<input id="mediacrawler_scheduled_interval_daily" name="mediacrawler_scheduled_interval['.$this->tab.']"';
        echo ' type="radio" value="daily" '.$checked.'/>';
        echo '<label for="mediacrawler_scheduled_interval_daily">daily</label> ';
    }
    
    public function update_metadata_validate($input) {
        $option = get_option('mediacrawler_update_metadata', array());
        if (!is_array($option)) { $option = array(); }
        if (array_key_exists('new', $input)) {
            $input[$this->new_id] = $input['new'];
            unset($input['new']);
        }
        return array_merge($option, $input);
    }
    
    public function update_metadata_field() {
        $update_metadata = 'never';
        if ($this->tab != 'never') {
            $option = get_option('mediacrawler_update_metadata', array());
            if (!empty($option) && array_key_exists($this->tab, $option)) $update_metadata = $option[$this->tab];
        }
        
        if ($update_metadata == 'never') { $checked = 'checked = "checked"'; } else { $checked = ''; }
        echo '<input id="mediacrawler_update_metadata_never" name="mediacrawler_update_metadata['.$this->tab.']"';
        echo ' type="radio" value="never" '.$checked.'/>';
        echo '<label for="mediacrawler_update_metadata_never">never</label> ';
        
        if ($update_metadata == 'update') { $checked = 'checked = "checked"'; } else { $checked = ''; }
        echo '<input id="mediacrawler_update_metadata_update" name="mediacrawler_update_metadata['.$this->tab.']"';
        echo ' type="radio" value="update" '.$checked.'/>';
        echo '<label for="mediacrawler_update_metadata_update">update resource</label> ';
        
        if ($update_metadata == 'always') { $checked = 'checked = "checked"'; } else { $checked = ''; }
        echo '<input id="mediacrawler_update_metadata_always" name="mediacrawler_update_metadata['.$this->tab.']"';
        echo ' type="radio" value="always" '.$checked.'/>';
        echo '<label for="mediacrawler_update_metadata_always">always</label> ';
    }
    
    /**
     * Print text in the auth setting section
     */
    public function auth_settings_text() {
        echo '<p>Auth settings</p>';
    }
    
    public function auth_user_validate($input) {
        $option = get_option('mediacrawler_auth_user', array());
        if (!is_array($option)) { $option = array(); }
        if (array_key_exists('new', $input)) {
            $input[$this->new_id] = $input['new'];
            unset($input['new']);
        }
        return array_merge($option, $input);
    }
    
    public function auth_user_field() {
        $auth_user = '';
        if ($this->tab != 'new') {
            $option = get_option('mediacrawler_auth_user', array());
            if (!empty($option) && array_key_exists($this->tab, $option)) $auth_user = $option[$this->tab];
        }
        echo '<input id="mediacrawler_auth_user" name="mediacrawler_auth_user['.$this->tab.']"';
        echo ' size="20" type="text" value="'.$auth_user.'" />';
    }
    
    public function auth_pass_validate($input) {
        $option = get_option('mediacrawler_auth_pass', array());
        if (!is_array($option)) { $option = array(); }
        if (array_key_exists('new', $input)) {
            $input[$this->new_id] = $input['new'];
            unset($input['new']);
        }
        return array_merge($option, $input);
    }
    
    public function auth_pass_field() {
        $auth_pass = '';
        if ($this->tab != 'new') {
            $option = get_option('mediacrawler_auth_pass', array());
            if (!empty($option) && array_key_exists($this->tab, $option)) $auth_pass = $option[$this->tab];
        }
        echo '<input id="mediacrawler_auth_pass" name="mediacrawler_auth_pass['.$this->tab.']"';
        echo ' size="20" type="password" value="'.$auth_pass.'" />';
    }
    
    /**
     * Print text in the metadata setting section
     */
    public function metadata_settings_text() {
        echo '<p>Basic metadata settings</p>';
    }
    
    public function metadata_file_validate($input) {
        $option = get_option('mediacrawler_meta_file', array());
        if (!is_array($option)) { $option = array(); }
        if (array_key_exists('new', $input)) {
            $input[$this->new_id] = $input['new'];
            unset($input['new']);
        }
        return array_merge($option, $input);
    }
    
    public function metadata_file_field() {
        $metadata_file = '';
        if ($this->tab != 'new') {
            $option = get_option('mediacrawler_meta_file', array());
            if (!empty($option) && array_key_exists($this->tab, $option)) $metadata_file = $option[$this->tab];
        }
        echo '<input id="mediacrawler_meta_file_'.$this->tab.'" name="mediacrawler_meta_file['.$this->tab.']"';
        echo ' size="50" type="text" value="'.$metadata_file.'"/>';
    }
    
    public function metadata_path_validate($input) {
        $option = get_option('mediacrawler_meta_path', array());
        if (!is_array($option)) { $option = array(); }
        if (array_key_exists('new', $input)) {
            $input[$this->new_id] = $input['new'];
            unset($input['new']);
        }
        return array_merge($option, $input);
    }
    
    public function metadata_path_field() {
        $metadata_path = '';
        if ($this->tab != 'new') {
            $option = get_option('mediacrawler_meta_path', array());
            if (!empty($option) && array_key_exists($this->tab, $option)) $metadata_path = $option[$this->tab];
        }
        echo '<input id="mediacrawler_meta_path_'.$this->tab.'" name="mediacrawler_meta_path['.$this->tab.']"';
        echo ' size="50" type="text" value="'.$metadata_path.'"/>';
    }
    
    public function metadata_url_validate($input) {
        $option = get_option('mediacrawler_meta_url', array());
        if (!is_array($option)) { $option = array(); }
        if (array_key_exists('new', $input)) {
            $input[$this->new_id] = $input['new'];
            unset($input['new']);
        }
        return array_merge($option, $input);
    }
    
    public function metadata_url_field() {
         $metadata_url = '';
        if ($this->tab != 'new') {
            $option = get_option('mediacrawler_meta_url', array());
            if (!empty($option) && array_key_exists($this->tab, $option)) $metadata_url = $option[$this->tab];
        }
        echo '<input id="mediacrawler_meta_url_'.$this->tab.'" name="mediacrawler_meta_url['.$this->tab.']"';
        echo ' size="50" type="text" value="'.$metadata_url.'"/>';
    }
    
    public function metadata_content_type_validate($input) {
        $option = get_option('mediacrawler_meta_content_type', array());
        if (!is_array($option)) { $option = array(); }
        if (array_key_exists('new', $input)) {
            $input[$this->new_id] = $input['new'];
            unset($input['new']);
        }
        return array_merge($option, $input);
    }
    
    public function metadata_content_type_field() {
        $content_type = '';
        if ($this->tab != 'new') {
            $option = get_option('mediacrawler_meta_content_type', array());
            if (!empty($option) && array_key_exists($this->tab, $option)) $content_type = $option[$this->tab];
        }
        echo '<input id="mediacrawler_meta_content_type_'.$this->tab.'"';
        echo ' name="mediacrawler_meta_content_type['.$this->tab.']" size="50" type="text" value="'.$content_type.'"/>';
    }
    
    public function metadata_content_length_validate($input) {
        $option = get_option('mediacrawler_meta_content_length', array());
        if (!is_array($option)) { $option = array(); }
        if (array_key_exists('new', $input)) {
            $input[$this->new_id] = $input['new'];
            unset($input['new']);
        }
        return array_merge($option, $input);
    }
    
    public function metadata_content_length_field() {
        $content_length = '';
        if ($this->tab != 'new') {
            $option = get_option('mediacrawler_meta_content_length', array());
            if (!empty($option) && array_key_exists($this->tab, $option)) $content_length = $option[$this->tab];
        }
        echo '<input id="mediacrawler_meta_content_length_'.$this->tab.'"';
        echo ' name="mediacrawler_meta_content_length['.$this->tab.']" size="50" type="text" value="'.$content_length.'"/>';
    }
    
    public function metadata_referer_url_validate($input) {
        $option = get_option('mediacrawler_meta_referer_url', array());
        if (!is_array($option)) { $option = array(); }
        if (array_key_exists('new', $input)) {
            $input[$this->new_id] = $input['new'];
            unset($input['new']);
        }
        return array_merge($option, $input);
    }
    
    public function metadata_referer_url_field() {
         $metadata_referer_url = '';
        if ($this->tab != 'new') {
            $option = get_option('mediacrawler_meta_referer_url', array());
            if (!empty($option) && array_key_exists($this->tab, $option)) $metadata_referer_url = $option[$this->tab];
        }
        echo '<input id="mediacrawler_meta_referer_url_'.$this->tab.'"';
        echo ' name="mediacrawler_meta_referer_url['.$this->tab.']" size="50" type="text" value="'.$metadata_referer_url.'"/>';
    }
    
    public function metadata_refering_linktext_validate($input) {
        $option = get_option('mediacrawler_meta_refering_linktext', array());
        if (!is_array($option)) { $option = array(); }
        if (array_key_exists('new', $input)) {
            $input[$this->new_id] = $input['new'];
            unset($input['new']);
        }
        return array_merge($option, $input);
    }
    
    public function metadata_refering_linktext_field() {
        $metadata_refering_linktext = '';
        if ($this->tab != 'new') {
            $option = get_option('mediacrawler_meta_refering_linktext', array());
            if (!empty($option) && array_key_exists($this->tab, $option)) $metadata_refering_linktext = $option[$this->tab];
        }
        echo '<input id="mediacrawler_meta_refering_linktext_'.$this->tab.'"';
        echo ' name="mediacrawler_meta_refering_linktext['.$this->tab.']" size="50"';
        echo ' type="text" value="'.$metadata_refering_linktext.'"/> ';
        if (isset($this->tab) && $this->tab != 'new') {
            echo '<form action="options.php" method="post">';
            settings_fields('mediacrawler_options');
            echo '<input name="add_xpath" type="submit" value="Add XPath" /> ';
            echo '<input name="add_const" type="submit" value="Add Const" /> ';
            echo '<input type="hidden" name="mediacrawler_tab" value="'.$this->tab.'" />';
            echo '</form>';
        }
    }
    
    public function metadata_xpath_path_validate($input) {
        $option = get_option('mediacrawler_meta_xpath_paths', array($this->tab=>''));
        $option[$this->tab] = implode(self::glue, $input);
        return $option;
    }
    
    public function metadata_xpath_metadata_validate($input) {
        $option = get_option('mediacrawler_meta_xpath_metadatas', array($this->tab=>''));
        $option[$this->tab] = implode(self::glue, $input);
        return $option;
    }
    
    public function metadata_xpath_field($i) {
        $option = get_option('mediacrawler_meta_xpath_paths', array($this->tab=>''));
        $metadata_xpath_paths = explode(self::glue, $option[$this->tab]);
        $metadata_xpath_path = isset($metadata_xpath_paths[$i]) ? $metadata_xpath_paths[$i] : '';
        $option = get_option('mediacrawler_meta_xpath_metadatas', array($this->tab=>''));
        $metadata_xpath_metadatas = explode(self::glue, $option[$this->tab]);
        $metadata_xpath_metadata = isset($metadata_xpath_metadatas[$i]) ? $metadata_xpath_metadatas[$i] : '';
        
        echo '<input id="mediacrawler_meta_xpath_paths_'.$i.'" name="mediacrawler_meta_xpath_paths['.$i.']"';
        echo ' size="40" type="text" value="'.$metadata_xpath_path.'"/> Metadata ';
        echo '<input id="mediacrawler_meta_xpath_metadatas_'.$i.'" name="mediacrawler_meta_xpath_metadatas['.$i.']"';
        echo ' size="40" type="text" value="'.$metadata_xpath_metadata.'"/> ';
        echo '<form action="options.php" method="post">';
        settings_fields('mediacrawler_options');
        echo '<input name="remove_xpath" type="submit" value="Remove" />';
        echo '<input type="hidden" name="mediacrawler_index" value="'.$i.'" />';
        echo '<input type="hidden" name="mediacrawler_tab" value="'.$this->tab.'" />';
        echo '</form>';
    }
    
    public function metadata_const_value_validate($input) {
        $option = get_option('mediacrawler_meta_const_values', array($this->tab=>''));
        $option[$this->tab] = implode(self::glue, $input);
        return $option;
    }
    
    public function metadata_const_metadata_validate($input) {
        $option = get_option('mediacrawler_meta_const_metadatas', array($this->tab=>''));
        $option[$this->tab] = implode(self::glue, $input);
        return $option;
    }
    
    public function metadata_const_field($i) {
        $option = get_option('mediacrawler_meta_const_values', array($this->tab=>''));
        $metadata_const_values = explode(self::glue, $option[$this->tab]);
        $metadata_const_value = isset($metadata_const_values[$i]) ? $metadata_const_values[$i] : '';
        $option = get_option('mediacrawler_meta_const_metadatas', array($this->tab=>''));
        $metadata_const_metadatas = explode(self::glue, $option[$this->tab]);
        $metadata_const_metadata = isset($metadata_const_metadatas[$i]) ? $metadata_const_metadatas[$i] : '';
        
        echo '<input id="mediacrawler_meta_const_values_'.$i.'" name="mediacrawler_meta_const_values['.$i.']"';
        echo ' size="40" type="text" value="'.$metadata_const_value.'"/> Metadata ';
        echo '<input id="mediacrawler_meta_const_metadatas_'.$i.'" name="mediacrawler_meta_const_metadatas['.$i.']"';
        echo ' size="40" type="text" value="'.$metadata_const_metadata.'"/> ';
        echo '<form action="options.php" method="post">';
        settings_fields('mediacrawler_options');
        echo '<input name="remove_const" type="submit" value="Remove" />';
        echo '<input type="hidden" name="mediacrawler_index" value="'.$i.'" />';
        echo '<input type="hidden" name="mediacrawler_tab" value="'.$this->tab.'" />';
        echo '</form>';
    }
    
    /**
     * Print text in the relation setting section
     */
    public function relation_settings_text() {
        echo '<p>Relation metadata settings. Each field describe a possible variation of metadata, ';
        echo 'this variations can be ignored or included. Thus, if the relation type is ignore only ';
        echo 'one post will saved in wordpress for each metadata and its variations (default value). ';
        echo 'If the relation type is include all metadata will saved as post, each variation will ';
        echo 'generate one post.</p>';
    }
    
    public function relation_type_validate($input) {
        $option = get_option('mediacrawler_relation_type', array());
        if (!is_array($option)) { $option = array(); }
        if (array_key_exists('new', $input)) {
            $input[$this->new_id] = $input['new'];
            unset($input['new']);
        }
        return array_merge($option, $input);
    }
    
    public function relation_type_field() {
        $relation_type = 'ignore';
        if ($this->tab != 'new') {
            $option = get_option('mediacrawler_relation_type', array());
            if (!empty($option) && array_key_exists($this->tab, $option)) $relation_type = $option[$this->tab];
        }
        
        if ($relation_type == 'ignore') { $checked = 'checked = "checked"'; } else { $checked = ''; }
        echo '<input id="mediacrawler_relation_type_ignore" name="mediacrawler_relation_type['.$this->tab.']"';
        echo ' type="radio" value="ignore" '.$checked.'/>';
        echo '<label for="mediacrawler_relation_type_ignore">ignore</label> ';
        
        if ($relation_type == 'include') { $checked = 'checked = "checked"'; } else { $checked = ''; }
        echo '<input id="mediacrawler_relation_type_include" name="mediacrawler_relation_type['.$this->tab.']"';
        echo ' type="radio" value="include" '.$checked.'/>';
        echo '<label for="mediacrawler_relation_type_include">include</label> ';
    }
    
    public function relation_files_validate($input) {
        $option = get_option('mediacrawler_relation_files', array());
        if (!is_array($option)) { $option = array(); }
        if (array_key_exists('new', $input)) {
            $input[$this->new_id] = $input['new'];
            unset($input['new']);
        }
        return array_merge($option, $input);
    }
    
    public function relation_files_field() {
        $relation_files = '';
        if ($this->tab != 'new') {
            $option = get_option('mediacrawler_relation_files', array());
            if (!empty($option) && array_key_exists($this->tab, $option)) $relation_files = $option[$this->tab];
        }
        echo '<input id="mediacrawler_relation_files_'.$this->tab.'" name="mediacrawler_relation_files['.$this->tab.']"';
        echo ' size="75" type="text" value="'.$relation_files.'"/>';
    }
    
    /**
     * Print text in the post setting section
     */
    public function post_settings_text() {
        echo '<p>Post content settings</p>';
    }
    
    public function post_replace_validate($input) {
        $option = get_option('mediacrawler_post_replace', array());
        if (!is_array($option)) { $option = array(); }
        if (array_key_exists('new', $input)) {
            $input[$this->new_id] = $input['new'];
            unset($input['new']);
        }
        return array_merge($option, $input);
    }
    
    public function post_replace_field() {
        $post_replace = 'yes';
        if ($this->tab != 'new') {
            $option = get_option('mediacrawler_post_replace', array());
            if (!empty($option) && array_key_exists($this->tab, $option)) $post_replace = $option[$this->tab];
        }
        
        if ($post_replace == 'yes') { $checked = 'checked = "checked"'; } else { $checked = ''; }
        echo '<input id="mediacrawler_post_replace_yes" name="mediacrawler_post_replace['.$this->tab.']"';
        echo ' type="radio" value="yes" '.$checked.'/>';
        echo '<label for="mediacrawler_post_replace_yes">yes</label> ';
        
        if ($post_replace == 'no') { $checked = 'checked = "checked"'; } else { $checked = ''; }
        echo '<input id="mediacrawler_post_replace_no" name="mediacrawler_post_replace['.$this->tab.']"';
        echo ' type="radio" value="no" '.$checked.'/>';
        echo '<label for="mediacrawler_post_replace_no">no</label> ';
    }
    
    public function post_type_validate($input) {
        $option = get_option('mediacrawler_post_type', array());
        if (!is_array($option)) { $option = array(); }
        if (array_key_exists('new', $input)) {
            $input[$this->new_id] = $input['new'];
            unset($input['new']);
        }
        return array_merge($option, $input);
    }
    
    public function post_type_field() {
        $post_type = 'post';
        if ($this->tab != 'new') {
            $option = get_option('mediacrawler_post_type', array());
            if (!empty($option) && array_key_exists($this->tab, $option)) $post_type = $option[$this->tab];
        }
        
        if ($post_type == 'post') { $checked = 'checked = "checked"'; } else { $checked = ''; }
        echo '<input id="mediacrawler_post_type_post" name="mediacrawler_post_type['.$this->tab.']"';
        echo ' type="radio" value="post" '.$checked.'/>';
        echo '<label for="mediacrawler_post_type_post">post</label> ';
        
        if ($post_type == 'attachment') { $checked = 'checked = "checked"'; } else { $checked = ''; }
        echo '<input id="mediacrawler_post_type_attachment" name="mediacrawler_post_type['.$this->tab.']"';
        echo ' type="radio" value="attachment" '.$checked.'/>';
        echo '<label for="mediacrawler_post_type_attachment">attachment</label> ';
        
        if ($post_type == 'page') { $checked = 'checked = "checked"'; } else { $checked = ''; }
        echo '<input id="mediacrawler_post_type_page" name="mediacrawler_post_type['.$this->tab.']"';
        echo ' type="radio" value="page" '.$checked.'/>';
        echo '<label for="mediacrawler_post_type_page">page</label> ';
    }
    
    public function post_status_validate($input) {
        $option = get_option('mediacrawler_post_status', array());
        if (!is_array($option)) { $option = array(); }
        if (array_key_exists('new', $input)) {
            $input[$this->new_id] = $input['new'];
            unset($input['new']);
        }
        return array_merge($option, $input);
    }
    
    public function post_status_field() {
        $post_status = 'draft';
        if ($this->tab != 'new') {
            $option = get_option('mediacrawler_post_status', array());
            if (!empty($option) && array_key_exists($this->tab, $option)) $post_status = $option[$this->tab];
        }
        
        if ($post_status == 'draft') { $checked = 'checked = "checked"'; } else { $checked = ''; }
        echo '<input id="mediacrawler_post_status_draft" name="mediacrawler_post_status['.$this->tab.']"';
        echo ' type="radio" value="draft" '.$checked.'/>';
        echo '<label for="mediacrawler_post_status_draft">draft</label> ';
        
        if ($post_status == 'publish') { $checked = 'checked = "checked"'; } else { $checked = ''; }
        echo '<input id="mediacrawler_post_status_publish" name="mediacrawler_post_status['.$this->tab.']"';
        echo ' type="radio" value="publish" '.$checked.'/>';
        echo '<label for="mediacrawler_post_status_publish">publish</label> ';
    }

    public function post_title_validate($input) {
        $option = get_option('mediacrawler_post_title', array());
        if (!is_array($option)) { $option = array(); }
        if (array_key_exists('new', $input)) {
            $input[$this->new_id] = $input['new'];
            unset($input['new']);
        }
        return array_merge($option, $input);
    }
    
    public function post_title_field() {
        $post_title = '';
        if ($this->tab != 'new') {
            $option = get_option('mediacrawler_post_title', array());
            if (!empty($option) && array_key_exists($this->tab, $option)) $post_title = $option[$this->tab];
        }
        echo '<input id="mediacrawler_post_title" name="mediacrawler_post_title['.$this->tab.']"';
        echo ' size="60" type="text" value=\''.$post_title.'\' />';
    }
    
    public function post_content_validate($input) {
        $option = get_option('mediacrawler_post_content', array());
        if (!is_array($option)) { $option = array(); }
        if (array_key_exists('new', $input)) {
            $input[$this->new_id] = $input['new'];
            unset($input['new']);
        }
        return array_merge($option, $input);
    }
    
    public function post_content_field() {
        $content = '';
        if ($this->tab != 'new') {
            $option = get_option('mediacrawler_post_content', array());
            if (!empty($option) && array_key_exists($this->tab, $option)) $content = $option[$this->tab];
        }
        wp_editor($content, 'mediacrawler_post_content', array('textarea_name'=>'mediacrawler_post_content['.$this->tab.']'));
    }
    
    /**
     * Funtion that add buttons for administrator of sites
     */
    public function admin_menu() {
        add_options_page('Media Crawler Options', 'Media Crawler', 'manage_options',
                         self::$info['page'].$this->tab, array($this, 'options_page'));
    }
    
    /**
     * Print options page, setting for pluggin
     */  
    public function options_page() {
        echo '<h2>Media crawler plugin</h2>';
        echo 'Options relating to the Media Crawler plugin.';
        echo '<div id="icon-themes" class="icon32"><br/></div>';
        echo '<h2 class="nav-tab-wrapper">';
        $tabs = get_option('mediacrawler_name', array()); $tabs['new'] = 'New Site';
        foreach ($tabs as $tab=>$name) {
            $class = ($this->tab == $tab ? ' nav-tab-active' : '');
            echo '<a class="nav-tab'.$class.'" href="?page='.self::$info['page'].$tab.'">'.$name.'</a>';
        }
        echo '</h2>'; ?>
        <div>
            <form action="options.php" method="post">
                <?php settings_fields('mediacrawler_options'); ?>
                <?php do_settings_sections('mediacrawler_main_settings_section'); ?>
                <?php do_settings_sections('mediacrawler_auth_settings_section'); ?>
                <?php do_settings_sections('mediacrawler_meta_settings_section'); ?>
                <?php do_settings_sections('mediacrawler_relation_settings_section'); ?>
                <?php do_settings_sections('mediacrawler_post_settings_section'); ?>
                <input type="hidden" name="_mediacrawler_tab" value="<?php echo $this->tab; ?>"/>
                <input class="button-primary" name="Submit" type="submit" value="<?php esc_attr_e('Save Changes'); ?>" />
                <?php if (isset($this->tab) && $this->tab != 'new') { ?>
                    <input type="hidden" name="mediacrawler_execute" value="<?php echo $this->tab; ?>"/>
                    <input name="execute" type="submit" value="<?php esc_attr_e('Execute crawling'); ?>" />
                    <input type="hidden" name="mediacrawler_remove" value="<?php echo $this->tab; ?>"/>
                    <input name="remove" type="submit" value="<?php esc_attr_e('Remove site'); ?>" />
                <?php } ?>
            </form>
        </div><?php
    }

    private static function do_shortcode_meta($content, $meta) {
        require_once(ABSPATH.'wp-includes/shortcodes.php');
        preg_match_all('#\[(\[?)(meta)\b([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(?:(\/)\]|\]'.
                       '(?:([^\[]*+(?:\[(?!\/\2\])[^\[]*+)*+)\[\/\2\])?)(\]?)#si', $content, $matchs);
        $replaces = array();
        foreach ($matchs[3] as $k=>$match_attrs) {
            $attrs = shortcode_parse_atts($match_attrs);
            $replaces[$k] = isset($meta[$attrs['key']]) ? array_shift(array_values($meta[$attrs['key']])) : '';
        }
        return str_replace($matchs[0], $replaces, $content);
    }
    
    private static function do_shortcode_foreach_meta($content, $meta) {
        require_once(ABSPATH.'wp-includes/shortcodes.php');
        preg_match_all('#\[(\[?)(foreach_meta)\b([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(?:(\/)\]|\]'.
                       '(?:([^\[]*+(?:\[(?!\/\2\])[^\[]*+)*+)\[\/\2\])?)(\]?)#si', $content, $matchs);
        $replaces = array();
        foreach ($matchs[3] as $k=>$match_attrs) {
            $replaces[$k] = '';
            $attrs = shortcode_parse_atts($match_attrs);
            if (isset($meta[$attrs['key']])) {
                foreach ($meta[$attrs['key']] as $value) {
                    $replaces[$k] .= preg_replace('#\{value\}#i', $value, $matchs[5][$k]);
                }
            }
            $replaces[$k] = self::do_shortcode_foreach_meta($replaces[$k], $meta);
        }
        $content = str_replace($matchs[0], $replaces, $content);
        return str_replace('[/foreach_meta]', '', $content);
    }

    private static function do_shortcode($content, $meta) {
        $content = self::do_shortcode_foreach_meta($content, $meta);
        return self::do_shortcode_meta($content, $meta);
    }

    private static function generate_attachment_metadata($fileurl, $thumbnails) {
        $metadata = array();
        $imagesize = getimagesize($fileurl);
        $metadata['width'] = $imagesize[0];
        $metadata['height'] = $imagesize[1];
        
        list($uwidth, $uheight) = wp_constrain_dimensions($metadata['width'], $metadata['height'], 128, 96);
        $metadata['hwstring_small'] = "height='$uheight' width='$uwidth'";
        
        // Make the file path relative to the upload dir
        $metadata['file'] = $fileurl; //_wp_relative_upload_path($file);

        // make thumbnails and other intermediate sizes
        global $_wp_additional_image_sizes;
        foreach (get_intermediate_image_sizes() as $s) {
            $sizes[$s] = array('width' => '', 'height' => '', 'crop' => FALSE);
            if (isset($_wp_additional_image_sizes[$s]['width'])) {
                $sizes[$s]['width'] = intval($_wp_additional_image_sizes[$s]['width']); // For theme-added sizes
            } else {
                $sizes[$s]['width'] = get_option("{$s}_size_w"); // For default sizes set in options
            }
            if (isset($_wp_additional_image_sizes[$s]['height'])) {
                $sizes[$s]['height'] = intval($_wp_additional_image_sizes[$s]['height']); // For theme-added sizes
            } else {
                $sizes[$s]['height'] = get_option("{$s}_size_h"); // For default sizes set in options
            }
            if (isset($_wp_additional_image_sizes[$s]['crop'])) {
                $sizes[$s]['crop'] = intval($_wp_additional_image_sizes[$s]['crop']); // For theme-added sizes
            } else {
                $sizes[$s]['crop'] = get_option("{$s}_crop"); // For default sizes set in options
            }
        }
        $sizes = apply_filters('intermediate_image_sizes_advanced', $sizes);
        
        // set thumbnails
        $uploads = wp_upload_dir();
        foreach ($sizes as $size => $size_data ) {
            $width = $size_data['width'];
            if (isset($thumbnails[$width])) {
                $metadata['sizes'][$size]['file'] = substr(str_replace($uploads['basedir'], '', $thumbnails[$width]), 1);
                $metadata['sizes'][$size]['path'] = $thumbnails[$width];
            }
        }
        
        // fetch additional metadata from exif/iptc
        $image_meta = wp_read_image_metadata($fileurl);
        if ($image_meta) { $metadata['image_meta'] = $image_meta; }
        return $metadata;
    }
    
    /**
     * Execute crawling using the settings
     */
    public static function execute($tab, $update_meta = false) {
        global $MEDIACRAWLER_CONFIG, $wpdb;
        $endl = (PHP_SAPI == 'cli') ? "\n" : "<br/>";
       
        require_once('config.php');
        require_once('mdcrawler.class.php');
        $crawler = new MDCrawler();
        
        // crawler default values
        $upload = wp_upload_dir();
        $thumbnailDir = $upload['basedir'].'/'.$tab;
        if (!file_exists($thumbnailDir)) { mkdir($thumbnailDir); }
        $crawler->setThumbnailSizes($MEDIACRAWLER_CONFIG['thumbnail_sizes']);
        $crawler->setThumbBaseDir($thumbnailDir);
        
        $crawler->enableCookieHandling(true);
        $crawler->setTrafficLimit($MEDIACRAWLER_CONFIG['traffic_limit']);
        $crawler->setContentSizeLimit($MEDIACRAWLER_CONFIG['content_limit']);
        
        // crawler main settings
        $option = get_option('mediacrawler_base_url', array());
        if (isset($option[$tab])) $baseUrl = $option[$tab];
        $crawler->setURL($baseUrl);
        
        $option = get_option('mediacrawler_follow_mode', array());
        if (isset($option[$tab])) $followMode = $option[$tab];
        $crawler->setFollowMode($followMode);
        
        $option = get_option('mediacrawler_content_types', array());
        if (isset($option[$tab])) $contentTypes = explode(',', $option[$tab]);
        foreach ($contentTypes as $type) { $crawler->addContentType(trim($type)); }
        
        $option = get_option('mediacrawler_exclude_files', array());
        if (isset($option[$tab])) $excludeFiles = explode(',', $option[$tab]);
        foreach ($excludeFiles as $file) { $crawler->addExcludeFile(trim($file)); }
        
        // crawler auth settings
        $option = get_option('mediacrawler_auth_user', array());
        if (isset($option[$tab])) $authUser = $option[$tab];
        $option = get_option('mediacrawler_auth_pass', array());
        if (isset($option[$tab])) $authPass = $option[$tab];
        if (isset($authUser) && !empty($authUser) && isset($authPass) && !empty($authPass)) {
            $authUrl = '#'.str_replace('.', '\.', $baseUrl).'#';
            $crawler->addBasicAuthentication($authUrl, $authUser, $authPass);
        }
        
        // crawler metadata settings
        $template = array();
        $option = get_option('mediacrawler_meta_file', array());
        if (isset($option[$tab])) $metaFile = trim($option[$tab]);
        if (isset($metaFile) && !empty($metaFile)) { $template[$metaFile] = '#file'; }
        
        $option = get_option('mediacrawler_meta_path', array());
        if (isset($option[$tab])) $metaPath = trim($option[$tab]);
        if (isset($metaPath) && !empty($metaPath)) { $template[$metaPath] = '#path'; }
        
        $option = get_option('mediacrawler_meta_url', array());
        if (isset($option[$tab])) $metaUrl = trim($option[$tab]);
        if (isset($metaUrl) && !empty($metaUrl)) { $template[$metaUrl] = '#url'; }
        
        $option = get_option('mediacrawler_meta_content_type', array());
        if (isset($option[$tab])) $metaContentType = trim($option[$tab]);
        if (isset($metaContentType) && !empty($metaContentType)) { $template[$metaContentType] = '#content_type'; }
 
        $option = get_option('mediacrawler_meta_content_length', array());
        if (isset($option[$tab])) $metaContentLength = trim($option[$tab]);
        if (isset($metaContentLength) && !empty($metaContentLength)) { $template[$metaContentLength] = '#content_length'; }
        
        $option = get_option('mediacrawler_meta_referer_url', array());
        if (isset($option[$tab])) $metaRefererUrl = trim($option[$tab]);
        if (isset($metaRefererUrl) && !empty($metaRefererUrl)) { $template[$metaRefererUrl] = '#referer_url'; }
        
        $option = get_option('mediacrawler_meta_refering_linktext', array());
        if (isset($option[$tab])) $metaRefLinktext = trim($option[$tab]);
        if (isset($metaRefLinktext) && !empty($metaRefLinktext)) { $template[$metaRefLinktext] = '#refering_linktext'; }
        
        $counts = get_option('mediacrawler_meta_xpath_counts', array($tab=>0));
        $paths = get_option('mediacrawler_meta_xpath_paths', array($tab=>''));
        $paths = explode(self::glue, $paths[$tab]);
        $metadatas = get_option('mediacrawler_meta_xpath_metadatas', array($tab=>''));
        $metadatas = explode(self::glue, $metadatas[$tab]);
        for ($i = 0; $i < $counts[$tab]; $i++) {
            $template[trim($metadatas[$i])] = '['.trim($paths[$i]).']';
        }
        
        $counts = get_option('mediacrawler_meta_const_counts', array($tab=>0));
        $values = get_option('mediacrawler_meta_const_values', array($tab=>''));
        $values = explode(self::glue, $values[$tab]); 
        $metadatas = get_option('mediacrawler_meta_const_metadatas', array($tab=>''));
        $metadatas = explode(self::glue, $metadatas[$tab]);
        for ($i = 0; $i < $counts[$tab]; $i++) {
            $template[trim($metadatas[$i])] = '['.trim($values[$i]).']';
        }
        
        $crawler->setMetadataTemplate($template);
        
        // crawler relation settings
        $option = get_option('mediacrawler_relation_type', array());
        if (isset($option[$tab]) && !empty($option[$tab])) { $relationType = $option[$tab]; }
        if (isset($relationType) && $relationType == 'ignore') { $crawler->setMergeVersions(true); }
        
        $option = get_option('mediacrawler_relation_files', array());
        if (isset($option[$tab])) $relationFiles = array_map('trim', explode(',', $option[$tab]));
        $crawler->setMultipleVersions(array('#file'=>$relationFiles));
        
        // do crawler
        $crawler->go();
        $metadatas = $crawler->getMetadatas();
        $relations = $crawler->getRelations();
        $thumbnails = $crawler->getThumbnails();
        $modifieds = $crawler->getModifieds();
        
        // save posts with metadatas
        $postReplace = 'yes';
        $option = get_option('mediacrawler_post_replace', array());
        if (isset($option[$tab])) { $postReplace = $option[$tab]; }

        $option = get_option('mediacrawler_post_type', array());
        if (isset($option[$tab])) { $postType = $option[$tab]; }
        
        $option = get_option('mediacrawler_post_status', array());
        if (isset($option[$tab])) { $postStatus = $option[$tab]; }
        
        $option = get_option('mediacrawler_post_title', array());
        if (isset($option[$tab])) { $postTitle = $option[$tab]; }
        
        $option = get_option('mediacrawler_post_content', array());
        if (isset($option[$tab])) { $postContent = $option[$tab]; }
        
        foreach ($metadatas as $id=>$metadata) {
            $post = array();
            $table_name = $wpdb->prefix.'mediacrawler_resources';
            $row = $wpdb->get_row("SELECT * FROM {$table_name} WHERE tab = '{$tab}' AND url = '{$id}'", ARRAY_A);
            if (isset($row) && isset($row['post_id'])) {
                $post = $wpdb->get_row("SELECT * FROM {$wpdb->posts} WHERE ID = ".$row['post_id'], ARRAY_A);
            }
            
            if ($update_meta && isset($post['post_modified']) && $modifieds[$id] <= strtotime($post['post_modified'])) { continue; }
            
            $post['post_type'] = $postType;
            $post['post_title'] = $postTitle;
            $post['post_status'] = $postStatus;
            $post['post_content'] = $postContent;
            if ($postReplace == 'yes') {
                $post['post_title'] = self::do_shortcode($postTitle, $metadata->properties);
                $post['post_content'] = self::do_shortcode($postContent, $metadata->properties);
            }
            if ($postType == 'attachment') {
                $post['guid'] = $id;
                $post['post_status'] = 'inherit';
                if (isset($metaContentType) && !empty($metaContentType)) {
                    $post['post_mime_type'] = array_shift(array_values($metadata->properties[$metaContentType]));
                }
            }
            
            if (isset($post['ID']) && !empty($post['ID'])) {
                $post_id = wp_update_post($post);
            } else {
                $post_id = wp_insert_post($post);
                $wpdb->insert($table_name, array('tab'=>$tab, 'url'=>$id, 'post_id'=>$post_id));
            }
            
            foreach ($metadata->properties as $meta_key=>$meta_values) {
                delete_metadata('post', $post_id, $meta_key);
                foreach ($meta_values as $meta_value) {
                    add_metadata('post', $post_id, $meta_key, trim($meta_value));
                }
            }
            
            // thumbnail
            if ($postType == 'attachment' && isset($thumbnails[$id])) {
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                $attach_data = self::generate_attachment_metadata($id, $thumbnails[$id]);
                wp_update_attachment_metadata($post_id, $attach_data);
            }
        }
        
        // log messages
        $report = $crawler->getProcessReport();
        $log = "Log of mediacrawler - data : ".date("Y-m-d H:i:s", time()).$endl.$endl;
        $log .= "Metadatas found: ".count($metadatas);
        $log .= "Relations of metadatas found: ".count($relations);
        $log .= "Links followed: ".$report->links_followed.$endl;
        $log .= "Documents received: ".$report->files_received.$endl;
        $log .= "Bytes received: ".$report->bytes_received." bytes".$endl;
        $log .= "Process runtime: ".$report->process_runtime." sec".$endl;
        
        return $log;
    }
    
    /**
     * Delete records from mediacrawler_resources
     */
    public static function delete_post($post_id) {
        global $wpdb;
        $table_name = $wpdb->prefix.'mediacrawler_resources';
        $wpdb->query($wpdb->prepare('DELETE FROM '.$table_name.' WHERE post_id = %d', $post_id));
    }

    /**
     * Update hourly media crawler resources
     */
    public static function update_hourly() {
        self::update_crawler('hourly');
    }
    
    private static function update_crawler($current_interval) {
        $option = get_option('mediacrawler_scheduled_interval', array());
        $option_update = get_option('mediacrawler_update_metadata', array());
        foreach ($option as $tab=>$interval) {
            if ($interval == trim($current_interval) && trim($option_udpate[$tab]) != 'never') {
                $update_meta = trim($option_update[$tab]) == 'update' ? true: false;
                self::execute($tab, $update_meta);
            }
        }
    }
    
    /**
     * Update twicedaily media crawler resources
     */
    public static function update_twicedaily() {
        self::update_crawler('twicedaily');
    }
    
    /**
     * Update daily media crawler resources
     */
    public static function update_daily() {
        self::update_crawler('daily');
    }
    
}

//-- install plugin
register_activation_hook(__FILE__, array('MediaCrawler', 'install'));
register_deactivation_hook(__FILE__, array('MediaCrawler', 'uninstall'));

//-- init plugin
add_filter('init', array('MediaCrawler', 'init'));

?>
