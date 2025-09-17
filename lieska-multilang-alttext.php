<?php
/*
Plugin Name: Lieska Multi-Language AI Alt Text – Free
Description: Multilingual alt text for images with OpenAI using your own API key. Free edition with a daily limit (3 images/day), manual generation (Attachment screen & Media modal), and automatic language-aware rendering (WPML, Polylang, TranslatePress).
Author: Lieska
Version: 1.0.5
Text Domain: lieska-mlat
*/

if (!defined('ABSPATH')) exit;

define('LIESKA_MLAT_VERSION', '1.0.4');
define('LIESKA_MLAT_TEXTDOMAIN', 'lieska-mlat');

// Pro voi kytkeä tämän true:ksi
function lieska_mlat_is_pro() {
    return apply_filters('lieska_mlat_is_pro', false);
}

class Lieska_MLAT_Free {
    const OPT_KEY   = 'lieska_ml_alt_options';
    const NONCE     = 'lieska_ml_alt_nonce_free';

    public function __construct() {
        add_action('plugins_loaded', [$this,'load_textdomain']);
        add_action('admin_init',     [$this,'register_settings']);
        add_action('admin_menu',     [$this,'add_settings_page']);

        // UI: Metabox attachment-näkymään + Media-modal pikanappi
        add_action('add_meta_boxes',        [$this, 'add_metabox']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_attachment_screen']);
        add_action('wp_enqueue_media',      [$this, 'enqueue_media_modal_script']);

        // AJAX: yksittäinen ALT nykyiselle admin-kielelle
        add_action('wp_ajax_lieska_generate_alt_text', [$this, 'ajax_generate_alt_text']);

        // Frontend: vaihda ALT nykyisen kielen mukaan
        add_filter('wp_get_attachment_image_attributes', [$this, 'filter_image_alt'], 10, 2);
    }

    /* ---------- i18n ---------- */
    public function load_textdomain(){
        load_plugin_textdomain(LIESKA_MLAT_TEXTDOMAIN, false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /* ---------- Options ---------- */
    public function default_options() {
        return [
            'api_key'     => '',                 // Free: käyttäjän oma avain
            'model'       => 'gpt-4o-mini',
            'langs'       => 'fi,sv,en',
            'prompt'      => 'Kirjoita saavutettava, ytimekäs ja informatiivinen alt-teksti kielellä {{LANG}} kuvalle {{FILENAME}}. Vastaa vain kyseisellä kielellä, yksi virke, ei lainausmerkkejä.',
            'max_tokens'  => 75,
            // Free quota
            'free_limit'  => 3,                  // 3 kuvaa/päivä
        ];
    }
    public function get_options() {
        $opts = get_option(self::OPT_KEY, []);
        return wp_parse_args($opts, $this->default_options());
    }
    public function register_settings() {
        register_setting('lieska_mlat_free', self::OPT_KEY, [
            'type'=>'array',
            'sanitize_callback'=>function($in){
                $def = $this->default_options();
                $out = [];
                $out['api_key']    = isset($in['api_key']) ? trim($in['api_key']) : '';
                $out['model']      = isset($in['model']) ? sanitize_text_field($in['model']) : $def['model'];
                $out['langs']      = isset($in['langs']) ? strtolower(preg_replace('/\s+/', '', $in['langs'])) : $def['langs'];
                $out['prompt']     = isset($in['prompt']) ? wp_kses_post($in['prompt']) : $def['prompt'];
                $out['max_tokens'] = isset($in['max_tokens']) ? max(10,intval($in['max_tokens'])) : $def['max_tokens'];
                $out['free_limit'] = isset($in['free_limit']) ? max(1,intval($in['free_limit'])) : $def['free_limit'];
                return $out;
            },
            'default'=>$this->default_options(),
        ]);
    }
    public function add_settings_page() {
        add_options_page(
            __('Lieska AI Alt (Free)', LIESKA_MLAT_TEXTDOMAIN),
            __('Lieska AI Alt (Free)', LIESKA_MLAT_TEXTDOMAIN),
            'manage_options',
            'lieska-ml-alt',
            [$this, 'render_settings_page']
        );
    }
    public function render_settings_page() {
        $o = $this->get_options();
        $used  = $this->get_quota_today();
        $limit = $this->free_limit();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Lieska Multi-Language AI Alt Text – Free', LIESKA_MLAT_TEXTDOMAIN); ?></h1>
            <?php if ( lieska_mlat_is_pro() ): ?>
                <div class="notice notice-success"><p><strong><?php esc_html_e('Pro add-on active: daily limits removed and night CRON & bulk enabled.', LIESKA_MLAT_TEXTDOMAIN); ?></strong></p></div>
            <?php else: ?>
                <div class="notice notice-info"><p><?php echo esc_html( sprintf( __('Free edition: %d / %d AI generations used today.', LIESKA_MLAT_TEXTDOMAIN), $used, $limit ) ); ?></p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('lieska_mlat_free'); $this->render_basic_settings($o); ?>

                <?php
                /**
                 * Pro voi lisätä jatko-osioita tähän (CRON, bulk ym.)
                 */
                do_action('lieska_mlat_settings_after_basic', $o);
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
    private function render_basic_settings($o){ ?>
        <h2 class="title"><?php esc_html_e('OpenAI & Generation Settings', LIESKA_MLAT_TEXTDOMAIN); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="api_key"><?php esc_html_e('OpenAI API key', LIESKA_MLAT_TEXTDOMAIN); ?></label></th>
                <td>
                    <input id="api_key" type="password" name="<?php echo esc_attr(self::OPT_KEY); ?>[api_key]" value="<?php echo esc_attr($o['api_key']); ?>" class="regular-text" autocomplete="off" />
                    <p class="description"><?php esc_html_e('Free edition uses your own OpenAI key. Billing is through your OpenAI account.', LIESKA_MLAT_TEXTDOMAIN); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="model"><?php esc_html_e('Model', LIESKA_MLAT_TEXTDOMAIN); ?></label></th>
                <td><input id="model" type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[model]" value="<?php echo esc_attr($o['model']); ?>" class="regular-text" />
                    <p class="description"><code>gpt-4o-mini</code></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="langs"><?php esc_html_e('Languages (preferred order, CSV)', LIESKA_MLAT_TEXTDOMAIN); ?></label></th>
                <td><input id="langs" type="text" name="<?php echo esc_attr(self::OPT_KEY); ?>[langs]" value="<?php echo esc_attr($o['langs']); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e('e.g. fi,sv,en', LIESKA_MLAT_TEXTDOMAIN); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="prompt"><?php esc_html_e('Prompt template', LIESKA_MLAT_TEXTDOMAIN); ?></label></th>
                <td><textarea id="prompt" name="<?php echo esc_attr(self::OPT_KEY); ?>[prompt]" class="large-text" rows="4"><?php echo esc_textarea($o['prompt']); ?></textarea>
                    <p class="description"><?php esc_html_e('Use {{LANG}} and {{FILENAME}} placeholders.', LIESKA_MLAT_TEXTDOMAIN); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="max_tokens"><?php esc_html_e('Max tokens', LIESKA_MLAT_TEXTDOMAIN); ?></label></th>
                <td><input id="max_tokens" type="number" min="10" step="1" name="<?php echo esc_attr(self::OPT_KEY); ?>[max_tokens]" value="<?php echo esc_attr(intval($o['max_tokens'])); ?>" /></td>
            </tr>
            <?php if ( ! lieska_mlat_is_pro() ): ?>
            <tr>
                <th scope="row"><label for="free_limit"><?php esc_html_e('Daily limit (images/day)', LIESKA_MLAT_TEXTDOMAIN); ?></label></th>
                <td><input id="free_limit" type="number" min="1" step="1" name="<?php echo esc_attr(self::OPT_KEY); ?>[free_limit]" value="<?php echo esc_attr(intval($o['free_limit'])); ?>" />
                    <p class="description"><?php esc_html_e('Free edition daily quota. Set by site owner.', LIESKA_MLAT_TEXTDOMAIN); ?></p>
                </td>
            </tr>
            <?php endif; ?>
        </table>
    <?php }

    /* ---------- Quota (Free) ---------- */
    private function quota_key_today() {
        $today = date_i18n('Ymd', current_time('timestamp'));
        return 'lieska_mlat_quota_' . $today;
    }
    private function get_quota_today() { return (int) get_option($this->quota_key_today(), 0); }
    private function inc_quota_today() { $k = $this->quota_key_today(); $v = (int)get_option($k,0)+1; update_option($k,$v,false); return $v; }
    private function free_limit() {
        $o = $this->get_options();
        return max(1, intval($o['free_limit']));
    }
    private function can_generate_now() {
        if ( lieska_mlat_is_pro() ) return true;
        return $this->get_quota_today() < $this->free_limit();
    }

    /* ---------- Language helpers ---------- */
    private function get_current_lang() {
        if ( function_exists('pll_current_language') ) { $s = pll_current_language('slug'); if ($s) return strtolower($s); }
        if ( function_exists('trp_get_current_language') ) { $t = trp_get_current_language(); if ($t) return strtolower($t); }
        $cur = apply_filters('wpml_current_language', null);
        if ($cur) return strtolower($cur);
        $loc = get_locale();
        return strtolower(substr($loc,0,2));
    }
    private function lang_label($code) {
        $map = ['fi'=>'suomi','sv'=>'svenska','en'=>'English'];
        $code = strtolower($code);
        return $map[$code] ?? strtoupper($code);
    }
    private function langs_from_pref($csv) {
        $out = [];
        foreach (explode(',', strtolower($csv)) as $c) { $c=trim($c); if($c) $out[]=$c; }
        return $out ?: ['en'];
    }

    /* ---------- Storage (per-language alts) ---------- */
    private function get_alts_json($attachment_id) {
        $json = get_post_meta($attachment_id, '_lieska_mlat_alts', true);
        $arr = $json ? json_decode($json, true) : [];
        return is_array($arr) ? $arr : [];
    }
    private function save_alt_for_lang($attachment_id, $lang, $alt) {
        $arr = $this->get_alts_json($attachment_id);
        $arr[$lang] = $alt;
        update_post_meta($attachment_id, '_lieska_mlat_alts', wp_json_encode($arr));
        // Päivitä myös WP:n oma alt fallbackiksi
        if (empty(get_post_meta($attachment_id, '_wp_attachment_image_alt', true))) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
        }
    }
    public function filter_image_alt($attr, $attachment) {
        $alts = $this->get_alts_json($attachment->ID);
        $lang = $this->get_current_lang();
        if (!empty($alts[$lang])) { $attr['alt'] = $alts[$lang]; }
        return $attr;
    }

    /* ---------- OpenAI call ---------- */
    private function openai_generate_alt($api_key, $model, $attachment_id, $image_url, $prompt, $max_tokens, $system_lang = 'English') {
        $endpoint = 'https://api.openai.com/v1/chat/completions';

        // Build image part (data URL if possible)
        $path = get_attached_file($attachment_id);
        $image_part = ['type'=>'image_url','image_url'=>['url'=>$image_url]];
        if ($path && file_exists($path) && is_readable($path)) {
            $size = @filesize($path);
            if ($size !== false && $size <= 10*1024*1024) {
                $mime = wp_check_filetype($path); $mime = $mime['type'] ?: 'application/octet-stream';
                $data = @file_get_contents($path);
                if ($data !== false) {
                    $b64 = base64_encode($data);
                    $image_part = ['type'=>'image_url','image_url'=>['url'=>'data:'.$mime.';base64,'.$b64]];
                }
            }
        }

        $messages = [
            ['role'=>'system', 'content'=>'You are an accessibility alt-text generator. Always reply ONLY in '.$system_lang.' as one short, descriptive sentence without quotes. Avoid phrases like "Image of". Be concise and helpful for screen readers.'],
            ['role'=>'user', 'content'=>[
                ['type'=>'text','text'=>$prompt],
                $image_part
            ]],
        ];
        $body = ['model'=>$model, 'max_tokens'=>intval($max_tokens), 'temperature'=>0.3, 'messages'=>$messages];

        $resp = wp_remote_post($endpoint, [
            'timeout'=>90,
            'headers'=>['Authorization'=>'Bearer '.$api_key,'Content-Type'=>'application/json'],
            'body'=>wp_json_encode($body),
        ]);
        if (is_wp_error($resp)) return $resp;
        $code = wp_remote_retrieve_response_code($resp);
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code >= 400 || !is_array($data)) {
            $msg = isset($data['error']['message']) ? $data['error']['message'] : ('HTTP '.$code);
            return new WP_Error('openai_http', $msg);
        }
        $text = rtrim($data['choices'][0]['message']['content'] ?? '');
        if (!$text) return new WP_Error('openai_empty','Empty response');
        $text = trim(preg_replace('/\s+/', ' ', $text), "\"' ");
        if (function_exists('mb_strimwidth')) $text = mb_strimwidth($text, 0, 160, '');
        return $text;
    }

    /* ---------- Core generation (public so Pro CRON voi kutsua) ---------- */
    public function generate_for_langs($attachment_id, $langs, $overwrite = false) {
        $o = $this->get_options();
        if (empty($o['api_key']) && !lieska_mlat_is_pro()) {
            return new WP_Error('missing_api', __('Add your OpenAI API key in Settings.', LIESKA_MLAT_TEXTDOMAIN));
        }
        $mime = get_post_mime_type($attachment_id);
        if (!$mime || strpos($mime,'image/') !== 0) return new WP_Error('not_image','Not an image');
        $image_url = wp_get_attachment_image_url($attachment_id, 'full');
        if (!$image_url) return new WP_Error('no_image_url','No image URL');
        $file = get_attached_file($attachment_id);
        $filename = $file ? wp_basename($file) : get_the_title($attachment_id);

        $results = [];
        foreach ($langs as $code) {
            // quota vain Free-tilassa ja vain jos overwrite tai alt puuttuu
            if (!lieska_mlat_is_pro()) {
                if (!$this->can_generate_now()) { $results[] = [$code,'quota']; continue; }
            }
            if (!$overwrite) {
                $alts = $this->get_alts_json($attachment_id);
                if (!empty($alts[$code])) { $results[] = [$code,'skipped']; continue; }
            }
            $prompt = strtr($o['prompt'], ['{{LANG}}'=>$this->lang_label($code), '{{FILENAME}}'=>$filename]);
            $syslang = $this->lang_label($code);
            $api_key = $o['api_key'];
            $alt = $this->openai_generate_alt($api_key, $o['model'], $attachment_id, $image_url, $prompt, intval($o['max_tokens']), $this->lang_label_to_system($code));
            if (is_wp_error($alt)) { $results[] = [$code, 'error: '.$alt->get_error_message()]; continue; }
            $this->save_alt_for_lang($attachment_id, $code, $alt);
            if (!lieska_mlat_is_pro()) $this->inc_quota_today();
            $results[] = [$code,'OK'];
            usleep(250000); // 250ms
        }
        return implode(', ', array_map(fn($r)=>$r[0].': '.$r[1], $results));
    }
    private function lang_label_to_system($code) {
        $map = ['fi'=>'Finnish','sv'=>'Swedish','en'=>'English'];
        $code=strtolower($code); return $map[$code] ?? 'English';
    }

    /* ---------- AJAX: single-lang (current admin lang) ---------- */
    public function ajax_generate_alt_text() {
        if (!current_user_can('upload_files')) wp_send_json(['ok'=>false,'message'=>'capability']);
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], self::NONCE)) wp_send_json(['ok'=>false,'message'=>'nonce']);
        $attachment_id = isset($_POST['attachment_id']) ? intval($_POST['attachment_id']) : 0;
        $overwrite = !empty($_POST['overwrite']);
        if (!$attachment_id) wp_send_json(['ok'=>false,'message'=>'missing attachment_id']);

