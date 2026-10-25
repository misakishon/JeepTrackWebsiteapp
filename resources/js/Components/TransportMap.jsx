import React, { useEffect, useRef, useState } from 'react';
import './TransportMap.css';

// Mapbox supplies the base map. Routes and vehicle positions are UI samples.
const SDK = 'https://api.mapbox.com/mapbox-gl-js/v3.15.0/mapbox-gl.js';
const CSS = 'https://api.mapbox.com/mapbox-gl-js/v3.15.0/mapbox-gl.css';
let sdkPromise;
function loadMapbox() {
    if (window.mapboxgl) return Promise.resolve(window.mapboxgl);
    if (!sdkPromise) {
        sdkPromise = new Promise((resolve, reject) => {
            if (!document.querySelector('[data-mapbox-css]')) {
                const link = document.createElement('link');
                link.rel = 'stylesheet'; link.href = CSS; link.dataset.mapboxCss = 'true';
                document.head.appendChild(link);
            }
            const script = document.createElement('script');
            script.src = SDK; script.async = true;
            script.onload = () => window.mapboxgl ? resolve(window.mapboxgl) : reject(new Error('Map SDK unavailable'));
            script.onerror = () => { script.remove(); reject(new Error('Mapbox could not be reached')); };
            document.head.appendChild(script);
        }).catch(error => { sdkPromise = undefined; throw error; });
    }
    return sdkPromise;
}
const batac = [120.5649,18.0554], laoag = [120.5937,18.1978], paoay = [120.5221,18.0616], sarrat = [120.6479,18.1579];
function geometry(name) {
    if (name.includes('Paoay')) return [batac,[120.551,18.056],[120.534,18.06],paoay];
    if (name.includes('Sarrat')) return [laoag,[120.606,18.188],[120.628,18.17],sarrat];
    return [batac,[120.571,18.091],[120.582,18.135],[120.594,18.172],[120.594,18.178],laoag];
}
export default function TransportMap({small=false,route=['Batac–Laoag','Batac Terminal','Laoag Public Market'],fallback,mapData=null}) {
    const container = useRef(null), mapRef = useRef(null), glRef=useRef(null), vehicleMarker=useRef(null), vehiclePopup=useRef(null), following=useRef(true);
    const [status, setStatus] = useState('loading'), [attempt, retry] = useState(0);
    const name = route[0];
    // A new GPS fix updates the existing marker; it must not reload map tiles or
    // discard the user's zoom/pan state every few seconds.
    const dataKey = JSON.stringify(mapData?{...mapData,location:undefined}:null);
    const knownStops = (mapData?.stops||[]).filter(p=>Number.isFinite(Number(p.longitude))&&Number.isFinite(Number(p.latitude))&&Math.abs(Number(p.longitude))<=180&&Math.abs(Number(p.latitude))<=90);
    const knownLocation = mapData?.location;
    const realPoints = [...knownStops.map(p=>[Number(p.longitude),Number(p.latitude)]),...(knownLocation?[[Number(knownLocation.longitude),Number(knownLocation.latitude)]]:[])];
    useEffect(() => {
        let disposed = false, instance;
        setStatus('loading');
        const timeout = setTimeout(() => { if (!disposed) setStatus('error'); }, 12000);
        const token = import.meta.env.VITE_MAPBOX_ACCESS_TOKEN;
        if (!token) { clearTimeout(timeout); setStatus('error'); return; }
        loadMapbox().then(gl => {
            if (disposed) return;
            if (!gl.supported()) throw new Error('WebGL unavailable');
            gl.accessToken = token;
            glRef.current=gl;following.current=true;
            const follow=mapData?.followVehicle&&knownLocation;
            instance = new gl.Map({container:container.current,style:'mapbox://styles/mapbox/streets-v12',center:follow?[Number(knownLocation.longitude),Number(knownLocation.latitude)]:mapData?(realPoints[0]||[121.77,12.87]):batac,zoom:follow?14:mapData&&!realPoints.length?5:11,attributionControl:true});
            mapRef.current = instance;
            const points = mapData?realPoints:geometry(name);
            if(points.length&&!follow){const bounds = points.reduce((b,p) => b.extend(p),new gl.LngLatBounds(points[0],points[0]));instance.fitBounds(bounds,{padding:{top:60,bottom:65,left:45,right:50},duration:0,maxZoom:14});}
            instance.addControl(new gl.NavigationControl({showCompass:false}),'bottom-right');
            instance.addControl(new gl.GeolocateControl({positionOptions:{enableHighAccuracy:true},trackUserLocation:false,showUserHeading:false}),'top-right');
            const marker = (point, label, vehicle=false) => {
                const element = document.createElement('button');
                element.type='button';element.className=vehicle?'mb-vehicle':'mb-stop';
                element.setAttribute('aria-label', label);
                element.innerHTML=vehicle?'<svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M5 3h14v14h-1v4h-3v-3H9v3H6v-4H5V3Zm2 2v6h10V5H7Zm1 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4Zm8 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z"/></svg>':'';
                const popup = new gl.Popup({offset:22}).setText(label);
                new gl.Marker({element}).setLngLat(point).setPopup(popup).addTo(instance);
            };
            if(mapData){
                knownStops.forEach(stop=>marker([Number(stop.longitude),Number(stop.latitude)],stop.label||stop.stop_name||'Route stop'));
            }else{
                marker(points[0],route[1]+' · demo boarding stop');
                marker(points[points.length-1],route[2]+' · demo destination');
                marker(points[Math.floor(points.length/2)],'IJ-087 · illustrative sample position, not live GPS',true);
            }
            instance.on('load',() => {
                if (disposed) return;
                const line=mapData?mapData.routeLine:points;
                if(line?.length>1){instance.addSource('demo-route',{type:'geojson',data:{type:'Feature',properties:{},geometry:{type:'LineString',coordinates:line}}});
                instance.addLayer({id:'demo-route-casing',type:'line',source:'demo-route',paint:{'line-color':'#fff','line-width':9}});
                instance.addLayer({id:'demo-route-line',type:'line',source:'demo-route',layout:{'line-join':'round','line-cap':'round'},paint:{'line-color':'#2463eb','line-width':5,'line-opacity':.9}});
                }
                clearTimeout(timeout);setStatus('ready');
            });
            instance.on('error',() => { if (!disposed && !instance.loaded()) { clearTimeout(timeout);setStatus('error'); } });
            instance.on('dragstart',()=>{following.current=false;});
        }).catch(() => { if (!disposed) {clearTimeout(timeout);setStatus('error');} });
        return () => {disposed=true;clearTimeout(timeout);if(instance)instance.remove();mapRef.current=null;vehicleMarker.current=null;vehiclePopup.current=null;};
    },[name,attempt,dataKey]);
    const locationKey=JSON.stringify(knownLocation?{latitude:knownLocation.latitude,longitude:knownLocation.longitude,timestamp:knownLocation.timestamp,is_stale:knownLocation.is_stale,point_id:knownLocation.point_id}:null);
    useEffect(()=>{
        if(!mapData||status!=='ready'||!mapRef.current||!glRef.current)return;
        if(!knownLocation){vehicleMarker.current?.remove();vehicleMarker.current=null;vehiclePopup.current=null;return;}
        const gl=glRef.current,map=mapRef.current,point=[Number(knownLocation.longitude),Number(knownLocation.latitude)];
        const label=(mapData.vehicleLabel||'Vehicle')+' · '+(knownLocation.is_stale?'stale GPS, last known position':'live GPS')+' · '+new Date(knownLocation.timestamp).toLocaleString('en-PH',{timeZone:'Asia/Manila'});
        if(!vehicleMarker.current){
            const element=document.createElement('button');element.type='button';element.className='mb-vehicle';element.innerHTML='<svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M5 3h14v14h-1v4h-3v-3H9v3H6v-4H5V3Zm2 2v6h10V5H7Zm1 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4Zm8 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z"/></svg>';
            vehiclePopup.current=new gl.Popup({offset:22});vehicleMarker.current=new gl.Marker({element}).setPopup(vehiclePopup.current).setLngLat(point).addTo(map);
        }else vehicleMarker.current.setLngLat(point);
        vehicleMarker.current.getElement().setAttribute('aria-label',label);vehicleMarker.current.getElement().classList.toggle('stale',!!knownLocation.is_stale);vehiclePopup.current.setText(label);
        if(mapData.followVehicle&&following.current){map.easeTo({center:point,zoom:Math.max(map.getZoom(),14),duration:800});}
    },[locationKey,status,dataKey]);
    function recenter() {
        const map=mapRef.current;
        if(!map)return;
        following.current=true;
        if(mapData?.followVehicle&&knownLocation){map.flyTo({center:[Number(knownLocation.longitude),Number(knownLocation.latitude)],zoom:Math.max(map.getZoom(),14),duration:500});return;}
        const points=mapData?realPoints:geometry(name);
        if(!points.length){map.flyTo({center:[121.77,12.87],zoom:5});return;}
        map.fitBounds([points.reduce((a,p)=>[Math.min(a[0],p[0]),Math.min(a[1],p[1])],points[0]),points.reduce((a,p)=>[Math.max(a[0],p[0]),Math.max(a[1],p[1])],points[0])],{padding:55,duration:500});
    }
    return <div className={'mapbox-wrap '+(small?'small':'')}>
        <div ref={container} className="mapbox-container" aria-label={'Mapbox map: '+name}/>
        {status==='ready'&&<><span className="map-label">{mapData?(knownLocation?(knownLocation.is_stale?'STALE GPS · LAST KNOWN POSITION':'LIVE VEHICLE GPS'):'ROUTE STOPS · VEHICLE GPS UNAVAILABLE'):'DEMO ROUTE & VEHICLE · NOT LIVE GPS'}</span><button className="mb-recenter" aria-label={mapData?.followVehicle&&knownLocation?'Recenter vehicle':'Recenter route'} onClick={recenter}>⌖</button></>}
        {status==='loading'&&<div className="mapbox-loading" role="status"><span className="map-spinner"/><strong>Loading Mapbox…</strong><small>{mapData?'Route stops and available location records':'Demo route and vehicle markers'}</small></div>}
        {status==='error'&&<div className="mapbox-fallback">{fallback||(mapData&&<StopPreview stops={knownStops} location={knownLocation}/>)}<div className="mapbox-error" role="status"><span>{mapData?'Mapbox unavailable · coordinate preview':'Mapbox unavailable · illustrative fallback'}</span><button onClick={()=>retry(x=>x+1)}>Retry</button></div></div>}
    </div>;
}

