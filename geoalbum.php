<?php
/*
 * Geo Album v1.0.3 — Page de gestion (admin uniquement)
 */
if (!defined('PHPWG_ROOT_PATH')) define('PHPWG_ROOT_PATH', '../../');

// Capturer tout output parasite de common.inc.php avant les réponses AJAX/JSON
// Charger Piwigo sans output parasite
ob_start();
include_once(PHPWG_ROOT_PATH . 'include/common.inc.php');
$_ob_content = ob_get_clean();  // capturer sans perdre

// Bloc AJAX : toujours répondre en JSON, jamais en HTML
if (!empty($_GET['ajax'])) {
    // Vider tout output résiduel
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');
    // Vérifier les droits APRÈS avoir positionné les headers JSON
    if (!is_admin()) { echo '[]'; exit; }
    // Le traitement AJAX suit ci-dessous...
}

if (empty($_GET['ajax'])) {
    if (!is_admin()) { redirect(make_index_url()); }
}

if (!defined('GAB_DIR'))     define('GAB_DIR',     dirname(__FILE__));
if (!defined('GAB_PATH'))    define('GAB_PATH',    GAB_DIR . '/');
if (!defined('GAB_FOLDER'))  define('GAB_FOLDER',  basename(GAB_DIR));
if (!defined('GAB_VERSION')) define('GAB_VERSION', '1.0.3');
global $prefixeTable;
if (!defined('GAB_TABLE')) define('GAB_TABLE', $prefixeTable . 'geo_zones');

include_once(GAB_PATH . 'include/geo_functions.php');
include_once(GAB_PATH . 'include/db.php');
global $conf;

// S'assurer que la table existe (au cas où activate n'a pas encore été appelé)
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

/* ── AJAX photos ─────────────────────────────────────────────────────────────
 * Format compact [[id, lat, lng], ...] — sans LIMIT, sans name/file
 * Le nom est chargé à la demande (au clic sur un marqueur) via ajax=photo_detail
 * ─────────────────────────────────────────────────────────────────────────── */
