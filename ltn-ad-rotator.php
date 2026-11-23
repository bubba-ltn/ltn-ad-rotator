<?php
/**
 * Plugin Name: LTN Ad Rotator
 * Description: Display and rotate global ads with start/end dates, media library integration, per-campaign reset, and click tracking.
 * Version: 1.6.1
 * Author: Love Thy Nerd
 */

// 1) Include the library
require_once plugin_dir_path( __FILE__ ) . 'plugin-update-checker/plugin-update-checker.php';

// 2) Point it at your GitHub repo
$updateChecker = Puc_v4_Factory::buildUpdateChecker(
    'https://api.github.com/repos/bubba-ltn/ltn-ad-rotator', // ← change this
    plugin_dir_path( __FILE__ ),                            // full path to this plugin file
    'ltn-ad-rotator'                     // this plugin’s slug (folder/file name)
);

// 3) If you want to track a branch other than “main”, uncomment & adjust:
// $updateChecker->setBranch('main');

if (!defined('ABSPATH')) exit;

// Enqueue admin assets
add_action('admin_enqueue_scripts', function($hook) {
    if ($hook === 'toplevel_page_ltn-ad-rotator') {
        wp_enqueue_style('ltn-ad-rotator-admin-css', plugin_dir_url(__FILE__) . 'admin-style.css');
        wp_enqueue_media();
        wp_enqueue_script('ltn-ad-rotator-admin-js', plugin_dir_url(__FILE__) . 'admin.js', ['jquery'], null, true);
    }
});

// Add top-level menu
add_action('admin_menu', function() {
    add_menu_page('LTN Ad Rotator','LTN Ad Rotator','manage_options','ltn-ad-rotator','ltn_render_ad_settings_page','dashicons-format-image',60);
});

// Click tracking
add_action('init', function() {
    if (isset($_GET['ltn_ad_click'])) {
        $key = sanitize_text_field($_GET['ltn_ad_click']);
        $map = ['ad1'=>'ltn_ad1_link','ad2'=>'ltn_ad2_link','default'=>'ltn_default_ad_link'];
        if (isset($map[$key])) {
            $url = get_option($map[$key]); $count_key = $key==='default'?'ltn_default_ad_clicks':"ltn_{$key}_clicks";
            update_option($count_key, (int)get_option($count_key,0)+1);
            if ($url) { wp_redirect(esc_url_raw($url)); exit; }
        }
    }
});

// Determine active ad
function ltn_get_active_ad() {
    $today = date('Y-m-d');
    for ($i=1;$i<=2;$i++){
        $img=get_option("ltn_ad{$i}_image"); $start=get_option("ltn_ad{$i}_start"); $end=get_option("ltn_ad{$i}_end");
        if ($img && $start && $end && $today>=$start && $today<=$end) {
            return ['key'=>"ad{$i}",'image'=>$img,'link'=>get_option("ltn_ad{$i}_link")];
        }
    }
    return ['key'=>'default','image'=>get_option('ltn_default_ad_image'),'link'=>get_option('ltn_default_ad_link')];
}

// Render settings page
function ltn_render_ad_settings_page() {
    echo '<div class="wrap"><h1>LTN Ad Rotator</h1>';
    $active = ltn_get_active_ad();
    if ($active['image']) {
        echo '<h2>Currently Active Ad</h2><div class="ltn-ad-preview-container">';
        echo '<img src="'.esc_url($active['image']).'" class="ltn-ad-preview-img"><div class="ltn-ad-overlay">AD</div></div>';
    }
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        foreach(['1','2'] as $i){
            update_option("ltn_ad{$i}_image",sanitize_text_field($_POST["ltn_ad{$i}_image"]));
            update_option("ltn_ad{$i}_link",esc_url_raw($_POST["ltn_ad{$i}_link"]));
            update_option("ltn_ad{$i}_start",sanitize_text_field($_POST["ltn_ad{$i}_start"]));
            update_option("ltn_ad{$i}_end",sanitize_text_field($_POST["ltn_ad{$i}_end"]));
        }
        update_option('ltn_default_ad_image',sanitize_text_field($_POST['ltn_default_ad_image']));
        update_option('ltn_default_ad_link',esc_url_raw($_POST['ltn_default_ad_link']));
        foreach(['ad1','ad2','default'] as $k){
            if(isset($_POST["reset_{$k}_clicks"])){
                $opt=$k==='default'?'ltn_default_ad_clicks':"ltn_{$k}_clicks";
                update_option($opt,0);
                echo '<div class="updated"><p>Clicks reset for '.strtoupper($k).'.</p></div>';
            }
        }
    }
    echo '<form method="post"><table class="form-table">';
    for($i=1;$i<=2;$i++){
        $img=get_option("ltn_ad{$i}_image"); $link=get_option("ltn_ad{$i}_link"); 
        $start=get_option("ltn_ad{$i}_start"); $end=get_option("ltn_ad{$i}_end");
        $clicks=(int)get_option("ltn_ad{$i}_clicks",0);
        echo "<tr><th colspan='2'><h2>Ad Campaign #{$i}</h2></th></tr>";
        echo "<tr><td colspan='2'><div class='ltn-ad-preview-container'>";
        if($img) echo "<img src='".esc_url($img)."' class='ltn-ad-preview-img'><div class='ltn-ad-overlay'>AD</div>";
        echo "</div></td></tr>";
        echo "<tr><th>Image URL</th><td><input type='text' id='ltn_ad{$i}_image' name='ltn_ad{$i}_image' value='".esc_attr($img)."' class='regular-text' /> <button class='button ltn-select-image' data-target='ltn_ad{$i}_image'>Select Image</button></td></tr>";
        echo "<tr><th>Link URL</th><td><input type='text' name='ltn_ad{$i}_link' value='".esc_attr($link)."' class='regular-text' /></td></tr>";
        echo "<tr><th>Start Date</th><td><input type='date' name='ltn_ad{$i}_start' value='".esc_attr($start)."' /></td></tr>";
        echo "<tr><th>End Date</th><td><input type='date' name='ltn_ad{$i}_end' value='".esc_attr($end)."' /></td></tr>";
        echo "<tr><th>Clicks</th><td>{$clicks} <input type='submit' name='reset_ad{$i}_clicks' class='button' value='Reset' /></td></tr>";
    }
    $dimg=get_option('ltn_default_ad_image'); $dlink=get_option('ltn_default_ad_link'); $dclicks=(int)get_option('ltn_default_ad_clicks',0);
    echo "<tr><th colspan='2'><h2>Default Ad</h2></th></tr>";
    echo "<tr><td colspan='2'><div class='ltn-ad-preview-container'>";
    if($dimg) echo "<img src='".esc_url($dimg)."' class='ltn-ad-preview-img'><div class='ltn-ad-overlay'>AD</div>";
    echo "</div></td></tr>";
    echo "<tr><th>Image URL</th><td><input type='text' id='ltn_default_ad_image' name='ltn_default_ad_image' value='".esc_attr($dimg)."' class='regular-text' /> <button class='button ltn-select-image' data-target='ltn_default_ad_image'>Select Image</button></td></tr>";
    echo "<tr><th>Link URL</th><td><input type='text' name='ltn_default_ad_link' value='".esc_attr($dlink)."' class='regular-text' /></td></tr>";
    echo "<tr><th>Clicks</th><td>{$dclicks} <input type='submit' name='reset_default_clicks' class='button' value='Reset' /></td></tr>";
    echo "</table><p><input type='submit' class='button-primary' value='Save Changes' /></p></form></div>";
}