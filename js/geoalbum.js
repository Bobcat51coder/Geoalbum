/**
 * geoalbum.js v4.0
 * Variables : GAB_TILES, GAB_TILE_KEY, GAB_ZOOM, GAB_EXISTING, GAB_AJAX_URL, GAB_ZONES
 */
var _map=null,_tile=null,_drawn=null,_layerAll=null,_layerSel=null,_drawer=null,_zoneLayer=null;
var _canvas=null;  // renderer Canvas = bien plus rapide que SVG pour 10k+ markers
var _allPhotos=null;   // cache des photos "toutes" chargées, pour reconstruire la vue à la demande
var _layerFlat=null;   // couche "points individuels" (sans regroupement), construite au 1er besoin
var _clusterOn=true;   // état courant du bouton de regroupement
var _selPhotos=null;   // photos de l'album actuellement sélectionné (ou null si aucun)
var _layerSelFlat=null;// couche "points d'album individuels" (sans regroupement)

var SD={color:'#1a73e8',weight:2,fillOpacity:0.12};
var SA={radius:5,fillColor:'#1a73e8',color:'#fff',weight:1,fillOpacity:0.7};
var SS={radius:7,fillColor:'#e04000',color:'#fff',weight:2,fillOpacity:0.95};
var SZ={color:'#188038',weight:2,fillOpacity:0.06,dashArray:'6 4'};

// Icône "appareil photo" pour les points GPS individuels (utilisée seulement
// quand le clustering est actif : le nombre de nœuds DOM réellement affichés
// reste faible même avec des milliers de photos, donc pas d'impact perf).
var _cameraIcon = L.divIcon({
    className: '',
    html: '<div style="width:20px;height:20px;border-radius:50%;background:#1a73e8;'
        + 'border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4);'
        + 'display:flex;align-items:center;justify-content:center">'
        + '<svg width="11" height="11" viewBox="0 0 24 24" fill="#fff">'
        + '<path d="M9 2L7.17 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-3.17L15 2H9zm3 15a5 5 0 1 1 0-10 5 5 0 0 1 0 10zm0-2a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/>'
        + '</svg></div>',
    iconSize: [20,20], iconAnchor: [10,10]
});

function gabDraw(t){
    if(!_map) return;
    if(_drawer){try{_drawer.disable();}catch(e){}_drawer=null;}
    var bb=document.getElementById('btn-bbox');
    var bp=document.getElementById('btn-polygon');
    if(bb) bb.className='btn'+(t==='bbox'?' act':'');
    if(bp) bp.className='btn'+(t==='polygon'?' act':'');
    _drawer=t==='bbox'
        ?new L.Draw.Rectangle(_map,{shapeOptions:SD})
        :new L.Draw.Polygon(_map,{shapeOptions:SD});
    _drawer.enable();
}

function gabClear(){
    if(_drawer){try{_drawer.disable();}catch(e){}_drawer=null;}
    if(_drawn) _drawn.clearLayers();
    _f('gab-coords',''); _f('gab-type','bbox');
    var bb=document.getElementById('btn-bbox');
    var bp=document.getElementById('btn-polygon');
    if(bb) bb.className='btn act';
    if(bp) bp.className='btn';
    _info('Aucune zone dessinée');
}

function gabFit(){
    if(!_map||!_layerAll) return;
    try{ _map.fitBounds(_layerAll.getBounds().pad(0.1)); }catch(e){}
}

var _CONTINENTS = {
    'europe'   : [[34, -25], [72, 45]],
    'namerica' : [[10, -170], [75, -50]],
    'samerica' : [[-60, -85], [15, -30]],
    'africa'   : [[-40, -20], [40, 55]],
    'asia'     : [[0, 25], [75, 145]],
    'oceania'  : [[-50, 100], [10, 180]],
    'world'    : [[-75, -180], [80, 180]]
};

