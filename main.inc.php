<?php
/*
Plugin Name: Geo Album
Version: 1.0.7
Description: Gestion simple des albums géographiques (zones GPS) sans SmartAlbums — associations photo/album gérées directement via image_category. Fonctionne de façon autonome ou en complément d'OSM Map Plus (partage sa clé API CartoDB).
Plugin URI: https://piwigo.org/ext/extension_view.php?eid=1112
Author: Bobcat-Fr
Author URI: https://github.com/Bobcat51coder/Geoalbum
Has Settings: true
*/
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

define('GAB_DIR',     dirname(__FILE__));
define('GAB_PATH',    GAB_DIR . '/');
define('GAB_FOLDER',  basename(GAB_DIR));
define('GAB_VERSION', '1.0.7');

global $prefixeTable;
if (!defined('GAB_TABLE')) define('GAB_TABLE', $prefixeTable . 'geo_zones');
if (!defined('GAB_MEMBERS_TABLE')) define('GAB_MEMBERS_TABLE', $prefixeTable . 'geo_zone_photos');

include_once(GAB_PATH . 'include/geo_functions.php');
include_once(GAB_PATH . 'include/db.php');

/* ── Tables ─────────────────────────────────────────────────────────────── */
// L'installation et la désinstallation sont gérées par Piwigo via maintain.class.php
// (Piwigo ne déclenche aucun événement "activate_plugin" / "deactivate_plugin").
// gab_install() reste appelée à chaque chargement comme filet de sécurité (idempotente) :
// elle couvre aussi une mise à jour faite en remplaçant simplement les fichiers.
function gab_install()
{
    gab_create_tables();
}

/* ── Init : sync périodique + upload ───────────────────────────────────── */
add_event_handler('init', 'gab_init');
function gab_init()
{
    gab_install(); // idempotent

    global $conf;
    $last = isset($conf['gab_last_update']) ? (int)$conf['gab_last_update'] : 0;
    if (time() - $last > 3600) {
        conf_update_param('gab_last_update', time());
        gab_sync_all_zones();
    }
}

add_event_handler('picture_inserted', 'gab_on_insert');
function gab_on_insert($image_id)
{
    $gps = gab_get_gps($image_id);
    if (!$gps) return;
    $touched = array();
    foreach (gab_get_active_zones() as $zone) {
        $coords = json_decode($zone['coordinates'], true);
        if (gab_point_in_zone($gps, $zone['zone_type'], $coords)
            && gab_image_in_period($image_id, $zone)) {
            gab_insert_photo($image_id, (int)$zone['album_id']);
            $touched[(int)$zone['album_id']] = true;
        }
    }
    // Recalcule les compteurs des albums concernés (comme Maintenance > "Mettre à jour
    // les informations des catégories"), sinon la photo ajoutée n'apparaît qu'après une
    // synchronisation manuelle.
    foreach (array_keys($touched) as $album_id) gab_refresh($album_id);
}

/* ── Menu admin ─────────────────────────────────────────────────────────── */
add_event_handler('get_admin_plugin_menu_links', 'gab_admin_menu');
function gab_admin_menu($menu)
{
    $menu[] = array(
        'NAME' => 'Geo Album',
        'URL'  => get_root_url() . 'plugins/' . GAB_FOLDER . '/geoalbum.php',
    );
    return $menu;
}

/* ── Lien dans menu galerie (admin uniquement) ──────────────────────────── */
add_event_handler('loc_end_page_header', 'gab_inject_menu_link');
function gab_inject_menu_link()
{
    if (defined('IN_ADMIN') || !is_admin()) return;
    global $template;
    $url   = get_root_url() . 'plugins/' . GAB_FOLDER . '/geoalbum.php';
    $inner = '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '">&#127758; Albums g&eacute;o</a>';
    $js  = "document.addEventListener('DOMContentLoaded',function(){";
    $js .= "var mn=null,li,lists=document.querySelectorAll('dd ul');";
    $js .= "for(var i=0;i<lists.length;i++){if(lists[i].querySelector('a[href*=osmmap_plus]')){mn=lists[i];break;}}";
    $js .= "if(!mn)for(var i=0;i<lists.length;i++){if(lists[i].querySelector('a[href*=search],a[href*=about]')){mn=lists[i];break;}}";
    $js .= "if(!mn&&lists.length)mn=lists[lists.length-1];";
    $js .= "if(!mn||document.querySelector('a[href*=geoalbum]'))return;";
    $js .= "li=document.createElement('li');li.innerHTML=" . json_encode($inner) . ";mn.appendChild(li);});";
    $template->append('head_elements', '<script>' . $js . '</script>');
}
