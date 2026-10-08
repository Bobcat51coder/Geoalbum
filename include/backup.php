<?php
/*
 * Sauvegarde / restauration des zones (table geo_zones).
 *
 * Format : un fichier JSON { plugin, format, version, exported_at, zones:[...] }.
 * Seule la définition des zones est sauvegardée. Les albums et les photos restent dans Piwigo,
 * et la table de suivi des photos (geo_zone_photos) se reconstruit par une resynchronisation.
 *
 * Les sauvegardes automatiques (avant désinstallation, ou bouton « Sauvegarder maintenant »)
 * sont écrites dans <_data>/geoalbum_backups/, HORS du dossier du plugin : elles survivent donc
 * à la suppression du plugin.
 */
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

if (!defined('GAB_BACKUP_FORMAT')) define('GAB_BACKUP_FORMAT', 1);
if (!defined('GAB_BACKUP_KEEP'))   define('GAB_BACKUP_KEEP',   10);          // nombre de sauvegardes conservées
if (!defined('GAB_IMPORT_MAX'))    define('GAB_IMPORT_MAX',    10 * 1024 * 1024); // taille max d'un fichier importé
if (!defined('GAB_IMPORT_ZONES'))  define('GAB_IMPORT_ZONES',  5000);        // nombre max de zones par fichier