// Bounding boxes approximatives par pays — suffisant pour un centrage/zoom,
// pas une frontière exacte. Ajoutez une entrée ici pour ajouter un pays au
// sélecteur (la clé doit aussi être ajoutée dans le <select> côté HTML).
var _COUNTRIES = {
    fr:[[41,-5],[51,10]], gb:[[49,-8],[61,2]], ie:[[51,-11],[55.5,-5.5]],
    de:[[47,5.5],[55,15]], it:[[36,6],[47,19]], es:[[36,-10],[44,4]],
    pt:[[36.5,-9.5],[42.2,-6]], nl:[[50.7,3.3],[53.6,7.3]], be:[[49.5,2.5],[51.5,6.4]],
    lu:[[49.4,5.7],[50.2,6.5]], ch:[[45.8,5.9],[47.9,10.5]], at:[[46.4,9.5],[49.1,17.2]],
    pl:[[49,14],[55,24.2]], cz:[[48.5,12],[51.1,18.9]], sk:[[47.7,16.8],[49.7,22.6]],
    hu:[[45.7,16],[48.6,22.9]], ro:[[43.6,20.2],[48.3,29.7]], bg:[[41.2,22.3],[44.3,28.6]],
    gr:[[34.8,19.3],[41.8,29.7]], se:[[55.3,11],[69.1,24.2]], no:[[57.9,4.5],[71.2,31.1]],
    dk:[[54.5,8],[57.8,15.2]], fi:[[59.7,20.5],[70.1,31.6]], is:[[63.3,-24.6],[66.6,-13.5]],
    ee:[[57.5,21.7],[59.7,28.2]], lv:[[55.6,20.9],[58.1,28.2]], lt:[[53.9,20.9],[56.5,26.9]],
    ua:[[44.3,22.1],[52.4,40.2]], hr:[[42.3,13.4],[46.6,19.5]], rs:[[42.2,18.8],[46.2,23.1]],
    si:[[45.4,13.3],[46.9,16.6]], ba:[[42.5,15.7],[45.3,19.7]], al:[[39.6,19.2],[42.7,21.1]],
    mk:[[40.8,20.4],[42.4,23.1]], me:[[41.8,18.4],[43.6,20.4]], mt:[[35.8,14.1],[36.1,14.6]],
    cy:[[34.5,32.2],[35.7,34.6]],
    us:[[24.5,-125],[49.4,-66.9]], ca:[[41.7,-141],[83.1,-52.6]], mx:[[14.5,-118.4],[32.7,-86.7]],
    br:[[-33.7,-73.9],[5.3,-34.8]], ar:[[-55.1,-73.6],[-21.8,-53.6]], cl:[[-55.9,-75.6],[-17.5,-66.4]],
    pe:[[-18.4,-81.3],[-0.03,-68.7]], co:[[-4.2,-79.0],[12.5,-66.9]], ve:[[0.6,-73.4],[12.2,-59.8]],
    uy:[[-35,-58.4],[-30.1,-53.1]], py:[[-27.6,-62.6],[-19.3,-54.3]], bo:[[-22.9,-69.6],[-9.7,-57.5]],
    ec:[[-5,-81.1],[1.4,-75.2]],
    ma:[[27.7,-13.2],[35.9,-1]], dz:[[19,-8.7],[37.1,12]], tn:[[30.2,7.5],[37.5,11.6]],
    eg:[[22,24.7],[31.7,36.9]], za:[[-34.8,16.5],[-22.1,32.9]], sn:[[12.3,-17.5],[16.7,-11.3]],
    ci:[[4.3,-8.6],[10.7,-2.5]], ng:[[4.3,2.7],[13.9,14.7]], ke:[[-4.7,33.9],[5,41.9]],
    et:[[3.4,33],[14.9,48]], mg:[[-25.6,43.2],[-11.9,50.5]],
    cn:[[18,73.5],[53.6,135.1]], jp:[[24,122.9],[45.5,153.9]], in_:[[6.7,68.1],[35.5,97.4]],
    th:[[5.6,97.3],[20.5,105.6]], vn:[[8.4,102.1],[23.4,109.5]], id:[[-11,95],[6,141]],
    tr:[[36,26],[42.1,44.8]], il:[[29.5,34.2],[33.3,35.9]], sa:[[16,34.6],[32.2,55.7]],
    kr:[[33,125.9],[38.6,129.6]], ru:[[41.2,19.6],[81.9,180]],
    au:[[-43.6,113],[-10.7,153.6]], nz:[[-47.3,166.4],[-34.4,178.6]]
};

