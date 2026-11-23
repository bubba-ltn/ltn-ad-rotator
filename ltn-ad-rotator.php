<?php
/**
 * Plugin Name: LTN Ad Rotator
 * Description: Display and rotate global ads with start/end dates, media library integration, per-campaign reset, click tracking, and alternate creatives per campaign.
 * Version: 1.7.0
 * Author: Love Thy Nerd
 */

if (!defined('ABSPATH')) exit;

// GitHub auto-updates (Plugin Update Checker v5)
require_once plugin_dir_path( __FILE__ ) . 'plugin-update-checker/plugin-update-checker.php';

$ltn_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/bubba-ltn/ltn-ad-rotator/',
    __FILE__,
    'ltn-ad-rotator'
);
$ltn_update_checker->setBranch('main');

// Enqueue admin assets
add_action('admin_enqueue_scripts', function($hook) {
    if ($hook === 'toplevel_page_ltn-ad-rotator') {
        wp_enqueue_style('ltn-ad-rotator-admin-css', plugin_dir_url(__FILE__) . 'admin-style.css', [], '1.7.0');
        wp_enqueue_media();
        wp_enqueue_script('ltn-ad-rotator-admin-js', plugin_dir_url(__FILE__) . 'admin.js', ['jquery'], '1.7.0', true);
    }
});

// Add top-level menu
add_action('admin_menu', function() {
    add_menu_page('LTN Ad Rotator','LTN Ad Rotator','manage_options','ltn-ad-rotator','ltn_render_ad_settings_page','dashicons-format-image',60);
});

/* ------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------ */

// The three ad "slots": two dated campaigns plus the default ad.
function ltn_ad_keys() {
    return ['ad1', 'ad2', 'default'];
}

// Option name for a slot's field (image, link, start, end, clicks, alts).
// Keeps the original option names so existing data is untouched.
function ltn_ad_opt($key, $field) {
    if ($key === 'default') {
        return $field === 'alts' ? 'ltn_default_alts' : "ltn_default_ad_{$field}";
    }
    return "ltn_{$key}_{$field}";
}

// Alternates for a slot: [ id => ['image'=>, 'link'=>, 'clicks'=>] ]
function ltn_get_alts($key) {
    $alts = get_option(ltn_ad_opt($key, 'alts'), []);
    return is_array($alts) ? $alts : [];
}

/* ------------------------------------------------------------------
 * Click tracking
 * ------------------------------------------------------------------ */
add_action('init', function() {
    if (!isset($_GET['ltn_ad_click'])) return;
    $key = sanitize_text_field(wp_unslash($_GET['ltn_ad_click']));
    $url = '';

    if (in_array($key, ltn_ad_keys(), true)) {
        // Main creative of a slot
        $url = get_option(ltn_ad_opt($key, 'link'));
        $count_key = ltn_ad_opt($key, 'clicks');
        update_option($count_key, (int) get_option($count_key, 0) + 1);
    } elseif (preg_match('/^(ad1|ad2|default)_alt_([a-z0-9]+)$/', $key, $m)) {
        // Alternate creative, e.g. ad1_alt_k3j9x2ab
        $alts = ltn_get_alts($m[1]);
        if (!isset($alts[$m[2]])) return;
        $alts[$m[2]]['clicks'] = (int) ($alts[$m[2]]['clicks'] ?? 0) + 1;
        update_option(ltn_ad_opt($m[1], 'alts'), $alts);
        $url = !empty($alts[$m[2]]['link']) ? $alts[$m[2]]['link'] : get_option(ltn_ad_opt($m[1], 'link'));
    } else {
        return;
    }

    if ($url) { wp_redirect(esc_url_raw($url)); exit; }
});

/* ------------------------------------------------------------------
 * Choose which ad to show
 * ------------------------------------------------------------------ */

// Pick one creative for a slot: the main ad or one of its alternates, at random.
function ltn_pick_creative($key) {
    $main = [
        'key'   => $key,
        'image' => get_option(ltn_ad_opt($key, 'image')),
        'link'  => get_option(ltn_ad_opt($key, 'link')),
    ];
    $pool = [];
    if ($main['image']) $pool[] = $main;
    foreach (ltn_get_alts($key) as $id => $alt) {
        if (empty($alt['image'])) continue;
        $pool[] = [
            'key'   => "{$key}_alt_{$id}",
            'image' => $alt['image'],
            'link'  => !empty($alt['link']) ? $alt['link'] : $main['link'],
        ];
    }
    if (!$pool) return $main;
    return $pool[array_rand($pool)];
}

// Determine active ad
function ltn_get_active_ad() {
    $today = date('Y-m-d');
    foreach (['ad1', 'ad2'] as $key) {
        $img   = get_option(ltn_ad_opt($key, 'image'));
        $start = get_option(ltn_ad_opt($key, 'start'));
        $end   = get_option(ltn_ad_opt($key, 'end'));
        if ($img && $start && $end && $today >= $start && $today <= $end) {
            return ltn_pick_creative($key);
        }
    }
    return ltn_pick_creative('default');
}

