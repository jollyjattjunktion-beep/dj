<?php
/**
 * Plugin Name: JattBeat Song Catalog
 * Description: JattBeat's WordPress-owned song catalogue, dark responsive storefront, importer API and authorized Render media bridge.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: jattbeat
 */
if (!defined('ABSPATH')) { exit; }

define('JATTBEAT_CATALOG_VERSION', '1.0.0');
define('JATTBEAT_DEFAULT_API_URL', 'https://dj-laqb.onrender.com');

function jattbeat_api_base_url() {
    $value = get_option('jattbeat_api_base_url', JATTBEAT_DEFAULT_API_URL);
    $value = esc_url_raw(trim((string) $value));
    $parts = wp_parse_url($value);
    if (!$parts || empty($parts['host']) || strtolower($parts['scheme'] ?? '') !== 'https') {
        return JATTBEAT_DEFAULT_API_URL;
    }
    return untrailingslashit($value);
}

function jattbeat_register_song_type() {
    register_post_type('song', [
        'labels' => [
            'name' => 'Songs',
            'singular_name' => 'Song',
            'add_new_item' => 'Add Song',
            'edit_item' => 'Edit Song',
            'view_item' => 'View Song',
            'search_items' => 'Search Songs',
        ],
        'public' => true,
        'show_in_rest' => true,
        'rest_base' => 'songs',
        'has_archive' => false,
        'rewrite' => ['slug' => 'song', 'with_front' => false],
        'menu_icon' => 'dashicons-format-audio',
        'supports' => ['title', 'editor', 'excerpt', 'thumbnail', 'custom-fields'],
    ]);

    $fields = [
        'artist', 'album', 'label', 'release_date', 'duration',
        'source_url', 'artwork_url', 'external_id', 'rights_status',
        'catalog_key', 'render_song_id',
    ];
    foreach ($fields as $field) {
        $is_url = in_array($field, ['source_url', 'artwork_url'], true);
        register_post_meta('song', 'ps_' . $field, [
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
            'sanitize_callback' => $is_url ? 'esc_url_raw' : 'sanitize_text_field',
            'auth_callback' => function () {
                return current_user_can('edit_posts');
            },
        ]);
    }
}
add_action('init', 'jattbeat_register_song_type');