function gabContinent(key){
    if(!_map||!key) return;
    var b=_CONTINENTS[key];
    if(b) _map.fitBounds(b);
}

function gabCountry(key){
    if(!_map||!key) return;
    var b=_COUNTRIES[key];
    if(b) _map.fitBounds(b);
}

function gabEurope(){
    gabContinent('europe');
}

function gabZoom(pct){
    if(!_map) return;
    var mapDiv = document.getElementById('gab-map');
    if(!mapDiv) return;
    var base = Math.max(400, window.innerHeight - 140);
    var h    = Math.round(base * parseInt(pct, 10) / 100);
    // setProperty avec 'important' est la seule méthode JS fiable pour !important
    mapDiv.style.setProperty('height',    h + 'px', 'important');
    mapDiv.style.setProperty('min-height', h + 'px', 'important');
    mapDiv.style.setProperty('max-height', h + 'px', 'important');
    setTimeout(function(){ _map.invalidateSize(); }, 150);
}

function gabTile(k){
    if(!_map) return;
    var t=(GAB_TILES||{})[k]; if(!t) return;
    if(_tile) _map.removeLayer(_tile);
    _tile=L.tileLayer(t.url,{attribution:t.attr,maxZoom:19}).addTo(_map);
}

function _gabMakeSelMarker(p, clustered){
    var m;
    if(clustered && typeof L.markerClusterGroup === 'function'){
        var icon = L.divIcon({
            className: '',
            html: '<div style="width:13px;height:13px;border-radius:50%;background:#e04000;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.5)"></div>',
            iconSize: [13,13], iconAnchor: [6,6]
        });
        m = L.marker([p[1],p[2]], {icon: icon});
    } else {
        m = L.circleMarker([p[1],p[2]], Object.assign({},SS,{renderer:_canvas}));
    }
    m._gab_id = p[0];
    m.on('mouseover', function(){
        if(this._gab_name) return;
        var self = this;
        _ajax(GAB_AJAX_URL.replace('?ajax=photos&album_id=','?ajax=photo_detail&id=')+this._gab_id, function(d){
            if(d&&d.name){ self._gab_name=d.name;
                self.bindTooltip(d.name+' ★',{direction:'top',offset:[0,-4]}).openTooltip(); }
        });
    });
    return m;
}

// (Re)construit l'affichage de l'album sélectionné selon l'état courant de la
// case "Regrouper les points", à partir des photos déjà en cache (_selPhotos)
// — pas de nouvel appel serveur nécessaire lors d'un simple changement de mode.
function _gabRenderSel(fit){
    if(!_map || !_selPhotos) return;
    if(_clusterOn){
        if(_layerSelFlat && _map.hasLayer(_layerSelFlat)) _map.removeLayer(_layerSelFlat);
        if(_layerSel){
            _layerSel.clearLayers();
            _selPhotos.forEach(function(p){ _layerSel.addLayer(_gabMakeSelMarker(p, true)); });
            if(!_map.hasLayer(_layerSel)) _map.addLayer(_layerSel);
        }
    } else {
        if(_layerSel && _map.hasLayer(_layerSel)) _map.removeLayer(_layerSel);
        if(!_layerSelFlat) _layerSelFlat = L.featureGroup();
        _layerSelFlat.clearLayers();
        _selPhotos.forEach(function(p){ _layerSelFlat.addLayer(_gabMakeSelMarker(p, false)); });
        if(!_map.hasLayer(_layerSelFlat)) _map.addLayer(_layerSelFlat);
    }
    if(fit){
        var b = _clusterOn ? _layerSel : _layerSelFlat;
        try{ _map.fitBounds(b.getBounds().pad(0.1)); }catch(e){}
    }
}

