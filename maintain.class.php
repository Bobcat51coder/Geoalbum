<?php
/*
 * Cycle de vie du plugin, appelé par Piwigo (Administration > Plugins) :
 *  - install / activate / update : crée les tables si besoin et migre les anciennes versions
 *  - deactivate : ne touche à rien (zones et albums conservés)
 *  - uninstall  : sauvegarde d'abord les zones dans _data/geoalbum_backups/, puis supprime les 2 tables
 *                 du plugin et ses réglages. Les albums Piwigo et les photos qu'ils contiennent sont
 *                 CONSERVÉS (ils deviennent des albums ordinaires).
 */
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

class geoalbum_maintain extends PluginMaintain
{
    private function gab_load()
    {
        global $prefixeTable;
        if (!defined('GAB_TABLE'))         define('GAB_TABLE',         $prefixeTable . 'geo_zones');
        if (!defined('GAB_MEMBERS_TABLE')) define('GAB_MEMBERS_TABLE', $prefixeTable . 'geo_zone_photos');
        include_once(dirname(__FILE__) . '/include/geo_functions.php');
        include_once(dirname(__FILE__) . '/include/db.php');
        include_once(dirname(__FILE__) . '/include/backup.php');
    }

    function install($plugin_version, &$errors = array())
    {
        $this->gab_load();
        gab_create_tables();
    }

    function activate($plugin_version, &$errors = array())
    {
        $this->gab_load();
        gab_create_tables();
    }

    function update($old_version, $new_version, &$errors = array())
    {
        $this->gab_load();
        gab_create_tables();
    }

    function deactivate()
    {
    }

    function uninstall()
    {
        $this->gab_load();
        // Filet de sécurité : on sauvegarde les zones AVANT de supprimer la table.
        // Si la sauvegarde est impossible (dossier _data non inscriptible), on CONSERVE les données
        // plutôt que de risquer de les perdre : une réinstallation les retrouvera.
        if (gab_table_exists(GAB_TABLE)) {
            if (gab_write_backup('uninstall') === false) return;
        }
        pwg_query('DROP TABLE IF EXISTS ' . GAB_TABLE);
        pwg_query('DROP TABLE IF EXISTS ' . GAB_MEMBERS_TABLE);
        pwg_query('DELETE FROM ' . CONFIG_TABLE . ' WHERE param IN ('
            . '"geoalbum_carto_api_key","geoalbum_default_tile","gab_last_update","geoalbum_schema")');
    }
}
