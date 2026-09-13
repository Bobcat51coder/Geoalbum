/**
 * geoalbum.js v4.0
 * Variables : GAB_TILES, GAB_TILE_KEY, GAB_ZOOM, GAB_EXISTING, GAB_AJAX_URL, GAB_ZONES
 */
var _map=null,_tile=null,_drawn=null,_layerAll=null,_layerSel=null,_drawer=null,_zoneLayer=null;
var _canvas=null;  // renderer Canvas = bien plus rapide que SVG pour 10k+ markers

var SD={color:'#1a73e8',weight:2,fillOpacity:0.12};
var SA={radius:5,fillColor:'#1a73e8',color:'#fff',weight:1,fillOpacity:0.7};
var SS={radius:7,fillColor:'#e04000',color:'#fff',weight:2,fillOpacity:0.95};
var SZ={color:'#188038',weight:2,fillOpacity:0.06,dashArray:'6 4'};

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

function gabContinent(key){
    if(!_map||!key) return;
    var b=_CONTINENTS[key];
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

function gabFilter(val){
    var aid = parseInt(val, 10);

    // Remettre tous les points bleus si "Tous les albums"
    if(aid <= 0){
        if(_layerSel){ _layerSel.clearLayers(); }
        if(_layerAll && !_map.hasLayer(_layerAll)) _map.addLayer(_layerAll);
        return;
    }

    // Masquer les points bleus, charger les orange de l'album
    if(_layerAll && _map.hasLayer(_layerAll)) _map.removeLayer(_layerAll);
    if(!_layerSel) return;
    _layerSel.clearLayers();

    _ajax(GAB_AJAX_URL + val, function(photos){
        if(!photos||!photos.length) return;
        photos.forEach(function(p){
            var m;
            if(typeof L.markerClusterGroup === 'function'){
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
            _layerSel.addLayer(m);
        });
        // Centrer sur la sélection
        try{ _map.fitBounds(_layerSel.getBounds().pad(0.1)); }catch(e){}
    });
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
            chunkedLoading: true, chunkSize: 300
        }).addTo(_map);
        _layerSel = L.markerClusterGroup({
            maxClusterRadius: 60, showCoverageOnHover: false,
            animate: false
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
    _ajax(GAB_AJAX_URL+'0', function(photos){
        _loading(false);
        if(!photos||!photos.length) return;
        if(!photos||!photos.length) return;
        var CHUNK=300, idx=0;
        function addChunk(){
            photos.slice(idx,idx+CHUNK).forEach(function(p){
                var m=(typeof L.markerClusterGroup==='function')
                    ? L.marker([p[1],p[2]])
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
                _layerAll.addLayer(m);
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