// Compat : le menu Album appelle gabFilter(val), qui passe maintenant par
// gabReload() pour que le filtre période en cours reste appliqué.
function gabFilter(val){
    gabReload(true);
}

document.addEventListener('DOMContentLoaded', function(){ _wait(0); });

function _wait(n){
    if(n>80) return;
    if(typeof L!=='undefined' && typeof L.Draw!=='undefined'){ _patch(); _build(); }
    else setTimeout(function(){ _wait(n+1); }, 100);
}

function _patch(){
    if(!L.DomEvent||!L.DomEvent.on) return;
    var o=L.DomEvent.on.bind(L.DomEvent);
    L.DomEvent.on=function(el,types,fn,ctx){
        if(typeof types==='string'){
            types=types.replace(/\btouchleave\b/g,'').replace(/\s+/g,' ').trim();
            if(!types) return L.DomEvent;
        }
        return o(el,types,fn,ctx);
    };
}

// ── Filtre par période ───────────────────────────────────────────────────
// Recharge les points en tenant compte à la fois de l'album sélectionné
// (menu "Album") ET du filtre période (champs date) — les deux se combinent
// en ET : si un album est choisi ET une période saisie, seules les photos de
// cet album ET dans cette période sont retournées. Le filtrage est fait en
// SQL côté serveur, donc reste rapide même sur un gros volume de photos.
function gabReload(fit){
    var f  = (document.getElementById('dt-from')||{}).value || '';
    var t  = (document.getElementById('dt-to')||{}).value || '';
    var fl = (document.getElementById('sel-datefield')||{}).value || 'creation';
    var selAlbum = document.getElementById('flt');
    var aid = selAlbum ? (parseInt(selAlbum.value, 10) || 0) : 0;

    var url = GAB_AJAX_URL + aid + '&dfield=' + encodeURIComponent(fl);
    if(f) url += '&df=' + encodeURIComponent(f);
    if(t) url += '&dt=' + encodeURIComponent(t);

    _loading(true);
    _ajax(url, function(data){
        _loading(false);
        var photos  = (data && data.photos) || [];
        var missing = (data && data.missing_date) || 0;

        var c=document.getElementById('dt-count');
        if(c){
            var txt = (f||t) ? (photos.length+' photo(s) sur la période') : '';
            if(fl==='creation' && missing>0)
                txt += (txt?' — ':'') + missing+' photo(s) géolocalisée(s) sans date de prise de vue (exclue(s))';
            c.textContent = txt;
        }

        if(aid > 0){
            // Album sélectionné : rendu via _layerSel/_layerSelFlat
            _selPhotos = photos;
            if(_layerAll  && _map.hasLayer(_layerAll))  _map.removeLayer(_layerAll);
            if(_layerFlat && _map.hasLayer(_layerFlat)) _map.removeLayer(_layerFlat);
            if(!photos.length){
                if(_layerSel)     _layerSel.clearLayers();
                if(_layerSelFlat) _layerSelFlat.clearLayers();
                return;
            }
            _gabRenderSel(fit);
            return;
        }

        // Aucun album : rendu via _layerAll/_layerFlat
        _selPhotos = null;
        if(_layerSel     && _map.hasLayer(_layerSel))     _map.removeLayer(_layerSel);
        if(_layerSelFlat && _map.hasLayer(_layerSelFlat)) _map.removeLayer(_layerSelFlat);
        _allPhotos = photos;
        if(_layerAll) _layerAll.clearLayers();
        if(_layerFlat){ if(_map.hasLayer(_layerFlat)) _map.removeLayer(_layerFlat); _layerFlat=null; }
        var CHUNK=300, idx=0;
        function addChunk(){
            photos.slice(idx,idx+CHUNK).forEach(function(p){
                _layerAll.addLayer(_gabMakeMarker(p, true));
            });
            idx+=CHUNK;
            if(idx<photos.length){ requestAnimationFrame(addChunk); return; }
            gabToggleCluster(_clusterOn);
            if(fit && photos.length){ try{ gabFit(); }catch(e){} }
        }
        requestAnimationFrame(addChunk);
    });
}

