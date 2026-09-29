<?php
// SPDX-License-Identifier: GPL-2.0-or-later
chdir('../../');
require('./include/auth.php');
require_once(__DIR__ . '/lib/functions.php');

if (!api_user_realm_auth('cereus_datasync.php')) {
    header('Location: ../../index.php');
    exit;
}

// Anchor to jump to, e.g. "7-tree-placement-rules". Only the characters a
// heading anchor can contain are kept; it is used in markup and in JS.
$section = preg_replace('/[^a-z0-9-]/', '', strtolower(get_nfilter_request_var('section', '')));

top_header();

html_start_box(__('Cereus Data Sync — Help', 'cereus_datasync'), '100%', '', '3', 'center', '');
print '<tr><td class="cds-help-cell">';
print '<div class="cds-help-back"><a href="cereus_datasync.php" class="cds-link">&laquo; ' . __('Back to Profiles', 'cereus_datasync') . '</a></div>';
print '<div class="cds-help" id="cds-help">' . cereus_datasync_help_html() . '</div>';
print '</td></tr>';
html_end_box();

?>
<script type="text/javascript">
$(function() {
    // Cacti loads pages over AJAX, so in-page "#anchor" links and URL fragments
    // do not scroll by themselves. Scroll within the help instead.
    function cdsHelpScrollTo(id) {
        var target = document.getElementById(id);
        if (target) {
            target.scrollIntoView({ block: 'start' });
        }
    }

    $('#cds-help').on('click', 'a[href^="#"]', function(e) {
        e.preventDefault();
        e.stopPropagation();
        cdsHelpScrollTo($(this).attr('href').substring(1));
    });

    var section = '<?php print $section; ?>';
    if (section !== '') {
        setTimeout(function() { cdsHelpScrollTo(section); }, 50);
    }
});
</script>
<?php

bottom_footer();

/**
 * HELP.md rendered to HTML. Parsedown runs in safe mode, and every heading gets
 * the GitHub-style anchor id the document's own contents links use
 * ("## 7. Tree placement rules" -> "7-tree-placement-rules").
 */
function cereus_datasync_help_html(): string {
    $file = __DIR__ . '/HELP.md';
    if (!is_readable($file)) {
        return '<p>' . __('The help file (HELP.md) is missing from the plugin directory.', 'cereus_datasync') . '</p>';
    }

    require_once __DIR__ . '/vendor/autoload.php';

    $parser = new Parsedown();
    $parser->setSafeMode(true);
    $html = $parser->text((string)file_get_contents($file));

    return preg_replace_callback('#<h([1-4])>(.*?)</h\1>#s', function ($m) {
        $slug = strtolower(html_entity_decode(strip_tags($m[2]), ENT_QUOTES, 'UTF-8'));
        $slug = preg_replace('/[^\p{L}\p{N} -]/u', '', $slug);
        $slug = str_replace(' ', '-', trim($slug));

        return '<h' . $m[1] . ' id="' . html_escape($slug) . '">' . $m[2] . '</h' . $m[1] . '>';
    }, $html);
}
