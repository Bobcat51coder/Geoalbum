<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/* ── Associations photo ↔ album ─────────────────────────────────────────── */

function gab_insert_photo($image_id, $album_id)
{
    $image_id = (int)$image_id; $album_id = (int)$album_id;
    if ($image_id <= 0 || $album_id <= 0) return;
    // GPS obligatoire
    $r = pwg_db_fetch_assoc(pwg_query(
        'SELECT id FROM ' . IMAGES_TABLE . ' WHERE id=' . $image_id
        . ' AND latitude IS NOT NULL AND latitude!=0'
        . ' AND longitude IS NOT NULL AND longitude!=0'));
    if (!$r) return;
    // Déjà dans l'album (ajoutée par l'utilisateur ou un autre outil) : on n'y touche pas
    // et on ne la "revendique" pas, donc le plugin ne la retirera jamais.
    $n = pwg_db_fetch_assoc(pwg_query(
        'SELECT COUNT(*) AS n FROM ' . IMAGE_CATEGORY_TABLE
        . ' WHERE image_id=' . $image_id . ' AND category_id=' . $album_id));
    if ((int)$n['n'] > 0) return;
    pwg_query('INSERT INTO ' . IMAGE_CATEGORY_TABLE
        . ' (image_id, category_id) VALUES (' . $image_id . ',' . $album_id . ')');
    // Mémorise que c'est le plugin qui a ajouté cette photo
    pwg_query('INSERT IGNORE INTO ' . GAB_MEMBERS_TABLE
        . ' (album_id, image_id) VALUES (' . $album_id . ',' . $image_id . ')');
}

/* Retire une photo d'un album, SEULEMENT si le plugin l'y avait ajoutée et si elle
 * reste classée dans au moins un autre album (jamais de photo orpheline).
 * Retourne true si la photo a été retirée. */
function gab_remove_photo($image_id, $album_id)
{
    $image_id = (int)$image_id; $album_id = (int)$album_id;
    $own = pwg_db_fetch_assoc(pwg_query(
        'SELECT COUNT(*) AS n FROM ' . GAB_MEMBERS_TABLE
        . ' WHERE album_id=' . $album_id . ' AND image_id=' . $image_id));
    if ((int)$own['n'] === 0) return false;
    $other = pwg_db_fetch_assoc(pwg_query(
        'SELECT COUNT(*) AS n FROM ' . IMAGE_CATEGORY_TABLE
        . ' WHERE image_id=' . $image_id . ' AND category_id!=' . $album_id));
    if ((int)$other['n'] === 0) return false;
    pwg_query('DELETE FROM ' . IMAGE_CATEGORY_TABLE
        . ' WHERE image_id=' . $image_id . ' AND category_id=' . $album_id);
    pwg_query('DELETE FROM ' . GAB_MEMBERS_TABLE
        . ' WHERE album_id=' . $album_id . ' AND image_id=' . $image_id);
    return true;
}

/* Vide l'album des photos ajoutées par le plugin (celles de l'utilisateur restent). */
function gab_clear_album($album_id)
{
    $album_id = (int)$album_id;
    $res = pwg_query('SELECT image_id FROM ' . GAB_MEMBERS_TABLE . ' WHERE album_id=' . $album_id);
    $ids = array();
    while ($r = pwg_db_fetch_assoc($res)) $ids[] = (int)$r['image_id'];
    foreach ($ids as $id) gab_remove_photo($id, $album_id);
    // Plus de suivi pour cet album (zone supprimée ou désactivée)
    pwg_query('DELETE FROM ' . GAB_MEMBERS_TABLE . ' WHERE album_id=' . $album_id);
}

function gab_refresh($album_id)
{
    $album_id = (int)$album_id;

    // Récupérer la chaîne des parents (uppercats) : sans ça, un album créé comme
    // sous-catégorie peut ne pas remonter sur l'accueil tant que ses parents n'ont
    // pas, eux aussi, leurs compteurs recalculés.
    $ids_to_update = array($album_id);
    $cat = pwg_db_fetch_assoc(pwg_query(
        'SELECT uppercats FROM ' . CATEGORIES_TABLE . ' WHERE id=' . $album_id));
    if ($cat && !empty($cat['uppercats'])) {
        foreach (explode(',', $cat['uppercats']) as $uc) {
            $uc = (int)$uc;
            if ($uc > 0 && !in_array($uc, $ids_to_update)) $ids_to_update[] = $uc;
        }
    }

    if (!function_exists('update_category_representative')) {
        $f = PHPWG_ROOT_PATH . 'include/functions_category.inc.php';
        if (file_exists($f)) include_once($f);
    }
    if (function_exists('update_category_representative'))
        update_category_representative($ids_to_update);

    // Recalcule les compteurs des catégories (date_last, count_images côté cache utilisateur, etc.
    // — pour l'album ET ses parents) — c'est exactement ce que fait Administration > Maintenance >
    // "Mettre à jour les informations des catégories". Sans cet appel, un album créé/alimenté par ce
    // plugin insère directement dans image_category et n'apparaît pas sur l'accueil tant qu'une
    // synchronisation manuelle n'est faite.
    if (!function_exists('update_category')) {
        $f = PHPWG_ROOT_PATH . 'admin/include/functions.php';
        if (file_exists($f)) include_once($f);
    }
    if (function_exists('update_category'))
        update_category($ids_to_update);

    // Vider le cache utilisateur pour que les changements soient visibles immédiatement
    if (function_exists('invalidate_user_cache'))
        invalidate_user_cache();
    // nb_images supprimé en Piwigo 16 — le comptage est dynamique
}