if (!empty($_GET['ajax']) && $_GET['ajax'] === 'photos') {
    // headers déjà envoyés dans le bloc auth ci-dessus
    $aid = (int)($_GET['album_id'] ?? 0);

    // Filtre période facultatif : dfield = creation (date de prise de vue,
    // colonne date_creation) ou available (date d'ajout, colonne date_available).
    // df / dt acceptent une année (1954), un mois (1954-06) ou un jour
    // (1954-06-21) ; saisie clavier libre, plus pratique que le calendrier du
    // navigateur pour les dates anciennes. Une seule des deux bornes suffit.
    $dfield = (($_GET['dfield'] ?? '') === 'available') ? 'date_available' : 'date_creation';

    // Normalise une saisie partielle vers une borne SQL complète.
    // Accepte le format ISO (1954, 1954-06, 1954-06-21) ET le format
    // français (21/06/1954, 21-06-1954, 06/1954) — l'année à 4 chiffres
    // sert de repère pour distinguer les deux sans ambiguïté.
    // $end=false -> début de période (1954 => 1954-01-01 00:00:00)
    // $end=true  -> fin de période  (1954 => 1954-12-31 23:59:59)
    $gab_bound = function($v, $end) {
        $v = trim((string)$v);
        if ($v === '') return '';
        $v = str_replace('.', '-', str_replace('/', '-', $v));

        // Année seule : 1954
        if (preg_match('/^(\d{4})$/', $v, $m))
            return $end ? $m[1].'-12-31 23:59:59' : $m[1].'-01-01 00:00:00';

        // ISO année-mois : 1954-06
        if (preg_match('/^(\d{4})-(\d{1,2})$/', $v, $m)) {
            $mo = (int)$m[2];
            if ($mo < 1 || $mo > 12) return '';
            $mo2 = str_pad($mo, 2, '0', STR_PAD_LEFT);
            if (!$end) return $m[1].'-'.$mo2.'-01 00:00:00';
            $last = (int)date('t', mktime(0,0,0,$mo,1,(int)$m[1]));
            return $m[1].'-'.$mo2.'-'.$last.' 23:59:59';
        }

        // ISO complet : 1954-06-21
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $v, $m)) {
            $mo = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $d  = str_pad($m[3], 2, '0', STR_PAD_LEFT);
            if (!checkdate((int)$mo, (int)$d, (int)$m[1])) return '';
            return $m[1].'-'.$mo.'-'.$d.($end ? ' 23:59:59' : ' 00:00:00');
        }

        // Français mois/année : 06-1954
        if (preg_match('/^(\d{1,2})-(\d{4})$/', $v, $m)) {
            $mo = (int)$m[1];
            if ($mo < 1 || $mo > 12) return '';
            $mo2 = str_pad($mo, 2, '0', STR_PAD_LEFT);
            if (!$end) return $m[2].'-'.$mo2.'-01 00:00:00';
            $last = (int)date('t', mktime(0,0,0,$mo,1,(int)$m[2]));
            return $m[2].'-'.$mo2.'-'.$last.' 23:59:59';
        }

        // Français complet jour/mois/année : 21-06-1954
        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $v, $m)) {
            $mo = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $d  = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            if (!checkdate((int)$mo, (int)$d, (int)$m[3])) return '';
            return $m[3].'-'.$mo.'-'.$d.($end ? ' 23:59:59' : ' 00:00:00');
        }

        return '';
    };

    $df = $gab_bound($_GET['df'] ?? '', false);
    $dt = $gab_bound($_GET['dt'] ?? '', true);
    $date_sql = '';
    if ($df !== '') $date_sql .= ' AND i.' . $dfield . ' >= \'' . $df . '\'';
    if ($dt !== '') $date_sql .= ' AND i.' . $dfield . ' <= \'' . $dt . '\'';
    // Sur la requête sans album, la table n'est pas aliasée "i"
    $date_sql_noalias = str_replace(' AND i.', ' AND ', $date_sql);

    if ($aid > 0) {
        $q = 'SELECT DISTINCT i.id, i.latitude, i.longitude'
           . ' FROM ' . IMAGES_TABLE . ' i'
           . ' INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' ic ON ic.image_id=i.id'
           . ' WHERE ic.category_id=' . $aid
           . ' AND i.latitude IS NOT NULL AND i.latitude!=0'
           . ' AND i.longitude IS NOT NULL AND i.longitude!=0'
           . $date_sql
           . ' ORDER BY i.id';
        $qmiss = 'SELECT COUNT(DISTINCT i.id) AS n'
           . ' FROM ' . IMAGES_TABLE . ' i'
           . ' INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' ic ON ic.image_id=i.id'
           . ' WHERE ic.category_id=' . $aid
           . ' AND i.latitude IS NOT NULL AND i.latitude!=0'
           . ' AND i.longitude IS NOT NULL AND i.longitude!=0'
           . ' AND i.date_creation IS NULL';
    } else {
        $q = 'SELECT DISTINCT id, latitude, longitude FROM ' . IMAGES_TABLE
           . ' WHERE latitude IS NOT NULL AND latitude!=0'
           . ' AND longitude IS NOT NULL AND longitude!=0'
           . $date_sql_noalias
           . ' ORDER BY id';
        $qmiss = 'SELECT COUNT(*) AS n FROM ' . IMAGES_TABLE
           . ' WHERE latitude IS NOT NULL AND latitude!=0'
           . ' AND longitude IS NOT NULL AND longitude!=0'
           . ' AND date_creation IS NULL';
    }
    $out = array();
    $res = pwg_query($q);
    while ($r = pwg_db_fetch_assoc($res))
        $out[] = array((int)$r['id'], round((float)$r['latitude'],6), round((float)$r['longitude'],6));

    // Nombre de photos géolocalisées (même périmètre album) sans date de prise
    // de vue renseignée — indépendant du filtre période lui-même, pour que ce
    // chiffre explique un éventuel "manque" de résultats quand on filtre sur
    // la date de prise de vue plutôt que la date d'ajout.
    $missing_date = (int)pwg_db_fetch_assoc(pwg_query($qmiss))['n'];

    echo json_encode(array('photos' => $out, 'missing_date' => $missing_date));
    exit;
}

/* ── AJAX détail photo (au clic sur un marqueur) ─────────────────────────── */
if (!empty($_GET['ajax']) && $_GET['ajax'] === 'photo_detail') {
    // headers déjà envoyés dans le bloc auth ci-dessus
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { echo '{}'; exit; }
    $r = pwg_db_fetch_assoc(pwg_query(
        'SELECT id, name, file, path FROM ' . IMAGES_TABLE . ' WHERE id=' . $id));
    if (!$r) { echo '{}'; exit; }
    $root_url = get_root_url();
    $title    = $r['name'] ?: pathinfo($r['file'], PATHINFO_FILENAME);
    // Construire l'URL absolue directement sans make_picture_url (qui génère des chemins relatifs)
    $page_url = $root_url . 'picture.php?/' . (int)$r['id'];
    echo json_encode(array('id'=>(int)$r['id'],'name'=>$title,'page_url'=>$page_url));
    exit;
}