// Compat : anciens noms encore appelés depuis geoalbum.php
function gabApplyDates(){ gabReload(true); }

function gabResetDates(){
    var f=document.getElementById('dt-from'), t=document.getElementById('dt-to');
    if(f) f.value=''; if(t) t.value='';
    gabReload(true);
}

// Réinitialise tout : "Tous les albums" + période vidée
function gabResetAll(){
    var sel=document.getElementById('flt');
    if(sel) sel.value='0';
    var f=document.getElementById('dt-from'), t=document.getElementById('dt-to');
    if(f) f.value=''; if(t) t.value='';
    var c=document.getElementById('dt-count');
    if(c) c.textContent='';
    gabReload(true);
}

function _gabMakeMarker(p, clustered){
    var m = clustered
        ? L.marker([p[1],p[2]],{icon:_cameraIcon})
        : L.circleMarker([p[1],p[2]],Object.assign({},SA,{renderer:_canvas}));
    m._gab_id=p[0];
    m.on('mouseover click',function(e){
        if(e.type==='click'&&this._gab_url){window.open(this._gab_url,'_blank');return;}
        if(e.type==='mouseover'&&!this._gab_name){
            var self=this;
            _ajax(GAB_AJAX_URL.replace('?ajax=photos&album_id=','?ajax=photo_detail&id=')+this._gab_id,function(d){
                if(d&&d.name){self._gab_name=d.name;self._gab_url=d.page_url;
                    self.bindTooltip(d.name,{direction:'top',offset:[0,-4]}).openTooltip();}
            });
        }
    });
    return m;
}

// Bascule entre "points regroupés" (lisible en vue d'ensemble) et "points
// individuels" (plus précis pour tracer une zone, aucun regroupement à aucun
// zoom). La couche plate est construite une seule fois, au premier besoin.
// S'applique à la couche "tous les points" ET à l'album sélectionné le cas
// échéant (les deux doivent rester cohérents avec la case à cocher).
function gabToggleCluster(on){
    _clusterOn = on;
    if(!_map) return;

    // Si un album est sélectionné, c'est lui qui pilote l'affichage —
    // la couche "tous les points" reste masquée dans ce cas.
    if(_selPhotos){
        _gabRenderSel(false);
        return;
    }

    if(!_layerAll) return;
    if(on){
        if(_layerFlat && _map.hasLayer(_layerFlat)) _map.removeLayer(_layerFlat);
        if(!_map.hasLayer(_layerAll)) _map.addLayer(_layerAll);
    } else {
        if(_map.hasLayer(_layerAll)) _map.removeLayer(_layerAll);
        if(!_layerFlat){
            _layerFlat = L.featureGroup();
            (_allPhotos||[]).forEach(function(p){
                _layerFlat.addLayer(_gabMakeMarker(p, false));
            });
        }
        if(!_map.hasLayer(_layerFlat)) _map.addLayer(_layerFlat);
    }
}