/* ------------------------------------------------------------------
 * Settings page
 * ------------------------------------------------------------------ */

// Save the settings form. Returns a list of notice strings.
function ltn_handle_settings_post() {
    $notices = [];
    if (!current_user_can('manage_options')) return $notices;
    check_admin_referer('ltn_save_ads');
    $p = wp_unslash($_POST);

    foreach (['1', '2'] as $i) {
        update_option("ltn_ad{$i}_image", sanitize_text_field($p["ltn_ad{$i}_image"] ?? ''));
        update_option("ltn_ad{$i}_link",  esc_url_raw($p["ltn_ad{$i}_link"] ?? ''));
        update_option("ltn_ad{$i}_start", sanitize_text_field($p["ltn_ad{$i}_start"] ?? ''));
        update_option("ltn_ad{$i}_end",   sanitize_text_field($p["ltn_ad{$i}_end"] ?? ''));
    }
    update_option('ltn_default_ad_image', sanitize_text_field($p['ltn_default_ad_image'] ?? ''));
    update_option('ltn_default_ad_link',  esc_url_raw($p['ltn_default_ad_link'] ?? ''));

    // Alternates: whatever rows were submitted become the new list
    // (rows removed in the browser simply aren't submitted).
    foreach (ltn_ad_keys() as $key) {
        $existing = ltn_get_alts($key);
        $posted = (isset($p["ltn_{$key}_alts"]) && is_array($p["ltn_{$key}_alts"])) ? $p["ltn_{$key}_alts"] : [];
        $clean = [];
        foreach ($posted as $posted_key => $row) {
            if (!is_array($row)) continue;
            $image = sanitize_text_field($row['image'] ?? '');
            if ($image === '') continue; // an alternate needs an image
            $posted_id = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $posted_key));
            $id = $posted_id;
            if ($id === '' || !isset($existing[$id])) {
                // New alternate: give it a fresh, stable id
                do { $id = strtolower(wp_generate_password(8, false)); } while (isset($existing[$id]) || isset($clean[$id]));
                $clicks = 0;
            } else {
                $clicks = (int) ($existing[$id]['clicks'] ?? 0);
            }
            // Per-alternate "Reset" button (only existing alternates have one)
            if (isset($existing[$posted_id]) && isset($p['reset_alt'][$key][$posted_id])) {
                $clicks = 0;
                $notices[] = 'Clicks reset for an alternate of ' . strtoupper($key) . '.';
            }
            $clean[$id] = [
                'image'  => $image,
                'link'   => esc_url_raw($row['link'] ?? ''),
                'clicks' => $clicks,
            ];
        }
        update_option(ltn_ad_opt($key, 'alts'), $clean);
    }

    // Main-ad click resets
    foreach (ltn_ad_keys() as $key) {
        if (isset($p["reset_{$key}_clicks"])) {
            update_option(ltn_ad_opt($key, 'clicks'), 0);
            $notices[] = 'Clicks reset for ' . strtoupper($key) . '.';
        }
    }
    return $notices;
}

// One alternate row. $id/$key are placeholders when rendering the JS template.
function ltn_alt_row_html($key, $id, $alt, $is_template = false) {
    $image  = $alt['image'] ?? '';
    $link   = $alt['link'] ?? '';
    $clicks = (int) ($alt['clicks'] ?? 0);
    $field  = "ltn_{$key}_alts[{$id}]";
    $dom_id = "ltn_{$key}_alt_{$id}_image";

    $h  = '<div class="ltn-alt-row">';
    $h .= '<div class="ltn-alt-fields">';
    $h .= "<input type='text' id='" . esc_attr($dom_id) . "' name='" . esc_attr($field) . "[image]' value='" . esc_attr($image) . "' class='regular-text' placeholder='Image URL' /> ";
    $h .= "<button type='button' class='button ltn-select-image' data-target='" . esc_attr($dom_id) . "'>Select Image</button> ";
    $h .= "<input type='text' name='" . esc_attr($field) . "[link]' value='" . esc_attr($link) . "' class='regular-text' placeholder='Link URL (blank = use campaign link)' /> ";
    if (!$is_template) {
        $h .= "<span class='ltn-alt-clicks'>Clicks: {$clicks}</span> ";
        $h .= "<button type='submit' name='reset_alt[" . esc_attr($key) . "][" . esc_attr($id) . "]' value='1' class='button'>Reset</button> ";
    }
    $h .= "<button type='button' class='button-link button-link-delete ltn-remove-alt'>Remove</button>";
    $h .= '</div>';
    if ($image) {
        $h .= "<img src='" . esc_url($image) . "' class='ltn-alt-thumb' alt='' />";
    }
    $h .= '</div>';
    return $h;
}