/* ── Actions POST ─────────────────────────────────────────────────────────── */
$errors = array(); $infos = array();
if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['gab_action'])) {
    $action = $_POST['gab_action'];

    if ($action === 'save') {
        $zone_id  = (int)($_POST['zone_id']  ?? 0);
        $album_id = (int)($_POST['album_id'] ?? 0);
        $name     = trim(stripslashes($_POST['zone_name'] ?? ''));
        $type     = (($_POST['zone_type'] ?? '') === 'polygon') ? 'polygon' : 'bbox';
        $coords   = gab_validate_coords($_POST['coordinates'] ?? '', $type);
        if (!$name)   $errors[] = 'Nom obligatoire.';
        if (!$coords) $errors[] = 'Dessinez une zone sur la carte.';
        if (empty($errors) && $album_id === 0) {
            $parent_id = (int)($_POST['parent_id'] ?? 0);
            $album_id  = gab_create_album($name, $parent_id > 0 ? $parent_id : null);
            if ($album_id > 0) $infos[] = 'Album "'.htmlspecialchars($name).'" créé (ID '.$album_id.').';
            else $errors[] = 'Impossible de créer l\'album.';
        }
        if (empty($errors)) {
            if ($zone_id > 0) {
                gab_update_zone($zone_id, $name, $type, $coords);
                $infos[] = 'Zone mise à jour.';
            } else {
                $zone_id = gab_create_zone($album_id, $name, $type, $coords);
                $infos[] = 'Zone créée (ID '.$zone_id.').';
            }
            $zone = gab_get_zone($zone_id);
            $r    = gab_sync_zone($zone);
            $infos[] = 'Sync : +'.$r['added'].' / -'.$r['removed'].' photo(s).';
        }

    } elseif ($action === 'delete') {
        $id = (int)($_POST['zone_id'] ?? 0);
        if ($id > 0) { gab_delete_zone($id); $infos[] = 'Zone supprimée. Album conservé.'; }

    } elseif ($action === 'toggle') {
        $id = (int)($_POST['zone_id'] ?? 0);
        $active = !empty($_POST['active_val']);
        if ($id > 0) {
            gab_toggle_zone($id, $active);
            $infos[] = 'Zone '.($active?'activée':'désactivée').'.';
            if ($active) {
                // Rattraper les photos importées pendant que la zone était inactive
                $zone = gab_get_zone($id);
                if ($zone) {
                    $r = gab_sync_zone($zone);
                    if ($r['added'] > 0) $infos[] = 'Rattrapage : +'.$r['added'].' photo(s).';
                }
            }
        }

    } elseif ($action === 'sync_one') {
        $zone = gab_get_zone((int)($_POST['zone_id'] ?? 0));
        if ($zone) { $r = gab_sync_zone($zone); $infos[] = 'Sync : +'.$r['added'].' / -'.$r['removed'].'.'; }

    } elseif ($action === 'sync_all') {
        $ta = $tr = 0;
        foreach (gab_all_zones() as $z) {
            if (!$z['active']) continue;
            $r = gab_sync_zone($z); $ta += $r['added']; $tr += $r['removed'];
        }
        $infos[] = 'Tout resynchronisé : +'.$ta.' / -'.$tr.'.';
    }
}

/* ── Données ──────────────────────────────────────────────────────────────── */
$zones   = gab_all_zones();
$editing = !empty($_GET['edit']) ? gab_get_zone((int)$_GET['edit']) : null;
$albums  = array();
// Albums avec photos GPS directes OU dans leurs sous-albums
// Afficher le nom du parent pour distinguer les albums homonymes
$res = pwg_query(
    'SELECT DISTINCT c.id, c.name,
        (SELECT p.name FROM ' . CATEGORIES_TABLE . ' p WHERE p.id = c.id_uppercat) AS parent_name
     FROM ' . CATEGORIES_TABLE . ' c
     INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' ic ON ic.category_id = c.id
     INNER JOIN ' . IMAGES_TABLE . ' i ON i.id = ic.image_id
     WHERE i.latitude IS NOT NULL AND i.latitude != 0
     ORDER BY c.name, c.id'
);
while ($a = pwg_db_fetch_assoc($res)) {
    // Afficher "Nom (Parent)" si le parent existe, sinon juste "Nom"
    $a['display_name'] = $a['name'];
    if (!empty($a['parent_name'])) {
        $a['display_name'] = $a['name'] . ' (' . $a['parent_name'] . ')';
    }
    $albums[] = $a;
}
$nb_gps  = (int)pwg_db_fetch_assoc(pwg_query(
    'SELECT COUNT(*) AS n FROM '.IMAGES_TABLE.' WHERE latitude IS NOT NULL AND latitude!=0'))['n'];