function _build(){
    var c=document.getElementById('gab-map');
    if(!c||_map) return;
    var tiles=GAB_TILES||{}, k=GAB_TILE_KEY||'carto';
    var _fallbackKey = window.OSM_CARTO_API_KEY || '';
    var _fallbackUrl = 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}.png' + (_fallbackKey ? ('?key='+encodeURIComponent(_fallbackKey)) : '');
    var t=tiles[k]||{url:_fallbackUrl,attr:'CARTO'};

    var _initCenter = (typeof GAB_CENTER!=='undefined') ? GAB_CENTER : [48.0,10.0];
    _map=L.map('gab-map',{center:_initCenter,zoom:GAB_ZOOM||4,scrollWheelZoom:false,
        preferCanvas:true});  // Canvas renderer global = performances optimales
    _map.on('mouseover',function(){_map.scrollWheelZoom.enable();});
    _map.on('mouseout', function(){_map.scrollWheelZoom.disable();});
    _tile=L.tileLayer(t.url,{attribution:t.attr,maxZoom:19}).addTo(_map);

    _canvas    = L.canvas({padding:0.5});  // renderer partagé pour tous les circleMarkers
    _zoneLayer = L.featureGroup().addTo(_map);
    _drawn     = L.featureGroup().addTo(_map);

    // Utiliser MarkerCluster si disponible (chargé par osm_map), sinon featureGroup simple
    if (typeof L.markerClusterGroup === 'function') {
        _layerAll = L.markerClusterGroup({
            maxClusterRadius: 60, showCoverageOnHover: false,
            animate: false, animateAddingMarkers: false,
            removeOutsideVisibleBounds: true,
            chunkedLoading: true, chunkSize: 300,
            // Au-delà de ce zoom, plus aucun regroupement : chaque point s'affiche
            // individuellement, pour un tracé de zone précis même en zone dense.
            disableClusteringAtZoom: 16
        }).addTo(_map);
        _layerSel = L.markerClusterGroup({
            maxClusterRadius: 60, showCoverageOnHover: false,
            animate: false,
            disableClusteringAtZoom: 16
        }).addTo(_map);
    } else {
        _layerAll = L.featureGroup().addTo(_map);
        _layerSel = L.featureGroup().addTo(_map);
    }

    // Zones existantes (contours verts)
    (GAB_ZONES||[]).forEach(function(z){
        if(!z.coordinates||!z.active) return;
        try{
            var layer;
            if(z.zone_type==='bbox'&&z.coordinates.length>=2)
                layer=L.rectangle([[z.coordinates[0].lat,z.coordinates[0].lng],
                                   [z.coordinates[1].lat,z.coordinates[1].lng]],SZ);
            else if(z.zone_type==='polygon')
                layer=L.polygon(z.coordinates.map(function(p){return[p.lat,p.lng];}),SZ);
            if(layer){
                layer.bindTooltip('\u{1F4CD} '+z.name,{permanent:false,direction:'center'});
                _zoneLayer.addLayer(layer);
            }
        }catch(e){}
    });

    // Charger toutes les photos GPS — format compact [[id,lat,lng],...]
    _loading(true);
    _ajax(GAB_AJAX_URL+'0', function(data){
        _loading(false);
        var photos = (data && data.photos) || [];
        var missing = (data && data.missing_date) || 0;
        if(!photos.length) return;
        _allPhotos = photos;
        var c=document.getElementById('dt-count');
        if(c && missing>0) c.textContent = missing+' photo(s) géolocalisée(s) sans date de prise de vue';
        var CHUNK=300, idx=0;
        function addChunk(){
            photos.slice(idx,idx+CHUNK).forEach(function(p){
                _layerAll.addLayer(_gabMakeMarker(p, true));
            });
            idx+=CHUNK;
            if(idx<photos.length){requestAnimationFrame(addChunk);return;}
            if(!GAB_EXISTING) gabFit();
        }
        requestAnimationFrame(addChunk);
    });

    if(GAB_EXISTING) _restoreZone(GAB_EXISTING.coords, GAB_EXISTING.type);

    // Lier les sélecteurs via JS (plus fiable que onchange inline)
    var selContinent = document.getElementById('sel-continent');
    if(selContinent) selContinent.addEventListener('change', function(){ gabContinent(this.value); this.value=''; });

    var selCountry = document.getElementById('sel-country');
    if(selCountry) selCountry.addEventListener('change', function(){ gabCountry(this.value); this.value=''; });

    // Validation du filtre période avec la touche Entrée
    ['dt-from','dt-to'].forEach(function(id){
        var el=document.getElementById(id);
        if(el) el.addEventListener('keydown', function(e){
            if(e.key==='Enter'){ e.preventDefault(); gabApplyDates(); }
        });
    });

    var selZoom = document.getElementById('sel-zoom');
    if(selZoom) selZoom.addEventListener('change', function(){ gabZoom(this.value); });

    var selAlbum = document.getElementById('flt');
    if(selAlbum) selAlbum.addEventListener('change', function(){ gabFilter(this.value); });

    _map.on(L.Draw.Event.CREATED, function(e){
        _drawn.clearLayers();
        _drawn.addLayer(e.layer);
        var coords, type;
        if(e.layerType==='rectangle'){
            var b=e.layer.getBounds(); type='bbox';
            coords=[{lat:b.getSouthWest().lat,lng:b.getSouthWest().lng},
                    {lat:b.getNorthEast().lat,lng:b.getNorthEast().lng}];
        } else {
            type='polygon';
            coords=e.layer.getLatLngs()[0].map(function(ll){return{lat:ll.lat,lng:ll.lng};});
        }
        _f('gab-coords', JSON.stringify(coords));
        _f('gab-type', type);
        _info(_desc(coords));
    });

    var form=document.getElementById('gab-form');
    if(form) form.addEventListener('submit', function(ev){
        var coord=document.getElementById('gab-coords');
        if(!coord||!coord.value){ ev.preventDefault(); alert('Dessinez une zone sur la carte.'); }
    });

    setTimeout(function(){ _map.invalidateSize(); }, 200);
}

