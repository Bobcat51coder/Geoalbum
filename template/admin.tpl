<div class="titrePage">
  <h2>Geo Album</h2>
</div>

<div class="content">
  {if $GAB_INFOS}
  <p style="color:#3c763d;background:#dff0d8;border:1px solid #d6e9c6;padding:8px 12px;border-radius:3px">
    {foreach from=$GAB_INFOS item=info}{$info}<br>{/foreach}
  </p>
  {/if}

  {if $GAB_EFFECTIVE_SOURCE == 'osm_map'}
  <p>
    Une clé API CartoDB est configurée dans <a href="{$ROOT_URL}admin.php?page=plugin-osm_map">OSM Map Plus</a> :
    elle est utilisée en priorité par ce plugin, qu'OSM Map Plus soit actif ou non.
  </p>
  {/if}

  <p>
    Vous pouvez aussi renseigner une clé propre à Geo Album (gratuite, sur
    <a href="https://carto.com/basemaps/apikey/" target="_blank" rel="noopener">carto.com/basemaps/apikey</a>) —
    elle ne sera utilisée que si aucune clé n'est configurée côté OSM Map Plus.
  </p>
  <form method="post" action="">
    <input type="text" name="geoalbum_carto_api_key" value="{$GAB_OWN_KEY|escape}"
           placeholder="Votre clé CartoDB"
           style="width:320px;max-width:100%;padding:5px 8px;border:1px solid #ccc;border-radius:3px">
    <button type="submit" class="btn a">Enregistrer</button>
  </form>

  {if $GAB_EFFECTIVE_SOURCE == 'none'}
  <p style="color:#a94442;background:#f2dede;border:1px solid #ebccd1;padding:8px 12px;border-radius:3px">
    ⚠️ Aucune clé API CartoDB configurée nulle part (ni ici, ni dans OSM Map Plus) — les fonds
    de carte « Carto Voyager » ne s'afficheront pas tant qu'une clé n'est pas renseignée.
  </p>
  {/if}

  <p>
    <a class="button" href="{$GAB_MANAGE_URL}">→ Gérer les zones géographiques (version {$GAB_VERSION})</a>
  </p>

  <hr style="margin:20px 0;border:none;border-top:1px solid #ddd">

  <h3>Comment ça marche</h3>
  <ol style="line-height:1.7">
    <li>Ouvrez « Gérer les zones géographiques » ci-dessus.</li>
    <li>Sur la carte, dessinez une zone avec l'outil <strong>Rectangle</strong> ou <strong>Polygone</strong>, ou utilisez le sélecteur <strong>Continent</strong> pour partir d'un contour prédéfini.</li>
    <li>Donnez un nom à la zone et associez-la à un album Piwigo (existant ou nouveau — il sera créé automatiquement en <strong>privé</strong>).</li>
    <li>Toutes les photos déjà géolocalisées dans ce périmètre y sont assignées immédiatement ; les futures photos importées avec des coordonnées GPS dans la zone le seront aussi, automatiquement.</li>
    <li>Le bouton <strong>Effacer</strong> retire le tracé en cours d'édition ; <strong>Centrer</strong> recadre la carte sur la zone sélectionnée.</li>
  </ol>

  <p><strong>Exemple</strong> — carte de gestion des zones, avec plusieurs zones déjà tracées en Europe :</p>
  <p><img src="{$GAB_HELP_IMG_URL}" alt="Exemple de zones géographiques tracées sur la carte" style="max-width:100%;border:1px solid #ddd;border-radius:4px"></p>
</div>
