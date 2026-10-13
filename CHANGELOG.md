# Changelog — Geo Album

## 1.1.0 (français)
Version majeure regroupant tout ce qui a été développé depuis la 1.0.8 (dernière version publiée) : les versions 1.0.9 à 1.0.12 n'ont pas été publiées séparément.

- **Réinitialiser après une modification** : le panneau de droite repasse en mode création (la page est rechargée sans la zone en cours d'édition).
- **Modification + critère temps** : le bouton « Valider » de la barre (avec « prendre en compte les données du filtre temps ») garde la carte sur la zone modifiée au lieu de revenir à la vue générale.
- **Gestion des albums Piwigo** : les albums alimentés par Geo Album sont repérés par un badge « 🌍 Geo Album » (ou « ⏸ Geo Album inactif ») dans la liste des albums, et par un bandeau sur la page d'édition de l'album, avec lien vers Geo Album.
- **Vue 3D cadrée sur la zone** : le bouton 3D ouvre la carte à l'emprise de la zone (modification) ou de la vue 2D, et trace le contour de la zone en bleu (avant : simple centre à fort zoom, la zone n'était pas visible).
- **Filtre période en 3D** : la vue 3D applique le même filtre de dates que la carte 2D (mêmes photos affichées).
- **Tri des listes** : bouton « ↕ Tri » dans la barre d'onglets pour passer de l'ordre chronologique (par défaut) à l'ordre alphabétique des noms de zone, pour « Zones » et « Albums liés ». Le choix est mémorisé dans le navigateur.
- **Bouton « 🗺 Toutes les zones »** dans la barre de la carte : cadre la vue sur l'ensemble des zones actives.
- **Repères de zones à petite échelle** : quand la carte est dézoomée (zoom ≤ 10), chaque zone active est signalée par un point orange cliquable (nom au survol, clic = zoom sur la zone), car les contours sont alors trop petits pour être vus.
- **Nouvelle copie d'écran** dans la page de réglages et dans la fiche du plugin (carte avec toutes les zones).
- **Import de photos** : une photo importée (upload, appli mobile, API) est classée tout de suite dans les zones qui la contiennent, sans resynchronisation. Le plugin utilise désormais l'événement Piwigo `loc_end_add_uploaded_file`, déclenché après la lecture du GPS.
- **Plus de préfixe « GZ_ »** : les nouveaux albums portent exactement le nom saisi (le badge suffit à les repérer). Les albums existants gardent leur nom ; renommez-les si vous le souhaitez.

## 1.1.0 (English)
Major release bundling everything developed since 1.0.8 (the last published version): versions 1.0.9 to 1.0.12 were not published separately.

- **Reset after an edit**: the right-hand panel goes back to creation mode (page reloaded without the zone being edited).
- **Edit + time criterion**: the bar's "Valider" button (with "take time-filter data into account") keeps the map on the modified zone instead of going back to the general view.
- **Piwigo album management**: albums fed by Geo Album get a "🌍 Geo Album" badge (or "⏸ Geo Album inactif") in the album list and a banner on the album edit page, linking to Geo Album.
- **3D view framed on the zone**: the 3D button opens the map at the extent of the zone (when editing) or of the 2D view, and draws the zone outline in blue (before: a plain centre at high zoom, the zone was not visible).
- **Date filter in 3D**: the 3D view applies the same date filter as the 2D map (same photos shown).
- **List sorting**: a "↕ Tri" button in the tab bar switches between chronological order (default) and alphabetical order of zone names, for both "Zones" and "Albums liés". The choice is remembered in the browser.
- **"🗺 Toutes les zones" button** in the map bar: frames the view on all active zones.
- **Zone markers at small scale**: when the map is zoomed out (zoom ≤ 10), each active zone is shown as a clickable orange dot (name on hover, click = zoom to the zone), as outlines are then too small to see.
- **New screenshot** in the settings page and the plugin sheet (map with all zones).
- **Photo import**: an imported photo (upload, mobile app, API) is filed at once into the zones containing it, without a resync. The plugin now uses Piwigo's `loc_end_add_uploaded_file` event, fired after the GPS is read.
- **No more "GZ_" prefix**: new albums get exactly the name entered (the badge identifies them). Existing albums keep their name; rename them if you wish.

## 1.0.11 (français)
- **Modification d'une zone** : la page affiche « Modification » et le bouton devient « Valider la modification ». Un message invite à choisir ⬜ Rectangle ou ⬡ Polygone pour retracer la zone.
- **Retracé sans effacer d'abord** : dès qu'on choisit un outil de tracé, l'ancienne forme est retirée de la carte (elle ne gêne plus), puis remplacée par la nouvelle ; si on abandonne sans tracer, elle est remise en place.
- **Nom de l'album modifiable** : le champ « Nom de l'album lié » permet de renommer l'album Piwigo en même temps que la zone est modifiée (le nom de la zone reste indépendant).

## 1.0.11 (English)
- **Editing a zone**: the form is titled "Modification" and the button reads "Valider la modification". A message prompts you to pick ⬜ Rectangle or ⬡ Polygone to redraw the zone.
- **Redraw without erasing first**: as soon as a drawing tool is chosen the old shape is taken off the map (it no longer gets in the way) and then replaced by the new one; if you give up without drawing, it is put back.
- **Editable album name**: the "Nom de l'album lié" field renames the Piwigo album at the same time as the zone is modified (the zone name stays independent).

