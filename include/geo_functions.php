<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

function gab_get_gps($image_id)
{
    $r = pwg_db_fetch_assoc(pwg_query(
        'SELECT latitude, longitude FROM ' . IMAGES_TABLE . ' WHERE id=' . (int)$image_id
    ));
    if (!$r) return null;
    $lat = (float)$r['latitude']; $lng = (float)$r['longitude'];
    if ($lat == 0 && $lng == 0) return null;
    return array('lat' => $lat, 'lng' => $lng);
}

function gab_point_in_zone($p, $type, $coords)
{
    return ($type === 'bbox') ? gab_in_bbox($p, $coords) : gab_in_polygon($p, $coords);
}

function gab_in_bbox($p, $b)
{
    if (count($b) < 2) return false;
    return ($p['lat'] >= $b[0]['lat'] && $p['lat'] <= $b[1]['lat']
         && $p['lng'] >= $b[0]['lng'] && $p['lng'] <= $b[1]['lng']);
}

function gab_in_polygon($p, $poly)
{
    $n = count($poly); if ($n < 3) return false;
    $inside = false; $j = $n - 1;
    for ($i = 0; $i < $n; $i++) {
        $xi=$poly[$i]['lng']; $yi=$poly[$i]['lat'];
        $xj=$poly[$j]['lng']; $yj=$poly[$j]['lat'];
        if ((($yi>$p['lat'])!==($yj>$p['lat'])) && ($p['lng']<($xj-$xi)*($p['lat']-$yi)/($yj-$yi)+$xi))
            $inside=!$inside;
        $j=$i;
    }
    return $inside;
}

function gab_validate_coords($raw, $type)
{
    if (!is_string($raw)) return null;
    $raw = trim(stripslashes($raw));
    if ($raw === '') return null;
    $data = json_decode($raw, true);
    if (!is_array($data) || count($data) < ($type === 'polygon' ? 3 : 2)) return null;
    foreach ($data as $pt) {
        if (!isset($pt['lat'], $pt['lng'])) return null;
        if (!is_numeric($pt['lat']) || !is_numeric($pt['lng'])) return null;
        if ((float)$pt['lat'] < -90  || (float)$pt['lat'] > 90)  return null;
        if ((float)$pt['lng'] < -180 || (float)$pt['lng'] > 180) return null;
    }
    return $data;
}

/* ── IDs des photos dans une zone ────────────────────────────────────────── */

function gab_get_photo_ids_in_zone($zone)
{
    $coords = json_decode($zone['coordinates'], true);
    if (!is_array($coords)) return array();

    global $user;

    // Respecter les droits d'accès Piwigo (même logique que osmme-points.php)
    $is_guest = empty($user) || !isset($user['status']) || $user['status'] === 'guest';

    // Photos accessibles uniquement (albums publics pour visiteurs)
    $pub_join  = '';
    $pub_where = '';
    if ($is_guest) {
        $pub_join  = ' INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' AS ic_pub ON ic_pub.image_id = i.id'
                   . ' INNER JOIN ' . CATEGORIES_TABLE    . ' AS c_pub  ON c_pub.id = ic_pub.category_id';
        $pub_where = " AND c_pub.status = 'public'";
    }

    // Pour les utilisateurs connectés : respecter les forbidden_categories
    $forbidden_where = '';
    if (!$is_guest && !empty($user['forbidden_categories'])) {
        $forbidden_ids = implode(',', array_map('intval', explode(',', $user['forbidden_categories'])));
        // Garder uniquement les photos présentes dans au moins un album autorisé
        $forbidden_where = ' AND i.id IN ('
            . 'SELECT image_id FROM ' . IMAGE_CATEGORY_TABLE
            . ' WHERE category_id NOT IN (' . $forbidden_ids . '))';
    }

    $res = pwg_query('SELECT DISTINCT i.id, i.latitude, i.longitude'
        . ' FROM ' . IMAGES_TABLE . ' AS i'
        . $pub_join
        . ' WHERE i.latitude IS NOT NULL AND i.latitude != 0'
        . '   AND i.longitude IS NOT NULL AND i.longitude != 0'
        . $pub_where
        . $forbidden_where);

    $ids = array();
    while ($r = pwg_db_fetch_assoc($res)) {
        $p = array('lat' => (float)$r['latitude'], 'lng' => (float)$r['longitude']);
        if (gab_point_in_zone($p, $zone['zone_type'], $coords)) {
            $ids[] = (int)$r['id'];
        }
    }
    return $ids;
}
