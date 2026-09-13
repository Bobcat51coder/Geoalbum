<?php
/*
Plugin Name: Geo Album
Version: 4.0.15
Description: Gestion simple des albums géographiques (zones GPS) sans SmartAlbums — associations photo/album gérées directement via image_category. Fonctionne de façon autonome ou en complément d'OSM Map Plus (partage sa clé API CartoDB).
Author: Bobcat-Fr
Author URI:
Has Settings: true
*/
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

define('GAB_DIR',     dirname(__FILE__));
define('GAB_PATH',    GAB_DIR . '/');
define('GAB_FOLDER',  basename(GAB_DIR));
define('GAB_VERSION', '4.0.15');

global $prefixeTable;
if (!defined('GAB_TABLE')) define('GAB_TABLE', $prefixeTable . 'geo_zones');

include_once(GAB_PATH . 'include/geo_functions.php');
include_once(GAB_PATH . 'include/db.php');

/* ── Table ──────────────────────────────────────────────────────────────── */
add_event_handler('activate_plugin',   'gab_install');
add_event_handler('deactivate_plugin', 'gab_uninstall');

function gab_install()
{
    pwg_query('CREATE TABLE IF NOT EXISTS ' . GAB_TABLE . ' (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        album_id    INT UNSIGNED NOT NULL UNIQUE,
        name        VARCHAR(255) NOT NULL,
        zone_type   ENUM("bbox","polygon") NOT NULL DEFAULT "bbox",
        coordinates LONGTEXT NOT NULL,
        active      TINYINT(1) NOT NULL DEFAULT 1,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_album (album_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function gab_uninstall()
{
    // Vider les associations créées par ce plugin avant de supprimer la table
    $ids = array();
    $res = pwg_query('SELECT album_id FROM ' . GAB_TABLE);
    while ($r = pwg_db_fetch_assoc($res)) $ids[] = (int)$r['album_id'];
    if (!empty($ids)) {
        pwg_query('DELETE FROM ' . IMAGE_CATEGORY_TABLE
            . ' WHERE category_id IN(' . implode(',', $ids) . ')');
    }
    pwg_query('DROP TABLE IF EXISTS ' . GAB_TABLE);
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
        if (gab_point_in_zone($gps, $zone['zone_type'], $coords)) {
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