// Clé API CartoDB — priorité à la clé d'OSM Map Plus si elle est renseignée
// (peu importe si ce plugin est actuellement actif ou non : seule la présence
// d'une valeur compte), sinon repli sur la clé propre de Geo Album, saisie
// sur SA page Paramètres (rend le plugin autonome, sans dépendre d'OSM Map Plus).
// Depuis le 26/08/2026, les tuiles basemaps.cartocdn.com nécessitent une clé.
$gab_clean = function($v) { return trim(preg_replace('/[\x{2028}\x{2029}\x{200B}\x{FEFF}]/u', '', (string)$v)); };
$gab_osm_key = $gab_clean($conf['osm_map_carto_api_key'] ?? '');
$gab_carto_key = ($gab_osm_key !== '') ? $gab_osm_key : $gab_clean($conf['geoalbum_carto_api_key'] ?? '');
$gab_carto_url = 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}.png';
if ($gab_carto_key !== '') {
    $gab_carto_url .= '?key=' . urlencode($gab_carto_key);
}

$TILES = array(
    'carto'     => array('label'=>'Carto',     'url'=>$gab_carto_url,   'attr'=>'&copy; OSM &copy; CARTO'),
    'osm'       => array('label'=>'OSM',       'url'=>'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',                     'attr'=>'&copy; OpenStreetMap'),
    'satellite' => array('label'=>'Satellite', 'url'=>'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}','attr'=>'&copy; Esri'),
    'topo'      => array('label'=>'Topo',      'url'=>'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',                       'attr'=>'&copy; OSM &copy; OpenTopoMap'),
);
$tile_key = 'carto';
if (!empty($_GET['tile']) && isset($TILES[$_GET['tile']])) $tile_key = $_GET['tile'];

$root     = get_root_url();
$self_url = $root . 'plugins/' . GAB_FOLDER . '/geoalbum.php';
$ajax_url = $self_url . '?ajax=photos&album_id=';
$zoom     = isset($conf['osm_map_zoom']) ? (int)$conf['osm_map_zoom'] : 5;
$edit_id  = $editing ? (int)$editing['id'] : 0;
$edit_data= $editing
    ? json_encode(array('coords'=>json_decode($editing['coordinates'],true),'type'=>$editing['zone_type']),JSON_HEX_TAG)
    : 'null';