/* ── Synchronisation d'une zone ─────────────────────────────────────────── */

function gab_sync_zone($zone)
{
    $album_id = (int)$zone['album_id'];
    if ($album_id <= 0) return array('added'=>0, 'removed'=>0);
    $coords = json_decode($zone['coordinates'], true);
    if (!is_array($coords)) return array('added'=>0, 'removed'=>0);

    // Photos qui doivent être dans l'album (GPS dans la zone ET période éventuelle)
    $should = array();
    $res = pwg_query('SELECT id,latitude,longitude FROM ' . IMAGES_TABLE
        . ' WHERE latitude IS NOT NULL AND latitude!=0'
        . ' AND longitude IS NOT NULL AND longitude!=0'
        . gab_zone_date_sql($zone));
    while ($r = pwg_db_fetch_assoc($res)) {
        $p = array('lat'=>(float)$r['latitude'], 'lng'=>(float)$r['longitude']);
        if (gab_point_in_zone($p, $zone['zone_type'], $coords))
            $should[(int)$r['id']] = true;
    }

    // Photos actuellement dans l'album
    $current = array();
    $res2 = pwg_query('SELECT image_id FROM ' . IMAGE_CATEGORY_TABLE
        . ' WHERE category_id=' . $album_id);
    while ($r = pwg_db_fetch_assoc($res2)) $current[(int)$r['image_id']] = true;

    $added = $removed = 0;
    foreach ($should as $id => $_) {
        if (!isset($current[$id])) { gab_insert_photo($id, $album_id); $added++; }
    }
    foreach ($current as $id => $_) {
        if (!isset($should[$id]) && gab_remove_photo($id, $album_id)) $removed++;
    }
    if ($added > 0 || $removed > 0) gab_refresh($album_id);
    return array('added'=>$added, 'removed'=>$removed);
}

function gab_sync_all_zones()
{
    foreach (gab_get_active_zones() as $zone) gab_sync_zone($zone);
}

/* ── CRUD zones ──────────────────────────────────────────────────────────── */

function gab_get_active_zones()
{
    $res = pwg_query('SELECT * FROM ' . GAB_TABLE . ' WHERE active=1');
    $z = array(); while ($r = pwg_db_fetch_assoc($res)) $z[] = $r; return $z;
}

function gab_get_zone($id)
{
    $r = pwg_db_fetch_assoc(pwg_query('SELECT * FROM ' . GAB_TABLE . ' WHERE id='.(int)$id));
    return $r ?: null;
}

function gab_get_zone_by_album($album_id)
{
    $r = pwg_db_fetch_assoc(pwg_query(
        'SELECT * FROM ' . GAB_TABLE . ' WHERE album_id='.(int)$album_id));
    return $r ?: null;
}

