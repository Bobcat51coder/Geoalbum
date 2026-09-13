<?php
/*
 * Page "Paramètres" (admin.php?page=plugin-geoalbum), attendue par Piwigo
 * du fait de "Has Settings: true" dans main.inc.php. La gestion des zones se
 * fait sur sa page dédiée (geoalbum.php) ; cette page ne gère qu'un seul
 * réglage : la clé API CartoDB. Si une clé est renseignée côté OSM Map Plus,
 * elle est utilisée en priorité (peu importe si ce plugin est actif ou non) ;
 * sinon la clé propre saisie ici est utilisée — ce qui rend Geo Album
 * autonome, sans dépendance obligatoire à OSM Map Plus.
 */
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');
check_status(ACCESS_ADMINISTRATOR);

global $template, $page, $conf;

if (!defined('GAB_FOLDER')) define('GAB_FOLDER', basename(dirname(__FILE__)));

// Supprime espaces classiques ET caractères Unicode invisibles fréquemment
// collés lors d'un copier-coller (U+2028/U+2029 séparateurs de ligne,
// U+200B espace de largeur nulle, U+FEFF BOM) — un trim() PHP seul ne les
// retire pas, et CartoDB rejette silencieusement une clé ainsi corrompue.
function gab_clean_key($key)
{
    $key = preg_replace('/[\x{2028}\x{2029}\x{200B}\x{FEFF}]/u', '', (string)$key);
    return trim($key);
}

$gab_manage_url   = get_root_url() . 'plugins/' . GAB_FOLDER . '/geoalbum.php';
$gab_help_img_url = get_root_url() . 'plugins/' . GAB_FOLDER . '/template/images/screenshot1.jpg';

$gab_infos = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['geoalbum_carto_api_key'])) {
    conf_update_param('geoalbum_carto_api_key', gab_clean_key($_POST['geoalbum_carto_api_key']));
    $gab_infos[] = 'Clé API enregistrée.';
}

$gab_own_key = gab_clean_key($conf['geoalbum_carto_api_key'] ?? '');
$gab_osm_key = gab_clean_key($conf['osm_map_carto_api_key'] ?? '');
// Clé effectivement utilisée par la carte, selon la même priorité que geoalbum.php
$gab_effective_source = ($gab_osm_key !== '') ? 'osm_map' : (($gab_own_key !== '') ? 'geoalbum' : 'none');

// Titre explicite affiché dans l'onglet du navigateur et l'en-tête admin
// (sinon Piwigo affiche un titre générique "Piwigo Administration Page").
$page['title'] = 'Geo Album v' . GAB_VERSION;

$template->set_filenames(array(
    'plugin_admin_content' => dirname(__FILE__) . '/template/admin.tpl',
));

$template->assign(array(
    'GAB_VERSION'          => GAB_VERSION,
    'GAB_MANAGE_URL'       => $gab_manage_url,
    'GAB_HELP_IMG_URL'     => $gab_help_img_url,
    'GAB_OWN_KEY'          => $gab_own_key,
    'GAB_EFFECTIVE_SOURCE' => $gab_effective_source,
    'GAB_INFOS'            => $gab_infos,
));

// Indispensable : sans cet appel, le template est enregistré mais jamais
// réellement inséré dans la page (contenu vide malgré aucune erreur).
$template->assign_var_from_handle('ADMIN_CONTENT', 'plugin_admin_content');
