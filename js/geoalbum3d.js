/* Geo Album — vue 3D (page autonome, lecture seule) : carte MapLibre inclinée, bâtiments en relief,
 * photos géolocalisées (regroupées) avec imagette au clic. Aucune écriture, aucun tracé de zone. */
(function(){
'use strict';
var STYLE='https://tiles.openfreemap.org/styles/liberty';
var ATTR='<a href="https://openfreemap.org" target="_blank" rel="noopener">OpenFreeMap</a> &copy; <a href="https://openmaptiles.org/" target="_blank" rel="noopener">OpenMapTiles</a> Data from <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>';
var info=document.getElementById('info');
function say(t){ if(info) info.textContent=t; }
if(typeof maplibregl==='undefined'){ say('MapLibre GL n’a pas pu être chargé (réseau).'); return; }
try{
    var c=document.createElement('canvas');
    if(!(c.getContext('webgl2')||c.getContext('webgl'))){ say('WebGL non disponible : la vue 3D est impossible sur ce navigateur.'); return; }
}catch(e){}

function frenchLabels(gl){
    try{
        var fr=['coalesce',['get','name:fr'],['get','name:latin'],['get','name']];
        var expr=['case',['all',['has','name:nonlatin'],['!=',['get','name:nonlatin'],'']],['concat',fr,'\n',['get','name:nonlatin']],fr];
        gl.getStyle().layers.forEach(function(l){
            if(l.type!=='symbol') return;
            var tf=l.layout&&l.layout['text-field'];
            if(!tf||JSON.stringify(tf).indexOf('name')<0) return;
            try{ gl.setLayoutProperty(l.id,'text-field',expr); }catch(e){}
        });
    }catch(e){ console.error('GeoAlbum 3D labels',e); }
}

var map=new maplibregl.Map({
    container:'map', style:STYLE, center:[GAB3D.lng,GAB3D.lat], zoom:Math.max(14,GAB3D.zoom-1),
    pitch:60, bearing:-17, maxPitch:85, attributionControl:{customAttribution:ATTR}
});
// Cadrage sur la zone (ou sur la vue 2D d'origine) plutôt qu'un simple centre/zoom
if(GAB3D.bb){
    try{ map.fitBounds([[GAB3D.bb[1],GAB3D.bb[0]],[GAB3D.bb[3],GAB3D.bb[2]]],
        {padding:60,pitch:60,bearing:-17,maxZoom:17,duration:0}); }catch(e){}
}
map.addControl(new maplibregl.NavigationControl({visualizePitch:true}),'top-right');
map.addControl(new maplibregl.ScaleControl(),'bottom-left');

var styleDone=false, photosAdded=false, photos=null;

function ajax(url,cb){
    var x=new XMLHttpRequest(); x.open('GET',url,true); x.withCredentials=true;
    x.onload=function(){ try{ cb(JSON.parse(x.responseText)); }catch(e){ cb(null); } };
    x.onerror=function(){ cb(null); };
    x.send();
}

function addBuildings(){
    var st=map.getStyle();
    var has=st.layers.some(function(l){ return l.type==='fill-extrusion'; });
    if(!has && st.sources && st.sources.openmaptiles){
        try{
            map.addLayer({id:'gab-bat-3d',type:'fill-extrusion',source:'openmaptiles','source-layer':'building',minzoom:13,
                paint:{'fill-extrusion-color':'#d8d2c8','fill-extrusion-height':['coalesce',['get','render_height'],6],
                       'fill-extrusion-base':['coalesce',['get','render_min_height'],0],'fill-extrusion-opacity':0.9}});
        }catch(e){ console.error('GeoAlbum 3D',e); }
    }
}


var MAXCZ=17;
function thumbLink(d,size){
    var a=document.createElement('a'); a.href=d.page_url; a.target='_blank'; a.rel='noopener'; a.title=d.name;
    if(d.thumb){ var im=document.createElement('img'); im.src=d.thumb; im.alt=d.name; im.loading='lazy';
        if(size){ im.style.cssText='width:'+size+'px;height:'+size+'px;object-fit:cover;border-radius:3px;display:block'; }
        a.appendChild(im); }
    else { a.textContent=d.name; }
    return a;
}
function showOne(coords,id){
    var box=document.createElement('div'); box.className='pp'; box.textContent='Chargement…';
    new maplibregl.Popup({maxWidth:'230px',offset:10}).setLngLat(coords).setDOMContent(box).addTo(map);
    ajax(GAB3D.ajax+'?ajax=photo_detail&id='+encodeURIComponent(id),function(d){
        box.textContent='';
        if(!d||!d.name){ box.textContent='Photo introuvable'; return; }
        var a=thumbLink(d,0); box.appendChild(a);
        var t=document.createElement('a'); t.href=d.page_url; t.target='_blank'; t.rel='noopener'; t.textContent=d.name; box.appendChild(t);
    });
}
/* Galerie : photos situées exactement au même endroit (impossibles à séparer par le zoom). */
function showGallery(coords,ids){
    var PAGE=24, pos=0;
    var box=document.createElement('div'); box.className='pp';
    var head=document.createElement('div'); head.style.cssText='font-weight:bold;margin-bottom:6px';
    head.textContent=ids.length+' photos à cet endroit';
    var grid=document.createElement('div'); grid.style.cssText='display:grid;grid-template-columns:repeat(3,72px);gap:4px;max-height:260px;overflow:auto';
    var more=document.createElement('button'); more.type='button'; more.style.cssText='margin-top:6px;cursor:pointer'; 
    box.appendChild(head); box.appendChild(grid); box.appendChild(more);
    function next(){
        var chunk=ids.slice(pos,pos+PAGE); if(!chunk.length){ more.style.display='none'; return; }
        more.disabled=true; more.textContent='Chargement…';
        ajax(GAB3D.ajax+'?ajax=photo_details&ids='+chunk.join(','),function(list){
            (list||[]).forEach(function(d){ grid.appendChild(thumbLink(d,72)); });
            pos+=PAGE; more.disabled=false;
            if(pos<ids.length){ more.textContent='Suivantes ('+(ids.length-pos)+')'; } else more.style.display='none';
        });
    }
    more.onclick=next; next();
    new maplibregl.Popup({maxWidth:'260px',offset:10}).setLngLat(coords).setDOMContent(box).addTo(map);
}

function setPhotosVisible(v){
    ['gab-clusters','gab-cluster-count','gab-photo'].forEach(function(id){
        if(map.getLayer(id)) map.setLayoutProperty(id,'visibility',v?'visible':'none');
    });
}

function addPhotos(){
    if(photosAdded||!photos||!styleDone) return;
    photosAdded=true;
    var feats=photos.map(function(p){ return {type:'Feature',properties:{id:p[0]},geometry:{type:'Point',coordinates:[p[2],p[1]]}}; });
    map.addSource('gab-photos',{type:'geojson',cluster:true,clusterMaxZoom:MAXCZ,clusterRadius:50,data:{type:'FeatureCollection',features:feats}});
    map.addLayer({id:'gab-clusters',type:'circle',source:'gab-photos',filter:['has','point_count'],
        paint:{'circle-color':'#2e7d32','circle-radius':['step',['get','point_count'],14,10,18,100,24],'circle-stroke-width':2,'circle-stroke-color':'#fff'}});
    var font=['Noto Sans Bold'];
    map.getStyle().layers.some(function(l){ if(l.type==='symbol'&&l.layout&&l.layout['text-font']){ font=l.layout['text-font']; return true; } return false; });
    map.addLayer({id:'gab-cluster-count',type:'symbol',source:'gab-photos',filter:['has','point_count'],
        layout:{'text-field':['get','point_count_abbreviated'],'text-size':12,'text-font':font},paint:{'text-color':'#fff'}});
    map.addLayer({id:'gab-photo',type:'circle',source:'gab-photos',filter:['!',['has','point_count']],
        paint:{'circle-color':'#e04000','circle-radius':7,'circle-stroke-width':2,'circle-stroke-color':'#fff','circle-pitch-alignment':'map'}});
    map.on('click','gab-clusters',function(e){
        var f=e.features[0], cid=f.properties.cluster_id, src=map.getSource('gab-photos');
        src.getClusterExpansionZoom(cid).then(function(z){
            if(z>MAXCZ){   // points superposés (même position) : le zoom ne les sépare pas -> galerie
                src.getClusterLeaves(cid,1000,0).then(function(leaves){
                    showGallery(f.geometry.coordinates,leaves.map(function(l){ return l.properties.id; }));
                }).catch(function(){});
            } else map.easeTo({center:f.geometry.coordinates,zoom:z+0.5});
        }).catch(function(){});
    });
    map.on('click','gab-photo',function(e){
        // plusieurs photos au même endroit : toutes celles situées sous le clic
        var pt=e.point, box=[[pt.x-8,pt.y-8],[pt.x+8,pt.y+8]];
        var seen={}, ids=[];
        map.queryRenderedFeatures(box,{layers:['gab-photo']}).forEach(function(f){
            var i=f.properties.id; if(!seen[i]){ seen[i]=1; ids.push(i); }
        });
        var coords=e.features[0].geometry.coordinates.slice();
        if(ids.length>1) showGallery(coords,ids); else showOne(coords,ids[0]);
    });
    ['gab-clusters','gab-photo'].forEach(function(id){
        map.on('mouseenter',id,function(){ map.getCanvas().style.cursor='pointer'; });
        map.on('mouseleave',id,function(){ map.getCanvas().style.cursor=''; });
    });
    var chk=document.getElementById('chk-photos');
    setPhotosVisible(!chk||chk.checked);
}

map.on('styledata',function(){
    if(styleDone) return;
    var st=null; try{ st=map.getStyle(); }catch(e){}
    if(!st||!st.layers||!st.layers.length) return;
    styleDone=true;
    frenchLabels(map);
    addBuildings();
    addZoneOutline();
    addPhotos();
});

function addZoneOutline(){
    var r=GAB3D.ring; if(!r||r.length<3) return;
    var c=r.slice(); c.push(r[0]);
    try{
        map.addSource('gab-zone',{type:'geojson',data:{type:'Feature',geometry:{type:'Polygon',coordinates:[c]}}});
        map.addLayer({id:'gab-zone-fill',type:'fill',source:'gab-zone',paint:{'fill-color':'#2a6fdb','fill-opacity':0.12}});
        map.addLayer({id:'gab-zone-line',type:'line',source:'gab-zone',paint:{'line-color':'#2a6fdb','line-width':3}});
    }catch(e){ console.error('GeoAlbum 3D zone',e); }
}
map.on('error',function(ev){ console.error('GeoAlbum 3D',ev&&ev.error); });

function drawHere(kind){
    var c=map.getCenter(), z=Math.max(2,Math.min(19,Math.round(map.getZoom()+1)));
    try{
        var o=window.opener;
        if(o && !o.closed && o._map && typeof o._map.setView==='function'){
            o._map.setView([c.lat,c.lng],z);   // onglet de gestion encore ouvert : on le recadre
            if(typeof o.gabDraw==='function') o.gabDraw(kind);   // et on active l'outil de tracé
            try{ o.focus(); }catch(e){}
            // Les navigateurs ne permettent pas de basculer d'un onglet à l'autre par script : on ferme
            // cet onglet 3D (ouvert par le plugin), ce qui ramène sur l'onglet de gestion.
            say('Outil de tracé activé dans l\u2019onglet de gestion.');
            setTimeout(function(){ window.close(); },150);
            setTimeout(function(){ say('Outil de tracé activé : revenez à l\u2019onglet de gestion (celui d\u2019où vous avez ouvert la 3D) ; vous pouvez fermer celui-ci.'); },600);
            return;
        }
    }catch(e){}
    window.open(GAB3D.ajax+'?glat='+c.lat.toFixed(6)+'&glng='+c.lng.toFixed(6)+'&gz='+z+'&gd='+kind,'_blank');
}
['bbox','polygon'].forEach(function(k){
    var b=document.getElementById('btn-draw-'+k);
    if(b) b.addEventListener('click',function(){ drawHere(k); });
});
var bt=document.getElementById('btn-tilt');
if(bt){
    function lab(){ bt.textContent=map.getPitch()>10?'Mettre à plat (2D)':'Revenir en 3D'; }
    bt.addEventListener('click',function(){
        if(map.getPitch()>10) map.easeTo({pitch:0,bearing:0,duration:600});
        else map.easeTo({pitch:60,bearing:-17,duration:600});
    });
    map.on('pitchend',lab); map.on('load',lab);
}
var chk=document.getElementById('chk-photos');
if(chk) chk.addEventListener('change',function(){ setPhotosVisible(chk.checked); });

ajax(GAB3D.ajax+'?ajax=photos&album_id='+GAB3D.album+(GAB3D.q||''),function(d){
    if(d&&d.photos){
        photos=d.photos;
        say(photos.length+' photo(s) géolocalisée(s)'+(GAB3D.album>0?' dans l’album sélectionné':'')+(GAB3D.q?' (filtre période appliqué)':''));
        addPhotos();
    } else say('Photos indisponibles (session expirée ?)');
});
})();