        $o = $this->get_options();
        $lang = $this->get_current_admin_lang();
        $res  = $this->generate_for_langs($attachment_id, [$lang], $overwrite);
        if (is_wp_error($res)) wp_send_json(['ok'=>false,'message'=>$res->get_error_message()]);
        // palauta viimeisin ALT
        $alts = $this->get_alts_json($attachment_id);
        $alt  = $alts[$lang] ?? '';
        wp_send_json(['ok'=>true,'alt'=>$alt,'details'=>$res,'lang'=>$lang]);
    }
    private function get_current_admin_lang() {
        if ( function_exists('apply_filters') ) {
            $cur = apply_filters('wpml_current_language', null);
            if ($cur) return $cur;
            if (function_exists('pll_current_language')) { $c = pll_current_language('slug'); if($c) return $c; }
            if (function_exists('trp_get_current_language')) { $t = trp_get_current_language(); if($t) return $t; }
        }
        return 'en';
    }

    /* ---------- UI bits: Metabox & Media modal ---------- */
    public function add_metabox() {
        add_meta_box('lieska-mlat-box', __('AI Alt Text (Free, single-lang)', LIESKA_MLAT_TEXTDOMAIN), [$this,'render_metabox'], 'attachment', 'side', 'high');
    }
    public function render_metabox($post){
        wp_nonce_field(self::NONCE, self::NONCE);
        ?>
        <div id="lieska-mlat-free">
            <label style="display:block;margin:6px 0;">
                <input type="checkbox" id="lieska-free-overwrite" /> <?php esc_html_e('Overwrite existing alt', LIESKA_MLAT_TEXTDOMAIN); ?>
            </label>
            <button type="button" class="button button-primary" id="lieska-free-generate" data-attachment="<?php echo esc_attr($post->ID); ?>">
                <?php esc_html_e('Generate now (current admin language)', LIESKA_MLAT_TEXTDOMAIN); ?>
            </button>
            <p id="lieska-free-status" style="margin-top:8px;"></p>
        </div>
        <?php
    }
    public function enqueue_attachment_screen($hook){
        if ($hook !== 'post.php' && $hook !== 'post-new.php') return;
        $screen = get_current_screen(); if (!$screen || $screen->post_type!=='attachment') return;
        wp_register_script('lieska-mlat-free-js', false, ['jquery'], LIESKA_MLAT_VERSION, true);
        wp_enqueue_script('lieska-mlat-free-js');
        wp_localize_script('lieska-mlat-free-js','LieskaFree',[
            'ajaxUrl'=>admin_url('admin-ajax.php'),
            'nonce'=>wp_create_nonce(self::NONCE),
            'i18n'=>['working'=>__('Generating…',LIESKA_MLAT_TEXTDOMAIN),'error'=>__('Error',LIESKA_MLAT_TEXTDOMAIN),'done'=>__('Done',LIESKA_MLAT_TEXTDOMAIN)]
        ]);
        $inline = <<<JS
jQuery(function($){
  $('#lieska-free-generate').on('click', async function(){
    const btn=$(this), id=btn.data('attachment'), ow=$('#lieska-free-overwrite').is(':checked')?1:0, s=$('#lieska-free-status');
    btn.prop('disabled',true); s.text(LieskaFree.i18n.working);
    try{
      const data = await $.post(LieskaFree.ajaxUrl, {action:'lieska_generate_alt_text', nonce:LieskaFree.nonce, attachment_id:id, overwrite:ow}, null, 'json');
      if(data && data.ok){ s.text(LieskaFree.i18n.done + (data.details?(': '+data.details):'')); }
      else { s.text(LieskaFree.i18n.error + ': ' + (data && data.message ? data.message : 'Unknown')); }
    }catch(e){ s.text(LieskaFree.i18n.error + ': ' + (e.responseJSON && e.responseJSON.message ? e.responseJSON.message : e.toString())); }
    btn.prop('disabled',false);
  });
});
JS;
        wp_add_inline_script('lieska-mlat-free-js',$inline);
    }

    public function enqueue_media_modal_script() {
        wp_register_script('lieska-mlat-media-free', false, ['jquery','media-views'], LIESKA_MLAT_VERSION, true);
        wp_enqueue_script('lieska-mlat-media-free');
        wp_localize_script('lieska-mlat-media-free','LieskaMediaFree',[
            'ajaxUrl'=>admin_url('admin-ajax.php'),
            'nonce'=>wp_create_nonce(self::NONCE),
            'i18n'=>['generate'=>__('Generate AI Alt (current admin language)',LIESKA_MLAT_TEXTDOMAIN),'working'=>__('Generating…',LIESKA_MLAT_TEXTDOMAIN),'error'=>__('Error',LIESKA_MLAT_TEXTDOMAIN)]
        ]);
        $inline = <<<JS
jQuery(function($){
  function inject(){
    var target=$('.media-modal .attachment-details .setting[data-setting="alt"]');
    if(!target.length || target.find('.lieska-free-btn').length) return;
    var btn=$('<button type="button" class="button lieska-free-btn" style="margin-top:6px;"></button>').text(LieskaMediaFree.i18n.generate);
    var status=$('<div class="description" style="margin-top:6px;"></div>');
    btn.on('click', async function(){
      try{
        var sel=wp.media.frame && wp.media.frame.state() && wp.media.frame.state().get('selection');
        var m=sel && sel.first && sel.first(); var id=m && m.get && m.get('id'); if(!id) return;
        btn.prop('disabled',true); status.text(LieskaMediaFree.i18n.working);
        const data = await $.post(LieskaMediaFree.ajaxUrl, {action:'lieska_generate_alt_text', nonce:LieskaMediaFree.nonce, attachment_id:id, overwrite:1}, null, 'json');
        if(data && data.ok && data.alt){
          var ta=target.find('textarea, input[type="text"]'); ta.val(data.alt).trigger('change');
          status.text('✓');
        } else {
          status.text(LieskaMediaFree.i18n.error + ': ' + (data && data.message ? data.message : 'Unknown'));
        }
      }catch(e){ status.text(LieskaMediaFree.i18n.error + ': ' + (e.responseJSON && e.responseJSON.message ? e.responseJSON.message : e.toString())); }
      btn.prop('disabled',false);
    });
    target.append(btn).append(status);
  }
  $(document).on('click', '.media-modal, .supports-drag-drop, .attachments .attachment, .media-frame-router .media-menu-item, .attachment .check, .set-post-thumbnail, .editor-post-featured-image__toggle, .insert-media', function(){ setTimeout(inject, 120); });
});
JS;
        wp_add_inline_script('lieska-mlat-media-free',$inline);
    }
}

$GLOBALS['lieska_mlat_free'] = new Lieska_MLAT_Free();

/* ======= Pro laajentaa tätä hookia asetussivullaan ======= */
