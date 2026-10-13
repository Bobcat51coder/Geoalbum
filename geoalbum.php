<?php
/*
 * Geo Album v4.0 — Page de gestion (admin uniquement)
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
if (!defined('GAB_VERSION')) define('GAB_VERSION', '1.1.0');
global $prefixeTable;
if (!defined('GAB_TABLE')) define('GAB_TABLE', $prefixeTable . 'geo_zones');
if (!defined('GAB_MEMBERS_TABLE')) define('GAB_MEMBERS_TABLE', $prefixeTable . 'geo_zone_photos');

include_once(GAB_PATH . 'include/geo_functions.php');
include_once(GAB_PATH . 'include/db.php');
include_once(GAB_PATH . 'include/backup.php');
global $conf;

// S'assurer que les tables existent et sont à jour (idempotent, vérifie leur présence réelle)
gab_create_tables();

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
    $gab_bound = 'gab_date_bound'; // fonction partagée (include/geo_functions.php)

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

/* ── Détail d'une photo (titre, page, imagette) ─────────────────────────── */
function gab_photo_info($r)
{
    $root_url = get_root_url();
    $title    = $r['name'] ?: pathinfo($r['file'], PATHINFO_FILENAME);
    // URL absolue directement, sans make_picture_url (qui génère des chemins relatifs)
    $page_url = $root_url . 'picture.php?/' . (int)$r['id'];
    // Imagette (dérivé « thumb » de Piwigo) ; vide si indisponible : le plugin reste utilisable sans
    $thumb = '';
    try {
        if (!class_exists('DerivativeImage') && is_file(PHPWG_ROOT_PATH . 'include/derivative.inc.php'))
            include_once(PHPWG_ROOT_PATH . 'include/derivative.inc.php');
        if (class_exists('DerivativeImage')) {
            $thumb = (string)DerivativeImage::thumb_url($r);
            if ($thumb !== '' && !preg_match('#^(https?:)?//#', $thumb) && $thumb[0] !== '/')
                $thumb = $root_url . preg_replace('#^\./#', '', $thumb);
        }
    } catch (Throwable $e) { $thumb = ''; }
    return array('id'=>(int)$r['id'],'name'=>$title,'page_url'=>$page_url,'thumb'=>$thumb);
}

/* ── AJAX détail photo (au clic sur un marqueur) ─────────────────────────── */
if (!empty($_GET['ajax']) && $_GET['ajax'] === 'photo_detail') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { echo '{}'; exit; }
    $r = pwg_db_fetch_assoc(pwg_query('SELECT * FROM ' . IMAGES_TABLE . ' WHERE id=' . $id));
    if (!$r) { echo '{}'; exit; }
    echo json_encode(gab_photo_info($r));
    exit;
}

/* ── AJAX détails de plusieurs photos (galerie des points superposés ; 60 au plus) ───────── */
if (!empty($_GET['ajax']) && $_GET['ajax'] === 'photo_details') {
    $ids = array();
    foreach (explode(',', (string)($_GET['ids'] ?? '')) as $x) { $x = (int)$x; if ($x > 0) $ids[$x] = $x; }
    $ids = array_slice(array_values($ids), 0, 60);
    $out = array();
    if ($ids) {
        $res = pwg_query('SELECT * FROM ' . IMAGES_TABLE . ' WHERE id IN (' . implode(',', $ids) . ')');
        $by = array();
        while ($r = pwg_db_fetch_assoc($res)) $by[(int)$r['id']] = gab_photo_info($r);
        foreach ($ids as $i) if (isset($by[$i])) $out[] = $by[$i];
    }
    echo json_encode($out);
    exit;
}