/* Dossier des sauvegardes sur le serveur (créé et protégé à la demande). */
function gab_backup_dir($create = false)
{
    global $conf;
    $loc = (isset($conf['data_location']) && $conf['data_location'] !== '') ? $conf['data_location'] : '_data/';
    $dir = PHPWG_ROOT_PATH . rtrim($loc, '/') . '/geoalbum_backups/';
    if ($create && !is_dir($dir)) {
        @mkdir($dir, 0755, true);
        if (is_dir($dir)) {
            @file_put_contents($dir . 'index.php', "<?php\nheader('HTTP/1.1 403 Forbidden');\nexit;\n");
            @file_put_contents($dir . '.htaccess',
                "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
              . "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
        }
    }
    return $dir;
}

function gab_backup_filename_ok($f)
{
    return is_string($f) && preg_match('/^geoalbum_backup_\d{8}_\d{6}_[0-9a-f]{8}\.json$/', $f) === 1;
}

/* Contenu de la table des zones sous forme de tableau exportable. */
function gab_export_data()
{
    $zones = array();
    $res = pwg_query('SELECT z.*, c.name AS album_name FROM ' . GAB_TABLE . ' z
        LEFT JOIN ' . CATEGORIES_TABLE . ' c ON c.id = z.album_id ORDER BY z.id');
    while ($r = pwg_db_fetch_assoc($res)) {
        $coords = json_decode($r['coordinates'], true);
        $zones[] = array(
            'album_id'    => (int)$r['album_id'],
            'album_name'  => $r['album_name'],
            'name'        => $r['name'],
            'zone_type'   => $r['zone_type'],
            'coordinates' => is_array($coords) ? $coords : array(),
            'active'      => (int)$r['active'],
            'date_field'  => isset($r['date_field']) ? $r['date_field'] : '',
            'date_from'   => isset($r['date_from'])  ? $r['date_from']  : '',
            'date_to'     => isset($r['date_to'])    ? $r['date_to']    : '',
        );
    }
    return array(
        'plugin'      => 'geoalbum',
        'format'      => GAB_BACKUP_FORMAT,
        'version'     => defined('GAB_VERSION') ? GAB_VERSION : '',
        'exported_at' => date('c'),
        'zones'       => $zones,
    );
}

function gab_json($data)
{
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/* Écrit une sauvegarde sur le serveur.
 * Retourne : le nom du fichier créé, 0 s'il n'y a aucune zone à sauvegarder, false en cas d'échec. */
function gab_write_backup($reason = 'manual')
{
    $data = gab_export_data();
    if (empty($data['zones'])) return 0;
    $data['reason'] = $reason;
    $json = gab_json($data);
    if ($json === false) return false;

    $dir = gab_backup_dir(true);
    if (!is_dir($dir) || !is_writable($dir)) return false;

    try { $rand = bin2hex(random_bytes(4)); } catch (Exception $e) { $rand = substr(md5(uniqid('', true)), 0, 8); }
    $name = 'geoalbum_backup_' . date('Ymd_His') . '_' . $rand . '.json';
    $n = @file_put_contents($dir . $name, $json, LOCK_EX);
    if ($n === false || $n !== strlen($json)) { @unlink($dir . $name); return false; }

    // Rotation : on ne garde que les GAB_BACKUP_KEEP plus récentes
    $all = glob($dir . 'geoalbum_backup_*.json');
    if (is_array($all) && count($all) > GAB_BACKUP_KEEP) {
        sort($all);
        foreach (array_slice($all, 0, count($all) - GAB_BACKUP_KEEP) as $old) @unlink($old);
    }
    return $name;
}

/* Sauvegardes présentes sur le serveur, de la plus récente à la plus ancienne. */
function gab_list_backups()
{
    $out = array();
    $all = glob(gab_backup_dir(false) . 'geoalbum_backup_*.json');
    if (!is_array($all)) return $out;
    rsort($all);
    foreach ($all as $path) {
        $f = basename($path);
        if (!gab_backup_filename_ok($f)) continue;
        $d = json_decode((string)@file_get_contents($path), true);
        if (!is_array($d) || !isset($d['zones']) || !is_array($d['zones'])) continue;
        $out[] = array(
            'file'   => $f,
            'time'   => (int)filemtime($path),
            'zones'  => count($d['zones']),
            'reason' => isset($d['reason']) ? (string)$d['reason'] : '',
        );
    }
    return $out;
}

/* Réinjecte les zones d'un fichier de sauvegarde. N'écrase jamais une zone existante :
 * une zone n'est créée que si son album existe encore et n'est pas déjà lié à une zone.
 * Retourne un tableau de compteurs, ou false si le fichier n'est pas une sauvegarde Geo Album. */
function gab_import_data($data)
{
    if (!is_array($data) || (isset($data['plugin']) ? $data['plugin'] : '') !== 'geoalbum'
        || !isset($data['zones']) || !is_array($data['zones'])
        || (int)(isset($data['format']) ? $data['format'] : 0) !== GAB_BACKUP_FORMAT
        || count($data['zones']) > GAB_IMPORT_ZONES) {
        return false;
    }
    $res = array('imported' => 0, 'exists' => 0, 'no_album' => 0, 'invalid' => 0);

    foreach ($data['zones'] as $z) {
        if (!is_array($z)) { $res['invalid']++; continue; }

        $album_id = (int)(isset($z['album_id']) ? $z['album_id'] : 0);
        $name     = trim((string)(isset($z['name']) ? $z['name'] : ''));
        $type     = ((isset($z['zone_type']) ? $z['zone_type'] : '') === 'polygon') ? 'polygon' : 'bbox';
        $coords   = gab_validate_coords(gab_json(isset($z['coordinates']) ? $z['coordinates'] : null), $type);
        if ($album_id <= 0 || $name === '' || !$coords) { $res['invalid']++; continue; }

        $clean = array();   // on ne garde que lat/lng, rien d'autre
        foreach ($coords as $p) $clean[] = array('lat' => (float)$p['lat'], 'lng' => (float)$p['lng']);
        $name = function_exists('mb_substr') ? mb_substr($name, 0, 255) : substr($name, 0, 255);

        $alb = pwg_db_fetch_assoc(pwg_query('SELECT id FROM ' . CATEGORIES_TABLE . ' WHERE id=' . $album_id));
        if (!$alb) { $res['no_album']++; continue; }
        if (gab_get_zone_by_album($album_id)) { $res['exists']++; continue; }

        $from  = trim((string)(isset($z['date_from']) ? $z['date_from'] : ''));
        $to    = trim((string)(isset($z['date_to'])   ? $z['date_to']   : ''));
        if ($from !== '' && gab_date_bound($from, false) === '') $from = '';
        if ($to   !== '' && gab_date_bound($to,   true)  === '') $to   = '';
        $field = ((isset($z['date_field']) ? $z['date_field'] : '') === 'available') ? 'available' : 'creation';
        if ($from === '' && $to === '') $field = '';

        $id = gab_create_zone($album_id, $name, $type, $clean,
            array('field' => $field, 'from' => substr($from, 0, 32), 'to' => substr($to, 0, 32)));
        if ($id <= 0) { $res['invalid']++; continue; }
        if (empty($z['active'])) pwg_query('UPDATE ' . GAB_TABLE . ' SET active=0 WHERE id=' . (int)$id);
        $res['imported']++;
    }
    return $res;
}

/* Restaure une sauvegarde du serveur (nom de fichier validé, jamais un chemin). */
function gab_restore_backup($file)
{
    if (!gab_backup_filename_ok($file)) return false;
    $path = gab_backup_dir(false) . $file;
    if (!is_file($path)) return false;
    return gab_import_data(json_decode((string)file_get_contents($path), true));
}

/* Phrase de résumé d'un import. */
function gab_import_summary($r)
{
    $s = $r['imported'] . ' zone(s) restaurée(s)';
    $skip = array();
    if ($r['exists'])   $skip[] = $r['exists']   . ' ignorée(s) car l\'album est déjà lié à une zone';
    if ($r['no_album']) $skip[] = $r['no_album'] . ' ignorée(s) car l\'album n\'existe plus';
    if ($r['invalid'])  $skip[] = $r['invalid']  . ' ignorée(s) car invalide(s)';
    if ($skip) $s .= ' ; ' . implode(' ; ', $skip);
    $s .= '.';
    if ($r['imported'] > 0) $s .= ' Cliquez sur « Tout sync » pour rattacher les photos correspondantes.';
    return $s;
}
