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

## Crédits

**Conception, cahier des charges et tests**
Bobcat-Fr

**Développement assisté par IA**
Code généré par [Claude](https://claude.ai) (Anthropic) via une session de développement itératif —
spécifications, corrections et validation assurées par Bobcat-Fr.