global $pwg_loaded_plugins;
$osm_url = $root . 'plugins/osm_map/osmmap_plus.php';
?><!DOCTYPE html>
<html lang="fr"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Albums géo — Piwigo</title>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
<script src="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:sans-serif;background:#f0f0f0;font-size:14px;color:#333}
/* Topbar */
#tb{background:#fff;border-bottom:1px solid #ddd;padding:8px 14px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;position:sticky;top:0;z-index:1000}
#tb a{padding:5px 11px;border-radius:3px;text-decoration:none;font-size:13px;border:1px solid #bbb;background:#f5f5f5;color:#333}
.tbg{background:#188038!important;color:#fff!important;border-color:#0d5c28!important}
/* Layout */
#main{display:flex;height:calc(100vh - 46px)}
#mc{flex:1;display:flex;flex-direction:column;min-width:0}
/* Barre carte */
#mb{background:#fff;border-bottom:1px solid #ddd;padding:5px 10px;display:flex;align-items:center;gap:5px;flex-wrap:wrap;font-size:12px}
#mb button{padding:3px 9px;font-size:12px;border:1px solid #bbb;border-radius:3px;background:#f5f5f5;cursor:pointer}
#mb button.act{background:#1a73e8;color:#fff;border-color:#1558b0}
#mb button.del{background:#d93025;color:#fff;border-color:#b52d20}
#mb select{padding:3px 6px;font-size:12px;border:1px solid #bbb;border-radius:3px}
#mb .sep{color:#ccc;margin:0 4px}
#gi{font-size:11px;color:#777;font-style:italic;flex:1}
#gab-map{flex:1}
#ld{position:absolute;top:8px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,.65);color:#fff;padding:5px 14px;border-radius:12px;font-size:12px;display:none;z-index:999}
/* Panneau droit */
#pn{width:300px;min-width:260px;background:#fff;border-left:1px solid #ddd;overflow-y:auto;display:flex;flex-direction:column}
.sc{padding:10px 12px;border-bottom:1px solid #eee}
.sc h3{font-size:13px;font-weight:600;margin-bottom:7px}
.sc label{display:block;font-size:12px;color:#555;margin-bottom:2px;margin-top:4px}
.sc input[type=text],.sc select{width:100%;padding:4px 7px;border:1px solid #bbb;border-radius:3px;font-size:13px}
.br{display:flex;gap:5px;margin-top:7px;flex-wrap:wrap}
/* Boutons */
.btn{padding:4px 10px;font-size:12px;border:1px solid #bbb;border-radius:3px;background:#f5f5f5;cursor:pointer;text-decoration:none;color:#333}
.btn.p{background:#1a73e8;color:#fff;border-color:#1558b0}
.btn.d{background:#d93025;color:#fff;border-color:#b52d20}
.btn.g{background:#188038;color:#fff;border-color:#0d5c28}
.btn.a{background:#e37400;color:#fff;border-color:#b35c00}
/* Liste zones */
.zr{padding:8px 12px;border-bottom:1px solid #f0f0f0;font-size:12px}
.zr:hover{background:#fafafa}
.zn{font-weight:600;margin-bottom:2px}
.zm{color:#888;font-size:11px;margin-bottom:4px}
.za{display:flex;gap:3px;flex-wrap:wrap}
/* Messages */
.ok{background:#e6f4ea;border:1px solid #81c995;color:#1e4620;padding:7px 11px;border-radius:3px;margin:7px 12px;font-size:12px}
.er{background:#fce8e6;border:1px solid #f28b82;color:#7c1d11;padding:7px 11px;border-radius:3px;margin:7px 12px;font-size:12px}
/* Géocodeur (recherche de lieu) — mêmes classes qu'OSM Map Plus */
.osm-geocoder-wrapper{position:relative;display:inline-flex;align-items:center;gap:6px}
#gab-geocoder{width:170px;padding:3px 6px;font-size:12px;border:1px solid #bbb;border-radius:3px}
.osm-geocoder-dropdown{display:none;position:absolute;top:calc(100% + 4px);left:0;z-index:1100;background:#fff;border:1px solid #ccc;border-radius:6px;box-shadow:0 6px 18px rgba(0,0,0,.15);max-height:260px;overflow-y:auto;min-width:280px}
.osm-geo-item{padding:8px 12px;font-size:0.82rem;cursor:pointer;border-bottom:1px solid #f0f0f0;line-height:1.3}
.osm-geo-item:hover{background:#f0f6ff}
.osm-geo-empty{color:#888;cursor:default}
</style>
</head><body>

<div id="tb">
    <a href="<?=htmlspecialchars(make_index_url())?>">← Galerie</a>
    <?php if(isset($pwg_loaded_plugins['osm_map'])): ?>
    <a href="<?=htmlspecialchars($osm_url)?>" class="tbg">🗺 OSM Map Plus</a>
    <?php endif; ?>
    <strong>🌍 Albums géo</strong>
    <span style="color:#888;font-size:12px"><?=$nb_gps?> photos GPS</span>
</div>

<div id="main">
  <!-- Carte -->
  <div id="mc">
    <div id="mb">
      <button type="button" id="btn-bbox"    onclick="gabDraw('bbox')"    class="act">⬜ Rectangle</button>
      <button type="button" id="btn-polygon" onclick="gabDraw('polygon')">⬡ Polygone</button>
      <button type="button"                  onclick="gabClear()"          class="del">✕ Effacer</button>
      <button type="button"                  onclick="gabFit()">⊕ Centrer</button>
      <span class="osm-geocoder-wrapper">
        <input type="text" id="gab-geocoder" placeholder="🔎 Rechercher un lieu…" autocomplete="off">
        <div id="gab-geocoder-results" class="osm-geocoder-dropdown"></div>
      </span>
      <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer" title="Regrouper les points proches en bulles chiffrées (plus lisible, mais moins précis pour tracer une zone)">
        <input type="checkbox" id="chk-cluster" checked onchange="gabToggleCluster(this.checked)">
        Regrouper les points
      </label>
      <span class="sep">|</span>
      <select id="sel-datefield" style="width:115px" title="Sur quelle date porte le filtre">
        <option value="creation">📷 Prise de vue</option>
        <option value="available">📥 Date d'ajout</option>
      </select>
      <input type="text" id="dt-from" title="Depuis — année (1954), JJ/MM/AAAA (21/06/1954) ou 1954-06-21" placeholder="depuis (1954 ou 21/06/1954)" size="16" style="padding:2px 4px;font-size:12px;border:1px solid #bbb;border-radius:3px;width:150px">
      <input type="text" id="dt-to"   title="Jusqu'à — année (1960), JJ/MM/AAAA (15/08/1960) ou 1960-08-15" placeholder="jusqu'à (1960 ou 15/08/1960)" size="16" style="padding:2px 4px;font-size:12px;border:1px solid #bbb;border-radius:3px;width:150px">
      <button type="button" onclick="gabApplyDates()" title="Appliquer le filtre de période">Filtrer</button>
      <button type="button" onclick="gabResetAll()" title="Tout réinitialiser (album + période)">↺ Réinitialiser</button>
      <a href="<?=htmlspecialchars($root.'admin.php?page=plugin-'.GAB_FOLDER.'#filtres')?>" target="_blank" rel="noopener"
         title="Aide sur les filtres période et album"
         style="display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:#e8e8e8;color:#555;text-decoration:none;font-weight:bold;font-size:12px">?</a>
      <span id="dt-count" style="color:#888;font-size:12px"></span>
      <span class="sep">|</span>
      <select id="sel-continent" style="width:130px" title="Centrer sur un continent">
        <option value="">— Continent —</option>
        <option value="europe">🌍 Europe</option>
        <option value="namerica">🌎 Amér. Nord</option>
        <option value="samerica">🌎 Amér. Sud</option>
        <option value="africa">🌍 Afrique</option>
        <option value="asia">🌏 Asie</option>
        <option value="oceania">🌏 Océanie</option>
        <option value="world">🗺 Monde</option>
      </select>
      <select id="sel-country" style="width:150px" title="Centrer sur un pays">
        <option value="">— Pays —</option>
        <optgroup label="Europe">
          <option value="fr">France</option>
          <option value="gb">Royaume-Uni</option>
          <option value="ie">Irlande</option>
          <option value="de">Allemagne</option>
          <option value="it">Italie</option>
          <option value="es">Espagne</option>
          <option value="pt">Portugal</option>
          <option value="nl">Pays-Bas</option>
          <option value="be">Belgique</option>
          <option value="lu">Luxembourg</option>
          <option value="ch">Suisse</option>
          <option value="at">Autriche</option>
          <option value="pl">Pologne</option>
          <option value="cz">Tchéquie</option>
          <option value="sk">Slovaquie</option>
          <option value="hu">Hongrie</option>
          <option value="ro">Roumanie</option>
          <option value="bg">Bulgarie</option>
          <option value="gr">Grèce</option>
          <option value="se">Suède</option>
          <option value="no">Norvège</option>
          <option value="dk">Danemark</option>
          <option value="fi">Finlande</option>
          <option value="is">Islande</option>
          <option value="ee">Estonie</option>
          <option value="lv">Lettonie</option>
          <option value="lt">Lituanie</option>
          <option value="ua">Ukraine</option>
          <option value="hr">Croatie</option>
          <option value="rs">Serbie</option>
          <option value="si">Slovénie</option>
          <option value="ba">Bosnie-Herzégovine</option>
          <option value="al">Albanie</option>
          <option value="mk">Macédoine du Nord</option>
          <option value="me">Monténégro</option>
          <option value="mt">Malte</option>
          <option value="cy">Chypre</option>
        </optgroup>
        <optgroup label="Amérique du Nord">
          <option value="us">États-Unis</option>
          <option value="ca">Canada</option>
          <option value="mx">Mexique</option>
        </optgroup>
        <optgroup label="Amérique du Sud">
          <option value="br">Brésil</option>
          <option value="ar">Argentine</option>
          <option value="cl">Chili</option>
          <option value="pe">Pérou</option>
          <option value="co">Colombie</option>
          <option value="ve">Venezuela</option>
          <option value="uy">Uruguay</option>
          <option value="py">Paraguay</option>
          <option value="bo">Bolivie</option>
          <option value="ec">Équateur</option>
        </optgroup>
        <optgroup label="Afrique">
          <option value="ma">Maroc</option>
          <option value="dz">Algérie</option>
          <option value="tn">Tunisie</option>
          <option value="eg">Égypte</option>
          <option value="za">Afrique du Sud</option>
          <option value="sn">Sénégal</option>
          <option value="ci">Côte d'Ivoire</option>
          <option value="ng">Nigeria</option>
          <option value="ke">Kenya</option>
          <option value="et">Éthiopie</option>
          <option value="mg">Madagascar</option>
        </optgroup>
        <optgroup label="Asie">
          <option value="cn">Chine</option>
          <option value="jp">Japon</option>
          <option value="in_">Inde</option>
          <option value="th">Thaïlande</option>
          <option value="vn">Vietnam</option>
          <option value="id">Indonésie</option>
          <option value="tr">Turquie</option>
          <option value="il">Israël</option>
          <option value="sa">Arabie Saoudite</option>
          <option value="kr">Corée du Sud</option>
          <option value="ru">Russie</option>
        </optgroup>
        <optgroup label="Océanie">
          <option value="au">Australie</option>
          <option value="nz">Nouvelle-Zélande</option>
        </optgroup>
      </select>
      <span class="sep">|</span>
      <label>Zoom carte :
      <select id="sel-zoom" style="width:80px">
        <option value="100">100%</option>
        <option value="75">75%</option>
        <option value="50" selected>50%</option>
        <option value="25">25%</option>
      </select></label>
      <span class="sep">|</span>
      <label>Album :
      <select id="flt" style="width:150px">
        <option value="0">— Toutes les photos —</option>
        <?php foreach($albums as $a): ?>
        <option value="<?=(int)$a['id']?>"><?=htmlspecialchars($a['display_name'])?></option>
        <?php endforeach; ?>
      </select></label>
      <span class="sep">|</span>
      <?php foreach($TILES as $k=>$t): ?>
      <label style="cursor:pointer"><input type="radio" name="tr" value="<?=$k?>"
             <?=$k===$tile_key?'checked':''?> onchange="gabTile('<?=$k?>')"> <?=$t['label']?></label>
      <?php endforeach; ?>
      <span id="gi"></span>
    </div>
    <div style="position:relative;flex:1;display:flex;flex-direction:column">
      <div id="ld">Chargement…</div>
      <div id="gab-map" style="flex:1"></div>
    </div>
  </div>

  <!-- Panneau -->
  <div id="pn">
    <?php foreach($errors as $e): ?><div class="er"><?=$e?></div><?php endforeach; ?>
    <?php foreach($infos  as $i): ?><div class="ok"><?=htmlspecialchars($i)?></div><?php endforeach; ?>

    <!-- Formulaire -->
    <div class="sc">
      <h3><?=$editing?'✏️ Modifier':'➕ Nouvelle zone'?></h3>
      <form method="post" action="" id="gab-form">
        <input type="hidden" name="gab_action"  value="save">
        <input type="hidden" name="zone_id"     value="<?=$edit_id?>">
        <input type="hidden" name="coordinates" id="gab-coords"
               value="<?=$editing?htmlspecialchars($editing['coordinates']):''?>">
        <input type="hidden" name="zone_type"   id="gab-type"
               value="<?=$editing?htmlspecialchars($editing['zone_type']):'bbox'?>">
        <label>Nom de la zone</label>
        <input type="text" name="zone_name" required
               value="<?=$editing?htmlspecialchars($editing['name']):''?>">
        <label>Album parent <small>(pour un nouvel album)</small></label>
        <select name="parent_id">
          <option value="0">— Racine —</option>
          <?php foreach($albums as $a): ?>
          <option value="<?=(int)$a['id']?>"><?=htmlspecialchars($a['display_name'])?></option>
          <?php endforeach; ?>
        </select>
        <label>Album existant <small>(optionnel)</small></label>
        <select name="album_id">
          <option value="0">— Créer automatiquement —</option>
          <?php foreach($albums as $a): ?>
          <option value="<?=(int)$a['id']?>"
            <?=($editing&&(int)$editing['album_id']===(int)$a['id'])?'selected':''?>>
            <?=htmlspecialchars($a['display_name'])?>
          </option>
          <?php endforeach; ?>
        </select>
        <div class="br">
          <button type="submit" class="btn p"><?=$editing?'💾 Enregistrer':'✅ Créer'?></button>
          <?php if($editing): ?>
          <a href="<?=htmlspecialchars($self_url)?>" class="btn">Annuler</a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <!-- Liste zones -->
    <div class="sc">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <h3 style="margin:0">Zones géo (<?=count($zones)?>)</h3>
        <form method="post" action="" style="margin:0">
          <input type="hidden" name="gab_action" value="sync_all">
          <button type="submit" class="btn a">↺ Tout sync</button>
        </form>
      </div>
      <?php if(count($zones) > 5): ?>
      <input type="text" id="zone-filter" placeholder="🔎 Filtrer par nom ou album…"
             style="width:100%;box-sizing:border-box;margin-top:8px;padding:5px 8px;font-size:12px;border:1px solid #ddd;border-radius:3px">
      <div id="zone-filter-empty" style="display:none;padding:8px 0;color:#777;font-size:12px;font-style:italic">
        Aucune zone ne correspond à la recherche.
      </div>
      <?php endif; ?>
    </div>

    <?php if(empty($zones)): ?>
    <div style="padding:12px;color:#777;font-size:12px;font-style:italic">
      Aucune zone définie. Dessinez-en une sur la carte.
    </div>
    <?php else: ?>
    <div id="zone-list">
    <?php foreach($zones as $z): ?>
    <div class="zr" data-search="<?=htmlspecialchars(mb_strtolower($z['name'].' '.($z['album_name']?:'')))?>">
      <div class="zn"><?=htmlspecialchars($z['name'])?></div>
      <div class="zm">
        📁 <?php
            $pub_url   = $root . 'index.php?/category/' . (int)$z['album_id'];
            $admin_url = $root . 'admin.php?page=album-' . (int)$z['album_id'];
if ($z['album_name']) {
    echo '<a href="' . htmlspecialchars($pub_url) . '" target="_blank" title="Voir l\'album">'
       . htmlspecialchars($z['album_name']) . '</a>'
       . ' <a href="' . htmlspecialchars($admin_url) . '" target="_blank" title="Éditer l\'album" style="color:#1a73e8;font-size:10px">[admin]</a>';
} else {
    echo '—';
}
        ?>
        &nbsp;·&nbsp; <?=$z['zone_type']==='bbox'?'Rectangle':'Polygone'?>
        &nbsp;·&nbsp; <?=(int)$z['photo_count']?> photo(s)
      </div>
      <div class="za">
        <!-- Activer / Désactiver -->
        <form method="post" action="" style="margin:0">
          <input type="hidden" name="gab_action" value="toggle">
          <input type="hidden" name="zone_id"    value="<?=(int)$z['id']?>">
          <input type="hidden" name="active_val" value="<?=$z['active']?0:1?>">
          <button type="submit" class="btn <?=$z['active']?'g':'a'?>">
            <?=$z['active']?'✓ Active':'✗ Inactive'?>
          </button>
        </form>
        <!-- Éditer -->
        <a href="<?=htmlspecialchars($self_url.'?edit='.(int)$z['id'])?>" class="btn">✏</a>
        <!-- Sync -->
        <form method="post" action="" style="margin:0">
          <input type="hidden" name="gab_action" value="sync_one">
          <input type="hidden" name="zone_id"    value="<?=(int)$z['id']?>">
          <button type="submit" class="btn">↺ Sync</button>
        </form>
        <!-- Supprimer -->
        <form method="post" action="" style="margin:0"
              onsubmit="return confirm('Supprimer la zone ?\nL\'album Piwigo sera vidé mais conservé.')">
          <input type="hidden" name="gab_action" value="delete">
          <input type="hidden" name="zone_id"    value="<?=(int)$z['id']?>">
          <button type="submit" class="btn d">🗑</button>
        </form>
      </div>
    </div>
    <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<script>
var GAB_TILES    = <?=json_encode($TILES,JSON_HEX_TAG|JSON_UNESCAPED_SLASHES)?>;
var GAB_TILE_KEY = <?=json_encode($tile_key)?>;
window.OSM_CARTO_API_KEY = <?=json_encode($gab_carto_key)?>;
var GAB_ZOOM     = <?=$zoom?>;
var GAB_CENTER   = [48.0, 10.0];   // Europe centrale
var GAB_EXISTING = <?=$edit_data?>;
var GAB_AJAX_URL = <?=json_encode($ajax_url)?>;
var GAB_ZONES    = <?=json_encode(array_map(function($z){return array(
    'id'=>(int)$z['id'],'name'=>$z['name'],
    'zone_type'=>$z['zone_type'],
    'coordinates'=>json_decode($z['coordinates'],true),
    'active'=>(bool)$z['active']);
},$zones),JSON_HEX_TAG)?>;
</script>
<script>
(function(){
  var input = document.getElementById('zone-filter');
  if (!input) return; // pas affiché si 5 zones ou moins
  var rows  = document.querySelectorAll('#zone-list .zr');
  var empty = document.getElementById('zone-filter-empty');
  input.addEventListener('input', function(){
    var q = input.value.trim().toLowerCase();
    var visibleCount = 0;
    rows.forEach(function(row){
      var match = !q || row.getAttribute('data-search').indexOf(q) !== -1;
      row.style.display = match ? '' : 'none';
      if (match) visibleCount++;
    });
    if (empty) empty.style.display = visibleCount === 0 ? '' : 'none';
  });
})();
</script>
<script src="<?=$root?>plugins/<?=GAB_FOLDER?>/js/geoalbum.js?v=<?=GAB_VERSION?>"></script>
</body></html>
