Ce plugin est distribué sous licence [GNU GPL-2.0](LICENSE).

# Geo Album — Plugin Piwigo

Crée et alimente automatiquement des albums Piwigo à partir de zones géographiques dessinées sur une carte — sans SmartAlbums, sans configuration complexe : vous dessinez une zone, vous la reliez à un album, et toutes les photos géolocalisées qui s'y trouvent (déjà présentes ou importées plus tard) y sont assignées automatiquement.

## Fonctionnalités

- Dessin de zones géographiques par **rectangle** ou **polygone** directement sur une carte interactive
- Association d'une zone à un album Piwigo existant, ou création automatique d'un nouvel album (en **privé** par défaut, avec accès admin accordé automatiquement)
- Assignation automatique des photos déjà géolocalisées dans la zone à sa création
- Assignation automatique des futures photos importées avec des coordonnées GPS dans une zone active
- Recalcul en cascade des compteurs d'albums parents (page d'accueil toujours à jour, sans passer par la Maintenance Piwigo)
- Activation/désactivation d'une zone, avec rattrapage automatique des photos manquées à la réactivation
- Filtre de recherche sur la liste des zones (utile au-delà d'une dizaine de zones)
- Fonctionne de façon autonome, ou en complément d'[OSM Map Plus](https://piwigo.org/ext/extension_view.php?eid=1073) (partage automatique de la clé API CartoDB entre les deux plugins, quel que soit celui qui est actif)
- Page d'administration avec aide
- Clustering (regroupement) des images à grande échelle, détail à petite échelle/
- Filtre de date d'ajout ou de prise de vue

## Installation

1. Décompresser dans `plugins/geoalbum/`
2. Activer depuis Administration → Extensions → Modules complémentaires
3. Ouvrir « Geo Album » depuis le menu d'administration (ou le bouton Paramètres de la vignette du plugin)

## Configuration

| Option | Description |
|---|---|
| Clé API CartoDB | Nécessaire depuis le 26/08/2026 pour le fond de carte « Carto Voyager » (gratuite sur [carto.com/basemaps/apikey](https://carto.com/basemaps/apikey/)). Partagée automatiquement avec OSM Map Plus si ce dernier en a une renseignée ; sinon, clé propre saisie ici. |
| Fond de carte par défaut | Fond affiché à l'ouverture de la page de gestion des zones : Carto (précoché par défaut), OSM, Satellite ou Topo. Modifiable ensuite à la volée depuis la barre de la carte. |

## Crédits

**Conception, cahier des charges et tests**
Bobcat-Fr

**Développement assisté par IA**
Code généré par [Claude](https://claude.ai) (Anthropic) via une session de développement itératif —
spécifications, corrections et validation assurées par Bobcat-Fr.

## Période enregistrée avec la zone

Le filtre de dates de la barre de la carte (prise de vue ou date d'ajout, bornes « depuis » / « jusqu'à ») peut être enregistré avec la zone : cochez « Limiter l'album à la période du filtre » dans le formulaire avant d'enregistrer. La période est ensuite appliquée à chaque ajout de photo et à chaque synchronisation (manuelle, « Tout sync », réactivation, resynchronisation automatique) : une photo hors période n'entre pas dans l'album, et une photo déjà présente mais hors période en est retirée à la synchronisation. La période s'affiche sous le nom de la zone dans la liste (📅) et se retrouve dans la barre de filtre en modification.

## Sécurité des albums existants

Le plugin ne retire d'un album que les photos qu'il y a lui-même ajoutées (suivi dans la table `geo_zone_photos`) : les photos déjà présentes dans un album, rangées à la main ou par un autre outil, ne sont jamais retirées par une synchronisation, une désactivation ou la suppression de la zone. Une photo n'est en outre jamais retirée si elle n'appartient à aucun autre album (pas d'orphelines).

