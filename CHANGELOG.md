# Changelog — Geo Album

## 1.0.8 (français)
- **Nouveau fond de carte : OpenFreeMap** (vectoriel, MapLibre GL), gratuit et sans clé API. Disponible dans la barre de la carte et comme fond par défaut dans les réglages. MapLibre n'est chargé que si ce fond est choisi ; sans WebGL ou sans réseau, la carte bascule sur OSM.
- Dossier de langue corrigé : `language/en_US` (au lieu de `language/language/en_US`), conformément aux recommandations Piwigo.
- README et CHANGELOG mis à jour (section « Services externes utilisés »).

## 1.0.8 (English)
- **New base map: OpenFreeMap** (vector, MapLibre GL), free and without API key. Available in the map toolbar and as the default base map in the settings. MapLibre is only loaded when this base map is selected; without WebGL or network access the map falls back to OSM.
- Language folder fixed: `language/en_US` (instead of `language/language/en_US`), as recommended by Piwigo.
- README and CHANGELOG updated ("External services used" section).

## 1.0.7 (français)
- **Sauvegarde et restauration des zones** : panneau « 💾 Sauvegarde des zones » (export `.json`, sauvegarde sur le serveur dans `_data/geoalbum_backups/`, import, restauration). Aucune zone existante n'est écrasée à la restauration.
- **Sauvegarde automatique avant désinstallation** ; si elle est impossible, rien n'est supprimé. La page de gestion propose la restauration après une réinstallation.
- **Dupliquer une zone** (bouton ⧉) : nouvelle zone et nouvel album avec la même forme, par exemple pour une autre période.
- **Sécurité** : jeton anti-CSRF sur toutes les actions de la page de gestion et sur les réglages.
- **Carte** : Ctrl + molette zoome la carte et non la page du navigateur.
- README entièrement réécrit.

## 1.0.7 (English)
- **Zone backup and restore**: new "💾 Sauvegarde des zones" panel (`.json` export, server-side backup in `_data/geoalbum_backups/`, import, restore). Restoring never overwrites an existing zone.
- **Automatic backup before uninstall**; if it cannot be written, nothing is deleted. The management page offers to restore after a reinstall.
- **Duplicate a zone** (⧉ button): new zone and new album with the same shape, e.g. for another period.
- **Security**: anti-CSRF token on every management action and on the settings forms.
- **Map**: Ctrl + mouse wheel zooms the map instead of the browser page.
- README fully rewritten.

## 1.0.6
- Sécurité : ajout de fichiers `index.php` de protection dans le dossier du plugin et ses sous-dossiers.
- Ajout de l'URL du dépôt GitHub dans `Author URI` et de l'image d'aide `screenshot1.jpg`.
- Aucun changement fonctionnel ni de base de données.

## 1.0.5
- Les tables du plugin sont vérifiées à chaque chargement et recréées si elles manquent (correction de l'erreur fatale « Table … geo_zone_photos doesn't exist »).
- Plugin URI corrigé pour que Piwigo propose les mises à jour depuis PEM.

## 1.0.4
- Fond de carte par défaut configurable dans les réglages du plugin.
- Couleurs des clusters plus contrastées sur tous les fonds de carte.
- La carte se recentre sur la zone après sa création.
- La liste des albums parents inclut les albums sans photo (qui ne contiennent que des sous-albums).
- Période (prise de vue ou date d'ajout) enregistrée avec la zone, appliquée à l'ajout de photos et à la resynchronisation.
- Bouton « Valider » : applique en une fois le lieu, la période et l'album.
- Suppression de l'option « Album existant », source de confusion.
- Contrôle de doublon : un album ne peut être lié qu'à une seule zone, avec un message clair.
- Le plugin ne retire jamais d'un album les photos qu'il n'y a pas ajoutées, ni une photo sans autre album (pas d'orphelines).
- Cycle de vie propre via `maintain.class.php` : désactivation sans perte, désinstallation qui supprime les tables et les réglages.
- Icône de suppression plus lisible.

## Versions antérieures
Voir l'historique du dépôt GitHub.