function StopPreview({stops,location}){
    const [zoom,Z]=useState(1),[selected,S]=useState('');
    const points=[...stops.map(s=>({...s,label:s.label||s.stop_name||'Route stop'})),...(location?[{...location,label:'Latest vehicle location',vehicle:true}]:[])];
    const lngs=points.map(p=>Number(p.longitude)),lats=points.map(p=>Number(p.latitude)),minLng=Math.min(...lngs),maxLng=Math.max(...lngs),minLat=Math.min(...lats),maxLat=Math.max(...lats);
    const x=p=>points.length===1?200:60+(Number(p.longitude)-minLng)/Math.max(maxLng-minLng,.0001)*270,y=p=>points.length===1?170:260-(Number(p.latitude)-minLat)/Math.max(maxLat-minLat,.0001)*170;
    return <div className="stop-coordinate-preview"><svg viewBox="0 0 400 340" role="img" aria-label="Stored route stop coordinates; basemap offline"><defs><pattern id="coordinate-grid" width="45" height="45" patternUnits="userSpaceOnUse"><path d="M45 0H0V45" fill="none" stroke="#dce5ef" strokeWidth="1"/></pattern></defs><rect width="400" height="340" fill="#eef3f8"/><rect width="400" height="340" fill="url(#coordinate-grid)"/><g transform={`translate(200 170) scale(${zoom}) translate(-200 -170)`}>{points.map((p,i)=><g key={i}><circle cx={x(p)} cy={y(p)} r={p.vehicle?10:7} fill={selected===p.label?'#2463eb':p.vehicle?'#1d4ed8':'white'} stroke="#2463eb" strokeWidth="3" tabIndex="0" role="button" aria-label={p.label} onClick={()=>S(p.label)} onKeyDown={e=>{if(e.key==='Enter')S(p.label)}}><title>{p.label}</title></circle></g>)}</g>{!points.length&&<text x="200" y="170" textAnchor="middle">No location coordinates available</text>}</svg>{points.length>0&&<label className="coordinate-stop-picker">Find a stop<select aria-label="Find a map stop" value={selected} onChange={e=>S(e.target.value)}><option value="">Select a stop</option>{points.map((p,i)=><option key={i} value={p.label}>{p.label}</option>)}</select></label>}<div className="map-controls"><button aria-label="Zoom in" onClick={()=>Z(Math.min(2,zoom+.2))}>+</button><button aria-label="Zoom out" onClick={()=>Z(Math.max(.8,zoom-.2))}>−</button><button aria-label="Recenter route" onClick={()=>Z(1)}>⌖</button></div><span className="map-selected">{selected||'Route stops · '+(location?'latest recorded vehicle point':'vehicle GPS unavailable')}</span><small className="attribution">Stored coordinates · Basemap offline</small></div>;
}
