<?php
/**
 * Plugin Name: Preet Song Catalog
 * Description: Song catalogue custom post type and lightweight responsive details.
 * Version: 0.2.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) { exit; }

add_action('init', function () {
    register_post_type('song', [
        'labels' => ['name' => 'Songs', 'singular_name' => 'Song'],
        'public' => true, 'show_in_rest' => true, 'rest_base' => 'songs',
        'has_archive' => true, 'rewrite' => ['slug' => 'songs'],
        'menu_icon' => 'dashicons-format-audio',
        'supports' => ['title', 'editor', 'excerpt', 'thumbnail', 'custom-fields']
    ]);

    $fields = ['artist','album','label','release_date','duration','source_url',
               'stream_url','artwork_url','external_id','rights_status'];
    foreach ($fields as $field) {
        register_post_meta('song', 'ps_' . $field, [
            'type' => 'string', 'single' => true,
            'show_in_rest' => ['schema' => ['type' => 'string', 'context' => ['view','edit']]],
            'sanitize_callback' => in_array($field, ['source_url','stream_url','artwork_url'], true)
                ? 'esc_url_raw' : 'sanitize_text_field',
            'auth_callback' => function () { return current_user_can('edit_posts'); }
        ]);
    }
});

add_filter('the_content', function ($content) {
    if (!is_singular('song') || !in_the_loop() || !is_main_query()) return $content;
    $id = get_the_ID();
    $fields = [
        'Artist' => get_post_meta($id, 'ps_artist', true),
        'Album' => get_post_meta($id, 'ps_album', true),
        'Label' => get_post_meta($id, 'ps_label', true),
        'Released' => get_post_meta($id, 'ps_release_date', true),
        'Duration' => get_post_meta($id, 'ps_duration', true)
    ];
    $html = '<section class="ps-song-panel"><h2>Song information</h2><dl>';
    foreach ($fields as $label => $value) {
        if ($value !== '') $html .= '<div><dt>'.esc_html($label).'</dt><dd>'.esc_html($value).'</dd></div>';
    }
    $html .= '</dl><div class="ps-song-actions">';
    $stream = get_post_meta($id, 'ps_stream_url', true);
    $source = get_post_meta($id, 'ps_source_url', true);
    if ($stream) $html .= '<a class="ps-button ps-primary" href="'.esc_url($stream).'" rel="nofollow noopener" target="_blank">Listen at provider</a>';
    if ($source) $html .= '<a class="ps-button" href="'.esc_url($source).'" rel="nofollow noopener" target="_blank">Original catalogue page</a>';
    $html .= '</div></section>';
    return $content . $html;
});

add_shortcode('preet_song_catalog', function ($atts) {
    $atts = shortcode_atts(['posts_per_page' => 24], $atts);
    $q = new WP_Query(['post_type'=>'song','post_status'=>'publish',
        'posts_per_page'=>max(1,min(48,(int)$atts['posts_per_page']))]);
    if (!$q->have_posts()) return '<p>No songs published yet.</p>';
    $html = '<div class="ps-song-grid">';
    while ($q->have_posts()) { $q->the_post();
        $artist = get_post_meta(get_the_ID(), 'ps_artist', true);
        $html .= '<article class="ps-song-card"><h3><a href="'.esc_url(get_permalink()).'">'.esc_html(get_the_title()).'</a></h3>';
        if ($artist) $html .= '<p>'.esc_html($artist).'</p>';
        $html .= '<a class="ps-button" href="'.esc_url(get_permalink()).'">View song</a></article>';
    }
    wp_reset_postdata();
    return $html.'</div>';
});

add_action('wp_enqueue_scripts', function () {
    wp_register_style('preet-song-catalog', false, [], '0.2.0');
    wp_enqueue_style('preet-song-catalog');
    wp_add_inline_style('preet-song-catalog', '
      .ps-song-panel{margin:28px 0;padding:22px;border:1px solid #e5e7eb;border-radius:16px;background:#fff}
      .ps-song-panel dl{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
      .ps-song-panel dl div{padding:10px;background:#f8fafc;border-radius:10px}
      .ps-song-panel dt{font-size:12px;color:#64748b}.ps-song-panel dd{margin:4px 0 0;font-weight:600}
      .ps-song-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:16px}
      .ps-button{display:inline-flex;padding:11px 16px;border-radius:9px;background:#eef2ff;color:#1e293b;text-decoration:none;font-weight:600}
      .ps-primary{background:#16a34a;color:#fff}
      .ps-song-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:16px}
      .ps-song-card{padding:16px;border:1px solid #e5e7eb;border-radius:14px;background:#fff}
    ');
});
