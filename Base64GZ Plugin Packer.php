<?php
/**
 * Base64GZ Plugin Encoder — Textpattern admin plugin
 * - Extensions panel: convert a template-style PHP plugin to a .txt payload:
 *   header + (Base64GZ | Base64) of the serialized $plugin, wrapped @72 and also single line
 * - Admin-only, POST-only actions. No execution of pasted code.
 * - Large safety cap (2 MB).
 */

$plugin['version']      = '1';
$plugin['type']         = '5'; // admin-side
$plugin['name']         = 'vis_base64gz_plugin_packer';
$plugin['author']       = 'C.S. Wilson';
$plugin['author_uri']   = 'https://www.hobbiesfordays.com';
$plugin['description']  = 'Base64GZ Plugin Encoder: build a plugin .txt from php in a Textpattern template.';
$plugin['order']        = '5';
$plugin['flags']        = '0';

if (!defined('txpinterface')) @include_once('zem_tpl.php');

if (0) {
?>
# --- BEGIN PLUGIN HELP ---
h1. Base64GZ Plugin Encoder

* Paste a full template-style PHP plugin.
* Choose *Base64GZ* (default) or *Base64*.
* Click *Convert* to preview the header and payload (wrapped @72 and single-line).
* Click *Download .txt* to save the installer.

# --- END PLUGIN HELP ---
<?php
}

# --- BEGIN PLUGIN CODE ---
if (txpinterface === 'admin') {
    new VIS_base64gz_plugin_encoder_admin();
}

class VIS_base64gz_plugin_encoder_admin
{
    protected string $event = __CLASS__;
    protected int $MAX_BYTES = 2097152; // 2 MB cap

    public function __construct()
    {
        add_privs($this->event, '1'); // Publishers+
        register_tab('extensions', $this->event, 'Base64GZ Plugin Encoder');
        register_callback([$this, 'dispatch'], $this->event);
        register_callback([$this, 'plugins_banner'], 'plugin', 'list'); // optional pointer from Plugins list
    }