function gab_all_zones()
{
    $res = pwg_query('
        SELECT z.*, c.name AS album_name,
            (SELECT COUNT(*) FROM ' . IMAGE_CATEGORY_TABLE . ' ic
             WHERE ic.category_id=z.album_id) AS photo_count
        FROM ' . GAB_TABLE . ' z
        LEFT JOIN ' . CATEGORIES_TABLE . ' c ON c.id=z.album_id
        ORDER BY z.id DESC');
    $z = array(); while ($r = pwg_db_fetch_assoc($res)) $z[] = $r; return $z;
}

function gab_create_zone($album_id, $name, $type, $coords, $dates = array())
{
    $album_id = (int)$album_id;
    if ($album_id <= 0) return 0;
    pwg_query('INSERT INTO ' . GAB_TABLE
        . ' (album_id,name,zone_type,coordinates,date_field,date_from,date_to) VALUES ('
        . $album_id . ',"'
        . pwg_db_real_escape_string($name) . '","'
        . pwg_db_real_escape_string($type) . '","'
        . pwg_db_real_escape_string(json_encode($coords)) . '","'
        . pwg_db_real_escape_string(isset($dates['field']) ? $dates['field'] : '') . '","'
        . pwg_db_real_escape_string(isset($dates['from'])  ? $dates['from']  : '') . '","'
        . pwg_db_real_escape_string(isset($dates['to'])    ? $dates['to']    : '') . '")');
    return (int)pwg_db_insert_id();
}

function gab_update_zone($id, $name, $type, $coords, $dates = array())
{
    pwg_query('UPDATE ' . GAB_TABLE . ' SET'
        . ' name="'        . pwg_db_real_escape_string($name) . '",'
        . ' zone_type="'   . pwg_db_real_escape_string($type) . '",'
        . ' coordinates="' . pwg_db_real_escape_string(json_encode($coords)) . '",'
        . ' date_field="'  . pwg_db_real_escape_string(isset($dates['field']) ? $dates['field'] : '') . '",'
        . ' date_from="'   . pwg_db_real_escape_string(isset($dates['from'])  ? $dates['from']  : '') . '",'
        . ' date_to="'     . pwg_db_real_escape_string(isset($dates['to'])    ? $dates['to']    : '') . '"'
        . ' WHERE id='.(int)$id);
}

function gab_delete_zone($id)
{
    $zone = gab_get_zone($id);
    if ($zone) { gab_clear_album((int)$zone['album_id']); gab_refresh((int)$zone['album_id']); }
    pwg_query('DELETE FROM ' . GAB_TABLE . ' WHERE id='.(int)$id);
}

function gab_toggle_zone($id, $active)
{
    pwg_query('UPDATE ' . GAB_TABLE . ' SET active='.($active?1:0).' WHERE id='.(int)$id);
    $zone = gab_get_zone($id);
    if (!$zone) return;
    if (!$active) { gab_clear_album((int)$zone['album_id']); gab_refresh((int)$zone['album_id']); }
    else gab_sync_zone($zone);
}

/* ── Création d'album Piwigo ─────────────────────────────────────────────── */

function gab_create_album($name, $parent_id = null)
{
    if (!function_exists('create_virtual_category')) {
        $f = PHPWG_ROOT_PATH . 'include/functions_category.inc.php';
        if (file_exists($f)) include_once($f);
    }
    if (!function_exists('update_global_rank')) {
        $f = PHPWG_ROOT_PATH . 'admin/include/functions.php';
        if (file_exists($f)) include_once($f);
    }
    $parent_id = ($parent_id && (int)$parent_id > 0) ? (int)$parent_id : null;
    if (function_exists('create_virtual_category')) {
        $info = create_virtual_category('GZ_' . $name, $parent_id);
        $id = 0;
        if (!empty($info['id']))                 $id = (int)$info['id'];
        elseif (!empty($info['category']['id'])) $id = (int)$info['category']['id'];
        if ($id > 0) {
            // Album créé depuis un module d'administration : privé par défaut,
            // pas public — visible par les seuls admins/webmasters tant que des
            // permissions ne sont pas explicitement accordées.
            pwg_query('UPDATE ' . CATEGORIES_TABLE . ' SET status="private" WHERE id=' . $id);
            gab_grant_admin_access($id);
            if (function_exists('update_global_rank')) update_global_rank();
            if (function_exists('invalidate_user_cache')) invalidate_user_cache();
            return $id;
        }
    }
    // Fallback INSERT direct
    if ($parent_id) {
        $pr = pwg_db_fetch_assoc(pwg_query(
            'SELECT uppercats FROM ' . CATEGORIES_TABLE . ' WHERE id='.$parent_id));
    }
    $rq  = $parent_id
        ? 'SELECT COALESCE(MAX(global_rank),0)+1 AS r FROM ' . CATEGORIES_TABLE . ' WHERE id_uppercat='.$parent_id
        : 'SELECT COALESCE(MAX(global_rank),0)+1 AS r FROM ' . CATEGORIES_TABLE . ' WHERE id_uppercat IS NULL';
    $rr  = pwg_db_fetch_assoc(pwg_query($rq));
    $cols = 'name,status,visible,commentable,global_rank' . ($parent_id ? ',id_uppercat' : '');
    // status "private" par défaut (voir commentaire ci-dessus)
    $vals = '"'.pwg_db_real_escape_string('GZ_'.$name).'","private","true","true",'.(int)$rr['r']
          . ($parent_id ? ','.$parent_id : '');
    pwg_query('INSERT INTO ' . CATEGORIES_TABLE . ' ('.$cols.') VALUES ('.$vals.')');
    $id = (int)pwg_db_insert_id();
    if ($id > 0) {
        $uc = ($parent_id && !empty($pr['uppercats'])) ? $pr['uppercats'].','.$id : (string)$id;
        pwg_query('UPDATE ' . CATEGORIES_TABLE . ' SET uppercats="'.$uc.'" WHERE id='.$id);
        gab_grant_admin_access($id);
        if (function_exists('update_global_rank')) update_global_rank();
        if (function_exists('invalidate_user_cache')) invalidate_user_cache();
    }
    return $id;
}

// Accorde l'accès à l'album privé nouvellement créé aux administrateurs/webmasters
// ainsi qu'à l'utilisateur courant. Insertion SQL directe dans la table des
// permissions individuelles (user_access) : plus fiable, indépendante des
// éventuelles variations de add_permission_on_category()/get_admins() selon
// les versions de Piwigo. Sans ça, un album privé créé par ce plugin n'apparaît
// même pas pour le super admin tant qu'aucune permission n'a été accordée
// manuellement.
function gab_grant_admin_access($cat_id)
{
    global $user;
    $cat_id = (int)$cat_id;
    if ($cat_id <= 0) return;

    // Tous les comptes admin/webmaster
    $user_ids = array();
    $res = pwg_query(
        'SELECT user_id FROM ' . USER_INFOS_TABLE . ' WHERE status IN (\'admin\',\'webmaster\')'
    );
    while ($row = pwg_db_fetch_assoc($res)) {
        $user_ids[] = (int)$row['user_id'];
    }
    // + l'utilisateur actuellement connecté (au cas où son statut ne serait pas
    // encore remonté ci-dessus, ou pour un compte "normal" ayant créé l'album)
    if (!empty($user['id'])) $user_ids[] = (int)$user['id'];
    $user_ids = array_unique(array_filter($user_ids));
    if (empty($user_ids)) return;

    foreach ($user_ids as $uid) {
        pwg_query(
            'INSERT IGNORE INTO ' . USER_ACCESS_TABLE . ' (user_id, cat_id) VALUES (' .
            (int)$uid . ',' . $cat_id . ')'
        );
    }
}

/* Migration : colonnes de période (1.1.0) et suivi des photos ajoutées par le plugin (1.1.2). */
function gab_migrate()
{
    global $conf;
    if (isset($conf['geoalbum_schema']) && (int)$conf['geoalbum_schema'] >= 3) return;

    $r = pwg_query('SHOW COLUMNS FROM ' . GAB_TABLE . ' LIKE "date_field"');
    if (!pwg_db_fetch_assoc($r)) {
        pwg_query('ALTER TABLE ' . GAB_TABLE
            . ' ADD COLUMN date_field VARCHAR(12) NOT NULL DEFAULT "",'
            . ' ADD COLUMN date_from VARCHAR(32) NOT NULL DEFAULT "",'
            . ' ADD COLUMN date_to VARCHAR(32) NOT NULL DEFAULT ""');
    }

    $new = !pwg_db_fetch_assoc(pwg_query('SHOW TABLES LIKE "' . GAB_MEMBERS_TABLE . '"'));
    pwg_query('CREATE TABLE IF NOT EXISTS ' . GAB_MEMBERS_TABLE . ' (
        album_id INT UNSIGNED NOT NULL,
        image_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (album_id, image_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    if ($new) {
        // Zones existantes : tout ce que contient déjà leur album est considéré comme géré
        // par le plugin (comportement des versions précédentes).
        pwg_query('INSERT IGNORE INTO ' . GAB_MEMBERS_TABLE . ' (album_id, image_id)'
            . ' SELECT ic.category_id, ic.image_id FROM ' . IMAGE_CATEGORY_TABLE . ' ic'
            . ' INNER JOIN ' . GAB_TABLE . ' z ON z.album_id = ic.category_id');
    }
    conf_update_param('geoalbum_schema', 3);
    $conf['geoalbum_schema'] = 3;
}

/* Création (idempotente) des deux tables du plugin + migration des anciennes versions.
 * Utilisée par l'installation Piwigo (maintain.class.php) et par le filet de sécurité
 * de gab_init() (main.inc.php). */
function gab_create_tables()
{
    pwg_query('CREATE TABLE IF NOT EXISTS ' . GAB_TABLE . ' (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        album_id    INT UNSIGNED NOT NULL UNIQUE,
        name        VARCHAR(255) NOT NULL,
        zone_type   ENUM("bbox","polygon") NOT NULL DEFAULT "bbox",
        coordinates LONGTEXT NOT NULL,
        active      TINYINT(1) NOT NULL DEFAULT 1,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        date_field  VARCHAR(12) NOT NULL DEFAULT "",
        date_from   VARCHAR(32) NOT NULL DEFAULT "",
        date_to     VARCHAR(32) NOT NULL DEFAULT "",
        INDEX idx_album (album_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    gab_migrate();
}