function _restoreZone(coords, type){
    if(!_drawn||!coords||!coords.length) return;
    _drawn.clearLayers();
    var layer;
    if(type==='bbox'&&coords.length>=2)
        layer=L.rectangle([[coords[0].lat,coords[0].lng],[coords[1].lat,coords[1].lng]],SD);
    else
        layer=L.polygon(coords.map(function(c){return[c.lat,c.lng];}),SD);
    _drawn.addLayer(layer);
    try{ _map.fitBounds(_drawn.getBounds().pad(0.05)); }catch(e){}
    _info(_desc(coords));
}

function _ajax(url,cb){
    var x=new XMLHttpRequest();
    x.open('GET',url,true);
    x.withCredentials=true;  // transmet les cookies de session admin
    x.onload=function(){
        if(x.status===200){
            var t=x.responseText.trim();
            // Accepter tableaux [ et objets {
            if(!t || (t[0]!=='[' && t[0]!=='{')){
                console.error('GeoAlbum: réponse non-JSON',url,t.substring(0,120));
                cb(null); return;
            }
            try{ cb(JSON.parse(t)); }catch(e){ console.error('GeoAlbum parse error',e); cb(null); }
        } else {
            console.error('GeoAlbum: HTTP',x.status,url);
            cb(null);
        }
    };
    x.onerror=function(){ console.error('GeoAlbum: erreur réseau',url); cb([]); };
    x.send();
}

function _f(id,v){ var e=document.getElementById(id); if(e) e.value=v; }
function _info(t){ var e=document.getElementById('gi'); if(e) e.textContent=t; }
function _loading(v){ var e=document.getElementById('ld'); if(e) e.style.display=v?'block':'none'; }
function _desc(c){
    if(!c||!c.length) return '';
    var la=c.map(function(p){return p.lat;}), lo=c.map(function(p){return p.lng;});
    return 'Lat ['+Math.min.apply(null,la).toFixed(3)+'\u2192'+Math.max.apply(null,la).toFixed(3)
          +'] Lng ['+Math.min.apply(null,lo).toFixed(3)+'\u2192'+Math.max.apply(null,lo).toFixed(3)+']';
}