// The "Alternates" table row for a slot.
function ltn_render_alts_row($key) {
    echo '<tr><th>Alternates</th><td>';
    echo "<p class='description'>Extra images for this " . ($key === 'default' ? 'ad' : 'campaign') . ". Visitors see the main ad or one alternate at random. Removed alternates are deleted when you click Save Changes.</p>";
    echo "<div class='ltn-alts' data-key='" . esc_attr($key) . "'>";
    foreach (ltn_get_alts($key) as $id => $alt) {
        echo ltn_alt_row_html($key, $id, $alt);
    }
    echo '</div>';
    echo "<p><button type='button' class='button ltn-add-alt' data-key='" . esc_attr($key) . "'>Add alternate</button></p>";
    echo '</td></tr>';
}

// Render settings page
function ltn_render_ad_settings_page() {
    echo '<div class="wrap"><h1>LTN Ad Rotator</h1>';

    // Save first so the page below shows the new values
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        foreach (ltn_handle_settings_post() as $notice) {
            echo '<div class="updated"><p>' . esc_html($notice) . '</p></div>';
        }
    }

    $active = ltn_get_active_ad();
    if ($active['image']) {
        echo '<h2>Currently Active Ad</h2><div class="ltn-ad-preview-container">';
        echo '<img src="'.esc_url($active['image']).'" class="ltn-ad-preview-img"><div class="ltn-ad-overlay">AD</div></div>';
    }

    echo '<form method="post">';
    wp_nonce_field('ltn_save_ads');
    echo '<table class="form-table">';
    for ($i = 1; $i <= 2; $i++) {
        $key = "ad{$i}";
        $img = get_option("ltn_ad{$i}_image"); $link = get_option("ltn_ad{$i}_link");
        $start = get_option("ltn_ad{$i}_start"); $end = get_option("ltn_ad{$i}_end");
        $clicks = (int) get_option("ltn_ad{$i}_clicks", 0);
        echo "<tr><th colspan='2'><h2>Ad Campaign #{$i}</h2></th></tr>";
        echo "<tr><td colspan='2'><div class='ltn-ad-preview-container'>";
        if ($img) echo "<img src='".esc_url($img)."' class='ltn-ad-preview-img'><div class='ltn-ad-overlay'>AD</div>";
        echo "</div></td></tr>";
        echo "<tr><th>Image URL</th><td><input type='text' id='ltn_ad{$i}_image' name='ltn_ad{$i}_image' value='".esc_attr($img)."' class='regular-text' /> <button class='button ltn-select-image' data-target='ltn_ad{$i}_image'>Select Image</button></td></tr>";
        echo "<tr><th>Link URL</th><td><input type='text' name='ltn_ad{$i}_link' value='".esc_attr($link)."' class='regular-text' /></td></tr>";
        echo "<tr><th>Start Date</th><td><input type='date' name='ltn_ad{$i}_start' value='".esc_attr($start)."' /></td></tr>";
        echo "<tr><th>End Date</th><td><input type='date' name='ltn_ad{$i}_end' value='".esc_attr($end)."' /></td></tr>";
        echo "<tr><th>Clicks</th><td>{$clicks} <input type='submit' name='reset_ad{$i}_clicks' class='button' value='Reset' /></td></tr>";
        ltn_render_alts_row($key);
    }
    $dimg = get_option('ltn_default_ad_image'); $dlink = get_option('ltn_default_ad_link'); $dclicks = (int) get_option('ltn_default_ad_clicks', 0);
    echo "<tr><th colspan='2'><h2>Default Ad</h2></th></tr>";
    echo "<tr><td colspan='2'><div class='ltn-ad-preview-container'>";
    if ($dimg) echo "<img src='".esc_url($dimg)."' class='ltn-ad-preview-img'><div class='ltn-ad-overlay'>AD</div>";
    echo "</div></td></tr>";
    echo "<tr><th>Image URL</th><td><input type='text' id='ltn_default_ad_image' name='ltn_default_ad_image' value='".esc_attr($dimg)."' class='regular-text' /> <button class='button ltn-select-image' data-target='ltn_default_ad_image'>Select Image</button></td></tr>";
    echo "<tr><th>Link URL</th><td><input type='text' name='ltn_default_ad_link' value='".esc_attr($dlink)."' class='regular-text' /></td></tr>";
    echo "<tr><th>Clicks</th><td>{$dclicks} <input type='submit' name='reset_default_clicks' class='button' value='Reset' /></td></tr>";
    ltn_render_alts_row('default');
    echo "</table><p><input type='submit' class='button-primary' value='Save Changes' /></p></form>";

    // Blank alternate row, cloned by admin.js when "Add alternate" is clicked
    echo '<template id="ltn-alt-template">' . ltn_alt_row_html('__KEY__', '__ID__', [], true) . '</template>';
    echo '</div>';
}