## 1.0.10 (français)
- **Onglet « Albums liés »** dans le panneau de droite (à côté de « Zones ») : pour chaque album alimenté par le plugin, lien vers l'album (et vers son édition), nom de la zone, nombre de photos (dont celles ajoutées par le plugin), 2<sup>e</sup> critère (période), date de dernière synchronisation et état. La liaison album ↔ zone est enregistrée en base : renommer l'album dans Piwigo ne la casse pas.
- **Date de dernière synchronisation** par zone (synchronisation manuelle, « Tout sync » ou automatique toutes les heures), enregistrée dans un réglage du plugin (aucune modification de la base de données).
- **Album supprimé dans Piwigo** : la zone est signalée « ⚠ Album introuvable » (liste des zones et onglet) et n'est plus synchronisée tant que l'album n'existe pas.
- **Suppression d'une zone** : la confirmation indique maintenant le nom de la zone, l'album concerné (conservé) et le nombre de photos ajoutées par le plugin qui seront retirées.

## 1.0.10 (English)
- **"Albums liés" tab** in the right-hand panel (next to "Zones"): for each album fed by the plugin, a link to the album (and to its admin page), the zone name, the number of photos (including those added by the plugin), the 2nd criterion (period), the last sync date and the status. The album ↔ zone link is stored in the database: renaming the album in Piwigo does not break it.
- **Last sync date** per zone (manual sync, "Tout sync" or the hourly automatic sync), stored in a plugin setting (no database schema change).
- **Album deleted in Piwigo**: the zone is flagged "⚠ Album introuvable" (zone list and tab) and is no longer synchronised while the album does not exist.
- **Deleting a zone**: the confirmation now shows the zone name, the album concerned (kept) and how many plugin-added photos will be removed from it.

## 1.0.9 (français)
- **Correction** : avec OpenFreeMap comme fond par défaut, une erreur JavaScript (« Map has no maxZoom specified ») bloquait la carte : plus de recherche de lieu, bouton « Valider » inactif, molette sans effet. La carte a désormais des limites de zoom explicites, et une erreur dans les couches de photos ne bloque plus les boutons.
- **Correction** : la molette ne zoomait que sur les zones déjà dessinées (activation de la molette fiabilisée, indépendante du fond de carte).
- Si OpenFreeMap ne peut pas s'afficher (WebGL, réseau, style illisible, délai de 20 s), la carte bascule sur OSM avec un message qui indique la cause. Une erreur isolée sur une tuile ou une police ne provoque pas de bascule.
- Le fichier JavaScript est rechargé automatiquement après une mise à jour (anti-cache).
- **Noms en français sur OpenFreeMap** (à défaut le nom latin ou local) ; pour les pays à alphabet non latin, le nom local est ajouté sur une seconde ligne.
- **Nouveaux fonds** : « Satellite + noms » (imagerie Esri avec toponymes) et « OSM France » (noms en français quand ils existent).
- **Vue 3D** (bouton « 🏙 3D ») : s'ouvre dans un **nouvel onglet**, en lecture seule : carte OpenFreeMap inclinée avec bâtiments en relief, centrée sur la vue courante, avec les photos géolocalisées de l'album choisi (ou toutes), regroupées. Au clic sur une photo : imagette, titre et lien vers la page de la photo ; quand plusieurs photos sont exactement au même endroit, une galerie d'imagettes (par 24) s'ouvre. Un bouton « Mettre à plat (2D) / Revenir en 3D » bascule la vue, et « ✏ Dessiner ici : ⬜ Rectangle / ⬡ Polygone » ramène à la carte de dessin (onglet de gestion ou nouvel onglet) à la même position, avec l'outil de tracé déjà activé (l'onglet 3D se ferme et ramène sur l'onglet de gestion). Aucun tracé de zone, aucune écriture.
- **Clic sur un marqueur photo** (carte plate) : popup avec imagette, titre et lien vers la photo.

## 1.0.9 (English)
- **Fix**: with OpenFreeMap as the default base map, a JavaScript error ("Map has no maxZoom specified") broke the page: no place search, "Valider" button inactive, mouse wheel not working. The map now has explicit zoom limits, and an error while loading photo layers no longer blocks the buttons.
- **Fix**: the mouse wheel only zoomed over already drawn zones (wheel activation made reliable, independent of the base map).
- If OpenFreeMap cannot be displayed (WebGL, network, unreadable style, 20 s timeout), the map falls back to OSM with a message giving the cause. An isolated tile or font error no longer triggers the fallback.
- The JavaScript file is reloaded automatically after an update (cache busting).
- **French place names on OpenFreeMap** (falling back to the Latin or local name); for non-Latin scripts the local name is added on a second line.
- **New base maps**: "Satellite + noms" (Esri imagery with place names) and "OSM France" (French names when available).
- **3D view** ("🏙 3D" button): opens in a **new tab**, read-only: tilted OpenFreeMap with 3D buildings, centred on the current view, with the geolocated photos of the selected album (or all), clustered. Click a photo for its thumbnail, title and a link to the photo page; when several photos share exactly the same spot, a thumbnail gallery (24 at a time) opens. A "Mettre à plat (2D) / Revenir en 3D" button toggles the view, and "✏ Dessiner ici: ⬜ Rectangle / ⬡ Polygone" goes back to the drawing map (management tab or a new tab) at the same position with the drawing tool already active (the 3D tab closes and returns to the management tab). No zone drawing, no writes.
- **Click on a photo marker** (flat map): popup with thumbnail, title and link to the photo.

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
