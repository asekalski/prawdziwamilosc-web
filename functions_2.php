<?php
add_action('init', function() {
    error_log('functions_2.php init hook fired. Request URI: ' . $_SERVER['REQUEST_URI']);
});

/**
 * Add Smart App Banner for iOS users
 */
add_action('wp_head', function() {
    echo '<meta name="apple-itunes-app" content="app-id=6758733087">';
});
/**
 * Helper function to check if the current page is the dashboard or front page
 * 
 * This function provides a centralized way to detect if we're on pages that should
 * bypass custom routing and onboarding checks. It returns true for both the dashboard
 * page and the front page to ensure proper routing behavior.
 * 
 * Note: Despite the name, this function checks for both dashboard and front page.
 * The name is kept for backward compatibility.
 * 
 * @since 1.0.0
 * @return bool True if on dashboard page or front page, false otherwise
 */
/**
 * Bezpieczna funkcja sprawdzania dashboardu/strony głównej
 */
if ( ! defined( 'ONBOARDING_PAGE_ID' ) ) {
    define( 'ONBOARDING_PAGE_ID', 1339 );
}

// DEBUG: Capture Fatal Errors - REMOVED

// Strona Dashboard
if ( ! defined( 'DASHBOARD_PAGE_ID' ) ) {
    define( 'DASHBOARD_PAGE_ID', 1318 );
}

// Strona Rejestracji
if ( ! defined( 'REGISTRATION_PAGE_ID' ) ) {
    define( 'REGISTRATION_PAGE_ID', 1254 );
}

// BuddyPress Avatar Size - increase default resolution from 150x150 to 500x500
if ( ! defined( 'BP_AVATAR_FULL_WIDTH' ) ) {
    define( 'BP_AVATAR_FULL_WIDTH', 500 );
}
if ( ! defined( 'BP_AVATAR_FULL_HEIGHT' ) ) {
    define( 'BP_AVATAR_FULL_HEIGHT', 500 );
}
if ( ! defined( 'BP_AVATAR_THUMB_WIDTH' ) ) {
    define( 'BP_AVATAR_THUMB_WIDTH', 150 );
}
if ( ! defined( 'BP_AVATAR_THUMB_HEIGHT' ) ) {
    define( 'BP_AVATAR_THUMB_HEIGHT', 150 );
}

/**
 * Admin Menu for Push Notifications
 */
add_action('admin_menu', function() {
    add_menu_page(
        'Powiadomienia Push',
        'Powiadomienia Push',
        'manage_options',
        'sk-push-notifications',
        'sk_render_push_admin_page',
        'dashicons-megaphone',
        30
    );
});

/**
 * Ensure the notifications table has a 'content' column for our broadcasts
 */
function sk_ensure_notifications_column() {
    if (!is_admin() || !current_user_can('manage_options')) return;
    
    global $wpdb;
    $table_name = function_exists('buddypress') && isset(buddypress()->notifications->table_name) 
        ? buddypress()->notifications->table_name 
        : $wpdb->prefix . 'bp_notifications';

    // Check if column exists
    $row = $wpdb->get_row("SHOW COLUMNS FROM $table_name LIKE 'content'");
    if (!$row) {
        $wpdb->query("ALTER TABLE $table_name ADD COLUMN content text DEFAULT NULL");
    }
}
add_action('admin_init', 'sk_ensure_notifications_column');

// TEMPORARY: Unblock Argen-Przyklad
add_action('init', function() {
    if ( isset($_GET['unblock_argen']) && current_user_can('manage_options') ) {
        $argen = get_user_by('login', 'Argen-Przyklad');
        if ($argen) {
            $current_user_id = get_current_user_id();
            $skipped = get_user_meta($current_user_id, 'sk_skipped_users', true);
            if (is_array($skipped)) {
                $new_skipped = array_diff($skipped, array($argen->ID));
                update_user_meta($current_user_id, 'sk_skipped_users', $new_skipped);
                echo "<div style='background:green;color:white;padding:20px;position:fixed;top:0;z-index:9999'>✅ Unblocked Argen-Przyklad (ID: {$argen->ID}) from User ID: $current_user_id</div>";
            }
        }
    }
});

function sk_render_push_admin_page() {
    if (!current_user_can('manage_options')) return;

    $success = false;
    global $wpdb;
    $table_name = function_exists('buddypress') && isset(buddypress()->notifications->table_name) 
        ? buddypress()->notifications->table_name 
        : $wpdb->prefix . 'bp_notifications';

    // Handle Deletion
    if (isset($_POST['sk_delete_broadcast']) && check_admin_referer('sk_delete_broadcast_nonce')) {
        $broadcast_id = (int)$_POST['broadcast_id'];
        if ($broadcast_id > 0) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $table_name WHERE component_name = 'custom_broadcast' AND item_id = %d",
                $broadcast_id
            ));
            $success = "Usunięto ogłoszenie i powiadomienia u wszystkich użytkowników.";
        }
    }

    // Handle Sending
    if (isset($_POST['sk_send_push_broadcast']) && check_admin_referer('sk_send_push_nonce')) {
        $title = sanitize_text_field($_POST['push_title']);
        $body = sanitize_textarea_field($_POST['push_body']);
        
        if ($title && $body) {
            // Send Push to those with tokens
            $push_user_ids = $wpdb->get_col("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'sk_expo_push_token'");
            if (!empty($push_user_ids)) {
                sk_send_push_notification($push_user_ids, $title, $body, ['type' => 'broadcast']);
            }

            // Use a unique ID for this broadcast to group them (timestamp)
            $broadcast_id = time();

            // IMPORTANT: Save BuddyPress notification for ALL users
            $all_user_ids = $wpdb->get_col("SELECT ID FROM {$wpdb->users} LIMIT 5000"); 
            
            if (function_exists('bp_notifications_add_notification')) {
                foreach ($all_user_ids as $uid) {
                    bp_notifications_add_notification([
                        'user_id'           => $uid,
                        'item_id'           => $broadcast_id,
                        'secondary_item_id' => 0,
                        'component_name'    => 'custom_broadcast',
                        'component_action'  => 'broadcast_message',
                        'date_notified'     => bp_core_current_time(),
                        'is_new'            => 1
                    ]);
                }
                
                $wpdb->update(
                    $table_name,
                    ['content' => $title . ': ' . $body],
                    ['item_id' => $broadcast_id, 'component_name' => 'custom_broadcast'],
                    ['%s'],
                    ['%d', '%s']
                );
            }
            
            $success = "Wysłano powiadomienie do " . count($push_user_ids) . " urządzeń. Historia zapisana dla " . count($all_user_ids) . " użytkowników.";
        }
    }

    // Fetch History
    $history = $wpdb->get_results("
        SELECT item_id as broadcast_id, MAX(content) as content, MAX(date_notified) as date_notified, COUNT(*) as user_count 
        FROM $table_name 
        WHERE component_name = 'custom_broadcast' 
        GROUP BY item_id 
        ORDER BY date_notified DESC 
        LIMIT 20
    ");

    ?>
    <div class="wrap">
        <h1>📣 System Powiadomień Push</h1>
        
        <?php if ($success): ?>
            <div class="notice notice-success is-dismissible"><p><?php echo $success; ?></p></div>
        <?php endif; ?>

        <div style="display: flex; gap: 30px; margin-top: 20px;">
            <!-- Form Column -->
            <div style="flex: 1; background: #fff; padding: 20px; border: 1px solid #ccd0d4;">
                <h2>Wyślij Nowe Ogłoszenie</h2>
                <form method="post">
                    <?php wp_nonce_field('sk_send_push_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="push_title">Tytuł</label></th>
                            <td><input name="push_title" type="text" id="push_title" value="Prawdziwa Miłość" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="push_body">Treść</label></th>
                            <td><textarea name="push_body" id="push_body" rows="5" class="large-text" required placeholder="Napisz coś ważnego..."></textarea></td>
                        </tr>
                    </table>
                    <p class="submit">
                        <input type="submit" name="sk_send_push_broadcast" id="submit" class="button button-primary" value="Wyślij do wszystkich">
                    </p>
                </form>
            </div>

            <!-- History Column -->
            <div style="flex: 1.5; background: #fff; padding: 20px; border: 1px solid #ccd0d4;">
                <h2>Historia Ostatnich Powiadomień</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Treść (skrót)</th>
                            <th>Odbiorcy</th>
                            <th style="width: 80px;">Akcja</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($history)): ?>
                            <tr><td colspan="4">Brak wysłanych powiadomień.</td></tr>
                        <?php else: ?>
                            <?php foreach ($history as $h): ?>
                                <tr>
                                    <td><?php echo date('d.m.Y H:i', strtotime($h->date_notified)); ?></td>
                                    <td><?php echo esc_html(wp_trim_words($h->content, 10)); ?></td>
                                    <td><?php echo $h->user_count; ?></td>
                                    <td>
                                        <form method="post" onsubmit="return confirm('Czy na pewno chcesz usunąć to ogłoszenie u WSZYSTKICH użytkowników?');">
                                            <?php wp_nonce_field('sk_delete_broadcast_nonce'); ?>
                                            <input type="hidden" name="broadcast_id" value="<?php echo $h->broadcast_id; ?>">
                                            <button type="submit" name="sk_delete_broadcast" class="button button-link" style="color: #d63638; text-decoration: none;">
                                                <span class="dashicons dashicons-trash" style="margin-top: 4px;"></span> Usuń
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
}


/**
 * Super-stabilna funkcja sprawdzania Dashboardu
 */
function is_dashboard_page()
{
    if (is_admin()) return false;

    // PRIORYTET 1: Sprawdzenie ścieżki URL (najbardziej niezawodne podczas refresh)
    if (isset($_SERVER['REQUEST_URI'])) {
        $path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
        
        // Pobierz rzeczywiste slugi stron z bazy danych
        $dashboard_page = get_post(DASHBOARD_PAGE_ID);
        $registration_page = get_post(REGISTRATION_PAGE_ID);
        $onboarding_page = get_post(ONBOARDING_PAGE_ID);
        
        // Sprawdź czy ścieżka pasuje do któregokolwiek sluga
        $dashboard_slug = $dashboard_page ? $dashboard_page->post_name : '';
        $registration_slug = $registration_page ? $registration_page->post_name : '';
        $onboarding_slug = $onboarding_page ? $onboarding_page->post_name : '';
        
        if ($path === $dashboard_slug || 
            $path === $registration_slug || 
            $path === $onboarding_slug ||
            $path === 'dashboard' ||  // Fallback dla starych linków
            $path === 'rejestracja' || 
            $path === 'onboarding') {
            return true;
        }
        
        // NIE sprawdzaj pustego path - strona główna NIE jest dashboardem!
        // Usunięto: $path === ''
    }

    // PRIORYTET 2: Sprawdzenie ID aktualnej strony (działa gdy query jest już rozwiązane)
    $current_id = get_queried_object_id();
    if ($current_id > 0) {
        $safe_ids = [DASHBOARD_PAGE_ID, REGISTRATION_PAGE_ID, ONBOARDING_PAGE_ID];
        // NIE dodawaj page_on_front do safe_ids!
        if (in_array($current_id, $safe_ids)) {
            return true;
        }
    }

    return false;
}

/**
 * Helper do zmiennej krok
 */
function get_krok_query_var()
{
    $krok = get_query_var('krok');
    if (empty($krok) && isset($_GET['krok'])) {
        $krok = sanitize_text_field(wp_unslash($_GET['krok']));
    }
    return $krok ?: false;
}

/**
 * Zmień query PRZED załadowaniem szablonu (Poprawny sposób WordPressa)
 * Tylko modyfikuje query gdy jest parametr 'krok' w URL
 */
function napraw_query_dla_kroku($query)
{
    // Nie ruszaj admina ani zapytań bocznych
    if (is_admin() || !$query->is_main_query()) {
        return;
    }

    // WAŻNE: Najpierw sprawdź czy w ogóle jest parametr 'krok'
    // Jeśli go nie ma, nie dotykaj zapytania!
    $krok = get_krok_query_var();
    if (!$krok) {
        return;
    }

    // Sprawdź czy to nie jest strona która powinna być pominięta
    // Używamy page_id zamiast pagename, bo pagename może nie być jeszcze ustawione
    $page_id = $query->get('page_id');
    $safe_pages = [DASHBOARD_PAGE_ID, REGISTRATION_PAGE_ID, ONBOARDING_PAGE_ID, (int)get_option('page_on_front')];
    
    if ($page_id && in_array($page_id, $safe_pages)) {
        return;
    }
    
    // Jeśli jest strona główna, również nie modyfikuj
    if ($query->is_front_page()) {
        return;
    }

    // Tylko jeśli jest parametr krok I nie jesteśmy na bezpiecznej stronie,
    // wtedy wymuszamy stronę rejestracji
    $query->set('page_id', REGISTRATION_PAGE_ID);
    $query->is_page = true;
    $query->is_singular = true;
    $query->is_404 = false;
    $query->is_home = false;
}
add_action('pre_get_posts', 'napraw_query_dla_kroku');


function dodaj_krok_query_var($vars)
{
    $vars[] = 'krok';
    return $vars;
}
add_filter('query_vars', 'dodaj_krok_query_var');

/**
 * AGGRESSIVE FIX: Completely disable canonical redirects that would redirect away from valid pages.
 * 
 * This is specifically designed to prevent WordPress from redirecting from the front page slug
 * (e.g., /dashboard) to the homepage (/) when a page is set as the front page.
 * 
 * WordPress has a built-in behavior where if you set a page as the front page in Settings > Reading,
 * it will redirect from the page slug to the homepage. This function completely blocks that behavior.
 *
 * This function runs at priority -1 (VERY early) to catch redirects before any other filters.
 *
 * @param string $redirect_url  URL that WordPress wants to redirect to.
 * @param string $requested_url URL that was originally requested.
 * @return string|false         URL to redirect to, or false to cancel redirect.
 */
function pm_stop_home_canonical_redirect($redirect_url, $requested_url)
{
    // If there's no redirect, return early
    if (empty($redirect_url)) {
        return $redirect_url;
    }

    // Validate input types
    if (!is_string($redirect_url) || !is_string($requested_url)) {
        return $redirect_url;
    }

    // Parse both URLs to compare them
    $requested_path_raw = parse_url($requested_url, PHP_URL_PATH);
    $redirect_path_raw = parse_url($redirect_url, PHP_URL_PATH);
    
    // Handle parse_url failures
    if ($requested_path_raw === false || $redirect_path_raw === false) {
        return $redirect_url;
    }

    // Normalize paths (trim slashes, handle empty strings)
    $requested_path = is_string($requested_path_raw) ? trim($requested_path_raw, '/') : '';
    $redirect_path = is_string($redirect_path_raw) ? trim($redirect_path_raw, '/') : '';

    // CRITICAL: If WordPress is trying to redirect us from a non-empty path to an empty path (homepage),
    // this is almost certainly the front page slug redirect that we want to block
    if ($requested_path !== '' && $redirect_path === '') {
        // WordPress is trying to redirect from /something to /
        // This is the front page redirect - block it unconditionally
        return false;
    }

    // If we're on any subpage (not the homepage) and WordPress wants to redirect to a different page
    if ($requested_path !== '' && $requested_path !== $redirect_path) {
        // First, try to find the page in pages post type
        $page = get_page_by_path($requested_path, OBJECT, 'page');
        if ($page instanceof WP_Post && $page->post_status === 'publish') {
            // We're on a valid published page, block the redirect
            return false;
        }
        
        // Also check for posts and other public post types
        $post_types = get_post_types(array('public' => true), 'names');
        foreach ($post_types as $post_type) {
            $post = get_page_by_path($requested_path, OBJECT, $post_type);
            if ($post instanceof WP_Post && $post->post_status === 'publish') {
                // We're on a valid published resource, block the redirect
                return false;
            }
        }
        
        // Check if it might be a BuddyPress or other plugin page
        // If the path exists in WordPress routing (not 404), block the redirect
        global $wp_query;
        if (isset($wp_query) && !$wp_query->is_404()) {
            // We have a valid WordPress resource, block the redirect
            return false;
        }
        
        // Also check via BuddyPress detection
        if (function_exists('bp_core_get_user_domain')) {
            // This looks like it might be a BuddyPress profile or page
            // Block the redirect to be safe
            return false;
        }
    }

    return $redirect_url;
}
// Priority -1 to run BEFORE any other redirect_canonical filters
add_filter('redirect_canonical', 'pm_stop_home_canonical_redirect', -1, 2);

/**
 * Final safety net: Prevent any redirects on valid pages during template_redirect.
 * This runs with priority 1 (very early) to catch redirects before they happen.
 * 
 * IMPORTANT: This function only removes redirect_canonical when ALL conditions are met:
 * 1. We're on a singular published page (not admin, not 404, not archive)
 * 2. The requested URL path exactly matches the current page slug
 * 3. The path is non-empty (not homepage)
 * 
 * This targeted approach ensures we only disable redirects when absolutely necessary
 * to prevent the front-page slug redirect issue, while preserving other redirect
 * functionality like trailing slashes, www vs non-www, etc.
 * 
 * @return void
 */
function pm_prevent_page_redirects() {
    // Skip on admin
    if (is_admin()) {
        return;
    }
    
    // Only act if we're on a singular page
    if (!is_page()) {
        return;
    }
    
    // Get the current page object
    $current_page = get_queried_object();
    if (!($current_page instanceof WP_Post)) {
        return;
    }
    
    // Check if we're on a valid published page
    if ($current_page->post_status !== 'publish') {
        return;
    }
    
    // Get the requested path from the URL
    // Note: We use wp_unslash() only because parse_url() safely handles validation.
    // We're not outputting this value, just using it for internal path comparison.
    if (!isset($_SERVER['REQUEST_URI'])) {
        return;
    }
    
    $request_uri = wp_unslash($_SERVER['REQUEST_URI']);
    $path_raw = parse_url($request_uri, PHP_URL_PATH);
    
    if ($path_raw === false || !is_string($path_raw)) {
        return;
    }
    
    $requested_path = trim($path_raw, '/');
    
    // If we have a non-empty path and it matches the current page slug,
    // we want to stay on this page - so remove the redirect_canonical hook entirely
    // Note: parse_url() returns decoded paths, so we need to handle URL encoding
    if ($requested_path !== '' && 
        ($requested_path === $current_page->post_name || urldecode($requested_path) === $current_page->post_name)) {
        // Remove the redirect_canonical action for this request
        // This prevents WordPress from redirecting /dashboard to / when dashboard is the front page
        // We only do this when we're certain we're on a valid page with the correct URL
        remove_action('template_redirect', 'redirect_canonical');
    }
}
add_action('template_redirect', 'pm_prevent_page_redirects', 1);

add_action('wp_enqueue_scripts', 'child_enqueue_styles');
function child_enqueue_styles()
{
    wp_enqueue_style('parent-style', get_template_directory_uri() . '/style.css');
}

function astra_child_enqueue_styles()
{
    wp_enqueue_style('astra-child-style', get_stylesheet_uri());
}
add_action('wp_enqueue_scripts', 'astra_child_enqueue_styles');

function enqueue_logo_redirect_script()
{
    wp_enqueue_script('logo-redirect', get_stylesheet_directory_uri() . '/js/logo-redirect.js', array('jquery'), null, true);
}
//add_action( 'wp_enqueue_scripts', 'enqueue_logo_redirect_script' );

function enqueue_user_filters_script()
{
    // Skrypt będzie ładowany tylko na stronie, gdzie jest grid
    if (is_page() || is_single()) { // Możesz dodać tu bardziej precyzyjne warunki
        wp_enqueue_script('user-filters', get_stylesheet_directory_uri() . '/js/user-filters.js', array('jquery'), '1.0', true);

        // Przekaż adres URL do admin-ajax.php do skryptu
        wp_localize_script('user-filters', 'ajax_filters_object', array('ajax_url' => admin_url('admin-ajax.php')));
    }
}
add_action('wp_enqueue_scripts', 'enqueue_user_filters_script');

/**
 * Zmienia link logo na /dashboard zamiast strony głównej
 */
function custom_logo_url($html)
{
    // Validate input
    if (!is_string($html) || $html === '') {
        return $html;
    }
    
    // Replace the href attribute in the custom logo link
    // This pattern matches both with and without trailing slash
    $home = rtrim(home_url('/'), '/');
    $dashboard = esc_url(home_url('/dashboard'));
    
    $result = preg_replace_callback(
        '#href=(["\'])' . preg_quote($home, '#') . '\/?(["\'])#i',
        function($matches) use ($dashboard) {
            return 'href=' . $matches[1] . $dashboard . $matches[2];
        },
        $html
    );
    
    // Return original HTML if preg_replace_callback failed
    return ($result !== null) ? $result : $html;
}
add_filter('get_custom_logo', 'custom_logo_url');

/**
 * =========================================================================
 * Shortcode siatki użytkowników [grid_uzytkownikow]
 * WERSJA Z OBSŁUGĄ NUMEROLOGII
 * =========================================================================
 */
function grid_uzytkownikow_shortcode()
{
    ob_start(); ?>
    <div id="user-results" class="users-grid-elegant">
        <p class="loading-message">Wczytywanie użytkowników...</p>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const container = document.getElementById('user-results');
            if (!container) return;

            function getMatchOpacity(match) {
                return match / 100;
            }

            container.addEventListener('click', function (event) {
                const likeButton = event.target.closest('.like-button');
                const blockButton = event.target.closest('.block-button');

                if (!likeButton && !blockButton) return;

                event.preventDefault();
                event.stopPropagation();

                if (likeButton) {
                    const likedUserId = likeButton.dataset.userId;
                    const isLiked = likeButton.classList.toggle('liked');

                    jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                        action: 'toggle_like_user',
                        liked_user_id: likedUserId,
                        nonce: '<?php echo wp_create_nonce('like_user_nonce'); ?>'
                    }, function(response) {
                        if (response.success && response.data.is_match && response.data.matched_user) {
                            // Use the global showMatchModal function if available
                            if (typeof showMatchModal === 'function') {
                                showMatchModal(response.data.matched_user);
                            } else if (jQuery('#sk-match-modal').length) {
                                // Fallback: manually show the modal
                                var modal = jQuery('#sk-match-modal');
                                jQuery('#sk-match-matched-avatar').attr('src', response.data.matched_user.avatar);
                                jQuery('#sk-match-name').text(response.data.matched_user.name);
                                jQuery('#sk-match-message-btn').attr('href', '<?php echo trailingslashit(bp_loggedin_user_domain()) ?: home_url('/'); ?>' + '<?php echo function_exists("bp_get_messages_slug") ? bp_get_messages_slug() : "messages"; ?>' + '/compose/?r=' + encodeURIComponent(response.data.matched_user.login || response.data.matched_user.name));
                                modal.fadeIn(300);
                                modal.find('.sk-match-content').addClass('sk-match-animate-in');
                                setTimeout(function() {
                                    modal.find('.sk-match-content').removeClass('sk-match-animate-in');
                                    modal.fadeOut(200);
                                }, 4000);
                            }
                        }
                    });
                }

                if (blockButton) {
                    const blockedUserId = blockButton.dataset.userId;
                    if (confirm('Czy na pewno chcesz zablokować tego użytkownika?')) {
                        const card = blockButton.closest('.user-card-elegant');
                        const userName = card.querySelector('.user-name').textContent;
                        const avatarSrc = card.querySelector('.user-avatar').src;
                        const profileUrl = card.dataset.profileUrl;

                        card.style.opacity = '0';
                        setTimeout(() => card.remove(), 300);

                        jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                            action: 'toggle_block_user',
                            blocked_user_id: blockedUserId,
                            nonce: '<?php echo wp_create_nonce('block_user_nonce'); ?>'
                        }, function (response) {
                            if (response.success) {
                                const blockedListContainer = jQuery('#blocked-users-widget .liked-by-list');
                                if (blockedListContainer.length) {
                                    blockedListContainer.find('.no-one-yet').remove();
                                    const newListItem = `
                                    <li class="liked-by-item" data-item-id="${blockedUserId}">
                                        <a href="${profileUrl}">
                                            <img src="${avatarSrc.replace('?type=full', '?type=thumb')}" class="avatar avatar-30 photo" height="30" width="30">
                                            <span>${userName}</span>
                                        </a>
                                        <button class="unblock-button" data-user-id="${blockedUserId}">Odblokuj</button>
                                    </li>
                                `;
                                    blockedListContainer.prepend(newListItem);
                                }
                            }
                        });
                    }
                }
            });

            function loadUsers(filters = {}) {
                const container = document.getElementById('user-results'); // FIXED: Matches HTML ID
                if(!container) return; // Safety check

                // Show loading state if it's not the initial specific "Loading..." p tag
                if (!container.querySelector('.loading-message')) {
                     // Using a subtle opacity change or small loader usually better than wiping content
                     container.style.opacity = '0.5';
                }

                const formData = new FormData();
                formData.append('action', 'load_users_grid');
                
                // Add filters to FormData
                for (const [key, value] of Object.entries(filters)) {
                    if(value) formData.append(`filters[${key}]`, value);
                }

                fetch('<?php echo admin_url('admin-ajax.php'); ?>', { 
                    method: 'POST',
                    body: formData, 
                    credentials: 'same-origin' 
                })
                .then(response => response.json())
                .then(data => {
                    container.style.opacity = '1';
                    if (!data.success || !Array.isArray(data.data) || data.data.length === 0) {
                        container.innerHTML = '<p class="no-users-message" style="grid-column: 1/-1; text-align: center; color: white;">Brak użytkowników spełniających kryteria</p>';
                        return;
                    }
                    container.innerHTML = '';
                    data.data.forEach(user => {
                        const userCard = document.createElement('div');
                        userCard.className = 'user-card-elegant';
                        userCard.dataset.profileUrl = user.profile_url;

                        const nowTimestamp = Math.floor(Date.now() / 1000);
                        const secondsSinceActive = nowTimestamp - user.last_active_timestamp;
                        const isOnline = user.last_active_timestamp && secondsSinceActive < 300;

                        // Zbierz meta tagi jeśli istnieją
                        let metaTags = '';
                        for (const [key, value] of Object.entries(user.details)) {
                            if (value && value !== 'Nie podano') {
                                metaTags += `<span class="meta-item">${value}</span>`;
                            }
                        }

                        // Numerologia jako osobny tag z klasą
                        if (user.numerology) {
                            metaTags += `<span class="meta-item numerology-item">${user.numerology}</span>`;
                        }

                        if (user.zodiac_sign) {
                            metaTags += `<span class="meta-item zodiac">${user.zodiac_sign}</span>`;
                        }

                        userCard.innerHTML = `
                    <a href="${user.profile_url}" class="card-image-wrapper">
                        <img src="${user.avatar}" alt="${user.name}" class="user-avatar">
                        ${isOnline ? '<div class="online-dot"></div>' : ''}
                    </a>

                    <div class="card-content">
                        <div class="card-header-row">
                            <a href="${user.profile_url}" class="user-name">${user.name}</a>
                            <div class="match-indicator" style="opacity: ${getMatchOpacity(user.match)}">
                                ${user.match}%
                            </div>
                        </div>

                        ${user.location ? `<div class="user-location">${user.location}</div>` : ''}

                        ${user.bio ? `<div class="user-bio">${user.bio}</div>` : ''}

                        ${metaTags ? `<div class="user-meta">${metaTags}</div>` : ''}

                        <div class="card-actions">
                            <button class="action-icon like-button ${user.is_liked_by_me ? 'liked' : ''}" data-user-id="${user.id}" title="Polub">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                                </svg>
                            </button>
                            <a href="${user.profile_url}" class="action-icon" title="Zobacz profil">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                            </a>
                            <button class="action-icon block-button" data-user-id="${user.id}" title="Blokuj">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"/>
                                    <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>
                                </svg>
                            </button>
                            ${user.is_match ? `
                            <a href="${user.profile_url}messages/" class="action-icon message-icon" title="Napisz wiadomość">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                                    <polyline points="22,6 12,13 2,6"/>
                                </svg>
                            </a>
                            ` : ''}
                        </div>
                    </div>
                `;
                        container.appendChild(userCard);
                    });
                })
                .catch(err => {
                    console.error('Error loading users:', err);
                    container.style.opacity = '1';
                });
            }

            // Initial load
            loadUsers();

            // Helper to get value securely
            function getFilterValue(selectors) {
                 for (let selector of selectors) {
                     let el = document.querySelector(selector);
                     if (el) {
                         if ((el.type === 'radio' || el.type === 'checkbox') && !el.checked) continue;
                         if (el.value) return el.value;
                     }
                 }
                 return '';
            }
            
            // --- ROBUST RESET BUTTON INJECTION & FILTER HANDLING ---
            
            function findGotoweButton() {
                // Search for any clickable element containing "Gotowe"
                // prioritizing buttons and inputs
                const candidates = Array.from(document.querySelectorAll('a, button, input[type="submit"], .elementor-button'));
                return candidates.find(el => (el.innerText && el.innerText.includes('Gotowe')) || (el.value && el.value.includes('Gotowe')));
            }

            function injectResetButton() {
                // Prevent multiple injections
                if (document.querySelector('.reset-filters-btn-container')) return;
                
                // Don't inject if mobile filter panel is open (it has its own reset button)
                if (document.querySelector('.pm-filter-panel.open')) return;

                const gotoweBtn = findGotoweButton();
                if (!gotoweBtn) return;

                // Find a stable container to inject into. 
                // We want to be at the bottom of the form.
                const form = gotoweBtn.closest('form');
                const container = form ? form : gotoweBtn.closest('.elementor-widget-container') || gotoweBtn.parentElement.parentElement;

                if (container) {
                    // Skip if inside mobile filter panel (it has its own reset button)
                    if (container.closest('.pm-filter-panel')) return;
                    const resetBtnContainer = document.createElement('div');
                    resetBtnContainer.className = 'reset-filters-btn-container';
                    resetBtnContainer.style.marginTop = '15px';
                    resetBtnContainer.style.textAlign = 'center';
                    resetBtnContainer.style.width = '100%';
                    resetBtnContainer.style.clear = 'both'; 

                    const resetBtn = document.createElement('button');
                    resetBtn.innerText = 'Zresetuj wszystkie filtry';
                    resetBtn.className = 'reset-filters-btn'; // Class for event delegation
                    resetBtn.type = 'button'; 
                    resetBtn.style.padding = '10px 20px';
                    resetBtn.style.background = 'transparent';
                    resetBtn.style.color = '#e74c3c';
                    resetBtn.style.border = '1px solid #e74c3c';
                    resetBtn.style.borderRadius = '25px';
                    resetBtn.style.cursor = 'pointer';
                    resetBtn.style.width = '100%';
                    resetBtn.style.fontWeight = '600';
                    resetBtn.style.transition = 'all 0.3s ease';

                    resetBtn.onmouseover = function() { this.style.background = '#e74c3c'; this.style.color = 'white'; };
                    resetBtn.onmouseout = function() { this.style.background = 'transparent'; this.style.color = '#e74c3c'; };

                    resetBtnContainer.appendChild(resetBtn);
                    container.appendChild(resetBtnContainer);
                }
            }

            // Run injection logic repeatedly to catch dynamic updates
            setInterval(injectResetButton, 1000);

            // Global Click Listener
            document.addEventListener('click', function(e) {
                const target = e.target;
                
                // 1. RESET BUTTON CLICK
                if (target.matches('.reset-filters-btn') || target.innerText === 'Zresetuj wszystkie filtry') {
                     e.preventDefault();
                     e.stopPropagation();
                     
                     // Helper to clear form
                     const form = target.closest('form');
                     if(form) form.reset();

                     // Specific clear for common fields
                     document.querySelectorAll('input[type="radio"], input[type="checkbox"]').forEach(el => el.checked = false);
                     document.querySelectorAll('select').forEach(el => el.selectedIndex = 0);
                     
                     // Helper to reset specific inputs by name
                     const clearInput = (name) => {
                         const el = document.querySelector(`[name="${name}"]`) || document.querySelector(`#${name}`);
                         if(el) el.value = '';
                     };
                     
                     // Explicitly clear Age fields
                     clearInput('min_age');
                     clearInput('max_age');
                     
                     loadUsers(); // Reload with empty filters
                     return;
                }

                // 2. GOTOWE (FILTER) BUTTON CLICK
                // We assume the user clicks something that looks like "Gotowe"
                const gotoweBtn = target.closest('a, button, input[type="submit"], .elementor-button');
                
                if (gotoweBtn && ((gotoweBtn.innerText && gotoweBtn.innerText.includes('Gotowe')) || (gotoweBtn.value && gotoweBtn.value.includes('Gotowe')))) {
                    e.preventDefault();
                    
                    // Harvest filters using multiple possible names
                    const filters = {
                        religion: getFilterValue(['[name="religia"]', '[name="religion"]', 'select[name="field_2"]']),
                        politics: getFilterValue(['[name="polityka"]', '[name="politics"]']),
                        work: getFilterValue(['[name="styl_pracy"]', '[name="work"]']), 
                        diet: getFilterValue(['[name="dieta"]', '[name="diet"]', '[name="styl_jedzenia"]']),
                        min_age: getFilterValue(['[name="min_age"]', '#min_age']),
                        max_age: getFilterValue(['[name="max_age"]', '#max_age'])
                    };

                    loadUsers(filters);
                }
            });
        });
    </script>

    <style>
        /* Grid Layout */
        .users-grid-elegant {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 24px;
            padding: 24px 16px;
            max-width: 1400px;
            margin: 0 auto;
            overflow: visible !important;
        }
        
        /* Fix: Prevent parent containers from clipping the grid on desktop */
        .entry-content,
        .site-content,
        #content,
        main,
        article,
        #buddypress,
        .buddypress-wrap {
            overflow: visible !important;
        }

        /* Card - Turkusowa kolorystyka */
        .user-card-elegant {
            /* Ciemne tło, lekko przeźroczyste (95% krycia), bez rozmycia dla iPada */
            background: rgba(30, 30, 30, 0.95);

            /* Subtelny ciemny border zamiast jaskrawego turkusu */
            border: 1px solid rgba(61, 218, 215, 0.15);

            border-radius: 8px;
            overflow: hidden;
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
            /* Lekki cień dla głębi */
        }

        .user-card-elegant:hover {
            /* Po najechaniu tło nieco jaśniejsze (ciemnoszare), ale nie turkusowe */
            background: rgba(45, 45, 45, 0.98);

            /* Border staje się nieco wyraźniejszy */
            border-color: rgba(61, 218, 215, 0.4);

            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
            transform: translateY(-2px);
            /* Lekkie uniesienie */
        }

        /* Image Wrapper */
        .card-image-wrapper {
            display: block;
            position: relative;
            width: 100%;
            overflow: hidden;
            background: rgba(61, 218, 215, 0.05);
        }

        .user-avatar {
            width: 100%;
            height: auto;
            display: block;
            transition: transform 0.4s ease;
        }

        .user-card-elegant:hover .user-avatar {
            transform: scale(1.02);
        }

        /* Online Indicator */
        .online-dot {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 10px;
            height: 10px;
            background: #10b981;
            border: 2px solid #ffffff;
            border-radius: 50%;
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.2);
        }

        /* Card Content */
        .card-content {
            padding: 16px 20px 20px 20px;
        }

        /* Header Row */
        .card-header-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 12px;
            margin-bottom: 8px;
        }

        .user-name {
            font-size: 1.125rem;
            font-weight: 600;
            color: #d4af37;
            text-decoration: none;
            transition: color 0.2s ease;
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }

        .user-name:hover {
            color: #f4d03f;
        }

        .premium-badge {
            display: inline-block;
            margin-left: 6px;
            font-size: 1rem;
            animation: premiumGlow 2s ease-in-out infinite;
        }

        /* HEADER AVATAR BADGE */
        .avatar-wrapper-premium {
            position: relative;
            display: inline-block;
            line-height: 0; /* Fix for extra space below img */
        }
        
        .avatar-wrapper-premium .avatar-premium-badge {
            /* Position relative to wrapping span */
            position: absolute !important; 
            top: -2px !important;
            right: -2px !important;
            font-size: 14px !important;
            z-index: 9999 !important;
            filter: drop-shadow(0 0 4px gold);
            animation: premiumGlow 2s ease-in-out infinite;
            line-height: 1;
        }

        .avatar-premium-badge {
            position: absolute;
            top: 8px;
            right: 8px;
            font-size: 1.5rem;
            z-index: 10;
            filter: drop-shadow(0 0 4px gold);
            animation: premiumGlow 2s ease-in-out infinite;
        }

        .user-card-avatar {
            position: relative;
        }

        @keyframes premiumGlow {
            0%, 100% { filter: drop-shadow(0 0 2px gold); }
            50% { filter: drop-shadow(0 0 8px gold); }
        }

        /* Match Indicator */
        .match-indicator {
            font-size: 0.875rem;
            font-weight: 600;
            color: #d4af37;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }

        /* Location */
        .user-location {
            font-size: 0.85rem;
            color: #d4af37;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .user-bio {
            font-size: 0.85rem;
            color: #ccc;
            margin-bottom: 12px;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            text-overflow: ellipsis;
            overflow: hidden;
        }

        /* Meta Items */
        .user-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 14px;
        }

        .meta-item {
            font-size: 0.75rem;
            color: #ffffff;
            background: rgba(212, 175, 55, 0.2);
            padding: 4px 10px;
            border-radius: 4px;
            font-weight: 500;
            border: 1px solid rgba(212, 175, 55, 0.3);
        }

        .meta-item.zodiac {
            background: rgba(244, 208, 63, 0.25);
            color: #ffd700;
            border-color: rgba(244, 208, 63, 0.4);
        }

        /* Numerologia - ukryta domyślnie */
        .meta-item.numerology-item {
            display: none;
            background: rgba(212, 175, 55, 0.25);
            color: #f4d03f;
            border: 1px solid rgba(212, 175, 55, 0.4);
        }

        body.show-numerology .meta-item.numerology-item {
            display: inline-block;
        }

        /* Actions */
        .card-actions {
            display: flex;
            gap: 8px;
        }

        .action-icon {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px;
            background: rgba(212, 175, 55, 0.15);
            border: 1px solid rgba(212, 175, 55, 0.4);
            border-radius: 6px;
            color: #d4af37;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .action-icon:hover {
            background: rgba(212, 175, 55, 0.3);
            border-color: rgba(212, 175, 55, 0.6);
            color: #f4d03f;
        }

        .like-button svg {
            transition: all 0.2s ease;
        }

        .like-button.liked {
            background: rgba(244, 208, 63, 0.3);
            border-color: rgba(244, 208, 63, 0.6);
            color: #ffd700;
        }

        .like-button.liked svg {
            fill: currentColor;
        }

        .block-button:hover {
            background: rgba(239, 68, 68, 0.2);
            border-color: rgba(239, 68, 68, 0.5);
            color: #ef4444;
        }

        /* Messages */
        .loading-message,
        .no-users-message {
            grid-column: 1 / -1;
            text-align: center;
            padding: 60px 20px;
            color: #d4af37;
            font-size: 0.9375rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .users-grid-elegant {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
                gap: 16px;
                padding: 16px;
            }

            .card-content {
                padding: 12px 16px 16px 16px;
            }

            .user-name {
                font-size: 1rem;
            }
        }
    </style>
    <?php
    return ob_get_clean();
}
add_shortcode('grid_uzytkownikow', 'grid_uzytkownikow_shortcode');

function shortcode_moj_profil()
{
    if (is_user_logged_in()) {
        if (function_exists('bp_loggedin_user_domain')) {
            $link = bp_loggedin_user_domain();
        } else {
            $user_id = get_current_user_id();
            $link = get_author_posts_url($user_id);
        }
        return '<a href="' . esc_url($link) . '" style="display: block; padding: 10px; text-decoration: none;">👤 Mój Profil</a>';
    } else {
        return '<a href="/logowanie" style="display: block; padding: 10px; text-decoration: none;">Zaloguj się</a>';
    }
}
add_shortcode('moj_profil', 'shortcode_moj_profil');

function bp_messages_link_shortcode()
{
    if (is_user_logged_in()) {
        return bp_loggedin_user_domain() . bp_get_messages_slug();
    } else {
        return '#'; // lub link do logowania
    }
}
add_shortcode('bp_messages_link', 'bp_messages_link_shortcode');

/**
 * Rejestracja odwiedzin profilu z datą i czasem.
 * WERSJA Z BLOKADĄ LOGOWANIA UŻYTKOWNIKA ID=1
 */
function sk_log_profile_visit()
{
    if (!is_user_logged_in() || !bp_is_user())
        return;

    $profile_user_id = bp_displayed_user_id();
    $visitor_user_id = get_current_user_id();

    // Nie loguj odwiedzin samego siebie
    if ($profile_user_id == $visitor_user_id)
        return;

    // --- POPRAWKA: Sprawdzamy bezpośrednio, czy ID odwiedzającego to 1 ---
    if ($visitor_user_id == 1) {
        return; // Zakończ funkcję, jeśli gościem jest admin o ID=1.
    }
    // --- KONIEC POPRAWKI ---

    $visitors = get_user_meta($profile_user_id, 'profile_visitors', true);
    if (!is_array($visitors))
        $visitors = [];

    $visitors = array_filter($visitors, function ($visit) use ($visitor_user_id) {
        return $visit['user_id'] != $visitor_user_id;
    });

    array_unshift($visitors, [
        'user_id' => $visitor_user_id,
        'visit_time' => current_time('timestamp'),
        'visit_date' => current_time('Y-m-d H:i:s')
    ]);

    $visitors = array_slice($visitors, 0, 50);

    update_user_meta($profile_user_id, 'profile_visitors', $visitors);
}
add_action('bp_after_member_header', 'sk_log_profile_visit');

// Dodajemy nową zakładkę do profilu
function sk_add_visitors_profile_tab()
{
    bp_core_new_nav_item(array(
        'name' => 'Odwiedzili mnie',
        'slug' => 'odwiedzili-mnie',
        'screen_function' => 'sk_show_profile_visitors_page',
        'position' => 80,
        'show_for_displayed_user' => true,
        'default_subnav_slug' => 'odwiedzili-mnie',
    ));
}
add_action('bp_setup_nav', 'sk_add_visitors_profile_tab', 100);

// Funkcja wyświetlająca listę odwiedzających
function sk_show_profile_visitors_page()
{
    add_action('bp_template_content', 'sk_render_profile_visitors');
    bp_core_load_template('members/single/plugins');
}

// Funkcja pomocnicza do formatowania czasu
function sk_format_visit_time($timestamp)
{
    $now = current_time('timestamp');
    $diff = $now - $timestamp;

    if ($diff < 60) {
        return 'przed chwilą';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes . ' min temu';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' godz. temu';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' dni temu';
    } else {
        return date('d.m.Y H:i', $timestamp);
    }
}

/**
 * Funkcja renderująca listę odwiedzających.
 * WERSJA Z UKRYWANIEM UŻYTKOWNIKA O ID=1
 */
function sk_render_profile_visitors()
{
    $user_id = bp_displayed_user_id();
    $current_user_id = get_current_user_id();

    if ($user_id != $current_user_id) {
        echo '<div class="bp-profile-visitors">';
        echo '<h3>Odwiedzili mnie</h3>';
        echo '<p>Możesz zobaczyć tylko swoją listę odwiedzających.</p>';
        echo '</div>';
        return;
    }

    $visitors = get_user_meta($user_id, 'profile_visitors', true);

    echo '<div class="bp-profile-visitors">';
    echo '<h3>Odwiedzili mnie</h3>';

    if (empty($visitors)) {
        echo '<p>Jeszcze nikt nie odwiedził Twojego profilu.</p>';
    } else {
        echo '<div style="max-width: 600px;">';
        foreach ($visitors as $visit) {
            if (is_array($visit) && isset($visit['user_id'])) {
                $visitor_id = $visit['user_id'];
                $visit_time = $visit['visit_time'];
                $formatted_time = sk_format_visit_time($visit_time);
            } else {
                $visitor_id = $visit;
                $formatted_time = 'dawno temu';
            }

            // --- POPRAWKA: Pomiń wyświetlanie wizyty, jeśli ID gościa to 1 ---
            if ($visitor_id == 1) {
                continue;
            }
            // --- KONIEC POPRAWKI ---

            $user = get_userdata($visitor_id);
            if (!$user)
                continue;

            echo '<div style="display: flex; align-items: center; padding: 15px; margin-bottom: 10px; background: #f9f9f9; border-radius: 8px; border-left: 4px solid #0073aa;">';
            echo '<div style="margin-right: 15px;">';
            echo bp_core_fetch_avatar(array('item_id' => $visitor_id, 'width' => 50, 'height' => 50));
            echo '</div>';
            echo '<div style="flex: 1;">';
            echo '<div style="margin-bottom: 5px;">';
            echo '<a href="' . bp_members_get_user_url($visitor_id) . '" style="font-weight: bold; color: #0073aa; text-decoration: none;">' . esc_html($user->display_name) . '</a>';
            echo '</div>';
            echo '<div style="font-size: 12px; color: #666;">';
            echo '<span style="margin-right: 10px;">👁️ Odwiedził(a): ' . $formatted_time . '</span>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';

        echo '<div style="margin-top: 20px;">';
        echo '<button onclick="clearVisitHistory()" style="background: #dc3545; color: white; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer;">Wyczyść historię odwiedzin</button>';
        echo '</div>';

        echo '<script>
        function clearVisitHistory() {
            if (confirm("Czy na pewno chcesz wyczyścić całą historię odwiedzin?")) {
                jQuery.post("' . admin_url('admin-ajax.php') . '", {
                    action: "clear_visit_history",
                    nonce: "' . wp_create_nonce('clear_visit_history') . '"
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert("Wystąpił błąd podczas czyszczenia historii.");
                    }
                });
            }
        }
        </script>';
    }

    echo '</div>';
}
// Dodaj funkcję AJAX do czyszczenia historii odwiedzin
add_action('wp_ajax_clear_visit_history', 'sk_clear_visit_history_ajax');
function sk_clear_visit_history_ajax()
{
    // Sprawdź nonce
    if (!wp_verify_nonce($_POST['nonce'], 'clear_visit_history')) {
        wp_send_json_error('Nieprawidłowy token bezpieczeństwa');
        return;
    }

    if (!is_user_logged_in()) {
        wp_send_json_error('Musisz być zalogowany');
        return;
    }

    $user_id = get_current_user_id();
    delete_user_meta($user_id, 'profile_visitors');

    wp_send_json_success('Historia odwiedzin została wyczyszczona');
}

// Funkcja do migracji starych danych (uruchom jednorazowo)
function sk_migrate_old_visitor_data()
{
    // Ta funkcja może być użyta do migracji starych danych
    // Można ją wywołać jednorazowo lub dodać do activation hook
    global $wpdb;

    $users_with_visitors = $wpdb->get_results("
        SELECT user_id, meta_value 
        FROM {$wpdb->usermeta} 
        WHERE meta_key = 'profile_visitors'
    ");

    foreach ($users_with_visitors as $user_meta) {
        $visitors = maybe_unserialize($user_meta->meta_value);
        if (is_array($visitors) && !empty($visitors)) {
            $needs_migration = false;
            $new_visitors = [];

            foreach ($visitors as $visitor) {
                if (!is_array($visitor)) {
                    // Stary format - tylko ID
                    $new_visitors[] = [
                        'user_id' => $visitor,
                        'visit_time' => current_time('timestamp') - rand(86400, 604800), // Losowa data z ostatniego tygodnia
                        'visit_date' => date('Y-m-d H:i:s', current_time('timestamp') - rand(86400, 604800))
                    ];
                    $needs_migration = true;
                } else {
                    // Nowy format - zostaw bez zmian
                    $new_visitors[] = $visitor;
                }
            }

            if ($needs_migration) {
                update_user_meta($user_meta->user_id, 'profile_visitors', $new_visitors);
            }
        }
    }
}

// =========================================================
// PREMIUM BADGE HEADER FIX (JS INJECTION - ROBUST V2)
// =========================================================
add_action('wp_footer', 'sk_add_header_avatar_badge_script');
function sk_add_header_avatar_badge_script() {
    if (!is_user_logged_in()) return;
    
    $user_id = get_current_user_id();
    $is_premium = sk_is_premium_user($user_id);

    if (!$is_premium) return;
    ?>
    <script>
    (function() {
        function log(msg) { console.log('[Premium Badge]: ' + msg); }

        function addPremiumBadgeToHeader() {
            // Broad selectors for any likely header avatar
            const potentialAvatars = document.querySelectorAll(
                'header img, .site-header img, #masthead img, .ast-mobile-header-wrap img, .elementor-widget-image img, .menu-item img, .nav-menu img'
            );

            log('Found ' + potentialAvatars.length + ' potential images in header areas.');

            let badgesAdded = 0;

            potentialAvatars.forEach(img => {
                // Filter: Must be small (icon size), likely square-ish, and not the logo
                const rect = img.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const ratio = rect.width / rect.height;

                // Rules for identifying a profile avatar:
                // 1. Size between 15px and 100px
                // 2. Aspect ratio close to 1 (square/circle)
                // 3. Not containing "logo" in class or src (unless it's a user logo?)
                // 4. parent is unlikely to be the main branding
                
                const isLogo = img.className.includes('logo') || img.src.includes('logo') || img.parentNode.className.includes('brand');
                const isIconSize = size > 15 && size < 120;
                const isSquareish = ratio > 0.8 && ratio < 1.2;

                if (isIconSize && isSquareish && !isLogo) {
                    // Check if already badged
                    const parent = img.parentNode;
                    if (parent.querySelector('.sk-header-badge')) return;

                    log('Targeting avatar: ' + img.src);

                    // Force parent position
                    const style = window.getComputedStyle(parent);
                    if (style.position === 'static') parent.style.position = 'relative';
                    if (style.overflow === 'hidden') parent.style.overflow = 'visible';

                    // Create Badge
                    const badge = document.createElement('span');
                    badge.className = 'sk-header-badge';
                    badge.innerHTML = '⭐';
                    badge.style.cssText = `
                        position: absolute;
                        top: -5px;
                        right: -5px;
                        z-index: 2147483647; /* MAX Z-INDEX */
                        font-size: 14px;
                        line-height: 1;
                        filter: drop-shadow(0 0 2px rgba(0,0,0,0.5));
                        pointer-events: none;
                        display: block !important;
                        visibility: visible !important;
                        opacity: 1 !important;
                    `;
                    
                    parent.appendChild(badge);
                    badgesAdded++;
                }
            });

            if (badgesAdded > 0) log('Successfully added ' + badgesAdded + ' badges.');
        }

        // Run on load and periodically to catch AJAX updates (like Elementor popups)
        window.addEventListener('load', addPremiumBadgeToHeader);
        document.addEventListener('DOMContentLoaded', addPremiumBadgeToHeader);
        setInterval(addPremiumBadgeToHeader, 2000); // Polling for SPA/AJAX changes
    })();
    </script>
    <?php
}


// Wywołaj migrację przy aktywacji (opcjonalnie)
// register_activation_hook(__FILE__, 'sk_migrate_old_visitor_data');


// Dodaj do twojego pliku functions.php

// === SYSTEM PREFERENCJI UŻYTKOWNIKA ===

// Dodaj zakładkę "Preferencje" do profilu BuddyPress
function sk_add_preferences_profile_tab()
{
    bp_core_new_nav_item(array(
        'name' => 'Preferencje',
        'slug' => 'preferencje',
        'screen_function' => 'sk_show_preferences_page',
        'position' => 90,
        'show_for_displayed_user' => true,
        'default_subnav_slug' => 'preferencje',
    ));
}
add_action('bp_setup_nav', 'sk_add_preferences_profile_tab', 100);

// Funkcja wyświetlająca stronę preferencji
function sk_show_preferences_page()
{
    add_action('bp_template_content', 'sk_render_preferences_page');
    bp_core_load_template('members/single/plugins');
}

// Renderowanie strony preferencji
function sk_render_preferences_page()
{
    $user_id = bp_displayed_user_id();
    $current_user_id = get_current_user_id();

    // Sprawdź czy użytkownik może edytować preferencje
    if ($user_id != $current_user_id) {
        echo '<div class="user-preferences">';
        echo '<h3>Preferencje</h3>';
        echo '<p>Możesz edytować tylko swoje preferencje.</p>';
        echo '</div>';
        return;
    }

    // Pobierz aktualne preferencje
    $preferences = get_user_meta($user_id, 'dating_preferences', true);
    if (!is_array($preferences)) {
        $preferences = [];
    }

    echo '<div class="user-preferences">';
    echo '<h3>Moje Preferencje Randkowe</h3>';
    echo '<p>Ustaw swoje preferencje, aby widzieć osoby, które Cię najbardziej interesują.</p>';

    echo '<form id="preferences-form" method="post">';
    wp_nonce_field('save_preferences', 'preferences_nonce');

    echo '<div style="max-width: 600px;">';

    // Wiek
    echo '<div style="margin-bottom: 25px; padding: 20px; background: #f9f9f9; border-radius: 8px;">';
    echo '<h4 style="margin-top: 0; color: #333;">🎂 Preferowany wiek</h4>';
    echo '<div style="display: flex; gap: 15px; align-items: center;">';
    echo '<div>';
    echo '<label for="min_age">Od:</label><br>';
    echo '<input type="number" id="min_age" name="min_age" min="18" max="80" value="' . ($preferences['min_age'] ?? 18) . '" style="width: 80px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">';
    echo '</div>';
    echo '<div>';
    echo '<label for="max_age">Do:</label><br>';
    echo '<input type="number" id="max_age" name="max_age" min="18" max="80" value="' . ($preferences['max_age'] ?? 50) . '" style="width: 80px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">';
    echo '</div>';
    echo '<span style="color: #666; font-size: 14px;">lat</span>';
    echo '</div>';
    echo '</div>';

    // Polityka
    echo '<div style="margin-bottom: 25px; padding: 20px; background: #f9f9f9; border-radius: 8px;">';
    echo '<h4 style="margin-top: 0; color: #333;">🏛️ Preferowana polityka</h4>';
    echo '<div>';
    $politics_options = ['', 'Konserwatyzm', 'Liberalizm'];
    foreach ($politics_options as $option) {
        $checked = ($preferences['politics'] ?? '') === $option ? 'checked' : '';
        $label = $option ?: 'Bez preferencji';
        echo '<label style="display: block; margin-bottom: 8px;">';
        echo '<input type="radio" name="politics" value="' . $option . '" ' . $checked . ' style="margin-right: 8px;">';
        echo $label;
        echo '</label>';
    }
    echo '</div>';
    echo '</div>';

    // Dieta
    echo '<div style="margin-bottom: 25px; padding: 20px; background: #f9f9f9; border-radius: 8px;">';
    echo '<h4 style="margin-top: 0; color: #333;">🥗 Preferowana dieta</h4>';
    echo '<div>';
    $diet_options = ['', 'Vege', 'Mięso'];
    foreach ($diet_options as $option) {
        $checked = ($preferences['dieta'] ?? '') === $option ? 'checked' : '';
        $label = $option ?: 'Bez preferencji';
        echo '<label style="display: block; margin-bottom: 8px;">';
        echo '<input type="radio" name="dieta" value="' . $option . '" ' . $checked . ' style="margin-right: 8px;">';
        echo $label;
        echo '</label>';
    }
    echo '</div>';
    echo '</div>';

    // Religia
    echo '<div style="margin-bottom: 25px; padding: 20px; background: #f9f9f9; border-radius: 8px;">';
    echo '<h4 style="margin-top: 0; color: #333;">⛪ Preferowana religia</h4>';
    echo '<div>';
    $religion_options = ['', 'Wierzący', 'Niewierzący'];
    foreach ($religion_options as $option) {
        $checked = ($preferences['religia'] ?? '') === $option ? 'checked' : '';
        $label = $option ?: 'Bez preferencji';
        echo '<label style="display: block; margin-bottom: 8px;">';
        echo '<input type="radio" name="religia" value="' . $option . '" ' . $checked . ' style="margin-right: 8px;">';
        echo $label;
        echo '</label>';
    }
    echo '</div>';
    echo '</div>';

    // Styl pracy
    echo '<div style="margin-bottom: 25px; padding: 20px; background: #f9f9f9; border-radius: 8px;">';
    echo '<h4 style="margin-top: 0; color: #333;">💼 Preferowany styl pracy</h4>';
    echo '<div>';
    $work_options = ['', 'Stabilna', 'Przedsiębiorca'];
    foreach ($work_options as $option) {
        $checked = ($preferences['styl_pracy'] ?? '') === $option ? 'checked' : '';
        $label = $option ?: 'Bez preferencji';
        echo '<label style="display: block; margin-bottom: 8px;">';
        echo '<input type="radio" name="styl_pracy" value="' . $option . '" ' . $checked . ' style="margin-right: 8px;">';
        echo $label;
        echo '</label>';
    }
    echo '</div>';
    echo '</div>';

    // Minimalne dopasowanie
    echo '<div style="margin-bottom: 25px; padding: 20px; background: #f9f9f9; border-radius: 8px;">';
    echo '<h4 style="margin-top: 0; color: #333;">⭐ Minimalne dopasowanie</h4>';
    echo '<div>';
    echo '<label for="min_match">Pokaż osoby z dopasowaniem co najmniej:</label><br>';
    echo '<select name="min_match" id="min_match" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px; width: 200px;">';
    $match_options = [0 => 'Wszystkie', 25 => '25%', 50 => '50%', 75 => '75%', 100 => '100%'];
    foreach ($match_options as $value => $label) {
        $selected = ($preferences['min_match'] ?? 0) == $value ? 'selected' : '';
        echo '<option value="' . $value . '" ' . $selected . '>' . $label . '</option>';
    }
    echo '</select>';
    echo '</div>';
    echo '</div>';

    // Przycisk zapisz
    echo '<div style="text-align: center; margin-top: 30px;">';
    echo '<button type="submit" style="background: #0073aa; color: white; padding: 12px 24px; border: none; border-radius: 6px; font-size: 16px; cursor: pointer;">💾 Zapisz preferencje</button>';
    echo '</div>';

    echo '</div>';
    echo '</form>';

    // Pokaż podgląd aktualnych preferencji
    echo '<div style="margin-top: 40px; padding: 20px; background: #e8f4f8; border-radius: 8px; border-left: 4px solid #0073aa;">';
    echo '<h4 style="margin-top: 0;">📋 Twoje aktualne preferencje:</h4>';
    echo '<ul style="margin: 0; padding-left: 20px;">';
    echo '<li>Wiek: ' . ($preferences['min_age'] ?? 18) . ' - ' . ($preferences['max_age'] ?? 50) . ' lat</li>';
    echo '<li>Polityka: ' . ($preferences['politics'] ?: 'Bez preferencji') . '</li>';
    echo '<li>Dieta: ' . ($preferences['dieta'] ?: 'Bez preferencji') . '</li>';
    echo '<li>Religia: ' . ($preferences['religia'] ?: 'Bez preferencji') . '</li>';
    echo '<li>Styl pracy: ' . ($preferences['styl_pracy'] ?: 'Bez preferencji') . '</li>';
    echo '<li>Minimalne dopasowanie: ' . ($preferences['min_match'] ?? 0) . '%</li>';
    echo '</ul>';
    echo '</div>';

    // JavaScript do obsługi formularza
    echo '<script>
    jQuery(document).ready(function($) {
        $("#preferences-form").on("submit", function(e) {
            e.preventDefault();
            
            var formData = $(this).serialize();
            
            $.ajax({
                url: "' . admin_url('admin-ajax.php') . '",
                type: "POST",
                data: formData + "&action=save_user_preferences",
                success: function(response) {
                    if (response.success) {
                        alert("✅ Preferencje zostały zapisane!");
                        location.reload();
                    } else {
                        alert("❌ Wystąpił błąd: " + response.data);
                    }
                },
                error: function() {
                    alert("❌ Wystąpił błąd podczas zapisywania.");
                }
            });
        });
        
        // Walidacja wieku
        $("#min_age, #max_age").on("change", function() {
            var minAge = parseInt($("#min_age").val());
            var maxAge = parseInt($("#max_age").val());
            
            if (minAge > maxAge) {
                alert("⚠️ Minimalny wiek nie może być większy od maksymalnego!");
                if (this.id === "min_age") {
                    $("#min_age").val(maxAge);
                } else {
                    $("#max_age").val(minAge);
                }
            }
        });
    });
    </script>';

    echo '</div>';
}

// AJAX handler dla zapisywania preferencji
add_action('wp_ajax_save_user_preferences', 'sk_save_user_preferences_ajax');
function sk_save_user_preferences_ajax()
{
    // Sprawdź nonce
    if (!wp_verify_nonce($_POST['preferences_nonce'], 'save_preferences')) {
        wp_send_json_error('Nieprawidłowy token bezpieczeństwa');
        return;
    }

    if (!is_user_logged_in()) {
        wp_send_json_error('Musisz być zalogowany');
        return;
    }

    $user_id = get_current_user_id();

    // Sanityzacja danych
    $preferences = [
        'min_age' => intval($_POST['min_age'] ?? 18),
        'max_age' => intval($_POST['max_age'] ?? 50),
        'politics' => sanitize_text_field($_POST['politics'] ?? ''),
        'dieta' => sanitize_text_field($_POST['dieta'] ?? ''),
        'religia' => sanitize_text_field($_POST['religia'] ?? ''),
        'styl_pracy' => sanitize_text_field($_POST['styl_pracy'] ?? ''),
        'min_match' => intval($_POST['min_match'] ?? 0),
        'updated_at' => current_time('Y-m-d H:i:s')
    ];

    // Walidacja wieku
    if ($preferences['min_age'] < 18 || $preferences['min_age'] > 80) {
        wp_send_json_error('Nieprawidłowy minimalny wiek');
        return;
    }

    if ($preferences['max_age'] < 18 || $preferences['max_age'] > 80) {
        wp_send_json_error('Nieprawidłowy maksymalny wiek');
        return;
    }

    if ($preferences['min_age'] > $preferences['max_age']) {
        wp_send_json_error('Minimalny wiek nie może być większy od maksymalnego');
        return;
    }

    // Zapisz preferencje
    update_user_meta($user_id, 'dating_preferences', $preferences);

    wp_send_json_success('Preferencje zostały zapisane');
}

// === MODYFIKACJA ISTNIEJĄCYCH FUNKCJI ===

// =========================================================================
// === SYSTEM WYŚWIETLANIA UŻYTKOWNIKÓW (WERSJA FINAŁOWA Z DEBUGGINGIEM) ===
// =========================================================================

// Funkcje pomocnicze (bez zmian)
if (!function_exists('sk_get_user_age')) {
    function sk_get_user_age($user_id)
    {
        $birth_date = bp_get_profile_field_data(['field' => 'Data urodzenia', 'user_id' => $user_id]);
        if (empty($birth_date))
            return null;
        return floor((time() - strtotime($birth_date)) / 31556926);
    }
}
if (!function_exists('calculate_match_percentage')) {
    function calculate_match_percentage($user1_id, $user2_id)
    {
        if ($user1_id == $user2_id)
            return 100;
        $fields_to_compare = [
            'Polityka', 
            'Dieta', 
            'Religia', 
            'Styl pracy',
            'Alkohol',
            'Dzieci',
            'Papierosy'
        ];
        $matched_fields = 0;
        $total_fields = 0;
        foreach ($fields_to_compare as $field) {
            $raw1 = bp_get_profile_field_data(['field' => $field, 'user_id' => $user1_id]);
            $val1 = is_array($raw1) ? trim(implode(', ', $raw1)) : trim((string)$raw1);
            $raw2 = bp_get_profile_field_data(['field' => $field, 'user_id' => $user2_id]);
            $val2 = is_array($raw2) ? trim(implode(', ', $raw2)) : trim((string)$raw2);
            if ($val1 !== '' && $val2 !== '') {
                $total_fields++;
                if ($val1 === $val2)
                    $matched_fields++;
            }
        }
        if ($total_fields === 0)
            return 0;
        return round(($matched_fields / $total_fields) * 100);
    }
}

/**
 * Get match percentage using the BP Match Me plugin's weighted calculation.
 * Falls back to the simple calculate_match_percentage if the plugin is not available.
 */
function sk_get_bp_match_percentage($user1_id, $user2_id) {
    static $match_me_obj = null;
    static $plugin_loaded = false;

    if (!$plugin_loaded) {
        $match_me_plugin_file = WP_PLUGIN_DIR . '/match-me-for-buddypress/match-me-for-buddypress.php';
        if (file_exists($match_me_plugin_file)) {
            require_once($match_me_plugin_file);
        }
        if (class_exists('Mp_BP_Match')) {
            $match_me_obj = new Mp_BP_Match();
        }
        $plugin_loaded = true;
    }

    if ($match_me_obj && is_callable([$match_me_obj, 'hmk_get_matching_percentage_number'])) {
        return intval($match_me_obj->hmk_get_matching_percentage_number($user2_id, $user1_id));
    }

    // Fallback to simple calculation
    return calculate_match_percentage($user1_id, $user2_id);
}

/**
 * Główna funkcja AJAX do ładowania siatki użytkowników.
 * WERSJA Z WYKLUCZENIEM UŻYTKOWNIKA O ID=1
 */
/**
 * Główna funkcja AJAX do ładowania siatki użytkowników.
 * WERSJA Z POPRAWIONYM ZNAKIEM ZODIAKU I WYKLUCZENIEM ID=1
 */
function load_users_grid_with_preferences_ajax()
{
    ob_start();

    $match_me_plugin_file = WP_PLUGIN_DIR . '/match-me-for-buddypress/match-me-for-buddypress.php';
    if (file_exists($match_me_plugin_file)) {
        require_once($match_me_plugin_file);
    }
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Błąd uprawnień.']);
        return;
    }

    $match_me_obj = null;
    if (class_exists('Mp_BP_Match')) {
        $match_me_obj = new Mp_BP_Match();
    }

    $current_user_id = get_current_user_id();
    $my_likes = get_user_meta($current_user_id, 'sk_user_likes', true) ?: [];
    $liked_me = get_user_meta($current_user_id, 'sk_liked_by_users', true) ?: [];

    $blocked_users_list = get_user_meta($current_user_id, 'sk_blocked_users', true);
    if (!is_array($blocked_users_list))
        $blocked_users_list = [];

    // Shadow Ban: Pobieranie ukrytych użytkowników
    $hidden_users_list = function_exists('sk_get_hidden_user_ids') ? sk_get_hidden_user_ids() : [];

    $exclude_ids = array_unique(array_merge([1, $current_user_id], $blocked_users_list, $hidden_users_list));

    $seeking_preference = trim(bp_get_profile_field_data(['field' => 338, 'user_id' => $current_user_id]));
    $target_gender = '';
    if ($seeking_preference === 'Kobiety')
        $target_gender = 'Kobieta';
    elseif ($seeking_preference === 'Mężczyzny')
        $target_gender = 'Mężczyzna';

    $all_users = get_users([
        'exclude' => $exclude_ids,
    ]);

    $results = [];
    foreach ($all_users as $user) {
        $user_id = $user->ID;
        if (!empty($target_gender)) {
            if (trim(bp_get_profile_field_data(['field' => 129, 'user_id' => $user_id])) !== $target_gender) {
                continue;
            }
        }

        $match_percentage = 0;
        if ($match_me_obj && is_callable([$match_me_obj, 'hmk_get_matching_percentage_number'])) {
            $match_percentage = $match_me_obj->hmk_get_matching_percentage_number($user_id, $current_user_id);
        }
        // --- FILTERING LOGIC ---
        // Get filters from POST or use defaults
        $filter_religion = isset($_POST['filters']['religion']) ? sanitize_text_field($_POST['filters']['religion']) : '';
        $filter_politics = isset($_POST['filters']['politics']) ? sanitize_text_field($_POST['filters']['politics']) : '';
        $filter_work = isset($_POST['filters']['work']) ? sanitize_text_field($_POST['filters']['work']) : '';
        $filter_diet = isset($_POST['filters']['diet']) ? sanitize_text_field($_POST['filters']['diet']) : '';
        $filter_min_age = isset($_POST['filters']['min_age']) ? intval($_POST['filters']['min_age']) : 18;
        $filter_max_age = isset($_POST['filters']['max_age']) ? intval($_POST['filters']['max_age']) : 100;

        // Filter by Age
        $birth_date_raw = bp_get_profile_field_data(['field' => 107, 'user_id' => $user_id]);
        if ($birth_date_raw) {
             $age = floor((time() - strtotime($birth_date_raw)) / 31556926);
             if ($age < $filter_min_age || $age > $filter_max_age) {
                 continue;
             }
        }

        // Filter by details
        if (!empty($filter_religion)) {
            $user_religion = bp_get_profile_field_data(['field' => 'Podejście do wiary', 'user_id' => $user_id]);
            if ($user_religion !== $filter_religion) continue;
        }

        if (!empty($filter_politics)) {
            $user_politics = bp_get_profile_field_data(['field' => 'Poglądy polityczne', 'user_id' => $user_id]);
            if ($user_politics !== $filter_politics) continue;
        }

        if (!empty($filter_work)) {
            $user_work = bp_get_profile_field_data(['field' => 'Styl pracy', 'user_id' => $user_id]);
            if ($user_work !== $filter_work) continue;
        }

        if (!empty($filter_diet)) {
            // Note: Field name varies, checking 'Styl jedzenia' based on previous lines
            $user_diet = bp_get_profile_field_data(['field' => 'Styl jedzenia', 'user_id' => $user_id]);
             if ($user_diet !== $filter_diet) continue;
        }

        $location = bp_get_profile_field_data(['field' => 'Lokalizacja', 'user_id' => $user_id]);
        $bio = bp_get_profile_field_data(['field' => 343, 'user_id' => $user_id]);
        $last_active_time = bp_get_user_last_activity($user_id);
        $last_active_formatted = $last_active_time ? bp_core_time_since($last_active_time) : 'Nigdy';
        $last_active_timestamp = $last_active_time ? strtotime($last_active_time) : 0;

        // --- POPRAWKA: Pobieramy datę urodzenia (RAW) po ID pola (107) - pewniejsze niż nazwa ---
        $birth_date = xprofile_get_field_data(107, $user_id); 
        
        // Fallback for array return
        if (is_array($birth_date)) $birth_date = reset($birth_date);
        if (is_object($birth_date)) $birth_date = ''; 
        
        $numerology_number = sk_calculate_life_path_number($birth_date);
        $zodiac_sign = get_zodiac_sign($birth_date); 
        
        // DEBUG LOGGING - ID 107
        // error_log("User ID: $user_id | Birth Date (ID 107): " . print_r($birth_date, true) . " | Zodiac: $zodiac_sign");

        $results[] = [
            'id' => $user_id,
            'name' => $user->display_name,
            'match' => intval($match_percentage),
            'profile_url' => bp_members_get_user_url($user_id),
            // Użyj bp_core_fetch_avatar dla lepszej jakości (type=full)
            'avatar' => bp_core_fetch_avatar(['item_id' => $user_id, 'type' => 'full', 'html' => false]),
            'location' => $location ? esc_html($location) : 'Brak lokalizacji',
            'bio' => $bio ? esc_html(wp_trim_words($bio, 15, '...')) : '',
            'details' => [
                'Wiara' => bp_get_profile_field_data(['field' => 'Podejście do wiary', 'user_id' => $user_id]) ?: 'Nie podano',
                'Polityka' => bp_get_profile_field_data(['field' => 'Poglądy Polityczne', 'user_id' => $user_id]) ?: 'Nie podano',
                'Styl Pracy' => bp_get_profile_field_data(['field' => 'Styl Pracy', 'user_id' => $user_id]) ?: 'Nie podano',
                'Styl Jedzenia' => bp_get_profile_field_data(['field' => 'Styl Jedzenia', 'user_id' => $user_id]) ?: 'Nie podano',
            ],
            'last_active_text' => $last_active_formatted,
            'last_active_timestamp' => $last_active_timestamp,
            'is_liked_by_me' => in_array($user_id, $my_likes),
            'is_match' => (in_array($user_id, $my_likes) && in_array($user_id, $liked_me)),
            'numerology' => $numerology_number,
            'zodiac_sign' => $zodiac_sign,
        ];

    }

    usort($results, function ($a, $b) {
        if ($b['match'] !== $a['match'])
            return $b['match'] - $a['match'];
        return strcmp($a['name'], $b['name']);
    });

    ob_end_clean();
    wp_send_json_success($results);
}
// Poprawne podpięcie funkcji do systemu AJAX WordPressa.
remove_action('wp_ajax_load_users_grid', 'load_users_grid_with_preferences_ajax'); // Usuwa ewentualne stare podpięcia
remove_action('wp_ajax_nopriv_load_users_grid', 'load_users_grid_with_preferences_ajax'); // Usuwa ewentualne stare podpięcia
add_action('wp_ajax_load_users_grid', 'load_users_grid_with_preferences_ajax');
add_action('wp_ajax_nopriv_load_users_grid', 'load_users_grid_with_preferences_ajax');


/**
 * Shortcode statystyk preferencji.
 * WERSJA OSTATECZNA - Zintegrowana z wtyczką "Match Me for BuddyPress".
 */
function sk_preferences_stats_shortcode()
{
    if (!is_user_logged_in())
        return '<p>Musisz być zalogowany, aby zobaczyć statystyki.</p>';

    // Ręczne ładowanie pliku wtyczki także tutaj, dla pewności.
    $match_me_plugin_file = WP_PLUGIN_DIR . '/match-me-for-buddypress/match-me-for-buddypress.php';
    if (file_exists($match_me_plugin_file))
        require_once($match_me_plugin_file);

    $match_me_obj = null;
    if (class_exists('Mp_BP_Match'))
        $match_me_obj = new Mp_BP_Match();

    $current_user_id = get_current_user_id();
    $preferences = get_user_meta($current_user_id, 'dating_preferences', true);

    if (empty($preferences)) {
        return '<div style="padding: 20px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px; margin: 10px 0;">
                    <strong>💡 Wskazówka:</strong> Ustaw swoje preferencje, aby widzieć bardziej dopasowane osoby!
                    <br><a href="' . bp_loggedin_user_domain() . 'preferencje/" style="color: #0073aa;">Ustaw preferencje →</a>
                </div>';
    }

    $all_users = get_users(['exclude' => [$current_user_id], 'fields' => ['ID']]);
    $matching_users = 0;

    foreach ($all_users as $user) {
        $user_id = $user->ID;

        $user_age = sk_get_user_age($user_id);
        if ($user_age !== null && ($user_age < ($preferences['min_age'] ?? 18) || $user_age > ($preferences['max_age'] ?? 80))) {
            continue;
        }

        if (!empty($preferences['politics']) && $preferences['politics'] !== bp_get_profile_field_data(['field' => 'Polityka', 'user_id' => $user_id]))
            continue;
        if (!empty($preferences['dieta']) && $preferences['dieta'] !== bp_get_profile_field_data(['field' => 'Dieta', 'user_id' => $user_id]))
            continue;
        if (!empty($preferences['religia']) && $preferences['religia'] !== bp_get_profile_field_data(['field' => 'Religia', 'user_id' => $user_id]))
            continue;
        if (!empty($preferences['styl_pracy']) && $preferences['styl_pracy'] !== bp_get_profile_field_data(['field' => 'Styl pracy', 'user_id' => $user_id]))
            continue;

        if ($match_me_obj && is_callable([$match_me_obj, 'hmk_get_matching_percentage_number'])) {
            $match_percentage = $match_me_obj->hmk_get_matching_percentage_number($user_id, $current_user_id);
            if ($match_percentage < ($preferences['min_match'] ?? 0))
                continue;
        }

        $matching_users++;
    }

    $total_users = count($all_users);
    $percentage = $total_users > 0 ? round(($matching_users / $total_users) * 100) : 0;

    return '<div style="padding: 20px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 5px; margin: 10px 0;">
                <strong>📊 Twoje preferencje:</strong><br>
                Znaleziono <strong>' . $matching_users . '</strong> z ' . $total_users . ' osób (' . $percentage . '%) spełniających Twoje kryteria.
                <br><a href="' . bp_loggedin_user_domain() . 'preferencje/" style="color: #0073aa;">Zmień preferencje →</a>
            </div>';
}
function odwiedzili_mnie_link_shortcode($atts)
{
    if (is_user_logged_in()) {
        $current_user = wp_get_current_user();
        $user_nicename = $current_user->user_nicename;
        $url = site_url('/czlonkowie/' . $user_nicename . '/odwiedzili-mnie/');

        $atts = shortcode_atts(array(
            'tekst' => '👀 Odwiedzili mnie',
        ), $atts);

        return '<a href="' . esc_url($url) . '" style="display: block; padding: 10px; text-decoration: none;">' . esc_html($atts['tekst']) . '</a>';
    } else {
        return '';
    }
}
add_shortcode('odwiedzili_mnie_link', 'odwiedzili_mnie_link_shortcode');

add_action('after_setup_theme', 'hide_admin_bar_for_users');
function hide_admin_bar_for_users()
{
    if (!is_admin()) {
        show_admin_bar(false);
    }
}
if (!is_user_logged_in()) {
    add_filter('show_admin_bar', '__return_false');
}

function custom_email_login_authenticate($user, $username, $password)
{
    if (is_email($username)) {
        $user_data = get_user_by('email', $username);
        if ($user_data) {
            $username = $user_data->user_login;
        }
    }
    return wp_authenticate_username_password(null, $username, $password);
}
remove_filter('authenticate', 'wp_authenticate_username_password', 20);
add_filter('authenticate', 'custom_email_login_authenticate', 20, 3);

function get_unread_message_count()
{
    if (is_user_logged_in() && function_exists('messages_get_unread_count')) {
        $user_id = get_current_user_id();
        $count = messages_get_unread_count($user_id);
        return intval($count);
    }
    return 0;
}

add_shortcode('liczba_wiadomosci', function () {
    $count = get_unread_message_count();
    if ($count > 0) {
        return '<span class="badge-wiadomosci">' . $count . '</span>';
    }
    return '';
});

// AJAX endpoint dla sprawdzania nowych wiadomości
function sprawdz_nowe_wiadomosci()
{
    if (!is_user_logged_in()) {
        wp_die();
    }

    $user_id = get_current_user_id();
    $unread_count = 0;

    // Sprawdź nieprzeczytane wiadomości BuddyPress
    if (function_exists('messages_get_unread_count')) {
        $unread_count = messages_get_unread_count($user_id);
    }

    wp_send_json_success($unread_count);
}
add_action('wp_ajax_sprawdz_nowe_wiadomosci', 'sprawdz_nowe_wiadomosci');

function dodaj_sprawdzanie_wiadomosci_script()
{
    if (!is_user_logged_in())
        return;
    ?>
    <script>
        (function ($) {
            let messageCheckInterval = null;

            function sprawdzNoweWiadomosci() {
                // Jeśli strona jest ukryta (np. inna karta), nie sprawdzaj
                if (document.hidden) return;

                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: { action: 'sprawdz_nowe_wiadomosci' },
                    success: function (response) {
                        if (response && response.success) {
                            var count = parseInt(response.data) || 0;

                            // Szukamy badge (licznik wiadomości)
                            var badge = $('#badge-wiadomosci');
                            if (badge.length === 0) badge = $('.badge').first();

                            if (count > 0) {
                                badge.text(count).show().css({
                                    'background': '#ff4444',
                                    'color': 'white',
                                    'border-radius': '50%',
                                    'padding': '2px 6px',
                                    'font-size': '12px',
                                    'display': 'inline-block'
                                });
                            } else {
                                badge.hide();
                            }
                        }
                    }
                });
            }

            $(document).ready(function () {
                // Pierwsze sprawdzenie po 2 sekundach
                setTimeout(sprawdzNoweWiadomosci, 2000);

                // Kolejne sprawdzenia co 60 sekund (zamiast 10)
                messageCheckInterval = setInterval(sprawdzNoweWiadomosci, 60000);
            });

        })(jQuery);
    </script>
    <?php
}
//add_action('wp_footer', 'dodaj_sprawdzanie_wiadomosci_script');


// Hook do Better Notifications (jeśli dostępny)
add_action('bn_notification_sent', function ($notification) {
    // Możesz tu dodać własną logikę
});
/**
 * =========================================================================
 * WERSJA OSTATECZNA 6.4: Kompletna funkcja aktywacji z KONTROLĄ WIDOCZNOŚCI DATY
 * =========================================================================
 */
add_action('bp_core_activated_user', 'prawidlowa_aktywacja_uzytkownika', 99, 3);

function prawidlowa_aktywacja_uzytkownika($user_id, $key, $user)
{
    global $wpdb;
    
    error_log("prawidlowa_aktywacja_uzytkownika called for user_id: $user_id, key: $key");
    error_log("User object type: " . gettype($user));
    error_log("User object: " . print_r($user, true));
    
    $meta = null;
    
    // Metoda 1: Pobierz meta z obiektu $user (przekazywany przez hook)
    if (is_object($user) && isset($user->meta)) {
        $meta = maybe_unserialize($user->meta);
        error_log("Got meta from \$user object");
    } elseif (is_array($user) && isset($user['meta'])) {
        $meta = maybe_unserialize($user['meta']);
        error_log("Got meta from \$user array");
    }
    
    // Metoda 2: Fallback - pobierz z tabeli signups
    if (empty($meta)) {
        $signup = $wpdb->get_row($wpdb->prepare("SELECT meta FROM {$wpdb->prefix}signups WHERE activation_key = %s", $key));
        if ($signup && $signup->meta) {
            $meta = maybe_unserialize($signup->meta);
            error_log("Got meta from signups table");
        }
    }
    
    if (empty($meta)) {
        error_log("No meta found for key: $key");
        return;
    }
    
    error_log("Meta keys: " . print_r(array_keys($meta), true));
    error_log("Has temp_password_for_activation: " . (isset($meta['temp_password_for_activation']) ? 'YES' : 'NO'));

    // 2. Poprawka na hasło
    if (isset($meta['temp_password_for_activation']) && !empty($meta['temp_password_for_activation'])) {
       error_log("Setting password for user $user_id");
       wp_set_password($meta['temp_password_for_activation'], $user_id);
       error_log("Password set successfully");
    } else {
       error_log("No temp_password_for_activation found in meta!");
    }

    // 3. Ustaw pola profilu xProfile
    if (bp_is_active('xprofile')) {
        $mapa_id_pol = [
            1 => 'first_name',
            2 => 'last_name',
            129 => 'gender',
            338 => 'Szukam',
            198 => 'relationship_search',
            218 => 'relationship_status',
            290 => 'has_children',
            295 => 'want_children',
            226 => 'preferencje_jedzeniowe',
            136 => 'religion',
            133 => 'approach_to_faith',
            157 => 'reincarnation_belief',
            160 => 'alternative_spirituality',
            303 => 'zodiac_sign',
            108 => 'styl_pracy',
            114 => 'luksus',
            185 => 'ryzyko',
            298 => 'mieszkanie',
            215 => 'polityka_skrot',
            190 => 'identyfikacja_polityczna',
            286 => 'alkohol',
            236 => 'friendly_420',
            316 => 'typ_ciala',
            324 => 'cwiczenia',
            329 => 'czyta',
            206 => 'jezyki',
            'dieta' => 'dieta',
            343 => 'field_343',
            345 => 'field_345',
            107 => 'birth_date'
        ];

        foreach ($meta as $klucz_z_sesji => $wartosc) {
            $field_id = array_search($klucz_z_sesji, $mapa_id_pol);
            if ($field_id !== false && !empty($wartosc)) {
                // Specjalne formatowanie dla daty urodzenia
                if ($field_id == 107) {
                    $timestamp = strtotime($wartosc);
                    if ($timestamp !== false) {
                        $wartosc_do_zapisu = date('Y-m-d 00:00:00', $timestamp);
                    } else {
                        continue; // Pomiń, jeśli data nieprawidłowa
                    }
                } else {
                    $wartosc_do_zapisu = wp_kses_post($wartosc);
                }
                xprofile_set_field_data($field_id, $user_id, $wartosc_do_zapisu);
            }
        }

        // === NOWY KOD: Ustawianie widoczności pola daty urodzenia ===
        if (isset($meta['widocznosc_daty'])) {
            // Upewniamy się, że przekazujemy poprawną wartość ('public', 'loggedin', 'friends', 'adminsonly')
            $poziom_widocznosci = ($meta['widocznosc_daty'] === 'public') ? 'public' : 'adminsonly';
            xprofile_set_field_visibility_level(107, $user_id, $poziom_widocznosci);
        }
        // === KONIEC NOWEGO KODU ===

        if (!empty($meta['first_name']))
            wp_update_user(['ID' => $user_id, 'first_name' => sanitize_text_field($meta['first_name'])]);
        if (!empty($meta['last_name']))
            wp_update_user(['ID' => $user_id, 'last_name' => sanitize_text_field($meta['last_name'])]);
    }

    // 4. Ustaw awatar użytkownika (bez zmian)
    $sciezka_awatara = $meta['temp_avatar_path_for_activation'] ?? null;
    if ($sciezka_awatara && file_exists($sciezka_awatara)) {
        if (!function_exists('bp_core_avatar_upload_path')) {
            require_once(WP_PLUGIN_DIR . '/buddypress/bp-core/bp-core-avatars.php');
        }
        $avatar_upload_path = bp_core_avatar_upload_path() . '/avatars/' . $user_id;
        wp_mkdir_p($avatar_upload_path);
        array_map('unlink', glob($avatar_upload_path . '/*'));

        $editor = wp_get_image_editor($sciezka_awatara);
        if (!is_wp_error($editor)) {
            $full_width = defined('BP_AVATAR_FULL_WIDTH') ? BP_AVATAR_FULL_WIDTH : 150;
            $editor->resize($full_width, $full_width, true);
            $editor->save($avatar_upload_path . '/' . time() . '-bpfull.jpg');

            $thumb_width = defined('BP_AVATAR_THUMB_WIDTH') ? BP_AVATAR_THUMB_WIDTH : 50;
            $editor->resize($thumb_width, $thumb_width, true);
            $editor->save($avatar_upload_path . '/' . time() . '-bpthumb.jpg');

            @unlink($sciezka_awatara);
        }
    }
}


function my_enqueue_lightbox_scripts()
{
    if (function_exists('bp_is_user') && bp_is_user()) {
        wp_enqueue_style('lightbox-css', 'https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.3/css/lightbox.min.css');
        wp_enqueue_script('lightbox-js', 'https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.3/js/lightbox.min.js', array('jquery'), '2.11.3', true);
    }
}
add_action('wp_enqueue_scripts', 'my_enqueue_lightbox_scripts');

/**
 * =========================================================================
 * === NOWY PANEL BOCZNY UŻYTKOWNIKA
 * =========================================================================
 */

function sk_get_profile_completion_percentage($user_id = 0)
{
    if (!$user_id) {
        $user_id = get_current_user_id();
    }

    // Zdefiniuj, które pola są "ważne" dla ukończenia profilu.
    // Dostosuj nazwy pól do swojej konfiguracji!
    $important_fields = [
        'O mnie',
        'Lokalizacja',
        'Preferencje Jedzeniowe',
        'Identyfikacja Polityczna',
        'Religia',
        'Styl pracy',
        'Status relacji', // Dodaj więcej pól, jeśli chcesz
        'Czy ma dzieci',
    ];

    $total_points = count($important_fields) + 1; // +1 punkt za posiadanie awatara
    $completed_points = 0;

    // Sprawdź, czy użytkownik ma awatar
    if (bp_get_user_has_avatar($user_id)) {
        $completed_points++;
    }

    // Sprawdź każde pole z listy
    foreach ($important_fields as $field_name) {
        $field_data = bp_get_profile_field_data(['field' => $field_name, 'user_id' => $user_id]);
        if (!empty($field_data)) {
            $completed_points++;
        }
    }

    if ($total_points === 0)
        return 0;

    return floor(($completed_points / $total_points) * 100);
}


/**
 * =========================================================================
 * Shortcode panelu bocznego [lewy_panel_uzytkownika]
 * WERSJA Z POPRAWIONĄ STRUKTURĄ HTML
 * =========================================================================
 */
function lewy_panel_uzytkownika_shortcode()
{
    if (!is_user_logged_in()) {
        return '<div class="widget-container">Zaloguj się, aby zobaczyć ten panel.</div>';
    }

    $current_user_id = get_current_user_id();

    ob_start();
    ?>
    <aside class="left-panel-container">

        <div class="widget">
            <ul class="widget-nav-list">
                <li><a href="<?php echo bp_loggedin_user_domain(); ?>">👤 Mój Profil</a></li>
                <li><a href="<?php echo bp_loggedin_user_domain() . bp_get_messages_slug(); ?>">✉️ Wiadomości</a>
                    <?php if (function_exists('get_unread_message_count')) {
                        $unread_count = get_unread_message_count();
                        $style = $unread_count > 0 ? '' : 'style="display: none;"';
                        echo '<span class="nav-badge" id="badge-wiadomosci" ' . $style . '>' . $unread_count . '</span>';
                    } ?>
                </li>
            </ul>
        </div>

        <div class="widget">
            <h3 class="widget-title accordion-trigger">Szybkie Filtrowanie<span class="widget-arrow">▼</span></h3>
            <div class="widget-content accordion-content" style="display: block;">
                <form id="user-filters-form">

                    <div style="margin-bottom: 10px;">
                        <label for="filter-poglady" style="font-weight: 500; font-size: 0.9em;  color: #e0e0e0;">W skrócie
                            Polityka:</label>
                        <select name="poglady" id="filter-poglady" class="user-filter-control"
                            style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; margin-top: 5px;">
                            <option value="">Wszystkie</option>
                            <option value="Konserwatyzm">Konserwatyzm</option>
                            <option value="Liberalizm">Liberalizm</option>
                            <option value="Nie interesuje mnie">Nie interesuje mnie</option>
                        </select>
                    </div>

                    <div style="margin-bottom: 10px;">
                        <label for="filter-religia" style="font-weight: 500; font-size: 0.9em;  color: #e0e0e0; ">Ogólne
                            Podejście do Wiary:</label>
                        <select name="religia" id="filter-religia" class="user-filter-control"
                            style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; margin-top: 5px;">
                            <option value="">Wszystkie</option>
                            <option value="Wierzący">Wierzący</option>
                            <option value="Niewierzący">Niewierzący</option>
                        </select>
                    </div>

                    <div style="margin-bottom: 10px;">
                        <label for="filter-dieta"
                            style="font-weight: 500; font-size: 0.9em;  color: #e0e0e0; ">Dieta:</label>
                        <select name="dieta" id="filter-dieta" class="user-filter-control"
                            style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; margin-top: 5px;">
                            <option value="">Wszystkie</option>
                            <option value="Vege">Vege</option>
                            <option value="Mięso">Mięso</option>
                            <option value="Inna">Inna</option>
                        </select>
                    </div>

                    <div style="margin-bottom: 10px;">
                        <label for="filter-styl-pracy" style="font-weight: 500; font-size: 0.9em;  color: #e0e0e0; ">Styl
                            pracy:</label>
                        <select name="styl_pracy" id="filter-styl-pracy" class="user-filter-control"
                            style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; margin-top: 5px;">
                            <option value="">Wszystkie</option>
                            <option value="Stabilna">Stabilna</option>
                            <option value="Przedsiębiorca">Przedsiębiorca</option>
                            <option value="Freelancerka">Freelancerka</option>
                            <option value="Korpo">Korpo</option>
                            <option value="Start-up">Start-up</option>
                            <option value="Artysta (praca kreatywna)">Artysta (praca kreatywna)</option>
                            <option value="Twórca Internetowy">Twórca Internetowy</option>
                            <option value="Właściciel">Właściciel</option>
                            <option value="Naukowa">Naukowa</option>
                        </select>
                    </div>

                    <div style="margin-bottom: 10px;">
                        <label for="filter-numerology" style="font-weight: 500; font-size: 0.9em; ">Numerologia:</label>
                        <select name="numerology" id="filter-numerology" class="user-filter-control"
                            style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; margin-top: 5px;">
                            <option value="">Wszystkie</option>
                            <option value="1">1 - Pionier</option>
                            <option value="2">2 - Dyplomata</option>
                            <option value="3">3 - Kreator</option>
                            <option value="4">4 - Budowniczy</option>
                            <option value="5">5 - Podróżnik</option>
                            <option value="6">6 - Opiekun</option>
                            <option value="7">7 - Mędrzec</option>
                            <option value="8">8 - Lider</option>
                            <option value="9">9 - Humanista</option>
                            <option value="11">11 - Mistrzowska</option>
                            <option value="22">22 - Mistrzowska</option>
                            <option value="33">33 - Mistrzowska</option>
                        </select>
                    </div>
                </form>
            </div>
        </div>

        <div class="widget">
            <h3 class="widget-title">Dodatkowe Opcje</h3>
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 0;">
                <label for="toggle-numerology-checkbox" style="font-weight: 500; font-size: 0.9em; color: #e0e0e0; cursor: pointer;">Pokaż Numerologię</label>
                <input type="checkbox" id="toggle-numerology-checkbox" style="width: 20px; height: 20px; cursor: pointer; accent-color: #6a1b9a;">
            </div>
        </div>

        <div class="widget">
            <h3 class="widget-title accordion-trigger">Polubili mnie<span class="widget-arrow">▼</span></h3>
            <div class="widget-content accordion-content">
                <?php
                $liked_by_ids = get_user_meta($current_user_id, 'sk_liked_by_users', true);
                if (!empty($liked_by_ids) && is_array($liked_by_ids)): ?>
                    <ul class="liked-by-list">
                        <?php foreach (array_reverse($liked_by_ids) as $liker_id):
                            $liker_data = get_userdata($liker_id);
                            if (!$liker_data)
                                continue; ?>
                            <li class="liked-by-item">
                                <a href="<?php echo bp_members_get_user_url($liker_id); ?>">
                                    <?php echo get_avatar($liker_id, 30); ?>
                                    <span><?php echo esc_html($liker_data->display_name); ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="no-one-yet">Nikt Cię jeszcze nie polubił.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="widget">
            <h3 class="widget-title accordion-trigger">Odwiedzili mnie<span class="widget-arrow">▼</span></h3>
            <div class="widget-content accordion-content">
                <?php
                $visitors = get_user_meta($current_user_id, 'profile_visitors', true);
                if (!empty($visitors) && is_array($visitors)):
                    ?>
                    <ul class="liked-by-list">
                        <?php foreach ($visitors as $visit):
                            $visitor_id = is_array($visit) ? $visit['user_id'] : $visit;
                            $visitor_data = get_userdata($visitor_id);
                            if (!$visitor_data)
                                continue;
                            ?>
                            <li class="liked-by-item">
                                <a href="<?php echo bp_members_get_user_url($visitor_id); ?>">
                                    <?php echo get_avatar($visitor_id, 30); ?>
                                    <span><?php echo esc_html($visitor_data->display_name); ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="no-one-yet">Nikt jeszcze nie odwiedził Twojego profilu.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="widget" id="blocked-users-widget">
            <h3 class="widget-title accordion-trigger">Zablokowani<span class="widget-arrow">▼</span></h3>
            <div class="widget-content accordion-content">
                <?php
                $blocked_user_ids = get_user_meta($current_user_id, 'sk_blocked_users', true);
                if (!empty($blocked_user_ids) && is_array($blocked_user_ids)): ?>
                    <ul class="liked-by-list">
                        <?php foreach ($blocked_user_ids as $blocked_id):
                            $blocked_data = get_userdata($blocked_id);
                            if (!$blocked_data)
                                continue; ?>
                            <li class="liked-by-item" data-item-id="<?php echo $blocked_id; ?>">
                                <a href="<?php echo bp_members_get_user_url($blocked_id); ?>" title="Zobacz profil">
                                    <?php echo get_avatar($blocked_id, 30); ?>
                                    <span><?php echo esc_html($blocked_data->display_name); ?></span>
                                </a>
                                <button class="unblock-button" data-user-id="<?php echo $blocked_id; ?>"
                                    title="Odblokuj">Odblokuj</button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="no-one-yet">Nikogo jeszcze nie zablokowałeś.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="widget">
            <h3 class="widget-title">Co nowego?</h3>
            <ul class="widget-list">
                <?php
                $likes_count = is_array($liked_by_ids) ? count($liked_by_ids) : 0;
                $visitor_count = is_array($visitors) ? count($visitors) : 0;
                ?>
                <li><span>Nowe polubienia:</span> <span class="value"><?php echo $likes_count; ?></span></li>
                <li><span>Nowe odwiedziny:</span> <span class="value"><?php echo $visitor_count; ?></span></li>
                <li><span>Zainteresowani Tobą:</span> <span class="value">0</span></li>
            </ul>
        </div>

        <div class="widget">
            <ul class="widget-list-toggle">
                <li><span>Status czatu:</span> <span class="value">Dostępny ▼</span></li>
                <li><span>Tryb prywatny:</span> <span class="toggle-switch disabled"></span></li>
                <li><span>Widoczność profilu:</span> <span class="toggle-switch enabled"></span></li>
            </ul>
        </div>

        <div class="widget">
            <a href="<?php echo bp_loggedin_user_domain() . 'profile/edit/'; ?>" class="widget-button purple">Pytania o
                dopasowanie</a>
            <a href="#" class="widget-button green">Sprawdzenie przeszłości</a>
        </div>

        <div class="widget">
            <h3 class="widget-title">Ukończenie Profilu</h3>
            <div class="profile-completion">
                <div class="completion-avatar"><?php echo get_avatar($current_user_id, 60); ?></div>
                <div class="completion-details">
                    <?php $percentage = function_exists('sk_get_profile_completion_percentage') ? sk_get_profile_completion_percentage($current_user_id) : 0; ?>
                    <p>Twój profil jest ukończony w <strong><?php echo $percentage; ?>%</strong>.</p>
                    <div class="progress-bar-container">
                        <div class="progress-bar" style="width: <?php echo $percentage; ?>%;"></div>
                    </div>
                    <div class="completion-links"><a
                            href="<?php echo bp_loggedin_user_domain() . 'profile/change-avatar/'; ?>">Zmień zdjęcie</a><a
                            href="<?php echo bp_loggedin_user_domain() . 'profile/edit/'; ?>">Uzupełnij profil</a></div>
                </div>
            </div>
        </div>

        <div class="widget">
            <h3 class="widget-title">Ostatnie dopasowania</h3>
            <ul class="recent-matches-list">
                <?php
                $match_me_obj = null;
                if (class_exists('Mp_BP_Match'))
                    $match_me_obj = new Mp_BP_Match();
                if ($match_me_obj) {
                    $blocked_user_ids_for_matches = get_user_meta($current_user_id, 'sk_blocked_users', true);
                    if (!is_array($blocked_user_ids_for_matches))
                        $blocked_user_ids_for_matches = [];
                    $all_users = get_users(['exclude' => array_merge([$current_user_id], $blocked_user_ids_for_matches)]);
                    $matches = [];
                    foreach ($all_users as $user) {
                        $matches[] = [
                            'user_id' => $user->ID,
                            'name' => $user->display_name,
                            'login' => $user->user_login,
                            'avatar' => get_avatar($user->ID, 40),
                            'match_percent' => $match_me_obj->hmk_get_matching_percentage_number($user->ID, $current_user_id),
                            'age_location' => bp_get_profile_field_data(['field' => 'Wiek', 'user_id' => $user->ID]) . ', ' . bp_get_profile_field_data(['field' => 'Lokalizacja', 'user_id' => $user->ID])
                        ];
                    }
                    usort($matches, function ($a, $b) {
                        return $b['match_percent'] - $a['match_percent'];
                    });
                    $top_matches = array_slice($matches, 0, 3);
                    foreach ($top_matches as $match) { ?>
                        <li class="recent-match-item">
                            <?php echo $match['avatar']; ?>
                            <div class="match-info">
                                <strong><?php echo esc_html($match['name']); ?></strong>
                                <span><?php echo esc_html($match['age_location']); ?></span>
                            </div>
                            <a href="<?php echo esc_url(trailingslashit(bp_loggedin_user_domain()) . bp_get_messages_slug() . '/compose/?r=' . urlencode($match['login'])); ?>"
                                class="send-message-btn">✉️</a>
                        </li>
                    <?php }
                } else {
                    echo '<li>Wtyczka "Match Me" nie jest aktywna.</li>';
                } ?>
            </ul>
        </div>
    </aside>

    <style>
        .left-panel-container {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .widget {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
        }

        .widget-title {
            font-size: 1rem;
            margin: 0 0 10px 0;
            padding-bottom: 10px;
            border-bottom: 1px solid #f0f0f0;
        }

        .accordion-trigger {
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .accordion-trigger:hover {
            color: #0073aa;
        }

        .widget-arrow {
            transition: transform 0.3s ease;
        }

        .widget-arrow.up {
            transform: rotate(180deg);
        }

        .accordion-content {
            display: none;
            padding-top: 10px;
        }

        .liked-by-list {
            list-style: none;
            padding: 0;
            margin: 0;
            max-height: 250px;
            overflow-y: auto;
        }

        .liked-by-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 5px 0;
            gap: 10px;
        }

        .liked-by-item a {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: #333;
            border-radius: 4px;
            flex-grow: 1;
        }

        .liked-by-item a:hover {
            color: #0073aa;
        }

        .liked-by-item img {
            border-radius: 50%;
        }

        .unblock-button {
            background: #e0e0e0;
            border: none;
            color: #333;
            font-size: 11px;
            padding: 4px 8px;
            border-radius: 3px;
            cursor: pointer;
            flex-shrink: 0;
        }

        .unblock-button:hover {
            background: #c0c0c0;
        }

        .no-one-yet {
            font-size: 0.9rem;
            color: #666;
            margin: 5px;
        }

        .widget-nav-list,
        .widget-list,
        .widget-list-toggle,
        .recent-matches-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .widget-nav-list li {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .widget-nav-list a {
            display: block;
            padding: 10px 5px;
            text-decoration: none;
            color: #333;
            font-weight: bold;
            font-size: 1rem;
            transition: color 0.2s;
            flex-grow: 1;
        }

        .nav-badge {
            background-color: #d9534f;
            color: white;
            font-size: 12px;
            font-weight: bold;
            padding: 2px 8px;
            border-radius: 10px;
            margin-left: 10px;
        }

        .widget-list li,
        .widget-list-toggle li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.9rem;
            padding: 5px 0;
        }

        .widget-list .value {
            font-weight: bold;
            color: #333;
        }

        .widget-list-toggle .value {
            color: #0073aa;
            cursor: pointer;
        }

        .toggle-switch {
            width: 40px;
            height: 20px;
            background: #ccc;
            border-radius: 10px;
            position: relative;
            cursor: pointer;
        }

        .toggle-switch::before {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: white;
            top: 2px;
            left: 2px;
            transition: all 0.2s;
        }

        .toggle-switch.enabled {
            background: #4CAF50;
        }

        .toggle-switch.enabled::before {
            left: 22px;
        }

        .widget-button {
            display: block;
            text-align: center;
            padding: 10px;
            border-radius: 5px;
            color: white;
            font-weight: bold;
            text-decoration: none;
            margin-bottom: 10px;
        }

        .widget-button:last-child {
            margin-bottom: 0;
        }

        .widget-button.purple {
            background: #6a1b9a;
        }

        .widget-button.green {
            background: #2e7d32;
        }

        .profile-completion {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .completion-avatar {
            flex-shrink: 0;
        }

        .completion-avatar img {
            border-radius: 50%;
        }

        .completion-details {
            flex-grow: 1;
        }

        .completion-details p {
            margin: 0 0 8px 0;
            font-size: 0.9rem;
        }

        .progress-bar-container {
            width: 100%;
            background: #e0e0e0;
            border-radius: 5px;
            height: 10px;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            background: #4CAF50;
            border-radius: 5px;
        }

        .completion-links {
            margin-top: 10px;
            font-size: 0.8rem;
        }

        .completion-links a {
            margin-right: 15px;
            color: #0073aa;
            text-decoration: none;
        }

        .recent-match-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .recent-match-item:last-child {
            border-bottom: none;
        }

        .recent-match-item img {
            border-radius: 50%;
        }

        .match-info {
            display: flex;
            flex-direction: column;
            flex-grow: 1;
        }

        .match-info strong {
            font-size: 0.9rem;
        }

        .match-info span {
            font-size: 0.8rem;
            color: #666;
        }

        .send-message-btn {
            font-size: 1.2rem;
            text-decoration: none;
            color: #0073aa;
        }
    </style>

    <script>
        jQuery(document).ready(function ($) {
            $('.accordion-trigger').on('click', function () {
                $(this).closest('.widget').siblings('.widget').find('.accordion-content').slideUp('fast');
                $(this).closest('.widget').siblings('.widget').find('.widget-arrow').removeClass('up');
                $(this).siblings('.accordion-content').slideToggle('fast');
                $(this).find('.widget-arrow').toggleClass('up');
            });

            $('#toggle-numerology-checkbox').on('change', function () {
                const body = $('body');

                if ($(this).is(':checked')) {
                    body.addClass('show-numerology');
                } else {
                    body.removeClass('show-numerology');
                }
            });

            $('.left-panel-container').on('click', '.unblock-button', function () {
                const button = $(this);
                const userIdToUnblock = button.data('userId');

                if (confirm('Czy na pewno chcesz odblokować tego użytkownika? Pojawi się on ponownie w wynikach wyszukiwania.')) {

                    $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                        action: 'toggle_block_user',
                        blocked_user_id: userIdToUnblock,
                        nonce: '<?php echo wp_create_nonce('block_user_nonce'); ?>'
                    }, function (response) {
                        if (response.success) {
                            button.closest('.liked-by-item').fadeOut(function () {
                                $(this).remove();
                            });
                        } else {
                            alert('Wystąpił błąd. Spróbuj ponownie.');
                        }
                    });
                }
            });
        });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('lewy_panel_uzytkownika', 'lewy_panel_uzytkownika_shortcode');
/**
 * ZMODYFIKOWANA Funkcja AJAX: Lajki oparte o BuddyPress Friends
 */
function sk_toggle_like_user_ajax()
{
    // 1. Weryfikacja bezpieczeństwa
    if (!is_user_logged_in() || !check_ajax_referer('like_user_nonce', 'nonce', false)) {
        wp_send_json_error('Błąd autoryzacji.');
        return;
    }

    $liker_id = get_current_user_id();
    $liked_id = isset($_POST['liked_user_id']) ? intval($_POST['liked_user_id']) : 0;

    if (!$liked_id || $liker_id == $liked_id) {
        wp_send_json_error('Nieprawidłowy ID użytkownika.');
        return;
    }

    // 2. Pobierz aktualne listy polubień (meta)
    $my_likes = get_user_meta($liker_id, 'sk_user_likes', true);
    if (!is_array($my_likes))
        $my_likes = [];

    $liked_by = get_user_meta($liked_id, 'sk_liked_by_users', true);
    if (!is_array($liked_by))
        $liked_by = [];

    // Sprawdź, czy druga osoba już mnie polubiła (czy jestem na jej liście 'likes' lub ona na mojej 'liked_by')
    // Skoro mamy listę "liked_by" u likera, sprawdźmy ją:
    $liker_liked_by_list = get_user_meta($liker_id, 'sk_liked_by_users', true);
    if (!is_array($liker_liked_by_list))
        $liker_liked_by_list = [];

    $is_mutual_match_possible = in_array($liked_id, $liker_liked_by_list);

    $is_already_liked = in_array($liked_id, $my_likes);

    if ($is_already_liked) {
        // --- ODLUBIENIE (UNLIKE) ---

        // 1. Usuń z meta
        $my_likes = array_diff($my_likes, [$liked_id]);
        $liked_by = array_diff($liked_by, [$liker_id]);

        // 2. Usuń znajomość w BuddyPress (jeśli istniała)
        if (function_exists('friends_remove_friend')) {
            friends_remove_friend($liker_id, $liked_id);
        }

        $new_status = 'unliked';

    } else {
        // --- POLUBIENIE (LIKE) ---

        // 1. Dodaj do meta
        $my_likes[] = $liked_id;
        $liked_by[] = $liker_id;

        // 2. Sprawdź czy to MATCH (Wzajemne polubienie)
        if ($is_mutual_match_possible) {
            // Tak! Druga osoba już mnie lubi. Tworzymy przyjaźń w BuddyPress.
            if (function_exists('friends_add_friend')) {
                // Trzeci parametr 'true' wymusza akceptację (nie wysyła zaproszenia, od razu łączy)
                friends_add_friend($liker_id, $liked_id, true);
            }
        }
        // Jeśli nie ma wzajemności, zapisujemy tylko meta (jednostronny lajk)

        $new_status = 'liked';
    }

    // Zapisz zaktualizowane meta (porządkujemy indeksy tablicy przez array_values)
    update_user_meta($liker_id, 'sk_user_likes', array_values($my_likes));
    update_user_meta($liked_id, 'sk_liked_by_users', array_values($liked_by));

    // Wyczyść cache grida dla obu użytkowników
    delete_transient('users_grid_cache_' . $liker_id);
    delete_transient('users_grid_cache_' . $liked_id);

    // Determine if this is a match
    $is_match = $is_mutual_match_possible && $new_status === 'liked';
    
    // Wyślij e-mail powiadomienie o polubieniu/dopasowaniu
    if ($new_status === 'liked') {
        $liked_user = get_userdata($liked_id);
        $liker_user = get_userdata($liker_id);
        if ($liked_user && $liker_user) {
            $to = $liked_user->user_email;
            $liker_name = $liker_user->display_name;
            
            if ($is_match) {
                $subject = 'Masz nową parę na Prawdziwa Miłość!';
                $message = "Cześć " . $liked_user->display_name . ",\n\n";
                $message .= "Mamy świetną wiadomość! Użytkownik " . $liker_name . " również Cię polubił(a). Masz nową parę!\n\n";
                $message .= "Zaloguj się do aplikacji, aby rozpocząć rozmowę:\n";
                $message .= "https://prawdziwamilosc.pl\n\n";
                $message .= "Życzymy udanej rozmowy,\nZespół Prawdziwa Miłość";
            } else {
                $subject = 'Ktoś Cię polubił na Prawdziwa Miłość!';
                $message = "Cześć " . $liked_user->display_name . ",\n\n";
                $message .= "Ktoś okazał Ci zainteresowanie! Użytkownik " . $liker_name . " polubił Twój profil.\n\n";
                $message .= "Zaloguj się do aplikacji, aby sprawdzić profil:\n";
                $message .= "https://prawdziwamilosc.pl\n\n";
                $message .= "Życzymy miłego dnia,\nZespół Prawdziwa Miłość";
            }
            
            $mail_sent = wp_mail($to, $subject, $message);
            error_log("SK DEBUG: sk_toggle_like_user_ajax - email to $to sent status: " . ($mail_sent ? 'SUCCESS' : 'FAILED'));
        }
    }

    // Build response
    $response_data = [
        'status' => $new_status,
        'is_match' => $is_match
    ];
    
    // If it's a match, include matched user info for the animation
    if ($is_match) {
        $matched_user = get_userdata($liked_id);
        if ($matched_user) {
            $avatar_id = get_user_meta($liked_id, 'sk_custom_avatar_id', true);
            $avatar_url = '';
            if ($avatar_id) {
                $avatar_url = wp_get_attachment_image_url($avatar_id, 'medium');
            }
            if (!$avatar_url) {
                $avatar_url = get_avatar_url($liked_id, ['size' => 200]);
            }
            
            $response_data['matched_user'] = [
                'id' => $liked_id,
                'name' => $matched_user->display_name,
                'avatar' => $avatar_url
            ];
        }
    }
    
    wp_send_json_success($response_data);
}
// Upewnij się, że hook jest podpięty (usuń poprzedni jeśli dublujesz kod)
remove_action('wp_ajax_toggle_like_user', 'sk_toggle_like_user_ajax');
add_action('wp_ajax_toggle_like_user', 'sk_toggle_like_user_ajax');

/**
 * Funkcja AJAX do obsługi blokowania użytkowników.
 */
function sk_toggle_block_user_ajax()
{
    if (!is_user_logged_in() || !check_ajax_referer('block_user_nonce', 'nonce', false)) {
        wp_send_json_error('Błąd autoryzacji.');
        return;
    }

    $blocker_id = get_current_user_id();
    $blocked_id = isset($_POST['blocked_user_id']) ? intval($_POST['blocked_user_id']) : 0;

    if (!$blocked_id || $blocker_id == $blocked_id) {
        wp_send_json_error('Nieprawidłowy ID użytkownika.');
        return;
    }

    $blocked_users = get_user_meta($blocker_id, 'sk_blocked_users', true);
    if (!is_array($blocked_users))
        $blocked_users = [];

    $is_already_blocked = in_array($blocked_id, $blocked_users);

    if ($is_already_blocked) {
        // ODBLOKOWANIE UŻYTKOWNIKA
        $blocked_users = array_diff($blocked_users, [$blocked_id]);
        $new_status = 'unblocked';
    } else {
        // ZABLOKOWANIE UŻYTKOWNIKA
        $blocked_users[] = $blocked_id;
        $new_status = 'blocked';
    }

    update_user_meta($blocker_id, 'sk_blocked_users', $blocked_users);

    wp_send_json_success(['status' => $new_status]);
}
add_action('wp_ajax_toggle_block_user', 'sk_toggle_block_user_ajax');


// Pamiętaj, aby usunąć lub wykomentować starą akcję, jeśli nadal istnieje
// remove_action( 'bp_before_member_header', 'sk_display_large_cover_avatar' );
/**
 * ===================================================================
 * BUDDYPRESS IN-PLACE PROFILE EDIT - BACKEND
 * Tworzy punkt końcowy AJAX do bezpiecznego zapisywania danych.
 * ===================================================================
 */
add_action('wp_ajax_save_buddypress_xprofile_field', function () {
    // Weryfikacja bezpieczeństwa
    check_ajax_referer('bp-xprofile-nonce', 'nonce');

    if (!is_user_logged_in() || !isset($_POST['field_id']) || !isset($_POST['value'])) {
        wp_send_json_error(['message' => 'Brak uprawnień lub wymaganych danych.']);
    }

    $field_id = intval($_POST['field_id']);
    $user_id = bp_displayed_user_id();

    // Sprawdź, czy zalogowany użytkownik ma prawo edytować ten profil
    if (!bp_current_user_can('bp_xprofile_edit_profile', ['user_id' => $user_id])) {
        wp_send_json_error(['message' => 'Nie masz uprawnień do edycji tego pola.']);
    }

    // Wyczyść dane przed zapisem.
    // `wp_kses_post` jest dobre dla pól tekstowych. Inne typy mogą wymagać innej walidacji.
    $value = wp_kses_post($_POST['value']);

    // Zapisywanie danych pola profilowego za pomocą funkcji BuddyPress
    if (xprofile_set_field_data($field_id, $user_id, $value)) {
        // Sukces - zwróć nową, oczyszczoną wartość do wyświetlenia
        $display_value = xprofile_get_field_data($field_id, $user_id, 'comma');
        wp_send_json_success(['display_value' => $display_value]);
    } else {
        // Błąd
        wp_send_json_error(['message' => 'Nie udało się zapisać pola w bazie danych.']);
    }
});

/**
 * ===================================================================
 * BUDDYPRESS IN-PLACE PROFILE EDIT - FRONTEND
 * Dodaje skrypt jQuery do obsługi edycji "w miejscu" na stronach profilu.
 * ===================================================================
 */
add_action('wp_footer', function () {
    // Wykonaj tylko na stronach profilu członka BuddyPress
    if (!bp_is_user_profile() || bp_is_user_profile_edit()) {
        return;
    }
    ?>
    <style>
        .editable-bp {
            cursor: pointer;
            border-bottom: 1px dashed #ccc;
        }

        .editable-bp:hover {
            background-color: #f9f9f9;
        }

        .edit-input-bp {
            width: 90%;
            padding: 5px;
        }

        .edit-controls-bp {
            margin-top: 8px;
        }
    </style>
    <script id="bp-inplace-edit-script">
        jQuery(document).ready(function ($) {
            let originalHtml = '';

            // 1. Użytkownik klika na pole edytowalne
            $('body').on('click', '.editable-bp', function (e) {
                e.stopPropagation();
                const $this = $(this);
                if ($this.find('input, select, textarea').length) { return; } // Już w trybie edycji

                originalHtml = $this.html();
                const fieldId = $this.data('field-id');
                const fieldType = $this.data('field-type');
                const rawValue = $this.data('field-value-raw');
                const options = $this.data('field-options');
                let inputElement = '';

                // 2. Tworzy odpowiedni typ pola formularza
                switch (fieldType) {
                    case 'textbox':
                    case 'url':
                    case 'number':
                        inputElement = `<input type="${fieldType === 'number' ? 'number' : 'text'}" class="edit-input-bp" value="${rawValue}">`;
                        break;

                    case 'textarea':
                        inputElement = `<textarea class="edit-input-bp" style="height: 100px;">${rawValue}</textarea>`;
                        break;

                    case 'datebox':
                        // BuddyPress przechowuje datę jako Y-m-d H:i:s. Formatujemy ją dla pola input.
                        const date = new Date(rawValue.replace(/-/g, "/"));
                        const dateString = date.getFullYear() + '-' + ('0' + (date.getMonth() + 1)).slice(-2) + '-' + ('0' + date.getDate()).slice(-2);
                        inputElement = `<input type="date" class="edit-input-bp" value="${dateString}">`;
                        break;

                    case 'selectbox':
                        inputElement = `<select class="edit-input-bp">`;
                        if (options && options.length) {
                            options.forEach(function (opt) {
                                const selected = (opt.name === rawValue) ? 'selected' : '';
                                inputElement += `<option value="${opt.name}" ${selected}>${opt.name}</option>`;
                            });
                        }
                        inputElement += `</select>`;
                        break;

                    case 'radio':
                        inputElement = '<div class="edit-input-bp">';
                        if (options && options.length) {
                            options.forEach(function (opt) {
                                const checked = (opt.name === rawValue) ? 'checked' : '';
                                inputElement += `<label style="margin-right: 15px;"><input type="radio" name="field_${fieldId}" value="${opt.name}" ${checked}> ${opt.name}</label>`;
                            });
                        }
                        inputElement += '</div>';
                        break;

                    default:
                        // Domyślnie dla nieobsługiwanych typów
                        inputElement = `<input type="text" class="edit-input-bp" value="${rawValue}">`;
                }

                const controls = `<div class="edit-controls-bp"><button class="save-btn-bp">Zapisz</button> <button class="cancel-btn-bp">Anuluj</button></div>`;
                $this.html(inputElement + controls).find('.edit-input-bp, select, textarea').first().focus();
            });

            // 3. Anulowanie
            $('body').on('click', '.cancel-btn-bp', function (e) {
                e.stopPropagation();
                $(this).closest('.editable-bp').html(originalHtml);
            });

            // 4. Zapisywanie
            $('body').on('click', '.save-btn-bp', function (e) {
                e.stopPropagation();
                const $this = $(this);
                const container = $this.closest('.editable-bp');
                const fieldId = container.data('field-id');
                let newValue = '';

                // Pobierz wartość w zależności od typu pola
                const input = container.find('.edit-input-bp, select, textarea');
                if (input.is('[type="radio"]')) {
                    newValue = container.find('input[type="radio"]:checked').val();
                } else {
                    newValue = input.val();
                }

                // Wywołanie AJAX do WordPressa
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    method: 'POST',
                    data: {
                        action: 'save_buddypress_xprofile_field',
                        nonce: '<?php echo wp_create_nonce('bp-xprofile-nonce'); ?>',
                        field_id: fieldId,
                        value: newValue
                    },
                    beforeSend: function () { $this.text('Zapisywanie...').prop('disabled', true); },
                    success: function (response) {
                        if (response.success) {
                            container.html(response.data.display_value);
                        } else {
                            alert('Błąd: ' + (response.data.message || 'Nieznany błąd.'));
                            container.html(originalHtml);
                        }
                    },
                    error: function () {
                        alert('Wystąpił błąd serwera. Spróbuj ponownie.');
                        container.html(originalHtml);
                    }
                });
            });
        });
    </script>
    <?php
});

/**
 * Astra Child Theme functions.php
 * Bezpieczny kod bez błędów składniowych
 */

// Zabezpieczenie przed bezpośrednim dostępem
if (!defined('ABSPATH')) {
    exit;
}

// DEBUG CONSTANT
if (!defined('SK_DEBUG_LOG_FILE')) {
    define('SK_DEBUG_LOG_FILE', WP_CONTENT_DIR . '/uploads/sk_debug.log');
}

function sk_debug_log($message) {
    if (!defined('SK_DEBUG_LOG_FILE')) return;
    $ts = date('Y-m-d H:i:s');
    $formatted = "[$ts] $message" . PHP_EOL;
    @file_put_contents(SK_DEBUG_LOG_FILE, $formatted, FILE_APPEND);
}

// Log Viewer Endpoint
add_action('rest_api_init', function () {
    register_rest_route('sk/v1', '/debug-log', [
        'methods' => 'GET',
        'callback' => function() {
            $log_file = defined('SK_DEBUG_LOG_FILE') ? SK_DEBUG_LOG_FILE : WP_CONTENT_DIR . '/uploads/sk_debug.log';
            
            if (file_exists($log_file)) {
                $content = file_get_contents($log_file);
                return new WP_REST_Response($content, 200, ['Content-Type' => 'text/plain']);
            }
            return new WP_REST_Response("Log file not found at " . $log_file, 404);
        },
        'permission_callback' => '__return_true',
    ]);
});

// Verify write on load
sk_debug_log("Functions reloaded at " . date('H:i:s'));

/**
 * Ładowanie dodatkowego arkusza stylów dla profilu BuddyPress.
 */
function moje_dodatkowe_style()
{
    // Sprawdzamy, czy jesteśmy na stronie profilu użytkownika BuddyPress
    if (function_exists('bp_is_user') && bp_is_user()) {
        wp_enqueue_style(
            'moj-styl-profilu',
            get_stylesheet_directory_uri() . '/class.css',
            array(), // zależności, jeśli są potrzebne
            '1.0'    // numer wersji
        );
    }
}
add_action('wp_enqueue_scripts', 'moje_dodatkowe_style');

function add_custom_nav_item()
{
    bp_core_new_nav_item(array(
        'name' => 'Moja zakładka',
        'slug' => 'moja-zakladka',
        'position' => 100,
        'screen_function' => 'custom_tab_display',
    ));
}
add_action('bp_setup_nav', 'add_custom_nav_item');

/**
 * =========================================================================
 * === DODANIE PRZYCISKU 'POLUB' NA STRONIE PROFILU BUDDYPRESS
 * =========================================================================
 */

// KROK 1: Dodanie przycisku do akcji w nagłówku profilu
add_action('bp_member_header_actions', 'sk_add_like_button_to_profile_header', 20);
function sk_add_like_button_to_profile_header()
{

    // Sprawdzamy, czy użytkownik jest zalogowany
    if (!is_user_logged_in()) {
        return;
    }

    $liker_id = get_current_user_id();
    $liked_id = bp_displayed_user_id();

    // Nie pokazuj przycisku na własnym profilu
    if ($liker_id == $liked_id) {
        return;
    }

    // Sprawdź, czy obecny użytkownik już polubił ten profil
    $my_likes = get_user_meta($liker_id, 'sk_user_likes', true);
    if (!is_array($my_likes)) {
        $my_likes = [];
    }

    $is_liked = in_array($liked_id, $my_likes);

    // Ustaw klasy i ikonę w zależności od statusu polubienia
    $button_class = $is_liked ? 'like-button liked' : 'like-button';
    $heart_icon = $is_liked ? '❤️' : '🤍';
    $button_title = $is_liked ? 'Odlub profil' : 'Polub profil';

    // Wygeneruj przycisk
    echo '<button class="' . esc_attr($button_class) . '" data-user-id="' . esc_attr($liked_id) . '" title="' . esc_attr($button_title) . '">
            <span class="heart-icon">' . $heart_icon . '</span>
            <span class="like-button-text">' . ($is_liked ? 'Lubisz to!' : 'Polub') . '</span>
          </button>';
}

// KROK 2: Dodanie globalnego skryptu JS do obsługi kliknięcia
add_action('wp_footer', 'sk_global_like_button_script');
function sk_global_like_button_script()
{
    // Skrypt jest potrzebny tylko, gdy użytkownik jest zalogowany
    if (!is_user_logged_in()) {
        return;
    }
    
    // Get current user avatar
    $current_user_id = get_current_user_id();
    $avatar_id = get_user_meta($current_user_id, 'sk_custom_avatar_id', true);
    $current_user_avatar = '';
    if ($avatar_id) {
        $current_user_avatar = wp_get_attachment_image_url($avatar_id, 'medium');
    }
    if (!$current_user_avatar) {
        $current_user_avatar = get_avatar_url($current_user_id, ['size' => 200]);
    }
    ?>
    <!-- Match Animation Modal -->
    <div id="sk-match-modal" class="sk-match-modal" style="display:none;">
        <div class="sk-match-overlay"></div>
        <div class="sk-match-content">
            <div class="sk-match-avatars">
                <img id="sk-match-user-avatar" src="<?php echo esc_url($current_user_avatar); ?>" alt="Ty" class="sk-match-avatar sk-match-avatar-me">
                <div class="sk-match-heart">
                    <svg viewBox="0 0 24 24" width="50" height="50" fill="#FF6B9D">
                        <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
                    </svg>
                </div>
                <img id="sk-match-matched-avatar" src="" alt="" class="sk-match-avatar sk-match-avatar-matched">
            </div>
            <h2 class="sk-match-title">🎉 Macie Match! 🎉</h2>
            <p id="sk-match-subtitle" class="sk-match-subtitle">Ty i <span id="sk-match-name"></span> wzajemnie się polubiliście!</p>
            <div class="sk-match-buttons">
                <a id="sk-match-message-btn" href="#" class="sk-match-btn sk-match-btn-primary">
                    <span>💬</span> Wyślij wiadomość
                </a>
                <button id="sk-match-close-btn" class="sk-match-btn sk-match-btn-secondary">
                    Kontynuuj przeglądanie
                </button>
            </div>
        </div>
    </div>
    
    <script id="global-like-button-handler">
        jQuery(document).ready(function ($) {
            // Match modal functions
            function showMatchModal(matchedUser) {
                var modal = $('#sk-match-modal');
                $('#sk-match-matched-avatar').attr('src', matchedUser.avatar);
                $('#sk-match-name').text(matchedUser.name);
                $('#sk-match-message-btn').attr('href', '<?php echo trailingslashit(bp_loggedin_user_domain()) ?: home_url('/'); ?>' + '<?php echo function_exists("bp_get_messages_slug") ? bp_get_messages_slug() : "messages"; ?>' + '/compose/?r=' + encodeURIComponent(matchedUser.login || matchedUser.name));
                
                modal.fadeIn(300);
                modal.find('.sk-match-content').addClass('sk-match-animate-in');
                
                // Auto-close after 4 seconds
                setTimeout(function() {
                    closeMatchModal();
                }, 4000);
            }
            
            function closeMatchModal() {
                var modal = $('#sk-match-modal');
                modal.find('.sk-match-content').removeClass('sk-match-animate-in');
                modal.fadeOut(200);
            }
            
            // Close modal on button click or overlay click
            $('#sk-match-close-btn').on('click', closeMatchModal);
            $('.sk-match-overlay').on('click', closeMatchModal);
            
            // Używamy delegacji zdarzeń, aby działało na elementach dodanych dynamicznie
            $('body').on('click', '.like-button', function (e) {
                e.preventDefault();

                const button = $(this);
                const likedUserId = button.data('user-id');
                const heartIcon = button.find('.heart-icon');
                const buttonText = button.find('.like-button-text');

                // Zablokuj przycisk na czas zapytania, aby uniknąć podwójnych kliknięć
                button.prop('disabled', true);

                $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                    action: 'toggle_like_user',
                    liked_user_id: likedUserId,
                    nonce: '<?php echo wp_create_nonce('like_user_nonce'); ?>'
                }, function (response) {
                    if (response.success) {
                        // Zmień wygląd przycisku na podstawie odpowiedzi
                        if (response.data.status === 'liked') {
                            button.addClass('liked');
                            heartIcon.text('❤️');
                            if (buttonText.length) {
                                buttonText.text('Lubisz to!');
                            }
                            
                            // Check if it's a match!
                            if (response.data.is_match && response.data.matched_user) {
                                showMatchModal(response.data.matched_user);
                            }
                        } else {
                            button.removeClass('liked');
                            heartIcon.text('🤍');
                            if (buttonText.length) {
                                buttonText.text('Polub');
                            }
                        }
                    } else {
                        // W przypadku błędu, poinformuj użytkownika
                        alert(response.data || 'Wystąpił błąd. Spróbuj ponownie.');
                    }
                    // Odblokuj przycisk po otrzymaniu odpowiedzi
                    button.prop('disabled', false);
                }).fail(function () {
                    alert('Wystąpił błąd serwera. Spróbuj ponownie później.');
                    button.prop('disabled', false);
                });
            });
        });
    </script>
    
    <style id="sk-match-modal-styles">
        /* Match Modal Styles */
        .sk-match-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 999999;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .sk-match-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(139, 69, 139, 0.95) 0%, rgba(255, 107, 157, 0.95) 100%);
        }
        .sk-match-content {
            position: relative;
            z-index: 1;
            text-align: center;
            padding: 40px;
            opacity: 0;
            transform: scale(0.5);
            transition: all 0.3s ease-out;
        }
        .sk-match-content.sk-match-animate-in {
            opacity: 1;
            transform: scale(1);
        }
        .sk-match-avatars {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0;
            margin-bottom: 30px;
        }
        .sk-match-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 4px solid #fff;
            object-fit: cover;
            box-shadow: 0 8px 25px rgba(0,0,0,0.3);
        }
        .sk-match-heart {
            background: #fff;
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 15px;
            animation: sk-heart-pulse 0.6s ease-in-out infinite;
            box-shadow: 0 4px 15px rgba(255, 107, 157, 0.5);
        }
        @keyframes sk-heart-pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.15); }
        }
        .sk-match-title {
            font-size: 36px;
            font-weight: bold;
            color: #fff;
            margin: 0 0 15px 0;
            text-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        .sk-match-subtitle {
            font-size: 18px;
            color: rgba(255,255,255,0.9);
            margin: 0 0 35px 0;
        }
        .sk-match-buttons {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 15px;
        }
        .sk-match-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 15px 40px;
            border-radius: 30px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            border: none;
        }
        .sk-match-btn-primary {
            background: #FF6B9D;
            color: #fff;
            box-shadow: 0 4px 15px rgba(255, 107, 157, 0.5);
        }
        .sk-match-btn-primary:hover {
            background: #ff5189;
            transform: translateY(-2px);
            color: #fff;
        }
        .sk-match-btn-secondary {
            background: transparent;
            color: rgba(255,255,255,0.8);
        }
        .sk-match-btn-secondary:hover {
            color: #fff;
        }
        
        @media (max-width: 480px) {
            .sk-match-avatar {
                width: 90px;
                height: 90px;
            }
            .sk-match-heart {
                width: 55px;
                height: 55px;
                margin: 0 10px;
            }
            .sk-match-heart svg {
                width: 35px;
                height: 35px;
            }
            .sk-match-title {
                font-size: 28px;
            }
            .sk-match-subtitle {
                font-size: 16px;
            }
            .sk-match-btn {
                padding: 12px 30px;
                font-size: 16px;
            }
        }
    </style>
    <?php
}

// KROK 3: Dodanie stylów CSS dla przycisku, aby wyglądał dobrze
add_action('wp_head', 'sk_like_button_styles');
function sk_like_button_styles()
{
    ?>
    <style id="like-button-styles">
        /* Styl dla przycisku 'Polub' w nagłówku profilu */
        #buddypress #item-header-content .like-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 15px;
            font-size: 14px;
            font-weight: bold;
            border: 1px solid #ddd;
            border-radius: 5px;
            background-color: #f5f5f5;
            color: #555;
            cursor: pointer;
            transition: all 0.2s ease-in-out;
        }

        #buddypress #item-header-content .like-button:hover {
            background-color: #e9e9e9;
            border-color: #ccc;
        }

        #buddypress #item-header-content .like-button .heart-icon {
            font-size: 1.2em;
            filter: grayscale(1);
            opacity: 0.7;
            transition: all 0.2s ease;
        }

        /* Styl dla polubionego przycisku */
        #buddypress #item-header-content .like-button.liked {
            background-color: #fff0f5;
            /* Jasnoróżowe tło */
            border-color: #ffb6c1;
            /* Różowa ramka */
            color: #d6336c;
            /* Ciemniejszy różowy tekst */
        }

        #buddypress #item-header-content .like-button.liked .heart-icon {
            filter: grayscale(0);
            opacity: 1;
            transform: scale(1.1);
            /* Lekkie powiększenie serca */
        }
    </style>
    <?php
}

/**
 * =========================================================================
 * === AJAX - FILTROWANIE UŻYTKOWNIKÓW W GRIDZIE
 * =========================================================================
 */
add_action('wp_ajax_filter_users_grid', 'ajax_filter_users_grid_callback');
add_action('wp_ajax_nopriv_filter_users_grid', 'ajax_filter_users_grid_callback');
/**
 * AJAX - FILTROWANIE UŻYTKOWNIKÓW W GRIDZIE (WERSJA Z WYKLUCZENIEM ID=1)
 */
function ajax_filter_users_grid_callback()
{
    if (!is_user_logged_in()) {
        echo '<p>Musisz być zalogowany, aby filtrować użytkowników.</p>';
        wp_die();
    }

    $filters = [];
    if (isset($_POST['filters'])) {
        parse_str($_POST['filters'], $filters);
    }

    $current_user_id = get_current_user_id();
    $blocked_users_list = get_user_meta($current_user_id, 'sk_blocked_users', true);

    // --- POPRAWKA: Na stałe wykluczamy ID=1 ---
    $base_exclude_ids = array_unique(array_merge(
        [1, $current_user_id],
        is_array($blocked_users_list) ? $blocked_users_list : []
    ));

    $xprofile_query = ['relation' => 'AND'];
    $field_id_map = [
        'poglady' => 215,
        'religia' => 133,
        'dieta' => 334,
        'styl_pracy' => 108,
    ];

    foreach ($field_id_map as $filter_key => $field_id) {
        if (!empty($filters[$filter_key])) {
            $xprofile_query[] = [
                'field' => $field_id,
                'value' => sanitize_text_field($filters[$filter_key]),
                'compare' => '=',
            ];
        }
    }

    $args = [
        'per_page' => 50,
        'exclude' => $base_exclude_ids,
        'type' => 'alphabetical',
    ];

    if (count($xprofile_query) > 1) {
        $args['xprofile_query'] = $xprofile_query;
    }

    $found_users_flag = false;

    if (bp_has_members($args)) {

        $my_likes = get_user_meta($current_user_id, 'sk_user_likes', true);
        if (!is_array($my_likes))
            $my_likes = [];

        $match_me_obj = class_exists('Mp_BP_Match') ? new Mp_BP_Match() : null;
        $numerology_filter = isset($filters['numerology']) ? sanitize_text_field($filters['numerology']) : '';

        while (bp_members()):
            bp_the_member();
            $user_id = bp_get_member_user_id();

            // Pomiń użytkowników bez zdjęcia profilowego
            $user_avatar_id = get_user_meta($user_id, 'user_avatar_id', true);
            if (empty($user_avatar_id)) {
                continue;
            }

            if (!empty($numerology_filter)) {
                $birth_date = bp_get_profile_field_data(107, $user_id);
                $user_numerology = sk_calculate_life_path_number($birth_date);
                if ((string) $user_numerology !== (string) $numerology_filter) {
                    continue;
                }
            }

            $found_users_flag = true;

            $match_percentage = 0;
            if ($match_me_obj && is_callable([$match_me_obj, 'hmk_get_matching_percentage_number'])) {
                $match_percentage = $match_me_obj->hmk_get_matching_percentage_number($user_id, $current_user_id);
            }

            $match_color = $match_percentage >= 90 ? '#4CAF50' : ($match_percentage >= 70 ? '#8BC34A' : ($match_percentage >= 50 ? '#FFC107' : '#F44336'));

            $activity_indicator_html = '';
            $last_active_time = bp_get_user_last_activity($user_id);
            if ($last_active_time) {
                $last_active_timestamp = strtotime($last_active_time);
                if (time() - $last_active_timestamp < 300) {
                    $activity_indicator_html = '<div class="online-indicator">ONLINE</div>';
                } else {
                    $activity_indicator_html = '<div class="last-active-indicator">Aktywny: ' . bp_core_time_since($last_active_time) . '</div>';
                }
            }

            $is_liked_by_me = in_array($user_id, $my_likes);

            $details = [
                'Polityka' => xprofile_get_field_data(215, $user_id) ?: 'Nie podano',
                'Religia' => xprofile_get_field_data(133, $user_id) ?: 'Nie podano',
                'Dieta' => xprofile_get_field_data(334, $user_id) ?: 'Nie podano',
                'Styl pracy' => xprofile_get_field_data(108, $user_id) ?: 'Nie podano',
            ];
            $detailsHTML = '';
            foreach ($details as $key => $value) {
                if ($value && $value !== 'Nie podano') {
                    $detailsHTML .= "<div class=\"detail-item\"><strong>{$key}:</strong> {$value}</div>";
                }
            }

            $birth_date_card = bp_get_profile_field_data(107, $user_id);
            $numerology_number_card = sk_calculate_life_path_number($birth_date_card);
            $numerologyHTML_card = '';
            if ($numerology_number_card) {
                $numerologyHTML_card = '<div class="detail-item numerology-display"><strong>Numerologia:</strong> ' . $numerology_number_card . '</div>';
            }

            // Check if user is premium
            $is_premium_user = sk_is_premium_user($user_id);
            $premium_badge = $is_premium_user ? '<span class="premium-badge" title="Użytkownik Premium">⭐</span>' : '';

            echo '<a href="' . bp_get_member_permalink() . '" class="user-card-link">';
            echo '  <div class="card-header">';
            echo '      <h3 class="user-name">' . bp_get_member_name() . '</h3>';
            echo '      <div class="header-actions">';
            echo '          <div class="user-match" style="color: ' . $match_color . ';">' . intval($match_percentage) . '% Dopasowanie</div>';
            echo '          <button class="like-button ' . ($is_liked_by_me ? 'liked' : '') . '" data-user-id="' . $user_id . '" title="Polub profil">';
            echo '              <span class="heart-icon">' . ($is_liked_by_me ? '❤️' : '🤍') . '</span>';
            echo '          </button>';
            echo '          <button class="block-button" data-user-id="' . $user_id . '" title="Zablokuj użytkownika">';
            echo '              <span class="block-icon">🚫</span>';
            echo '          </button>';
            echo '      </div>';
            echo '  </div>';
            echo '  <div class="card-body">';
            echo '      <div class="user-card-avatar">';
            echo bp_member_avatar('type=full&width=400&height=400');
            echo $activity_indicator_html;
            // Show premium badge only for premium users
            if ($is_premium_user) {
                echo '<span class="avatar-premium-badge">⭐</span>';
            }
            echo '      </div>';
            echo '      <div class="user-card-info">';
            echo '          <p class="user-location">' . (xprofile_get_field_data('Lokalizacja', $user_id) ?: 'Brak lokalizacji') . '</p>';
            echo '          <p class="user-bio">' . esc_html(wp_trim_words(xprofile_get_field_data('O mnie', $user_id), 15, '...')) . '</p>';
            echo '          <div class="user-details-list">' . $detailsHTML . $numerologyHTML_card . '</div>';
            echo '      </div>';
            echo '  </div>';
            echo '</a>';
        endwhile;
    }

    if (!$found_users_flag) {
        echo '<p style="grid-column: 1 / -1; text-align: center;">Nie znaleziono użytkowników spełniających te kryteria.</p>';
    }

    wp_die();
}
/**
 * ===================================================================
 * OSTATECZNE I KOMPLETNE ROZWIĄZANIE
 * Ten kod ukrywa zarówno kontener nagłówka (#item-header),
 * jak i jego wewnętrzną zawartość, rozwiązując wszystkie problemy.
 * ===================================================================
 */
add_action('wp_head', function () {
    if (bp_is_user()) {
        echo '<style>
            #buddypress #item-header,
            #buddypress #item-header-avatar,
            #buddypress #item-header-content {
                display: none !important;
            }
        </style>';
    }
});
/**
 * Dodaje limit znaków i licznik do pola biografii na stronie edycji profilu.
 */
function sk_add_char_limit_to_bio_field()
{
    // Upewniamy się, że skrypt jest dodawany tylko na stronie edycji profilu
    if (!bp_is_user_profile_edit()) {
        return;
    }
    ?>
    <style>
        /* Proste style dla licznika znaków */
        .char-counter {
            font-size: 0.9em;
            color: #777;
            text-align: right;
            margin-top: 5px;
        }
    </style>
    <script id="bio-char-limit-script">
        jQuery(document).ready(function ($) {
            // Celujemy w pole textarea o ID 'field_343'
            var bioTextarea = $('#field_343');

            // Sprawdzamy, czy takie pole istnieje na stronie
            if (bioTextarea.length) {
                var maxLength = 280; // Ustaw maksymalną liczbę znaków (ok. 4 linie)

                // Dodajemy atrybut maxlength do pola textarea
                bioTextarea.attr('maxlength', maxLength);

                // Dodajemy element licznika pod polem textarea
                bioTextarea.after('<div class="char-counter"></div>');
                var counter = bioTextarea.siblings('.char-counter');

                // Funkcja aktualizująca licznik
                function updateCounter() {
                    var currentLength = bioTextarea.val().length;
                    var remaining = maxLength - currentLength;
                    counter.text(remaining + ' znaków pozostało');
                }

                // Uruchamiamy licznik przy załadowaniu strony
                updateCounter();

                // Aktualizujemy licznik przy każdym wpisaniu znaku
                bioTextarea.on('keyup input', updateCounter);
            }
        });
    </script>
    <?php
}
// Podpinamy naszą funkcję do stopki WordPressa
add_action('wp_footer', 'sk_add_char_limit_to_bio_field');

/**
 * Oblicza numerologiczną Drogę Życia z daty urodzenia.
 * WERSJA 2.0 - BARDZIEJ NIEZAWODNA, ODPORNA NA RÓŻNE FORMATY DATY
 * @param string $date_string Data w dowolnym rozpoznawalnym przez PHP formacie.
 * @return int|string Numerologiczna liczba (1-9) lub liczba mistrzowska (11, 22, 33), lub pusty string.
 */
function sk_calculate_life_path_number($date_string)
{
    // Zwróć pusty wynik, jeśli data jest pusta
    if (empty($date_string)) {
        return '';
    }

    // Użyj wbudowanej w PHP funkcji do "zrozumienia" daty w różnych formatach.
    // To jest o wiele bardziej niezawodne niż ręczne czyszczenie stringa.
    $date_obj = date_create($date_string);

    // Jeśli PHP nie potrafi zinterpretować daty, zwróć pusty wynik
    if (!$date_obj) {
        return '';
    }

    // Sformatuj datę do postaci Czystych Cyfr (RokMiesiącDzień), aby mieć pewność,
    // że obliczenia zawsze działają na tych samych, prawidłowych danych.
    $date_digits = date_format($date_obj, 'Ymd');

    // Oblicz sumę cyfr
    $sum = array_sum(str_split($date_digits));

    // Redukuj sumę, aż uzyskasz pojedynczą cyfrę lub liczbę mistrzowską
    while ($sum > 9 && !in_array($sum, [11, 22, 33])) {
        $sum = array_sum(str_split((string) $sum));
    }

    return $sum;
}

function get_zodiac_sign($date_string)
{
    if (empty($date_string)) {
        return null;
    }
    // Upewnij się, że data jest w formacie RRRR-MM-DD
    $date = date_create($date_string);
    if (!$date) {
        return null;
    }
    $month = (int) date_format($date, 'm');
    $day = (int) date_format($date, 'd');

    $signs = [
        ['sign' => 'Koziorożec ♑', 'start_month' => 1, 'start_day' => 1, 'end_month' => 1, 'end_day' => 19],
        ['sign' => 'Wodnik ♒', 'start_month' => 1, 'start_day' => 20, 'end_month' => 2, 'end_day' => 18],
        ['sign' => 'Ryby ♓', 'start_month' => 2, 'start_day' => 19, 'end_month' => 3, 'end_day' => 20],
        ['sign' => 'Baran ♈', 'start_month' => 3, 'start_day' => 21, 'end_month' => 4, 'end_day' => 19],
        ['sign' => 'Byk ♉', 'start_month' => 4, 'start_day' => 20, 'end_month' => 5, 'end_day' => 20],
        ['sign' => 'Bliźnięta ♊', 'start_month' => 5, 'start_day' => 21, 'end_month' => 6, 'end_day' => 20],
        ['sign' => 'Rak ♋', 'start_month' => 6, 'start_day' => 21, 'end_month' => 7, 'end_day' => 22],
        ['sign' => 'Lew ♌', 'start_month' => 7, 'start_day' => 23, 'end_month' => 8, 'end_day' => 22],
        ['sign' => 'Panna ♍', 'start_month' => 8, 'start_day' => 23, 'end_month' => 9, 'end_day' => 22],
        ['sign' => 'Waga ♎', 'start_month' => 9, 'start_day' => 23, 'end_month' => 10, 'end_day' => 22],
        ['sign' => 'Skorpion ♏', 'start_month' => 10, 'start_day' => 23, 'end_month' => 11, 'end_day' => 21],
        ['sign' => 'Strzelec ♐', 'start_month' => 11, 'start_day' => 22, 'end_month' => 12, 'end_day' => 21],
        ['sign' => 'Koziorożec ♑', 'start_month' => 12, 'start_day' => 22, 'end_month' => 12, 'end_day' => 31],
    ];

    foreach ($signs as $s) {
        if (($month == $s['start_month'] && $day >= $s['start_day']) || ($month == $s['end_month'] && $day <= $s['end_day'])) {
            return $s['sign'];
        }
    }

    return null;
}


// === STATIC BACKGROUND FOR PRAWDZIWAMILOSC.PL ===
// Note: Removed GSAP libraries and animated background to prevent iOS/iPad auto-refresh issues

// CSS for static background gradient
function custom_static_background_css()
{
    ?>
    <style id="custom-static-background">
        /* Static background gradient - no animations to prevent iOS/iPad refresh issues */
        body {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 25%, #1a1a2e 50%, #0f3460 75%, #1a1a2e 100%) !important;
            background-attachment: fixed;
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }

        /* Static gradient overlay layer */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at 50% 50%, rgba(127, 83, 172, 0.3) 0%, rgba(100, 125, 238, 0.2) 35%, transparent 70%);
            pointer-events: none;
            z-index: -2;
        }

       
    </style>
    <?php
}
add_action('wp_head', 'custom_static_background_css', 100);

// Note: Animated background removed to prevent iOS/iPad auto-refresh issues
// The continuous requestAnimationFrame loops, CSS variable updates, and event listeners
// were causing Safari on iOS/iPad to reload the page every few minutes.
// Static background gradient is now defined in custom_static_background_css() function above

// === DOSTOSOWANIE NAGŁÓWKA I STOPKI DO CIEMNEGO TŁA - WZMOCNIONE ===

function custom_header_footer_dark_style()
{
    ?>
    <style id="dark-header-footer">
        /* ===== NAGŁÓWEK (HEADER) - ULTRA PRIORITY ===== */
        /* UWAGA: NIE UŻYWAJ backdrop-filter - powoduje auto-refresh na iOS/iPad */

        /* Wszystkie możliwe selektory nagłówka */
        header,
        #masthead,
        .site-header,
        #site-header,
        .header,
        .main-header-bar,
        .ast-header-break-point .main-header-bar,
        .main-header-bar-wrap,
        .ast-main-header-bar-alignment,
        body .site-header,
        body #masthead,
        .site-header-focus-item,
        .ast-builder-layout-header {
            background-color: rgba(26, 26, 46, 0.95) !important;
            background-image: none !important;
            background: rgba(26, 26, 46, 0.95) !important;
            border-bottom: 1px solid rgba(188, 111, 241, 0.2) !important;
        }

        /* Container nagłówka */
        .site-header .site-container,
        .main-header-bar .site-container,
        .ast-container {
            background: transparent !important;
        }

        /* Logo / tytuł witryny */
        .site-title,
        .site-title a,
        .logo,
        .logo a,
        header h1,
        header h1 a,
        .site-branding a,
        .custom-logo-link,
        .site-logo-img a {
            color: #ffffff !important;
            text-shadow: 0 0 20px rgba(188, 111, 241, 0.5) !important;
        }

        /* Menu nawigacji */
        .main-navigation a,
        .nav-menu a,
        header nav a,
        .menu-item a,
        .ast-header-navigation ul li a,
        .main-header-menu a,
        #primary-menu a,
        .ast-masthead-custom-menu-items a {
            color: #ffffff !important;
            transition: color 0.3s ease !important;
        }

        .main-navigation a:hover,
        .menu-item a:hover,
        .ast-header-navigation ul li a:hover {
            color: #bc6ff1 !important;
        }

        /* Przyciski w nagłówku */
        header .btn,
        header .button,
        .ast-header-button,
        .button-custom-menu-item .menu-link {
            background: linear-gradient(135deg, #7F53AC, #647DEE) !important;
            color: #ffffff !important;
            border: none !important;
        }

        /* Mobile menu button */
        .ast-mobile-menu-buttons .menu-toggle,
        .ast-button-wrap .menu-toggle,
        .menu-toggle {
            color: #ffffff !important;
            background: rgba(188, 111, 241, 0.2) !important;
            border-color: rgba(188, 111, 241, 0.3) !important;
        }

        /* ===== STOPKA (FOOTER) - ULTRA PRIORITY ===== */
        /* UWAGA: NIE UŻYWAJ backdrop-filter - powoduje auto-refresh na iOS/iPad */

        /* Wszystkie możliwe selektory stopki */
        footer,
        .site-footer,
        #colophon,
        .footer,
        .ast-footer-overlay,
        .ast-small-footer,
        .site-footer-primary-section,
        .site-footer-below-section,
        body .site-footer,
        body footer,
        .footer-widget-area,
        .ast-footer-widget,
        .footer-adv,
        .footer-adv-overlay {
            background-color: rgba(22, 33, 62, 0.95) !important;
            background-image: none !important;
            background: rgba(22, 33, 62, 0.95) !important;
            border-top: 1px solid rgba(188, 111, 241, 0.2) !important;
            color: #ffffff !important;
        }

        /* Container stopki */
        .site-footer .site-container,
        .ast-small-footer .ast-container,
        .footer-widget-area .ast-container {
            background: transparent !important;
        }

        /* Linki w stopce */
        footer a,
        .site-footer a,
        .footer-widget a,
        .ast-small-footer a,
        #colophon a {
            color: #bc6ff1 !important;
            transition: color 0.3s ease !important;
        }

        footer a:hover,
        .site-footer a:hover {
            color: #ffffff !important;
        }

        /* Tytuły widgetów w stopce */
        .footer-widget-area .widget-title,
        .site-footer h1,
        .site-footer h2,
        .site-footer h3,
        .ast-footer-widget .widget-title,
        .footer h1,
        .footer h2,
        .footer h3 {
            color: #ffffff !important;
            border-bottom: 2px solid rgba(188, 111, 241, 0.3) !important;
            padding-bottom: 10px !important;
        }

        /* Tekst w stopce */
        .site-footer p,
        .footer-widget,
        .copyright-text,
        .site-footer-section,
        .ast-small-footer p,
        .footer p,
        .site-info {
            color: rgba(255, 255, 255, 0.8) !important;
        }

        /* Copyright */
        .site-info,
        .ast-small-footer-section,
        .ast-footer-copyright {
            background: rgba(15, 52, 96, 0.9) !important;
            color: rgba(255, 255, 255, 0.7) !important;
        }

        /* Widget areas w stopce */
        .footer-widget-area .widget,
        .ast-footer-widget {
            background: rgba(255, 255, 255, 0.05) !important;
            border-radius: 8px !important;
            padding: 20px !important;
        }
    </style>
    <?php
}
add_action('wp_head', 'custom_header_footer_dark_style', 999);

function force_dark_header_footer_inline()
{
    echo '<style>
        * header, * .site-header, * #masthead { background: rgba(26, 26, 46, 0.95) !important; }
        * footer, * .site-footer, * #colophon { background: rgba(22, 33, 62, 0.95) !important; }
    </style>';
}
add_action('wp_head', 'force_dark_header_footer_inline', 9999);

// === UKRYJ KOMUNIKAT "ALREADY HAVE CONVERSATION" W BETTER MESSAGES ===
function hide_better_messages_existing_conversation_notice() {
    ?>
    <style>
        /* Ukryj komunikat "You already have a conversation with this member" */
        .bp-messages-content .bm-new-conversation .bm-existing-conversation,
        .bp-better-messages-mini .bm-existing-conversation,
        .bm-existing-conversation,
        .bp-messages .bm-new-conversation > div:first-child:not(.bm-send-to-container),
        [class*="existing-conversation"],
        .bp-messages-content .bm-new-conversation > p,
        .bm-new-conversation > p {
            display: none !important;
        }
    </style>
    <?php
}
add_action('wp_head', 'hide_better_messages_existing_conversation_notice', 9999);

// Ukryj header i dodaj tło na stronie rejestracji i onboardingu
function hide_header_on_registration_page() {
    global $post;
    if (!$post) return;
    
    // Sprawdź czy strona zawiera shortcode rejestracji LUB onboardingu
    $is_registration = has_shortcode($post->post_content, 'moj_formularz_rejestracji');
    $is_onboarding = has_shortcode($post->post_content, 'moj_onboarding_form');
    
    if (!$is_registration && !$is_onboarding) return;

    ?>
    <style>
        /* Ukryj header na stronie rejestracji */
        body header,
        body .site-header,
        body #masthead,
        body .header-wrapper,
        body .ast-header-break-point,
        body .ast-primary-header,
        body #ast-desktop-header,
        body #ast-mobile-header {
            display: none !important;
        }
        
        /* Tło strony rejestracji */
        body {
            background: url('https://prawdziwamilosc.pl/venus.jpg') center center / cover no-repeat fixed !important;
            min-height: 100vh;
        }
        
        /* Glassmorphism dla formularza */
        .custom-reg-container {
            background: rgba(255, 255, 255, 0.92) !important;
            backdrop-filter: blur(20px) !important;
            -webkit-backdrop-filter: blur(20px) !important;
            border-radius: 24px !important;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3) !important;
            padding: 40px !important;
            max-width: 440px !important;
            margin: 40px auto !important;
            border: 1px solid rgba(255, 255, 255, 0.3) !important;
        }
        
        /* Usuń padding z contentu strony */
        .entry-content,
        .site-content,
        #content,
        main {
            padding-top: 0 !important;
        }
        
        /* Ukryj footer na stronie rejestracji */
        body footer,
        body .site-footer,
        body #colophon {
            display: none !important;
        }
    </style>
    <?php
}
add_action('wp_head', 'hide_header_on_registration_page', 9999);

/**
 * App Store Download Button Shortcode/Helper
 */
function pm_get_app_store_button() {
    return '
    <div class="pm-app-store-button-container" style="text-align: center; margin: 20px 0;">
        <a href="https://apps.apple.com/app/id6758733087" target="_blank" style="display: inline-block;">
            <img src="https://prawdziwamilosc.pl/wp-content/uploads/2024/03/app-store-badge.png" alt="Pobierz w App Store" style="height: 45px; border-radius: 8px;">
        </a>
    </div>';
}

// === NAPRAW STOPKĘ - EKSTRA MOCNE STYLE ===

function force_footer_dark_background()
{
    ?>
    <style id="force-dark-footer">
        /* STOPKA - WSZYSTKIE SEKCJE */
        footer,
        footer *,
        .site-footer,
        .site-footer *,
        #colophon,
        #colophon *,
        .footer,
        .ast-footer-overlay,
        .ast-small-footer,
        .ast-small-footer *,
        .site-footer-primary-section,
        .site-footer-primary-section *,
        .site-footer-below-section,
        .site-footer-below-section *,
        .footer-widget-area,
        .footer-widget-area *,
        .ast-footer-widget,
        .ast-footer-widget *,
        .footer-adv,
        .footer-adv *,
        .footer-adv-overlay,
        .footer-adv-overlay * {
            background-color: transparent !important;
        }

        /* GŁÓWNE TŁO STOPKI */
        footer,
        .site-footer,
        #colophon,
        body .site-footer,
        body footer {
            background: rgba(22, 33, 62, 0.95) !important;
            background-color: rgba(22, 33, 62, 0.95) !important;
            background-image: none !important;
            border-top: 1px solid rgba(188, 111, 241, 0.2) !important;
        }

        /* SEKCJE STOPKI */
        .ast-small-footer,
        .site-footer-primary-section,
        .site-footer-below-section,
        .footer-widget-area,
        .ast-footer-widget,
        .footer-adv {
            background: transparent !important;
            background-color: transparent !important;
            background-image: none !important;
        }

        /* KONTENERY WEWNĄTRZ */
        footer .site-container,
        footer .ast-container,
        .site-footer .site-container,
        .site-footer .ast-container,
        .ast-small-footer .ast-container,
        .footer-widget-area .ast-container {
            background: transparent !important;
            background-color: transparent !important;
        }

        /* POJEDYNCZE WIDGETY */
        .footer-widget,
        .widget,
        footer .widget,
        .site-footer .widget {
            background: rgba(255, 255, 255, 0.05) !important;
            border-radius: 8px !important;
            padding: 20px !important;
        }

        /* KOLUMNY STOPKI */
        .site-footer-section,
        .footer-adv .footer-adv-widget,
        .ast-footer-widget-1-area,
        .ast-footer-widget-2-area,
        .ast-footer-widget-3-area,
        .ast-footer-widget-4-area {
            background: transparent !important;
        }

        /* COPYRIGHT / SITE INFO */
        .site-info,
        .ast-small-footer-section,
        .ast-footer-copyright,
        .copyright-text {
            background: rgba(15, 52, 96, 0.9) !important;
            background-color: rgba(15, 52, 96, 0.9) !important;
            color: rgba(255, 255, 255, 0.7) !important;
            padding: 20px 0 !important;
        }

        /* TEKST W STOPCE */
        footer,
        footer p,
        footer span,
        footer div,
        .site-footer,
        .site-footer p,
        .site-footer span,
        .site-footer div,
        .ast-small-footer,
        .ast-small-footer p {
            color: rgba(255, 255, 255, 0.8) !important;
        }

        /* TYTUŁY W STOPCE */
        footer h1,
        footer h2,
        footer h3,
        footer h4,
        footer .widget-title,
        .site-footer h1,
        .site-footer h2,
        .site-footer h3,
        .site-footer h4,
        .site-footer .widget-title {
            color: #ffffff !important;
            border-bottom: 2px solid rgba(188, 111, 241, 0.3) !important;
            padding-bottom: 10px !important;
            margin-bottom: 15px !important;
        }

        /* LINKI W STOPCE */
        footer a,
        .site-footer a,
        .ast-small-footer a {
            color: #bc6ff1 !important;
            background: transparent !important;
        }

        footer a:hover,
        .site-footer a:hover {
            color: #ffffff !important;
        }

        /* MENU W STOPCE */
        .footer-menu,
        .footer-menu ul,
        .footer-menu li {
            background: transparent !important;
        }

        .footer-menu a {
            color: #bc6ff1 !important;
        }

        /* SOCIAL ICONS W STOPCE */
        footer .social-icons,
        footer .social-icons a {
            background: transparent !important;
            color: #bc6ff1 !important;
        }
    </style>
    <?php
}
add_action('wp_footer', 'force_footer_dark_background', 1);
add_action('wp_head', 'force_footer_dark_background', 9999);

// === ROZJAŚNIENIE MENU DASHBOARDU (SIDEBAR) ===
// === ROZJAŚNIENIE LEWEGO MENU UŻYTKOWNIKA - FINAL ===
function final_brighten_left_menu()
{
    ?>
    <style>
        /* Wszystkie linki i teksty w lewym menu */
        #item-nav a,
        #item-nav span,
        .item-list-tabs a,
        .item-list-tabs span,
        #object-nav a,
        #object-nav span,
        #subnav a,
        #subnav span,
        .bp-navs a,
        .bp-navs span {
            color: #ffffff !important;
            font-weight: 500 !important;
        }

        /* Hover */
        #item-nav a:hover,
        .item-list-tabs a:hover,
        #object-nav a:hover {
            color: #bc6ff1 !important;
            background: rgba(188, 111, 241, 0.2) !important;
        }

        /* Aktywny element */
        #item-nav li.current a,
        #item-nav li.selected a,
        .item-list-tabs li.current a,
        .item-list-tabs li.selected a,
        #object-nav li.current a,
        #object-nav li.selected a {
            color: #bc6ff1 !important;
            background: rgba(188, 111, 241, 0.25) !important;
            font-weight: bold !important;
        }

        /* Liczniki */
        .count,
        span.count {
            background: linear-gradient(135deg, #bc6ff1, #7F53AC) !important;
            color: #ffffff !important;
            padding: 3px 8px !important;
            border-radius: 12px !important;
        }

        /* Tło menu */
        #item-nav,
        .item-list-tabs,
        #object-nav {
            background: rgba(26, 26, 46, 0.7) !important;
            border-radius: 8px !important;
            padding: 10px !important;
        }
    </style>
    <?php
}
add_action('wp_head', 'final_brighten_left_menu', 99999);

// === ROZJAŚNIENIE WSZYSTKICH MENU (GÓRNE + LEWE) ===
function brighten_all_navigation()
{
    ?>
    <style>
        /* GÓRNE MENU NAWIGACJI */
        nav,
        nav a,
        nav span,
        .navigation a,
        .navigation span,
        .menu a,
        .menu span,
        #site-navigation a,
        #site-navigation span,
        .main-navigation a,
        .main-navigation span,
        header nav a,
        header nav span {
            color: #ffffff !important;
            font-weight: 500 !important;
        }

        /* IKONY W MENU */
        nav svg,
        nav i,
        .menu svg,
        .menu i,
        .navigation svg {
            fill: #ffffff !important;
            color: #ffffff !important;
        }

        /* HOVER */
        nav a:hover,
        .menu a:hover,
        .navigation a:hover {
            color: #bc6ff1 !important;
        }

        /* AKTYWNY ELEMENT */
        nav .current-menu-item a,
        nav .current_page_item a,
        .menu .current-menu-item a {
            color: #bc6ff1 !important;
            font-weight: bold !important;
        }

        /* LEWE MENU UŻYTKOWNIKA - BuddyPress */
        #item-nav,
        #item-nav a,
        #item-nav span,
        .item-list-tabs,
        .item-list-tabs a,
        .item-list-tabs span,
        #object-nav,
        #object-nav a,
        #object-nav span,
        #subnav,
        #subnav a,
        #subnav span,
        .bp-navs,
        .bp-navs a,
        .bp-navs span,
        .bp-subnavs,
        .bp-subnavs a,
        .bp-subnavs span {
            color: #ffffff !important;
            font-weight: 500 !important;
        }

        /* Sidebar widgets */
        aside,
        aside a,
        aside span,
        .sidebar,
        .sidebar a,
        .sidebar span,
        .widget,
        .widget a,
        .widget span {
            color: rgba(255, 255, 255, 0.9) !important;
        }

        /* Widget titles */
        .widget-title,
        aside h1,
        aside h2,
        aside h3,
        .sidebar h1,
        .sidebar h2,
        .sidebar h3 {
            color: #ffffff !important;
            border-bottom: 2px solid rgba(188, 111, 241, 0.4) !important;
        }

        /* Wszystkie linki hover */
        aside a:hover,
        .sidebar a:hover,
        .widget a:hover {
            color: #bc6ff1 !important;
        }

        /* Liczniki */
        .count,
        span.count,
        .badge {
            background: linear-gradient(135deg, #bc6ff1, #7F53AC) !important;
            color: #ffffff !important;
            padding: 3px 8px !important;
            border-radius: 12px !important;
            font-weight: bold !important;
        }

        /* Tło dla lewego menu */
        #item-nav,
        .item-list-tabs,
        #object-nav,
        aside,
        .sidebar {
            background: rgba(26, 26, 46, 0.7) !important;
            border-radius: 8px !important;
            padding: 15px !important;
        }
    </style>
    <?php
}
add_action('wp_head', 'brighten_all_navigation', 99999);

// === FIX MOBILE NAVIGATION TABS VISIBILITY ===
function pm_fix_mobile_nav_tabs_visibility() {
    ?>
    <style>
        /* Fix for mobile navigation tabs - ensure visibility */
        @media (max-width: 768px) {
            /* BuddyPress profile navigation tabs */
            #item-nav ul li a,
            #object-nav ul li a,
            .item-list-tabs ul li a,
            .type-nav ul li a,
            nav.bp-navs ul li a,
            #subnav ul li a {
                background: rgba(26, 26, 46, 0.85) !important;
                color: #ffffff !important;
                padding: 8px 12px !important;
                border-radius: 6px !important;
                margin: 2px !important;
                display: inline-block !important;
            }
            
            /* Active/current tab */
            #item-nav ul li.current a,
            #item-nav ul li.selected a,
            #object-nav ul li.current a,
            .item-list-tabs ul li.current a,
            .type-nav ul li.current a,
            nav.bp-navs ul li.current a,
            nav.bp-navs ul li.selected a {
                background: linear-gradient(135deg, #bc6ff1, #7F53AC) !important;
                color: #ffffff !important;
            }
            
            /* Navigation container */
            #item-nav,
            #object-nav,
            .item-list-tabs,
            nav.bp-navs {
                background: transparent !important;
                overflow-x: auto !important;
                white-space: nowrap !important;
                -webkit-overflow-scrolling: touch !important;
            }
            
            /* Nav list */
            #item-nav ul,
            #object-nav ul,
            .item-list-tabs ul,
            nav.bp-navs ul {
                display: flex !important;
                flex-wrap: wrap !important;
                gap: 4px !important;
                padding: 8px !important;
                margin: 0 !important;
                list-style: none !important;
            }
            
            /* Count badges */
            #item-nav .count,
            #object-nav .count,
            .item-list-tabs .count,
            nav.bp-navs .count {
                background: linear-gradient(135deg, #bc6ff1, #7F53AC) !important;
                color: #fff !important;
                font-size: 11px !important;
                padding: 2px 6px !important;
                border-radius: 10px !important;
                margin-left: 4px !important;
            }
        }
    </style>
    <?php
}
add_action('wp_head', 'pm_fix_mobile_nav_tabs_visibility', 100000);

// === SHORTCODE REJESTRACJI KROK 1 ===
function rejestracja_krok1_shortcode()
{
    ob_start();
    echo pm_get_app_store_button();

    // === TWÓJ KOD PHP TUTAJ ===
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Walidacja wieku
        if (isset($_POST['data_urodzenia']) && !empty($_POST['data_urodzenia'])) {
            $birthDate = new DateTime($_POST['data_urodzenia']);
            $today = new DateTime('today');
            $age = $today->diff($birthDate)->y;
            if ($age < 18) {
                echo '<div class="error">Musisz mieć ukończone 18 lat!</div>';
                return ob_get_clean();
            }
        }

        // Walidacja hasła
        $password = $_POST['password'] ?? '';
        $password2 = $_POST['password2'] ?? '';
        if ($password !== $password2) {
            echo '<div class="error">Hasła nie są takie same!</div>';
            return ob_get_clean();
        }

        // Zapis do sesji
        $_SESSION['rejestracja'] = [
            'username' => sanitize_user($_POST['username'] ?? ''),
            'email' => sanitize_email($_POST['email'] ?? ''),
            'password' => $password,
            'gender' => sanitize_text_field($_POST['gender'] ?? ''),
            'Szukam' => sanitize_text_field($_POST['Szukam'] ?? ''),
            'data_urodzenia' => sanitize_text_field($_POST['data_urodzenia'] ?? ''),
            'widocznosc_daty' => sanitize_text_field($_POST['widocznosc_daty'] ?? 'adminsonly')
        ];

        wp_redirect(home_url('/rejestracja-krok2/'));
        exit;
    }

    // === HTML FORMULARZA ===
    ?>

    <style>
        .reg-container {
            max-width: 600px;
            margin: 40px auto;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.05);
            padding: 40px;
        }

        .reg-container h1 {
            font-size: 28px;
            color: #2e7d32;
            margin-bottom: 10px;
        }

        .reg-container label {
            font-weight: 600;
            display: block;
            margin: 20px 0 6px;
        }

        .reg-container input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 16px;
        }

        .reg-container .note {
            font-size: 13px;
            color: #888;
            margin-top: 4px;
        }

        .reg-container .radio-group {
            display: flex;
            gap: 20px;
            margin-top: 10px;
            flex-wrap: wrap;
        }

        .reg-container .radio-group input {
            width: auto;
        }

        .reg-container button {
            background-color: #2e7d32;
            color: #fff;
            border: none;
            padding: 14px 20px;
            font-size: 16px;
            border-radius: 6px;
            margin-top: 30px;
            cursor: pointer;
            width: 100%;
        }

        .reg-container button:hover {
            background-color: #1b5e20;
        }

        .error {
            background: #ffebee;
            color: #c62828;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
    </style>

    <div class="reg-container">
        <h1>Witaj w społeczności Prawdziwej Miłości!</h1>
        <p>Serdecznie zapraszamy Cię do dołączenia do naszej wyjątkowej społeczności!</p>

        <form method="POST" id="rejestracja-krok1-form">
            <label for="username">*Nazwa użytkownika</label>
            <input type="text" name="username" id="username" required maxlength="15" pattern="^[a-zA-Z0-9]+$">
            <div class="note">Maksymalnie 15 znaków, bez spacji i polskich znaków.</div>

            <label for="password">*Hasło</label>
            <input type="password" name="password" id="password" required minlength="6" maxlength="15">

            <label for="password2">*Potwierdź hasło</label>
            <input type="password" name="password2" id="password2" required minlength="6" maxlength="15">

            <label for="email">*Email</label>
            <input type="email" name="email" id="email" required>

            <label for="data_urodzenia">*Data urodzenia</label>
            <input type="date" name="data_urodzenia" id="data_urodzenia" required>

            <label>*Widoczność wieku</label>
            <div class="radio-group">
                <label><input type="radio" name="widocznosc_daty" value="public" checked> Pokazuj wiek</label>
                <label><input type="radio" name="widocznosc_daty" value="adminsonly"> Ukryj wiek</label>
            </div>

            <label>*Jestem</label>
            <div class="radio-group">
                <label><input type="radio" name="gender" value="Mężczyzna" required> Mężczyzną</label>
                <label><input type="radio" name="gender" value="Kobieta" required> Kobietą</label>
                <label><input type="radio" name="gender" value="Inna" required> Inna</label>
            </div>

            <label>*Szukam</label>
            <div class="radio-group">
                <label><input type="radio" name="Szukam" value="Kobiety" required> Kobiety</label>
                <label><input type="radio" name="Szukam" value="Mężczyzny" required> Mężczyzny</label>
                <label><input type="radio" name="Szukam" value="Wszystkich" required> Wszystkich</label>
            </div>

            <button type="submit">Dalej</button>
        </form>
    </div>

    <script>
        document.getElementById('rejestracja-krok1-form').addEventListener('submit', function (e) {
            const password = document.getElementById('password');
            const password2 = document.getElementById('password2');
            if (password.value !== password2.value) {
                alert('Hasła nie są takie same.');
                e.preventDefault();
            }
        });
    </script>

    <?php
    return ob_get_clean();
}
add_shortcode('rejestracja_krok1', 'rejestracja_krok1_shortcode');


// Shortcode główny dla wielokrokowego formularza
add_shortcode('formularz_rejestracyjny', 'wyswietl_formularz_rejestracyjny');

function wyswietl_formularz_rejestracyjny()
{
    ob_start();

    // DEBUG - sprawdź czy shortcode się wykonuje
    echo '<!-- SHORTCODE WYKONUJE SIĘ! Krok z GET: ' . (isset($_GET['krok']) ? $_GET['krok'] : 'brak') . ' -->';

    $aktualny_krok = isset($_GET['krok']) ? intval($_GET['krok']) : 1;

    // DEBUG - sprawdź jaki krok został wybrany
    echo '<!-- AKTUALNY KROK: ' . $aktualny_krok . ' -->';
    // Przetwarzanie formularza
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
        if (!isset($_SESSION['rejestracja'])) {
            $_SESSION['rejestracja'] = [];
        }

        // Obsługa checkboxów (np. języki)
        if (isset($_POST['jezyki']) && is_array($_POST['jezyki'])) {
            $_SESSION['rejestracja']['jezyki'] = implode(', ', array_map('sanitize_text_field', $_POST['jezyki']));
        }

        // Zapisywanie pozostałych danych do sesji
        foreach ($_POST as $klucz => $wartosc) {
            if ($klucz !== 'submit' && $klucz !== 'jezyki') {
                $_SESSION['rejestracja'][$klucz] = is_array($wartosc) ? array_map('sanitize_text_field', $wartosc) : sanitize_text_field($wartosc);
            }
        }
    }

    // KROK 6 - Finalizacja rejestracji (po przesłaniu kroku 5)
    if ($aktualny_krok == 6) {
        $dane = $_SESSION['rejestracja'] ?? [];
        $usermeta = [];

        // Obsługa zdjęcia profilowego
        if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] == 0) {
            if (!function_exists('wp_handle_upload')) {
                require_once(ABSPATH . 'wp-admin/includes/file.php');
            }
            $przeslany_plik = $_FILES['profile_photo'];
            $upload_overrides = ['test_form' => false];
            $movefile = wp_handle_upload($przeslany_plik, $upload_overrides);

            if ($movefile && !isset($movefile['error'])) {
                $usermeta['temp_avatar_path_for_activation'] = $movefile['file'];
            } else {
                echo '<div class="rejestracja-status error">Błąd podczas przesyłania pliku: ' . esc_html($movefile['error']) . '</div>';
                return ob_get_clean();
            }
        } else {
            echo '<div class="rejestracja-status error">Zdjęcie profilowe jest wymagane.</div>';
            return ob_get_clean();
        }

        // DOKŁADNE MAPOWANIE NA PODSTAWIE TWOICH DANYCH
        $mapowanie_pol = [
            // Base Group
            'name' => 'field_1',              // Name (wymagane)
            'gender' => 'field_129',          // Płeć
            'kraj' => 'field_101',            // Kraj
            'data_urodzenia' => 'field_107',  // Data urodzenia
            'orientacja' => 'field_94',       // Orientacja seksualna
            'szukam' => 'field_338',          // Szukam
            'about_me' => 'field_343',        // Krótko o mnie

            // Relacje Group
            'szukam_relacji' => 'field_198',  // Szukam Relacji
            'status_relacyjny' => 'field_218',// Status Relacyjny
            'dzieci' => 'field_290',          // Czy masz dzieci
            'chce_dzieci' => 'field_295',     // Czy chcesz mieć (więcej) dzieci

            // Polityka Group
            'polityka_skrot' => 'field_215',  // W skrócie Polityka
            'identyfikacja_polityczna' => 'field_190', // Identyfikacja Polityczna
            'alkohol' => 'field_286',         // Podejście do Alkoholu

            // Zainteresowania i Hobby
            'czytac' => 'field_329',          // Czy lubisz czytać książki

            // Duchowość Group
            'podejscie_wiary' => 'field_133', // Ogólne Podejście do Wiary
            'religia' => 'field_136',         // Religia
            'reinkarnacja' => 'field_157',    // Podejście do Reinkarnacji
            'duchowosc_alternatywna' => 'field_160', // Zainteresowanie Duchowością Alternatywną
            'zodiak' => 'field_303',          // Twój znak Zodiaku

            // Dieta i Sport Group
            'preferencje_jedzeniowe' => 'field_226', // Preferencje Jedzeniowe
            'friendly_420' => 'field_236',    // 420 Friendly
            'typ_ciala' => 'field_316',       // Typ ciała
            'cwiczenia' => 'field_324',       // Jak często ćwiczysz
            'dieta' => 'field_334',           // Dieta

            // Praca i Kariera Group
            'styl_pracy' => 'field_108',      // Styl Pracy
            'luksus' => 'field_114',          // Podejście do Luksusu
            'ryzyko' => 'field_185',          // Podejście do Ryzyka
            'jezyki' => 'field_206',          // Jakie znasz języki
            'mieszkanie' => 'field_298',      // Mieszkanie
        ];

        // Przygotuj dane XProfile do zapisania po aktywacji
        $dane_xprofile = [];
        foreach ($mapowanie_pol as $klucz_sesji => $field_id) {
            if (isset($dane[$klucz_sesji]) && !empty($dane[$klucz_sesji])) {
                // Usuń prefix "field_" i zostaw tylko numer ID
                $dane_xprofile[str_replace('field_', '', $field_id)] = $dane[$klucz_sesji];
            }
        }

        // Zapisz dane XProfile jako usermeta (zostaną przetworzone po aktywacji)
        $usermeta['pending_xprofile_data'] = $dane_xprofile;
        $usermeta['temp_password_for_activation'] = $dane['password'];

        if (function_exists('bp_core_signup_user') && !empty($dane)) {
            $wynik_rejestracji = bp_core_signup_user(
                $dane['username'],
                $dane['password'],
                $dane['email'],
                $usermeta
            );

            if (is_wp_error($wynik_rejestracji)) {
                echo '<div class="rejestracja-status error">Błąd rejestracji: ' . $wynik_rejestracji->get_error_message() . '</div>';
            } else {
                echo '<div class="rejestracja-status success">Twoje konto zostało wstępnie utworzone! Sprawdź swoją skrzynkę e-mail i kliknij w link aktywacyjny, aby dokończyć rejestrację. (Sprawdź folder SPAM)</div>';
                unset($_SESSION['rejestracja']);
            }
        } else {
            echo '<div class="rejestracja-status error">Błąd krytyczny: Dane rejestracyjne są puste lub funkcja BuddyPress jest niedostępna.</div>';
        }
    } else {
        // Wyświetl pasek postępu i formularz dla aktualnego kroku
        wyswietl_pasek_postepu($aktualny_krok);
        wyswietl_formularz_dla_kroku($aktualny_krok);
    }

    return ob_get_clean();
}

function wyswietl_pasek_postepu($aktualny_krok)
{
    $kroki = [
        1 => 'Dane Podstawowe',
        2 => 'Relacje',
        3 => 'Duchowość',
        4 => 'Styl Życia',
        5 => 'O Mnie i Zdjęcie'
    ];
    ?>
    <style>
        .pasek-postepu {
            display: flex;
            flex-wrap: wrap;
            list-style: none;
            padding: 0;
            margin: 20px 0;
            gap: 5px;
        }

        .pasek-postepu li {
            flex: 1;
            text-align: center;
            padding: 10px;
            background-color: #f0f0f0;
            border-radius: 8px;
            min-width: 100px;
        }

        .pasek-postepu li a {
            color: inherit;
            text-decoration: none;
            display: block;
        }

        .pasek-postepu li.aktywny {
            background-color: #a873e8;
            color: white;
            font-weight: bold;
        }

        .pasek-postepu li.ukonczony {
            background-color: #d8c0f5;
            color: #333;
            cursor: pointer;
        }

        .pasek-postepu li.ukonczony:hover {
            background-color: #c8aee8;
        }

        .form-container {
            padding: 25px;
            border-radius: 8px;
            margin-top: 20px;
            background-color: rgba(255, 255, 255, 0.9);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
        }

        .form-container h2 {
            margin-top: 0;
            color: #2e7d32;
        }

        .form-container label {
            display: block;
            margin: 15px 0 5px;
            font-weight: 600;
        }

        .form-container input[type="text"],
        .form-container input[type="email"],
        .form-container input[type="password"],
        .form-container input[type="date"],
        .form-container select,
        .form-container textarea {
            width: 100%;
            padding: 10px;
            margin-bottom: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }

        .form-container .radio-group,
        .form-container .checkbox-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 15px;
        }

        .form-container .radio-group label,
        .form-container .checkbox-group label {
            font-weight: normal;
            display: flex;
            align-items: center;
        }

        .form-container .radio-group input,
        .form-container .checkbox-group input {
            width: auto;
            margin-right: 8px;
        }

        .form-container input[type="submit"] {
            background-color: #a873e8;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            width: 100%;
            margin-top: 20px;
        }

        .form-container input[type="submit"]:hover {
            background-color: #8e5ccb;
        }

        .rejestracja-status {
            padding: 20px;
            margin: 20px auto;
            border-radius: 8px;
            font-size: 18px;
            text-align: center;
            font-weight: bold;
            max-width: 800px;
        }

        .rejestracja-status.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .rejestracja-status.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
    <ul class="pasek-postepu">
        <?php foreach ($kroki as $numer => $nazwa):
            $klasa = '';
            if ($numer == $aktualny_krok)
                $klasa = 'aktywny';
            elseif ($numer < $aktualny_krok)
                $klasa = 'ukonczony';
            ?>
            <li class="<?php echo $klasa; ?>">
                <?php if ($numer < $aktualny_krok && isset($_SESSION['rejestracja'])): ?>
                    <a href="<?php echo esc_url(add_query_arg('krok', $numer, get_permalink())); ?>">Krok
                        <?php echo $numer . ': ' . esc_html($nazwa); ?></a>
                <?php else: ?>
                    Krok <?php echo $numer . ': ' . esc_html($nazwa); ?>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php
}

function wyswietl_formularz_dla_kroku($krok)
{
    // Formularz wysyła na tę samą stronę BEZ parametru krok
    $base_url = str_replace('/register-one/', '/register-ONE/', get_permalink());
    $action_url = add_query_arg('krok', $krok + 1, $base_url);
    $dane_sesji = $_SESSION['rejestracja'] ?? [];
    ?>
    <div class="form-container">
        <form action="<?php echo esc_url($action_url); ?>" method="post" <?php if ($krok == 5)
               echo 'enctype="multipart/form-data"'; ?>>
            <?php
            switch ($krok) {
                case 1: ?>
                    <h2>Krok 1: Dane Podstawowe</h2>
                    <p>Wprowadź swoje podstawowe dane, aby rozpocząć.</p>

                    <label for="username">*Nazwa użytkownika:</label>
                    <input type="text" name="username" id="username" value="<?php echo esc_attr($dane_sesji['username'] ?? ''); ?>"
                        required maxlength="15">

                    <label for="email">*E-mail:</label>
                    <input type="email" name="email" id="email" value="<?php echo esc_attr($dane_sesji['email'] ?? ''); ?>"
                        required>

                    <label for="password">*Hasło:</label>
                    <input type="password" name="password" id="password" required minlength="6">

                    <label for="password2">*Powtórz hasło:</label>
                    <input type="password" name="password2" id="password2" required minlength="6">

                    <label for="name">*Imię (wyświetlane):</label>
                    <input type="text" name="name" id="name" value="<?php echo esc_attr($dane_sesji['name'] ?? ''); ?>" required>

                    <label>*Płeć:</label>
                    <div class="radio-group">
                        <label><input type="radio" name="gender" value="Mężczyzna" <?php checked($dane_sesji['gender'] ?? '', 'Mężczyzna'); ?> required> Mężczyzna</label>
                        <label><input type="radio" name="gender" value="Kobieta" <?php checked($dane_sesji['gender'] ?? '', 'Kobieta'); ?> required> Kobieta</label>
                        <label><input type="radio" name="gender" value="Inna" <?php checked($dane_sesji['gender'] ?? '', 'Inna'); ?>
                                required> Inna</label>
                    </div>

                    <label for="data_urodzenia">*Data urodzenia:</label>
                    <input type="date" name="data_urodzenia" id="data_urodzenia"
                        value="<?php echo esc_attr($dane_sesji['data_urodzenia'] ?? ''); ?>" required>

                    <label for="kraj">Kraj:</label>
                    <input type="text" name="kraj" id="kraj" value="<?php echo esc_attr($dane_sesji['kraj'] ?? ''); ?>">

                    <label for="orientacja">Orientacja seksualna:</label>
                    <select name="orientacja" id="orientacja">
                        <option value="">-- Wybierz --</option>
                        <option value="Heteroseksualna" <?php selected($dane_sesji['orientacja'] ?? '', 'Heteroseksualna'); ?>>
                            Heteroseksualna</option>
                        <option value="Homoseksualna" <?php selected($dane_sesji['orientacja'] ?? '', 'Homoseksualna'); ?>>
                            Homoseksualna</option>
                        <option value="Biseksualna" <?php selected($dane_sesji['orientacja'] ?? '', 'Biseksualna'); ?>>Biseksualna
                        </option>
                        <option value="Panseksualna" <?php selected($dane_sesji['orientacja'] ?? '', 'Panseksualna'); ?>>
                            Panseksualna</option>
                        <option value="Aseksualna" <?php selected($dane_sesji['orientacja'] ?? '', 'Aseksualna'); ?>>Aseksualna
                        </option>
                    </select>

                    <label>*Szukam:</label>
                    <div class="radio-group">
                        <label><input type="radio" name="szukam" value="Kobiety" <?php checked($dane_sesji['szukam'] ?? '', 'Kobiety'); ?> required> Kobiety</label>
                        <label><input type="radio" name="szukam" value="Mężczyzny" <?php checked($dane_sesji['szukam'] ?? '', 'Mężczyzny'); ?> required> Mężczyzny</label>
                        <label><input type="radio" name="szukam" value="Wszystkich" <?php checked($dane_sesji['szukam'] ?? '', 'Wszystkich'); ?> required> Wszystkich</label>
                    </div>
                    <?php break;

                case 2: ?>
                    <h2>Krok 2: Relacje</h2>

                    <label>Szukam Relacji:</label>
                    <div class="radio-group">
                        <label><input type="radio" name="szukam_relacji" value="Długoterminowej" <?php checked($dane_sesji['szukam_relacji'] ?? '', 'Długoterminowej'); ?>> Długoterminowej</label>
                        <label><input type="radio" name="szukam_relacji" value="Małżeństwa" <?php checked($dane_sesji['szukam_relacji'] ?? '', 'Małżeństwa'); ?>> Małżeństwa</label>
                        <label><input type="radio" name="szukam_relacji" value="Przyjaźni" <?php checked($dane_sesji['szukam_relacji'] ?? '', 'Przyjaźni'); ?>> Przyjaźni</label>
                        <label><input type="radio" name="szukam_relacji" value="Miłości" <?php checked($dane_sesji['szukam_relacji'] ?? '', 'Miłości'); ?>> Miłości</label>
                        <label><input type="radio" name="szukam_relacji" value="Otwartego Związku" <?php checked($dane_sesji['szukam_relacji'] ?? '', 'Otwartego Związku'); ?>> Otwartego Związku</label>
                        <label><input type="radio" name="szukam_relacji" value="Kogoś do Popisania" <?php checked($dane_sesji['szukam_relacji'] ?? '', 'Kogoś do Popisania'); ?>> Kogoś do Popisania</label>
                        <label><input type="radio" name="szukam_relacji" value="Kompana do podróży" <?php checked($dane_sesji['szukam_relacji'] ?? '', 'Kompana do podróży'); ?>> Kompana do podróży</label>
                    </div>

                    <label>Status Relacyjny:</label>
                    <div class="radio-group">
                        <label><input type="radio" name="status_relacyjny" value="Singiel" <?php checked($dane_sesji['status_relacyjny'] ?? '', 'Singiel'); ?>> Singiel</label>
                        <label><input type="radio" name="status_relacyjny" value="Rozwiedziony" <?php checked($dane_sesji['status_relacyjny'] ?? '', 'Rozwiedziony'); ?>> Rozwiedziony</label>
                        <label><input type="radio" name="status_relacyjny" value="W Separacji" <?php checked($dane_sesji['status_relacyjny'] ?? '', 'W Separacji'); ?>> W Separacji</label>
                        <label><input type="radio" name="status_relacyjny" value="Wdowa/wiec" <?php checked($dane_sesji['status_relacyjny'] ?? '', 'Wdowa/wiec'); ?>> Wdowa/wiec</label>
                        <label><input type="radio" name="status_relacyjny" value="W relacji" <?php checked($dane_sesji['status_relacyjny'] ?? '', 'W relacji'); ?>> W relacji</label>
                        <label><input type="radio" name="status_relacyjny" value="W otwartym związku" <?php checked($dane_sesji['status_relacyjny'] ?? '', 'W otwartym związku'); ?>> W otwartym związku</label>
                        <label><input type="radio" name="status_relacyjny" value="Żonaty/Zamężna" <?php checked($dane_sesji['status_relacyjny'] ?? '', 'Żonaty/Zamężna'); ?>> Żonaty/Zamężna</label>
                    </div>

                    <label>Czy masz dzieci:</label>
                    <div class="radio-group">
                        <label><input type="radio" name="dzieci" value="Tak" <?php checked($dane_sesji['dzieci'] ?? '', 'Tak'); ?>>
                            Tak</label>
                        <label><input type="radio" name="dzieci" value="Nie" <?php checked($dane_sesji['dzieci'] ?? '', 'Nie'); ?>>
                            Nie</label>
                    </div>

                    <label>Czy chcesz mieć (więcej) dzieci?:</label>
                    <div class="radio-group">
                        <label><input type="radio" name="chce_dzieci" value="Tak" <?php checked($dane_sesji['chce_dzieci'] ?? '', 'Tak'); ?>> Tak</label>
                        <label><input type="radio" name="chce_dzieci" value="Nie" <?php checked($dane_sesji['chce_dzieci'] ?? '', 'Nie'); ?>> Nie</label>
                    </div>
                    <?php break;

                case 3: ?>
                    <h2>Krok 3: Duchowość</h2>

                    <label>Ogólne Podejście do Wiary:</label>
                    <div class="radio-group">
                        <label><input type="radio" name="podejscie_wiary" value="Wierzący" <?php checked($dane_sesji['podejscie_wiary'] ?? '', 'Wierzący'); ?>> Wierzący</label>
                        <label><input type="radio" name="podejscie_wiary" value="Niewierzący" <?php checked($dane_sesji['podejscie_wiary'] ?? '', 'Niewierzący'); ?>> Niewierzący</label>
                    </div>

                    <label>Religia:</label>
                    <div class="radio-group">
                        <label><input type="radio" name="religia" value="Chrześcijańska" <?php checked($dane_sesji['religia'] ?? '', 'Chrześcijańska'); ?>> Chrześcijańska</label>
                        <label><input type="radio" name="religia" value="Buddyzm" <?php checked($dane_sesji['religia'] ?? '', 'Buddyzm'); ?>> Buddyzm</label>
                        <label><input type="radio" name="religia" value="Islam" <?php checked($dane_sesji['religia'] ?? '', 'Islam'); ?>> Islam</label>
                        <label><input type="radio" name="religia" value="Hinduizm" <?php checked($dane_sesji['religia'] ?? '', 'Hinduizm'); ?>> Hinduizm</label>
                        <label><input type="radio" name="religia" value="Judaizm" <?php checked($dane_sesji['religia'] ?? '', 'Judaizm'); ?>> Judaizm</label>
                        <label><input type="radio" name="religia" value="Ateizm" <?php checked($dane_sesji['religia'] ?? '', 'Ateizm'); ?>> Ateizm</label>
                        <label><input type="radio" name="religia" value="Agnostycyzm" <?php checked($dane_sesji['religia'] ?? '', 'Agnostycyzm'); ?>> Agnostycyzm</label>
                        <label><input type="radio" name="religia" value="New Age" <?php checked($dane_sesji['religia'] ?? '', 'New Age'); ?>> New Age</label>
                        <label><input type="radio" name="religia" value="Szamanizm" <?php checked($dane_sesji['religia'] ?? '', 'Szamanizm'); ?>> Szamanizm</label>
                        <label><input type="radio" name="religia" value="Własne Przekonania Religijne" <?php checked($dane_sesji['religia'] ?? '', 'Własne Przekonania Religijne'); ?>> Własne Przekonania
                            Religijne</label>
                    </div>

                    <label>Podejście do Reinkarnacji / Życia po Śmierci:</label>
                    <div class="radio-group">
                        <label><input type="radio" name="reinkarnacja" value="Wierzę" <?php checked($dane_sesji['reinkarnacja'] ?? '', 'Wierzę'); ?>> Wierzę</label>
                        <label><input type="radio" name="reinkarnacja" value="Nie wierzę" <?php checked($dane_sesji['reinkarnacja'] ?? '', 'Nie wierzę'); ?>> Nie wierzę</label>
                    </div>

                    <label>Zainteresowanie Duchowością Alternatywną:</label>
                    <div class="radio-group">
                        <label><input type="radio" name="duchowosc_alternatywna" value="Bardzo zainteresowany" <?php checked($dane_sesji['duchowosc_alternatywna'] ?? '', 'Bardzo zainteresowany'); ?>> Bardzo
                            zainteresowany</label>
                        <label><input type="radio" name="duchowosc_alternatywna" value="Średnio zainteresowany" <?php checked($dane_sesji['duchowosc_alternatywna'] ?? '', 'Średnio zainteresowany'); ?>> Średnio
                            zainteresowany</label>
                        <label><input type="radio" name="duchowosc_alternatywna" value="Nie interesuje mnie to" <?php checked($dane_sesji['duchowosc_alternatywna'] ?? '', 'Nie interesuje mnie to'); ?>> Nie interesuje
                            mnie to</label>
                    </div>

                    <label>Twój znak Zodiaku:</label>
                    <select name="zodiak">
                        <option value="">-- Wybierz --</option>
                        <option value="Baran" <?php selected($dane_sesji['zodiak'] ?? '', 'Baran'); ?>>Baran</option>
                        <option value="Byk" <?php selected($dane_sesji['zodiak'] ?? '', 'Byk'); ?>>Byk</option>
                        <option value="Bliźnięta" <?php selected($dane_sesji['zodiak'] ?? '', 'Bliźnięta'); ?>>Bliźnięta</option>
                        <option value="Rak" <?php selected($dane_sesji['zodiak'] ?? '', 'Rak'); ?>>Rak</option>
                        <option value="Lew" <?php selected($dane_sesji['zodiak'] ?? '', 'Lew'); ?>>Lew</option>
                        <option value="Panna" <?php selected($dane_sesji['zodiak'] ?? '', 'Panna'); ?>>Panna</option>
                        <option value="Waga" <?php selected($dane_sesji['zodiak'] ?? '', 'Waga'); ?>>Waga</option>
                        <option value="Skorpion" <?php selected($dane_sesji['zodiak'] ?? '', 'Skorpion'); ?>>Skorpion</option>
                        <option value="Strzelec" <?php selected($dane_sesji['zodiak'] ?? '', 'Strzelec'); ?>>Strzelec</option>
                        <option value="Koziorożec" <?php selected($dane_sesji['zodiak'] ?? '', 'Koziorożec'); ?>>Koziorożec</option>
                        <option value="Wodnik" <?php selected($dane_sesji['zodiak'] ?? '', 'Wodnik'); ?>>Wodnik</option>
                        <option value="Ryby" <?php selected($dane_sesji['zodiak'] ?? '', 'Ryby'); ?>>Ryby</option>
                    </select>
                    <?php break;

                case 4: ?>
                    <h2>Krok 4: Styl Życia i Praca</h2>

                    <label>Preferencje Jedzeniowe:</label>
                    <div class="radio-group">
                        <label><input type="radio" name="preferencje_jedzeniowe" value="Wegetariańska" <?php checked($dane_sesji['preferencje_jedzeniowe'] ?? '', 'Wegetariańska'); ?>> Wegetariańska</label>
                        <label><input type="radio" name="preferencje_jedzeniowe" value="Mięsożerna" <?php checked($dane_sesji['preferencje_jedzeniowe'] ?? '', 'Mięsożerna'); ?>> Mięsożerna</label>
                        <label><input type="radio" name="preferencje_jedzeniowe" value="Vegan" <?php checked($dane_sesji['preferencje_jedzeniowe'] ?? '', 'Vegan'); ?>> Vegan</label>
                        <label><input type="radio" name="preferencje_jedzeniowe" value="Raw Food" <?php checked($dane_sesji['preferencje_jedzeniowe'] ?? '', 'Raw Food'); ?>> Raw Food</label>
                        <label><input type="radio" name="preferencje_jedzeniowe" value="Roślinna" <?php checked($dane_sesji['preferencje_jedzeniowe'] ?? '', 'Roślinna'); ?>> Roślinna</label>
                        <label><input type="radio" name="preferencje_jedzeniowe" value="Keto" <?php checked($dane_sesji['preferencje_jedzeniowe'] ?? '', 'Keto'); ?>> Keto</label>
                        <label><input type="radio" name="preferencje_jedzeniowe" value="Jem wszystko" <?php checked($dane_sesji['preferencje_jedzeniowe'] ?? '', 'Jem wszystko'); ?>> Jem wszystko</label>
                        <label><input type="radio" name="preferencje_jedzeniowe" value="Frutarianin" <?php checked($dane_sesji['preferencje_jedzeniowe'] ?? '', 'Frutarianin'); ?>> Frutarianin</label>
                        <label><input type="radio" name="preferencje_jedzeniowe" value="Breatharianin" <?php checked($dane_sesji['preferencje_jedzeniowe'] ?? '', 'Breatharianin'); ?>> Breatharianin</label>
                    </div>

                    <label>420 Friendly:</label>
                    <div class="radio-group">
                        <label><input type="radio" name="friendly_420" value="420 Friend" <?php checked($dane_sesji['friendly_420'] ?? '', '420 Friend'); ?>> 420 Friend</label>
                        <label><input type="radio" name="friendly_420" value="Okazjonalnie" <?php checked($dane_sesji['friendly_420'] ?? '', 'Okazjonalnie'); ?>> Okazjonalnie</label>
                        <label><input type="radio" name="friendly_420" value="Nie toleruję" <?php checked($dane_sesji['friendly_420'] ?? '', 'Nie toleruję'); ?>> Nie toleruję</label>
                    </div>

                    <label>Typ ciała:</label>
                    <div class="radio-group">
                        <label><input type="radio" name="typ_ciala" value="Szczupły" <?php checked($dane_sesji['typ_ciala'] ?? '', 'Szczupły'); ?>> Szczupły</label>
                        <label><input type="radio" name="typ_ciala" value="Średni" <?php checked($dane_sesji['typ_ciala'] ?? '', 'Średni'); ?>> Średni</label>
                        <label><input type="radio" name="typ_ciala" value="Kilka dodatkowych kg" <?php checked($dane_sesji['typ_ciala'] ?? '', 'Kilka dodatkowych kg'); ?>> Kilka dodatkowych kg</label>
                        <label><input type="radio" name="typ_ciala" value="Muskularny" <?php checked($dane_sesji['typ_ciala'] ?? '', 'Muskularny'); ?>> Muskularny</label>
                    </div>

                    <label>Jak często ćwiczysz:</label>
                    <div class="radio-group">
                        <label><input type="radio" name="cwiczenia" value="Codziennie" <?php checked($dane_sesji['cwiczenia'] ?? '', 'Codziennie'); ?>> Codziennie</label>
                        <label><input type="radio" name="cwiczenia" value="3-4 razy w tygodniu" <?php checked($dane_sesji['cwiczenia'] ?? '', '3-4 razy w tygodniu'); ?>> 3-4 razy w tygodniu</label>
                        <label><input type="radio" name="cwiczenia" value="Sporadycznie" <?php checked($dane_sesji['cwiczenia'] ?? '', 'Sporadycznie'); ?>> Sporadycznie</label>
                        <label><input type="radio" name="cwiczenia" value="W ogóle" <?php checked($dane_sesji['cwiczenia'] ?? '', 'W ogóle'); ?>> W ogóle</label>
                    </div>

                    <label>Dieta:</label>
                    <div class="radio-group">
                        <label><input type="radio" name="dieta" value="Vege" <?php checked($dane_sesji['dieta'] ?? '', 'Vege'); ?>>
                            Vege</label>
                        <label><input type="radio" name="dieta" value="Mięso" <?php checked($dane_sesji['dieta'] ?? '', 'Mięso'); ?>> Mięso</label>
                        <label><input type="radio" name="dieta" value="Inna" <?php checked($dane_sesji['dieta'] ?? '', 'Inna'); ?>>
                            Inna</label>
                    </div>

                    <label>Podejście do Alkoholu:</label>
                    <div class="radio-group">
                        <label><input type="radio" name="alkohol" value="Często" <?php checked($dane_sesji['alkohol'] ?? '', 'Często'); ?>> Często</label>
                        <label><input type="radio" name="alkohol" value="Okazjonalnie" <?php checked($dane_sesji['alkohol'] ?? '', 'Okazjonalnie'); ?>> Okazjonalnie</label>
                        <label><input type="radio" name="alkohol" value="Nie piję" <?php checked($dane_sesji['alkohol'] ?? '', 'Nie piję'); ?>> Nie piję</label>
                    </div>

                    <label>Czy lubisz czytać książki:</label>
                    <div class="radio-group">
                        <label><input type="radio" name="czytac" value="Tak dużo czytam" <?php checked($dane_sesji['czytac'] ?? '', 'Tak dużo czytam'); ?>> Tak dużo czytam</label>
                        <label><input type="radio" name="czytac" value="Średnio" <?php checked($dane_sesji['czytac'] ?? '', 'Średnio'); ?>> Średnio</label>
                        <label><input type="radio" name="czytac" value="Mało czytam" <?php checked($dane_sesji['czytac'] ?? '', 'Mało czytam'); ?>> Mało czytam</label>
                        <label><input type="radio" name="czytac" value="Nie czytam" <?php checked($dane_sesji['czytac'] ?? '', 'Nie czytam'); ?>> Nie czytam</label>
                    </div>

                    <label>W skrócie Polityka:</label>
                    <div class="radio-group">
                        <label><input type="radio" name="polityka_skrot" value="Konserwatyzm" <?php checked($dane_sesji['polityka_skrot'] ?? '', 'Konserwatyzm'); ?>> Konserwatyzm</label>
                        <label><input type="radio" name="polityka_skrot" value="Liberalizm" <?php checked($dane_sesji['polityka_skrot'] ?? '', 'Liberalizm'); ?>> Liberalizm</label>
                        <label><input type="radio" name="polityka_skrot" value="Nie interesuje mnie" <?php checked($dane_sesji['polityka_skrot'] ?? '', 'Nie interesuje mnie'); ?>> Nie interesuje mnie</label>
                    </div>

                    <label>Identyfikacja Polityczna:</label>
                    <div class="radio-group">
                        <label><input type="radio" name="identyfikacja_polityczna" value="Liberalizm" <?php checked($dane_sesji['identyfikacja_polityczna'] ?? '', 'Liberalizm'); ?>> Liberalizm</label>
                        <label><input type="radio" name="identyfikacja_polityczna" value="Konserwatyzm" <?php checked($dane_sesji['identyfikacja_polityczna'] ?? '', 'Konserwatyzm'); ?>> Konserwatyzm</label>
                        <label><input type="radio" name="identyfikacja_polityczna" value="Anarchizm" <?php checked($dane_sesji['identyfikacja_polityczna'] ?? '', 'Anarchizm'); ?>> Anarchizm</label>
                        <label><input type="radio" name="identyfikacja_polityczna" value="Libertarianizm" <?php checked($dane_sesji['identyfikacja_polityczna'] ?? '', 'Libertarianizm'); ?>> Libertarianizm</label>
                        <label><input type="radio" name="identyfikacja_polityczna" value="Socjalizm" <?php checked($dane_sesji['identyfikacja_polityczna'] ?? '', 'Socjalizm'); ?>> Socjalizm</label>
                        <label><input type="radio" name="identyfikacja_polityczna" value="Prawica" <?php checked($dane_sesji['identyfikacja_polityczna'] ?? '', 'Prawica'); ?>> Prawica</label>
                        <label><input type="radio" name="identyfikacja_polityczna" value="Lewica" <?php checked($dane_sesji['identyfikacja_polityczna'] ?? '', 'Lewica'); ?>> Lewica</label>
                    </div>

                    <label>Styl Pracy:</label>
                    <div class="radio-group">
                        <label><input type="radio" name="styl_pracy" value="Stabilna" <?php checked($dane_sesji['styl_pracy'] ?? '', 'Stabilna'); ?>> Stabilna</label>
                        <label><input type="radio" name="styl_pracy" value="Przedsiębiorca" <?php checked($dane_sesji['styl_pracy'] ?? '', 'Przedsiębiorca'); ?>> Przedsiębiorca</label>
                        <label><input type="radio" name="styl_pracy" value="Freelancerka" <?php checked($dane_sesji['styl_pracy'] ?? '', 'Freelancerka'); ?>> Freelancerka</label>
                        <label><input type="radio" name="styl_pracy" value="Korpo" <?php checked($dane_sesji['styl_pracy'] ?? '', 'Korpo'); ?>> Korpo</label>
                        <label><input type="radio" name="styl_pracy" value="Start-up" <?php checked($dane_sesji['styl_pracy'] ?? '', 'Start-up'); ?>> Start-up</label>
                        <label><input type="radio" name="styl_pracy" value="Artysta (praca kreatywna)" <?php checked($dane_sesji['styl_pracy'] ?? '', 'Artysta (praca kreatywna)'); ?>> Artysta (praca
                            kreatywna)</label>
                        <label><input type="radio" name="styl_pracy" value="Twórca internetowy" <?php checked($dane_sesji['styl_pracy'] ?? '', 'Twórca internetowy'); ?>> Twórca internetowy</label>
                        <label><input type="radio" name="styl_pracy" value="Właściciel" <?php checked($dane_sesji['styl_pracy'] ?? '', 'Właściciel'); ?>> Właściciel</label>
                        <label><input type="radio" name="styl_pracy" value="Naukowa" <?php checked($dane_sesji['styl_pracy'] ?? '', 'Naukowa'); ?>> Naukowa</label>
                    </div>

                    <label>Podejście do Luksusu:</label>
                    <div class="radio-group">
                        <label><input type="radio" name="luksus" value="Pozytywne" <?php checked($dane_sesji['luksus'] ?? '', 'Pozytywne'); ?>> Pozytywne</label>
                        <label><input type="radio" name="luksus" value="Pośredku" <?php checked($dane_sesji['luksus'] ?? '', 'Pośredku'); ?>> Pośredku</label>
                        <label><input type="radio" name="luksus" value="Negatywne" <?php checked($dane_sesji['luksus'] ?? '', 'Negatywne'); ?>> Negatywne</label>
                    </div>

                    <label>Podejście do Ryzyka:</label>
                    <div class="radio-group">
                        <label><input type="radio" name="ryzyko" value="Ostrożne" <?php checked($dane_sesji['ryzyko'] ?? '', 'Ostrożne'); ?>> Ostrożne</label>
                        <label><input type="radio" name="ryzyko" value="Umiarkowane" <?php checked($dane_sesji['ryzyko'] ?? '', 'Umiarkowane'); ?>> Umiarkowane</label>
                        <label><input type="radio" name="ryzyko" value="Ryzykowne" <?php checked($dane_sesji['ryzyko'] ?? '', 'Ryzykowne'); ?>> Ryzykowne</label>
                        <label><input type="radio" name="ryzyko" value="Heroiczne" <?php checked($dane_sesji['ryzyko'] ?? '', 'Heroiczne'); ?>> Heroiczne</label>
                    </div>

                    <label>Jakie znasz języki? (zaznacz wszystkie):</label>
                    <div class="checkbox-group">
                        <?php
                        $jezyki_wybrane = isset($dane_sesji['jezyki']) ? explode(', ', $dane_sesji['jezyki']) : [];
                        ?>
                        <label><input type="checkbox" name="jezyki[]" value="polski" <?php checked(in_array('polski', $jezyki_wybrane)); ?>> polski</label>
                        <label><input type="checkbox" name="jezyki[]" value="angielski" <?php checked(in_array('angielski', $jezyki_wybrane)); ?>> angielski</label>
                        <label><input type="checkbox" name="jezyki[]" value="niemiecki" <?php checked(in_array('niemiecki', $jezyki_wybrane)); ?>> niemiecki</label>
                        <label><input type="checkbox" name="jezyki[]" value="hiszpański" <?php checked(in_array('hiszpański', $jezyki_wybrane)); ?>> hiszpański</label>
                        <label><input type="checkbox" name="jezyki[]" value="francuski" <?php checked(in_array('francuski', $jezyki_wybrane)); ?>> francuski</label>
                        <label><input type="checkbox" name="jezyki[]" value="chiński" <?php checked(in_array('chiński', $jezyki_wybrane)); ?>> chiński</label>
                        <label><input type="checkbox" name="jezyki[]" value="rosyjski" <?php checked(in_array('rosyjski', $jezyki_wybrane)); ?>> rosyjski</label>
                        <label><input type="checkbox" name="jezyki[]" value="inne" <?php checked(in_array('inne', $jezyki_wybrane)); ?>> inne</label>
                    </div>

                    <label>Mieszkanie:</label>
                    <div class="radio-group">
                        <label><input type="radio" name="mieszkanie" value="Mieszkam Sam/ą" <?php checked($dane_sesji['mieszkanie'] ?? '', 'Mieszkam Sam/ą'); ?>> Mieszkam Sam/ą</label>
                        <label><input type="radio" name="mieszkanie" value="Z Współlokatorem" <?php checked($dane_sesji['mieszkanie'] ?? '', 'Z Współlokatorem'); ?>> Z Współlokatorem</label>
                        <label><input type="radio" name="mieszkanie" value="Z Rodzicami" <?php checked($dane_sesji['mieszkanie'] ?? '', 'Z Rodzicami'); ?>> Z Rodzicami</label>
                        <label><input type="radio" name="mieszkanie" value="Podróżuję" <?php checked($dane_sesji['mieszkanie'] ?? '', 'Podróżuję'); ?>> Podróżuję</label>
                    </div>
                    <?php break;

                case 5: ?>
                    <h2>Krok 5: O Mnie i Zdjęcie</h2>
                    <p>To już ostatni krok! Opisz siebie i dodaj swoje zdjęcie profilowe.</p>

                    <label for="about_me">*Krótko o mnie (min. 150 znaków):</label>
                    <textarea name="about_me" id="about_me" rows="6" minlength="150"
                        required><?php echo esc_textarea($dane_sesji['about_me'] ?? ''); ?></textarea>

                    <label for="profile_photo">*Zdjęcie profilowe (wymagane):</label>
                    <input type="file" name="profile_photo" id="profile_photo" accept="image/*" required>
                    <?php break;
            }
            ?>

            <!-- Ukryte pole przekazujące numer następnego kroku -->
            <input type="hidden" name="next_krok" value="<?php echo ($krok + 1); ?>">

            <?php
            if ($krok < 5) {
                echo '<input type="submit" name="submit" value="Dalej">';
            } else {
                echo '<input type="submit" name="submit" value="Zakończ Rejestrację">';
            }
            ?>
        </form>
    </div>
    <?php
}
// Shortcode do wyświetlania formularza logowania
// Shortcode do wyświetlania formularza logowania z linkiem resetowania i stylami
function wyswietl_formularz_logowania_shortcode()
{
    if (is_user_logged_in()) {
        $current_user = wp_get_current_user();
        return '<div class="logged-in-msg" style="color: white; padding: 10px;">Cześć ' . esc_html($current_user->display_name) . '! Jesteś już zalogowany/a.</div>';
    }

    echo pm_get_app_store_button();

    // Argumenty formularza
    $args = array(
        'redirect' => home_url(),
        'remember' => true,
        'label_username' => 'Nazwa użytkownika lub e-mail',
        'label_password' => 'Hasło',
        'label_remember' => 'Zapamiętaj mnie',
        'label_log_in' => 'Zaloguj się',
        'id_submit' => 'wp-submit-custom', // Unikalne ID dla łatwiejszego stylowania
    );

    ob_start();
    ?>

    <!-- Kontener z jasnym tłem i stylami naprawiającymi widoczność -->
    <style>
        .custom-login-wrapper {
            background-color: #ffffff;
            /* Białe tło formularza */
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            max-width: 400px;
            margin: 0 auto;
            color: #333333;
            /* Ciemny tekst */
        }

        .custom-login-wrapper label {
            color: #333333 !important;
            /* Wymuszenie ciemnego koloru etykiet */
            font-weight: 600;
            margin-bottom: 5px;
            display: block;
        }

        .custom-login-wrapper input[type="text"],
        .custom-login-wrapper input[type="password"] {
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: #f9f9f9;
            color: #333;
        }

        .custom-login-wrapper .login-submit {
            margin-top: 10px;
        }

        /* Styl linku resetowania */
        .custom-lost-password {
            display: block;
            margin-top: 15px;
            text-align: center;
            font-size: 0.9em;
        }

        .custom-lost-password a {
            color: #d6336c;
            /* Kolor pasujący do PrawdziwaMiłość (róż/czerwień) */
            text-decoration: none;
        }

        .custom-lost-password a:hover {
            text-decoration: underline;
        }
    </style>

    <div class="custom-login-wrapper">
        <?php wp_login_form($args); ?>

        <div class="custom-lost-password">
            <a href="<?php echo esc_url(wp_lostpassword_url()); ?>">
                Nie pamiętam hasła
            </a>
        </div>
    </div>

    <?php
    return ob_get_clean();
}
add_shortcode('formularz_logowania', 'wyswietl_formularz_logowania_shortcode');


function my_custom_mime_types($mimes)
{
    // Dodajemy obsługę dla plików .glb
    $mimes['glb'] = 'model/gltf-binary';
    return $mimes;
}
add_filter('upload_mimes', 'my_custom_mime_types');

/**
 * Zapisz pola XProfile po aktywacji użytkownika
 */
function zapisz_xprofile_po_aktywacji($user_id, $key, $user)
{
    // Pobierz dane XProfile z signup meta
    $pending_xprofile_data = get_user_meta($user_id, 'pending_xprofile_data', true);

    if (!empty($pending_xprofile_data) && is_array($pending_xprofile_data)) {
        // Zapisz każde pole XProfile
        foreach ($pending_xprofile_data as $field_id => $field_value) {
            if (!empty($field_value)) {
                xprofile_set_field_data($field_id, $user_id, $field_value);
            }
        }

        // Usuń tymczasowe dane po zapisaniu
        delete_user_meta($user_id, 'pending_xprofile_data');
    }
}
add_action('bp_core_activated_user', 'zapisz_xprofile_po_aktywacji', 10, 3);

function my_custom_registration_shortcode()
{
    // Jeśli zalogowany, wyślij od razu do Etapu 2 (lub na główną, jeśli skończył)
    if (is_user_logged_in()) {
        wp_redirect(home_url('/onboarding')); // Ustaw tu URL Twojej strony z Etapu 2
        exit;
    }

    $output = '';
    $errors = [];

    // --- LOGIKA REJESTRACJI (Gdy kliknięto "Zarejestruj") ---
    if (isset($_POST['submit_registration']) && wp_verify_nonce($_POST['_wpnonce'], 'new_user_register')) {

        $email = sanitize_email($_POST['user_email']);
        $password = $_POST['user_pass'];
        $name = sanitize_text_field($_POST['user_name']);
        $gender = sanitize_text_field($_POST['gender']); // Np. 'Mezczyzna'
        $birthdate = sanitize_text_field($_POST['birthdate']); // YYYY-MM-DD

        // Prosta walidacja
        if (email_exists($email)) {
            $errors[] = "Ten email jest już zajęty.";
        }
        if (strlen($password) < 6) {
            $errors[] = "Hasło musi mieć min. 6 znaków.";
        }
        if (empty($name) || empty($birthdate)) {
            $errors[] = "Wypełnij wszystkie pola.";
        }
        
        // Walidacja zdjęcia
        if (empty($_FILES['profile_photo']['name']) || $_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Dodaj swoje zdjęcie profilowe.";
        } else {
            // Sprawdź typ pliku
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $file_type = wp_check_filetype($_FILES['profile_photo']['name']);
            if (!in_array($_FILES['profile_photo']['type'], $allowed_types)) {
                $errors[] = "Dozwolone są tylko pliki graficzne (JPG, PNG, GIF, WEBP).";
            }
            // Sprawdź rozmiar (max 5MB)
            if ($_FILES['profile_photo']['size'] > 5 * 1024 * 1024) {
                $errors[] = "Zdjęcie jest zbyt duże. Maksymalny rozmiar to 5MB.";
            }
        }

        if (empty($errors)) {

            // 1. Generujemy login BEZ ZNAKÓW SPECJALNYCH
            // Użycie emaila jako loginu w bp_core_signup_user często powoduje błędy.
            // Tworzymy unikalny login: Imię + losowe cyfry (np. Tomek4829)
            $generated_username = sanitize_user($name . rand(1000, 9999));

            // 2. Przygotowujemy meta dane - POPRAWKA: dodajemy dane do pending_xprofile_data
            $usermeta = array(
                'temp_password_for_activation' => $password,
                'pending_xprofile_data' => array(
                    '1' => $name,                         // Imię (field ID 1)
                    '129' => $gender,                       // Płeć (field ID 129)
                    '107' => date(
                        'Y-m-d 00:00:00',
                        strtotime($birthdate)             // UŻYJ $birth_date
                    ),
                ),
            );

            // 3. Rejestracja
            $userid = bp_core_signup_user(
                $generated_username,
                $password,
                $email,
                $usermeta
            );

            if (is_wp_error($userid)) {
                $errors[] = "Błąd rejestracji: " . $userid->get_error_message();
            } else {
                // SUKCES! Zapisz zdjęcie tymczasowo
                
                // Pobierz activation_key z bazy
                global $wpdb;
                $signup = $wpdb->get_row($wpdb->prepare(
                    "SELECT activation_key FROM {$wpdb->prefix}signups WHERE user_login = %s",
                    $generated_username
                ));
                
                if ($signup && $signup->activation_key && !empty($_FILES['profile_photo']['name'])) {
                    $upload_dir = wp_upload_dir();
                    $temp_dir = $upload_dir['basedir'] . '/temp-avatars/';
                    
                    if (!file_exists($temp_dir)) {
                        wp_mkdir_p($temp_dir);
                    }
                    
                    // Zapisz z nazwą = activation_key
                    $file_ext = pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
                    $temp_filename = $signup->activation_key . '.' . $file_ext;
                    $temp_path = $temp_dir . $temp_filename;
                    
                    if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $temp_path)) {
                        // Zaktualizuj meta signup o ścieżkę do avatara
                        $signup_row = $wpdb->get_row($wpdb->prepare(
                            "SELECT id, meta FROM {$wpdb->prefix}signups WHERE user_login = %s",
                            $generated_username
                        ));
                        
                        if ($signup_row) {
                            $meta = maybe_unserialize($signup_row->meta);
                            if (!is_array($meta)) {
                                $meta = [];
                            }
                            $meta['temp_avatar_path_for_activation'] = $temp_path;
                            
                            $wpdb->update(
                                $wpdb->prefix . 'signups',
                                ['meta' => maybe_serialize($meta)],
                                ['id' => $signup_row->id]
                            );
                        }
                    }
                }

                $output = '<div class="custom-reg-container success-mode">';
                $output .= '<h2 style="color:green;">Prawie gotowe! 🚀</h2>';
                $output .= '<p>Na Twój adres <strong>' . esc_html($email) . '</strong> wysłaliśmy link aktywacyjny.</p>';
                $output .= '<p>Kliknij w niego, aby dokończyć zakładanie konta.</p>';
                $output .= '<p style="color:#d63031; font-weight:600;">⚠️ Sprawdź również folder SPAM/Oferty!</p>';
                $output .= '<a href="' . wp_login_url() . '" class="btn-submit" style="text-align:center; display:block; text-decoration:none;">Przejdź do logowania</a>';

                $output .= '</div>';

                return $output;
            }
        }




    }

    // --- WIDOK HTML ---

    $output .= '<div class="custom-reg-container">';

    // Nagłówek
    $output .= '<h2>Zacznij swoją przygodę</h2>';
    $output .= '<p class="subtext">To zajmie tylko 30 sekund.</p>';

    // 1. SOCIAL LOGIN (Wtyczka Nextend Social Login)
    // Upewnij się, że masz zainstalowaną wtyczkę, inaczej usuń tę linię
    // $output .= '<div class="social-section">';
    // $output .= do_shortcode('[nextend_social_login provider="facebook" style="grid"]');
    // $output .= do_shortcode('[nextend_social_login provider="google" style="grid"]');
    //$output .= '</div>';

    // $output .= '<div class="separator"><span>lub przez email</span></div>';

    // Wyświetlanie błędów
    if (!empty($errors)) {
        $output .= '<div class="reg-errors">';
        foreach ($errors as $error)
            $output .= '<p>' . $error . '</p>';
        $output .= '</div>';
    }

    // 2. FORMULARZ EMAIL
    $output .= '<form method="post" class="reg-form" enctype="multipart/form-data">';

    // Imię
    $output .= '<div class="form-group">';
    $output .= '<label>Twoje Imię</label>';
    $output .= '<input type="text" name="user_name" required placeholder="Np. Tomek">';
    $output .= '</div>';

    // Płeć i Data Urodzenia (W jednej linii)
    $output .= '<div class="form-row">';
    $output .= '<div class="form-group half">';
    $output .= '<label>Płeć</label>';
    $output .= '<select name="gender" required>';
    $output .= '<option value="Mężczyzna">Mężczyzna</option>';
    $output .= '<option value="Kobieta">Kobieta</option>';
    $output .= '</select>';
    $output .= '</div>';

    $output .= '<div class="form-group half">';
    $output .= '<label>Data urodzenia</label>';
    $output .= '<input type="date" name="birthdate" required>';
    $output .= '</div>';
    $output .= '</div>';

    // Email
    $output .= '<div class="form-group">';
    $output .= '<label>Adres Email</label>';
    $output .= '<input type="email" name="user_email" required placeholder="twoj@email.com">';
    $output .= '</div>';

    // Hasło
    $output .= '<div class="form-group">';
    $output .= '<label>Hasło</label>';
    $output .= '<input type="password" name="user_pass" required placeholder="Min. 6 znaków">';
    $output .= '</div>';

    // Zdjęcie profilowe
    $output .= '<div class="form-group">';
    $output .= '<label>Twoje zdjęcie profilowe</label>';
    $output .= '<div id="avatar-preview-container" style="margin: 10px 0; display: none;">';
    $output .= '<img id="avatar-preview" src="" alt="Podgląd" style="max-width: 120px; max-height: 120px; border-radius: 12px; object-fit: cover; border: 3px solid #e91e63;">';
    $output .= '</div>';
    $output .= '<label for="profile_photo_input" style="display: inline-block; padding: 12px 24px; background: linear-gradient(135deg, #e91e63 0%, #9c27b0 100%); color: white; border-radius: 25px; cursor: pointer; font-weight: 500;">';
    $output .= '<span id="photo-btn-text">📷 Wybierz zdjęcie</span>';
    $output .= '</label>';
    $output .= '<input type="file" name="profile_photo" id="profile_photo_input" accept="image/*" required style="display: none;">';
    $output .= '<p id="photo-filename" style="margin-top: 8px; color: #666; font-size: 13px;"></p>';
    $output .= '</div>';
    
    $output .= '<script>';
    $output .= 'document.getElementById("profile_photo_input").addEventListener("change", function(e) {';
    $output .= '  var file = e.target.files[0];';
    $output .= '  if (file) {';
    $output .= '    var reader = new FileReader();';
    $output .= '    reader.onload = function(event) {';
    $output .= '      document.getElementById("avatar-preview").src = event.target.result;';
    $output .= '      document.getElementById("avatar-preview-container").style.display = "block";';
    $output .= '      document.getElementById("photo-btn-text").textContent = "📷 Zmień zdjęcie";';
    $output .= '      document.getElementById("photo-filename").textContent = "✓ " + file.name;';
    $output .= '      var tooltip = document.getElementById("photo-tooltip");';
    $output .= '      if (tooltip) tooltip.style.display = "none";';
    $output .= '    };';
    $output .= '    reader.readAsDataURL(file);';
    $output .= '  }';
    $output .= '});';
    $output .= '</script>';

    // Zgody (Opcjonalnie)
    $output .= '<div class="form-check">';
    $output .= '<input type="checkbox" required> <small>Akceptuję regulamin serwisu</small>';
    $output .= '</div>';

    // Przycisk z tooltipem
    $output .= wp_nonce_field('new_user_register', '_wpnonce', true, false);
    $output .= '<div style="position: relative; display: inline-block; width: 100%;">';
    $output .= '<div id="photo-tooltip" style="display: none; position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%); margin-bottom: 10px; padding: 10px 15px; background: #ff5252; color: white; border-radius: 8px; font-size: 14px; white-space: nowrap; box-shadow: 0 4px 15px rgba(255,82,82,0.4); animation: shake 0.5s ease-in-out;">';
    $output .= '📷 Musisz dodać zdjęcie profilowe';
    $output .= '<div style="position: absolute; top: 100%; left: 50%; transform: translateX(-50%); border: 8px solid transparent; border-top-color: #ff5252;"></div>';
    $output .= '</div>';
    $output .= '<button type="submit" name="submit_registration" class="btn-submit" onclick="return validatePhoto();">Załóż darmowe konto</button>';
    $output .= '</div>';
    
    $output .= '<script>';
    $output .= 'function validatePhoto() {';
    $output .= '  var photoInput = document.getElementById("profile_photo_input");';
    $output .= '  var tooltip = document.getElementById("photo-tooltip");';
    $output .= '  if (!photoInput.files || photoInput.files.length === 0) {';
    $output .= '    tooltip.style.display = "block";';
    $output .= '    setTimeout(function() { tooltip.style.display = "none"; }, 3000);';
    $output .= '    photoInput.scrollIntoView({ behavior: "smooth", block: "center" });';
    $output .= '    return false;';
    $output .= '  }';
    $output .= '  return true;';
    $output .= '}';
    $output .= '</script>';
    
    $output .= '<style>';
    $output .= '@keyframes shake { 0%, 100% { transform: translateX(-50%); } 25% { transform: translateX(-55%); } 75% { transform: translateX(-45%); } }';
    $output .= '</style>';

    $output .= '</form>';

    // Link do logowania
    $output .= '<p class="login-link">Masz już konto? <a href="' . wp_login_url() . '">Zaloguj się</a></p>';

    $output .= '</div>'; // koniec container

    return $output;
}
add_shortcode('moj_formularz_rejestracji', 'my_custom_registration_shortcode');


// ---------------------------------------------------------
// KONFIGURACJA (Uzupełnij swoje ID!)
// ---------------------------------------------------------
// ID Pól w BuddyPress (xProfile) - sprawdź w panelu admina!
define('FIELD_RELIGIA', 346);
define('FIELD_POLITYKA', 351);
define('FIELD_PRACA', 356);
define('FIELD_DIETA', 362);
// ---------------------------------------------------------

// Note: ONBOARDING_PAGE_ID is already defined at the top of this file (line 19)
// This check is kept for backward compatibility with older code sections
if ( ! defined( 'ONBOARDING_PAGE_ID' ) ) {
    define( 'ONBOARDING_PAGE_ID', 1339 );
}

/**
 * Helper function: Check if current page is a BuddyPress page
 * 
 * @return bool True if on any BuddyPress page (user, activity, groups, messages)
 */
function is_buddypress_page() {
    $bp_checks = ['bp_is_user', 'bp_is_activity', 'bp_is_groups', 'bp_is_messages'];
    
    foreach ($bp_checks as $check_function) {
        if (function_exists($check_function) && call_user_func($check_function)) {
            return true;
        }
    }
    
    return false;
}

function my_onboarding_gatekeeper()
{
    if (!is_user_logged_in() || current_user_can('manage_options')) {
        return;
    }

    // Jeśli jesteśmy JUŻ na stronie onboardingu - nie przekierowuj (unikamy pętli)
    $current_id = get_queried_object_id();
    if ($current_id === ONBOARDING_PAGE_ID) {
        return;
    }
    
    // Sprawdź też po URL path dla pewności
    if (isset($_SERVER['REQUEST_URI'])) {
        $path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
        $onboarding_page = get_post(ONBOARDING_PAGE_ID);
        $onboarding_slug = $onboarding_page ? $onboarding_page->post_name : 'onboarding';
        if ($path === 'onboarding' || $path === $onboarding_slug) {
            return;
        }
    }
    
    // Nie przekierowuj z profili BuddyPress (bp_is_user, bp_is_activity, etc.)
    if (is_buddypress_page()) {
        return;
    }

    $user_id = get_current_user_id();
    $is_completed = get_user_meta($user_id, 'app_onboarding_complete', true);

    // Jeśli nie ukończono onboardingu -> przekieruj
    if (!$is_completed) {
        $target = get_permalink(ONBOARDING_PAGE_ID);
        if ($target && $target !== home_url('/')) {
            wp_redirect($target);
            exit;
        }
    }
}
// Priorytet 20, aby upewnić się, że WP już wie, na jakiej stronie jesteśmy
add_action('template_redirect', 'my_onboarding_gatekeeper', 20);

/**
 * Redirect non-logged-in users to login when accessing BuddyPress pages
 * This handles the case when someone clicks a messages link from email while logged out
 */
function pm_buddypress_login_redirect() {
    // Only for non-logged-in users
    if (is_user_logged_in()) {
        return;
    }
    
    // Check if this is a BuddyPress members page
    if (!isset($_SERVER['REQUEST_URI'])) {
        return;
    }
    
    $path = $_SERVER['REQUEST_URI'];
    
    // Check if URL contains /members/ (BuddyPress member pages including messages)
    if (strpos($path, '/members/') !== false) {
        // Build the redirect URL with the current page as redirect_to parameter
        $current_url = home_url($path);
        $login_url = wp_login_url($current_url);
        
        wp_redirect($login_url);
        exit;
    }
}
// Priority 5 - run early, before BuddyPress returns 404
add_action('template_redirect', 'pm_buddypress_login_redirect', 5);

// POPRAWIONA FUNKCJA ONBOARDINGU
function my_safe_onboarding_form()
{
    // 1. Jeśli użytkownik nie jest zalogowany, nic nie pokazuj
    if (!is_user_logged_in())
        return '';

    $user_id = get_current_user_id();

    // --- KONFIGURACJA ID PÓL (DOCELOWA, NA STAŁO) ---
// Te ID wynikają z Twojej aktualnej konfiguracji BuddyPress
    $id_data_urodzenia = 107;       // Data urodzenia (używasz jej już w gridzie)[file:23]
    $id_kogo_szukam = 338;       // Pole „Kogo szukam” (też już używane)[file:23]

    $id_religia = FIELD_RELIGIA;   // 346
    $id_polityka = FIELD_POLITYKA;  // 351
    $id_praca = FIELD_PRACA;     // 356
    $id_dieta = FIELD_DIETA;     // 362


    // --- LOGIKA ZAPISU DANYCH (PHP) ---
    if (isset($_POST['submit_onboarding']) && wp_verify_nonce($_POST['_wpnonce'], 'save_onboarding')) {

        // Zapis Data Urodzenia - Wymaga specjalnego formatowania dla BuddyPress
        if (!empty($_POST['dataurodzenia'])) {
            $datainput = sanitize_text_field($_POST['dataurodzenia']); // Format YYYY-MM-DD z HTML input
            $datasql = date('Y-m-d 00:00:00', strtotime($datainput)); // Konwersja do formatu BP
            xprofile_set_field_data($id_data_urodzenia, $user_id, $datasql);
        }


        // 2. Zapis Kogo Szukam
        if (!empty($_POST['kogo_szukam'])) {
            xprofile_set_field_data($id_kogo_szukam, $user_id, sanitize_text_field($_POST['kogo_szukam']));
        }

        // 3. Zapis pozostałych pól tekstowych
        $fields = [
            'religia' => $id_religia,
            'polityka' => $id_polityka,
            'praca' => $id_praca,
            'dieta' => $id_dieta
        ];

        foreach ($fields as $name => $id) {
            if (!empty($_POST[$name])) {
                xprofile_set_field_data($id, $user_id, sanitize_text_field($_POST[$name]));
            }
        }

        // 4. Zapis zdjęć z siatki (photo_1 do photo_6)
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        $profile_photos_ids = [];

        for ($i = 1; $i <= 6; $i++) {
            $field_name = "photo_$i";
            if (!empty($_FILES[$field_name]['name'])) {
                // Używamy media_handle_upload, aby automatycznie dodać plik do Mediów WP
                $attach_id = media_handle_upload($field_name, 0);

                if (!is_wp_error($attach_id)) {
                    // Powiąż załącznik z autorem
                    wp_update_post([
                        'ID' => $attach_id,
                        'post_author' => $user_id
                    ]);

                    $profile_photos_ids[] = $attach_id;

                    // Jeśli to pierwsze zdjęcie, ustaw jako główny avatar (user_avatar_id)
                    if ($i === 1) {
                        update_user_meta($user_id, 'user_avatar_id', $attach_id);
                    }
                    
                    // === INTEGRACJA Z RTMEDIA ===
                    // Dodaj zdjęcie do galerii rtMedia użytkownika
                    if (class_exists('RTMediaModel')) {
                        global $wpdb;
                        $rtmedia_model = new RTMediaModel();
                        
                        // Pobierz dane załącznika
                        $attachment = get_post($attach_id);
                        $file_url = wp_get_attachment_url($attach_id);
                        $file_path = get_attached_file($attach_id);
                        $file_type = wp_check_filetype($file_path);
                        
                        // Przygotuj dane do wstawienia do rtMedia
                        $rtmedia_data = array(
                            'blog_id'        => get_current_blog_id(),
                            'media_id'       => $attach_id,
                            'media_author'   => $user_id,
                            'media_title'    => $attachment->post_title,
                            'album_id'       => 0, // Główna galeria profilu
                            'context'        => 'profile',
                            'context_id'     => $user_id,
                            'activity_id'    => 0,
                            'privacy'        => 0, // Publiczne
                            'media_type'     => 'photo',
                            'upload_date'    => current_time('mysql'),
                        );
                        
                        // Wstaw do tabeli rtMedia
                        $rtmedia_model->insert($rtmedia_data);
                    }
                }
            }
        }

        // Zapisz listę wszystkich dodatkowych zdjęć profilowych
        if (!empty($profile_photos_ids)) {
            $existing_ids = get_user_meta($user_id, 'user_profile_photos_ids', true);
            if (!is_array($existing_ids)) $existing_ids = [];
            
            // If photo_1 (avatar) was uploaded, remove OLD avatar from gallery to avoid duplication
            $old_avatar_id = get_user_meta($user_id, 'user_avatar_id', true);
            if ($old_avatar_id && in_array($old_avatar_id, $existing_ids)) {
                $existing_ids = array_filter($existing_ids, function($id) use ($old_avatar_id) {
                    return (int)$id !== (int)$old_avatar_id;
                });
            }

            $all_ids = array_unique(array_merge($existing_ids, $profile_photos_ids));
            update_user_meta($user_id, 'user_profile_photos_ids', array_values($all_ids));
        }

        // 5. Finalizacja i przekierowanie
        update_user_meta($user_id, 'app_onboarding_complete', true);
        $redirect = function_exists('bp_core_get_user_domain') ? bp_core_get_user_domain($user_id) : home_url();
        echo "<script>window.location.href='$redirect';</script>";
        return '<p style="color:green">Zapisano! Przekierowywanie...</p>';
    }

    // --- WIDOK FORMULARZA (HTML) ---
    ob_start();
    ?>

    <div class="onboarding-container" style="max-width:500px; margin:20px auto; padding:20px; background:#fff;">
        <h2>Uzupełnij profil</h2>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('save_onboarding'); ?>

            <!-- 2. KOGO SZUKAM -->
            <div style="margin-bottom:15px;">
                <label><strong>Kogo szukasz?</strong></label>
                <select name="kogo_szukam" style="width:100%; padding:8px;">
                    <option value="">-- Wybierz --</option>
                    <!-- WAŻNE: Wartości "value" muszą być IDENTYCZNE jak opcje w BuddyPress -->
                    <option value="Kobiety">Kobiety</option>
                    <option value="Mężczyzny">Mężczyzny</option>
                    <option value="Wszystkich">Wszystkich</option>
                </select>
            </div>

            <!-- 3. SIATKA ZDJĘĆ - STYL DATING APP -->
            <div style="margin-bottom:20px;">
                <label><strong>Twoje zdjęcia</strong></label>
                <p style="color: #666; font-size: 13px; margin-bottom: 15px;">Pierwsze zdjęcie to Twój główny avatar. Dodaj więcej, by zwiększyć swoje szanse!</p>
                
                <?php
                // Pobierz aktualny avatar użytkownika
                $current_avatar_url = '';
                
                // Sprawdź czy użytkownik ma już ustawiony avatar (custom)
                $user_avatar_id = get_user_meta($user_id, 'user_avatar_id', true);
                if ($user_avatar_id) {
                    $current_avatar_url = wp_get_attachment_image_url($user_avatar_id, 'medium');
                }
                
                // Jeśli nie ma custom avatara, sprawdź BuddyPress avatar
                if (!$current_avatar_url && function_exists('bp_core_fetch_avatar')) {
                    $bp_avatar = bp_core_fetch_avatar(array(
                        'item_id' => $user_id,
                        'type' => 'full',
                        'html' => false
                    ));
                    if ($bp_avatar && strpos($bp_avatar, 'mystery-man') === false && strpos($bp_avatar, 'gravatar') === false) {
                        $current_avatar_url = $bp_avatar;
                    }
                }
                ?>
                
                <div class="photo-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; max-width: 350px;">
                    <?php for ($i = 1; $i <= 6; $i++): ?>
                    <div class="photo-tile" 
                         id="tile-<?php echo $i; ?>"
                         onclick="triggerPhotoInput(<?php echo $i; ?>)"
                         style="
                            aspect-ratio: 1;
                            background: <?php echo ($i === 1 && $current_avatar_url) ? 'url('.esc_url($current_avatar_url).')' : 'linear-gradient(135deg, #f5f5f5 0%, #e0e0e0 100%)'; ?>;
                            background-size: cover;
                            background-position: center;
                            border-radius: 12px;
                            border: 2px dashed <?php echo ($i === 1 && $current_avatar_url) ? '#e91e63' : '#ccc'; ?>;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            cursor: pointer;
                            position: relative;
                            overflow: hidden;
                            transition: all 0.3s ease;
                         "
                         onmouseover="this.style.transform='scale(1.05)'; this.style.borderColor='#e91e63';"
                         onmouseout="this.style.transform='scale(1)'; this.style.borderColor='<?php echo ($i === 1 && $current_avatar_url) ? '#e91e63' : '#ccc'; ?>';">
                        
                        <?php if ($i === 1 && $current_avatar_url): ?>
                            <span class="tile-badge" style="
                                position: absolute;
                                top: 5px;
                                left: 5px;
                                background: #e91e63;
                                color: white;
                                font-size: 10px;
                                padding: 2px 6px;
                                border-radius: 10px;
                            ">Główne</span>
                        <?php endif; ?>
                        
                        <span class="plus-icon" id="plus-<?php echo $i; ?>" style="
                            font-size: 32px;
                            color: #999;
                            display: <?php echo ($i === 1 && $current_avatar_url) ? 'none' : 'block'; ?>;
                        ">+</span>
                        
                        <button type="button" class="remove-btn" id="remove-<?php echo $i; ?>" onclick="event.stopPropagation(); removePhoto(<?php echo $i; ?>);" style="
                            display: <?php echo ($i === 1 && $current_avatar_url) ? 'flex' : 'none'; ?>;
                            position: absolute;
                            top: 5px;
                            right: 5px;
                            width: 24px;
                            height: 24px;
                            background: rgba(0,0,0,0.6);
                            color: white;
                            border: none;
                            border-radius: 50%;
                            cursor: pointer;
                            align-items: center;
                            justify-content: center;
                            font-size: 14px;
                        ">×</button>
                        
                        <input type="file" 
                               name="photo_<?php echo $i; ?>" 
                               id="photo-input-<?php echo $i; ?>" 
                               accept="image/*" 
                               style="display: none;"
                               onchange="previewPhoto(this, <?php echo $i; ?>)">
                    </div>
                    <?php endfor; ?>
                </div>
                
                <!-- Ukryty input dla głównego avatara (dla kompatybilności) -->
                <input type="file" name="avatar" id="avatar-input" accept="image/*" style="display: none;">
            </div>
            
            <script>
            function triggerPhotoInput(index) {
                document.getElementById('photo-input-' + index).click();
            }
            
            function previewPhoto(input, index) {
                if (input.files && input.files[0]) {
                    var reader = new FileReader();
                    reader.onload = function(e) {
                        var tile = document.getElementById('tile-' + index);
                        tile.style.background = 'url(' + e.target.result + ')';
                        tile.style.backgroundSize = 'cover';
                        tile.style.backgroundPosition = 'center';
                        tile.style.borderStyle = 'solid';
                        tile.style.borderColor = '#e91e63';
                        
                        document.getElementById('plus-' + index).style.display = 'none';
                        document.getElementById('remove-' + index).style.display = 'flex';
                        
                        // Jeśli to pierwszy kafelek, skopiuj do głównego inputa avatara
                        if (index === 1) {
                            var avatarInput = document.getElementById('avatar-input');
                            var dataTransfer = new DataTransfer();
                            dataTransfer.items.add(input.files[0]);
                            avatarInput.files = dataTransfer.files;
                        }
                    };
                    reader.readAsDataURL(input.files[0]);
                }
            }
            
            function removePhoto(index) {
                var tile = document.getElementById('tile-' + index);
                tile.style.background = 'linear-gradient(135deg, #f5f5f5 0%, #e0e0e0 100%)';
                tile.style.borderStyle = 'dashed';
                tile.style.borderColor = '#ccc';
                
                document.getElementById('plus-' + index).style.display = 'block';
                document.getElementById('remove-' + index).style.display = 'none';
                document.getElementById('photo-input-' + index).value = '';
                
                // Jeśli to pierwszy kafelek, wyczyść też główny avatar input
                if (index === 1) {
                    document.getElementById('avatar-input').value = '';
                }
            }
            </script>

            <!-- 4. RELIGIA -->
            <div style="margin-bottom:15px;">
                <label><strong>Podejście do wiary</strong></label>
                <select name="religia" style="width:100%; padding:8px;">
                    <option value="">-- Wybierz --</option>
                    <option value="Wierzący">Wierzący</option>
                    <option value="Ateista">Ateista</option>
                    <option value="Duchowy">Duchowy, ale nie religijny</option>
                    <option value="Inne">Inne</option>
                </select>
            </div>

            <!-- 5. POLITYKA -->
            <div style="margin-bottom:15px;">
                <label><strong>Poglądy polityczne</strong></label>
                <select name="polityka" style="width:100%; padding:8px;">
                    <option value="">-- Wybierz --</option>
                    <option value="Konserwatywne">Konserwatywne</option>
                    <option value="Liberalne">Liberalne</option>
                    <option value="Centrowe">Centrowe</option>
                    <option value="Apolityczny">Apolityczny</option>
                </select>
            </div>

            <!-- 6. PRACA -->
            <div style="margin-bottom:15px;">
                <label><strong>Styl pracy</strong></label>
                <select name="praca" style="width:100%; padding:8px;">
                    <option value="">-- Wybierz --</option>
                    <option value="Korporacja">Korporacja</option>
                    <option value="Własny Biznes">Własny Biznes</option>
                    <option value="Normalna Praca">Normalna Praca</option>
                    <option value="Praca Kreatywna">Praca Kreatywna</option>
                    <option value="Nie pracuję">Nie pracuję</option>
                </select>
            </div>

            <!-- 7. DIETA -->
            <div style="margin-bottom:15px;">
                <label><strong>Styl jedzenia</strong></label>
                <select name="dieta" style="width:100%; padding:8px;">
                    <option value="">-- Wybierz --</option>
                    <option value="Wszystkożerca">Wszystkożerca</option>
                    <option value="Wegetarianin">Wegetarianin</option>
                    <option value="Weganin">Weganin</option>
                    <option value="Keto/Inne">Keto / Specjalistyczna</option>
                </select>
            </div>

            <button type="submit" name="submit_onboarding" class="button button-primary" style="width:100%; padding:12px;">
                Zapisz i wejdź
            </button>
        </form>
    </div>

    <?php
    return ob_get_clean();
}
add_shortcode('moj_onboarding_form', 'my_safe_onboarding_form');


/* --- PODMIANA AVATARA NA ZDJĘCIE Z BIBLIOTEKI --- */

// 1. Podmiana dla WordPressa (np. w pasku admina, komentarzach)
add_filter('get_avatar', 'my_force_wp_avatar', 10, 5);
function my_force_wp_avatar($avatar, $id_or_email, $size, $default, $alt)
{
    $user_id = 0;
    if (is_numeric($id_or_email)) {
        $user_id = (int) $id_or_email;
    } elseif (is_object($id_or_email) && !empty($id_or_email->user_id)) {
        $user_id = (int) $id_or_email->user_id;
    } elseif (is_string($id_or_email)) {
        $u = get_user_by('email', $id_or_email);
        if ($u)
            $user_id = $u->ID;
    }

    if ($user_id > 0) {
        $attach_id = get_user_meta($user_id, 'user_avatar_id', true);
        if ($attach_id) {
            $img_src = wp_get_attachment_image_src($attach_id, [$size, $size]); // Pobieramy miniaturkę
            if ($img_src) {
                return "<img alt='{$alt}' src='{$img_src[0]}' class='avatar avatar-{$size} photo' height='{$size}' width='{$size}' />";
            }
        }
    }
    return $avatar;
}

// 2. Podmiana dla BuddyPressa (URL avatara) - To jest najważniejsze dla profilu!
add_filter('bp_core_fetch_avatar', 'my_force_bp_avatar_html', 10, 2);
function my_force_bp_avatar_html($html, $params)
{
    // Sprawdźmy, czy mamy ID usera
    if (empty($params['item_id']))
        return $html;

    $user_id = $params['item_id'];

    // Pobierz ID zdjęcia z meta
    $attach_id = get_user_meta($user_id, 'user_avatar_id', true);

    if ($attach_id) {
        // Pobierz URL zdjęcia
        $img_src = wp_get_attachment_image_src($attach_id, 'thumbnail'); // Użyj 'full' jeśli wolisz HD
        if ($img_src) {
            // Zbuduj nowy tag IMG
            $width = isset($params['width']) ? $params['width'] : 150;
            $height = isset($params['height']) ? $params['height'] : 150;
            $class = isset($params['class']) ? $params['class'] : 'avatar';
            $alt = isset($params['alt']) ? $params['alt'] : 'Profile Picture';

            $img_html = "<img src='{$img_src[0]}' alt='{$alt}' class='{$class}' width='{$width}' height='{$height}' />";

            // --- PREMIUM BADGE INJECTION (HEADER/GLOBAL) ---
            // Check if user is premium
            $is_premium = sk_is_premium_user($user_id);

            if ($is_premium) {
                // Return wrapped avatar with badge
                // Note: We use inline styles for the wrapper to ensure it doesn't break layout too much,
                // but specific positioning might need tweaking depending on where this avatar is used.
                $badge_html = '<span class="avatar-premium-badge" style="position: absolute; top: -5px; right: -5px; z-index: 999; font-size: 14px;">⭐</span>';
                return '<span class="avatar-wrapper-premium" style="position: relative; display: inline-block;">' . $img_html . $badge_html . '</span>';
            }

            return $img_html;
        }
    }

    return $html;
}

// 3. Podmiana samego URL-a (dla motywów, które pobierają tylko link)
add_filter('bp_core_fetch_avatar_url', 'my_force_bp_avatar_url', 10, 2);

// 4. GLOBAL WP AVATAR FILTER (COVERS DASHBOARD/HEADER)
add_filter('get_avatar', 'my_force_wp_avatar_html', 10, 5);
function my_force_wp_avatar_html($avatar, $id_or_email, $size, $default, $alt) {
    // Resolve User ID
    $user_id = 0;
    if (is_numeric($id_or_email)) {
        $user_id = (int) $id_or_email;
    } elseif (is_string($id_or_email) && ($user = get_user_by('email', $id_or_email))) {
        $user_id = $user->ID;
    } elseif (is_object($id_or_email) && !empty($id_or_email->user_id)) {
        $user_id = (int) $id_or_email->user_id;
    } elseif ($id_or_email instanceof WP_User) {
        $user_id = $id_or_email->ID;
    } elseif ($id_or_email instanceof WP_Post) {
        $user_id = (int) $id_or_email->post_author;
    }

    if (!$user_id) return $avatar;

    // --- PREMIUM BADGE INJECTION ---
    $is_premium = sk_is_premium_user($user_id);

    if ($is_premium) {
        // Ensure we don't double-wrap if it's already wrapped (though usually safe)
        if (strpos($avatar, 'avatar-wrapper-premium') !== false) return $avatar;

        $badge_html = '<span class="avatar-premium-badge" style="position: absolute; top: -5px; right: -5px; z-index: 99999; font-size: 14px;">⭐</span>';
        return '<span class="avatar-wrapper-premium" style="position: relative; display: inline-block;">' . $avatar . $badge_html . '</span>';
    }

    return $avatar;
}
function my_force_bp_avatar_url($url, $params)
{
    if (empty($params['item_id']))
        return $url;

    $user_id = $params['item_id'];
    $attach_id = get_user_meta($user_id, 'user_avatar_id', true);

    if ($attach_id) {
        $img_src = wp_get_attachment_image_src($attach_id, 'thumbnail');
        if ($img_src)
            return $img_src[0];
    }
    return $url;
}

add_action('bp_core_activated_user', 'moj_fix_przenoszenia_danych_rejestracji', 10, 3);

add_action('bp_core_activated_user', 'moj_fix_przenoszenia_danych_rejestracji', 10, 3);

function moj_fix_przenoszenia_danych_rejestracji($user_id, $key, $user)
{
    // 1. Rzutowanie na tablicę (dla bezpieczeństwa)
    if (is_object($user)) {
        $user = (array) $user;
    }

    // 2. Pobieramy meta dane z rejestracji
    $meta = isset($user['meta']) ? $user['meta'] : [];

    // KLUCZOWA POPRAWKA: Dane są ukryte głębiej, w 'pending_xprofile_data'
    $pending = isset($meta['pending_xprofile_data']) ? $meta['pending_xprofile_data'] : [];

    // Jeśli nie ma danych pending, spróbujmy poszukać bezpośrednio (dla kompatybilności)
    if (empty($pending)) {
        $pending = $meta;
    }

    // Jeśli nadal pusto, kończymy
    if (empty($pending))
        return;

    // --- KONFIGURACJA ID PÓL (Sprawdź to w panelu!) ---
    $id_imie = 1;       // Zazwyczaj Name to ID 1
    $id_plec = 129;     // ID pola Płeć
    $id_data = 107;     // ID pola Data Urodzenia

    // 3. Zapisz Imię (Name) - to pole na zrzucie "Name (wymagane)"
    if (!empty($pending[$id_imie])) {
        xprofile_set_field_data($id_imie, $user_id, $pending[$id_imie]);
    }
    // Fallback: czasem imię jest zapisane pod kluczem 'field_1'
    elseif (!empty($pending['field_' . $id_imie])) {
        xprofile_set_field_data($id_imie, $user_id, $pending['field_' . $id_imie]);
    }

    // 4. Zapisz Płeć
    if (!empty($pending[$id_plec])) {
        // Upewnij się, że wartość "Mężczyzna" pasuje do opcji w BuddyPress
        xprofile_set_field_data($id_plec, $user_id, $pending[$id_plec]);
    }

    // 5. Zapisz Datę Urodzenia
    if (!empty($pending[$id_data])) {
        $data_val = $pending[$id_data];

        // Formatowanie dla BuddyPress (wymaga Y-m-d 00:00:00)
        // Jeśli przyszła sama data (np. 1990-01-01), dodajemy czas
        if (strlen($data_val) <= 10) {
            $data_val = date('Y-m-d 00:00:00', strtotime($data_val));
        }

        xprofile_set_field_data($id_data, $user_id, $data_val);
    }
}

function handle_custom_registration_form()
{
    // 1. Sprawdź nonce i wysłanie formularza
    if (isset($_POST['submit_registration']) && isset($_POST['wp_nonce']) && wp_verify_nonce($_POST['wp_nonce'], 'user-registration')) {

        $username = sanitize_user($_POST['username']);
        $email = sanitize_email($_POST['useremail']);
        $password = $_POST['userpass'];
        $gender = sanitize_text_field($_POST['gender']); // "Mężczyzna" lub "Kobieta"
        $birthdate = sanitize_text_field($_POST['birthdate']); // Format z inputa: YYYY-MM-DD

        // ... (walidacja: puste pola, email_exists itp.) ...
        if (empty($username) || empty($email) || empty($password) || empty($gender) || empty($birthdate)) {
            return;
        }
        if (username_exists($username) || email_exists($email)) {
            return;
        }

        // 2. Utwórz użytkownika
        $user_id = wp_create_user($username, $password, $email);

        if (!is_wp_error($user_id)) {
            // Zaloguj
            wp_set_current_user($user_id, $username);
            wp_set_auth_cookie($user_id);

            // 3. Zapisz dane do profilu BuddyPress
            if (function_exists('xprofile_set_field_data')) {

                // Zapisz Płeć (to działało, więc zostawiamy)
                xprofile_set_field_data('Płeć', $user_id, $gender);

                // POPRAWKA: Konwersja daty urodzenia
                // BuddyPress wymaga pełnego formatu datetime dla pól typu Datebox/Selector
                if (!empty($birthdate)) {
                    // $birthdate z inputa to np. "1990-05-21"
                    // Konwertujemy na "1990-05-21 00:00:00"
                    $formatted_date = date('Y-m-d H:i:s', strtotime($birthdate));

                    // Upewnij się, że nazwa pola to dokładnie "Data urodzenia" (wielkość liter!)
                    xprofile_set_field_data('Data urodzenia', $user_id, $formatted_date);
                }
            }

            // Przekierowanie
            $profile_url = function_exists('bp_core_get_user_domain') ? bp_core_get_user_domain($user_id) : home_url();
            wp_redirect($profile_url);
            exit;
        }
    }
}
add_action('init', 'handle_custom_registration_form');

// Pamiętaj o dodaniu tego pola w formularzu HTML:
// <?php wp_nonce_field( 'user-registration', 'wp_nonce' ); ? >

/**
 * Obsługa niestandardowej rejestracji (PrawdziwaMiłość)
 */
function sk_handle_custom_registration()
{
    // Sprawdzamy, czy przesłano nasz formularz
    if (isset($_POST['submit_registration'])) {

        // KONFIGURACJA: Wpisz tutaj ID swoich pól z BuddyPressa (Krok 1)
        $id_pola_plec = 129; // Zmień na ID pola "Płeć"
        $id_pola_daty = 107; // Zmień na ID pola "Data urodzenia"

        $username = sanitize_user($_POST['username']);
        $email = sanitize_email($_POST['useremail']);
        $password = $_POST['userpass'];
        $gender = sanitize_text_field($_POST['gender']); // "Mężczyzna" lub "Kobieta"
        $birthdate = sanitize_text_field($_POST['birthdate']); // Format YYYY-MM-DD

        // Walidacja
        if (empty($username) || empty($email) || empty($password)) {
            return;
        }

        // Sprawdź czy user już istnieje, aby nie dublować
        if (username_exists($username) || email_exists($email)) {
            return;
        }

        // 1. Utwórz użytkownika
        $user_id = wp_create_user($username, $password, $email);

        if (!is_wp_error($user_id)) {

            // Ustaw Display Name
            wp_update_user(['ID' => $user_id, 'display_name' => $username]);

            // 2. Zapisz dane do BuddyPress (xProfile)
            if (function_exists('xprofile_set_field_data')) {

                // A. Zapisz Płeć (używając ID jest bezpieczniej)
                // Upewnij się, że w formularzu <option> ma taką samą wartość jak w panelu BP
                xprofile_set_field_data($id_pola_plec, $user_id, $gender);

                // B. Zapisz Datę Urodzenia
                if (!empty($birthdate)) {
                    // Konwersja: 1990-05-21 -> 1990-05-21 00:00:00
                    $formatted_date = date('Y-m-d H:i:s', strtotime($birthdate));
                    xprofile_set_field_data($id_pola_daty, $user_id, $formatted_date);
                }
            }

            // 3. Automatyczne logowanie i przekierowanie
            wp_set_current_user($user_id, $username);
            wp_set_auth_cookie($user_id);

            // Przekieruj do edycji profilu, aby uzupełnił resztę
            wp_redirect(home_url('/czlonkowie/' . $username . '/profile/edit/'));
            exit;
        }
    }
}
// Używamy hooka 'bp_init', aby funkcje BuddyPressa były na pewno dostępne
add_action('bp_init', 'sk_handle_custom_registration');

// Funkcja pomocnicza do pobierania ID pola po nazwie (żeby nie wpisywać ID na sztywno)
function get_bp_field_id_by_name($name)
{
    global $wpdb;
    $table = $wpdb->prefix . 'bp_xprofile_fields';
    return $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE name = %s", $name));
}

// Rejestracja shortcode'u [krok_trzeci_formularz]
add_shortcode('krok_trzeci_formularz', 'render_step_three_form');

function render_step_three_form()
{
    if (!is_user_logged_in()) {
        return 'Zaloguj się, aby uzupełnić profil.';
    }

    $user_id = get_current_user_id();
    $message = '';

    // 1. OBSŁUGA ZAPISU DANYCH
    if (isset($_POST['submit_step_3']) && wp_verify_nonce($_POST['step_3_nonce'], 'save_step_3')) {

        // Mapa: Nazwa pola w HTML => Dokładna nazwa pola w BuddyPress (którą utworzyłeś w adminie)
        $fields_map = [
            'bio' => 'O mnie',
            'szukam' => 'Szukam',
            'dzieci' => 'Dzieci',
            'chce_dzieci' => 'Chcę dzieci',
            'wzrost' => 'Wzrost',
            'budowa' => 'Budowa ciała',
            'papierosy' => 'Papierosy',
            'alkohol' => 'Alkohol',
            'wyksztalcenie' => 'Wykształcenie',
            'personality' => 'Personality'
        ];

        foreach ($fields_map as $input_name => $bp_field_name) {
            if (isset($_POST[$input_name])) {
                $field_id = get_bp_field_id_by_name($bp_field_name);
                $value = sanitize_text_field($_POST[$input_name]);

                // textarea wymaga innej sanityzacji, aby zachować nowe linie
                if ($input_name === 'bio') {
                    $value = sanitize_textarea_field($_POST[$input_name]);
                }

                if ($field_id) {
                    xprofile_set_field_data($field_id, $user_id, $value);
                }
            }
        }

        $message = '<div class="pm-success-msg">Profil zaktualizowany! Przekierowanie...</div>';
        // Opcjonalnie: Przekierowanie na profil po 2 sekundach
        echo '<script>setTimeout(function(){window.location.href="/members/me/";}, 2000);</script>';
    }

    // 2. RENDERING FORMULARZA
    // Pobieramy aktualne wartości (jeśli user wraca edytować)
    // Używamy xprofile_get_field_data()

    ob_start();
    ?>

    <div class="krok-3-container">
        <?php echo $message; ?>

        <form method="post" class="pm-custom-form">
            <h2>Powiedz nam więcej o sobie</h2>
            <p class="subtitle">To pomoże nam znaleźć dla Ciebie idealną bratnią duszę.</p>

            <!-- SEKCJA 1: BIO -->
            <div class="form-group full-width">
                <label>O mnie (Twoja wizytówka)</label>
                <textarea name="bio" rows="5"
                    placeholder="Napisz kilka zdań o sobie, swoich pasjach i marzeniach..."><?php echo xprofile_get_field_data('O mnie', $user_id); ?></textarea>
            </div>

            <div class="form-grid">
                <!-- SEKCJA 2: RELACJA -->
                <div class="form-group">
                    <label>Czego szukasz?</label>
                    <select name="szukam">
                        <option value="">Wybierz...</option>
                        <option value="Poważny związek">Poważny związek</option>
                        <option value="Małżeństwo">Małżeństwo</option>
                        <option value="Przyjaźń">Przyjaźń</option>
                    </select>
                </div>

                <!-- SEKCJA 3: DZIECI -->
                <div class="form-group">
                    <label>Czy masz dzieci?</label>
                    <select name="dzieci">
                        <option value="Nie mam">Nie mam</option>
                        <option value="Mam">Mam</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Czy chcesz dzieci?</label>
                    <select name="chce_dzieci">
                        <option value="Tak">Tak</option>
                        <option value="Nie">Nie</option>
                    </select>
                </div>

                <!-- SEKCJA 4: WYGLĄD -->
                <div class="form-group">
                    <label>Wzrost (cm)</label>
                    <input type="number" name="wzrost" placeholder="175">
                </div>

                <div class="form-group">
                    <label>Budowa ciała</label>
                    <select name="budowa">
                        <option value="Normalna">Normalna</option>
                        <option value="Atletyczna">Atletyczna</option>
                        <option value="Szczupła">Szczupła</option>
                        <option value="Przy kości">Przy kości</option>
                    </select>
                </div>

                <!-- SEKCJA 5: STYL ŻYCIA -->
                <div class="form-group">
                    <label>Papierosy</label>
                    <select name="papierosy">
                        <option value="Nie palę">Nie palę</option>
                        <option value="Palę okazjonalnie">Palę okazjonalnie</option>
                        <option value="Palę nałogowo">Palę nałogowo</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Personality</label>
                    <div class="radio-group">
                        <label><input type="radio" name="personality" value="Introvert"> Introvert</label>
                        <label><input type="radio" name="personality" value="Extrovert"> Extrovert</label>
                    </div>
                </div>
            </div>

            <?php wp_nonce_field('save_step_3', 'step_3_nonce'); ?>

            <div class="form-actions">
                <input type="submit" name="submit_step_3" value="Zakończ i przejdź do profilu" class="pm-btn-primary">
            </div>
        </form>
    </div>

    <style>
        /* Prosty CSS na start - dopasuj do swojego stylu */
        .krok-3-container {
            max_width: 800px;
            margin: 0 auto;
            padding: 20px;
        }

        .pm-custom-form {
            background: #fff;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
        }

        .pm-btn-primary {
            background: #E1306C;
            color: #fff;
            padding: 15px 30px;
            border: none;
            border-radius: 50px;
            cursor: pointer;
            font-size: 18px;
            width: 100%;
            transition: 0.3s;
        }

        .pm-btn-primary:hover {
            background: #C13584;
        }

        .pm-success-msg {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }

        @media (max-width: 600px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <?php
    return ob_get_clean();
}

// ============================================
// METODA NUKLEARNA: JS DETEKTOR FORMULARZY
// ============================================
function inteligentna_naprawa_kolorow_js()
{
    ?>
    <script type="text/javascript">
        document.addEventListener("DOMContentLoaded", function () {

            // Funkcja naprawcza
            function naprawFormularze() {
                // Pobieramy wszystkie formularze na stronie
                const forms = document.querySelectorAll('form');

                forms.forEach(form => {
                    // Znajdź przycisk zatwierdzania w tym formularzu
                    const btn = form.querySelector('input[type="submit"], button[type="submit"]');

                    if (btn) {
                        // Pobieramy tekst z przycisku (wartość lub tekst wewnątrz)
                        const btnText = (btn.value || btn.innerText || "").toLowerCase();

                        // --- SCENARIUSZ 1: ODZYSKIWANIE HASŁA ---
                        // Sprawdzamy czy na przycisku jest słowo "hasło", "password" lub "reset"
                        if (btnText.includes('hasło') || btnText.includes('password') || btnText.includes('reset')) {

                            // 1. Zmieniamy kolor przycisku na RÓŻOWY
                            btn.style.setProperty('background-color', '#d6336c', 'important');
                            btn.style.setProperty('color', '#ffffff', 'important');
                            btn.style.setProperty('border', 'none', 'important');

                            // 2. Zmieniamy WSZYSTKIE teksty w tym formularzu na BIAŁE
                            const textElements = form.querySelectorAll('label, p, span, h2, h3, div');
                            textElements.forEach(el => {
                                // Ignorujemy same inputy i przyciski
                                if (el.tagName !== 'INPUT' && el.tagName !== 'BUTTON') {
                                    el.style.setProperty('color', '#ffffff', 'important');
                                }
                            });

                            // 3. Dodatkowe upewnienie się dla etykiet (Label)
                            const labels = form.querySelectorAll('label');
                            labels.forEach(label => {
                                label.style.setProperty('color', '#ffffff', 'important');
                                label.style.setProperty('display', 'block', 'important'); // Żeby nie znikały
                            });

                            // 4. Inputy mają być czytelne (Białe tło, czarny tekst)
                            const inputs = form.querySelectorAll('input[type="text"], input[type="email"]');
                            inputs.forEach(input => {
                                input.style.setProperty('background-color', '#ffffff', 'important');
                                input.style.setProperty('color', '#000000', 'important');
                                input.style.setProperty('border', '1px solid #ccc', 'important');
                            });
                        }

                        // --- SCENARIUSZ 2: LOGOWANIE ---
                        // Sprawdzamy czy na przycisku jest słowo "zaloguj", "login" lub "log in"
                        else if (btnText.includes('zaloguj') || btnText.includes('login') || btnText.includes('log in')) {

                            // Przycisk NIEBIESKI
                            btn.style.setProperty('background-color', '#0073aa', 'important'); // Klasyczny niebieski
                            btn.style.setProperty('color', '#ffffff', 'important');

                            // Teksty standardowe (ciemne) - jeśli tło jest jasne
                            // UWAGA: Jeśli tło logowania masz ciemne, zmień tutaj '#333' na '#fff'
                            const loginLabels = form.querySelectorAll('label');
                            loginLabels.forEach(l => {
                                l.style.setProperty('color', '#333333', 'important');
                            });
                        }
                    }
                });
            }

            // Uruchom od razu
            naprawFormularze();

            // Uruchom ponownie po 1 sekundzie (na wypadek gdyby Elementor coś ładował z opóźnieniem)
            setTimeout(naprawFormularze, 1000);
            setTimeout(naprawFormularze, 3000);
        });
    </script>

    <style>
        /* Dodatkowe zabezpieczenie CSS dla linków pod formularzem */
        .login-action-links a,
        #nav a,
        #backtoblog a {
            color: #ffffff !important;
            /* Białe linki */
            text-decoration: underline !important;
        }
    </style>
    <?php
}

// Podpinamy skrypt do stopki (działa na Frontendzie i Backendzie)
add_action('wp_footer', 'inteligentna_naprawa_kolorow_js', 100);
add_action('login_footer', 'inteligentna_naprawa_kolorow_js', 100);
/**
 * BLOKADA WYSYŁANIA WIADOMOŚCI BEZ MATCHA (DLA WEB I API)
 */
/**
 * Helper: Sprawdza czy dwoje użytkowników ma wzajemnego Matcha
 * Sprawdza zarówno meta (dating match) jak i status znajomych w BuddyPress.
 */
function sk_is_mutual_match($user_id_1, $user_id_2)
{
    if (!$user_id_1 || !$user_id_2)
        return false;

    // 1. Sprawdzenie w meta (Dating App Match)
    $likes_1 = get_user_meta($user_id_1, 'sk_user_likes', true) ?: [];
    $liked_by_1 = get_user_meta($user_id_1, 'sk_liked_by_users', true) ?: [];

    // Konwersja na inty dla pewności porównania
    $user_id_2 = (int) $user_id_2;
    $likes_1_ints = array_map('intval', (array) $likes_1);
    $liked_by_1_ints = array_map('intval', (array) $liked_by_1);

    $meta_match = in_array($user_id_2, $likes_1_ints) && in_array($user_id_2, $liked_by_1_ints);

    if ($meta_match)
        return true;

    // 2. Fallback: Sprawdzenie w BuddyPress (jeśli są już znajomymi w systemie)
    if (function_exists('friends_check_friendship')) {
        $status = friends_check_friendship($user_id_1, $user_id_2);
        if ($status === 'is_friend')
            return true;
    }

    return false;
}

function pm_strict_match_validation($errors, $recipients)
{
    try {
        // Safety check for recipients
        if (empty($recipients)) {
            return $errors;
        }

        // Get Sender ID safely
        $sender_id = function_exists('bp_loggedin_user_id') ? bp_loggedin_user_id() : get_current_user_id();

        // If no sender (not logged in), abort
        if (!$sender_id) {
            return $errors;
        }

        // BYPASS: Global Override
        if (defined('SK_BYPASS_MATCH_CHECK') && SK_BYPASS_MATCH_CHECK === true) {
            return $errors;
        }

        // BYPASS: Admin/Super Admin
        if (user_can($sender_id, 'manage_options')) {
            return $errors;
        }

        // BYPASS: Existing Thread Reply (Improve context detection)
        $is_reply = false;
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';

        // 1. Check $_REQUEST/$_POST for thread_id
        if (!empty($_REQUEST['thread_id']) || !empty($_POST['thread_id'])) {
            $is_reply = true;
        }
        // 2. Check REST Request (Better Messages specific URL pattern)
        elseif (strpos($request_uri, '/better-messages/v1/thread/') !== false && strpos($request_uri, '/send') !== false) {
            $is_reply = true;
        }
        // 3. Fallback: Check if JSON body has thread_id (for REST)
        elseif (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
            $json = file_get_contents('php://input');
            $data = json_decode($json, true);
            if (isset($data['thread_id']) || (strpos($request_uri, '/thread/') !== false && isset($data['content']))) {
                 // Heuristic: if it's a thread endpoint and has content, it's likely a reply
                 $is_reply = true;
            }
        }

        if ($is_reply) {
            return $errors;
        }

        // Safe Recipient Iteration
        $recipients_list = $recipients;
        if (is_object($recipients)) {
            // If it's a specific BP_Messages_Recipients object, it might not be iterable directly
            $recipients_list = (array) $recipients;
        }
        
        if (!is_array($recipients_list) && !is_iterable($recipients_list)) {
            // If we can't iterate, we can't validate, so default to allow (to avoid blocking valid messages due to code errors)
            error_log("pm_strict_match_validation: Recipients not iterable. Type: " . gettype($recipients));
            return $errors;
        }

        foreach ($recipients_list as $recipient) {
            // Safe recipient ID extraction
            $recipient_id = 0;
            if (is_numeric($recipient)) {
                $recipient_id = (int) $recipient;
            } elseif (is_object($recipient) && isset($recipient->user_id)) {
                $recipient_id = (int) $recipient->user_id;
            } elseif (is_array($recipient) && isset($recipient['user_id'])) {
                $recipient_id = (int) $recipient['user_id'];
            }

            if (!$recipient_id) continue;

            // Skip self
            if ($sender_id == $recipient_id) {
                continue;
            }

            // --- 1. MATCH CHECK ---
            // Wrap in try-catch to be extra safe
            try {
                if (function_exists('sk_is_mutual_match') && sk_is_mutual_match($sender_id, $recipient_id)) {
                    continue;
                }
            } catch (Throwable $t) {
                error_log("sk_is_mutual_match error: " . $t->getMessage());
                // On error, let it pass? Or fail? Better to fail open (allow) to avoid frustration if system is buggy
                continue;
            }

            // --- 2. ALLOW CHECK (Pozwól na rozmowę) ---
            $allowed_by_recipient = get_user_meta($recipient_id, 'sk_allowed_chat_ids', true) ?: [];
            if (is_array($allowed_by_recipient) && in_array($sender_id, $allowed_by_recipient)) {
                continue;
            }

            // Reverse check (if I allowed them, can I reply? Logic says yes if thread exists, but new thread? No.)
            // But since strict connection requires mutual match, we stick to specific allows.
            
            // BLOCK: No match, no permission
            $errors->add(
                'no_match_error',
                __('Nie możesz wysłać wiadomości. Użytkownik nie jest Twoim parą (brak Matcha).', 'buddypress')
            );
        }

        return $errors;

    } catch (Throwable $t) {
        // Critical safeguard: If ANYTHING fails in validation, log it and ALLOW the message.
        // It's better to allow a potential spam message than to block all communication due to a 500 error.
        error_log("CRITICAL ERROR in pm_strict_match_validation: " . $t->getMessage());
        return $errors; 
    }
}
add_filter('bp_messages_validate_send', 'pm_strict_match_validation', 10, 2);

/**
 * Better Messages validation for new thread creation.
 */
add_action('better_messages_before_new_thread', 'pm_bm_restrict_new_thread_creation', 10, 2);
function pm_bm_restrict_new_thread_creation(&$args, &$errors) {
    try {
        $sender_id = isset($args['sender_id']) ? intval($args['sender_id']) : get_current_user_id();
        if (!$sender_id) {
            return;
        }

        // Admin bypass
        if (user_can($sender_id, 'manage_options')) {
            return;
        }

        // Global override bypass
        if (defined('SK_BYPASS_MATCH_CHECK') && SK_BYPASS_MATCH_CHECK === true) {
            return;
        }

        $recipients = isset($args['recipients']) ? $args['recipients'] : [];
        if (empty($recipients)) {
            return;
        }

        foreach ($recipients as $recipient) {
            $recipient_id = intval(is_object($recipient) && isset($recipient->user_id) ? $recipient->user_id : $recipient);
            if (!$recipient_id || $recipient_id === $sender_id) {
                continue;
            }

            // 1. Sprawdź Match (Wzajemne polubienie)
            if (function_exists('sk_is_mutual_match') && sk_is_mutual_match($sender_id, $recipient_id)) {
                continue;
            }

            // 2. Sprawdź czy odbiorca wyraził zgodę (Allow)
            $allowed_by_recipient = get_user_meta($recipient_id, 'sk_allowed_chat_ids', true) ?: [];
            if (is_array($allowed_by_recipient) && in_array($sender_id, $allowed_by_recipient)) {
                continue;
            }

            // Zablokuj
            $errors[] = 'Nie możesz rozpocząć rozmowy z tym użytkownikiem. Wymagane jest wzajemne polubienie (Match) lub zgoda na rozmowę.';
            return;
        }
    } catch (Throwable $t) {
        error_log("CRITICAL ERROR in pm_bm_restrict_new_thread_creation: " . $t->getMessage());
    }
}

/**
 * Better Messages validation for sending messages in general.
 */
add_filter('better_messages_can_send_message', 'pm_bm_can_send_message_filter', 10, 3);
function pm_bm_can_send_message_filter($allowed, $user_id, $thread_id) {
    if (!$allowed) {
        return false;
    }

    try {
        $user_id = intval($user_id);
        if (!$user_id) {
            return $allowed;
        }

        // Admin bypass
        if (user_can($user_id, 'manage_options')) {
            return $allowed;
        }

        // Global override bypass
        if (defined('SK_BYPASS_MATCH_CHECK') && SK_BYPASS_MATCH_CHECK === true) {
            return $allowed;
        }

        // Pobierz odbiorców wątku
        if (class_exists('Better_Messages') && function_exists('Better_Messages')) {
            $thread_info = Better_Messages()->functions->get_thread($thread_id);
            if ($thread_info && isset($thread_info->recipients)) {
                // Jeśli wątek już istnieje i ma wiadomości, pozwalamy na kontynuację (to jest reply)
                global $wpdb;
                $messages_count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}bm_messages WHERE thread_id = %d",
                    $thread_id
                ));
                
                // Jeśli to jest istniejąca rozmowa (ma już wiadomości), pozwalamy pisać
                if ($messages_count > 0) {
                    return $allowed;
                }

                // W przeciwnym razie sprawdzamy dopasowanie
                foreach ($thread_info->recipients as $recipient) {
                    $recipient_id = intval(isset($recipient->user_id) ? $recipient->user_id : $recipient);
                    if (!$recipient_id || $recipient_id === $user_id) {
                        continue;
                    }

                    // 1. Sprawdź Match
                    if (function_exists('sk_is_mutual_match') && sk_is_mutual_match($user_id, $recipient_id)) {
                        continue;
                    }

                    // 2. Sprawdź czy odbiorca wyraził zgodę
                    $allowed_by_recipient = get_user_meta($recipient_id, 'sk_allowed_chat_ids', true) ?: [];
                    if (is_array($allowed_by_recipient) && in_array($user_id, $allowed_by_recipient)) {
                        continue;
                    }

                    // Zablokuj
                    global $bp_better_messages_restrict_send_message;
                    if (!is_array($bp_better_messages_restrict_send_message)) {
                        $bp_better_messages_restrict_send_message = [];
                    }
                    $bp_better_messages_restrict_send_message['no_match_error'] = 'Nie możesz pisać do tego użytkownika bez wzajemnego polubienia lub zgody.';
                    return false;
                }
            }
        }
    } catch (Throwable $t) {
        error_log("CRITICAL ERROR in pm_bm_can_send_message_filter: " . $t->getMessage());
    }

    return $allowed;
}

/**
 * UKRYCIE PRZYCISKU "WYŚLIJ WIADOMOŚĆ" NA PROFILU (TYLKO WWW)
 */
function pm_hide_message_button_no_match()
{
    // Działa tylko na profilu użytkownika
    if (!bp_is_user()) {
        return;
    }

    $user_id = bp_loggedin_user_id();
    $displayed_user_id = bp_displayed_user_id();

    // Jeśli to ten sam user lub niezalogowany -> return
    if ($user_id == $displayed_user_id || !is_user_logged_in()) {
        return;
    }

    // Sprawdzenie matcha
    $is_match = sk_is_mutual_match($user_id, $displayed_user_id);

    // Jeśli admin, zawsze pokazuj
    if (current_user_can('manage_options')) {
        $is_match = true;
    }

    // Jeśli nie ma matcha, usuwamy przycisk i zakładkę
    if (!$is_match) {
        // Usuwamy zakładkę wiadomości z profilu
        bp_core_remove_nav_item('messages');

        // Usuwamy przycisk "Prywatna wiadomość" (standardowy BuddyPress)
        // Czasami priorytet 20 jest za niski/wysoki, dodajemy filtr dla pewności
        remove_action('bp_member_header_actions', 'bp_send_private_message_button', 20);
        add_filter('bp_get_send_message_button_args', '__return_false', 99);
    } else {
        // Jeśli JEST match, upewniamy się, że przycisk NIE jest blokowany
        // (W razie gdyby jakiś inny plugin/kod go blokował)
        remove_filter('bp_get_send_message_button_args', '__return_false', 99);
    }
}
add_action('bp_actions', 'pm_hide_message_button_no_match');

/**
 * =========================================================================
 * === ZAKŁADKA "MATCHE" (MATCHES) W PROFILU
 * =========================================================================
 */
function sk_add_matches_nav_item()
{
    bp_core_new_nav_item(array(
        'name' => 'Matche',
        'slug' => 'matche',
        'position' => 60,
        'show_for_displayed_user' => true,
        'screen_function' => 'sk_matches_screen_content',
        'default_subnav_slug' => 'matche',
        'item_css_id' => 'matche'
    ));
}
add_action('bp_setup_nav', 'sk_add_matches_nav_item', 50);

function sk_matches_screen_content()
{
    add_action('bp_template_content', 'sk_render_matches_list');
    bp_core_load_template(apply_filters('bp_core_template_plugin', 'members/single/plugins'));
}

function sk_render_matches_list()
{
    $user_id = bp_displayed_user_id();
    $my_likes = get_user_meta($user_id, 'sk_user_likes', true) ?: [];
    $liked_me = get_user_meta($user_id, 'sk_liked_by_users', true) ?: [];
    $friend_ids = array_intersect($my_likes, $liked_me);

    if (empty($friend_ids)) {
        echo '<div class="info-box">Nie masz jeszcze żadnych matchy. Przeglądaj profile i polub kogoś!</div>';
        return;
    }

    if (bp_has_members(['include' => $friend_ids, 'type' => 'active', 'per_page' => 20])): ?>
        <div id="matches-grid" class="users-grid">
            <?php while (bp_members()):
                bp_the_member(); ?>
                <div class="user-card-match">
                    <div class="match-avatar"><a
                            href="<?php bp_member_permalink(); ?>"><?php bp_member_avatar('type=full&width=300&height=300'); ?></a>
                    </div>
                    <div class="match-info">
                        <h3><a href="<?php bp_member_permalink(); ?>"><?php bp_member_name(); ?></a></h3>
                        <div class="match-actions">
                            <a href="<?php bp_member_permalink(); ?>" class="button view-profile">Zobacz Profil</a>
                            <?php if (is_user_logged_in() && bp_loggedin_user_id() != bp_get_member_user_id()) {
                                // Build message compose URL
                                $recipient_login = bp_get_member_user_login();
                                
                                // Get user domain with trailing slash
                                $messages_url = trailingslashit(bp_loggedin_user_domain());
                                $messages_slug = function_exists('bp_get_messages_slug') ? bp_get_messages_slug() : 'messages';
                                
                                // Format: /members/username/messages/compose/?r=recipient_login
                                $message_link = $messages_url . $messages_slug . '/compose/?r=' . urlencode($recipient_login);
                                
                                echo '<a href="' . esc_url($message_link) . '" class="button message-button-match">✉️ Napisz</a>';
                            } ?>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
        <style>
            #matches-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                gap: 20px;
                margin-top: 20px;
            }

            .user-card-match {
                background: #fff;
                border-radius: 8px;
                padding: 15px;
                text-align: center;
                box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
                border: 1px solid #eee;
            }

            .match-avatar img {
                border-radius: 50%;
                margin-bottom: 10px;
            }

            .match-info h3 {
                font-size: 1.1em;
                margin: 10px 0;
            }

            .match-info h3 a {
                color: #333;
                text-decoration: none;
            }

            .match-actions {
                display: flex;
                gap: 10px;
                justify-content: center;
                margin-top: 15px;
            }

            .match-actions .button {
                font-size: 0.8em;
                padding: 5px 10px;
                border-radius: 4px;
                text-decoration: none;
            }

            .view-profile {
                background: #f5f5f5;
                color: #555;
            }

            .message-button-match {
                background: #e3f2fd;
                color: #1976d2;
                border: 1px solid #4a9eff;
            }

            .message-button-match:hover {
                background: #1976d2;
                color: #fff;
            }
        </style>
    <?php else: ?>
        <div class="info-box">Nie znaleziono matchy.</div>
    <?php endif;
}

/**
 * Zmiana nazwy zakładki "Znajomi" na "Dopasowania"
 */
function zmiana_nazwy_zakladki_friends()
{
    buddypress()->members->nav->edit_nav(array(
        'name' => 'Dopasowania',
        'slug' => 'dopasowania'
    ), 'friends');
}
add_action('bp_actions', 'zmiana_nazwy_zakladki_friends');

function pm_bm_ios_fullscreen_fix_mobile_wrap() {
    ?>
    <style>
    /* iOS Safari / mobile – poprawka wysokości wrappera Better Messages */
    @supports (-webkit-touch-callout: none) {
      /* główny wrapper chatu na mobile */
      .bp-messages-wrap.mobile-ready.bp-messages-mobile {
        height: calc(100vh - 85px) !important;
        max-height: calc(100vh - 85px) !important;
        display: flex !important;
        flex-direction: column;
        overflow: hidden;
      }

      /* lista wiadomości wewnątrz */
      .bp-messages-wrap.mobile-ready.bp-messages-mobile .bpbm-chat-main,
      .bp-messages-wrap.mobile-ready.bp-messages-mobile .bpbm-messages-list-wrap {
        flex: 1 1 auto;
        min-height: 0;
        overflow-y: auto;
      }

      /* stopka z inputem */
      .bp-messages-wrap.mobile-ready.bp-messages-mobile .bm-reply {
        flex: 0 0 auto;
        position: sticky;
        bottom: 0;
        padding-bottom: env(safe-area-inset-bottom, 8px);
        background: inherit;
      }
    }
    </style>
    
    <script>
    // Smart Polling & Optimistic Updates for Better Messages
    (function() {
        let pollingInterval = null;
        let currentThreadId = null;
        
        // Start smart polling when chat is opened
        function startSmartPolling() {
            if (pollingInterval) return; // Already polling
            
            pollingInterval = setInterval(() => {
                // Refresh messages for current thread
                if (currentThreadId && typeof BetterMessages !== 'undefined') {
                    console.log('[Smart Poll] Refreshing messages...');
                    // Better Messages has a method to refresh current thread
                    if (BetterMessages.functions && BetterMessages.functions.updateThread) {
                        BetterMessages.functions.updateThread(currentThreadId);
                    }
                }
            }, 10000); // Poll every 10 seconds
        }
        
        // Stop polling when chat closed
        function stopSmartPolling() {
            if (pollingInterval) {
                clearInterval(pollingInterval);
                pollingInterval = null;
                console.log('[Smart Poll] Stopped');
            }
        }
        
        // Initialize when document ready
        document.addEventListener('DOMContentLoaded', function() {
            // Detect when chat thread is opened
            document.addEventListener('click', function(e) {
                const threadLink = e.target.closest('.thread');
                if (threadLink) {
                    const threadId = threadLink.dataset.threadId || threadLink.getAttribute('data-thread-id');
                    if (threadId) {
                        currentThreadId = threadId;
                        startSmartPolling();
                    }
                }
            });
            
            // Optimistic Update - show message immediately
            document.addEventListener('submit', function(e) {
                if (e.target.closest('.bm-reply-form, .bp-messages-reply-form')) {
                    const textarea = e.target.querySelector('textarea[name="message"]');
                    const messageText = textarea ? textarea.value.trim() : '';
                    
                    if (messageText) {
                        // Create optimistic message element
                        const messagesList = document.querySelector('.bpbm-messages-list, .bp-messages-thread');
                        if (messagesList) {
                            const optimisticMsg = document.createElement('div');
                            optimisticMsg.className = 'message bp-messages-item outgoing optimistic-message';
                            optimisticMsg.innerHTML = `
                                <div class="message-content">
                                    <div class="message-text">${escapeHtml(messageText)}</div>
                                    <div class="message-time">Wysyłanie...</div>
                                </div>
                            `;
                            optimisticMsg.style.opacity = '0.7';
                            messagesList.appendChild(optimisticMsg);
                            messagesList.scrollTop = messagesList.scrollHeight;
                            
                            // Clear textarea immediately
                            textarea.value = '';
                            
                            // Remove optimistic message after 2 seconds (will be replaced by real message from poll)
                            setTimeout(() => {
                                optimisticMsg.style.transition = 'opacity 0.3s';
                                optimisticMsg.style.opacity = '1';
                            }, 500);
                        }
                    }
                }
            });
            
            // Stop polling when user leaves messages page
            const observer = new MutationObserver(() => {
                if (!document.querySelector('.bp-messages-wrap')) {
                    stopSmartPolling();
                    currentThreadId = null;
                }
            });
            observer.observe(document.body, { childList: true, subtree: true });
        });
        
        // Helper function to escape HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        console.log('[Smart Polling] Initialized');
    })();
    </script>
    <?php
}
add_action( 'wp_head', 'pm_bm_ios_fullscreen_fix_mobile_wrap', 80 );

// ============================================
// MOBILE BOTTOM NAVIGATION BAR
// ============================================

/**
 * Render mobile bottom navigation bar for logged-in users
 * Only visible on devices < 768px
 */
function pm_mobile_bottom_nav() {
    if (!is_user_logged_in()) {
        return;
    }
    
    $user_domain = function_exists('bp_loggedin_user_domain') ? bp_loggedin_user_domain() : home_url('/');
    $messages_slug = function_exists('bp_get_messages_slug') ? bp_get_messages_slug() : 'messages';
    $unread_count = function_exists('messages_get_unread_count') ? messages_get_unread_count() : 0;
    
    // Get count of users who liked me (only count existing users)
    $current_user_id = get_current_user_id();
    $liked_by_users = get_user_meta($current_user_id, 'sk_liked_by_users', true) ?: [];
    $likes_me_count = 0;
    if (is_array($liked_by_users)) {
        foreach ($liked_by_users as $user_id) {
            if (get_userdata($user_id)) {
                $likes_me_count++;
            }
        }
    }
    
    // Get current URL for active state detection
    $current_url = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    
    // Determine active item
    $is_dashboard = (strpos($current_url, '/dashboard') !== false || strpos($current_url, '/members-grid') !== false);
    $is_matches = (strpos($current_url, '/matche') !== false || strpos($current_url, '/friends') !== false);
    $is_messages = (strpos($current_url, '/bp-messages') !== false || strpos($current_url, '/' . $messages_slug) !== false);
    $is_profile = (!$is_dashboard && !$is_matches && !$is_messages && strpos($current_url, '/members/') !== false);
    ?>
    
    <nav class="pm-bottom-nav" id="pm-mobile-nav">
        <a href="<?php echo home_url('/dashboard'); ?>" class="pm-nav-item <?php echo $is_dashboard ? 'active' : ''; ?>">
            <svg class="pm-nav-icon" viewBox="0 0 24 24" fill="currentColor">
                <path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/>
            </svg>
            <span class="pm-nav-label">Odkryj</span>
        </a>
        
        <a href="<?php echo home_url('/dashboard/?tab=likes-me'); ?>" class="pm-nav-item <?php echo $is_matches ? 'active' : ''; ?>">
            <span class="pm-nav-icon-wrapper">
                <svg class="pm-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
                </svg>
                <?php if ($likes_me_count > 0): ?>
                    <span class="pm-nav-badge pm-nav-badge-yellow"><?php echo $likes_me_count > 99 ? '99+' : $likes_me_count; ?></span>
                <?php endif; ?>
            </span>
            <span class="pm-nav-label">Lubią Mnie</span>
        </a>
        
        <a href="<?php echo home_url('/tablica'); ?>" class="pm-nav-item <?php echo (strpos($current_url, '/tablica') !== false) ? 'active' : ''; ?>">
            <svg class="pm-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/>
            </svg>
            <span class="pm-nav-label">Tablica</span>
        </a>
        
        <a href="<?php echo $user_domain . $messages_slug . '/'; ?>" class="pm-nav-item <?php echo $is_messages ? 'active' : ''; ?>">
            <span class="pm-nav-icon-wrapper">
                <svg class="pm-nav-icon" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 12H6v-2h12v2zm0-3H6V9h12v2zm0-3H6V6h12v2z"/>
                </svg>
                <?php if ($unread_count > 0): ?>
                    <span class="pm-nav-badge" id="pm-nav-badge-messages"><?php echo $unread_count > 9 ? '9+' : $unread_count; ?></span>
                <?php endif; ?>
            </span>
            <span class="pm-nav-label">Wiadomości</span>
        </a>
        
        <a href="<?php echo $user_domain; ?>" class="pm-nav-item <?php echo $is_profile ? 'active' : ''; ?>">
            <svg class="pm-nav-icon" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
            </svg>
            <span class="pm-nav-label">Profil</span>
        </a>
    </nav>
    
    <style>
    /* Bottom Navigation - visible on all screen widths */
    .pm-bottom-nav {
        display: flex;
        justify-content: space-around;
        align-items: center;
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        height: 60px;
        background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
        border-top: 1px solid rgba(255,255,255,0.1);
        z-index: 9999;
        padding-bottom: env(safe-area-inset-bottom, 0);
        box-shadow: 0 -2px 10px rgba(0,0,0,0.3);
    }
    
    /* Add padding to body so content doesn't hide behind nav */
    body.logged-in {
        padding-bottom: calc(60px + env(safe-area-inset-bottom, 0)) !important;
    }
    
    /* Better Messages fullscreen fix - account for bottom nav */
    .bp-messages-wrap.mobile-ready.bp-messages-mobile {
        height: calc(100vh - 145px) !important;
        max-height: calc(100vh - 145px) !important;
    }
    
    .pm-nav-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        color: rgba(255,255,255,0.6);
        padding: 8px 16px;
        position: relative;
        transition: color 0.2s ease;
    }
    
    .pm-nav-item:hover,
    .pm-nav-item.active {
        color: #ff6b9d;
    }
    
    .pm-nav-icon {
        width: 24px;
        height: 24px;
        margin-bottom: 2px;
    }
    
    .pm-nav-label {
        font-size: 10px;
        font-weight: 500;
        letter-spacing: 0.3px;
    }
    
    .pm-nav-icon-wrapper {
        position: relative;
        display: inline-block;
    }
    
    .pm-nav-badge {
        position: absolute;
        top: -6px;
        right: -8px;
        background: #ff4757;
        color: white;
        font-size: 9px;
        font-weight: bold;
        min-width: 16px;
        height: 16px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0 3px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.3);
    }
    
    .pm-nav-badge-yellow {
        background: #ffc107;
        color: #333;
    }
    </style>
    <?php
}
add_action('wp_footer', 'pm_mobile_bottom_nav', 100);

// ============================================
// CUSTOM REST API: High-Resolution Profile Photos
// ============================================

/**
 * Add high-resolution profile photo URL to BuddyPress Members REST API response
 * This extends the default member data with full-size profile photo from media library
 */
add_filter('rest_prepare_buddypress_member', 'pm_add_hires_avatar_to_rest', 10, 3);

function pm_add_hires_avatar_to_rest($response, $user, $request) {
    $user_id = $user->ID;
    
    // Get the attachment ID from user meta
    $attach_id = get_user_meta($user_id, 'user_avatar_id', true);
    
    if ($attach_id) {
        // Get full size image URL
        $image_url = wp_get_attachment_image_url($attach_id, 'full');
        
        // Also get large size as fallback (good balance between quality and file size)
        $image_large_url = wp_get_attachment_image_url($attach_id, 'large');
        
        // Add to response
        $response->data['hires_avatar'] = array(
            'full' => $image_url ? $image_url : '',
            'large' => $image_large_url ? $image_large_url : '',
            'attachment_id' => $attach_id
        );
    } else {
        // Fallback: Pobierz avatar BuddyPress w dużej rozdzielczości
        $bp_avatar_full = bp_core_fetch_avatar([
            'item_id' => $user_id,
            'object'  => 'user',
            'type'    => 'full',
            'width'   => 500,
            'height'  => 500,
            'html'    => false // zwróć URL zamiast HTML
        ]);
        
        $bp_avatar_large = bp_core_fetch_avatar([
            'item_id' => $user_id,
            'object'  => 'user',
            'type'    => 'full',
            'width'   => 300,
            'height'  => 300,
            'html'    => false
        ]);
        
        // Sprawdź czy avatar nie jest domyślnym (gravatar, mystery-man)
        $is_default = empty($bp_avatar_full) || 
                      strpos($bp_avatar_full, 'mystery-man') !== false || 
                      strpos($bp_avatar_full, 'gravatar.com') !== false ||
                      strpos($bp_avatar_full, '/avatars/') === false;
        
        if (!$is_default) {
            $response->data['hires_avatar'] = array(
                'full' => $bp_avatar_full,
                'large' => $bp_avatar_large ? $bp_avatar_large : $bp_avatar_full,
                'attachment_id' => 0,
                'source' => 'buddypress'
            );
        } else {
            // No custom avatar, return empty
            $response->data['hires_avatar'] = array(
                'full' => '',
                'large' => '',
                'attachment_id' => 0
            );
        }
    }
    
    return $response;
}



// ============================================
// CUSTOM REST API: Members Endpoint
// ============================================

/**
 * Register REST API endpoint for getting members with filters
 */
/**
 * Batch fetch xprofile data for a list of user IDs
 */
function sk_get_batch_xprofile_data($user_ids) {
    if (empty($user_ids)) return [];
    
    global $wpdb;
    $bp = buddypress();
    
    $ids_placeholder = implode(',', array_map('intval', $user_ids));
    
    // Define the fields we care about (both Names and important IDs for Empaths/PM)
    $fields_to_fetch = [
        'Data urodzenia', 'O mnie', 'Podejście do wiary', 'Poglądy Polityczne', 
        'Styl Pracy', 'Styl jedzenia', 'Znak zodiaku', 'Personality', 'Numerology',
        'About Me', 'What\'s your Faith', 'Political Views', 'Working Style', 'Eating Style',
        'Faith', 'Religion', 'Politics', 'Work', 'Employment', 'Diet', 'Zodiac', 'Zodiac Sign'
    ];
    $empaths_ids = [107, 303, 338, 343, 346, 351, 356, 362, 367, 133, 215, 108, 334, 6721, 6722]; // Expanded IDs for Empaths and PM fallback
    
    $fields_placeholder = implode("','", array_map('esc_sql', $fields_to_fetch));
    $ids_placeholder_fields = implode(",", $empaths_ids);
    
    $query = "
        SELECT d.user_id, f.name, d.value, f.id as field_id 
        FROM {$bp->profile->table_name_data} d
        JOIN {$bp->profile->table_name_fields} f ON d.field_id = f.id
        WHERE d.user_id IN ($ids_placeholder)
        AND (f.name IN ('$fields_placeholder') OR f.id IN ($ids_placeholder_fields))
    ";
    
    $raw_results = $wpdb->get_results($query);
    $formatted = [];
    
    foreach ($raw_results as $row) {
        $val = maybe_unserialize($row->value);
        
        // Key by Name (for legacy logic)
        $formatted[$row->user_id][$row->name] = $val;
        
        // Key by ID (for robust Empaths logic)
        $formatted[$row->user_id][$row->field_id] = $val;
        
        // Normalize specific keys for backward compatibility
        if ($row->field_id == 107) {
            $formatted[$row->user_id]['Data urodzenia'] = $val;
        }
    }
    
    return $formatted;
}

/**
 * Batch fetch last activity for a list of user IDs
 */
function sk_get_batch_last_activity($user_ids) {
    if (empty($user_ids)) return [];
    
    global $wpdb;
    $ids_placeholder = implode(',', array_map('intval', $user_ids));
    $formatted = [];

    // 1. Try usermeta (common for BuddyPress)
    $meta_results = $wpdb->get_results("
        SELECT user_id, meta_value 
        FROM {$wpdb->usermeta} 
        WHERE user_id IN ($ids_placeholder) 
        AND meta_key = 'last_activity'
    ");
    
    foreach ($meta_results as $row) {
        $formatted[$row->user_id] = $row->meta_value;
    }
    
    // 2. Try BuddyPress activity table for missing IDs
    $missing_ids = array_diff($user_ids, array_keys($formatted));
    if (!empty($missing_ids)) {
        $missing_placeholder = implode(',', array_map('intval', $missing_ids));
        $bp_activity_table = $wpdb->prefix . 'bp_activity';
        
        // Check if table exists to avoid SQL errors
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$bp_activity_table'");
        
        if ($table_exists) {
            $activity_results = $wpdb->get_results("
                SELECT user_id, date_recorded 
                FROM $bp_activity_table 
                WHERE user_id IN ($missing_placeholder) 
                AND type = 'last_activity'
            ");
            
            foreach ($activity_results as $row) {
                if (!isset($formatted[$row->user_id])) {
                    $formatted[$row->user_id] = $row->date_recorded;
                }
            }
        }
    }
    
    return $formatted;
}

/**
 * Format activity status for privacy
 * Admins see full detail, users see "fuzzy" buckets
 */
function sk_get_time_since($timestamp) {
    $diff = time() - $timestamp;
    if ($diff < 60) {
        return 'przed chwilą';
    }
    
    $minutes = round($diff / 60);
    if ($minutes < 60) {
        if ($minutes == 1) return '1 minutę temu';
        if (in_array($minutes % 10, [2, 3, 4]) && !in_array($minutes % 100, [12, 13, 14])) {
            return "$minutes minuty temu";
        }
        return "$minutes minut temu";
    }
    
    $hours = round($diff / 3600);
    if ($hours < 24) {
        if ($hours == 1) return '1 godzinę temu';
        if (in_array($hours % 10, [2, 3, 4]) && !in_array($hours % 100, [12, 13, 14])) {
            return "$hours godziny temu";
        }
        return "$hours godzin temu";
    }
    
    $days = round($diff / 86400);
    if ($days < 30) {
        if ($days == 1) return '1 dzień temu';
        return "$days dni temu";
    }
    
    $months = round($days / 30);
    if ($months < 12) {
        if ($months == 1) return '1 miesiąc temu';
        if (in_array($months % 10, [2, 3, 4]) && !in_array($months % 100, [12, 13, 14])) {
            return "$months miesiące temu";
        }
        return "$months miesięcy temu";
    }
    
    return 'dawno temu';
}

function sk_format_activity_status($last_activity_date, $is_admin = false) {
    if (empty($last_activity_date)) {
        return 'Aktywność nieznana';
    }

    $last_active_timestamp = is_numeric($last_activity_date) ? $last_activity_date : strtotime($last_activity_date);
    if (!$last_active_timestamp) {
        return 'Aktywność nieznana';
    }

    $diff = time() - $last_active_timestamp;

    // Jeśli użytkownik był aktywny w ciągu ostatnich 5 minut, oznaczamy go jako dostępnego teraz
    if ($diff < 300) {
        return 'Dostępny/a teraz';
    }

    return 'Aktywny/a ' . sk_get_time_since($last_active_timestamp);
}

/**
 * Batch fetch high-res avatars for a list of user IDs
 */
function sk_get_batch_hires_avatars($user_ids) {
    if (empty($user_ids)) return [];
    
    global $wpdb;
    $ids_placeholder = implode(',', array_map('intval', $user_ids));
    
    $query = "
        SELECT user_id, meta_value 
        FROM {$wpdb->usermeta} 
        WHERE user_id IN ($ids_placeholder) 
        AND meta_key = 'user_avatar_id'
    ";
    
    $raw_results = $wpdb->get_results($query);
    $formatted = [];
    
    foreach ($raw_results as $row) {
        $attach_id = intval($row->meta_value);
        if ($attach_id) {
            $large_url = wp_get_attachment_image_url($attach_id, 'large');
            $full_url = wp_get_attachment_image_url($attach_id, 'full');
            
            // Only add to array if at least one URL was successfully retrieved
            if ($large_url || $full_url) {
                $formatted[$row->user_id] = [
                    'large' => $large_url,
                    'full' => $full_url,
                ];
            }
        }
    }
    
    return $formatted;
}

add_action('rest_api_init', function () {
    register_rest_route('sk/v1', '/messages/threads/all', [
        'methods' => 'GET',
        'callback' => 'sk_get_all_message_threads',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);

    register_rest_route('sk/v1', '/members', [
        'methods' => 'GET',
        'callback' => 'sk_get_members_endpoint',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);

    register_rest_route('sk/v1', '/unread-count', [
        'methods' => 'GET',
        'callback' => 'sk_get_unread_count_endpoint',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);

    // Single member endpoint with full details - supports 'me' or ID
    register_rest_route('sk/v1', '/member/(?P<id>[a-zA-Z0-9]+)', [
        'methods' => 'GET',
        'callback' => 'sk_get_single_member_endpoint',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);

    // Notifications History endpoint
    register_rest_route('sk/v1', '/notifications', [
        'methods' => 'GET',
        'callback' => 'sk_get_notifications_endpoint',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);

    // Delete Notification endpoint
    register_rest_route('sk/v1', '/notifications/(?P<id>\d+)', [
        'methods' => 'DELETE',
        'callback' => 'sk_delete_notification_endpoint',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);

    // Mark Notifications as Read endpoint
    register_rest_route('sk/v1', '/notifications/mark-read', [
        'methods' => 'POST',
        'callback' => 'sk_mark_notifications_read_endpoint',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);

    // Push Token Endpoints
    register_rest_route('sk/v1', '/update-push-token', [
        'methods' => 'POST',
        'callback' => 'sk_update_push_token_endpoint',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);

    register_rest_route('sk/v1', '/push-token', [
        'methods' => 'POST',
        'callback' => 'sk_update_push_token_endpoint',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
});

/**
 * Update user preference (meta) - Dedicated registration to ensure it's picked up
 */
add_action('rest_api_init', function() {
    register_rest_route('sk/v1', '/update-preference', [
        'methods' => 'POST',
        'callback' => 'sk_update_preference_endpoint',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);

    // Compatibility Breakdown endpoint
    register_rest_route('sk/v1', '/compatibility-breakdown', [
        'methods' => 'GET',
        'callback' => 'sk_compatibility_breakdown_endpoint',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
});

function sk_compatibility_breakdown_endpoint($request) {
    $current_user_id = get_current_user_id();
    $target_user_id = (int) $request->get_param('user_id');

    if (!$target_user_id || $target_user_id == $current_user_id) {
        return new WP_Error('invalid_user', 'Invalid target user', ['status' => 400]);
    }

    $target_user = get_userdata($target_user_id);
    if (!$target_user) {
        return new WP_Error('user_not_found', 'User not found', ['status' => 404]);
    }

    // Define the compatibility fields (matching BP Match weighted fields)
    $fields = [
        ['id' => 346, 'label' => 'Wiara', 'icon' => '🙏', 'names' => ['Podejście do wiary', 'Wiara', 'Faith', 'Religion']],
        ['id' => 351, 'label' => 'Polityka', 'icon' => '🏛️', 'names' => ['Poglądy Polityczne', 'Polityka', 'Politics', 'Political Views']],
        ['id' => 356, 'label' => 'Styl Pracy', 'icon' => '💼', 'names' => ['Styl Pracy', 'Praca', 'Work', 'Employment']],
        ['id' => 362, 'label' => 'Dieta', 'icon' => '🥗', 'names' => ['Styl Jedzenia', 'Dieta', 'Diet', 'Eating Style', 'Styl jedzenia']],
        ['id' => 286, 'label' => 'Alkohol', 'icon' => '🍷', 'names' => ['Alkohol', 'Podejście do Alkoholu']],
        ['id' => 6947, 'label' => 'Dzieci', 'icon' => '👶', 'names' => ['Dzieci']],
        ['id' => 6951, 'label' => 'Papierosy', 'icon' => '🚬', 'names' => ['Papierosy']],
        ['id' => 303, 'label' => 'Zodiak', 'icon' => '✨', 'names' => ['Znak zodiaku', 'Zodiak', 'Znak Zodiaku', 'Zodiac Sign']],
        ['id' => 0, 'label' => 'Wykształcenie', 'icon' => '🎓', 'names' => ['Wykształcenie', 'Education']],
        ['id' => 0, 'label' => 'Osobowość', 'icon' => '🧠', 'names' => ['Osobowość', 'Personality']],
    ];

    $breakdown = [];
    $matched = 0;
    $total = 0;

    foreach ($fields as $field) {
        // Try by field ID first (skip if ID is 0 — name-only fields)
        $my_val = '';
        if ($field['id'] > 0) {
            $my_raw = xprofile_get_field_data($field['id'], $current_user_id);
            $my_val = is_array($my_raw) ? trim(implode(', ', $my_raw)) : trim((string)$my_raw);
        }
        if ($my_val === '') {
            foreach ($field['names'] as $name) {
                $my_raw = bp_get_profile_field_data(['field' => $name, 'user_id' => $current_user_id]);
                $my_val = is_array($my_raw) ? trim(implode(', ', $my_raw)) : trim((string)$my_raw);
                if ($my_val !== '') break;
            }
        }

        // Try by field ID first for their value
        $their_val = '';
        if ($field['id'] > 0) {
            $their_raw = xprofile_get_field_data($field['id'], $target_user_id);
            $their_val = is_array($their_raw) ? trim(implode(', ', $their_raw)) : trim((string)$their_raw);
        }
        if ($their_val === '') {
            foreach ($field['names'] as $name) {
                $their_raw = bp_get_profile_field_data(['field' => $name, 'user_id' => $target_user_id]);
                $their_val = is_array($their_raw) ? trim(implode(', ', $their_raw)) : trim((string)$their_raw);
                if ($their_val !== '') break;
            }
        }

        // If both have values, count it
        if ($my_val !== '' && $their_val !== '') {
            $total++;
            $is_match = ($my_val === $their_val);
            if ($is_match) $matched++;
        } else {
            $is_match = null; // Unknown — one or both haven't set this
        }

        $breakdown[] = [
            'label' => $field['label'],
            'icon' => $field['icon'],
            'my_value' => $my_val ?: null,
            'their_value' => $their_val ?: null,
            'is_match' => $is_match,
        ];
    }

    // Use BP Match plugin for overall weighted percentage
    $overall = sk_get_bp_match_percentage($current_user_id, $target_user_id);

    return rest_ensure_response([
        'overall_percent' => $overall,
        'total_fields' => $total,
        'matched_fields' => $matched,
        'target_name' => $target_user->display_name,
        'breakdown' => $breakdown,
    ]);
}

/**
 * Delete a specific BuddyPress notification
 */
function sk_delete_notification_endpoint($request) {
    if (!function_exists('bp_notifications_delete_notification')) {
        return new WP_Error('bp_inactive', 'BuddyPress Notifications component is not active', ['status' => 500]);
    }

    $id = (int)$request['id'];
    $user_id = get_current_user_id();

    global $wpdb;
    // Get BuddyPress notification table name safely
    $table_name = function_exists('buddypress') && isset(buddypress()->notifications->table_name) 
        ? buddypress()->notifications->table_name 
        : $wpdb->prefix . 'bp_notifications';

    // Verify ownership directly from DB before deletion
    $notification_owner = $wpdb->get_var($wpdb->prepare(
        "SELECT user_id FROM $table_name WHERE id = %d", 
        $id
    ));

    if (!$notification_owner) {
        return new WP_Error('not_found', 'Powiadomienie nie istnieje', ['status' => 404]);
    }

    if ((int)$notification_owner !== (int)$user_id) {
        return new WP_Error('forbidden', 'Nie masz uprawnień do usunięcia tego powiadomienia', ['status' => 403]);
    }

    // Direct deletion
    $deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM $table_name WHERE id = %d", 
        $id
    ));

    if ($deleted !== false) {
        return rest_ensure_response(['success' => true]);
    } else {
        return new WP_Error('delete_failed', 'Błąd bazy danych przy usuwaniu powiadomienia', ['status' => 500]);
    }
}

/**
 * Mark notifications as read for the current user
 */
function sk_mark_notifications_read_endpoint($request) {
    if (!function_exists('buddypress')) {
        return new WP_Error('bp_inactive', 'BuddyPress is not active', ['status' => 500]);
    }

    $user_id = get_current_user_id();
    $notification_id = $request->get_param('id'); // Optional: if provided, marks only this one. If empty, marks all for user.
    
    global $wpdb;
    $table_name = isset(buddypress()->notifications->table_name) 
        ? buddypress()->notifications->table_name 
        : $wpdb->prefix . 'bp_notifications';

    if (!empty($notification_id)) {
        // Mark specific notification as read
        $updated = $wpdb->update(
            $table_name,
            ['is_new' => 0],
            ['id' => (int)$notification_id, 'user_id' => $user_id],
            ['%d'],
            ['%d', '%d']
        );
    } else {
        // Mark all notifications as read for this user
        $updated = $wpdb->update(
            $table_name,
            ['is_new' => 0],
            ['user_id' => $user_id, 'is_new' => 1],
            ['%d'],
            ['%d', '%d']
        );
    }

    return rest_ensure_response(['success' => true]);
}

/**
 * Get the current user's BuddyPress notifications
 */
function sk_get_notifications_endpoint($request) {
    if (!function_exists('buddypress')) {
        return new WP_Error('bp_inactive', 'BuddyPress is not active', ['status' => 500]);
    }

    $user_id = get_current_user_id();
    global $wpdb;
    $table_name = isset(buddypress()->notifications->table_name) 
        ? buddypress()->notifications->table_name 
        : $wpdb->prefix . 'bp_notifications';

    // Fetch notifications directly to include our custom 'content' column and get history
    $notifications = $wpdb->get_results($wpdb->prepare("
        SELECT * FROM $table_name 
        WHERE user_id = %d 
        ORDER BY date_notified DESC 
        LIMIT 50
    ", $user_id));
    
    $formatted = [];
    if (!empty($notifications)) {
        foreach ($notifications as $n) {
            $item = [
                'id' => (int)$n->id,
                'component' => $n->component_name,
                'action' => $n->component_action,
                'date' => $n->date_notified,
                'is_new' => (int)$n->is_new,
                'title' => 'Powiadomienie',
                'body' => '',
                'data' => [
                    'item_id' => (int)$n->item_id,
                    'secondary_item_id' => (int)$n->secondary_item_id
                ]
            ];

            // Resolve content
            switch ($n->component_name) {
                case 'messages':
                    $item['title'] = 'Nowa wiadomość';
                    $sender_name = bp_core_get_user_displayname($n->item_id);
                    $item['body'] = $sender_name . ' wysłał(a) Ci wiadomość.';
                    $item['type'] = 'message';
                    break;
                case 'custom_broadcast':
                    $item['title'] = 'Ogłoszenie';
                    // Use the custom content column!
                    $item['body'] = $n->content ? $n->content : 'Masz nową wiadomość od administratora.';
                    $item['type'] = 'broadcast';
                    break;
                case 'members':
                    if ($n->component_action === 'new_match') {
                         $item['title'] = 'Nowy Match! 💖';
                         $matched_name = bp_core_get_user_displayname($n->item_id);
                         $item['body'] = 'Masz nowe dopasowanie z ' . $matched_name;
                         $item['type'] = 'match';
                    }
                    break;
            }

            $formatted[] = $item;
        }
    }

    return rest_ensure_response($formatted);
}

/**
 * Core function to send Push Notification via Expo
 * 
 * @param int|array $user_ids Single ID or array of user IDs
 * @param string $title Notification title
 * @param string $body Notification body
 * @param array $data Optional data payload
 */
function sk_send_push_notification($user_ids, $title, $body, $data = []) {
    sk_debug_log("Push: sk_send_push_notification called for users: " . (is_array($user_ids) ? implode(',', $user_ids) : $user_ids));
    $log_file = dirname(__FILE__) . '/sk_push.log';
    $log_debug = function($msg) use ($log_file) {
        $ts = date('Y-m-d H:i:s');
        @file_put_contents($log_file, "[$ts] $msg" . PHP_EOL, FILE_APPEND);
    };

    $uids = is_array($user_ids) ? array_map('intval', $user_ids) : [(int)$user_ids];
    $current_thread_id = isset($data['thread_id']) ? (int)$data['thread_id'] : 0;
    $sender_id = isset($data['sender_id']) ? (int)$data['sender_id'] : 0;
    $current_logged_in_uid = (int)get_current_user_id();

    $log_debug("PUSH: CALLED for users [" . implode(',', $uids) . "] | T:$current_thread_id | S:$sender_id");
    
    if (empty($uids)) {
        error_log("PUSH DEBUG: FAILURE - Empty user_ids");
        return false;
    }

    
    $messages = [];
    foreach ($uids as $target_uid) {
        if ($target_uid <= 0) continue;

        // 1. Self Check
        if ($target_uid === $sender_id || ($current_logged_in_uid > 0 && $target_uid === $current_logged_in_uid)) {
            error_log("PUSH DEBUG [$target_uid]: ABORT - Self notification (S:$sender_id, L:$current_logged_in_uid)");
            continue;
        }

        // 2. Throttling Check (Persistent until read)
        $alerted = get_user_meta($target_uid, 'sk_alerted_threads', true);
        if (!is_array($alerted)) $alerted = [];
        
        $is_silent = false;
        if ($current_thread_id && in_array((int)$current_thread_id, array_map('intval', $alerted))) {
            $is_silent = true;
            $log_debug("PUSH [$target_uid][T:$current_thread_id]: SILENT - Already alerted.");
        } else {
            if ($current_thread_id) {
                $alerted[] = (int)$current_thread_id;
                update_user_meta($target_uid, 'sk_alerted_threads', array_unique($alerted));
                $log_debug("PUSH [$target_uid][T:$current_thread_id]: VOCAL - First alert for this thread.");
            }
        }

        // 3. Duplicate Lock (Safety for rapid events)
        $lock_key = "sk_push_lock_{$target_uid}_{$current_thread_id}";
        if (!$is_silent && get_transient($lock_key)) {
            $log_debug("PUSH [$target_uid][T:$current_thread_id]: ABORT - Lock active (3s).");
            continue;
        }
        if (!$is_silent) set_transient($lock_key, 1, 3);

        // 4. Badge Calculation (Skip Reset during push to avoid race conditions)
        $badge = 0;
        $unread_resp = sk_get_unread_count_endpoint($target_uid, true);
        if (is_object($unread_resp)) {
            $rd = $unread_resp->get_data();
            $badge = (int)($rd['unread_count'] ?? 0);
        }
        
        $user_data = $data;
        $user_data['unread_count'] = $badge;

        // 5. Gather Tokens
        $tokens = get_user_meta($target_uid, 'sk_expo_push_token', false);
        if (!is_array($tokens) || empty($tokens)) {
            $log_debug("PUSH [$target_uid]: FAILURE - No tokens found.");
            continue;
        }

        foreach ($tokens as $token) {
            if (empty($token)) continue;
            
            $payload = [
                'to' => $token,
                'data' => $user_data,
                'badge' => (int)$badge,
                'sound' => 'default'
            ];

            if (!$is_silent) {
                $payload['title'] = $title;
                $payload['body'] = $body;
            } else {
                unset($payload['sound']);
                $log_debug("PUSH [$target_uid]: Adding SILENT payload for token " . substr($token, -6));
            }

            if (isset($data['collapse_id'])) {
                $payload['collapseId'] = $data['collapse_id'];
            }

            $messages[] = $payload;
        }
    }

    if (empty($messages)) {
        $log_debug("PUSH: ABORT - No payloads to send.");
        return false;
    }
    
    // Send to Expo Push API
    $log_debug("PUSH: Sending " . count($messages) . " messages to Expo.");
    $response = wp_remote_post('https://exp.host/--/api/v2/push/send', [
        'headers' => [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Accept-encoding' => 'gzip, deflate',
        ],
        'body' => json_encode($messages),
        'timeout' => 15,
    ]);
    
    if (is_wp_error($response)) {
        $log_debug("PUSH ERROR: " . $response->get_error_message());
        return false;
    }
    
    $log_debug("PUSH SUCCESS: Batch sent.");
    
    return true;
}

/**
 * Send Push Notification for new messages
 */
/**
 * Send Push Notification for new messages WITH SMART THROTTLING
 */
function sk_push_on_message_sent($message) {
    sk_debug_log("Push: sk_push_on_message_sent called. Msg ID: " . (isset($message->id) ? $message->id : 'unknown'));
    
    if (!class_exists('Better_Messages')) {
        sk_debug_log("Push: Better_Messages class NOT found.");
        return;
    }
    try {
        if (!is_object($message) || empty($message->sender_id) || empty($message->thread_id) || empty($message->recipients)) {
            return;
        }

        // 1. Cooldown to prevent duplicate triggers (BP + BM hooks often trigger together)
        $thread_id = $message->thread_id;
        $sender_id = $message->sender_id;
        
        // --- SMART THROTTLING: RESET SENDER ---
        // If I am sending a message, I am 'active'. Clear my throttle so I get the NEXT notification immediately.
        $my_throttle_key = 'sk_push_throttle_' . $sender_id . '_' . $thread_id;
        delete_transient($my_throttle_key);

        static $push_processed_in_this_request = [];
        $request_key = $sender_id . '_' . $thread_id;
        if (in_array($request_key, $push_processed_in_this_request)) {
            return; // Already handled in this PHP request cycle
        }
        $push_processed_in_this_request[] = $request_key;

        // Lock for duplicate prevention (technical 5s lock across requests)
        $lock_key = 'sk_push_lock_' . $sender_id . '_' . $thread_id;
        if (get_transient($lock_key)) {
            return; // Skip if sent in last 5 seconds
        }
        set_transient($lock_key, 1, 5);

        foreach ($message->recipients as $recipient) {
            // Safety check for recipient object
            if (!is_object($recipient) || empty($recipient->user_id)) continue;
            
            $recipient_id = (int) $recipient->user_id;
            $sender_compare_id = (int) $sender_id;

            // 1. Strict Sender Check
            if ($recipient_id === $sender_compare_id) {
                continue;
            }

            // 2. Extra safety: Check against logged-in user
            $current_user = get_current_user_id();
            if ($current_user && $recipient_id === $current_user) {
                error_log("PUSH DEBUG: Skipped self-notification (current_user match) for $recipient_id");
                continue;
            }

            // 3. Smart Presence Check
            $active_thread = (int) get_user_meta($recipient_id, 'sk_active_thread_id', true);
            sk_debug_log("PUSH CHECK: Recipient $recipient_id, Active: $active_thread, MsgThread: $thread_id");

            if ($active_thread && $active_thread === (int) $thread_id) {
                sk_debug_log(" - SKIPPED (Active)");
                continue;
            }

            if ($recipient_id != $sender_id) {
                // --- SMART THROTTLING LOGIC ---
                // Temporarily DISABLED for Debugging
                $recipient_throttle_key = 'sk_push_throttle_' . $recipient_id . '_' . $thread_id;
                
                // if (get_transient($recipient_throttle_key)) {
                //    error_log("PUSH DEBUG: Throttled for $recipient_id on thread $thread_id");
                //    continue; // TEMPORARILY DISABLED THROTTLING FOR DEBUG
                // }

                // If not throttled, we will send. NOW set the throttle for next 120 seconds.
                // set_transient($recipient_throttle_key, 1, 120);

                error_log("PUSH DEBUG: Sending to $recipient_id (Sender: $sender_id)");

                // Safe Sender Name Retrieval
                $sender_name = 'Użytkownik';
                if (function_exists('bp_core_get_user_displayname')) {
                    $sender_name = bp_core_get_user_displayname($message->sender_id);
                } elseif (function_exists('get_the_author_meta')) {
                    $sender_name = get_the_author_meta('display_name', $message->sender_id);
                }
                
                $push_data = [
                    'type' => 'message',
                    'thread_id' => $thread_id,
                    'sender_id' => $message->sender_id,
                    '_displayInForeground' => false, 
                    'collapse_id' => 'thread_' . $thread_id 
                ];

                // Debug context
                // $debug_context = "[S:$sender_id R:$recipient_id]"; // REMOVED
                
                sk_send_push_notification(
                    $recipient_id,
                    'Nowa wiadomość',
                     $sender_name . ': ' . wp_trim_words(strip_tags($message->message), 10),
                    $push_data
                );
            }
        }
    } catch (Throwable $t) {
        error_log("CRITICAL ERROR in sk_push_on_message_sent: " . $t->getMessage());
    }
}
add_action('messages_message_sent', 'sk_push_on_message_sent');

// Hook for Better Messages
add_action('better_messages_message_sent', function($arg1 = null, $arg2 = null, $arg3 = null, $arg4 = null, $arg5 = null) {
   sk_debug_log("HOOK: better_messages_message_sent fired.");
   try {
       // Determine if we received the message object (new format) or individual args (old format)
       // The error log showed 1 argument passed: BM_Messages_Message object
       $recipients = [];
       
       if (is_object($arg1)) {
           // New format: Single object passed
           $message_object = $arg1;
           $message_id = isset($message_object->id) ? $message_object->id : 0;
           $thread_id  = isset($message_object->thread_id) ? $message_object->thread_id : 0;
           $sender_id  = isset($message_object->sender_id) ? $message_object->sender_id : 0;
           $content    = isset($message_object->message) ? $message_object->message : '';
           
           // Attempt to get recipients from object or fetch them
           if (isset($message_object->recipients) && !empty($message_object->recipients)) {
               $recipients = $message_object->recipients;
           } else {
               // Fallback: This might require a DB call or BP function if recipients aren't on the object
               // For now, we'll try to get them via thread_id if possible, or skip to avoid crash
               global $wpdb;
               if ($thread_id) {
                    $table_recipients = $wpdb->prefix . 'bp_messages_recipients';
                    $recipients = $wpdb->get_col($wpdb->prepare("SELECT user_id FROM $table_recipients WHERE thread_id = %d AND user_id != %d", $thread_id, $sender_id));
               }
           }
       } else {
           // Old format: 5 arguments
           $message_id = $arg1;
           $thread_id = $arg2;
           $sender_id = $arg3;
           $recipients = $arg4;
           $content = $arg5;
       }

       // Common Logic
       if (empty($recipients)) return;
       
       // Construct a fake message object compliant with sk_push_on_message_sent expectations
       $message = new stdClass();
       $message->id = $message_id;
       $message->thread_id = $thread_id;
       $message->sender_id = $sender_id;
       $message->message = $content;
       $message->recipients = []; // We iterate recipients below, this property is just for structure
       
       // Handle different recipients formats
       if (is_array($recipients)) {
           foreach($recipients as $rid) {
               $r_id = 0;
               if (is_numeric($rid)) {
                   $r_id = (int)$rid;
               } elseif (is_object($rid) && isset($rid->user_id)) {
                   $r_id = (int)$rid->user_id;
               }

               // Explicitly exclude sender from push recipients
               if ($r_id > 0 && $r_id !== (int)$sender_id) {
                   $r = new stdClass();
                   $r->user_id = $r_id;
                   $message->recipients[] = $r;
               }
           }
       }
       
       sk_push_on_message_sent($message);
   } catch (Throwable $t) {
       error_log("CRITICAL ERROR in better_messages_message_sent hook: " . $t->getMessage());
   }

}, 10, 5);

/**
 * Shadow Ban: Filter Better Messages threads
 */
add_filter('better_messages_get_threads_args', function($args) {
    // Debug log
    error_log('BM Threads Args: ' . print_r($args, true));

    $hidden_users = sk_get_hidden_user_ids();
    if (!empty($hidden_users)) {
        if (!isset($args['exclude_users'])) {
            $args['exclude_users'] = [];
        }
        $args['exclude_users'] = array_merge($args['exclude_users'], $hidden_users);
        error_log('Hidden users applied: ' . count($hidden_users));
    }
    return $args;
});

/**
 * Handle push token update
 */
function sk_update_push_token_endpoint($request) {
    $log_path = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/uploads/sk_ping.txt' : (defined('ABSPATH') ? ABSPATH . 'wp-content/uploads/sk_ping.txt' : '/tmp/sk_ping.txt');
    $log_debug = function($msg) use ($log_path) {
        @file_put_contents($log_path, date('[H:i:s] ') . "PUSH DEBUG: $msg" . PHP_EOL, FILE_APPEND);
    };

    $token = sanitize_text_field($request['push_token'] ?: $request['token']);
    $user_id = get_current_user_id();

    if (empty($token) || (strpos($token, 'ExponentPushToken') === false && strpos($token, 'ExpoPushToken') === false)) {
        $log_debug("Invalid token format for user $user_id: $token");
        return new WP_Error('invalid_token', 'Invalid push token format', ['status' => 400]);
    }

    // Save token as multiple meta (one user can have multiple devices)
    $existing_tokens = get_user_meta($user_id, 'sk_expo_push_token', false);
    if (!in_array($token, $existing_tokens)) {
        add_user_meta($user_id, 'sk_expo_push_token', $token);
        $log_debug("Added new token for user $user_id. Total tokens: " . (count($existing_tokens) + 1));
    } else {
        $log_debug("Token already exists for user $user_id. Total tokens: " . count($existing_tokens));
    }

    return rest_ensure_response(['success' => true]);
}

/**
 * Hook into Match event (when mutual like happens)
 * This assumes there is an action 'sk_user_matched' triggered when a match occurs.
 * If not, we should trigger it in the toggle_like_user function.
 */
function sk_push_on_match($user_id_1, $user_id_2) {
    // Notify User 1
    $user_2_name = bp_core_get_user_displayname($user_id_2);
    sk_send_push_notification(
        $user_id_1,
        'Nowe dopasowanie! ❤️',
        'Masz nowe dopasowanie z ' . $user_2_name . '. Napisz pierwszy!',
        ['type' => 'match', 'user_id' => $user_id_2]
    );

    // Notify User 2
    $user_1_name = bp_core_get_user_displayname($user_id_1);
    sk_send_push_notification(
        $user_id_2,
        'Nowe dopasowanie! ❤️',
        'Masz nowe dopasowanie z ' . $user_1_name . '. Napisz pierwszy!',
        ['type' => 'match', 'user_id' => $user_id_1]
    );
}
add_action('sk_user_matched', 'sk_push_on_match', 10, 2);

/**
 * Hook into Like event (when someone likes you, but it's not yet a match)
 */
function sk_push_on_like($liked_user_id, $liking_user_id) {
    // Only send if it's NOT a match (match has its own notification)
    $my_likes = get_user_meta($liked_user_id, 'sk_user_likes', true) ?: [];
    if (!in_array($liking_user_id, array_map('intval', $my_likes))) {
        sk_send_push_notification(
            $liked_user_id,
            'Ktoś Cię polubił! ✨',
            'Nowa osoba zainteresowała się Twoim profilem. Sprawdź kto!',
            ['type' => 'like', 'user_id' => $liking_user_id]
        );
    }
}
add_action('sk_user_liked', 'sk_push_on_like', 10, 2);

// ============================================
// CUSTOM REST API: Matches Endpoint
// ============================================

/**
 * Register REST API endpoint for getting matched users (mutual likes)
 */
add_action('rest_api_init', function () {
    register_rest_route('sk/v1', '/matches', [
        'methods' => 'GET',
        'callback' => 'sk_get_matches_endpoint',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
});

/**
 * Get matched users for the current user
 * A match occurs when two users have mutually liked each other
 */
function sk_get_matches_endpoint($request) {
    $current_user_id = get_current_user_id();
    
    if (!$current_user_id) {
        return new WP_Error('not_logged_in', 'User must be logged in', ['status' => 401]);
    }
    
    // Get users I liked and users who liked me
    $my_likes = get_user_meta($current_user_id, 'sk_user_likes', true) ?: [];
    $liked_me = get_user_meta($current_user_id, 'sk_liked_by_users', true) ?: [];
    
    // Find mutual likes (matches)
    $match_ids = array_intersect($my_likes, $liked_me);

    // Shadow Ban: Filter hidden users
    $hidden_users = sk_get_hidden_user_ids();
    if (!empty($match_ids) && !empty($hidden_users)) {
        $match_ids = array_diff($match_ids, $hidden_users);
    }
    
    if (empty($match_ids)) {
        return rest_ensure_response([]);
    }

    // BATCH FETCH ALL DATA
    $batch_xprofile = sk_get_batch_xprofile_data($match_ids);
    $batch_activity = sk_get_batch_last_activity($match_ids);
    $batch_avatars = sk_get_batch_hires_avatars($match_ids);

    $results = [];
    foreach ($match_ids as $user_id) {
        $user_data = get_userdata($user_id);
        if (!$user_data) continue;
        
        $x_data = isset($batch_xprofile[$user_id]) ? $batch_xprofile[$user_id] : [];
        
        // Age calculation
        $age = '';
        $birth_date = isset($x_data['Data urodzenia']) ? $x_data['Data urodzenia'] : '';
        $hide_age = get_user_meta($user_id, 'sk_hide_age', true) === '1';
        
        if ($birth_date && !$hide_age) {
            $age = date_diff(date_create($birth_date), date_create('today'))->y;
        }
        
        $bio = isset($x_data['O mnie']) ? $x_data['O mnie'] : '';
        $faith_val = isset($x_data['Podejście do wiary']) ? $x_data['Podejście do wiary'] : (isset($x_data['Wiara']) ? $x_data['Wiara'] : null);
        $politics_val = isset($x_data['Poglądy Polityczne']) ? $x_data['Poglądy Polityczne'] : null;
        $work_val = isset($x_data['Styl Pracy']) ? $x_data['Styl Pracy'] : (isset($x_data['Styl pracy']) ? $x_data['Styl pracy'] : null);
        $diet_val = isset($x_data['Styl jedzenia']) ? $x_data['Styl jedzenia'] : (isset($x_data['Dieta']) ? $x_data['Dieta'] : null);
        $zodiac_val = isset($x_data['Znak zodiaku']) ? $x_data['Znak zodiaku'] : (isset($x_data['Znak Zodiaku']) ? $x_data['Znak Zodiaku'] : null);
        
        // Check for existing thread with this user
        $thread_id = 0;
        if (class_exists('BP_Messages_Thread') || function_exists('messages_get_message_thread_id')) {
            global $wpdb;
            $bp = buddypress();
            if (isset($bp->messages->table_name_recipients)) {
                $thread_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT r1.thread_id FROM {$bp->messages->table_name_recipients} r1
                     INNER JOIN {$bp->messages->table_name_recipients} r2 ON r1.thread_id = r2.thread_id
                     WHERE r1.user_id = %d AND r2.user_id = %d AND r1.user_id != r2.user_id
                     ORDER BY r1.thread_id DESC LIMIT 1",
                    $current_user_id, $user_id
                ));
            }
        }
        
        $avatar_url = '';
        if (isset($batch_avatars[$user_id])) {
            $avatar_url = $batch_avatars[$user_id]['large'] ?: $batch_avatars[$user_id]['full'] ?: '';
        }
        
        // Robust check: user_avatar_id meta
        if (!$avatar_url) {
            $manual_avatar_id = get_user_meta($user_id, 'user_avatar_id', true);
            if ($manual_avatar_id) {
                $avatar_url = wp_get_attachment_image_url($manual_avatar_id, 'full');
            }
        }

        if (!$avatar_url) {
            $avatar_url = bp_core_fetch_avatar(['item_id' => $user_id, 'type' => 'full', 'html' => false]);
        }
        
        $results[] = [
            'id' => $user_id,
            'name' => $user_data->display_name,
            'login' => $user_data->user_login,
            'mention_name' => $user_data->user_nicename,
            'avatar' => $avatar_url,
            'avatar_urls' => [
                'full' => $avatar_url,
                'thumb' => $avatar_url
            ],
            'hires_avatar' => [
                'full' => $avatar_url,
                'large' => $avatar_url,
                'thumb' => $avatar_url
            ],
            'age' => $age,
            'hide_age' => $hide_age,
            'bio' => $bio ? esc_html(wp_trim_words($bio, 15, '...')) : '',
            'faith' => $faith_val,
            'politics' => $politics_val,
            'work' => $work_val,
            'diet' => $diet_val,
            'zodiac_sign' => $zodiac_val,
            'last_activity' => sk_format_activity_status(isset($batch_activity[$user_id]) ? $batch_activity[$user_id] : null, current_user_can('manage_options')),
            'match_percentage' => sk_get_bp_match_percentage($current_user_id, $user_id),
            'thread_id' => intval($thread_id),
        ];
    }
    
    return rest_ensure_response($results);
}

// ========================================
// Get Users I Liked Endpoint
// ========================================
add_action('rest_api_init', function () {
    register_rest_route('sk/v1', '/liked', [
        'methods' => 'GET',
        'callback' => 'sk_get_liked_users_endpoint',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
});

/**
 * Get users that the current user has liked
 */
function sk_get_liked_users_endpoint($request) {
    $current_user_id = get_current_user_id();
    
    if (!$current_user_id) {
        return new WP_Error('not_logged_in', 'User must be logged in', ['status' => 401]);
    }
    
    // Get users I liked
    $my_likes = get_user_meta($current_user_id, 'sk_user_likes', true) ?: [];
    
    // Filter out users I blocked or who blocked me
    $my_blocked = get_user_meta($current_user_id, 'sk_blocked_users', true) ?: [];
    if (!empty($my_likes)) {
        $my_likes = array_filter($my_likes, function($uid) use ($current_user_id, $my_blocked) {
            if (in_array($uid, $my_blocked)) return false;
            $their_blocked = get_user_meta($uid, 'sk_blocked_users', true) ?: [];
            if (in_array($current_user_id, $their_blocked)) return false;
            
            // Shadow Ban
            if (get_user_meta($uid, 'sk_is_hidden', true) === '1') return false;
            
            return true;
        });
    }

    if (empty($my_likes)) {
        return rest_ensure_response([]);
    }

    // BATCH FETCH ALL DATA
    $batch_xprofile = sk_get_batch_xprofile_data($my_likes);
    $batch_activity = sk_get_batch_last_activity($my_likes);
    $batch_avatars = sk_get_batch_hires_avatars($my_likes);

    $results = [];
    foreach ($my_likes as $user_id) {
        $user_data = get_userdata($user_id);
        if (!$user_data) continue;
        
        $x_data = isset($batch_xprofile[$user_id]) ? $batch_xprofile[$user_id] : [];
        
        // Age calculation
        $age = '';
        $birth_date = isset($x_data['Data urodzenia']) ? $x_data['Data urodzenia'] : '';
        $hide_age = get_user_meta($user_id, 'sk_hide_age', true) === '1';

        if ($birth_date && !$hide_age) {
            $age = date_diff(date_create($birth_date), date_create('today'))->y;
        }
        
        $bio = isset($x_data['O mnie']) ? $x_data['O mnie'] : '';
        $faith_val = isset($x_data['Podejście do wiary']) ? $x_data['Podejście do wiary'] : (isset($x_data['Wiara']) ? $x_data['Wiara'] : null);
        $politics_val = isset($x_data['Poglądy Polityczne']) ? $x_data['Poglądy Polityczne'] : null;
        $work_val = isset($x_data['Styl Pracy']) ? $x_data['Styl Pracy'] : (isset($x_data['Styl pracy']) ? $x_data['Styl pracy'] : null);
        $diet_val = isset($x_data['Styl jedzenia']) ? $x_data['Styl jedzenia'] : (isset($x_data['Dieta']) ? $x_data['Dieta'] : null);
        $zodiac_val = get_zodiac_sign($birth_date);
        
        $avatar_url = '';
        if (isset($batch_avatars[$user_id])) {
            $avatar_url = $batch_avatars[$user_id]['large'] ?: $batch_avatars[$user_id]['full'] ?: '';
        }

        // Robust check: user_avatar_id meta
        if (!$avatar_url) {
            $manual_avatar_id = get_user_meta($user_id, 'user_avatar_id', true);
            if ($manual_avatar_id) {
                $avatar_url = wp_get_attachment_image_url($manual_avatar_id, 'full');
            }
        }

        if (!$avatar_url) {
            $avatar_url = bp_core_fetch_avatar(['item_id' => $user_id, 'type' => 'full', 'html' => false]);
        }
        
        $results[] = [
            'id' => $user_id,
            'name' => $user_data->display_name,
            'mention_name' => $user_data->user_nicename,
            'avatar_urls' => [
                'full' => $avatar_url
            ],
            'hires_avatar' => isset($batch_avatars[$user_id]) ? $batch_avatars[$user_id] : [],
            'age' => $age,
            'hide_age' => $hide_age,
            'bio' => $bio ? esc_html(wp_trim_words($bio, 15, '...')) : '',
            'faith' => $faith_val,
            'politics' => $politics_val,
            'work' => $work_val,
            'diet' => $diet_val,
            'zodiac_sign' => $zodiac_val,
            'last_activity' => sk_format_activity_status(isset($batch_activity[$user_id]) ? $batch_activity[$user_id] : null, current_user_can('manage_options')),
            'match_percentage' => sk_get_bp_match_percentage($current_user_id, $user_id),
        ];
    }
    
    return rest_ensure_response($results);
}
/**
 * Get all user IDs marked as hidden (Shadow Ban)
 */
function sk_get_hidden_user_ids() {
    global $wpdb;
    $hidden_ids = $wpdb->get_col("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'sk_is_hidden' AND meta_value = '1'");
    // error_log('Checked hidden users: ' . print_r($hidden_ids, true));
    return array_map('intval', $hidden_ids ?: []);
}

/**
 * Shadow Ban: Add checkbox to admin user profile
 */
function sk_add_shadow_ban_admin_fields($user) {
    if (!current_user_can('manage_options')) return;
    
    $is_hidden = get_user_meta($user->ID, 'sk_is_hidden', true) === '1';
    ?>
    <h3>Shadow Ban (Antigravity)</h3>
    <table class="form-table">
        <tr>
            <th><label for="sk_is_hidden">Ukryty użytkownik</label></th>
            <td>
                <input type="checkbox" name="sk_is_hidden" id="sk_is_hidden" value="1" <?php checked($is_hidden); ?> />
                <span class="description">Jeśli zaznaczone, użytkownik nie będzie widoczny w wyszukiwarce, liście członków oraz nie będzie można do niego pisać.</span>
            </td>
        </tr>
    </table>
    <?php
}
add_action('show_user_profile', 'sk_add_shadow_ban_admin_fields');
add_action('edit_user_profile', 'sk_add_shadow_ban_admin_fields');

/**
 * Shadow Ban: Save admin user profile field
 */
function sk_save_shadow_ban_admin_fields($user_id) {
    if (!current_user_can('manage_options')) return;
    
    if (isset($_POST['sk_is_hidden'])) {
        update_user_meta($user_id, 'sk_is_hidden', '1');
    } else {
        delete_user_meta($user_id, 'sk_is_hidden');
    }
}
add_action('personal_options_update', 'sk_save_shadow_ban_admin_fields');
add_action('edit_user_profile_update', 'sk_save_shadow_ban_admin_fields');

// Custom Members Endpoint Callback
function sk_get_members_endpoint($request) {
    $current_user_id = get_current_user_id();
    
    // Pagination params
    $page = $request->get_param('page') ?: 1;
    $per_page = $request->get_param('per_page') ?: 20;
    $search = $request->get_param('search');
    $include = $request->get_param('include');
    
    // Filter params
    $min_age = $request->get_param('min_age');
    $max_age = $request->get_param('max_age');
    $faith = $request->get_param('faith'); 
    $politics = $request->get_param('politics');
    $work = $request->get_param('work');
    $diet = $request->get_param('diet');

    $args = [
        'page' => $page,
        'per_page' => $per_page,
        'search_terms' => $search,
        'type' => 'alphabetical',
        'populate_extras' => false,
    ];

    if ($include) {
        $args['include'] = array_filter(array_map('intval', explode(',', $include)));
    }

    $exclude_ids = [$current_user_id, 1];
    
    // Only exclude blocked/skipped users if we are NOT explicitly asking for them (e.g. via 'include' param for Skipped/Blocked list)
    if (empty($include)) {
        $blocked_users = get_user_meta($current_user_id, 'sk_blocked_users', true);
        if (is_array($blocked_users)) {
            $exclude_ids = array_merge($exclude_ids, $blocked_users);
        }

        $skipped_users = get_user_meta($current_user_id, 'sk_skipped_users', true);
        if (is_array($skipped_users)) {
            $exclude_ids = array_merge($exclude_ids, $skipped_users);
        }
    }
    
    // Shadow Ban: Exclude hidden users
    $hidden_users = sk_get_hidden_user_ids();
    if (!empty($hidden_users)) {
        $exclude_ids = array_merge($exclude_ids, $hidden_users);
    }

    $args['exclude'] = array_unique($exclude_ids);

    $xprofile_query = ['relation' => 'AND'];

    // --- GENDER & CUSTOM FILTERS (Only apply if NOT fetching specific IDs) ---
    if (empty($include)) {
        // 1. Get current user's "Looking For" preference (Field ID 338)
        $looking_for_raw = xprofile_get_field_data(338, $current_user_id);
        
        // Normalize to string (it's often an array)
        $looking_for_str = is_array($looking_for_raw) ? implode(', ', $looking_for_raw) : (string)$looking_for_raw;
        
        // 2. Determine target gender
        $target_gender = null;
        
        if (stripos($looking_for_str, 'Wszyscy') !== false) {
            $target_gender = null; // Show all
        } elseif (stripos($looking_for_str, 'Kobiety') !== false) {
            $target_gender = 'Kobieta';
        } elseif (stripos($looking_for_str, 'Mężczyzn') !== false) { // Matches Mężczyzna, Mężczyzny, Mężczyźni
            $target_gender = 'Mężczyzna';
        }
        
        // 3. Apply Gender Filter
        if ($target_gender) {
            $xprofile_query[] = [
                'field'   => 129, // Gender Field ID (Empaths)
                'value'   => $target_gender,
                'compare' => 'LIKE'
            ];
        }

        // 4. Custom Filters (Faith, Politics, etc.)
        $field_id_map = ['faith' => 346, 'politics' => 351, 'work' => 356, 'diet' => 362];
        foreach ($field_id_map as $param => $id) {
            $val = $request->get_param($param);
            if ($val) {
                $xprofile_query[] = ['field' => $id, 'value' => $val, 'compare' => '='];
            }
        }
    }

    if (count($xprofile_query) > 1) {
        $args['xprofile_query'] = $xprofile_query;
    }

    if (bp_has_members($args)) {
        global $members_template;
        $user_ids = [];
        foreach ($members_template->members as $user) {
            $user_ids[] = $user->ID;
        }

        // BATCH FETCH EVERYTHING
        $batch_xprofile = sk_get_batch_xprofile_data($user_ids);
        $batch_activity = sk_get_batch_last_activity($user_ids);
        $batch_avatars = sk_get_batch_hires_avatars($user_ids);

        $results = [];
        foreach ($members_template->members as $user) {
            $u_id = $user->ID;
            $u_data = get_userdata($u_id);
            if (!$u_data) continue;

            $x_data = isset($batch_xprofile[$u_id]) ? $batch_xprofile[$u_id] : [];
            
            // Age calculation
            $birth_date = isset($x_data[107]) ? $x_data[107] : (isset($x_data['Data urodzenia']) ? $x_data['Data urodzenia'] : '');
            $age = '';
            $hide_age = get_user_meta($u_id, 'sk_hide_age', true) === '1';

            if ($birth_date) {
                $calc_age = date_diff(date_create($birth_date), date_create('today'))->y;
                // Only apply age filters if we are NOT explicitly asking for specific IDs (e.g. for Deleted/Blocked list)
                if (empty($include)) {
                    if ($min_age && $calc_age < $min_age) continue;
                    if ($max_age && $calc_age > $max_age) continue;
                }
                if (!$hide_age) {
                    $age = $calc_age;
                }
            }

            $bio = isset($x_data[343]) ? $x_data[343] : (isset($x_data[367]) ? $x_data[367] : (isset($x_data['O mnie']) ? $x_data['O mnie'] : (isset($x_data['About Me']) ? $x_data['About Me'] : (isset($x_data['Introduction']) ? $x_data['Introduction'] : ''))));
            
            // Prioritize IDs for Empaths app with PM fallbacks and English Names
            $faith_val = isset($x_data[346]) ? $x_data[346] : (isset($x_data[133]) ? $x_data[133] : (isset($x_data['Podejście do wiary']) ? $x_data['Podejście do wiary'] : (isset($x_data['Wiara']) ? $x_data['Wiara'] : (isset($x_data['Faith']) ? $x_data['Faith'] : (isset($x_data['Religion']) ? $x_data['Religion'] : null)))));
            if (empty($faith_val)) $faith_val = bp_get_profile_field_data(['field' => 346, 'user_id' => $u_id]);
            
            $politics_val = isset($x_data[351]) ? $x_data[351] : (isset($x_data[215]) ? $x_data[215] : (isset($x_data['Poglądy Polityczne']) ? $x_data['Poglądy Polityczne'] : (isset($x_data['Politics']) ? $x_data['Politics'] : (isset($x_data['Political Views']) ? $x_data['Political Views'] : null))));
            if (empty($politics_val)) $politics_val = bp_get_profile_field_data(['field' => 351, 'user_id' => $u_id]);
            
            $work_val = isset($x_data[356]) ? $x_data[356] : (isset($x_data[108]) ? $x_data[108] : (isset($x_data['Styl Pracy']) ? $x_data['Styl Pracy'] : (isset($x_data['Styl pracy']) ? $x_data['Styl pracy'] : (isset($x_data['Work']) ? $x_data['Work'] : (isset($x_data['Employment']) ? $x_data['Employment'] : null)))));
            if (empty($work_val)) $work_val = bp_get_profile_field_data(['field' => 356, 'user_id' => $u_id]);
            
            $diet_val = isset($x_data[362]) ? $x_data[362] : (isset($x_data[334]) ? $x_data[334] : (isset($x_data['Styl jedzenia']) ? $x_data['Styl jedzenia'] : (isset($x_data['Dieta']) ? $x_data['Dieta'] : (isset($x_data['Diet']) ? $x_data['Diet'] : (isset($x_data['Eating Style']) ? $x_data['Eating Style'] : null)))));
            if (empty($diet_val)) $diet_val = bp_get_profile_field_data(['field' => 362, 'user_id' => $u_id]);

            // DEBUG LOGGING
            if ($u_id == $current_user_id) { // Log only for the current user to avoid spam
                error_log("sk_get_members_endpoint: User $u_id - Faith: " . print_r($faith_val, true));
                error_log("sk_get_members_endpoint: User $u_id - Politics: " . print_r($politics_val, true));
                error_log("sk_get_members_endpoint: User $u_id - Work: " . print_r($work_val, true));
                error_log("sk_get_members_endpoint: User $u_id - Diet: " . print_r($diet_val, true));
            }
            
            $personality_val = isset($x_data[6721]) ? $x_data[6721] : (isset($x_data['Personality']) ? $x_data['Personality'] : null);
            $zodiac_val = isset($x_data[303]) ? $x_data[303] : get_zodiac_sign($birth_date);

            $hide_age = (bool) get_user_meta($u_id, 'sk_hide_age', true);

            $avatar_url = '';
            if (isset($batch_avatars[$u_id])) {
                $avatar_url = $batch_avatars[$u_id]['large'] ?: $batch_avatars[$u_id]['full'] ?: '';
            }
            
            // Robust check: user_avatar_id meta
            if (!$avatar_url) {
                $manual_avatar_id = get_user_meta($u_id, 'user_avatar_id', true);
                if ($manual_avatar_id) {
                    $avatar_url = wp_get_attachment_image_url($manual_avatar_id, 'full');
                }
            }

            if (!$avatar_url) {
                $avatar_url = bp_core_fetch_avatar(['item_id' => $u_id, 'type' => 'full', 'html' => false]);
            }

            // Construct minimal mock-up of xprofile structure for mapUserProfile
            $xprofile_mock = [
                'groups' => [
                    [
                        'id' => 1,
                        'name' => 'Details',
                        'fields' => []
                    ]
                ]
            ];
            foreach ($x_data as $fid => $fval) {
                if (is_numeric($fid)) {
                    // Filter out birth date (field 107) if age is hidden
                    if ($fid == 107 && $hide_age && $u_id != $current_user_id) {
                        continue;
                    }
                    $xprofile_mock['groups'][0]['fields'][] = [
                        'id' => $fid,
                        'value' => ['raw' => $fval, 'rendered' => $fval]
                    ];
                }
            }

            // Explicitly ensure standard Empaths fields are present in the mock using the resolved values
            // This ensures mapUserProfile finds them even if they came from fallback IDs or Names in DB
            if ($faith_val) {
                $xprofile_mock['groups'][0]['fields'][] = [
                    'id' => 346, 
                    'name' => 'Faith', 
                    'value' => ['raw' => $faith_val, 'rendered' => $faith_val]
                ];
            }
            if ($politics_val) {
                $xprofile_mock['groups'][0]['fields'][] = [
                    'id' => 351, 
                    'name' => 'Politics', 
                    'value' => ['raw' => $politics_val, 'rendered' => $politics_val]
                ];
            }
            if ($work_val) {
                $xprofile_mock['groups'][0]['fields'][] = [
                    'id' => 356, 
                    'name' => 'Work', 
                    'value' => ['raw' => $work_val, 'rendered' => $work_val]
                ];
            }
            if ($diet_val) {
                $xprofile_mock['groups'][0]['fields'][] = [
                    'id' => 362, 
                    'name' => 'Diet', 
                    'value' => ['raw' => $diet_val, 'rendered' => $diet_val]
                ];
            }

            $results[] = [
                'id' => $u_id,
                'name' => $u_data->display_name,
                'mention_name' => $u_data->user_nicename,
                'link' => bp_members_get_user_url($u_id),
                'avatar' => $avatar_url,
                'avatar_urls' => [
                    'full' => $avatar_url
                ],
                'hires_avatar' => isset($batch_avatars[$u_id]) ? $batch_avatars[$u_id] : [],
                'age' => $age,
                'hide_age' => $hide_age,
                'bio' => $bio ? esc_html(wp_trim_words($bio, 15, '...')) : '',
                'faith' => $faith_val,
                'politics' => $politics_val,
                'work' => $work_val,
                'diet' => $diet_val,
                'zodiac_sign' => $zodiac_val,
                'last_activity' => sk_format_activity_status(isset($batch_activity[$u_id]) ? $batch_activity[$u_id] : null, current_user_can('manage_options')),
                'numerology' => isset($x_data[6722]) ? $x_data[6722] : ($birth_date ? sk_calculate_life_path_number($birth_date) : null),
                'zodiac' => $zodiac_val,
                'match_percentage' => sk_get_bp_match_percentage($current_user_id, $u_id),
                'xprofile' => $xprofile_mock
            ];

        }
        return rest_ensure_response($results);
    }
    return rest_ensure_response([]);
}

// ========================================
// Get Users Who Liked Me Endpoint
// ========================================
add_action('rest_api_init', function () {
    register_rest_route('sk/v1', '/likes-me', [
        'methods' => 'GET',
        'callback' => 'sk_get_likes_me_endpoint',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
});

// ========================================
// Update XProfile Field Endpoint (for Mobile App)
// ========================================
add_action('rest_api_init', function () {
    register_rest_route('sk/v1', '/xprofile/update', [
        'methods' => 'POST',
        'callback' => 'sk_update_xprofile_field',
        'permission_callback' => function () {
            return is_user_logged_in();
        }
    ]);

    // DEBUG ENDPOINT
    register_rest_route('sk/v1', '/debug-bubbles', [
        'methods' => 'GET',
        'callback' => 'sk_get_debug_bubbles',
        'permission_callback' => function () {
            return is_user_logged_in();
        }
    ]);

    // DEBUG ENDPOINT - Relationship Inspector
    register_rest_route('sk/v1', '/debug-relationships', [
        'methods' => 'GET',
        'callback' => 'sk_debug_relationships',
        'permission_callback' => function () {
            return is_user_logged_in();
        }
    ]);
});

function sk_debug_relationships($request) {
    $current_user_id = get_current_user_id();
    if (!$current_user_id) {
        return new WP_Error('not_logged_in', 'User must be logged in', ['status' => 401]);
    }

    // Get relationship data
    $my_likes = get_user_meta($current_user_id, 'sk_user_likes', true) ?: [];
    $liked_by = get_user_meta($current_user_id, 'sk_liked_by_users', true) ?: [];
    $my_blocked = get_user_meta($current_user_id, 'sk_blocked_users', true) ?: [];

    // Get all subscriber users for reference
    $all_users = get_users(['role' => 'subscriber', 'fields' => ['ID', 'display_name', 'user_email']]);
    $user_list = [];
    foreach ($all_users as $u) {
        $their_blocked = get_user_meta($u->ID, 'sk_blocked_users', true) ?: [];
        $user_list[] = [
            'id' => (int)$u->ID,
            'name' => $u->display_name,
            'email' => $u->user_email,
            'blocked_me' => in_array($current_user_id, $their_blocked),
        ];
    }

    // BuddyPress friendship check
    $bp_friends = [];
    if (function_exists('friends_get_friend_user_ids')) {
        $bp_friends = friends_get_friend_user_ids($current_user_id);
    }

    return rest_ensure_response([
        'current_user_id' => $current_user_id,
        'my_likes' => $my_likes,
        'liked_by_me_count' => count($my_likes),
        'liked_by' => $liked_by,
        'liked_me_count' => count($liked_by),
        'my_blocked' => $my_blocked,
        'bp_friends' => $bp_friends,
        'all_subscribers' => $user_list,
    ]);
}

function sk_get_debug_bubbles() {
    $user_id = get_current_user_id();
    if (!$user_id) return ['error' => 'Not logged in'];
    
    global $wpdb;
    $fields = [346, 351, 356, 362]; 
    $debug = ['user_id' => $user_id];
    
    foreach ($fields as $fid) {
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT value FROM {$wpdb->prefix}bp_xprofile_data WHERE field_id = %d AND user_id = %d", 
            $fid, $user_id
        ));
        $debug[$fid] = [
            'raw_sql' => $raw,
            'bp_get' => xprofile_get_field_data($fid, $user_id)
        ];
    }
    return $debug;
}


function sk_update_xprofile_field($request) {
    $user_id = get_current_user_id();
    
    if (!$user_id) {
        return new WP_Error('not_logged_in', 'Musisz być zalogowany', ['status' => 401]);
    }
    
    $field_id = $request->get_param('field_id');
    $value = $request->get_param('value');
    
    if (empty($field_id)) {
        return new WP_Error('missing_field_id', 'Brakuje ID pola', ['status' => 400]);
    }
    
    if (!function_exists('xprofile_set_field_data')) {
        return new WP_Error('xprofile_not_active', 'XProfile nie jest aktywne', ['status' => 500]);
    }
    
    $result = xprofile_set_field_data($field_id, $user_id, $value);
    
    if ($result) {
        $updated_value = xprofile_get_field_data($field_id, $user_id);
        return rest_ensure_response([
            'success' => true,
            'field_id' => $field_id,
            'value' => $updated_value,
            'message' => 'Pole zostało zaktualizowane'
        ]);
    } else {
        return new WP_Error('update_failed', 'Nie udało się zaktualizować pola', ['status' => 500]);
    }
}


/**
 * Get single member details with xprofile
 */
function sk_get_single_member_endpoint($request) {
    global $wpdb;
    $user_id = $request->get_param('id');
    
    if ($user_id === 'me') {
        $user_id = get_current_user_id();
    }
    
    $user = get_userdata($user_id);
    
    if (!$user) {
        return new WP_Error('user_not_found', 'User not found', ['status' => 404]);
    }

    // Shadow Ban check
    $is_hidden = get_user_meta($user_id, 'sk_is_hidden', true) === '1';
    $is_admin = current_user_can('manage_options');
    if ($is_hidden && !$is_admin && $user_id != get_current_user_id()) {
        return new WP_Error('user_not_found', 'User not found', ['status' => 404]);
    }
    
    // Get full xprofile data using our batch helper (works for single too)
    // $batch_xprofile = sk_get_batch_xprofile_data([$user_id]);
    // $x_data = isset($batch_xprofile[$user_id]) ? $batch_xprofile[$user_id] : [];
    
    // Get avatar - Prioritize user_avatar_id if exists (Robust V15)
    $manual_avatar_id = get_user_meta($user_id, 'user_avatar_id', true);
    $avatar_full = false;
    $avatar_thumb = false;

    if ($manual_avatar_id) {
        $avatar_full = wp_get_attachment_image_url($manual_avatar_id, 'full');
        $avatar_thumb = wp_get_attachment_image_url($manual_avatar_id, 'thumbnail');
    }

    // Fallback do BuddyPress jeśli brak manualnego lub błąd pobierania URL
    if (!$avatar_full) {
        $avatar_full = bp_core_fetch_avatar(['item_id' => $user_id, 'type' => 'full', 'html' => false]);
    }
    if (!$avatar_thumb) {
        $avatar_thumb = bp_core_fetch_avatar(['item_id' => $user_id, 'type' => 'thumb', 'html' => false]);
    }
    
    // Get last activity
    $last_activity = bp_core_get_last_activity($user_id, "");

    

    $raw_groups = bp_xprofile_get_groups([
        'user_id' => $user_id,
        'fetch_fields' => true,
        'fetch_field_data' => true,
        'hide_empty_groups' => false,
        'hide_empty_fields' => false
    ]);
    

    $hide_age = get_user_meta($user_id, 'sk_hide_age', true) === '1';
    $is_own_profile = (get_current_user_id() == $user_id);

    $clean_groups = [];
    if (!empty($raw_groups)) {
        foreach ($raw_groups as $group) {
            $clean_fields = [];
            if (!empty($group->fields) && is_array($group->fields)) {
                foreach ($group->fields as $field) {
                    // Filter out birth date (field 107) if age is hidden
                    if ($field->id == 107 && $hide_age && !$is_own_profile) {
                        continue;
                    }

                    $field_value = xprofile_get_field_data($field->id, $user_id);
                    
                    if (is_array($field_value)) {
                        $field_value = implode(', ', $field_value);
                    }
                    
                    // Skip photo slots to avoid duplication with gallery
                    if (stripos($field->name, 'zdjęcie') !== false || stripos($field->name, 'photo') !== false) {
                        continue;
                    }


                    // Check if this is the user's own profile
                    $is_own_profile = (get_current_user_id() == $user_id);
                    
                    // Include field if it has a value OR if viewing own profile
                    if (!empty($field_value) || $is_own_profile) {
                        $field_type = $field->type;
                        $field_options = [];
                        
                        // Get options for select types
                        if (in_array($field_type, ['selectbox', 'multiselectbox', 'radio', 'checkbox'])) {
                            $field_obj = null;
                            if (method_exists($field, 'get_children')) {
                                $field_obj = $field;
                            } else {
                                $field_obj = new BP_XProfile_Field($field->id);
                            }

                            if ($field_obj) {
                                $children = $field_obj->get_children();
                                if (!empty($children)) {
                                    foreach ($children as $child) {
                                        $field_options[] = [
                                            'id' => $child->id,
                                            'name' => $child->name
                                        ];
                                    }
                                }
                            }
                        }

                        $clean_fields[] = [
                            'id' => $field->id,
                            'name' => $field->name,
                            'value' => !empty($field_value) ? strip_tags((string)$field_value) : '',
                            'type' => $field_type,
                            'options' => $field_options
                        ];
                    }
                }
            }
            
            $clean_groups[] = [
                'id' => $group->id,
                'name' => $group->name,
                'fields' => $clean_fields
            ];
        }
    }
    

    

    // Check for mutual match
    $current_user_id = get_current_user_id();
    $is_matched = false;
    if ($current_user_id && $current_user_id != $user_id) {
        $my_likes = get_user_meta($current_user_id, 'sk_user_likes', true) ?: [];
        $their_likes = get_user_meta($user_id, 'sk_user_likes', true) ?: [];
        
        if (is_array($my_likes) && is_array($their_likes)) {
            if (in_array($user_id, array_map('intval', $my_likes)) && 
                in_array($current_user_id, array_map('intval', $their_likes))) {
                $is_matched = true;
            }
        }
    }

    // Flatten key properties for easy access (badges, bio, etc.)
    $flattened = [];
    foreach ($clean_groups as $group) {
        foreach ($group['fields'] as $field) {
            $f_id = (int)$field['id'];
            $f_name = (string)$field['name'];
            $f_val = $field['value'];

            // Map by IDs and Names for robustness
            if ($f_id == 346 || $f_id == 133 || stripos($f_name, 'wiara') !== false || stripos($f_name, 'faith') !== false) $flattened['faith'] = $f_val;
            if ($f_id == 351 || $f_id == 215 || stripos($f_name, 'polityka') !== false || stripos($f_name, 'politics') !== false) $flattened['politics'] = $f_val;
            if ($f_id == 356 || $f_id == 108 || stripos($f_name, 'praca') !== false || stripos($f_name, 'work') !== false) $flattened['work'] = $f_val;
            if ($f_id == 362 || $f_id == 334 || stripos($f_name, 'diet') !== false || stripos($f_name, 'jedzeni') !== false) $flattened['diet'] = $f_val;
            if ($f_id == 303 || stripos($f_name, 'zodiak') !== false || stripos($f_name, 'zodiac') !== false) $flattened['zodiac_sign'] = $f_val;
            if ($f_id == 343 || $f_id == 367 || stripos($f_name, 'o mnie') !== false || stripos($f_name, 'bio') !== false) $flattened['bio'] = $f_val;
            
            // Age calculation if field 107 is found
            if ($f_id == 107) {
                if (!$hide_age || $is_own_profile) {
                    $flattened['age'] = date_diff(date_create($f_val), date_create('today'))->y;
                } else {
                    $flattened['age'] = '';
                }
            }
        }
    }

    // Simplify the response
    return rest_ensure_response(array_merge($flattened, [
        'id' => $user->ID,
        'name' => $user->display_name,
        'hide_age' => $hide_age,
        'user_login' => $user->user_login,
        'roles' => $user->roles,
        'link' => bp_core_get_user_domain($user_id),
        'avatar' => $avatar_full,
        'avatar_urls' => [
            'full' => $avatar_full ? $avatar_full . '?t=' . time() : null,
            'thumb' => $avatar_thumb ? $avatar_thumb . '?t=' . time() : null
        ],
        'hires_avatar' => [
            'full' => $avatar_full ? $avatar_full . '?t=' . time() : null,
            'large' => $avatar_full ? $avatar_full . '?t=' . time() : null,
            'thumb' => $avatar_thumb ? $avatar_thumb . '?t=' . time() : null
        ],
        'xprofile' => [
            'groups' => $clean_groups
        ],
        'last_activity' => sk_format_activity_status($last_activity, current_user_can('manage_options')),
        'onboarding_complete' => (bool) get_user_meta($user_id, 'app_onboarding_complete', true),
        'is_matched' => $is_matched,
        'chat_allowed_by_me' => (function($current_user_id, $target_user_id) {
            if (!$current_user_id) return false;
            $allowed_ids = get_user_meta($current_user_id, 'sk_allowed_chat_ids', true) ?: [];
            return is_array($allowed_ids) && in_array((int)$target_user_id, array_map('intval', $allowed_ids));
        })(get_current_user_id(), $user_id),
        'gallery' => (function($user_id) {
            $photo_ids = get_user_meta($user_id, 'user_profile_photos_ids', true);
            if (!is_array($photo_ids)) return [];
            $photo_ids = array_unique($photo_ids); // Deduplicate IDs
            $gallery = [];
            $seen_urls = [];
            
            // Generate a server-side timestamp for cache busting
            $cb = '?t=' . time();

            foreach ($photo_ids as $pid) {
                if ($pid) {
                    $url = wp_get_attachment_image_url($pid, 'medium_large');
                    $full_url = wp_get_attachment_image_url($pid, 'full');
                    
                    if ($url && !in_array($url, $seen_urls)) {
                        $seen_urls[] = $url;
                        $gallery[] = [
                            'id' => $pid,
                            'url' => $url . $cb,
                            'full' => $full_url ? $full_url . $cb : $url . $cb
                        ];
                    }
                }
            }
            return $gallery;
        })($user_id),
        'unread_notifications_count' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . (isset(buddypress()->notifications->table_name) ? buddypress()->notifications->table_name : $wpdb->prefix . 'bp_notifications') . " WHERE user_id = %d AND is_new = 1",
            $user_id
        ))
    ]));
}

/**
 * Update user preference (meta)
 */
function sk_update_preference_endpoint($request) {
    $user_id = get_current_user_id();
    $key = $request->get_param('key');
    $value = $request->get_param('value');

    if (empty($key)) {
        return new WP_Error('missing_key', 'Missing preference key', ['status' => 400]);
    }

    // List of allowed keys to prevent arbitrary meta updates
    $allowed_keys = ['sk_hide_age'];
    if (!in_array($key, $allowed_keys)) {
        return new WP_Error('invalid_key', 'Invalid preference key', ['status' => 400]);
    }

    // sanitize value
    $final_value = $value;
    if ($key === 'sk_hide_age') {
        $final_value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
    }

    update_user_meta($user_id, $key, $final_value);

    return rest_ensure_response([
        'success' => true,
        'key' => $key,
        'value' => $final_value === '1'
    ]);
}

/**
 * Get users who have liked the current user
 */
function sk_get_likes_me_endpoint($request) {
    $current_user_id = get_current_user_id();
    
    if (!$current_user_id) {
        return new WP_Error('not_logged_in', 'User must be logged in', ['status' => 401]);
    }
    
    // Get users who liked me
    $liked_me = get_user_meta($current_user_id, 'sk_liked_by_users', true) ?: [];
    
    // Filter out users I blocked or who blocked me
    $my_blocked = get_user_meta($current_user_id, 'sk_blocked_users', true) ?: [];
    if (!empty($liked_me)) {
        $liked_me = array_filter($liked_me, function($uid) use ($current_user_id, $my_blocked) {
            if (in_array($uid, $my_blocked)) return false;
            $their_blocked = get_user_meta($uid, 'sk_blocked_users', true) ?: [];
            if (in_array($current_user_id, $their_blocked)) return false;
            
            // Shadow Ban
            if (get_user_meta($uid, 'sk_is_hidden', true) === '1') return false;
            
            return true;
        });
    }

    if (empty($liked_me)) {
        return rest_ensure_response([]);
    }

    // BATCH FETCH ALL DATA
    $batch_xprofile = sk_get_batch_xprofile_data($liked_me);
    $batch_activity = sk_get_batch_last_activity($liked_me);
    $batch_avatars = sk_get_batch_hires_avatars($liked_me);

    $results = [];
    foreach ($liked_me as $user_id) {
        $user_data = get_userdata($user_id);
        if (!$user_data) continue;
        
        $x_data = isset($batch_xprofile[$user_id]) ? $batch_xprofile[$user_id] : [];
        
        // Age calculation
        $age = '';
        $birth_date = isset($x_data['Data urodzenia']) ? $x_data['Data urodzenia'] : '';
        $hide_age = get_user_meta($user_id, 'sk_hide_age', true) === '1';

        if ($birth_date && !$hide_age) {
            $age = date_diff(date_create($birth_date), date_create('today'))->y;
        }
        
        $bio = isset($x_data['O mnie']) ? $x_data['O mnie'] : '';
        $faith_val = isset($x_data['Podejście do wiary']) ? $x_data['Podejście do wiary'] : (isset($x_data['Wiara']) ? $x_data['Wiara'] : null);
        $politics_val = isset($x_data['Poglądy Polityczne']) ? $x_data['Poglądy Polityczne'] : null;
        $work_val = isset($x_data['Styl Pracy']) ? $x_data['Styl Pracy'] : (isset($x_data['Styl pracy']) ? $x_data['Styl pracy'] : null);
        $diet_val = isset($x_data['Styl jedzenia']) ? $x_data['Styl jedzenia'] : (isset($x_data['Dieta']) ? $x_data['Dieta'] : null);
        $zodiac_val = isset($x_data['Znak zodiaku']) ? $x_data['Znak zodiaku'] : (isset($x_data['Znak Zodiaku']) ? $x_data['Znak Zodiaku'] : null);
        
        $avatar_url = '';
        if (isset($batch_avatars[$user_id])) {
            $avatar_url = $batch_avatars[$user_id]['large'] ?: $batch_avatars[$user_id]['full'] ?: '';
        }
        if (!$avatar_url) {
            $avatar_url = bp_core_fetch_avatar(['item_id' => $user_id, 'type' => 'full', 'html' => false]);
        }
        
        $results[] = [
            'id' => $user_id,
            'name' => $user_data->display_name,
            'mention_name' => $user_data->user_nicename,
            'avatar_urls' => [
                'full' => $avatar_url
            ],
            'hires_avatar' => isset($batch_avatars[$user_id]) ? $batch_avatars[$user_id] : [],
            'age' => $age,
            'hide_age' => $hide_age,
            'bio' => $bio ? esc_html(wp_trim_words($bio, 15, '...')) : '',
            'faith' => $faith_val,
            'politics' => $politics_val,
            'work' => $work_val,
            'diet' => $diet_val,
            'zodiac_sign' => $zodiac_val,
            'last_activity' => sk_format_activity_status(isset($batch_activity[$user_id]) ? $batch_activity[$user_id] : null, current_user_can('manage_options')),
            'match_percentage' => sk_get_bp_match_percentage($current_user_id, $user_id),
        ];
    }
    
    return rest_ensure_response($results);
}

// ========================================
// Custom Like/Unlike Endpoint
// ========================================
add_action('rest_api_init', function () {
    register_rest_route('sk/v1', '/like', [
        'methods' => 'POST',
        'callback' => 'sk_toggle_like_endpoint',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);

    // GET Blocked Users List (for mobile sync)
    register_rest_route('sk/v1', '/blocked', [
        'methods' => 'GET',
        'callback' => function() {
            $current_user_id = get_current_user_id();
            $blocked = get_user_meta($current_user_id, 'sk_blocked_users', true) ?: [];
            if (!is_array($blocked)) $blocked = [];

            // ALSO Fetch Skipped Users (from Thread Deletion)
            $skipped = get_user_meta($current_user_id, 'sk_skipped_users', true) ?: [];
            if (!is_array($skipped)) $skipped = [];

            // Merge and Unique
            $merged = array_unique(array_merge($blocked, $skipped));
            
            return rest_ensure_response(array_values(array_map('intval', $merged)));
        },
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);

    // BLOCK User Endpoint
    register_rest_route('sk/v1', '/block', [
        'methods' => 'POST',
        'callback' => 'sk_block_user_endpoint',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);

    // ALLOW CHAT Endpoint (for women to allow men)
    register_rest_route('sk/v1', '/allow-chat', [
        'methods' => 'POST',
        'callback' => 'sk_allow_chat_endpoint',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
});

/**
 * Block/Unblock a user
 */
function sk_block_user_endpoint($request) {
    $current_user_id = get_current_user_id();
    $target_user_id = intval($request->get_param('user_id'));
    $action = $request->get_param('action') ?: 'block'; // block, unblock, toggle

    if (!$current_user_id) {
        return new WP_Error('not_logged_in', 'User must be logged in', ['status' => 401]);
    }
    
    if (!$target_user_id || $current_user_id == $target_user_id) {
        return new WP_Error('invalid_target', 'Invalid target user ID', ['status' => 400]);
    }

    $blocked_users = get_user_meta($current_user_id, 'sk_blocked_users', true);
    if (!is_array($blocked_users)) {
        $blocked_users = [];
    }

    // Ensure all IDs are integers
    $blocked_users = array_map('intval', $blocked_users);

    $is_blocked = in_array($target_user_id, $blocked_users);
    $new_status = $is_blocked ? 'blocked' : 'unblocked';

    if ($action === 'block' && !$is_blocked) {
        $blocked_users[] = $target_user_id;
        $new_status = 'blocked';
    } elseif ($action === 'unblock') {
        // ROBUST REMOVAL: Remove from both Blocked and Skipped lists
        $blocked_users = get_user_meta($current_user_id, 'sk_blocked_users', true) ?: [];
        if (!is_array($blocked_users)) $blocked_users = [];
        
        $skipped_users = get_user_meta($current_user_id, 'sk_skipped_users', true) ?: [];
        if (!is_array($skipped_users)) $skipped_users = [];

        // Cast all to int for comparison
        $target_user_id = (int)$target_user_id;
        $blocked_users = array_map('intval', $blocked_users);
        $skipped_users = array_map('intval', $skipped_users);

        $new_blocked = array_values(array_diff($blocked_users, [$target_user_id]));
        $new_skipped = array_values(array_diff($skipped_users, [$target_user_id]));

        update_user_meta($current_user_id, 'sk_blocked_users', $new_blocked);
        update_user_meta($current_user_id, 'sk_skipped_users', $new_skipped);
        
        $new_status = 'unblocked';

        // Mutual UI Sync - Also clear from the other person's list if they happened to block back
        $their_blocked = get_user_meta($target_user_id, 'sk_blocked_users', true) ?: [];
        if (is_array($their_blocked)) {
            $their_blocked = array_map('intval', $their_blocked);
            $new_their_blocked = array_values(array_diff($their_blocked, [$current_user_id]));
            update_user_meta($target_user_id, 'sk_blocked_users', $new_their_blocked);
        }
        
        $their_skipped = get_user_meta($target_user_id, 'sk_skipped_users', true) ?: [];
        if (is_array($their_skipped)) {
            $their_skipped = array_map('intval', $their_skipped);
            $new_their_skipped = array_values(array_diff($their_skipped, [$current_user_id]));
            update_user_meta($target_user_id, 'sk_skipped_users', $new_their_skipped);
        }
    } elseif ($action === 'toggle') {
        if ($is_blocked) {
            // Unblock: remove from BOTH lists
            $target_user_id = (int)$target_user_id;
            
            $blocked_users = array_map('intval', $blocked_users);
            $blocked_users = array_values(array_diff($blocked_users, [$target_user_id]));
            update_user_meta($current_user_id, 'sk_blocked_users', $blocked_users);
            
            $skipped_users = get_user_meta($current_user_id, 'sk_skipped_users', true) ?: [];
            if (!is_array($skipped_users)) $skipped_users = [];
            $skipped_users = array_map('intval', $skipped_users);
            $skipped_users = array_values(array_diff($skipped_users, [$target_user_id]));
            update_user_meta($current_user_id, 'sk_skipped_users', $skipped_users);
            
            $new_status = 'unblocked';

            // MUTUAL UNBLOCK for toggle too
            $target_blocked = get_user_meta($target_user_id, 'sk_blocked_users', true) ?: [];
            if (is_array($target_blocked) && in_array($current_user_id, $target_blocked)) {
                $target_blocked = array_diff($target_blocked, [$current_user_id]);
                update_user_meta($target_user_id, 'sk_blocked_users', array_values($target_blocked));
            }
        } else {
            $blocked_users[] = $target_user_id;
            $new_status = 'blocked';
        }
    }

    // Re-index array
    $blocked_users = array_values($blocked_users);
    
    update_user_meta($current_user_id, 'sk_blocked_users', $blocked_users);

    // AUTO-REMOVE LIKES if blocking
    if ($new_status === 'blocked') {
        // Remove from my likes
        $my_likes = get_user_meta($current_user_id, 'sk_user_likes', true) ?: [];
        $my_likes = array_diff($my_likes, [$target_user_id]);
        update_user_meta($current_user_id, 'sk_user_likes', array_values($my_likes));
        
        // Remove from their liked_by
        $their_liked_by = get_user_meta($target_user_id, 'sk_liked_by_users', true) ?: [];
        $their_liked_by = array_diff($their_liked_by, [$current_user_id]);
        update_user_meta($target_user_id, 'sk_liked_by_users', array_values($their_liked_by));

        // Remove from their likes
        $their_likes = get_user_meta($target_user_id, 'sk_user_likes', true) ?: [];
        $their_likes = array_diff($their_likes, [$current_user_id]);
        update_user_meta($target_user_id, 'sk_user_likes', array_values($their_likes));

        // Remove from my liked_by
        $my_liked_by = get_user_meta($current_user_id, 'sk_liked_by_users', true) ?: [];
        $my_liked_by = array_diff($my_liked_by, [$target_user_id]);
        update_user_meta($current_user_id, 'sk_liked_by_users', array_values($my_liked_by));

        // Remove friendship
        if (function_exists('friends_remove_friend')) {
            friends_remove_friend($current_user_id, $target_user_id);
        }

        // Block in Better Messages if active
        if (class_exists('Better_Messages') && function_exists('Better_Messages')) {
            $bm = Better_Messages();
            if (isset($bm->functions) && method_exists($bm->functions, 'block_user')) {
                $bm->functions->block_user($current_user_id, $target_user_id);
            }
        }
    } else if ($new_status === 'unblocked') {
        // Unblock in Better Messages if active
        if (class_exists('Better_Messages') && function_exists('Better_Messages')) {
            $bm = Better_Messages();
            if (isset($bm->functions) && method_exists($bm->functions, 'unblock_user')) {
                // Mutual unblock in Better Messages
                $bm->functions->unblock_user($current_user_id, $target_user_id);
                $bm->functions->unblock_user($target_user_id, $current_user_id);
            }
        }
    }

    return rest_ensure_response([
        'status' => $new_status,
        'blocked_users' => $blocked_users // returning list for sync if needed
    ]);
}

/**
 * Allow chat endpoint (women allowing men)
 */
function sk_allow_chat_endpoint($request) {
    if (!defined('SK_BYPASS_MATCH_CHECK')) {
        define('SK_BYPASS_MATCH_CHECK', true);
    }
    try {
        // 1. Safe User ID Retrieval
        $current_user_id = 0;
        if (function_exists('bp_loggedin_user_id')) {
            $current_user_id = bp_loggedin_user_id();
        }
        if (!$current_user_id && function_exists('get_current_user_id')) {
            $current_user_id = get_current_user_id();
        }
        
        if (!$current_user_id) {
            return new WP_Error('not_logged_in', 'User must be logged in', ['status' => 401]);
        }

        // 2. Premium Check
        if (!sk_is_premium_user($current_user_id)) {
            return new WP_Error('not_premium', 'Funkcja "Pozwól na rozmowę" jest dostępna tylko dla użytkowników Premium', ['status' => 403]);
        }

        $target_user_id = intval($request->get_param('user_id'));
        $action = $request->get_param('action') ?: 'allow';
        
        // Debug log (Safe logging)
        error_log("sk_allow_chat_endpoint: User $current_user_id -> Target $target_user_id. Action: $action");
        
        if (!$target_user_id) {
            return new WP_Error('invalid_user', 'Invalid user ID', ['status' => 400]);
        }

        $allowed_ids = get_user_meta($current_user_id, 'sk_allowed_chat_ids', true) ?: [];
        if (!is_array($allowed_ids)) $allowed_ids = [];

        // REVOKE LOGIC
        if ($action === 'revoke') {
            if (($key = array_search($target_user_id, $allowed_ids)) !== false) {
                unset($allowed_ids[$key]);
                update_user_meta($current_user_id, 'sk_allowed_chat_ids', array_values($allowed_ids));
            }

            // Delete created thread (subject: 'Prawdziwa Miłość')
            global $wpdb;
            
            // Ensure BuddyPress components are loaded
            if (function_exists('buddypress') && isset(buddypress()->messages->table_name_messages)) {
                $bp = buddypress();
                $table_messages = $bp->messages->table_name_messages;
                $table_recipients = $bp->messages->table_name_recipients;

                $sql = $wpdb->prepare("
                    SELECT m.thread_id 
                    FROM {$table_messages} m
                    JOIN {$table_recipients} r ON m.thread_id = r.thread_id
                    WHERE m.sender_id = %d 
                    AND m.subject = 'Prawdziwa Miłość'
                    AND m.message LIKE '%%Użytkownik pozwala Ci ze sobą porozmawiać%%'
                    AND r.user_id = %d
                    LIMIT 1
                ", $current_user_id, $target_user_id);
                
                $thread_id = $wpdb->get_var($sql);
                
                if ($thread_id && function_exists('messages_delete_thread')) {
                    messages_delete_thread($thread_id);
                }
            }

            return rest_ensure_response([
                 'success' => true,
                 'chat_allowed' => false,
                 'revoked' => true
            ]);
        }

        // ALLOW LOGIC
        $force = $request->get_param('force');

        if (!in_array($target_user_id, $allowed_ids) || $force) {
            if (!in_array($target_user_id, $allowed_ids)) {
                $allowed_ids[] = $target_user_id;
                update_user_meta($current_user_id, 'sk_allowed_chat_ids', array_values($allowed_ids));
            }
            
            // Send notification message
            $bm_sent_id = 0;
            $bp_fallback_id = 0;
            $sync_result = 'none';

            // $existing_thread = sk_get_existing_thread_id($current_user_id, $target_user_id);
            
            $args = [
                'sender_id' => $current_user_id,
                'recipients' => [$target_user_id],
                'content' => 'Użytkownik pozwala Ci ze sobą porozmawiać.',
                'subject' => 'Zgoda na rozmowę',
                'return' => 'thread_id'
            ];
            
            /*
            if ($existing_thread) {
                $args['thread_id'] = $existing_thread;
                error_log("sk_allow_chat_endpoint: Reusing thread $existing_thread");
            }
            */

            // 1. Try Better Messages
            if (class_exists('Better_Messages') && function_exists('Better_Messages')) {
                try {
                    $bm_sent_id = Better_Messages()->functions->new_message($args);
                    if (is_wp_error($bm_sent_id)) {
                        error_log("sk_allow_chat_endpoint: BM WP_Error: " . $bm_sent_id->get_error_message());
                        $bm_sent_id = 0;
                    }
                } catch (Throwable $t) {
                    error_log("sk_allow_chat_endpoint: BM Throwable: " . $t->getMessage());
                }
            }
                    
                    if ($bm_sent_id) {
                        error_log("sk_allow_chat_endpoint: Sent via Better_Messages. ID: " . (is_scalar($bm_sent_id) ? $bm_sent_id : 'Object/Array'));
                        
                        // HIDE THREAD FOR SENDER (Wait for reply)
                        global $wpdb;
                        $bm_table = $wpdb->prefix . 'bm_message_recipients';
                        $wpdb->update(
                            $bm_table,
                            ['is_deleted' => 1],
                            ['thread_id' => $bm_sent_id, 'user_id' => $current_user_id],
                            ['%d'],
                            ['%d', '%d']
                        );
                        error_log("sk_allow_chat_endpoint: Manually set is_deleted=1 for thread $bm_sent_id user $current_user_id in $bm_table");

                        if (function_exists('messages_delete_thread')) {
                            // Keep BP fallback just in case
                            messages_delete_thread($bm_sent_id, $current_user_id);
                        }

                        // FORCE UNREAD COUNT UPDATE FOR RECIPIENT
                        if (class_exists('Better_Messages') && function_exists('Better_Messages')) {
                            try {
                                $bm = Better_Messages();
                                if ($bm && isset($bm->functions) && method_exists($bm->functions, 'update_unread_count')) {
                                    $bm->functions->update_unread_count($target_user_id);
                                    error_log("sk_allow_chat_endpoint: Forced unread count update for recipient $target_user_id");
                                }
                            } catch (Throwable $t) {}
                        }

                        return rest_ensure_response([
                            'success' => true,
                            'chat_allowed' => true,
                            'received_action' => $action, 
                            'debug_bm_id' => is_scalar($bm_sent_id) ? $bm_sent_id : 'complex',
                            'bm_sync' => true
                        ]);
                    }
            
            // 2. Fallback to BuddyPress Core
            if (!$bm_sent_id && function_exists('messages_new_message')) {
                $date_sent = function_exists('bp_core_current_time') ? bp_core_current_time() : current_time('mysql');
                
                try {
                    $bp_fallback_id = messages_new_message([
                        'sender_id' => $current_user_id,
                        'recipients' => [$target_user_id],
                        'subject' => 'Prawdziwa Miłość',
                        'content' => 'Użytkownik pozwala Ci ze sobą porozmawiać.',
                        'date_sent' => $date_sent
                    ]);
                } catch (Throwable $t) {
                    error_log("sk_allow_chat_endpoint: BP Throwable: " . $t->getMessage());
                } catch (Exception $e) {
                    error_log("sk_allow_chat_endpoint: BP Exception: " . $e->getMessage());
                }

                if ($bp_fallback_id) {
                    error_log("sk_allow_chat_endpoint: Sent via BP Native. ID: $bp_fallback_id");
                    
                    // Sync to BM if needed
                    if (class_exists('Better_Messages') && function_exists('Better_Messages')) {
                        $bm = Better_Messages();
                        if ($bm && isset($bm->functions) && method_exists($bm->functions, 'restoring_thread')) {
                            try {
                                $bm->functions->restoring_thread($bp_fallback_id);
                                $sync_result = 'restored_thread_' . $bp_fallback_id;
                            } catch (Throwable $t) {
                                error_log("sk_allow_chat_endpoint: BM Sync Throwable: " . $t->getMessage());
                            }
                        }
                        
                        // Action hook with safe object
                        if ($bp_fallback_id) {
                            do_action('messages_message_sent', (object)['id' => $bp_fallback_id, 'thread_id' => $bp_fallback_id]);
                        }
                    }
                }
            }
        }

        return rest_ensure_response([
            'success' => true,
            'chat_allowed' => true,
            'received_action' => $action, 
            'debug_bm_id' => is_scalar($bm_sent_id) ? $bm_sent_id : 'complex',
            'debug_bp_id' => $bp_fallback_id,
            'sync_result' => $sync_result
        ]);

    } catch (Throwable $t) {
        error_log("CRITICAL FATAL in sk_allow_chat_endpoint: " . $t->getMessage() . " in " . $t->getFile() . ":" . $t->getLine());
        return new WP_Error('internal_error', 'Błąd krytyczny: ' . $t->getMessage(), ['status' => 500]);
    } catch (Exception $e) {
        error_log("CRITICAL EXCEPTION in sk_allow_chat_endpoint: " . $e->getMessage());
        return new WP_Error('internal_error', 'Wystąpił nieoczekiwany błąd.', ['status' => 500]);
    }
}

/**
 * Toggle like/unlike for a user
 */
function sk_toggle_like_endpoint($request) {
    $liker_id = get_current_user_id();
    $liked_id = intval($request->get_param('user_id'));
    
    error_log("sk_toggle_like_endpoint: liker=$liker_id, liked=$liked_id");
    
    if (!$liker_id) {
        return new WP_Error('not_logged_in', 'User must be logged in', ['status' => 401]);
    }
    
    if (!$liked_id || $liker_id == $liked_id) {
        return new WP_Error('invalid_user_id', 'Invalid user ID', ['status' => 400]);
    }

    // Safety check will be performed later specifically for "Like" action
    $my_blocked = get_user_meta($liker_id, 'sk_blocked_users', true) ?: [];
    $is_i_blocked = is_array($my_blocked) && in_array($liked_id, $my_blocked);

    $their_blocked = get_user_meta($liked_id, 'sk_blocked_users', true) ?: [];
    $is_they_blocked = is_array($their_blocked) && in_array($liker_id, $their_blocked);
    
    // Get current likes - ensure all values are integers
    $my_likes = get_user_meta($liker_id, 'sk_user_likes', true) ?: [];
    if (!is_array($my_likes)) $my_likes = [];
    $my_likes = array_map('intval', $my_likes);
    
    $liked_by = get_user_meta($liked_id, 'sk_liked_by_users', true) ?: [];
    if (!is_array($liked_by)) $liked_by = [];
    $liked_by = array_map('intval', $liked_by);
    
    // Check if second user already liked me
    $liker_liked_by_list = get_user_meta($liker_id, 'sk_liked_by_users', true) ?: [];
    if (!is_array($liker_liked_by_list)) $liker_liked_by_list = [];
    $liker_liked_by_list = array_map('intval', $liker_liked_by_list);
    
    $is_mutual_match_possible = in_array($liked_id, $liker_liked_by_list, true);
    $is_already_liked = in_array($liked_id, $my_likes, true);
    
    error_log("my_likes: " . json_encode($my_likes));
    error_log("liked_by: " . json_encode($liked_by));
    error_log("liker_liked_by_list: " . json_encode($liker_liked_by_list));
    error_log("is_mutual_match_possible: " . ($is_mutual_match_possible ? 'YES' : 'NO'));
    error_log("is_already_liked: " . ($is_already_liked ? 'YES' : 'NO'));
    
    if ($is_already_liked) {
        // UNLIKE is always allowed even if blocked (to fix stuck state)
        $my_likes = array_diff($my_likes, [$liked_id]);
        $liked_by = array_diff($liked_by, [$liker_id]);
        
        // Remove friendship in BuddyPress
        if (function_exists('friends_remove_friend')) {
            friends_remove_friend($liker_id, $liked_id);
        }
        
        $new_status = 'unliked';
    } else {
        // LIKE is only allowed if no block exists
        if ($is_i_blocked) {
            return new WP_Error('user_blocked', 'You have blocked this user', ['status' => 403]);
        }
        if ($is_they_blocked) {
            return new WP_Error('user_blocked_you', 'Action not allowed', ['status' => 403]);
        }

        // LIKE
        $my_likes[] = $liked_id;
        $liked_by[] = $liker_id;
        
        // Check if it's a MATCH (mutual like)
        if ($is_mutual_match_possible) {
            if (function_exists('friends_add_friend')) {
                // Auto-accept friendship
                friends_add_friend($liker_id, $liked_id, true);
            }
        }
        
        $new_status = 'liked';
    }
    
    // Save updated meta
    update_user_meta($liker_id, 'sk_user_likes', array_values($my_likes));
    update_user_meta($liked_id, 'sk_liked_by_users', array_values($liked_by));
    
    $is_match = $is_mutual_match_possible && $new_status === 'liked';

    // Wyślij e-mail powiadomienie o polubieniu/dopasowaniu
    if ($new_status === 'liked') {
        $liked_user = get_userdata($liked_id);
        $liker_user = get_userdata($liker_id);
        if ($liked_user && $liker_user) {
            $to = $liked_user->user_email;
            $liker_name = $liker_user->display_name;
            
            if ($is_match) {
                $subject = 'Masz nową parę na Prawdziwa Miłość!';
                $message = "Cześć " . $liked_user->display_name . ",\n\n";
                $message .= "Mamy świetną wiadomość! Użytkownik " . $liker_name . " również Cię polubił(a). Masz nową parę!\n\n";
                $message .= "Zaloguj się do aplikacji, aby rozpocząć rozmowę:\n";
                $message .= "https://prawdziwamilosc.pl\n\n";
                $message .= "Życzymy udanej rozmowy,\nZespół Prawdziwa Miłość";
            } else {
                $subject = 'Ktoś Cię polubił na Prawdziwa Miłość!';
                $message = "Cześć " . $liked_user->display_name . ",\n\n";
                $message .= "Ktoś okazał Ci zainteresowanie! Użytkownik " . $liker_name . " polubił Twój profil.\n\n";
                $message .= "Zaloguj się do aplikacji, aby sprawdzić profil:\n";
                $message .= "https://prawdziwamilosc.pl\n\n";
                $message .= "Życzymy miłego dnia,\nZespół Prawdziwa Miłość";
            }
            
            $mail_sent = wp_mail($to, $subject, $message);
            error_log("SK DEBUG: sk_toggle_like_endpoint - email to $to sent status: " . ($mail_sent ? 'SUCCESS' : 'FAILED'));
        }
    }
    
    return rest_ensure_response([
        'status' => $new_status,
        'is_match' => $is_match
    ]);
}

// ========================================
// Custom Delete Thread Endpoint
// ========================================
add_action('rest_api_init', function () {
    register_rest_route('sk/v1', '/thread/(?P<id>\d+)', [
        'methods' => 'DELETE',
        'callback' => 'sk_delete_thread_endpoint',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);

    register_rest_route('sk/v1', '/thread/(?P<id>\d+)/read', [
        'methods' => 'POST',
        'callback' => 'sk_mark_thread_read_endpoint',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);

    register_rest_route('sk/v1', '/thread/(?P<id>\d+)/read-status', [
        'methods' => 'GET',
        'callback' => 'sk_get_thread_read_status',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
});

/**
 * Returns the unread count of the OTHER participant in a thread.
 * This tells the current user whether their messages have been read.
 * If other_unread = 0, all messages were read by the other person.
 */
function sk_get_thread_read_status($request) {
    global $wpdb;
    $thread_id = intval($request->get_param('id'));
    $current_user_id = get_current_user_id();

    if (!$thread_id) {
        return new WP_Error('missing_param', 'Thread ID is required', ['status' => 400]);
    }

    $result = [
        'thread_id' => $thread_id,
        'other_unread' => null,
        'source' => 'none'
    ];

    // 1. Try Better Messages table first
    $bm_table = $wpdb->prefix . 'bm_message_recipients';
    $has_bm = $wpdb->get_var("SHOW TABLES LIKE '$bm_table'") == $bm_table;
    
    if ($has_bm) {
        // Find the unread column name
        $bm_col = $wpdb->get_var("SHOW COLUMNS FROM {$bm_table} LIKE 'unread_count'") ? 'unread_count' : ($wpdb->get_var("SHOW COLUMNS FROM {$bm_table} LIKE 'unread'") ? 'unread' : '');
        
        if ($bm_col) {
            $other_unread = $wpdb->get_var($wpdb->prepare(
                "SELECT {$bm_col} FROM {$bm_table} WHERE thread_id = %d AND user_id != %d LIMIT 1",
                $thread_id, $current_user_id
            ));
            
            if ($other_unread !== null) {
                $result['other_unread'] = (int)$other_unread;
                $result['source'] = 'better_messages';
            }
        }
    }

    // 2. Fallback to BuddyPress table
    if ($result['other_unread'] === null) {
        $bp_table = $wpdb->prefix . 'bp_messages_recipients';
        $has_bp = $wpdb->get_var("SHOW TABLES LIKE '$bp_table'") == $bp_table;
        
        if ($has_bp) {
            $other_unread = $wpdb->get_var($wpdb->prepare(
                "SELECT unread_count FROM {$bp_table} WHERE thread_id = %d AND user_id != %d LIMIT 1",
                $thread_id, $current_user_id
            ));
            
            if ($other_unread !== null) {
                $result['other_unread'] = (int)$other_unread;
                $result['source'] = 'buddypress';
            }
        }
    }

    // Default to 0 if nothing found (assume read)
    if ($result['other_unread'] === null) {
        $result['other_unread'] = 0;
        $result['source'] = 'default';
    }

    return rest_ensure_response($result);
}

function sk_get_unread_count_endpoint($request = null, $skip_reset = false) {
    global $wpdb;
    $table_recipients = $wpdb->prefix . 'bp_messages_recipients';
    $table_messages = $wpdb->prefix . 'bp_messages_messages';
    
    // Support being called as $request object OR manual ID
    $user_id = 0;
    if (is_numeric($request)) {
        $user_id = (int)$request;
    } elseif (is_object($request) && method_exists($request, 'get_param')) {
        $user_id = get_current_user_id();
    } else {
        $user_id = get_current_user_id();
    }

    $log_file = dirname(__FILE__) . '/sk_push.log';
    $log_debug = function($msg) use ($log_file) {
        $ts = date('Y-m-d H:i:s');
        @file_put_contents($log_file, "[$ts] $msg" . PHP_EOL, FILE_APPEND);
    };

    if (!$user_id) {
        $log_debug("FAILURE: Unauthenticated call");
        return rest_ensure_response(['unread_count' => 0, 'unread_thread_ids' => [], 'status' => 'unauthenticated']);
    }
    
    $log_debug("ENTERED: User $user_id (Manual: " . (is_numeric($request) ? $request : 'NO') . ")");

    try {
        $clear_all = (is_object($request) && method_exists($request, 'get_param')) 
            ? ($request->get_param('clear') === 'true' || $request->get_param('clear_all') == 1) 
            : false;
        
        $table_recipients = $wpdb->prefix . 'bp_messages_recipients';
        $table_messages = $wpdb->prefix . 'bp_messages_messages';
        
        $bm_table_recipients = $wpdb->prefix . 'bm_message_recipients';
        $bm_col = '';
        if ($wpdb->get_var("SHOW TABLES LIKE '$bm_table_recipients'") == $bm_table_recipients) {
            $bm_col = $wpdb->get_var("SHOW COLUMNS FROM {$bm_table_recipients} LIKE 'unread_count'") ? 'unread_count' : ($wpdb->get_var("SHOW COLUMNS FROM {$bm_table_recipients} LIKE 'unread'") ? 'unread' : '');
        }

        // 0. Forced Clear All Logic
        if ($clear_all) {
            $wpdb->update($table_recipients, ['unread_count' => 0], ['user_id' => $user_id]);
            bp_update_user_meta($user_id, 'bp_messages_unread_count', 0);
            delete_user_meta($user_id, 'sk_alerted_threads'); // RESET ALL PUSH ALERTS

            // 0b. Manual Reset for Better Messages table
            if ($bm_table_recipients && $bm_col) {
                $wpdb->update($bm_table_recipients, [$bm_col => 0], ['user_id' => $user_id]);
                $log_debug("CLEAR ALL: Reset $bm_col in $bm_table_recipients for user $user_id");
            }
            
            if (class_exists('Better_Messages') && function_exists('Better_Messages')) {
                try {
                    $bm = Better_Messages();
                    if ($bm && isset($bm->functions) && method_exists($bm->functions, 'mark_all_as_read')) {
                        $bm->functions->mark_all_as_read($user_id);
                    }
                } catch (Throwable $t) { /* ignore secondary error */ }
            }
        }

        // 1. Get raw unread thread IDs from BP table
        $unread_thread_ids = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT thread_id FROM {$table_recipients}
            WHERE user_id = %d AND unread_count > 0 AND is_deleted = 0
        ", $user_id));
        
        $unread_thread_ids = array_map('intval', (array)$unread_thread_ids);

        // 1b. Get Better Messages unread thread IDs (Robust Column Check)
        $bm_unread_ids = [];
        if ($bm_table_recipients && $bm_col) {
            if ($bm_col) {
                $bm_unread_ids = $wpdb->get_col($wpdb->prepare("
                    SELECT DISTINCT thread_id FROM {$bm_table_recipients}
                    WHERE user_id = %d AND {$bm_col} > 0 AND is_deleted = 0
                ", $user_id));
                $bm_unread_ids = array_map('intval', (array)$bm_unread_ids);
                $log_debug("BM Unread IDs found: " . implode(',', $bm_unread_ids) . " (Using column: $bm_col)");
            }
        }

        // Merge and unique
        $merged_ids = array_unique(array_merge($unread_thread_ids, $bm_unread_ids));
        
        // 2. Verified Count
        $verified_unread_ids = array_values($merged_ids); // reset keys
        $db_count = count($verified_unread_ids);

        $log_debug("IDs Sync: BP(" . count($unread_thread_ids) . ") BM(" . count($bm_unread_ids) . ") TOTAL UNIQUE(" . $db_count . ")");

        // 3. Get standard BP unread count
        $bp_count = 0;
        if (function_exists('messages_get_unread_count')) {
            $bp_count = messages_get_unread_count($user_id);
            $log_debug("BP messages_get_unread_count: $bp_count");
        }
        
        // 4. Get Better Messages total unread count (Fallback)
        $bm_count = 0;
        if (class_exists('Better_Messages') && function_exists('Better_Messages')) {
            try {
                $bm = Better_Messages();
                if ($bm && isset($bm->functions)) {
                    if (method_exists($bm->functions, 'get_unread_threads_count')) {
                        $bm_count = $bm->functions->get_unread_threads_count($user_id);
                        $log_debug("BM get_unread_threads_count for user $user_id: $bm_count");
                    }
                }
            } catch (Throwable $t) {
                $log_debug("BM Fallback Error: " . $t->getMessage());
            }
        }
        // --- FINAL COUNT ---
        // CRITICAL FIX: We strictly trust the DB count (verified thread IDs).
        // If $bm_count is higher, it signifies a "ghost" message in Better Messages cache/meta 
        // that doesn't correspond to a real unread thread in the recipients table.
        // We logging it for debug but don't show to user if no thread IDs were found.
        
        $final_count = (int)$db_count;
        
        // --- GHOST DESTROYER ---
        // If the Better Messages plugin says there are 0 unread threads, 
        // but our DB query found some, it means there are "ghost" records in the DB 
        // (likely BuddyPress orphans or stuck flags).
        // We force-clear them to preserve user sanity.
        // CRITICAL FIX: Skip this if $skip_reset is true (Push context) to avoid race conditions with plugin cache.
        if (!$skip_reset && $final_count > 0 && (int)$bm_count === 0 && class_exists('Better_Messages')) {
            $log_debug("GHOST DETECTED: Plugin says 0 but DB says $final_count. (Clearing DISABLED to avoid false resets)");
            // We NO LONGER wipe the DB here, we trust our DB query over the potentially cached plugin result.
            // $wpdb->update($table_recipients, ['unread_count' => 0], ['user_id' => $user_id, 'thread_id' => $ghost_id]);
            // final_count = 0;
        }

        // If we found NO threads via DB query, but BM plugin says there is one,
        // it's almost certainly the source of the "ghost badge".
        if (!$skip_reset && $final_count === 0 && (int)$bm_count > 0) {
            $log_debug("WARNING: Ghost detected - BM Plugin says $bm_count but DB query found 0 threads.");
        }

        $log_debug("FINAL RESULT: $final_count (DB Thread:$db_count, BM Thread:$bm_count, BP Total Msg:$bp_count)");
        
        // Persist final count for mobile app reliably
        update_user_meta($user_id, 'sk_unread_count', $final_count);

        // Alert Reset Logic (Per-Thread) - ONLY CRITICAL CLEANUP
        if (!$skip_reset) {
            $alerted_threads = get_user_meta($user_id, 'sk_alerted_threads', true);
            if (is_array($alerted_threads) && !empty($alerted_threads)) {
                if ($final_count === 0) {
                    // Fully read? Clear all alerts definitely.
                    delete_user_meta($user_id, 'sk_alerted_threads');
                    $log_debug("UNREAD [$user_id]: Cleared ALL alerts (Total 0).");
                }
            }
        } else {
            $log_debug("UNREAD [$user_id]: Internal call, no reset.");
        }

        // Clean up legacy flag if exists
        delete_user_meta($user_id, 'sk_push_alerted');

        // Efficiently update user meta to stay in sync
        update_user_meta($user_id, '_bm_unread_count', $final_count);
        bp_update_user_meta($user_id, 'bp_messages_unread_count', $final_count);
        update_user_meta($user_id, '_bp_messages_unread_count', $final_count);
        
        return rest_ensure_response([
            'unread_count' => (int) $final_count,
            'details' => [
                'bp_msg_count' => (int) $bp_count,
                'bm_thread_count' => (int) $bm_count,
                'db_thread_count' => (int) $db_count,
            ],
            'unread_thread_ids' => $verified_unread_ids,
            'status' => 'success'
        ]);
    } catch (Throwable $t) {
        $log_debug("CRITICAL ERROR: " . $t->getMessage() . " in " . $t->getFile() . ":" . $t->getLine());
        return rest_ensure_response(['unread_count' => 0, 'error' => $t->getMessage()]);
    }
}

function sk_mark_thread_read_endpoint($request) {
    $thread_id = intval($request->get_param('id'));
    $user_id = get_current_user_id();

    if (!$thread_id) {
        return new WP_Error('missing_param', 'Thread ID is required', ['status' => 400]);
    }

    $results = [
        'bp_marked' => false,
        'bm_marked' => false,
        'db_updated' => false
    ];

    try {
        // 1. Mark as read in BuddyPress via standard function
        if (function_exists('messages_mark_thread_read')) {
            messages_mark_thread_read($thread_id);
            $results['bp_marked'] = true;
        }

        // 2. Clear BuddyPress notifications for this thread
        if (function_exists('bp_notifications_mark_notifications_by_item_id')) {
            bp_notifications_mark_notifications_by_item_id($user_id, $thread_id, 'messages', 'new_message');
            $results['notifications_cleared'] = true;
        }

        // Reset alerted flag for this specific thread
        $alerted = get_user_meta($user_id, 'sk_alerted_threads', true);
        if (is_array($alerted)) {
            $new_alerted = array_diff(array_map('intval', $alerted), [(int)$thread_id]);
            if (count($new_alerted) !== count($alerted)) {
                update_user_meta($user_id, 'sk_alerted_threads', array_values($new_alerted));
                error_log("MARK READ [$user_id]: Cleared alert for thread $thread_id");
            }
        }
        delete_user_meta($user_id, 'sk_push_alerted');

        // 3. Direct DB update for BuddyPress (insurance)
        global $wpdb;
        $table_recipients = $wpdb->prefix . 'bp_messages_recipients';
        $updated = $wpdb->update(
            $table_recipients,
            ['unread_count' => 0],
            ['thread_id' => $thread_id, 'user_id' => $user_id],
            ['%d'],
            ['%d', '%d']
        );
        if ($updated !== false) {
            $results['db_updated'] = true;
        }

        // 4. Mark as read in Better Messages
        if (class_exists('Better_Messages') && function_exists('Better_Messages')) {
            $bm = Better_Messages();
            if (isset($bm->functions)) {
                $log_file = dirname(__FILE__) . '/sk_push.log';
                $log_bm = function($msg) use ($log_file) {
                    $ts = date('Y-m-d H:i:s');
                    @file_put_contents($log_file, "[$ts] BM SYNC: $msg" . PHP_EOL, FILE_APPEND);
                };

                // Try multiple possible methods depending on version
                if (method_exists($bm->functions, 'mark_as_read')) {
                    $bm->functions->mark_as_read($thread_id, $user_id);
                    $results['bm_marked_v1'] = true;
                    $log_bm("Used mark_as_read for Thread $thread_id User $user_id");
                }
                
                if (method_exists($bm->functions, 'mark_thread_as_read')) {
                    $bm->functions->mark_thread_as_read($thread_id, $user_id);
                    $results['bm_marked_v2'] = true;
                    $log_bm("Used mark_thread_as_read for Thread $thread_id User $user_id");
                }

                // Force update unread count for user
                if (method_exists($bm->functions, 'update_unread_count')) {
                    $bm->functions->update_unread_count($user_id);
                    $results['bm_count_updated'] = true;
                    $log_bm("Force updated unread count for User $user_id");
                }
            }
        }

        // 4.5 Manual DB Insurance for Better Messages
        $bm_table = $wpdb->prefix . 'bm_message_recipients';
        if ($wpdb->get_var("SHOW TABLES LIKE '$bm_table'") == $bm_table) {
            $bm_col = $wpdb->get_var("SHOW COLUMNS FROM {$bm_table} LIKE 'unread_count'") ? 'unread_count' : ($wpdb->get_var("SHOW COLUMNS FROM {$bm_table} LIKE 'unread'") ? 'unread' : '');
            if ($bm_col) {
                $bm_updated = $wpdb->update(
                    $bm_table,
                    [$bm_col => 0],
                    ['thread_id' => $thread_id, 'user_id' => $user_id],
                    ['%d'],
                    ['%d', '%d']
                );
                if ($bm_updated !== false) {
                    $results['bm_db_updated'] = true;
                }
            }
        }
        
        // 5. Force recalculate and clear cache (BuddyPress)
            // 5. Force recalculate and clear cache (BuddyPress)
            if (function_exists('messages_get_unread_count')) {
                
                // CRITICAL FIX: Better Messages might have its own table that reverts the meta.
                // We perform a "Scorched Earth" update on any table that looks like a message recipient table.
                // WE DO THIS BEFORE FETCHING THE COUNT to ensure we get the fresh 0.
                global $wpdb;
                $potential_tables = $wpdb->get_col("SHOW TABLES LIKE '%message%recipient%'");
                foreach ($potential_tables as $table) {
                    // Robust Column Check: Determine if table uses 'unread_count' or 'unread'
                    $column = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'unread_count'") ? 'unread_count' : ($wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'unread'") ? 'unread' : '');
                    
                    if ($column) {
                        $wpdb->query($wpdb->prepare(
                            "UPDATE {$table} SET {$column} = 0 WHERE user_id = %d AND thread_id = %d",
                            $user_id, $thread_id
                        ));
                    }
                }
                
                // Clear cache BEFORE fetching new count
                wp_cache_delete($user_id, 'bp_messages_unread_count');

                // Now fetch the true count (should be 0 for this thread)
                $new_count = messages_get_unread_count($user_id);
                
                update_user_meta($user_id, '_bp_messages_unread_count', $new_count);
                // Also update Better Messages meta if it exists
                update_user_meta($user_id, '_bm_unread_count', $new_count); 
                
                $results['new_count'] = $new_count;
            }

    } catch (Exception $e) {
        error_log('Mark Read Error (Exception): ' . $e->getMessage());
        return new WP_Error('server_error', 'Exception: ' . $e->getMessage(), ['status' => 500]);
    } catch (Throwable $t) {
        return new WP_Error('internal_error', $t->getMessage(), ['status' => 500]);
    }
}

function sk_delete_thread_endpoint($request) {
    $thread_id = intval($request->get_param('id'));
    $user_id = get_current_user_id();

    if (!$thread_id) {
        return new WP_Error('missing_param', 'Thread ID is required', ['status' => 400]);
    }

    if (!function_exists('messages_delete_thread')) {
        return new WP_Error('bp_missing', 'BuddyPress messaging functions missing', ['status' => 500]);
    }

    global $wpdb;
    $bp_table = $wpdb->prefix . 'bp_messages_recipients';
    $bm_table = $wpdb->prefix . 'bm_message_recipients';
    
    // 1. Get participants from BuddyPress
    $bp_participants = $wpdb->get_col($wpdb->prepare(
        "SELECT user_id FROM $bp_table WHERE thread_id = %d", 
        $thread_id
    ));

    // 2. Get participants from Better Messages
    $bm_participants = [];
    if ($wpdb->get_var("SHOW TABLES LIKE '$bm_table'") == $bm_table) {
        $bm_participants = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM $bm_table WHERE thread_id = %d", 
            $thread_id
        ));
    }

    // Merge and filter
    $all_participants = array_unique(array_merge(
        is_array($bp_participants) ? $bp_participants : [], 
        is_array($bm_participants) ? $bm_participants : []
    ));
    $all_participants = array_map('intval', array_filter($all_participants));

    if (empty($all_participants)) {
        $all_participants = [(int)$user_id];
    }

    $results = [
        'bm_deleted_for' => [],
        'bp_deleted_for' => [],
        'alerts_cleared_for' => [],
        'mutual_block' => true,
        'participants_found' => count($all_participants)
    ];

    $log_file = ABSPATH . 'sk_push.log';
    $log_ts = date('Y-m-d H:i:s');
    $p_list = implode(',', $all_participants);
    file_put_contents($log_file, "[$log_ts] DELETE THREAD $thread_id: Participants: [$p_list] Initiated by: $user_id\n", FILE_APPEND);

    // SKIP LOGIC (Previously Mutual Block)
    // Only the user who is deleting the thread should have the other participants added to their "Skipped" list.
    foreach ($all_participants as $p2) {
        if ($user_id == $p2) continue;

        // Add p2 to current user's skipped list
        $skipped = get_user_meta($user_id, 'sk_skipped_users', true) ?: [];
        if (!is_array($skipped)) $skipped = [];
        if (!in_array($p2, $skipped)) {
            $skipped[] = (int)$p2;
            update_user_meta($user_id, 'sk_skipped_users', array_values($skipped));
            file_put_contents($log_file, "[$log_ts] DELETE THREAD: Added $p2 to $user_id skipped list.\n", FILE_APPEND);
        }

        // Revoke chat permissions (Allow Chat sync)
        $allowed_ids = get_user_meta($user_id, 'sk_allowed_chat_ids', true) ?: [];
        if (is_array($allowed_ids) && in_array($p2, $allowed_ids)) {
            $allowed_ids = array_values(array_diff($allowed_ids, [$p2]));
            update_user_meta($user_id, 'sk_allowed_chat_ids', $allowed_ids);
            file_put_contents($log_file, "[$log_ts] DELETE THREAD: Revoked chat permission for $p2 in $user_id list.\n", FILE_APPEND);
        }

        // Clean up likes/matches
        $likes1 = get_user_meta($user_id, 'sk_user_likes', true) ?: [];
        update_user_meta($user_id, 'sk_user_likes', array_values(array_diff($likes1, [$p2])));
        
        $liked_by1 = get_user_meta($user_id, 'sk_liked_by_users', true) ?: [];
        update_user_meta($user_id, 'sk_liked_by_users', array_values(array_diff($liked_by1, [$p2])));

        if (function_exists('friends_remove_friend')) {
            friends_remove_friend($user_id, $p2);
        }
    }

    foreach ($all_participants as $participant_id) {
        // 1. Better Messages removal
        if (class_exists('Better_Messages') && function_exists('Better_Messages')) {
            try {
                $removed = Better_Messages()->functions->remove_participant_from_thread($thread_id, $participant_id);
                if (!$removed) {
                    Better_Messages()->functions->archive_thread($thread_id, $participant_id);
                }
                $results['bm_deleted_for'][] = (int)$participant_id;
                file_put_contents($log_file, "[$log_ts] DELETE THREAD: BM removed/archived for $participant_id\n", FILE_APPEND);
            } catch (Exception $e) {
                error_log("Delete Thread (BM) Error for user $participant_id: " . $e->getMessage());
            }
        }

        // 2. BuddyPress removal
        $deleted_bp = messages_delete_thread($thread_id, $participant_id);
        if ($deleted_bp) {
            $results['bp_deleted_for'][] = (int)$participant_id;
            file_put_contents($log_file, "[$log_ts] DELETE THREAD: BP marked deleted for $participant_id\n", FILE_APPEND);
        }

        // 3. Clear push alerts state
        $alerted = get_user_meta($participant_id, 'sk_alerted_threads', true);
        if (!empty($alerted) && is_array($alerted)) {
            $updated_alerts = array_filter($alerted, function($tid) use ($thread_id) {
                return (int)$tid !== (int)$thread_id;
            });
            update_user_meta($participant_id, 'sk_alerted_threads', $updated_alerts);
            $results['alerts_cleared_for'][] = (int)$participant_id;
        }
    }

    error_log("Mutual Delete/Block for Thread $thread_id initiated by User $user_id.");

    return rest_ensure_response([
        'success' => true,
        'message' => 'Thread deleted and users moved to Deleted list for all participants.',
        'thread_id' => (int)$thread_id,
        'details' => $results
    ]);
}

// ========================================
// Custom Registration Endpoint
// ========================================
add_action('rest_api_init', function () {
    register_rest_route('sk/v1', '/register', [
        'methods' => 'POST',
        'callback' => 'sk_register_user',
        'permission_callback' => '__return_true', // Public endpoint
    ]);
});

function sk_register_user($request) {
    $username = sanitize_user($request->get_param('user_login'));
    $email = sanitize_email($request->get_param('user_email'));
    $password = $request->get_param('password');
    
    // Walidacja
    if (empty($username) || empty($email) || empty($password)) {
        return new WP_Error('missing_fields', 'Wszystkie pola są wymagane', ['status' => 400]);
    }
    
    if (!is_email($email)) {
        return new WP_Error('invalid_email', 'Nieprawidłowy adres email', ['status' => 400]);
    }
    
    if (username_exists($username)) {
        return new WP_Error('username_exists', 'Nazwa użytkownika już istnieje', ['status' => 400]);
    }
    
    if (email_exists($email)) {
        return new WP_Error('email_exists', 'Email już jest zarejestrowany', ['status' => 400]);
    }
    
    if (strlen($password) < 6) {
        return new WP_Error('weak_password', 'Hasło musi mieć minimum 6 znaków', ['status' => 400]);
    }
    
    // Użyj BuddyPress signup z email verification
    $signup_id = bp_core_signup_user(
        $username,
        $password,
        $email,
        ['field_1' => ''] // Puste meta - można później rozszerzyć
    );
    
    if (is_wp_error($signup_id)) {
        return new WP_Error('registration_failed', $signup_id->get_error_message(), ['status' => 500]);
    }
    
    // Email z linkiem aktywacyjnym został wysłany przez BuddyPress
    return rest_ensure_response([
        'success' => true,
        'message' => 'Konto zostało utworzone. Sprawdź email aby aktywować konto.',
        'username' => $username,
        'email' => $email,
        'requires_activation' => true,
    ]);
}



// ============================================================================
// ENDPOINT AKTYWACJI KONTA
// ============================================================================

add_action('rest_api_init', 'register_user_activation_endpoint');
function register_user_activation_endpoint() {
    register_rest_route('sk/v1', '/activate', [
        'methods' => 'GET',
        'callback' => 'sk_activate_user',
        'permission_callback' => '__return_true',
    ]);
}

function sk_activate_user($request) {
    $activation_key = sanitize_text_field($request->get_param('key'));
    $user_login = sanitize_user($request->get_param('user'));
    
    error_log("SK API: /activate called for user: $user_login with key: $activation_key");
    $sk_log = WP_CONTENT_DIR . '/uploads/temp-avatars/sk_debug.log';
    file_put_contents($sk_log, "SK API: /activate called for user: $user_login with key: $activation_key\n", FILE_APPEND);

    if (empty($activation_key) || empty($user_login)) {
        return new WP_Error('missing_params', 'Brak klucza aktywacyjnego lub nazwy użytkownika', ['status' => 400]);
    }
    
    $activate = bp_core_activate_signup($activation_key);
    
    if (is_wp_error($activate)) {
        error_log("SK Activation ERROR: bp_core_activate_signup failed: " . $activate->get_error_message());
        $sk_log = WP_CONTENT_DIR . '/uploads/temp-avatars/sk_debug.log';
        file_put_contents($sk_log, "SK ERROR: bp_core_activate_signup failed: " . $activate->get_error_message() . "\n", FILE_APPEND);
        return new WP_Error('activation_failed', $activate->get_error_message(), ['status' => 400]);
    }
    
    error_log("SK Activation SUCCESS: Account activated for user_id: " . $activate['user_id']);
    $sk_log = WP_CONTENT_DIR . '/uploads/temp-avatars/sk_debug.log';
    file_put_contents($sk_log, "SK SUCCESS: Account activated for user_id: " . $activate['user_id'] . "\n", FILE_APPEND);

    return rest_ensure_response([
        'success' => true,
        'message' => 'Konto zostało aktywowane pomyślnie!',
        'user_id' => $activate['user_id'],
        'user_login' => $activate['user_login'],
    ]);
}


// ============================================================================
// REJESTRACJA Z AVATAREM - CUSTOM ENDPOINT
// ============================================================================

add_action('rest_api_init', function () {
    register_rest_route('sk/v1', '/register-with-avatar', [
        'methods' => 'POST',
        'callback' => 'sk_register_user_with_avatar',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('sk/v1', '/onboarding/update', [
        'methods' => 'POST',
        'callback' => 'sk_update_onboarding',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);

    register_rest_route('sk/v1', '/google-login', [
        'methods' => 'POST',
        'callback' => 'sk_google_login_handler',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('sk/v1', '/apple-login', [
        'methods' => 'POST',
        'callback' => 'sk_apple_login_handler',
        'permission_callback' => '__return_true',
    ]);
});

/**
 * Helper to generate a unique human-readable username
 */
function sk_generate_unique_username($first_name, $last_name, $email, $prefix = '') {
    $base_name = '';
    
    if (!empty($first_name) || !empty($last_name)) {
        $base_name = sanitize_title(trim($first_name . ' ' . $last_name));
    }
    
    // If name is totally invalid or empty, use email prefix
    if (empty($base_name) && !empty($email)) {
        $parts = explode('@', $email);
        $base_name = sanitize_title($parts[0]);
    }

    // Last resort fallback
    if (empty($base_name)) {
        $base_name = $prefix . '_' . wp_generate_password(6, false);
    }
    
    // Make it a clean, continuous string (buddypress likes it simple)
    $base_name = str_replace('-', '', $base_name);

    $username = $base_name;
    $counter = 1;

    // Check availability
    while (username_exists($username)) {
        $username = $base_name . $counter;
        $counter++;
    }

    return $username;
}

/**
 * Handle Google Login from the App
 */
function sk_google_login_handler($request) {
    $id_token = $request->get_param('id_token');
    
    if (empty($id_token)) {
        return new WP_Error('missing_token', 'Brak tokenu Google', ['status' => 400]);
    }

    // 1. Verify Token with Google
    $verify_url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . $id_token;
    $response = wp_remote_get($verify_url);

    if (is_wp_error($response)) {
        return new WP_Error('google_error', 'Nie udało się połączyć z Google', ['status' => 500]);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if (empty($body) || isset($body['error'])) {
        return new WP_Error('invalid_google_token', 'Nieprawidłowy token Google', ['status' => 401]);
    }

    // 1b. SECURITY: Verify Audience (Client ID)
    // Only allow tokens issued specifically for our iOS app
    $allowed_aud = [
        '203973175336-93sf5dgi7gmdket8hn7t0a7tmf0trmc9.apps.googleusercontent.com', // OLD iOS Client ID
        '203973175336-1qatql0ahjqe68b9o81pnnekcl9jsst1.apps.googleusercontent.com', // OLD Web Client ID
        '422700251567-j1vnrcrqkfbshu1bukfs05gailpk012k.apps.googleusercontent.com', // NEW Web Client ID (PrawdziwaMilosc)
        '422700251567-n8s55df8i522i512f4a9oqcv6p69dlv0.apps.googleusercontent.com', // NEW Android Client ID
        '422700251567-i7ivt9jac5jhkbk52eqec3c1jglpl06g.apps.googleusercontent.com', // NEW iOS Client ID
    ];

    if (!in_array($body['aud'], $allowed_aud)) {
        return new WP_Error('unauthorized_client', 'Ten token nie został wydany dla tej aplikacji.', ['status' => 403]);
    }

    // Google data
    $email = sanitize_email($body['email']);
    $google_id = sanitize_text_field($body['sub']);
    $full_name = sanitize_text_field($body['name']);
    $first_name = sanitize_text_field($body['given_name']);
    $last_name = sanitize_text_field($body['family_name']);
    $picture = esc_url_raw($body['picture']);

    // 2. Find or Create User
    // First, check by Google ID meta
    $user_query = new WP_User_Query([
        'meta_key' => 'sk_google_user_id',
        'meta_value' => $google_id,
        'number' => 1
    ]);
    
    $user = null;
    $is_new_user = false;

    if (!empty($user_query->get_results())) {
        $user = $user_query->get_results()[0];
    } else {
        // Not found by Google ID, try by email
        $user = get_user_by('email', $email);
        
        if ($user) {
            // User exists, but doesn't have the Google ID linked - link it now
            update_user_meta($user->ID, 'sk_google_user_id', $google_id);
        } else {
            // New User - Create!
            $is_new_user = true;
            $username = sk_generate_unique_username($first_name, $last_name, $email, 'google');
            $random_password = wp_generate_password(12, true);
            
            $user_id = wp_create_user($username, $random_password, $email);
            
            if (is_wp_error($user_id)) {
                return new WP_Error('user_creation_failed', $user_id->get_error_message(), ['status' => 500]);
            }

            // Wyślij email z powiadomieniem do administratora
            wp_new_user_notification($user_id, null, 'admin');
            
            $user = get_userdata($user_id);
            update_user_meta($user_id, 'sk_google_user_id', $google_id);
            update_user_meta($user_id, 'first_name', $first_name);
            update_user_meta($user_id, 'last_name', $last_name);
            
            // Sync with BuddyPress Full Name (Field ID 1) - ONLY FIRST NAME FOR PRIVACY
            if (function_exists('xprofile_set_field_data')) {
                $display_name = !empty($first_name) ? $first_name : $full_name;
                xprofile_set_field_data(1, $user_id, $display_name);
                wp_update_user(['ID' => $user_id, 'display_name' => $display_name]);
            }

            // DO NOT set app_onboarding_complete to true - force onboarding for new users
            update_user_meta($user_id, 'app_onboarding_complete', false);

            // Download and set Google Profile Picture
            if (!empty($picture)) {
                require_once(ABSPATH . 'wp-admin/includes/file.php');
                require_once(ABSPATH . 'wp-admin/includes/media.php');
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                
                $attach_id = media_sideload_image($picture, 0, null, 'id');
                if (!is_wp_error($attach_id)) {
                    update_user_meta($user_id, 'user_avatar_id', $attach_id);
                    update_user_meta($user_id, 'user_profile_photos_ids', [$attach_id]);
                    
                    // Assign post author
                    wp_update_post([
                        'ID' => $attach_id,
                        'post_author' => $user_id
                    ]);
                }
            }
        }
    }

    // 3. Generate JWT Token
    $token = '';
    // Check if JWT Auth plugin is active and we can use its logic
    if (class_exists('T_JWT_Auth') || class_exists('Jwt_Auth_Public')) {
        // T_JWT_Auth usually provides the token generation
        // But since we are in a custom endpoint, we might have to manually sign it if we can't find the class method
        // Standard JWT-Auth plugin doesn't have a simple static method for this many times.
        // Let's try to find if we can manually generate it using the same secret.
    }

    // Robust Fallback: Manual JWT Generation matching standard jwt-auth expectations
    // Standard payload: iss, iat, nbf, exp, data { user { id } }
    if (defined('JWT_AUTH_SECRET_KEY')) {
        $secret = JWT_AUTH_SECRET_KEY;
        $issued_at = time();
        $not_before = $issued_at;
        $expire = $issued_at + (DAY_IN_SECONDS * 7); // 1 week

        $payload = [
            'iss' => get_bloginfo('url'),
            'iat' => $issued_at,
            'nbf' => $not_before,
            'exp' => $expire,
            'data' => [
                'user' => [
                    'id' => $user->ID,
                ],
            ],
        ];

        // Basic JWT header
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        
        $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($payload)));
        
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);
        $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        
        $token = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    } else {
        // If we can't find the secret, we might have a problem, but let's try to notify.
        error_log('SK Google Login: JWT_AUTH_SECRET_KEY not defined!');
    }

    return rest_ensure_response([
        'token'             => $token,
        'user_email'        => $user->user_email,
        'user_nicename'     => $user->user_nicename,
        'user_display_name' => $user->display_name,
        'user_id'           => $user->ID,
        'is_new_user'       => $is_new_user
    ]);
}

/**
 * Handle Apple Login from the App
 */
function sk_apple_login_handler($request) {
    $identity_token = $request->get_param('identity_token');
    $apple_user_id = $request->get_param('user'); // Apple's unique user ID (sub)
    $email_param = $request->get_param('email');
    $full_name_param = $request->get_param('full_name');

    if (empty($identity_token)) {
        return new WP_Error('missing_token', 'Brak tokenu Apple', ['status' => 400]);
    }

    // 1. Decode & Verify Identity Token (JWT)
    $sections = explode('.', $identity_token);
    if (count($sections) !== 3) {
        return new WP_Error('invalid_token_format', 'Nieprawidłowy format tokenu Apple', ['status' => 400]);
    }

    $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $sections[1])), true);

    if (empty($payload)) {
        return new WP_Error('invalid_payload', 'Nie udało się zdekodować danych Apple', ['status' => 400]);
    }

    // 2. Security Checks
    // a. Verify Issuer
    if ($payload['iss'] !== 'https://appleid.apple.com') {
        return new WP_Error('invalid_issuer', 'Nieprawidłowy wystawca tokenu', ['status' => 403]);
    }

    // b. Verify Audience (Bundle ID)
    if ($payload['aud'] !== 'com.prawdziwamilosc.app') {
        return new WP_Error('invalid_audience', 'Token nie jest przeznaczony dla tej aplikacji', ['status' => 403]);
    }

    // c. Verify Expiration
    if (time() > $payload['exp']) {
        return new WP_Error('token_expired', 'Token Apple wygasł', ['status' => 401]);
    }

    // Use token data as source of truth for email and user id
    $apple_id = sanitize_text_field($payload['sub']);
    $email = isset($payload['email']) ? sanitize_email($payload['email']) : $email_param;

    // 3. Find or Create User
    $user_query = new WP_User_Query([
        'meta_key' => 'apple_user_id',
        'meta_value' => $apple_id,
        'number' => 1
    ]);

    $user = null;
    $is_new_user = false;

    if (!empty($user_query->get_results())) {
        $user = $user_query->get_results()[0];
    } else {
        // Try to match by email if available
        if (!empty($email)) {
            $user = get_user_by('email', $email);
            if ($user) {
                update_user_meta($user->ID, 'apple_user_id', $apple_id);
            }
        }

        if (!$user) {
            // New User Registration
            $is_new_user = true;
            
            // Try to extract first and last name from full name if present
            $first_name = '';
            $last_name = '';
            if (!empty($full_name_param)) {
                $name_parts = explode(' ', $full_name_param);
                $first_name = $name_parts[0];
                if (count($name_parts) > 1) {
                    unset($name_parts[0]);
                    $last_name = implode(' ', $name_parts);
                }
            }

            // Create a unique human-readable username
            $username = sk_generate_unique_username($first_name, $last_name, $email, 'apple');
            $random_password = wp_generate_password(12, true);
            
            // If email is missing (sometimes happens if user hides it, but identityToken usually has it)
            if (empty($email)) {
                $email = $username . '@apple-user.prawdziwamilosc.pl'; 
            }

            $user_id = wp_create_user($username, $random_password, $email);
            
            if (is_wp_error($user_id)) {
                return new WP_Error('user_creation_failed', $user_id->get_error_message(), ['status' => 500]);
            }

            // Wyślij email z powiadomieniem do administratora
            wp_new_user_notification($user_id, null, 'admin');
            
            $user = get_userdata($user_id);
            update_user_meta($user_id, 'apple_user_id', $apple_id);
            
            // Try to set display name from Apple fullName if provided (only provided on first login)
            if (!empty($full_name_param)) {
                $display_name = !empty($first_name) ? $first_name : sanitize_text_field($full_name_param);
                wp_update_user([
                    'ID' => $user_id,
                    'display_name' => $display_name
                ]);
                
                if (function_exists('xprofile_set_field_data')) {
                    xprofile_set_field_data(1, $user_id, $display_name); // Field ID 1 is Name in BuddyPress
                }
            }

            // Force onboarding
            update_user_meta($user_id, 'app_onboarding_complete', false);
        }
    }

    // 4. Generate JWT Token (Same logic as Google)
    $token = '';
    if (defined('JWT_AUTH_SECRET_KEY')) {
        $secret = JWT_AUTH_SECRET_KEY;
        $issued_at = time();
        $payload_jwt = [
            'iss' => get_bloginfo('url'),
            'iat' => $issued_at,
            'nbf' => $issued_at,
            'exp' => $issued_at + (DAY_IN_SECONDS * 30), // 30 days for Apple
            'data' => [
                'user' => [
                    'id' => $user->ID,
                ],
            ],
        ];

        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($payload_jwt)));
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);
        $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        $token = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    return rest_ensure_response([
        'token'             => $token,
        'user_email'        => $user->user_email,
        'user_nicename'     => $user->user_nicename,
        'user_display_name' => $user->display_name,
        'user_id'           => $user->ID,
        'is_new_user'       => $is_new_user
    ]);
}

function sk_update_onboarding($request) {
    $user_id = get_current_user_id();

    // --- DEBUG LOGGING to sk_debug.log ---
    $log_entry = "SK DEBUG [" . date('Y-m-d H:i:s') . "]: sk_update_onboarding called.\n";
    $log_entry .= "User ID: " . $user_id . "\n";
    $log_entry .= "Request Params: " . print_r($request->get_params(), true) . "\n";
    $log_entry .= "FILES: " . print_r($_FILES, true) . "\n";
    $log_entry .= "--------------------------------------------------\n";
    $sk_log = WP_CONTENT_DIR . '/uploads/temp-avatars/sk_debug.log';
    file_put_contents($sk_log, $log_entry, FILE_APPEND);
    // ---------------------
    if (!$user_id) {
        return new WP_Error('not_logged_in', 'Musisz być zalogowany', ['status' => 401]);
    }

    // --- FIELD MAPPING ---
    $id_data_urodzenia = 107;
    $id_kogo_szukam = 338;
    $id_gender = 129;
    $id_religia = 346;
    $id_polityka = 351;
    $id_praca = 356;
    $id_dieta = 362;

    // 1. Birthdate (BuddyPress specific format)
    // Frontend sends 'dataurodzenia' (YYYY-MM-DD or similar)
    $birthdate = $request->get_param('dataurodzenia');
    if (empty($birthdate)) {
        $birthdate = $request->get_param('birthdate'); // Fallback
    }

    if (!empty($birthdate)) {
        // Ensure format is compatible with BuddyPress (usually YYYY-MM-DD 00:00:00)
        $timestamp = strtotime($birthdate);
        if ($timestamp) {
            $datasql = date('Y-m-d 00:00:00', $timestamp);
            xprofile_set_field_data($id_data_urodzenia, $user_id, $datasql);
            // Log birthdate save
            $sk_log = WP_CONTENT_DIR . '/uploads/temp-avatars/sk_debug.log';
            file_put_contents($sk_log, "SK DEBUG: Saved Birthdate '$datasql' for User $user_id\n", FILE_APPEND);
        }
    }

    // 2. Simple fields - Keys MUST match User's Frontend formData keys!
    $simple_fields = [
        'gender'       => $id_gender,      // Try 'gender'
        'plec'         => $id_gender,      // Try 'plec' if frontend changes
        'kogo_szukam'  => $id_kogo_szukam, // Frontend sends 'kogo_szukam'
        'looking_for'  => $id_kogo_szukam, // Fallback
        'religia'      => $id_religia,     // Frontend sends 'religia'
        'religion'     => $id_religia,     // Fallback
        'polityka'     => $id_polityka,    // Frontend sends 'polityka'
        'politics'     => $id_polityka,    // Fallback
        'praca'        => $id_praca,       // Frontend sends 'praca'
        'work'         => $id_praca,       // Fallback
        'dieta'        => $id_dieta,       // Frontend sends 'dieta'
        'diet'         => $id_dieta        // Fallback
    ];

    // Translation Map (English -> Polish) AND (Polish -> Polish normalization)
    $translation_map = [
        // Gender
        'Woman' => 'Kobieta',
        'Man' => 'Mężczyzna',
        'Kobiety' => 'Kobieta', // Normalization just in case? No, 'Kobiety' is for 'Looking For'

        // Looking for
        'Women' => 'Kobiety',
        'Men' => 'Mężczyźni',
        'Wszyscy' => 'Wszyscy', // Explicitly allow
        'Everyone' => 'Wszyscy',

        // Faith
        'Believer' => 'Wierzący',
        'Atheist' => 'Ateista',
        'Spiritual but not religious' => 'Duchowy, ale nie religijny',
        'Spiritual' => 'Duchowy', // Assuming frontend sends "Duchowy" maps to "Duchowy" via in_array check
        'Duchowy' => 'Duchowy', // Explicit
        'Other' => 'Inne',
        
        // Politics
        'Conservative' => 'Konserwatywne',
        'Liberal' => 'Liberalne',
        'Centrist' => 'Centrowe',
        'Apolitical' => 'Apolityczny',
        
        // Work
        'Corporate' => 'Korporacja',
        'Own Business' => 'Własny Biznes',
        'Regular Job' => 'Normalna Praca',
        'Creative Work' => 'Praca Kreatywna',
        'Not working' => 'Nie pracuję',
        
        // Diet
        'Omnivore' => 'Wszystkożerca',
        'Vegetarian' => 'Wegetarianin',
        'Vegan' => 'Weganin',
        'Keto / Special' => 'Keto / Specjalistyczna',
        'Keto/Inne' => 'Keto / Specjalistyczna' // Frontend sends "Keto/Inne"
    ];

    foreach ($simple_fields as $param => $field_id) {
        $val = $request->get_param($param);
        if (!empty($val)) {
            // Helper function to translate value
            $translate_val = function($v) use ($translation_map) {
                // If the value is already in the map (as a value), return it. 
                // This handles cases where we receive "Duchowy" and the map is 'Spiritual' => 'Duchowy'.
                if (in_array($v, $translation_map)) {
                    return $v;
                }
                return isset($translation_map[$v]) ? $translation_map[$v] : $v;
            };

            // Handle arrays (multi-selects) vs strings
            if (is_array($val)) {
                $translated_val = array_map($translate_val, $val);
                // For arrays, BuddyPress typically expects an array of values
                $sanitized_val = $translated_val; 
            } else {
                $translated_val = $translate_val($val);
                $sanitized_val = sanitize_textarea_field($translated_val);
            }

            // ROBUST FIELD ID RESOLUTION
            $field_names_map = [
                'gender' => ['Płeć', 'Gender'],
                'looking_for' => ['Kogo szukasz?', 'Looking for'],
                'religion' => ['Podejście do wiary', 'Faith', 'Religion', 'Wiara'],
                'politics' => ['Poglądy polityczne', 'Politics', 'Political Views'],
                'work' => ['Styl pracy', 'Work', 'Job', 'Employment'],
                'diet' => ['Styl jedzenia', 'Diet', 'Eating Style']
            ];

            $final_field_id = $field_id; // Default to hardcoded
            if (isset($field_names_map[$param])) {
                $potential_names = $field_names_map[$param];
                // Prioritize the hardcoded ID if it exists
                $resolved_id = sk_get_field_id_robust($potential_names, [$field_id]);
                if ($resolved_id) {
                    $final_field_id = $resolved_id;
                }
            }

            // Log BEFORE saving
            $debug_val = is_array($sanitized_val) ? print_r($sanitized_val, true) : $sanitized_val;
            $log_msg = "SK DEBUG: Saving Field '$param' (Target ID: $final_field_id). Value: '$debug_val'";
            file_put_contents(__DIR__ . '/sk_debug.log', $log_msg . "\n", FILE_APPEND);

            $result = xprofile_set_field_data($final_field_id, $user_id, $sanitized_val);
            
            // Log AFTER saving
            $log_msg = "SK DEBUG: Save Result for '$param': " . ($result ? 'SUCCESS' : 'FAILURE');
            file_put_contents(__DIR__ . '/sk_debug.log', $log_msg . "\n", FILE_APPEND);
        }
    }

    // --- PHOTO DELETIONS ---
    $delete_photo_id = $request->get_param('delete_photo_id');
    if (!empty($delete_photo_id)) {
        $existing_ids = get_user_meta($user_id, 'user_profile_photos_ids', true);
        if (is_array($existing_ids)) {
            $new_ids = array_filter($existing_ids, function($id) use ($delete_photo_id) {
                return (int)$id !== (int)$delete_photo_id;
            });
            update_user_meta($user_id, 'user_profile_photos_ids', array_values($new_ids));
            
            // If the deleted photo was the avatar, reset it to the first remaining photo or empty
            $current_avatar_id = get_user_meta($user_id, 'user_avatar_id', true);
            if ((int)$current_avatar_id === (int)$delete_photo_id) {
                $new_avatar_id = !empty($new_ids) ? reset($new_ids) : 0;
                update_user_meta($user_id, 'user_avatar_id', $new_avatar_id);
            }
            
            error_log("SK Update Onboarding: Deleted photo ID $delete_photo_id for user $user_id");
        }
    }

    // --- AVATAR SWAP (SET AVATAR) ---
    $set_avatar_id = $request->get_param('set_avatar_id');
    if (!empty($set_avatar_id)) {
        // Robust check: Verify ownership via post_author instead of potentially stale meta list
        $photo_post = get_post((int)$set_avatar_id);
        if ($photo_post && (int)$photo_post->post_author === (int)$user_id) {
            update_user_meta($user_id, 'user_avatar_id', (int)$set_avatar_id);
            error_log("SK Update Onboarding: Swapped avatar to ID $set_avatar_id for user $user_id (Ownership Verified)");
        } else {
             error_log("SK Update Onboarding: Failed to swap avatar - ID $set_avatar_id not owned by user $user_id");
        }
    }

    // 3. Photos
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');

    $files = $request->get_file_params();
    error_log("SK Update Onboarding: Received files: " . print_r($files, true));
    $sk_log = WP_CONTENT_DIR . '/uploads/temp-avatars/sk_debug.log';
    file_put_contents($sk_log, "SK Update Onboarding: Received files: " . count($files) . " keys: " . implode(',', array_keys($files)) . "\n", FILE_APPEND);
    
    $profile_photos_ids = [];

    $first_uploaded_attach_id = null;

    for ($i = 1; $i <= 6; $i++) {
        $field_name = "photo_$i";
        if (!empty($files[$field_name]) && $files[$field_name]['error'] === UPLOAD_ERR_OK) {
            // Helper to handle the upload
            $_FILES[$field_name] = $files[$field_name];
            
            // SECURITY/CACHE-BUSTING: Ensure unique filename per user/time to defeat CDN/App caching completely
            $ext = pathinfo($_FILES[$field_name]['name'], PATHINFO_EXTENSION);
            if (empty($ext)) { $ext = 'jpg'; }
            $_FILES[$field_name]['name'] = 'user_' . $user_id . '_' . $field_name . '_' . time() . '.' . $ext;
            
            $attach_id = media_handle_upload($field_name, 0);

            if (is_wp_error($attach_id)) {
                error_log("SK Update Onboarding ERROR: media_handle_upload failed for $field_name: " . $attach_id->get_error_message());
                $sk_log = WP_CONTENT_DIR . '/uploads/temp-avatars/sk_debug.log';
                file_put_contents($sk_log, "SK ERROR: media_handle_upload failed for $field_name: " . $attach_id->get_error_message() . "\n", FILE_APPEND);
            } else {
                error_log("SK Update Onboarding: Successfully uploaded $field_name, attach_id: $attach_id");
                $sk_log = WP_CONTENT_DIR . '/uploads/temp-avatars/sk_debug.log';
                file_put_contents($sk_log, "SK SUCCESS: Successfully uploaded $field_name, attach_id: $attach_id\n", FILE_APPEND);
                wp_update_post([
                    'ID' => $attach_id,
                    'post_author' => $user_id
                ]);

                $profile_photos_ids[] = $attach_id;

                if (!$first_uploaded_attach_id) {
                    $first_uploaded_attach_id = $attach_id;
                }

                // First explicit photo slot ALWAYS updates avatar
                if ($i === 1) {
                    update_user_meta($user_id, 'user_avatar_id', $attach_id);
                }

                // rtMedia integration
                if (class_exists('RTMediaModel')) {
                    $rtmedia_model = new RTMediaModel();
                    $attachment = get_post($attach_id);
                    $rtmedia_data = [
                        'blog_id'      => get_current_blog_id(),
                        'media_id'     => $attach_id,
                        'media_author' => $user_id,
                        'media_title'  => $attachment->post_title,
                        'album_id'     => 0,
                        'context'      => 'profile',
                        'context_id'   => $user_id,
                        'media_type'   => 'photo',
                        'upload_date'  => current_time('mysql'),
                    ];
                    $rtmedia_model->insert($rtmedia_data);
                }
            }
        }
    }

    error_log("SK Update Onboarding: Data received: " . print_r($request->get_params(), true));
    $existing_ids = get_user_meta($user_id, 'user_profile_photos_ids', true);
    if (!is_array($existing_ids)) $existing_ids = [];

    if (!empty($profile_photos_ids)) {
        $all_ids = array_unique(array_merge($existing_ids, $profile_photos_ids));
        update_user_meta($user_id, 'user_profile_photos_ids', array_values($all_ids));
        error_log("SK Update Onboarding: Updated user_profile_photos_ids for user $user_id. Total photos: " . count($all_ids));
    }

    // 4. Finalize
    update_user_meta($user_id, 'app_onboarding_complete', true);

    return rest_ensure_response([
        'success' => true,
        'message' => 'Profil został uzupełniony',
        'onboarding_complete' => true
    ]);
}

/**
 * Helper to robustly find a field ID by name, falling back to a list of IDs.
 */
function sk_get_field_id_robust($possible_names, $fallback_ids = []) {
    if (!function_exists('xprofile_get_field_id_from_name')) {
        return !empty($fallback_ids) ? $fallback_ids[0] : false;
    }

    // 1. Priority: Check if the provided Fallback ID (User Preferred) exists
    global $wpdb;
    $bp = buddypress();
    if (isset($bp->profile->table_name_fields) && !empty($fallback_ids)) {
        foreach ($fallback_ids as $fid) {
            // Verify if this ID actually exists in the DB
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$bp->profile->table_name_fields} WHERE id = %d", $fid));
            if ($exists) {
                // If it exists, USE IT. This honors the explicit ID provided.
                return $fid; 
            }
        }
    }

    // 2. Fallback: Try to find by name if the ID was not found
    foreach ($possible_names as $name) {
        $id = xprofile_get_field_id_from_name($name);
        if ($id) return $id;
    }

    // 3. Last resort
    return !empty($fallback_ids) ? $fallback_ids[0] : false;
}

function sk_register_user_with_avatar($request) {
    $username = sanitize_user($request->get_param('user_login'));
    $email = sanitize_email($request->get_param('user_email'));
    $password = $request->get_param('password');
    
    // Walidacja
    if (empty($username) || empty($email) || empty($password)) {
        return new WP_Error('missing_fields', 'Wszystkie pola są wymagane', ['status' => 400]);
    }
    
    if (!is_email($email)) {
        return new WP_Error('invalid_email', 'Nieprawidłowy adres email', ['status' => 400]);
    }
    
    if (username_exists($username)) {
        return new WP_Error('username_exists', 'Nazwa użytkownika już istnieje', ['status' => 400]);
    }
    
    if (email_exists($email)) {
        return new WP_Error('email_exists', 'Email już jest zarejestrowany', ['status' => 400]);
    }
    
    if (strlen($password) < 6) {
        return new WP_Error('weak_password', 'Hasło musi mieć minimum 6 znaków', ['status' => 400]);
    }
    
    // Sprawdź czy jest avatar
    $files = $request->get_file_params();
    $has_avatar = !empty($files['avatar']) && $files['avatar']['error'] === UPLOAD_ERR_OK;
    
    error_log("SK Registration: username=$username, email=$email, has_avatar=" . ($has_avatar ? 'YES' : 'NO'));
    error_log("SK Registration: FILES count: " . count($files));
    if (!empty($files)) {
        error_log("SK Registration: FILES keys: " . implode(', ', array_keys($files)));
        foreach ($files as $key_name => $file_info) {
            error_log("SK Registration: File '$key_name' - name: {$file_info['name']}, error: {$file_info['error']}, size: {$file_info['size']}");
        }
    }
    
    // Zarejestruj przez BuddyPress signup
    global $wpdb;
    
    // Sprawdź czy użytkownik lub email już istnieje w wp_users (główna tabela WP)
    if (username_exists($username)) {
        error_log("SK Registration ERROR: Username '$username' already exists in wp_users.");
        return new WP_Error('registration_failed', "Użytkownik o tym loginie już istnieje.", ['status' => 400]);
    }
    if (email_exists($email)) {
        error_log("SK Registration ERROR: Email '$email' already exists in wp_users.");
        return new WP_Error('registration_failed', "Użytkownik o tym adresie email już istnieje.", ['status' => 400]);
    }
    
    // Sprawdź czy użytkownik już istnieje w tabeli signups (oczekujący na aktywację)
    // Usuń WSZYSTKIE wpisy z tym emailem lub username
    $existing_signups = $wpdb->get_results($wpdb->prepare(
        "SELECT signup_id, user_login, user_email FROM {$wpdb->prefix}signups WHERE user_login = %s OR user_email = %s",
        $username, $email
    ));
    
    if (!empty($existing_signups)) {
        error_log("SK Registration: Found " . count($existing_signups) . " existing signup(s) to delete");
        foreach ($existing_signups as $existing_signup) {
            error_log("SK Registration: Deleting signup - ID: {$existing_signup->signup_id}, login: {$existing_signup->user_login}, email: {$existing_signup->user_email}");
            $wpdb->delete(
                "{$wpdb->prefix}signups",
                ['signup_id' => $existing_signup->signup_id],
                ['%d']
            );
        }
        error_log("SK Registration: Deleted all old signup entries");
    }
    
    // Walidacja BuddyPress przed rejestracją
    if (function_exists('bp_core_validate_user_signup')) {
        $validation = bp_core_validate_user_signup($username, $email);
        if (is_wp_error($validation['errors']) && $validation['errors']->get_error_messages()) {
            $errors = $validation['errors']->get_error_messages();
            error_log("SK Registration: BuddyPress validation errors: " . implode(', ', $errors));
            // Kontynuuj mimo błędów walidacji (bo mogą być fałszywe pozytywne po usunięciu)
        }
    }

    // Get Gender
    $gender = $request->get_param('gender');
    
    $meta_args = [
        'field_1' => $username,
        'temp_password_for_activation' => $password // Zapisz czyste hasło do późniejszego ustawienia
    ];

    // Zapisz imię (Name, field ID 1) do pending_xprofile_data
    // Jest to konieczne, ponieważ hook moj_fix_przenoszenia_danych_rejestracji
    // patrzy na pending_xprofile_data, a jeśli ono istnieje, ignoruje 'field_1' z głównego meta.
    $pending_data = [ 1 => $username ];

    if (!empty($gender)) {
        // Save gender to pending_xprofile_data (Field ID 129 for Empaths)
        $pending_data[129] = $gender;
        error_log("SK Registration: Added gender '$gender' to pending_xprofile_data");
    }
    
    $meta_args['pending_xprofile_data'] = $pending_data;

    $signup_id = bp_core_signup_user(
        $username,
        $password,
        $email,
        $meta_args
    );
    
    global $wpdb;
    error_log("SK Registration: bp_core_signup_user result: " . var_export($signup_id, true));

    if (is_wp_error($signup_id)) {
        error_log("SK Registration Failed: " . $signup_id->get_error_message());
        return new WP_Error('registration_failed', $signup_id->get_error_message(), ['status' => 500]);
    }

    if (!$signup_id) {
        if ($wpdb->last_error) {
            error_log("SK Registration: Database Error during signup: " . $wpdb->last_error);
        }
        
        // Sprawdź czy wpis ZOSTAŁ UTWORZONY mimo że funkcja zwróciła false
        $created_signup = $wpdb->get_row($wpdb->prepare(
            "SELECT signup_id, activation_key FROM {$wpdb->prefix}signups WHERE user_login = %s AND user_email = %s ORDER BY registered DESC LIMIT 1",
            $username, $email
        ));
        
        if ($created_signup) {
            // Wpis istnieje - rejestracja się UDAŁA, funkcja tylko zwróciła zły result
            error_log("SK Registration: bp_core_signup_user returned false but signup WAS created (ID: {$created_signup->signup_id}). Treating as SUCCESS.");
            $signup_id = $created_signup->signup_id;
            // Kontynuuj normalnie - nie zwracaj błędu
        } else {
            // Naprawdę nie ma wpisu - to jest prawdziwy błąd
            error_log("SK Registration ERROR: bp_core_signup_user returned false and no signup was created.");
            return new WP_Error('registration_failed_silently', 'Rejestracja nie powiodła się. Proszę spróbować ponownie.', ['status' => 500]);
        }
    }
    
    // Dodatkowo zaktualizuj meta z hasłem
    global $wpdb;
    $signup_query = $wpdb->prepare(
        "SELECT signup_id, meta FROM {$wpdb->prefix}signups WHERE user_login = %s",
        $username
    );
    error_log("SK Registration: Signup query: $signup_query");
    $signup_row = $wpdb->get_row($signup_query);
    
    if ($signup_row) {
        $meta = maybe_unserialize($signup_row->meta);
        if (!is_array($meta)) {
            $meta = [];
        }
        $meta['temp_password_for_activation'] = $password;
        
        $wpdb->update(
            $wpdb->prefix . 'signups',
            ['meta' => maybe_serialize($meta)],
            ['signup_id' => $signup_row->signup_id]
        );
    }
    
    // Jeśli jest avatar, zapisz go tymczasowo z activation key
    if ($has_avatar) {
        $signup = $wpdb->get_row($wpdb->prepare(
            "SELECT activation_key FROM {$wpdb->prefix}signups WHERE user_login = %s OR signup_id = %d",
            $username,
            $signup_id
        ));
        
        if ($signup && $signup->activation_key) {
            $key_to_use = $signup->activation_key;
            $upload_dir = wp_upload_dir();
            $temp_dir = $upload_dir['basedir'] . '/temp-avatars/';
            
            error_log("SK Registration: Attempting to save avatar for key: " . $key_to_use);
            $sk_log = WP_CONTENT_DIR . '/uploads/temp-avatars/sk_debug.log';
            file_put_contents($sk_log, "SK Registration: Saving avatar for key: $key_to_use\n", FILE_APPEND);
            
            if (!file_exists($temp_dir)) {
                if (wp_mkdir_p($temp_dir)) {
                    error_log("SK Registration: Created temp dir $temp_dir");
                    chmod($temp_dir, 0755);
                } else {
                    error_log("SK Registration ERROR: Failed to create temp dir $temp_dir");
                }
            }
            
            $ext = pathinfo($files['avatar']['name'], PATHINFO_EXTENSION);
            if (empty($ext)) $ext = 'jpg';
            
            $temp_file = $temp_dir . $signup->activation_key . '.' . $ext;
            error_log("SK Registration: Target temp file path: $temp_file");
            
            if (move_uploaded_file($files['avatar']['tmp_name'], $temp_file)) {
                error_log("SK Registration: Avatar saved successfully to $temp_file. File size: " . filesize($temp_file));
                chmod($temp_file, 0644);
            } else {
                error_log("SK Registration ERROR: move_uploaded_file failed. SRC: " . $files['avatar']['tmp_name'] . " DEST: $temp_file. Error: " . print_r(error_get_last(), true));
            }
        } else {
            error_log("SK Registration ERROR: Could not find activation_key for $username (ID: $signup_id)");
        }
    } else {
        error_log("SK Registration: No avatar in request or upload error. " . print_r($files, true));
    }
    
    return rest_ensure_response([
        'success' => true,
        'message' => 'Konto zostało utworzone. Sprawdź email aby aktywować konto.',
        'username' => $username,
        'email' => $email,
        'requires_activation' => true,
    ]);
}

// Hook do ustawienia avatara po aktywacji
add_action('bp_core_activated_user', 'sk_set_avatar_after_activation', 10, 3);
function sk_set_avatar_after_activation($user_id, $key, $user) {
    error_log("sk_set_avatar_after_activation called for user_id: $user_id, key: $key");
    
    $upload_dir = wp_upload_dir();
    $temp_dir = $upload_dir['basedir'] . '/temp-avatars/';
    
    // Listuj wszystkie pliki w katalogu dla debugowania
    if (file_exists($temp_dir)) {
        $all_temp_files = glob($temp_dir . '*');
        error_log("SK Activation: All files in temp-avatars (" . count($all_temp_files) . "): " . print_r($all_temp_files, true));
    } else {
        error_log("SK Activation ERROR: Temp dir does NOT exist: $temp_dir");
    }

    // Szukaj pliku z tym activation key (to jest klucz z maila)
    $files = glob($temp_dir . $key . '.*');
    error_log("SK Activation: Searching for pattern: " . $temp_dir . $key . '.*');
    error_log("SK Activation: glob() result: " . print_r($files, true));
    
    if (empty($files)) {
        // Fallback: search for ANY file in case the key is slightly different but timestamp matches
        $all_files = glob($temp_dir . '*');
        error_log("SK Activation: FALLBACK - All files in temp_dir: " . print_r($all_files, true));
    }

    if (!empty($files)) {
        $temp_file = $files[0];
        error_log("Found temp file: $temp_file. Size: " . filesize($temp_file));
        
        // 1. Dodaj plik do WordPress Media Library
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        
        $ext = pathinfo($temp_file, PATHINFO_EXTENSION);
        $filename = 'avatar-' . $user_id . '-' . time() . '.' . $ext;
        $new_file_path = $upload_dir['path'] . '/' . $filename;
        
        // Kopiuj plik (nie przenoś, bo potrzebujemy go jeszcze dla BuddyPress)
        copy($temp_file, $new_file_path);
        
        // Sprawdź typ pliku
        $filetype = wp_check_filetype($filename, null);
        
        // Stwórz attachment w Media Library
        $attachment = array(
            'guid'           => $upload_dir['url'] . '/' . $filename,
            'post_mime_type' => $filetype['type'],
            'post_title'     => 'Avatar użytkownika ' . $user_id,
            'post_content'   => '',
            'post_status'    => 'inherit',
            'post_author'    => $user_id
        );
        
        $attach_id = wp_insert_attachment($attachment, $new_file_path);
        
        if (!is_wp_error($attach_id) && $attach_id) {
            error_log("SK Activation: Successfully created attachment $attach_id");
            // Wygeneruj metadata dla attachment
            $attach_data = wp_generate_attachment_metadata($attach_id, $new_file_path);
            wp_update_attachment_metadata($attach_id, $attach_data);
            
            // Dodaj też do listy zdjęć profilowych
            $existing_ids = get_user_meta($user_id, 'user_profile_photos_ids', true);
            if (!is_array($existing_ids)) {
                $existing_ids = [];
            }
            
            // If we are setting a NEW avatar ID, remove the OLD one from the gallery to avoid duplication
            $old_avatar_id = get_user_meta($user_id, 'user_avatar_id', true);
            if ($old_avatar_id && in_array($old_avatar_id, $existing_ids)) {
                $existing_ids = array_filter($existing_ids, function($id) use ($old_avatar_id) {
                    return (int)$id !== (int)$old_avatar_id;
                });
            }

            if (!in_array($attach_id, $existing_ids)) {
                $existing_ids[] = $attach_id;
                update_user_meta($user_id, 'user_profile_photos_ids', array_values($existing_ids));
                error_log("SK Activation: Added $attach_id to user_profile_photos_ids and removed old if existed");
            }
            
            // 2. Ustaw user_avatar_id w user meta - TO JEST KLUCZOWE!
            update_user_meta($user_id, 'user_avatar_id', $attach_id);
            error_log("Set user_avatar_id to: $attach_id for user: $user_id");
            
            // 3. Integracja z rtMedia (jeśli dostępne)
            if (class_exists('RTMediaModel')) {
                $rtmedia_model = new RTMediaModel();
                $rtmedia_data = array(
                    'blog_id'       => get_current_blog_id(),
                    'media_id'      => $attach_id,
                    'media_author'  => $user_id,
                    'media_title'   => 'Avatar',
                    'album_id'      => 0,
                    'context'       => 'profile',
                    'context_id'    => $user_id,
                    'activity_id'   => 0,
                    'privacy'       => 0,
                    'media_type'    => 'photo',
                    'upload_date'   => current_time('mysql'),
                );
                $rtmedia_model->insert($rtmedia_data);
                error_log("Added avatar to rtMedia for user: $user_id");
            }
        } else {
            if (is_wp_error($attach_id)) {
                error_log("SK Activation ERROR: Failed to create attachment: " . $attach_id->get_error_message());
            } else {
                error_log("SK Activation ERROR: Failed to create attachment (unknown error)");
            }
        }
        
        // 4. Ustaw jako avatar BuddyPress (oryginalna logika)
        $avatar_dir = bp_core_avatar_upload_path() . '/avatars/' . $user_id . '/';
        wp_mkdir_p($avatar_dir);
        error_log("Avatar dir: $avatar_dir");
        
        // Standardowe nazwy plików BuddyPress
        $avatar_full = $avatar_dir . $user_id . '-bpfull.' . $ext;
        $avatar_thumb = $avatar_dir . $user_id . '-bpthumb.' . $ext;
        
        error_log("Avatar full path: $avatar_full");
        error_log("Avatar thumb path: $avatar_thumb");
        
        // Stwórz miniatury
        $image = wp_get_image_editor($temp_file);
        if (!is_wp_error($image)) {
            // Full size (300x300)
            $image->resize(300, 300, true);
            $image->save($avatar_full);
            error_log("SK Activation: Saved full avatar to $avatar_full");
            
            // Thumb size (100x100)
            $image = wp_get_image_editor($temp_file);
            $image->resize(100, 100, true);
            $image->save($avatar_thumb);
            error_log("SK Activation: Saved thumb avatar to $avatar_thumb");
        } else {
            error_log("Image editor error: " . $image->get_error_message());
        }
        
        // Usuń plik tymczasowy
        unlink($temp_file);
        error_log("Deleted temp file");
        
        // Czyść cache użytkownika aby zmiany były widoczne natychmiast
        clean_user_cache($user_id);
        if (function_exists('bp_core_clear_cache')) {
            bp_core_clear_cache();
        }
        error_log("Cleared cache for user: $user_id after avatar activation");
    } else {
        error_log("No avatar file found for key: $key in $temp_dir");
    }
}

// ============================================================================
// MOBILE DEEP LINKING DLA STRONY AKTYWACJI
// ============================================================================

add_action('wp_footer', 'sk_mobile_activation_redirect');
function sk_mobile_activation_redirect() {
    // Sprawdź czy jesteśmy na stronie aktywacji BuddyPress
    if (strpos($_SERVER['REQUEST_URI'], 'activate') === false && strpos($_SERVER['REQUEST_URI'], 'aktywacja') === false) {
        return;
    }
    
    $activation_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
    ?>
    <style>
        .sk-activation-buttons {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-top: 25px;
            align-items: center;
        }
        .sk-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 14px 24px;
            border-radius: 30px;
            text-decoration: none !important;
            font-weight: bold;
            width: 100%;
            max-width: 300px;
            transition: transform 0.2s, box-shadow 0.2s;
            text-align: center;
            font-size: 16px;
        }
        .sk-btn:active {
            transform: scale(0.98);
        }
        .sk-btn-app {
            background: linear-gradient(45deg, #FF6B9D, #C06C84);
            color: white !important;
            box-shadow: 0 4px 15px rgba(255, 107, 157, 0.3);
        }
        .sk-btn-web {
            background: #f0f2f5;
            color: #333 !important;
            border: 1px solid #ddd;
        }
        .sk-btn i {
            margin-right: 10px;
            font-size: 20px;
        }
    </style>
    <script>
    (function() {
        console.log('SK Activation Script: Running');
        document.addEventListener('DOMContentLoaded', function() {
            // Szukaj komunikatu o sukcesie lub linku do logowania po aktywacji
            // Szukamy linków, które zawierają "logowanie" lub "login" w URL, 
            // lub mają tekst "Zaloguj" / "Zaloguj się"
            var links = document.querySelectorAll('a');
            console.log('SK Activation Script: Found ' + links.length + ' links total');
            
            var found = false;
            links.forEach(function(link) {
                var text = link.textContent.trim();
                var href = link.href.toLowerCase();
                
                // Sprawdź czy link wygląda na link do logowania po aktywacji
                if (
                    (text === 'Zaloguj' || text === 'Zaloguj się' || text === 'Sign In' || text === 'Log In') ||
                    (href.indexOf('logowanie') !== -1 || href.indexOf('login') !== -1)
                ) {
                    // Dodatkowe sprawdzenie, żeby nie podmienić linków w menu (jeśli są)
                    // Zwykle link BuddyPress jest wewnątrz kontenera #activate-page lub .activate-status
                    if (link.closest('#activate-page') || link.closest('.bp-template-notice') || link.closest('.activate-status') || links.length < 20) {
                        console.log('SK Activation Script: Matching link found: ', text, href);
                        
                        var container = document.createElement('div');
                        container.className = 'sk-activation-buttons';
                        
                        // Przycisk APKA
                        var appBtn = document.createElement('a');
                        appBtn.href = 'prawdziwamilosc://login';
                        appBtn.className = 'sk-btn sk-btn-app';
                        appBtn.innerHTML = '📱 Zaloguj się przez Aplikację';
                        
                        // Przycisk WEB
                        var webBtn = document.createElement('a');
                        webBtn.href = href || '/logowanie';
                        webBtn.className = 'sk-btn sk-btn-web';
                        webBtn.innerHTML = '🌐 Zaloguj przez Web';
                        
                        container.appendChild(appBtn);
                        container.appendChild(webBtn);
                        
                        // Ukryj stary link i wstaw nowe przyciski
                        link.style.display = 'none';
                        link.parentNode.insertBefore(container, link.nextSibling);
                        found = true;
                    }
                }
            });
            
            if (!found) {
                console.log('SK Activation Script: No specific activation login link found. Attempting fallback append.');
                // Fallback: Dodaj przyciski na końcu głównego kontenera BuddyPress
                var bpContainer = document.querySelector('#buddypress, #activate-page, .activate-status, .bp-template-notice');
                if (bpContainer) {
                    var container = document.createElement('div');
                    container.className = 'sk-activation-buttons';
                    container.style.marginTop = '20px';
                    
                    var appBtn = document.createElement('a');
                    appBtn.href = 'prawdziwamilosc://login';
                    appBtn.className = 'sk-btn sk-btn-app';
                    appBtn.innerHTML = '📱 Zaloguj się przez Aplikację';
                    
                    var webBtn = document.createElement('a');
                    webBtn.href = '/logowanie';
                    webBtn.className = 'sk-btn sk-btn-web';
                    webBtn.innerHTML = '🌐 Zaloguj przez Web';
                    
                    container.appendChild(appBtn);
                    container.appendChild(webBtn);
                    bpContainer.appendChild(container);
                    console.log('SK Activation Script: Fallback append successful');
                }
            }
            
            // Auto-redirect do apki jeśli wykryto klucz i mobile (stara logika)
            var isMobile = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent);
            var activationKey = '<?php echo esc_js($activation_key); ?>';
            
            if (isMobile && activationKey && window.location.search.indexOf('key=') !== -1) {
                console.log('SK Activation Script: Mobile detected with key, attempting auto-redirect');
                var appUrl = 'prawdziwamilosc://activate?key=' + activationKey;
                setTimeout(function() {
                    window.location.href = appUrl;
                }, 500);
            }
        });
    })();
    </script>
    <?php
}

// ========================================
// Custom Send Message Endpoint (via Better Messages / BuddyPress)
// ========================================
add_action('rest_api_init', function () {
    register_rest_route('sk/v1', '/send-message', [
        'methods' => 'POST',
        'callback' => 'sk_send_message_endpoint',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
});

/**
 * Send a message to a user (creates new thread or replies to existing)
 */
function sk_send_message_endpoint($request) {
    $sender_id = get_current_user_id();
    $recipient_id = intval($request->get_param('recipient_id'));
    $message = sanitize_textarea_field($request->get_param('message'));
    $subject = sanitize_text_field($request->get_param('subject')) ?: 'Nowa wiadomość';
    
    error_log("sk_send_message_endpoint: sender=$sender_id, recipient=$recipient_id, message=$message");
    
    if (!$sender_id) {
        return new WP_Error('not_logged_in', 'User must be logged in', ['status' => 401]);
    }
    
    if (!$recipient_id) {
        return new WP_Error('invalid_recipient', 'Recipient ID is required', ['status' => 400]);
    }

    // SAFETY CHECK: Blocking
    // 1. Did I block this user?
    $my_blocked = get_user_meta($sender_id, 'sk_blocked_users', true) ?: [];
    if (is_array($my_blocked) && in_array($recipient_id, $my_blocked)) {
        return new WP_Error('user_blocked', 'You have blocked this user', ['status' => 403]);
    }
    // 2. Did this user block me?
    $their_blocked = get_user_meta($recipient_id, 'sk_blocked_users', true) ?: [];
    if (is_array($their_blocked) && in_array($sender_id, $their_blocked)) {
        return new WP_Error('user_blocked_you', 'Message not delivered', ['status' => 403]);
    }

    // 3. Shadow Ban: Is recipient hidden?
    if (get_user_meta($recipient_id, 'sk_is_hidden', true) === '1') {
        return new WP_Error('user_not_found', 'Recipient not found', ['status' => 404]);
    }
    
    if (empty($message)) {
        return new WP_Error('empty_message', 'Message cannot be empty', ['status' => 400]);
    }
    
    // Use BuddyPress messages_new_message - this works with Better Messages plugin
    if (function_exists('messages_new_message')) {
        $thread_id = messages_new_message([
            'sender_id' => $sender_id,
            'recipients' => [$recipient_id],
            'subject' => $subject,
            'content' => $message,
        ]);
        
        if (!$thread_id) {
            error_log("messages_new_message failed for sender=$sender_id, recipient=$recipient_id");
            return new WP_Error('send_failed', 'Failed to send message', ['status' => 500]);
        }

        error_log("Message sent successfully, thread_id: $thread_id");

        // Release client early if possible
        if (function_exists('fastcgi_finish_request')) {
            echo json_encode([
                'success' => true,
                'thread_id' => $thread_id,
                'message' => 'Message sent successfully'
            ]);
            fastcgi_finish_request();
        }

        return rest_ensure_response([
            'success' => true,
            'thread_id' => $thread_id,
            'message' => 'Message sent successfully'
        ]);
    }
    
    return new WP_Error('no_messaging_system', 'BuddyPress messaging not available', ['status' => 500]);
}

// ========================================
// Super Wiadomość (Super Message) Feature
// Premium users can send messages without matching
// ========================================

add_action('rest_api_init', function () {
    // Send Super Message
    register_rest_route('sk/v1', '/super-message/send', [
        'methods' => 'POST',
        'callback' => 'sk_super_message_send',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
    
    // Respond to Super Message
    register_rest_route('sk/v1', '/super-message/respond', [
        'methods' => 'POST',
        'callback' => 'sk_super_message_respond',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
    
    // Get inbox (received Super Messages)
    register_rest_route('sk/v1', '/super-message/inbox', [
        'methods' => 'GET',
        'callback' => 'sk_super_message_inbox',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
    
    // Get status (sent messages + remaining count)
    register_rest_route('sk/v1', '/super-message/status', [
        'methods' => 'GET',
        'callback' => 'sk_super_message_status',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
    
    // Admin: Reset Super Message data (for testing)
    register_rest_route('sk/v1', '/super-message/reset', [
        'methods' => 'POST',
        'callback' => 'sk_super_message_reset',
        'permission_callback' => function() {
            return current_user_can('administrator');
        }
    ]);

    // Presence Update
    register_rest_route('sk/v1', '/presence/update', [
        'methods' => 'POST',
        'callback' => 'sk_update_presence_endpoint',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
    
    // Delete User Account - Required by Apple App Store
    register_rest_route('sk/v1', '/delete-account', [
        'methods' => 'DELETE',
        'callback' => 'sk_delete_user_account',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
});

/**
 * Delete User Account - Required by Apple App Store
 * Deletes user and all associated data (profile, messages, likes, etc.)
 */
function sk_delete_user_account(WP_REST_Request $request) {
    $user_id = get_current_user_id();
    
    if (!$user_id) {
        return new WP_Error('not_logged_in', 'Musisz być zalogowany', ['status' => 401]);
    }
    
    // Get user data for email notification
    $user = get_userdata($user_id);
    $user_email = $user->user_email;
    $user_name = $user->display_name;
    
    // 1. Delete Better Messages conversations
    if (class_exists('Better_Messages')) {
        global $wpdb;
        
        // Get all thread IDs where user is a participant
        $threads = $wpdb->get_col($wpdb->prepare(
            "SELECT thread_id FROM {$wpdb->prefix}bm_recipients WHERE user_id = %d",
            $user_id
        ));
        
        // Delete messages sent by this user
        $wpdb->delete(
            $wpdb->prefix . 'bm_messages',
            ['sender_id' => $user_id],
            ['%d']
        );
        
        // Delete recipient entries
        $wpdb->delete(
            $wpdb->prefix . 'bm_recipients',
            ['user_id' => $user_id],
            ['%d']
        );
    }
    
    // 2. Delete BuddyPress data
    if (function_exists('bp_is_active')) {
        // Delete xProfile data
        if (bp_is_active('xprofile')) {
            global $wpdb;
            $bp = buddypress();
            $wpdb->delete($bp->profile->table_name_data, ['user_id' => $user_id], ['%d']);
        }
        
        // Delete activity
        if (bp_is_active('activity') && function_exists('bp_activity_delete')) {
            bp_activity_delete(['user_id' => $user_id]);
        }
        
        // Delete friends/friendships
        if (bp_is_active('friends') && function_exists('friends_remove_friend')) {
            global $wpdb;
            $bp = buddypress();
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$bp->friends->table_name} WHERE initiator_user_id = %d OR friend_user_id = %d",
                $user_id, $user_id
            ));
        }
        
        // Delete messages (BP private messages if not using Better Messages)
        if (bp_is_active('messages')) {
            global $wpdb;
            $bp = buddypress();
            // Delete from recipients
            $wpdb->delete($bp->messages->table_name_recipients, ['user_id' => $user_id], ['%d']);
            // Delete messages sent
            $wpdb->delete($bp->messages->table_name_messages, ['sender_id' => $user_id], ['%d']);
        }
        
        // Delete notifications
        if (bp_is_active('notifications') && function_exists('bp_notifications_delete_notifications_by_type')) {
            global $wpdb;
            $bp = buddypress();
            $wpdb->delete($bp->notifications->table_name, ['user_id' => $user_id], ['%d']);
        }
    }
    
    // 3. Delete likes data
    delete_user_meta($user_id, 'sk_liked_users');
    delete_user_meta($user_id, 'sk_matches');
    delete_user_meta($user_id, 'sk_skipped_users');
    delete_user_meta($user_id, 'sk_compatibility_cache');
    delete_user_meta($user_id, 'sk_super_message_last_sent');
    delete_user_meta($user_id, 'sk_super_message_pending_from');
    
    // 4. Remove this user from other users' likes/matches
    global $wpdb;
    $all_users = $wpdb->get_col("SELECT ID FROM {$wpdb->users}");
    foreach ($all_users as $other_user_id) {
        $liked = get_user_meta($other_user_id, 'sk_liked_users', true);
        if (is_array($liked) && in_array($user_id, $liked)) {
            $liked = array_diff($liked, [$user_id]);
            update_user_meta($other_user_id, 'sk_liked_users', array_values($liked));
        }
        
        $matches = get_user_meta($other_user_id, 'sk_matches', true);
        if (is_array($matches) && in_array($user_id, $matches)) {
            $matches = array_diff($matches, [$user_id]);
            update_user_meta($other_user_id, 'sk_matches', array_values($matches));
        }
    }
    
    // 5. Delete avatar files
    if (function_exists('bp_core_delete_existing_avatar')) {
        bp_core_delete_existing_avatar(['item_id' => $user_id, 'object' => 'user']);
    }
    
    // 6. Delete hi-res avatars
    $hires_id = get_user_meta($user_id, 'hires_avatar_attachment_id', true);
    if ($hires_id) {
        wp_delete_attachment($hires_id, true);
    }
    
    // 7. Send confirmation email
    $subject = 'Potwierdzenie usunięcia konta - Prawdziwa Miłość';
    $message = "Cześć {$user_name},\n\n";
    $message .= "Potwierdzamy, że Twoje konto w serwisie Prawdziwa Miłość zostało pomyślnie usunięte.\n\n";
    $message .= "Usunięte zostały:\n";
    $message .= "- Wszystkie dane profilowe\n";
    $message .= "- Historia wiadomości\n";
    $message .= "- Polubienia i pary\n";
    $message .= "- Przesłane zdjęcia\n\n";
    $message .= "Jeśli chcesz wrócić do serwisu, zawsze możesz założyć nowe konto.\n\n";
    $message .= "Życzymy wszystkiego dobrego!\n";
    $message .= "Zespół Prawdziwa Miłość";
    
    wp_mail($user_email, $subject, $message);
    
    // 8. Finally, delete the WordPress user
    require_once(ABSPATH . 'wp-admin/includes/user.php');
    $result = wp_delete_user($user_id);
    
    if ($result) {
        return new WP_REST_Response([
            'success' => true,
            'message' => 'Konto zostało pomyślnie usunięte'
        ], 200);
    } else {
        return new WP_Error('delete_failed', 'Nie udało się usunąć konta', ['status' => 500]);
    }
}

/**
 * Check if user is premium
 */
function sk_is_premium_user($user_id) {
    // Check if user has premium role or membership
    $user = get_userdata($user_id);
    if (!$user) return false;
    
    // Check if ANY role contains 'premium' (case-insensitive)
    $user_roles = (array)$user->roles;
    foreach ($user_roles as $role) {
        // Convert to lowercase for comparison
        $role_lower = strtolower($role);
        if (strpos($role_lower, 'premium') !== false) {
            return true;
        }
    }
    
    // Check BuddyPress Member Type (Rodzaj członka)
    if (function_exists('bp_get_member_type')) {
        $member_type = bp_get_member_type($user_id);
        if ($member_type && (stripos($member_type, 'premium') !== false)) {
            return true;
        }
        // Also check for array of types
        $member_types = bp_get_member_type($user_id, false);
        if (is_array($member_types)) {
            foreach ($member_types as $type) {
                if (stripos($type, 'premium') !== false) {
                    return true;
                }
            }
        }
    }
    
    // Check PaidMembershipsPro
    if (function_exists('pmpro_hasMembershipLevel')) {
        if (pmpro_hasMembershipLevel(null, $user_id)) {
            return true;
        }
    }

    return false;
}


/**
 * Get remaining Super Messages for this week (rolling 7 days)
 */
function sk_get_remaining_super_messages($user_id) {
    $week_data = get_user_meta($user_id, 'sk_super_messages_week', true);
    $now = time();
    $week_ago = $now - (7 * 24 * 60 * 60);
    
    if (!is_array($week_data)) {
        $week_data = [];
    }
    
    // Filter to only messages sent in last 7 days
    $recent = array_filter($week_data, function($timestamp) use ($week_ago) {
        return $timestamp > $week_ago;
    });
    
    $used = count($recent);
    $limit = 3;
    
    return max(0, $limit - $used);
}

/**
 * Record a Super Message sent
 */
function sk_record_super_message_sent($user_id) {
    $week_data = get_user_meta($user_id, 'sk_super_messages_week', true);
    if (!is_array($week_data)) {
        $week_data = [];
    }
    $week_data[] = time();
    update_user_meta($user_id, 'sk_super_messages_week', $week_data);
}

/**
 * Check cooldown for specific recipient
 */
function sk_check_cooldown($sender_id, $recipient_id) {
    $cooldowns = get_user_meta($sender_id, 'sk_super_message_cooldowns', true);
    if (!is_array($cooldowns)) {
        return false; // No cooldown
    }
    
    $key = 'user_' . $recipient_id;
    if (isset($cooldowns[$key])) {
        $cooldown_until = strtotime($cooldowns[$key]);
        if (time() < $cooldown_until) {
            return $cooldowns[$key]; // Return cooldown end date
        }
    }
    
    return false;
}

/**
 * Set cooldown for recipient (7 days)
 */
function sk_set_cooldown($sender_id, $recipient_id) {
    $cooldowns = get_user_meta($sender_id, 'sk_super_message_cooldowns', true);
    if (!is_array($cooldowns)) {
        $cooldowns = [];
    }
    
    $key = 'user_' . $recipient_id;
    $cooldowns[$key] = date('c', time() + (7 * 24 * 60 * 60)); // 7 days from now
    update_user_meta($sender_id, 'sk_super_message_cooldowns', $cooldowns);
}

/**
 * Helper to find existing private thread between two users
 */
function sk_get_existing_thread_id($user_id, $recipient_id) {
    global $wpdb;

    // 1. Try Better Messages Native Function first
    if (class_exists('Better_Messages') && function_exists('Better_Messages')) {
        try {
            // Better Messages usually has internal functions to get private thread
            // This is a robust way to ask the plugin itself
            if (method_exists(Better_Messages()->functions, 'get_private_thread_id')) {
                $bm_thread_id = Better_Messages()->functions->get_private_thread_id($user_id, $recipient_id);
                if ($bm_thread_id) return (int)$bm_thread_id;
            }
        } catch (Exception $e) {
            error_log('sk_get_existing_thread_id: BM Exception: ' . $e->getMessage());
        }
    }

    // 2. Fallback to BuddyPress table query
    $bp = buddypress();
    if (!isset($bp->messages->table_name_recipients)) return null;
    
    $table_name = $bp->messages->table_name_recipients;
    
    // Find a thread with EXACTLY these two users
    $thread_id = $wpdb->get_var($wpdb->prepare("
        SELECT r1.thread_id
        FROM {$table_name} r1
        JOIN {$table_name} r2 ON r1.thread_id = r2.thread_id
        WHERE r1.user_id = %d 
        AND r2.user_id = %d
        AND (SELECT COUNT(*) FROM {$table_name} WHERE thread_id = r1.thread_id) = 2
        LIMIT 1
    ", $user_id, $recipient_id));
    
    return $thread_id ? (int)$thread_id : null;
}

/**
 * Send Super Message endpoint
 */
function sk_super_message_send($request) {
    if (!defined('SK_BYPASS_MATCH_CHECK')) {
        define('SK_BYPASS_MATCH_CHECK', true);
    }
    $sender_id = get_current_user_id();
    $recipient_id = intval($request->get_param('to_user_id'));
    $message = sanitize_textarea_field($request->get_param('message'));
    
    // Check if premium
    if (!sk_is_premium_user($sender_id)) {
        return new WP_Error('not_premium', 'Tylko użytkownicy Premium mogą wysyłać Super Wiadomości', ['status' => 403]);
    }
    
    // Check weekly limit
    $remaining = sk_get_remaining_super_messages($sender_id);
    if ($remaining <= 0) {
        return new WP_Error('weekly_limit_reached', 'Wykorzystałeś limit 3 Super Wiadomości na ten tydzień', ['status' => 429]);
    }
    
    // Check cooldown for this recipient
    $cooldown = sk_check_cooldown($sender_id, $recipient_id);
    if ($cooldown) {
        return new WP_Error('cooldown_active', 'Musisz poczekać do ' . $cooldown . ' przed wysłaniem kolejnej wiadomości do tego użytkownika', ['status' => 429]);
    }
    
    // Check if already sent pending message to this user
    $sent = get_user_meta($sender_id, 'sk_super_messages_sent', true);
    if (is_array($sent)) {
        foreach ($sent as $msg) {
            // TEMPORARY: Allow sending again for debugging
            // if ($msg['to'] == $recipient_id && $msg['status'] === 'pending') {
            //     return new WP_Error('already_sent', 'Masz już oczekującą Super Wiadomość do tego użytkownika', ['status' => 400]);
            // }
        }
    }

    // Validate message
    if (empty($message) || strlen($message) < 10) {
        return new WP_Error('message_too_short', 'Wiadomość musi mieć minimum 10 znaków', ['status' => 400]);
    }
    
    if (strlen($message) > 500) {
        return new WP_Error('message_too_long', 'Wiadomość może mieć maksimum 500 znaków', ['status' => 400]);
    }
    
    // Generate message ID
    $message_id = 'sm_' . $sender_id . '_' . $recipient_id . '_' . time();
    
    // ===== SEND VIA BETTER MESSAGES (or BuddyPress fallback) =====
    $bp_message_id = false;
    $bp_thread_id = null;
    $sender = get_userdata($sender_id);
    $sender_name = $sender ? $sender->display_name : 'Ktoś';
    
    $message_content = '<div class="super-message-content" style="background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%); border: 2px solid #FFD700; border-radius: 12px; padding: 16px; color: #fff; margin-bottom: 12px;">
<div style="color: #FFD700; font-weight: bold; margin-bottom: 8px;">⭐ Super Wiadomość</div>
<div style="color: #fff;">' . esc_html($message) . '</div>
<div style="font-size: 10px; font-style: italic; color: #333; margin-top: 12px; background: linear-gradient(135deg, #ffd700 0%, #ffeb3b 100%); padding: 8px 10px; border-radius: 6px;">Ta wiadomość została wysłana jako Super Wiadomość Premium. Możesz odpowiedzieć, aby rozpocząć rozmowę. Nie odpowiadaj, jeśli nie chcesz.</div>
</div>';
    
    error_log('Super Message: attempting to send from ' . $sender_id . ' to ' . $recipient_id);
    
    // Try Better Messages first (if available)
    if (class_exists('Better_Messages') && function_exists('Better_Messages')) {
        error_log('Super Message: Using Better Messages API');
        
        try {
            $existing_thread_id = sk_get_existing_thread_id($sender_id, $recipient_id);
            
            $bm_args = [
                'sender_id'    => $sender_id,
                'recipients'   => [$recipient_id],
                'subject'      => 'Super Wiadomość', // Added subject
                'content'      => $message_content,
                'return'       => 'thread_id'
            ];
            
            // FORCE NEW THREAD for Super Messages to ensure participants are added correctly.
            // Debugging showed that reusing threads (especially ghost ones) caused empty participant lists.
            /*
            if ($existing_thread_id) {
                $bm_args['thread_id'] = $existing_thread_id;
                sk_debug_log('Super Message: Reusing existing thread_id: ' . $existing_thread_id);
            }
            */
            
            sk_debug_log('Super Message: Sending args: ' . print_r($bm_args, true));

            $result = Better_Messages()->functions->new_message($bm_args);
            
            sk_debug_log('Super Message: Better Messages returned: ' . print_r($result, true));
            
            if (is_wp_error($result)) {
                sk_debug_log('Super Message Better Messages error: ' . $result->get_error_message());
                // Don't verify return here, just fall through to fallback? 
                // Usually WP_Error is definitive failure.
                return new WP_Error('bm_send_failed', 'Nie udało się wysłać wiadomości: ' . $result->get_error_message(), ['status' => 500]);
            }
            
            if ($result && is_numeric($result)) {
                $bp_thread_id = $result;
                sk_debug_log('Super Message: Better Messages created thread_id: ' . $bp_thread_id);

                // HIDE THREAD FOR SENDER (Wait for reply)
                global $wpdb;
                $bm_table = $wpdb->prefix . 'bm_message_recipients';
                $wpdb->update(
                    $bm_table,
                    ['is_deleted' => 1],
                    ['thread_id' => $bp_thread_id, 'user_id' => $sender_id],
                    ['%d'],
                    ['%d', '%d']
                );
                sk_debug_log("Super Message: Manually set is_deleted=1 for thread $bp_thread_id sender $sender_id");

                if (function_exists('messages_delete_thread')) {
                    messages_delete_thread($bp_thread_id, $sender_id);
                }

                // FORCE UNREAD COUNT UPDATE FOR RECIPIENT
                if (class_exists('Better_Messages') && function_exists('Better_Messages')) {
                    try {
                        $bm = Better_Messages();
                        if ($bm && isset($bm->functions) && method_exists($bm->functions, 'update_unread_count')) {
                            $bm->functions->update_unread_count($recipient_id);
                            sk_debug_log("Super Message: Forced unread count update for recipient $recipient_id");
                        }
                    } catch (Throwable $t) {}
                }

                // SUCCESS - Return early properly
                return rest_ensure_response([
                    'success' => true,
                    'message_id' => $message_id,
                    'thread_id' => $bp_thread_id,
                    'remaining' => $remaining - 1
                ]);
            } else {
                sk_debug_log('Super Message: Better Messages returned false/invalid. Falling back to BuddyPress.');
                // Fallback will happen below because $bp_thread_id is still null
            }
            
        } catch (Exception $e) {
            sk_debug_log('Super Message: Better Messages exception: ' . $e->getMessage());
            // Continue to fallback
        }
    }
    
    // FALLBACK: Use BuddyPress if BM failed or not active
    if (!$bp_thread_id && function_exists('messages_new_message')) {
        // Fallback to BuddyPress
        sk_debug_log('Super Message: Using BuddyPress messages_new_message fallback');
        
        $existing_thread_id = sk_get_existing_thread_id($sender_id, $recipient_id);
        
        $bp_args = [
            'sender_id' => $sender_id,
            'recipients' => [$recipient_id],
            'content' => $message_content,
            'error_type' => 'wp_error'
        ];
        
        if ($existing_thread_id) {
            $bp_args['thread_id'] = $existing_thread_id;
            error_log('Super Message: BP Fallback reusing thread_id: ' . $existing_thread_id);
        }
        
        $bp_message_id = messages_new_message($bp_args);
        
        error_log('Super Message: messages_new_message returned: ' . print_r($bp_message_id, true));
        
        if (is_wp_error($bp_message_id)) {
            error_log('Super Message BuddyPress error: ' . $bp_message_id->get_error_message());
            return new WP_Error('bp_send_failed', 'Nie udało się wysłać wiadomości: ' . $bp_message_id->get_error_message(), ['status' => 500]);
        }
        
        if (!$bp_message_id) {
            error_log('Super Message: BP returned false/null');
            return new WP_Error('bp_send_failed', 'BuddyPress nie mógł wysłać wiadomości', ['status' => 500]);
        }
        
        // Get thread_id from message_id
        global $wpdb;
        $bp = buddypress();
        if (isset($bp->messages->table_name_messages)) {
            $bp_thread_id = $wpdb->get_var($wpdb->prepare(
                "SELECT thread_id FROM {$bp->messages->table_name_messages} WHERE id = %d",
                $bp_message_id
            ));
            error_log('Super Message: Got thread_id ' . $bp_thread_id . ' from message_id ' . $bp_message_id);
            
            // HIDE THREAD FOR SENDER (Wait for reply)
            if ($bp_thread_id && function_exists('messages_delete_thread')) {
                messages_delete_thread($bp_thread_id, $sender_id);
                sk_debug_log("Super Message (Fallback): Hidden thread $bp_thread_id for sender $sender_id");
            }
        }
        
    } else {
        error_log('Super Message: No messaging system available!');
        return new WP_Error('no_messaging', 'System wiadomości nie jest dostępny', ['status' => 500]);
    }
    
    // Store in sender's sent list
    if (!is_array($sent)) $sent = [];
    $sent[] = [
        'id' => $message_id,
        'bp_thread_id' => $bp_thread_id ?: null,
        'to' => $recipient_id,
        'message' => $message,
        'timestamp' => date('c'),
        'status' => 'pending' // pending, read, accepted, rejected, blocked
    ];
    update_user_meta($sender_id, 'sk_super_messages_sent', $sent);
    
    // Store in recipient's inbox
    $inbox = get_user_meta($recipient_id, 'sk_super_messages_received', true);
    if (!is_array($inbox)) $inbox = [];
    $inbox[] = [
        'id' => $message_id,
        'bp_thread_id' => $bp_thread_id ?: null,
        'from' => $sender_id,
        'message' => $message,
        'timestamp' => date('c'),
        'read' => false
    ];
    update_user_meta($recipient_id, 'sk_super_messages_received', $inbox);
    
    // Record usage for weekly limit
    sk_record_super_message_sent($sender_id);
    
    return rest_ensure_response([
        'success' => true,
        'message_id' => $message_id,
        'bp_thread_id' => $bp_thread_id ?: null,
        'remaining_this_week' => sk_get_remaining_super_messages($sender_id)
    ]);
}

/**
 * Respond to Super Message endpoint
 */
function sk_super_message_respond($request) {
    $user_id = get_current_user_id();
    $message_id = sanitize_text_field($request->get_param('message_id'));
    $action = sanitize_text_field($request->get_param('action')); // accept, not_now, block
    
    // Find message in inbox
    $inbox = get_user_meta($user_id, 'sk_super_messages_received', true);
    if (!is_array($inbox)) {
        return new WP_Error('not_found', 'Wiadomość nie znaleziona', ['status' => 404]);
    }
    
    $message_index = null;
    $message = null;
    foreach ($inbox as $i => $msg) {
        if ($msg['id'] === $message_id) {
            $message_index = $i;
            $message = $msg;
            break;
        }
    }
    
    if ($message === null) {
        return new WP_Error('not_found', 'Wiadomość nie znaleziona', ['status' => 404]);
    }
    
    $sender_id = $message['from'];
    
    // Update status in sender's sent list
    $sent = get_user_meta($sender_id, 'sk_super_messages_sent', true);
    if (is_array($sent)) {
        foreach ($sent as &$s) {
            if ($s['id'] === $message_id) {
                if ($action === 'accept') {
                    $s['status'] = 'accepted';
                } elseif ($action === 'not_now') {
                    $s['status'] = 'rejected';
                } elseif ($action === 'block') {
                    $s['status'] = 'blocked';
                }
                break;
            }
        }
        update_user_meta($sender_id, 'sk_super_messages_sent', $sent);
    }
    
    // Remove from inbox
    unset($inbox[$message_index]);
    $inbox = array_values($inbox);
    update_user_meta($user_id, 'sk_super_messages_received', $inbox);
    
    // Handle specific actions
    if ($action === 'accept') {
        // No need to send message again since it was sent in sk_super_message_send
        // Metadata is already updated above to mark it as accepted
    } elseif ($action === 'not_now') {
        // Set cooldown for sender
        sk_set_cooldown($sender_id, $user_id);
    } elseif ($action === 'block') {
        // Add sender to skipped users
        $skipped = get_user_meta($user_id, 'sk_skipped_users', true);
        if (!is_array($skipped)) $skipped = [];
        if (!in_array($sender_id, $skipped)) {
            $skipped[] = $sender_id;
            update_user_meta($user_id, 'sk_skipped_users', $skipped);
        }
        // Set permanent cooldown
        sk_set_cooldown($sender_id, $user_id);
    }
    
    return rest_ensure_response([
        'success' => true,
        'action' => $action
    ]);
}

/**
 * Get Super Message inbox
 */
function sk_super_message_inbox($request) {
    $user_id = get_current_user_id();
    
    $inbox = get_user_meta($user_id, 'sk_super_messages_received', true);
    if (!is_array($inbox)) {
        return rest_ensure_response(['messages' => []]);
    }
    
    // Mark all as read and enrich with sender info
    $enriched = [];
    $updated = false;
    foreach ($inbox as &$msg) {
        if (!$msg['read']) {
            $msg['read'] = true;
            $updated = true;
            
            // Also update sender's status to 'read'
            $sender_sent = get_user_meta($msg['from'], 'sk_super_messages_sent', true);
            if (is_array($sender_sent)) {
                foreach ($sender_sent as &$s) {
                    if ($s['id'] === $msg['id'] && $s['status'] === 'pending') {
                        $s['status'] = 'read';
                    }
                }
                update_user_meta($msg['from'], 'sk_super_messages_sent', $sender_sent);
            }
        }
        
        // Get sender info
        $sender = get_userdata($msg['from']);
        $avatar = get_avatar_url($msg['from'], ['size' => 150]);
        
        // Shadow Ban Check
        if (get_user_meta($msg['from'], 'sk_is_hidden', true) === '1') {
            continue;
        }

        $enriched[] = [
            'id' => $msg['id'],
            'from' => [
                'id' => $msg['from'],
                'name' => $sender ? $sender->display_name : 'Użytkownik',
                'avatar' => $avatar,
                'profile_url' => function_exists('bp_members_get_user_url') ? bp_members_get_user_url($msg['from']) : ''
            ],
            'message' => $msg['message'],
            'timestamp' => $msg['timestamp']
        ];
    }
    
    if ($updated) {
        update_user_meta($user_id, 'sk_super_messages_received', $inbox);
    }
    
    return rest_ensure_response(['messages' => $enriched]);
}

/**
 * Get Super Message status
 */
function sk_super_message_status($request) {
    try {
        $user_id = get_current_user_id();
        error_log("SK Super Message Status: Start for user $user_id");
        
        $is_premium = sk_is_premium_user($user_id);
        error_log("SK Super Message Status: is_premium checked: " . ($is_premium ? 'YES' : 'NO'));
        
        $remaining = $is_premium ? sk_get_remaining_super_messages($user_id) : 0;
        
        $sent = get_user_meta($user_id, 'sk_super_messages_sent', true);
        if (!is_array($sent)) $sent = [];
        
        // Enrich sent messages
        $enriched_sent = [];
        foreach ($sent as $msg) {
            $recipient = get_userdata($msg['to']);
            $enriched_sent[] = [
                'id' => $msg['id'],
                'to' => [
                    'id' => $msg['to'],
                    'name' => $recipient ? $recipient->display_name : 'Użytkownik'
                ],
                'timestamp' => $msg['timestamp'],
                'status' => $msg['status']
            ];
        }
        
        // Count inbox
        $inbox = get_user_meta($user_id, 'sk_super_messages_received', true);
        $inbox_count = is_array($inbox) ? count($inbox) : 0;
        
        error_log("SK Super Message Status: Success");
        
        return rest_ensure_response([
            'is_premium' => $is_premium,
            'remaining_this_week' => $remaining,
            'sent' => $enriched_sent,
            'inbox_count' => $inbox_count
        ]);
    } catch (Exception $e) {
        error_log("SK Super Message Status CRITICAL ERROR: " . $e->getMessage());
        // Return default safe response to prevent 500 on frontend
        return rest_ensure_response([
            'is_premium' => false,
            'remaining_this_week' => 0,
            'sent' => [],
            'inbox_count' => 0,
            'error' => 'Internal Server Error logged'
        ]);
    } catch (Error $e) {
        error_log("SK Super Message Status PHP FATAL ERROR: " . $e->getMessage());
        return rest_ensure_response([
            'is_premium' => false,
            'remaining_this_week' => 0,
            'sent' => [],
            'inbox_count' => 0,
            'error' => 'Internal Fatal Error logged'
        ]);
    }
}

/**
 * Reset Super Message data (Admin only, for testing)
 */
function sk_super_message_reset($request) {
    $current_user_id = get_current_user_id();
    $target_user_id = $request->get_param('target_user_id') ?: $current_user_id;
    
    // Clear sent messages
    delete_user_meta($target_user_id, 'sk_super_messages_sent');
    
    // Clear received messages
    delete_user_meta($target_user_id, 'sk_super_messages_received');
    
    // Clear weekly count
    delete_user_meta($target_user_id, 'sk_super_messages_weekly_count');
    
    // Clear send history (cooldowns)
    delete_user_meta($target_user_id, 'sk_super_message_history');
    
    return rest_ensure_response([
        'success' => true,
        'message' => 'Super Message data reset for user ' . $target_user_id
    ]);
}

/**
 * Mobile Member Tabs Navigation
 * Adds horizontal tab bar for Members page on mobile (similar to mobile app)
 * Tabs: Wyszukaj (Search), Polubieni (Liked), Lubią Mnie (Likes Me), Matche (Matches)
 */
function pm_mobile_member_tabs() {
    // Only show on members directory or dashboard
    $current_url = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    $show_tabs = (strpos($current_url, '/members') !== false && strpos($current_url, '/members/') === false) 
              || strpos($current_url, '/dashboard') !== false
              || strpos($current_url, '/czlonkowie') !== false;
    
    if (!$show_tabs || !is_user_logged_in()) {
        return;
    }
    
    $user_id = get_current_user_id();
    $avatar_url = get_avatar_url($user_id, array('size' => 80));
    $user_profile_url = function_exists('bp_loggedin_user_domain') ? bp_loggedin_user_domain() : home_url('/members/');
    $messages_url = $user_profile_url . (function_exists('bp_get_messages_slug') ? bp_get_messages_slug() : 'messages') . '/';
    $unread_count = function_exists('messages_get_unread_count') ? messages_get_unread_count() : 0;
    ?>
    
    <!-- Mobile Header Bar -->
    <div class="pm-mobile-header" id="pm-mobile-header">
        <div class="pm-mh-left">
            <div class="pm-mh-btn pm-filter-btn" id="pm-filter-toggle" role="button" tabindex="0" onclick="document.getElementById('pm-filter-panel').classList.add('active');document.getElementById('pm-filter-overlay').classList.add('active');document.body.style.overflow='hidden';">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="4" y1="21" x2="4" y2="14"></line>
                    <line x1="4" y1="10" x2="4" y2="3"></line>
                    <line x1="12" y1="21" x2="12" y2="12"></line>
                    <line x1="12" y1="8" x2="12" y2="3"></line>
                    <line x1="20" y1="21" x2="20" y2="16"></line>
                    <line x1="20" y1="12" x2="20" y2="3"></line>
                    <line x1="1" y1="14" x2="7" y2="14"></line>
                    <line x1="9" y1="8" x2="15" y2="8"></line>
                    <line x1="17" y1="16" x2="23" y2="16"></line>
                </svg>
            </div>
        </div>
        <div class="pm-mh-right">
            <a href="<?php echo esc_url($messages_url); ?>" class="pm-mh-btn pm-notif-btn">
                <svg viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.89 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/>
                </svg>
                <?php if ($unread_count > 0): ?>
                    <span class="pm-mh-badge"><?php echo $unread_count > 9 ? '9+' : $unread_count; ?></span>
                <?php endif; ?>
            </a>
            <a href="<?php echo esc_url($user_profile_url); ?>" class="pm-mh-avatar" style="position: relative;">
                <img src="<?php echo esc_url($avatar_url); ?>" alt="Profil">
                <?php 
                // PREMIUM BADGE INJECTION
                $is_premium = sk_is_premium_user($user_id);
                if ($is_premium): ?>
                    <span class="pm-premium-header-badge" style="
                        position: absolute;
                        top: -2px;
                        right: -2px;
                        font-size: 12px;
                        line-height: 1;
                        z-index: 10;
                        filter: drop-shadow(0 1px 2px rgba(0,0,0,0.5));
                        pointer-events: none;
                    ">⭐</span>
                <?php endif; ?>
            </a>
        </div>
    </div>
    
    <div class="pm-member-tabs" id="pm-member-tabs">
        <button class="pm-mtab active" data-tab="search">Wyszukaj</button>
        <button class="pm-mtab pm-mtab-premium" data-tab="liked">Polubieni<span class="pm-premium-badge">Premium</span></button>
        <button class="pm-mtab pm-mtab-premium" data-tab="likes-me">Lubią Mnie<span class="pm-premium-badge">Premium</span></button>
        <button class="pm-mtab" data-tab="matches">Matche</button>
        <button class="pm-mtab pm-mtab-premium" data-tab="skipped">Usunięci<span class="pm-premium-badge">Premium</span></button>
    </div>
    
    <div id="pm-tabs-loader" style="display:none; text-align:center; padding:40px;">
        <div class="pm-spinner"></div>
        <p style="color:#999; margin-top:15px;">Ładowanie...</p>
    </div>
    
    <div id="pm-tabs-content"></div>
    
    <!-- Filter Panel Overlay -->
    <div class="pm-filter-overlay" id="pm-filter-overlay" onclick="document.getElementById('pm-filter-panel').classList.remove('active');this.classList.remove('active');document.body.style.overflow='';"></div>
    
    <!-- Filter Panel -->
    <div class="pm-filter-panel" id="pm-filter-panel">
        <div class="pm-filter-header">
            <h3>Ustawienia wyszukiwania</h3>
            <button class="pm-filter-done" id="pm-filter-done" onclick="document.getElementById('pm-filter-panel').classList.remove('active');document.getElementById('pm-filter-overlay').classList.remove('active');document.body.style.overflow='';var f={ageMin:document.getElementById('pm-age-min').value,ageMax:document.getElementById('pm-age-max').value,hasBio:document.getElementById('pm-has-bio')?.checked||false, faith:document.getElementById('pm-filter-faith')?.value||'', politics:document.getElementById('pm-filter-politics')?.value||'', work:document.getElementById('pm-filter-work')?.value||'', diet:document.getElementById('pm-filter-diet')?.value||'', zodiac:document.getElementById('pm-filter-zodiac')?.value||''};localStorage.setItem('pmFilters',JSON.stringify(f));window.dispatchEvent(new CustomEvent('pmReloadTab'));">Gotowe</button>
        </div>
        
        <div class="pm-filter-content">
            <!-- Age Range Slider -->
            <div class="pm-filter-section pm-age-section">
                <label class="pm-filter-label">Zakres wiekowy</label>
                <div class="pm-age-slider-container">
                    <input type="range" id="pm-age-min" class="pm-age-range" min="18" max="65" value="18" oninput="var mn=parseInt(this.value),mx=parseInt(document.getElementById('pm-age-max').value);if(mn>=mx){this.value=mx-1;mn=mx-1;}document.getElementById('pm-age-min-val').textContent=mn;var l=((mn-18)/47)*100,r=((mx-18)/47)*100;document.getElementById('pm-age-fill').style.left=l+'%';document.getElementById('pm-age-fill').style.width=(r-l)+'%';">
                    <input type="range" id="pm-age-max" class="pm-age-range" min="18" max="65" value="65" oninput="var mx=parseInt(this.value),mn=parseInt(document.getElementById('pm-age-min').value);if(mx<=mn){this.value=mn+1;mx=mn+1;}document.getElementById('pm-age-max-val').textContent=mx>=65?'65+':mx;var l=((mn-18)/47)*100,r=((mx-18)/47)*100;document.getElementById('pm-age-fill').style.left=l+'%';document.getElementById('pm-age-fill').style.width=(r-l)+'%';">
                    <div class="pm-age-track"></div>
                    <div class="pm-age-range-fill" id="pm-age-fill"></div>
                </div>
                <div class="pm-age-values">
                    <span id="pm-age-min-val">18</span> - <span id="pm-age-max-val">65+</span>
                </div>
            </div>
            
            <!-- Has Bio Toggle -->
            <div class="pm-filter-row pm-toggle-row">
                <span class="pm-filter-name">Ma bio</span>
                <label class="pm-toggle">
                    <input type="checkbox" id="pm-has-bio">
                    <span class="pm-toggle-slider"></span>
                </label>
            </div>
            

            <!-- Filter List -->
            <!-- Filter List -->
            <div class="pm-filter-list">
                <style>
                    .pm-filter-row select {
                        background: transparent;
                        border: none;
                        color: #fff;
                        text-align: right;
                        font-size: 14px;
                        outline: none;
                        -webkit-appearance: none;
                        appearance: none;
                        padding-right: 15px;
                        cursor: pointer;
                    }
                    .pm-filter-row {
                        position: relative;
                    }
                    .pm-filter-value {
                    display: flex;
                    align-items: center;
                    justify-content: flex-end;
                }
                .pm-filter-value::after {
                    content: '\25BC'; /* Down arrow */
                    font-size: 10px;
                    margin-left: 8px;
                    opacity: 0.7;
                    pointer-events: none;
                }    }
                </style>
                
                <div class="pm-filter-row">
                    <span class="pm-filter-icon">🛐</span>
                    <span class="pm-filter-name">Religia</span>
                    <span class="pm-filter-value">
                        <select id="pm-filter-faith">
                            <option value="">Wszystkie</option>
                            <option value="Wierzący">Wierzący</option>
                            <option value="Ateista">Ateista</option>
                            <option value="Duchowy">Duchowy</option>
                            <option value="Inne">Inne</option>
                        </select>
                    </span>
                </div>

                <div class="pm-filter-row">
                    <span class="pm-filter-icon">⚖️</span>
                    <span class="pm-filter-name">Poglądy</span>
                    <span class="pm-filter-value">
                        <select id="pm-filter-politics">
                            <option value="">Wszystkie</option>
                            <option value="Konserwatywne">Konserwatywne</option>
                            <option value="Liberalne">Liberalne</option>
                            <option value="Centrowe">Centrowe</option>
                            <option value="Apolityczny">Apolityczny</option>
                        </select>
                    </span>
                </div>

                <div class="pm-filter-row">
                    <span class="pm-filter-icon">💼</span>
                    <span class="pm-filter-name">Praca</span>
                    <span class="pm-filter-value">
                        <select id="pm-filter-work">
                            <option value="">Wszystkie</option>
                            <option value="Korporacja">Korporacja</option>
                            <option value="Własny Biznes">Własny Biznes</option>
                            <option value="Normalna Praca">Normalna Praca</option>
                            <option value="Praca Kreatywna">Praca Kreatywna</option>
                            <option value="Nie pracuję">Nie pracuję</option>
                        </select>
                    </span>
                </div>

                <div class="pm-filter-row">
                    <span class="pm-filter-icon">🥗</span>
                    <span class="pm-filter-name">Dieta</span>
                    <span class="pm-filter-value">
                        <select id="pm-filter-diet">
                            <option value="">Wszystkie</option>
                            <option value="Wszystkożerca">Wszystkożerca</option>
                            <option value="Wegetarianin">Wegetarianin</option>
                            <option value="Weganin">Weganin</option>
                            <option value="Keto/Inne">Keto/Inne</option>
                        </select>
                    </span>
                </div>
                
                <div class="pm-filter-row">
                    <span class="pm-filter-icon">♈</span>
                    <span class="pm-filter-name">Znak zodiaku</span>
                    <span class="pm-filter-value">
                        <select id="pm-filter-zodiac">
                            <option value="">Wszystkie</option>
                            <option value="Baran">Baran</option>
                            <option value="Byk">Byk</option>
                            <option value="Bliźnięta">Bliźnięta</option>
                            <option value="Rak">Rak</option>
                            <option value="Lew">Lew</option>
                            <option value="Panna">Panna</option>
                            <option value="Waga">Waga</option>
                            <option value="Skorpion">Skorpion</option>
                            <option value="Strzelec">Strzelec</option>
                            <option value="Koziorożec">Koziorożec</option>
                            <option value="Wodnik">Wodnik</option>
                            <option value="Ryby">Ryby</option>
                        </select>
                    </span>
                </div>
                
                <!-- Show Numerology Toggle -->
                <div class="pm-filter-row pm-toggle-row" style="margin-top: 16px;">
                    <span class="pm-filter-name">Pokaż Numerologię</span>
                    <label class="pm-toggle">
                        <input type="checkbox" id="pm-show-numerology" onchange="document.body.classList.toggle('show-numerology', this.checked); localStorage.setItem('pmShowNumerology', this.checked);">
                        <span class="pm-toggle-slider"></span>
                    </label>
                </div>
                
                <!-- Reset Filters Button -->
                <div style="margin-top: 24px; padding-bottom: 20px;">
                    <button type="button" class="pm-reset-filters-btn" onclick="document.getElementById('pm-age-min').value=18;document.getElementById('pm-age-max').value=65;document.getElementById('pm-age-min-val').textContent='18';document.getElementById('pm-age-max-val').textContent='65+';document.getElementById('pm-age-fill').style.left='0%';document.getElementById('pm-age-fill').style.width='100%';document.getElementById('pm-has-bio').checked=false;document.getElementById('pm-show-numerology').checked=false;document.body.classList.remove('show-numerology');localStorage.removeItem('pmShowNumerology');['pm-filter-faith','pm-filter-politics','pm-filter-work','pm-filter-diet','pm-filter-zodiac'].forEach(id=>{var el=document.getElementById(id);if(el)el.value='';});localStorage.removeItem('pmFilters');">
                        Zresetuj wszystkie filtry
                    </button>
                </div>
            </div>
        </div>
    </div>

    <style>
    /* Header Bar - visible on all screen widths */
    .pm-mobile-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
        padding: 8px 15px;
        padding-top: calc(env(safe-area-inset-top, 0) + 8px);
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 101;
    }
    
    .pm-mh-left,
    .pm-mh-right {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .pm-mh-btn {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: rgba(255,255,255,0.1);
        border: none;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        color: #fff;
        text-decoration: none;
        position: relative;
        transition: background 0.2s;
    }
    
    .pm-mh-btn:hover {
        background: rgba(255,255,255,0.2);
    }
    
    .pm-mh-btn svg {
        width: 20px;
        height: 20px;
    }
    
    .pm-notif-btn {
        background: rgba(255,255,255,0.15);
    }
    
    .pm-mh-badge {
        position: absolute;
        top: -2px;
        right: -2px;
        background: #f44336;
        color: #fff;
        font-size: 10px;
        font-weight: 600;
        min-width: 16px;
        height: 16px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0 4px;
    }
    
    .pm-mh-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        overflow: visible; /* Allow badge to be visible outside */
        border: 2px solid rgba(255,255,255,0.3);
        position: relative; /* Ensure badge positioning works */
    }
    
    .pm-mh-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 50%; /* Apply rounding to image itself */
        display: block;
    }
    
    /* Member Tabs - visible on all screen widths */
    .pm-member-tabs {
        display: flex;
        justify-content: space-around;
        align-items: center;
        background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
        padding: 12px 15px;
        position: fixed;
        top: calc(56px + env(safe-area-inset-top, 0));
        left: 0;
        right: 0;
        width: 100%;
        z-index: 100;
        border-bottom: 1px solid rgba(255,255,255,0.1);
    }
    
    .pm-member-tabs::-webkit-scrollbar {
        display: none;
    }
    
    /* Add padding to body to account for fixed header + tabs */
    body.pm-tabs-visible {
        padding-top: calc(110px + env(safe-area-inset-top, 0)) !important;
    }
        
        /* Hide default BuddyPress members search/filter when tabs active */
        .pm-tabs-active .bp-dir-hori-nav,
        .pm-tabs-active #members-dir-search,
        .pm-tabs-active .bp-subnavs,
        .pm-tabs-active #subnav,
        .pm-tabs-active .item-list-tabs,
        .pm-tabs-active .site-header,
        .pm-tabs-active header,
        .pm-tabs-active #masthead,
        .pm-tabs-active .top-header,
        .pm-tabs-active #members-list,
        .pm-tabs-active .members-list,
        .pm-tabs-active #item-body,
        .pm-tabs-active #buddypress .members,
        .pm-tabs-active .bp-pagination,
        .pm-tabs-active .members.item-list,
        .pm-tabs-active #members-dir-list,
        .pm-tabs-active .member-loop,
        .pm-tabs-active #buddypress #members-dir-list,
        .pm-tabs-active #buddypress,
        .pm-tabs-active .buddypress-wrap,
        .pm-tabs-active .buddypress,
        .pm-tabs-active .profile-card-view,
        .pm-tabs-active .bp-list,
        .pm-tabs-active .bp-list.members-list,
        .pm-tabs-active #content,
        .pm-tabs-active .site-content,
        .pm-tabs-active main,
        .pm-tabs-active article,
        .pm-tabs-active .entry-content {
            display: none !important;
        }
    }
    
    @media (min-width: 768px) {
        .pm-member-tabs {
            display: none !important;
        }
    }
    
    .pm-mtab {
        flex-shrink: 0;
        padding: 10px 12px;
        border: none;
        background: rgba(255,255,255,0.1);
        color: rgba(255,255,255,0.7);
        border-radius: 25px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s ease;
        white-space: nowrap;
    }
    
    .pm-mtab:hover {
        background: rgba(255,255,255,0.15);
    }
    
    .pm-mtab.active {
        background: linear-gradient(135deg, #2ECC71 0%, #27AE60 100%);
        color: #fff;
        box-shadow: 0 2px 8px rgba(46,204,113,0.3);
    }
    
    /* Premium badge for tabs */
    .pm-mtab-premium {
        position: relative;
        padding-bottom: 16px;
    }
    
    .pm-premium-badge {
        position: absolute;
        bottom: 2px;
        right: 4px;
        font-size: 8px;
        font-weight: 700;
        color: #FFD700;
        background: #1a1a1a;
        padding: 1px 4px;
        border-radius: 3px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        line-height: 1.2;
    }
    
    .pm-spinner {
        width: 40px;
        height: 40px;
        border: 3px solid rgba(255,255,255,0.1);
        border-top-color: #2ECC71;
        border-radius: 50%;
        animation: pm-spin 1s linear infinite;
        margin: 0 auto;
    }
    
    @keyframes pm-spin {
        to { transform: rotate(360deg); }
    }
    
    /* Tab content member cards */
    .pm-tab-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
        padding: 15px;
    }
    
    .pm-tab-card {
        background: linear-gradient(135deg, #2d2d3a 0%, #1f1f2e 100%);
        border-radius: 15px;
        overflow: hidden;
        box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    }
    
    .pm-tab-card img {
        width: 100%;
        aspect-ratio: 1;
        object-fit: cover;
    }
    
    .pm-tab-card-info {
        padding: 12px;
    }
    
    .pm-tab-card-name {
        color: #fff;
        font-weight: 600;
        font-size: 14px;
        margin: 0 0 5px 0;
    }
    
    .pm-tab-card-age {
        color: #2ECC71;
        font-size: 13px;
    }
    
    .pm-tab-card-actions {
        display: flex;
        gap: 8px;
        margin-top: 10px;
    }
    
    .pm-tab-card-btn {
        flex: 1;
        padding: 8px;
        border: none;
        border-radius: 8px;
        font-size: 12px;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    
    .pm-tab-card-btn.profile {
        background: rgba(255,255,255,0.1);
        color: #fff;
    }
    
    .pm-tab-card-btn.message {
        background: linear-gradient(135deg, #2ECC71 0%, #27AE60 100%);
        color: #fff;
    }
    
    /* NEW: Swipe Grid - Matches Original BuddyPress Layout */
    .pm-swipe-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0;
        padding: 0;
    }
    
    /* 2 columns on tablets / medium screens */
    @media (min-width: 600px) {
        .pm-swipe-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            padding: 15px;
        }
        .pm-swipe-card {
            border-radius: 15px;
            overflow: hidden;
        }
        .pm-swipe-card-image {
            border-radius: 15px 15px 0 0;
        }
    }
    
    /* 3 columns on larger screens */
    @media (min-width: 900px) {
        .pm-swipe-grid {
            grid-template-columns: repeat(3, 1fr);
        }
    }
    
    /* 4 columns on very wide screens */
    @media (min-width: 1200px) {
        .pm-swipe-grid {
            grid-template-columns: repeat(4, 1fr);
            max-width: 1400px;
            margin: 0 auto;
        }
    }
    
    .pm-swipe-card {
        background: #1a1a2e;
        display: flex;
        flex-direction: column;
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        margin-bottom: 30px;
    }
    
    .pm-swipe-card-link {
        display: block;
        text-decoration: none;
    }
    
    .pm-swipe-card-image {
        position: relative;
        width: 100%;
        padding-bottom: 120%; /* 5:6 aspect ratio for portrait */
        background-size: cover;
        background-position: center;
        background-color: #2d2d3a;
        border-radius: 20px 20px 0 0;
    }
    
    /* Info section BELOW image - not on top of it */
    .pm-swipe-card-overlay {
        position: relative;
        padding: 15px;
        background: linear-gradient(to bottom, rgba(20,20,30,0.95) 0%, rgba(15,15,25,1) 100%);
    }
    
    .pm-swipe-card-name {
        display: block;
        color: #fff;
        font-size: 22px;
        font-weight: 700;
        text-decoration: none;
        text-shadow: 0 2px 4px rgba(0,0,0,0.5);
        margin-bottom: 4px;
    }
    
    .pm-swipe-card-bio {
        font-size: 13px;
        color: rgba(255,255,255,0.7);
        margin-bottom: 8px;
        line-height: 1.4;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    
    .pm-swipe-card-tags {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }
    
    .pm-member-tag {
        display: inline-block;
        padding: 5px 10px;
        background: rgba(255,255,255,0.15);
        border: 1px solid rgba(255,255,255,0.3);
        border-radius: 15px;
        color: #fff;
        font-size: 12px;
    }
    
    .pm-member-tag.pm-tag-numerology {
        background: linear-gradient(135deg, #f39c12, #f1c40f);
        border-color: #f39c12;
        color: #000;
    }
    
    .pm-member-tag.pm-tag-zodiac {
        background: rgba(155, 89, 182, 0.4);
        border-color: #9b59b6;
    }
    
    /* Action Buttons - Inside Overlay (matching mobile app design) */
    .pm-swipe-card-actions {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 20px;
        padding: 15px 0;
        margin-top: 10px;
    }
    
    .pm-action-btn {
        width: 65px;
        height: 65px;
        border-radius: 50%;
        border: none;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 28px;
        cursor: pointer;
        transition: all 0.2s ease;
        background: #ffffff;
        box-shadow: 0 4px 15px rgba(0,0,0,0.3);
    }
    
    /* Skip button - white with red X */
    .pm-action-btn.pm-action-skip {
        background: #ffffff;
        color: #e74c3c;
    }
    
    .pm-action-btn.pm-action-skip:hover {
        transform: scale(1.1);
        box-shadow: 0 6px 20px rgba(231, 76, 60, 0.4);
    }
    
    /* Restore button - white with blue arrow */
    .pm-action-btn.pm-action-restore {
        background: #ffffff;
        color: #3498db;
    }
    
    .pm-action-btn.pm-action-restore:hover {
        transform: scale(1.1);
        box-shadow: 0 6px 20px rgba(52, 152, 219, 0.4);
    }
    
    /* Like button - white with green heart outline */
    .pm-action-btn.pm-action-like {
        background: #ffffff;
        color: #2ecc71;
    }
    
    .pm-action-btn.pm-action-like:hover {
        transform: scale(1.1);
        box-shadow: 0 6px 20px rgba(46, 204, 113, 0.4);
    }
    
    /* Super Wiadomość button - dark blue with yellow envelope (matching app) */
    .pm-action-btn.pm-action-super-msg {
        background: #1a1a2e !important;
        border: 3px solid #FFD700 !important;
        color: transparent !important;
        font-size: 0 !important;
        position: relative;
        width: 65px;
        height: 65px;
        display: flex !important;
        flex-direction: column !important;
        justify-content: center !important;
        align-items: center !important;
        gap: 0 !important;
        padding: 0 !important;
        padding-bottom: 10px !important;
    }
    
    /* Envelope icon from PNG */
    .pm-action-btn.pm-action-super-msg::before {
        content: "";
        display: block;
        width: 32px;
        height: 24px;
        background-image: url("https://prawdziwamilosc.pl/envelope.png");
        background-repeat: no-repeat;
        background-size: contain;
        background-position: center;
    }
    
    .pm-action-btn.pm-action-super-msg::after {
        content: "Premium";
        font-size: 8px !important;
        font-weight: bold;
        color: #FFD700 !important;
        letter-spacing: 0.3px;
        margin-top: 2px;
        line-height: 1;
    }
    
    .pm-action-btn.pm-action-super-msg:hover {
        background: #16213e !important;
        transform: scale(1.1);
        box-shadow: 0 0 20px rgba(255, 215, 0, 0.5);
    }
    
    /* Desktop: larger envelope icon */
    @media (min-width: 768px) {
        .pm-action-btn.pm-action-super-msg::before {
            width: 36px;
            height: 28px;
        }
    }
    
    /* Super Message Modal */
    .pm-super-msg-modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100vw;
        height: 100vh;
        background: rgba(0,0,0,0.85);
        z-index: 10000;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        box-sizing: border-box;
    }
    
    .pm-super-msg-modal.active {
        display: flex;
    }
    
    .pm-super-msg-content {
        background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
        border: 2px solid #FFD700;
        border-radius: 16px;
        padding: 24px;
        max-width: 90vw;
        width: 350px;
        color: #fff;
        box-sizing: border-box;
    }
    
    .pm-super-msg-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
    }
    
    .pm-super-msg-title {
        font-size: 20px;
        font-weight: bold;
        color: #FFD700;
    }
    
    .pm-super-msg-close {
        background: none;
        border: none;
        color: #888;
        font-size: 28px;
        cursor: pointer;
    }
    
    .pm-super-msg-close:hover {
        color: #fff;
    }
    
    .pm-super-msg-recipient {
        color: #FFD700;
        font-size: 16px;
        margin-bottom: 12px;
    }
    
    .pm-super-msg-textarea {
        width: 100%;
        min-height: 120px;
        padding: 12px;
        border: 1px solid #444;
        border-radius: 8px;
        background: #333;
        color: #fff;
        font-size: 14px;
        resize: none;
    }
    
    .pm-super-msg-textarea:focus {
        outline: none;
        border-color: #FFD700;
    }
    
    .pm-super-msg-counter {
        text-align: right;
        font-size: 12px;
        color: #888;
        margin-top: 4px;
    }
    
    .pm-super-msg-remaining {
        text-align: center;
        margin: 16px 0;
        font-size: 12px;
        color: #999;
    }
    
    .pm-super-msg-remaining span {
        color: #FFD700;
        font-weight: bold;
    }
    
    .pm-super-msg-send {
        width: 100%;
        padding: 14px;
        background: linear-gradient(135deg, #FFD700 0%, #FFA500 100%);
        color: #1a1a1a;
        border: none;
        border-radius: 8px;
        font-size: 16px;
        font-weight: bold;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    
    .pm-super-msg-send:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(255, 215, 0, 0.4);
    }
    
    .pm-super-msg-send:disabled {
        opacity: 0.5;
        cursor: not-allowed;
        transform: none;
    }
    
    /* Super Message Counter in Header */
    .pm-super-msg-header-counter {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 12px;
        background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
        border: 1px solid #FFD700;
        border-radius: 20px;
        font-size: 12px;
        color: #FFD700;
        margin-left: 8px;
    }
    
    .pm-empty-state {
        text-align: center;
        padding: 60px 20px;
        color: rgba(255,255,255,0.5);
    }
    
    .pm-empty-state svg {
        width: 64px;
        height: 64px;
        opacity: 0.3;
        margin-bottom: 15px;
    }
    
    /* Filter Panel Overlay */
    .pm-filter-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.6);
        z-index: 199;
    }
    
    .pm-filter-overlay.active {
        display: block;
    }
    
    /* Filter Panel */
    .pm-filter-panel {
        position: fixed;
        top: 0;
        left: -100%;
        width: 85%;
        max-width: 360px;
        height: 100%;
        background: linear-gradient(180deg, #1a1a2e 0%, #0f0f1a 100%);
        z-index: 200;
        transition: left 0.3s ease;
        overflow-y: auto;
        padding-bottom: 40px;
    }
    
    .pm-filter-panel.active {
        left: 0;
    }
    
    .pm-filter-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 20px 16px;
        padding-top: calc(env(safe-area-inset-top, 0) + 20px);
        border-bottom: 1px solid rgba(255,255,255,0.1);
    }
    
    .pm-filter-header h3 {
        margin: 0;
        color: #fff;
        font-size: 18px;
        font-weight: 600;
    }
    
    .pm-filter-done {
        background: linear-gradient(135deg, #ec4899 0%, #8b5cf6 100%);
        color: #fff;
        border: none;
        padding: 10px 24px;
        border-radius: 20px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
    }
    
    .pm-filter-content {
        padding: 16px;
        padding-bottom: 40px;
    }
    
    .pm-reset-filters-btn {
        width: 100%;
        padding: 12px 20px;
        background: transparent;
        color: #e74c3c;
        border: 1px solid #e74c3c;
        border-radius: 25px;
        cursor: pointer;
        font-weight: 600;
        font-size: 14px;
        transition: all 0.3s ease;
    }
    
    .pm-reset-filters-btn:hover,
    .pm-reset-filters-btn:active {
        background: #e74c3c;
        color: white;
    }
    
    /* Age Section */
    .pm-filter-section {
        margin-bottom: 24px;
    }
    
    .pm-filter-label {
        display: block;
        color: #fff;
        font-size: 15px;
        font-weight: 500;
        margin-bottom: 16px;
    }
    
    .pm-age-slider-container {
        position: relative;
        height: 40px;
        margin-bottom: 8px;
    }
    
    .pm-age-range {
        position: absolute;
        width: 100%;
        height: 6px;
        top: 50%;
        transform: translateY(-50%);
        -webkit-appearance: none;
        appearance: none;
        background: transparent;
        pointer-events: none;
        z-index: 2;
    }
    
    .pm-age-range::-webkit-slider-thumb {
        -webkit-appearance: none;
        appearance: none;
        width: 24px;
        height: 24px;
        background: #ec4899;
        border-radius: 50%;
        cursor: pointer;
        pointer-events: auto;
        box-shadow: 0 2px 6px rgba(0,0,0,0.3);
    }
    
    .pm-age-track {
        position: absolute;
        width: 100%;
        height: 6px;
        top: 50%;
        transform: translateY(-50%);
        background: rgba(255,255,255,0.2);
        border-radius: 3px;
    }
    
    .pm-age-range-fill {
        position: absolute;
        height: 6px;
        top: 50%;
        transform: translateY(-50%);
        background: linear-gradient(90deg, #ec4899, #8b5cf6);
        border-radius: 3px;
        left: 0%;
        width: 100%;
    }
    
    .pm-age-values {
        text-align: center;
        color: #fff;
        font-size: 16px;
        font-weight: 500;
    }
    
    /* Toggle */
    .pm-toggle-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px 0;
        border-bottom: 1px solid rgba(255,255,255,0.1);
    }
    
    .pm-toggle {
        position: relative;
        display: inline-block;
        width: 50px;
        height: 28px;
    }
    
    .pm-toggle input {
        opacity: 0;
        width: 0;
        height: 0;
    }
    
    .pm-toggle-slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(120, 120, 140, 0.6);
        transition: 0.3s;
        border-radius: 28px;
    }
    
    .pm-toggle-slider:before {
        position: absolute;
        content: "";
        height: 22px;
        width: 22px;
        left: 3px;
        bottom: 3px;
        background: #fff;
        transition: 0.3s;
        border-radius: 50%;
    }
    
    .pm-toggle input:checked + .pm-toggle-slider {
        background: linear-gradient(90deg, #ec4899, #8b5cf6);
    }
    
    .pm-toggle input:checked + .pm-toggle-slider:before {
        transform: translateX(22px);
    }
    
    /* Filter List */
    .pm-filter-list {
        margin-top: 16px;
    }
    
    .pm-filter-row {
        display: flex;
        align-items: center;
        padding: 14px 0;
        border-bottom: 1px solid rgba(255,255,255,0.1);
        color: #fff;
    }
    
    .pm-filter-icon {
        font-size: 20px;
        margin-right: 12px;
    }
    
    .pm-filter-name {
        flex: 1;
        font-size: 15px;
        color: #fff;
    }
    
    .pm-filter-value {
        color: rgba(255,255,255,0.5);
        font-size: 14px;
    }
    </style>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Restore numerology toggle state from localStorage
        const savedNumerology = localStorage.getItem('pmShowNumerology');
        if (savedNumerology === 'true') {
            document.body.classList.add('show-numerology');
            const checkbox = document.getElementById('pm-show-numerology');
            if (checkbox) checkbox.checked = true;
        }
        
        const tabsContainer = document.getElementById('pm-member-tabs');
        const loader = document.getElementById('pm-tabs-loader');
        const content = document.getElementById('pm-tabs-content');
        const tabs = document.querySelectorAll('.pm-mtab');
        
        if (!tabs.length) return;
        
        // Move tabs to the top of the page, right after header
        const header = document.querySelector('header, .site-header, #masthead, .main-header, .top-header');
        const mainContent = document.querySelector('main, #main, .site-main, #content, .content-area, #buddypress');
        
        if (header && header.parentNode) {
            // Insert after header
            header.parentNode.insertBefore(tabsContainer, header.nextSibling);
            header.parentNode.insertBefore(loader, tabsContainer.nextSibling);
            header.parentNode.insertBefore(content, loader.nextSibling);
        } else if (mainContent && mainContent.parentNode) {
            // Insert before main content
            mainContent.parentNode.insertBefore(content, mainContent);
            mainContent.parentNode.insertBefore(loader, content);
            mainContent.parentNode.insertBefore(tabsContainer, loader);
        }
        
        // Also hide the original header on mobile and show tabs instead
        if (window.innerWidth <= 767) {
            const originalHeader = document.querySelector('.site-header, header, #masthead');
            if (originalHeader) {
                originalHeader.style.display = 'none';
            }
        }
        
        const originalContent = document.querySelector('#members-list, .members-list, #item-body, #buddypress .members');
        
        // Always add pm-tabs-visible for body padding (header + tabs are always visible)
        document.body.classList.add('pm-tabs-visible');
        
        // Default tab is 'search' which should show original content
        // Don't add pm-tabs-active class initially
        
        // Check URL for tab parameter
        const urlParams = new URLSearchParams(window.location.search);
        const urlTab = urlParams.get('tab');
        let currentTab = 'search';
        
        // Function to switch tab (extracted for reuse)
        async function switchToTab(tabId) {
            if (tabId === currentTab) return;
            
            // Update active state
            tabs.forEach(t => t.classList.remove('active'));
            const targetTab = document.querySelector(`.pm-mtab[data-tab="${tabId}"]`);
            if (targetTab) targetTab.classList.add('active');
            currentTab = tabId;
            
            // ALL tabs now use AJAX for consistent HiRes images
            if (originalContent) originalContent.style.display = 'none';
            content.style.display = 'block';
            loader.style.display = 'block';
            document.body.classList.add('pm-tabs-active');
            
            // Special handling for skipped tab - loads from localStorage
            if (tabId === 'skipped') {
                try {
                    const skippedIds = JSON.parse(localStorage.getItem('pmSkippedUsers') || '[]');
                    if (skippedIds.length === 0) {
                        loader.style.display = 'none';
                        renderMembers([], tabId);
                        return;
                    }
                    // Fetch user data for skipped IDs
                    const endpoint = '<?php echo rest_url('sk/v1/members'); ?>?include=' + skippedIds.join(',');
                    const response = await fetch(endpoint, {
                        credentials: 'same-origin',
                        headers: { 'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>' }
                    });
                    if (!response.ok) throw new Error('API error');
                    const data = await response.json();
                    loader.style.display = 'none';
                    renderSkippedMembers(data);
                } catch (error) {
                    console.error('Skipped tab error:', error);
                    loader.style.display = 'none';
                    content.innerHTML = '<p style="text-align:center;padding:40px;color:#999;">Nie udało się załadować danych</p>';
                }
                return;
            }
            
            try {
                // Build endpoint with filters for search tab
                let endpoint = getEndpoint(tabId);
                
                // Add filters to search (members) endpoint
                if (tabId === 'search') {
                    try {
                        const saved = localStorage.getItem('pmFilters');
                        if (saved) {
                            const filters = JSON.parse(saved);
                            const params = new URLSearchParams();
                            if (filters.ageMin) params.append('min_age', filters.ageMin);
                            if (filters.ageMax) params.append('max_age', filters.ageMax);
                            if (filters.hasBio) params.append('has_bio', 'true');
                            if (filters.faith) params.append('faith', filters.faith);
                            if (filters.politics) params.append('politics', filters.politics);
                            if (filters.work) params.append('work', filters.work);
                            if (filters.diet) params.append('diet', filters.diet);
                            if (filters.zodiac) params.append('zodiac', filters.zodiac);
                            if (params.toString()) {
                                endpoint += '?' + params.toString();
                            }
                        }
                    } catch(e) { console.error('Filter parse error:', e); }
                }
                
                const response = await fetch(endpoint, {
                    credentials: 'same-origin',
                    headers: {
                        'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                    }
                });
                
                if (!response.ok) throw new Error('API error');
                
                const data = await response.json();
                loader.style.display = 'none';
                renderMembers(data, tabId);
            } catch (error) {
                console.error('Tab loading error:', error);
                loader.style.display = 'none';
                content.innerHTML = '<p style="text-align:center;padding:40px;color:#999;">Nie udało się załadować danych</p>';
            }
        }
        
        // If URL has tab parameter, switch to that tab on load
        if (urlTab && ['search', 'liked', 'likes-me', 'matches', 'skipped'].includes(urlTab)) {
            setTimeout(() => switchToTab(urlTab), 100);
        } else {
            // Default: load search via API on page init
            currentTab = ''; // Reset so switchToTab will run
            setTimeout(() => switchToTab('search'), 100);
        }
        
        tabs.forEach(tab => {
            tab.addEventListener('click', function() {
                const tabId = this.dataset.tab;
                switchToTab(tabId);
            });
        });
        
        function getEndpoint(tabId) {
            const base = '<?php echo rest_url('sk/v1/'); ?>';
            switch(tabId) {
                case 'liked': return base + 'liked';
                case 'likes-me': return base + 'likes-me';
                case 'matches': return base + 'matches';
                default: return base + 'members';
            }
        }
        
        function renderMembers(members, tabId) {
            if (!members || members.length === 0) {
                const messages = {
                    'liked': 'Nie masz jeszcze polubionych profili',
                    'likes-me': 'Nikt jeszcze nie polubił Twojego profilu',
                    'matches': 'Nie masz jeszcze żadnych matchy',
                    'search': 'Nie znaleziono użytkowników spełniających kryteria',
                    'skipped': 'Nie masz żadnych usuniętych profili'
                };
                content.innerHTML = `
                    <div class="pm-empty-state">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
                        </svg>
                        <p>${messages[tabId] || 'Brak wyników'}</p>
                    </div>
                `;
                return;
            }
            
            // Build tags HTML helper
            const buildTags = (member) => {
                let tags = '';
                if (member.faith) tags += `<span class="pm-member-tag">${member.faith}</span>`;
                if (member.work) tags += `<span class="pm-member-tag">${member.work}</span>`;
                if (member.diet) tags += `<span class="pm-member-tag">${member.diet}</span>`;
                if (member.numerology) tags += `<span class="pm-member-tag pm-tag-numerology">${member.numerology}</span>`;
                if (member.zodiac_sign) tags += `<span class="pm-member-tag pm-tag-zodiac">${member.zodiac_sign}</span>`;
                return tags;
            };
            
            let html = '<div class="pm-swipe-grid">';
            members.forEach(member => {
                const avatar = member.hires_avatar?.large || member.hires_avatar?.full || member.avatar_urls?.full || member.avatar || '';
                const name = member.name || member.display_name || 'Użytkownik';
                const age = member.age || '';
                const bio = member.bio ? (member.bio.length > 60 ? member.bio.substring(0, 60) + '...' : member.bio) : '';
                const url = member.link || member.profile_url || '/members/' + (member.mention_name || member.user_nicename || member.id);
                const tagsHtml = buildTags(member);
                
                html += `
                    <div class="pm-swipe-card" data-user-id="${member.id}">
                        <a href="${url}" class="pm-swipe-card-link">
                            <div class="pm-swipe-card-image" style="background-image: url('${avatar}')"></div>
                        </a>
                        <div class="pm-swipe-card-overlay">
                            <div class="pm-swipe-card-info">
                                <a href="${url}" class="pm-swipe-card-name">${name}${age ? `, ${age}` : ''}</a>
                                ${bio ? `<div class="pm-swipe-card-bio">${bio}</div>` : ''}
                                ${tagsHtml ? `<div class="pm-swipe-card-tags">${tagsHtml}</div>` : ''}
                            </div>
                            <div class="pm-swipe-card-actions">
                                ${tabId === 'liked' 
                                    ? `<button class="pm-action-btn pm-action-skip" onclick="event.preventDefault(); event.stopPropagation(); pmUnlikeUser(${member.id});">✕</button>`
                                    : `<button class="pm-action-btn pm-action-skip" onclick="event.preventDefault(); event.stopPropagation(); pmSkipUser(${member.id});">✕</button>
                                       <button class="pm-action-btn pm-action-super-msg" onclick="event.preventDefault(); event.stopPropagation(); pmOpenSuperMessage(${member.id}, '${name.replace(/'/g, "\\'")}');" title="Super Wiadomość">✉</button>
                                       <button class="pm-action-btn pm-action-like" onclick="event.preventDefault(); event.stopPropagation(); pmLikeUser(${member.id});">♥</button>`
                                }
                            </div>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            content.innerHTML = html;
        }
        
        // Render skipped members with "Restore" button
        function renderSkippedMembers(members) {
            if (!members || members.length === 0) {
                content.innerHTML = `
                    <div class="pm-empty-state">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
                        </svg>
                        <p>Nie masz żadnych usuniętych profili</p>
                    </div>
                `;
                return;
            }
            
            // Build tags HTML helper
            const buildTags = (member) => {
                let tags = '';
                if (member.faith) tags += `<span class="pm-member-tag">${member.faith}</span>`;
                if (member.work) tags += `<span class="pm-member-tag">${member.work}</span>`;
                if (member.diet) tags += `<span class="pm-member-tag">${member.diet}</span>`;
                if (member.numerology) tags += `<span class="pm-member-tag pm-tag-numerology">${member.numerology}</span>`;
                if (member.zodiac_sign) tags += `<span class="pm-member-tag pm-tag-zodiac">${member.zodiac_sign}</span>`;
                return tags;
            };
            
            let html = '<div class="pm-swipe-grid">';
            members.forEach(member => {
                const avatar = member.hires_avatar?.large || member.hires_avatar?.full || member.avatar_urls?.full || member.avatar || '';
                const name = member.name || member.display_name || 'Użytkownik';
                const age = member.age || '';
                const bio = member.bio ? (member.bio.length > 60 ? member.bio.substring(0, 60) + '...' : member.bio) : '';
                const url = member.link || member.profile_url || '/members/' + (member.mention_name || member.user_nicename || member.id);
                const tagsHtml = buildTags(member);
                
                html += `
                    <div class="pm-swipe-card" data-user-id="${member.id}">
                        <a href="${url}" class="pm-swipe-card-link">
                            <div class="pm-swipe-card-image" style="background-image: url('${avatar}')"></div>
                        </a>
                        <div class="pm-swipe-card-overlay">
                            <div class="pm-swipe-card-info">
                                <a href="${url}" class="pm-swipe-card-name">${name}${age ? `, ${age}` : ''}</a>
                                ${bio ? `<div class="pm-swipe-card-bio">${bio}</div>` : ''}
                                ${tagsHtml ? `<div class="pm-swipe-card-tags">${tagsHtml}</div>` : ''}
                            </div>
                            <div class="pm-swipe-card-actions">
                                <button class="pm-action-btn pm-action-restore" onclick="event.preventDefault(); event.stopPropagation(); pmRestoreUser(${member.id});">↩</button>
                                <button class="pm-action-btn pm-action-like" onclick="event.preventDefault(); event.stopPropagation(); pmLikeUser(${member.id});">♥</button>
                            </div>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            content.innerHTML = html;
        }
        
        // Restore user - remove from skipped list
        window.pmRestoreUser = function(userId) {
            hideCard(userId);
            // Remove from localStorage
            let skipped = JSON.parse(localStorage.getItem('pmSkippedUsers') || '[]');
            skipped = skipped.filter(id => id !== userId);
            localStorage.setItem('pmSkippedUsers', JSON.stringify(skipped));
        };
        
        // Custom toast notification (replaces alert to prevent mobile layout issues)
        function showToast(msg, isSuccess = true) {
            // Remove existing toast
            const existing = document.getElementById('pm-toast');
            if (existing) existing.remove();
            
            const toast = document.createElement('div');
            toast.id = 'pm-toast';
            toast.innerHTML = msg;
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                left: 50%;
                transform: translateX(-50%);
                padding: 14px 24px;
                background: ${isSuccess ? 'linear-gradient(135deg, #1a1a1a, #2d2d2d)' : '#c0392b'};
                border: 2px solid ${isSuccess ? '#FFD700' : '#e74c3c'};
                color: #fff;
                border-radius: 12px;
                font-size: 15px;
                z-index: 99999;
                box-shadow: 0 4px 20px rgba(0,0,0,0.5);
                max-width: 90vw;
                text-align: center;
                animation: toastSlideIn 0.3s ease-out;
            `;
            
            // Add animation keyframes if not exists
            if (!document.getElementById('pm-toast-style')) {
                const style = document.createElement('style');
                style.id = 'pm-toast-style';
                style.textContent = `
                    @keyframes toastSlideIn { from { opacity: 0; transform: translateX(-50%) translateY(-20px); } to { opacity: 1; transform: translateX(-50%) translateY(0); } }
                    @keyframes toastSlideOut { from { opacity: 1; transform: translateX(-50%) translateY(0); } to { opacity: 0; transform: translateX(-50%) translateY(-20px); } }
                `;
                document.head.appendChild(style);
            }
            
            document.body.appendChild(toast);
            
            // Auto remove after 3 seconds
            setTimeout(() => {
                toast.style.animation = 'toastSlideOut 0.3s ease-out forwards';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
        
        // Hide card with animation (direction: 'left', 'right', or 'scale')
        function hideCard(userId, direction = 'scale') {
            const card = document.querySelector(`.pm-swipe-card[data-user-id="${userId}"]`);
            if (card) {
                card.style.transition = 'opacity 0.4s ease-out, transform 0.4s ease-out';
                card.style.opacity = '0';
                
                if (direction === 'right') {
                    card.style.transform = 'translateX(150%) rotate(15deg)';
                } else if (direction === 'left') {
                    card.style.transform = 'translateX(-150%) rotate(-15deg)';
                } else {
                    card.style.transform = 'scale(0.8)';
                }
                
                setTimeout(() => card.remove(), 400);
            }
        }
        
        // Skip user - just hide from view
        window.pmSkipUser = function(userId) {
            hideCard(userId);
            // Store skipped users in localStorage to persist
            let skipped = JSON.parse(localStorage.getItem('pmSkippedUsers') || '[]');
            if (!skipped.includes(userId)) {
                skipped.push(userId);
                localStorage.setItem('pmSkippedUsers', JSON.stringify(skipped));
            }
        };
        
        // Unlike user - remove from liked list via API
        window.pmUnlikeUser = async function(userId) {
            // Hide immediately for responsiveness
            hideCard(userId);
            
            try {
                const response = await fetch('<?php echo rest_url('sk/v1/like'); ?>', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                    },
                    body: JSON.stringify({ user_id: userId })
                });
                
                if (!response.ok) {
                    console.error('Unlike API error:', await response.text());
                } else {
                    const data = await response.json();
                    console.log('Unlike response:', data);
                }
            } catch (error) {
                console.error('Unlike error:', error);
            }
        };
        
        // Like user - call API and hide
        window.pmLikeUser = async function(userId) {
            // Hide immediately with swipe right animation
            hideCard(userId, 'right');
            
            try {
                const response = await fetch('<?php echo rest_url('sk/v1/like'); ?>', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                    },
                    body: JSON.stringify({ user_id: userId })
                });
                
                if (!response.ok) {
                    console.error('Like API error:', await response.text());
                } else {
                    const data = await response.json();
                    console.log('Like response:', data);
                    // If it's a match, show notification!
                    if (data.is_match) {
                        alert('🎉 Masz Match! Możesz teraz porozmawiać.');
                    }
                }
            } catch (error) {
                console.error('Like error:', error);
            }
        };
        
        // ===== Super Wiadomość (Super Message) Functions =====
        let superMsgStatus = { is_premium: false, remaining_this_week: 0 };
        
        // Load Super Message status on page load
        async function loadSuperMsgStatus() {
            try {
                const response = await fetch('<?php echo rest_url('sk/v1/super-message/status'); ?>', {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                    }
                });
                if (response.ok) {
                    superMsgStatus = await response.json();
                    updateSuperMsgCounter();
                }
            } catch (e) {
                console.error('Failed to load super message status:', e);
            }
        }
        
        // Update counter display
        function updateSuperMsgCounter() {
            const counter = document.getElementById('pm-super-msg-counter');
            if (counter && superMsgStatus.is_premium) {
                counter.textContent = superMsgStatus.remaining_this_week;
                counter.closest('.pm-super-msg-header-counter').style.display = 'inline-flex';
            }
        }
        
        // Open Super Message modal
        window.pmOpenSuperMessage = function(userId, userName) {
            if (!superMsgStatus.is_premium) {
                alert('Super Wiadomości są dostępne tylko dla użytkowników Premium! ⭐');
                return;
            }
            
            if (superMsgStatus.remaining_this_week <= 0) {
                alert('Wykorzystałeś limit 3 Super Wiadomości na ten tydzień. Poczekaj do jutra lub kup więcej! 📅');
                return;
            }
            
            const modal = document.getElementById('pm-super-msg-modal');
            const recipientEl = document.getElementById('pm-super-msg-recipient');
            const textareaEl = document.getElementById('pm-super-msg-textarea');
            const remainingEl = document.getElementById('pm-super-msg-remaining-count');
            
            if (modal) {
                modal.dataset.userId = userId;
                if (recipientEl) recipientEl.textContent = `Do: ${userName}`;
                if (textareaEl) textareaEl.value = '';
                if (remainingEl) remainingEl.textContent = superMsgStatus.remaining_this_week;
                updateCharCounter();
                modal.classList.add('active');
            }
        };
        
        // Close Super Message modal
        window.pmCloseSuperMessage = function() {
            const modal = document.getElementById('pm-super-msg-modal');
            if (modal) {
                modal.classList.remove('active');
            }
        };
        
        // Update character counter - must be global for oninput attribute
        window.updateCharCounter = function() {
            const textarea = document.getElementById('pm-super-msg-textarea');
            const counter = document.getElementById('pm-super-msg-char-counter');
            const sendBtn = document.getElementById('pm-super-msg-send-btn');
            if (textarea && counter) {
                const len = textarea.value.length;
                counter.textContent = `${len}/500`;
                counter.style.color = len > 500 ? '#e74c3c' : (len < 10 ? '#888' : '#2ecc71');
                if (sendBtn) {
                    sendBtn.disabled = len < 10 || len > 500;
                }
            }
        };
        
        // Send Super Message
        window.pmSendSuperMessage = async function() {
            const modal = document.getElementById('pm-super-msg-modal');
            const textarea = document.getElementById('pm-super-msg-textarea');
            const sendBtn = document.getElementById('pm-super-msg-send-btn');
            
            if (!modal || !textarea) return;
            
            const userId = modal.dataset.userId;
            const message = textarea.value.trim();
            
            if (message.length < 10 || message.length > 500) {
                alert('Wiadomość musi mieć od 10 do 500 znaków.');
                return;
            }
            
            // Disable button during send
            if (sendBtn) {
                sendBtn.disabled = true;
                sendBtn.textContent = 'Wysyłanie...';
            }
            
            try {
                const response = await fetch('<?php echo rest_url('sk/v1/super-message/send'); ?>', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                    },
                    body: JSON.stringify({ to_user_id: parseInt(userId), message: message })
                });
                
                const data = await response.json();
                
                if (response.ok && data.success) {
                    superMsgStatus.remaining_this_week = data.remaining_this_week;
                    updateSuperMsgCounter();
                    pmCloseSuperMessage();
                    showToast('✉️ Super Wiadomość wysłana! Poczekaj na odpowiedź.', true);
                } else {
                    showToast('❌ ' + (data.message || 'Nie udało się wysłać wiadomości.'), false);
                }
            } catch (e) {
                console.error('Super message send error:', e);
                showToast('❌ Błąd połączenia. Spróbuj ponownie.', false);
            } finally {
                if (sendBtn) {
                    sendBtn.disabled = false;
                    sendBtn.textContent = '✉️ Wyślij Super Wiadomość';
                }
            }
        };
        
        // Initialize Super Message status
        loadSuperMsgStatus();
        
        // Load saved filters from localStorage on page init
        function loadFilters() {
            try {
                const saved = localStorage.getItem('pmFilters');
                if (saved) {
                    const filters = JSON.parse(saved);
                    
                    // Update age min slider
                    const ageMinEl = document.getElementById('pm-age-min');
                    const ageMaxEl = document.getElementById('pm-age-max');
                    const ageMinValEl = document.getElementById('pm-age-min-val');
                    const ageMaxValEl = document.getElementById('pm-age-max-val');
                    const ageFillEl = document.getElementById('pm-age-fill');
                    const hasBioEl = document.getElementById('pm-has-bio');
                    
                    if (ageMinEl && filters.ageMin) {
                        ageMinEl.value = filters.ageMin;
                        if (ageMinValEl) ageMinValEl.textContent = filters.ageMin;
                    }
                    
                    if (ageMaxEl && filters.ageMax) {
                        ageMaxEl.value = filters.ageMax;
                        if (ageMaxValEl) {
                            ageMaxValEl.textContent = filters.ageMax >= 65 ? '65+' : filters.ageMax;
                        }
                    }
                    
                    // Update the range fill
                    if (ageFillEl && filters.ageMin && filters.ageMax) {
                        const minVal = parseInt(filters.ageMin);
                        const maxVal = parseInt(filters.ageMax);
                        const left = ((minVal - 18) / 47) * 100;
                        const right = ((maxVal - 18) / 47) * 100;
                        ageFillEl.style.left = left + '%';
                        ageFillEl.style.width = (right - left) + '%';
                    }
                    
                    // Update has bio checkbox
                    if (hasBioEl && typeof filters.hasBio !== 'undefined') {
                        hasBioEl.checked = filters.hasBio;
                    }

                    // Update New Filters
                    ['faith', 'politics', 'work', 'diet', 'zodiac'].forEach(key => {
                        const el = document.getElementById('pm-filter-' + key);
                        if (el && filters[key]) {
                            el.value = filters[key];
                        }
                    });
                    
                    console.log('Filters loaded from localStorage:', filters);
                }
            } catch (e) {
                console.error('Error loading filters:', e);
            }
        }
        
        // Load filters on page init
        loadFilters();
    });
    </script>
    
    <!-- Super Wiadomość Modal -->
    <div id="pm-super-msg-modal" class="pm-super-msg-modal" onclick="if(event.target === this) pmCloseSuperMessage();">
        <div class="pm-super-msg-content">
            <div class="pm-super-msg-header">
                <div class="pm-super-msg-title">✉️ Super Wiadomość</div>
                <button class="pm-super-msg-close" onclick="pmCloseSuperMessage();">&times;</button>
            </div>
            <div id="pm-super-msg-recipient" class="pm-super-msg-recipient"></div>
            <textarea 
                id="pm-super-msg-textarea" 
                class="pm-super-msg-textarea" 
                placeholder="Napisz przemyślaną wiadomość, która zrobi wrażenie... (min. 10 znaków)"
                maxlength="500"
                oninput="window.updateCharCounter();"
            ></textarea>
            <div id="pm-super-msg-char-counter" class="pm-super-msg-counter">0/500</div>
            <div class="pm-super-msg-remaining">
                Pozostało Super Wiadomości w tym tygodniu: <span id="pm-super-msg-remaining-count">3</span>
            </div>
            <button id="pm-super-msg-send-btn" class="pm-super-msg-send" onclick="pmSendSuperMessage();" disabled>
                ✉️ Wyślij Super Wiadomość
            </button>
        </div>
    </div>
    
    <?php
}
add_action('wp_footer', 'pm_mobile_member_tabs', 99);

// ============================================================================
// GALERIA ZDJĘĆ NA PROFILU UŻYTKOWNIKA
// ============================================================================

/**
 * Wyświetla galerię zdjęć użytkownika na jego profilu BuddyPress
 * Pokazuje główne zdjęcie (avatar) oraz dodatkowe zdjęcia z onboardingu
 */
function sk_display_profile_photo_gallery() {
    if (!function_exists('bp_displayed_user_id')) return;
    
    $user_id = bp_displayed_user_id();
    if (!$user_id) return;
    
    // Pobierz główne zdjęcie (avatar)
    $avatar_id = get_user_meta($user_id, 'user_avatar_id', true);
    
    // Pobierz dodatkowe zdjęcia
    $photo_ids = get_user_meta($user_id, 'user_profile_photos_ids', true);
    if (!is_array($photo_ids)) $photo_ids = [];
    
    // Zbierz wszystkie zdjęcia (avatar + dodatkowe, bez duplikatów)
    $all_photo_ids = [];
    if ($avatar_id) {
        $all_photo_ids[] = $avatar_id;
    }
    foreach ($photo_ids as $pid) {
        if ($pid && !in_array($pid, $all_photo_ids)) {
            $all_photo_ids[] = $pid;
        }
    }
    
    // Jeśli nie ma żadnych zdjęć, nie wyświetlaj galerii
    if (empty($all_photo_ids)) return;
    
    // Jeśli jest tylko jedno zdjęcie, nie wyświetlaj galerii (avatar jest widoczny domyślnie)
    if (count($all_photo_ids) <= 1) return;
    
    $is_owner = (is_user_logged_in() && get_current_user_id() == $user_id);
    
    ?>
    <style>
        .gallery-photo-item .delete-photo-btn {
            position: absolute;
            top: 5px;
            right: 5px;
            background: rgba(231, 76, 60, 0.85);
            color: white;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            cursor: pointer;
            z-index: 5;
            transition: all 0.2s;
            border: none;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        .gallery-photo-item .delete-photo-btn:hover {
            background: #c0392b;
            transform: scale(1.1);
        }
        .gallery-photo-item .change-photo-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(0,0,0,0.5);
            color: white;
            padding: 5px;
            text-align: center;
            font-size: 11px;
            font-weight: 500;
            backdrop-filter: blur(2px);
            opacity: 0;
            transition: opacity 0.2s;
        }
        .gallery-photo-item:hover .change-photo-overlay {
            opacity: 1;
        }
        .upload-photo-spinner {
            display: none;
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.7);
            z-index: 10;
            align-items: center;
            justify-content: center;
        }
    </style>

    <div class="profile-photo-gallery" style="
        margin: 20px 0;
        padding: 15px;
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        position: relative;
    ">
        <h4 style="
            margin: 0 0 15px 0;
            font-size: 16px;
            font-weight: 600;
            color: #333;
            display: flex;
            align-items: center;
            gap: 8px;
        ">
            <span style="font-size: 20px;">📸</span> Zdjęcia
            <span style="
                background: #e91e63;
                color: white;
                font-size: 12px;
                padding: 2px 8px;
                border-radius: 10px;
                font-weight: normal;
            "><?php echo count($all_photo_ids); ?></span>
        </h4>
        
        <div class="photo-gallery-grid" style="
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
        ">
            <?php foreach ($all_photo_ids as $index => $photo_id): 
                $photo_url = wp_get_attachment_image_url($photo_id, 'medium_large');
                $photo_full_url = wp_get_attachment_image_url($photo_id, 'full');
                if (!$photo_url) continue;
            ?>
            <div class="gallery-photo-item" 
                 id="photo-item-<?php echo $photo_id; ?>"
                 style="
                    aspect-ratio: 1;
                    border-radius: 8px;
                    overflow: hidden;
                    cursor: pointer;
                    position: relative;
                    transition: transform 0.2s ease;
                 "
                 data-full-url="<?php echo esc_url($photo_full_url); ?>">
                
                <img src="<?php echo esc_url($photo_url); ?>" 
                     onclick="openPhotoLightbox(<?php echo $index; ?>)"
                     alt="Zdjęcie profilowe <?php echo $index + 1; ?>"
                     style="
                        width: 100%;
                        height: 100%;
                        object-fit: cover;
                        display: block;
                     ">

                <?php if ($is_owner): ?>
                    <button class="delete-photo-btn" onclick="event.stopPropagation(); deleteProfilePhoto(<?php echo $photo_id; ?>)" title="Usuń zdjęcie">✕</button>
                    
                    <?php if ($index === 0): ?>
                        <div class="change-photo-overlay" onclick="event.stopPropagation(); document.getElementById('avatar-upload-input').click();">
                            Zmień Zdjęcie
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($index === 0): ?>
                <span style="
                    position: absolute;
                    bottom: 5px;
                    left: 5px;
                    background: #e91e63;
                    color: white;
                    font-size: 10px;
                    padding: 2px 6px;
                    border-radius: 8px;
                    z-index: 2;
                ">Główne</span>
                <?php endif; ?>

                <div class="upload-photo-spinner" id="spinner-<?php echo $photo_id; ?>">
                    <img src="/wp-admin/images/spinner.gif" alt="Ładowanie...">
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($is_owner): ?>
            <input type="file" id="avatar-upload-input" style="display: none;" accept="image/*" onchange="uploadProfilePhoto(this)">
        <?php endif; ?>
    </div>
    
    <script>
        async function deleteProfilePhoto(photoId) {
            if (!confirm('Czy na pewno chcesz usunąć to zdjęcie?')) return;
            
            const spinner = document.getElementById('spinner-' + photoId);
            if (spinner) spinner.style.display = 'flex';
            
            try {
                const formData = new FormData();
                formData.append('action', 'sk_delete_profile_photo');
                formData.append('photo_id', photoId);
                formData.append('nonce', '<?php echo wp_create_nonce('sk_photo_management'); ?>');
                
                const response = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                if (data.success) {
                    location.reload();
                } else {
                    alert('Błąd: ' + (data.data || 'Nie udało się usunąć zdjęcia.'));
                    if (spinner) spinner.style.display = 'none';
                }
            } catch (e) {
                console.error('Delete error:', e);
                alert('Błąd połączenia.');
                if (spinner) spinner.style.display = 'none';
            }
        }

        async function uploadProfilePhoto(input) {
            if (!input.files || !input.files[0]) return;
            
            const file = input.files[0];
            const formData = new FormData();
            formData.append('action', 'sk_upload_profile_photo');
            formData.append('photo', file);
            formData.append('nonce', '<?php echo wp_create_nonce('sk_photo_management'); ?>');
            
            // Show global spinner or update the first item's spinner
            const firstSpinner = document.querySelector('.upload-photo-spinner');
            if (firstSpinner) firstSpinner.style.display = 'flex';
            
            try {
                const response = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                if (data.success) {
                    location.reload();
                } else {
                    alert('Błąd: ' + (data.data || 'Nie udało się wgrać zdjęcia.'));
                    if (firstSpinner) firstSpinner.style.display = 'none';
                }
            } catch (e) {
                console.error('Upload error:', e);
                alert('Błąd połączenia.');
                if (firstSpinner) firstSpinner.style.display = 'none';
            }
        }
    </script>
    
    <!-- Lightbox do powiększania zdjęć -->
    <div id="photo-lightbox" onclick="closePhotoLightbox()" style="
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.9);
        z-index: 99999;
        justify-content: center;
        align-items: center;
        padding: 20px;
    ">
        <button onclick="event.stopPropagation(); navigatePhoto(-1);" style="
            position: absolute;
            left: 20px;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            font-size: 24px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            cursor: pointer;
            z-index: 100000;
        ">❮</button>
        
        <img id="lightbox-image" src="" alt="Powiększone zdjęcie" style="
            max-width: 90%;
            max-height: 90%;
            object-fit: contain;
            border-radius: 8px;
        " onclick="event.stopPropagation();">
        
        <button onclick="event.stopPropagation(); navigatePhoto(1);" style="
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            font-size: 24px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            cursor: pointer;
            z-index: 100000;
        ">❯</button>
        
        <button onclick="closePhotoLightbox();" style="
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            font-size: 20px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            z-index: 100000;
        ">✕</button>
        
        <div id="lightbox-counter" style="
            position: absolute;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            color: white;
            font-size: 14px;
            background: rgba(0,0,0,0.5);
            padding: 5px 15px;
            border-radius: 20px;
        "></div>
    </div>
    
    <script>
    var galleryPhotos = [];
    var currentPhotoIndex = 0;
    
    document.querySelectorAll('.gallery-photo-item').forEach(function(item, index) {
        galleryPhotos.push(item.getAttribute('data-full-url'));
    });
    
    function openPhotoLightbox(index) {
        currentPhotoIndex = index;
        var lightbox = document.getElementById('photo-lightbox');
        var image = document.getElementById('lightbox-image');
        var counter = document.getElementById('lightbox-counter');
        
        image.src = galleryPhotos[index];
        counter.textContent = (index + 1) + ' / ' + galleryPhotos.length;
        lightbox.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
    
    function closePhotoLightbox() {
        document.getElementById('photo-lightbox').style.display = 'none';
        document.body.style.overflow = '';
    }
    
    function navigatePhoto(direction) {
        currentPhotoIndex += direction;
        if (currentPhotoIndex < 0) currentPhotoIndex = galleryPhotos.length - 1;
        if (currentPhotoIndex >= galleryPhotos.length) currentPhotoIndex = 0;
        
        var image = document.getElementById('lightbox-image');
        var counter = document.getElementById('lightbox-counter');
        
        image.src = galleryPhotos[currentPhotoIndex];
        counter.textContent = (currentPhotoIndex + 1) + ' / ' + galleryPhotos.length;
    }
    
    // Obsługa klawiszy strzałek
    document.addEventListener('keydown', function(e) {
        var lightbox = document.getElementById('photo-lightbox');
        if (lightbox.style.display === 'flex') {
            if (e.key === 'ArrowLeft') navigatePhoto(-1);
            if (e.key === 'ArrowRight') navigatePhoto(1);
            if (e.key === 'Escape') closePhotoLightbox();
        }
    });
    </script>
    <?php
}
add_action('bp_before_member_header_meta', 'sk_display_profile_photo_gallery');

/**
 * AJAX Handler for deleting a profile photo (non-mobile version)
 */
add_action('wp_ajax_sk_delete_profile_photo', 'sk_delete_profile_photo_ajax');
function sk_delete_profile_photo_ajax() {
    check_ajax_referer('sk_photo_management', 'nonce');
    
    if (!is_user_logged_in()) {
        wp_send_json_error('Musisz być zalogowany.');
    }
    
    $user_id = get_current_user_id();
    $photo_id = isset($_POST['photo_id']) ? intval($_POST['photo_id']) : 0;
    
    if (!$photo_id) {
        wp_send_json_error('Nieprawidłowe ID zdjęcia.');
    }
    
    $existing_ids = get_user_meta($user_id, 'user_profile_photos_ids', true);
    if (!is_array($existing_ids)) $existing_ids = array();
    
    if (!in_array($photo_id, $existing_ids)) {
        wp_send_json_error('Nie masz uprawnień do usunięcia tego zdjęcia.');
    }
    
    // Remove from gallery
    $new_ids = array_filter($existing_ids, function($id) use ($photo_id) {
        return (int)$id !== (int)$photo_id;
    });
    $new_ids = array_values($new_ids);
    update_user_meta($user_id, 'user_profile_photos_ids', $new_ids);
    
    // If it was the main avatar, update to the first remaining or empty
    $current_avatar_id = get_user_meta($user_id, 'user_avatar_id', true);
    if ((int)$current_avatar_id === (int)$photo_id) {
        if (!empty($new_ids)) {
            update_user_meta($user_id, 'user_avatar_id', $new_ids[0]);
        } else {
            delete_user_meta($user_id, 'user_avatar_id');
        }
    }
    
    wp_send_json_success('Zdjęcie zostało usunięte.');
}

/**
 * AJAX Handler for uploading/changing profile photo (non-mobile version)
 */
add_action('wp_ajax_sk_upload_profile_photo', 'sk_upload_profile_photo_ajax');
function sk_upload_profile_photo_ajax() {
    check_ajax_referer('sk_photo_management', 'nonce');
    
    if (!is_user_logged_in()) {
        wp_send_json_error('Musisz być zalogowany.');
    }
    
    if (!isset($_FILES['photo'])) {
        wp_send_json_error('Brak pliku.');
    }
    
    $user_id = get_current_user_id();
    
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    
    $attachment_id = media_handle_upload('photo', 0);
    
    if (is_wp_error($attachment_id)) {
        wp_send_json_error($attachment_id->get_error_message());
    }
    
    $existing_ids = get_user_meta($user_id, 'user_profile_photos_ids', true);
    if (!is_array($existing_ids)) $existing_ids = array();
    
    // Unshift to make it the first (main) photo
    array_unshift($existing_ids, $attachment_id);
    $existing_ids = array_unique($existing_ids);
    
    update_user_meta($user_id, 'user_profile_photos_ids', $existing_ids);
    update_user_meta($user_id, 'user_avatar_id', $attachment_id);
    
    wp_send_json_success('Zdjęcie zostało dodane.');
}

// ========================================
// FILTER: Ukryj użytkowników bez zdjęcia profilowego w REST API (dla aplikacji mobilnej)
// ========================================
add_filter('rest_post_dispatch', function($response, $server, $request) {
    // Tylko dla endpointu members BuddyPress
    $route = $request->get_route();
    if (strpos($route, '/buddypress/v1/members') === false) {
        return $response;
    }
    
    // Tylko dla listy członków (nie pojedynczego profilu ani /me)
    if (preg_match('/\/members\/(\d+|me)/', $route)) {
        return $response;
    }
    
    if (is_wp_error($response)) {
        return $response;
    }
    
    $data = $response->get_data();
    
    // Sprawdź czy to tablica memberów
    if (!is_array($data)) {
        return $response;
    }
    
    // Filtruj użytkowników bez zdjęcia profilowego
    $filtered_data = array();
    foreach ($data as $member) {
        // Sprawdź czy to member (ma id)
        if (!is_array($member) || !isset($member['id'])) {
            $filtered_data[] = $member;
            continue;
        }
        
        $user_id = intval($member['id']);
        $has_photo = false;
        
        if ($user_id > 0) {
            // Sprawdź 1: user_avatar_id (zdjęcie wrzucone przez nasz system)
            $user_avatar_id = get_user_meta($user_id, 'user_avatar_id', true);
            if (!empty($user_avatar_id)) {
                $has_photo = true;
            }
            
            // Sprawdź 2: user_profile_photos_ids (zdjęcia profilowe w galerii)
            if (!$has_photo) {
                $profile_photos_ids = get_user_meta($user_id, 'user_profile_photos_ids', true);
                if (!empty($profile_photos_ids) && is_array($profile_photos_ids) && count($profile_photos_ids) > 0) {
                    $has_photo = true;
                }
            }
            
            // Sprawdź 3: hires_avatar w odpowiedzi API (avatar BuddyPress)
            if (!$has_photo && isset($member['hires_avatar'])) {
                $hires_avatar = $member['hires_avatar'];
                // Sprawdź czy nie jest to domyślny avatar (mystery-man lub gravatar)
                if (!empty($hires_avatar) && 
                    strpos($hires_avatar, 'mystery-man') === false && 
                    strpos($hires_avatar, 'gravatar.com') === false &&
                    strpos($hires_avatar, 'default') === false) {
                    $has_photo = true;
                }
            }
            
            // Sprawdź 4: avatar_urls w odpowiedzi API
            if (!$has_photo && isset($member['avatar_urls']) && is_array($member['avatar_urls'])) {
                foreach ($member['avatar_urls'] as $size => $url) {
                    if (!empty($url) && 
                        strpos($url, 'mystery-man') === false && 
                        strpos($url, 'gravatar.com') === false &&
                        strpos($url, 'default') === false) {
                        $has_photo = true;
                        break;
                    }
                }
            }
        }
        
        if ($has_photo) {
            $filtered_data[] = $member;
        }
    }
    
    $response->set_data($filtered_data);
    
    return $response;
}, 10, 3);

/**
 * PrawdziwaMilosc Premium Profile Styles
 * Injects custom CSS to modernize BuddyPress profile edit pages.
 */
function hook_premium_profile_css() {
    if ( function_exists('is_buddypress') && is_buddypress() ) {
        ?>
        <style type="text/css">
            /* --- PM Premium Theme V8: Dark Matter & High Contrast --- */
            :root {
                --pm-gold: #d4af37;
                --pm-dark-bg: #121212;
                --pm-card-bg: #1e1e1e;
                --pm-input-bg: #2a2a2a;
                --pm-text-main: #ffffff;
                --pm-text-muted: #aaaaaa;
                --pm-border: #444;
            }

            /* --- FORCE ALL PARENTS TO FULL WIDTH (MOBILE FIX) --- */
            body.buddypress,
            body.buddypress .site,
            body.buddypress .site-content,
            body.buddypress .entry-content,
            body.buddypress .container,
            body.buddypress #content,
            body.buddypress div#primary,
            body.buddypress article,
            body.buddypress .hentry,
            body.buddypress main {
                width: 100% !important;
                max-width: 100% !important;
                padding-left: 0 !important;
                padding-right: 0 !important;
                margin-left: 0 !important;
                margin-right: 0 !important;
                overflow-x: hidden !important;
            }

            /* --- MAIN BUDDYPRESS CONTAINER --- */
            body.buddypress #buddypress {
                width: 100% !important;
                max-width: 100% !important;
                margin: 0 !important;
                padding: 10px !important; /* Minimal safety padding */
                background: var(--pm-dark-bg) !important;
                box-sizing: border-box !important;
            }

            /* --- KILL ALL LIGHT BACKGROUNDS --- */
            /* Targeting every possible container that could have a light background */
            #buddypress #profile-edit-form,
            #buddypress .profile,
            #buddypress .editfield,
            #buddypress fieldset,
            #buddypress div.field-group,
            #buddypress .bp-profile-edit,
            #buddypress table,
            #buddypress tr,
            #buddypress td,
            #buddypress .standard-form,
            #buddypress .odd,
            #buddypress .even,
            #buddypress .alt,
            #buddypress div {
                background-color: transparent !important;
                background-image: none !important;
                border: none !important;
                box-shadow: none !important;
                border-radius: 0 !important;
            }
=======
            /* --- PM Premium Theme V11: Nuclear Width & Green Box Killer --- */
            :root {
                --pm-gold: #d4af37;
                --pm-dark-bg: #121212;
                --pm-card-bg: #1e1e1e;
                --pm-input-bg: #2a2a2a;
                --pm-text-main: #ffffff;
                --pm-text-muted: #aaaaaa;
                --pm-border: #444;
            }

            /* --- GLOBAL DARK ENFORCER --- */
            html, body, #page, .site-content, .entry-content, article, main {
                background-color: var(--pm-dark-bg) !important;
                color: var(--pm-text-main) !important;
                width: 100% !important;
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow-x: hidden !important;
            }

            /* --- MAIN BUDDYPRESS CONTAINER (Edge-to-Edge) --- */
            body.buddypress #buddypress {
                width: 100vw !important;
                max-width: 100vw !important;
                margin-left: calc(50% - 50vw) !important;
                margin-right: calc(50% - 50vw) !important;
                padding: 10px 15px !important; /* Proper mobile side padding */
                background: var(--pm-dark-bg) !important;
                box-sizing: border-box !important;
            }

            /* --- THE NUCLEAR OPTION: KILL GREEN BOXES & BORDERS --- */
            /* Using wildcard selectors to strip styles from EVERYTHING deeply nested */
            #buddypress form *, 
            #buddypress form *::before, 
            #buddypress form *::after {
                background-color: transparent !important;
                background-image: none !important;
                border-color: transparent !important;
                box-shadow: none !important;
                border-radius: 0 !important;
            }

            /* --- RESTORE STYLES FOR INPUTS ONLY --- */
            #buddypress input, 
            #buddypress select, 
            #buddypress textarea {
                background-color: var(--pm-input-bg) !important;
                border: 1px solid var(--pm-border) !important;
                border-radius: 8px !important;
            }
            #buddypress input:focus, 
            #buddypress select:focus {
                border-color: var(--pm-gold) !important;
            }
            
            /* --- RESTORE RADIO BUTTONS --- */
            #buddypress input[type="radio"] {
                accent-color: var(--pm-gold) !important;
                border: 1px solid #555 !important;
                border-radius: 50% !important;
                width: 20px !important;
                height: 20px !important;
                background-color: #333 !important;
            }

            /* --- RESTORE VISIBILITY CONTAINER --- */
            /* This needs a specific background to stand out */
            body #buddypress .field-visibility-settings {
                background-color: #1a1a1a !important;
                border: 1px solid #444 !important; /* Restore border */
                border-color: #444 !important;
                border-radius: 12px !important;
                padding: 20px !important;
                margin-top: 15px !important;
            }
            
            /* --- RESTORE SUBMIT BUTTON --- */
            #buddypress .submit input {
                background: linear-gradient(135deg, #d4af37 0%, #aa8c2c 100%) !important;
                color: #000 !important;
                border-radius: 50px !important;
            }
            
            /* --- RESTORE TABS --- */
            #buddypress #profile-group-tabs li a {
                background-color: #333 !important;
                border-radius: 50px !important;
            }
            #buddypress #profile-group-tabs li.current a {
                background-color: var(--pm-gold) !important;
            }

            /* --- FORM CONTAINERS RESET --- */
            #buddypress #profile-edit-form,
            #buddypress .profile,
            #buddypress fieldset,
            #buddypress .editfield,
            #buddypress .bp-profile-edit {
                width: 100% !important;
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                border: none !important;
            }

            /* --- HEADINGS --- */
            #buddypress h4, 
            #buddypress legend,
            #buddypress .label {
                color: var(--pm-gold) !important;
                font-size: 1.1rem !important;
                font-weight: 700 !important;
                margin: 25px 0 10px 0 !important;
                text-transform: uppercase !important;
                border-bottom: 2px solid #333 !important;
                border-color: #333 !important; /* Ensure border color override works */
                padding-bottom: 5px !important;
                width: 100% !important;
                display: block !important;
            }

            /* --- LABELS --- */
            #buddypress label {
                color: var(--pm-text-muted) !important;
                font-size: 0.85rem !important;
                font-weight: 600 !important;
                text-transform: uppercase !important;
                margin-bottom: 8px !important;
                display: block !important;
            }

            /* --- FIELD SPACING --- */
            #buddypress .editfield {
                margin-bottom: 30px !important;
            }
            
            /* --- DATE BOX FIX --- */
            #buddypress .editfield.datebox {
                display: flex !important;
                gap: 10px !important;
            }
            #buddypress .editfield.datebox label { display: none !important; }
            
            /* --- VISIBILITY TOGGLE LINK --- */
            .field-visibility-settings-toggle a {
                border: 1px solid #444 !important;
                border-color: #444 !important;
                border-radius: 20px !important;
                padding: 5px 15px !important;
            }

            /* --- HEADINGS & LEGENDS --- */
            /* Ensure they are visible and gold */
            #buddypress h4, 
            #buddypress legend,
            #buddypress .label {
                color: var(--pm-gold) !important;
                font-size: 1.2rem !important;
                font-weight: 800 !important;
                margin: 30px 0 15px 0 !important;
                text-transform: uppercase !important;
                border-bottom: 2px solid #333 !important;
                padding-bottom: 5px !important;
                width: 100% !important;
                display: block !important;
                background: transparent !important;
                text-shadow: none !important;
            }

            /* --- LABELS --- */
            #buddypress label {
                display: block !important;
                color: var(--pm-text-muted) !important;
                font-size: 0.85rem !important;
                margin-bottom: 8px !important;
                font-weight: 600 !important;
                text-transform: uppercase !important;
                background: transparent !important;
            }

            /* --- FORM FIELDS CONTAINER --- */
            #buddypress .editfield {
                margin-bottom: 30px !important;
                width: 100% !important;
                clear: both !important;
                padding: 0 !important;
            }

            /* --- INPUTS --- */
            #buddypress input[type=text],
            #buddypress textarea,
            #buddypress select {
                background: var(--pm-input-bg) !important;
                border: 1px solid var(--pm-border) !important;
                color: #fff !important;
                border-radius: 8px !important;
                padding: 16px !important;
                font-size: 1rem !important;
                width: 100% !important;
                box-sizing: border-box !important;
                height: auto !important;
                -webkit-appearance: none !important;
            }
            
            #buddypress input:focus, #buddypress select:focus {
                border-color: var(--pm-gold) !important;
                outline: none !important;
                box-shadow: 0 0 10px rgba(212, 175, 55, 0.2) !important;
            }

            /* --- DATE FIELDS --- */
            #buddypress .editfield.datebox {
                display: flex !important;
                flex-wrap: nowrap !important;
                gap: 10px !important;
                align-items: center !important;
            }
            #buddypress .editfield.datebox label { display: none !important; }
            #buddypress .editfield.datebox select {
                flex: 1 !important;
                text-align: center !important;
                min-width: 0 !important;
            }

            /* --- RADIO BUTTONS --- */
            #buddypress .radio label {
                display: flex !important;
                align-items: center !important;
                margin: 0 0 12px 0 !important;
                color: #fff !important;
                font-size: 1rem !important;
                text-transform: none !important;
                font-weight: 400 !important;
            }
            #buddypress .radio input[type="radio"] {
                width: 24px !important;
                height: 24px !important;
                margin-right: 15px !important;
                accent-color: var(--pm-gold) !important;
                border: 1px solid #555 !important;
                background: #2a2a2a !important;
            }

            /* --- TABS --- */
            #buddypress #profile-group-tabs {
                display: flex !important;
                gap: 10px !important;
                padding: 15px 0 !important;
                margin-bottom: 20px !important;
                overflow-x: auto !important;
                white-space: nowrap !important;
                width: 100% !important;
            }
            #buddypress #profile-group-tabs li {
                list-style: none !important;
                margin: 0 !important;
                flex: 0 0 auto !important;
            }
            #buddypress #profile-group-tabs li a {
                display: block !important;
                padding: 12px 24px !important;
                background: #333 !important;
                color: #ccc !important;
                border-radius: 50px !important;
                font-size: 0.95rem !important;
                text-decoration: none !important;
            }
            #buddypress #profile-group-tabs li.current a {
                background: var(--pm-gold) !important;
                color: #000 !important;
                font-weight: 800 !important;
            }

           /* --- VISIBILITY SETTINGS (Fix for Desktop & Mobile) --- */
            .field-visibility-settings-toggle {
                display: block !important;
                margin-top: 10px !important;
                text-align: right !important;
            }
            .field-visibility-settings-toggle a {
                color: var(--pm-text-muted) !important;
                font-size: 0.8rem !important;
                text-decoration: none !important;
                border: 1px solid #444 !important;
                padding: 6px 12px !important;
                border-radius: 20px !important;
                display: inline-block !important;
                position: relative !important;
                z-index: 10 !important;
            }
            
            /* Container for the visibility options - NEEDS DARK BACKGROUND */
            body #buddypress .field-visibility-settings,
            #buddypress .field-visibility-settings {
                display: block !important;
                margin-top: 15px !important;
                background: #1a1a1a !important; /* Force dark bg */
                padding: 20px !important;
                border: 1px solid #444 !important;
                border-radius: 12px !important;
                width: 100% !important;
                box-sizing: border-box !important;
                color: #fff !important;
                clear: both !important;
            }
            
            /* Fix invisible headings inside visibility settings */
            #buddypress .field-visibility-settings legend, 
            #buddypress .field-visibility-settings h4 {
                display: block !important;
                color: var(--pm-gold) !important;
                margin-bottom: 15px !important;
                font-size: 1rem !important;
                border-bottom: 1px solid #333 !important;
                text-align: left !important;
                width: 100% !important;
            }
            
            /* Enforce vertical stacking for standard BP structure (radio inputs often direct children or wrapped in labels) */
            #buddypress .field-visibility-settings label {
                display: flex !important;
                align-items: center !important;
                width: 100% !important;
                margin-bottom: 12px !important;
                color: #fff !important;
                font-size: 1rem !important;
                background: transparent !important;
                text-transform: none !important;
                font-weight: 400 !important;
            }
            
            #buddypress .field-visibility-settings input[type="radio"] {
                width: 24px !important;
                height: 24px !important;
                margin-right: 15px !important;
                accent-color: var(--pm-gold) !important;
                flex-shrink: 0 !important; /* Prevent squishing */
                display: inline-block !important;
            }

            #buddypress .field-visibility-settings .radio {
                display: block !important;
                width: 100% !important;
            }

            /* Handle close button if present */
            #buddypress .field-visibility-settings .close {
                display: inline-block !important;
                margin-top: 15px !important;
                padding: 8px 16px !important;
                background: #333 !important;
                color: #fff !important;
                border-radius: 4px !important;
                text-decoration: none !important;
                cursor: pointer !important;
                border: 1px solid #555 !important;
            }

            /* --- SUBMIT --- */
            #buddypress .submit input {
                width: 100% !important;
                padding: 20px !important;
                background: linear-gradient(135deg, #d4af37 0%, #aa8c2c 100%) !important;
                color: #000 !important;
                font-weight: 800 !important;
                text-transform: uppercase !important;
                border: none !important;
                border-radius: 50px !important;
                margin-top: 40px !important;
                font-size: 1.2rem !important;
                letter-spacing: 1px !important;
                cursor: pointer !important;
                box-shadow: 0 4px 15px rgba(212, 175, 55, 0.3) !important;
            }
        </style>
        <?php
    }
}
add_action('wp_head', 'hook_premium_profile_css');

// ========================================
// PM Premium: V14 BULLETPROOF Avatar Quality Boost
// ========================================

// 1. HTML FILTER (For <img> tags)
function hook_bp_avatar_quality_boost_opt($html, $params = [], $item_id = 0, $avatar_dir = '', $css_id = '', $html_width = 0, $html_height = 0, $avatar_folder_url = '', $avatar_folder_dir = '') {
    
    // Safety check for params array
    if ( ! is_array($params) ) return $html;

    $object = isset($params['object']) ? $params['object'] : 'user';

    // Only mess with User avatars
    if ( $object !== 'user' && $object !== 'member' ) {
        return $html;
    }

    if ( ! $item_id ) return $html;

    $attach_id = get_user_meta($item_id, 'user_avatar_id', true);

    if ( $attach_id ) {
        $hires_url = wp_get_attachment_image_url($attach_id, 'large'); 
        if ( ! $hires_url ) $hires_url = wp_get_attachment_image_url($attach_id, 'full');

        if ( $hires_url ) {
            $html = preg_replace('/src=["\']([^"\']+)["\']/', 'src="' . esc_url($hires_url) . '"', $html);
            $html = preg_replace('/srcset=["\']([^"\']+)["\']/', '', $html);
            $html = preg_replace('/sizes=["\']([^"\']+)["\']/', '', $html);
            $html = str_replace('class="', 'class="pm-hires-avatar-html ', $html);
        }
    }

    // --- PREMIUM BADGE INJECTION (DESKTOP FIX) ---
    // Force debug or check capability
    $is_premium_debug = true; 
    // $is_premium = sk_is_premium_user($item_id);

    if ( $is_premium_debug ) {
        $badge = '<span class="sk-bp-premium-badge" style="
            position: absolute; 
            top: -3px; 
            right: -3px; 
            font-size: 14px; 
            line-height: 1; 
            z-index: 999;
            filter: drop-shadow(0 0 2px rgba(0,0,0,0.5));
            pointer-events: none;
        ">⭐</span>';
        
        // Wrap in relative container if not already handled by parent
        // We use a span with inline-flex to minimize layout disruption
        return '<span class="sk-bp-avatar-wrapper" style="position: relative; display: inline-block; line-height: 0;">' . $html . $badge . '</span>';
    }

    return $html;
}
add_filter('bp_core_fetch_avatar', 'hook_bp_avatar_quality_boost_opt', 20, 9);


// 2. URL FILTER (For JS/CSS, background-images)
// Using default args to prevent ArgumentCountError
function hook_bp_avatar_urls_bulletproof($url, $item_id = 0, $object = 'user') {
    
    if ( $object !== 'user' && $object !== 'member' ) {
        return $url;
    }

    if ( empty($item_id) || ! is_numeric($item_id) ) {
        return $url;
    }

    $attach_id = get_user_meta($item_id, 'user_avatar_id', true);
    
    if ( $attach_id ) {
        $hires_url = wp_get_attachment_image_url($attach_id, 'large');
        if ( ! $hires_url ) $hires_url = wp_get_attachment_image_url($attach_id, 'full');

        if ( $hires_url ) {
            return add_query_arg('t', time(), $hires_url);
        }
    }
    return add_query_arg('t', time(), $url);
}
add_filter('bp_core_fetch_avatar_url', 'hook_bp_avatar_urls_bulletproof', 20, 3);



// TYMCZASOWE - usuń po testach!
add_action('admin_init', function() {
    if (isset($_GET['reset_super_msg']) && $_GET['reset_super_msg'] == '335') {
        delete_user_meta(335, 'sk_super_messages_sent');
        delete_user_meta(335, 'sk_super_messages_received');
        delete_user_meta(335, 'sk_super_messages_weekly_count');
        delete_user_meta(335, 'sk_super_messages_week'); // <- to jest prawidłowy klucz licznika!
        delete_user_meta(335, 'sk_super_message_history');
        delete_user_meta(335, 'sk_super_message_cooldowns');
        wp_die('Reset wykonany dla użytkownika 335! Możesz zamknąć tę stronę.');
    }
});

// TYMCZASOWE - usuń po testach!
add_action('admin_init', function() {
    if (isset($_GET['reset_super_msg']) && $_GET['reset_super_msg'] == '429') {
        delete_user_meta(429, 'sk_super_messages_sent');
        delete_user_meta(429, 'sk_super_messages_received');
        delete_user_meta(429, 'sk_super_messages_weekly_count');
        delete_user_meta(429, 'sk_super_messages_week'); // <- to jest prawidłowy klucz licznika!
        delete_user_meta(429, 'sk_super_message_history');
        delete_user_meta(429, 'sk_super_message_cooldowns');
        wp_die('Reset wykonany dla użytkownika 429!');
    }
});



// ========================================
// PM Premium: Global Avatar Badge Injection
// ========================================
function sk_inject_premium_badge_global($avatar, $id_or_email, $size, $default, $alt) {
    // 1. Get User ID
    $user_id = 0;
    if (is_numeric($id_or_email)) {
        $user_id = (int) $id_or_email;
    } elseif (is_string($id_or_email) && ($user = get_user_by('email', $id_or_email))) {
        $user_id = $user->ID;
    } elseif (is_object($id_or_email) && !empty($id_or_email->user_id)) {
        $user_id = (int) $id_or_email->user_id;
    }

    if (!$user_id) return $avatar;

    // 2. Check Premium
    $is_premium = sk_is_premium_user($user_id);

    if ($is_premium) {
        // 3. Inject Badge
        $badge = '<span class="sk-global-premium-badge" style="
            position: absolute; 
            top: -5px; 
            right: -5px; 
            font-size: 14px; 
            line-height: 1; 
            z-index: 999;
            filter: drop-shadow(0 0 2px rgba(0,0,0,0.5));
            pointer-events: none;
        ">⭐</span>';

        return '<span class="sk-avatar-wrapper" style="position: relative; display: inline-block;">' . $avatar . $badge . '</span>';
    }

    return $avatar;
}
add_filter('get_avatar', 'sk_inject_premium_badge_global', 100, 5);

/**
 * Dodaj banner Beta na górze strony
 */
function pm_display_beta_banner() {
    // Wyświetl tylko dla zalogowanych użytkowników
    if (!is_user_logged_in()) {
        return;
    }
    
    // Nie wyświetlaj w panelu admina
    if (is_admin()) {
        return;
    }
    ?>
    <!-- Beta banner - pokazuje się tylko raz na sesję -->
    <div id="pm-beta-banner" style="
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 999999;
        background: linear-gradient(135deg, rgba(212, 175, 55, 0.95) 0%, rgba(244, 208, 63, 0.9) 100%);
        border-bottom: 2px solid rgba(212, 175, 55, 0.5);
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.3);
        padding: 12px 20px;
        text-align: center;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        display: none;
    ">
        <div style="
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
        ">
            <span style="
                background: rgba(26, 26, 26, 0.9);
                color: #f4d03f;
                font-weight: 700;
                font-size: 0.75rem;
                padding: 4px 10px;
                border-radius: 4px;
                letter-spacing: 0.5px;
                text-transform: uppercase;
            ">BETA</span>
            <span style="
                color: #1a1a1a;
                font-size: 0.875rem;
                font-weight: 500;
                line-height: 1.4;
            ">
                Witamy w wersji testowej! Aplikacja jest w fazie rozwoju - mogą pojawić się błędy. Dziękujemy za cierpliwość i zgłaszanie uwag! 💙
            </span>
            <button onclick="pmCloseBetaBanner()" style="
                background: rgba(26, 26, 26, 0.2);
                border: 1px solid rgba(26, 26, 26, 0.3);
                color: #1a1a1a;
                padding: 4px 12px;
                border-radius: 4px;
                cursor: pointer;
                font-size: 0.75rem;
                font-weight: 600;
                margin-left: auto;
                transition: all 0.2s ease;
            " onmouseover="this.style.background='rgba(26, 26, 26, 0.3)'" onmouseout="this.style.background='rgba(26, 26, 26, 0.2)'">
                Ukryj
            </button>
        </div>
    </div>
    
    <script>
    // Funkcja do zamknięcia bannera i zapisania do sessionStorage
    function pmCloseBetaBanner() {
        document.getElementById('pm-beta-banner').style.display = 'none';
        document.body.style.paddingTop = '0';
        sessionStorage.setItem('pm_beta_banner_shown', 'true');
    }
    
    // Sprawdź czy banner był już pokazany w tej sesji
    (function() {
        var banner = document.getElementById('pm-beta-banner');
        if (!sessionStorage.getItem('pm_beta_banner_shown')) {
            // Pierwszy raz w tej sesji - pokaż banner
            banner.style.display = 'block';
            sessionStorage.setItem('pm_beta_banner_shown', 'true');
        } else {
            // Banner już był pokazany - nie pokazuj
            document.body.style.paddingTop = '0';
        }
    })();
    </script>
    
    <style>
        /* Dodaj padding do body żeby banner nie zakrywał contentu - tylko gdy banner widoczny */
        body {
            padding-top: 50px !important;
        }
        
        /* Responsywność dla mobile */
        @media (max-width: 768px) {
            #pm-beta-banner {
                padding: 10px 16px;
                font-size: 0.8125rem;
            }
            
            #pm-beta-banner > div {
                flex-direction: column;
                gap: 8px;
            }
            
            #pm-beta-banner button {
                margin-left: 0;
                width: 100%;
            }
            
            body {
                padding-top: 80px !important;
            }
        }
        
        /* Usuń padding gdy banner jest ukryty */
        body:has(#pm-beta-banner[style*="display: none"]) {
            padding-top: 0 !important;
        }
    </style>
    <?php
}
add_action('wp_body_open', 'pm_display_beta_banner', 1);
// Fallback dla starszych wersji WordPress bez wp_body_open
add_action('wp_footer', function() {
    if (!did_action('wp_body_open')) {
        pm_display_beta_banner();
    }
}, 1);

/**
 * ==============================================================
 * SOFT BOARD - Przestrzeń Refleksji (Conscious Dating Board)
 * ==============================================================
 * Shortcode: [pm_activity_feed]
 * A calm, intentional reflection space instead of typical social feed
 */

/**
 * Get daily reflection prompt based on day of year
 */
function pm_get_daily_prompt() {
    $prompts = array(
        'Czego szukam w głębokiej relacji?',
        'Co ostatnio pomogło mi lepiej rozumieć siebie?',
        'Jaka wartość jest dla mnie najważniejsza w miłości?',
        'Co mnie inspiruje w budowaniu bliskości?',
        'Za co jestem dziś wdzięczny/a w kontekście relacji?',
        'Czego się ostatnio nauczyłem/am o sobie?',
        'Jak chcę się czuć w relacji?',
        'Co oznacza dla mnie świadome randkowanie?',
        'Jaką przestrzeń chcę tworzyć dla drugiej osoby?',
        'Co pomaga mi być autentyczny/a?',
        'Jaki jest mój sposób na okazywanie miłości?',
        'Co mnie buduje jako partnera/partnerkę?',
        'Czym się ostatnio podzieliłem/am z bliską osobą?',
        'Jak dbam o swoją równowagę emocjonalną?'
    );
    
    $day_of_year = date('z');
    return $prompts[$day_of_year % count($prompts)];
}

/**
 * Check if user can post (24h cooldown)
 */
function pm_check_posting_cooldown($user_id) {
    $last_post_time = get_user_meta($user_id, 'pm_last_board_post_time', true);
    
    if (!$last_post_time) {
        return array('can_post' => true, 'time_remaining' => 0);
    }
    
    $cooldown_hours = 24;
    $time_since_post = time() - intval($last_post_time);
    $cooldown_seconds = $cooldown_hours * 3600;
    
    if ($time_since_post >= $cooldown_seconds) {
        return array('can_post' => true, 'time_remaining' => 0);
    }
    
    $remaining = $cooldown_seconds - $time_since_post;
    $hours = floor($remaining / 3600);
    $minutes = floor(($remaining % 3600) / 60);
    
    return array(
        'can_post' => false, 
        'time_remaining' => $remaining,
        'time_display' => $hours . 'h ' . $minutes . 'min'
    );
}

function pm_activity_feed_shortcode() {
    // Check if user is logged in
    if (!is_user_logged_in()) {
        return '<p style="text-align:center; color:#8b7355; font-family: Georgia, serif;">Musisz być zalogowany, aby wejść do przestrzeni refleksji.</p>';
    }
    
    $user_id = get_current_user_id();
    $daily_prompt = pm_get_daily_prompt();
    $cooldown = pm_check_posting_cooldown($user_id);
    
    // Get existing posts from BuddyPress Activity
    $posts = array();
    if (function_exists('bp_activity_get')) {
        $activities = bp_activity_get(array(
            'action' => 'activity_update',
            'per_page' => 10,
            'page' => 1
        ));
        if (!empty($activities['activities'])) {
            $posts = $activities['activities'];
        }
    }
    
    ob_start();
    ?>
    
    <div class="pm-soft-board-container">
        
        <!-- Guidelines Banner -->
        <div class="pm-board-guidelines">
            <h3>🌿 Przestrzeń Refleksji</h3>
            <p>To miejsce do dzielenia się przemyśleniami o miłości, relacjach i osobistym rozwoju.</p>
            <div class="pm-guidelines-list">
                <span>💭 Pisz szczerze, z serca</span>
                <span>🕊️ Szanuj intymność innych</span>
                <span>🌱 Każdy jest na swojej drodze</span>
            </div>
        </div>
        
        <!-- Daily Prompt -->
        <div class="pm-daily-prompt">
            <span class="pm-prompt-label">Dzisiejsze pytanie do refleksji:</span>
            <p class="pm-prompt-text"><?php echo esc_html($daily_prompt); ?></p>
        </div>
        
        <!-- Post Composer -->
        <div class="pm-post-composer <?php echo !$cooldown['can_post'] ? 'pm-composer-disabled' : ''; ?>">
            <?php if (!$cooldown['can_post']): ?>
                <div class="pm-cooldown-notice">
                    <span class="pm-cooldown-icon">⏳</span>
                    <p>Możesz podzielić się kolejną refleksją za <strong><?php echo $cooldown['time_display']; ?></strong></p>
                    <small>Jeden post dziennie pozwala na głębsze przemyślenia.</small>
                </div>
            <?php else: ?>
                <form id="pm-post-form" method="post">
                    <?php wp_nonce_field('pm_soft_board_nonce', 'pm_soft_board_nonce'); ?>
                    <div class="pm-composer-header">
                        <div class="pm-composer-avatar">
                            <?php echo get_avatar($user_id, 48); ?>
                        </div>
                        <div class="pm-composer-input-wrapper">
                            <textarea 
                                id="pm-post-content" 
                                name="pm_post_content" 
                                placeholder="Podziel się swoją refleksją..." 
                                minlength="100"
                                maxlength="500"
                                rows="4"
                            ></textarea>
                            <div class="pm-char-info">
                                <span class="pm-char-min">Min. 100 znaków</span>
                                <span class="pm-char-counter">0/500</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="pm-post-composer-footer">
                        <span class="pm-reflection-hint">💡 Zastanów się chwilę przed wysłaniem</span>
                        <button type="submit" class="pm-post-button" disabled>
                            <span class="pm-btn-text">Podziel się</span>
                            <span class="pm-btn-loading" style="display:none;">Wysyłam...</span>
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
        
        <!-- Reflections Stream -->
        <div id="pm-reflections-stream">
            <h4 class="pm-stream-title">Refleksje społeczności</h4>
            <div class="pm-reflections-list">
                <?php if (empty($posts)): ?>
                    <div class="pm-no-reflections">
                        <span class="pm-empty-icon">🌱</span>
                        <p>Bądź pierwszą osobą, która podzieli się refleksją.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($posts as $post): 
                        $post_user_id = $post->user_id;
                        $display_name = bp_core_get_user_displayname($post_user_id);
                        $avatar = get_avatar($post_user_id, 48);
                        $content = wp_kses_post($post->content);
                        $time_ago = human_time_diff(strtotime($post->date_recorded), current_time('timestamp')) . ' temu';
                        $user_profile_url = bp_core_get_user_domain($post_user_id);
                    ?>
                        <article class="pm-reflection-item">
                            <div class="pm-reflection-header">
                                <a href="<?php echo esc_url($user_profile_url); ?>" class="pm-reflection-avatar">
                                    <?php echo $avatar; ?>
                                </a>
                                <div class="pm-reflection-meta">
                                    <a href="<?php echo esc_url($user_profile_url); ?>" class="pm-reflection-author"><?php echo esc_html($display_name); ?></a>
                                    <span class="pm-reflection-time"><?php echo $time_ago; ?></span>
                                </div>
                            </div>
                            <div class="pm-reflection-content">
                                <?php echo $content; ?>
                            </div>
                            <div class="pm-reflection-footer">
                                <a href="<?php echo esc_url($user_profile_url); ?>" class="pm-view-profile-link">Zobacz profil →</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <style>
        /* ===== SOFT BOARD - Calm, Intentional Design ===== */
        
        @import url('https://fonts.googleapis.com/css2?family=Crimson+Text:ital,wght@0,400;0,600;1,400&family=Inter:wght@400;500;600&display=swap');
        
        .pm-soft-board-container {
            max-width: 640px;
            margin: 0 auto;
            padding: 24px 16px;
            background: linear-gradient(180deg, #faf9f6 0%, #f5f3ef 100%);
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        /* Guidelines Banner */
        .pm-board-guidelines {
            background: linear-gradient(135deg, #f8f6f1 0%, #ede9e0 100%);
            border: 1px solid #e0dcd3;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            text-align: center;
        }
        
        .pm-board-guidelines h3 {
            font-family: 'Crimson Text', Georgia, serif;
            font-size: 1.5rem;
            color: #5c5346;
            margin: 0 0 12px 0;
            font-weight: 600;
        }
        
        .pm-board-guidelines p {
            color: #7a7265;
            font-size: 0.95rem;
            margin: 0 0 16px 0;
            line-height: 1.6;
        }
        
        .pm-guidelines-list {
            display: flex;
            justify-content: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .pm-guidelines-list span {
            color: #8b7355;
            font-size: 0.85rem;
        }
        
        /* Daily Prompt */
        .pm-daily-prompt {
            background: #fff;
            border: 1px solid #e8e4dc;
            border-left: 4px solid #b8a88a;
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 24px;
        }
        
        .pm-prompt-label {
            display: block;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #a09485;
            margin-bottom: 8px;
        }
        
        .pm-prompt-text {
            font-family: 'Crimson Text', Georgia, serif;
            font-size: 1.25rem;
            font-style: italic;
            color: #5c5346;
            margin: 0;
            line-height: 1.5;
        }
        
        /* Post Composer */
        .pm-post-composer {
            background: #fff;
            border: 1px solid #e8e4dc;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 32px;
            box-shadow: 0 2px 12px rgba(92, 83, 70, 0.06);
        }
        
        .pm-composer-disabled {
            opacity: 0.9;
        }
        
        .pm-cooldown-notice {
            text-align: center;
            padding: 16px;
        }
        
        .pm-cooldown-icon {
            font-size: 2rem;
            display: block;
            margin-bottom: 12px;
        }
        
        .pm-cooldown-notice p {
            color: #5c5346;
            margin: 0 0 8px 0;
        }
        
        .pm-cooldown-notice small {
            color: #a09485;
            font-size: 0.85rem;
        }
        
        .pm-composer-header {
            display: flex;
            gap: 16px;
        }
        
        .pm-composer-avatar img {
            border-radius: 50%;
            width: 48px;
            height: 48px;
            border: 2px solid #e8e4dc;
        }
        
        .pm-composer-input-wrapper {
            flex: 1;
        }
        
        #pm-post-content {
            width: 100%;
            background: #faf9f6;
            border: 1px solid #e0dcd3;
            border-radius: 12px;
            padding: 16px;
            color: #3d3730;
            font-size: 1rem;
            font-family: 'Crimson Text', Georgia, serif;
            line-height: 1.6;
            resize: none;
            min-height: 120px;
            transition: border-color 0.3s, box-shadow 0.3s;
        }
        
        #pm-post-content:focus {
            outline: none;
            border-color: #b8a88a;
            box-shadow: 0 0 0 3px rgba(184, 168, 138, 0.15);
            background: #fff;
        }
        
        #pm-post-content::placeholder {
            color: #a09485;
            font-style: italic;
        }
        
        .pm-char-info {
            display: flex;
            justify-content: space-between;
            margin-top: 8px;
            font-size: 0.8rem;
        }
        
        .pm-char-min {
            color: #a09485;
        }
        
        .pm-char-min.pm-satisfied {
            color: #7a9b76;
        }
        
        .pm-char-counter {
            color: #a09485;
        }
        
        .pm-char-counter.pm-warning {
            color: #c4956a;
        }
        
        .pm-post-composer-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #e8e4dc;
        }
        
        .pm-reflection-hint {
            color: #a09485;
            font-size: 0.85rem;
        }
        
        .pm-post-button {
            background: linear-gradient(135deg, #8b7355 0%, #7a6548 100%);
            color: #fff;
            border: none;
            padding: 12px 24px;
            border-radius: 24px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.3s;
            font-family: 'Inter', sans-serif;
        }
        
        .pm-post-button:hover:not(:disabled) {
            background: linear-gradient(135deg, #7a6548 0%, #6a563a 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(139, 115, 85, 0.25);
        }
        
        .pm-post-button:disabled {
            background: #d4cfc5;
            color: #a09485;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        /* Reflections Stream */
        #pm-reflections-stream {
            margin-top: 8px;
        }
        
        .pm-stream-title {
            font-family: 'Crimson Text', Georgia, serif;
            font-size: 1.1rem;
            color: #5c5346;
            margin: 0 0 20px 0;
            padding-bottom: 12px;
            border-bottom: 1px solid #e0dcd3;
        }
        
        .pm-reflections-list {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .pm-no-reflections {
            text-align: center;
            padding: 48px 24px;
            background: #fff;
            border-radius: 16px;
            border: 1px dashed #d4cfc5;
        }
        
        .pm-empty-icon {
            font-size: 2.5rem;
            display: block;
            margin-bottom: 16px;
        }
        
        .pm-no-reflections p {
            color: #7a7265;
            margin: 0;
        }
        
        /* Reflection Item */
        .pm-reflection-item {
            background: #fff;
            border: 1px solid #e8e4dc;
            border-radius: 16px;
            padding: 24px;
            transition: box-shadow 0.3s;
        }
        
        .pm-reflection-item:hover {
            box-shadow: 0 4px 16px rgba(92, 83, 70, 0.08);
        }
        
        .pm-reflection-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }
        
        .pm-reflection-avatar img {
            border-radius: 50%;
            width: 44px;
            height: 44px;
            border: 2px solid #e8e4dc;
        }
        
        .pm-reflection-meta {
            display: flex;
            flex-direction: column;
        }
        
        .pm-reflection-author {
            color: #5c5346;
            font-weight: 600;
            text-decoration: none;
            font-size: 0.95rem;
        }
        
        .pm-reflection-author:hover {
            color: #8b7355;
        }
        
        .pm-reflection-time {
            color: #a09485;
            font-size: 0.8rem;
        }
        
        .pm-reflection-content {
            font-family: 'Crimson Text', Georgia, serif;
            font-size: 1.1rem;
            line-height: 1.7;
            color: #3d3730;
            margin-bottom: 16px;
        }
        
        .pm-reflection-footer {
            padding-top: 12px;
            border-top: 1px solid #f0ece4;
        }
        
        .pm-view-profile-link {
            color: #8b7355;
            font-size: 0.85rem;
            text-decoration: none;
            font-weight: 500;
        }
        
        .pm-view-profile-link:hover {
            color: #6a563a;
        }
        
        /* Mobile Responsive */
        @media (max-width: 600px) {
            .pm-soft-board-container {
                padding: 16px 12px;
            }
            
            .pm-board-guidelines {
                padding: 20px 16px;
            }
            
            .pm-guidelines-list {
                flex-direction: column;
                gap: 8px;
            }
            
            .pm-daily-prompt {
                padding: 16px;
            }
            
            .pm-prompt-text {
                font-size: 1.1rem;
            }
            
            .pm-post-composer {
                padding: 16px;
            }
            
            .pm-composer-header {
                flex-direction: column;
                gap: 12px;
            }
            
            .pm-composer-avatar {
                display: none;
            }
            
            .pm-post-composer-footer {
                flex-direction: column;
                gap: 12px;
                align-items: stretch;
            }
            
            .pm-reflection-hint {
                text-align: center;
            }
            
            .pm-post-button {
                width: 100%;
            }
            
            .pm-reflection-item {
                padding: 16px;
            }
        }
    </style>
    
    <script>
    jQuery(document).ready(function($) {
        const MIN_CHARS = 100;
        const MAX_CHARS = 500;
        let canSubmit = false;
        
        // Character counter and validation
        $('#pm-post-content').on('input', function() {
            const length = $(this).val().length;
            const $charMin = $('.pm-char-min');
            const $charCounter = $('.pm-char-counter');
            const $button = $('.pm-post-button');
            
            // Update counter
            $charCounter.text(length + '/' + MAX_CHARS);
            
            // Min chars indicator
            if (length >= MIN_CHARS) {
                $charMin.addClass('pm-satisfied').text('✓ Min. 100 znaków');
            } else {
                $charMin.removeClass('pm-satisfied').text('Min. 100 znaków (' + (MIN_CHARS - length) + ' więcej)');
            }
            
            // Warning when approaching max
            if (length > 450) {
                $charCounter.addClass('pm-warning');
            } else {
                $charCounter.removeClass('pm-warning');
            }
            
            // Enable/disable button
            canSubmit = (length >= MIN_CHARS && length <= MAX_CHARS);
            $button.prop('disabled', !canSubmit);
        });
        
        // Form submission with reflection pause
        $('#pm-post-form').on('submit', function(e) {
            e.preventDefault();
            
            if (!canSubmit) return;
            
            const $button = $('.pm-post-button');
            const $btnText = $('.pm-btn-text');
            const $btnLoading = $('.pm-btn-loading');
            const content = $('#pm-post-content').val();
            
            // Disable and show loading
            $button.prop('disabled', true);
            $btnText.hide();
            $btnLoading.show();
            
            // 3-second reflection pause
            setTimeout(function() {
                // AJAX submit
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'pm_create_soft_board_post',
                        nonce: '<?php echo wp_create_nonce('pm_soft_board_nonce'); ?>',
                        content: content
                    },
                    success: function(response) {
                        if (response.success) {
                            // Refresh to show new post
                            location.reload();
                        } else {
                            alert(response.data.message || 'Wystąpił błąd');
                            $btnText.show();
                            $btnLoading.hide();
                            $button.prop('disabled', false);
                        }
                    },
                    error: function() {
                        alert('Błąd połączenia. Spróbuj ponownie.');
                        $btnText.show();
                        $btnLoading.hide();
                        $button.prop('disabled', false);
                    }
                });
            }, 3000); // 3 second pause
        });
        
        // Initial button state
        $('.pm-post-button').prop('disabled', true);
    });
    </script>
    
    <?php
    return ob_get_clean();
}
add_shortcode('pm_activity_feed', 'pm_activity_feed_shortcode');

/**
 * AJAX Handler: Create Soft Board Post (with cooldown)
 */
function pm_create_soft_board_post() {
    check_ajax_referer('pm_soft_board_nonce', 'nonce');
    
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Musisz być zalogowany'));
    }
    
    $user_id = get_current_user_id();
    $content = sanitize_textarea_field($_POST['content']);
    
    // Check cooldown
    $cooldown = pm_check_posting_cooldown($user_id);
    if (!$cooldown['can_post']) {
        wp_send_json_error(array('message' => 'Możesz dodać refleksję za ' . $cooldown['time_display']));
    }
    
    // Check min length
    if (strlen($content) < 100) {
        wp_send_json_error(array('message' => 'Refleksja musi mieć minimum 100 znaków'));
    }
    
    // Check max length
    if (strlen($content) > 500) {
        wp_send_json_error(array('message' => 'Refleksja może mieć maksymalnie 500 znaków'));
    }
    
    // Create the activity
    if (function_exists('bp_activity_add')) {
        $activity_id = bp_activity_add(array(
            'user_id' => $user_id,
            'content' => $content,
            'component' => 'activity',
            'type' => 'activity_update'
        ));
        
        if ($activity_id) {
            // Save cooldown timestamp
            update_user_meta($user_id, 'pm_last_board_post_time', time());
            wp_send_json_success(array('activity_id' => $activity_id));
        } else {
            wp_send_json_error(array('message' => 'Nie udało się zapisać refleksji'));
        }
    } else {
        wp_send_json_error(array('message' => 'System nie jest dostępny'));
    }
}
add_action('wp_ajax_pm_create_soft_board_post', 'pm_create_soft_board_post');

/**
 * AJAX Handler: Create Activity Post
 */
function pm_create_activity_post() {
    check_ajax_referer('pm_activity_nonce', 'nonce');
    
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Musisz być zalogowany'));
    }
    
    $content = sanitize_textarea_field($_POST['content']);
    
    if (empty($content)) {
        wp_send_json_error(array('message' => 'Post nie może być pusty'));
    }
    
    if (function_exists('bp_activity_add')) {
        $activity_id = bp_activity_add(array(
            'user_id' => get_current_user_id(),
            'content' => $content,
            'component' => 'activity',
            'type' => 'activity_update'
        ));
        
        if ($activity_id) {
            wp_send_json_success(array('activity_id' => $activity_id));
        } else {
            wp_send_json_error(array('message' => 'Nie udało się utworzyć posta'));
        }
    } else {
        wp_send_json_error(array('message' => 'BuddyPress nie jest aktywny'));
    }
}
add_action('wp_ajax_pm_create_activity_post', 'pm_create_activity_post');

/**
 * AJAX Handler: Delete Activity Post
 */
function pm_delete_activity_post() {
    check_ajax_referer('pm_activity_nonce', 'nonce');
    
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Musisz być zalogowany'));
    }
    
    $activity_id = intval($_POST['activity_id']);
    
    if (function_exists('bp_activity_delete')) {
        $activity = bp_activity_get_specific(array('activity_ids' => $activity_id));
        
        if ($activity && isset($activity['activities'][0])) {
            $activity_obj = $activity['activities'][0];
            
            // Check if user owns this activity
            if ($activity_obj->user_id == get_current_user_id()) {
                if (bp_activity_delete(array('id' => $activity_id))) {
                    wp_send_json_success();
                }
            } else {
                wp_send_json_error(array('message' => 'Nie możesz usunąć tego posta'));
            }
        }
    }
    
    wp_send_json_error(array('message' => 'Nie udało się usunąć posta'));
}
add_action('wp_ajax_pm_delete_activity_post', 'pm_delete_activity_post');

/**
 * ==============================================================
 * CUSTOM ACTIVITY REST API ENDPOINT
 * ==============================================================
 * Since BuddyPress REST API might not be available,
 * we create our own /sk/v1/activity endpoint
 */

/**
 * Register custom activity REST routes
 */
function sk_register_activity_routes() {
    // Get activity feed
    register_rest_route('sk/v1', '/activity', array(
        'methods' => 'GET',
        'callback' => 'sk_get_activity_feed',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ));
    
    // Create activity post
    register_rest_route('sk/v1', '/activity', array(
        'methods' => 'POST',
        'callback' => 'sk_create_activity_post',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ));
    
    // Get activity comments
    register_rest_route('sk/v1', '/activity/(?P<id>\d+)/comments', array(
        'methods' => 'GET',
        'callback' => 'sk_get_activity_comments',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ));
    
    // Get single activity
    register_rest_route('sk/v1', '/activity/(?P<id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'sk_get_single_activity',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ));
    
    // Delete activity post
    register_rest_route('sk/v1', '/activity/(?P<id>\d+)', array(
        'methods' => 'DELETE',
        'callback' => 'sk_delete_activity_post',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ));
    
    // Favorite (like) activity
    register_rest_route('sk/v1', '/activity/(?P<id>\d+)/favorite', array(
        'methods' => 'POST',
        'callback' => 'sk_favorite_activity',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ));
    
    // Unfavorite activity
    register_rest_route('sk/v1', '/activity/(?P<id>\d+)/favorite', array(
        'methods' => 'DELETE',
        'callback' => 'sk_unfavorite_activity',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ));
}
add_action('rest_api_init', 'sk_register_activity_routes');

/**
 * Get comments for a specific activity
 */
function sk_get_activity_comments($request) {
    $id = intval($request->get_param('id'));

    global $wpdb;
    $table = $wpdb->prefix . 'bp_activity';

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table} WHERE type = 'activity_comment' AND item_id = %d ORDER BY date_recorded ASC",
        $id
    ));

    if (empty($rows)) {
        return rest_ensure_response(array());
    }

    $formatted = array();
    foreach ($rows as $comment) {
        $formatted[] = array(
            'id'          => intval($comment->id),
            'user_id'     => intval($comment->user_id),
            'content'     => $comment->content,
            'date'        => $comment->date_recorded,
            'type'        => $comment->type,
            'name'        => bp_core_get_user_displayname($comment->user_id),
            'user_avatar' => array(
                'full' => bp_core_fetch_avatar(array(
                    'item_id' => $comment->user_id,
                    'type'    => 'full',
                    'html'    => false
                ))
            ),
            'favorited'      => bp_activity_is_favorite($comment->id, get_current_user_id()),
            'favorite_count' => (int)(bp_activity_get_meta($comment->id, 'favorite_count') ?: 0)
        );
    }

    return rest_ensure_response($formatted);
}

/**
 * Get single activity
 */
function sk_get_single_activity($request) {
    if (!function_exists('bp_activity_get_specific')) {
        return new WP_Error('bp_disabled', 'BuddyPress not active', array('status' => 500));
    }
    
    $activity_id = $request['id'];
    $activities = bp_activity_get_specific(array(
        'activity_ids' => array($activity_id),
        'display_comments' => 'stream',
        'show_hidden' => true
    ));
    
    if (empty($activities['activities'])) {
        return new WP_Error('no_activity', 'Activity not found', array('status' => 404));
    }
    
    $activity = $activities['activities'][0];
    global $wpdb;
    
    // Extract media from activity meta
    $media = array();
    $media_id = bp_activity_get_meta($activity->id, 'sk_media_id');
    $media_url = bp_activity_get_meta($activity->id, 'sk_media_url');
    
    if ($media_id && $media_url) {
        $media[] = array(
            'id' => (int)$media_id,
            'url' => $media_url
        );
    }
    
    // Strip embedded image HTML from content
    $clean_content = preg_replace('/<div class="activity-media">.*?<\/div>/s', '', $activity->content);
    $clean_content = trim($clean_content);

    $formatted = array(
        'id' => (int)$activity->id,
        'user_id' => (int)$activity->user_id,
        'content' => $clean_content,
        'date' => $activity->date_recorded,
        'type' => $activity->type,
        'name' => bp_core_get_user_displayname($activity->user_id),
        'user_avatar' => array(
            'full' => bp_core_fetch_avatar(array(
                'item_id' => $activity->user_id,
                'type' => 'full',
                'html' => false
            ))
        ),
        'favorited' => bp_activity_is_favorite($activity->id, get_current_user_id()),
        'favorite_count' => (int)(bp_activity_get_meta($activity->id, 'favorite_count') ?: 0),
        'comment_count' => (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(id) FROM {$wpdb->prefix}bp_activity WHERE type = 'activity_comment' AND item_id = %d",
            $activity->id
        )),
        'media' => $media
    );
    
    return rest_ensure_response($formatted);
}

/**
 * Get activity feed
 */
function sk_get_activity_feed($request) {
    if (!function_exists('bp_activity_get')) {
        return new WP_Error('bp_disabled', 'BuddyPress not active', array('status' => 500));
    }
    
    $page = $request->get_param('page') ?: 1;
    $per_page = $request->get_param('per_page') ?: 20;
    $user_id = $request->get_param('user_id');
    $action = $request->get_param('type') ?: 'activity_update';
    
    $args = array(
        'display_comments' => false,
        'page' => $page,
        'per_page' => $per_page,
        'show_hidden' => true
    );

    $display_comments = $request->get_param('display_comments');
    if ($display_comments === 'stream') {
        $args['display_comments'] = 'stream';
        $action = 'activity_update,activity_comment';
    }

    if ($user_id) {
        $args['filter'] = array(
            'user_id' => $user_id,
            'action' => $action
        );
    } else {
        $args['action'] = $action;
        $hidden_users = sk_get_hidden_user_ids();
        if (!empty($hidden_users)) {
            $args['user_id'] = $hidden_users;
            $args['exclude_user_ids'] = true;
        }
    }

    $activities = bp_activity_get($args);
    
    if (empty($activities['activities'])) {
        return array();
    }
    
    $formatted = array();
    global $wpdb;
    foreach ($activities['activities'] as $activity) {
        // Extract media from activity meta
        $media = array();
        $media_id = bp_activity_get_meta($activity->id, 'sk_media_id');
        $media_url = bp_activity_get_meta($activity->id, 'sk_media_url');
        
        if ($media_id && $media_url) {
            $media[] = array(
                'id' => (int)$media_id,
                'url' => $media_url
            );
        }
        
        // Strip embedded image HTML from content for clean text display
        $clean_content = preg_replace('/<div class="activity-media">.*?<\/div>/s', '', $activity->content);
        $clean_content = trim($clean_content);
        
        // Fetch parent post details if this is a comment
        $parent_data = null;
        if ($activity->type === 'activity_comment' && !empty($activity->item_id)) {
            $parent_activities = bp_activity_get_specific(array(
                'activity_ids' => $activity->item_id,
                'display_comments' => false
            ));
            
            if (!empty($parent_activities['activities'][0])) {
                $p_act = $parent_activities['activities'][0];
                $p_clean_content = preg_replace('/<div class="activity-media">.*?<\/div>/s', '', $p_act->content);
                $p_clean_content = trim($p_clean_content);
                
                // Fetch parent post media
                $p_media = array();
                $p_media_id = bp_activity_get_meta($p_act->id, 'sk_media_id');
                $p_media_url = bp_activity_get_meta($p_act->id, 'sk_media_url');
                
                if ($p_media_id && $p_media_url) {
                    $p_media[] = array(
                        'id' => (int)$p_media_id,
                        'url' => $p_media_url
                    );
                }

                $parent_data = array(
                    'id' => $p_act->id,
                    'user_id' => $p_act->user_id,
                    'name' => bp_core_get_user_displayname($p_act->user_id),
                    'content' => $p_clean_content,
                    'media' => $p_media,
                    'user_avatar' => array(
                        'full' => bp_core_fetch_avatar(array(
                            'item_id' => $p_act->user_id,
                            'type' => 'full',
                            'html' => false
                        )),
                        'thumb' => bp_core_fetch_avatar(array(
                            'item_id' => $p_act->user_id,
                            'type' => 'thumb',
                            'html' => false
                        ))
                    )
                );
            }
        }
        
        $formatted[] = array(
            'id' => $activity->id,
            'user_id' => $activity->user_id,
            'content' => $clean_content,
            'date' => $activity->date_recorded,
            'type' => $activity->type,
            'name' => bp_core_get_user_displayname($activity->user_id),
            'user_avatar' => array(
                'full' => bp_core_fetch_avatar(array(
                    'item_id' => $activity->user_id,
                    'type' => 'full',
                    'html' => false
                ))
            ),
            'parent_content' => $parent_data,
            'favorited' => bp_activity_is_favorite($activity->id, get_current_user_id()),
            'favorite_count' => bp_activity_get_meta($activity->id, 'favorite_count') ?: 0,
            'comment_count' => (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(id) FROM {$wpdb->prefix}bp_activity WHERE type = 'activity_comment' AND item_id = %d",
                $activity->id
            )),
            'media' => $media
        );
    }
    
    return rest_ensure_response($formatted);
}

/**
 * Create activity post
 */
function sk_create_activity_post($request) {
    if (!function_exists('bp_activity_add')) {
        return new WP_Error('bp_disabled', 'BuddyPress not active', array('status' => 500));
    }
    
    $content = $request->get_param('content');
    
    $type = $request->get_param('type');
    $parent_id = $request->get_param('parent');
    
    if (empty($content) && empty($_FILES['media'])) {
        return new WP_Error('empty_content', 'Content cannot be empty', array('status' => 400));
    }
    
    $media_url = '';
    $media_id = 0;
    
    // Handle image upload
    if (!empty($_FILES['media']) && $_FILES['media']['error'] === UPLOAD_ERR_OK) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        
        $attachment_id = media_handle_upload('media', 0);
        
        if (!is_wp_error($attachment_id)) {
            $media_url = wp_get_attachment_url($attachment_id);
            $media_id = $attachment_id;
        } else {
            error_log('Activity media upload failed: ' . $attachment_id->get_error_message());
        }
    }
    
    // Build activity content with optional image
    $activity_content = sanitize_textarea_field($content);
    if ($media_url) {
        $activity_content .= "\n\n<div class=\"activity-media\"><img src=\"{$media_url}\" class=\"activity-image\" /></div>";
    }
    
    $activity_id = false;
    
    // Check if we are creating a comment vs a regular post
    if ($type === 'activity_comment' && !empty($parent_id)) {
        // Find the parent activity to get the activity_id to attach to
        $parent_activity = bp_activity_get_specific(array('activity_ids' => $parent_id));
        
        if (empty($parent_activity['activities'])) {
            return new WP_Error('parent_not_found', 'Parent activity not found', array('status' => 404));
        }
        
        $activity_id = bp_activity_new_comment(array(
            'activity_id' => $parent_id,
            'content'     => $activity_content,
            'user_id'     => get_current_user_id()
        ));
    } else {
        // Regular activity update
        $activity_id = bp_activity_add(array(
            'user_id' => get_current_user_id(),
            'content' => $activity_content,
            'component' => 'activity',
            'type' => 'activity_update'
        ));
    }
    
    if (!$activity_id) {
        return new WP_Error('create_failed', 'Failed to create activity/comment', array('status' => 500));
    }
    
    // Save media ID as activity meta for future reference
    if ($media_id) {
        bp_activity_update_meta($activity_id, 'sk_media_id', $media_id);
        bp_activity_update_meta($activity_id, 'sk_media_url', $media_url);
    }
    
    $activity = bp_activity_get_specific(array(
        'activity_ids' => $activity_id,
        'display_comments' => 'stream',
        'show_hidden' => true
    ));
    
    if (empty($activity['activities'])) {
        return new WP_Error('not_found', 'Activity not found after creation', array('status' => 404));
    }
    
    $act = $activity['activities'][0];
    global $wpdb;
    
    // Extract media from activity meta
    $media_id = bp_activity_get_meta($act->id, 'sk_media_id');
    $media_url = bp_activity_get_meta($act->id, 'sk_media_url');
    
    $response_data = array(
        'id' => $act->id,
        'user_id' => $act->user_id,
        'content' => $act->content,
        'date' => $act->date_recorded,
        'type' => $act->type,
        'name' => bp_core_get_user_displayname($act->user_id),
        'user_avatar' => array(
            'full' => bp_core_fetch_avatar(array(
                'item_id' => $act->user_id,
                'type' => 'full',
                'html' => false
            )),
            'thumb' => bp_core_fetch_avatar(array(
                'item_id' => $act->user_id,
                'type' => 'thumb',
                'html' => false
            ))
        ),
        'parent_content' => null, // Will be filled below if it's a comment
        'favorited' => bp_activity_is_favorite($act->id, get_current_user_id()),
        'favorite_count' => (int)(bp_activity_get_meta($act->id, 'favorite_count') ?: 0),
        'comment_count' => (int)$wpdb->get_var($wpdb->prepare(
            "SELECT count(*) FROM {$wpdb->prefix}bp_activity WHERE item_id = %d AND type = 'activity_comment'",
            $act->id
        )),
        'media' => array()
    );
    
    if ($act->type === 'activity_comment' && !empty($act->item_id)) {
        $parent_activities = bp_activity_get_specific(array(
            'activity_ids' => $act->item_id,
            'display_comments' => false
        ));
        
        if (!empty($parent_activities['activities'][0])) {
            $p_act = $parent_activities['activities'][0];
            $p_clean_content = preg_replace('/<div class="activity-media">.*?<\/div>/s', '', $p_act->content);
            $p_clean_content = trim($p_clean_content);
            
            $p_media = array();
            $p_media_id = bp_activity_get_meta($p_act->id, 'sk_media_id');
            $p_media_url = bp_activity_get_meta($p_act->id, 'sk_media_url');
            
            if ($p_media_id && $p_media_url) {
                $p_media[] = array(
                    'id' => (int)$p_media_id,
                    'url' => $p_media_url
                );
            }

            $response_data['parent_content'] = array(
                'id' => $p_act->id,
                'user_id' => $p_act->user_id,
                'name' => bp_core_get_user_displayname($p_act->user_id),
                'content' => $p_clean_content,
                'media' => $p_media,
                'user_avatar' => array(
                    'full' => bp_core_fetch_avatar(array(
                        'item_id' => $p_act->user_id,
                        'type' => 'full',
                        'html' => false
                    )),
                    'thumb' => bp_core_fetch_avatar(array(
                        'item_id' => $p_act->user_id,
                        'type' => 'thumb',
                        'html' => false
                    ))
                )
            );
        }
    }
    
    if ($media_url) {
        $response_data['media'][] = array(
            'id' => (int)$media_id,
            'url' => $media_url
        );
    }
    
    return rest_ensure_response($response_data);
}

/**
 * Delete activity post
 */
function sk_delete_activity_post($request) {
    if (!function_exists('bp_activity_delete')) {
        return new WP_Error('bp_disabled', 'BuddyPress not active', array('status' => 500));
    }
    
    $activity_id = $request->get_param('id');
    $activity = bp_activity_get_specific(array('activity_ids' => $activity_id));
    
    if (empty($activity['activities'])) {
        return new WP_Error('not_found', 'Activity not found', array('status' => 404));
    }
    
    $act = $activity['activities'][0];
    
    // Check ownership or administrator role
    if ($act->user_id != get_current_user_id() && !current_user_can('administrator')) {
        return new WP_Error('forbidden', 'You can only delete your own posts', array('status' => 403));
    }
    
    $deleted = bp_activity_delete(array('id' => $activity_id));
    
    if (!$deleted) {
        return new WP_Error('delete_failed', 'Failed to delete activity', array('status' => 500));
    }
    
    return rest_ensure_response(array('deleted' => true, 'previous' => array('id' => $activity_id)));
}

/**
 * Favorite (like) activity
 */
function sk_favorite_activity($request) {
    if (!function_exists('bp_activity_add_user_favorite')) {
        return new WP_Error('bp_disabled', 'BuddyPress not active', array('status' => 500));
    }
    
    $activity_id = $request->get_param('id');
    $user_id = get_current_user_id();
    
    $result = bp_activity_add_user_favorite($activity_id, $user_id);
    
    if (!$result) {
        return new WP_Error('favorite_failed', 'Failed to favorite activity', array('status' => 500));
    }
    
    // Update favorite count
    $count = bp_activity_get_meta($activity_id, 'favorite_count') ?: 0;
    bp_activity_update_meta($activity_id, 'favorite_count', $count + 1);
    
    return rest_ensure_response(array('favorited' => true));
}

/**
 * Unfavorite activity
 */
function sk_unfavorite_activity($request) {
    if (!function_exists('bp_activity_remove_user_favorite')) {
        return new WP_Error('bp_disabled', 'BuddyPress not active', array('status' => 500));
    }
    
    $activity_id = $request->get_param('id');
    $user_id = get_current_user_id();
    
    $result = bp_activity_remove_user_favorite($activity_id, $user_id);
    
    if (!$result) {
        return new WP_Error('unfavorite_failed', 'Failed to unfavorite activity', array('status' => 500));
    }
    
    // Update favorite count
    $count = bp_activity_get_meta($activity_id, 'favorite_count') ?: 0;
    bp_activity_update_meta($activity_id, 'favorite_count', max(0, $count - 1));
    
    return rest_ensure_response(array('favorited' => false));
}

/**
 * ==============================================================
 * AUTO-CREATE TABLICA (FEED) PAGE
 * ==============================================================
 * Automatically creates "Tablica" page with activity feed shortcode
 */
function pm_create_tablica_page() {
    // Check if page already exists
    $page = get_page_by_path('tablica');
    
    if ($page) {
        return; // Page already exists
    }
    
    // Create the page
    $page_data = array(
        'post_title'    => 'Tablica',
        'post_content'  => '[pm_activity_feed]',
        'post_status'   => 'publish',
        'post_type'     => 'page',
        'post_name'     => 'tablica',
        'post_author'   => 1,
        'comment_status' => 'closed',
        'ping_status'   => 'closed'
    );
    
    $page_id = wp_insert_post($page_data);
    
    if ($page_id && !is_wp_error($page_id)) {
        // Log success
        error_log('PM: Tablica page created successfully with ID: ' . $page_id);
    }
}
// Run on admin_init to create page on first load
add_action('admin_init', 'pm_create_tablica_page');

/**
 * ==============================================================
 * AUTO-ADD TABLICA TO MENU
 * ==============================================================
 * Automatically adds Tablica page to primary navigation menu
 */
function pm_add_tablica_to_menu() {
    // Get the Tablica page
    $tablica_page = get_page_by_path('tablica');
    
    if (!$tablica_page) {
        return; // Page doesn't exist yet
    }
    
    // Get the primary menu (adjust 'primary' if your theme uses different menu location)
    $locations = get_nav_menu_locations();
    $menu_id = isset($locations['primary']) ? $locations['primary'] : 0;
    
    if (!$menu_id) {
        // Try to find any menu
        $menus = wp_get_nav_menus();
        if (!empty($menus)) {
            $menu_id = $menus[0]->term_id;
        } else {
            return; // No menu exists
        }
    }
    
    // Check if Tablica is already in the menu
    $menu_items = wp_get_nav_menu_items($menu_id);
    foreach ($menu_items as $item) {
        if ($item->object_id == $tablica_page->ID) {
            return; // Already in menu
        }
    }
    
    // Add Tablica to menu
    wp_update_nav_menu_item($menu_id, 0, array(
        'menu-item-title' => '📰 Tablica',
        'menu-item-object' => 'page',
        'menu-item-object-id' => $tablica_page->ID,
        'menu-item-type' => 'post_type',
        'menu-item-status' => 'publish',
        'menu-item-position' => 4 // Position in menu (adjust as needed)
    ));
    
    error_log('PM: Tablica added to menu successfully');
}
// Run after page is created
add_action('admin_init', 'pm_add_tablica_to_menu', 20);

/**
 * Global filter for BuddyPress REST XProfile data to hide birth date if user enabled 'sk_hide_age'
 */
add_filter('bp_rest_xprofile_data_get_items_response', 'sk_filter_buddypress_rest_age', 10, 3);
add_filter('bp_rest_xprofile_fields_get_items_response', 'sk_filter_buddypress_rest_age', 10, 3);
add_filter('bp_rest_xprofile_groups_get_items_response', 'sk_filter_buddypress_rest_age', 10, 3);

function sk_filter_buddypress_rest_age($response, $handler, $request) {
    if (empty($response->data)) return $response;

    $user_id = $request->get_param('user_id');
    if (!$user_id) {
        $user_id = get_current_user_id();
    }
    
    // Own data is always visible to the user
    if ($user_id && $user_id == get_current_user_id()) {
        return $response;
    }

    $hide_age = get_user_meta($user_id, 'sk_hide_age', true) === '1';
    if (!$hide_age) return $response;

    // Filter fields/groups
    // Logic depends on whether response is a single group, array of groups, or fields list
    if (isset($response->data['fields'])) {
        // Single group or fields list
        $filtered_fields = [];
        foreach ($response->data['fields'] as $field) {
            $field_id = isset($field['id']) ? $field['id'] : (isset($field->id) ? $field->id : 0);
            if ($field_id != 107) {
                $filtered_fields[] = $field;
            }
        }
        $response->data['fields'] = $filtered_fields;
    } elseif (is_array($response->data)) {
        // Array of items (groups or fields)
        foreach ($response->data as $key => &$item) {
            if (isset($item['fields'])) {
                // It's a group
                $filtered_fields = [];
                foreach ($item['fields'] as $field) {
                    $field_id = isset($field['id']) ? $field['id'] : (isset($field->id) ? $field->id : 0);
                    if ($field_id != 107) {
                        $filtered_fields[] = $field;
                    }
                }
                $item['fields'] = $filtered_fields;
            } elseif (isset($item['id']) && $item['id'] == 107) {
                // It's a field list and this is field 107
                unset($response->data[$key]);
            }
        }
        if (!isset($response->data[0]['fields'])) {
            $response->data = array_values($response->data);
        }
    }
    
    return $response;
}

// ============================================================================
// WP UPLOAD HELPERS - ALLOW HEIC
// ============================================================================
add_filter('upload_mimes', 'sk_allow_heic_uploads');
function sk_allow_heic_uploads($mimes) {
    if (!isset($mimes['heic'])) $mimes['heic'] = 'image/heic';
    if (!isset($mimes['heif'])) $mimes['heif'] = 'image/heif';
    return $mimes;
}

/**
 * Presence Update Endpoint
 * Used to suppress notifications when user is active in a thread.
 */
function sk_update_presence_endpoint($request) {
    sk_debug_log("Presence: Endpoint hit. Params: " . print_r($request->get_params(), true));
    
    $user_id = get_current_user_id();
    if (!$user_id) {
        return new WP_Error('not_logged_in', 'User not logged in', ['status' => 401]);
    }

    $thread_id = intval($request->get_param('thread_id'));

    // 0 means "leaving chat" / "inactive"
    // >0 means "active in thread X"
    sk_debug_log("PRESENCE UPDATE: User $user_id update presence to $thread_id");
    
    update_user_meta($user_id, 'sk_active_thread_id', $thread_id);

    return rest_ensure_response([
        'success' => true,
        'active_thread_id' => $thread_id
    ]);
}

add_action('rest_api_init', function () {
    register_rest_route('sk/v1', '/presence/update', array(
        'methods' => 'POST',
        'callback' => 'sk_update_presence_endpoint',
        'permission_callback' => function () {
            return is_user_logged_in();
        }
    ));
});

/**
 * Hook to enforce thread hiding for sender immediately after creation
 * valid for Super Message and Allow Chat
 */
add_action('better_messages_thread_created', 'sk_force_hide_thread_for_sender', 20, 2);

function sk_force_hide_thread_for_sender($thread_id, $args) {
    sk_debug_log("HOOK TRIGGERED: better_messages_thread_created for thread $thread_id");
    // Check if this is a Super Message or Allow Chat
    $subject = isset($args['subject']) ? $args['subject'] : '';
    $content = isset($args['content']) ? $args['content'] : '';

    $is_super = ($subject === 'Super Wiadomość');
    $is_allow = ($subject === 'Zgoda na rozmowę');
    $is_allow_old = ($subject === 'Prawdziwa Miłość' && strpos($content, 'rozmawiać') !== false);

    if ($is_super || $is_allow || $is_allow_old) {
        $sender_id = isset($args['sender_id']) ? $args['sender_id'] : get_current_user_id();
        
        if ($sender_id) {
            // Force DB update
            global $wpdb;
            $bm_table = $wpdb->prefix . 'bm_message_recipients';
            
            $wpdb->update(
                $bm_table,
                ['is_deleted' => 1],
                ['thread_id' => $thread_id, 'user_id' => $sender_id],
                ['%d'],
                ['%d', '%d']
            );
            
            error_log("sk_force_hide_thread_for_sender: Forced hide thread $thread_id for sender $sender_id (Subject: $subject)");
        }
    }
}

/**
 * Mobile App - Fetch Message Threads
 */
function sk_get_all_message_threads($request) {
    if (!function_exists('buddypress') || !bp_is_active('messages')) {
        return new WP_Error('no_buddypress', 'BuddyPress Messages not active.', array('status' => 500));
    }

    $current_user_id = get_current_user_id();
    $threads = array();
    $messages = array();
    $users_map = array();

    // Fetch inbox threads
    if (bp_has_message_threads(array('user_id' => $current_user_id, 'max' => 50))) {
        while (bp_message_threads()) {
            bp_message_thread();
            
            $thread_id = bp_get_message_thread_id();
            global $messages_template;
            $thread_obj = $messages_template->thread;
            
            // 1. Process Participants & Users Map
            $participants = array();
            if (isset($thread_obj->recipients)) {
                foreach ($thread_obj->recipients as $recipient) {
                    $participants[] = array(
                        'user_id' => $recipient->user_id,
                        'name'    => bp_core_get_user_displayname($recipient->user_id)
                    );
                    
                    // Add to users map for avatars (frontend expects this)
                    if (!isset($users_map[$recipient->user_id])) {
                        $users_map[$recipient->user_id] = array(
                            'user_id' => $recipient->user_id,
                            'name'    => bp_core_get_user_displayname($recipient->user_id),
                            'avatar'  => bp_core_fetch_avatar(array('item_id' => $recipient->user_id, 'type' => 'thumb', 'html' => false))
                        );
                    }
                }
            }

            // 2. Build Thread Object
            $threads[] = array(
                'thread_id'    => $thread_id,
                'subject'      => bp_get_message_thread_subject(),
                'lastMessage'  => bp_get_message_thread_excerpt(),
                'unread'       => bp_get_message_thread_unread_count(),
                'lastTime'     => strtotime(bp_get_message_thread_last_post_date()) * 1000,
                'participants' => $participants
            );

            // 3. Build Last Message Object for the `messages` array
            $last_message_id = $thread_obj->last_message_id;
            if ($last_message_id) {
                // We fake the full message structure using excerpt for the listing view
                $messages[] = array(
                    'id'         => $last_message_id,
                    'thread_id'  => $thread_id,
                    'message'    => bp_get_message_thread_excerpt(),
                    'sender_id'  => bp_get_message_thread_last_post_date() ? $thread_obj->last_sender_id : $current_user_id, // approximation if we don't load the full message
                    'created_at' => strtotime(bp_get_message_thread_last_post_date()) * 1000
                );
            }
        }
    }

    // Convert users map to array
    $users = array_values($users_map);

    return array(
        'threads'  => $threads,
        'messages' => $messages,
        'users'    => $users
    );
}

/**
 * Dodaje szybki link "Ukryj/Pokaż" do listy użytkowników w panelu admina
 */
add_filter('user_row_actions', function($actions, $user_object) {
    if (current_user_can('edit_users')) {
        $is_hidden = get_user_meta($user_object->ID, 'sk_is_hidden', true) === '1';
        $action_url = wp_nonce_url(admin_url("users.php?action=sk_toggle_hide_user&user_id={$user_object->ID}"), 'sk_toggle_hide_user');
        
        if ($is_hidden) {
            $actions['sk_hide'] = "<a href='" . esc_url($action_url) . "' style='color: #d63638; font-weight: bold;'>Pokaż profil (Teraz: Ukryty)</a>";
        } else {
            $actions['sk_hide'] = "<a href='" . esc_url($action_url) . "'>Ukryj profil (Shadow Ban)</a>";
        }
    }
    return $actions;
}, 10, 2);

add_action('admin_init', function() {
    if (isset($_GET['action']) && $_GET['action'] === 'sk_toggle_hide_user' && isset($_GET['user_id'])) {
        if (!current_user_can('edit_users') || !check_admin_referer('sk_toggle_hide_user')) {
            wp_die('Brak uprawnień.');
        }
        $user_id = intval($_GET['user_id']);
        $is_hidden = get_user_meta($user_id, 'sk_is_hidden', true) === '1';
        
        if ($is_hidden) {
            delete_user_meta($user_id, 'sk_is_hidden');
        } else {
            update_user_meta($user_id, 'sk_is_hidden', '1');
        }
        
        wp_safe_redirect(wp_get_referer() ? wp_get_referer() : admin_url('users.php'));
        exit;
    }
});

// Automatyczne zapisywanie czasu ostatniego logowania i aktywności użytkownika
add_action('init', function() {
    $user_id = get_current_user_id();
    if ($user_id > 0) {
        $now = time();
        $last_update = (int) get_user_meta($user_id, 'sk_last_activity_update_time', true);
        
        // Aktualizacja maksymalnie raz na 5 minut (300 sekund)
        if ($now - $last_update > 300) {
            $date_str = date("Y-m-d H:i:s");
            update_user_meta($user_id, 'last_activity', $date_str);
            update_user_meta($user_id, 'sk_last_activity_update_time', $now);
            update_user_meta($user_id, 'last_login', $now); // standard Unix timestamp dla logowania
            
            // Wykrywanie platformy z User-Agent
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $platform = 'Nieznana';
            if (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false || stripos($ua, 'Darwin') !== false || stripos($ua, 'CFNetwork') !== false) {
                $platform = 'iOS';
            } elseif (stripos($ua, 'Android') !== false || stripos($ua, 'okhttp') !== false) {
                $platform = 'Android';
            } else {
                if (stripos($ua, 'Windows') !== false || stripos($ua, 'Macintosh') !== false || stripos($ua, 'Linux') !== false) {
                    $platform = 'Web';
                }
            }
            update_user_meta($user_id, 'sk_device_platform', $platform);
            
            if (function_exists('bp_update_user_last_activity')) {
                bp_update_user_last_activity($user_id, $date_str);
            }
        }
    }
});

// Wzbogacanie odpowiedzi Better Messages unread_count oraz last_read dla wyświetlania statusów w aplikacji mobilnej
add_filter('rest_post_dispatch', 'sk_enrich_better_messages_threads_response', 10, 3);
function sk_enrich_better_messages_threads_response($response, $server, $request) {
    $route = $request->get_route();
    
    // Sprawdzenie czy to ścieżka do wątków Better Messages
    if (strpos($route, '/better-messages/v1/threads') !== false || 
        strpos($route, '/better-messages/v1/thread/') !== false ||
        strpos($route, '/better-messages/v1/getPrivateThread') !== false) {
        
        $data = $response->get_data();
        if (empty($data)) {
            return $response;
        }

        global $wpdb;
        $bm_table = $wpdb->prefix . 'bm_message_recipients';
        $bp_table = $wpdb->prefix . 'bp_messages_recipients';
        $has_bm = $wpdb->get_var("SHOW TABLES LIKE '$bm_table'") == $bm_table;
        $has_bp = $wpdb->get_var("SHOW TABLES LIKE '$bp_table'") == $bp_table;
        
        $bm_col = '';
        $has_last_read = '';
        if ($has_bm) {
            $bm_col = $wpdb->get_var("SHOW COLUMNS FROM {$bm_table} LIKE 'unread_count'") ? 'unread_count' : ($wpdb->get_var("SHOW COLUMNS FROM {$bm_table} LIKE 'unread'") ? 'unread' : '');
            $has_last_read = $wpdb->get_var("SHOW COLUMNS FROM {$bm_table} LIKE 'last_read'") ? 'last_read' : '';
        }

        // Pomocnik do wzbogacania tablicy uczestników
        $enrich_participants = function(&$participants, $thread_id) use ($wpdb, $has_bm, $has_bp, $bm_table, $bp_table, $bm_col, $has_last_read) {
            if (!is_array($participants)) return;
            foreach ($participants as &$participant) {
                $user_id = 0;
                if (is_array($participant)) {
                    $user_id = isset($participant['user_id']) ? intval($participant['user_id']) : (isset($participant['id']) ? intval($participant['id']) : 0);
                } elseif (is_object($participant)) {
                    $user_id = isset($participant->user_id) ? intval($participant->user_id) : (isset($participant->id) ? intval($participant->id) : 0);
                } elseif (is_numeric($participant)) {
                    $user_id = intval($participant);
                }

                if (!$user_id || !$thread_id) continue;

                $unread_count = 0;
                $last_read = 0;

                // 1. Better Messages
                if ($has_bm) {
                    if ($bm_col) {
                        $unread_count = (int)$wpdb->get_var($wpdb->prepare(
                            "SELECT {$bm_col} FROM {$bm_table} WHERE thread_id = %d AND user_id = %d",
                            $thread_id, $user_id
                        ));
                    }
                    if ($has_last_read) {
                        $last_read = (int)$wpdb->get_var($wpdb->prepare(
                            "SELECT last_read FROM {$bm_table} WHERE thread_id = %d AND user_id = %d",
                            $thread_id, $user_id
                        ));
                    }
                }

                // 2. BuddyPress Fallback
                if (!$unread_count && $has_bp) {
                    $unread_count = (int)$wpdb->get_var($wpdb->prepare(
                        "SELECT unread_count FROM {$bp_table} WHERE thread_id = %d AND user_id = %d",
                        $thread_id, $user_id
                    ));
                }

                // Zapisujemy wartości z powrotem do uczestnika
                if (is_array($participant)) {
                    $participant['unread'] = $unread_count;
                    $participant['unread_count'] = $unread_count;
                    if ($last_read) {
                        $participant['last_read'] = $last_read;
                        $participant['last_read_message_id'] = $last_read;
                    }
                } elseif (is_object($participant)) {
                    $participant->unread = $unread_count;
                    $participant->unread_count = $unread_count;
                    if ($last_read) {
                        $participant->last_read = $last_read;
                        $participant->last_read_message_id = $last_read;
                    }
                }
            }
        };

        // Wątki (lista): format ['threads' => [...]]
        if (isset($data['threads']) && is_array($data['threads'])) {
            foreach ($data['threads'] as &$thread) {
                $thread_id = isset($thread['thread_id']) ? intval($thread['thread_id']) : (isset($thread['id']) ? intval($thread['id']) : 0);
                if (isset($thread['participants']) && is_array($thread['participants'])) {
                    $enrich_participants($thread['participants'], $thread_id);
                }
                if (isset($thread['recipients']) && is_array($thread['recipients'])) {
                    $enrich_participants($thread['recipients'], $thread_id);
                }
            }
        }
        // Pojedynczy wątek
        else {
            $thread_id = 0;
            if (is_array($data)) {
                $thread_id = isset($data['thread_id']) ? intval($data['thread_id']) : (isset($data['id']) ? intval($data['id']) : 0);
            } elseif (is_object($data)) {
                $thread_id = isset($data->thread_id) ? intval($data->thread_id) : (isset($data->id) ? intval($data->id) : 0);
            }

            if ($thread_id > 0) {
                if (is_array($data)) {
                    if (isset($data['participants']) && is_array($data['participants'])) {
                        $enrich_participants($data['participants'], $thread_id);
                    }
                    if (isset($data['recipients']) && is_array($data['recipients'])) {
                        $enrich_participants($data['recipients'], $thread_id);
                    }
                } elseif (is_object($data)) {
                    if (isset($data->participants) && is_array($data->participants)) {
                        $enrich_participants($data->participants, $thread_id);
                    }
                    if (isset($data->recipients) && is_array($data->recipients)) {
                        $enrich_participants($data->recipients, $thread_id);
                    }
                }
            }
        }

        $response->set_data($data);
    }
    
    return $response;
}

// ========================================
// Admin Dashboard REST API Endpoint
// ========================================
add_action('rest_api_init', function () {
    register_rest_route('sk/v1', '/admin/dashboard', [
        'methods' => 'GET',
        'callback' => 'sk_admin_dashboard_endpoint',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ]);
});

function sk_admin_dashboard_endpoint() {
    global $wpdb;
    
    // 1. Users List (ordered by registration, limit 100)
    $users_query = $wpdb->get_results("SELECT ID, user_login, display_name, user_registered FROM {$wpdb->users} ORDER BY user_registered DESC LIMIT 100");
    $users = [];
    foreach ($users_query as $u) {
        $last_login = get_user_meta($u->ID, 'last_login', true);
        $last_activity = get_user_meta($u->ID, 'last_activity', true);
        
        // Format last activity
        $activity_status = 'Nigdy';
        if ($last_activity) {
            $activity_status = sk_format_activity_status($last_activity, true);
        }
        
        $platform = get_user_meta($u->ID, 'sk_device_platform', true) ?: 'Nieznana';
        
        $users[] = [
            'id' => $u->ID,
            'username' => $u->user_login,
            'name' => $u->display_name,
            'registered' => $u->user_registered,
            'last_login' => $last_login ? date("Y-m-d H:i:s", $last_login) : 'Nigdy',
            'last_activity' => $activity_status,
            'platform' => $platform
        ];
    }
    
    // 2. Matches
    $likes_query = $wpdb->get_results("SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'sk_user_likes'");
    $likes = [];
    foreach ($likes_query as $row) {
        $likes_array = maybe_unserialize($row->meta_value);
        if (is_array($likes_array)) {
            $likes[$row->user_id] = array_map('intval', $likes_array);
        }
    }
    
    $matches = [];
    foreach ($likes as $user_id => $user_likes) {
        foreach ($user_likes as $liked_id) {
            if ($liked_id > $user_id) {
                if (isset($likes[$liked_id]) && in_array($user_id, $likes[$liked_id])) {
                    $user1 = get_userdata($user_id);
                    $user2 = get_userdata($liked_id);
                    if ($user1 && $user2) {
                        $matches[] = [
                            'user1_id' => $user_id,
                            'user1_name' => $user1->display_name,
                            'user2_id' => $liked_id,
                            'user2_name' => $user2->display_name,
                            'time' => date("Y-m-d H:i:s")
                        ];
                    }
                }
            }
        }
    }
    
    // 3. Recent Messages (Better Messages)
    $messages_query = $wpdb->get_results("
        SELECT m.id, m.thread_id, m.sender_id, m.message, m.date_sent 
        FROM {$wpdb->prefix}bm_message_messages m
        ORDER BY m.date_sent DESC LIMIT 50
    ");
    
    $messages = [];
    foreach ($messages_query as $msg) {
        $sender = get_userdata($msg->sender_id);
        $sender_name = $sender ? $sender->display_name : 'Unknown';
        
        $recipients_query = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}bm_message_recipients WHERE thread_id = %d AND user_id != %d",
            $msg->thread_id, $msg->sender_id
        ));
        
        $recipients_names = [];
        foreach ($recipients_query as $rid) {
            $rec = get_userdata($rid);
            if ($rec) {
                $recipients_names[] = $rec->display_name;
            }
        }
        
        $messages[] = [
            'id' => $msg->id,
            'thread_id' => $msg->thread_id,
            'sender_id' => $msg->sender_id,
            'sender_name' => $sender_name,
            'recipient_name' => !empty($recipients_names) ? implode(', ', $recipients_names) : 'Brak',
            'message' => $msg->message,
            'date_sent' => $msg->date_sent
        ];
    }
    
    return rest_ensure_response([
        'users' => $users,
        'matches' => $matches,
        'messages' => $messages
    ]);
}