register_activation_hook(__FILE__, function () {
    add_option('jattbeat_api_base_url', JATTBEAT_DEFAULT_API_URL);
    jattbeat_register_song_type();
    flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, 'flush_rewrite_rules');

/* -------------------------------------------------------------------------
 * Settings: where the short-lived media tokens are requested from.
 * The Render URL is not a media URL and contains no credentials.
 * ---------------------------------------------------------------------- */
add_action('admin_init', function () {
    register_setting('jattbeat_catalog_settings', 'jattbeat_api_base_url', [
        'type' => 'string',
        'sanitize_callback' => 'jattbeat_sanitize_api_url',
        'default' => JATTBEAT_DEFAULT_API_URL,
    ]);
});

function jattbeat_sanitize_api_url($value) {
    $value = esc_url_raw(trim((string) $value));
    $parts = wp_parse_url($value);
    if (!$parts || empty($parts['host']) || strtolower($parts['scheme'] ?? '') !== 'https') {
        add_settings_error(
            'jattbeat_api_base_url',
            'invalid_api_url',
            'Enter the HTTPS URL of your Render API service.'
        );
        return get_option('jattbeat_api_base_url', JATTBEAT_DEFAULT_API_URL);
    }
    return untrailingslashit($value);
}

add_action('admin_menu', function () {
    add_options_page(
        'JattBeat Catalog',
        'JattBeat Catalog',
        'manage_options',
        'jattbeat-catalog',
        'jattbeat_render_settings_page'
    );
});

function jattbeat_render_settings_page() {
    if (!current_user_can('manage_options')) { return; }
    ?>
    <div class="wrap">
      <h1>JattBeat Catalog</h1>
      <p>WordPress stores the song catalogue. Render remains the importer and approved media-token service.</p>
      <form method="post" action="options.php">
        <?php settings_fields('jattbeat_catalog_settings'); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="jattbeat_api_base_url">Render API base URL</label></th>
            <td>
              <input class="regular-text" type="url" id="jattbeat_api_base_url"
                name="jattbeat_api_base_url"
                value="<?php echo esc_attr(jattbeat_api_base_url()); ?>"
                placeholder="https://dj-laqb.onrender.com" required>
              <p class="description">Use the HTTPS Render service URL. Do not add /api or a trailing route.</p>
            </td>
          </tr>
        </table>
        <?php submit_button('Save API settings'); ?>
      </form>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * Authenticated importer endpoint. The importer uses an Application Password
 * for a dedicated WordPress Editor account. Matching uses a stable key based
 * on the Render song row ID, with legacy external-ID/slug fallbacks.
 * ---------------------------------------------------------------------- */
function jattbeat_find_catalog_post($catalog_key, $external_id, $legacy_slug) {
    $statuses = ['publish', 'draft', 'pending', 'private', 'future'];

    if ($catalog_key !== '') {
        $found = get_posts([
            'post_type' => 'song',
            'post_status' => $statuses,
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => 'ps_catalog_key',
            'meta_value' => $catalog_key,
        ]);
        if ($found) { return (int) $found[0]; }
    }

    if ($external_id !== '') {
        $found = get_posts([
            'post_type' => 'song',
            'post_status' => $statuses,
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => 'ps_external_id',
            'meta_value' => $external_id,
        ]);
        if ($found) { return (int) $found[0]; }
    }

    if ($legacy_slug !== '') {
        $legacy = get_page_by_path($legacy_slug, OBJECT, 'song');
        if ($legacy instanceof WP_Post && $legacy->post_status !== 'trash') {
            return (int) $legacy->ID;
        }
    }
    return 0;
}

function jattbeat_catalog_upsert(WP_REST_Request $request) {
    $data = $request->get_json_params();
    if (!is_array($data)) {
        return new WP_Error('jattbeat_invalid_payload', 'Expected a JSON song object.', ['status' => 400]);
    }

    $title = sanitize_text_field($data['title'] ?? '');
    $catalog_key = sanitize_text_field($data['catalog_key'] ?? '');
    $render_song_id = absint($data['render_song_id'] ?? 0);
    $external_id = sanitize_text_field($data['external_id'] ?? '');
    if ($title === '' || $catalog_key === '' || $render_song_id < 1) {
        return new WP_Error(
            'jattbeat_missing_identity',
            'Song title, catalog_key and render_song_id are required.',
            ['status' => 400]
        );
    }

    $artist = sanitize_text_field($data['artist'] ?? '');
    $legacy_slug = sanitize_title($title . ' ' . $artist);
    $post_id = jattbeat_find_catalog_post($catalog_key, $external_id, $legacy_slug);
    $is_new = !$post_id;
    $summary = sanitize_text_field($data['summary'] ?? '');
    if ($summary === '') {
        $summary = $artist !== '' ? $title . ' by ' . $artist . '.' : $title . '.';
    }

    if ($is_new) {
        $status = in_array(($data['status'] ?? 'draft'), ['draft', 'publish'], true)
            ? $data['status'] : 'draft';
        $post_id = wp_insert_post([
            'post_type' => 'song',
            'post_status' => $status,
            'post_title' => $title,
            'post_name' => $legacy_slug,
            'post_content' => '<p>' . esc_html($summary) . '</p>',
            'post_excerpt' => $summary,
        ], true);
        if (is_wp_error($post_id)) { return $post_id; }
        $post_id = (int) $post_id;
    } else {
        $existing = get_post($post_id);
        if (!$existing || $existing->post_type !== 'song') {
            return new WP_Error('jattbeat_bad_match', 'The matched WordPress song is unavailable.', ['status' => 409]);
        }

        // Preserve editor-written titles/content. Replace only blank or old
        // auto-generated content that exposed a direct provider MP3 URL.
        $patch = ['ID' => $post_id];
        $old_content = (string) $existing->post_content;
        if (trim($old_content) === '' || stripos($old_content, 'hd1.djjohal.com') !== false) {
            $patch['post_content'] = '<p>' . esc_html($summary) . '</p>';
        }
        if (trim((string) $existing->post_excerpt) === '') {
            $patch['post_excerpt'] = $summary;
        }
        if (!empty($patch['post_content']) || !empty($patch['post_excerpt'])) {
            $updated = wp_update_post($patch, true);
            if (is_wp_error($updated)) { return $updated; }
        }
    }

    $fields = [
        'artist' => $artist,
        'album' => sanitize_text_field($data['album'] ?? ''),
        'label' => sanitize_text_field($data['label'] ?? ''),
        'release_date' => sanitize_text_field($data['release_date'] ?? ''),
        'duration' => sanitize_text_field($data['duration'] ?? ''),
        'source_url' => esc_url_raw($data['source_url'] ?? ''),
        'artwork_url' => esc_url_raw($data['artwork_url'] ?? ''),
        'external_id' => $external_id,
        'rights_status' => sanitize_text_field($data['rights_status'] ?? ''),
        'catalog_key' => $catalog_key,
        'render_song_id' => (string) $render_song_id,
    ];

    $merge_missing = !array_key_exists('merge_missing', $data) || (bool) $data['merge_missing'];
    foreach ($fields as $key => $value) {
        $meta_key = 'ps_' . $key;
        $current = (string) get_post_meta($post_id, $meta_key, true);
        $is_identity = in_array($key, ['catalog_key', 'render_song_id'], true);
        $is_source = $key === 'source_url';
        if ($is_identity || $is_source || !$merge_missing || $current === '') {
            // Store even empty values so date-sorted queries do not omit songs
            // that have not yet received a release date.
            update_post_meta($post_id, $meta_key, $value);
        }
    }

    // Never persist the provider's raw MP3 URL in a public WordPress field.
    // Existing legacy meta is removed when this post is next synchronized.
    delete_post_meta($post_id, 'ps_stream_url');
    delete_post_meta($post_id, 'ps_audio_url');
    delete_transient('jattbeat_media_' . $post_id);

    return rest_ensure_response([
        'ok' => true,
        'created' => $is_new,
        'post_id' => $post_id,
        'permalink' => get_permalink($post_id),
        'status' => get_post_status($post_id),
    ]);
}

/* -------------------------------------------------------------------------
 * Public media bridge. It only serves published songs and requests a fresh,
 * short-lived signed token from Render when needed. Provider URLs are never
 * written to page markup or WordPress post metadata.
 * ---------------------------------------------------------------------- */
function jattbeat_song_media(WP_REST_Request $request) {
    $post_id = absint($request['post_id']);
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'song' || $post->post_status !== 'publish') {
        return new WP_Error('jattbeat_song_unavailable', 'This song is not publicly available.', ['status' => 404]);
    }

    $cached = get_transient('jattbeat_media_' . $post_id);
    if (is_array($cached) && !empty($cached['media_url'])) {
        return rest_ensure_response($cached);
    }

    $render_song_id = absint(get_post_meta($post_id, 'ps_render_song_id', true));
    if (!$render_song_id) {
        return new WP_Error('jattbeat_media_unavailable', 'No approved playback record is linked to this song.', ['status' => 404]);
    }

    $api_url = jattbeat_api_base_url() . '/api/songs/' . $render_song_id;
    $response = wp_remote_get($api_url, [
        'timeout' => 12,
        'redirection' => 0,
        'headers' => ['Accept' => 'application/json'],
    ]);
    if (is_wp_error($response)) {
        return new WP_Error('jattbeat_api_unavailable', 'The JattBeat media service is temporarily unavailable.', ['status' => 502]);
    }
    if (wp_remote_retrieve_response_code($response) !== 200) {
        return new WP_Error('jattbeat_media_missing', 'No approved playback link is currently available.', ['status' => 404]);
    }

    $payload = json_decode(wp_remote_retrieve_body($response), true);
    $media_url = is_array($payload) ? esc_url_raw($payload['media_url'] ?? '') : '';
    $download_url = is_array($payload) ? esc_url_raw($payload['download_url'] ?? '') : '';
    $api_host = strtolower((string) wp_parse_url(jattbeat_api_base_url(), PHP_URL_HOST));
    $media_host = strtolower((string) wp_parse_url($media_url, PHP_URL_HOST));
    $media_path = (string) wp_parse_url($media_url, PHP_URL_PATH);

    if (
        $media_url === '' ||
        $media_host !== $api_host ||
        strpos($media_path, '/media/') !== 0 ||
        strpos($download_url, $media_url . '?download=true') !== 0
    ) {
        return new WP_Error('jattbeat_invalid_media_url', 'The media service returned an invalid playback address.', ['status' => 502]);
    }

    $result = [
        'media_url' => $media_url,
        'download_url' => $download_url,
    ];
    // Render's token lifetime defaults to one hour; cache it for only four minutes.
    set_transient('jattbeat_media_' . $post_id, $result, 4 * MINUTE_IN_SECONDS);
    return rest_ensure_response($result);
}

add_action('rest_api_init', function () {
    register_rest_route('jattbeat/v1', '/catalog/upsert', [
        'methods' => 'POST',
        'callback' => 'jattbeat_catalog_upsert',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
    ]);
    register_rest_route('jattbeat/v1', '/media/(?P<post_id>\d+)', [
        'methods' => 'GET',
        'callback' => 'jattbeat_song_media',
        'permission_callback' => '__return_true',
        'args' => [
            'post_id' => ['validate_callback' => function ($value) { return absint($value) > 0; }],
        ],
    ]);
});

/* -------------------------------------------------------------------------
 * Storefront helpers and shortcodes. WordPress is the catalogue database;
 * every card/detail page is rendered from the Song custom post type.
 * ---------------------------------------------------------------------- */
function jattbeat_song_query($limit, $page = 1) {
    return new WP_Query([
        'post_type' => 'song',
        'post_status' => 'publish',
        'posts_per_page' => max(1, min(100, (int) $limit)),
        'paged' => max(1, (int) $page),
        'meta_key' => 'ps_release_date',
        'orderby' => ['meta_value' => 'DESC', 'date' => 'DESC'],
        'order' => 'DESC',
        'ignore_sticky_posts' => true,
        'no_found_rows' => false,
    ]);
}

function jattbeat_song_card($post_id) {
    $title = get_the_title($post_id);
    $artist = get_post_meta($post_id, 'ps_artist', true);
    $artwork = get_post_meta($post_id, 'ps_artwork_url', true);
    $permalink = get_permalink($post_id);
    ob_start();
    ?>
    <article class="jb-song-row" data-jb-card>
      <a class="jb-cover-link" href="<?php echo esc_url($permalink); ?>">
        <?php if ($artwork) : ?>
          <img class="jb-cover" src="<?php echo esc_url($artwork); ?>" alt="<?php echo esc_attr($title . ' cover artwork'); ?>" loading="lazy">
        <?php else : ?>
          <span class="jb-cover jb-cover-fallback" aria-hidden="true">♫</span>
        <?php endif; ?>
      </a>
      <div class="jb-song-copy">
        <a class="jb-song-title" href="<?php echo esc_url($permalink); ?>"><?php echo esc_html($title); ?></a>
        <span class="jb-song-artist"><?php echo esc_html($artist ?: 'Artist credit pending'); ?></span>
      </div>
      <button class="jb-play" type="button" data-jb-play="<?php echo esc_attr($post_id); ?>"
        data-jb-title="<?php echo esc_attr($title); ?>"
        data-jb-artist="<?php echo esc_attr($artist); ?>"
        data-jb-artwork="<?php echo esc_url($artwork); ?>"
        aria-label="<?php echo esc_attr('Play ' . $title); ?>">▶</button>
    </article>
    <?php
    return ob_get_clean();
}

function jattbeat_audio_dock() {
    return '<div class="jb-audio-dock" id="jbAudioDock" hidden>' .
        '<img id="jbDockCover" alt="">' .
        '<div class="jb-dock-track"><strong id="jbDockTitle"></strong><small id="jbDockArtist"></small></div>' .
        '<audio id="jbAudioPlayer" controls preload="none"></audio></div>';
}

function jattbeat_render_catalogue($mode = 'home', $atts = []) {
    $per_page = isset($atts['posts_per_page']) ? (int) $atts['posts_per_page'] : 15;
    $per_page = max(1, min(48, $per_page));
    $page = $mode === 'all' ? max(1, absint($_GET['jb_page'] ?? 1)) : 1;
    $query = jattbeat_song_query($mode === 'home' ? 15 : $per_page, $page);
    $posts = $query->posts;
    ob_start();
    ?>
    <div class="jb-shell">
      <header class="jb-topbar">
        <a class="jb-brand" href="<?php echo esc_url(home_url('/')); ?>" aria-label="JattBeat home">
          <span class="jb-brandmark">♫</span><span>Jatt<strong>Beat</strong></span>
        </a>
        <label class="jb-search"><span>⌕</span><input type="search" data-jb-search placeholder="Search songs or artists" aria-label="Search songs or artists"></label>
      </header>

      <?php if ($mode === 'home') : ?>
        <?php $hero_id = !empty($posts) ? (int) $posts[0]->ID : 0; ?>
        <section class="jb-hero">
          <div class="jb-hero-copy">
            <span class="jb-eyebrow">Fresh Punjabi Releases</span>
            <h1>Latest Punjabi<br>Beats</h1>
            <p>New singles, artists and sounds — all in one place.</p>
            <a class="jb-hero-cta" href="<?php echo esc_url(home_url('/songs/')); ?>">Explore Songs →</a>
          </div>
          <?php if ($hero_id) :
              $hero_art = get_post_meta($hero_id, 'ps_artwork_url', true);
              if ($hero_art) : ?>
                <img class="jb-hero-art" src="<?php echo esc_url($hero_art); ?>" alt="" loading="lazy">
              <?php else : ?>
                <div class="jb-hero-disc">JB</div>
              <?php endif;
          endif; ?>
        </section>
        <div class="jb-quicklinks">
          <a href="<?php echo esc_url(home_url('/songs/')); ?>">♫ Latest</a>
          <a href="<?php echo esc_url(home_url('/songs/')); ?>">♨ All Songs</a>
          <a href="<?php echo esc_url(home_url('/songs/')); ?>">♛ Browse</a>
        </div>
        <div class="jb-section-head"><h2>Latest Punjabi Songs</h2><a href="<?php echo esc_url(home_url('/songs/')); ?>">View All →</a></div>
      <?php else : ?>
        <div class="jb-section-head jb-all-heading"><h1>All Punjabi Songs</h1><span><?php echo esc_html(number_format_i18n($query->found_posts)); ?> songs</span></div>
      <?php endif; ?>

      <?php if (empty($posts)) : ?>
        <p class="jb-empty">No songs have been published yet. Please check back soon.</p>
      <?php else : ?>
        <div class="jb-song-list" data-jb-list>
          <?php foreach ($posts as $song_post) { echo jattbeat_song_card($song_post->ID); } ?>
        </div>
      <?php endif; ?>

      <?php if ($mode === 'all' && $query->max_num_pages > 1) : ?>
        <nav class="jb-pagination" aria-label="Song pages">
          <?php
          $base = remove_query_arg('jb_page');
          if ($page > 1) {
              echo '<a href="' . esc_url(add_query_arg('jb_page', $page - 1, $base)) . '">← Previous</a>';
          } else {
              echo '<span class="is-disabled">← Previous</span>';
          }
          echo '<span>Page ' . esc_html($page) . ' of ' . esc_html($query->max_num_pages) . '</span>';
          if ($page < $query->max_num_pages) {
              echo '<a href="' . esc_url(add_query_arg('jb_page', $page + 1, $base)) . '">Next →</a>';
          } else {
              echo '<span class="is-disabled">Next →</span>';
          }
          ?>
        </nav>
      <?php endif; ?>
      <?php echo jattbeat_audio_dock(); ?>
    </div>
    <?php
    wp_reset_postdata();
    return ob_get_clean();
}

add_shortcode('jattbeat_homepage', function () {
    return jattbeat_render_catalogue('home');
});
add_shortcode('jattbeat_all_songs', function ($atts) {
    return jattbeat_render_catalogue('all', shortcode_atts(['posts_per_page' => 15], $atts));
});
add_shortcode('preet_song_catalog', function ($atts) {
    return jattbeat_render_catalogue('all', shortcode_atts(['posts_per_page' => 24], $atts));
});

/* Song detail page: keep the source/rights attribution, but never output raw MP3 URLs. */
add_filter('the_content', function ($content) {
    if (!is_singular('song') || !in_the_loop() || !is_main_query()) { return $content; }

    $id = get_the_ID();
    $artist = get_post_meta($id, 'ps_artist', true);
    $album = get_post_meta($id, 'ps_album', true);
    $label = get_post_meta($id, 'ps_label', true);
    $release = get_post_meta($id, 'ps_release_date', true);
    $duration = get_post_meta($id, 'ps_duration', true);
    $artwork = get_post_meta($id, 'ps_artwork_url', true);
    $source = get_post_meta($id, 'ps_source_url', true);
    $rights = get_post_meta($id, 'ps_rights_status', true);

    ob_start();
    ?>
    <section class="jb-detail">
      <div class="jb-detail-top">
        <?php if ($artwork) : ?>
          <img class="jb-detail-cover" src="<?php echo esc_url($artwork); ?>" alt="<?php echo esc_attr(get_the_title($id) . ' cover artwork'); ?>">
        <?php endif; ?>
        <div class="jb-detail-heading">
          <span class="jb-eyebrow">Punjabi Single Track</span>
          <h1><?php echo esc_html(get_the_title($id)); ?></h1>
          <p class="jb-detail-artist"><?php echo esc_html($artist ?: 'Artist credit pending'); ?></p>
          <?php if ($release) : ?><p class="jb-detail-date">Released <?php echo esc_html($release); ?></p><?php endif; ?>
          <div class="jb-detail-actions">
            <button class="jb-action jb-action-primary" type="button"
              data-jb-play="<?php echo esc_attr($id); ?>"
              data-jb-title="<?php echo esc_attr(get_the_title($id)); ?>"
              data-jb-artist="<?php echo esc_attr($artist); ?>"
              data-jb-artwork="<?php echo esc_url($artwork); ?>">▶ Play Song</button>
            <button class="jb-action" type="button" data-jb-download="<?php echo esc_attr($id); ?>">↓ Download</button>
          </div>
        </div>
      </div>

      <div class="jb-detail-section">
        <h2>♫ Song Details</h2>
        <dl class="jb-info-grid">
          <div><dt>Song Title</dt><dd><?php echo esc_html(get_the_title($id)); ?></dd></div>
          <div><dt>Singer</dt><dd><?php echo esc_html($artist ?: 'Not provided'); ?></dd></div>
          <div><dt>Release Date</dt><dd><?php echo esc_html($release ?: 'Not provided'); ?></dd></div>
          <div><dt>Duration</dt><dd><?php echo esc_html($duration ?: 'Not provided'); ?></dd></div>
          <div><dt>Music Label</dt><dd><?php echo esc_html($label ?: 'Not provided'); ?></dd></div>
          <div><dt>Album</dt><dd><?php echo esc_html($album ?: 'Single Track'); ?></dd></div>
        </dl>
      </div>
      <?php if ($rights) : ?>
        <p class="jb-rights-note"><?php echo esc_html($rights); ?></p>
      <?php endif; ?>
      <?php if ($source) : ?>
        <p class="jb-source-credit">Source / catalogue credit:
          <a href="<?php echo esc_url($source); ?>" rel="nofollow noopener" target="_blank">View original source</a>
        </p>
      <?php endif; ?>
      <?php echo jattbeat_audio_dock(); ?>
    </section>
    <?php
    return $content . ob_get_clean();
}, 30);

/* Basic MusicRecording structured data for song URLs. */
add_action('wp_head', function () {
    if (!is_singular('song')) { return; }
    $id = get_queried_object_id();
    $artist = get_post_meta($id, 'ps_artist', true);
    $release = get_post_meta($id, 'ps_release_date', true);
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'MusicRecording',
        'name' => get_the_title($id),
        'url' => get_permalink($id),
    ];
    if ($artist) { $schema['byArtist'] = ['@type' => 'MusicGroup', 'name' => $artist]; }
    if ($release) { $schema['datePublished'] = $release; }
    $artwork = get_post_meta($id, 'ps_artwork_url', true);
    if ($artwork) { $schema['image'] = $artwork; }
    echo "\n<script type=\"application/ld+json\">" .
        wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) .
        "</script>\n";
}, 30);