/* ── Vue 3D : page autonome, ouverte dans un nouvel onglet (lecture seule) ─────────────────── */
if (!empty($_GET['view3d'])) {
    $v_lat = max(-85, min(85, (float)($_GET['lat'] ?? 46.5)));
    $v_lng = max(-180, min(180, (float)($_GET['lng'] ?? 2.5)));
    $v_zoom = max(2, min(20, (float)($_GET['z'] ?? 14)));
    $v_album = max(0, (int)($_GET['album'] ?? 0));
    // Emprise à cadrer (s,w,n,e) et contour éventuel de la zone (anneau lng,lat;lng,lat…)
    $v_bb = null;
    if (!empty($_GET['bb']) && preg_match('/^-?[\d.]+(,-?[\d.]+){3}$/', $_GET['bb'])) $v_bb = array_map('floatval', explode(',', $_GET['bb']));
    $v_ring = array();
    if (!empty($_GET['ring']) && preg_match('/^[-\d.,;]+$/', $_GET['ring'])) {
        foreach (array_slice(explode(';', $_GET['ring']), 0, 500) as $pt) {
            $xy = explode(',', $pt);
            if (count($xy) == 2) $v_ring[] = array((float)$xy[0], (float)$xy[1]);
        }
    }
    $v_q = '';
    foreach (array('dfield','df','dt') as $k) if (isset($_GET[$k]) && strlen($_GET[$k]) <= 20) $v_q .= '&' . $k . '=' . rawurlencode($_GET[$k]);
    $v_root = get_root_url();
    $v_self = $v_root . 'plugins/' . basename(dirname(__FILE__)) . '/geoalbum.php';
    $v_ver = defined('GAB_VERSION') ? GAB_VERSION : '';
    $v_js = dirname(__FILE__) . '/js/geoalbum3d.js';
    $v_v = $v_ver . '.' . (int)@filemtime($v_js);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache');
    ?><!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Geo Album — Vue 3D</title>
<link rel="stylesheet" href="https://unpkg.com/maplibre-gl@5.24.0/dist/maplibre-gl.css">
<style>
html,body{margin:0;height:100%;font:13px sans-serif}
body{display:flex;flex-direction:column}
#bar{padding:6px 10px;background:#222;color:#fff;display:flex;gap:14px;align-items:center;flex-wrap:wrap}
#bar b{font-size:14px} #bar span{opacity:.8} #bar label{cursor:pointer}
#map{flex:1}
.pp img{display:block;max-width:200px;max-height:200px;border-radius:3px;margin-bottom:4px}
.pp a{color:#06c;text-decoration:none}
</style></head><body>
<div id="bar"><b>Vue 3D</b>
<span>Clic droit + glisser (ou Ctrl + glisser) : incliner et pivoter. Pour tracer une zone, utilisez « Dessiner ici ».</span>
<label><input type="checkbox" id="chk-photos" checked> Photos</label>
<span>✏ Dessiner ici :</span>
<button type="button" id="btn-draw-bbox" style="cursor:pointer" title="Retourner sur la carte de dessin, à cet endroit, et tracer un rectangle">⬜ Rectangle</button>
<button type="button" id="btn-draw-polygon" style="cursor:pointer" title="Retourner sur la carte de dessin, à cet endroit, et tracer un polygone">⬡ Polygone</button>
<button type="button" id="btn-tilt" style="cursor:pointer">Mettre à plat (2D)</button>
<span id="info"></span></div>
<div id="map"></div>
<script>
var GAB3D = <?=json_encode(array('lat'=>$v_lat,'lng'=>$v_lng,'zoom'=>$v_zoom,'album'=>$v_album,'ajax'=>$v_self,'bb'=>$v_bb,'ring'=>$v_ring,'q'=>$v_q), JSON_HEX_TAG|JSON_HEX_AMP)?>;
</script>
<script src="https://unpkg.com/maplibre-gl@5.24.0/dist/maplibre-gl.js"></script>
<script src="<?=htmlspecialchars($v_root)?>plugins/<?=htmlspecialchars(basename(dirname(__FILE__)))?>/js/geoalbum3d.js?v=<?=htmlspecialchars($v_v)?>"></script>
</body></html>
<?php
    exit;
}

/* ── Actions POST ─────────────────────────────────────────────────────────── */
$errors = array(); $infos = array();
$focus_zone = null; // zone à cadrer sur la carte après enregistrement
if ($_SERVER['REQUEST_METHOD']==='POST' && empty($_POST) && empty($_FILES) && !empty($_SERVER['CONTENT_LENGTH'])) {
    // Envoi plus gros que post_max_size : PHP a tout rejeté avant que le plugin ne le voie
    $errors[] = 'Fichier trop volumineux pour la configuration du serveur (post_max_size).';
}
if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['gab_action'])) {
    $action = $_POST['gab_action'];

    // Protection CSRF : toute action qui modifie des données exige le jeton de session Piwigo
    if (!gab_token_ok($_POST['pwg_token'] ?? '')) {
        $errors[] = 'Action refusée : jeton de sécurité absent ou expiré. Rechargez la page et recommencez.';
        $action = '';
    }

    if ($action === 'save') {
        $zone_id  = (int)($_POST['zone_id']  ?? 0);
        $album_id = (int)($_POST['album_id'] ?? 0);
        $name     = trim(stripslashes($_POST['zone_name'] ?? ''));
        $type     = (($_POST['zone_type'] ?? '') === 'polygon') ? 'polygon' : 'bbox';
        $coords   = gab_validate_coords($_POST['coordinates'] ?? '', $type);
        // Période facultative enregistrée avec la zone (prise de vue ou date d'ajout)
        $dates = array('field'=>'', 'from'=>'', 'to'=>'');
        if (!empty($_POST['gab_use_dates'])) {
            $df_raw = trim(stripslashes($_POST['gab_dfrom'] ?? ''));
            $dt_raw = trim(stripslashes($_POST['gab_dto']   ?? ''));
            if ($df_raw !== '' && gab_date_bound($df_raw, false) === '') $errors[] = 'Date de début invalide : '.htmlspecialchars($df_raw);
            if ($dt_raw !== '' && gab_date_bound($dt_raw, true)  === '') $errors[] = 'Date de fin invalide : '.htmlspecialchars($dt_raw);
            if (empty($errors) && $df_raw !== '' && $dt_raw !== ''
                && gab_date_bound($df_raw, false) > gab_date_bound($dt_raw, true))
                $errors[] = 'La date de début est postérieure à la date de fin.';
            if (empty($errors) && ($df_raw !== '' || $dt_raw !== '')) {
                $dates = array(
                    'field' => (($_POST['gab_dfield'] ?? '') === 'available') ? 'available' : 'creation',
                    'from'  => mb_substr($df_raw, 0, 32),
                    'to'    => mb_substr($dt_raw, 0, 32),
                );
            }
        }
        if (!$name)   $errors[] = 'Nom obligatoire.';
        if (!$coords) $errors[] = 'Dessinez une zone sur la carte.';
        if (empty($errors) && $album_id === 0) {
            $parent_id = (int)($_POST['parent_id'] ?? 0);
            $album_id  = gab_create_album($name, $parent_id > 0 ? $parent_id : null);
            if ($album_id > 0) $infos[] = 'Album "'.htmlspecialchars($name).'" créé (ID '.$album_id.').';
            else $errors[] = 'Impossible de créer l\'album.';
        }
        // Un album ne peut être lié qu'à une seule zone (clé unique) : message clair
        // au lieu d'une erreur SQL quand on choisit un album qui a déjà sa zone.
        if (empty($errors) && $zone_id === 0 && $album_id > 0) {
            $dup = gab_get_zone_by_album($album_id);
            if ($dup) $errors[] = 'Cet album est déjà lié à la zone « '.htmlspecialchars($dup['name'])
                .' » (ID '.(int)$dup['id'].'). Modifiez cette zone (✏ dans la liste) ou choisissez un autre album.';
        }
        if (empty($errors)) {
            if ($zone_id > 0) {
                gab_update_zone($zone_id, $name, $type, $coords, $dates);
                $infos[] = 'Zone mise à jour.';
                // Renommage de l'album lié (nom de l'album pris dans la base, jamais dans le formulaire)
                $new_album = trim(stripslashes($_POST['album_name'] ?? ''));
                $zrow = gab_get_zone($zone_id);
                if ($new_album !== '' && $zrow && gab_rename_album((int)$zrow['album_id'], $new_album))
                    $infos[] = 'Album renommé : « ' . htmlspecialchars($new_album) . ' ».';
            } else {
                $zone_id = gab_create_zone($album_id, $name, $type, $coords, $dates);
                $infos[] = 'Zone créée (ID '.$zone_id.').';
            }
            $zone = gab_get_zone($zone_id);
            $focus_zone = $zone;
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

    } elseif ($action === 'export') {
        // Téléchargement du fichier de sauvegarde (aucun autre affichage)
        $data = gab_export_data();
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="geoalbum_zones_' . date('Ymd_His') . '.json"');
        header('Cache-Control: no-store');
        echo gab_json($data);
        exit;

    } elseif ($action === 'backup_now') {
        $b = gab_write_backup('manual');
        if ($b === 0)       $infos[]  = 'Aucune zone à sauvegarder.';
        elseif ($b === false) $errors[] = 'Sauvegarde impossible : le dossier _data de Piwigo n\'est pas accessible en écriture. Utilisez « Exporter » pour télécharger le fichier.';
        else                $infos[]  = 'Sauvegarde enregistrée sur le serveur (' . $b . ').';

    } elseif ($action === 'import') {
        $f = isset($_FILES['gab_file']) ? $_FILES['gab_file'] : null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            $errors[] = 'Aucun fichier reçu. Choisissez un fichier .json exporté par Geo Album.';
        } elseif ($f['size'] > GAB_IMPORT_MAX) {
            $errors[] = 'Fichier trop volumineux (maximum 10 Mo).';
        } else {
            $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string)file_get_contents($f['tmp_name']));
            $r = gab_import_data(json_decode($raw, true));
            if ($r === false) $errors[] = 'Ce fichier n\'est pas une sauvegarde Geo Album valide.';
            else              $infos[]  = 'Import : ' . gab_import_summary($r);
        }

    } elseif ($action === 'restore') {
        $r = gab_restore_backup((string)($_POST['backup_file'] ?? ''));
        if ($r === false) $errors[] = 'Sauvegarde introuvable ou invalide.';
        else              $infos[]  = 'Restauration : ' . gab_import_summary($r);

    } elseif ($action === 'sync_all') {
        $ta = $tr = 0; $done_ids = array();
        foreach (gab_all_zones() as $z) {
            if (!$z['active']) continue;
            $r = gab_sync_zone($z, false); $ta += $r['added']; $tr += $r['removed'];
            $done_ids[] = (int)$z['id'];
        }
        if ($done_ids) gab_sync_times_save($done_ids);
        $infos[] = 'Tout resynchronisé : +'.$ta.' / -'.$tr.'.';
    }
}

