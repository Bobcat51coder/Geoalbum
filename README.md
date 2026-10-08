# Geo Album — Plugin Piwigo

Crée et alimente automatiquement des albums Piwigo à partir de zones géographiques dessinées sur une carte — sans SmartAlbums, sans configuration complexe : vous dessinez une zone, un album est créé et toutes les photos géolocalisées qui s'y trouvent (déjà présentes ou importées plus tard) y sont assignées automatiquement. Une période (prise de vue ou date d'ajout) peut être enregistrée avec la zone.

Ce plugin est distribué sous licence [GNU GPL-2.0](LICENSE).

## Fonctionnalités

**Zones et albums**
- Dessin de zones par **rectangle** ou **polygone** sur une carte interactive, avec recherche de lieu, sélecteurs de continent et de pays, réglage de la hauteur de la carte et zoom de la carte avec Ctrl + molette.
- Création automatique d'un album Piwigo pour chaque nouvelle zone, dans l'album parent de votre choix (**privé** par défaut, accès admin accordé automatiquement).
- **Période** facultative enregistrée avec la zone (date de prise de vue ou date d'ajout, bornes « depuis » / « jusqu'à »).
- Bouton **⧉ Dupliquer** : nouvelle zone et nouvel album avec la même forme, par exemple un album par année pour un même lieu.
- Modification d'une zone (nom, forme, période) : l'album lié est conservé et resynchronisé.
- Activation / désactivation d'une zone, avec rattrapage des photos manquées à la réactivation.
- Filtre de recherche dans la liste des zones.

**Synchronisation**
- Les photos déjà géolocalisées dans la zone sont assignées à la création de la zone.
- Les photos importées plus tard avec des coordonnées GPS dans une zone active sont assignées automatiquement, en respectant la période de la zone.
- Synchronisation manuelle (une zone ou « Tout sync ») et resynchronisation automatique horaire.
- Compteurs des albums parents recalculés en cascade, sans passer par la Maintenance de Piwigo.

**Carte**
- Regroupement (clusters) des photos à grande échelle, points individuels en zoomant.
- Fond de carte par défaut au choix (Carto, OSM, Satellite, Topo), modifiable à la volée.
- Fonctionne de façon autonome, ou en complément d'[OSM Map Plus](https://piwigo.org/ext/extension_view.php?eid=1073) (partage de la clé API CartoDB).

**Sécurité des données**
- Le plugin ne retire jamais d'un album une photo qu'il n'y a pas lui-même ajoutée, ni une photo qui n'appartient à aucun autre album (voir plus bas).
- Sauvegarde, export, import et restauration des zones, avec sauvegarde automatique avant désinstallation.
- Jeton de sécurité (anti-CSRF) sur toutes les actions et fichiers `index.php` de protection dans chaque dossier.

## Installation

1. Installer depuis Administration > Plugins > Autres plugins, ou copier le dossier du plugin dans `plugins/`. Le dossier doit s'appeler exactement `Geoalbum` (sans trait d'union, sans numéro de version).
2. Activer le plugin dans Administration > Plugins.
3. Ouvrir « Geo Album » depuis le menu d'administration, ou avec le bouton Paramètres du plugin.

## Configuration

| Option | Description |
|---|---|
| Clé API CartoDB | Nécessaire depuis le 26/08/2026 pour le fond de carte « Carto Voyager » (gratuite sur [carto.com/basemaps/apikey](https://carto.com/basemaps/apikey/)). Partagée automatiquement avec OSM Map Plus si ce dernier en a une renseignée ; sinon, clé propre saisie ici. |
| Fond de carte par défaut | Fond affiché à l'ouverture de la page de gestion : Carto (précoché), OSM, Satellite ou Topo. Modifiable ensuite depuis la barre de la carte. |

## Utilisation

1. **Créer une zone** : tracez un rectangle ou un polygone, saisissez un nom, choisissez l'album parent, puis « Créer ». L'album est créé et rempli.
2. **Limiter à une période** : saisissez les dates dans la barre au-dessus de la carte, cliquez sur « Valider », cochez « Limiter l'album à la période du filtre » dans le formulaire, puis enregistrez. La période s'affiche sous le nom de la zone (📅).
3. **Modifier** (✏) : changez le nom, la forme ou la période. L'album reste le même et est resynchronisé.
4. **Dupliquer** (⧉) : même forme, nouvel album, nouveau nom, nouvelle période possible. La zone d'origine n'est pas modifiée.
5. **Supprimer** : la zone disparaît, l'album est conservé.

## Sécurité des albums existants

Le plugin ne retire d'un album que les photos qu'il y a lui-même ajoutées (suivi dans la table `geo_zone_photos`). Les photos déjà présentes dans un album, rangées à la main ou par un autre outil, ne sont jamais retirées par une synchronisation, une désactivation ou la suppression de la zone. Une photo n'est en outre jamais retirée si elle n'appartient à aucun autre album (pas d'orphelines).

## Sauvegarde et restauration des zones

Le panneau « 💾 Sauvegarde des zones » de la page de gestion permet de :

- **Exporter** les zones dans un fichier `.json` (forme, album, période, état actif) ;
- **Sauvegarder sur le serveur** : copie dans `_data/geoalbum_backups/` (10 sauvegardes conservées, dossier protégé), hors du dossier du plugin ;
- **Importer** un fichier exporté, ou **Restaurer** une sauvegarde du serveur.

À la restauration, une zone n'est recréée que si son album existe encore et n'est pas déjà lié à une zone : aucune zone existante n'est modifiée. Seule la définition des zones est sauvegardée (les albums et les photos restent dans Piwigo) ; cliquez sur « Tout sync » après une restauration pour rattacher les photos correspondantes.

## Activation, désactivation, désinstallation

- **Activation / mise à jour** : les tables `geo_zones` et `geo_zone_photos` sont créées ou mises à niveau automatiquement, sans toucher aux données existantes.
- **Désactivation** : rien n'est supprimé ; zones, réglages et albums sont conservés.
- **Désinstallation** (Administration > Plugins > Désinstaller) : les zones sont d'abord sauvegardées dans `_data/geoalbum_backups/`, puis les deux tables et les réglages du plugin sont supprimés. Les albums et les photos qu'ils contiennent restent en place. Si la sauvegarde est impossible (dossier `_data` non inscriptible), rien n'est supprimé.
- **Réinstallation** : si aucune zone n'existe mais qu'une sauvegarde est présente, la page de gestion propose de la restaurer.

## Sécurité du plugin

Un fichier `index.php` dans chaque dossier empêche le listage par le navigateur. Toutes les actions de la page de gestion (enregistrement, synchronisation, activation, suppression, export, import, restauration) et l'enregistrement des réglages exigent le jeton de sécurité de la session Piwigo : une action sans jeton valide est refusée avec un message.

## Historique

Voir [CHANGELOG.md](CHANGELOG.md).

## Crédits

**Conception, cahier des charges et tests**
Bobcat-Fr

**Développement assisté par IA**
Code généré par [Claude](https://claude.ai) (Anthropic) via une session de développement itératif — spécifications, corrections et validation assurées par Bobcat-Fr.