/* -------------------------------------------------------------------------
 * JattBeat's black / charcoal / red identity, tuned for desktop and mobile.
 * ---------------------------------------------------------------------- */
add_action('wp_enqueue_scripts', function () {
    wp_register_style('jattbeat-catalog', false, [], JATTBEAT_CATALOG_VERSION);
    wp_enqueue_style('jattbeat-catalog');
    wp_add_inline_style('jattbeat-catalog', '
      .jb-shell,.jb-detail{--jb-red:#f0143d;--jb-bg:#090a10;--jb-panel:#151722;--jb-muted:#c1c2cc;--jb-line:#343544;color:#f7f7fb;background:var(--jb-bg);font-family:Inter,system-ui,-apple-system,Segoe UI,sans-serif}
      .jb-shell{max-width:1120px;margin:0 auto;padding:18px 20px 130px;border-radius:18px}
      .jb-topbar{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:12px 0 18px;border-bottom:1px solid var(--jb-line)}
      .jb-brand{display:flex;align-items:center;gap:10px;color:#fff;text-decoration:none;font-size:28px;font-weight:900;letter-spacing:-1px}
      .jb-brand strong{color:var(--jb-red)}
      .jb-brandmark{display:grid;place-items:center;width:46px;height:42px;border:3px solid var(--jb-red);border-radius:15px;color:var(--jb-red);font-size:25px}
      .jb-search{display:flex;align-items:center;gap:8px;width:min(310px,48%);padding:9px 13px;border:1px solid var(--jb-line);border-radius:30px;background:#151722;color:#aaa}
      .jb-search input{width:100%;min-width:0;border:0!important;outline:0!important;background:transparent!important;color:#fff!important;box-shadow:none!important}
      .jb-hero{position:relative;display:flex;align-items:center;min-height:270px;margin:22px 0;padding:34px 38px;overflow:hidden;border:1px solid #573044;border-radius:22px;background:radial-gradient(circle at 85% 50%,#9f1438 0,transparent 34%),linear-gradient(120deg,#23101b,#421225 58%,#11131d)}
      .jb-hero-copy{position:relative;z-index:2;max-width:62%}
      .jb-eyebrow{display:inline-block;color:#ff8ca2;font-size:12px;font-weight:900;letter-spacing:1.7px;text-transform:uppercase}
      .jb-hero h1{margin:10px 0;font-size:clamp(34px,5vw,58px);line-height:1.02;letter-spacing:-2px;color:#fff}
      .jb-hero p{color:#e5d8df;font-size:15px}
      .jb-hero-cta{display:inline-flex;margin-top:14px;padding:12px 20px;border-radius:28px;background:var(--jb-red);color:#fff;text-decoration:none;font-weight:900}
      .jb-hero-art{position:absolute;right:4%;width:230px;height:230px;object-fit:cover;border-radius:50%;border:9px solid #ffffff22;box-shadow:0 12px 60px #0009}
      .jb-hero-disc{position:absolute;right:8%;display:grid;place-items:center;width:205px;height:205px;border:18px solid #171923;border-radius:50%;background:radial-gradient(circle,#f0143d 0 8%,#24101a 9% 12%,#171923 13% 100%);font-size:28px;font-weight:900}
      .jb-quicklinks{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:18px 0}
      .jb-quicklinks a{padding:15px;border:1px solid #563044;border-radius:13px;background:linear-gradient(140deg,#351321,#171923);color:#fff;text-align:center;text-decoration:none;font-weight:800}
      .jb-section-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:25px 0 12px}
      .jb-section-head h1,.jb-section-head h2{margin:0;color:#fff;font-size:23px;font-weight:900}
      .jb-section-head>a{color:#ff607d;font-weight:800;text-decoration:none}
      .jb-all-heading span{color:var(--jb-muted);font-size:13px}
      .jb-song-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));column-gap:24px;border-top:1px solid var(--jb-line)}
      .jb-song-row{display:grid;grid-template-columns:76px minmax(0,1fr) 44px;align-items:center;gap:13px;padding:10px 3px;border-bottom:1px solid var(--jb-line);min-width:0}
      .jb-cover-link{display:block}
      .jb-cover{display:block;width:76px;height:76px;object-fit:cover;border:1px solid #ffffff25;border-radius:11px;background:#28202b}
      .jb-cover-fallback{display:grid;place-items:center;color:#ff5272;font-size:27px}
      .jb-song-copy{display:flex;min-width:0;flex-direction:column;gap:5px}
      .jb-song-title{overflow:hidden;color:#fff;font-size:16px;font-weight:900;line-height:1.25;text-decoration:none;text-overflow:ellipsis}
      .jb-song-artist{overflow:hidden;color:var(--jb-muted);font-size:13px;text-overflow:ellipsis;white-space:nowrap}
      .jb-play{width:42px;height:42px;border:0;border-radius:50%;background:linear-gradient(145deg,#ff3150,#d9002c);color:#fff;font-size:17px;cursor:pointer;box-shadow:0 5px 15px #d9002c33}
      .jb-empty{padding:28px;text-align:center;color:var(--jb-muted)}
      .jb-pagination{display:flex;align-items:center;justify-content:center;gap:14px;margin:24px 0;color:var(--jb-muted);font-size:13px}
      .jb-pagination a,.jb-pagination .is-disabled{padding:9px 15px;border:1px solid var(--jb-line);border-radius:22px;background:#191a25;color:#fff;text-decoration:none;font-weight:800}
      .jb-pagination .is-disabled{opacity:.35}
      .jb-audio-dock{position:fixed;right:18px;bottom:18px;left:18px;z-index:99999;display:flex;align-items:center;gap:12px;max-width:680px;margin:auto;padding:10px 13px;border:1px solid #ffffff30;border-radius:15px;background:#171923f5;box-shadow:0 10px 40px #0009}
      .jb-audio-dock[hidden]{display:none}
      .jb-audio-dock img{width:42px;height:42px;border-radius:8px;object-fit:cover}
      .jb-dock-track{display:flex;flex:1;min-width:0;flex-direction:column}
      .jb-dock-track strong,.jb-dock-track small{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .jb-dock-track small{color:var(--jb-muted)}
      .jb-audio-dock audio{width:min(250px,42%)}
      .jb-detail{max-width:1000px;margin:28px auto;padding:26px;border:1px solid #343544;border-radius:18px;background:#10121b}
      .jb-detail-top{display:grid;grid-template-columns:minmax(150px,260px) minmax(0,1fr);align-items:center;gap:28px}
      .jb-detail-cover{width:100%;aspect-ratio:1;object-fit:cover;border:1px solid #ffffff30;border-radius:18px}
      .jb-detail-heading h1{margin:9px 0;color:#fff;font-size:clamp(28px,4vw,43px);line-height:1.05}
      .jb-detail-artist{margin:8px 0;color:#ddd;font-size:18px}
      .jb-detail-date{color:var(--jb-muted);font-size:14px}
      .jb-detail-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:22px}
      .jb-action{padding:12px 19px;border:1px solid #ffffff35;border-radius:28px;background:#272832;color:#fff;font-weight:900;cursor:pointer}
      .jb-action-primary{border-color:#ff526d;background:linear-gradient(110deg,#ff123c,#c6002b)}
      .jb-detail-section{margin:28px 0}
      .jb-detail-section h2{margin:0 0 12px;color:#fff;font-size:21px}
      .jb-info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:0}
      .jb-info-grid div{padding:13px;border:1px solid #ffffff12;border-radius:11px;background:#171923}
      .jb-info-grid dt{color:#9fa1ad;font-size:12px}
      .jb-info-grid dd{margin:4px 0 0;color:#fff;font-weight:800}
      .jb-rights-note,.jb-source-credit{color:#c6c7d0;font-size:13px;line-height:1.6}
      .jb-source-credit a{color:#ff718b}
      @media(max-width:700px){
        .jb-shell{padding:8px 13px 125px;border-radius:0}
        .jb-topbar{padding:8px 0 14px}
        .jb-brand{font-size:24px;gap:7px}
        .jb-brandmark{width:39px;height:36px;font-size:20px;border-width:2px}
        .jb-search{width:43px;padding:7px 10px;border-color:transparent;background:transparent}
        .jb-search:focus-within{position:absolute;right:55px;z-index:5;width:calc(100% - 130px);border-color:var(--jb-line);background:#171923}
        .jb-hero{min-height:230px;margin:15px 0;padding:23px 18px}
        .jb-hero-copy{max-width:68%}
        .jb-hero h1{font-size:37px}
        .jb-hero p{font-size:12px}
        .jb-hero-art{right:-8%;width:160px;height:160px;opacity:.86}
        .jb-hero-disc{right:-2%;width:150px;height:150px;border-width:13px}
        .jb-quicklinks{gap:8px}
        .jb-quicklinks a{padding:12px 4px;font-size:12px}
        .jb-section-head h1,.jb-section-head h2{font-size:20px}
        .jb-song-list{display:block}
        .jb-song-row{grid-template-columns:62px minmax(0,1fr) 40px;gap:10px;padding:8px 2px}
        .jb-cover{width:62px;height:62px;border-radius:9px}
        .jb-song-title{font-size:14px}
        .jb-song-artist{font-size:12px}
        .jb-play{width:38px;height:38px}
        .jb-detail{margin:14px 0;padding:15px;border-radius:13px}
        .jb-detail-top{grid-template-columns:118px minmax(0,1fr);gap:14px}
        .jb-detail-heading h1{font-size:24px}
        .jb-detail-artist{font-size:14px}
        .jb-detail-actions{margin-top:14px}
        .jb-action{flex:1;padding:11px 12px;font-size:13px}
        .jb-info-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
        .jb-audio-dock{right:8px;bottom:9px;left:8px;gap:8px;padding:8px}
        .jb-audio-dock img{width:36px;height:36px}
        .jb-audio-dock audio{width:118px;height:34px}
      }
    ');
});

add_action('wp_footer', function () {
    ?>
    <script>
    (function () {
      'use strict';
      const restBase = <?php echo wp_json_encode(trailingslashit(rest_url('jattbeat/v1/'))); ?>;
      const mediaCache = new Map();

      async function getMedia(postId) {
        if (mediaCache.has(String(postId))) return mediaCache.get(String(postId));
        const response = await fetch(restBase + 'media/' + encodeURIComponent(postId), {
          headers: { 'Accept': 'application/json' },
          credentials: 'same-origin'
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'Playback is temporarily unavailable.');
        mediaCache.set(String(postId), data);
        return data;
      }

      function showDock(button, mediaUrl) {
        const dock = document.getElementById('jbAudioDock');
        const player = document.getElementById('jbAudioPlayer');
        if (!dock || !player) throw new Error('Audio player is not available on this page.');
        const title = button.dataset.jbTitle || 'JattBeat song';
        const artist = button.dataset.jbArtist || '';
        const artwork = button.dataset.jbArtwork || '';
        const cover = document.getElementById('jbDockCover');
        const titleNode = document.getElementById('jbDockTitle');
        const artistNode = document.getElementById('jbDockArtist');
        if (cover) { cover.src = artwork; cover.hidden = !artwork; }
        if (titleNode) titleNode.textContent = title;
        if (artistNode) artistNode.textContent = artist;
        dock.hidden = false;
        if (player.src !== mediaUrl) player.src = mediaUrl;
        player.play().catch(function () {
          player.controls = true;
          player.focus();
        });
      }

      document.addEventListener('click', async function (event) {
        const play = event.target.closest('[data-jb-play]');
        const download = event.target.closest('[data-jb-download]');
        const button = play || download;
        if (!button) return;
        event.preventDefault();
        const postId = play ? play.dataset.jbPlay : download.dataset.jbDownload;
        button.disabled = true;
        try {
          const media = await getMedia(postId);
          if (play) {
            showDock(play, media.media_url);
          } else if (media.download_url) {
            window.location.assign(media.download_url);
          } else {
            throw new Error('No authorized download link is available.');
          }
        } catch (error) {
          window.alert(error.message || 'The media service is temporarily unavailable.');
        } finally {
          button.disabled = false;
        }
      });

      document.addEventListener('input', function (event) {
        const search = event.target.closest('[data-jb-search]');
        if (!search) return;
        const query = search.value.trim().toLocaleLowerCase();
        document.querySelectorAll('[data-jb-card]').forEach(function (card) {
          card.hidden = query !== '' && !card.textContent.toLocaleLowerCase().includes(query);
        });
      });
    })();
    </script>
    <?php
}, 40);
