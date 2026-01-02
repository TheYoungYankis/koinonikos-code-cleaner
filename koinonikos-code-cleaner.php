<?php
/**
 * Plugin Name: KOINONIKOS Code Cleaner
 * Description: Nettoie le code HTML (attributs data-* de ChatGPT/Quillbot, class="qbe-widget", attributs vides, &nbsp;, balises vides). Bouton Clean Code dans TinyMCE ou Gutenberg + nettoyage global ou automatique.
 * Version: 1.9.2
 * Author: KOINONIKOS, Allen Le Yaouanc
 * Author URI: https://koinonikos.com/
 * License: GPLv2 or later
 * Text Domain: koinonikos-code-cleaner
 */

if (!defined('ABSPATH')) exit;

class Koinonikos_Code_Cleaner {
    const OPTION_AUTO = 'kcc_auto_clean_on_save';
    const NONCE_ADMIN = 'kcc_admin_action';
    const NONCE_AJAX  = 'kcc_ajax_clean';

    public function __construct() {

        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_kcc_scan', [$this, 'handle_bulk_scan']);

        add_filter('content_save_pre', [$this, 'maybe_auto_clean_on_save'], 12);

        add_action('wp_ajax_kcc_clean', [$this, 'ajax_clean']);

        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // TinyMCE plugin + bouton
        add_filter('mce_external_plugins', [$this, 'register_tinymce_plugin']);
        add_filter('mce_buttons', [$this, 'add_tinymce_button']);

        // Gutenberg
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_gutenberg_assets']);

        // Lien "Réglages"
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_plugin_action_link']);
    }

    public function add_plugin_action_link($links) {
        $url = admin_url('tools.php?page=kcc-settings');
        array_unshift($links, '<a href="'.$url.'">Réglages</a>');
        return $links;
    }

