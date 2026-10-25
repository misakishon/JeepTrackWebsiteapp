// Deterministic fixtures. No contact details, database, or live telemetry.
export let ROUTES = [
    {id:'batac-laoag',name:'Batac–Laoag',origin:'Batac Terminal',destination:'Laoag Public Market',stops:['Batac Terminal','San Nicolas Plaza','Robinsons Ilocos','Laoag Public Market'],scheduled:35,km:18.4},
    {id:'batac-paoay',name:'Batac–Paoay',origin:'Batac Terminal',destination:'Paoay Public Market',stops:['Batac Terminal','Quiling','Paoay Church','Paoay Public Market'],scheduled:25,km:8.1},
    {id:'laoag-sarrat',name:'Laoag–Sarrat',origin:'Laoag Public Market',destination:'Sarrat Town Center',stops:['Laoag Public Market','Bacarra Junction','Sarrat Church','Sarrat Town Center'],scheduled:30,km:12.3},
];
export let DRIVERS = ['Reynaldo Cruz','Elena Santos','Marco Reyes','Joel Torres','Luis Ramirez','Ana Dela Cruz','Paolo Garcia','Nestor Ramos'].map((name,i)=>({id:'D-'+(101+i),name,vehicle:'IJ-'+String(87+i).padStart(3,'0'),status:i===7?'Off duty':'Active'}));
export let VEHICLES = DRIVERS.map((d,i)=>({id:d.vehicle,plate:'NCC '+(7421+i*17),capacity:26,status:i===7?'Maintenance':'Available',denominator:i===6?null:12}));
const trips=[],journeys=[],feedback=[];
const dayISO=n=>new Date(Date.UTC(2026,8,1+n)).toISOString().slice(0,10);
for(let day=0;day<55;day++){
    for(let routeIndex=0;routeIndex<3;routeIndex++){
        const route=ROUTES[routeIndex];
        for(let seq=0;seq<8+(day+routeIndex)%5;seq++){
            const seed=day*31+routeIndex*13+seq*7;
            const driver=DRIVERS[(seq+day+routeIndex)%DRIVERS.length];
            const date=dayISO(day),hour=6+(seq%14),minute=seq%2?30:0;
            const scheduledDeparture=new Date(`${date}T${String(hour).padStart(2,'0')}:${String(minute).padStart(2,'0')}:00+08:00`);
            const departureLate=seed%9===0?8:seed%4-1;
            const duration=route.scheduled+(seed%13)-5;
            const departure=new Date(+scheduledDeparture+departureLate*60000);
            const arrival=new Date(+departure+duration*60000);
            const status=seed%37===0?'Cancelled':'Completed';
            const trip={id:'T-'+(1000+trips.length),date,route:route.id,direction:seq%2?'Inbound':'Outbound',driver:driver.id,vehicle:driver.vehicle,status,scheduledDeparture:scheduledDeparture.toISOString(),scheduledArrival:new Date(+scheduledDeparture+route.scheduled*60000).toISOString(),departure:seed%41===0?null:departure.toISOString(),arrival:seed%41===0?null:arrival.toISOString(),duration:seed%41===0?null:duration,departureLate:seed%41===0?null:departureLate,arrivalLate:seed%41===0?null:departureLate+duration-route.scheduled,distance:seed%29===0?null:Number((route.km*(.96+(seed%8)/100)).toFixed(1)),gps:seed%29===0?null:seed%11===0?68:96,delayReason:departureLate>5?['Traffic','Road closure','Weather','Vehicle issue'][seed%4]:null};
            trips.push(trip);
            if(status!=='Completed')continue;
            for(let j=0;j<10+seed%16;j++){
                const app=j%4!==0;
                const stop=seq%2?[...route.stops].reverse():route.stops;
                const record={id:'J-'+journeys.length,trip:trip.id,date,route:route.id,direction:trip.direction,driver:trip.driver,vehicle:trip.vehicle,passenger:app?'P-'+((seed+j*19)%740):null,source:app?'App journey':'Manual boarding',reconciliationKey:trip.id+'-'+j,boarding:stop[j%3],alighting:stop[3],hour,dayOfWeek:new Date(date+'T12:00:00+08:00').getDay()};
                journeys.push(record);
                // An imported count linked to an app journey must not count again.
                if(app&&j===1)journeys.push({...record,id:record.id+'-manual-link',source:'Linked manual entry'});
            }
            if(seed%4===0){const categories=['Service quality','Driver behavior','Waiting time','Vehicle condition','Other'];const status=['New','Reviewing','Resolved'][seed%3];feedback.push({id:'FB-'+(1001+feedback.length),date,trip:trip.id,journey:'J-'+(journeys.length-3),route:route.id,driver:trip.driver,vehicle:trip.vehicle,category:categories[seed%5],type:seed%7===0?'Complaint':seed%5===0?'Suggestion':'Rating',rating:seed%7===0?null:seed%9===0?3:seed%3===0?4:5,status,message:seed%7===0?'The waiting time at the boarding stop was longer than expected. Please review the departure schedule.':'The trip was comfortable. Clearer signs at the boarding area would be helpful.',response:status==='Resolved'?'Thank you for sharing this. We have forwarded the stop signage concern to the route coordinator.':'',internalNote:status==='Reviewing'?'Verify the assignment and route conditions before responding.':'',resolvedAt:status==='Resolved'?date+'T14:00:00+08:00':null});}
        }
    }
}
export let TRIPS=trips;
export let JOURNEYS=[...new Map(journeys.map(j=>[j.reconciliationKey,j])).values()];
export let FEEDBACK=feedback;
export const DEFAULT_FILTERS={from:'2026-10-01',to:'2026-10-25',route:'all',driver:'all',vehicle:'all',direction:'all',stop:'all',day:'all',category:'all',status:'all',compare:true};
export const DEFINITIONS=[
    ['Completed trips','Trip records with status Completed and a reporting date inside the selected period (actual departure date when recorded; scheduled date otherwise).'],
    ['Passenger journeys','Reconciled app and manual boarding records. Linked duplicate entries are counted once using a reconciliation key.'],
    ['Unique passengers','Distinct registered passenger IDs from app-linked journeys only. Anonymous manual boardings are excluded.'],
    ['Actual duration','Actual arrival minus actual departure, using only valid non-negative timestamp pairs.'],
    ['Departure punctuality','Departure is on time when no more than the configured threshold after scheduled departure.'],
    ['Arrival delay','Actual arrival minus scheduled arrival. Delayed-arrival rate uses valid arrivals only. Early arrivals do not contribute positive delay minutes.'],
    ['Utilization','Valid trip operating hours divided by configured available operating hours for the selected vehicle-days. Unavailable when the denominator is missing.'],
    ['Estimated distance','GPS-derived estimate. Missing or incomplete tracking is flagged; partial totals are not full distance coverage.'],
    ['Resolution rate','Resolved feedback divided by all feedback submissions in the selected period. Rating averages use only provided ratings.'],
];
export const routeName=id=>ROUTES.find(r=>r.id===id)?.name||id;
export const driverName=id=>DRIVERS.find(d=>d.id===id)?.name||id;
export const fmt=(n,digits=0)=>n==null||!Number.isFinite(n)?'Unavailable':new Intl.NumberFormat('en-PH',{maximumFractionDigits:digits}).format(n);
export const average=a=>a.length?a.reduce((s,n)=>s+n,0)/a.length:null;
export const sum=a=>a.reduce((s,n)=>s+n,0);
export const percent=(a,b)=>b?a/b*100:null;
export const valid=t=>t.departure&&t.arrival&&new Date(t.arrival)>=new Date(t.departure);
export const formatTime=iso=>iso?new Date(iso).toLocaleString('en-PH',{timeZone:'Asia/Manila',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}):'Unavailable';
export function searchRows(rows,columns,query=''){const needle=query.toLowerCase();return rows.filter(row=>[...Object.values(row),...columns.map(c=>c.export?c.export(row[c.key],row):row[c.key])].join(' ').toLowerCase().includes(needle));}
export function filterRecords(list,f){return list.filter(x=>x.date>=f.from&&x.date<=f.to&&(f.route==='all'||x.route===f.route)&&(f.driver==='all'||x.driver===f.driver)&&(f.vehicle==='all'||x.vehicle===f.vehicle)&&(f.direction==='all'||x.direction===f.direction));}
export function getData(f,threshold=5,feedbackOverrides={}){
    const all=filterRecords(TRIPS,f),completed=all.filter(t=>t.status==='Completed');
    const ids=new Set(TRIPS.filter(t=>t.status!=='Cancelled').map(t=>t.id));
    const js=filterRecords(JOURNEYS,f).filter(j=>ids.has(j.trip)&&(f.stop==='all'||j.boarding===f.stop)&&(f.day==='all'||j.dayOfWeek===Number(f.day)));
    const fb=filterRecords(FEEDBACK.map(x=>({...x,...feedbackOverrides[x.id]})),f).filter(x=>(f.category==='all'||x.category===f.category)&&(f.status==='all'||x.status===f.status));
    const timed=completed.filter(valid),arrivals=timed.filter(t=>t.arrivalLate!=null),departures=timed.filter(t=>t.departureLate!=null),delayed=arrivals.filter(t=>t.arrivalLate>threshold),onTime=departures.filter(t=>t.departureLate<=threshold);
    return {all,trips:completed,journeys:js,feedback:fb,timed,metrics:{trips:completed.length,journeys:DATA_META.journeysAvailable?js.length:null,unique:DATA_META.journeysAvailable?new Set(js.filter(j=>j.passenger).map(j=>j.passenger)).size:null,duration:average(timed.map(t=>t.duration)),delayRate:percent(delayed.length,arrivals.length),onTime:percent(onTime.length,departures.length),arrivalDelay:average(arrivals.map(t=>Math.max(0,t.arrivalLate))),operatingHours:timed.length?sum(timed.map(t=>t.duration))/60:null,distance:completed.some(t=>t.distance!=null)?sum(completed.filter(t=>t.distance!=null).map(t=>t.distance)):null,rating:average(fb.filter(x=>x.rating!=null).map(x=>x.rating)),resolution:percent(fb.filter(x=>x.status==='Resolved').length,fb.length)}};
}
export function previousFilters(f){const days=Math.round((new Date(f.to)-new Date(f.from))/86400000)+1;const shift=(s,n)=>new Date(+new Date(s)-n*86400000).toISOString().slice(0,10);return {...f,from:shift(f.from,days),to:shift(f.from,1)};}
export function dayCount(f){return Math.max(1,Math.round((new Date(f.to)-new Date(f.from))/86400000)+1);}
export function grouped(list,group='Daily',value=()=>1){const map=new Map();for(const row of list){let key=row.date;if(group==='Monthly')key=key.slice(0,7);if(group==='Weekly'){const date=new Date(key+'T00:00:00Z');date.setUTCDate(date.getUTCDate()-((date.getUTCDay()+6)%7));key=date.toISOString().slice(0,10);}map.set(key,(map.get(key)||0)+value(row));}return [...map].sort(([a],[b])=>a.localeCompare(b)).map(([label,value])=>({label,value}));}
export function reportBuckets(data,filters,group){
    const bucket=date=>grouped([{date}],group)[0].label;
    const labels=[...new Set([...data.trips,...data.feedback,...data.journeys].map(x=>bucket(x.date)))].sort();
    return labels.map(period=>{
        let from=period,to=period;
        if(group==='Weekly')to=new Date(+new Date(period)+6*86400000).toISOString().slice(0,10);
        if(group==='Monthly'){from=period+'-01';const date=new Date(from);to=new Date(Date.UTC(date.getUTCFullYear(),date.getUTCMonth()+1,0)).toISOString().slice(0,10);}
        const f={...filters,from:from<filters.from?filters.from:from,to:to>filters.to?filters.to:to};
        const selected={...data,trips:data.trips.filter(t=>bucket(t.date)===period),timed:data.timed.filter(t=>bucket(t.date)===period),journeys:data.journeys.filter(t=>bucket(t.date)===period),feedback:data.feedback.filter(t=>bucket(t.date)===period)};
        return {period,filters:f,data:selected};
    });
}
export function breakdown(data,f,threshold=5){
    const routeRows=ROUTES.filter(r=>f.route==='all'||r.id===f.route).map(r=>{const ts=data.trips.filter(t=>t.route===r.id),timed=ts.filter(valid),js=data.journeys.filter(j=>j.route===r.id);return {id:r.id,route:r.name,trips:ts.length,journeys:DATA_META.journeysAvailable?js.length:null,scheduled:average(timed.filter(t=>t.scheduledDeparture&&t.scheduledArrival&&new Date(t.scheduledArrival)>=new Date(t.scheduledDeparture)).map(t=>(new Date(t.scheduledArrival)-new Date(t.scheduledDeparture))/60000)),duration:average(timed.map(t=>t.duration)),delay:average(timed.filter(t=>t.arrivalLate!=null).map(t=>Math.max(0,t.arrivalLate))),delayed:timed.some(t=>t.arrivalLate!=null)?timed.filter(t=>t.arrivalLate>threshold).length:null,rate:percent(timed.filter(t=>t.arrivalLate!=null&&t.arrivalLate>threshold).length,timed.filter(t=>t.arrivalLate!=null).length),n:timed.length};}).filter(r=>r.trips||r.journeys);
    const driverRows=DRIVERS.filter(x=>f.driver==='all'||x.id===f.driver).map(d=>{const ts=data.trips.filter(t=>t.driver===d.id),timed=ts.filter(valid);return {id:d.id,driver:d.name,trips:ts.length,hours:timed.length?sum(timed.map(t=>t.duration))/60:null,duration:f.route==='all'?null:average(timed.map(t=>t.duration)),onTime:percent(timed.filter(t=>t.departureLate!=null&&t.departureLate<=threshold).length,timed.filter(t=>t.departureLate!=null).length),delayed:timed.some(t=>t.arrivalLate!=null)?timed.filter(t=>t.arrivalLate>threshold).length:null,delay:average(timed.filter(t=>t.arrivalLate!=null).map(t=>Math.max(0,t.arrivalLate))),n:timed.length};}).filter(x=>x.trips);
    const vehicleRows=VEHICLES.filter(v=>f.vehicle==='all'||v.id===f.vehicle).map(v=>{const ts=data.trips.filter(t=>t.vehicle===v.id),timed=ts.filter(valid),hours=timed.length?sum(timed.map(t=>t.duration))/60:(ts.length?null:0),available=availableHours(v,f);return {id:v.id,vehicle:v.id,plate:v.plate,trips:ts.length,distance:ts.some(t=>t.distance!=null)?sum(ts.filter(t=>t.distance!=null).map(t=>t.distance)):null,hours,idle:available==null||hours==null?null:Math.max(0,available-hours),utilization:available&&hours!=null?hours/available*100:null,gps:average(ts.filter(t=>t.gps!=null).map(t=>t.gps)),incomplete:ts.some(t=>t.gps==null||t.gps<90),available};});
    const stopRows=[];for(const r of ROUTES.filter(r=>f.route==='all'||r.id===f.route)){for(const stop of r.stops){const js=data.journeys.filter(j=>j.route===r.id),boarding=js.filter(j=>j.boarding===stop).length,alighting=js.filter(j=>j.alighting===stop).length;if(boarding||alighting)stopRows.push({id:r.id+stop,stop,route:r.name,boarding,alighting,share:percent(boarding,data.journeys.length)});}}
    return {routeRows,driverRows,vehicleRows,stopRows};
}

export let DATA_META={source:"Demo data",journeysAvailable:true};
export function setDatabaseData(dataset){ROUTES=dataset.routes;DRIVERS=dataset.drivers;VEHICLES=dataset.vehicles;TRIPS=dataset.trips;JOURNEYS=dataset.journeys;FEEDBACK=dataset.feedback;DATA_META=dataset.meta;}

export function availableHours(vehicle,filters){if(!vehicle.availability)return vehicle.denominator==null?null:vehicle.denominator*dayCount(filters);let total=0;for(let d=new Date(filters.from+'T00:00:00Z');d<=new Date(filters.to+'T00:00:00Z');d.setUTCDate(d.getUTCDate()+1)){const value=vehicle.availability[d.toISOString().slice(0,10)];if(value==null)return null;total+=value;}return total;}
