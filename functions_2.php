<?php
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

            fetch('<?php echo admin_url('admin-ajax.php?action=load_users_grid'); ?>', { credentials: 'same-origin' })
                .then(response => response.json())
                .then(data => {
                    if (!data.success || !Array.isArray(data.data) || data.data.length === 0) {
                        container.innerHTML = '<p class="no-users-message">Brak użytkowników spełniających kryteria</p>';
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
            font-size: 0.875rem;
            color: #f5f5f5;
            margin-bottom: 10px;
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
        $fields_to_compare = ['Polityka', 'Dieta', 'Religia', 'Styl pracy'];
        $matched_fields = 0;
        $total_fields = 0;
        foreach ($fields_to_compare as $field) {
            $val1 = trim(bp_get_profile_field_data(['field' => $field, 'user_id' => $user1_id]));
            $val2 = trim(bp_get_profile_field_data(['field' => $field, 'user_id' => $user2_id]));
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

    $exclude_ids = array_unique(array_merge([1, $current_user_id], $blocked_users_list));

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
        $location = bp_get_profile_field_data(['field' => 'Lokalizacja', 'user_id' => $user_id]);
        $bio = bp_get_profile_field_data(['field' => 343, 'user_id' => $user_id]);
        $last_active_time = bp_get_user_last_activity($user_id);
        $last_active_formatted = $last_active_time ? bp_core_time_since($last_active_time) : 'Nigdy';
        $last_active_timestamp = $last_active_time ? strtotime($last_active_time) : 0;

        // --- POPRAWKA: Pobieramy datę urodzenia i na jej podstawie obliczamy obie wartości ---
        $birth_date = bp_get_profile_field_data(['field' => 107, 'user_id' => $user_id]);
        $numerology_number = sk_calculate_life_path_number($birth_date);
        $zodiac_sign = get_zodiac_sign($birth_date); // Używamy tej samej daty urodzenia

        $results[] = [
            'id' => $user_id,
            'name' => $user->display_name,
            'match' => intval($match_percentage),
            'profile_url' => bp_members_get_user_url($user_id),
            'avatar' => get_avatar_url($user_id, ['size' => 200]),
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
    if (!current_user_can('manage_options') && !is_admin()) {
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
            <button id="toggle-numerology-btn" class="widget-button purple"
                style="width:100%; box-sizing: border-box;">Pokaż Numerologię</button>
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
                            <a href="<?php echo wp_nonce_url(bp_loggedin_user_domain() . bp_get_messages_slug() . '/compose/?r=' . $match['login']); ?>"
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

            $('#toggle-numerology-btn').on('click', function (e) {
                e.preventDefault();
                const btn = $(this);
                const body = $('body');

                body.toggleClass('show-numerology');

                if (body.hasClass('show-numerology')) {
                    btn.text('Ukryj Numerologię');
                } else {
                    btn.text('Pokaż Numerologię');
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

    wp_send_json_success(['status' => $new_status]);
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
    ?>
    <script id="global-like-button-handler">
        jQuery(document).ready(function ($) {
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
            echo bp_member_avatar('type=full');
            echo $activity_indicator_html;
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

// === SHORTCODE REJESTRACJI KROK 1 ===
function rejestracja_krok1_shortcode()
{
    ob_start();

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
                // SUKCES!

                $output = '<div class="custom-reg-container success-mode">';
                $output .= '<h2 style="color:green;">Prawie gotowe! 🚀</h2>';
                $output .= '<p>Na Twój adres <strong>' . esc_html($email) . '</strong> wysłaliśmy link aktywacyjny.</p>';
                $output .= '<p>Kliknij w niego, aby dokończyć zakładanie konta.</p>';
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
    $output .= '<form method="post" class="reg-form">';

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

    // Zgody (Opcjonalnie)
    $output .= '<div class="form-check">';
    $output .= '<input type="checkbox" required> <small>Akceptuję regulamin serwisu</small>';
    $output .= '</div>';

    // Przycisk
    $output .= wp_nonce_field('new_user_register', '_wpnonce', true, false);
    $output .= '<button type="submit" name="submit_registration" class="btn-submit">Załóż darmowe konto</button>';

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

        // 4. Zapis Avatara (bez zmian)
        if (!empty($_FILES['avatar']['name'])) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $uploadedfile = $_FILES['avatar'];
            $upload_overrides = ['test_form' => false];
            $movefile = wp_handle_upload($uploadedfile, $upload_overrides);

            if ($movefile && !isset($movefile['error'])) {
                $filename = $movefile['file'];
                $filetype = wp_check_filetype(basename($filename), null);
                $attachment = [
                    'guid' => $movefile['url'],
                    'post_mime_type' => $filetype['type'],
                    'post_title' => preg_replace('/\.[^.]+$/', '', basename($filename)),
                    'post_content' => '',
                    'post_status' => 'inherit'
                ];
                $attach_id = wp_insert_attachment($attachment, $filename, 0);
                $attach_data = wp_generate_attachment_metadata($attach_id, $filename);
                wp_update_attachment_metadata($attach_id, $attach_data);
                update_user_meta($user_id, 'user_avatar_id', $attach_id);
            }
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
                <select name="kogo_szukam" required style="width:100%; padding:8px;">
                    <option value="">-- Wybierz --</option>
                    <!-- WAŻNE: Wartości "value" muszą być IDENTYCZNE jak opcje w BuddyPress -->
                    <option value="Kobiety">Kobiety</option>
                    <option value="Mężczyzny">Mężczyzny</option>
                    <option value="Wszystkich">Wszystkich</option>
                </select>
            </div>

            <!-- 3. AVATAR -->
            <div style="margin-bottom:15px;">
                <label><strong>Zdjęcie profilowe (wymagane)</strong></label><br>
                <div id="avatar-preview-container" style="margin: 10px 0; display: none;">
                    <img id="avatar-preview" src="" alt="Podgląd zdjęcia" style="max-width: 200px; max-height: 200px; border-radius: 50%; object-fit: cover; border: 3px solid #e91e63; box-shadow: 0 4px 15px rgba(233,30,99,0.3);">
                </div>
                <label for="avatar-input" style="display: inline-block; padding: 12px 24px; background: linear-gradient(135deg, #e91e63 0%, #9c27b0 100%); color: white; border-radius: 25px; cursor: pointer; font-weight: 500; transition: transform 0.2s, box-shadow 0.2s;">
                    <span id="avatar-btn-text">📷 Wybierz zdjęcie</span>
                </label>
                <input type="file" name="avatar" id="avatar-input" accept="image/*" required style="display: none;">
                <p id="avatar-filename" style="margin-top: 8px; color: #666; font-size: 13px;"></p>
            </div>
            
            <script>
            document.getElementById('avatar-input').addEventListener('change', function(e) {
                var file = e.target.files[0];
                if (file) {
                    var reader = new FileReader();
                    reader.onload = function(event) {
                        var preview = document.getElementById('avatar-preview');
                        var container = document.getElementById('avatar-preview-container');
                        var btnText = document.getElementById('avatar-btn-text');
                        var filename = document.getElementById('avatar-filename');
                        
                        preview.src = event.target.result;
                        container.style.display = 'block';
                        btnText.textContent = '📷 Zmień zdjęcie';
                        filename.textContent = '✓ ' + file.name;
                    }
                    reader.readAsDataURL(file);
                }
            });
            </script>

            <!-- 4. RELIGIA -->
            <div style="margin-bottom:15px;">
                <label><strong>Podejście do wiary</strong></label>
                <select name="religia" required style="width:100%; padding:8px;">
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
                <select name="polityka" required style="width:100%; padding:8px;">
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
                <select name="praca" required style="width:100%; padding:8px;">
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
                <select name="dieta" required style="width:100%; padding:8px;">
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

            return "<img src='{$img_src[0]}' alt='{$alt}' class='{$class}' width='{$width}' height='{$height}' />";
        }
    }

    return $html;
}

// 3. Podmiana samego URL-a (dla motywów, które pobierają tylko link)
add_filter('bp_core_fetch_avatar_url', 'my_force_bp_avatar_url', 10, 2);
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
            'osobowosc' => 'Osobowość'
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
                    <label>Osobowość</label>
                    <div class="radio-group">
                        <label><input type="radio" name="osobowosc" value="Introwertyk"> Introwertyk</label>
                        <label><input type="radio" name="osobowosc" value="Ekstrawertyk"> Ekstrawertyk</label>
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
    // Jeśli nie ma zalogowanego usera, przerwij
    if (!is_user_logged_in()) {
        return $errors;
    }

    $sender_id = bp_loggedin_user_id();

    // Sprawdzamy każdego odbiorcę
    foreach ($recipients as $recipient) {
        $recipient_id = (is_object($recipient)) ? $recipient->user_id : $recipient;

        // Pomiń wysyłanie do samego siebie
        if ($sender_id == $recipient_id) {
            continue;
        }

        // --- SPRAWDZANIE ZNAJOMOŚCI (MATCHA) ---
        $is_match = sk_is_mutual_match($sender_id, $recipient_id);

        // Jeśli NIE MA matcha, dodajemy błąd krytyczny do obiektu $errors
        if (!$is_match) {
            $errors->add(
                'no_match_error',
                __('Nie możesz wysłać wiadomości. Użytkownik nie jest Twoim parą (brak Matcha).', 'buddypress')
            );
        }
    }

    return $errors;
}
add_filter('bp_messages_validate_send', 'pm_strict_match_validation', 10, 2);

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
                            href="<?php bp_member_permalink(); ?>"><?php bp_member_avatar('type=full&width=150&height=150'); ?></a>
                    </div>
                    <div class="match-info">
                        <h3><a href="<?php bp_member_permalink(); ?>"><?php bp_member_name(); ?></a></h3>
                        <div class="match-actions">
                            <a href="<?php bp_member_permalink(); ?>" class="button view-profile">Zobacz Profil</a>
                            <?php if (is_user_logged_in() && bp_loggedin_user_id() != bp_get_member_user_id()) {
                                $message_link = wp_nonce_url(bp_loggedin_user_domain() . bp_get_messages_slug() . '/compose/?r=' . bp_get_member_user_login());
                                echo '<a href="' . $message_link . '" class="button message-button-match">✉️ Napisz</a>';
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
        
        <a href="<?php echo $user_domain . 'matche/'; ?>" class="pm-nav-item <?php echo $is_matches ? 'active' : ''; ?>">
            <svg class="pm-nav-icon" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
            </svg>
            <span class="pm-nav-label">Pary</span>
        </a>
        
        <a href="<?php echo $user_domain . $messages_slug . '/'; ?>" class="pm-nav-item <?php echo $is_messages ? 'active' : ''; ?>">
            <svg class="pm-nav-icon" viewBox="0 0 24 24" fill="currentColor">
                <path d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 12H6v-2h12v2zm0-3H6V9h12v2zm0-3H6V6h12v2z"/>
            </svg>
            <span class="pm-nav-label">Wiadomości</span>
            <?php if ($unread_count > 0): ?>
                <span class="pm-nav-badge" id="pm-nav-badge-messages"><?php echo $unread_count > 9 ? '9+' : $unread_count; ?></span>
            <?php endif; ?>
        </a>
        
        <a href="<?php echo $user_domain; ?>" class="pm-nav-item <?php echo $is_profile ? 'active' : ''; ?>">
            <svg class="pm-nav-icon" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
            </svg>
            <span class="pm-nav-label">Profil</span>
        </a>
    </nav>
    
    <style>
    /* Mobile Bottom Navigation - only visible on mobile */
    .pm-bottom-nav {
        display: none;
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
    
    @media (max-width: 767px) {
        .pm-bottom-nav {
            display: flex;
            justify-content: space-around;
            align-items: center;
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
    
    .pm-nav-badge {
        position: absolute;
        top: 2px;
        right: 8px;
        background: #ff4757;
        color: white;
        font-size: 10px;
        font-weight: bold;
        min-width: 16px;
        height: 16px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0 4px;
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
        // No custom avatar, return empty
        $response->data['hires_avatar'] = array(
            'full' => '',
            'large' => '',
            'attachment_id' => 0
        );
    }
    
    return $response;
}

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
    
    $results = [];
    
    foreach ($match_ids as $user_id) {
        $user_data = get_userdata($user_id);
        if (!$user_data) {
            continue;
        }
        
        // Get high-res avatar from media library
        $attach_id = get_user_meta($user_id, 'user_avatar_id', true);
        $avatar_url = '';
        if ($attach_id) {
            $avatar_url = wp_get_attachment_image_url($attach_id, 'large') ?: 
                         wp_get_attachment_image_url($attach_id, 'full');
        }
        // Fallback to BuddyPress avatar
        if (!$avatar_url) {
            $avatar_url = bp_core_get_avatar(array(
                'item_id' => $user_id,
                'type' => 'full',
                'html' => false
            ));
        }
        
        // Get last active time
        $last_active = bp_get_user_last_activity($user_id);
        
        $results[] = [
            'id' => $user_id,
            'name' => $user_data->display_name,
            'mention_name' => $user_data->user_nicename,
            'avatar_urls' => [
                'full' => $avatar_url
            ],
            'hires_avatar' => [
                'large' => $attach_id ? wp_get_attachment_image_url($attach_id, 'large') : '',
                'full' => $attach_id ? wp_get_attachment_image_url($attach_id, 'full') : '',
            ],
            'last_activity' => $last_active,
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
    
    $results = [];
    
    foreach ($my_likes as $user_id) {
        $user_data = get_userdata($user_id);
        if (!$user_data) {
            continue;
        }
        
        // Get high-res avatar from media library
        $attach_id = get_user_meta($user_id, 'user_avatar_id', true);
        $avatar_url = '';
        if ($attach_id) {
            $avatar_url = wp_get_attachment_image_url($attach_id, 'large') ?: 
                         wp_get_attachment_image_url($attach_id, 'full');
        }
        // Fallback to BuddyPress avatar
        if (!$avatar_url) {
            $avatar_url = bp_core_fetch_avatar(array(
                'item_id' => $user_id,
                'type' => 'full',
                'html' => false
            ));
        }
        
        // Get last active time
        $last_active = bp_get_user_last_activity($user_id);
        
        $results[] = [
            'id' => $user_id,
            'name' => $user_data->display_name,
            'mention_name' => $user_data->user_nicename,
            'avatar_urls' => [
                'full' => $avatar_url
            ],
            'hires_avatar' => [
                'large' => $attach_id ? wp_get_attachment_image_url($attach_id, 'large') : '',
                'full' => $attach_id ? wp_get_attachment_image_url($attach_id, 'full') : '',
            ],
            'last_activity' => $last_active,
        ];
    }
    
    return rest_ensure_response($results);
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
    
    $results = [];
    
    foreach ($liked_me as $user_id) {
        $user_data = get_userdata($user_id);
        if (!$user_data) {
            continue;
        }
        
        // Get high-res avatar from media library
        $attach_id = get_user_meta($user_id, 'user_avatar_id', true);
        $avatar_url = '';
        if ($attach_id) {
            $avatar_url = wp_get_attachment_image_url($attach_id, 'large') ?: 
                         wp_get_attachment_image_url($attach_id, 'full');
        }
        // Fallback to BuddyPress avatar
        if (!$avatar_url) {
            $avatar_url = bp_core_fetch_avatar(array(
                'item_id' => $user_id,
                'type' => 'full',
                'html' => false
            ));
        }
        
        // Get last active time
        $last_active = bp_get_user_last_activity($user_id);
        
        $results[] = [
            'id' => $user_id,
            'name' => $user_data->display_name,
            'mention_name' => $user_data->user_nicename,
            'avatar_urls' => [
                'full' => $avatar_url
            ],
            'hires_avatar' => [
                'large' => $attach_id ? wp_get_attachment_image_url($attach_id, 'large') : '',
                'full' => $attach_id ? wp_get_attachment_image_url($attach_id, 'full') : '',
            ],
            'last_activity' => $last_active,
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
});

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
        // UNLIKE
        $my_likes = array_diff($my_likes, [$liked_id]);
        $liked_by = array_diff($liked_by, [$liker_id]);
        
        // Remove friendship in BuddyPress
        if (function_exists('friends_remove_friend')) {
            friends_remove_friend($liker_id, $liked_id);
        }
        
        $new_status = 'unliked';
    } else {
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
    
    // Clear cache
    delete_transient('users_grid_cache_' . $liker_id);
    delete_transient('users_grid_cache_' . $liked_id);
    
    return rest_ensure_response([
        'status' => $new_status,
        'is_match' => $is_mutual_match_possible && $new_status === 'liked'
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
    
    if (empty($activation_key) || empty($user_login)) {
        return new WP_Error('missing_params', 'Brak klucza aktywacyjnego lub nazwy użytkownika', ['status' => 400]);
    }
    
    $activate = bp_core_activate_signup($activation_key);
    
    if (is_wp_error($activate)) {
        return new WP_Error('activation_failed', $activate->get_error_message(), ['status' => 400]);
    }
    
    return rest_ensure_response([
        'success' => true,
        'message' => 'Konto zostało aktywowane pomyślnie!',
        'user_id' => $activate['user_id'],
        'username' => $activate['user_login'],
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
});

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
    
    // Zarejestruj przez BuddyPress signup
    $signup_id = bp_core_signup_user(
        $username,
        $password,
        $email,
        [
            'field_1' => $username,
            'temp_password_for_activation' => $password // Zapisz czyste hasło do późniejszego ustawienia
        ]
    );
    
    if (is_wp_error($signup_id)) {
        return new WP_Error('registration_failed', $signup_id->get_error_message(), ['status' => 500]);
    }
    
    // Dodatkowo zaktualizuj meta z hasłem
    global $wpdb;
    $signup_row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, meta FROM {$wpdb->prefix}signups WHERE user_login = %s",
        $username
    ));
    
    if ($signup_row) {
        $meta = maybe_unserialize($signup_row->meta);
        if (!is_array($meta)) {
            $meta = [];
        }
        $meta['temp_password_for_activation'] = $password;
        
        $wpdb->update(
            $wpdb->prefix . 'signups',
            ['meta' => maybe_serialize($meta)],
            ['id' => $signup_row->id]
        );
    }
    
    // Jeśli jest avatar, zapisz go tymczasowo z activation key
    if ($has_avatar) {
        global $wpdb;
        $signup = $wpdb->get_row($wpdb->prepare(
            "SELECT activation_key FROM {$wpdb->prefix}signups WHERE user_login = %s",
            $username
        ));
        
        if ($signup && $signup->activation_key) {
            $upload_dir = wp_upload_dir();
            $temp_dir = $upload_dir['basedir'] . '/temp-avatars/';
            
            if (!file_exists($temp_dir)) {
                wp_mkdir_p($temp_dir);
            }
            
            $ext = pathinfo($files['avatar']['name'], PATHINFO_EXTENSION);
            $temp_file = $temp_dir . $signup->activation_key . '.' . $ext;
            
            move_uploaded_file($files['avatar']['tmp_name'], $temp_file);
        }
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
    
    // Szukaj pliku z tym activation key
    $files = glob($temp_dir . $key . '.*');
    error_log("Looking for avatar files: " . print_r($files, true));
    
    if (!empty($files)) {
        $temp_file = $files[0];
        error_log("Found temp file: $temp_file");
        
        // Ustaw jako avatar BuddyPress
        $avatar_dir = bp_core_avatar_upload_path() . '/avatars/' . $user_id . '/';
        wp_mkdir_p($avatar_dir);
        error_log("Avatar dir: $avatar_dir");
        
        // Standardowe nazwy plików BuddyPress
        $ext = pathinfo($temp_file, PATHINFO_EXTENSION);
        $avatar_full = $avatar_dir . $user_id . '-bpfull.' . $ext;
        $avatar_thumb = $avatar_dir . $user_id . '-bpthumb.' . $ext;
        
        error_log("Avatar full path: $avatar_full");
        error_log("Avatar thumb path: $avatar_thumb");
        
        // Stwórz miniatury
        $image = wp_get_image_editor($temp_file);
        if (!is_wp_error($image)) {
            // Full size (150x150)
            $image->resize(150, 150, true);
            $image->save($avatar_full);
            error_log("Saved full avatar");
            
            // Thumb size (50x50)
            $image = wp_get_image_editor($temp_file);
            $image->resize(50, 50, true);
            $image->save($avatar_thumb);
            error_log("Saved thumb avatar");
        } else {
            error_log("Image editor error: " . $image->get_error_message());
        }
        
        // Usuń plik tymczasowy
        unlink($temp_file);
        error_log("Deleted temp file");
    } else {
        error_log("No avatar file found for key: $key");
    }
}

// ============================================================================
// MOBILE DEEP LINKING DLA STRONY AKTYWACJI
// ============================================================================

add_action('wp_footer', 'sk_mobile_activation_redirect');
function sk_mobile_activation_redirect() {
    // Sprawdź czy jesteśmy na stronie aktywacji BuddyPress
    if (!isset($_GET['key']) || strpos($_SERVER['REQUEST_URI'], 'activate') === false) {
        return;
    }
    
    $activation_key = sanitize_text_field($_GET['key']);
    ?>
    <script>
    (function() {
        // Wykryj czy jest mobile
        var isMobile = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent);
        
        if (isMobile && '<?php echo esc_js($activation_key); ?>') {
            var appUrl = 'prawdziwamilosc://activate?key=<?php echo esc_js($activation_key); ?>';
            
            // Próbuj otworzyć aplikację
            var iframe = document.createElement('iframe');
            iframe.style.display = 'none';
            iframe.src = appUrl;
            document.body.appendChild(iframe);
            
            // Alternatywnie użyj window.location
            setTimeout(function() {
                window.location.href = appUrl;
            }, 100);
            
            // Fallback - jeśli aplikacja nie otworzy się w ciągu 2 sekund,
            // pozwól użytkownikowi aktywować przez web
            setTimeout(function() {
                document.body.removeChild(iframe);
            }, 2000);
        }
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
        
        return rest_ensure_response([
            'success' => true,
            'thread_id' => $thread_id,
            'message' => 'Message sent successfully'
        ]);
    }
    
    return new WP_Error('no_messaging_system', 'BuddyPress messaging not available', ['status' => 500]);
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
            <button class="pm-mh-btn pm-filter-btn" id="pm-filter-toggle">🔍</button>
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
            <a href="<?php echo esc_url($user_profile_url); ?>" class="pm-mh-avatar">
                <img src="<?php echo esc_url($avatar_url); ?>" alt="Profil">
            </a>
        </div>
    </div>
    
    <div class="pm-member-tabs" id="pm-member-tabs">
        <button class="pm-mtab active" data-tab="search">Wyszukaj</button>
        <button class="pm-mtab" data-tab="liked">Polubieni</button>
        <button class="pm-mtab" data-tab="likes-me">Lubią Mnie</button>
        <button class="pm-mtab" data-tab="matches">Matche</button>
    </div>
    
    <div id="pm-tabs-loader" style="display:none; text-align:center; padding:40px;">
        <div class="pm-spinner"></div>
        <p style="color:#999; margin-top:15px;">Ładowanie...</p>
    </div>
    
    <div id="pm-tabs-content"></div>
    
    <style>
    /* Mobile Header Bar - visible only on mobile */
    .pm-mobile-header {
        display: none;
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
    
    @media (max-width: 767px) {
        .pm-mobile-header {
            display: flex;
        }
    }
    
    @media (min-width: 768px) {
        .pm-mobile-header {
            display: none !important;
        }
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
        overflow: hidden;
        border: 2px solid rgba(255,255,255,0.3);
    }
    
    .pm-mh-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    /* Mobile Member Tabs - only visible on mobile */
    .pm-member-tabs {
        display: none;
        overflow-x: auto;
        background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
        padding: 12px 15px;
        gap: 4px;
        position: sticky;
        top: 0;
        z-index: 100;
        border-bottom: 1px solid rgba(255,255,255,0.1);
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
    }
    
    .pm-member-tabs::-webkit-scrollbar {
        display: none;
    }
    
    @media (max-width: 767px) {
        .pm-member-tabs {
            display: flex;
            position: fixed;
            top: calc(56px + env(safe-area-inset-top, 0));
            left: 0;
            right: 0;
            width: 100%;
            padding-top: 0;
        }
        
        /* Add padding to body to account for fixed header + tabs */
        body.pm-tabs-active {
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
        .pm-tabs-active .top-header {
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
    </style>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
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
        
        // Add class to body for CSS hiding
        document.body.classList.add('pm-tabs-active');
        
        let currentTab = 'search';
        
        tabs.forEach(tab => {
            tab.addEventListener('click', async function() {
                const tabId = this.dataset.tab;
                if (tabId === currentTab) return;
                
                // Update active state
                tabs.forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                currentTab = tabId;
                
                // Show/hide content based on tab
                if (tabId === 'search') {
                    // Show original BuddyPress content
                    loader.style.display = 'none';
                    content.style.display = 'none';
                    if (originalContent) originalContent.style.display = '';
                    document.body.classList.remove('pm-tabs-active');
                } else {
                    // Load AJAX content
                    if (originalContent) originalContent.style.display = 'none';
                    content.style.display = 'block';
                    loader.style.display = 'block';
                    document.body.classList.add('pm-tabs-active');
                    
                    try {
                        const endpoint = getEndpoint(tabId);
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
                        console.error('Error loading tab:', error);
                        loader.style.display = 'none';
                        content.innerHTML = '<div class="pm-empty-state"><p>Błąd ładowania danych</p></div>';
                    }
                }
            });
        });
        
        function getEndpoint(tabId) {
            const base = '<?php echo rest_url('sk/v1/'); ?>';
            switch(tabId) {
                case 'liked': return base + 'liked';
                case 'likes-me': return base + 'likes-me';
                case 'matches': return base + 'matches';
                default: return base + 'liked';
            }
        }
        
        function renderMembers(members, tabId) {
            if (!members || members.length === 0) {
                const messages = {
                    'liked': 'Nie masz jeszcze polubionych profili',
                    'likes-me': 'Nikt jeszcze nie polubił Twojego profilu',
                    'matches': 'Nie masz jeszcze żadnych matchy'
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
            
            let html = '<div class="pm-tab-grid">';
            members.forEach(member => {
                const avatar = member.hires_avatar?.large || member.hires_avatar?.full || member.avatar_urls?.full || member.avatar || '';
                const name = member.name || member.display_name || 'Użytkownik';
                const age = member.age || '';
                const url = member.link || member.profile_url || '/members/' + (member.user_nicename || member.id);
                
                html += `
                    <div class="pm-tab-card">
                        <a href="${url}">
                            <img src="${avatar}" alt="${name}" loading="lazy">
                        </a>
                        <div class="pm-tab-card-info">
                            <h4 class="pm-tab-card-name">${name}</h4>
                            ${age ? `<span class="pm-tab-card-age">${age} lat</span>` : ''}
                            <div class="pm-tab-card-actions">
                                <a href="${url}" class="pm-tab-card-btn profile">Profil</a>
                                <a href="${url}bp-messages/" class="pm-tab-card-btn message">💬</a>
                            </div>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            content.innerHTML = html;
        }
    });
    </script>
    
    <?php
}
add_action('wp_footer', 'pm_mobile_member_tabs', 99);