/* ── Données ──────────────────────────────────────────────────────────────── */
$zones   = gab_all_zones();
$gab_sync_times = gab_sync_times();
$gab_missing = 0; foreach ($zones as $z_) if (empty($z_['cat_id'])) $gab_missing++;
$editing = !empty($_GET['edit']) ? gab_get_zone((int)$_GET['edit']) : null;
if ($editing) {
    $cn = pwg_db_fetch_assoc(pwg_query('SELECT name FROM ' . CATEGORIES_TABLE . ' WHERE id=' . (int)$editing['album_id']));
    $editing['album_name'] = $cn ? $cn['name'] : null;
}
// Duplication : nouvelle zone (nouvel album) qui reprend la forme d'une zone existante
$prefill = (!$editing && !empty($_GET['duplicate'])) ? gab_get_zone((int)$_GET['duplicate']) : null;
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
// Liste des albums pour les selects « Album parent » et « Album existant » : TOUS les albums Piwigo (y compris ceux qui
// ne contiennent que des sous-albums, sans photo ni GPS), avec leur chemin complet.
$parent_albums = array();
$res = pwg_query('SELECT id, name, id_uppercat, global_rank FROM ' . CATEGORIES_TABLE);
$cat_rows = array();
while ($c = pwg_db_fetch_assoc($res)) { $cat_rows[(int)$c['id']] = $c; }
foreach ($cat_rows as $cid => $c) {
    $path = array(); $cur = $cid; $guard = 0;
    while ($cur && isset($cat_rows[$cur]) && $guard++ < 50) {
        array_unshift($path, $cat_rows[$cur]['name']);
        $cur = (int)$cat_rows[$cur]['id_uppercat'];
    }
    $parent_albums[] = array(
        'id'           => $cid,
        'display_name' => implode(' / ', $path),
        'rank'         => (string)$c['global_rank'],
    );
}
usort($parent_albums, function($a, $b) { return strnatcmp($a['rank'], $b['rank']); });
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
    'openfreemap' => array('label'=>'OpenFreeMap', 'url'=>'', 'vector'=>true,
                           'style'=>'https://tiles.openfreemap.org/styles/liberty',
                           'attr'=>'<a href="https://openfreemap.org" target="_blank" rel="noopener">OpenFreeMap</a> &copy; <a href="https://openmaptiles.org/" target="_blank" rel="noopener">OpenMapTiles</a> Data from <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>'),
    'satellite_noms' => array('label'=>'Satellite + noms', 'url'=>'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
                           'overlay'=>'https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}',
                           'attr'=>'&copy; Esri'),
    'osmfr'     => array('label'=>'OSM France', 'url'=>'https://{s}.tile.openstreetmap.fr/osmfr/{z}/{x}/{y}.png', 'attr'=>'&copy; OpenStreetMap France &copy; OpenStreetMap'),
    'topo'      => array('label'=>'Topo',      'url'=>'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',                       'attr'=>'&copy; OSM &copy; OpenTopoMap'),
);
$tile_key = gab_default_tile(); // réglage de la page Paramètres (Carto par défaut)
if (!isset($TILES[$tile_key])) $tile_key = 'carto';
if (!empty($_GET['tile']) && isset($TILES[$_GET['tile']])) $tile_key = $_GET['tile'];

