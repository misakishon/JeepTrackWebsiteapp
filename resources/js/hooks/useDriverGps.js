import {useEffect,useRef,useState} from 'react';
import axios from 'axios';

export const gpsConsentKey=(userId,tripId)=>`puj-gps:${userId}:${tripId}`;
export function gpsError(error){
    if(error?.code===1)return 'Location permission denied. Allow precise location in your browser’s site settings, then retry.';
    if(error?.code===2)return 'Your location is unavailable. Enable GPS and move to an open area when safe.';
    if(error?.code===3)return 'Getting GPS took too long. Check location settings and retry.';
    return error?.message||'Could not get your location. Please retry.';
}
export function pointFromPosition(position){
    const {latitude,longitude,accuracy,speed}=position.coords;
    if(!Number.isFinite(latitude)||!Number.isFinite(longitude)||!Number.isFinite(accuracy))throw new Error('The browser returned an invalid GPS fix. Please retry.');
    if(accuracy>1000)throw new Error('GPS accuracy is too low. Enable precise location and try again in an open area.');
    return {client_point_id:crypto.randomUUID(),latitude,longitude,accuracy_m:accuracy,speed_kmh:Number.isFinite(speed)&&speed>=0&&speed*3.6<=300?speed*3.6:null,captured_at:new Date(position.timestamp).toISOString()};
}
export function getFreshPoint(){
    if(!window.isSecureContext)return Promise.reject(new Error('GPS requires HTTPS on a phone browser. Open this app using HTTPS (localhost also works for desktop testing).'));
    if(!navigator.geolocation)return Promise.reject(new Error('This browser does not support location sharing.'));
    return new Promise((resolve,reject)=>navigator.geolocation.getCurrentPosition(position=>{try{resolve(pointFromPosition(position))}catch(error){reject(error)}},error=>reject(new Error(gpsError(error))),{enableHighAccuracy:true,maximumAge:0,timeout:15000}));
}

export default function useDriverGps({userId,tripId,onLocation,onInactive}){
    const [version,Version]=useState(0),[status,Status]=useState({phase:'idle',message:'GPS sharing is not active.'});
    const callbacks=useRef({onLocation,onInactive});callbacks.current={onLocation,onInactive};
    const control=useRef({stop:()=>{}});
    function authorize(id){sessionStorage.setItem(gpsConsentKey(userId,id),'yes');Version(v=>v+1);}
    function stop(){if(tripId)sessionStorage.removeItem(gpsConsentKey(userId,tripId));control.current.stop();Version(v=>v+1);}
    async function resume(){
        if(!tripId)return;
        Status({phase:'acquiring',message:'Getting a fresh GPS fix…'});
        try{const point=await getFreshPoint();const response=await axios.post(`/api/transport/trips/${tripId}/gps`,point);callbacks.current.onLocation?.(response.data.location);authorize(tripId);Status({phase:'sharing',message:'GPS is being shared with passengers.'});}
        catch(error){Status({phase:'error',message:Object.values(error.response?.data?.errors||{}).flat().join(' ')||error.response?.data?.message||gpsError(error)});}
    }
    useEffect(()=>{
        if(!tripId||sessionStorage.getItem(gpsConsentKey(userId,tripId))!=='yes'){Status({phase:tripId?'paused':'idle',message:tripId?'Resume GPS sharing to show passengers your current position.':'GPS sharing is not active.'});return;}
        if(!window.isSecureContext||!navigator.geolocation){Status({phase:'error',message:'GPS requires a supported browser and HTTPS on phones.'});return;}
        let disposed=false,halted=false,watchId=null,timer=null,gettingFix=false,sending=false,lastSent=0,lastCapture=null,permission=null,abort=null;
        function halt(){halted=true;if(watchId!==null)navigator.geolocation.clearWatch(watchId);if(timer)clearInterval(timer);abort?.abort();}
        control.current.stop=halt;
        async function positionChanged(position){
            if(disposed||halted)return;
            if(document.visibilityState==='hidden'){Status({phase:'paused',message:'Tracking may pause while the browser is hidden. Keep this page visible to share GPS.'});return;}
            if(!navigator.onLine){Status({phase:'offline',message:'Connection lost. GPS will resume automatically when you are online.'});return;}
            if(sending||Date.now()-lastSent<4500||position.timestamp===lastCapture)return;
            let point;try{point=pointFromPosition(position)}catch(error){Status({phase:'error',message:error.message});return;}
            sending=true;abort=new AbortController();
            try{
                const response=await axios.post(`/api/transport/trips/${tripId}/gps`,point,{signal:abort.signal,timeout:12000});
                if(disposed||halted)return;
                lastSent=Date.now();lastCapture=position.timestamp;callbacks.current.onLocation?.(response.data.location);Status({phase:'sharing',message:'GPS is being shared with passengers.'});
            }catch(error){
                if(disposed||halted||axios.isCancel(error))return;
                if([401,403,404,409].includes(error.response?.status)){
                    sessionStorage.removeItem(gpsConsentKey(userId,tripId));halt();Status({phase:'stopped',message:error.response?.data?.message||'GPS sharing stopped. Refresh your trip.'});callbacks.current.onInactive?.();
                }else Status({phase:'offline',message:error.response?.data?.message||'GPS upload failed. Retrying automatically; passengers can see when the last fix becomes stale.'});
            }finally{sending=false;}
        }
        function failure(error){
            if(disposed||halted)return;
            if(error.code===1){sessionStorage.removeItem(gpsConsentKey(userId,tripId));halt();Status({phase:'denied',message:gpsError(error)});}
            else Status({phase:'waiting',message:gpsError(error)});
        }
        function fresh(){
            if(disposed||halted||gettingFix||document.visibilityState==='hidden'||!navigator.onLine)return;
            gettingFix=true;navigator.geolocation.getCurrentPosition(p=>{gettingFix=false;positionChanged(p)},e=>{gettingFix=false;failure(e)},{enableHighAccuracy:true,maximumAge:0,timeout:10000});
        }
        function visibility(){if(document.visibilityState==='visible')fresh();else Status({phase:'paused',message:'Tracking may pause in the background. Keep this page visible while on duty.'});}
        function offline(){Status({phase:'offline',message:'Connection lost. GPS sharing will retry when you are online.'});}
        Status({phase:'acquiring',message:'Connecting to GPS…'});
        watchId=navigator.geolocation.watchPosition(positionChanged,failure,{enableHighAccuracy:true,maximumAge:0,timeout:15000});
        timer=setInterval(fresh,5000);fresh();
        document.addEventListener('visibilitychange',visibility);window.addEventListener('online',fresh);window.addEventListener('offline',offline);
        navigator.permissions?.query({name:'geolocation'}).then(p=>{if(disposed)return;permission=p;permission.onchange=()=>{if(permission.state==='denied')failure({code:1})};}).catch(()=>{});
        return()=>{disposed=true;halt();if(permission)permission.onchange=null;document.removeEventListener('visibilitychange',visibility);window.removeEventListener('online',fresh);window.removeEventListener('offline',offline);};
    },[userId,tripId,version]);
    return {status,authorize,resume,stop};
}