    /* -----------------------------------
     *  CLEANER CORE FUNCTION
     * ----------------------------------- */
    public static function clean_html($html) {
        if (!is_string($html) || trim($html) === '') return $html;

        // Normalisation des espaces insécables avant parsing
        $html = trim($html);
        $html = str_replace("\xC2\xA0", ' ', $html);    // caractère insécable UTF-8
        $html = str_replace('&nbsp;', ' ', $html);      // entité &nbsp;
        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');

        $libxml_previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<div id="kcc-wrapper">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new DOMXPath($dom);

        /* 1) Nettoyage des attributs */
        foreach ($xpath->query('//*') as $el) {
            if (!($el instanceof DOMElement)) continue;
            $toRemove = [];

            foreach (iterator_to_array($el->attributes) as $attr) {
                $name  = strtolower($attr->name);
                $value = $attr->value;

                // data-*
                if (strpos($name, 'data-') === 0) {
                    $toRemove[] = $name;
                    continue;
                }

                // class (suppression de qbe-widget)
                if ($name === 'class') {
                    $classes = preg_split('/\s+/', trim($value)) ?: [];
                    $classes = array_values(array_filter($classes, function($c){
                        return strtolower($c) !== 'qbe-widget' && $c !== '';
                    }));

                    if (empty($classes)) {
                        $toRemove[] = 'class';
                    } else {
                        $el->setAttribute('class', implode(' ', $classes));
                    }
                    continue;
                }

                // Attributs vides
                if (in_array($name, ['id','style','title','aria-label','role'], true) && trim($value) === '') {
                    $toRemove[] = $name;
                }
            }

            foreach ($toRemove as $n) {
                $el->removeAttribute($n);
            }
        }

        /* 2) Suppression de toutes les balises <span> en conservant le contenu */
        $spanNodes = [];
        foreach ($xpath->query('//span') as $span) {
            $spanNodes[] = $span; // cloner la NodeList, elle est live
        }
        foreach ($spanNodes as $span) {
            if (!$span->parentNode) continue;
            $parent = $span->parentNode;
            while ($span->firstChild) {
                $parent->insertBefore($span->firstChild, $span);
            }
            $parent->removeChild($span);
        }

        /* 3) Suppression des <strong> à l’intérieur des titres h1–h6 (on garde le texte) */
        $headingTags = ['h1','h2','h3','h4','h5','h6'];
        foreach ($headingTags as $hTag) {
            foreach ($xpath->query('//'.$hTag) as $heading) {
                foreach ($xpath->query('.//strong', $heading) as $strong) {
                    if (!$strong->parentNode) continue;
                    $parent = $strong->parentNode;
                    while ($strong->firstChild) {
                        $parent->insertBefore($strong->firstChild, $strong);
                    }
                    $parent->removeChild($strong);
                }
            }
        }

        /* 4) Suppression des balises vides (hors médias) */
        $allowed_empty = [
            'br','img','hr','input','source',
            'video','audio','iframe','picture',
            'embed','object','svg','canvas'
        ];

        do {
            $removed = false;

            foreach ($xpath->query('//*') as $empty) {
                $tag = strtolower($empty->nodeName);

                if (in_array($tag, $allowed_empty, true)) continue;

                $hasChild = false;
                foreach ($empty->childNodes as $child) {
                    if ($child instanceof DOMElement) {
                        $hasChild = true;
                        break;
                    }
                }
                if ($hasChild) continue;

                if (trim($empty->textContent) !== '') continue;

                if ($empty->parentNode) {
                    $empty->parentNode->removeChild($empty);
                    $removed = true;
                }
            }
        } while ($removed);

        /* Extraction finale */
        $wrapper = $dom->getElementById('kcc-wrapper');
        $cleaned = '';
        if ($wrapper) {
            foreach ($wrapper->childNodes as $child) {
                $cleaned .= $dom->saveHTML($child);
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors($libxml_previous);

        // Post-traitement : virer les derniers &nbsp; éventuels + doubles espaces
        $cleaned = str_replace("\xC2\xA0", ' ', $cleaned);
        $cleaned = str_replace('&nbsp;', ' ', $cleaned);
        $cleaned = preg_replace('/ {2,}/', ' ', $cleaned);
        $cleaned = trim($cleaned);

        return html_entity_decode($cleaned, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function maybe_auto_clean_on_save($content) {
        return get_option(self::OPTION_AUTO, false) ? self::clean_html($content) : $content;
    }

    public function add_settings_page() {
        add_management_page(
            'Nettoyeur de code',
            'Nettoyeur de code',
            'manage_options',
            'kcc-settings',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting('kcc_settings_group', self::OPTION_AUTO, [
            'type' => 'boolean',
            'sanitize_callback' => fn($v)=> (bool)$v,
            'default' => false,
        ]);
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        $auto = (bool) get_option(self::OPTION_AUTO, false);
        $scan_url = admin_url('admin-post.php');
?>
        <div class="wrap">
            <h1>🧹 Nettoyeur de code — KOINONIKOS</h1>
            <p>Supprime les attributs <code>data-*</code>, les <code>&nbsp;</code>, les <code>&lt;span&gt;</code>, les classes inutiles et les balises réellement vides, sans supprimer les images.</p>

            <form method="post" action="options.php">
                <?php settings_fields('kcc_settings_group'); ?>
                <label>
                    <input type="checkbox" name="<?php echo self::OPTION_AUTO; ?>" value="1" <?php checked($auto); ?>>
                    Nettoyer automatiquement lors de la sauvegarde
                </label>
                <?php submit_button(); ?>
            </form>

            <hr>

            <h2>Scan global</h2>
            <p><strong>⚠️ Opération irréversible.</strong></p>

            <form method="post" action="<?php echo esc_url($scan_url); ?>" onsubmit="return confirm('Confirmer le nettoyage global ?');">
                <?php wp_nonce_field(self::NONCE_ADMIN); ?>
                <input type="hidden" name="action" value="kcc_scan">
                <label><input type="checkbox" name="kcc_scan_types[]" value="post" checked> Articles</label>
                <label><input type="checkbox" name="kcc_scan_types[]" value="page" checked> Pages</label>
                <?php submit_button('Scanner & nettoyer', 'delete'); ?>
            </form>
        </div>
<?php
    }

    public function handle_bulk_scan() {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer(self::NONCE_ADMIN);

        $types = isset($_POST['kcc_scan_types']) ? array_map('sanitize_text_field', (array) $_POST['kcc_scan_types']) : ['post','page'];
        $types = array_intersect($types, ['post','page']);

        $total = 0;

        foreach ($types as $post_type) {
            $q = new WP_Query([
                'post_type'      => $post_type,
                'post_status'    => ['publish','draft','pending','future','private'],
                'posts_per_page' => -1,
                'fields'         => 'ids'
            ]);

            foreach ($q->posts as $post_id) {

                $content = get_post_field('post_content', $post_id);
                $cleaned = self::clean_html($content);

                if ($cleaned !== $content) {

                    remove_filter('content_save_pre', [$this, 'maybe_auto_clean_on_save'], 12);

                    wp_update_post([
                        'ID'           => $post_id,
                        'post_content' => $cleaned
                    ]);

                    add_filter('content_save_pre', [$this, 'maybe_auto_clean_on_save'], 12);

                    $total++;
                }
            }
        }

        wp_safe_redirect(
            add_query_arg(['page'=>'kcc-settings','kcc_scanned'=>$total], admin_url('tools.php'))
        );
        exit;
    }

    public function ajax_clean() {
        if (!current_user_can('edit_posts')) wp_send_json_error(['message'=>'Forbidden'],403);
        check_ajax_referer(self::NONCE_AJAX,'nonce');

        $content = isset($_POST['content']) ? (string) wp_unslash($_POST['content']) : '';
        wp_send_json_success(['cleaned'=>self::clean_html($content)]);
    }

    /* -----------------------------------
     *   ENQUEUE SCRIPT + CSS (post/page uniquement)
     * ----------------------------------- */
    public function enqueue_admin_assets($hook) {
        if (!in_array($hook, ['post.php','post-new.php'], true)) {
            return;
        }

        $screen    = function_exists('get_current_screen') ? get_current_screen() : null;
        $post_type = $screen && isset($screen->post_type) ? $screen->post_type : null;

        if (!in_array($post_type, ['post','page'], true)) {
            return; // ne rien charger sur les taxonomies, CPT, etc.
        }

        $data = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE_AJAX),
            'iconUrl' => plugins_url('assets/cleancodespray.png', __FILE__)
        ];

        wp_register_script(
            'kcc-editor-classic',
            plugins_url('assets/editor-cleaner-classic.js', __FILE__),
            ['jquery'],
            '1.9.2',
            true
        );

        wp_localize_script('kcc-editor-classic', 'KCC', $data);
        wp_enqueue_script('kcc-editor-classic');

        wp_enqueue_style(
            'kcc-editor-css',
            plugins_url('assets/editor-cleaner.css', __FILE__),
            [],
            '1.0.0'
        );
    }

    /* TinyMCE plugin (URL avec version pour casser le cache) */
    public function register_tinymce_plugin($plugins) {
        $url = plugins_url('assets/editor-cleaner-classic.js', __FILE__);
        $url = add_query_arg('ver', '1.9.2', $url);
        $plugins['kcc_clean_button'] = $url;
        return $plugins;
    }

    public function add_tinymce_button($buttons) {
        $buttons[] = 'kcc_clean_code';
        return $buttons;
    }

    /* Gutenberg panel : post/page uniquement */
    public function enqueue_gutenberg_assets() {
        $screen    = function_exists('get_current_screen') ? get_current_screen() : null;
        $post_type = $screen && isset($screen->post_type) ? $screen->post_type : null;

        if (!in_array($post_type, ['post','page'], true)) {
            return;
        }

        wp_register_script(
            'kcc-editor-gutenberg',
            plugins_url('assets/editor-cleaner-gutenberg.js', __FILE__),
            ['wp-plugins','wp-edit-post','wp-components','wp-element','wp-data'],
            '1.9.2',
            true
        );

        wp_localize_script('kcc-editor-gutenberg', 'KCC', [
            'ajaxUrl'=>admin_url('admin-ajax.php'),
            'nonce'  => wp_create_nonce(self::NONCE_AJAX)
        ]);

        wp_enqueue_script('kcc-editor-gutenberg');
    }
}

new Koinonikos_Code_Cleaner();