    public function dispatch($evt, $step)
    {
        $step = $step ?: 'list';
        if ($step === 'convert'  && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') return $this->convert();
        if ($step === 'download' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') return $this->download();
        return $this->list();
    }

    /* ---------- screens ---------- */

    public function list()
    {
        pagetop('Base64GZ Plugin Encoder');
        echo $this->style();
        echo $this->scripts();
        echo '<div class="vis-pack">';
        echo '<h1>Base64GZ Plugin Encoder</h1>';
        echo $this->form('', 'b64gz');
        echo '<p><small>Paste a template-style plugin (with BEGIN/END markers). Nothing is executed. Limit: ~2&nbsp;MB.</small></p>';
        echo '</div>';
    }

    public function convert()
    {
        pagetop('Base64GZ Plugin Encoder');
        echo $this->style();
        echo $this->scripts();
        echo '<div class="vis-pack">';

        $src  = (string) ps('plugin_php');
        $enc  = (string) ps('enc') ?: 'b64gz';
        $errs = $this->validate($src);

        echo '<h1>Base64GZ Plugin Encoder</h1>';
        echo $this->form($src, $enc);

        if ($errs) {
            $this->errors($errs);
            echo '</div>';
            return;
        }

        $r = $this->build($src, $enc);
        $this->result($r, $enc);
        echo '</div>';
    }

    public function download()
    {
        $src  = (string) ps('plugin_php');
        $enc  = (string) ps('enc') ?: 'b64gz';
        $errs = $this->validate($src);
        if ($errs) {
            return $this->list();
        }

        $r    = $this->build($src, $enc);
        $safe = preg_replace('/[^a-z0-9._-]+/i', '-', $r['meta']['name']).'-'.$r['meta']['version'].'.txt';

        header('Content-Type: text/plain; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$safe.'"');
        echo $r['txt'];
        exit;
    }

    /* ---------- plugins page banner ---------- */

    public function plugins_banner()
    {
        $url = 'index.php?event=' . urlencode($this->event);
        echo '<div style="margin:8px 16px 12px;padding:8px 10px;border:1px solid #d0d7de;border-radius:6px;background:#f6f8fa">
                <strong>Base64GZ Plugin Encoder:</strong> <a href="'.txpspecialchars($url).'">Open encoder panel</a>
              </div>';
    }

    /* ---------- helpers ---------- */

    protected function style(): string
    {
        return <<<CSS
<style>
.vis-pack{max-width:960px;margin:0px auto 24px;padding:0 16px}
.vis-pack h1{font-size:18px;margin:0 0 12px}
.vis-pack label{display:block;margin:8px 0 6px;font-weight:600}
#vis_src{
  width:100% !important;
  height:280px !important;
  min-height:0 !important;
  resize:none !important;
  overflow:auto !important;
  box-sizing:border-box !important;
  font:12px ui-monospace,Consolas,Monaco,monospace !important;
  padding:10px !important;
  border:1px solid #d0d7de !important;
  border-radius:8px !important;
}
.vis-pack .row{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:10px 0 14px}
.vis-pack small{color:#666}
.vis-pack .btn{
  display:inline-flex; align-items:center; justify-content:center;
  padding:.55rem .95rem;
  line-height:1; vertical-align:middle;
  border:1px solid #d0d7de; border-radius:8px; background:#fff; cursor:pointer;
}
.vis-pack .errors{background:#fee;border:1px solid #f99;padding:10px;border-radius:6px;margin-top:10px}
.vis-out{
  width:100% !important;
  height:200px !important;
  min-height:0 !important;
  resize:none !important;
  overflow:auto !important;
  font:12px ui-monospace,Consolas,Monaco,monospace !important;
  padding:10px !important;
  border:1px solid #d0d7de !important;
  border-radius:8px !important;
  box-sizing:border-box !important;
}
.vis-pack .bar{
  display:flex; align-items:center; justify-content:space-between;
  gap:12px; margin-top:16px;
}
.vis-pack .controls{display:flex; align-items:center; gap:8px}
.copy-msg{
  font-size:12px; color:#2e7d32; opacity:0; transition:opacity .2s ease;
}
.copy-msg.err{color:#b00020}
.copy-msg.show{opacity:1}
</style>
CSS;
    }

    protected function scripts(): string
    {
        return <<<JS
<script>
function visCopy(textId, statusId){
  var el = document.getElementById(textId);
  var st = document.getElementById(statusId);
  if(!el){ return; }
  var txt = (el.value !== undefined) ? el.value : (el.textContent || '');
  function show(msg, isErr){
    if(!st) return;
    st.textContent = msg;
    st.classList.remove('ok','err','show');
    st.classList.add(isErr ? 'err' : 'ok', 'show');
    clearTimeout(st._t);
    st._t = setTimeout(function(){ st.classList.remove('show'); }, 1200);
  }
  function fallback(){
    if(el.select){ el.focus(); el.select(); }
    var ok = false;
    try{ ok = document.execCommand('copy'); }catch(e){}
    if(el.blur) el.blur();
    show(ok ? 'Copied!' : 'Copy failed', !ok);
  }
  if(navigator.clipboard && window.isSecureContext){
    navigator.clipboard.writeText(txt).then(function(){ show('Copied!', false); }).catch(fallback);
  }else{
    fallback();
  }
}
</script>
JS;
    }

    protected function form(string $src, string $enc): string
    {
        $srcEsc = txpspecialchars($src, false);
        $action = 'index.php?event=' . urlencode($this->event);

        $b64gzChecked = $enc === 'b64gz' ? 'checked' : '';
        $b64Checked   = $enc === 'b64'   ? 'checked' : '';

        return <<<HTML
<form method="post" action="{$action}" autocomplete="off">
  <input type="hidden" name="step" value="convert">
  <label for="vis_src"><strong>Paste your template-style plugin PHP</strong></label>
  <textarea id="vis_src" name="plugin_php" required placeholder="Paste plugin.php here...">{$srcEsc}</textarea>

  <div class="row">
    <label><input type="radio" name="enc" value="b64gz" {$b64gzChecked}> Base64GZ (recommended)</label>
    <label><input type="radio" name="enc" value="b64" {$b64Checked}> Base64</label>
  </div>

  <div class="row">
    <button class="btn" type="submit" onclick="this.form.step.value='convert'">Convert</button>
    <button class="btn" type="submit" onclick="this.form.step.value='download'">Download .txt</button>
  </div>
</form>
HTML;
    }

    protected function errors(array $errs): void
    {
        echo '<div class="errors"><strong>Errors:</strong><ul>';
        foreach ($errs as $e) echo '<li>'.txpspecialchars($e).'</li>';
        echo '</ul></div>';
    }

    protected function result(array $r, string $enc): void
    {
        $modeLabel = ($enc === 'b64gz') ? 'Payload (Base64GZ, wrapped @72)' : 'Payload (Base64 only, wrapped @72)';
        $hdrEsc  = txpspecialchars($r['header']);
        $wrapEsc = txpspecialchars($r['wrapped']);
        $rawEsc  = txpspecialchars($r['raw']);

        // Header
        echo '<div class="bar"><h3>Header</h3>
                <div class="controls">
                  <button class="btn" type="button" onclick="visCopy(\'vis_hdr\',\'vis_status_hdr\')">Copy</button>
                  <span id="vis_status_hdr" class="copy-msg"></span>
                </div>
              </div>';
        echo '<textarea readonly id="vis_hdr" class="vis-out">'.$hdrEsc.'</textarea>';

        // Wrapped
        echo '<div class="bar"><h3>'.$modeLabel.'</h3>
                <div class="controls">
                  <button class="btn" type="button" onclick="visCopy(\'vis_wrap\',\'vis_status_wrap\')">Copy</button>
                  <span id="vis_status_wrap" class="copy-msg"></span>
                </div>
              </div>';
        echo '<textarea readonly id="vis_wrap" class="vis-out">'.$wrapEsc.'</textarea>';

        // Raw
        echo '<div class="bar"><h3>Payload (single line)</h3>
                <div class="controls">
                  <button class="btn" type="button" onclick="visCopy(\'vis_raw\',\'vis_status_raw\')">Copy</button>
                  <span id="vis_status_raw" class="copy-msg"></span>
                </div>
              </div>';
        echo '<textarea readonly id="vis_raw" class="vis-out">'.$rawEsc.'</textarea>';

        echo '<p><small>code_md5: '.txpspecialchars($r['hash']).'</small></p>';
    }

    protected function validate(string $src): array
    {
        $e = [];
        if ($src === '') $e[] = 'No input provided.';
        if (strlen($src) > $this->MAX_BYTES) $e[] = 'Input exceeds 2 MB.';
        return $e;
    }

    protected function build(string $src, string $enc): array
    {
        $blocks = $this->extract_blocks($src);
        $meta   = $this->parse_meta($src);

        // Verbatim HELP/TEXTPACK/DATA (BOM strip + EOL normalize only)
        $help     = $this->verbatim_block($blocks['HELP'] ?? '');
        $textpack = $this->verbatim_block($blocks['TEXTPACK'] ?? '');
        $data     = $this->verbatim_block($blocks['DATA'] ?? '');

        // If no DATA block, try to read a literal $plugin['data'] assignment (HEREDOC/NOWDOC or quoted)
        if ($data === '') {
            $data = $this->extract_data_literal($src);
        }

        // Defaults
        $meta['name']        = $meta['name']        !== '' ? $meta['name']        : 'my_plugin';
        $meta['version']     = $meta['version']     !== '' ? $meta['version']     : '0.1';
        $meta['author']      = $meta['author']      ?? '';
        $meta['author_uri']  = $meta['author_uri']  ?? '';
        $meta['description'] = $meta['description'] ?? '';
        $meta['type']        = $meta['type']        !== '' ? $meta['type']        : '5';
        $meta['order']       = $meta['order']       !== '' ? $meta['order']       : '5';
        $meta['flags']       = $meta['flags']       !== '' ? $meta['flags']       : '0';

        // CODE: use block if present, else entire source
        $code = ($blocks['CODE'] ?? '') !== '' ? rtrim($blocks['CODE'], "\r\n") : trim($src);

        $plugin = [
            'name'       => $meta['name'],
            'version'    => $meta['version'],
            'author'     => $meta['author'],
            'author_uri' => $meta['author_uri'],
            'description'=> $meta['description'],
            'type'       => (int)$meta['type'],
            'order'      => (int)$meta['order'],
            'flags'      => (int)$meta['flags'],

            // Help via Textile in admin
            'help_raw'   => rtrim($help, "\n"),
            'help'       => '',

            'textpack'   => rtrim($textpack, "\n"),
            'code'       => $code,
        ];

        // Only attach data if provided
        if ($data !== '') {
            $plugin['data'] = rtrim($data, "\n");
        }

        if (isset($meta['allow_html_help'])) {
            $plugin['allow_html_help'] = (int)$meta['allow_html_help'];
        }

        $ser = serialize($plugin);

        if ($enc === 'b64gz') {
            $gz      = gzencode($ser, 9);
            $payload = base64_encode($gz);
        } else {
            $payload = base64_encode($ser);
        }

        $wrapped = chunk_split($payload, 72, "\n");
        $header  = $this->build_header($meta);
        $txt     = $header . $wrapped;

        return [
            'header'  => $header,
            'wrapped' => $wrapped,
            'raw'     => $payload,
            'txt'     => $txt,
            'meta'    => $meta,
            'hash'    => md5($code),
        ];
    }

    /* ---------- parsing utilities ---------- */

    protected function extract_blocks(string $src): array
    {
        $lines  = preg_split("/\r\n|\n|\r/", $src);
        $blocks = ['CODE'=>'','HELP'=>'','TEXTPACK'=>'','DATA'=>''];
        $state  = null;

        foreach ($lines as $line) {
            $probe = strtoupper(ltrim($line));
            $probe = preg_replace('/^([#\/\*]+\s*)+/', '', $probe);

            if ($state === null) {
                if (strpos($probe, 'BEGIN PLUGIN CODE')     !== false) { $state = 'CODE';     continue; }
                if (strpos($probe, 'BEGIN PLUGIN HELP')     !== false) { $state = 'HELP';     continue; }
                if (strpos($probe, 'BEGIN PLUGIN TEXTPACK') !== false) { $state = 'TEXTPACK'; continue; }
                if (strpos($probe, 'BEGIN PLUGIN DATA')     !== false) { $state = 'DATA';     continue; }
            } else {
                if (strpos($probe, 'END PLUGIN '.$state) !== false) { $state = null; continue; }
                $blocks[$state] .= $line."\n";
            }
        }

        return $blocks;
    }

    protected function parse_meta(string $src): array
    {
        $keys = ['name','version','author','author_uri','description','type','order','flags'];
        $out  = array_fill_keys($keys, '');

        foreach ($keys as $k) {
            if (preg_match('/\\$plugin\\[\\s*(?:\'|")'.$k.'(?:\'|")\\s*\\]\\s*=\\s*(["\'])(.*?)\\1\\s*;?/si', $src, $m)) {
                $out[$k] = str_replace(['\\"',"\\'"], ['"',"'"], trim($m[2]));
            }
        }

        if (preg_match('/\\$plugin\\[\\s*(?:\'|")allow_html_help(?:\'|")\\s*\\]\\s*=\\s*(\\d+)\\s*;/', $src, $m)) {
            $out['allow_html_help'] = (int) $m[1];
        }

        return $out;
    }

    protected function build_header(array $m): string
    {
        $type  = (int)($m['type']  !== '' ? $m['type']  : 5);
        $order = (int)($m['order'] !== '' ? $m['order'] : 5);
        $flags = (int)($m['flags'] !== '' ? $m['flags'] : 0);

        return "# Name: {$m['name']}\n"
             . "# Type: {$type}\n"
             . "# Author: {$m['author']}\n"
             . "# URL: {$m['author_uri']}\n"
             . "# Version: {$m['version']}\n"
             . "# Description: {$m['description']}\n"
             . "# Order: {$order}\n"
             . "# Flags: {$flags}\n\n";
    }

    protected function verbatim_block(string $s): string
    {
        if ($s === '') return '';
        $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
        return str_replace(["\r\n", "\r"], "\n", $s);
    }

    protected function extract_data_literal(string $src): string
    {
        // HEREDOC/NOWDOC: $plugin['data'] = <<<TAG ... TAG;
        if (preg_match('/\\$plugin\\[\\s*(?:\'|")data(?:\'|")\\s*\\]\\s*=\\s*<<<[ \\t]*(?:["\']?)([A-Z0-9_]+)(?:["\']?)\\R(.*?)\\R\\1;?/si', $src, $m)) {
            return $this->verbatim_block($m[2]);
        }
        // Quoted string: $plugin['data'] = '...';  (kept verbatim; no variable expansion)
        if (preg_match('/\\$plugin\\[\\s*(?:\'|")data(?:\'|")\\s*\\]\\s*=\\s*(["\'])(.*?)\\1\\s*;?/s', $src, $m)) {
            return str_replace(["\r\n", "\r"], "\n", $m[2]);
        }
        return '';
    }
}
# --- END PLUGIN CODE ---