$root     = get_root_url();
$self_url = $root . 'plugins/' . GAB_FOLDER . '/geoalbum.php';
$ajax_url = $self_url . '?ajax=photos&album_id=';
$zoom     = isset($conf['osm_map_zoom']) ? (int)$conf['osm_map_zoom'] : 5;
$edit_id  = $editing ? (int)$editing['id'] : 0;
$edit_data= $editing
    ? json_encode(array('coords'=>json_decode($editing['coordinates'],true),'type'=>$editing['zone_type'],
        'dates'=>array('field'=>$editing['date_field'] ?? '','from'=>$editing['date_from'] ?? '','to'=>$editing['date_to'] ?? '')),JSON_HEX_TAG)
    : ($prefill
        ? json_encode(array('coords'=>json_decode($prefill['coordinates'],true),'type'=>$prefill['zone_type'],
            'dates'=>array('field'=>'','from'=>'','to'=>'')),JSON_HEX_TAG)
        : 'null');
$focus_data = $focus_zone
    ? json_encode(array('coords'=>json_decode($focus_zone['coordinates'],true),'type'=>$focus_zone['zone_type']),JSON_HEX_TAG)
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
/* Clusters : couleurs pleines + liseré blanc + chiffre en gras, lisibles sur tous les fonds (Carto, OSM, Satellite, Topo) */
.marker-cluster-small{background:rgba(27,94,32,.35)}
.marker-cluster-small div{background:#2e7d32}
.marker-cluster-medium{background:rgba(191,100,0,.35)}
.marker-cluster-medium div{background:#e07b00}
.marker-cluster-large{background:rgba(160,20,20,.35)}
.marker-cluster-large div{background:#c62828}
.marker-cluster div{color:#fff;font-weight:700;font-size:13px;border:2px solid #fff;box-shadow:0 1px 5px rgba(0,0,0,.55);text-shadow:0 1px 2px rgba(0,0,0,.6)}
.marker-cluster div span{line-height:26px}
/* Panneau droit */
#pn{width:300px;min-width:260px;background:#fff;border-left:1px solid #ddd;overflow-y:auto;display:flex;flex-direction:column}
.gab-tabs{display:flex;border-bottom:1px solid #ddd;background:#f3f3f3}
.gab-tabs button{flex:1;padding:7px 6px;border:0;border-bottom:3px solid transparent;background:none;cursor:pointer;font-size:12px;color:#555}
.gab-tabs button.on{border-bottom-color:#1a73e8;color:#111;font-weight:600;background:#fff}
.warn{color:#c5221f;font-weight:600}
.sc{padding:10px 12px;border-bottom:1px solid #eee}
.sc h3{font-size:13px;font-weight:600;margin-bottom:7px}
.sc label{display:block;font-size:12px;color:#555;margin-bottom:2px;margin-top:4px}
.sc input[type=text],.sc select{width:100%;padding:4px 7px;border:1px solid #bbb;border-radius:3px;font-size:13px}
.br{display:flex;gap:5px;margin-top:7px;flex-wrap:wrap}
/* Boutons */
.btn{padding:4px 10px;font-size:12px;border:1px solid #bbb;border-radius:3px;background:#f5f5f5;cursor:pointer;text-decoration:none;color:#333}
.btn.p{background:#1a73e8;color:#fff;border-color:#1558b0}
.btn.d{background:#c5221f;color:#fff;border-color:#8c1613;padding:4px 9px}
.btn.d:hover{background:#a50e0e}
.zp{color:#7a4d00;font-size:11px}
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
      <button type="button" onclick="gabFitZones()" title="Cadrer la carte pour voir toutes les zones actives">🗺 Toutes les zones</button>
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
      <button type="button" onclick="gabValidate()" title="Appliquer lieu, période et album">Valider</button>
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
      <span class="sep">|</span>
      <button type="button" onclick="gab3D()" title="Vue 3D des bâtiments (observation seule, sans tracé de zone)" style="cursor:pointer">🏙 3D</button>
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
      <h3><?=$editing?'✏️ Modification':($prefill?'⧉ Dupliquer la zone':'➕ Nouvelle zone')?></h3>
      <?php if($prefill): ?>
      <p style="margin:0 0 8px;font-size:12px;color:#555">Même forme que « <?=htmlspecialchars($prefill['name'])?> ». Un <strong>nouvel album</strong> sera créé :
        choisissez un nom, un album parent et, si besoin, une période (champs de dates de la barre au-dessus de la carte, puis « Valider »). La zone d'origine n'est pas modifiée.</p>
      <?php endif; ?>
      <form method="post" action="" id="gab-form">
          <?=gab_token_field()?>
        <input type="hidden" name="gab_action"  value="save">
        <input type="hidden" name="zone_id"     value="<?=$edit_id?>">
        <input type="hidden" name="coordinates" id="gab-coords"
               value="<?=$editing?htmlspecialchars($editing['coordinates']):($prefill?htmlspecialchars($prefill['coordinates']):'')?>">
        <input type="hidden" name="zone_type"   id="gab-type"
               value="<?=$editing?htmlspecialchars($editing['zone_type']):($prefill?htmlspecialchars($prefill['zone_type']):'bbox')?>">
        <label>Nom de la zone</label>
        <input type="text" name="zone_name" required
               value="<?=$editing?htmlspecialchars($editing['name']):($prefill?htmlspecialchars($prefill['name'].' (copie)'):'')?>">
        <?php if(!$editing): ?>
        <label>Album parent <small>(l'album sera créé dedans)</small></label>
        <select name="parent_id">
          <option value="0">— Racine —</option>
          <?php foreach($parent_albums as $a): ?>
          <option value="<?=(int)$a['id']?>"><?=htmlspecialchars($a['display_name'])?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <input type="hidden" name="album_id" value="<?=$editing?(int)$editing['album_id']:0?>">
        <?php if($editing): ?>
        <?php if($editing['album_name'] !== null && $editing['album_name'] !== ''): ?>
        <label>Nom de l'album lié <small>(modifiable ; la zone n'est pas renommée pour autant)</small></label>
        <input type="text" name="album_name" maxlength="255" value="<?=htmlspecialchars($editing['album_name'])?>">
        <?php else: ?>
        <p style="margin:8px 0;font-size:12px" class="warn">⚠ L'album lié (n° <?=(int)$editing['album_id']?>) n'existe plus dans Piwigo.</p>
        <?php endif; ?>
        <p style="margin:8px 0;font-size:12px;color:#555">Pour modifier la forme : choisissez <strong>⬜ Rectangle</strong> ou <strong>⬡ Polygone</strong>
          au-dessus de la carte et retracez la zone (l'ancienne forme est remplacée), puis « Valider la modification ».</p>
        <?php endif; ?>
        <label style="display:flex;align-items:flex-start;gap:6px;font-weight:normal;cursor:pointer;margin-top:10px">
          <input type="checkbox" name="gab_use_dates" id="gab-use-dates" value="1" <?=($editing && (($editing['date_from'] ?? '') !== '' || ($editing['date_to'] ?? '') !== ''))?'checked':''?> style="margin-top:3px">
          <span>Limiter l'album à la période du filtre de la barre de la carte
            <small id="gab-dates-summary" style="display:block;color:#666"></small></span>
        </label>
        <input type="hidden" name="gab_dfield" id="gab-dfield" value="">
        <input type="hidden" name="gab_dfrom"  id="gab-dfrom"  value="">
        <input type="hidden" name="gab_dto"    id="gab-dto"    value="">
        <div class="br">
          <button type="submit" class="btn p"><?=$editing?'✅ Valider la modification':'✅ Créer'?></button>
          <?php if($editing): ?>
          <a href="<?=htmlspecialchars($self_url.'?duplicate='.$edit_id)?>" class="btn" title="Créer un nouvel album avec la même forme">⧉ Dupliquer</a>
          <a href="<?=htmlspecialchars($self_url)?>" class="btn">Annuler</a>
          <?php elseif($prefill): ?>
          <a href="<?=htmlspecialchars($self_url)?>" class="btn">Annuler</a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <!-- Onglets du panneau de droite -->
    <div class="gab-tabs">
      <button type="button" id="gab-tb-zones" class="on" onclick="gabTab('zones')">Zones (<?=count($zones)?>)</button>
      <button type="button" id="gab-tb-albums" onclick="gabTab('albums')">Albums liés (<?=count($zones)?>)<?=$gab_missing?' <span class="warn" title="Album introuvable">⚠</span>':''?></button>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:5px 10px;background:#fafafa;border-bottom:1px solid #eee;font-size:11px;color:#888">
      <span>Geo Album <?=htmlspecialchars(GAB_VERSION)?></span>
      <button type="button" id="gab-sort" style="cursor:pointer;font-size:12px;padding:3px 10px" title="Changer l'ordre des listes (zones et albums liés)">↕ Tri : récent</button>
    </div>
    <div id="gab-tab-zones">

    <!-- Liste zones -->
    <div class="sc">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <h3 style="margin:0">Zones géo (<?=count($zones)?>)</h3>
        <form method="post" action="" style="margin:0">
          <?=gab_token_field()?>
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

    <!-- Sauvegarde / restauration des zones -->
    <?php $gab_backups = gab_list_backups(); ?>
    <details class="sc" id="gab-backup" <?=(empty($zones) && $gab_backups)?'open':''?>>
      <summary style="cursor:pointer;font-weight:600;font-size:13px">💾 Sauvegarde des zones</summary>
      <?php if (empty($zones) && $gab_backups): ?>
      <div class="ok" style="margin-top:8px">Aucune zone n'est définie, mais des sauvegardes existent : vous pouvez les restaurer ci-dessous.</div>
      <?php endif; ?>
      <p style="font-size:12px;color:#555;margin:8px 0 6px">
        Enregistre la définition des zones (forme, album, période). Les albums et les photos ne sont pas concernés.
        Une sauvegarde est aussi faite automatiquement avant une désinstallation du plugin.
      </p>
      <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
        <form method="post" action="" style="margin:0">
          <?=gab_token_field()?>
          <input type="hidden" name="gab_action" value="export">
          <button type="submit" class="btn a" <?=empty($zones)?'disabled':''?>>⬇ Exporter (.json)</button>
        </form>
        <form method="post" action="" style="margin:0">
          <?=gab_token_field()?>
          <input type="hidden" name="gab_action" value="backup_now">
          <button type="submit" class="btn" <?=empty($zones)?'disabled':''?>>Sauvegarder sur le serveur</button>
        </form>
      </div>
      <form method="post" action="" enctype="multipart/form-data" style="margin:8px 0 0;display:flex;gap:6px;flex-wrap:wrap;align-items:center">
        <?=gab_token_field()?>
        <input type="hidden" name="gab_action" value="import">
        <input type="file" name="gab_file" accept=".json,application/json" required style="font-size:12px;max-width:100%">
        <button type="submit" class="btn">⬆ Importer</button>
      </form>
      <?php if ($gab_backups): ?>
      <div style="margin-top:10px;font-size:12px"><strong>Sauvegardes sur le serveur</strong></div>
      <?php foreach ($gab_backups as $b): ?>
      <form method="post" action="" style="margin:4px 0 0;display:flex;gap:6px;align-items:center;justify-content:space-between"
            onsubmit="return confirm('Restaurer cette sauvegarde ?\nLes zones dont l\'album existe encore et n\'est pas déjà lié à une zone seront recréées. Aucune zone existante n\'est modifiée.')">
        <?=gab_token_field()?>
        <input type="hidden" name="gab_action"  value="restore">
        <input type="hidden" name="backup_file" value="<?=htmlspecialchars($b['file'])?>">
        <span style="font-size:12px"><?=date('d/m/Y H:i', $b['time'])?> · <?=(int)$b['zones']?> zone(s)<?=$b['reason']==='uninstall'?' · avant désinstallation':''?></span>
        <button type="submit" class="btn">↺ Restaurer</button>
      </form>
      <?php endforeach; endif; ?>
    </details>

    <?php if(empty($zones)): ?>
    <div style="padding:12px;color:#777;font-size:12px;font-style:italic">
      Aucune zone définie. Dessinez-en une sur la carte.
    </div>
    <?php else: ?>
    <div id="zone-list">
    <?php foreach($zones as $z): ?>
    <div class="zr" data-gname="<?=htmlspecialchars(mb_strtolower($z['name']))?>" data-search="<?=htmlspecialchars(mb_strtolower($z['name'].' '.($z['album_name']?:'')))?>">
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
        <?php if (empty($z['cat_id'])): ?><br><span class="warn">⚠ Album introuvable : il a été supprimé dans Piwigo</span><?php endif; ?>
        <?php $per = gab_zone_period_label($z); if ($per !== ''): ?>
        <br><span class="zp" title="Période appliquée à la synchronisation de cet album">📅 <?=htmlspecialchars($per)?></span>
        <?php endif; ?>
      </div>
      <div class="za">
        <!-- Activer / Désactiver -->
        <form method="post" action="" style="margin:0">
          <?=gab_token_field()?>
          <input type="hidden" name="gab_action" value="toggle">
          <input type="hidden" name="zone_id"    value="<?=(int)$z['id']?>">
          <input type="hidden" name="active_val" value="<?=$z['active']?0:1?>">
          <button type="submit" class="btn <?=$z['active']?'g':'a'?>">
            <?=$z['active']?'✓ Active':'✗ Inactive'?>
          </button>
        </form>
        <!-- Éditer -->
        <a href="<?=htmlspecialchars($self_url.'?edit='.(int)$z['id'])?>" class="btn" title="Modifier">✏</a>
        <a href="<?=htmlspecialchars($self_url.'?duplicate='.(int)$z['id'])?>" class="btn" title="Dupliquer : nouvel album avec la même forme">⧉</a>
        <!-- Sync -->
        <form method="post" action="" style="margin:0">
          <?=gab_token_field()?>
          <input type="hidden" name="gab_action" value="sync_one">
          <input type="hidden" name="zone_id"    value="<?=(int)$z['id']?>">
          <button type="submit" class="btn">↺ Sync</button>
        </form>
        <!-- Supprimer -->
        <form method="post" action="" style="margin:0"
              onsubmit="return confirm(<?=htmlspecialchars(json_encode(
                  !empty($z['cat_id'])
                  ? ('Supprimer la zone « ' . $z['name'] . ' » ?' . "\n" . 'Album concerné : « ' . $z['album_name'] . ' » (il sera conservé).' . "\n"
                     . (int)$z['tracked_count'] . ' photo(s) ajoutée(s) par le plugin seront retirées de cet album ; les autres photos restent.')
                  : ('Supprimer la zone « ' . $z['name'] . ' » ? (son album n\'existe plus dans Piwigo)'),
                  JSON_UNESCAPED_UNICODE), ENT_QUOTES)?>)">
          <?=gab_token_field()?>
          <input type="hidden" name="gab_action" value="delete">
          <input type="hidden" name="zone_id"    value="<?=(int)$z['id']?>">
          <button type="submit" class="btn d" title="Supprimer la zone" aria-label="Supprimer la zone"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:block"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg></button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
    </div><!-- /gab-tab-zones -->

    <!-- Onglet « Albums liés » -->
    <div id="gab-tab-albums" style="display:none">
      <div class="sc">
        <h3 style="margin:0 0 4px">Albums alimentés par Geo Album</h3>
        <div style="font-size:11px;color:#666">
          La liaison album ↔ zone est enregistrée par le plugin : renommer l'album dans Piwigo ne la casse pas.
        </div>
      </div>
      <?php if (empty($zones)): ?>
      <div style="padding:12px;color:#777;font-size:12px;font-style:italic">Aucun album lié.</div>
      <?php endif; ?>
      <?php foreach ($zones as $z):
          $aid = (int)$z['album_id'];
          $exists = !empty($z['cat_id']);
          $per = gab_zone_period_label($z);
          $ts  = isset($gab_sync_times[(int)$z['id']]) ? (int)$gab_sync_times[(int)$z['id']] : 0;
      ?>
      <div class="zr" data-gname="<?=htmlspecialchars(mb_strtolower($z['name']))?>">
        <div class="zn">
          <?php if ($exists): ?>
          <a href="<?=htmlspecialchars($root . 'index.php?/category/' . $aid)?>" target="_blank" title="Voir l'album"><?=htmlspecialchars($z['album_name'])?></a>
          <a href="<?=htmlspecialchars($root . 'admin.php?page=album-' . $aid)?>" target="_blank" title="Éditer l'album" style="color:#1a73e8;font-size:10px">[admin]</a>
          <?php else: ?>
          <span class="warn">⚠ Album introuvable (n° <?=$aid?>)</span>
          <?php endif; ?>
        </div>
        <div class="zm">
          Zone : <b><?=htmlspecialchars($z['name'])?></b>
          &nbsp;·&nbsp; <?=$z['active']?'✓ active':'✗ inactive'?><br>
          📷 <?=(int)$z['photo_count']?> photo(s), dont <?=(int)$z['tracked_count']?> ajoutée(s) par le plugin<br>
          📅 2<sup>e</sup> critère : <?=$per !== '' ? htmlspecialchars($per) : 'aucun (zone géographique seule)'?><br>
          🕒 Dernière synchro : <?=$ts ? date('d/m/Y H:i', $ts) : '—'?>
          <?php if (!$exists): ?>
          <br><span class="warn">L'album a été supprimé dans Piwigo : supprimez cette zone, ou recréez-en une avec un nouvel album.</span>
          <?php endif; ?>
        </div>
        <div class="za">
          <a href="<?=htmlspecialchars($self_url.'?edit='.(int)$z['id'])?>" class="btn" title="Modifier la zone">✏</a>
          <?php if ($exists): ?>
          <form method="post" action="" style="margin:0">
            <?=gab_token_field()?>
            <input type="hidden" name="gab_action" value="sync_one">
            <input type="hidden" name="zone_id"    value="<?=(int)$z['id']?>">
            <button type="submit" class="btn">↺ Sync</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div><!-- /gab-tab-albums -->
  </div>
</div>

<script>
var GAB_TILES    = <?=json_encode($TILES,JSON_HEX_TAG|JSON_UNESCAPED_SLASHES)?>;
var GAB_TILE_KEY = <?=json_encode($tile_key)?>;
window.OSM_CARTO_API_KEY = <?=json_encode($gab_carto_key)?>;
<?php
// Position d'arrivée demandée (bouton « Dessiner ici » de la vue 3D) : coordonnées validées
$go_c = array(48.0, 10.0); $go_z = $zoom; $go_on = false; $go_draw = '';
if (isset($_GET['glat'], $_GET['glng']) && is_numeric($_GET['glat']) && is_numeric($_GET['glng'])
    && abs((float)$_GET['glat']) <= 85 && abs((float)$_GET['glng']) <= 180) {
    $go_on = true;
    $go_draw = (isset($_GET['gd']) && in_array($_GET['gd'], array('bbox','polygon'), true)) ? $_GET['gd'] : '';
    $go_c = array((float)$_GET['glat'], (float)$_GET['glng']);
    $go_z = (isset($_GET['gz']) && is_numeric($_GET['gz'])) ? max(2, min(19, (int)round((float)$_GET['gz']))) : 15;
}
?>
var GAB_ZOOM     = <?=(int)$go_z?>;
var GAB_CENTER   = <?=json_encode($go_c)?>;
var GAB_GOTO     = <?=$go_on ? 'true' : 'false'?>;
var GAB_GOTO_DRAW = <?=json_encode($go_draw)?>;   // outil de tracé à activer à l'arrivée ('bbox', 'polygon' ou '')   // position imposée : pas de recadrage automatique sur les photos
var GAB_EXISTING = <?=$edit_data?>;
var GAB_FOCUS    = <?=$focus_data?>;   // zone tout juste créée/modifiée : la carte s'y recadre
var GAB_AJAX_URL = <?=json_encode($ajax_url)?>;
var GAB_ZONES    = <?=json_encode(array_map(function($z){return array(
    'id'=>(int)$z['id'],'name'=>$z['name'],
    'zone_type'=>$z['zone_type'],
    'coordinates'=>json_decode($z['coordinates'],true),
    'active'=>(bool)$z['active']);
},$zones),JSON_HEX_TAG)?>;
</script>
<script>
function gabTab(n){
  var z=document.getElementById('gab-tab-zones'), a=document.getElementById('gab-tab-albums');
  if(!z||!a) return;
  z.style.display = n==='albums' ? 'none' : '';
  a.style.display = n==='albums' ? '' : 'none';
  document.getElementById('gab-tb-zones').className  = n==='albums' ? '' : 'on';
  document.getElementById('gab-tb-albums').className = n==='albums' ? 'on' : '';
  try{ sessionStorage.setItem('gabTab', n); }catch(e){}
}
/* Tri des listes (zones + albums liés) : chronologique (défaut, ordre du serveur) ou alphabétique */
(function(){
  var btn=document.getElementById('gab-sort'); if(!btn) return;
  var lists=[];
  ['zone-list','gab-tab-albums'].forEach(function(id){
    var el=document.getElementById(id); if(!el) return;
    var rows=[].slice.call(el.querySelectorAll('.zr[data-gname]'));
    if(rows.length) lists.push({parent:rows[0].parentNode,rows:rows});
  });
  lists.forEach(function(l){ l.rows.forEach(function(r,i){ r._i=i; }); });
  function apply(mode){
    lists.forEach(function(l){
      var a=l.rows.slice();
      if(mode==='az') a.sort(function(x,y){ return x.getAttribute('data-gname').localeCompare(y.getAttribute('data-gname'),'fr',{numeric:true,sensitivity:'base'}); });
      else a.sort(function(x,y){ return x._i-y._i; });
      var anchor=document.createComment(''); l.parent.insertBefore(anchor,l.rows[0]);
      a.forEach(function(r){ l.parent.insertBefore(r,anchor); });
      l.parent.removeChild(anchor);
      l.rows=a;
    });
    btn.textContent = mode==='az' ? '↕ Tri : A→Z' : '↕ Tri : récent';
    btn.setAttribute('data-mode',mode);
    try{ localStorage.setItem('gabSort',mode); }catch(e){}
  }
  btn.addEventListener('click',function(){ apply(btn.getAttribute('data-mode')==='az'?'recent':'az'); });
  btn.setAttribute('data-mode','recent');
  var m=''; try{ m=localStorage.getItem('gabSort')||''; }catch(e){}
  if(m==='az') apply('az');
})();
(function(){ var n=''; try{ n=sessionStorage.getItem('gabTab')||''; }catch(e){} if(n==='albums') gabTab('albums'); })();
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
<script src="<?=$root?>plugins/<?=GAB_FOLDER?>/js/geoalbum.js?v=<?=GAB_VERSION?>.<?=(int)@filemtime(dirname(__FILE__)."/js/geoalbum.js")?>"></script>
</body></html>
